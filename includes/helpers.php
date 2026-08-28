<?php
/**
 * Shared utility functions.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/currency.php';

/**
 * Escape HTML for safe output.
 *
 * @param string|null $str
 * @return string
 */
function sanitize(?string $str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

/**
 * Clean internal path — hide .php in the browser (API/oauth callbacks keep .php).
 */
function app_path(string $path): string
{
    if ($path === '' || $path === '/') {
        return '/';
    }
    if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }

    $hash = '';
    if (str_contains($path, '#')) {
        [$path, $hash] = explode('#', $path, 2);
        $hash = '#' . $hash;
    }

    $query = '';
    if (str_contains($path, '?')) {
        [$path, $query] = explode('?', $path, 2);
        $query = '?' . $query;
    }

    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    // Webhooks, OAuth callbacks, cron — keep .php (registered with Meta/Google/cPanel)
    // IQ Pigeon 2 is a subdirectory app; keep .php so Apache can find the real files.
    if (preg_match('#^/api/#', $path)
        || preg_match('#^/iqpigeon2(/|$)#i', $path)
        || preg_match('#^/client/whatsapp-oauth-callback\.php$#', $path)
        || preg_match('#^/client/whatsapp-oauth-callback$#', $path)) {
        // Always emit .php for Meta-registered WhatsApp OAuth callback
        if (str_ends_with($path, '/client/whatsapp-oauth-callback')) {
            $path .= '.php';
        }
        return $path . $query . $hash;
    }

    if (str_ends_with(strtolower($path), '.php')) {
        $path = substr($path, 0, -4);
    }
    if ($path === '/index' || $path === '') {
        $path = '/';
    }

    return $path . $query . $hash;
}

/**
 * IQ Pigeon 2 public path — root deploy (/login.php) or nested (/iqpigeon2/login.php).
 */
if (!function_exists('iqp2_public_path')) {
function iqp2_public_path(string $page): string
{
    $page = ltrim($page, '/');
    $root = dirname(__DIR__);
    if (is_file($root . '/' . $page) && is_file($root . '/includes/app.php')) {
        return '/' . $page;
    }
    if (is_file($root . '/iqpigeon2/' . $page)) {
        return '/iqpigeon2/' . $page;
    }

    return '/' . $page;
}
}

/**
 * True when request comes from the native Android wrapper (WebView User-Agent suffix).
 */
function is_native_app(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($ua, 'IQPigeonApp') !== false;
}

/** Phone or tablet browser — used for WhatsApp OAuth routing. */
function is_mobile_client(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    return (bool) preg_match(
        '/android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile|silk|fennec/i',
        $ua
    );
}

/**
 * Path to the Android APK served from the landing page.
 */
function android_apk_path(): string
{
    return __DIR__ . '/../downloads/sales-app.apk';
}

function android_apk_available(): bool
{
    return is_readable(android_apk_path());
}

function android_apk_url(): string
{
    return '/downloads/sales-app.apk';
}

/**
 * Live WhatsApp demo for marketing (wa.me link to test bot on real WhatsApp).
 */
function whatsapp_demo_url(): string
{
    if (defined('WHATSAPP_DEMO_URL') && WHATSAPP_DEMO_URL !== '') {
        return WHATSAPP_DEMO_URL;
    }
    if (function_exists('get_setting')) {
        $stored = get_setting('whatsapp_demo_url', '');
        return is_string($stored) ? trim($stored) : '';
    }
    return '';
}

function whatsapp_demo_available(): bool
{
    return whatsapp_demo_url() !== '';
}

function whatsapp_demo_href(?string $message = null): string
{
    $base = whatsapp_demo_url();
    if ($base === '') {
        return '';
    }

    $msg = $message;
    if ($msg === null && defined('WHATSAPP_DEMO_MESSAGE') && WHATSAPP_DEMO_MESSAGE !== '') {
        $msg = WHATSAPP_DEMO_MESSAGE;
    }
    if ($msg === null || $msg === '') {
        return $base;
    }

    $sep = str_contains($base, '?') ? '&' : '?';
    return $base . $sep . 'text=' . rawurlencode($msg);
}

/**
 * Calendly / booking URL for a bot (bot setting, then app default).
 *
 * @param array<string, mixed>|null $bot
 */
function get_bot_calendly_link(?array $bot = null): string
{
    $link = trim((string) ($bot['calendly_link'] ?? ''));
    if ($link !== '') {
        return $link;
    }
    if (defined('DEFAULT_CALENDLY_LINK') && DEFAULT_CALENDLY_LINK !== '') {
        return trim(DEFAULT_CALENDLY_LINK);
    }
    return '';
}

/**
 * Generate a cryptographically secure random token.
 *
 * @param int $length
 * @return string
 */
function generate_token(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/**
 * Parse a MySQL datetime for display.
 * DB session uses APP_TIMEZONE — naive "Y-m-d H:i:s" values are local app time, not UTC.
 */
function app_datetime(?string $datetime): ?DateTimeImmutable
{
    if (!$datetime) {
        return null;
    }

    $datetime = trim($datetime);
    if ($datetime === '') {
        return null;
    }

    $appTz = defined('APP_TIMEZONE') && APP_TIMEZONE !== ''
        ? APP_TIMEZONE
        : (date_default_timezone_get() ?: 'UTC');

    try {
        if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $datetime)) {
            return (new DateTimeImmutable($datetime))->setTimezone(new DateTimeZone($appTz));
        }

        return new DateTimeImmutable($datetime, new DateTimeZone($appTz));
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Format a datetime as a human-readable relative or absolute string.
 *
 * @param string|null $datetime
 * @return string
 */
function format_date(?string $datetime): string
{
    $dt = app_datetime($datetime);
    if (!$dt) {
        return '';
    }

    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $diff = $now->getTimestamp() - $dt->getTimestamp();

    if ($diff < 60) {
        return 'Just now';
    }
    $mins = (int) floor($diff / 60);
    if ($diff < 3600) {
        return $mins === 1 ? '1 minute ago' : $mins . ' minutes ago';
    }
    $hours = (int) floor($diff / 3600);
    if ($diff < 86400) {
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }
    $days = (int) floor($diff / 86400);
    if ($diff < 604800) {
        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }

    return $dt->format('M j, Y');
}

/**
 * ISO-8601 timestamp for client-side relative time updates.
 */
function format_date_iso(?string $datetime): string
{
    $dt = app_datetime($datetime);
    return $dt ? $dt->format('c') : '';
}

/**
 * SQL expression for a lead's most recent conversation activity.
 */
function lead_last_activity_sql(string $leadAlias = 'l'): string
{
    return "COALESCE(
        (SELECT MAX(c.created_at) FROM conversations c WHERE c.lead_id = {$leadAlias}.id),
        {$leadAlias}.updated_at,
        {$leadAlias}.created_at
    )";
}

/**
 * Bump lead sort order when a new message arrives.
 */
function touch_lead_activity(int $leadId): void
{
    if ($leadId <= 0) {
        return;
    }
    require_once __DIR__ . '/db.php';
    db_execute('UPDATE leads SET updated_at = NOW() WHERE id = ?', 'i', [$leadId]);
}

/**
 * Format datetime for display in conversation threads.
 *
 * @param string|null $datetime
 * @return string
 */
function format_time(?string $datetime): string
{
    $dt = app_datetime($datetime);
    return $dt ? $dt->format('g:i A') : '';
}

/**
 * ISO-8601 with timezone offset for browser-local formatting.
 */
function datetime_to_iso(?string $datetime): string
{
    $dt = app_datetime($datetime);
    return $dt ? $dt->format('c') : '';
}

/**
 * Calendar day label for grouping messages (Today / Yesterday / date).
 *
 * @param string|null $datetime
 */
function format_day_label(?string $datetime): string
{
    $dt = app_datetime($datetime);
    if (!$dt) {
        return '';
    }

    $tz = $dt->getTimezone();
    $now = new DateTimeImmutable('now', $tz);
    $msgDay = $dt->format('Y-m-d');
    $today = $now->format('Y-m-d');

    if ($msgDay === $today) {
        return 'Today';
    }
    if ($msgDay === $now->modify('-1 day')->format('Y-m-d')) {
        return 'Yesterday';
    }
    if ($dt->format('Y') === $now->format('Y')) {
        return $dt->format('M j');
    }

    return $dt->format('M j, Y');
}

/**
 * Single-letter avatar for leads (WhatsApp Lead → W).
 *
 * @param string $name
 */
function get_lead_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'L';
    }

    $first = preg_split('/\s+/u', $name, 2)[0] ?? $name;
    $letter = mb_strtoupper(mb_substr($first, 0, 1));

    return $letter !== '' ? $letter : 'L';
}

/**
 * Check if a database column exists (cached per request).
 */
function db_column_exists(string $table, string $column, bool $refresh = false): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!$refresh && array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    require_once __DIR__ . '/db.php';

    try {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            'sss',
            [DB_NAME, $table, $column]
        );
        $cache[$key] = ((int) ($row['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/**
 * Mark user email verified when column exists.
 */
function db_mark_email_verified(int $userId): void
{
    if (!db_column_exists('users', 'email_verified_at')) {
        return;
    }
    db_execute('UPDATE users SET email_verified_at = NOW() WHERE id = ?', 'i', [$userId]);
}

/**
 * Key material for token encryption (single source — avoids ENCRYPT_KEY / ENCRYPTION_KEY drift).
 *
 * @return list<string>
 */
function encryption_key_candidates(): array
{
    $out = [];
    foreach (['ENCRYPTION_KEY', 'ENCRYPT_KEY'] as $name) {
        if (!defined($name)) {
            continue;
        }
        $val = trim((string) constant($name));
        if ($val !== '') {
            $out[] = $val;
        }
    }

    return array_values(array_unique($out));
}

function encryption_key_material(): string
{
    return encryption_key_candidates()[0] ?? '';
}

/**
 * Encrypt sensitive data (WhatsApp/Instagram tokens).
 *
 * @param string $plaintext
 * @return string Base64-encoded ciphertext
 */
function encrypt_token(string $plaintext): string
{
    $material = encryption_key_material();
    if ($material === '') {
        throw new RuntimeException('ENCRYPTION_KEY is not configured — add it to config.local.php on the server.');
    }
    $key = hash('sha256', $material, true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new RuntimeException('openssl_encrypt failed — check OpenSSL on server.');
    }

    return base64_encode($iv . $encrypted);
}

/**
 * @return string|false
 */
function decrypt_token_with_material(string $ciphertext, string $material)
{
    $data = base64_decode($ciphertext, true);
    if ($data === false) {
        return false;
    }

    $material = trim($material);
    if ($material === '') {
        return false;
    }

    $key = hash('sha256', $material, true);

    // Primary format: base64(iv + raw ciphertext) — 16-byte IV prefix
    if (strlen($data) >= 17) {
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain !== false) {
            return $plain;
        }
    }

    // Legacy lib/Encryption.php format: base64(ciphertext:base64(iv))
    if (str_contains($data, ':')) {
        [$encrypted, $ivB64] = explode(':', $data, 2);
        $iv = base64_decode($ivB64, true);
        if ($iv !== false && strlen($iv) === 16) {
            $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
                return $plain;
            }
        }
    }

    return false;
}

/**
 * Decrypt sensitive data.
 *
 * @param string $ciphertext Base64-encoded
 * @return string|false
 */
function decrypt_token(string $ciphertext)
{
    foreach (encryption_key_candidates() as $material) {
        $plain = decrypt_token_with_material($ciphertext, $material);
        if (is_string($plain) && $plain !== '') {
            return $plain;
        }
    }

    return false;
}

/**
 * Normalize AI markdown for WhatsApp — keep single *bold*, drop redundant ** markers.
 */
function chat_strip_markdown_asterisks(string $text): string
{
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '*$1*', $text) ?? $text;

    return trim($text);
}

/**
 * Generate CSRF token and store in session.
 *
 * @return string
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token(32);
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST request.
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf(?string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get avatar initials from a name (max 2 chars).
 *
 * @param string $name
 * @return string
 */
function get_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper($part[0]);
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return substr($initials, 0, 2) ?: 'U';
}

/**
 * Redirect helper.
 *
 * @param string $url
 * @return never
 */
function redirect(string $url)
{
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = app_path($url);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Load per-bot Qualify helpers without taking down WhatsApp replies.
 * A missing or broken qualification-flow.php must never fatal the turn engine.
 */
function qualification_flow_load(): bool
{
    if (function_exists('qualification_prompt_block')) {
        return true;
    }
    $path = __DIR__ . '/qualification-flow.php';
    if (!is_readable($path)) {
        return false;
    }
    try {
        require_once $path;
    } catch (Throwable $e) {
        error_log('qualification_flow_load: ' . $e->getMessage());
        return false;
    }

    return function_exists('qualification_prompt_block');
}

/**
 * Ensure leads table has columns required by widget/demo training.
 */
function ensure_leads_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    require_once __DIR__ . '/db.php';

    $columns = [
        'qualification_data' => 'JSON NULL',
        'bot_paused_until'   => 'DATETIME NULL',
        'notes'              => 'TEXT NULL',
    ];

    foreach ($columns as $column => $definition) {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'leads\' AND COLUMN_NAME = ?',
            'ss',
            [DB_NAME, $column]
        );
        if ((int) ($row['cnt'] ?? 0) === 0) {
            try {
                db_connect()->query("ALTER TABLE leads ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                error_log('ensure_leads_schema add column ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    try {
        $platformCol = db_fetch(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'leads\' AND COLUMN_NAME = \'platform\'',
            's',
            [DB_NAME]
        );
        if ($platformCol && strpos((string) ($platformCol['COLUMN_TYPE'] ?? ''), 'widget') === false) {
            db_connect()->query(
                "ALTER TABLE leads MODIFY platform ENUM('whatsapp','instagram','widget') DEFAULT 'whatsapp'"
            );
        }
    } catch (Throwable $e) {
        error_log('ensure_leads_schema platform enum: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Ensure conversations table exists (widget / AI chat).
 */
function ensure_conversations_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    require_once __DIR__ . '/db.php';

    try {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'conversations\'',
            's',
            [DB_NAME]
        );
        if ((int) ($row['cnt'] ?? 0) === 0) {
            db_connect()->query(
                "CREATE TABLE IF NOT EXISTS conversations (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    lead_id     INT NOT NULL,
                    role        ENUM('user','assistant','system') NOT NULL,
                    message     TEXT NOT NULL,
                    media_type  VARCHAR(20) NULL,
                    media_url   VARCHAR(512) NULL,
                    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            $col = db_fetch(
                'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'conversations\' AND COLUMN_NAME = \'media_type\'',
                's',
                [DB_NAME]
            );
            if ((int) ($col['cnt'] ?? 0) === 0) {
                db_connect()->query(
                    'ALTER TABLE conversations
                     ADD COLUMN media_type VARCHAR(20) NULL AFTER message,
                     ADD COLUMN media_url VARCHAR(512) NULL AFTER media_type'
                );
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_conversations_schema: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Return JSON response and exit.
 *
 * @param array<string, mixed> $data
 * @param int $status
 * @return never
 */
function json_response(array $data, int $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Subscription plan definitions (display + limits).
 * Chat limits = unique client conversations per month (one lead/thread = 1 chat).
 *
 * @return array<string, array<string, mixed>>
 */
function get_plans(): array
{
    require_once __DIR__ . '/platform-settings.php';
    return get_plans_merged();
}

/**
 * Map plan slug to monthly price for MRR calculations.
 *
 * @return array<string, int>
 */
function get_plan_prices(): array
{
    $prices = [];
    foreach (get_plans() as $slug => $plan) {
        if (isset($plan['price']) && is_numeric($plan['price'])) {
            $prices[$slug] = (int) $plan['price'];
        }
    }
    $prices['growth'] = $prices['pro'] ?? 30;
    $prices['agency'] = 0;
    return $prices;
}

/**
 * Normalize legacy plan slugs to current tiers.
 */
function normalize_plan_slug(string $plan): string
{
    return match ($plan) {
        'growth' => 'pro',
        default  => $plan,
    };
}

/**
 * Get plan limits for feature gating.
 *
 * @return array{bots: int, chats: int, leads: int}
 */
function get_plan_limits(string $plan): array
{
    $plan = normalize_plan_slug($plan);

    $limits = [
        'starter'    => ['bots' => 1, 'chats' => 100, 'leads' => PHP_INT_MAX],
        'pro'        => ['bots' => 1, 'chats' => 500, 'leads' => PHP_INT_MAX],
        'enterprise' => ['bots' => PHP_INT_MAX, 'chats' => PHP_INT_MAX, 'leads' => PHP_INT_MAX],
        'agency'     => ['bots' => PHP_INT_MAX, 'chats' => PHP_INT_MAX, 'leads' => PHP_INT_MAX],
    ];

    return $limits[$plan] ?? $limits['starter'];
}

/**
 * Check if user can add another bot.
 *
 * @param int $user_id
 * @return bool
 */
function can_add_bot(int $user_id): bool
{
    require_once __DIR__ . '/db.php';

    $user = db_fetch('SELECT subscription_plan FROM users WHERE id = ?', 'i', [$user_id]);
    if (!$user) {
        return false;
    }

    $limits = get_plan_limits($user['subscription_plan']);
    $count = db_fetch('SELECT COUNT(*) AS cnt FROM bots WHERE user_id = ?', 'i', [$user_id]);

    return (int) ($count['cnt'] ?? 0) < $limits['bots'];
}

/**
 * Render a lead status badge.
 *
 * @param string $status new|in_progress|qualified|disqualified|booked
 * @return string HTML
 */
function status_badge(string $status): string
{
    $classes = [
        'qualified'    => 'bg-primary-container text-on-primary-container',
        'in_progress'  => 'bg-secondary-container text-on-secondary-container',
        'booked'       => 'bg-surface-container-highest text-on-surface-variant',
        'disqualified' => 'bg-error-container text-on-error-container',
        'new'          => 'bg-tertiary-container text-on-tertiary-container',
    ];

    $class = $classes[$status] ?? $classes['new'];
    $label = ucwords(str_replace('_', ' ', $status));

    return '<span class="' . $class . ' px-sm py-0.5 rounded text-[10px] font-bold uppercase tracking-wider font-label whitespace-nowrap shrink-0 inline-block">'
        . sanitize($label) . '</span>';
}

/**
 * Get a global setting value.
 *
 * @param string $key
 * @param string|null $default
 * @return string|null
 */
function get_setting(string $key, ?string $default = null): ?string
{
    try {
        require_once __DIR__ . '/db.php';
        $row = db_fetch('SELECT value FROM settings WHERE key_name = ?', 's', [$key]);
        return $row['value'] ?? $default;
    } catch (Throwable $e) {
        error_log('get_setting(' . $key . ') failed: ' . $e->getMessage());
        return $default;
    }
}

/**
 * @see set_setting() in platform-settings.php
 */

/**
 * Platform icon name for Material Symbols.
 *
 * @param string $platform
 * @return string
 */
function platform_icon(string $platform): string
{
    switch ($platform) {
        case 'whatsapp':
            return 'chat';
        case 'instagram':
            return 'photo_camera';
        case 'widget':
            return 'language';
        default:
            return 'forum';
    }
}

/**
 * Check if AI bot is paused for a lead (human takeover).
 *
 * @param array<string, mixed> $lead
 * @return bool
 */
function is_lead_bot_paused(array $lead): bool
{
    if (empty($lead['bot_paused_until'])) {
        return false;
    }
    return strtotime($lead['bot_paused_until']) > time();
}

/**
 * Count unique client conversations this calendar month.
 * A conversation = one lead/thread with any WhatsApp activity (customer or bot) this month.
 */
function count_monthly_chats(int $userId): int
{
    require_once __DIR__ . '/db.php';
    $row = db_fetch(
        'SELECT COUNT(DISTINCT l.id) AS cnt
         FROM leads l
         JOIN bots b ON b.id = l.bot_id
         WHERE b.user_id = ?
           AND EXISTS (
             SELECT 1 FROM conversations c
             WHERE c.lead_id = l.id
               AND c.created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')
           )',
        'i',
        [$userId]
    );
    return (int) ($row['cnt'] ?? 0);
}

/**
 * Whether this lead already counts toward this month's chat quota.
 */
function lead_has_monthly_ai_chat(int $leadId, int $userId): bool
{
    if ($leadId <= 0) {
        return false;
    }

    require_once __DIR__ . '/db.php';
    $row = db_fetch(
        'SELECT 1 AS ok FROM conversations c
         JOIN leads l ON l.id = c.lead_id
         JOIN bots b ON b.id = l.bot_id
         WHERE b.user_id = ? AND l.id = ?
           AND c.created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')
         LIMIT 1',
        'ii',
        [$userId, $leadId]
    );

    return $row !== null;
}

/** @deprecated Use count_monthly_chats() */
function count_monthly_messages(int $userId): int
{
    return count_monthly_chats($userId);
}

/**
 * Check if client is within monthly conversation quota.
 * New threads count once; existing threads can keep replying when at limit.
 */
function within_chat_limit(int $userId, ?int $leadId = null): bool
{
    require_once __DIR__ . '/db.php';
    $user = db_fetch('SELECT subscription_plan FROM users WHERE id = ?', 'i', [$userId]);
    if (!$user) {
        return false;
    }
    $limits = get_plan_limits($user['subscription_plan']);
    if ($limits['chats'] === PHP_INT_MAX) {
        return true;
    }
    if (count_monthly_chats($userId) < $limits['chats']) {
        return true;
    }
    return $leadId !== null && lead_has_monthly_ai_chat($leadId, $userId);
}

/** @deprecated Use within_chat_limit() */
function within_message_limit(int $userId): bool
{
    return within_chat_limit($userId);
}

/**
 * Count leads created this calendar month for a client.
 *
 * @param int $userId
 * @return int
 */
function count_monthly_leads(int $userId): int
{
    require_once __DIR__ . '/db.php';
    $row = db_fetch(
        'SELECT COUNT(l.id) AS cnt FROM leads l
         JOIN bots b ON b.id = l.bot_id
         WHERE b.user_id = ?
           AND l.created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')',
        'i',
        [$userId]
    );
    return (int) ($row['cnt'] ?? 0);
}

/**
 * Monthly chat quota usage for dashboard / billing UI.
 *
 * @return array{used: int, limit: int, pct: float, unlimited: bool}
 */
function client_chat_usage_stats(int $userId, string $plan): array
{
    $limits = get_plan_limits($plan);
    $limit = (int) ($limits['chats'] ?? 100);
    $used = count_monthly_chats($userId);

    if ($limit === PHP_INT_MAX || $limit <= 0) {
        return [
            'used'      => $used,
            'limit'     => $limit,
            'pct'       => 0.0,
            'unlimited' => true,
        ];
    }

    return [
        'used'      => $used,
        'limit'     => $limit,
        'pct'       => min(100.0, round(($used / $limit) * 100, 1)),
        'unlimited' => false,
    ];
}

/**
 * @deprecated Leads are unlimited — use within_chat_limit() for AI replies.
 */
function within_lead_limit(int $userId): bool
{
    return true;
}

/**
 * Pause AI responses for a lead (human takeover).
 *
 * @param int $leadId
 * @param int $minutes
 * @return void
 */
function pause_lead_bot(int $leadId, int $minutes = 60): void
{
    require_once __DIR__ . '/db.php';
    $until = date('Y-m-d H:i:s', time() + $minutes * 60);
    db_execute('UPDATE leads SET bot_paused_until = ? WHERE id = ?', 'si', [$until, $leadId]);
}

/**
 * Resume AI responses for a lead.
 *
 * @param int $leadId
 * @return void
 */
function resume_lead_bot(int $leadId): void
{
    require_once __DIR__ . '/db.php';
    db_execute('UPDATE leads SET bot_paused_until = NULL WHERE id = ?', 'i', [$leadId]);
}

function theme_head_init(): string
{
    $favLight = json_encode(brand_favicon_url(false));
    $favDark = json_encode(brand_favicon_url(true));

    return <<<HTML
<script>
(function(){
    var k='iqpigeon_theme',s=localStorage.getItem(k)||'system',d=document.documentElement;
    var dark=s==='dark'||(s==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);
    if(dark){d.classList.add('dark');d.dataset.theme='dark';}else{d.dataset.theme='light';}
    d.dataset.themePreference=s;
    var fav=document.getElementById('site-favicon');
    if(fav){fav.href=dark?{$favDark}:{$favLight};}
})();
</script>
HTML;
}

function brand_img(string $filename): string
{
    $file = ltrim($filename, '/');
    foreach (['assets/img/', 'assets/images/'] as $dir) {
        $path = __DIR__ . '/../' . $dir . $file;
        if (is_file($path)) {
            return '/' . $dir . $file;
        }
    }

    return '/assets/img/' . $file;
}

function brand_asset_url(string $filename): string
{
    $file = ltrim($filename, '/');
    $path = null;
    foreach (['assets/img/', 'assets/images/'] as $dir) {
        $candidate = __DIR__ . '/../' . $dir . $file;
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }
    $url = brand_img($file);
    if ($path !== null) {
        $url .= '?v=' . filemtime($path);
    }

    return $url;
}

/** Favicon for light UI chrome (tabs, bookmarks). */
function brand_favicon_url(bool $darkUi = false): string
{
    return brand_asset_url($darkUi ? 'Fav-Icon-on-black-bg.png' : 'Fav-Icon-on-white-bg.png');
}

/**
 * Theme-aware logo PNGs:
 * - site-logo-on-white-bg.png → use on light/white surfaces
 * - site-logo-on-dark-bg.png  → use on dark surfaces (sidebar, inverse login)
 *
 * @param string $class   CSS classes for the img element
 * @param string $context auto|light|dark — auto outputs both with CSS toggle
 */
function brand_logo_markup(string $class = 'brand-logo-img', string $context = 'auto'): string
{
    $onLight = brand_asset_url('site-logo-on-white-bg.png');
    $onDark = brand_asset_url('site-logo-on-dark-bg.png');
    $classAttr = htmlspecialchars(trim($class . ' brand-logo'), ENT_QUOTES, 'UTF-8');
    $alt = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');

    if ($context === 'dark') {
        return '<img src="' . htmlspecialchars($onDark, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="' . $classAttr . '"/>';
    }
    if ($context === 'light') {
        return '<img src="' . htmlspecialchars($onLight, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="' . $classAttr . '"/>';
    }

    return '<img src="' . htmlspecialchars($onLight, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="brand-logo brand-logo--light-bg ' . $classAttr . '"/>'
        . '<img src="' . htmlspecialchars($onDark, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="brand-logo brand-logo--dark-bg ' . $classAttr . '"/>';
}

/**
 * Render shared page head assets (Tailwind, fonts, meta).
 *
 * @param string $title
 * @return string HTML
 */
function page_head(string $title): string
{
    $appName = sanitize(APP_NAME);
    $safeTitle = sanitize($title);
    $cssVer = @filemtime(__DIR__ . '/../assets/css/app.css') ?: time();
    $polishVer = @filemtime(__DIR__ . '/../assets/css/design-polish.css') ?: time();
    $v2Ver = @filemtime(__DIR__ . '/../assets/css/design-v2.css') ?: time();
    $fontsVer = @filemtime(__DIR__ . '/../assets/css/fonts.css') ?: time();
    $themeVer = @filemtime(__DIR__ . '/../assets/css/theme.css') ?: time();
    $themeJsVer = @filemtime(__DIR__ . '/../assets/js/theme.js') ?: time();
    $iqpUserVer = @filemtime(__DIR__ . '/../assets/css/iqp-user.css') ?: time();
    $iqpAdminVer = @filemtime(__DIR__ . '/../assets/css/iqp-admin.css') ?: time();
    $iqpBridgeVer = @filemtime(__DIR__ . '/../assets/css/iqp-bridge.css') ?: time();
    $iqpJsVer = @filemtime(__DIR__ . '/../assets/js/iqp-ui.js') ?: time();
    $themeInit = theme_head_init();
    $faviconLight = brand_favicon_url(false);
    $appleTouch = brand_asset_url('Fav-Icon-on-white-bg.png');
    $robotsMeta = '';
    if (function_exists('ai_crawler_block_enabled') && ai_crawler_block_enabled()) {
        $robotsMeta = <<<'HTML'
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet"/>
<meta name="googlebot" content="noindex, nofollow"/>

HTML;
    }

    return <<<HTML
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, interactive-widget=resizes-content"/>
{$robotsMeta}<meta name="theme-color" content="#4aad36"/>
<meta name="color-scheme" content="light dark"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-status-bar-style" content="default"/>
<meta name="apple-mobile-web-app-title" content="{$appName}"/>
<link rel="manifest" href="/manifest.json"/>
<link rel="icon" href="{$faviconLight}" type="image/png" id="site-favicon"/>
<link rel="apple-touch-icon" href="{$appleTouch}"/>
<title>{$safeTitle} — {$appName}</title>
{$themeInit}
<link href="/assets/css/theme.css?v={$themeVer}" rel="stylesheet"/>
<link href="/assets/css/fonts.css?v={$fontsVer}" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<link href="/assets/css/app.css?v={$cssVer}" rel="stylesheet"/>
<link href="/assets/css/design-polish.css?v={$polishVer}" rel="stylesheet"/>
<link href="/assets/css/design-v2.css?v={$v2Ver}" rel="stylesheet"/>
<script>document.documentElement.classList.add('app-shell-root');</script>
<script src="/assets/js/theme.js?v={$themeJsVer}"></script>
<script>
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            colors: {
                "primary": "#4aad36",
                "primary-container": "#d4edce",
                "primary-fixed": "#6bc956",
                "primary-fixed-dim": "#3d9430",
                "on-primary": "#ffffff",
                "on-primary-container": "#1a4d12",
                "secondary": "#3d9430",
                "secondary-container": "#e8f5e4",
                "secondary-fixed": "#6bc956",
                "secondary-fixed-dim": "#4aad36",
                "on-secondary": "#ffffff",
                "on-secondary-container": "#1a4d12",
                "tertiary": "#6bc956",
                "tertiary-container": "#ecf7ea",
                "tertiary-fixed-dim": "#4aad36",
                "on-tertiary": "#ffffff",
                "on-tertiary-container": "#2d7a20",
                "background": "var(--color-background)",
                "surface": "var(--color-surface)",
                "surface-bright": "var(--color-surface-bright)",
                "surface-container-lowest": "var(--color-surface-container-lowest)",
                "surface-container-low": "var(--color-surface-container-low)",
                "surface-container": "var(--color-surface-container)",
                "surface-container-high": "var(--color-surface-container-high)",
                "surface-container-highest": "var(--color-surface-container-highest)",
                "surface-dim": "var(--color-surface-dim)",
                "surface-variant": "var(--color-surface-variant)",
                "on-surface": "var(--color-on-surface)",
                "on-surface-variant": "var(--color-on-surface-variant)",
                "on-background": "var(--color-on-background)",
                "outline": "var(--color-outline)",
                "outline-variant": "var(--color-outline-variant)",
                "error": "#ba1a1a",
                "error-container": "var(--color-error-container)",
                "on-error": "#ffffff",
                "on-error-container": "var(--color-on-error-container)",
                "inverse-surface": "var(--color-inverse-surface)",
                "inverse-on-surface": "var(--color-inverse-on-surface)",
                "inverse-primary": "#6bc956"
            },
            borderRadius: {
                DEFAULT: "0.25rem",
                lg: "0.5rem",
                xl: "0.75rem",
                "2xl": "1rem",
                full: "9999px"
            },
            spacing: {
                xs: "4px", sm: "8px", base: "8px", gutter: "12px",
                md: "16px", "edge-margin": "16px", lg: "24px", xl: "32px"
            },
            fontFamily: {
                "sans": ["Google Sans Flex", "Inter", "system-ui", "sans-serif"],
                "display": ["Google Sans Flex", "Inter", "system-ui", "sans-serif"],
                "headline": ["Google Sans Flex", "Inter", "system-ui", "sans-serif"],
                "title": ["Google Sans Flex", "Inter", "system-ui", "sans-serif"],
                "body": ["Google Sans Flex", "Inter", "system-ui", "sans-serif"],
                "label": ["Google Sans Flex", "Inter", "system-ui", "sans-serif"]
            },
            fontSize: {
                "display-lg":   ["57px", { lineHeight: "64px", letterSpacing: "-0.025em", fontWeight: "800" }],
                "headline-lg":  ["32px", { lineHeight: "40px", letterSpacing: "-0.02em", fontWeight: "700" }],
                "headline-mob": ["22px", { lineHeight: "28px", letterSpacing: "-0.02em", fontWeight: "700" }],
                "title-md":     ["15px", { lineHeight: "22px", letterSpacing: "0.01em", fontWeight: "600" }],
                "body-lg":      ["15px", { lineHeight: "22px", letterSpacing: "0.01em", fontWeight: "400" }],
                "body-md":      ["14px", { lineHeight: "20px", letterSpacing: "0.01em", fontWeight: "400" }],
                "label-sm":     ["11px", { lineHeight: "14px", letterSpacing: "0.04em", fontWeight: "600" }]
            }
        }
    }
};
</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="/assets/css/iqp-user.css?v={$iqpUserVer}" rel="stylesheet"/>
<link href="/assets/css/iqp-admin.css?v={$iqpAdminVer}" rel="stylesheet"/>
<link href="/assets/css/iqp-bridge.css?v={$iqpBridgeVer}" rel="stylesheet"/>
<script src="/assets/js/iqp-ui.js?v={$iqpJsVer}" defer></script>
HTML;
}

/**
 * Configured public demo bot ID (settings table overrides config.php).
 */
function get_configured_demo_bot_id(): int
{
    $stored = get_setting('demo_bot_id');
    if ($stored !== null && $stored !== '') {
        return (int) $stored;
    }
    return (int) (defined('DEMO_BOT_ID') ? DEMO_BOT_ID : 0);
}

/**
 * Why the public demo bot is unavailable (empty string if OK).
 */
function get_demo_bot_unavailable_reason(): string
{
    require_once __DIR__ . '/db.php';

    $configuredId = get_configured_demo_bot_id();

    if ($configuredId > 0) {
        $bot = db_fetch(
            'SELECT id, name, is_active, widget_enabled FROM bots WHERE id = ?',
            'i',
            [$configuredId]
        );
        if (!$bot) {
            return 'Demo bot #' . $configuredId . ' was deleted. In Admin → All Bots, open a bot and click “Set as public demo”.';
        }
        if (!(int) $bot['is_active']) {
            return 'Demo bot “' . ($bot['name'] ?? '#' . $configuredId) . '” is inactive. Activate it in Admin → Bot detail.';
        }
        if (!(int) $bot['widget_enabled']) {
            return 'Demo bot “' . ($bot['name'] ?? '#' . $configuredId) . '” needs the website widget enabled (Admin → Bot detail → Set as public demo).';
        }
    }

    $widgetBot = db_fetch(
        'SELECT COUNT(*) AS cnt FROM bots WHERE is_active = 1 AND widget_enabled = 1',
        '',
        []
    );
    if ((int) ($widgetBot['cnt'] ?? 0) > 0) {
        return '';
    }

    $anyActive = db_fetch('SELECT COUNT(*) AS cnt FROM bots WHERE is_active = 1', '', []);
    if ((int) ($anyActive['cnt'] ?? 0) === 0) {
        return 'No active bots exist. Create a bot and enable the website widget, or set it as the public demo in Admin.';
    }

    return 'No bot has the website widget enabled. In Admin → Bot detail, click “Set as public demo” on any active bot.';
}

/**
 * Re-enable widget/active flag for the configured demo bot (safe auto-repair).
 */
function repair_configured_demo_bot(): void
{
    require_once __DIR__ . '/db.php';

    $configuredId = get_configured_demo_bot_id();
    if ($configuredId <= 0) {
        return;
    }

    $bot = db_fetch('SELECT id, is_active, widget_enabled FROM bots WHERE id = ?', 'i', [$configuredId]);
    if (!$bot) {
        return;
    }

    if (!(int) $bot['is_active'] || !(int) $bot['widget_enabled']) {
        db_execute(
            'UPDATE bots SET is_active = 1, widget_enabled = 1 WHERE id = ?',
            'i',
            [$configuredId]
        );
    }
}

/**
 * Get the public marketing/demo bot (widget-enabled, active).
 *
 * @return array<string, mixed>|null
 */
function get_demo_bot(): ?array
{
    require_once __DIR__ . '/db.php';

    repair_configured_demo_bot();

    $configuredId = get_configured_demo_bot_id();

    if ($configuredId > 0) {
        $bot = db_fetch(
            'SELECT b.id, b.name, b.persona_description, b.widget_color, b.widget_enabled, b.is_active, u.company_name
             FROM bots b
             JOIN users u ON u.id = b.user_id
             WHERE b.id = ? AND b.widget_enabled = 1 AND b.is_active = 1',
            'i',
            [$configuredId]
        );
        if ($bot) {
            return $bot;
        }
    }

    return db_fetch(
        'SELECT b.id, b.name, b.persona_description, b.widget_color, b.widget_enabled, b.is_active, u.company_name
         FROM bots b
         JOIN users u ON u.id = b.user_id
         WHERE b.widget_enabled = 1 AND b.is_active = 1
         ORDER BY b.id ASC
         LIMIT 1'
    );
}

/**
 * Sales rep first name from persona (e.g. "You are Sareen, ...") or bot name fallback.
 *
 * @param array<string, mixed> $bot
 */
function get_bot_rep_name(array $bot): string
{
    $stored = trim((string) ($bot['rep_name'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }

    $persona = trim(preg_replace('/ Tone: .+$/', '', $bot['persona_description'] ?? ''));

    $patterns = [
        '/\bYou(?:\'re| are)\s+([A-Z][a-zA-Z\-]+(?:\s+[A-Z][a-zA-Z\-]+)?)/u',
        '/\bI(?:\'m| am)\s+([A-Z][a-zA-Z\-]+)/u',
        '/\b(?:My name is|Call me|This is)\s+([A-Z][a-zA-Z\-]+)/iu',
    ];

    $name = '';
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $persona, $matches)) {
            $name = trim($matches[1]);
            break;
        }
    }

    if ($name === '') {
        $name = 'Alex';
    }

    $configured = defined('WHATSAPP_DEMO_LABEL') ? trim(WHATSAPP_DEMO_LABEL) : '';
    if ($configured !== '' && strcasecmp($name, 'Nosheen') === 0) {
        return $configured;
    }

    $botId = (int) ($bot['id'] ?? 0);
    $demoId = get_configured_demo_bot_id();
    if ($botId > 0 && $demoId > 0 && $botId === $demoId && $configured !== '') {
        return $configured;
    }

    return $name;
}

/**
 * Customer-facing business name — prefer client company over platform app name.
 *
 * @param array<string, mixed> $bot
 */
function get_bot_brand_label(array $bot): string
{
    $company = trim((string) ($bot['company_name'] ?? ''));
    $name = trim((string) ($bot['name'] ?? ''));
    $appName = defined('APP_NAME') ? trim((string) APP_NAME) : '';

    if ($company !== '' && ($appName === '' || strcasecmp($company, $appName) !== 0)) {
        return $company;
    }
    if ($name !== '' && ($appName === '' || strcasecmp($name, $appName) !== 0)) {
        return $name;
    }
    if ($company !== '') {
        return $company;
    }
    if ($name !== '') {
        return $name;
    }

    return 'our store';
}

/**
 * @param array<string, mixed> $bot
 */
function conversation_bot_has_catalog(array $bot): bool
{
    $botId = (int) ($bot['id'] ?? 0);
    if ($botId <= 0) {
        return false;
    }

    require_once __DIR__ . '/catalog.php';

    return catalog_bot_has_products($botId);
}

/**
 * Normalize a widget hex color (#RGB or #RRGGBB). Returns fallback if invalid.
 */
function normalize_widget_color(string $color, string $fallback = '#4aad36'): string
{
    $color = trim($color);
    if ($color !== '' && $color[0] !== '#') {
        $color = '#' . $color;
    }

    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color, $m)) {
        return $fallback;
    }

    if (strlen($m[1]) === 3) {
        $c = $m[1];
        return '#' . strtolower($c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2]);
    }

    return '#' . strtolower($m[1]);
}

/**
 * Widget header label: rep name + business (matches embed fallback in bot-setup).
 *
 * @param array<string, mixed> $bot
 */
function get_widget_bot_name(array $bot): string
{
    $repName = trim((string) ($bot['rep_name'] ?? ''));
    $businessName = trim((string) ($bot['name'] ?? ''));

    if ($repName !== '') {
        return $repName . ' — ' . ($businessName !== '' ? $businessName : 'Support');
    }

    return $businessName !== '' ? $businessName : 'Chat with us';
}

/** True for short openers like Hi / Hello / Salam — not product or service questions. */
function message_is_simple_greeting(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match(
        '/^(hi|hello|hey|hiya|yo|salam|assalamu?|aoa|as\s*salam|good\s+(morning|afternoon|evening)|howdy|greetings)'
        . '([\s!.?,]+(hi|hello|hey|hiya|yo|salam|assalamu?|aoa))*[\s!.?,]*$/iu',
        $lower
    )) {
        return true;
    }

    if (preg_match('/^(hi|hello|hey)\s+(there|everyone|team|sir|madam|maam|bro|sis)[\s!.?,]*$/iu', $lower)) {
        return true;
    }

    $words = preg_split('/[\s!.?,]+/u', $lower) ?: [];
    $words = array_values(array_filter($words, static fn ($w) => $w !== ''));
    if ($words === [] || count($words) > 5) {
        return false;
    }

    $greetingWords = [
        'hi', 'hello', 'hey', 'hiya', 'yo', 'salam', 'assalam', 'assalamu', 'alaikum', 'aoa',
        'howdy', 'greetings', 'morning', 'afternoon', 'evening', 'good',
    ];

    foreach ($words as $word) {
        if (!in_array($word, $greetingWords, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Common conversational replies without calling AI (wellbeing, frustration, repeat greeting).
 *
 * @param array<string, mixed> $bot
 * @return array{success: true, reply: string, signals: array<int, string>}|null
 */
function conversation_try_common_reply(array $bot, int $leadId, string $userMessage): ?array
{
    $lower = mb_strtolower(trim($userMessage));
    if ($lower === '') {
        return null;
    }

    require_once __DIR__ . '/conversation-intent.php';

    $rep = get_bot_rep_name($bot);
    $brand = trim((string) ($bot['name'] ?? ''));
    $brandLabel = $brand !== '' ? $brand : 'our team';

    if (preg_match('/\b(not reading|not responding|ignor|didn\'?t answer|no reply|anyone there|are you there|hello\?+|why aren\'?t you)\b/u', $lower)) {
        require_once __DIR__ . '/bot-knowledge.php';
        $topics = bot_fallback_help_topics($bot, $userMessage);
        $reply = conversation_finalize_reply(
            $bot,
            $leadId,
            "Sorry for the delay — I'm here now. What do you need help with regarding {$topics}?"
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
        return ['success' => true, 'reply' => $reply, 'signals' => ['COMMON']];
    }

    if (preg_match('/\b(thank you|thanks|shukriya|jazakallah)\b/u', $lower) && mb_strlen($lower) < 40) {
        $reply = conversation_finalize_reply(
            $bot,
            $leadId,
            "You're welcome! Message me anytime if you need anything else."
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
        return ['success' => true, 'reply' => $reply, 'signals' => ['COMMON']];
    }

    if (preg_match('/\b(bye|goodbye|good night|goodnight|see you|allah hafiz|khuda hafiz)\b/u', $lower) && mb_strlen($lower) < 48) {
        require_once __DIR__ . '/cart.php';
        if (!cart_is_empty($leadId)) {
            cart_clear($leadId);
        }
        $reply = conversation_finalize_reply(
            $bot,
            $leadId,
            "Good night! Message us anytime — we're here when you need us."
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
        return ['success' => true, 'reply' => $reply, 'signals' => ['COMMON']];
    }

    if (preg_match('/^(ni|nahi|no)\s+(chahye|chahiye|chahie)/u', $lower)) {
        require_once __DIR__ . '/cart.php';
        if (!cart_is_empty($leadId)) {
            cart_clear($leadId);
        }
        $reply = conversation_finalize_reply(
            $bot,
            $leadId,
            "No problem — message us anytime when you're ready."
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
        return ['success' => true, 'reply' => $reply, 'signals' => ['COMMON']];
    }

    if (preg_match('/\b(love you|i love|like you|marry)\b/u', $lower)) {
        $reply = conversation_finalize_reply(
            $bot,
            $leadId,
            conversation_casual_redirect_reply($bot, $userMessage)
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
        return ['success' => true, 'reply' => $reply, 'signals' => ['COMMON']];
    }

    if (conversation_is_casual_chat($userMessage)) {
        conversation_bump_lead_interest($leadId, $userMessage, 5);
        $reply = conversation_finalize_reply(
            $bot,
            $leadId,
            conversation_casual_redirect_reply($bot, $userMessage)
        );
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $reply]
        );
        return ['success' => true, 'reply' => $reply, 'signals' => ['CASUAL']];
    }

    return null;
}

/** True if this lead already has at least one assistant message in the thread. */
function conversation_thread_started(int $leadId): bool
{
    if ($leadId <= 0) {
        return false;
    }
    ensure_conversations_schema();
    $row = db_fetch(
        'SELECT id FROM conversations WHERE lead_id = ? AND role = \'assistant\' LIMIT 1',
        'i',
        [$leadId]
    );

    return $row !== null;
}

function conversation_last_assistant_reply(int $leadId): string
{
    if ($leadId <= 0) {
        return '';
    }
    ensure_conversations_schema();
    $row = db_fetch(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );

    return trim((string) ($row['message'] ?? ''));
}

/**
 * @return list<string>
 */
function conversation_last_assistant_replies(int $leadId, int $limit = 2): array
{
    if ($leadId <= 0 || $limit <= 0) {
        return [];
    }
    ensure_conversations_schema();
    $rows = db_fetch_all(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 2',
        'i',
        [$leadId]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = trim((string) ($row['message'] ?? ''));
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/** Persist the reply that was actually sent (insert, or replace a draft from this same turn). */
function conversation_store_sent_assistant_reply(int $leadId, string $reply): void
{
    $reply = trim($reply);
    if ($leadId <= 0 || $reply === '') {
        return;
    }

    ensure_conversations_schema();
    $row = db_fetch(
        'SELECT id, message, created_at FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );
    if ($row) {
        $created = strtotime((string) ($row['created_at'] ?? ''));
        $sameDraft = $created !== false && $created >= time() - 45;
        if ($sameDraft) {
            if (trim((string) ($row['message'] ?? '')) !== $reply) {
                db_execute('UPDATE conversations SET message = ? WHERE id = ?', 'si', [$reply, (int) $row['id']]);
            }

            return;
        }
    }

    require_once __DIR__ . '/conversation-media.php';
    conversation_insert($leadId, 'assistant', $reply);
}

/** Generic shop CTA that tells the customer to type *menu* instead of sending the menu. */
function conversation_is_generic_menu_prompt_reply(string $reply): bool
{
    $lower = mb_strtolower(trim($reply));
    if ($lower === '') {
        return false;
    }

    if (preg_match('/i\'m here\s*[—–-]\s*reply \*?menu\*?/u', $lower)) {
        return true;
    }

    if (preg_match('/i\'m here with .+ tap below/u', $lower)) {
        return true;
    }

    if (preg_match('/tap below to browse the menu/u', $lower)) {
        return true;
    }

    if (preg_match('/in the mood for|tell me the section or item|i can send the menu once you tell me/u', $lower)) {
        return true;
    }

    if (preg_match('/reply \*?menu\*? to (browse|see|view)/u', $lower)
        && !preg_match('/here is our menu|here\'s our menu|sending (one )?menu|tap an item/u', $lower)) {
        return true;
    }

    return false;
}

function conversation_flag_shop_menu_send(bool $on = true): void
{
    $GLOBALS['conversation_flag_shop_menu'] = $on;
}

function conversation_shop_menu_send_flagged(): bool
{
    return !empty($GLOBALS['conversation_flag_shop_menu']);
}

function conversation_consume_shop_menu_send(): bool
{
    $on = !empty($GLOBALS['conversation_flag_shop_menu']);
    unset($GLOBALS['conversation_flag_shop_menu']);

    return $on;
}

/** Customer-facing copy when we actually send the menu card (no type-*menu* CTA). */
function conversation_shop_menu_open_reply(array $bot, string $userMessage = ''): string
{
    require_once __DIR__ . '/whatsapp-shop-ux.php';
    if (function_exists('whatsapp_shop_copy_menu_with_items')) {
        $withItems = whatsapp_shop_copy_menu_with_items($bot, (int) ($bot['id'] ?? 0), $userMessage);
        if ($withItems !== '') {
            return $withItems;
        }
    }

    return whatsapp_shop_copy_menu_intro();
}

function conversation_would_repeat_reply(int $leadId, string $reply): bool
{
    require_once __DIR__ . '/conversation-router.php';

    $reply = trim($reply);
    if ($leadId <= 0 || $reply === '') {
        return false;
    }

    $recent = conversation_last_assistant_replies($leadId, 2);
    if ($recent === []) {
        return false;
    }

    $last = $recent[0];
    // The latest assistant row may be the draft we just stored this turn — compare to the one before it.
    if (mb_strtolower($last) === mb_strtolower($reply)) {
        if (!isset($recent[1]) || trim($recent[1]) === '') {
            return false;
        }
        $last = $recent[1];
    }

    if ($last === '') {
        return false;
    }

    if (conversation_is_generic_menu_prompt_reply($reply)
        && (conversation_is_generic_menu_prompt_reply($last) || conversation_text_too_similar($last, $reply))) {
        return true;
    }

    if (conversation_is_generic_menu_prompt_reply($last) && conversation_is_generic_menu_prompt_reply($reply)) {
        return true;
    }

    if (conversation_is_shop_pitch_reply($reply) && conversation_is_shop_pitch_reply($last)) {
        return true;
    }

    return conversation_text_too_similar($last, $reply);
}

function conversation_reply_is_structured_menu(string $text): bool
{
    if (str_contains($text, "\n") && (str_contains($text, '💰') || str_contains($text, '────────'))) {
        return true;
    }

    return (bool) preg_match('/reply with a number|tap \*view catalog\*|showing \d/iu', $text)
        && substr_count($text, "\n") >= 2;
}

function conversation_normalize_whatsapp_whitespace(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    $lines = explode("\n", $text);
    foreach ($lines as &$line) {
        $line = rtrim($line);
    }
    unset($line);

    return trim(implode("\n", $lines));
}

function conversation_strip_unresolved_placeholders(string $text): string
{
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\[[^\]\n]{1,64}\]/u', '', $text) ?? $text;
    $text = preg_replace('/\{\{[^}\n]{1,64}\}\}/u', '', $text) ?? $text;
    $text = preg_replace('/\$\{[^}\n]{1,64}\}/u', '', $text) ?? $text;

    return conversation_normalize_whatsapp_whitespace($text);
}

/** Strip bot/AI jargon from outbound customer text. */
function conversation_sanitize_customer_facing(string $text): string
{
    $structured = conversation_reply_is_structured_menu($text);
    $text = conversation_strip_internal_directives($text);

    if (function_exists('knowledge_sanitize_for_customer')) {
        $text = knowledge_sanitize_for_customer($text);
    }

    $text = conversation_strip_unresolved_placeholders($text);
    $text = conversation_normalize_whatsapp_whitespace($text);
    if ($structured || conversation_reply_is_structured_menu($text)) {
        return $text;
    }

    return conversation_strip_truncated_tail($text);
}

/** Remove mid-sentence cuts and trailing ellipsis from outbound replies. */
function conversation_strip_truncated_tail(string $text): string
{
    if ($text === '') {
        return '';
    }

    if (preg_match('/(?:…|\.\.\.)\s*$/u', $text)) {
        $text = preg_replace('/(?:…|\.\.\.)\s*$/u', '', $text) ?? $text;
        $text = rtrim($text, ' ,;:');
        if ($text !== '' && !preg_match('/[.!?]$/u', $text)) {
            $text .= '.';
        }
    }

    if (str_contains($text, "\n") || conversation_reply_is_structured_menu($text)) {
        return trim($text);
    }

    if (mb_strlen($text) > 80 && !preg_match('/[.!?]$/u', $text)) {
        if (preg_match('/^(.+[.!?])\s/u', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+)\s+\S+$/u', $text, $m)) {
            return trim($m[1]) . '.';
        }
    }

    return trim($text);
}

/**
 * Remove internal prompt / training directives that must never reach customers.
 */
function conversation_strip_internal_directives(string $text): string
{
    if ($text === '') {
        return '';
    }

    $patterns = [
        '/\[(?:Reply naturally|do not repeat|mandatory|never say|CATALOG VERIFICATION|Customer image|Voice note|DECISION:)[^\]]*\]/iu',
        '/\n?─────\s*(?:THIS BUSINESS|BUSINESS FACTS|SEARCH RESULTS|UNTRUSTED|CATALOG VERIFICATION)[^\n]*/iu',
        '/\[(?:[^\]]{12,}(?:do not|never|mandatory|override|Reply naturally|injected|append|overrides)[^\]]*)\]/iu',
        '/\b(?:SOCIAL CUE|LANGUAGE|CONVERSATION CUE|LIVE AGENT MODE|SALES SIGNALS|Prime Directive|SOURCE OF TRUTH)\s*:[^\n]*/iu',
        '/\bReply naturally to their latest message[^\n]*/iu',
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', $text) ?? $text;
    }

    return conversation_normalize_whatsapp_whitespace($text);
}

/**
 * Casual banter — not a company-fact question.
 */
function conversation_is_casual_chat(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '' || mb_strlen($lower) > 120) {
        return false;
    }

    if (preg_match('/\b(haha|lol|lmao|hehe|nice|cool|awesome|great|good|funny|cute|sweet)\b/u', $lower)) {
        return !preg_match('/\b(price|cost|fee|offer|service|product|order|visa|study|how much|what do you)\b/u', $lower);
    }

    if (preg_match('/\b(just chatting|bored|random|tell me a joke|who made you)\b/u', $lower)) {
        return true;
    }

    return false;
}

/** Interest / intent signals worth nurturing toward conversion. */
function conversation_has_buying_signal(string $message): bool
{
    $lower = mb_strtolower(trim($message));

    if (preg_match(
        '/\b(product|products|catalog|catalogue|menu|image|photo|pic|pricing|price|pkr|rs\.?|'
        . 'how much|cost|buy|order|available|in stock|show me|send me|do you have|have you got)\b/u',
        $lower
    )) {
        return true;
    }

    return (bool) preg_match(
        '/\b(interested|sign up|get started|need this|want this|tell me more|'
        . 'book|demo|trial|package|plan|quote|when can|how does it work|help me with)\b/u',
        $lower
    );
}

function conversation_text_too_similar(string $a, string $b): bool
{
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }

    similar_text($a, $b, $pct);

    return $pct >= 72.0;
}

/**
 * Only for company-specific facts not in KB — not casual chat or general sales talk.
 *
 * @param array<string, mixed> $bot
 */
function conversation_should_verify_with_team(array $bot, string $userMessage): bool
{
    require_once __DIR__ . '/bot-knowledge.php';
    require_once __DIR__ . '/conversation-intent.php';

    if (knowledge_message_is_offer_question($userMessage)
        || conversation_is_bot_frustration($userMessage)
        || conversation_is_identity_question($userMessage)
        || conversation_is_wellbeing_question($userMessage)
    ) {
        return false;
    }

    if (conversation_is_casual_chat($userMessage) || message_is_simple_greeting($userMessage)) {
        return false;
    }

    $lower = mb_strtolower(trim($userMessage));
    if (mb_strlen($lower) < 12) {
        return false;
    }

    require_once __DIR__ . '/bot-knowledge.php';
    if (knowledge_question_likely_answerable($bot, $userMessage)) {
        return false;
    }

    if (conversation_has_buying_signal($userMessage)) {
        return false;
    }

    return (bool) preg_match(
        '/\b(exact|specific|policy|refund|warranty|custom|exception|confirm|verify|'
        . 'official|document|contract|legal|compliance|can you guarantee|do you support)\b/u',
        $lower
    );
}

/** Customer asked something specific — never reply with a generic nudge. */
function conversation_is_direct_question(string $message): bool
{
    require_once __DIR__ . '/conversation-intent.php';
    require_once __DIR__ . '/bot-knowledge.php';

    if (trim($message) === '') {
        return false;
    }

    if (conversation_should_hold_turn_for_more_input($message, 1)) {
        return false;
    }

    if (knowledge_message_is_offer_question($message)
        || conversation_is_identity_question($message)
        || conversation_is_wellbeing_question($message)
        || conversation_is_meta_activity_question($message)
        || conversation_is_bot_frustration($message)
    ) {
        return true;
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($message));

    return (bool) preg_match(
        '/\?|\b(what|how|why|when|where|who|tell me|can you|do you|are you|price|cost|offer|provid|service|product)\b/u',
        $lower
    );
}

/** Nudge toward conversion — default when AI is down but chat should continue naturally. */
function conversation_conversion_nudge_reply(array $bot, string $userMessage, int $leadId = 0): string
{
    require_once __DIR__ . '/bot-knowledge.php';
    require_once __DIR__ . '/catalog.php';

    if (knowledge_message_is_offer_question($userMessage)) {
        return knowledge_offer_reply_text($bot, $userMessage, $leadId);
    }

    if ($leadId > 0) {
        require_once __DIR__ . '/whatsapp-inbound.php';
        if (whatsapp_lead_has_prior_reply($leadId)) {
            require_once __DIR__ . '/catalog.php';
            $botId = (int) ($bot['id'] ?? 0);
            if ($botId > 0 && catalog_bot_has_products($botId)) {
                require_once __DIR__ . '/whatsapp-shop-ux.php';
                require_once __DIR__ . '/conversation-router.php';
                if (conversation_route_is_explicit_menu($userMessage)) {
                    conversation_flag_shop_menu_send(true);

                    return conversation_shop_menu_open_reply($bot, $userMessage);
                }
                if (preg_match('/\b(cost|price|how much)\b/u', mb_strtolower(trim($userMessage)))) {
                    return whatsapp_shop_copy_prices();
                }

                $rep = get_bot_rep_name($bot);

                return "I'm {$rep} — I heard you. Go ahead.";
            }
            if (preg_match('/\b(cost|price|how much)\b/u', mb_strtolower(trim($userMessage)))) {
                return 'Pricing depends on the project — tell me what you have in mind and I\'ll give you a straight number.';
            }

            return 'I\'m here — tell me what you need and I\'ll help directly.';
        }
    }

    $rep = get_bot_rep_name($bot);
    $topics = bot_fallback_help_topics($bot, $userMessage);
    $botId = (int) ($bot['id'] ?? 0);
    $hasCatalog = $botId > 0 && catalog_bot_has_products($botId);

    if ($hasCatalog && catalog_has_clear_shopping_intent($userMessage)) {
        $variants = [
            'Sure — let me pull that up from our catalog for you.',
            'One sec — I\'ll grab the details and photos for you.',
        ];
    } elseif (conversation_has_buying_signal($userMessage)) {
        $variants = [
            "Good to hear you're interested! What matters most to you right now, {$topics}?",
        ];
    } else {
        require_once __DIR__ . '/human-agent-prompt.php';

        return human_agent_warm_last_resort($bot, $userMessage, $leadId);
    }

    $salt = $userMessage !== '' ? $userMessage : (string) $leadId;
    $idx = abs(crc32($salt . '|' . $rep . '|' . $leadId)) % count($variants);

    return $variants[$idx];
}

/**
 * Smarter fallback when AI output is empty or would repeat — catalog-first for shop bots.
 *
 * @param array<string, mixed> $bot
 */
function conversation_smart_fallback_reply(array $bot, int $leadId, string $userMessage, string $avoidReply = ''): string
{
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/bot-knowledge.php';

    $local = knowledge_try_local_reply($bot, $userMessage, $leadId);
    if ($local !== null && ($avoidReply === '' || !conversation_text_too_similar($avoidReply, $local))) {
        return $local;
    }

    $botId = (int) ($bot['id'] ?? 0);
    if ($botId > 0 && catalog_bot_has_products($botId) && catalog_has_clear_shopping_intent($userMessage)) {
        $hit = catalog_try_resolve_product_request($botId, $userMessage, $bot);
        if ($hit !== null) {
            $candidate = trim((string) ($hit['reply'] ?? ''));
            if ($candidate !== '' && ($avoidReply === '' || !conversation_text_too_similar($avoidReply, $candidate))) {
                return $candidate;
            }
        }
    }

    $nudge = conversation_conversion_nudge_reply($bot, $userMessage, $leadId);
    if ($avoidReply !== '' && conversation_text_too_similar($avoidReply, $nudge)) {
        require_once __DIR__ . '/human-agent-prompt.php';
        $nudge = human_agent_warm_last_resort($bot, $userMessage . ' ', $leadId);
    }
    if (conversation_is_canned_help_intro($nudge) || conversation_is_generic_deflection_reply($nudge)) {
        require_once __DIR__ . '/human-agent-prompt.php';

        return human_agent_warm_last_resort($bot, $userMessage, $leadId);
    }

    return $nudge;
}

/** Friendly reply for flirt / banter — warm, professional, steers back to business. */
function conversation_casual_redirect_reply(array $bot, string $userMessage): string
{
    $rep = get_bot_rep_name($bot);
    $brand = trim((string) ($bot['name'] ?? ''));
    $brandLabel = $brand !== '' ? $brand : 'our team';
    $lower = mb_strtolower(trim($userMessage));

    if (preg_match('/\b(love you|i love|like you|marry)\b/u', $lower)) {
        return "That's sweet of you! I'm {$rep} from {$brandLabel} — tell me what you're looking for and I'll help.";
    }

    if (preg_match('/\b(haha|lol|hehe|funny)\b/u', $lower)) {
        return "Haha glad we're chatting! When you're ready — what can I help you with at {$brandLabel}?";
    }

    return "I'm {$rep} from {$brandLabel} — always happy to chat. What can I help you with today?";
}

/** Raise lead score when interest signals appear. */
function conversation_bump_lead_interest(int $leadId, string $userMessage, int $points = 10): void
{
    if ($leadId <= 0 || $points <= 0) {
        return;
    }
    if (!conversation_has_buying_signal($userMessage) && !conversation_is_casual_chat($userMessage)) {
        return;
    }
    try {
        db_execute(
            'UPDATE leads SET score = LEAST(100, score + ?) WHERE id = ?',
            'ii',
            [$points, $leadId]
        );
    } catch (Throwable $e) {
        error_log('conversation_bump_lead_interest: ' . $e->getMessage());
    }
}

/**
 * Human-style reply when the answer is not in the knowledge base.
 *
 * @param array<string, mixed> $bot
 */
function conversation_verify_with_team_reply(array $bot, string $userMessage = ''): string
{
    $rep = get_bot_rep_name($bot);
    $variants = [
        "Good question — let me double-check that and get back to you here shortly. One moment please.",
        "I'll confirm the exact detail and reply here soon — please wait a moment.",
        "Let me verify that for you and come back to you shortly.",
        "{$rep} here — let me check on that and get back to you shortly.",
        "One moment — I'll confirm that and reply here soon.",
    ];
    $idx = abs(crc32($userMessage . '|' . $rep)) % count($variants);

    return $variants[$idx];
}

function conversation_is_stall_reply(string $reply): bool
{
    return (bool) preg_match(
        '/\b(double-check|verify that|get back to you shortly|one moment please|let me check|confirm the exact detail|come back to you shortly)\b/iu',
        $reply
    );
}

function conversation_is_marketing_dump_reply(string $reply): bool
{
    $lower = mb_strtolower(trim($reply));

    if (preg_match(
        '/\b(dramatic product reveals|lifestyle storytelling|high-impact social|we can develop concepts|'
        . 'our services include|brand storytelling|content strategy|cinematic advertisements|'
        . 'transform your ideas|brand stories into)\b/u',
        $lower
    )) {
        return true;
    }

    return mb_strlen($reply) > 180
        && preg_match('/\b(offer|provide|service|product|content|marketing|brand|concept)\b/u', $lower);
}

function conversation_is_canned_help_intro(string $reply): bool
{
    $lower = mb_strtolower(trim($reply));
    if ($lower === '') {
        return false;
    }

    if (preg_match(
        '/^(?:hi[!.,\s]*)?i(?:\'m| am) .{1,40} from .{1,80}\s*[—–.-]\s*what can i help you with/u',
        $lower
    )) {
        return true;
    }

    return (bool) preg_match(
        '/\b(i\'m|i am) .{1,40} from .{1,80}\s*[—–-]\s*(what can i help you with|how can i help you)\b/u',
        $lower
    );
}

function conversation_is_reintroduction_reply(string $reply): bool
{
    if (conversation_is_canned_help_intro($reply)) {
        return true;
    }

    $lower = mb_strtolower(trim($reply));

    return (bool) preg_match('/\b(i\'m|i am) .+ from .+ —/u', $lower)
        && (bool) preg_match('/what can i help|how can i help|ask me anything|good to hear from you/u', $lower);
}

function conversation_is_generic_deflection_reply(string $reply): bool
{
    $lower = mb_strtolower(trim($reply));

    return conversation_is_canned_help_intro($reply)
        || (bool) preg_match(
            '/tell me what you need|what\'?s on your mind|guide you from here|'
            . 'how can i help you today|how can i help\??|what can i help you with|'
            . 'happy to help! what|sure — tell me|'
            . 'i\'m .+ from .+ — how can i help|at your service\b/u',
            $lower
        ) || conversation_is_stall_reply($reply);
}

/**
 * Sanitize, dedupe, and return a customer-facing reply.
 *
 * @param array<string, mixed> $bot
 */
function conversation_finalize_reply(array $bot, int $leadId, string $reply, string $userMessage = ''): string
{
    $reply = conversation_sanitize_customer_facing(trim($reply));
    $botId = (int) ($bot['id'] ?? 0);
    require_once __DIR__ . '/conversation-intent.php';
    $reply = conversation_strip_bot_habits($reply, $userMessage);
    $hasCatalog = false;
    if ($botId > 0) {
        require_once __DIR__ . '/catalog.php';
        $hasCatalog = catalog_bot_has_products($botId);
    }

    $genericMenu = conversation_is_generic_menu_prompt_reply($reply) || conversation_is_shop_pitch_reply($reply);
    $wouldRepeat = $leadId > 0 && conversation_would_repeat_reply($leadId, $reply);

    require_once __DIR__ . '/conversation-router.php';
    $wantsMenu = $userMessage !== '' && (
        conversation_route_is_explicit_menu($userMessage)
        || (function_exists('whatsapp_shop_customer_wants_visual_card') && whatsapp_shop_customer_wants_visual_card($userMessage))
        || (function_exists('catalog_message_is_browse_intent') && catalog_message_is_browse_intent($userMessage))
    );

    if ($hasCatalog && $wantsMenu && ($genericMenu || $wouldRepeat || $reply === '')) {
        conversation_flag_shop_menu_send(true);

        return conversation_shop_menu_open_reply($bot, $userMessage);
    }

    if ($genericMenu) {
        $rep = get_bot_rep_name($bot);

        return conversation_append_conversion_resume("I'm {$rep} — I heard you. Go ahead.", $leadId, $botId, $userMessage);
    }

    if ($reply === '') {
        return conversation_append_conversion_resume(
            conversation_smart_fallback_reply($bot, $leadId, $userMessage),
            $leadId,
            $botId,
            $userMessage
        );
    }

    if ($leadId > 0 && conversation_would_repeat_reply($leadId, $reply)) {
        require_once __DIR__ . '/bot-knowledge.php';
        if ($userMessage !== '' && knowledge_message_is_offer_question($userMessage)) {
            $offer = knowledge_offer_reply_text($bot, $userMessage, $leadId);
            if ($offer !== '' && !conversation_text_too_similar(conversation_last_assistant_reply($leadId), $offer)) {
                return conversation_append_conversion_resume($offer, $leadId, $botId, $userMessage);
            }
        }

        if ($hasCatalog && $wantsMenu) {
            conversation_flag_shop_menu_send(true);

            return conversation_shop_menu_open_reply($bot, $userMessage);
        }

        $rep = get_bot_rep_name($bot);

        return conversation_append_conversion_resume("I'm {$rep} — I heard you. Go ahead.", $leadId, $botId, $userMessage);
    }

    return conversation_append_conversion_resume($reply, $leadId, $botId, $userMessage);
}

/**
 * Fast path: shop catalog reply before calling AI (product photos, pricing, browse).
 *
 * @param array<string, mixed> $bot
 * @return array{success: true, reply: string, signals: array<int, string>, product_indexes?: array<int, int>}|null
 */
function conversation_try_shop_catalog_response(array $bot, int $leadId, int $botId, string $userMessage): ?array
{
    require_once __DIR__ . '/catalog.php';

    if ($botId <= 0 || !catalog_bot_has_products($botId)) {
        return null;
    }
    if (!catalog_has_clear_shopping_intent($userMessage)) {
        return null;
    }

    $hit = catalog_try_resolve_product_request($botId, $userMessage, $bot);
    if ($hit === null) {
        return null;
    }

    $reply = conversation_finalize_reply($bot, $leadId, (string) ($hit['reply'] ?? ''), $userMessage);
    if ($reply === '') {
        return null;
    }

    db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
        'is',
        [$leadId, $reply]
    );

    return [
        'success'         => true,
        'reply'           => $reply,
        'signals'         => !empty($hit['checking']) ? ['CHECKING'] : ['CATALOG'],
        'product_indexes' => $hit['indexes'] ?? [],
        'menu_card'       => !empty($hit['menu_card']),
        'menu_card_title' => (string) ($hit['menu_card_title'] ?? ''),
    ];
}

/**
 * Reply to a pure greeting without calling the AI (widget + WhatsApp).
 *
 * @param array<string, mixed> $bot
 * @return array{success: true, reply: string, signals: array<int, string>}|null
 */
function conversation_try_greeting_response(array $bot, int $leadId, string $userMessage): ?array
{
    if (!message_is_simple_greeting($userMessage)) {
        return null;
    }

    require_once __DIR__ . '/conversation-intent.php';
    if (conversation_is_wellbeing_question($userMessage)) {
        return null;
    }

    if (conversation_should_hold_turn_for_more_input($userMessage, 1)) {
        return null;
    }

    if ($leadId > 0) {
        require_once __DIR__ . '/whatsapp-inbound.php';
        if (whatsapp_lead_has_prior_reply($leadId)) {
            $short = whatsapp_human_contextual_reply($bot, $leadId, $userMessage);
            if ($short === null || $short === '') {
                $short = 'Hey! Still here — go ahead.';
            }
            db_insert(
                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                'is',
                [$leadId, $short]
            );

            return [
                'success' => true,
                'reply'   => $short,
                'signals' => ['GREETING'],
            ];
        }
    }

    $reply = get_demo_greeting($bot);
    $reply = conversation_finalize_reply($bot, $leadId, $reply);
    db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
        'is',
        [$leadId, $reply]
    );

    return [
        'success' => true,
        'reply'   => $reply,
        'signals' => ['GREETING'],
    ];
}

/**
 * Opening message for public demo chat (uses bot configured in settings).
 *
 * @param array<string, mixed> $bot
 */
function get_demo_greeting(array $bot): string
{
    $rep = get_bot_rep_name($bot);
    $business = trim($bot['name'] ?? '');

    if ($business !== '' && strcasecmp($rep, $business) !== 0) {
        return "Hey! I'm {$rep} from {$business} — good to hear from you.";
    }

    return "Hey! I'm {$rep} — good to hear from you.";
}

/**
 * Extra assets for marketing/landing pages.
 *
 * @return string HTML
 */
function marketing_assets(): string
{
    $v = @filemtime(__DIR__ . '/../assets/css/landing.css') ?: time();
    $hip = @filemtime(__DIR__ . '/../assets/css/hippo-landing.css') ?: time();
    return '<link href="/assets/css/landing.css?v=' . $v . '" rel="stylesheet"/>'
        . '<link href="/assets/css/hippo-landing.css?v=' . $hip . '" rel="stylesheet"/>';
}

/**
 * Auth page V2 stylesheet (login, register, verify, password reset).
 *
 * @return string HTML link tags
 */
function auth_assets(): string
{
    $v = @filemtime(__DIR__ . '/../assets/css/auth-v2.css') ?: time();
    return '<link href="/assets/css/auth-v2.css?v=' . $v . '" rel="stylesheet"/>';
}

/**
 * PWA install prompt — client portal only, loaded after login.
 */
function client_pwa_install_script(): string
{
    $pwaVer = @filemtime(__DIR__ . '/../assets/js/pwa-install.js') ?: time();
    $appName = sanitize(APP_NAME);

    return '<script src="/assets/js/pwa-install.js?v=' . $pwaVer . '" data-client-pwa="1" data-app-name="' . $appName . '"></script>';
}

require_once __DIR__ . '/notification-bell.php';
require_once __DIR__ . '/client-header.php';
