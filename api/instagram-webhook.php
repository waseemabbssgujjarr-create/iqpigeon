<?php
/**
 * Instagram Messaging webhook — receive and reply to DMs.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/instagram.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/ai-respond.php';

if (!integration_instagram_enabled()) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Instagram integration disabled';
        exit;
    }
    http_response_code(503);
    echo 'Disabled';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && hash_equals(INSTAGRAM_VERIFY_TOKEN, $token)) {
        http_response_code(200);
        echo $challenge;
        exit;
    }

    http_response_code(403);
    exit;
}

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

if (!verify_meta_signature($payload, $signature)) {
    error_log('Instagram webhook: invalid signature');
    http_response_code(403);
    exit;
}

http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$data = json_decode($payload, true);

if (!$data || empty($data['entry'])) {
    exit;
}

foreach ($data['entry'] as $entry) {
    $messaging = $entry['messaging'] ?? [];

    foreach ($messaging as $event) {
        $senderId = $event['sender']['id'] ?? '';
        $text = $event['message']['text'] ?? '';
        $pageId = $event['recipient']['id'] ?? '';

        if (!$senderId || $text === '' || !$pageId) {
            continue;
        }

        $bot = db_fetch(
            'SELECT * FROM bots WHERE instagram_page_id = ? AND is_active = 1 LIMIT 1',
            's',
            [$pageId]
        );

        if (!$bot) {
            continue;
        }

        $token = decrypt_token($bot['instagram_token'] ?? '');
        if ($token === false) {
            continue;
        }

        $lead = db_fetch(
            'SELECT * FROM leads WHERE bot_id = ? AND external_id = ?',
            'is',
            [(int) $bot['id'], $senderId]
        );

        if (!$lead) {
            if (!within_lead_limit((int) $bot['user_id'])) {
                continue;
            }

            $leadId = db_insert(
                'INSERT INTO leads (bot_id, external_id, name, platform, status) VALUES (?, ?, ?, \'instagram\', \'new\')',
                'iss',
                [(int) $bot['id'], $senderId, 'Instagram Lead']
            );

            $owner = db_fetch('SELECT email FROM users WHERE id = ?', 'i', [(int) $bot['user_id']]);
            if ($owner) {
                email_new_lead($owner['email'], 'Instagram Lead', 'instagram');
            }
            require_once __DIR__ . '/../includes/notifications.php';
            notify_new_lead((int) $bot['id'], 'Instagram Lead', 'instagram', $leadId);
        } else {
            $leadId = (int) $lead['id'];
        }

        $ai = get_ai_response($leadId, (int) $bot['id'], $text);

require_once __DIR__ . '/../includes/reply-timing.php';

        if ($ai['success'] && !empty($ai['reply'])) {
            human_agent_pause($ai['reply'], $text);
            send_instagram_message($pageId, $token, $senderId, $ai['reply']);
        }
    }
}
