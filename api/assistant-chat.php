<?php
/**
 * Playground chat — talk to the business AI like a normal model.
 * Uses knowledge + catalog as context. No training pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/catalog.php';

header('Content-Type: application/json; charset=UTF-8');

$user = get_user();
if (!$user || ($user['role'] ?? '') === 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sign in required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

$userId = (int) $user['id'];
$botId = (int) ($_POST['bot_id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));
if ($message === '') {
    echo json_encode(['ok' => false, 'error' => 'Type a message']);
    exit;
}

$bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
if (!$bot) {
    $bot = db_fetch('SELECT * FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
}
if (!$bot) {
    echo json_encode(['ok' => false, 'error' => 'No assistant found']);
    exit;
}

ensure_bots_schema();
$rep = trim((string) ($bot['rep_name'] ?? $bot['name'] ?? 'Assistant'));
$company = trim((string) ($user['company_name'] ?? $bot['name'] ?? APP_NAME));
$knowledge = trim((string) ($bot['bot_knowledge'] ?? ''));
if (mb_strlen($knowledge) > 4000) {
    $knowledge = mb_substr($knowledge, 0, 4000);
}

$catalogBits = [];
try {
    $items = db_fetch_all(
        'SELECT name, price, currency FROM bot_products WHERE bot_id = ? AND is_active = 1 ORDER BY sort_order ASC, id DESC LIMIT 40',
        'i',
        [(int) $bot['id']]
    );
    foreach ($items as $item) {
        $catalogBits[] = ($item['name'] ?? '') . ' — ' . ($item['price'] ?? '') . ' ' . ($item['currency'] ?? 'PKR');
    }
} catch (Throwable $e) {
    // catalog optional
}

$system = "You are {$rep}, the AI assistant for {$company}. "
    . "Talk naturally, like ChatGPT — answer the customer, do not recite scripts, do not say you are being trained. "
    . "If you do not know something, say so and ask a short clarifying question.\n\n"
    . "BUSINESS KNOWLEDGE:\n" . ($knowledge !== '' ? $knowledge : 'No extra notes yet.') . "\n\n";
if ($catalogBits !== []) {
    $system .= "CATALOG:\n" . implode("\n", $catalogBits) . "\n";
}

$historyRaw = $_POST['history'] ?? '[]';
$history = json_decode((string) $historyRaw, true);
if (!is_array($history)) {
    $history = [];
}

$messages = [['role' => 'system', 'content' => $system]];
foreach (array_slice($history, -12) as $turn) {
    $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string) ($turn['content'] ?? ''));
    if ($content !== '') {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}
$messages[] = ['role' => 'user', 'content' => $message];

$result = openai_chat($messages, ['temperature' => 0.7, 'max_tokens' => 500]);
if (empty($result['success'])) {
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'AI unavailable']);
    exit;
}

echo json_encode(['ok' => true, 'reply' => (string) ($result['content'] ?? '')]);
