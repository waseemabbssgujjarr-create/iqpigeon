<?php
/**
 * Meta OAuth redirect target — exchange code and save WhatsApp connection.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth-debug.php';

$safeGet = static function (string $key): string {
    return trim((string) ($_GET[$key] ?? ''));
};

whatsapp_oauth_debug_log('callback_hit', [
    'client_id'  => (int) ($_SESSION['wa_oauth_client_id'] ?? 0),
    'has_code'   => $safeGet('code') !== '',
    'has_error'  => $safeGet('error') !== '' || $safeGet('error_description') !== '',
    'has_state'  => $safeGet('state') !== '',
    'waba_id'    => $safeGet('waba_id') ?: $safeGet('whatsapp_business_account_id'),
    'phone_id'   => $safeGet('phone_number_id') ?: $safeGet('phone_id'),
    'query_keys' => array_keys($_GET),
]);

$state = $safeGet('state');
$parsedState = whatsapp_oauth_parse_state($state);
$sessionState = (string) ($_SESSION['wa_oauth_state'] ?? '');

$returnPath = $parsedState['return']
    ?? whatsapp_oauth_normalize_return((string) ($_SESSION['wa_oauth_return'] ?? ''));
$popup = !empty($parsedState['popup']) || !empty($_SESSION['wa_oauth_popup']);

$errorParam = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
if ($errorParam !== '') {
    whatsapp_oauth_debug_log('callback_meta_error', [
        'client_id'  => (int) ($_SESSION['wa_oauth_client_id'] ?? 0),
        'meta_error' => $errorParam,
    ]);
    unset(
        $_SESSION['wa_oauth_state'],
        $_SESSION['wa_oauth_client_id'],
        $_SESSION['wa_oauth_waba_id'],
        $_SESSION['wa_oauth_phone_id'],
        $_SESSION['wa_oauth_return'],
        $_SESSION['wa_oauth_popup']
    );
    whatsapp_oauth_finish_flow($returnPath, ['error' => $errorParam], $popup);
}

$auth = whatsapp_oauth_callback_user($state);
if ($auth === null) {
    if ($parsedState === null && $state !== '' && $sessionState !== '' && hash_equals($sessionState, $state)) {
        $user = require_login();
        $auth = ['user' => $user, 'client_id' => (int) ($_SESSION['wa_oauth_client_id'] ?? $user['id'])];
    } else {
        whatsapp_oauth_debug_log('callback_auth_failed', [
            'client_id'     => (int) ($_SESSION['wa_oauth_client_id'] ?? 0),
            'error'         => 'Signup session expired or invalid state',
            'state_parsed'  => $parsedState !== null,
        ]);
        whatsapp_oauth_finish_flow($returnPath, [
            'error' => 'Signup session expired. Click Connect again and use Finish in Meta (do not use browser Back).',
        ], $popup);
    }
}

$clientId = (int) ($auth['client_id'] ?? 0);
$user = $auth['user'];

$wabaId = trim((string) (
    $_GET['waba_id']
    ?? $_GET['whatsapp_business_account_id']
    ?? $_SESSION['wa_oauth_waba_id']
    ?? ''
));
$phoneId = trim((string) (
    $_GET['phone_number_id']
    ?? $_GET['phone_id']
    ?? $_SESSION['wa_oauth_phone_id']
    ?? ''
));
$catalogId = trim((string) (
    $_GET['catalog_id']
    ?? ''
));
if ($catalogId === '') {
    $catalogIdsRaw = $_GET['catalog_ids'] ?? '';
    if (is_array($catalogIdsRaw)) {
        $catalogId = trim((string) ($catalogIdsRaw[0] ?? ''));
    } else {
        $catalogId = trim((string) $catalogIdsRaw);
    }
}
$businessId = trim((string) ($_GET['business_id'] ?? ''));

unset(
    $_SESSION['wa_oauth_state'],
    $_SESSION['wa_oauth_client_id'],
    $_SESSION['wa_oauth_waba_id'],
    $_SESSION['wa_oauth_phone_id'],
    $_SESSION['wa_oauth_return'],
    $_SESSION['wa_oauth_popup']
);

if ($parsedState === null && !($state !== '' && $sessionState !== '' && hash_equals($sessionState, $state))) {
    whatsapp_oauth_debug_log('callback_auth_failed', [
        'client_id' => $clientId,
        'error'     => 'State mismatch after auth',
    ]);
    whatsapp_oauth_finish_flow($returnPath, [
        'error' => 'Signup session expired. Click Connect again and use Finish in Meta (do not use browser Back).',
    ], $popup);
}

if ($clientId <= 0 || ((int) $user['id'] !== $clientId && ($user['role'] ?? '') !== 'admin')) {
    whatsapp_oauth_debug_log('callback_auth_failed', ['client_id' => $clientId, 'error' => 'Unauthorized']);
    whatsapp_oauth_finish_flow($returnPath, ['error' => 'Unauthorized'], $popup);
}

$code = $safeGet('code');
if ($code === '') {
    whatsapp_oauth_debug_log('callback_no_code', [
        'client_id' => $clientId,
        'error'     => 'Meta redirect reached IQ Pigeon but authorization code is missing',
    ]);
    whatsapp_oauth_finish_flow($returnPath, [
        'error' => 'Meta did not return an authorization code. In Meta click Get started → finish all steps → Finish. '
            . 'Open /client/whatsapp-oauth-debug for details.',
    ], $popup);
}

try {
    $result = whatsapp_complete_oauth_connection($clientId, $code, $wabaId, $phoneId, '', 'redirect', $catalogId, $businessId);
} catch (Throwable $e) {
    error_log('whatsapp oauth callback exception client=' . $clientId . ' ' . $e->getMessage());
    whatsapp_oauth_debug_log('callback_exchange_failed', [
        'client_id' => $clientId,
        'error'     => $e->getMessage(),
    ]);
    whatsapp_oauth_finish_flow($returnPath, [
        'error' => 'Connection failed while saving. Please try Connect again.',
    ], $popup);
}

if (empty($result['success'])) {
    error_log('whatsapp oauth callback failed client=' . $clientId . ' err=' . ($result['error'] ?? ''));
    whatsapp_oauth_debug_log('callback_exchange_failed', [
        'client_id' => $clientId,
        'error'     => (string) ($result['error'] ?? 'Connection failed'),
    ]);
    whatsapp_oauth_finish_flow($returnPath, ['error' => $result['error'] ?? 'Connection failed'], $popup);
}

whatsapp_oauth_debug_log('callback_saved', [
    'client_id'    => $clientId,
    'waba_id'      => $result['waba_id'] ?? '',
    'phone_number' => $result['phone_number'] ?? '',
    'catalog_id'   => $result['catalog_id'] ?? '',
    'business_id'  => $result['business_id'] ?? '',
]);

whatsapp_oauth_finish_flow($returnPath, ['connected' => '1'], $popup);
