<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/lead-lifecycle.php';

$user = require_login();
$userId = (int) $user['id'];

$syncMessage = '';
if (isset($_GET['sync_orders']) && $_GET['sync_orders'] === '1') {
    $synced = lifecycle_repair_stuck_orders_for_user($userId, 100);
    $syncMessage = $synced > 0
        ? "Synced {$synced} confirmed order(s) from WhatsApp chat history."
        : 'No missing orders found â€” all chats are already synced.';
} else {
    lifecycle_repair_stuck_orders_for_user($userId, 50);
}

$filter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$validFilters = ['all', 'new', 'in_progress', 'qualified', 'booked', 'disqualified'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'delete') {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        db_execute(
            'DELETE l FROM leads l JOIN bots b ON b.id = l.bot_id WHERE l.id = ? AND b.user_id = ?',
            'ii',
            [$leadId, $userId]
        );
        redirect('/client/leads');
    }

    if (($_POST['action'] ?? '') === 'export') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Name', 'Platform', 'Status', 'Score', 'Created']);

        $exportLeads = db_fetch_all(
            'SELECT l.* FROM leads l JOIN bots b ON b.id = l.bot_id WHERE b.user_id = ? ORDER BY l.created_at DESC',
            'i',
            [$userId]
        );
        foreach ($exportLeads as $row) {
            fputcsv($out, [$row['id'], $row['name'], $row['platform'], $row['status'], $row['score'], $row['created_at']]);
        }
        fclose($out);
        exit;
    }
}

$sql = 'SELECT l.*, tm.name AS assignee_name, tm.color AS assignee_color, (
            SELECT message FROM conversations c WHERE c.lead_id = l.id ORDER BY c.created_at DESC LIMIT 1
        ) AS last_message,
        ' . lead_last_activity_sql('l') . ' AS last_activity_at
        FROM leads l
        JOIN bots b ON b.id = l.bot_id
        LEFT JOIN team_members tm ON tm.id = l.assigned_member_id
        WHERE b.user_id = ?';
$types = 'i';
$params = [$userId];

if ($filter !== 'all') {
    $sql .= ' AND l.status = ?';
    $types .= 's';
    $params[] = $filter;
}

if ($search !== '') {
    $sql .= ' AND (l.name LIKE ? OR l.external_id LIKE ?)';
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY last_activity_at DESC';
$leads = db_fetch_all($sql, $types, $params);

$userBots = db_fetch_all('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC', 'i', [$userId]);
$defaultBotId = (int) ($userBots[0]['id'] ?? 0);

$activeTab = 'leads';
$selectedId = (int) ($_GET['lead_id'] ?? 0);
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-leads.php';
return;
