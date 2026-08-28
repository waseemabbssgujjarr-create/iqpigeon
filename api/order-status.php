<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/cart.php';

require_once __DIR__ . '/../includes/shipment.php';

require_once __DIR__ . '/../includes/industry-order-pipeline.php';
require_once __DIR__ . '/../includes/bot-context.php';



header('Content-Type: application/json');



$user = require_login();

$userId = (int) $user['id'];



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    json_response(['success' => false, 'error' => 'Method not allowed'], 405);

}



$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

if (!verify_csrf($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {

    json_response(['success' => false, 'error' => 'Invalid token'], 403);

}



$orderId = (int) ($input['order_id'] ?? $_POST['order_id'] ?? 0);

$status = trim($input['status'] ?? $_POST['status'] ?? '');



if (!$orderId || $status === '') {

    json_response(['success' => false, 'error' => 'Missing order_id or status'], 400);

}



if ($status === 'shipped') {
    $orderRow = db_fetch(
        'SELECT o.bot_id, b.industry_key, b.business_mode FROM bot_orders o
         JOIN bots b ON b.id = o.bot_id WHERE o.id = ? AND o.user_id = ?',
        'ii',
        [$orderId, $userId]
    );
    $industryKey = industry_order_pipeline_for_bot($orderRow ?: null)['industry_key'];

    if (industry_order_requires_shipment('shipped', $industryKey) && !shipment_get_by_order($orderId)) {
        json_response([
            'success'            => false,
            'requires_shipment'  => true,
            'error'              => 'Enter courier & tracking details to mark as shipped',
            'order_id'           => $orderId,
        ], 422);
    }
}



if (!catalog_update_order_status($orderId, $userId, $status)) {

    json_response(['success' => false, 'error' => 'Invalid status'], 400);

}



$orderBot = db_fetch('SELECT bot_id FROM bot_orders WHERE id = ? AND user_id = ?', 'ii', [$orderId, $userId]);
$botId = (int) ($orderBot['bot_id'] ?? 0);
$meta = catalog_order_status_last_update();

json_response(bot_context_api_envelope($botId, $userId, [
    'success'           => true,
    'order_id'          => $orderId,
    'status'            => $status,
    'status_label'      => $meta['status_label'] ?? $status,
    'customer_notified' => !empty($meta['customer_notified']),
    'notify_error'      => $meta['notify_error'] ?? null,
]));

