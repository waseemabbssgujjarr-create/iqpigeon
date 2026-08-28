<?php
/**
 * Attach catalog_id / customer business_id from Embedded Signup FINISH
 * after WhatsApp is already connected (late postMessage).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/meta-catalog-sync.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login();
$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$clientId = (int) ($input['client_id'] ?? 0);

if ($clientId <= 0) {
    json_response(['success' => false, 'error' => 'Missing client_id'], 400);
}

if ((int) $user['id'] !== $clientId && ($user['role'] ?? '') !== 'admin') {
    json_response(['success' => false, 'error' => 'Unauthorized'], 403);
}

$catalogRaw = $input['catalog_id'] ?? ($input['catalog_ids'] ?? '');
if (is_array($catalogRaw)) {
    $catalogRaw = $catalogRaw[0] ?? '';
}
$businessRaw = $input['business_id'] ?? '';
if (is_array($businessRaw)) {
    $businessRaw = $businessRaw[0] ?? '';
}

$result = meta_catalog_apply_signup_assets(
    $clientId,
    trim((string) $catalogRaw),
    trim((string) $businessRaw)
);

require_once __DIR__ . '/../../includes/whatsapp-oauth-debug.php';

if (function_exists('whatsapp_oauth_debug_log')) {
    whatsapp_oauth_debug_log('save_catalog_id', [
        'client_id'    => $clientId,
        'catalog_id'   => $result['catalog_id'] ?? '',
        'business_id'  => $result['business_id'] ?? '',
        'success'      => !empty($result['success']),
    ]);
}

json_response($result);
