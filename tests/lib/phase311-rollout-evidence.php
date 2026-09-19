<?php
/**
 * Phase 3.11 — production rollout operations evidence & cohort review (offline; no config writes).
 */
declare(strict_types=1);

require_once __DIR__ . '/phase310-rollout.php';
require_once __DIR__ . '/phase37-observability.php';

const PHASE311_EVIDENCE_VERSION = '3.11.0';

/** @return list<string> */
function phase311_evidence_states(): array
{
    return [
        'NOT_STARTED',
        'COLLECTING',
        'SUFFICIENT',
        'INSUFFICIENT',
        'CRITICAL_FAILURE',
        'READY_FOR_REVIEW',
    ];
}

/** @return list<string> */
function phase311_review_decisions(): array
{
    return ['EXPAND', 'HOLD', 'ROLLBACK', 'BLOCKED'];
}

/**
 * @param array<string, mixed> $package
 * @return list<string>
 */
function phase311_validate_evidence_package_shape(array $package): array
{
    $errors = [];
    $schemaPath = dirname(__DIR__) . '/phase311-rollout-evidence-schema.json';
    $schema = is_readable($schemaPath)
        ? (json_decode((string) file_get_contents($schemaPath), true) ?: [])
        : [];
    foreach (is_array($schema['required_fields'] ?? null) ? $schema['required_fields'] : [] as $field) {
        if (!array_key_exists($field, $package)) {
            $errors[] = 'missing field: ' . $field;
        }
    }
    $state = (string) ($package['evidence_state'] ?? '');
    if ($state !== '' && !in_array($state, phase311_evidence_states(), true)) {
        $errors[] = 'invalid evidence_state: ' . $state;
    }

    return $errors;
}

/**
 * Normalize audit batches keyed by bot_id → list of n_events shapes.
 *
 * @param array<string, mixed> $input
 * @return array<int, list<array<string, mixed>>>
 */
function phase311_audit_batches_by_bot(array $input): array
{
    $out = [];
    $batches = is_array($input['production_audit_batches'] ?? null) ? $input['production_audit_batches'] : [];
    foreach ($batches as $batch) {
        if (!is_array($batch)) {
            continue;
        }
        $nEvents = is_array($batch['n_events'] ?? null) ? $batch['n_events'] : $batch;
        $meta = phase36_extract_turn_meta(phase36_parse_audit_events($nEvents));
        $botId = (int) ($batch['bot_id'] ?? $meta['bot_id'] ?? 0);
        if ($botId <= 0) {
            continue;
        }
        $nEvents['select_only'] = $nEvents['select_only'] ?? true;
        $nEvents['turn_id'] = $nEvents['turn_id'] ?? ($meta['turn_id'] ?? 0);
        $out[$botId] ??= [];
        $out[$botId][] = $nEvents;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $nEventsList
 * @return array<string, mixed>
 */
function phase311_summarize_bot_audit(int $botId, array $nEventsList): array
{
    $turns = [];
    foreach ($nEventsList as $nEvents) {
        if (!is_array($nEvents)) {
            continue;
        }
        $turns[] = phase37_normalize_turn_from_n_events($nEvents, ['bot_id' => $botId]);
    }
    $metrics = $turns !== [] ? phase37_aggregate_metrics($turns) : null;

    $coreTurns = 0;
    $fallbackCount = 0;
    $notVerified = 0;
    foreach ($turns as $t) {
        $path = (string) ($t['path'] ?? 'unknown');
        if ($path === 'agent_core') {
            $coreTurns++;
        } elseif ($path === 'fallback') {
            $fallbackCount++;
        } elseif ($path === 'unknown' || $path === 'NOT_VERIFIED') {
            $notVerified++;
        }
    }

    return [
        'bot_id'              => $botId,
        'turns_observed'      => count($turns),
        'recent_core_turns'   => $coreTurns,
        'fallback_count'      => $fallbackCount,
        'not_verified_turns'  => $notVerified,
        'observability'       => $metrics,
        'turns'               => $turns,
    ];
}

/**
 * CONFIGURED / ELIGIBLE / EFFECTIVE bot verification.
 *
 * @param array<string, mixed> $bot
 * @param list<int> $configuredRolloutIds empty = all eligible allowed by rollout gate
 * @param list<array<string, mixed>>|null $nEventsList
 * @return array<string, mixed>
 */
function phase311_verify_bot(
    array $bot,
    bool $masterEnabled,
    array $configuredRolloutIds,
    ?array $nEventsList = null
): array {
    $botId = (int) ($bot['id'] ?? 0);
    $eligible = agent_core_bot_eligible($bot, 'whatsapp');
    $onRolloutList = $configuredRolloutIds === [] || in_array($botId, $configuredRolloutIds, true);

    $configured = 'OFF_ROLLOUT';
    if ($configuredRolloutIds === []) {
        $configured = 'ALL_ELIGIBLE';
    } elseif ($onRolloutList) {
        $configured = 'ON_ROLLOUT';
    }

    $expectedEffective = $masterEnabled && $eligible && ($configuredRolloutIds === [] || $onRolloutList);
    $effective = $expectedEffective ? 'CORE_ON' : 'CORE_OFF';

    $auditSummary = null;
    $effectiveObserved = 'NOT_VERIFIED';
    if ($nEventsList !== null && $nEventsList !== []) {
        $auditSummary = phase311_summarize_bot_audit($botId, $nEventsList);
        if ($auditSummary['recent_core_turns'] > 0) {
            $effectiveObserved = $expectedEffective ? 'CORE_ON' : 'MISMATCH_CORE_ACTIVE';
        } elseif ($auditSummary['fallback_count'] > 0) {
            $effectiveObserved = 'FALLBACK_OBSERVED';
        } elseif ($auditSummary['turns_observed'] > 0) {
            $effectiveObserved = 'NOT_VERIFIED';
        }
    }

    $mismatch = false;
    if ($effectiveObserved === 'MISMATCH_CORE_ACTIVE') {
        $mismatch = true;
    }
    if ($expectedEffective && $effectiveObserved === 'NOT_VERIFIED' && $nEventsList !== null && $nEventsList !== []) {
        $mismatch = true;
    }
    if (!$expectedEffective && $effectiveObserved === 'CORE_ON') {
        $mismatch = true;
    }

    $lastTurn = null;
    if (is_array($auditSummary) && ($auditSummary['turns'][0] ?? null) !== null) {
        $lastTurn = $auditSummary['turns'][count($auditSummary['turns']) - 1];
    }

    return [
        'bot_id'                 => $botId,
        'eligibility'            => $eligible ? 'ELIGIBLE' : 'INELIGIBLE',
        'configured_rollout'     => $configured,
        'effective_expected'     => $effective,
        'effective_observed'     => $effectiveObserved,
        'configuration_mismatch' => $mismatch,
        'recent_core_turns'      => (int) ($auditSummary['recent_core_turns'] ?? 0),
        'fallback_count'         => (int) ($auditSummary['fallback_count'] ?? 0),
        'validation'             => is_array($lastTurn) ? ($lastTurn['validation'] ?? null) : null,
        'action'                 => is_array($lastTurn) ? ($lastTurn['action'] ?? null) : null,
        'delivery'               => is_array($lastTurn) ? ($lastTurn['delivery'] ?? null) : null,
    ];
}

/**
 * Read-only production rollout verification snapshot.
 *
 * @param array<string, mixed> $config master_enabled, rollout_bot_ids (list<int>)
 * @param list<array<string, mixed>> $bots
 * @param array<string, mixed> $input optional production_audit_batches
 */
function phase311_verify_production_rollout(array $config, array $bots, array $input = []): array
{
    $master = !empty($config['master_enabled']);
    $rolloutIds = [];
    foreach (is_array($config['rollout_bot_ids'] ?? null) ? $config['rollout_bot_ids'] : [] as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $rolloutIds[] = $id;
        }
    }
    $byBotAudit = phase311_audit_batches_by_bot($input);
    $botReports = [];
    foreach ($bots as $bot) {
        if (!is_array($bot)) {
            continue;
        }
        $id = (int) ($bot['id'] ?? 0);
        $botReports[] = phase311_verify_bot(
            $bot,
            $master,
            $rolloutIds,
            $byBotAudit[$id] ?? null
        );
    }

    $activeCohort = [];
    foreach ($botReports as $r) {
        if (($r['configured_rollout'] ?? '') === 'ON_ROLLOUT' || ($r['configured_rollout'] ?? '') === 'ALL_ELIGIBLE') {
            if (($r['eligibility'] ?? '') === 'ELIGIBLE' && $master) {
                if ($rolloutIds === [] || ($r['configured_rollout'] ?? '') === 'ON_ROLLOUT') {
                    $activeCohort[] = (int) $r['bot_id'];
                }
            }
        }
    }

    $mismatches = array_values(array_filter($botReports, static fn ($r) => !empty($r['configuration_mismatch'])));

    return [
        'version'              => PHASE311_EVIDENCE_VERSION,
        'master_enabled'       => $master,
        'configured_rollout'   => $rolloutIds,
        'active_cohort'        => array_values(array_unique($activeCohort)),
        'bots'                 => $botReports,
        'configuration_mismatch_count' => count($mismatches),
        'read_only'            => true,
    ];
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $opsPolicy from schema
 */
function phase311_classify_evidence_state(array $input, array $opsPolicy): string
{
    if (!empty($input['production_evidence_critical'])) {
        return 'CRITICAL_FAILURE';
    }
    $crit = (int) ($input['critical_failure_count'] ?? ($input['evaluation_report']['critical_failure_count'] ?? 0));
    if ($crit > 0) {
        return 'CRITICAL_FAILURE';
    }

    $batches = phase311_audit_batches_by_bot($input);
    if ($batches === []) {
        return (string) ($input['evidence_state_hint'] ?? 'NOT_STARTED');
    }

    $minTurns = (int) ($opsPolicy['min_core_turns_for_sufficient'] ?? 1);
    $minBots = (int) ($opsPolicy['min_bots_with_verified_core'] ?? 1);
    $verifiedBots = 0;
    $totalCore = 0;
    foreach ($batches as $botId => $list) {
        $sum = phase311_summarize_bot_audit((int) $botId, $list);
        $totalCore += (int) $sum['recent_core_turns'];
        if ($sum['recent_core_turns'] >= $minTurns) {
            $verifiedBots++;
        }
    }

    if ($totalCore === 0) {
        return 'INSUFFICIENT';
    }
    if ($verifiedBots < $minBots) {
        return 'COLLECTING';
    }

    return 'SUFFICIENT';
}

/**
 * Build structured evidence package (does not fetch network).
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $rolloutPolicy phase310 policy
 * @param array<string, mixed> $opsPolicy
 */
function phase311_build_evidence_package(array $input, array $rolloutPolicy, array $opsPolicy): array
{
    $gate = is_array($input['release_gate'] ?? null) ? $input['release_gate'] : [];
    $eval = is_array($input['evaluation_report'] ?? null) ? $input['evaluation_report'] : [];
    $stage = (string) ($input['rollout_stage'] ?? '0');
    $prev = (string) ($input['previous_stage'] ?? '0');
    $activeBots = phase310_stage_bot_ids($rolloutPolicy, $stage);
    $candidate = is_array($input['candidate_bots'] ?? null)
        ? array_map('intval', $input['candidate_bots'])
        : $activeBots;

    $evidenceState = phase311_classify_evidence_state(array_merge($input, [
        'critical_failure_count' => (int) ($eval['critical_failure_count'] ?? 0),
    ]), $opsPolicy);

    if ($evidenceState === 'SUFFICIENT' && ($input['operator_ready_for_review'] ?? false)) {
        $evidenceState = 'READY_FOR_REVIEW';
    }

    $auditByBot = phase311_audit_batches_by_bot($input);
    $prodEvidence = [];
    foreach ($auditByBot as $botId => $list) {
        $prodEvidence[(string) $botId] = phase311_summarize_bot_audit((int) $botId, $list);
    }

    $allTurns = [];
    foreach ($prodEvidence as $row) {
        foreach (is_array($row['turns'] ?? null) ? $row['turns'] : [] as $t) {
            $allTurns[] = $t;
        }
    }
    $obsSummary = $allTurns !== [] ? phase37_aggregate_metrics($allTurns) : null;

    $rollback = phase310_evaluate_rollback([
        'current_stage'               => $stage,
        'release_gate_status'         => (string) ($gate['status'] ?? 'BLOCKED'),
        'critical_failures'           => (int) ($eval['critical_failure_count'] ?? 0),
        'production_evidence_critical'=> !empty($input['production_evidence_critical']),
        'observability_metrics'       => is_array($obsSummary) ? [
            'volume'     => ['total_turns' => (int) ($obsSummary['volume']['total_turns'] ?? 0)],
            'path_rates' => ['fallback_rate' => (float) ($obsSummary['path_rates']['fallback_rate'] ?? 0)],
        ] : null,
    ], $rolloutPolicy);

    $package = [
        'version'                 => PHASE311_EVIDENCE_VERSION,
        'release_commit'          => (string) ($input['release_commit'] ?? ''),
        'rollout_stage'           => $stage,
        'previous_stage'          => $prev,
        'candidate_bots'          => $candidate,
        'active_bots'             => $activeBots,
        'rollout_timestamp'       => (string) ($input['rollout_timestamp'] ?? date('c')),
        'operator_decision'       => (string) ($input['operator_decision'] ?? 'NOT_RECORDED'),
        'release_gate'            => $gate,
        'release_gate_status'     => (string) ($gate['status'] ?? 'NOT_AVAILABLE'),
        'evaluation_summary'      => [
            'run_id'                  => (string) ($eval['run_id'] ?? ''),
            'critical_failure_count'  => (int) ($eval['critical_failure_count'] ?? 0),
            'pass_count'              => (int) ($eval['pass_count'] ?? 0),
            'fail_count'              => (int) ($eval['fail_count'] ?? 0),
            'blocked_count'           => (int) ($eval['blocked_count'] ?? 0),
        ],
        'critical_failure_count'  => (int) ($eval['critical_failure_count'] ?? 0),
        'production_evidence'     => $prodEvidence,
        'observability_summary'   => $obsSummary,
        'fallback_summary'        => is_array($obsSummary) ? ($obsSummary['path_rates'] ?? null) : null,
        'delivery_summary'        => is_array($obsSummary) ? ($obsSummary['delivery'] ?? null) : null,
        'evidence_state'              => $evidenceState,
        'rollback_status'             => $rollback,
        'operator_approved_expansion' => !empty($input['operator_approved_expansion']),
        'manual_apply_required'       => true,
    ];

    $package['next_decision'] = phase311_review_cohort(
        $package,
        $rolloutPolicy,
        $opsPolicy,
        is_array($input['rollout_proposal'] ?? null) ? $input['rollout_proposal'] : null
    );

    return $package;
}

/**
 * Operational recommendation (not an automatic production action).
 *
 * @param array<string, mixed> $package
 * @param array<string, mixed>|null $rolloutProposal phase310 expansion audit
 * @return array<string, mixed>
 */
function phase311_review_cohort(array $package, array $rolloutPolicy, array $opsPolicy, ?array $rolloutProposal = null): array
{
    $reasons = [];
    $gateStatus = (string) ($package['release_gate_status'] ?? 'BLOCKED');
    $crit = (int) ($package['critical_failure_count'] ?? 0);
    $evidenceState = (string) ($package['evidence_state'] ?? 'NOT_STARTED');
    $rollbackLevel = (string) (($package['rollback_status']['rollback_level'] ?? 'NO_ROLLBACK'));

    if ($crit > 0 || $evidenceState === 'CRITICAL_FAILURE') {
        $reasons[] = ['code' => 'SAFETY_ROLLBACK', 'message' => 'Critical failure'];

        return phase311_review_result('ROLLBACK', $reasons, $rolloutPolicy, $package);
    }
    if ($rollbackLevel === 'ROLLBACK_REQUIRED' && !empty($package['production_evidence_critical'])) {
        $reasons[] = ['code' => 'PRODUCTION_EVIDENCE_CRITICAL', 'message' => 'Production evidence critical'];

        return phase311_review_result('ROLLBACK', $reasons, $rolloutPolicy, $package);
    }

    if ($gateStatus === 'BLOCKED') {
        $reasons[] = ['code' => 'GATE_BLOCKED', 'message' => 'Release gate BLOCKED'];

        return phase311_review_result('BLOCKED', $reasons, $rolloutPolicy, $package);
    }
    if ($gateStatus === 'FAIL') {
        $reasons[] = ['code' => 'GATE_FAIL', 'message' => 'Release gate FAIL'];

        return phase311_review_result('ROLLBACK', $reasons, $rolloutPolicy, $package);
    }

    if ($rollbackLevel === 'ROLLBACK_RECOMMENDED') {
        $reasons[] = ['code' => 'METRIC_ROLLBACK_RECOMMENDED', 'message' => 'Observability threshold exceeded'];

        return phase311_review_result('HOLD', $reasons, $rolloutPolicy, $package);
    }

    $allowedStates = is_array($opsPolicy['expansion_requires_evidence_states'] ?? null)
        ? $opsPolicy['expansion_requires_evidence_states']
        : ['SUFFICIENT', 'READY_FOR_REVIEW'];
    if (!in_array($evidenceState, $allowedStates, true)) {
        $reasons[] = ['code' => 'EVIDENCE_NOT_READY', 'message' => 'Evidence state ' . $evidenceState];

        return phase311_review_result('HOLD', $reasons, $rolloutPolicy, $package);
    }

    if ($rolloutProposal === null || ($rolloutProposal['decision'] ?? '') !== 'APPROVED') {
        $reasons[] = ['code' => 'NO_APPROVED_PROPOSAL', 'message' => 'Phase 3.10 rollout proposal not APPROVED'];

        return phase311_review_result('HOLD', $reasons, $rolloutPolicy, $package);
    }

    if (!empty($opsPolicy['require_operator_approval_flag_for_expand'])
        && empty($package['operator_approved_expansion'])) {
        $reasons[] = ['code' => 'MANUAL_APPROVAL_PENDING', 'message' => 'Operator approval not recorded'];

        return phase311_review_result('HOLD', $reasons, $rolloutPolicy, $package);
    }

    $rollbackStage = (string) ($rolloutPolicy['stages'][$package['rollout_stage'] ?? '']['rollback_stage'] ?? '');
    if ($rollbackStage !== '' && !isset($rolloutPolicy['stages'][$rollbackStage])) {
        $reasons[] = ['code' => 'MISSING_ROLLBACK_TARGET', 'message' => 'Rollback stage unavailable'];
        return phase311_review_result('BLOCKED', $reasons, $rolloutPolicy, $package);
    }

    if (is_array($rolloutProposal['rejected_bot_ids'] ?? null) && $rolloutProposal['rejected_bot_ids'] !== []) {
        $reasons[] = ['code' => 'INELIGIBLE_BOT', 'message' => 'Rejected bot in proposal'];
        return phase311_review_result('BLOCKED', $reasons, $rolloutPolicy, $package);
    }

    $reasons[] = ['code' => 'COHORT_READY', 'message' => 'Gate pass, evidence sufficient, proposal approved'];

    return phase311_review_result('EXPAND', $reasons, $rolloutPolicy, $package, $rolloutProposal);
}

/**
 * @param list<array<string, mixed>> $reasons
 * @return array<string, mixed>
 */
function phase311_review_result(
    string $decision,
    array $reasons,
    array $rolloutPolicy,
    array $package,
    ?array $rolloutProposal = null
): array {
    $stage = (string) ($package['rollout_stage'] ?? '0');
    $targetStage = is_array($rolloutProposal) ? (string) ($rolloutProposal['candidate_stage'] ?? '') : '';

    return [
        'decision'              => $decision,
        'reason_codes'          => $reasons,
        'manual_apply_required' => true,
        'target_stage'          => $targetStage !== '' ? $targetStage : null,
        'proposed_config'       => $targetStage !== '' ? phase310_proposed_config_for_stage($rolloutPolicy, $targetStage) : null,
        'rollback_stage'        => (string) ($rolloutPolicy['stages'][$stage]['rollback_stage'] ?? '0'),
    ];
}

/**
 * Snapshot config from runtime constants (read-only).
 *
 * @return array{master_enabled: bool, rollout_bot_ids: list<int>, rollout_bot_ids_raw: string}
 */
function phase311_read_configured_rollout(): array
{
    if (!function_exists('agent_core_master_enabled')) {
        require_once dirname(__DIR__, 2) . '/includes/agent-core/bootstrap.php';
    }

    return [
        'master_enabled'       => agent_core_master_enabled(),
        'rollout_bot_ids'      => agent_core_rollout_bot_ids(),
        'rollout_bot_ids_raw'  => defined('AGENT_CORE_ROLLOUT_BOT_IDS') ? (string) AGENT_CORE_ROLLOUT_BOT_IDS : '',
    ];
}
