<?php
/**
 * Notifications API — poll, mark read (JSON).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

$user = require_login();
$userId = (int) $user['id'];

if (!notifications_tables_ready()) {
    json_response(['success' => true, 'unread' => 0, 'notifications' => [], 'ready' => false]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $since = (int) ($_GET['since_id'] ?? 0);
    $limit = min(30, max(5, (int) ($_GET['limit'] ?? 15)));

    $notifications = get_user_notifications($userId, $limit);

    if ($since > 0) {
        $notifications = array_values(array_filter(
            $notifications,
            static fn ($n) => (int) $n['id'] > $since
        ));
    }

    $payload = [];
    foreach ($notifications as $n) {
        $payload[] = [
            'id'         => (int) $n['id'],
            'type'       => $n['type'],
            'title'      => $n['title'],
            'message'    => $n['message'],
            'link'       => $n['link'] ?: null,
            'is_read'    => (bool) $n['is_read'],
            'icon'       => notification_icon($n['type']),
            'created_at' => $n['created_at'],
            'time_ago'   => format_date($n['created_at']),
        ];
    }

    json_response([
        'success'       => true,
        'unread'        => get_unread_notification_count($userId),
        'notifications' => $payload,
        'ready'         => true,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    security_require_api_csrf();
    $input = security_cached_json_body();
    $action = $input['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int) ($input['id'] ?? 0);
        mark_notification_read($id, $userId);
        json_response(['success' => true, 'unread' => get_unread_notification_count($userId)]);
    }

    if ($action === 'mark_all_read') {
        mark_all_notifications_read($userId);
        json_response(['success' => true, 'unread' => 0]);
    }

    json_response(['success' => false, 'error' => 'Invalid action'], 400);
}

json_response(['success' => false, 'error' => 'Method not allowed'], 405);
