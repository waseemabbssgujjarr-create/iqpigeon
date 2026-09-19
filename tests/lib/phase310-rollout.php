<?php
/**
 * Phase 3.10 — progressive rollout validation (offline; does not write production config).
 */
declare(strict_types=1);

require_once __DIR__ . '/phase39-release-gate.php';

const PHASE310_ROLLOUT_VERSION = '3.10.0';

/**
 * @param array<string, mixed> $policy
 * @return list<string>
 */
function phase310_stage_ids(array $policy): array
{
    $stages = is_array($policy['stages'] ?? null) ? $policy['stages'] : [];

    return array_keys($stages);
}

/**
 * @param array<string, mixed> $policy
 * @return list<int>
 */
function phase310_stage_bot_ids(array $policy, string $stageId): array
{
    $stage = is_array($policy['stages'][$stageId] ?? null) ? $policy['stages'][$stageId] : [];
    if (!empty($stage['rollout_list_empty_means_all_eligible'])) {
        return [];
    }
    $ids = [];
    foreach (is_array($stage['active_bot_ids'] ?? null) ? $stage['active_bot_ids'] : [] as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @param array<string, mixed> $policy
 */
function phase310_transition_allowed(array $policy, string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    if (!empty($policy['allow_stage_jumps'])) {
        return isset($policy['stages'][$to]);
    }
    foreach (is_array($policy['allowed_transitions'] ?? null) ? $policy['allowed_transitions'] : [] as $pair) {
        if (is_array($pair) && count($pair) === 2 && (string) $pair[0] === $from && (string) $pair[1] === $to) {
            return true;
        }
    }

    return false;
}

/**
 * Proposed rollout list string for operator (manual apply only).
 *
 * @return array{rollout_bot_ids: string, master_enabled_note: string}
 */
function phase310_proposed_config_for_stage(array $policy, string $stageId): array
{
    $stage = is_array($policy['stages'][$stageId] ?? null) ? $policy['stages'][$stageId] : [];
    if (!empty($stage['rollout_list_empty_means_all_eligible'])) {
        return [
            'rollout_bot_ids'     => '',
            'master_enabled_note' => 'AGENT_CORE_ENABLED must remain true; empty AGENT_CORE_ROLLOUT_BOT_IDS = all eligible bots',
        ];
    }
    $ids = phase310_stage_bot_ids($policy, $stageId);

    return [
        'rollout_bot_ids'     => implode(',', $ids),
        'master_enabled_note' => 'AGENT_CORE_ENABLED=true (unchanged unless prior stage had master off)',
    ];
}

/**
 * @param list<int> $botIds
 * @param array<string, mixed> $policy
 * @return array{eligible: list<int>, rejected: list<array{bot_id: int, reason: string}>}
 */
function phase310_validate_bot_eligibility(array $botIds, array $policy): array
{
    if (!function_exists('agent_core_bot_eligible')) {
        require_once dirname(__DIR__, 2) . '/includes/agent-core/bootstrap.php';
    }
    $catalog = is_array($policy['bot_catalog'] ?? null) ? $policy['bot_catalog'] : [];
    $eligible = [];
    $rejected = [];
    $seen = [];
    foreach ($botIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            $rejected[] = ['bot_id' => $id, 'reason' => 'invalid_bot_id'];
            continue;
        }
        if (isset($seen[$id])) {
            $rejected[] = ['bot_id' => $id, 'reason' => 'duplicate_bot_id'];
            continue;
        }
        $seen[$id] = true;
        $row = is_array($catalog[(string) $id] ?? null) ? $catalog[(string) $id] : ['id' => $id, 'is_active' => 1];
        $row['id'] = $id;
        if (!agent_core_bot_eligible($row, 'whatsapp')) {
            $rejected[] = ['bot_id' => $id, 'reason' => 'ineligible_bot_or_channel'];
            continue;
        }
        $eligible[] = $id;
    }

    return ['eligible' => $eligible, 'rejected' => $rejected];
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $policy
 * @return array<string, mixed>
 */
function phase310_evaluate_rollout_expansion(array $input, array $policy): array
{
    $from = (string) ($input['current_stage'] ?? '');
    $to = (string) ($input['target_stage'] ?? '');
    $gate = is_array($input['release_gate'] ?? null) ? $input['release_gate'] : [];
    $evalReport = is_array($input['evaluation_report'] ?? null) ? $input['evaluation_report'] : null;
    $metrics = is_array($input['observability_metrics'] ?? null) ? $input['observability_metrics'] : null;
    $timestamp = (string) ($input['timestamp'] ?? '1970-01-01T00:00:00Z');

    $reasons = [];
    $decision = 'APPROVED';
    $approvedBots = [];
    $rejectedBots = [];

    if (!isset($policy['stages'][$from]) || !isset($policy['stages'][$to])) {
        return phase310_audit($timestamp, $from, $to, [], [], $gate, $evalReport, 'BLOCKED', [
            ['code' => 'MALFORMED_STAGE', 'message' => 'Unknown stage id'],
        ], $policy);
    }

    if (!phase310_transition_allowed($policy, $from, $to)) {
        $reasons[] = ['code' => 'INVALID_STAGE_JUMP', 'message' => "Transition {$from} → {$to} not allowed"];
        $decision = 'REJECTED';
    }

    $reqGate = (string) (($policy['requirements']['release_gate_status'] ?? 'PASS'));
    $gateStatus = (string) ($gate['status'] ?? 'BLOCKED');
    if ($gateStatus !== $reqGate) {
        $reasons[] = ['code' => 'GATE_NOT_PASS', 'message' => 'Release gate status ' . $gateStatus];
        $decision = $gateStatus === 'BLOCKED' ? 'BLOCKED' : 'REJECTED';
    }

    $maxCrit = (int) ($policy['requirements']['max_critical_failures'] ?? 0);
    $crit = (int) ($evalReport['critical_failure_count'] ?? ($gate['evaluation_summary']['critical_failure_count'] ?? -1));
    if ($evalReport === null && !empty($policy['requirements']['evaluation_report_required'])) {
        $reasons[] = ['code' => 'EVALUATION_MISSING', 'message' => 'Evaluation report required'];
        $decision = 'BLOCKED';
        $crit = -1;
    } elseif ($crit > $maxCrit) {
        $reasons[] = ['code' => 'CRITICAL_FAILURE', 'message' => 'Critical failures: ' . $crit];
        $decision = 'REJECTED';
    }

    $targetBots = phase310_stage_bot_ids($policy, $to);
    if ($targetBots === [] && empty($policy['stages'][$to]['rollout_list_empty_means_all_eligible'])) {
        $reasons[] = ['code' => 'EMPTY_COHORT', 'message' => 'Target stage has empty bot cohort'];
        $decision = 'REJECTED';
    }

    $elig = phase310_validate_bot_eligibility($targetBots, $policy);
    $approvedBots = $elig['eligible'];
    $rejectedBots = $elig['rejected'];
    if ($rejectedBots !== [] && $targetBots !== []) {
        $reasons[] = ['code' => 'INELIGIBLE_BOTS', 'message' => 'One or more bots rejected'];
        $decision = 'REJECTED';
    }

    if ($metrics !== null) {
        $fb = $metrics['path_rates']['fallback_rate'] ?? null;
        $thr = (float) ($policy['rollback_signals']['fallback_rate_threshold']['max'] ?? 1);
        $minTurns = (int) ($policy['rollback_signals']['fallback_rate_threshold']['min_turns'] ?? 99999);
        $total = (int) ($metrics['volume']['total_turns'] ?? 0);
        if (is_numeric($fb) && $total >= $minTurns && (float) $fb > $thr) {
            $reasons[] = ['code' => 'FALLBACK_SPIKE', 'message' => 'Fallback rate ' . $fb . ' exceeds ' . $thr];
            if ($decision === 'APPROVED') {
                $decision = 'REJECTED';
            }
        }
    }

    return phase310_audit($timestamp, $from, $to, $approvedBots, $rejectedBots, $gate, $evalReport, $decision, $reasons, $policy);
}

/**
 * @param array<string, mixed> $signals
 * @param array<string, mixed> $policy
 */
function phase310_evaluate_rollback(array $signals, array $policy): array
{
    $currentStage = (string) ($signals['current_stage'] ?? '0');
    $gateStatus = (string) ($signals['release_gate_status'] ?? 'PASS');
    $crit = (int) ($signals['critical_failures'] ?? 0);
    $evidenceCritical = !empty($signals['production_evidence_critical']);

    $level = 'NO_ROLLBACK';
    $codes = [];

    if ($gateStatus === 'FAIL') {
        $level = 'ROLLBACK_REQUIRED';
        $codes[] = 'gate_fail';
    } elseif ($gateStatus === 'BLOCKED') {
        $level = 'ROLLBACK_REQUIRED';
        $codes[] = 'gate_blocked';
    }
    if ($crit > 0 || $evidenceCritical) {
        $level = 'ROLLBACK_REQUIRED';
        $codes[] = 'critical_evaluation_failure';
    }

    $metrics = is_array($signals['observability_metrics'] ?? null) ? $signals['observability_metrics'] : null;
    if ($metrics !== null && $level === 'NO_ROLLBACK') {
        $fb = $metrics['path_rates']['fallback_rate'] ?? null;
        $thr = (float) ($policy['rollback_signals']['fallback_rate_threshold']['max'] ?? 1);
        $minTurns = (int) ($policy['rollback_signals']['fallback_rate_threshold']['min_turns'] ?? 99999);
        $total = (int) ($metrics['volume']['total_turns'] ?? 0);
        if (is_numeric($fb) && $total >= $minTurns && (float) $fb > $thr) {
            $level = 'ROLLBACK_RECOMMENDED';
            $codes[] = 'fallback_spike';
        }
    }

    $rollbackStage = (string) ($policy['stages'][$currentStage]['rollback_stage'] ?? $policy['known_good_stage'] ?? '0');
    if (!isset($policy['stages'][$rollbackStage])) {
        $rollbackStage = (string) ($policy['known_good_stage'] ?? '0');
    }
    $proposed = phase310_proposed_config_for_stage($policy, $rollbackStage);

    return [
        'rollback_level'   => $level,
        'reason_codes'     => $codes,
        'current_stage'    => $currentStage,
        'rollback_stage'   => $rollbackStage,
        'proposed_config'  => $proposed,
        'preserve_master'  => true,
        'note'             => 'Operator must apply config manually; this module does not write config.local.php',
    ];
}

/**
 * @param list<int> $approved
 * @param list<array<string, mixed>> $rejected
 * @param array<string, mixed>|null $gate
 * @param array<string, mixed>|null $evalReport
 * @param list<array<string, mixed>> $reasons
 * @return array<string, mixed>
 */
function phase310_audit(
    string $timestamp,
    string $from,
    string $to,
    array $approved,
    array $rejected,
    ?array $gate,
    ?array $evalReport,
    string $decision,
    array $reasons,
    array $policy
): array {
    $rollbackStage = (string) ($policy['stages'][$from]['rollback_stage'] ?? '0');

    return [
        'version'              => PHASE310_ROLLOUT_VERSION,
        'timestamp'            => $timestamp,
        'candidate_stage'      => $to,
        'previous_stage'       => $from,
        'candidate_bot_ids'    => phase310_stage_bot_ids($policy, $to),
        'approved_bot_ids'     => $approved,
        'rejected_bot_ids'     => $rejected,
        'release_gate_status'  => (string) ($gate['status'] ?? 'NOT_AVAILABLE'),
        'evaluation_status'    => $evalReport === null ? 'NOT_AVAILABLE' : 'OBSERVED',
        'critical_failures'    => (int) ($evalReport['critical_failure_count'] ?? 0),
        'evidence_status'      => (string) ($gate['evaluation_summary']['run_id'] ?? 'NOT_AVAILABLE'),
        'eligibility_status'   => $rejected === [] ? 'PASS' : 'FAIL',
        'decision'             => $decision,
        'reason_codes'         => $reasons,
        'rollback_stage'       => $rollbackStage,
        'proposed_config'      => phase310_proposed_config_for_stage($policy, $to),
        'next_stage'           => phase310_next_stage($policy, $to),
        'manual_apply_required'=> true,
    ];
}

/** @param array<string, mixed> $policy */
function phase310_next_stage(array $policy, string $current): ?string
{
    foreach (is_array($policy['allowed_transitions'] ?? null) ? $policy['allowed_transitions'] : [] as $pair) {
        if (is_array($pair) && (string) ($pair[0] ?? '') === $current) {
            return (string) ($pair[1] ?? '');
        }
    }

    return null;
}

/**
 * Simulate runtime eligibility (requires bootstrap loaded).
 *
 * @param array<string, mixed> $bot
 */
function phase310_simulate_core_enabled(array $bot, bool $masterOn, array $rolloutIds): bool
{
    $GLOBALS['agent_core_enabled_override'] = $masterOn;
    $GLOBALS['agent_core_rollout_bot_ids_override'] = $rolloutIds;
    $enabled = agent_core_enabled($bot, 'whatsapp');
    unset($GLOBALS['agent_core_enabled_override'], $GLOBALS['agent_core_rollout_bot_ids_override']);

    return $enabled;
}
