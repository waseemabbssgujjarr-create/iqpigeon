<?php
/**
 * Read-only Agent Core global rollout eligibility audit (no tokens, no config writes).
 *
 * Usage: php tests/agent-core-global-rollout-audit.php [--json]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/agent-core/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);

$report = [
    'master_enabled'       => agent_core_master_enabled(),
    'rollout_bot_ids_raw'  => defined('AGENT_CORE_ROLLOUT_BOT_IDS') ? (string) AGENT_CORE_ROLLOUT_BOT_IDS : '',
    'rollout_bot_ids'      => agent_core_rollout_bot_ids(),
    'rollout_mode'         => agent_core_rollout_bot_ids() === [] ? 'all_eligible_bots' : 'allow_list',
    'deprecated_bot_ids'   => function_exists('agent_core_bot_ids') ? agent_core_bot_ids() : [],
    'bots'                 => [],
];

try {
    require_once $root . '/includes/db.php';
    $rows = db_fetch_all(
        'SELECT id, is_active, whatsapp_auto_reply, name FROM bots ORDER BY id ASC LIMIT 500',
        '',
        []
    ) ?: [];
    foreach ($rows as $row) {
        $bot = [
            'id'                   => (int) ($row['id'] ?? 0),
            'is_active'            => (int) ($row['is_active'] ?? 0),
            'whatsapp_auto_reply'  => (int) ($row['whatsapp_auto_reply'] ?? 0),
        ];
        $eligible = agent_core_bot_eligible($bot, 'whatsapp');
        $rolloutAllowed = agent_core_rollout_allows_bot($bot);
        $enabled = agent_core_enabled($bot, 'whatsapp');
        $report['bots'][] = [
            'bot_id'              => $bot['id'],
            'is_active'           => $bot['is_active'],
            'whatsapp_auto_reply' => $bot['whatsapp_auto_reply'],
            'agent_core_eligible' => $eligible,
            'rollout_allowed'     => $rolloutAllowed,
            'agent_core_enabled'  => $enabled,
        ];
    }
} catch (Throwable $e) {
    $report['db_error'] = 'DB unavailable — config-only audit';
    foreach ([53, 54, 99] as $id) {
        $bot = ['id' => $id, 'is_active' => 1, 'whatsapp_auto_reply' => 1];
        if ($id === 99) {
            $bot['whatsapp_auto_reply'] = 0;
        }
        $report['bots'][] = [
            'bot_id'              => $id,
            'is_active'           => 1,
            'whatsapp_auto_reply' => (int) $bot['whatsapp_auto_reply'],
            'agent_core_eligible' => agent_core_bot_eligible($bot, 'whatsapp'),
            'rollout_allowed'     => agent_core_rollout_allows_bot($bot),
            'agent_core_enabled'  => agent_core_enabled($bot, 'whatsapp'),
        ];
    }
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "Agent Core global rollout audit (read-only)\n";
echo 'Master: ' . ($report['master_enabled'] ? 'true' : 'false') . "\n";
echo 'Rollout raw: "' . $report['rollout_bot_ids_raw'] . "\"\n";
echo 'Mode: ' . $report['rollout_mode'] . "\n\n";
foreach ($report['bots'] as $b) {
    echo sprintf(
        "Bot %d active=%d wa_auto=%d eligible=%s rollout=%s core=%s\n",
        (int) $b['bot_id'],
        (int) $b['is_active'],
        (int) $b['whatsapp_auto_reply'],
        !empty($b['agent_core_eligible']) ? 'Y' : 'N',
        !empty($b['rollout_allowed']) ? 'Y' : 'N',
        !empty($b['agent_core_enabled']) ? 'ON' : 'OFF'
    );
}
