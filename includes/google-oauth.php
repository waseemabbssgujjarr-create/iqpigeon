<?php

/**

 * Google OAuth 2.0 sign-in / sign-up.

 */



declare(strict_types=1);



require_once __DIR__ . '/integration-settings.php';



function google_oauth_is_valid_client_id(string $clientId): bool

{

    $clientId = trim($clientId);

    return $clientId !== ''

        && preg_match('/^\d+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $clientId) === 1;

}



function google_oauth_configured(): bool

{

    return google_oauth_is_valid_client_id(integration_config('GOOGLE_CLIENT_ID'))

        && trim(integration_config('GOOGLE_CLIENT_SECRET')) !== '';

}



function google_oauth_redirect_uri(): string

{

    $uri = integration_config('GOOGLE_REDIRECT_URI');

    if ($uri !== '') {

        return rtrim($uri, '/');

    }

    return rtrim(APP_URL, '/') . '/api/auth/google-callback.php';

}



function google_oauth_state_secret(): string

{

    $secret = integration_config('GOOGLE_CLIENT_SECRET');

    if ($secret !== '') {

        return $secret;

    }

    if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') {

        return (string) ENCRYPTION_KEY;

    }



    return 'google-oauth-state';

}



function google_oauth_build_state(string $mode = 'login'): string

{

    $payload = [

        'mode' => $mode === 'register' ? 'register' : 'login',

        'ts'   => time(),

        'n'    => bin2hex(random_bytes(8)),

    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $b64 = rtrim(strtr(base64_encode($json ?: ''), '+/', '-_'), '=');

    $sig = hash_hmac('sha256', $b64, google_oauth_state_secret());



    return $b64 . '.' . $sig;

}



/**

 * @return array{mode: string}|null

 */

function google_oauth_parse_state(string $state): ?array

{

    $state = trim($state);

    if ($state === '' || !str_contains($state, '.')) {

        return null;

    }



    [$b64, $sig] = explode('.', $state, 2);

    $expected = hash_hmac('sha256', $b64, google_oauth_state_secret());

    if (!hash_equals($expected, $sig)) {

        return null;

    }



    $json = base64_decode(strtr($b64, '-_', '+/'), true);

    $data = json_decode($json ?: '', true);

    if (!is_array($data)) {

        return null;

    }



    if ((int) ($data['ts'] ?? 0) < time() - 7200) {

        return null;

    }



    return [

        'mode' => ($data['mode'] ?? '') === 'register' ? 'register' : 'login',

    ];

}



/**

 * @return array{ok: bool, body: string, code: int}

 */

function google_oauth_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    require_once __DIR__ . '/whatsapp.php';

    $opts = [
        CURLOPT_TIMEOUT    => 25,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body ?? '';
    }

    $result = whatsapp_curl_request($url, $opts);

    if ($result['ok'] && $result['body'] !== false && $result['body'] !== '') {
        return [
            'ok'   => true,
            'body' => (string) $result['body'],
            'code' => (int) $result['http_code'],
        ];
    }

    if (!empty($result['curl_error'])) {
        error_log('Google OAuth HTTP failed: ' . $result['curl_error'] . ' url=' . $url);
    }

    return ['ok' => false, 'body' => '', 'code' => (int) ($result['http_code'] ?? 0)];
}



function google_oauth_start_url(string $mode = 'login'): string

{

    if (!google_oauth_configured()) {

        return '/login.php?google_error=not_configured';

    }



    $mode = $mode === 'register' ? 'register' : 'login';

    $state = google_oauth_build_state($mode);

    $_SESSION['google_oauth_mode'] = $mode;



    $params = [

        'client_id'     => integration_config('GOOGLE_CLIENT_ID'),

        'redirect_uri'  => google_oauth_redirect_uri(),

        'response_type' => 'code',

        'scope'         => 'openid email profile',

        'state'         => $state,

        'access_type'   => 'online',

        'prompt'        => 'select_account',

    ];



    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

}



/**

 * @return array{success: bool, message: string, user?: array<string, mixed>, is_new_user?: bool, mode?: string}

 */

function google_oauth_handle_callback(string $code, string $state): array

{

    if (!google_oauth_configured()) {

        return ['success' => false, 'message' => 'Google sign-in is not configured on this site. Contact support.'];

    }



    $parsedState = google_oauth_parse_state($state);

    if ($parsedState === null) {

        return ['success' => false, 'message' => 'Google sign-in session expired. Please try again.'];

    }



    $token = google_oauth_exchange_code($code);

    if (!$token['success']) {

        return $token;

    }



    $profile = google_oauth_fetch_profile($token['access_token']);

    if (!$profile['success']) {

        return $profile;

    }



    $result = google_oauth_login_or_register($profile['profile']);

    if ($result['success']) {

        $result['mode'] = $parsedState['mode'];

    }



    return $result;

}



/**

 * @return array{success: bool, message: string, access_token?: string}

 */

function google_oauth_exchange_code(string $code): array

{

    $response = google_oauth_http(

        'https://oauth2.googleapis.com/token',

        'POST',

        http_build_query([

            'code'          => $code,

            'client_id'     => integration_config('GOOGLE_CLIENT_ID'),

            'client_secret' => integration_config('GOOGLE_CLIENT_SECRET'),

            'redirect_uri'  => google_oauth_redirect_uri(),

            'grant_type'    => 'authorization_code',

        ]),

        ['Content-Type: application/x-www-form-urlencoded']

    );



    if (!$response['ok'] || $response['body'] === '') {

        return [
            'success' => false,
            'message' => 'Could not reach Google. Your server may block outbound HTTPS (DNS issue on Hostinger). Try again shortly, or use email sign-in.',
        ];

    }



    $data = json_decode($response['body'], true);

    if (!is_array($data)) {

        return ['success' => false, 'message' => 'Invalid response from Google.'];

    }



    if (!empty($data['error'])) {

        $err = (string) ($data['error'] ?? '');

        if ($err === 'invalid_client') {

            return [

                'success' => false,

                'message' => 'Google OAuth is misconfigured (invalid client). Set Client ID and Secret in Admin → Integrations, and add the redirect URI below in Google Cloud Console.',

            ];

        }

        return [

            'success' => false,

            'message' => (string) ($data['error_description'] ?? $data['error'] ?? 'Google sign-in failed.'),

        ];

    }



    if (empty($data['access_token'])) {

        return ['success' => false, 'message' => 'Google did not return an access token.'];

    }



    return ['success' => true, 'message' => 'OK', 'access_token' => (string) $data['access_token']];

}



/**

 * @return array{success: bool, message: string, profile?: array<string, mixed>}

 */

function google_oauth_fetch_profile(string $accessToken): array

{

    $response = google_oauth_http(

        'https://www.googleapis.com/oauth2/v3/userinfo',

        'GET',

        null,

        ['Authorization: Bearer ' . $accessToken]

    );



    if (!$response['ok']) {

        return ['success' => false, 'message' => 'Could not load Google profile.'];

    }



    $data = json_decode($response['body'], true);

    if (!is_array($data) || empty($data['email'])) {

        return ['success' => false, 'message' => 'Google did not provide an email address.'];

    }



    return [

        'success' => true,

        'message' => 'OK',

        'profile' => [

            'google_id'      => (string) ($data['sub'] ?? ''),

            'email'          => strtolower(trim((string) $data['email'])),

            'name'           => trim((string) ($data['name'] ?? 'Google User')),

            'email_verified' => !empty($data['email_verified']),

        ],

    ];

}



/**

 * @param array<string, mixed> $profile

 * @return array{success: bool, message: string, user?: array<string, mixed>, is_new_user?: bool}

 */

function google_oauth_login_or_register(array $profile): array

{

    require_once __DIR__ . '/auth.php';

    require_once __DIR__ . '/mailer.php';

    require_once __DIR__ . '/platform-schema.php';



    ensure_oauth_schema();



    $googleId = (string) ($profile['google_id'] ?? '');

    $email = (string) ($profile['email'] ?? '');

    $name = (string) ($profile['name'] ?? 'Google User');



    if ($googleId === '' || $email === '') {

        return ['success' => false, 'message' => 'Incomplete Google profile.'];

    }



    $byGoogle = db_fetch(

        'SELECT * FROM users WHERE google_id = ? LIMIT 1',

        's',

        [$googleId]

    );

    if ($byGoogle) {

        return google_oauth_finalize_session($byGoogle, false);

    }



    $byEmail = db_fetch('SELECT * FROM users WHERE email = ? LIMIT 1', 's', [$email]);

    if ($byEmail) {

        if (($byEmail['role'] ?? '') === 'admin') {

            return ['success' => false, 'message' => 'Use admin sign-in for this account.'];

        }

        if (db_column_exists('users', 'google_id')) {

            db_execute('UPDATE users SET google_id = ? WHERE id = ?', 'si', [$googleId, (int) $byEmail['id']]);

        }

        $byEmail['google_id'] = $googleId;

        return google_oauth_finalize_session($byEmail, false);

    }



    $company = $name . ' Co';

    $initials = get_initials($name);

    $trialEnds = date('Y-m-d H:i:s', strtotime('+' . TRIAL_DAYS . ' days'));

    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);



    $cols = 'name, email, password, role, company_name, avatar_initials, subscription_plan, subscription_status, trial_ends_at';

    $vals = '?, ?, ?, \'client\', ?, ?, \'starter\', \'trialing\', ?';

    $types = 'ssssss';

    $params = [$name, $email, $hash, $company, $initials, $trialEnds];



    if (db_column_exists('users', 'google_id')) {

        $cols .= ', google_id';

        $vals .= ', ?';

        $types .= 's';

        $params[] = $googleId;

    }



    $userId = db_insert("INSERT INTO users ({$cols}) VALUES ({$vals})", $types, $params);



    if (db_column_exists('users', 'email_verified_at')) {

        db_mark_email_verified($userId);

    }



    email_admin_new_client($name, $email, $company);



    $user = db_fetch('SELECT * FROM users WHERE id = ?', 'i', [$userId]);

    if (!$user) {

        return ['success' => false, 'message' => 'Account could not be created.'];

    }



    return google_oauth_finalize_session($user, true);

}



/**

 * @param array<string, mixed> $user

 * @return array{success: bool, message: string, user: array<string, mixed>, is_new_user: bool}

 */

function google_oauth_finalize_session(array $user, bool $isNewUser): array

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


