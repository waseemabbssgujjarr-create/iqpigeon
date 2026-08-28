<?php
/**
 * Facebook Login OAuth — sign-in / sign-up (Meta Graph API).
 */

declare(strict_types=1);

require_once __DIR__ . '/integration-settings.php';

function facebook_oauth_log(string $message, array $context = []): void
{
    $line = '[facebook-oauth] ' . $message;
    if ($context !== []) {
        $safe = $context;
        foreach (['code', 'access_token', 'state'] as $redactKey) {
            if (isset($safe[$redactKey]) && is_string($safe[$redactKey]) && $safe[$redactKey] !== '') {
                $val = $safe[$redactKey];
                $safe[$redactKey] = substr($val, 0, 8) . '…(' . strlen($val) . ' chars)';
            }
        }
        $line .= ' ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    error_log($line);

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    @file_put_contents($logDir . '/facebook-oauth.log', date('c') . ' ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function facebook_oauth_graph_version(): string
{
    require_once __DIR__ . '/integration-settings.php';
    return integration_meta_graph_api_version();
}

/** @return array{app_id: string, app_secret: string, source: string} */
function facebook_oauth_credentials(): array
{
    $fbAppId = trim(integration_config('FACEBOOK_APP_ID'));
    $fbSecret = trim(integration_config('FACEBOOK_APP_SECRET'));

    if ($fbAppId !== '' && $fbSecret !== '') {
        return ['app_id' => $fbAppId, 'app_secret' => $fbSecret, 'source' => 'facebook_dedicated'];
    }

    $meta = integration_meta_credentials();
    return [
        'app_id'     => $fbAppId !== '' ? $fbAppId : trim($meta['app_id']),
        'app_secret' => $fbSecret !== '' ? $fbSecret : trim($meta['app_secret']),
        'source'     => $fbSecret !== '' ? 'facebook_secret_meta_app_id' : 'meta_integration',
    ];
}

function facebook_oauth_app_id(): string
{
    return facebook_oauth_credentials()['app_id'];
}

function facebook_oauth_app_secret(): string
{
    return facebook_oauth_credentials()['app_secret'];
}

function facebook_oauth_configured(): bool
{
    $creds = facebook_oauth_credentials();
    return $creds['app_id'] !== '' && $creds['app_secret'] !== '';
}

/** Config ID for Facebook Login for Business (required on Business-type Meta apps). */
function facebook_oauth_login_config_id(): string
{
    $fromSettings = trim((string) (get_integration_settings()['facebook_login_config_id'] ?? ''));
    if ($fromSettings !== '') {
        return $fromSettings;
    }

    return trim(integration_config('FACEBOOK_LOGIN_CONFIG_ID'));
}

function facebook_oauth_redirect_uri(): string
{
    $uri = trim(integration_config('FACEBOOK_REDIRECT_URI'));
    if ($uri !== '') {
        return rtrim($uri, '/');
    }

    $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $base = $scheme . '://' . $host;
    }

    return $base . '/api/auth/facebook-callback.php';
}

function facebook_oauth_state_secret(): string
{
    $secret = facebook_oauth_app_secret();
    if ($secret !== '') {
        return $secret;
    }
    if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') {
        return (string) ENCRYPTION_KEY;
    }
    return 'facebook-oauth-state';
}

function facebook_oauth_build_state(string $mode = 'login'): string
{
    $payload = [
        'mode' => $mode === 'register' ? 'register' : 'login',
        'ts'   => time(),
        'n'    => bin2hex(random_bytes(8)),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $b64 = rtrim(strtr(base64_encode($json ?: ''), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, facebook_oauth_state_secret());
    return $b64 . '.' . $sig;
}

/** @return array{mode: string}|null */
function facebook_oauth_parse_state(string $state): ?array
{
    $state = trim($state);
    if ($state === '' || !str_contains($state, '.')) {
        facebook_oauth_log('State parse failed: missing dot separator', ['state_len' => strlen($state)]);
        return null;
    }

    [$b64, $sig] = explode('.', $state, 2);
    $expected = hash_hmac('sha256', $b64, facebook_oauth_state_secret());
    if (!hash_equals($expected, $sig)) {
        facebook_oauth_log('State parse failed: invalid signature');
        return null;
    }

    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    $data = json_decode($json ?: '', true);
    if (!is_array($data)) {
        facebook_oauth_log('State parse failed: invalid JSON payload');
        return null;
    }

    if ((int) ($data['ts'] ?? 0) < time() - 7200) {
        facebook_oauth_log('State parse failed: expired', ['ts' => $data['ts'] ?? null]);
        return null;
    }

    return [
        'mode' => ($data['mode'] ?? '') === 'register' ? 'register' : 'login',
    ];
}

/** @return array{ok: bool, body: string, code: int, curl_error: string} */
function facebook_oauth_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    require_once __DIR__ . '/whatsapp.php';

    $opts = [
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ];

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body ?? '';
    }

    $result = whatsapp_curl_request($url, $opts);

    if ($result['body'] !== false && $result['body'] !== '') {
        return [
            'ok'         => true,
            'body'       => (string) $result['body'],
            'code'       => (int) $result['http_code'],
            'curl_error' => '',
        ];
    }

    facebook_oauth_log('HTTP request failed', [
        'url'        => preg_replace('/client_secret=[^&]+/', 'client_secret=***', $url),
        'http_code'  => $result['http_code'] ?? 0,
        'curl_error' => $result['curl_error'] ?? '',
    ]);

    return [
        'ok'         => false,
        'body'       => is_string($result['body']) ? $result['body'] : '',
        'code'       => (int) ($result['http_code'] ?? 0),
        'curl_error' => (string) ($result['curl_error'] ?? ''),
    ];
}

/** @return array<string, mixed> */
function facebook_oauth_parse_response_body(string $body): array
{
    $body = trim($body);
    if ($body === '') {
        return [];
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
        return $json;
    }

    parse_str($body, $parsed);
    return is_array($parsed) ? $parsed : [];
}

function facebook_oauth_graph_error_message(array $data, string $fallback = 'Facebook sign-in failed.'): string
{
    if (isset($data['error']) && is_array($data['error'])) {
        return (string) ($data['error']['message'] ?? $data['error']['type'] ?? $fallback);
    }
    if (isset($data['error']) && is_string($data['error'])) {
        return $data['error'];
    }
    return (string) ($data['error_description'] ?? $data['message'] ?? $fallback);
}

function facebook_oauth_start_url(string $mode = 'login'): string
{
    if (!facebook_oauth_configured()) {
        facebook_oauth_log('Start blocked: not configured');
        return '/login.php?facebook_error=not_configured';
    }

    $creds = facebook_oauth_credentials();
    $mode = $mode === 'register' ? 'register' : 'login';
    $state = facebook_oauth_build_state($mode);
    $_SESSION['facebook_oauth_mode'] = $mode;

    $redirectUri = facebook_oauth_redirect_uri();
    $version = facebook_oauth_graph_version();

    $configId = facebook_oauth_login_config_id();
    $params = [
        'client_id'     => $creds['app_id'],
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'state'         => $state,
    ];

    // Business-type Meta apps use Facebook Login for Business — config_id replaces scope.
    if ($configId !== '') {
        $params['config_id'] = $configId;
        $params['override_default_response_type'] = 'true';
    } else {
        $params['scope'] = 'email,public_profile';
    }

    facebook_oauth_log('OAuth start', [
        'mode'         => $mode,
        'app_id'       => $creds['app_id'],
        'cred_source'  => $creds['source'],
        'redirect_uri' => $redirectUri,
        'graph_version'=> $version,
        'config_id'    => $configId !== '' ? $configId : null,
        'login_mode'   => $configId !== '' ? 'facebook_login_for_business' : 'standard_scope',
    ]);

    return 'https://www.facebook.com/' . $version . '/dialog/oauth?' . http_build_query($params);
}

/** @return array{success: bool, message: string, user?: array<string, mixed>, is_new_user?: bool, mode?: string} */
function facebook_oauth_handle_callback(string $code, string $state): array
{
    if (!facebook_oauth_configured()) {
        facebook_oauth_log('Callback blocked: not configured');
        return ['success' => false, 'message' => 'Facebook sign-in is not configured on this site. Contact support.'];
    }

    facebook_oauth_log('Callback handling start', [
        'code'         => $code,
        'state'        => $state,
        'redirect_uri' => facebook_oauth_redirect_uri(),
    ]);

    $parsedState = facebook_oauth_parse_state($state);
    if ($parsedState === null) {
        return ['success' => false, 'message' => 'Facebook sign-in session expired. Please try again.'];
    }

    $token = facebook_oauth_exchange_code($code);
    if (!$token['success']) {
        return $token;
    }

    $profile = facebook_oauth_fetch_profile($token['access_token']);
    if (!$profile['success']) {
        return $profile;
    }

    $result = facebook_oauth_login_or_register($profile['profile']);
    if ($result['success']) {
        $result['mode'] = $parsedState['mode'];
        facebook_oauth_log('Callback success', [
            'user_id'     => $result['user']['id'] ?? null,
            'is_new_user' => $result['is_new_user'] ?? false,
        ]);
    }

    return $result;
}

/** @return array{success: bool, message: string, access_token?: string} */
function facebook_oauth_exchange_code(string $code): array
{
    $creds = facebook_oauth_credentials();
    $redirectUri = facebook_oauth_redirect_uri();
    $version = facebook_oauth_graph_version();

    $postFields = http_build_query([
        'client_id'     => $creds['app_id'],
        'client_secret' => $creds['app_secret'],
        'redirect_uri'  => $redirectUri,
        'code'          => $code,
    ]);

    $urls = [
        'https://graph.facebook.com/' . $version . '/oauth/access_token',
        'https://graph.facebook.com/oauth/access_token',
    ];

    $lastMessage = 'Could not reach Facebook. Try again shortly, or use email sign-in.';

    foreach ($urls as $url) {
        facebook_oauth_log('Token exchange attempt', [
            'url'          => $url,
            'redirect_uri' => $redirectUri,
            'app_id'       => $creds['app_id'],
        ]);

        $response = facebook_oauth_http(
            $url,
            'POST',
            $postFields,
            ['Content-Type: application/x-www-form-urlencoded']
        );

        if (!$response['ok'] && $response['body'] === '') {
            $lastMessage = $response['curl_error'] !== ''
                ? 'Could not reach Facebook (' . $response['curl_error'] . ').'
                : $lastMessage;
            continue;
        }

        facebook_oauth_log('Token exchange response', [
            'http_code' => $response['code'],
            'body'      => substr($response['body'], 0, 500),
        ]);

        $data = facebook_oauth_parse_response_body($response['body']);
        if ($data === []) {
            $lastMessage = 'Invalid response from Facebook token endpoint.';
            continue;
        }

        if (!empty($data['error'])) {
            $lastMessage = facebook_oauth_graph_error_message($data, 'Facebook token exchange failed.');
            facebook_oauth_log('Token exchange API error', ['error' => $lastMessage, 'http_code' => $response['code']]);
            continue;
        }

        if (!empty($data['access_token'])) {
            return ['success' => true, 'message' => 'OK', 'access_token' => (string) $data['access_token']];
        }

        $lastMessage = 'Facebook did not return an access token.';
    }

    return ['success' => false, 'message' => $lastMessage];
}

/** @return array{success: bool, message: string, profile?: array<string, mixed>} */
function facebook_oauth_fetch_profile(string $accessToken): array
{
    $version = facebook_oauth_graph_version();
    $query = http_build_query([
        'fields'       => 'id,name,email',
        'access_token' => $accessToken,
    ]);

    $urls = [
        'https://graph.facebook.com/' . $version . '/me?' . $query,
        'https://graph.facebook.com/me?' . $query,
    ];

    foreach ($urls as $url) {
        $response = facebook_oauth_http($url);

        if (!$response['ok'] && $response['body'] === '') {
            continue;
        }

        facebook_oauth_log('Graph /me response', [
            'http_code' => $response['code'],
            'body'      => substr($response['body'], 0, 500),
        ]);

        $data = facebook_oauth_parse_response_body($response['body']);
        if (!empty($data['error'])) {
            facebook_oauth_log('Graph /me API error', ['error' => facebook_oauth_graph_error_message($data)]);
            continue;
        }

        if (!is_array($data) || empty($data['id'])) {
            continue;
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '') {
            facebook_oauth_log('Graph /me missing email', ['facebook_id' => $data['id'] ?? null]);
            return [
                'success' => false,
                'message' => 'Facebook did not provide an email address. Confirm your Facebook account has an email, grant the email permission, or sign up with email instead.',
            ];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'profile' => [
                'facebook_id' => (string) $data['id'],
                'email'       => $email,
                'name'        => trim((string) ($data['name'] ?? 'Facebook User')),
            ],
        ];
    }

    return ['success' => false, 'message' => 'Could not load Facebook profile.'];
}

/** @param array<string, mixed> $profile @return array{success: bool, message: string, user?: array<string, mixed>, is_new_user?: bool} */
function facebook_oauth_login_or_register(array $profile): array
{
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/platform-schema.php';

    ensure_oauth_schema();

    $facebookId = (string) ($profile['facebook_id'] ?? '');
    $email = (string) ($profile['email'] ?? '');
    $name = (string) ($profile['name'] ?? 'Facebook User');

    if ($facebookId === '' || $email === '') {
        return ['success' => false, 'message' => 'Incomplete Facebook profile.'];
    }

    $byFacebook = db_fetch(
        'SELECT * FROM users WHERE facebook_id = ? LIMIT 1',
        's',
        [$facebookId]
    );
    if ($byFacebook) {
        facebook_oauth_mark_provider((int) $byFacebook['id']);
        return facebook_oauth_finalize_session($byFacebook, false);
    }

    $byEmail = db_fetch('SELECT * FROM users WHERE email = ? LIMIT 1', 's', [$email]);
    if ($byEmail) {
        if (($byEmail['role'] ?? '') === 'admin') {
            return ['success' => false, 'message' => 'Use admin sign-in for this account.'];
        }
        if (db_column_exists('users', 'facebook_id')) {
            db_execute('UPDATE users SET facebook_id = ? WHERE id = ?', 'si', [$facebookId, (int) $byEmail['id']]);
        }
        facebook_oauth_mark_provider((int) $byEmail['id']);
        $byEmail['facebook_id'] = $facebookId;
        return facebook_oauth_finalize_session($byEmail, false);
    }

    $company = $name . ' Co';
    $initials = get_initials($name);
    $trialEnds = date('Y-m-d H:i:s', strtotime('+' . TRIAL_DAYS . ' days'));
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);

    $cols = 'name, email, password, role, company_name, avatar_initials, subscription_plan, subscription_status, trial_ends_at';
    $vals = '?, ?, ?, \'client\', ?, ?, \'starter\', \'trialing\', ?';
    $types = 'ssssss';
    $params = [$name, $email, $hash, $company, $initials, $trialEnds];

    if (db_column_exists('users', 'facebook_id')) {
        $cols .= ', facebook_id';
        $vals .= ', ?';
        $types .= 's';
        $params[] = $facebookId;
    }

    if (db_column_exists('users', 'auth_provider')) {
        $cols .= ', auth_provider';
        $vals .= ', ?';
        $types .= 's';
        $params[] = 'facebook';
    }

    try {
        $userId = db_insert("INSERT INTO users ({$cols}) VALUES ({$vals})", $types, $params);
    } catch (Throwable $e) {
        facebook_oauth_log('User insert failed', ['exception' => $e->getMessage()]);
        return ['success' => false, 'message' => 'Account could not be created. Please try again.'];
    }

    if (db_column_exists('users', 'email_verified_at')) {
        db_mark_email_verified($userId);
    }

    email_admin_new_client($name, $email, $company);

    $user = db_fetch('SELECT * FROM users WHERE id = ?', 'i', [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'Account could not be created.'];
    }

    return facebook_oauth_finalize_session($user, true);
}

function facebook_oauth_mark_provider(int $userId): void
{
    if (!db_column_exists('users', 'auth_provider')) {
        return;
    }
    db_execute(
        'UPDATE users SET auth_provider = \'facebook\' WHERE id = ? AND (auth_provider IS NULL OR auth_provider = \'\')',
        'i',
        [$userId]
    );
}

/** @param array<string, mixed> $user @return array{success: bool, message: string, user: array<string, mixed>, is_new_user: bool} */
function facebook_oauth_finalize_session(array $user, bool $isNewUser): array
{
    if (($user['role'] ?? '') === 'admin') {
        return ['success' => false, 'message' => 'Use admin sign-in for this account.'];
    }

    if (db_column_exists('users', 'email_verified_at') && empty($user['email_verified_at'])) {
        db_mark_email_verified((int) $user['id']);
        $user['email_verified_at'] = date('Y-m-d H:i:s');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = 'client';
    auth_issue_remember_token((int) $user['id']);

    return [
        'success'     => true,
        'message'     => $isNewUser ? 'Account created.' : 'Signed in.',
        'user'        => $user,
        'is_new_user' => $isNewUser,
    ];
}

/** @return array{app_id: string, app_secret_set: bool, redirect_uri: string, cred_source: string} */
function facebook_oauth_debug_status(): array
{
    $creds = facebook_oauth_credentials();
    return [
        'app_id'          => $creds['app_id'],
        'app_secret_set'  => $creds['app_secret'] !== '',
        'redirect_uri'    => facebook_oauth_redirect_uri(),
        'cred_source'     => $creds['source'],
        'login_config_id' => facebook_oauth_login_config_id(),
        'login_mode'      => facebook_oauth_login_config_id() !== '' ? 'facebook_login_for_business' : 'standard_scope',
    ];
}
