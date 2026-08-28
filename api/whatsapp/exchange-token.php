<?php

/**

 * Exchange Embedded Signup OAuth code for WABA + phone number tokens.

 */



require_once __DIR__ . '/../../config.php';

require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/auth.php';

require_once __DIR__ . '/../../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../../includes/whatsapp-oauth-debug.php';



header('Content-Type: application/json');



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    json_response(['success' => false, 'error' => 'Method not allowed'], 405);

}



$user = require_login();



$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

$code = trim($input['code'] ?? '');

$clientId = (int) ($input['client_id'] ?? 0);

$catalogIdInput = $input['catalog_id'] ?? ($input['catalog_ids'] ?? '');
if (is_array($catalogIdInput)) {
    $catalogIdInput = $catalogIdInput[0] ?? '';
}
$catalogIdInput = trim((string) $catalogIdInput);

$businessIdInput = $input['business_id'] ?? '';
if (is_array($businessIdInput)) {
    $businessIdInput = $businessIdInput[0] ?? '';
}
$businessIdInput = trim((string) $businessIdInput);



if ($code === '' || !$clientId) {

    json_response(['success' => false, 'error' => 'Missing code or client_id'], 400);

}



if ((int) $user['id'] !== $clientId && $user['role'] !== 'admin') {

    json_response(['success' => false, 'error' => 'Unauthorized'], 403);

}



if (!integration_meta_configured()) {

    json_response(['success' => false, 'error' => 'META_APP_ID and META_APP_SECRET must be configured in config.php or Admin → Integrations'], 500);

}



try {

    $result = whatsapp_complete_oauth_connection(
        $clientId,
        $code,
        trim((string) ($input['waba_id'] ?? '')),
        trim((string) ($input['phone_number_id'] ?? '')),
        trim((string) ($input['display_phone_number'] ?? '')),
        'sdk',
        $catalogIdInput,
        $businessIdInput
    );



    if (empty($result['success'])) {

        whatsapp_oauth_debug_log('exchange_token_failed', [
            'client_id' => $clientId,
            'error'     => (string) ($result['error'] ?? 'Connection failed'),
            'mode'      => 'sdk',
        ]);

        json_response(['success' => false, 'error' => $result['error'] ?? 'Connection failed'], 400);

    }



    whatsapp_oauth_debug_log('exchange_token_saved', [
        'client_id'    => $clientId,
        'waba_id'      => $result['waba_id'] ?? '',
        'phone_number' => $result['phone_number'] ?? '',
        'catalog_id'   => $result['catalog_id'] ?? '',
        'business_id'  => $result['business_id'] ?? '',
        'mode'         => 'sdk',
    ]);

    json_response([

        'success'      => true,

        'phone_number' => $result['phone_number'] ?? '',

        'waba_id'      => $result['waba_id'] ?? '',

        'catalog_id'   => $result['catalog_id'] ?? '',

        'business_id'  => $result['business_id'] ?? '',

    ]);

} catch (Throwable $e) {

    error_log('exchange-token error: ' . $e->getMessage());

    json_response(['success' => false, 'error' => 'Token exchange failed. Please try again.'], 500);

}

