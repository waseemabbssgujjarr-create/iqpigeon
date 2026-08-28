<?php
/**
 * Poll new conversation messages for live chat UI.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/conversation-media.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login();
$userId = (int) $user['id'];
$leadId = (int) ($_GET['lead_id'] ?? 0);
$sinceId = (int) ($_GET['since_id'] ?? 0);

if ($leadId <= 0) {
    json_response(['success' => false, 'error' => 'lead_id is required.'], 400);
}

$lead = db_fetch(
    'SELECT l.*, b.name AS bot_name, b.rep_name
     FROM leads l
     JOIN bots b ON b.id = l.bot_id
     WHERE l.id = ? AND b.user_id = ?',
    'ii',
    [$leadId, $userId]
);

if (!$lead) {
    json_response(['success' => false, 'error' => 'Lead not found.'], 404);
}

ensure_conversations_schema();

$sql = 'SELECT * FROM conversations WHERE lead_id = ?';
$types = 'i';
$params = [$leadId];

if ($sinceId > 0) {
    $sql .= ' AND id > ?';
    $types .= 'i';
    $params[] = $sinceId;
}

$sql .= ' ORDER BY id ASC LIMIT 100';

$rows = db_fetch_all($sql, $types, $params);

$messages = [];
$lastId = $sinceId;

foreach ($rows as $row) {
    $lastId = max($lastId, (int) $row['id']);
    $display = conversation_message_display($row);
    $messages[] = [
        'id'          => (int) $row['id'],
        'role'        => (string) $row['role'],
        'message'     => (string) $row['message'],
        'kind'        => $display['kind'],
        'media_url'   => $display['media_url'],
        'transcript'  => $display['transcript'],
        'created_at'  => (string) $row['created_at'],
        'time_label'  => format_time($row['created_at']),
        'day_label'   => format_day_label($row['created_at']),
        'iso_time'    => datetime_to_iso($row['created_at']),
    ];
}

json_response([
    'success'    => true,
    'messages'   => $messages,
    'bot_paused' => is_lead_bot_paused($lead),
    'last_id'    => $lastId,
    'lead_name'  => (string) ($lead['name'] ?? 'Lead'),
    'rep_name'   => get_bot_rep_name(['rep_name' => $lead['rep_name'] ?? '', 'name' => $lead['bot_name'] ?? '']),
]);
