<?php
/**
 * Admin-only live test-chat endpoint for the direct AI (NON-training).
 * Builds its system message from the full Master Behavior block (base prompt,
 * behavior/tone, core principles, per-section notes, guardrails) — exactly
 * what admin/ai.php's "Save Changes" pushes live to every business's bot.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/platform-training.php';

header('Content-Type: application/json; charset=UTF-8');

require_admin(); // rejects non-admins (redirects); this endpoint is admin-only

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        echo json_encode(['ok' => false, 'error' => 'Type a message']);
        exit;
    }

    // Full assembled Master Behavior block (base prompt + behavior/tone + principles
    // + section notes + guardrails) — the same block every live business bot receives.
    $base = build_admin_master_prompt_block();
    if ($base === '') {
        $base = 'You are a helpful AI assistant. Answer naturally and directly.';
    }

    $historyRaw = $_POST['history'] ?? '[]';
    $history = json_decode((string) $historyRaw, true);
    if (!is_array($history)) {
        $history = [];
    }

    $messages = [['role' => 'system', 'content' => $base]];
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

    $result = openai_chat($messages, ['temperature' => 0.7, 'max_tokens' => 500]);
    if (empty($result['success'])) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'AI unavailable']);
        exit;
    }

    echo json_encode(['ok' => true, 'reply' => (string) ($result['content'] ?? '')]);
} catch (Throwable $e) {
    error_log('admin-ai-test error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again.']);
}
