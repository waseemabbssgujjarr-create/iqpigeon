<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/client-shell.php';

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'mark_all') {
        mark_all_notifications_read($userId);
        redirect('/client/notifications');
    }
}

$notifications = get_user_notifications($userId, 50);
$updates = notifications_tables_ready()
    ? db_fetch_all('SELECT * FROM system_updates ORDER BY sent_at DESC LIMIT 20', '', [])
    : [];

$activeTab = 'notifications';
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-updates.php';
return;
