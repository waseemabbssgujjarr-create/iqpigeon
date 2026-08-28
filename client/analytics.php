<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/analytics.php';
require_once __DIR__ . '/../includes/catalog.php';

$user = require_login();
$userId = (int) $user['id'];

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? 0);

$stats = analytics_overview($userId, $botId ?: null);
$topProducts = analytics_top_products($userId, $botId ?: null);
$revenueDays = analytics_revenue_by_day($userId, 14, $botId ?: null);
$currency = !empty($user['billing_currency']) ? strtoupper((string) $user['billing_currency']) : visitor_currency();

require __DIR__ . '/../includes/views/client-analytics.php';
