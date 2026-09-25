<?php
/**
 * Visitor currency — PKR for Pakistan IP, USD everywhere else.
 */

/**
 * Best-effort client IP (Cloudflare / proxy aware).
 */
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $candidate = trim(explode(',', (string) $_SERVER[$key])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return '127.0.0.1';
}

/**
 * ISO 3166-1 alpha-2 country code (e.g. PK, US) or null if unknown.
 */
function detect_country_code(): ?string
{
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $cf = strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY']));
        if ($cf !== '' && $cf !== 'XX' && $cf !== 'T1') {
            return $cf;
        }
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!empty($_SESSION['_geo_country']) && is_string($_SESSION['_geo_country'])) {
        return $_SESSION['_geo_country'];
    }

    $ip = client_ip();
    if (
        $ip === '127.0.0.1'
        || $ip === '::1'
        || str_starts_with($ip, '10.')
        || str_starts_with($ip, '192.168.')
        || str_starts_with($ip, '172.')
    ) {
        return null;
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode';
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!is_string($json) || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success' || empty($data['countryCode'])) {
        return null;
    }

    $code = strtoupper((string) $data['countryCode']);
    $_SESSION['_geo_country'] = $code;

    return $code;
}

/**
 * PKR when visitor is in Pakistan; USD for all other countries.
 */
function visitor_currency(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!empty($_SESSION['visitor_currency']) && in_array($_SESSION['visitor_currency'], ['PKR', 'USD'], true)) {
        return (string) $_SESSION['visitor_currency'];
    }

    $country = detect_country_code();
    $currency = ($country === 'PK') ? 'PKR' : 'USD';
    $_SESSION['visitor_currency'] = $currency;

    return $currency;
}

/** Default currency for new products, carts, etc. */
function default_currency(): string
{
    return visitor_currency();
}

function is_pakistan_visitor(): bool
{
    return visitor_currency() === 'PKR';
}

/**
 * @return array<string, int>
 */
function plan_pkr_prices(): array
{
    return [
        'starter' => defined('PLAN_PRICE_STARTER_PKR') ? (int) PLAN_PRICE_STARTER_PKR : 1440,
        'pro'     => defined('PLAN_PRICE_PRO_PKR') ? (int) PLAN_PRICE_PRO_PKR : 9000,
        'growth'  => defined('PLAN_PRICE_PRO_PKR') ? (int) PLAN_PRICE_PRO_PKR : 9000,
    ];
}

/**
 * Monthly plan amount in visitor currency.
 *
 * @param array<string, mixed> $plan
 */
function plan_is_annual(array $plan): bool
{
    return ($plan['billing_interval'] ?? 'month') === 'year';
}

/**
 * Annual savings vs paying the listed monthly rate × 12 (e.g. $20/mo → $240 vs $190/year ≈ 20.8% off).
 */
function plan_annual_discount_percent(array $plan): ?float
{
    if (!plan_is_annual($plan)) {
        return null;
    }
    $monthlyList = (int) ($plan['compare_monthly_usd'] ?? $plan['price'] ?? 0);
    $annual = (int) ($plan['price_usd'] ?? 0);
    if ($monthlyList <= 0 || $annual <= 0) {
        return null;
    }
    $fullYear = $monthlyList * 12;

    return round((($fullYear - $annual) / $fullYear) * 100, 1);
}

function plan_price_amount(array $plan, ?string $currency = null): ?int
{
    if (!empty($plan['contact_only'])) {
        return null;
    }

    $currency = strtoupper($currency ?? visitor_currency());

    if ($currency === 'PKR') {
        if (!empty($plan['stripe_only']) || plan_is_annual($plan)) {
            return null;
        }
        $slug = normalize_plan_slug((string) ($plan['slug'] ?? 'starter'));
        if (isset($plan['price_pkr']) && $plan['price_pkr'] !== null) {
            return (int) $plan['price_pkr'];
        }
        $pkr = plan_pkr_prices();
        return (int) ($pkr[$slug] ?? $pkr['starter']);
    }

    if (plan_is_annual($plan)) {
        return (int) ($plan['compare_monthly_usd'] ?? $plan['price'] ?? 0);
    }

    return (int) ($plan['price_usd'] ?? $plan['price'] ?? 0);
}

/**
 * Amount charged per billing period (annual total or monthly).
 */
function plan_billed_amount(array $plan, ?string $currency = null): ?int
{
    if (!empty($plan['contact_only'])) {
        return null;
    }
    $currency = strtoupper($currency ?? visitor_currency());
    if ($currency === 'PKR' && (plan_is_annual($plan) || !empty($plan['stripe_only']))) {
        return null;
    }
    if (plan_is_annual($plan)) {
        return (int) ($plan['price_usd'] ?? 0);
    }

    return plan_price_amount($plan, $currency);
}

function format_plan_price(?int $amount, ?string $currency = null): string
{
    if ($amount === null) {
        return 'Custom';
    }

    $currency = strtoupper($currency ?? visitor_currency());

    if ($currency === 'PKR') {
        return 'PKR ' . number_format($amount, 0);
    }

    return '$' . number_format($amount, 0);
}

/**
 * Plans with display_price + display_currency for the current visitor.
 *
 * @return array<string, array<string, mixed>>
 */
function localized_plans(): array
{
    $currency = visitor_currency();
    $plans = get_plans();
    $out = [];

    foreach ($plans as $slug => $plan) {
        if ($currency === 'PKR' && (plan_is_annual($plan) || !empty($plan['stripe_only']))) {
            continue;
        }
        $plan['slug'] = $slug;
        $plan['display_currency'] = $currency;
        $plan['display_price'] = plan_price_amount($plan, $currency);
        $plan['billed_amount'] = plan_billed_amount($plan, $currency);
        $plan['annual_discount_percent'] = plan_annual_discount_percent($plan);
        $out[$slug] = $plan;
    }

    return $out;
}

/**
 * Format money for catalog / orders (reuse catalog rules when available).
 */
function format_money(float $amount, ?string $currency = null): string
{
    $currency = strtoupper($currency ?? default_currency());

    if (function_exists('catalog_format_price')) {
        return catalog_format_price($amount, $currency);
    }

    if ($currency === 'PKR') {
        return 'PKR ' . number_format($amount, 0);
    }

    return '$' . number_format($amount, 2);
}
