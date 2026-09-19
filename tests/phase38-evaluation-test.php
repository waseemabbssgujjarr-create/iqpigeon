<?php
/**
 * PHASE 3.8 OFFLINE AGENT EVALUATION VALIDATION
 * Run: php tests/phase38-evaluation-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/lib/phase38-evaluation.php';

$passed = 0;
$failed = 0;

function p38_assert(bool $cond, string $name): void
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

echo "PHASE 3.8 OFFLINE AGENT EVALUATION VALIDATION\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$fixtures = json_decode((string) file_get_contents($root . '/tests/phase38-evaluation-fixtures.json'), true);
$scenarios = json_decode((string) file_get_contents($root . '/tests/phase38-evaluation-scenarios.json'), true);
p38_assert(is_array($fixtures) && is_array($scenarios), 'LOAD: fixtures and scenarios JSON');

$report = phase38_run_fixture_dataset($fixtures, $scenarios, 'P38-TEST-RUN-001');
p38_assert(($report['scenario_count'] ?? 0) === 12, 'RUN: twelve scenarios evaluated');
p38_assert(($report['pass_count'] ?? 0) >= 1, 'RUN: at least one PASS');
p38_assert($report['score'] ?? null === null || !isset($report['score']), 'RUN: no overall quality score');

// Deterministic repeatability
$report2 = phase38_run_fixture_dataset($fixtures, $scenarios, 'P38-TEST-RUN-001');
p38_assert(json_encode($report['scenario_results']) === json_encode($report2['scenario_results']), 'REP: identical results on repeat');

// Specific scenario outcomes
$byId = [];
foreach ($report['scenario_results'] as $row) {
    $byId[(string) ($row['scenario_id'] ?? '')] = $row;
}
p38_assert(($byId['P38-001']['status'] ?? '') === 'PASS', 'SCEN: P38-001 PASS');
p38_assert(($byId['P38-006']['status'] ?? '') === 'PASS', 'SCEN: P38-006 validation block PASS');
p38_assert(($byId['P38-008']['status'] ?? '') === 'FAIL', 'SCEN: P38-008 unverified booking FAIL');
p38_assert(count($byId['P38-008']['critical_failures'] ?? []) >= 1, 'CRIT: P38-008 critical failure recorded');

// Assertion engine unit
$norm = phase37_normalize_turn_from_n_events([
    'turn_id' => 1,
    'events'  => $fixtures['fallback']['events'],
]);
$a = phase38_evaluate_assertion($norm, ['assertion_id' => 't1', 'type' => 'path_equals', 'expected' => 'fallback']);
p38_assert(($a['status'] ?? '') === 'PASS' && ($a['observed'] ?? '') === 'fallback', 'ASSERT: path_equals');

$b = phase38_evaluate_assertion($norm, ['assertion_id' => 't2', 'type' => 'path_equals', 'expected' => 'agent_core']);
p38_assert(($b['status'] ?? '') === 'FAIL', 'ASSERT: path mismatch FAIL');

// Missing data
$emptyNorm = phase37_normalize_turn_from_n_events(['turn_id' => 0, 'events' => []]);
$c = phase38_evaluate_assertion($emptyNorm, ['assertion_id' => 't3', 'type' => 'intent_equals', 'expected' => 'X']);
p38_assert(($c['status'] ?? '') === 'NOT_VERIFIED', 'ASSERT: missing intent NOT_VERIFIED');

// Baseline comparison / regression
$baseline = phase38_build_evaluation_report('base', [
    ['scenario_id' => 'P38-001', 'status' => 'PASS', 'critical_failures' => []],
    ['scenario_id' => 'P38-008', 'status' => 'PASS', 'critical_failures' => []],
]);
$current = $report;
$cmp = phase38_compare_runs($baseline, $current);
p38_assert(($cmp['regression_count'] ?? 0) >= 1, 'REG: regression detected P38-008 PASS→FAIL');
p38_assert(isset($cmp['regressions'][0]['flag']) && $cmp['regressions'][0]['flag'] === 'REGRESSION_DETECTED', 'REG: flag REGRESSION_DETECTED not RELEASE_BLOCKED');

// Phase 3.7 pipeline
$p37fixture = json_decode((string) file_get_contents($root . '/tests/phase37-observability-fixtures.json'), true);
$ev = phase38_evaluate_from_n_events([
    'turn_id' => $p37fixture['successful_agent_core']['turn_id'],
    'events'  => $p37fixture['successful_agent_core']['events'],
], $scenarios[0]);
p38_assert(($ev['status'] ?? '') === 'PASS', 'P37: consumes phase37 fixture via normalization');

// Phase 3.6 bridge
require_once $root . '/tests/lib/phase36-evidence.php';
$p36 = phase36_build_evidence_from_audit([
    'turn_id' => 90001,
    'events'  => $fixtures['successful_agent_core']['events'],
    'status'  => 'ok',
], ['scenario_id' => 'P36-005', 'result' => 'PASS']);
$p36norm = phase37_normalize_from_phase36_evidence($p36);
$p36eval = phase38_evaluate_scenario($p36norm, $scenarios[0]);
p38_assert(in_array($p36eval['status'], ['FAIL', 'NOT_VERIFIED'], true), 'P36: type-only evidence lacks detail → not PASS');

// Human-readable failure shape
$failAssert = null;
foreach ($byId['P38-008']['assertions'] ?? [] as $as) {
    if (($as['status'] ?? '') === 'FAIL') {
        $failAssert = $as;
        break;
    }
}
p38_assert(
    is_array($failAssert)
    && array_key_exists('observed', $failAssert)
    && ($failAssert['reason'] ?? '') !== '',
    'REVIEW: failure includes observed and reason'
);

// Docs
foreach (['docs/phase38-agent-evaluation.md', 'docs/phase38-evaluation-contract.md'] as $doc) {
    p38_assert(is_readable($root . '/' . $doc), 'DOC: ' . $doc);
}

// Critical failure IDs documented
p38_assert(count(phase38_critical_failure_ids()) >= 3, 'CRIT: critical failure catalog');

echo "\nPHASE 3.8 EVALUATION\n";
echo 'Scenarios: ' . ($report['scenario_count'] ?? 0) . "\n";
echo 'PASS: ' . ($report['pass_count'] ?? 0) . "\n";
echo 'FAIL: ' . ($report['fail_count'] ?? 0) . "\n";
echo 'BLOCKED: ' . ($report['blocked_count'] ?? 0) . "\n";
echo 'NOT_VERIFIED: ' . ($report['not_verified_count'] ?? 0) . "\n";
echo 'Critical failures: ' . ($report['critical_failure_count'] ?? 0) . "\n";

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
