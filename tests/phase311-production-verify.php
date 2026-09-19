<?php
/**
 * Phase 3.11 — read-only production rollout verification (no writes).
 *
 * Live:  php tests/phase311-production-verify.php [--json]
 * Fixture: php tests/phase311-production-verify.php --fixture tests/phase311-production-verify-fixture.json
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/tests/lib/phase311-rollout-evidence.php';

$jsonOut = in_array('--json', $argv, true);
$fixturePath = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--fixture' && isset($argv[$i + 1])) {
        $fixturePath = $argv[$i + 1];
    }
}

if ($fixturePath !== null && is_readable($fixturePath)) {
    $fixture = json_decode((string) file_get_contents($fixturePath), true) ?: [];
    $config = is_array($fixture['config'] ?? null) ? $fixture['config'] : [];
    $bots = is_array($fixture['bots'] ?? null) ? $fixture['bots'] : [];
    $input = is_array($fixture['input'] ?? null) ? $fixture['input'] : [];
} else {
    $configured = phase311_read_configured_rollout();
    $config = [
        'master_enabled'  => $configured['master_enabled'],
        'rollout_bot_ids' => $configured['rollout_bot_ids'],
    ];
    $policy = json_decode((string) file_get_contents($root . '/tests/phase310-rollout-policy.json'), true) ?: [];
    $bots = [];
    foreach (is_array($policy['bot_catalog'] ?? null) ? $policy['bot_catalog'] : [] as $row) {
        if (is_array($row)) {
            $bots[] = $row;
        }
    }
    $input = ['production_audit_batches' => []];
}

$report = phase311_verify_production_rollout($config, $bots, $input);
$report['configured_rollout_raw'] = defined('AGENT_CORE_ROLLOUT_BOT_IDS') ? (string) AGENT_CORE_ROLLOUT_BOT_IDS : '';
$report['note'] = 'Read-only verification; does not modify config.local.php';

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Phase 3.11 Production Rollout Verification (read-only)\n";
    echo 'Master: ' . ($report['master_enabled'] ? 'true' : 'false') . "\n";
    echo 'Rollout IDs: ' . implode(',', $report['configured_rollout'] ?? []) . "\n";
    echo 'Active cohort (configured+eligible): ' . implode(',', $report['active_cohort'] ?? []) . "\n";
    echo 'Mismatches: ' . (int) ($report['configuration_mismatch_count'] ?? 0) . "\n";
    foreach ($report['bots'] ?? [] as $b) {
        echo sprintf(
            "  Bot %d eligible=%s configured=%s expected=%s observed=%s core_turns=%d\n",
            (int) ($b['bot_id'] ?? 0),
            (string) ($b['eligibility'] ?? ''),
            (string) ($b['configured_rollout'] ?? ''),
            (string) ($b['effective_expected'] ?? ''),
            (string) ($b['effective_observed'] ?? ''),
            (int) ($b['recent_core_turns'] ?? 0)
        );
    }
}

exit(($report['configuration_mismatch_count'] ?? 0) > 0 ? 1 : 0);
