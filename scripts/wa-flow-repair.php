#!/usr/bin/env php
<?php
/**
 * One-shot WhatsApp flow repair — run from cPanel Terminal (site root: public_html).
 *
 * Repairs cancelled/stuck turns, consolidates per lead, sends ONE reply per lead (no duplicates).
 *
 * Usage:
 *   php scripts/wa-flow-repair.php --bot=50 --ai
 *   php scripts/wa-flow-repair.php --bot=50 --ai --limit=6
 *   php scripts/wa-flow-repair.php --bot=50 --repair-only
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "Run from public_html (folder containing config.php).\n");
    exit(1);
}

require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/turn-schema-lite.php';
require_once $root . '/includes/wa-recover-lite.php';

$opts = [
    'key'          => '',
    'bot'          => 50,
    'ai'           => true,
    'limit'        => 10,
    'repair_only'  => false,
];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--key=')) {
        $opts['key'] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--bot=')) {
        $opts['bot'] = (int) substr($arg, 6);
    } elseif ($arg === '--ai') {
        $opts['ai'] = true;
    } elseif ($arg === '--no-ai') {
        $opts['ai'] = false;
    } elseif (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = (int) substr($arg, 8);
    } elseif ($arg === '--repair-only') {
        $opts['repair_only'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/wa-flow-repair.php --bot=50 [--ai] [--limit=10] [--repair-only]\n";
        exit(0);
    }
}

if ($opts['key'] === '' && defined('CRON_SECRET') && CRON_SECRET !== '') {
    $opts['key'] = (string) CRON_SECRET;
}

if (!wa_recover_auth($opts['key'])) {
    fwrite(STDERR, "Invalid or missing CRON_SECRET (--key= or config.php)\n");
    exit(1);
}

turn_schema_lite_ensure();

$botId = (int) $opts['bot'];
$params = $botId > 0 ? [$botId] : [];
$botSql = $botId > 0 ? ' AND t.bot_id = ?' : '';

$before = db_fetch(
    'SELECT COUNT(DISTINCT t.lead_id) AS needs FROM conversation_turns t
     WHERE NOT EXISTS (
        SELECT 1 FROM conversation_turn_events e
        WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
     )
     AND EXISTS (SELECT 1 FROM conversation_turn_messages m WHERE m.turn_id = t.id)' . $botSql,
    str_repeat('i', count($params)),
    $params
);

echo "Bot #{$botId} — leads needing reply before: " . (int) ($before['needs'] ?? 0) . "\n";

$engine = $root . '/includes/conversation-turn-engine.php';
if (is_readable($engine)) {
    require_once $engine;
}

$leadIds = wa_recover_leads_needing_reply($botId, 100);
echo 'Repairing ' . count($leadIds) . " lead(s)...\n";

foreach ($leadIds as $leadId) {
    wa_recover_repair_lead_turn($leadId);
    if (function_exists('turn_engine_consolidate_open_turns_for_lead')) {
        turn_engine_consolidate_open_turns_for_lead($leadId);
    }
    if (function_exists('turn_engine_repair_lead_turn')) {
        turn_engine_repair_lead_turn($leadId);
    }
}

wa_recover_finalize_buffering($botId);
$hung = wa_recover_clear_hung($botId, 3);
echo "Cleared hung: {$hung}\n";

if ($opts['repair_only']) {
    $after = db_fetch(
        'SELECT COUNT(DISTINCT t.lead_id) AS needs FROM conversation_turns t
         WHERE NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = t.id AND e.event_type = \'RESPONSE_SENT\'
         )
         AND EXISTS (SELECT 1 FROM conversation_turn_messages m WHERE m.turn_id = t.id)' . $botSql,
        str_repeat('i', count($params)),
        $params
    );
    echo json_encode([
        'ok'            => true,
        'repair_only'   => true,
        'leads_repaired'=> count($leadIds),
        'needs_after'   => (int) ($after['needs'] ?? 0),
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

foreach ($leadIds as $leadId) {
    wa_recover_wait_leads_quiet([$leadId], wa_recover_quiet_seconds() + 8);
}

$quietSec = wa_recover_quiet_seconds();
db_execute(
    'UPDATE conversation_turns SET finalize_after = NOW()
     WHERE status = \'buffering\'
     AND last_message_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)',
    'i',
    [$quietSec]
);

$result = wa_recover_run($botId, $opts['ai'], max(1, (int) $opts['limit']));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(!empty($result['ok']) ? 0 : 1);
