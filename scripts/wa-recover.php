#!/usr/bin/env php
<?php
/**
 * CLI: recover stuck WhatsApp turns and send replies (no debug page, no 503).
 *
 * Usage (cPanel Terminal or SSH — from public_html):
 *   php scripts/wa-recover.php --key=YOUR_CRON_SECRET --bot=50 --ai
 *
 * Options:
 *   --key=...     CRON_SECRET from config.php (required)
 *   --bot=50      Bot ID (0 = all bots, default 0)
 *   --ai          Use OpenAI for reply (default: fallback text only)
 *   --limit=1     Max turns to process (default 1 — avoids cPanel 503)
 *   --dry         List stuck turns only, do not send
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "Run from public_html (folder containing config.php).\n");
    exit(1);
}

require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/wa-recover-lite.php';

$opts = [
    'key'   => '',
    'bot'   => 0,
    'ai'    => false,
    'limit' => 1,
    'dry'   => false,
];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--key=')) {
        $opts['key'] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--bot=')) {
        $opts['bot'] = (int) substr($arg, 6);
    } elseif ($arg === '--ai') {
        $opts['ai'] = true;
    } elseif (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = (int) substr($arg, 8);
    } elseif ($arg === '--dry') {
        $opts['dry'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo file_get_contents(__FILE__);
        exit(0);
    }
}

if ($opts['key'] === '' && defined('CRON_SECRET') && CRON_SECRET !== '') {
    $opts['key'] = (string) CRON_SECRET;
}

if (!wa_recover_auth($opts['key'])) {
    fwrite(STDERR, "Invalid or missing --key=CRON_SECRET\n");
    exit(1);
}

if ($opts['dry']) {
    $botId = $opts['bot'];
    $params = $botId > 0 ? [$botId] : [];
    $botSql = $botId > 0 ? ' AND bot_id = ?' : '';
    $turns = db_fetch_all(
        'SELECT id, lead_id, status, suppression_reason, last_message_at FROM conversation_turns
         WHERE status IN (\'failed\', \'processing\', \'buffering\')' . $botSql . '
         ORDER BY id DESC LIMIT 15',
        str_repeat('i', count($params)),
        $params
    ) ?: [];
    echo json_encode(['dry' => true, 'turns' => $turns], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "Recovering bot #{$opts['bot']} (ai=" . ($opts['ai'] ? 'yes' : 'no') . ", limit={$opts['limit']})...\n";
$result = wa_recover_run($opts['bot'], $opts['ai'], $opts['limit']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(!empty($result['ok']) ? 0 : 1);
