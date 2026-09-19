<?php
/**
 * Phase 3.8 — offline agent evaluation (deterministic, evidence-based).
 * Pipeline: Phase 3.6 evidence → Phase 3.7 normalization → Phase 3.8 evaluation.
 */
declare(strict_types=1);

require_once __DIR__ . '/phase37-observability.php';

const PHASE38_EVALUATOR_VERSION = '3.8.0';

/** @return list<string> */
function phase38_assertion_statuses(): array
{
    return ['PASS', 'FAIL', 'BLOCKED', 'NOT_VERIFIED', 'NOT_APPLICABLE'];
}

/** @return list<string> */
function phase38_critical_failure_ids(): array
{
    return [
        'fabricated_action_completion',
        'validation_false_action_leaked',
        'unverified_booking_claim',
        'support_path_sales_cta',
        'unexpected_agent_core_fallback',
    ];
}

/**
 * @param array<string, mixed> $normalized from phase37_normalize_turn_from_n_events
 * @param array<string, mixed> $assertion
 * @return array<string, mixed>
 */
function phase38_evaluate_assertion(array $normalized, array $assertion): array
{
    $id = (string) ($assertion['assertion_id'] ?? 'assert');
    $type = (string) ($assertion['type'] ?? '');
    $expected = $assertion['expected'] ?? null;
    $critical = !empty($assertion['critical']);

    $base = [
        'assertion_id' => $id,
        'type'         => $type,
        'critical'     => $critical,
        'expected'     => $expected,
        'observed'     => null,
        'status'       => 'NOT_VERIFIED',
        'reason'       => '',
        'evidence'     => [
            'turn_id' => $normalized['turn_id'] ?? null,
            'bot_id'  => $normalized['bot_id'] ?? null,
        ],
    ];

    $path = (string) ($normalized['path'] ?? 'unknown');
    $intel = is_array($normalized['intelligence'] ?? null) ? $normalized['intelligence'] : [];
    $dec = is_array($normalized['decision'] ?? null) ? $normalized['decision'] : [];
    $val = is_array($normalized['validation'] ?? null) ? $normalized['validation'] : [];
    $act = is_array($normalized['action'] ?? null) ? $normalized['action'] : [];
    $del = is_array($normalized['delivery'] ?? null) ? $normalized['delivery'] : [];
    $stages = is_array($normalized['stages'] ?? null) ? $normalized['stages'] : [];

    switch ($type) {
        case 'path_equals':
            $base['observed'] = $path;
            $base['status'] = ($path === (string) $expected) ? 'PASS' : 'FAIL';
            $base['reason'] = $base['status'] === 'PASS' ? 'path matches' : 'path mismatch';
            break;
        case 'path_not_fallback':
            $base['observed'] = $path;
            $base['status'] = $path !== 'fallback' ? 'PASS' : 'FAIL';
            $base['reason'] = $path === 'fallback' ? 'unexpected fallback' : 'no fallback';
            break;
        case 'intent_equals':
            $obs = $intel['intent'] ?? null;
            $base['observed'] = $obs;
            if ($obs === null) {
                $base['status'] = 'NOT_VERIFIED';
                $base['reason'] = 'intent not in normalized evidence';
            } else {
                $base['status'] = ((string) $obs === (string) $expected) ? 'PASS' : 'FAIL';
                $base['reason'] = $base['status'] === 'PASS' ? 'intent matches' : 'intent mismatch';
            }
            break;
        case 'intent_present':
            $obs = $intel['intent'] ?? null;
            $base['observed'] = $obs;
            $base['status'] = ($obs !== null && $obs !== '') ? 'PASS' : 'NOT_VERIFIED';
            $base['reason'] = $base['status'] === 'PASS' ? 'intent observed' : 'intent absent';
            break;
        case 'action_equals':
            $obs = $dec['action'] ?? $act['type'] ?? null;
            $base['observed'] = $obs;
            if ($obs === null) {
                $base['status'] = 'NOT_VERIFIED';
                $base['reason'] = 'action not observable';
            } else {
                $base['status'] = ((string) $obs === (string) $expected) ? 'PASS' : 'FAIL';
                $base['reason'] = $base['status'] === 'PASS' ? 'action matches' : 'action mismatch';
            }
            break;
        case 'cta_mode_equals':
            $obs = $dec['cta_mode'] ?? null;
            $base['observed'] = $obs;
            if ($obs === null) {
                $base['status'] = 'NOT_VERIFIED';
                $base['reason'] = 'cta_mode not in evidence';
            } else {
                $base['status'] = ((string) $obs === (string) $expected) ? 'PASS' : 'FAIL';
                $base['reason'] = $base['status'] === 'PASS' ? 'cta_mode matches' : 'cta_mode mismatch';
            }
            break;
        case 'customer_need_equals':
            $obs = $dec['customer_need'] ?? null;
            $base['observed'] = $obs;
            if ($obs === null) {
                $base['status'] = 'NOT_VERIFIED';
                $base['reason'] = 'customer_need not in evidence';
            } else {
                $base['status'] = ((string) $obs === (string) $expected) ? 'PASS' : 'FAIL';
                $base['reason'] = $base['status'] === 'PASS' ? 'customer_need matches' : 'mismatch';
            }
            break;
        case 'stage_observed':
            $stage = (string) $expected;
            $base['observed'] = $stages;
            $base['status'] = in_array($stage, $stages, true) ? 'PASS' : 'FAIL';
            $base['reason'] = $base['status'] === 'PASS' ? 'stage observed' : 'stage NOT_OBSERVED in evidence';
            break;
        case 'validation_executed':
            $base['observed'] = !empty($val['executed']);
            $base['status'] = !empty($val['executed']) ? 'PASS' : 'NOT_VERIFIED';
            $base['reason'] = $base['status'] === 'PASS' ? 'CORE_VALIDATE present' : 'validation not observed';
            break;
        case 'validation_passed':
            $base['observed'] = ($val['status'] ?? null);
            $base['status'] = (!empty($val['executed']) && empty($val['blocked'])) ? 'PASS' : 'FAIL';
            $base['reason'] = empty($val['blocked']) ? 'validation passed' : 'validation blocked or missing';
            break;
        case 'validation_blocked':
            $base['observed'] = !empty($val['blocked']);
            $base['status'] = !empty($val['blocked']) ? 'PASS' : 'FAIL';
            $base['reason'] = !empty($val['blocked']) ? 'validation blocked as expected' : 'validation not blocked';
            break;
        case 'validation_reason_equals':
            $obs = $val['reason'] ?? null;
            $base['observed'] = $obs;
            if ($obs === null) {
                $base['status'] = 'NOT_VERIFIED';
                $base['reason'] = 'validation reason absent';
            } else {
                $base['status'] = ((string) $obs === (string) $expected) ? 'PASS' : 'FAIL';
                $base['reason'] = $base['status'] === 'PASS' ? 'reason matches' : 'reason mismatch';
            }
            break;
        case 'action_executed':
            $base['observed'] = !empty($act['executed']);
            $base['status'] = !empty($act['executed']) ? 'PASS' : 'FAIL';
            $base['reason'] = $base['status'] === 'PASS' ? 'tool execution observed' : 'no executed action';
            break;
        case 'action_verified':
            $base['observed'] = !empty($act['verified']);
            $base['status'] = !empty($act['verified']) ? 'PASS' : 'FAIL';
            $base['reason'] = $base['status'] === 'PASS' ? 'verified action' : 'verification absent';
            break;
        case 'action_not_verified':
            $base['observed'] = !empty($act['verified']);
            $base['status'] = empty($act['verified']) ? 'PASS' : 'FAIL';
            $base['reason'] = empty($act['verified']) ? 'not verified' : 'unexpected verification';
            break;
        case 'delivery_succeeded':
            $base['observed'] = !empty($del['succeeded']);
            $base['status'] = !empty($del['succeeded']) ? 'PASS' : 'FAIL';
            $base['reason'] = $base['status'] === 'PASS' ? 'RESPONSE_SENT observed' : 'delivery not confirmed';
            break;
        case 'fallback_reason_equals':
            $obs = $normalized['fallback_reason'] ?? null;
            $base['observed'] = $obs;
            if ($obs === null) {
                $base['status'] = 'NOT_VERIFIED';
                $base['reason'] = 'no fallback reason';
            } else {
                $base['status'] = ((string) $obs === (string) $expected) ? 'PASS' : 'FAIL';
                $base['reason'] = $base['status'] === 'PASS' ? 'fallback reason matches' : 'fallback reason mismatch';
            }
            break;
        case 'no_unverified_booking_execution':
            $bookingExec = !empty($act['executed']) && str_contains((string) ($act['type'] ?? ''), 'booking');
            $verified = !empty($act['verified']);
            $base['observed'] = ['executed' => $bookingExec, 'verified' => $verified];
            if (!$bookingExec) {
                $base['status'] = 'NOT_APPLICABLE';
                $base['reason'] = 'no booking execution observed';
            } else {
                $base['status'] = $verified ? 'PASS' : 'FAIL';
                $base['reason'] = $verified ? 'booking verified' : 'booking executed without verification signal';
            }
            break;
        default:
            $base['status'] = 'BLOCKED';
            $base['reason'] = 'unknown assertion type: ' . $type;
    }

    if ($base['status'] === 'FAIL' && $critical) {
        $base['critical_failure_id'] = phase38_map_critical_failure($type, $normalized, $assertion);
    }

    return $base;
}

/**
 * @param array<string, mixed> $normalized
 * @param array<string, mixed> $assertion
 */
function phase38_map_critical_failure(string $type, array $normalized, array $assertion): string
{
    $val = is_array($normalized['validation'] ?? null) ? $normalized['validation'] : [];
    if ($type === 'validation_passed' && (string) ($val['reason'] ?? '') === 'false_action') {
        return 'validation_false_action_leaked';
    }
    if ($type === 'no_unverified_booking_execution') {
        return 'unverified_booking_claim';
    }
    if ($type === 'path_not_fallback' || ($type === 'path_equals' && ($assertion['expected'] ?? '') === 'agent_core')) {
        return 'unexpected_agent_core_fallback';
    }
    if ($type === 'action_verified') {
        return 'fabricated_action_completion';
    }
    if ($type === 'cta_mode_equals' && (string) ($assertion['expected'] ?? '') !== 'stop') {
        $need = (string) (($normalized['decision']['customer_need'] ?? '') ?: '');
        if ($need === 'resolve_support' || $need === 'resolve_delivery') {
            return 'support_path_sales_cta';
        }
    }

    return 'fabricated_action_completion';
}

/**
 * @param array<string, mixed> $normalized
 * @param array<string, mixed> $scenario
 * @return array<string, mixed>
 */
function phase38_evaluate_scenario(array $normalized, array $scenario): array
{
    $scenarioId = (string) ($scenario['scenario_id'] ?? '');
    $assertions = [];
    $criticalFailures = [];
    foreach (is_array($scenario['assertions'] ?? null) ? $scenario['assertions'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $result = phase38_evaluate_assertion($normalized, $row);
        $assertions[] = $result;
        if (($result['status'] ?? '') === 'FAIL' && !empty($result['critical_failure_id'])) {
            $criticalFailures[] = (string) $result['critical_failure_id'];
        }
    }

    $status = 'PASS';
    foreach ($assertions as $a) {
        $st = (string) ($a['status'] ?? '');
        if ($st === 'FAIL') {
            $status = 'FAIL';
            break;
        }
        if ($st === 'BLOCKED' && $status === 'PASS') {
            $status = 'BLOCKED';
        }
        if ($st === 'NOT_VERIFIED' && $status === 'PASS') {
            $status = 'NOT_VERIFIED';
        }
    }

    return [
        'scenario_id'       => $scenarioId,
        'name'              => (string) ($scenario['name'] ?? ''),
        'dimension'         => (string) ($scenario['dimension'] ?? ''),
        'status'            => $status,
        'score'             => null,
        'assertions'        => $assertions,
        'critical_failures' => array_values(array_unique($criticalFailures)),
        'evidence'          => [
            'turn_id'         => $normalized['turn_id'] ?? null,
            'conversation_id' => $normalized['conversation_id'] ?? null,
            'bot_id'          => $normalized['bot_id'] ?? null,
            'path'            => $normalized['path'] ?? null,
        ],
        'notes'             => [],
    ];
}

/**
 * @param array<string, mixed> $nEvents
 * @param array<string, mixed> $scenario
 * @param array<string, mixed> $meta
 */
function phase38_evaluate_from_n_events(array $nEvents, array $scenario, array $meta = []): array
{
    $normalized = phase37_normalize_turn_from_n_events($nEvents, $meta);

    return phase38_evaluate_scenario($normalized, $scenario);
}

/**
 * @param list<array<string, mixed>> $scenarioResults
 * @return array<string, mixed>
 */
function phase38_build_evaluation_report(string $runId, array $scenarioResults, string $datasetVersion = '1'): array
{
    $counts = ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0, 'NOT_VERIFIED' => 0];
    $criticalTotal = 0;
    foreach ($scenarioResults as $row) {
        $st = (string) ($row['status'] ?? 'NOT_VERIFIED');
        if (isset($counts[$st])) {
            $counts[$st]++;
        } else {
            $counts['NOT_VERIFIED']++;
        }
        $criticalTotal += count(is_array($row['critical_failures'] ?? null) ? $row['critical_failures'] : []);
    }

    return [
        'run_id'            => $runId,
        'evaluator_version' => PHASE38_EVALUATOR_VERSION,
        'dataset_version'   => $datasetVersion,
        'scenario_count'    => count($scenarioResults),
        'pass_count'        => $counts['PASS'],
        'fail_count'        => $counts['FAIL'],
        'blocked_count'     => $counts['BLOCKED'],
        'not_verified_count'=> $counts['NOT_VERIFIED'],
        'critical_failure_count' => $criticalTotal,
        'regression_count'  => 0,
        'scenario_results'  => $scenarioResults,
    ];
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $current
 * @return array<string, mixed>
 */
function phase38_compare_runs(array $baseline, array $current): array
{
    $baseMap = [];
    foreach (is_array($baseline['scenario_results'] ?? null) ? $baseline['scenario_results'] : [] as $row) {
        $baseMap[(string) ($row['scenario_id'] ?? '')] = $row;
    }
    $newPass = [];
    $newFail = [];
    $unchanged = [];
    $blockedChanges = [];
    $criticalChanges = [];
    $regressions = [];

    foreach (is_array($current['scenario_results'] ?? null) ? $current['scenario_results'] : [] as $row) {
        $id = (string) ($row['scenario_id'] ?? '');
        $curSt = (string) ($row['status'] ?? '');
        $prev = $baseMap[$id] ?? null;
        if ($prev === null) {
            $newPass[] = $id;
            continue;
        }
        $prevSt = (string) ($prev['status'] ?? '');
        if ($prevSt === $curSt) {
            $unchanged[] = $id;
        } elseif ($prevSt === 'PASS' && $curSt === 'FAIL') {
            $newFail[] = $id;
            $regressions[] = ['scenario_id' => $id, 'from' => $prevSt, 'to' => $curSt, 'flag' => 'REGRESSION_DETECTED'];
        } elseif ($prevSt === 'PASS' && in_array($curSt, ['BLOCKED', 'NOT_VERIFIED'], true)) {
            $blockedChanges[] = $id;
            $regressions[] = ['scenario_id' => $id, 'from' => $prevSt, 'to' => $curSt, 'flag' => 'REGRESSION_DETECTED'];
        } elseif ($prevSt !== 'PASS' && $curSt === 'PASS') {
            $newPass[] = $id;
        }
        $prevCrit = count(is_array($prev['critical_failures'] ?? null) ? $prev['critical_failures'] : []);
        $curCrit = count(is_array($row['critical_failures'] ?? null) ? $row['critical_failures'] : []);
        if ($curCrit > $prevCrit) {
            $criticalChanges[] = $id;
            $regressions[] = ['scenario_id' => $id, 'from' => 'critical_' . $prevCrit, 'to' => 'critical_' . $curCrit, 'flag' => 'REGRESSION_DETECTED'];
        }
    }

    return [
        'newly_passing'      => $newPass,
        'newly_failing'      => $newFail,
        'unchanged'          => $unchanged,
        'blocked_changes'    => $blockedChanges,
        'critical_changes'   => $criticalChanges,
        'regressions'        => $regressions,
        'regression_count'   => count($regressions),
    ];
}

/**
 * @param array<string, mixed> $fixtures phase37 fixture file
 * @param list<array<string, mixed>> $scenarios phase38 evaluation scenarios
 */
function phase38_run_fixture_dataset(array $fixtures, array $scenarios, string $runId = 'P38-RUN-FIXTURE'): array
{
    $results = [];
    foreach ($scenarios as $scenario) {
        if (!is_array($scenario)) {
            continue;
        }
        $key = (string) ($scenario['fixture_key'] ?? '');
        if ($key === '' || !isset($fixtures[$key])) {
            $results[] = [
                'scenario_id' => (string) ($scenario['scenario_id'] ?? ''),
                'status'      => 'BLOCKED',
                'score'       => null,
                'assertions'  => [],
                'critical_failures' => [],
                'evidence'    => [],
                'notes'       => ['fixture missing: ' . $key],
            ];
            continue;
        }
        $block = $fixtures[$key];
        $nEvents = [
            'ok'          => true,
            'status'      => 'ok',
            'select_only' => true,
            'turn_id'     => (int) ($block['turn_id'] ?? 0),
            'events'      => $block['events'] ?? [],
        ];
        $results[] = phase38_evaluate_from_n_events($nEvents, $scenario);
    }

    return phase38_build_evaluation_report($runId, $results);
}
