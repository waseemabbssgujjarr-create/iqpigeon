<?php
/**
 * PHASE 3.7 OFFLINE OBSERVABILITY VALIDATION
 * Run: php tests/phase37-observability-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/lib/phase37-observability.php';

$passed = 0;
$failed = 0;

function p37_assert(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

echo "PHASE 3.7 OFFLINE OBSERVABILITY VALIDATION\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$fixturePath = $root . '/tests/phase37-observability-fixtures.json';
$fixtures = json_decode((string) file_get_contents($fixturePath), true);
p37_assert(is_array($fixtures), 'FIX: fixtures JSON loads');

function p37_fixture_events(array $fixtures, string $key): array
{
    $block = $fixtures[$key] ?? [];
    $events = [];
    foreach (is_array($block['events'] ?? null) ? $block['events'] : [] as $row) {
        $events[] = [
            'event_type'  => (string) ($row['event_type'] ?? ''),
            'created_at'  => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'detail_json' => is_array($row['detail_json'] ?? null) ? $row['detail_json'] : null,
        ];
    }

    return $events;
}

function p37_n_events_from_fixture(array $fixtures, string $key): array
{
    $block = $fixtures[$key] ?? [];

    return [
        'ok'          => true,
        'status'      => 'ok',
        'select_only' => true,
        'turn_id'     => (int) ($block['turn_id'] ?? 0),
        'events'      => $block['events'] ?? [],
    ];
}

// --- Normalization: successful Core ---
$success = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, 'successful_agent_core'));
p37_assert(($success['path'] ?? '') === 'agent_core', 'NORM: successful path agent_core');
p37_assert(empty($success['fallback']), 'NORM: successful not fallback');
p37_assert(($success['bot_id'] ?? 0) === 53, 'NORM: bot_id from events');
p37_assert(in_array('PLAN', $success['stages'] ?? [], true), 'NORM: PLAN stage observed');
p37_assert(($success['intelligence']['intent'] ?? '') === 'PRICE_INQUIRY', 'INTEL: intent extracted from events');
p37_assert(($success['decision']['cta_mode'] ?? '') === 'answer_advance', 'DEC: cta_mode extracted');
p37_assert(($success['validation']['status'] ?? '') === 'ok', 'VAL: validation pass');
p37_assert(!empty($success['delivery']['succeeded']), 'DEL: delivery success');

// --- Validation block ---
$vblock = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, 'validation_block'));
p37_assert(($vblock['path'] ?? '') === 'fallback', 'NORM: validation block → fallback');
p37_assert(!empty($vblock['validation']['blocked']), 'VAL: blocked true');
p37_assert(($vblock['validation']['reason'] ?? '') === 'false_action', 'VAL: reason extracted');
p37_assert(($vblock['fallback_reason'] ?? '') === 'validation_failed', 'PATH: fallback reason preserved');

// --- Action flow ---
$action = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, 'action_flow'));
p37_assert(!empty($action['action']['executed']), 'ACT: tool executed');
p37_assert(($action['action']['type'] ?? '') === 'booking.create', 'ACT: booking.create type');
p37_assert(!empty($action['action']['verified']), 'ACT: verified when validation_result present');

// --- Fallback ---
$fb = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, 'fallback'));
p37_assert(($fb['path'] ?? '') === 'fallback', 'PATH: fallback turn');
p37_assert(($fb['fallback_reason'] ?? '') === 'tool_failure', 'PATH: tool_failure reason');

// --- Incomplete / unknown ---
$inc = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, 'incomplete_unknown'));
p37_assert(($inc['path'] ?? '') === 'unknown', 'PATH: incomplete → unknown');
p37_assert(($inc['stage_presence']['VALIDATE'] ?? '') === 'NOT_OBSERVED', 'STAGE: missing VALIDATE NOT_OBSERVED');

// --- Delivery without core ---
$del = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, 'delivery_without_core'));
p37_assert(($del['path'] ?? '') === 'unknown', 'PATH: webhook path unknown');
p37_assert(!empty($del['delivery']['succeeded']), 'DEL: RESPONSE_SENT without CORE');

// --- Timing ---
p37_assert(is_int($success['timing']['duration_ms']) || is_float($success['timing']['duration_ms']), 'TIME: duration from elapsed_ms');
$emptyTiming = phase37_normalize_turn_from_n_events(['turn_id' => 1, 'events' => []]);
p37_assert(($emptyTiming['timing']['duration_ms'] ?? '') === 'NOT_AVAILABLE', 'TIME: empty → NOT_AVAILABLE');

// --- Percentiles ---
$p50 = phase37_percentile([100, 200, 900, 1000], 50);
$p95 = phase37_percentile([100, 200, 900, 1000], 95);
p37_assert($p50 === 200.0, 'AGG: p50 calculation');
p37_assert($p95 === 1000.0, 'AGG: p95 calculation');
p37_assert(phase37_percentile([], 50) === null, 'AGG: empty percentile null');

// --- Aggregation ---
$allNorm = [];
foreach (['successful_agent_core', 'validation_block', 'action_flow', 'fallback', 'incomplete_unknown', 'delivery_without_core'] as $fk) {
    $allNorm[] = phase37_normalize_turn_from_n_events(p37_n_events_from_fixture($fixtures, $fk));
}
$metrics = phase37_aggregate_metrics($allNorm);
p37_assert(($metrics['volume']['total_turns'] ?? 0) === 6, 'AGG: total turns 6');
p37_assert(($metrics['volume']['agent_core_turns'] ?? 0) === 2, 'AGG: two agent_core turns (success + action flow)');
p37_assert(($metrics['volume']['fallback_turns'] ?? 0) === 2, 'AGG: two fallback turns');
p37_assert(is_array($metrics['fallback_by_reason'] ?? null), 'AGG: fallback reasons grouped');
p37_assert(isset($metrics['fallback_by_reason']['validation_failed']), 'AGG: validation_failed counted');
p37_assert(($metrics['validation']['blocked'] ?? 0) >= 1, 'AGG: validation blocks counted');
p37_assert(($metrics['actions']['executed'] ?? 0) === 1, 'AGG: action executed count');
p37_assert(($metrics['delivery']['success'] ?? 0) === 3, 'AGG: delivery success count (success + action + delivery-only)');
p37_assert(is_float($metrics['path_rates']['agent_core_rate']) || $metrics['path_rates']['agent_core_rate'] === 'NOT_AVAILABLE', 'AGG: agent_core_rate numeric or NA');

// --- Phase 3.6 compatibility ---
$p36 = phase36_build_evidence_from_audit(p37_n_events_from_fixture($fixtures, 'successful_agent_core'), [
    'scenario_id' => 'P36-005',
    'result'      => 'PASS',
]);
$p36norm = phase37_normalize_from_phase36_evidence($p36);
p37_assert(($p36norm['turn_id'] ?? 0) === 90001, 'P36: turn_id preserved');
p37_assert(in_array($p36norm['path'], ['agent_core', 'unknown'], true), 'P36: path compatible');

// --- Duplicate events ---
$dupEvents = p37_fixture_events($fixtures, 'successful_agent_core');
$dupEvents[] = $dupEvents[count($dupEvents) - 1];
$dupNorm = phase37_normalize_turn_from_n_events(['turn_id' => 90001, 'events' => array_map(static fn ($e) => [
    'event_type' => $e['event_type'],
    'created_at' => $e['created_at'],
    'detail_json' => $e['detail_json'],
], $dupEvents)]);
p37_assert(count($dupNorm['events']) > count($success['events']), 'NORM: duplicate event types listed');

// --- Docs ---
foreach (['docs/phase37-production-observability.md', 'docs/phase37-metrics-contract.md'] as $doc) {
    p37_assert(is_readable($root . '/' . $doc), 'DOC: ' . $doc);
}

// --- Lifecycle ---
p37_assert(($success['lifecycle']['started'] ?? '') === 'OBSERVED', 'LIFE: started OBSERVED');
p37_assert(($fb['lifecycle']['failed'] ?? '') === 'OBSERVED', 'LIFE: failed OBSERVED');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
