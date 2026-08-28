<?php
/**
 * Start Meta Embedded Signup OAuth — full-page redirect or popup (popup=1).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth-debug.php';

$user = require_login();
$clientId = (int) ($_GET['client_id'] ?? $user['id']);
$popup = !empty($_GET['popup']);

if ($clientId !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
    if ($popup) {
        whatsapp_oauth_render_popup_finish(false, 'Unauthorized', whatsapp_oauth_normalize_return((string) ($_GET['return'] ?? '')));
    }
    redirect(whatsapp_oauth_return_with_query(
        whatsapp_oauth_normalize_return((string) ($_GET['return'] ?? '')),
        ['error' => 'Unauthorized']
    ));
}

if (!integration_meta_configured()) {
    if ($popup) {
        whatsapp_oauth_render_popup_finish(false, 'WhatsApp signup is not configured on this site.', whatsapp_oauth_normalize_return((string) ($_GET['return'] ?? '')));
    }
    redirect(whatsapp_oauth_return_with_query(
        whatsapp_oauth_normalize_return((string) ($_GET['return'] ?? '')),
        ['error' => 'WhatsApp signup is not configured on this site.']
    ));
}

$returnPath = whatsapp_oauth_normalize_return(
    (string) ($_GET['return'] ?? ''),
    '/client/whatsapp-settings'
);
$state = whatsapp_oauth_build_state($clientId, $returnPath, $popup);
$_SESSION['wa_oauth_state'] = $state;
$_SESSION['wa_oauth_client_id'] = $clientId;
$_SESSION['wa_oauth_waba_id'] = trim((string) ($_GET['waba_id'] ?? ''));
$_SESSION['wa_oauth_phone_id'] = trim((string) ($_GET['phone_number_id'] ?? ''));
$_SESSION['wa_oauth_return'] = $returnPath;
$_SESSION['wa_oauth_popup'] = $popup ? 1 : 0;

$launchUrl = whatsapp_oauth_launch_url($state, $popup);
whatsapp_oauth_debug_log('oauth_start', [
    'client_id'        => $clientId,
    'return'           => $returnPath,
    'popup'            => $popup,
    'redirect_uri'     => whatsapp_oauth_redirect_uri(),
    'has_redirect_uri' => str_contains($launchUrl, 'redirect_uri='),
    'launch_host'      => 'business.facebook.com',
    'state_len'        => strlen($state),
]);

redirect($launchUrl);
