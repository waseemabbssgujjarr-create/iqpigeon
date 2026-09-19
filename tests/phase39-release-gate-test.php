<?php
/**
 * PHASE 3.9 OFFLINE RELEASE GATE VALIDATION
 * Run: php tests/phase39-release-gate-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/lib/phase38-evaluation.php';
require_once $root . '/tests/lib/phase39-release-gate.php';

$passed = 0;
$failed = 0;

function p39_assert(bool $cond, string $name): void
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

echo "PHASE 3.9 OFFLINE RELEASE GATE VALIDATION\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$policy = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-policy.json'), true) ?: [];
$fixtures = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-fixtures.json'), true) ?: [];
$regOk = phase39_default_regression_pass();

function p39_input(array $report, array $reg, ?array $baseline = null): array
{
    $in = [
        'release_candidate' => '5f63202',
        'regression'        => $reg,
        'evaluation_report' => $report,
    ];
    if ($baseline !== null) {
        $in['baseline_comparison'] = $baseline;
    }

    return $in;
}

// 1. clean PASS
$clean = phase39_run_release_gate(p39_input($fixtures['clean_evaluation_report'], $regOk), $policy);
p39_assert(($clean['status'] ?? '') === 'PASS', 'GATE: clean candidate PASS');

// 2. regression suite below
$badReg = $regOk;
$badReg['agent_core_mind'] = ['passed' => 794, 'failed' => 1];
$r2 = phase39_run_release_gate(p39_input($fixtures['clean_evaluation_report'], $badReg), $policy);
p39_assert(($r2['status'] ?? '') === 'FAIL', 'GATE: 794/795 FAIL');

// 3–5. phase suite failures
foreach (['phase36' => 44, 'phase37' => 42, 'phase38_harness' => 19] as $key => $p) {
    $r = $regOk;
    $r[$key] = ['passed' => $p, 'failed' => 1];
    $gr = phase39_run_release_gate(p39_input($fixtures['clean_evaluation_report'], $r), $policy);
    p39_assert(($gr['status'] ?? '') === 'FAIL', 'GATE: ' . $key . ' below threshold FAIL');
}

// 6. critical failure
$crit = phase39_run_release_gate(p39_input($fixtures['critical_failure_report'], $regOk), $policy);
p39_assert(($crit['status'] ?? '') === 'FAIL', 'GATE: critical failure FAIL');

// 7. required scenario failure
$reqFail = phase39_run_release_gate(p39_input($fixtures['required_scenario_fail_report'], $regOk), $policy);
p39_assert(($reqFail['status'] ?? '') === 'FAIL', 'GATE: required scenario FAIL');

// 8. required scenario blocked
$blocked = phase39_run_release_gate(p39_input($fixtures['required_blocked_report'], $regOk), $policy);
p39_assert(($blocked['status'] ?? '') === 'BLOCKED', 'GATE: required scenario BLOCKED');

// 9. required NOT_VERIFIED
$nv = phase39_run_release_gate(p39_input($fixtures['not_verified_report'], $regOk), $policy);
p39_assert(($nv['status'] ?? '') === 'BLOCKED', 'GATE: required NOT_VERIFIED BLOCKED');

// 10–11. optional failure / NOT_VERIFIED → warning, gate can PASS
$opt = phase39_run_release_gate(p39_input($fixtures['optional_fail_report'], $regOk), $policy);
p39_assert(($opt['status'] ?? '') === 'PASS' && count($opt['warnings'] ?? []) >= 1, 'GATE: optional fail warning only PASS');

// 12. baseline PASS→FAIL
$baseReport = phase38_build_evaluation_report('base', $fixtures['baseline_pass_baseline']['scenario_results']);
$curReport = phase38_build_evaluation_report('cur', $fixtures['baseline_regression_current']['scenario_results']);
$cmp = phase38_compare_runs($baseReport, $curReport);
$regGate = phase39_run_release_gate(p39_input($curReport, $regOk, $cmp), $policy);
p39_assert(($regGate['status'] ?? '') === 'FAIL', 'GATE: baseline regression FAIL');

// 13. baseline PASS→BLOCKED
$curB = phase38_build_evaluation_report('cur', $fixtures['required_blocked_report']['scenario_results']);
$cmpB = phase38_compare_runs($baseReport, $curB);
$g13 = phase39_run_release_gate(p39_input($curB, $regOk, $cmpB), $policy);
p39_assert(in_array($g13['status'], ['FAIL', 'BLOCKED'], true), 'GATE: baseline PASS→BLOCKED surfaces');

// 14. baseline PASS→NOT_VERIFIED
$curNv = phase38_build_evaluation_report('cur', $fixtures['not_verified_report']['scenario_results']);
$cmpNv = phase38_compare_runs($baseReport, $curNv);
$g14 = phase39_run_release_gate(p39_input($curNv, $regOk, $cmpNv), $policy);
p39_assert(in_array($g14['status'], ['FAIL', 'BLOCKED'], true), 'GATE: baseline PASS→NOT_VERIFIED');

// 15. improvement without masking failure
$improveBase = phase38_build_evaluation_report('b', [
    ['scenario_id' => 'P38-006', 'status' => 'FAIL', 'critical_failures' => []],
    ['scenario_id' => 'P38-001', 'status' => 'FAIL', 'critical_failures' => []],
]);
$improveCur = phase38_build_evaluation_report('c', [
    ['scenario_id' => 'P38-006', 'status' => 'PASS', 'critical_failures' => []],
    ['scenario_id' => 'P38-001', 'status' => 'FAIL', 'critical_failures' => []],
]);
$g15 = phase39_run_release_gate(p39_input($improveCur, $regOk, phase38_compare_runs($improveBase, $improveCur)), $policy);
p39_assert(($g15['status'] ?? '') === 'FAIL', 'GATE: improvement does not mask P38-001 FAIL');

// 16. malformed input
$mal = phase39_run_release_gate(['release_candidate' => 'x'], $policy);
p39_assert(($mal['status'] ?? '') === 'BLOCKED', 'GATE: malformed BLOCKED');
p39_assert(($mal['blocked_reasons'][0]['code'] ?? '') === 'INVALID_GATE_INPUT', 'GATE: INVALID_GATE_INPUT');

// 17. missing baseline when required
$polBase = $policy;
$polBase['baseline_required'] = true;
$g17 = phase39_run_release_gate(p39_input($fixtures['clean_evaluation_report'], $regOk), $polBase);
p39_assert(($g17['status'] ?? '') === 'BLOCKED', 'GATE: missing baseline BLOCKED');

// 18. zero critical on clean
p39_assert(($clean['summary']['critical_failures'] ?? 1) === 0, 'GATE: zero critical failures clean');

// 19. multiple simultaneous failures
$multi = $fixtures['clean_evaluation_report'];
$multi['critical_failure_count'] = 1;
$multi['scenario_results'][] = ['scenario_id' => 'P38-006', 'status' => 'FAIL', 'critical_failures' => ['x'], 'assertions' => []];
$g19 = phase39_run_release_gate(p39_input($multi, $badReg), $policy);
p39_assert(($g19['status'] ?? '') === 'FAIL' && count($g19['failures'] ?? []) >= 2, 'GATE: multiple failures');

// 20. deterministic repeat
$g20a = phase39_run_release_gate(p39_input($fixtures['clean_evaluation_report'], $regOk), $policy);
$g20b = phase39_run_release_gate(p39_input($fixtures['clean_evaluation_report'], $regOk), $policy);
p39_assert(json_encode($g20a) === json_encode($g20b), 'GATE: deterministic repeat');

// Phase 3.8 full run still evaluable (P38-008 remains failure in evaluator)
$p38fixtures = json_decode((string) file_get_contents($root . '/tests/phase38-evaluation-fixtures.json'), true);
$p38scenarios = json_decode((string) file_get_contents($root . '/tests/phase38-evaluation-scenarios.json'), true);
$fullEval = phase38_run_fixture_dataset($p38fixtures, $p38scenarios);
p39_assert(($fullEval['fail_count'] ?? 0) >= 1, 'P38: P38-008 still fails evaluation');
$fullGate = phase39_run_release_gate(p39_input($fullEval, $regOk), $policy);
p39_assert(
    ($fullGate['status'] ?? '') !== 'PASS'
    && (($fullGate['evaluation_summary']['critical_failure_count'] ?? 0) > 0
        || ($fullGate['status'] ?? '') === 'FAIL'),
    'GATE: full eval with critical does not PASS'
);

// Human report
p39_assert(str_contains(phase39_format_gate_report($clean), 'PHASE 3.9 RELEASE GATE'), 'REPORT: human readable');

// Docs
foreach (['docs/phase39-release-gate.md', 'docs/phase39-release-gate-contract.md'] as $doc) {
    p39_assert(is_readable($root . '/' . $doc), 'DOC: ' . $doc);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
