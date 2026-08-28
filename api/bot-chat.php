<?php
/**
 * /api/bot-chat.php
 * Training page "Test Your Assistant" live chat.
 * Uses the same runtime prompt assembly as live WhatsApp (platform-training).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/platform-training.php';
require_once __DIR__ . '/../includes/industry-templates.php';
require_once __DIR__ . '/../includes/conversation-intent.php';
require_once __DIR__ . '/../includes/catalog.php';

header('Content-Type: application/json; charset=UTF-8');

$user = require_login();
$userId = (int) $user['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        echo json_encode(['ok' => false, 'error' => 'Type a message first.']);
        exit;
    }

    $botId = (int) ($_POST['bot_id'] ?? 0);

    $bot = null;
    if ($botId > 0) {
        $bot = db_fetch(
            'SELECT b.*, u.company_name, u.address, u.industry AS owner_industry, u.bio
             FROM bots b JOIN users u ON u.id = b.user_id
             WHERE b.id = ? AND b.user_id = ?',
            'ii',
            [$botId, $userId]
        );
    }
    if (!$bot) {
        $bot = db_fetch(
            'SELECT b.*, u.company_name, u.address, u.industry AS owner_industry, u.bio
             FROM bots b JOIN users u ON u.id = b.user_id
             WHERE b.user_id = ? ORDER BY b.id ASC LIMIT 1',
            'i',
            [$userId]
        );
    }
    if (!$bot) {
        echo json_encode(['ok' => false, 'error' => 'No assistant found. Complete training setup first.']);
        exit;
    }

    $companyName = trim((string) ($user['company_name'] ?? ''));
    if ($companyName === '') {
        $companyName = trim((string) ($bot['name'] ?? APP_NAME));
    }

    $systemPrompt = build_runtime_bot_prompt($bot, $companyName);

    $industryKey = trim((string) ($bot['industry_key'] ?? ''));
    if ($industryKey !== '' && conversation_wants_commercial_context($message)) {
        $systemPrompt .= industry_live_context_block($bot);
        $systemPrompt .= catalog_ai_prompt_block((int) $bot['id']);
    }

    require_once __DIR__ . '/../includes/lead-lifecycle.php';
    if (function_exists('lifecycle_conversion_prompt_block')) {
        $systemPrompt .= lifecycle_conversion_prompt_block($bot);
    }

    $systemPrompt .= "\n\nThis is the Test & Publish panel. Use the same rules as a live customer chat. "
        . "Answer using ONLY the business knowledge above — never invent a different company type. "
        . "If they ask where the business is, use the business address, not the rep's home city.";

    $historyRaw = $_POST['history'] ?? '[]';
    $history = json_decode((string) $historyRaw, true);
    if (!is_array($history)) {
        $history = [];
    }

    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach (array_slice($history, -12) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content !== '') {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $result = openai_chat($messages, [
        'temperature' => 0.7,
        'max_tokens'  => 600,
    ]);

    if (empty($result['success'])) {
        $errMsg = (string) ($result['error'] ?? 'AI is unavailable right now.');
        if (stripos($errMsg, 'key') !== false || stripos($errMsg, 'auth') !== false) {
            $errMsg = 'AI service not configured. Please check your API key in Integrations.';
        }
        echo json_encode(['ok' => false, 'reply' => $errMsg]);
        exit;
    }

    $reply = trim((string) ($result['content'] ?? ''));
    if ($reply === '') {
        $reply = 'I\'m not sure how to answer that. Could you rephrase your question?';
    }

    echo json_encode(['ok' => true, 'reply' => $reply]);

} catch (Throwable $e) {
    error_log('bot-chat.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'reply' => 'Something went wrong on the server. Please try again.']);
}
