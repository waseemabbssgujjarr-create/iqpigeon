<?php
/**
 * Recover stuck WhatsApp turns — lightweight (no turn engine).
 *
 * FAST (fallback only, ~5s, use this if ai=1 gives 503):
 *   /api/wa-recover.php?key=CRON_SECRET&bot_id=50&quick=1
 *
 * One turn with OpenAI (~15s):
 *   /api/wa-recover.php?key=CRON_SECRET&bot_id=50&ai=1&limit=1
 *
 * List stuck turns only:
 *   /api/wa-recover.php?key=CRON_SECRET&bot_id=50&dry=1
 *
 * Health (instant):
 *   /api/wa-recover.php?key=CRON_SECRET&ping=1
 */
declare(strict_types=1);

@set_time_limit(90);
ignore_user_abort(true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/wa-recover-lite.php';

$key = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
if (!wa_recover_auth($key)) {
    http_response_code(403);
    echo json_encode([
        'ok'    => false,
        'error' => 'Forbidden — pass ?key=CRON_SECRET (exact value from config.php)',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ping'] ?? '') === '1') {
    echo json_encode([
        'ok'      => true,
        'service' => 'wa-recover',
        'version' => 2,
        'time'    => date('c'),
        'hint'    => 'Use quick=1 for fast fallback, or ai=1&limit=1 for one OpenAI reply',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$botId = (int) ($_GET['bot_id'] ?? $_GET['bot'] ?? 0);
$quick = ($_GET['quick'] ?? '') === '1' || ($_GET['fallback'] ?? '') === '1';
$useAi = !$quick && ($_GET['ai'] ?? '0') === '1';
$limit = (int) ($_GET['limit'] ?? 1);
$dryRun = ($_GET['dry'] ?? '') === '1';

if ($dryRun) {
    require_once __DIR__ . '/../includes/turn-schema-lite.php';
    turn_schema_lite_ensure();
    $params = $botId > 0 ? [$botId] : [];
    $botSql = $botId > 0 ? ' AND bot_id = ?' : '';
    $turns = db_fetch_all(
        'SELECT id, lead_id, status, suppression_reason, processing_started_at, last_message_at
         FROM conversation_turns
         WHERE status IN (\'failed\', \'processing\', \'buffering\')' . $botSql . '
         ORDER BY id DESC LIMIT 15',
        str_repeat('i', count($params)),
        $params
    ) ?: [];
    echo json_encode([
        'ok'     => true,
        'dry'    => true,
        'bot_id' => $botId,
        'turns'  => $turns,
        'hint'   => 'Run quick=1 to send fallback, or ai=1&limit=1 for OpenAI',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$t0 = microtime(true);
try {
    $out = wa_recover_run($botId, $useAi, $limit);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok'     => false,
        'error'  => $e->getMessage(),
        'bot_id' => $botId,
        'hint'   => 'Try quick=1 for fallback-only recover (~3s)',
        'time'   => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
$out['ms'] = (int) round((microtime(true) - $t0) * 1000);
if (($out['sent'] ?? 0) === 0 && ($out['results'] ?? []) === []) {
    $out['hint'] = 'No unsent turns found — send a new WhatsApp message, wait 8s, then run again. Or use ?dry=1 to list stuck turns.';
}
$out['bot_id'] = $botId;
$out['ai'] = $useAi;
$out['quick'] = $quick;
$out['limit'] = max(1, min(3, $limit));
$out['time'] = date('c');

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
