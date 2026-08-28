<?php
/**
 * Fastest stuck-turn fix — fallback text only, no OpenAI, no turn engine (~5 seconds).
 *
 *   /api/wa-fallback.php?key=CRON_SECRET&bot_id=50
 */
declare(strict_types=1);

@set_time_limit(45);
ignore_user_abort(true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/wa-recover-lite.php';

$key = trim((string) ($_GET['key'] ?? ''));
if (!wa_recover_auth($key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
    exit;
}

$botId = (int) ($_GET['bot_id'] ?? $_GET['bot'] ?? 0);
$t0 = microtime(true);
$out = wa_recover_run($botId, false, 1);
$out['ms'] = (int) round((microtime(true) - $t0) * 1000);
$out['mode'] = 'fallback_only';
$out['bot_id'] = $botId;
$out['time'] = date('c');

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
