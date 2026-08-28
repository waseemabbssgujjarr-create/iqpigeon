<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/../includes/billing-settings.php';
require_once __DIR__ . '/../includes/phase5-schema.php';

ensure_phase5_schema();

$user = require_login();
$clientId = (int) $user['id'];

$account = db_fetch(
    'SELECT * FROM client_whatsapp_accounts
     WHERE client_id = ? AND connection_status = \'active\'
     ORDER BY connected_at DESC LIMIT 1',
    'i',
    [$clientId]
);

$isConnected = $account !== null;
require_once __DIR__ . '/../includes/integration-settings.php';
$manualMode = integration_whatsapp_manual_mode();

$manualBot = db_fetch(
    'SELECT id, name, whatsapp_phone_id, whatsapp_verified FROM bots
     WHERE user_id = ? AND whatsapp_verified = 1 AND whatsapp_phone_id IS NOT NULL AND whatsapp_phone_id != \'\'
     ORDER BY id ASC LIMIT 1',
    'i',
    [$clientId]
);
$manualConnected = $manualMode && $manualBot !== null;
$primaryBotId = $manualBot ? (int) $manualBot['id'] : (int) (db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$clientId])['id'] ?? 0);

$flashSuccess = trim($_GET['connected'] ?? '') === '1';
$flashError = trim($_GET['error'] ?? '');
$waIsTestNumber = $isConnected && whatsapp_is_meta_test_number($account['phone_display_number'] ?? '');
$waUsage = client_whatsapp_usage_stats($clientId);
$billingSettings = get_billing_settings();
$waBillingBlocked = billing_whatsapp_blocked_for_user($user);
$inboundReady = $isConnected ? whatsapp_ensure_client_inbound_ready($clientId) : null;
$tokenReadable = $isConnected && whatsapp_client_access_token($clientId, false) !== false;

$messages = db_fetch_all(
    'SELECT * FROM whatsapp_messages_log WHERE client_id = ? ORDER BY created_at DESC LIMIT 50',
    'i',
    [$clientId]
);

$flashMessage = '';
$flashErr = '';
$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_widget_color' && $primaryBotId > 0) {
        $curBot = db_fetch('SELECT widget_color FROM bots WHERE id = ? AND user_id = ?', 'ii', [$primaryBotId, $clientId]);
        $fallback = normalize_widget_color((string) ($curBot['widget_color'] ?? ''), '#1FA855');
        $newColor = normalize_widget_color(trim((string) ($_POST['widget_color'] ?? '')), $fallback);
        db_execute(
            'UPDATE bots SET widget_color = ? WHERE id = ? AND user_id = ?',
            'sii',
            [$newColor, $primaryBotId, $clientId]
        );
        $flashMessage = 'Widget color saved — your embed code is updated.';
    } elseif ($primaryBotId <= 0) {
        $flashErr = 'Create a bot first to customize the widget.';
    }
}

$activeTab = 'whatsapp';
$waShowMetaSdk = !$isConnected && !$manualMode && integration_meta_configured();
$oauthStartUrl = whatsapp_oauth_start_url($clientId, '/client/whatsapp-settings', false);
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-whatsapp.php';
return;
