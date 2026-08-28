<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/billing-settings.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/client-shell.php';

$user = require_login();
$userId = (int) $user['id'];
ensure_payment_schema();
$limits = get_plan_limits($user['subscription_plan']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'checkout') {
        $plan = in_array($_POST['plan'] ?? '', ['starter', 'pro', 'growth'], true) ? $_POST['plan'] : 'starter';
        if ($plan === 'growth') {
            $plan = 'pro';
        }
        $checkout = payment_create_checkout($userId, $plan);
        if ($checkout['success']) {
            if (($checkout['type'] ?? '') === 'paypak' && !empty($checkout['payment_id'])) {
                redirect('/client/paypak-pay?payment_id=' . (int) $checkout['payment_id']);
            }
            if (!empty($checkout['url'])) {
                redirect($checkout['url']);
            }
        }
        redirect('/client/billing?error=' . urlencode($checkout['message'] ?? 'Checkout failed'));
    }

    if ($action === 'cancel' && !empty($user['stripe_subscription_id'])) {
        stripe_cancel_subscription($user['stripe_subscription_id']);
        redirect('/client/billing?success=1');
    }
}

$botCount = db_fetch('SELECT COUNT(*) AS cnt FROM bots WHERE user_id = ?', 'i', [$userId]);
$monthChats = count_monthly_chats($userId);

$plans = localized_plans();
$billingCurrency = visitor_currency();
$billingGateway = payment_gateway($billingCurrency);
$errorMsg = trim($_GET['error'] ?? '');
$errorMessages = [
    'paypak_missing_ref' => 'Payment reference missing. Please try again.',
    'paypak_not_found'   => 'Payment record not found.',
    'paypak_invalid'     => 'Payment verification failed.',
    'paypak_config'      => 'PayPak gateway is not configured on the server.',
];
if ($errorMsg && isset($errorMessages[$errorMsg])) {
    $errorMsg = $errorMessages[$errorMsg];
}

$statusLabels = [
    'active'   => ['Active', 'bg-primary-container text-on-primary-container'],
    'trialing' => ['Trial', 'bg-tertiary-container text-on-tertiary-container'],
    'past_due' => ['Past Due', 'bg-error-container text-on-error-container'],
    'canceled' => ['Canceled', 'bg-surface-container-highest text-on-surface-variant'],
];
$subStatus = $statusLabels[$user['subscription_status']] ?? $statusLabels['trialing'];
$activeTab = 'billing';
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-billing.php';
return;
