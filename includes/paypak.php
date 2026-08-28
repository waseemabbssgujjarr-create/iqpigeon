<?php
/**
 * PayPak payments (Pakistan) — JazzCash, Easypaisa, PayPak cards & bank transfer.
 * Powered by PayFast hosted checkout (SBP-licensed gateway).
 */

require_once __DIR__ . '/payment-schema.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/integration-settings.php';

function paypak_configured(): bool
{
    return integration_paypak_configured();
}

function paypak_api_base(): string
{
    $sandbox = integration_config_bool('PAYPAK_SANDBOX', true);
    if ($sandbox && defined('PAYPAK_SANDBOX_API_URL') && PAYPAK_SANDBOX_API_URL !== '') {
        return rtrim(PAYPAK_SANDBOX_API_URL, '/') . '/';
    }
    if (!$sandbox && defined('PAYPAK_LIVE_API_URL') && PAYPAK_LIVE_API_URL !== '') {
        return rtrim(PAYPAK_LIVE_API_URL, '/') . '/';
    }
    return $sandbox
        ? 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/'
        : 'https://ipg.apps.net.pk/Ecommerce/api/Transaction/';
}

function paypak_checkout_url(): string
{
    $sandbox = integration_config_bool('PAYPAK_SANDBOX', true);
    if ($sandbox && defined('PAYPAK_SANDBOX_CHECKOUT_URL') && PAYPAK_SANDBOX_CHECKOUT_URL !== '') {
        return PAYPAK_SANDBOX_CHECKOUT_URL;
    }
    if (!$sandbox && defined('PAYPAK_LIVE_CHECKOUT_URL') && PAYPAK_LIVE_CHECKOUT_URL !== '') {
        return PAYPAK_LIVE_CHECKOUT_URL;
    }
    return $sandbox
        ? 'https://ipguat.apps.net.pk/Ecommerce/api/Checkout'
        : 'https://ipg.apps.net.pk/Ecommerce/api/Checkout';
}

/**
 * @return array{success: bool, token?: string, message?: string}
 */
function paypak_get_access_token(): array
{
    if (!paypak_configured()) {
        return ['success' => false, 'message' => 'PayPak is not configured.'];
    }

    $fields = http_build_query([
        'merchant_id' => integration_config('PAYPAK_MERCHANT_ID'),
        'secured_key'   => integration_config('PAYPAK_SECURED_KEY'),
        'grant_type'    => 'client_credentials',
        'customer_ip'   => client_ip(),
    ]);

    $ch = curl_init(paypak_api_base() . 'token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $code >= 400) {
        return ['success' => false, 'message' => 'Could not connect to PayPak.'];
    }

    $data = json_decode($response, true);
    $token = $data['token'] ?? ($data['data']['token'] ?? null);
    if (!$token) {
        return ['success' => false, 'message' => $data['message'] ?? 'PayPak auth failed.'];
    }

    return ['success' => true, 'token' => (string) $token];
}

function paypak_generate_order_ref(int $userId): string
{
    return 'SUB-' . $userId . '-' . time() . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function paypak_signature(string $merchantId, string $merchantName, float $amount, string $orderRef): string
{
    return md5($merchantId . ':' . $merchantName . ':' . $amount . ':' . $orderRef);
}

/**
 * Start PayPak hosted checkout for a subscription plan.
 *
 * @return array{success: bool, payment_id?: int, checkout_url?: string, form_html?: string, message?: string}
 */
function paypak_create_subscription_checkout(int $userId, string $plan): array
{
    ensure_payment_schema();

    if (!paypak_configured()) {
        return ['success' => false, 'message' => 'PayPak payment gateway is not configured yet. Contact support.'];
    }

    $user = db_fetch('SELECT id, email, name FROM users WHERE id = ?', 'i', [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    $plan = normalize_plan_slug($plan);
    $plans = get_plans();
    if (!isset($plans[$plan]) || !empty($plans[$plan]['contact_only'])) {
        return ['success' => false, 'message' => 'Invalid plan.'];
    }

    $amount = (float) plan_price_amount($plans[$plan], 'PKR');
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Invalid plan amount.'];
    }

    $orderRef = paypak_generate_order_ref($userId);

    $paymentId = db_insert(
        'INSERT INTO subscription_payments (user_id, plan, gateway, currency, amount, order_ref, status)
         VALUES (?, ?, \'paypak\', \'PKR\', ?, ?, \'pending\')',
        'isds',
        [$userId, $plan, $amount, $orderRef]
    );

    db_execute(
        'UPDATE users SET subscription_plan = ?, billing_currency = \'PKR\' WHERE id = ?',
        'si',
        [$plan, $userId]
    );

    return [
        'success'    => true,
        'payment_id' => $paymentId,
        'order_ref'  => $orderRef,
    ];
}

/**
 * Verify PayPak return callback and activate subscription if valid.
 *
 * @param array<string, mixed> $params
 * @return array{success: bool, message?: string, redirect?: string}
 */
function paypak_handle_callback(array $params, string $status): array
{
    ensure_payment_schema();

    $orderRef = trim((string) ($params['BASKET_ID'] ?? $params['basket_id'] ?? $params['order_id'] ?? ''));
    $receivedSig = trim((string) ($params['SIGNATURE'] ?? $params['signature'] ?? ''));

    if ($orderRef === '') {
        return ['success' => false, 'message' => 'Missing order reference.', 'redirect' => '/client/billing?error=paypak_missing_ref'];
    }

    $payment = subscription_payment_by_ref($orderRef);
    if (!$payment) {
        return ['success' => false, 'message' => 'Payment not found.', 'redirect' => '/client/billing?error=paypak_not_found'];
    }

    if ($status === 'failed') {
        subscription_payment_mark_failed((int) $payment['id'], json_encode($params));
        return ['success' => false, 'message' => 'Payment failed.', 'redirect' => '/client/billing?canceled=1'];
    }

    if (!paypak_configured()) {
        return ['success' => false, 'message' => 'PayPak not configured.', 'redirect' => '/client/billing?error=paypak_config'];
    }

    $expectedSig = paypak_signature(
        integration_config('PAYPAK_MERCHANT_ID'),
        integration_config('PAYPAK_MERCHANT_NAME'),
        (float) $payment['amount'],
        $orderRef
    );

    if ($receivedSig === '' || !hash_equals($expectedSig, $receivedSig)) {
        subscription_payment_mark_failed((int) $payment['id'], json_encode($params));
        return ['success' => false, 'message' => 'Invalid or missing signature.', 'redirect' => '/client/billing?error=paypak_invalid'];
    }

    $txnId = trim((string) ($params['transaction_id'] ?? $params['TXNID'] ?? $params['pp_TxnRefNo'] ?? ''));
    subscription_payment_mark_paid((int) $payment['id'], $txnId ?: null, json_encode($params));
    subscription_activate_from_payment(
        (int) $payment['user_id'],
        (string) $payment['plan'],
        'paypak',
        (string) $payment['currency']
    );

    return [
        'success'  => true,
        'message'  => 'Payment successful.',
        'redirect' => '/client/dashboard?payment=success',
    ];
}

function paypak_supported_methods_label(): string
{
    return 'JazzCash · Easypaisa · PayPak · Bank transfer';
}
