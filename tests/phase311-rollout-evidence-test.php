<?php
/**
 * PHASE 3.11 OFFLINE ROLLOUT OPERATIONS & EVIDENCE
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/tests/lib/phase311-rollout-evidence.php';

$passed = 0;
$failed = 0;

function p311_assert(bool $cond, string $name): void
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

echo "PHASE 3.11 OFFLINE ROLLOUT OPERATIONS & EVIDENCE\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$rolloutPolicy = json_decode((string) file_get_contents($root . '/tests/phase310-rollout-policy.json'), true) ?: [];
$opsPolicy = json_decode((string) file_get_contents($root . '/tests/phase311-rollout-evidence-schema.json'), true)['ops_policy'] ?? [];
$gateFix = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-fixtures.json'), true) ?: [];
$gatePolicy = json_decode((string) file_get_contents($root . '/tests/phase39-release-gate-policy.json'), true) ?: [];
$p37Fix = json_decode((string) file_get_contents($root . '/tests/phase37-observability-fixtures.json'), true) ?: [];

$regOk = phase39_default_regression_pass();
$passGate = phase39_run_release_gate([
    'release_candidate' => '1c71270',
    'regression'        => $regOk,
    'evaluation_report' => $gateFix['clean_evaluation_report'],
], $gatePolicy);

function p311_n_events(string $fixtureKey, array $p37Fix): array
{
    $f = $p37Fix[$fixtureKey] ?? [];
    $f['select_only'] = true;

    return $f;
}

$auditBatch53 = [
    'bot_id'  => 53,
    'n_events'=> p311_n_events('successful_agent_core', $p37Fix),
];

$baseInput = [
    'release_commit'     => '1c71270',
    'rollout_stage'      => '0',
    'previous_stage'     => '0',
    'rollout_timestamp'  => '2026-09-19T14:00:00Z',
    'release_gate'       => $passGate,
    'evaluation_report'  => $gateFix['clean_evaluation_report'],
    'production_audit_batches' => [$auditBatch53],
];

$proposal = phase310_evaluate_rollout_expansion([
    'current_stage'     => '0',
    'target_stage'      => '1',
    'timestamp'         => '2026-09-19T14:00:00Z',
    'release_gate'      => $passGate,
    'evaluation_report' => $gateFix['clean_evaluation_report'],
], $rolloutPolicy);

// Valid evidence package
$pkg = phase311_build_evidence_package(array_merge($baseInput, [
    'rollout_proposal'            => $proposal,
    'operator_approved_expansion' => true,
    'operator_ready_for_review'   => true,
]), $rolloutPolicy, $opsPolicy);
p311_assert(phase311_validate_evidence_package_shape($pkg) === [], 'PKG: valid shape');
p311_assert(in_array($pkg['evidence_state'], ['SUFFICIENT', 'READY_FOR_REVIEW'], true), 'PKG: sufficient evidence');
p311_assert(($pkg['next_decision']['decision'] ?? '') === 'EXPAND', 'REVIEW: EXPAND');
p311_assert(($pkg['manual_apply_required'] ?? false) === true, 'PKG: manual apply');

// Missing evidence
$missing = phase311_build_evidence_package([
    'release_commit'    => '1c71270',
    'rollout_stage'     => '0',
    'previous_stage'    => '0',
    'rollout_timestamp' => '2026-09-19T14:00:00Z',
    'release_gate'      => $passGate,
    'evaluation_report' => $gateFix['clean_evaluation_report'],
], $rolloutPolicy, $opsPolicy);
p311_assert($missing['evidence_state'] === 'NOT_STARTED', 'EV: NOT_STARTED without audit');
p311_assert(($missing['next_decision']['decision'] ?? '') === 'HOLD', 'REVIEW: HOLD missing evidence');

// Insufficient evidence (non-core turn only)
$insuf = phase311_build_evidence_package(array_merge($baseInput, [
    'production_audit_batches' => [[
        'bot_id'   => 53,
        'n_events' => p311_n_events('delivery_without_core', $p37Fix),
    ]],
]), $rolloutPolicy, $opsPolicy);
p311_assert($insuf['evidence_state'] === 'INSUFFICIENT', 'EV: INSUFFICIENT');

// Critical failure
$critPkg = phase311_build_evidence_package(array_merge($baseInput, [
    'evaluation_report' => $gateFix['critical_failure_report'],
    'release_gate'      => phase39_run_release_gate([
        'release_candidate' => 'x',
        'regression'        => $regOk,
        'evaluation_report' => $gateFix['critical_failure_report'],
    ], $gatePolicy),
]), $rolloutPolicy, $opsPolicy);
p311_assert($critPkg['evidence_state'] === 'CRITICAL_FAILURE', 'EV: CRITICAL_FAILURE');
p311_assert(($critPkg['next_decision']['decision'] ?? '') === 'ROLLBACK', 'REVIEW: ROLLBACK critical');

// Blocked gate
$blockedGate = phase39_run_release_gate([
    'release_candidate' => 'x',
    'regression'        => $regOk,
    'evaluation_report' => $gateFix['required_blocked_report'],
], $gatePolicy);
$blockedPkg = phase311_build_evidence_package(array_merge($baseInput, [
    'release_gate' => $blockedGate,
]), $rolloutPolicy, $opsPolicy);
p311_assert(($blockedPkg['next_decision']['decision'] ?? '') === 'BLOCKED', 'REVIEW: BLOCKED gate');

// Hold without operator approval
$holdPkg = phase311_build_evidence_package(array_merge($baseInput, [
    'rollout_proposal' => $proposal,
]), $rolloutPolicy, $opsPolicy);
p311_assert(($holdPkg['next_decision']['decision'] ?? '') === 'HOLD', 'REVIEW: HOLD no operator approval');

// Production verify fixture
$fixture = json_decode((string) file_get_contents($root . '/tests/phase311-production-verify-fixture.json'), true) ?: [];
$verify = phase311_verify_production_rollout($fixture['config'], $fixture['bots'], $fixture['input']);
p311_assert(($verify['active_cohort'] ?? []) === [53], 'VERIFY: active cohort 53');
p311_assert((int) ($verify['configuration_mismatch_count'] ?? 1) === 0, 'VERIFY: no mismatch fixture');

// Effective mismatch: bot 54 not on rollout but has core audit
$mismatchInput = [
    'production_audit_batches' => [[
        'bot_id'   => 54,
        'n_events' => p311_n_events('successful_agent_core', $p37Fix),
    ]],
];
$verifyBad = phase311_verify_production_rollout($fixture['config'], $fixture['bots'], $mismatchInput);
p311_assert(($verifyBad['configuration_mismatch_count'] ?? 0) >= 1, 'VERIFY: effective mismatch');

// Ineligible bot
$inelig = phase311_verify_bot(['id' => 56, 'is_active' => 0, 'whatsapp_auto_reply' => 1], true, [56], null);
p311_assert(($inelig['eligibility'] ?? '') === 'INELIGIBLE', 'BOT: ineligible');
p311_assert(($inelig['effective_expected'] ?? '') === 'CORE_OFF', 'BOT: ineligible core off');

// Missing rollback target in review
$badPol = $rolloutPolicy;
$badPol['stages']['0']['rollback_stage'] = 'missing';
$rbReview = phase311_review_cohort([
    'release_gate_status'     => 'PASS',
    'critical_failure_count'  => 0,
    'evidence_state'          => 'READY_FOR_REVIEW',
    'rollout_stage'           => '0',
    'operator_approved_expansion' => true,
    'rollback_status'         => ['rollback_level' => 'NO_ROLLBACK'],
], $badPol, $opsPolicy, $proposal);
p311_assert(($rbReview['decision'] ?? '') === 'BLOCKED', 'REVIEW: missing rollback BLOCKED');

// Manual apply on all review paths
foreach (['EXPAND', 'HOLD', 'ROLLBACK', 'BLOCKED'] as $d) {
    $r = phase311_review_result($d, [], $rolloutPolicy, ['rollout_stage' => '0']);
    p311_assert(($r['manual_apply_required'] ?? false) === true, 'REVIEW: manual apply ' . $d);
}

// CLI verify script exists
p311_assert(is_readable($root . '/tests/phase311-production-verify.php'), 'TOOL: verify script');

// Docs
foreach (['docs/phase311-production-rollout-runbook.md', 'docs/phase311-evidence-contract.md'] as $doc) {
    p311_assert(is_readable($root . '/docs/' . basename($doc)), 'DOC: ' . basename($doc));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
