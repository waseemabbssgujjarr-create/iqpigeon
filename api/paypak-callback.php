<?php
/**
 * PayPak return / IPN callback (success, failure, server notify).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paypak.php';

$params = array_merge($_GET, $_POST);
$status = strtolower(trim((string) ($params['status'] ?? 'success')));

if ($status === 'ipn') {
    $result = paypak_handle_callback($params, 'success');
    http_response_code(200);
    echo 'OK';
    exit;
}

$result = paypak_handle_callback($params, $status === 'failed' ? 'failed' : 'success');
$redirect = $result['redirect'] ?? '/client/billing';

if (!empty($_SESSION['user_id'])) {
    redirect($redirect);
}

redirect('/login.php?redirect=' . urlencode($redirect));
