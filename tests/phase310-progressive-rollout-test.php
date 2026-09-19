<?php
/**
 * PHASE 3.10 OFFLINE PROGRESSIVE ROLLOUT VALIDATION
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/tests/lib/phase310-rollout.php';

$passed = 0;
$failed = 0;

function p310_assert(bool $cond, string $name): void
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

echo "PHASE 3.10 OFFLINE PROGRESSIVE ROLLOUT VALIDATION\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$policy = json_decode((string) file_get_contents($root . '/tests/phase310-rollout-policy.json'), true) ?: [];
$gateFix = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-fixtures.json'), true) ?: [];
$regOk = phase39_default_regression_pass();

function p310_pass_gate(array $gateFix, array $regOk, array $policy): array
{
    return phase39_run_release_gate([
        'release_candidate' => '43d8699',
        'regression'        => $regOk,
        'evaluation_report' => $gateFix['clean_evaluation_report'],
    ], $policy);
}

$passGate = p310_pass_gate($gateFix, $regOk, json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-policy.json'), true) ?: []);

$baseInput = static fn (string $from, string $to) => [
    'current_stage'      => $from,
    'target_stage'       => $to,
    'timestamp'          => '2026-09-19T12:00:00Z',
    'release_gate'       => $passGate,
    'evaluation_report'  => $gateFix['clean_evaluation_report'],
];

// Valid expansions
foreach ([['0', '1'], ['1', '2'], ['2', '3']] as [$from, $to]) {
    $r = phase310_evaluate_rollout_expansion($baseInput($from, $to), $policy);
    p310_assert(($r['decision'] ?? '') === 'APPROVED', "EXP: {$from}→{$to} APPROVED");
}

// Invalid jump 0→3
$jump = phase310_evaluate_rollout_expansion($baseInput('0', '3'), $policy);
p310_assert(($jump['decision'] ?? '') === 'REJECTED', 'EXP: 0→3 rejected');

// FAIL gate
$failGate = phase39_run_release_gate([
    'release_candidate' => 'x',
    'regression'        => $regOk,
    'evaluation_report' => $gateFix['critical_failure_report'],
], json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-policy.json'), true) ?: []);
$fg = $baseInput('0', '1');
$fg['release_gate'] = $failGate;
$fg['evaluation_report'] = $gateFix['critical_failure_report'];
p310_assert(phase310_evaluate_rollout_expansion($fg, $policy)['decision'] === 'REJECTED', 'EXP: FAIL gate rejected');

// BLOCKED gate
$blockedGate = phase39_run_release_gate([
    'release_candidate' => 'x',
    'regression'        => $regOk,
    'evaluation_report' => $gateFix['required_blocked_report'],
], json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-policy.json'), true) ?: []);
$bg = $baseInput('0', '1');
$bg['release_gate'] = $blockedGate;
p310_assert(in_array(phase310_evaluate_rollout_expansion($bg, $policy)['decision'], ['BLOCKED', 'REJECTED'], true), 'EXP: BLOCKED gate rejected');

// Critical eval
$cg = $baseInput('0', '1');
$cg['evaluation_report'] = $gateFix['critical_failure_report'];
p310_assert(phase310_evaluate_rollout_expansion($cg, $policy)['decision'] === 'REJECTED', 'EXP: critical eval rejected');

// Missing eval
$me = $baseInput('0', '1');
unset($me['evaluation_report']);
p310_assert(phase310_evaluate_rollout_expansion($me, $policy)['decision'] === 'BLOCKED', 'EXP: missing eval BLOCKED');

// Ineligible bot in stage — patch policy stage 1 to include bot 56 inactive
$badPol = $policy;
$badPol['stages']['1']['active_bot_ids'] = [53, 56];
p310_assert(phase310_evaluate_rollout_expansion($baseInput('0', '1'), $badPol)['decision'] === 'REJECTED', 'EXP: ineligible bot 56');

// Duplicate in request validation
$dup = phase310_validate_bot_eligibility([53, 53], $policy);
p310_assert(count($dup['rejected']) === 1, 'EXP: duplicate bot');

// Unknown bot id 0
$inv = phase310_validate_bot_eligibility([0], $policy);
p310_assert(count($inv['rejected']) === 1, 'EXP: invalid bot id');

// Rollback required
$rb = phase310_evaluate_rollback(['current_stage' => '2', 'release_gate_status' => 'FAIL', 'critical_failures' => 0], $policy);
p310_assert($rb['rollback_level'] === 'ROLLBACK_REQUIRED' && $rb['rollback_stage'] === '1', 'RB: gate fail rollback to stage 1');

$rb2 = phase310_evaluate_rollback(['current_stage' => '1', 'release_gate_status' => 'PASS', 'critical_failures' => 2], $policy);
p310_assert($rb2['rollback_level'] === 'ROLLBACK_REQUIRED', 'RB: critical rollback');

$rb3 = phase310_evaluate_rollback(['current_stage' => '1', 'release_gate_status' => 'PASS', 'critical_failures' => 0], $policy);
p310_assert($rb3['rollback_level'] === 'NO_ROLLBACK', 'RB: safe no rollback');

p310_assert($rb['preserve_master'] === true, 'RB: preserve master flag');

// Rollback config proposal stage 1 → bots 53,54
p310_assert(phase310_proposed_config_for_stage($policy, '1')['rollout_bot_ids'] === '53,54', 'CFG: stage 1 rollout string');

// Existing runtime behavior unchanged
p310_assert(phase310_simulate_core_enabled(['id' => 53, 'is_active' => 1, 'whatsapp_auto_reply' => 1], false, [53]) === false, 'RT: master OFF');
p310_assert(phase310_simulate_core_enabled(['id' => 99, 'is_active' => 1, 'whatsapp_auto_reply' => 1], true, []) === true, 'RT: empty rollout all eligible');
p310_assert(phase310_simulate_core_enabled(['id' => 53, 'is_active' => 1, 'whatsapp_auto_reply' => 1], true, [53]) === true, 'RT: bot 53 on list');
p310_assert(phase310_simulate_core_enabled(['id' => 99, 'is_active' => 1, 'whatsapp_auto_reply' => 1], true, [53]) === false, 'RT: other bot blocked');
p310_assert(phase310_simulate_core_enabled(['id' => 56, 'is_active' => 0, 'whatsapp_auto_reply' => 1], true, [56]) === false, 'RT: inactive blocked');

// Audit shape
$audit = phase310_evaluate_rollout_expansion($baseInput('0', '1'), $policy);
p310_assert(isset($audit['manual_apply_required']) && $audit['manual_apply_required'] === true, 'AUDIT: manual apply flag');
p310_assert(($audit['release_gate_status'] ?? '') === 'PASS', 'AUDIT: gate status recorded');

// Malformed stage
$mal = $baseInput('0', '9');
p310_assert(phase310_evaluate_rollout_expansion($mal, $policy)['decision'] === 'BLOCKED', 'EXP: malformed stage BLOCKED');

// Empty cohort (no all-eligible flag)
$emptyPol = $policy;
$emptyPol['stages']['9'] = ['label' => 'bad', 'active_bot_ids' => [], 'rollback_stage' => '0'];
$emptyPol['allowed_transitions'][] = ['2', '9'];
$ec = $baseInput('2', '9');
p310_assert(phase310_evaluate_rollout_expansion($ec, $emptyPol)['decision'] === 'REJECTED', 'EXP: empty cohort rejected');

// Rollback preserves prior stage bots
$rbCfg = phase310_proposed_config_for_stage($policy, (string) phase310_evaluate_rollback(['current_stage' => '2', 'release_gate_status' => 'FAIL', 'critical_failures' => 0], $policy)['rollback_stage']);
p310_assert($rbCfg['rollout_bot_ids'] === '53,54', 'RB: stage 2 rollback config 53,54');

// Rollback recommended on fallback
$rbRec = phase310_evaluate_rollback([
    'current_stage'           => '1',
    'release_gate_status'     => 'PASS',
    'critical_failures'       => 0,
    'observability_metrics'   => ['volume' => ['total_turns' => 50], 'path_rates' => ['fallback_rate' => 0.5]],
], $policy);
p310_assert($rbRec['rollback_level'] === 'ROLLBACK_RECOMMENDED', 'RB: fallback recommended');

// Fallback spike metric
$spike = $baseInput('1', '2');
$spike['observability_metrics'] = [
    'volume' => ['total_turns' => 20],
    'path_rates' => ['fallback_rate' => 0.9],
];
p310_assert(phase310_evaluate_rollout_expansion($spike, $policy)['decision'] === 'REJECTED', 'EXP: fallback spike rejected');

// Docs
foreach (['docs/phase310-progressive-rollout.md', 'docs/phase310-rollout-contract.md'] as $doc) {
    p310_assert(is_readable($root . '/' . $doc), 'DOC: ' . $doc);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
