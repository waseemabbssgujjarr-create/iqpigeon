<?php
/**
 * Meta Embedded Signup — OAuth redirect + token exchange helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/integration-settings.php';

function whatsapp_oauth_redirect_uri(): string
{
    require_once __DIR__ . '/domain.php';
    $base = is_allowed_host() ? request_base_url() : app_canonical_url();

    // Meta dashboard usually registers without .php (launch-check canonical form).
    return rtrim($base, '/') . '/client/whatsapp-oauth-callback';
}

/**
 * Redirect URIs to try during token exchange (must match Meta app registration).
 *
 * @return list<string>
 */
function whatsapp_oauth_redirect_uri_candidates(): array
{
    require_once __DIR__ . '/domain.php';

    $candidates = [
        whatsapp_oauth_redirect_uri(),
        rtrim(app_canonical_url(), '/') . '/client/whatsapp-oauth-callback.php',
        rtrim(app_canonical_url(), '/') . '/client/whatsapp-oauth-callback',
    ];

    return array_values(array_unique(array_filter($candidates)));
}

function whatsapp_oauth_start_url(int $clientId, string $returnPath = '', bool $popup = false): string
{
    $url = '/client/whatsapp-oauth-start?client_id=' . $clientId;
    $returnPath = trim($returnPath);
    if ($returnPath !== '' && str_starts_with($returnPath, '/') && !str_starts_with($returnPath, '//')) {
        $url .= '&return=' . rawurlencode($returnPath);
    }
    if ($popup) {
        $url .= '&popup=1';
    }

    return $url;
}

/**
 * Safe redirect target after OAuth (must stay on this site).
 */
function whatsapp_oauth_normalize_return(string $returnPath, string $fallback = '/client/whatsapp-settings'): string
{
    $returnPath = trim($returnPath);
    if ($returnPath === '' || !str_starts_with($returnPath, '/') || str_starts_with($returnPath, '//')) {
        return $fallback;
    }
    if (!preg_match('#^/[a-zA-Z0-9/_\-.\?=&%]+$#', $returnPath)) {
        return $fallback;
    }

    return $returnPath;
}

function whatsapp_oauth_return_with_query(string $returnPath, array $params): string
{
    $sep = str_contains($returnPath, '?') ? '&' : '?';

    return $returnPath . $sep . http_build_query($params);
}

/** postMessage type sent from oauth callback popup to parent page. */
function whatsapp_oauth_popup_message_type(): string
{
    return 'iqpigeon-whatsapp-oauth';
}

/**
 * Finish OAuth — redirect full page or postMessage + close popup.
 *
 * @param array<string, scalar> $params e.g. connected=1 or error=...
 */
function whatsapp_oauth_finish_flow(string $returnPath, array $params, bool $popup): never
{
    if ($popup) {
        $success = isset($params['connected']) && (string) $params['connected'] === '1';
        $error = (string) ($params['error'] ?? '');
        whatsapp_oauth_render_popup_finish($success, $error, $returnPath);
    }

    require_once __DIR__ . '/helpers.php';
    if (is_mobile_client() || is_native_app()) {
        whatsapp_oauth_render_mobile_finish($returnPath, $params);
    }

    redirect(whatsapp_oauth_return_with_query($returnPath, $params));
}

/**
 * Mobile / WebView: HTML page with auto-redirect (PHP redirect alone often fails after Meta app handoff).
 *
 * @param array<string, scalar> $params
 */
function whatsapp_oauth_render_mobile_finish(string $returnPath, array $params): never
{
    require_once __DIR__ . '/domain.php';
    $returnPath = whatsapp_oauth_normalize_return($returnPath, '/client/dashboard?welcome=1');
    $dest = whatsapp_oauth_return_with_query($returnPath, $params);
    $success = isset($params['connected']) && (string) $params['connected'] === '1';
    $error = trim((string) ($params['error'] ?? ''));

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta http-equiv="refresh" content="1;url=' . htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') . '">';
    echo '<title>' . ($success ? 'WhatsApp connected' : 'WhatsApp connection') . '</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0b141a;color:#e9edef;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center}'
        . 'a{color:#25d366}</style></head><body>';
    if ($success) {
        echo '<div><p style="font-size:1.25rem;margin:0 0 12px">WhatsApp connected</p>';
        echo '<p style="opacity:.85">Returning to IQ Pigeon…</p>';
    } else {
        echo '<div><p style="font-size:1.25rem;margin:0 0 12px">Connection issue</p>';
        echo '<p style="opacity:.85">' . htmlspecialchars($error !== '' ? $error : 'Please try Connect again.', ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '<p style="margin-top:20px"><a href="' . htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') . '">Continue</a></p></div>';
    echo '<script>(function(){var d=' . json_encode($dest) . ';'
        . 'try{sessionStorage.removeItem("wa_oauth_pending");}catch(e){}'
        . 'setTimeout(function(){window.location.replace(d);},400);'
        . 'setTimeout(function(){window.location.href=d;},1800);'
        . '})();</script></body></html>';

    exit;
}

/**
 * HTML page in OAuth popup: notify opener via postMessage, then close (fallback redirect).
 */
function whatsapp_oauth_render_popup_finish(bool $success, string $error, string $returnPath): never
{
    require_once __DIR__ . '/domain.php';
    $returnPath = whatsapp_oauth_normalize_return($returnPath);
    $fallbackUrl = whatsapp_oauth_return_with_query(
        $returnPath,
        $success ? ['connected' => '1'] : ['error' => $error !== '' ? $error : 'Connection failed']
    );
    $messageType = whatsapp_oauth_popup_message_type();
    $origins = array_values(array_unique(array_filter([
        rtrim(app_canonical_url(), '/'),
        rtrim(request_base_url(), '/'),
    ])));

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
        . ($success ? 'WhatsApp connected' : 'WhatsApp connection')
        . '</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center">';
    echo $success
        ? '<p>WhatsApp connected. Closing&hellip;</p>'
        : '<p>' . htmlspecialchars($error !== '' ? $error : 'Connection failed. Closing&hellip;', ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<script>(function(){'
        . 'var payload={type:' . json_encode($messageType) . ',success:' . ($success ? 'true' : 'false')
        . ',error:' . json_encode($error) . '};'
        . 'var origins=' . json_encode($origins) . ';'
        . 'var fallback=' . json_encode($fallbackUrl) . ';'
        . 'function notifyOpener(){'
        . 'if(!window.opener||window.opener.closed){return false;}'
        . 'origins.forEach(function(o){try{window.opener.postMessage(payload,o);}catch(e){}});'
        . 'try{window.opener.postMessage(payload,window.location.origin);}catch(e2){}'
        . 'return true;'
        . '}'
        . 'if(notifyOpener()){'
        . 'var n=0;var t=setInterval(function(){if(n++>8){clearInterval(t);try{window.close();}catch(e){}}notifyOpener();},400);'
        . 'setTimeout(function(){try{window.close();}catch(e){}window.location.href=fallback;},3500);'
        . '}else{window.location.href=fallback;}'
        . '})();</script></body></html>';

    exit;
}

function whatsapp_oauth_state_secret(): string
{
    $secret = whatsapp_meta_app_secret();
    if ($secret !== '') {
        return $secret;
    }

    return defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : 'whatsapp-oauth-state';
}

/**
 * Signed OAuth state — survives Meta redirect even if PHP session is lost.
 */
function whatsapp_oauth_build_state(int $clientId, string $returnPath, bool $popup = false): string
{
    $payload = [
        'cid' => $clientId,
        'ret' => whatsapp_oauth_normalize_return($returnPath),
        'ts'  => time(),
        'n'   => bin2hex(random_bytes(8)),
    ];
    if ($popup) {
        $payload['pop'] = 1;
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $b64 = rtrim(strtr(base64_encode($json ?: ''), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, whatsapp_oauth_state_secret());

    return $b64 . '.' . $sig;
}

/**
 * @return array{client_id: int, return: string, popup: bool}|null
 */
function whatsapp_oauth_parse_state(string $state): ?array
{
    $state = trim($state);
    if ($state === '' || !str_contains($state, '.')) {
        return null;
    }

    [$b64, $sig] = explode('.', $state, 2);
    $expected = hash_hmac('sha256', $b64, whatsapp_oauth_state_secret());
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    $data = json_decode($json ?: '', true);
    if (!is_array($data) || empty($data['cid'])) {
        return null;
    }

    if ((int) ($data['ts'] ?? 0) < time() - 7200) {
        return null;
    }

    return [
        'client_id' => (int) $data['cid'],
        'return'    => whatsapp_oauth_normalize_return((string) ($data['ret'] ?? '')),
        'popup'     => !empty($data['pop']),
    ];
}

/**
 * Resolve logged-in user on OAuth callback — session often drops after Meta redirect (mobile / in-app browser).
 *
 * @return array{user: array<string, mixed>, client_id: int}|null
 */
function whatsapp_oauth_callback_user(string $state): ?array
{
    $parsedState = whatsapp_oauth_parse_state($state);
    $sessionUser = get_user();

    if ($parsedState !== null) {
        $clientId = (int) $parsedState['client_id'];
        if ($clientId <= 0) {
            return null;
        }

        if ($sessionUser !== null) {
            if ((int) $sessionUser['id'] !== $clientId && ($sessionUser['role'] ?? '') !== 'admin') {
                return null;
            }

            return ['user' => $sessionUser, 'client_id' => $clientId];
        }

        $user = db_fetch('SELECT id, name, email, role, company_name FROM users WHERE id = ?', 'i', [$clientId]);
        if (!$user) {
            return null;
        }

        $_SESSION['user_id'] = $clientId;

        return ['user' => $user, 'client_id' => $clientId];
    }

    if ($sessionUser === null) {
        return null;
    }

    return ['user' => $sessionUser, 'client_id' => (int) $sessionUser['id']];
}

function whatsapp_ensure_embedded_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/migrations/whatsapp_tables.php';
    run_whatsapp_embedded_signup_migration();
}

/**
 * True when client has an active Embedded Signup account saved.
 */
function whatsapp_client_embedded_connected(int $clientId): bool
{
    whatsapp_ensure_embedded_schema();
    $row = db_fetch(
        'SELECT id FROM client_whatsapp_accounts WHERE client_id = ? AND connection_status = \'active\' LIMIT 1',
        'i',
        [$clientId]
    );

    return $row !== null;
}

/**
 * Copy saved Embedded Signup token onto all user bots (Bot Setup reads bots table).
 *
 * @param string|null $plainToken Skip DB decrypt when caller already has the plain token.
 */
function whatsapp_sync_embedded_account_to_bots(int $clientId, ?string $plainToken = null): bool
{
    whatsapp_ensure_embedded_schema();
    require_once __DIR__ . '/whatsapp-token.php';

    $account = db_fetch(
        'SELECT * FROM client_whatsapp_accounts
         WHERE client_id = ? AND connection_status = \'active\'
         ORDER BY connected_at DESC LIMIT 1',
        'i',
        [$clientId]
    );
    if (!$account) {
        return false;
    }

    if ($plainToken === null || $plainToken === '') {
        $plainToken = whatsapp_client_access_token($clientId, true);
        if ($plainToken === false || $plainToken === '') {
            return false;
        }
    }

    $encrypted = encrypt_token($plainToken);
    $bots = db_fetch_all('SELECT id FROM bots WHERE user_id = ?', 'i', [$clientId]);
    foreach ($bots as $row) {
        db_execute(
            'UPDATE bots SET whatsapp_phone_id = ?, whatsapp_token = ?, whatsapp_verified = 1, whatsapp_token_error = NULL WHERE id = ?',
            'ssi',
            [(string) $account['phone_number_id'], $encrypted, (int) $row['id']]
        );
    }

    return $bots !== [];
}

/**
 * Meta Cloud API sandbox numbers (+1 555…) are not reachable from the WhatsApp consumer app.
 */
function whatsapp_is_meta_test_number(?string $displayNumber): bool
{
    $raw = trim((string) $displayNumber);
    if ($raw === '') {
        return false;
    }
    $digits = preg_replace('/\D/', '', $raw);

    return str_starts_with($digits, '1555') || str_contains($raw, '555-');
}

/**
 * WhatsApp message counts for billing / usage (this calendar month).
 *
 * @return array{outbound: int, inbound: int, total: int}
 */
function client_whatsapp_usage_stats(int $clientId): array
{
    whatsapp_ensure_embedded_schema();

    $out = (int) (db_fetch(
        "SELECT COUNT(*) AS cnt FROM whatsapp_messages_log
         WHERE client_id = ? AND direction = 'outbound'
           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
        'i',
        [$clientId]
    )['cnt'] ?? 0);

    $in = (int) (db_fetch(
        "SELECT COUNT(*) AS cnt FROM whatsapp_messages_log
         WHERE client_id = ? AND direction = 'inbound'
           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
        'i',
        [$clientId]
    )['cnt'] ?? 0);

    return [
        'outbound' => $out,
        'inbound'  => $in,
        'total'    => $out + $in,
    ];
}

/**
 * Log inbound/outbound to whatsapp_messages_log (used by webhook + sender).
 */
function whatsapp_log_message_for_client(
    int $clientId,
    string $phoneNumberId,
    string $direction,
    ?string $fromNumber,
    ?string $toNumber,
    ?string $messageBody,
    ?string $waMessageId = null,
    string $status = 'received'
): void {
    whatsapp_ensure_embedded_schema();

    try {
        db_insert(
            'INSERT INTO whatsapp_messages_log
             (client_id, phone_number_id, direction, wa_message_id, from_number, to_number, message_body, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'isssssss',
            [
                $clientId,
                $phoneNumberId,
                $direction,
                $waMessageId,
                $fromNumber,
                $toNumber,
                $messageBody,
                $status,
            ]
        );
    } catch (Throwable $e) {
        error_log('whatsapp_log_message_for_client: ' . $e->getMessage());
    }
}

/**
 * Sync embedded account to bots and ensure WABA is subscribed for inbound webhooks.
 *
 * @return array{
 *   ok: bool,
 *   bot_linked: bool,
 *   bot_active: bool,
 *   waba_subscribed: bool,
 *   phone_number_id: string,
 *   bot_id: int,
 *   issues: string[]
 * }
 */
function whatsapp_ensure_client_inbound_ready(int $clientId): array
{
    require_once __DIR__ . '/whatsapp-token.php';

    $result = [
        'ok'              => false,
        'bot_linked'      => false,
        'bot_active'      => false,
        'waba_subscribed' => false,
        'phone_number_id' => '',
        'bot_id'          => 0,
        'issues'          => [],
    ];

    whatsapp_sync_embedded_account_to_bots($clientId);

    $account = db_fetch(
        'SELECT * FROM client_whatsapp_accounts
         WHERE client_id = ? AND connection_status = \'active\'
         ORDER BY connected_at DESC LIMIT 1',
        'i',
        [$clientId]
    );
    if (!$account) {
        $result['issues'][] = 'No active WhatsApp account — connect in Bot Setup → Channels.';

        return $result;
    }

    $phoneNumberId = (string) ($account['phone_number_id'] ?? '');
    $wabaId = (string) ($account['waba_id'] ?? '');
    $result['phone_number_id'] = $phoneNumberId;

    $bot = db_fetch(
        'SELECT id, is_active, whatsapp_verified FROM bots
         WHERE user_id = ? AND whatsapp_phone_id = ? LIMIT 1',
        'is',
        [$clientId, $phoneNumberId]
    );

    if (!$bot) {
        $result['issues'][] = 'Bot is not linked to this Phone Number ID — open Bot Setup → Channels once to sync.';
    } else {
        $result['bot_linked'] = true;
        $result['bot_id'] = (int) $bot['id'];
        $result['bot_active'] = (int) ($bot['is_active'] ?? 0) === 1;
        if (!$result['bot_active']) {
            $result['issues'][] = 'Bot is disabled — enable it on the dashboard.';
        }
        if (empty($bot['whatsapp_verified']) && !bot_whatsapp_token_is_healthy($bot)) {
            $result['issues'][] = 'WhatsApp token not verified on bot — disconnect and connect WhatsApp again.';
        }
    }

    $plainToken = whatsapp_client_access_token($clientId, true);
    if ($plainToken === false || $plainToken === '') {
        $result['issues'][] = 'Could not read WhatsApp access token — disconnect and connect WhatsApp again (encryption key may have changed).';

        return $result;
    }

    if ($wabaId !== '') {
        $subStatus = whatsapp_waba_subscription_status($wabaId, $plainToken);
        $result['waba_subscribed'] = !empty($subStatus['subscribed']);
        if (!$result['waba_subscribed']) {
            $subscribe = whatsapp_subscribe_waba_to_app($wabaId, $plainToken);
            $result['waba_subscribed'] = !empty($subscribe['success']);
            if (!$result['waba_subscribed']) {
                $result['issues'][] = 'WABA not subscribed to app for inbound webhooks — '
                    . ($subscribe['error'] ?? 'reconnect WhatsApp or contact support.');
            }
        }
    } else {
        $result['issues'][] = 'WABA ID missing — reconnect WhatsApp.';
    }

    $result['ok'] = $result['bot_linked'] && $result['bot_active'] && $result['waba_subscribed'] && $result['issues'] === [];

    return $result;
}

function whatsapp_oauth_app_host(): string
{
    $url = defined('APP_URL') && APP_URL !== '' ? APP_URL : app_canonical_url();

    return (string) (parse_url($url, PHP_URL_HOST) ?: 'iqpigeon.com');
}

function whatsapp_oauth_is_domain_error(string $error): bool
{
    $lower = strtolower($error);
    foreach (['domain', 'app domains', "can't load url", 'redirect uri', 'not included'] as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }

    return false;
}

function whatsapp_oauth_is_secret_error(string $error): bool
{
    $lower = strtolower($error);

    return str_contains($lower, 'client secret')
        || str_contains($lower, 'validating client secret');
}

function whatsapp_oauth_friendly_error(string $error): string
{
    $lower = strtolower($error);

    if (whatsapp_oauth_is_secret_error($error)) {
        return 'Meta App Secret does not match App ID. In Admin → Integrations, set App ID '
            . whatsapp_meta_app_id() . ' and re-enter the App Secret from Meta → Settings → Basic, then Save. '
            . 'Or set META_APP_SECRET in config.local.php on the server.';
    }

    if (str_contains($lower, 'redirect_uri') || str_contains($lower, 'redirect uri')) {
        return 'OAuth redirect URI mismatch. In Meta → Facebook Login → Settings, add: '
            . whatsapp_oauth_redirect_uri()
            . ' — then try Connect again.';
    }

    if (str_contains($lower, 'code') && (str_contains($lower, 'expired') || str_contains($lower, 'invalid'))) {
        return 'Signup code expired. Click Connect WhatsApp again and click Finish in Meta without using browser Back.';
    }

    if (str_contains($lower, 'has been used')) {
        return 'Signup code already used. Click Connect WhatsApp again for a fresh code — do not refresh the callback page.';
    }

    if ($error === '' || $error === 'Meta token exchange failed') {
        return 'Meta token exchange failed. Check Admin → Integrations: App ID must be '
            . whatsapp_meta_app_id()
            . ' and App Secret must match Meta → Settings → Basic. Also add OAuth redirect: '
            . whatsapp_oauth_redirect_uri();
    }

    return $error;
}

/**
 * Embedded Signup extras for Coexistence (existing WhatsApp Business App numbers).
 *
 * @return array<string, mixed>
 */
function whatsapp_embedded_signup_extras(): array
{
    return [
        'setup'              => (object) [],
        'featureType'        => 'whatsapp_business_app_onboarding',
        'sessionInfoVersion' => '3',
        'version'            => 'v4',
    ];
}

function whatsapp_embedded_signup_extras_json(): string
{
    return json_encode(whatsapp_embedded_signup_extras(), JSON_UNESCAPED_SLASHES) ?: '{}';
}

/**
 * Static Meta onboard URL (admin display / manual open — no OAuth return).
 */
function whatsapp_embedded_onboard_url(): string
{
    $appId = whatsapp_meta_app_id();
    $configId = integration_config('META_CONFIG_ID');

    return 'https://business.facebook.com/messaging/whatsapp/onboard/?'
        . http_build_query([
            'app_id'    => $appId,
            'config_id' => $configId,
            'extras'    => whatsapp_embedded_signup_extras_json(),
        ]);
}

/**
 * Embedded Signup launch URL (popup and full-page).
 * Uses business.facebook.com onboard — correct path for WhatsApp Embedded Signup.
 */
function whatsapp_oauth_launch_url(string $state, bool $popup = false): string
{
    unset($popup);

    $params = [
        'app_id'                         => whatsapp_meta_app_id(),
        'config_id'                      => integration_config('META_CONFIG_ID'),
        'extras'                         => whatsapp_embedded_signup_extras_json(),
        'redirect_uri'                   => whatsapp_oauth_redirect_uri(),
        'state'                          => $state,
        'response_type'                  => 'code',
        'override_default_response_type' => 'true',
    ];

    return 'https://business.facebook.com/messaging/whatsapp/onboard/?' . http_build_query($params);
}

/**
 * Legacy facebook.com/dialog/oauth URL — fallback only; often blocked until email/manage_app_solution approved.
 */
function whatsapp_oauth_authorize_url(string $state): string
{
    $params = [
        'client_id'                      => whatsapp_meta_app_id(),
        'redirect_uri'                   => whatsapp_oauth_redirect_uri(),
        'response_type'                  => 'code',
        'config_id'                      => integration_config('META_CONFIG_ID'),
        'override_default_response_type' => 'true',
        'extras'                         => whatsapp_embedded_signup_extras_json(),
        'state'                          => $state,
    ];

    return 'https://www.facebook.com/' . integration_meta_graph_api_version() . '/dialog/oauth?' . http_build_query($params);
}

/**
 * Exchange OAuth authorization code for a user access token (Meta Graph API).
 *
 * @param string $exchangeMode 'redirect' = code from oauth callback URL; 'sdk' = code from FB.login (no redirect_uri)
 * @return array{success: bool, data?: array<string, mixed>, error?: string}
 */
function whatsapp_meta_exchange_oauth_code(string $code, string $redirectUri = '', string $exchangeMode = 'redirect'): array
{
    $creds = integration_meta_credentials();
    if ($creds['app_id'] === '') {
        return ['success' => false, 'error' => 'WhatsApp signup is not configured (Meta App ID missing).'];
    }
    if ($creds['app_secret'] === '') {
        return [
            'success' => false,
            'error'   => 'Meta App Secret is missing on this server. Set META_APP_SECRET in config.local.php or Admin → Integrations (App ID '
                . $creds['app_id'] . ').',
        ];
    }

    $verify = whatsapp_meta_verify_credentials_pair($creds['app_id'], $creds['app_secret']);
    if (!$verify['success']) {
        return [
            'success' => false,
            'error'   => 'Meta rejected App ID + Secret ('
                . ($verify['error'] ?? 'invalid')
                . '). In Admin → Integrations set App ID 552479924130015 and paste a fresh App Secret from Meta → Settings → Basic, then Save.',
        ];
    }

    $url = 'https://graph.facebook.com/' . integration_meta_graph_api_version() . '/oauth/access_token';

    $redirectUris = [];
    if ($redirectUri !== '') {
        $redirectUris[] = $redirectUri;
    }
    foreach (whatsapp_oauth_redirect_uri_candidates() as $uri) {
        if (!in_array($uri, $redirectUris, true)) {
            $redirectUris[] = $uri;
        }
    }

    // Primary mode first, then opposite mode — Meta codes from Embedded Signup vary by browser flow.
    $attempts = [];
    $queueRedirect = static function () use (&$attempts, $redirectUris): void {
        foreach ($redirectUris as $uri) {
            $attempts[] = ['mode' => 'redirect', 'redirect_uri' => $uri];
        }
    };
    $queueSdk = static function () use (&$attempts): void {
        $attempts[] = ['mode' => 'sdk', 'redirect_uri' => null];
    };

    if ($exchangeMode === 'sdk') {
        $queueSdk();
        $queueRedirect();
    } else {
        $queueRedirect();
        $queueSdk();
    }

    $seen = [];
    $uniqueAttempts = [];
    foreach ($attempts as $attempt) {
        $key = $attempt['redirect_uri'] ?? '__sdk__';
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $uniqueAttempts[] = $attempt;
    }

    $lastError = 'Meta token exchange failed';

    foreach ($uniqueAttempts as $attempt) {
        $post = [
            'client_id'     => $creds['app_id'],
            'client_secret' => $creds['app_secret'],
            'code'          => $code,
        ];
        if ($attempt['redirect_uri'] !== null) {
            $post['redirect_uri'] = $attempt['redirect_uri'];
        }

        $result = whatsapp_curl_request($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_TIMEOUT    => 30,
        ]);

        if (!$result['ok']) {
            $lastError = whatsapp_outbound_error_message($result['curl_error']);
            error_log('whatsapp oauth exchange attempt failed app=' . $creds['app_id']
                . ' mode=' . $attempt['mode']
                . ' redirect=' . ($attempt['redirect_uri'] ?? '(none)')
                . ' curl=' . $result['curl_error']);
            continue;
        }

        $response = $result['body'];
        $httpCode = $result['http_code'];

        $data = json_decode(is_string($response) ? $response : '', true) ?: [];

        if ($httpCode < 400 && !empty($data['access_token'])) {
            error_log('whatsapp oauth exchange ok app=' . $creds['app_id']
                . ' mode=' . $attempt['mode']
                . ' redirect=' . ($attempt['redirect_uri'] ?? '(none)'));
            return ['success' => true, 'data' => $data];
        }

        $lastError = (string) ($data['error']['message'] ?? $data['error_description'] ?? $lastError);
        error_log('whatsapp oauth exchange attempt failed app=' . $creds['app_id']
            . ' mode=' . $attempt['mode']
            . ' redirect=' . ($attempt['redirect_uri'] ?? '(none)')
            . ' err=' . $lastError);

        $lower = strtolower($lastError);
        if (str_contains($lower, 'has been used') || str_contains($lower, 'authorization code has been used')) {
            break;
        }
    }

    return ['success' => false, 'error' => whatsapp_oauth_friendly_error($lastError)];
}

/**
 * Verify a specific Meta App ID + secret pair (client credentials grant).
 *
 * @return array{success: bool, error?: string, http_code?: int, meta_code?: int, curl_error?: string}
 */
function whatsapp_meta_verify_credentials_pair(string $appId, string $appSecret): array
{
    $appId = trim($appId);
    $appSecret = trim($appSecret);
    if ($appId === '' || $appSecret === '') {
        return ['success' => false, 'error' => 'App ID or secret missing'];
    }

    $apiVersion = integration_meta_graph_api_version();
    $urls = array_values(array_unique(array_filter([
        'https://graph.facebook.com/' . $apiVersion . '/oauth/access_token',
        'https://graph.facebook.com/oauth/access_token',
    ])));

    $lastError = 'Invalid App ID / App Secret pair';
    $lastHttp = 0;
    $lastCurl = '';
    $data = [];

    foreach ($urls as $url) {
        $query = http_build_query([
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'grant_type'    => 'client_credentials',
        ]);

        $result = whatsapp_curl_request($url . '?' . $query, [CURLOPT_TIMEOUT => 20]);

        if (!$result['ok']) {
            $lastCurl = $result['curl_error'];
            $lastError = whatsapp_outbound_error_message($result['curl_error']);
            break;
        }

        $response = $result['body'];
        $httpCode = $result['http_code'];

        $data = json_decode(is_string($response) ? $response : '', true) ?: [];

        if ($httpCode >= 200 && $httpCode < 400 && !empty($data['access_token'])) {
            return ['success' => true, 'http_code' => $httpCode];
        }

        $lastHttp = $httpCode;
        $lastCurl = $result['curl_error'] ?? '';
        $lastError = (string) ($data['error']['message'] ?? $lastError);
    }

    return [
        'success'    => false,
        'error'      => $lastError,
        'http_code'  => $lastHttp,
        'meta_code'  => (int) ($data['error']['code'] ?? 0),
        'curl_error' => $lastCurl !== '' ? $lastCurl : null,
    ];
}

/**
 * Verify effective Meta credentials from integration_meta_credentials().
 *
 * @return array{success: bool, error?: string}
 */
function whatsapp_meta_verify_app_credentials(): array
{
    $creds = integration_meta_credentials();

    return whatsapp_meta_verify_credentials_pair($creds['app_id'], $creds['app_secret']);
}

function whatsapp_meta_get(string $url, ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $result = whatsapp_curl_request($url, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT    => 30,
    ]);

    if (!$result['ok']) {
        return ['success' => false, 'error' => whatsapp_outbound_error_message($result['curl_error'])];
    }

    $data = json_decode(is_string($result['body']) ? $result['body'] : '', true) ?: [];

    if ($result['http_code'] >= 400) {
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Meta API request failed'];
    }

    return ['success' => true, 'data' => $data];
}

/**
 * @return array{waba_id: string, phone_number_id: string, display_number: string}|null
 */
function whatsapp_resolve_embedded_assets(
    string $accessToken,
    string $wabaIdInput = '',
    string $phoneNumberIdInput = '',
    string $displayNumberInput = ''
): ?array {
    $version = integration_meta_graph_api_version();

    if ($wabaIdInput !== '' && $phoneNumberIdInput !== '') {
        $displayNumber = $displayNumberInput;
        if ($displayNumber === '') {
            $phoneResult = whatsapp_meta_get(
                'https://graph.facebook.com/' . $version . '/' . rawurlencode($phoneNumberIdInput)
                . '?fields=display_phone_number,verified_name',
                $accessToken
            );
            if ($phoneResult['success']) {
                $displayNumber = (string) ($phoneResult['data']['display_phone_number'] ?? '');
            }
        }

        return [
            'waba_id'         => $wabaIdInput,
            'phone_number_id' => $phoneNumberIdInput,
            'display_number'  => $displayNumber,
        ];
    }

    if ($wabaIdInput !== '' && $phoneNumberIdInput === '') {
        $resolved = whatsapp_resolve_waba_phones($accessToken, $wabaIdInput, $version);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    $appToken = whatsapp_meta_app_id() . '|' . whatsapp_meta_app_secret();
    $debugResult = whatsapp_meta_get(
        'https://graph.facebook.com/' . $version . '/debug_token'
        . '?input_token=' . urlencode($accessToken)
        . '&access_token=' . urlencode($appToken)
    );

    if ($debugResult['success']) {
        $granular = $debugResult['data']['data']['granular_scopes'] ?? [];
        foreach ($granular as $scopeRow) {
            $scope = (string) ($scopeRow['scope'] ?? '');
            if (!str_contains($scope, 'whatsapp')) {
                continue;
            }
            foreach (($scopeRow['target_ids'] ?? []) as $targetId) {
                $targetId = (string) $targetId;
                if ($targetId === '') {
                    continue;
                }
                $resolved = whatsapp_resolve_waba_phones($accessToken, $targetId, $version);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
    }

    require_once __DIR__ . '/whatsapp.php';
    foreach (whatsapp_waba_ids_from_token($accessToken) as $wabaId) {
        if ($wabaId === '') {
            continue;
        }
        $resolved = whatsapp_resolve_waba_phones($accessToken, $wabaId, $version);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    $businesses = whatsapp_meta_get(
        'https://graph.facebook.com/' . $version . '/me/businesses?fields=id,name',
        $accessToken
    );
    if ($businesses['success']) {
        foreach ($businesses['data']['data'] ?? [] as $biz) {
            if (!is_array($biz)) {
                continue;
            }
            $bizId = (string) ($biz['id'] ?? '');
            if ($bizId === '') {
                continue;
            }
            $wabaList = whatsapp_meta_get(
                'https://graph.facebook.com/' . $version . '/' . rawurlencode($bizId)
                . '/owned_whatsapp_business_accounts?fields=id,name',
                $accessToken
            );
            if (!$wabaList['success']) {
                continue;
            }
            foreach ($wabaList['data']['data'] ?? [] as $wabaRow) {
                if (!is_array($wabaRow)) {
                    continue;
                }
                $wabaId = (string) ($wabaRow['id'] ?? '');
                if ($wabaId === '') {
                    continue;
                }
                $resolved = whatsapp_resolve_waba_phones($accessToken, $wabaId, $version);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
    }

    if ($phoneNumberIdInput !== '') {
        return [
            'waba_id'         => $wabaIdInput,
            'phone_number_id' => $phoneNumberIdInput,
            'display_number'  => $displayNumberInput,
        ];
    }

    return null;
}

/**
 * @return array{waba_id: string, phone_number_id: string, display_number: string}|null
 */
function whatsapp_resolve_waba_phones(string $accessToken, string $wabaId, string $version): ?array
{
    $phonesResult = whatsapp_meta_get(
        'https://graph.facebook.com/' . $version . '/' . rawurlencode($wabaId) . '/phone_numbers',
        $accessToken
    );
    if (!$phonesResult['success']) {
        return null;
    }

    $phones = $phonesResult['data']['data'] ?? [];
    if ($phones === []) {
        return null;
    }

    return [
        'waba_id'         => $wabaId,
        'phone_number_id' => (string) ($phones[0]['id'] ?? ''),
        'display_number'  => (string) ($phones[0]['display_phone_number'] ?? ''),
    ];
}

/**
 * Exchange OAuth code and store WhatsApp connection for a client.
 *
 * @return array{success: bool, error?: string, phone_number?: string, waba_id?: string}
 */
function whatsapp_complete_oauth_connection(
    int $clientId,
    string $code,
    string $wabaIdInput = '',
    string $phoneNumberIdInput = '',
    string $displayNumberInput = '',
    string $exchangeMode = 'redirect',
    string $catalogIdInput = '',
    string $businessIdInput = ''
): array {
    require_once __DIR__ . '/../lib/Encryption.php';

    whatsapp_ensure_embedded_schema();

    $redirectUri = $exchangeMode === 'sdk' ? '' : whatsapp_oauth_redirect_uri();
    $tokenResult = whatsapp_meta_exchange_oauth_code($code, $redirectUri, $exchangeMode);
    if (!$tokenResult['success']) {
        error_log('whatsapp oauth token exchange: ' . ($tokenResult['error'] ?? 'unknown'));
        return ['success' => false, 'error' => $tokenResult['error'] ?? 'Token exchange failed'];
    }

    $accessToken = (string) ($tokenResult['data']['access_token'] ?? '');
    if ($accessToken === '') {
        return ['success' => false, 'error' => 'No access_token returned from Meta'];
    }

    $assets = whatsapp_resolve_embedded_assets(
        $accessToken,
        $wabaIdInput,
        $phoneNumberIdInput,
        $displayNumberInput
    );

    if ($assets === null || $assets['phone_number_id'] === '') {
        $storedGraphVer = trim(integration_config('META_GRAPH_API_VERSION'));
        $graphHint = ($storedGraphVer !== '' && $storedGraphVer !== integration_meta_graph_api_version())
            ? ' Admin → Integrations: set Graph API version to v25.0 (stored value is invalid).'
            : '';
        if (function_exists('whatsapp_oauth_debug_log')) {
            whatsapp_oauth_debug_log('exchange_assets_failed', [
                'client_id'        => $clientId,
                'waba_id_input'    => $wabaIdInput,
                'phone_id_input'   => $phoneNumberIdInput,
                'graph_version'    => integration_meta_graph_api_version(),
                'stored_graph_ver' => $storedGraphVer,
                'mode'             => $exchangeMode,
            ]);
        }
        return [
            'success' => false,
            'error'   => 'Could not read WhatsApp account from Meta. Complete signup in Meta and click Finish, then try again.'
                . $graphHint,
        ];
    }

    $encryptedToken = encrypt_token($accessToken);
    $roundtrip = decrypt_token($encryptedToken);
    if ($roundtrip !== $accessToken) {
        error_log('whatsapp oauth: token encrypt/decrypt roundtrip failed for client ' . $clientId);
        return [
            'success' => false,
            'error'   => 'Server encryption misconfigured (ENCRYPTION_KEY). Check config.local.php — use one stable ENCRYPTION_KEY, then connect again.',
        ];
    }

    $existing = db_fetch(
        'SELECT id FROM client_whatsapp_accounts WHERE client_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$clientId]
    );

    if ($existing) {
        db_execute(
            'UPDATE client_whatsapp_accounts SET
                waba_id = ?, phone_number_id = ?, business_token = ?,
                phone_display_number = ?, connection_status = \'active\', connected_at = NOW()
             WHERE id = ?',
            'ssssi',
            [
                $assets['waba_id'],
                $assets['phone_number_id'],
                $encryptedToken,
                $assets['display_number'],
                (int) $existing['id'],
            ]
        );
    } else {
        db_insert(
            'INSERT INTO client_whatsapp_accounts
             (client_id, waba_id, phone_number_id, business_token, phone_display_number, connection_status, connected_at)
             VALUES (?, ?, ?, ?, ?, \'active\', NOW())',
            'issss',
            [
                $clientId,
                $assets['waba_id'],
                $assets['phone_number_id'],
                $encryptedToken,
                $assets['display_number'],
            ]
        );
    }

    require_once __DIR__ . '/auth.php';
    ensure_client_starter_bot($clientId);

    $bots = db_fetch_all('SELECT id FROM bots WHERE user_id = ?', 'i', [$clientId]);
    foreach ($bots as $botRow) {
        db_execute(
            'UPDATE bots SET whatsapp_phone_id = ?, whatsapp_token = ?, whatsapp_verified = 1, whatsapp_token_error = NULL WHERE id = ?',
            'ssi',
            [$assets['phone_number_id'], encrypt_token($accessToken), (int) $botRow['id']]
        );
    }

    $subscribe = whatsapp_subscribe_waba_to_app($assets['waba_id'], $accessToken);
    if (empty($subscribe['success'])) {
        error_log('whatsapp oauth subscribe: ' . ($subscribe['error'] ?? 'unknown'));
    }

    require_once __DIR__ . '/meta-catalog-sync.php';
    $catalogId = function_exists('meta_catalog_normalize_id')
        ? meta_catalog_normalize_id($catalogIdInput)
        : trim($catalogIdInput);
    $businessId = function_exists('meta_catalog_normalize_id')
        ? meta_catalog_normalize_id($businessIdInput)
        : trim($businessIdInput);
    meta_catalog_after_whatsapp_connect($clientId, $assets['waba_id'], $accessToken, $catalogId, $businessId);

    whatsapp_sync_user_business_phone($clientId, $assets['display_number']);

    return [
        'success'      => true,
        'phone_number' => $assets['display_number'],
        'waba_id'      => $assets['waba_id'],
        'catalog_id'   => $catalogId,
        'business_id'  => $businessId,
    ];
}
