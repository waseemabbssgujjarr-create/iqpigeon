<?php
/**
 * Site URL helpers — iqpigeon.com only.
 *
 * Load after config.php defines APP_URL.
 */

/**
 * Primary production URL (emails, OAuth callbacks, webhooks, absolute links).
 */
function app_canonical_url(): string
{
    if (defined('APP_URL') && trim((string) APP_URL) !== '') {
        return rtrim((string) APP_URL, '/');
    }

    return 'https://iqpigeon.com';
}

/**
 * Hostnames allowed to serve the app (lowercase, no port).
 *
 * @return list<string>
 */
function allowed_app_hosts(): array
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $hosts = [];
    $appHost = parse_url(app_canonical_url(), PHP_URL_HOST);
    if (is_string($appHost) && $appHost !== '') {
        $hosts[] = strtolower($appHost);
    }

    return $hosts = array_values(array_unique($hosts));
}

/**
 * Current request host (lowercase, strip port).
 */
function request_host(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = strtolower(trim(explode(':', $host, 2)[0]));

    return $host;
}

function is_allowed_host(?string $host = null): bool
{
    $host = strtolower(trim((string) ($host ?? request_host())));
    if ($host === '') {
        return false;
    }

    return in_array($host, allowed_app_hosts(), true);
}

/**
 * Base URL for the current HTTP request when host is allowed; otherwise APP_URL.
 */
function request_base_url(): string
{
    if (!is_allowed_host()) {
        return app_canonical_url();
    }

    $host = request_host();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    return ($https ? 'https' : 'http') . '://' . $host;
}

/**
 * Build absolute URL — uses request host when allowed, else APP_URL.
 */
function app_url(string $path = ''): string
{
    $base = request_base_url();
    if ($path === '') {
        return $base;
    }

    if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }

    if (function_exists('app_path')) {
        $path = app_path($path);
    } elseif (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return $base . $path;
}

/**
 * Webhook verify tokens accepted for Meta GET handshake.
 *
 * @return list<string>
 */
function meta_webhook_verify_tokens(): array
{
    $tokens = [];
    foreach (['WEBHOOK_VERIFY_TOKEN', 'WHATSAPP_VERIFY_TOKEN'] as $const) {
        if (defined($const)) {
            $value = trim((string) constant($const));
            if ($value !== '') {
                $tokens[] = $value;
            }
        }
    }

    return array_values(array_unique($tokens));
}

/**
 * Meta GET webhook handshake — accept any configured verify token.
 */
function meta_webhook_verify_ok(string $token): bool
{
    if ($token === '') {
        return false;
    }
    foreach (meta_webhook_verify_tokens() as $expected) {
        if (hash_equals($expected, $token)) {
            return true;
        }
    }

    return false;
}
