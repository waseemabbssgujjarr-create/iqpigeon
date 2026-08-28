<?php
/**
 * Save demo visitor training (text / website / PDF link) before chat.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/demo-training.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

security_rate_limit_or_abort('demo_training', 20, 60);

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$botId = (int) ($input['bot_id'] ?? 0);
$sessionId = trim($input['session_id'] ?? '');

if (!$botId || $sessionId === '') {
    json_response(['success' => false, 'message' => 'Missing bot_id or session_id'], 400);
}

if (!is_demo_bot($botId)) {
    json_response(['success' => false, 'message' => 'Training is only available on the demo bot.'], 403);
}

$bot = db_fetch(
    'SELECT * FROM bots WHERE id = ? AND widget_enabled = 1 AND is_active = 1',
    'i',
    [$botId]
);

if (!$bot) {
    json_response(['success' => false, 'message' => 'Demo bot not available.'], 404);
}

try {
    ensure_leads_schema();
} catch (Throwable $e) {
    error_log('demo-training schema check: ' . $e->getMessage());
}

$externalId = 'widget_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

$lead = db_fetch(
    'SELECT * FROM leads WHERE bot_id = ? AND external_id = ?',
    'is',
    [$botId, $externalId]
);

if (!$lead) {
    if (!within_lead_limit((int) $bot['user_id'])) {
        json_response(['success' => false, 'message' => 'Demo temporarily unavailable.'], 429);
    }

    $leadId = db_insert(
        'INSERT INTO leads (bot_id, external_id, name, platform, status) VALUES (?, ?, ?, \'widget\', \'new\')',
        'iss',
        [$botId, $externalId, 'Demo Visitor']
    );
} else {
    $leadId = (int) $lead['id'];
}

$result = save_demo_training($leadId, [
    'text'          => $input['text'] ?? '',
    'website'       => $input['website'] ?? '',
    'pdf_url'       => $input['pdf_url'] ?? '',
    'business_name' => $input['business_name'] ?? '',
]);

if (!$result['success']) {
    json_response($result, 400);
}

$businessName = $result['training']['business_name'] ?? 'your business';
$greeting = "Hello — I'm here for {$businessName}. How can I help you today?";

json_response([
    'success'  => true,
    'message'  => $result['message'],
    'greeting' => $greeting,
    'lead_id'  => $leadId,
]);
