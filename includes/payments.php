<?php
/**
 * Unified billing — PayPak (Pakistan) or Stripe (international).
 */

require_once __DIR__ . '/payment-schema.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/paypak.php';

/** @return 'paypak'|'stripe' */
function payment_gateway(?string $currency = null): string
{
    require_once __DIR__ . '/billing-settings.php';

    return billing_resolve_gateway($currency);
}

function payment_gateway_label(?string $gateway = null): string
{
    $gateway = $gateway ?? payment_gateway();
    return $gateway === 'paypak'
        ? 'PayPak — ' . paypak_supported_methods_label()
        : 'Stripe — Card (USD)';
}

/**
 * Create checkout for subscription upgrade / signup.
 *
 * @return array{success: bool, type?: string, url?: string, payment_id?: int, message?: string}
 */
function payment_create_checkout(int $userId, string $plan): array
{
    ensure_payment_schema();

    $gateway = payment_gateway();
    if ($gateway === 'paypak') {
        $result = paypak_create_subscription_checkout($userId, $plan);
        if (!$result['success']) {
            return $result;
        }
        return [
            'success'    => true,
            'type'       => 'paypak',
            'payment_id' => $result['payment_id'],
        ];
    }

    $stripe = stripe_create_checkout($userId, $plan);
    if (!$stripe['success']) {
        return $stripe;
    }

    db_execute(
        'UPDATE users SET payment_provider = \'stripe\', billing_currency = \'USD\' WHERE id = ?',
        'i',
        [$userId]
    );

    return [
        'success' => true,
        'type'    => 'redirect',
        'url'     => $stripe['url'],
    ];
}

function payment_user_is_pakistan(int $userId): bool
{
    $user = db_fetch('SELECT billing_currency FROM users WHERE id = ?', 'i', [$userId]);
    if (!empty($user['billing_currency'])) {
        return strtoupper((string) $user['billing_currency']) === 'PKR';
    }
    return is_pakistan_visitor();
}

function payment_subscription_expiring_soon(int $userId, int $days = 7): bool
{
    ensure_payment_schema();
    $user = db_fetch('SELECT subscription_expires_at, payment_provider FROM users WHERE id = ?', 'i', [$userId]);
    if (empty($user['subscription_expires_at']) || ($user['payment_provider'] ?? '') !== 'paypak') {
        return false;
    }
    $expires = strtotime($user['subscription_expires_at']);
    return $expires > time() && $expires <= strtotime('+' . $days . ' days');
}
