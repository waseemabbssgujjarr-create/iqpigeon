<?php
/**
 * Phase 3.9 offline release gate runner (fixture-driven demo).
 * Run: php tests/phase39-release-gate-runner.php [clean|critical]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/lib/phase38-evaluation.php';
require_once $root . '/tests/lib/phase39-release-gate.php';

$mode = $argv[1] ?? 'clean';
$policy = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-policy.json'), true) ?: [];
$fixtures = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-fixtures.json'), true) ?: [];

$reportKey = $mode === 'critical' ? 'critical_failure_report' : 'clean_evaluation_report';
$evalReport = $fixtures[$reportKey] ?? [];

$input = [
    'release_candidate' => '5f63202',
    'regression'        => phase39_default_regression_pass(),
    'evaluation_report' => $evalReport,
];

$result = phase39_run_release_gate($input, $policy);
echo phase39_format_gate_report($result) . "\n";

if (($argv[2] ?? '') === '--json') {
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}

exit(match ($result['status'] ?? 'FAIL') {
    'PASS' => 0,
    'BLOCKED' => 2,
    default => 1,
});
