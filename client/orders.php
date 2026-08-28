<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/lead-lifecycle.php';
require_once __DIR__ . '/../includes/shipment.php';
require_once __DIR__ . '/../includes/industry-order-pipeline.php';

ensure_shipment_schema();

$user = require_login();
$userId = (int) $user['id'];
$message = '';

if (isset($_GET['sync_orders']) && $_GET['sync_orders'] === '1') {
    $synced = lifecycle_repair_stuck_orders_for_user($userId, 100);
    $message = $synced > 0
        ? "Synced {$synced} order(s) from WhatsApp chat history."
        : 'No missing orders found â€” all chats are already synced.';
} else {
    $repairedOrders = lifecycle_repair_stuck_orders_for_user($userId, 50);
    if ($repairedOrders > 0 && $message === '') {
        $message = 'Synced ' . $repairedOrders . ' order' . ($repairedOrders === 1 ? '' : 's') . ' from WhatsApp chat history.';
    }
}

$bots = db_fetch_all(
    'SELECT id, name, industry_key, business_mode FROM bots WHERE user_id = ? ORDER BY name ASC',
    'i',
    [$userId]
);
$botId = (int) ($_GET['bot_id'] ?? 0);

$pipelineBot = null;
if ($botId > 0) {
    foreach ($bots as $b) {
        if ((int) $b['id'] === $botId) {
            $pipelineBot = $b;
            break;
        }
    }
}
if ($pipelineBot === null && $bots !== []) {
    $pipelineBot = $bots[0];
    $botId = (int) $pipelineBot['id'];
}
$pipeline = industry_order_pipeline_for_bot($pipelineBot);
$industryKey = (string) $pipeline['industry_key'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    if ($orderId && catalog_update_order_status($orderId, $userId, $status)) {
        $meta = catalog_order_status_last_update();
        $message = 'Order #' . $orderId . ' â†’ ' . ($meta['status_label'] ?? industry_order_status_label($status, $industryKey !== 'default' ? $industryKey : null));
        if (!empty($meta['customer_notified'])) {
            $message .= ' Â· Customer notified on WhatsApp';
        } elseif (!empty($meta['notify_error'])) {
            $message .= ' Â· WhatsApp: ' . $meta['notify_error'];
        }
    }
    $botId = (int) ($_POST['bot_id'] ?? $botId);
    if ($botId > 0) {
        foreach ($bots as $b) {
            if ((int) $b['id'] === $botId) {
                $pipelineBot = $b;
                $pipeline = industry_order_pipeline_for_bot($pipelineBot);
                $industryKey = (string) $pipeline['industry_key'];
                break;
            }
        }
    }
}


$columns = catalog_orders_by_status($userId, $botId ?: null);

$allOrderIds = [];
foreach ($columns as $statusOrders) {
    foreach ($statusOrders as $ord) {
        $allOrderIds[] = (int) $ord['id'];
    }
}
$orderStatusEvents = order_status_events_for_orders($allOrderIds);
$allOrderIds = [];
foreach ($columns as $colOrders) {
    foreach ($colOrders as $o) {
        $allOrderIds[] = (int) $o['id'];
    }
}
$shipmentMap = shipment_map_for_orders($allOrderIds);
$courierPresets = courier_manual_presets();
$statusOrder = $pipeline['status_order'];
$columnMeta = $pipeline['columns'];

$activeTab = 'orders';
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-orders.php';
return;
