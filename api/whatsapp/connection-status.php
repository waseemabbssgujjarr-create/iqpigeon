<?php
/**
 * Poll whether the logged-in client has completed WhatsApp Embedded Signup.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/whatsapp-oauth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = require_login();
$clientId = (int) $user['id'];

json_response([
    'success'   => true,
    'connected' => whatsapp_client_embedded_connected($clientId),
    'client_id' => $clientId,
]);
