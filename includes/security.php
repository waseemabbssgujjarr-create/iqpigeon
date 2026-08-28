<?php
/**
 * Application security layer — headers, rate limits, access gates, audit log.
 * Adapted for vanilla PHP + MySQL (cPanel). No Laravel/Redis required.
 */

declare(strict_types=1);

if (defined('SECURITY_BOOTSTRAPPED')) {
    return;
}
define('SECURITY_BOOTSTRAPPED', true);

/** @var bool|null */
$GLOBALS['_security_headers_sent'] = null;

/**
 * Run on every web request (via config.php).
 */
function security_bootstrap(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    require_once __DIR__ . '/ai-crawler-block.php';
    ai_crawler_block_request();

    security_guard_dangerous_scripts();
    security_send_headers();
    security_scan_request_uri();
    security_start_output_sanitize();
}

require_once __DIR__ . '/security-output.php';

/**
 * Block one-time / dev scripts unless CRON_SECRET, ADMIN_ACCESS_KEY, or admin session.
 */
function security_guard_dangerous_scripts(): void
{
    if (!security_enabled()) {
        return;
    }

    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === '') {
        return;
    }

    $alwaysBlocked = [
        'ensure-test-users.php',
        'setup.php',
        'sync-calendly.php',
    ];

    $apiGated = [
        'chat-health.php',
        'mail-diagnose.php',
        'mail-version.php',
        'debug-config-leak.php',
        'clear-opcache.php',
    ];

    if (in_array($script, $alwaysBlocked, true)) {
        if (!security_dev_scripts_allowed()) {
            security_deny(403, 'This script is disabled in production.');
        }
        return;
    }

    if (in_array($script, $apiGated, true)) {
        if (!security_has_privileged_key()) {
            security_deny(403, 'Forbidden.');
        }
    }
}

function security_enabled(): bool
{
    return !defined('SECURITY_ENABLED') || SECURITY_ENABLED;
}

function security_dev_scripts_allowed(): bool
{
    if (defined('ALLOW_DEV_SCRIPTS') && ALLOW_DEV_SCRIPTS) {
        return security_has_privileged_key();
    }

    if (defined('APP_DEBUG') && APP_DEBUG) {
        return security_has_privileged_key();
    }

    return false;
}

function security_has_privileged_key(): bool
{
    $key = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    if ($key === '') {
        return false;
    }

    if (defined('CRON_SECRET') && CRON_SECRET !== '' && hash_equals(CRON_SECRET, $key)) {
        return true;
    }

    if (function_exists('admin_access_key_valid') && admin_access_key_valid($key)) {
        return true;
    }

    return false;
}

/**
 * Real admin URL key check. ADMIN_ACCESS_KEY_DECOY (in config.php) never grants access.
 */
function admin_access_key_valid(string $key): bool
{
    if (!defined('ADMIN_ACCESS_KEY') || ADMIN_ACCESS_KEY === '') {
        return true;
    }

    if ($key === '') {
        return false;
    }

    if (hash_equals(ADMIN_ACCESS_KEY, $key)) {
        return true;
    }

    if (defined('ADMIN_ACCESS_KEY_DECOY') && ADMIN_ACCESS_KEY_DECOY !== ''
        && hash_equals(ADMIN_ACCESS_KEY_DECOY, $key)
    ) {
        security_audit('admin_decoy_key_used', ['ip' => security_client_ip()]);
    }

    return false;
}

/** Gate one-time migration scripts — call after config.php is loaded. */
function security_require_privileged_key(): void
{
    if (security_has_privileged_key()) {
        return;
    }

    security_deny(403, 'Forbidden. Use ?key= from CRON_SECRET in config.');
}

function security_deny(int $code, string $message): never
{
    http_response_code($code);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/api/')
    ) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . (int) $code . '</title></head><body>';
    echo '<h1>' . (int) $code . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

function security_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $raw) {
        if ($raw === '') {
            continue;
        }
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '0.0.0.0';
}

function security_storage_dir(string $subdir): string
{
    $base = dirname(__DIR__) . '/storage/security/' . trim($subdir, '/');
    if (!is_dir($base)) {
        @mkdir($base, 0750, true);
    }

    return $base;
}

/**
 * Returns true when the request is allowed, false when rate limit exceeded.
 */
function security_rate_limit(string $bucket, int $maxAttempts, int $windowSeconds): bool
{
    if (!security_enabled() || (defined('SECURITY_RATE_LIMIT_ENABLED') && !SECURITY_RATE_LIMIT_ENABLED)) {
        return true;
    }

    $ip = security_client_ip();
    $hash = hash('sha256', $bucket . '|' . $ip);
    $file = security_storage_dir('ratelimit') . '/' . $hash . '.json';
    $now = time();
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return true;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return true;
        }

        $raw = stream_get_contents($fp);
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($now >= (int) ($data['reset'] ?? 0)) {
            $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $data['count'] = (int) ($data['count'] ?? 0) + 1;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);

        if ($data['count'] > $maxAttempts) {
            security_audit('rate_limit_exceeded', ['bucket' => $bucket, 'ip' => $ip, 'count' => $data['count']]);
            return false;
        }

        return true;
    } finally {
        fclose($fp);
    }
}

function security_rate_limit_or_abort(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    if (security_rate_limit($bucket, $maxAttempts, $windowSeconds)) {
        return;
    }

    http_response_code(429);
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json; charset=UTF-8');
    header('Retry-After: ' . (string) max(1, $windowSeconds));
    echo json_encode([
        'success' => false,
        'error' => 'Too many requests. Please try again shortly.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function security_send_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    }

    if (function_exists('ai_crawler_block_enabled') && ai_crawler_block_enabled()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageai');
    }

    // CSP must allow Tailwind CDN (site-wide styling). Disabled via SECURITY_CSP_ENABLED=false if needed.
    if (!defined('SECURITY_CSP_ENABLED') || SECURITY_CSP_ENABLED) {
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://connect.facebook.net https://js.stripe.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; "
            . "img-src 'self' data: https: blob:; "
            . "font-src 'self' https://fonts.gstatic.com data:; "
            . "connect-src 'self' https://cdn.tailwindcss.com https://api.openai.com https://graph.facebook.com https://connect.facebook.net https://www.facebook.com https://web.facebook.com https://business.facebook.com https://ip-api.com https://generativelanguage.googleapis.com; "
            . "frame-src https://js.stripe.com https://hooks.stripe.com https://www.facebook.com https://web.facebook.com https://business.facebook.com https://staticxx.facebook.com; "
            . "frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'";
        header('Content-Security-Policy: ' . $csp);
    }
}

function security_scan_request_uri(): void
{
    if (!security_enabled()) {
        return;
    }

    $uri = rawurldecode($_SERVER['REQUEST_URI'] ?? '');
    $patterns = [
        '/\.\.\//',
        '/\.\.\\\\/',
        '/\/etc\/passwd/i',
        '/<script\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $uri)) {
            security_audit('blocked_uri_pattern', ['uri' => $uri, 'pattern' => $pattern]);
            security_deny(403, 'Forbidden.');
        }
    }
}

/**
 * CSRF token from header (JSON APIs) or POST/body field.
 * Multipart uploads must use POST field first — some hosts strip or rewrite custom headers.
 */
function security_api_csrf_token(): ?string
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data') && !empty($_POST['csrf_token'])) {
        return trim((string) $_POST['csrf_token']);
    }

    $header = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($header !== '') {
        return $header;
    }

    if (!empty($_POST['csrf_token'])) {
        return trim((string) $_POST['csrf_token']);
    }

    $cached = security_cached_json_body();
    if (!empty($cached['csrf_token'])) {
        return trim((string) $cached['csrf_token']);
    }

    return null;
}

/**
 * Parse JSON body once (for CSRF + handler reuse).
 *
 * @return array<string, mixed>
 */
function security_cached_json_body(): array
{
    if (array_key_exists('_security_json_body', $GLOBALS)) {
        return $GLOBALS['_security_json_body'];
    }

    $GLOBALS['_security_json_body'] = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        return $GLOBALS['_security_json_body'];
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        return $GLOBALS['_security_json_body'];
    }

    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $GLOBALS['_security_json_body'];
    }

    $decoded = json_decode($raw, true);
    $GLOBALS['_security_json_body'] = is_array($decoded) ? $decoded : [];

    return $GLOBALS['_security_json_body'];
}

function security_require_api_csrf(): void
{
    if (!function_exists('verify_csrf')) {
        return;
    }

    if (!verify_csrf(security_api_csrf_token())) {
        security_audit('csrf_failed', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
        json_response(['success' => false, 'error' => 'Invalid or missing CSRF token.'], 403);
    }
}

function security_require_login_api_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        return;
    }

    security_require_api_csrf();
}

function security_audit(string $event, array $context = []): void
{
    if (defined('SECURITY_AUDIT_ENABLED') && !SECURITY_AUDIT_ENABLED) {
        return;
    }

    $line = json_encode([
        'time' => date('c'),
        'event' => $event,
        'ip' => security_client_ip(),
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'user_id' => $_SESSION['user_id'] ?? null,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE);

    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    @file_put_contents($dir . '/security.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Require shop / custom webhook shared secret (constant-time).
 */
function security_require_webhook_secret(string $provided, string $expected, string $provider = 'webhook'): void
{
    if ($expected === '') {
        security_audit('webhook_missing_secret', ['provider' => $provider]);
        json_response(['success' => false, 'error' => 'Webhook not configured securely.'], 503);
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        security_audit('webhook_auth_failed', ['provider' => $provider]);
        json_response(['success' => false, 'error' => 'Unauthorized'], 401);
    }
}
