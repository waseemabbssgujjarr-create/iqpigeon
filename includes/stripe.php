<?php
/**
 * Stripe checkout and subscription helpers (curl-based).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integration-settings.php';

/**
 * Map plan name to Stripe Price ID.
 *
 * @param string $plan starter|pro|growth|agency
 * @return string
 */
function stripe_price_id(string $plan): string
{
    switch (normalize_plan_slug($plan)) {
        case 'pro':
            $pro = integration_config('STRIPE_PRICE_PRO');
            return $pro !== '' ? $pro : integration_config('STRIPE_PRICE_GROWTH');
        case 'agency':
        case 'enterprise':
            return integration_config('STRIPE_PRICE_AGENCY');
        default:
            return integration_config('STRIPE_PRICE_STARTER');
    }
}

/**
 * Execute a Stripe API request.
 *
 * @param string $method GET|POST|DELETE
 * @param string $endpoint e.g. /customers
 * @param array<string, mixed> $params
 * @return array<string, mixed>|null
 */
function stripe_request(string $method, string $endpoint, array $params = []): ?array
{
    $url = 'https://api.stripe.com/v1' . $endpoint;

    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . integration_config('STRIPE_SECRET_KEY')];

    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $url);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log('Stripe curl error');
        return null;
    }

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        error_log('Stripe API error: ' . ($data['error']['message'] ?? $response));
        return null;
    }

    return $data;
}

/**
 * Create or retrieve Stripe customer for a user.
 *
 * @param int $userId
 * @return string|null Customer ID
 */
function stripe_get_or_create_customer(int $userId): ?string
{
    $user = db_fetch('SELECT id, email, name, stripe_customer_id FROM users WHERE id = ?', 'i', [$userId]);
    if (!$user) {
        return null;
    }

    if (!empty($user['stripe_customer_id'])) {
        return $user['stripe_customer_id'];
    }

    $customer = stripe_request('POST', '/customers', [
        'email' => $user['email'],
        'name'  => $user['name'],
        'metadata[user_id]' => (string) $userId,
    ]);

    if (!$customer || empty($customer['id'])) {
        return null;
    }

    db_execute(
        'UPDATE users SET stripe_customer_id = ? WHERE id = ?',
        'si',
        [$customer['id'], $userId]
    );

    return $customer['id'];
}

/**
 * Create a Stripe Checkout Session for subscription.
 *
 * @param int $userId
 * @param string $plan starter|pro|growth|agency
 * @return array{success: bool, url?: string, message?: string}
 */
function stripe_create_checkout(int $userId, string $plan): array
{
    $customerId = stripe_get_or_create_customer($userId);
    if (!$customerId) {
        return ['success' => false, 'message' => 'Could not create billing account.'];
    }

    $priceId = stripe_price_id($plan);
    $user = db_fetch('SELECT trial_ends_at, subscription_status FROM users WHERE id = ?', 'i', [$userId]);

    $params = [
        'customer'             => $customerId,
        'mode'                 => 'subscription',
        'line_items[0][price]' => $priceId,
        'line_items[0][quantity]' => 1,
        'success_url'          => APP_URL . '/client/dashboard?success=1',
        'cancel_url'           => APP_URL . '/client/billing?canceled=1',
        'metadata[user_id]'    => (string) $userId,
        'metadata[plan]'       => $plan,
        'metadata[currency]'   => 'USD',
        'subscription_data[metadata][user_id]' => (string) $userId,
        'subscription_data[metadata][plan]'  => $plan,
    ];

    if ($user && $user['subscription_status'] === 'trialing' && !empty($user['trial_ends_at'])) {
        $trialEnd = strtotime($user['trial_ends_at']);
        if ($trialEnd > time()) {
            $params['subscription_data[trial_end]'] = (string) $trialEnd;
        }
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);

    if (!$session || empty($session['url'])) {
        return ['success' => false, 'message' => 'Could not start checkout. Check Stripe configuration.'];
    }

    db_execute(
        'UPDATE users SET subscription_plan = ? WHERE id = ?',
        'si',
        [$plan, $userId]
    );

    return ['success' => true, 'url' => $session['url']];
}

/**
 * Cancel a Stripe subscription at period end.
 *
 * @param string $subscriptionId
 * @return bool
 */
function stripe_cancel_subscription(string $subscriptionId): bool
{
    $result = stripe_request('POST', '/subscriptions/' . $subscriptionId, [
        'cancel_at_period_end' => 'true',
    ]);
    return $result !== null;
}

/**
 * Verify Stripe webhook signature.
 *
 * @param string $payload Raw body
 * @param string|null $sigHeader Stripe-Signature header
 * @return array<string, mixed>|null Event data
 */
function stripe_verify_webhook(string $payload, ?string $sigHeader): ?array
{
    if (!$sigHeader) {
        return null;
    }

    $parts = [];
    foreach (explode(',', $sigHeader) as $item) {
        [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
        $parts[$k] = $v;
    }

    $timestamp = $parts['t'] ?? '';
    $signature = $parts['v1'] ?? '';

    if (!$timestamp || !$signature) {
        return null;
    }

    if (abs(time() - (int) $timestamp) > 300) {
        return null;
    }

    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, integration_config('STRIPE_WEBHOOK_SECRET'));

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $event = json_decode($payload, true);
    return is_array($event) ? $event : null;
}
