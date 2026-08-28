<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/platform-schema.php';
platform_ensure_all_silent();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/commerce-schema.php';

require_once __DIR__ . '/../includes/whatsapp-oauth.php';

$user = require_login();

if (empty($_GET['skip_wa']) && needs_whatsapp_connect((int) $user['id'])) {
    redirect('/client/connect-whatsapp');
}

if (needs_onboarding((int) $user['id'])) {
    ensure_client_starter_bot((int) $user['id']);
}

$userId = (int) $user['id'];
$limits = get_plan_limits($user['subscription_plan']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $botId = (int) ($_POST['bot_id'] ?? 0);

    if ($action === 'toggle_bot' && $botId) {
        $bot = db_fetch('SELECT is_active FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
        if ($bot) {
            $newState = $bot['is_active'] ? 0 : 1;
            db_execute('UPDATE bots SET is_active = ? WHERE id = ? AND user_id = ?', 'iii', [$newState, $botId, $userId]);
        }
        redirect('/client/dashboard');
    }
}

$stats = db_fetch(
    'SELECT
        COUNT(l.id) AS total_leads,
        SUM(CASE WHEN l.status = \'qualified\' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN l.status = \'booked\' OR l.calendly_link_sent = 1 THEN 1 ELSE 0 END) AS booked
     FROM leads l
     JOIN bots b ON b.id = l.bot_id
     WHERE b.user_id = ?',
    'i',
    [$userId]
);

$totalLeads = (int) ($stats['total_leads'] ?? 0);
$qualified = (int) ($stats['qualified'] ?? 0);
$booked = (int) ($stats['booked'] ?? 0);
$conversion = $totalLeads > 0 ? round(($qualified / $totalLeads) * 100) : 0;

$weekLeads = db_fetch(
    'SELECT COUNT(l.id) AS cnt FROM leads l
     JOIN bots b ON b.id = l.bot_id
     WHERE b.user_id = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
    'i',
    [$userId]
);
$prevWeek = db_fetch(
    'SELECT COUNT(l.id) AS cnt FROM leads l
     JOIN bots b ON b.id = l.bot_id
     WHERE b.user_id = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
       AND l.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)',
    'i',
    [$userId]
);
$weekCnt = (int) ($weekLeads['cnt'] ?? 0);
$prevCnt = (int) ($prevWeek['cnt'] ?? 0);
$weekGrowth = $prevCnt > 0 ? round((($weekCnt - $prevCnt) / $prevCnt) * 100) : ($weekCnt > 0 ? 100 : 0);

$bots = db_fetch_all('SELECT * FROM bots WHERE user_id = ? ORDER BY created_at ASC', 'i', [$userId]);
$primaryBot = $bots[0] ?? null;

$recentLeads = db_fetch_all(
    'SELECT l.*, COALESCE(
        (SELECT message FROM conversations c WHERE c.lead_id = l.id AND c.role = \'user\' ORDER BY c.created_at DESC LIMIT 1),
        (SELECT message FROM conversations c WHERE c.lead_id = l.id ORDER BY c.created_at DESC LIMIT 1)
     ) AS last_message,
     ' . lead_last_activity_sql('l') . ' AS last_activity_at
     FROM leads l
     JOIN bots b ON b.id = l.bot_id
     WHERE b.user_id = ?
     ORDER BY last_activity_at DESC
     LIMIT 5',
    'i',
    [$userId]
);

$chatUsage = client_chat_usage_stats($userId, (string) ($user['subscription_plan'] ?? 'starter'));

$weekGrowthLabel = $prevCnt > 0
    ? (($weekGrowth >= 0 ? '↑' : '↓') . ' ' . abs($weekGrowth) . '% this week')
    : ($weekCnt > 0 ? 'New this week' : 'No change this week');

$greeting = 'Good morning';
$hour = (int) date('G');
if ($hour >= 12 && $hour < 17) {
    $greeting = 'Good afternoon';
} elseif ($hour >= 17) {
    $greeting = 'Good evening';
}

$activeTab = 'home';
require __DIR__ . '/../includes/views/client-home.php';
