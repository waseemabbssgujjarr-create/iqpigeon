<?php
/**
 * Shipment tracking — manual default, optional courier API sync.
 * AI always reads from shipment database, never courier APIs directly.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/phase7-schema.php';
require_once __DIR__ . '/courier/providers.php';

function ensure_shipment_schema(): void
{
    ensure_phase7_schema();
}

function shipment_statuses(): array
{
    return [
        'shipment_created'  => 'Shipment Created',
        'picked_up'         => 'Picked Up',
        'in_transit'        => 'In Transit',
        'arrived_at_hub'    => 'Arrived at Hub',
        'out_for_delivery'  => 'Out For Delivery',
        'delivered'         => 'Delivered',
        'failed_delivery'   => 'Failed Delivery',
        'returned'          => 'Returned',
    ];
}

function shipment_status_label(string $status): string
{
    return shipment_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function shipment_status_icon(string $status): string
{
    return match ($status) {
        'picked_up'        => 'inventory',
        'in_transit'       => 'local_shipping',
        'arrived_at_hub'   => 'warehouse',
        'out_for_delivery' => 'delivery_dining',
        'delivered'        => 'check_circle',
        'failed_delivery'  => 'error',
        'returned'         => 'undo',
        default            => 'package_2',
    };
}

function shipment_courier_logo_key(string $courierName): string
{
    $n = mb_strtolower(trim($courierName));
    foreach (['leopards', 'tcs', 'blueex', 'trax', 'm&p', 'mandp', 'dhl', 'fedex', 'ups', 'postex'] as $key) {
        if (str_contains($n, str_replace('&', '', $key))) {
            return $key;
        }
    }
    return 'generic';
}

/**
 * Auto-build tracking URLs for known couriers (Pakistan + international).
 */
function shipment_tracking_url_templates(): array
{
    return [
        'leopards' => 'https://track.leopardscourier.com/track?cn={tracking}',
        'tcs'      => 'https://www.tcsexpress.com/track/{tracking}',
        'blueex'   => 'https://www.blue-ex.com/tracking?tracking_no={tracking}',
        'trax'     => 'https://trax.pk/track/{tracking}',
        'mandp'    => 'https://www.mulphilog.com/tracking/{tracking}',
        'm&p'      => 'https://www.mulphilog.com/tracking/{tracking}',
        'postex'   => 'https://postex.pk/tracking/{tracking}',
        'dhl'      => 'https://www.dhl.com/pk-en/home/tracking.html?tracking-id={tracking}',
        'fedex'    => 'https://www.fedex.com/fedextrack/?trknbr={tracking}',
        'ups'      => 'https://www.ups.com/track?tracknum={tracking}',
    ];
}

function shipment_build_tracking_url(string $courierName, string $trackingNumber): string
{
    $tracking = trim($trackingNumber);
    if ($tracking === '') {
        return '';
    }

    $key = shipment_courier_logo_key($courierName);
    $templates = shipment_tracking_url_templates();
    if (!isset($templates[$key])) {
        return '';
    }

    return str_replace('{tracking}', rawurlencode($tracking), $templates[$key]);
}

function shipment_generate_public_token(): string
{
    return bin2hex(random_bytes(16));
}

function shipment_public_track_url(array $shipment): string
{
    $token = trim((string) ($shipment['public_tracking_token'] ?? ''));
    if ($token === '') {
        return '';
    }
    return rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/track.php?t=' . rawurlencode($token);
}

function shipment_uploads_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/shipments';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * @return array{success: bool, url?: string, path?: string, error?: string}
 */
function shipment_save_receipt_upload(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'No file'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid upload'];
    }

    $maxBytes = 8 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'error' => 'Image must be under 8 MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return ['success' => false, 'error' => 'Use JPG, PNG, or WebP for parcel receipt'];
    }

    $userDir = shipment_uploads_dir() . '/' . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }

    $name = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $userDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Could not save image'];
    }

    $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/uploads/shipments/' . $userId . '/' . $name;

    return ['success' => true, 'url' => $url, 'path' => $dest];
}

function shipment_message_wants_receipt(string $message): bool
{
    return (bool) preg_match(
        '/receipt|parcel slip|shipping slip|label photo|consignment slip|tracking photo|'
        . 'parcel photo|receipt image|label pic|bhej do receipt|receipt bhej/iu',
        mb_strtolower(trim($message))
    );
}

/**
 * Human rep-style tracking reply (for AI + direct handler).
 *
 * @param array<string, mixed> $shipment
 */
function shipment_format_rep_reply(array $shipment, array $order, bool $includeReceiptHint = false, bool $offerReceipt = false): string
{
    require_once __DIR__ . '/helpers.php';
    $courier = (string) ($shipment['courier_name'] ?? 'our courier');
    $tracking = (string) ($shipment['tracking_number'] ?? '');
    $eta = shipment_format_eta($shipment['estimated_delivery'] ?? null);
    $status = shipment_status_label((string) ($shipment['current_status'] ?? ''));
    $orderId = (int) ($order['id'] ?? 0);

    $lines = ["Sure — I've pulled up your order #{$orderId} for you."];
    $lines[] = '';
    $lines[] = "Courier: *{$courier}*";
    $lines[] = "Tracking number: *{$tracking}*";
    if ($eta !== '') {
        $lines[] = 'Estimated delivery: ' . $eta;
    }
    $lines[] = 'Status: ' . $status;

    $trackUrl = trim((string) ($shipment['tracking_url'] ?? ''));
    if ($trackUrl !== '') {
        $lines[] = 'Track online: ' . $trackUrl;
    }
    $public = shipment_public_track_url($shipment);
    if ($public !== '') {
        $lines[] = 'Live updates: ' . $public;
    }

    if ($includeReceiptHint && !empty($shipment['receipt_image_url'])) {
        $lines[] = '';
        $lines[] = "I'm sending your parcel receipt photo now — it has the full tracking details on it.";
    } elseif ($offerReceipt) {
        $lines[] = '';
        $lines[] = 'Need the parcel receipt photo? Just say "send receipt" and I\'ll share it here.';
    }

    return implode("\n", $lines);
}

function shipment_send_receipt_image(int $shipmentId): bool
{
    ensure_shipment_schema();
    require_once __DIR__ . '/whatsapp.php';

    $shipment = db_fetch('SELECT * FROM shipments WHERE id = ?', 'i', [$shipmentId]);
    if (!$shipment || empty($shipment['receipt_image_url'])) {
        return false;
    }

    $order = db_fetch('SELECT * FROM bot_orders WHERE id = ?', 'i', [(int) $shipment['order_id']]);
    if (!$order) {
        return false;
    }

    $phone = preg_replace('/\D/', '', (string) ($order['customer_phone'] ?? ''));
    if ($phone === '' && !empty($order['lead_id'])) {
        $lead = db_fetch('SELECT external_id FROM leads WHERE id = ?', 'i', [(int) $order['lead_id']]);
        $phone = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
    }
    if ($phone === '') {
        return false;
    }

    $creds = whatsapp_bot_credentials((int) $order['bot_id'], (int) $order['user_id']);
    if (!$creds) {
        return false;
    }

    $caption = '📋 Parcel receipt — ' . ($shipment['courier_name'] ?? 'Courier')
        . "\nTracking: " . ($shipment['tracking_number'] ?? '');

    $result = send_whatsapp_image(
        $creds['phone_id'],
        $creds['token'],
        $phone,
        (string) $shipment['receipt_image_url'],
        $caption
    );

    if (!empty($result['success']) && !empty($order['lead_id'])) {
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [(int) $order['lead_id'], '[Parcel receipt photo sent] ' . $caption]
        );
    }

    return !empty($result['success']);
}

/**
 * Bulk import shipments from CSV rows.
 *
 * @param array<int, array<string, string>> $rows
 * @return array{imported: int, skipped: int, errors: array<int, string>}
 */
function shipment_bulk_import(int $userId, array $rows): array
{
    $imported = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $i => $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            $skipped++;
            continue;
        }
        if (shipment_get_by_order($orderId)) {
            $errors[] = 'Row ' . ($i + 1) . ': Order #' . $orderId . ' already has shipment';
            $skipped++;
            continue;
        }

        $result = shipment_create_for_order($orderId, $userId, [
            'courier_name'       => $row['courier_name'] ?? $row['courier'] ?? '',
            'tracking_number'    => $row['tracking_number'] ?? $row['tracking'] ?? '',
            'tracking_url'       => $row['tracking_url'] ?? '',
            'dispatch_date'      => $row['dispatch_date'] ?? '',
            'estimated_delivery' => $row['estimated_delivery'] ?? $row['eta'] ?? '',
            'notes'              => $row['notes'] ?? '',
        ], true);

        if ($result['success']) {
            $imported++;
        } else {
            $errors[] = 'Row ' . ($i + 1) . ': ' . ($result['error'] ?? 'Failed');
            $skipped++;
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * @return array<string, mixed>|null
 */
function shipment_get_by_public_token(string $token): ?array
{
    ensure_shipment_schema();
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    return db_fetch('SELECT * FROM shipments WHERE public_tracking_token = ? LIMIT 1', 's', [$token]) ?: null;
}

function shipment_get_by_order(int $orderId): ?array
{
    ensure_shipment_schema();
    $row = db_fetch('SELECT * FROM shipments WHERE order_id = ? LIMIT 1', 'i', [$orderId]);
    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function shipment_get(int $shipmentId, int $userId): ?array
{
    ensure_shipment_schema();
    $row = db_fetch('SELECT * FROM shipments WHERE id = ? AND user_id = ?', 'ii', [$shipmentId, $userId]);
    return $row ?: null;
}

/**
 * @param array<int> $orderIds
 * @return array<int, array<string, mixed>>
 */
function shipment_map_for_orders(array $orderIds): array
{
    if ($orderIds === []) {
        return [];
    }
    ensure_shipment_schema();
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));
    $rows = db_fetch_all(
        "SELECT * FROM shipments WHERE order_id IN ({$placeholders})",
        $types,
        $orderIds
    );
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['order_id']] = $row;
    }
    return $map;
}

/**
 * @return array<int, array<string, mixed>>
 */
function shipment_timeline(int $shipmentId): array
{
    ensure_shipment_schema();
    return db_fetch_all(
        'SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY event_at ASC, id ASC',
        'i',
        [$shipmentId]
    );
}

function shipment_add_event(
    int $shipmentId,
    string $status,
    string $title,
    ?string $description = null,
    ?string $location = null,
    ?string $eventAt = null,
    string $source = 'system'
): void {
    ensure_shipment_schema();
    db_insert(
        'INSERT INTO shipment_events (shipment_id, status, title, description, location, event_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        'issssss',
        [
            $shipmentId,
            $status,
            $title,
            $description,
            $location,
            $eventAt ?? date('Y-m-d H:i:s'),
            in_array($source, ['manual', 'system', 'api'], true) ? $source : 'system',
        ]
    );
}

/**
 * Create shipment + mark order shipped + notify customer.
 *
 * @param array<string, mixed> $data
 * @return array{success: bool, shipment_id?: int, error?: string}
 */
function shipment_create_for_order(int $orderId, int $userId, array $data, bool $notifyCustomer = true): array
{
    ensure_shipment_schema();
    require_once __DIR__ . '/cart.php';

    $order = db_fetch('SELECT * FROM bot_orders WHERE id = ? AND user_id = ?', 'ii', [$orderId, $userId]);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    if (shipment_get_by_order($orderId)) {
        return ['success' => false, 'error' => 'Shipment already exists for this order'];
    }

    $courierName = trim((string) ($data['courier_name'] ?? ''));
    $trackingNumber = trim((string) ($data['tracking_number'] ?? ''));
    if ($courierName === '' || $trackingNumber === '') {
        return ['success' => false, 'error' => 'Courier name and tracking number are required'];
    }

    $dispatchDate = trim((string) ($data['dispatch_date'] ?? ''));
    $estimatedDelivery = trim((string) ($data['estimated_delivery'] ?? ''));
    $trackingUrl = trim((string) ($data['tracking_url'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $receiptUrl = trim((string) ($data['receipt_image_url'] ?? ''));
    $courierProvider = trim((string) ($data['courier_provider'] ?? 'manual'));
    $settings = courier_settings_for_bot((int) $order['bot_id']) ?: [];
    $autoUrls = !isset($settings['auto_tracking_urls']) || !empty($settings['auto_tracking_urls']);
    if ($trackingUrl === '' && $autoUrls) {
        $trackingUrl = shipment_build_tracking_url($courierName, $trackingNumber);
    }
    $publicToken = shipment_generate_public_token();
    $apiEnabled = !empty($settings['api_enabled']) && $courierProvider !== 'manual' && $courierProvider === ($settings['provider'] ?? '');

    $shipmentId = db_insert(
        'INSERT INTO shipments (order_id, bot_id, user_id, lead_id, courier_name, tracking_number, tracking_url,
         dispatch_date, estimated_delivery, current_status, courier_provider, api_enabled, notes,
         receipt_image_url, public_tracking_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'shipment_created\', ?, ?, ?, ?, ?)',
        'iiiisssssissss',
        [
            $orderId,
            (int) $order['bot_id'],
            $userId,
            !empty($order['lead_id']) ? (int) $order['lead_id'] : null,
            $courierName,
            $trackingNumber,
            $trackingUrl !== '' ? $trackingUrl : null,
            $dispatchDate !== '' ? $dispatchDate : null,
            $estimatedDelivery !== '' ? $estimatedDelivery : null,
            $courierProvider !== '' ? $courierProvider : 'manual',
            $apiEnabled ? 1 : 0,
            $notes !== '' ? $notes : null,
            $receiptUrl !== '' ? $receiptUrl : null,
            $publicToken,
        ]
    );

    shipment_add_event($shipmentId, 'shipment_created', 'Shipment created', 'Order handed to ' . $courierName, null, null, 'manual');
    shipment_add_event($shipmentId, 'picked_up', 'Picked up by courier', null, null, null, 'system');

    db_execute(
        'UPDATE shipments SET current_status = \'picked_up\' WHERE id = ?',
        'i',
        [$shipmentId]
    );

    db_execute(
        'UPDATE bot_orders SET status = \'shipped\' WHERE id = ? AND user_id = ?',
        'ii',
        [$orderId, $userId]
    );

    require_once __DIR__ . '/industry-order-pipeline.php';
    $botRow = db_fetch('SELECT industry_key, business_mode FROM bots WHERE id = ?', 'i', [(int) $order['bot_id']]);
    $pipelineKey = industry_order_pipeline_for_bot($botRow)['industry_key'];
    $shippedLabel = industry_order_status_label('shipped', $pipelineKey !== 'default' ? $pipelineKey : null);
    $oldStatus = (string) ($order['status'] ?? 'new');

    if ($notifyCustomer) {
        shipment_notify_customer($shipmentId, 'shipped', true);
        order_status_log_event(
            $orderId,
            (int) $order['bot_id'],
            $userId,
            $oldStatus,
            'shipped',
            true,
            null,
            'shipment',
            $shippedLabel
        );
    } else {
        order_status_log_event(
            $orderId,
            (int) $order['bot_id'],
            $userId,
            $oldStatus,
            'shipped',
            false,
            null,
            'shipment',
            $shippedLabel
        );
    }

    return ['success' => true, 'shipment_id' => $shipmentId];
}

/**
 * @param array<string, mixed> $data
 */
function shipment_update(int $shipmentId, int $userId, array $data): bool
{
    ensure_shipment_schema();
    $shipment = shipment_get($shipmentId, $userId);
    if (!$shipment) {
        return false;
    }

    $fields = [];
    $params = [];
    $types = '';

    foreach ([
        'courier_name'       => 's',
        'tracking_number'    => 's',
        'tracking_url'       => 's',
        'dispatch_date'      => 's',
        'estimated_delivery' => 's',
        'notes'              => 's',
        'current_status'     => 's',
        'receipt_image_url'  => 's',
        'pod_image_url'      => 's',
    ] as $col => $type) {
        if (array_key_exists($col, $data)) {
            $val = trim((string) $data[$col]);
            $fields[] = "{$col} = ?";
            $params[] = $val !== '' ? $val : null;
            $types .= $type;
        }
    }

    if ($fields === []) {
        return true;
    }

    $params[] = $shipmentId;
    $params[] = $userId;
    $types .= 'ii';

    db_execute(
        'UPDATE shipments SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
        $types,
        $params
    );

    return true;
}

function shipment_update_status(
    int $shipmentId,
    int $userId,
    string $newStatus,
    ?string $title = null,
    ?string $description = null,
    ?string $location = null,
    string $source = 'manual',
    bool $notifyCustomer = true
): bool {
    ensure_shipment_schema();
    if (!array_key_exists($newStatus, shipment_statuses())) {
        return false;
    }

    $shipment = shipment_get($shipmentId, $userId);
    if (!$shipment) {
        return false;
    }

    $oldStatus = (string) ($shipment['current_status'] ?? '');
    if ($oldStatus === $newStatus) {
        return true;
    }

    db_execute(
        'UPDATE shipments SET current_status = ?, last_synced_at = NOW() WHERE id = ?',
        'si',
        [$newStatus, $shipmentId]
    );

    shipment_add_event(
        $shipmentId,
        $newStatus,
        $title ?? shipment_status_label($newStatus),
        $description,
        $location,
        null,
        $source
    );

    if ($newStatus === 'delivered') {
        db_execute(
            'UPDATE bot_orders SET status = \'delivered\' WHERE id = ?',
            'i',
            [(int) $shipment['order_id']]
        );
    }

    if ($notifyCustomer) {
        shipment_notify_customer($shipmentId, $newStatus, false);
    }

    return true;
}

function shipment_format_eta(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    $today = strtotime('today');
    $tomorrow = strtotime('tomorrow');
    if ($ts >= $today && $ts < $tomorrow) {
        return 'Today';
    }
    if ($ts >= $tomorrow && $ts < strtotime('+2 days')) {
        return 'Tomorrow';
    }
    return date('M j, Y', $ts);
}

/**
 * Build customer WhatsApp message for shipment updates.
 *
 * @param array<string, mixed> $shipment
 */
function shipment_whatsapp_message(array $shipment, string $context = 'shipped'): string
{
    $courier = (string) ($shipment['courier_name'] ?? 'Courier');
    $tracking = (string) ($shipment['tracking_number'] ?? '');
    $eta = shipment_format_eta($shipment['estimated_delivery'] ?? null);
    $url = trim((string) ($shipment['tracking_url'] ?? ''));
    $orderId = (int) ($shipment['order_id'] ?? 0);
    $status = (string) ($shipment['current_status'] ?? '');

    if ($context === 'shipped' || $context === 'shipment_created' || $context === 'picked_up') {
        $lines = [
            '🎉 Good news!',
            '',
            'Your order has been shipped.',
            '',
            'Courier: ' . $courier,
            'Tracking Number: ' . $tracking,
        ];
        if ($eta !== '') {
            $lines[] = 'Estimated Delivery: ' . $eta;
        }
        if ($url !== '') {
            $lines[] = 'Track here: ' . $url;
        }
        $public = shipment_public_track_url($shipment);
        if ($public !== '') {
            $lines[] = 'Live tracking: ' . $public;
        }
        $lines[] = '';
        $lines[] = "We'll keep you updated on delivery.";
        if (!empty($shipment['receipt_image_url'])) {
            $lines[] = '';
            $lines[] = "I'm also sending your parcel receipt photo with the tracking details.";
        }
        return implode("\n", $lines);
    }

    $msg = match ($status) {
        'picked_up'        => "📦 Your parcel has been collected by {$courier}.\n\nTracking: {$tracking}",
        'in_transit'       => "🚚 Your parcel is currently in transit.\n\nCourier: {$courier}\nTracking: {$tracking}",
        'arrived_at_hub'   => "🏢 Your parcel arrived at a {$courier} hub.\n\nTracking: {$tracking}",
        'out_for_delivery' => "🛵 Great news! Your parcel is out for delivery today.\n\nCourier: {$courier}\nTracking: {$tracking}",
        'delivered'        => "✅ Your order #{$orderId} has been delivered successfully.\n\nThank you for shopping with us!",
        'failed_delivery'  => "⚠️ Delivery attempt was unsuccessful.\n\nCourier: {$courier}\nTracking: {$tracking}\n\nWe'll try again — reply here if you need help.",
        'returned'         => "↩️ Your parcel was returned to sender.\n\nTracking: {$tracking}\n\nReply here and we'll help you.",
        default            => "📦 Order #{$orderId} update: " . shipment_status_label($status) . "\n\nTracking: {$tracking}",
    };

    if ($url !== '' && $status !== 'delivered') {
        $msg .= "\n\nTrack: {$url}";
    }

    return $msg;
}

function shipment_notify_customer(int $shipmentId, string $context = 'shipped', bool $isInitialShip = false): void
{
    ensure_shipment_schema();
    require_once __DIR__ . '/whatsapp.php';

    $shipment = db_fetch('SELECT * FROM shipments WHERE id = ?', 'i', [$shipmentId]);
    if (!$shipment) {
        return;
    }

    $order = db_fetch('SELECT * FROM bot_orders WHERE id = ?', 'i', [(int) $shipment['order_id']]);
    if (!$order) {
        return;
    }

    $phone = preg_replace('/\D/', '', (string) ($order['customer_phone'] ?? ''));
    if ($phone === '' && !empty($order['lead_id'])) {
        $lead = db_fetch('SELECT external_id FROM leads WHERE id = ?', 'i', [(int) $order['lead_id']]);
        $phone = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
    }
    if ($phone === '') {
        return;
    }

    $creds = whatsapp_bot_credentials((int) $order['bot_id'], (int) $order['user_id']);
    if (!$creds) {
        return;
    }

    $ctx = $isInitialShip ? 'shipped' : (string) ($shipment['current_status'] ?? $context);
    $msg = shipment_whatsapp_message($shipment, $ctx);

    send_whatsapp_message($creds['phone_id'], $creds['token'], $phone, $msg);

    if ($isInitialShip && !empty($shipment['receipt_image_url'])) {
        $settings = courier_settings_for_bot((int) $order['bot_id']) ?: [];
        if (!isset($settings['send_receipt_on_ship']) || !empty($settings['send_receipt_on_ship'])) {
            shipment_send_receipt_image($shipmentId);
        }
    }

    if (!empty($order['lead_id'])) {
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [(int) $order['lead_id'], '[Shipment update] ' . $msg]
        );
    }
}

function shipment_message_is_tracking_query(string $message): bool
{
    $m = mb_strtolower(trim($message));
    if ($m === '') {
        return false;
    }

    return (bool) preg_match(
        '/track(ing)?|where is my|order status|delivery update|parcel|shipment|consignment|'
        . 'tracking number|kahan hai|parcel kahan|order kahan|deliver ho|shipped|ship hua/iu',
        $m
    );
}

/**
 * @param array<string, mixed> $options
 * @return array{reply: string, send_receipt_image?: bool, shipment_id?: int}|null
 */
function shipment_handle_customer_query(int $leadId, int $botId, string $phone = '', array $options = []): ?array
{
    ensure_shipment_schema();
    $userMessage = (string) ($options['message'] ?? '');
    $wantsReceipt = $userMessage !== '' && shipment_message_wants_receipt($userMessage);

    $lead = db_fetch('SELECT * FROM leads WHERE id = ? AND bot_id = ?', 'ii', [$leadId, $botId]);
    if (!$lead) {
        return null;
    }

    $phoneDigits = preg_replace('/\D/', '', $phone);
    if ($phoneDigits === '') {
        $phoneDigits = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
    }

    $order = db_fetch(
        'SELECT o.* FROM bot_orders o
         WHERE o.bot_id = ? AND o.lead_id = ?
         ORDER BY o.created_at DESC LIMIT 1',
        'ii',
        [$botId, $leadId]
    );

    if (!$order && $phoneDigits !== '') {
        $order = db_fetch(
            'SELECT o.* FROM bot_orders o
             WHERE o.bot_id = ? AND REPLACE(REPLACE(o.customer_phone, \'+\', \'\'), \' \', \'\') LIKE ?
             ORDER BY o.created_at DESC LIMIT 1',
            'is',
            [$botId, '%' . substr($phoneDigits, -10)]
        );
    }

    if (!$order) {
        return [
            'reply' => "Let me check — I don't see an active order on this chat yet. Have you placed an order with us? Share your name or what you ordered and I'll look it up.",
        ];
    }

    $orderId = (int) $order['id'];
    $status = (string) ($order['status'] ?? 'new');
    $total = function_exists('catalog_format_price')
        ? catalog_format_price((float) $order['total_amount'], (string) ($order['currency'] ?? 'PKR'))
        : (string) $order['total_amount'];

    $shipment = shipment_get_by_order($orderId);

    if ($status === 'new' || $status === 'confirmed') {
        $label = $status === 'confirmed' ? 'confirmed and being prepared' : 'received';
        return [
            'reply' => "Your order #{$orderId} is {$label}.\n\nTotal: {$total} (COD)\n\nWe'll notify you here as soon as it ships.",
        ];
    }

    if (!$shipment && $status === 'shipped') {
        return [
            'reply' => "Your order #{$orderId} has been marked shipped.\n\nTracking details are being updated — I'll send them here shortly.",
        ];
    }

    if (!$shipment) {
        if ($status === 'delivered') {
            return ['reply' => "Your order #{$orderId} was delivered. Thank you for shopping with us! Need anything else?"];
        }
        return [
            'reply' => "Order #{$orderId} status: " . ucfirst($status) . ".\n\nTotal: {$total}",
        ];
    }

    $hasReceipt = !empty($shipment['receipt_image_url']);
    $sendReceipt = $hasReceipt && $wantsReceipt;
    $offerReceipt = !$sendReceipt && $hasReceipt && shipment_message_is_tracking_query($userMessage);

    return [
        'reply'              => shipment_format_rep_reply($shipment, $order, $sendReceipt, $offerReceipt),
        'send_receipt_image' => $sendReceipt,
        'shipment_id'        => (int) $shipment['id'],
    ];
}

function shipment_ai_context_block(int $leadId, int $botId): string
{
    ensure_shipment_schema();
    $order = db_fetch(
        'SELECT o.* FROM bot_orders o WHERE o.lead_id = ? AND o.bot_id = ? ORDER BY o.created_at DESC LIMIT 1',
        'ii',
        [$leadId, $botId]
    );
    if (!$order) {
        return '';
    }

    $shipment = shipment_get_by_order((int) $order['id']);
    $lines = [
        '',
        '───── CUSTOMER ORDER & SHIPMENT (from database — use this for tracking questions) ─────',
        'Order #' . $order['id'] . ' status: ' . ($order['status'] ?? 'new'),
    ];

    if ($shipment) {
        $lines[] = 'Shipment status: ' . shipment_status_label((string) ($shipment['current_status'] ?? ''));
        $lines[] = 'Courier: ' . ($shipment['courier_name'] ?? '');
        $lines[] = 'Tracking: ' . ($shipment['tracking_number'] ?? '');
        if (!empty($shipment['estimated_delivery'])) {
            $lines[] = 'ETA: ' . shipment_format_eta($shipment['estimated_delivery']);
        }
        if (!empty($shipment['tracking_url'])) {
            $lines[] = 'Tracking URL: ' . $shipment['tracking_url'];
        }
        if (!empty($shipment['receipt_image_url'])) {
            $lines[] = 'Parcel receipt photo on file — send to customer when they ask for tracking/receipt.';
        }
        $public = shipment_public_track_url($shipment);
        if ($public !== '') {
            $lines[] = 'Public track link: ' . $public;
        }
        $lines[] = 'Reply as a human rep with courier name + tracking number. NEVER say team will contact them.';
    } else {
        $lines[] = 'No shipment record yet — if not shipped, say order is being prepared.';
    }

    return implode("\n", $lines);
}

/**
 * @return array<string, mixed>|null
 */
function courier_settings_for_bot(int $botId): ?array
{
    ensure_shipment_schema();
    return db_fetch('SELECT * FROM bot_courier_settings WHERE bot_id = ?', 'i', [$botId]) ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function courier_settings_save(int $botId, int $userId, array $data): bool
{
    ensure_shipment_schema();
    $provider = trim((string) ($data['provider'] ?? 'manual'));
    if (!courier_provider($provider) && $provider !== 'manual') {
        $provider = 'manual';
    }

    $existing = courier_settings_for_bot($botId);
    $fields = [
        'provider'            => $provider,
        'api_username'        => trim((string) ($data['api_username'] ?? '')),
        'api_password'        => trim((string) ($data['api_password'] ?? '')),
        'api_key'             => trim((string) ($data['api_key'] ?? '')),
        'api_secret'          => trim((string) ($data['api_secret'] ?? '')),
        'account_number'      => trim((string) ($data['account_number'] ?? '')),
        'environment'         => ($data['environment'] ?? '') === 'sandbox' ? 'sandbox' : 'production',
        'api_enabled'         => !empty($data['api_enabled']) ? 1 : 0,
        'auto_tracking_urls'  => !isset($data['auto_tracking_urls']) || !empty($data['auto_tracking_urls']) ? 1 : 0,
        'send_receipt_on_ship'=> !isset($data['send_receipt_on_ship']) || !empty($data['send_receipt_on_ship']) ? 1 : 0,
    ];

    if ($existing) {
        db_execute(
            'UPDATE bot_courier_settings SET provider = ?, api_username = ?, api_password = ?, api_key = ?,
             api_secret = ?, account_number = ?, environment = ?, api_enabled = ?, auto_tracking_urls = ?,
             send_receipt_on_ship = ? WHERE bot_id = ? AND user_id = ?',
            'sssssssiiiii',
            [
                $fields['provider'],
                $fields['api_username'],
                $fields['api_password'],
                $fields['api_key'],
                $fields['api_secret'],
                $fields['account_number'],
                $fields['environment'],
                $fields['api_enabled'],
                $fields['auto_tracking_urls'],
                $fields['send_receipt_on_ship'],
                $botId,
                $userId,
            ]
        );
    } else {
        db_insert(
            'INSERT INTO bot_courier_settings (bot_id, user_id, provider, api_username, api_password, api_key,
             api_secret, account_number, environment, api_enabled, auto_tracking_urls, send_receipt_on_ship)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iisssssssiii',
            [
                $botId,
                $userId,
                $fields['provider'],
                $fields['api_username'],
                $fields['api_password'],
                $fields['api_key'],
                $fields['api_secret'],
                $fields['account_number'],
                $fields['environment'],
                $fields['api_enabled'],
                $fields['auto_tracking_urls'],
                $fields['send_receipt_on_ship'],
            ]
        );
    }

    return true;
}

function shipment_refresh_from_api(int $shipmentId, int $userId): array
{
    ensure_shipment_schema();
    $shipment = shipment_get($shipmentId, $userId);
    if (!$shipment) {
        return ['success' => false, 'error' => 'Shipment not found'];
    }

    if (empty($shipment['api_enabled'])) {
        return ['success' => false, 'error' => 'API tracking not enabled for this shipment'];
    }

    $settings = courier_settings_for_bot((int) $shipment['bot_id']);
    if (!$settings || empty($settings['api_enabled'])) {
        return ['success' => false, 'error' => 'Courier API not configured'];
    }

    $provider = courier_provider((string) ($shipment['courier_provider'] ?? $settings['provider'] ?? 'manual'));
    if (!$provider) {
        return ['success' => false, 'error' => 'Unknown courier provider'];
    }

    $result = $provider->trackShipment((string) $shipment['tracking_number'], $settings);
    if (empty($result['success'])) {
        db_execute('UPDATE shipments SET last_synced_at = NOW() WHERE id = ?', 'i', [$shipmentId]);
        return ['success' => false, 'error' => $result['error'] ?? 'Tracking failed'];
    }

    $mapped = $provider->mapStatus((string) ($result['status'] ?? 'in_transit'));
    $changed = ((string) $shipment['current_status'] !== $mapped);

    if ($changed) {
        shipment_update_status(
            $shipmentId,
            $userId,
            $mapped,
            (string) ($result['title'] ?? shipment_status_label($mapped)),
            isset($result['description']) ? (string) $result['description'] : null,
            isset($result['location']) ? (string) $result['location'] : null,
            'api',
            true
        );
    } else {
        db_execute('UPDATE shipments SET last_synced_at = NOW() WHERE id = ?', 'i', [$shipmentId]);
    }

    return ['success' => true, 'changed' => $changed, 'status' => $mapped];
}

function shipment_sync_all(int $limit = 100): array
{
    ensure_shipment_schema();

    $rows = db_fetch_all(
        'SELECT * FROM shipments WHERE api_enabled = 1 AND current_status NOT IN (\'delivered\', \'returned\')
         ORDER BY COALESCE(last_synced_at, created_at) ASC LIMIT ' . max(1, min(200, $limit))
    );

    $stats = ['checked' => 0, 'updated' => 0, 'errors' => 0];
    foreach ($rows as $row) {
        $stats['checked']++;
        $result = shipment_refresh_from_api((int) $row['id'], (int) $row['user_id']);
        if (!empty($result['success']) && !empty($result['changed'])) {
            $stats['updated']++;
        } elseif (empty($result['success'])) {
            $stats['errors']++;
        }
    }

    return $stats;
}
