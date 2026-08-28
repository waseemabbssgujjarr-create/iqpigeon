<?php
/**
 * Website chat widget API — receive and reply to widget messages.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $botId = (int) ($_GET['bot_id'] ?? 0);
    $sessionId = trim((string) ($_GET['session_id'] ?? ''));

    if (!$botId) {
        json_response(['success' => false, 'error' => 'Missing bot_id'], 400);
    }

    try {
        $bot = db_fetch(
            'SELECT id, name, rep_name, widget_color, widget_enabled, is_active, knowledge_updated_at FROM bots WHERE id = ?',
            'i',
            [$botId]
        );

        if (!$bot) {
            json_response(['success' => false, 'error' => 'Bot not found.'], 404);
        }

        if (!(int) $bot['widget_enabled'] || !(int) $bot['is_active']) {
            json_response([
                'success' => false,
                'error'   => 'Widget not enabled for this bot. Enable it in Bot Setup → Website Widget.',
                'widget_enabled' => (int) ($bot['widget_enabled'] ?? 0),
                'is_active' => (int) ($bot['is_active'] ?? 0),
            ], 404);
        }

        $payload = [
            'success'           => true,
            'botName'           => get_widget_bot_name($bot),
            'widget_color'      => trim((string) ($bot['widget_color'] ?? '')) ?: '#4aad36',
            'ready'             => true,
            'knowledge_version' => trim((string) ($bot['knowledge_updated_at'] ?? '')),
        ];

        if ($sessionId !== '') {
            ensure_leads_schema();
            ensure_conversations_schema();

            $externalId = 'widget_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
            $lead = db_fetch(
                'SELECT id FROM leads WHERE bot_id = ? AND external_id = ?',
                'is',
                [$botId, $externalId]
            );

            $messages = [];
            if ($lead) {
                $rows = db_fetch_all(
                    'SELECT role, message, created_at FROM conversations
                     WHERE lead_id = ? AND role IN (\'user\', \'assistant\')
                     ORDER BY created_at ASC',
                    'i',
                    [(int) $lead['id']]
                );
                foreach ($rows as $row) {
                    $role = ($row['role'] ?? '') === 'user' ? 'user' : 'bot';
                    $text = trim((string) ($row['message'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $messages[] = ['role' => $role, 'text' => $text];
                }
            }
            $payload['messages'] = $messages;
        }

        json_response($payload);
    } catch (Throwable $e) {
        error_log('chat-widget config error: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error'   => defined('APP_DEBUG') && APP_DEBUG
                ? $e->getMessage()
                : 'Could not load widget configuration.',
        ], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/demo-training.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';

try {
    require_once __DIR__ . '/ai-respond.php';
} catch (Throwable $e) {
    error_log('chat-widget ai-respond load: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    json_response([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG
            ? 'Server module error: ' . $e->getMessage()
            : 'Chat service is temporarily unavailable. Please try again shortly.',
    ], 503);
}

security_rate_limit_or_abort('chat_widget', 40, 60);

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$botId = (int) ($input['bot_id'] ?? 0);
$sessionId = trim($input['session_id'] ?? '');
$message = trim($input['message'] ?? '');
$visitorName = trim($input['name'] ?? 'Website Visitor');
$demoMode = !empty($input['demo_mode']);

if (!$botId || $sessionId === '' || $message === '') {
    json_response(['success' => false, 'error' => 'Missing bot_id, session_id, or message'], 400);
}

try {
    $bot = db_fetch(
        'SELECT * FROM bots WHERE id = ? AND widget_enabled = 1 AND is_active = 1',
        'i',
        [$botId]
    );

    if (!$bot) {
        json_response([
            'success' => false,
            'error'   => 'Widget not enabled for this bot. Enable it in Bot Setup → Website Widget.',
        ], 404);
    }

    ensure_leads_schema();
    ensure_conversations_schema();

    $externalId = 'widget_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

    $lead = db_fetch(
        'SELECT * FROM leads WHERE bot_id = ? AND external_id = ?',
        'is',
        [$botId, $externalId]
    );

    if (!$lead) {
        if (!within_lead_limit((int) $bot['user_id'])) {
            json_response(['success' => false, 'error' => 'This bot has reached its monthly lead limit.'], 429);
        }

        $leadId = db_insert(
            'INSERT INTO leads (bot_id, external_id, name, platform, status) VALUES (?, ?, ?, \'widget\', \'new\')',
            'iss',
            [$botId, $externalId, $visitorName]
        );

        $isPublicDemo = is_demo_bot($botId) && $demoMode;
        if (!$isPublicDemo) {
            $owner = db_fetch('SELECT email FROM users WHERE id = ?', 'i', [(int) $bot['user_id']]);
            if ($owner) {
                try {
                    email_new_lead($owner['email'], $visitorName, 'widget');
                } catch (Throwable $e) {
                    error_log('chat-widget email_new_lead: ' . $e->getMessage());
                }
            }
            try {
                require_once __DIR__ . '/../includes/notifications.php';
                notify_new_lead($botId, $visitorName, 'widget', $leadId);
            } catch (Throwable $e) {
                error_log('chat-widget notify_new_lead: ' . $e->getMessage());
            }
        }
    } else {
        $leadId = (int) $lead['id'];
    }

    $result = get_ai_response($leadId, $botId, $message, [
        'locale'  => trim($input['locale'] ?? ''),
        'country' => strtoupper(trim($input['country'] ?? '')),
    ]);
} catch (Throwable $e) {
    error_log('chat-widget error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG
            ? $e->getMessage()
            : 'Could not process your message. Please try again.',
    ], 500);
}

if (empty($result['success'])) {
    json_response([
        'success' => false,
        'error'   => trim((string) ($result['error'] ?? '')) !== ''
            ? (string) $result['error']
            : 'AI could not generate a reply. Check OpenAI API key in Admin → Integrations.',
    ], 500);
}

require_once __DIR__ . '/../includes/reply-timing.php';
human_agent_pause((string) ($result['reply'] ?? ''), $message);

json_response([
    'success'    => true,
    'reply'      => $result['reply'] ?? '',
    'signals'    => $result['signals'] ?? [],
    'paused'     => !empty($result['paused']),
    'lead_id'    => $leadId,
    'session_id' => $sessionId,
]);
