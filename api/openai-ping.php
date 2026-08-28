<?php
/**
 * OpenAI connectivity test from the server — /api/openai-ping.php?key=CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/openai.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$cron = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
if ($cron === '' || !hash_equals($cron, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — pass ?key=CRON_SECRET']);
    exit;
}

$out = [
    'ok'      => false,
    'api_url' => integration_openai_api_url(),
    'model'   => integration_openai_model(),
    'key_set' => integration_openai_chat_key() !== '',
];

$t0 = microtime(true);
$result = ai_chat(
    [['role' => 'user', 'content' => 'Reply with exactly: OK']],
    ['max_tokens' => 5, 'timeout' => 12, 'max_attempts' => 1]
);
$out['ms'] = (int) round((microtime(true) - $t0) * 1000);
$out['success'] = !empty($result['success']);
$out['content'] = $result['content'] ?? null;
$out['error'] = $result['error'] ?? null;
$out['ok'] = $out['success'];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
