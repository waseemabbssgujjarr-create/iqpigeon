<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shipment.php';
require_once __DIR__ . '/../includes/cart.php';

header('Content-Type: application/json');

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$input = $isMultipart
    ? $_POST
    : (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []);

if (!verify_csrf($input['csrf_token'] ?? '')) {
    json_response(['success' => false, 'error' => 'Invalid token'], 403);
}

$action = trim($input['action'] ?? 'create');

/**
 * @return array<string, mixed>
 */
function shipment_api_create_payload(array $input, int $userId): array
{
    $data = [
        'courier_name'       => $input['courier_name'] ?? '',
        'tracking_number'    => $input['tracking_number'] ?? '',
        'tracking_url'       => $input['tracking_url'] ?? '',
        'dispatch_date'      => $input['dispatch_date'] ?? '',
        'estimated_delivery' => $input['estimated_delivery'] ?? '',
        'notes'              => $input['notes'] ?? '',
        'courier_provider'   => $input['courier_provider'] ?? 'manual',
    ];

    if (!empty($_FILES['receipt_image']) && ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = shipment_save_receipt_upload($_FILES['receipt_image'], $userId);
        if (empty($upload['success'])) {
            return ['success' => false, 'error' => $upload['error'] ?? 'Receipt upload failed'];
        }
        $data['receipt_image_url'] = $upload['url'];
    }

    return ['success' => true, 'data' => $data];
}

/**
 * @return array<int, array<string, string>>
 */
function shipment_parse_csv_rows(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $rows = [];
    $header = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line);
        if ($header === null) {
            $header = array_map(static fn ($h) => strtolower(trim(str_replace(' ', '_', $h))), $cols);
            if (in_array('order_id', $header, true) || in_array('orderid', $header, true)) {
                continue;
            }
            $rows[] = [
                'order_id'           => $cols[0] ?? '',
                'courier_name'       => $cols[1] ?? '',
                'tracking_number'    => $cols[2] ?? '',
                'dispatch_date'      => $cols[3] ?? '',
                'estimated_delivery' => $cols[4] ?? '',
                'tracking_url'       => $cols[5] ?? '',
                'notes'              => $cols[6] ?? '',
            ];
            $header = ['__inline__'];
            continue;
        }

        $row = [];
        foreach ($header as $i => $key) {
            if ($key === '__inline__') {
                continue;
            }
            $row[$key] = $cols[$i] ?? '';
        }
        if (isset($row['orderid']) && !isset($row['order_id'])) {
            $row['order_id'] = $row['orderid'];
        }
        if (!empty($row['order_id'])) {
            $rows[] = $row;
        }
    }

    return $rows;
}

if ($action === 'create') {
    $orderId = (int) ($input['order_id'] ?? 0);
    $payload = shipment_api_create_payload($input, $userId);
    if (empty($payload['success'])) {
        json_response($payload, 400);
    }

    $result = shipment_create_for_order($orderId, $userId, $payload['data']);

    if (!$result['success']) {
        json_response($result, 400);
    }

    json_response([
        'success'     => true,
        'shipment_id' => $result['shipment_id'],
        'order_id'    => $orderId,
        'status'      => 'shipped',
    ]);
}

if ($action === 'update') {
    $shipmentId = (int) ($input['shipment_id'] ?? 0);
    $updateData = $input;
    if (!empty($_FILES['receipt_image']) && ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = shipment_save_receipt_upload($_FILES['receipt_image'], $userId);
        if (empty($upload['success'])) {
            json_response(['success' => false, 'error' => $upload['error'] ?? 'Receipt upload failed'], 400);
        }
        $updateData['receipt_image_url'] = $upload['url'];
    }
    if (!$shipmentId || !shipment_update($shipmentId, $userId, $updateData)) {
        json_response(['success' => false, 'error' => 'Could not update shipment'], 400);
    }
    json_response(['success' => true, 'shipment_id' => $shipmentId]);
}

if ($action === 'status') {
    $shipmentId = (int) ($input['shipment_id'] ?? 0);
    $status = trim($input['status'] ?? '');
    $title = trim($input['title'] ?? '');
    if (!$shipmentId || !shipment_update_status($shipmentId, $userId, $status, $title !== '' ? $title : null)) {
        json_response(['success' => false, 'error' => 'Invalid status update'], 400);
    }
    json_response(['success' => true, 'shipment_id' => $shipmentId, 'status' => $status]);
}

if ($action === 'refresh') {
    $shipmentId = (int) ($input['shipment_id'] ?? 0);
    $result = shipment_refresh_from_api($shipmentId, $userId);
    json_response($result, !empty($result['success']) ? 200 : 400);
}

if ($action === 'get') {
    $orderId = (int) ($input['order_id'] ?? 0);
    $shipment = shipment_get_by_order($orderId);
    if (!$shipment || (int) $shipment['user_id'] !== $userId) {
        json_response(['success' => false, 'error' => 'Not found'], 404);
    }
    json_response([
        'success'  => true,
        'shipment' => $shipment,
        'timeline' => shipment_timeline((int) $shipment['id']),
    ]);
}

if ($action === 'bulk_import') {
    $csvRaw = trim((string) ($input['csv_text'] ?? ''));
    if ($csvRaw === '' && !empty($_FILES['csv_file']['tmp_name'])) {
        $csvRaw = (string) file_get_contents($_FILES['csv_file']['tmp_name']);
    }
    $rows = shipment_parse_csv_rows($csvRaw);
    if ($rows === []) {
        json_response(['success' => false, 'error' => 'No valid rows in CSV'], 400);
    }
    $result = shipment_bulk_import($userId, $rows);
    json_response(['success' => true] + $result);
}

if ($action === 'tracking_url') {
    $courier = trim((string) ($input['courier_name'] ?? ''));
    $tracking = trim((string) ($input['tracking_number'] ?? ''));
    json_response([
        'success'       => true,
        'tracking_url'  => shipment_build_tracking_url($courier, $tracking),
    ]);
}

json_response(['success' => false, 'error' => 'Unknown action'], 400);
