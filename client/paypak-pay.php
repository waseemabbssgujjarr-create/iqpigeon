<?php
/**
 * Auto-submit PayPak hosted checkout form.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paypak.php';

$user = require_login();
$userId = (int) $user['id'];
$paymentId = (int) ($_GET['payment_id'] ?? 0);

ensure_payment_schema();

$payment = db_fetch(
    'SELECT * FROM subscription_payments WHERE id = ? AND user_id = ? AND gateway = \'paypak\' AND status = \'pending\'',
    'ii',
    [$paymentId, $userId]
);

if (!$payment) {
    redirect('/client/billing?error=' . urlencode('Payment session expired.'));
}

$tokenResult = paypak_get_access_token();
if (!$tokenResult['success']) {
    redirect('/client/billing?error=' . urlencode($tokenResult['message'] ?? 'PayPak unavailable'));
}

$orderRef = (string) $payment['order_ref'];
$amount = (float) $payment['amount'];
$signature = paypak_signature(PAYPAK_MERCHANT_ID, PAYPAK_MERCHANT_NAME, $amount, $orderRef);

$payload = [
    'MERCHANT_ID'            => PAYPAK_MERCHANT_ID,
    'MERCHANT_NAME'          => PAYPAK_MERCHANT_NAME,
    'TOKEN'                  => $tokenResult['token'],
    'PROCCODE'               => '00',
    'TXNAMT'                 => $amount,
    'CUSTOMER_MOBILE_NO'     => defined('PAYPAK_DEFAULT_MOBILE') ? PAYPAK_DEFAULT_MOBILE : '03000000000',
    'CUSTOMER_EMAIL_ADDRESS' => (string) $user['email'],
    'SIGNATURE'              => $signature,
    'VERSION'                => 'AILS-PAYMENT-1.0',
    'TXNDESC'                => APP_NAME . ' subscription',
    'SUCCESS_URL'            => APP_URL . '/api/paypak-callback.php?status=success',
    'FAILURE_URL'            => APP_URL . '/api/paypak-callback.php?status=failed',
    'BASKET_ID'              => $orderRef,
    'ORDER_DATE'             => date('Y-m-d H:i:s'),
    'CHECKOUT_URL'           => APP_URL . '/api/paypak-callback.php?status=ipn',
];

$checkoutUrl = paypak_checkout_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('PayPak Checkout') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface flex items-center justify-center min-h-[100dvh]">
    <div class="text-center p-lg max-w-md">
        <span class="material-symbols-outlined text-5xl text-primary mb-md animate-pulse">payments</span>
        <h1 class="font-title text-title-md mb-sm">Redirecting to PayPak</h1>
        <p class="text-body-md text-on-surface-variant mb-md"><?= sanitize(paypak_supported_methods_label()) ?></p>
        <p class="text-body-md text-on-surface-variant">Amount: <?= sanitize(format_plan_price((int) $amount, 'PKR')) ?></p>
    </div>
    <form id="paypak-form" action="<?= sanitize($checkoutUrl) ?>" method="post" class="hidden">
        <?php foreach ($payload as $key => $value): ?>
        <input type="hidden" name="<?= sanitize($key) ?>" value="<?= sanitize((string) $value) ?>"/>
        <?php endforeach; ?>
    </form>
    <script>document.getElementById('paypak-form').submit();</script>
</body>
</html>
