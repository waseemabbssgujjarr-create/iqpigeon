<?php
/**
 * Session management and authentication helpers.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;
const REMEMBER_COOKIE_NAME = 'als_remember';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

auth_try_remember_login();

/**
 * Get the currently logged-in user row.
 *
 * @return array<string, mixed>|null
 */
function get_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    try {
        $fields = 'id, name, email, role, company_name, avatar_initials,
                    stripe_customer_id, stripe_subscription_id,
                    subscription_status, subscription_plan, trial_ends_at, created_at';
        $optional = ['email_verified_at', 'avatar_url', 'phone', 'profile_title',
            'pref_language', 'pref_timezone', 'pref_currency', 'pref_date_format'];
        foreach ($optional as $col) {
            if (db_column_exists('users', $col)) {
                $fields .= ', ' . $col;
            }
        }

        $user = db_fetch(
            "SELECT {$fields} FROM users WHERE id = ?",
            'i',
            [(int) $_SESSION['user_id']]
        );

        if ($user && !array_key_exists('email_verified_at', $user)) {
            $user['email_verified_at'] = date('Y-m-d H:i:s');
        }

        return $user;
    } catch (Throwable $e) {
        error_log('get_user failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Require authenticated session; redirect to login if not.
 *
 * @return array<string, mixed> Current user
 */
function require_login(): array
{
    $user = get_user();
    if (!$user) {
        redirect('/login.php');
    }

    $script = basename($_SERVER['PHP_SELF'] ?? '');
    $allowedUnverified = ['verify-email.php', 'logout.php'];

    if ($user['role'] === 'client'
        && email_verification_enabled()
        && !is_user_email_verified($user)
        && !in_array($script, $allowedUnverified, true)
    ) {
        redirect('/verify-email.php');
    }

    return $user;
}

/**
 * Require authenticated session for JSON API endpoints (no HTML redirect).
 *
 * @return array<string, mixed> Current user
 */
function require_login_json(): array
{
    $user = get_user();
    if (!$user) {
        json_response(['success' => false, 'error' => 'Session expired. Please sign in again.'], 401);
    }

    if ($user['role'] === 'client'
        && email_verification_enabled()
        && !is_user_email_verified($user)
    ) {
        json_response(['success' => false, 'error' => 'Verify your email to continue.'], 403);
    }

    return $user;
}

/**
 * Require admin role.
 *
 * @return array<string, mixed> Current admin user
 */
function require_admin(): array
{
    $user = get_user();
    if (!$user) {
        redirect(admin_login_url());
    }
    if ($user['role'] !== 'admin') {
        redirect('/client/dashboard');
    }
    return $user;
}

/**
 * URL for the admin sign-in page (optionally includes access key).
 */
function admin_login_url(): string
{
    $url = '/admin/login';
    if (defined('ADMIN_ACCESS_KEY') && ADMIN_ACCESS_KEY !== '') {
        $url .= '?key=' . urlencode(ADMIN_ACCESS_KEY);
    }
    return $url;
}

/**
 * Whether the current request has a valid admin portal access key (if configured).
 */
function admin_access_granted(): bool
{
    if (!defined('ADMIN_ACCESS_KEY') || ADMIN_ACCESS_KEY === '') {
        return true;
    }

    $key = (string) ($_GET['key'] ?? $_POST['access_key'] ?? '');

    return admin_access_key_valid($key);
}

/**
 * Attempt login with email and password.
 *
 * @param string $email
 * @param string $password
 * @param array{admin_only?: bool, client_only?: bool, remember?: bool} $options
 * @return array{success: bool, message: string, user?: array<string, mixed>}
 */
function login(string $email, string $password, array $options = []): array
{
    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $user = db_fetch(
        'SELECT id, name, email, password, role, company_name, avatar_initials,
                login_attempts, locked_until
         FROM users WHERE email = ?',
        's',
        [$email]
    );

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        return [
            'success' => false,
            'message' => 'Too many attempts. Try again in 15 minutes.',
            'locked' => true,
        ];
    }

    if (!password_verify($password, $user['password'])) {
        $attempts = (int) $user['login_attempts'] + 1;
        $lockedUntil = null;

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            $attempts = 0;
        }

        db_execute(
            'UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?',
            'isi',
            [$attempts, $lockedUntil, (int) $user['id']]
        );

        if ($lockedUntil) {
            return [
                'success' => false,
                'message' => 'Too many attempts. Try again in 15 minutes.',
                'locked' => true,
            ];
        }

        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $adminOnly = !empty($options['admin_only']);
    $clientOnly = !empty($options['client_only']);

    if ($adminOnly && ($user['role'] ?? '') !== 'admin') {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }
    if ($clientOnly && ($user['role'] ?? '') === 'admin') {
        return [
            'success' => false,
            'message' => 'Administrator accounts must use the admin sign-in page.',
            'admin_portal' => true,
        ];
    }

    db_execute(
        'UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?',
        'i',
        [(int) $user['id']]
    );

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];

    if (!empty($options['remember'])) {
        auth_issue_remember_token((int) $user['id']);
    }

    unset($user['password'], $user['login_attempts'], $user['locked_until']);

    if (db_column_exists('users', 'email_verified_at')) {
        $fullUser = db_fetch('SELECT email_verified_at FROM users WHERE id = ?', 'i', [(int) $user['id']]);
        $user['email_verified_at'] = $fullUser['email_verified_at'] ?? null;
    } else {
        $user['email_verified_at'] = date('Y-m-d H:i:s');
    }

    return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
}

/**
 * Destroy session and log out.
 *
 * @return void
 */
function logout(): void
{
    auth_clear_remember_token();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Whether new clients must verify email before using the dashboard.
 */
function email_verification_enabled(): bool
{
    return defined('REQUIRE_EMAIL_VERIFICATION') && REQUIRE_EMAIL_VERIFICATION;
}

/**
 * Check if user's email is verified (admins always pass).
 *
 * @param array<string, mixed>|null $user
 */
function is_user_email_verified(?array $user): bool
{
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    if (!email_verification_enabled()) {
        return true;
    }
    return !empty($user['email_verified_at']);
}

/**
 * Generate a 6-digit verification code.
 */
function generate_verify_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Email verification TTL in seconds (default 30 minutes).
 */
function email_verify_expiry_seconds(): int
{
    if (defined('EMAIL_VERIFY_EXPIRY_MINUTES')) {
        return max(5, (int) EMAIL_VERIFY_EXPIRY_MINUTES) * 60;
    }

    $hours = defined('EMAIL_VERIFY_EXPIRY_HOURS') ? (int) EMAIL_VERIFY_EXPIRY_HOURS : 24;

    return max(300, $hours * 3600);
}

/**
 * Human-readable expiry label for emails/UI.
 */
function email_verify_expiry_label(): string
{
    if (defined('EMAIL_VERIFY_EXPIRY_MINUTES')) {
        $mins = max(5, (int) EMAIL_VERIFY_EXPIRY_MINUTES);

        return $mins . ' minute' . ($mins === 1 ? '' : 's');
    }

    $hours = defined('EMAIL_VERIFY_EXPIRY_HOURS') ? (int) EMAIL_VERIFY_EXPIRY_HOURS : 24;

    return $hours . ' hour' . ($hours === 1 ? '' : 's');
}

/**
 * Log in a verified client and keep them signed in (30-day remember cookie).
 */
function auth_login_verified_client(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = 'client';
    auth_issue_remember_token($userId);
}

/**
 * Issue and email verification link + code.
 */
function send_user_verification_email(int $userId, ?string $verifyPage = null): bool
{
    if (!db_column_exists('users', 'verify_token')) {
        return false;
    }

    require_once __DIR__ . '/mailer.php';

    $row = db_fetch('SELECT id, name, email, email_verified_at FROM users WHERE id = ?', 'i', [$userId]);
    if (!$row || !empty($row['email_verified_at'])) {
        return false;
    }

    $token = generate_token(32);
    $code = generate_verify_code();
    $expires = date('Y-m-d H:i:s', time() + email_verify_expiry_seconds());

    db_execute(
        'UPDATE users SET verify_token = ?, verify_code = ?, verify_expires_at = ? WHERE id = ?',
        'sssi',
        [$token, $code, $expires, $userId]
    );

    $page = trim((string) $verifyPage);
    if ($page === '') {
        $page = rtrim((string) APP_URL, '/') . '/verify-email.php';
    } elseif (!preg_match('#^https?://#i', $page)) {
        $page = rtrim((string) APP_URL, '/') . '/' . ltrim($page, '/');
    }
    $verifyUrl = $page . (str_contains($page, '?') ? '&' : '?') . 'token=' . urlencode($token);

    return email_verify_address($row['email'], $row['name'], $verifyUrl, $code);
}

/**
 * Verify email via link token.
 *
 * @return array{success: bool, message: string}
 */
function verify_email_by_token(string $token): array
{
    if (!db_column_exists('users', 'verify_token')) {
        return ['success' => false, 'message' => 'Email verification is not set up yet. Run migrate-email-verification.php.'];
    }

    require_once __DIR__ . '/mailer.php';

    $row = db_fetch(
        'SELECT id, name, email FROM users WHERE verify_token = ? AND verify_expires_at > NOW()',
        's',
        [$token]
    );

    if (!$row) {
        return ['success' => false, 'message' => 'This verification link is invalid or has expired.'];
    }

    $userId = (int) $row['id'];
    db_execute(
        'UPDATE users SET email_verified_at = NOW(), verify_token = NULL, verify_code = NULL, verify_expires_at = NULL WHERE id = ?',
        'i',
        [$userId]
    );

    auth_login_verified_client($userId);
    email_welcome($row['email'], $row['name']);

    return ['success' => true, 'message' => 'Your email has been verified successfully.', 'user_id' => $userId];
}

/**
 * Verify email via 6-digit code.
 *
 * @return array{success: bool, message: string}
 */
function verify_email_by_code(int $userId, string $code): array
{
    if (!db_column_exists('users', 'verify_code')) {
        return ['success' => false, 'message' => 'Email verification is not set up yet. Run migrate-email-verification.php.'];
    }

    require_once __DIR__ . '/mailer.php';

    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return ['success' => false, 'message' => 'Please enter the 6-digit code from your email.'];
    }

    $row = db_fetch(
        'SELECT id, name, email FROM users WHERE id = ? AND verify_code = ? AND verify_expires_at > NOW()',
        'is',
        [$userId, $code]
    );

    if (!$row) {
        return ['success' => false, 'message' => 'Invalid or expired code. Request a new email.'];
    }

    db_execute(
        'UPDATE users SET email_verified_at = NOW(), verify_token = NULL, verify_code = NULL, verify_expires_at = NULL WHERE id = ?',
        'i',
        [$userId]
    );

    auth_login_verified_client($userId);
    email_welcome($row['email'], $row['name']);

    return ['success' => true, 'message' => 'Your email has been verified successfully.', 'user_id' => $userId];
}

/**
 * Register a new client account.
 *
 * @param array<string, string> $data
 * @return array{success: bool, message: string, user_id?: int}
 */
function register_client(array $data): array
{
    $name = trim($data['name'] ?? '');
    $company = trim($data['company_name'] ?? '');
    $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $data['password'] ?? '';
    $confirm = $data['confirm_password'] ?? '';
    $plan = in_array($data['plan'] ?? '', ['starter', 'pro', 'growth', 'agency'], true)
        ? normalize_plan_slug($data['plan'] ?? 'starter')
        : 'starter';

    if ($name === '') {
        return ['success' => false, 'message' => 'Your name is required.'];
    }
    if ($company === '') {
        $company = $name . ' Business';
    }
    if (!$email) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
    }
    if ($password !== $confirm) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }

    $existing = db_fetch('SELECT id FROM users WHERE email = ?', 's', [$email]);
    if ($existing) {
        return ['success' => false, 'message' => 'An account with this email already exists.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $initials = get_initials($name);
    $trialEnds = date('Y-m-d H:i:s', strtotime('+' . TRIAL_DAYS . ' days'));

    $userId = db_insert(
        'INSERT INTO users (name, email, password, role, company_name, avatar_initials,
                            subscription_plan, subscription_status, trial_ends_at)
         VALUES (?, ?, ?, \'client\', ?, ?, ?, \'trialing\', ?)',
        'sssssss',
        [$name, $email, $hash, $company, $initials, $plan, $trialEnds]
    );

    if (email_verification_enabled() && db_column_exists('users', 'verify_token')) {
        send_user_verification_email($userId, isset($data['verify_page']) ? (string) $data['verify_page'] : null);
    } elseif (db_column_exists('users', 'email_verified_at')) {
        db_mark_email_verified($userId);
    }

    return ['success' => true, 'message' => 'Account created.', 'user_id' => $userId];
}

/**
 * Check if client needs onboarding wizard.
 *
 * @param int $user_id
 * @return bool
 */
function needs_onboarding(int $user_id): bool
{
    $bot = db_fetch('SELECT id FROM bots WHERE user_id = ? LIMIT 1', 'i', [$user_id]);
    return $bot === null;
}

/**
 * WhatsApp Embedded Signup not completed yet — primary post-signup gate.
 */
function needs_whatsapp_connect(int $userId): bool
{
    require_once __DIR__ . '/whatsapp-oauth.php';

    return !whatsapp_client_embedded_connected($userId);
}

/**
 * Default landing URL after login / email verification.
 */
function client_post_auth_url(int $userId): string
{
    if (needs_whatsapp_connect($userId)) {
        return '/client/connect-whatsapp';
    }

    return '/client/dashboard';
}

/**
 * Ensure every client has a starter bot row before WhatsApp OAuth sync.
 */
function ensure_client_starter_bot(int $userId): int
{
    require_once __DIR__ . '/bot-knowledge.php';

    ensure_bots_schema();

    $existing = db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
    if ($existing) {
        return (int) $existing['id'];
    }

    $user = db_fetch('SELECT name, company_name FROM users WHERE id = ?', 'i', [$userId]);
    $ownerName = trim((string) ($user['name'] ?? ''));
    $brand = trim((string) ($user['company_name'] ?? ''));
    if ($brand === '') {
        $brand = $ownerName !== '' ? $ownerName . ' Business' : 'My Business';
    }
    $repName = $ownerName !== '' ? $ownerName : 'Alex';

    return db_insert(
        'INSERT INTO bots (user_id, name, rep_name, calendly_link, widget_enabled, is_active)
         VALUES (?, ?, ?, ?, 1, 1)',
        'isss',
        [$userId, $brand, $repName, get_bot_calendly_link(null)]
    );
}

/**
 * Change password for the logged-in user.
 *
 * @return array{success: bool, message: string}
 */
function change_password(int $userId, string $currentPassword, string $newPassword, string $confirm): array
{
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters.'];
    }
    if ($newPassword !== $confirm) {
        return ['success' => false, 'message' => 'New passwords do not match.'];
    }

    $row = db_fetch('SELECT password FROM users WHERE id = ?', 'i', [$userId]);
    if (!$row || !password_verify($currentPassword, $row['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    db_execute('UPDATE users SET password = ? WHERE id = ?', 'si', [$hash, $userId]);

    return ['success' => true, 'message' => 'Password updated successfully.'];
}

/**
 * Admin: set a user's password without current password.
 *
 * @return array{success: bool, message: string}
 */
function admin_set_user_password(int $userId, string $newPassword): array
{
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    db_execute('UPDATE users SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?', 'si', [$hash, $userId]);

    return ['success' => true, 'message' => 'Password updated.'];
}

/**
 * Admin: update own profile (name + email).
 *
 * @return array{success: bool, message: string}
 */
function admin_update_own_profile(int $adminId, array $data): array
{
    $row = db_fetch('SELECT id, name, email FROM users WHERE id = ? AND role = \'admin\'', 'i', [$adminId]);
    if (!$row) {
        return ['success' => false, 'message' => 'Admin account not found.'];
    }

    $name = trim($data['name'] ?? (string) $row['name']);
    $email = filter_var(trim($data['email'] ?? (string) $row['email']), FILTER_VALIDATE_EMAIL);

    if ($name === '') {
        return ['success' => false, 'message' => 'Name is required.'];
    }
    if (!$email) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $existing = db_fetch('SELECT id FROM users WHERE email = ? AND id != ?', 'si', [$email, $adminId]);
    if ($existing) {
        return ['success' => false, 'message' => 'Another account already uses this email.'];
    }

    db_execute('UPDATE users SET name = ?, email = ? WHERE id = ?', 'ssi', [$name, $email, $adminId]);

    return ['success' => true, 'message' => 'Profile updated.'];
}

/**
 * Admin: update a client account.
 *
 * @return array{success: bool, message: string}
 */
function admin_update_client(int $clientId, array $data): array
{
    $client = db_fetch('SELECT id FROM users WHERE id = ? AND role = \'client\'', 'i', [$clientId]);
    if (!$client) {
        return ['success' => false, 'message' => 'Client not found.'];
    }

    $name = trim($data['name'] ?? '');
    $company = trim($data['company_name'] ?? '');
    $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $plan = in_array($data['subscription_plan'] ?? '', ['starter', 'pro', 'growth', 'agency', 'enterprise'], true)
        ? ($data['subscription_plan'] ?? 'starter')
        : 'starter';
    $status = in_array($data['subscription_status'] ?? '', ['active', 'trialing', 'canceled', 'past_due'], true)
        ? $data['subscription_status']
        : 'trialing';

    if ($name === '' || $company === '') {
        return ['success' => false, 'message' => 'Name and company are required.'];
    }
    if (!$email) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $existing = db_fetch('SELECT id FROM users WHERE email = ? AND id != ?', 'si', [$email, $clientId]);
    if ($existing) {
        return ['success' => false, 'message' => 'Another account already uses this email.'];
    }

    db_execute(
        'UPDATE users SET name = ?, email = ?, company_name = ?, avatar_initials = ?,
         subscription_plan = ?, subscription_status = ? WHERE id = ? AND role = \'client\'',
        'ssssssi',
        [$name, $email, $company, get_initials($name), $plan, $status, $clientId]
    );

    return ['success' => true, 'message' => 'Client account updated.'];
}

/**
 * Admin: permanently delete a client and all their data (cascades bots/leads).
 *
 * @return array{success: bool, message: string}
 */
function admin_delete_client(int $clientId): array
{
    $client = db_fetch('SELECT id, company_name FROM users WHERE id = ? AND role = \'client\'', 'i', [$clientId]);
    if (!$client) {
        return ['success' => false, 'message' => 'Client not found.'];
    }

    db_execute('DELETE FROM users WHERE id = ? AND role = \'client\'', 'i', [$clientId]);

    return ['success' => true, 'message' => 'Client "' . ($client['company_name'] ?? 'Account') . '" deleted permanently.'];
}

/**
 * Days to keep users signed in when "Remember me" is checked.
 */
function remember_me_days(): int
{
    return defined('REMEMBER_ME_DAYS') ? max(1, (int) REMEMBER_ME_DAYS) : 30;
}

/**
 * Restore session from secure remember-me cookie.
 */
function auth_try_remember_login(): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }

    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie === '' || !str_contains($cookie, ':')) {
        return;
    }

    if (!db_table_exists('user_remember_tokens')) {
        return;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') {
        return;
    }

    $row = db_fetch(
        'SELECT rt.id, rt.user_id, rt.token_hash, rt.expires_at, u.role
         FROM user_remember_tokens rt
         JOIN users u ON u.id = rt.user_id
         WHERE rt.selector = ? AND rt.expires_at > NOW()
         LIMIT 1',
        's',
        [$selector]
    );

    if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
        auth_clear_remember_cookie();
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['user_id'];
    $_SESSION['user_role'] = $row['role'];

    db_execute(
        'UPDATE user_remember_tokens SET last_used_at = NOW() WHERE id = ?',
        'i',
        [(int) $row['id']]
    );
}

/**
 * Issue a new remember-me token (replaces prior tokens for this user).
 */
function auth_issue_remember_token(int $userId): void
{
    if (!db_table_exists('user_remember_tokens')) {
        return;
    }

    auth_clear_remember_token(false);

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $days = remember_me_days();
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);

    db_execute('DELETE FROM user_remember_tokens WHERE user_id = ?', 'i', [$userId]);
    db_insert(
        'INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)',
        'isss',
        [$userId, $selector, hash('sha256', $validator), $expires]
    );

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(
        REMEMBER_COOKIE_NAME,
        $selector . ':' . $validator,
        [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

/**
 * Clear remember-me cookie and DB tokens for current user.
 */
function auth_clear_remember_token(bool $clearCookie = true): void
{
    if ($clearCookie) {
        auth_clear_remember_cookie();
    }

    if (!db_table_exists('user_remember_tokens')) {
        return;
    }

    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie !== '' && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        db_execute('DELETE FROM user_remember_tokens WHERE selector = ?', 's', [$selector]);
    } elseif (!empty($_SESSION['user_id'])) {
        db_execute('DELETE FROM user_remember_tokens WHERE user_id = ?', 'i', [(int) $_SESSION['user_id']]);
    }
}

function auth_clear_remember_cookie(): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Check if a database table exists.
 */
function db_table_exists(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            'ss',
            [DB_NAME, $table]
        );
        $cache[$table] = ((int) ($row['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}
