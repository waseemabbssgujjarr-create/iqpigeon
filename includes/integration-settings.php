<?php

/**

 * Admin-managed third-party integrations (non-secrets + encrypted secrets in settings table).

 */



declare(strict_types=1);



require_once __DIR__ . '/platform-settings.php';



/** @var array<string, string>|null */

$GLOBALS['_integration_override_cache'] = null;



/**

 * @return array<string, mixed>

 */

function integration_settings_defaults(): array

{

    return [

        'meta_app_id'              => '',

        'meta_config_id'           => '',

        'meta_graph_api_version'   => 'v25.0',

        'webhook_verify_token'     => '',

        'whatsapp_manual_mode'     => false,

        'media_understanding_enabled'=> true,

        'visitor_geo_enabled'      => true,

        'behavior_learning_enabled'=> false,

        'google_signin_enabled'    => true,

        'facebook_signin_enabled'  => true,

        'instagram_enabled'        => true,

        'ai_responses_enabled'     => true,

        'stripe_price_starter'     => '',

        'stripe_price_pro'         => '',

        'stripe_price_growth'      => '',

        'stripe_price_agency'      => '',

        'paypak_sandbox'           => true,

        'paypak_merchant_name'     => '',

        'paypak_default_mobile'    => '',

        'smtp_host'                => 'localhost',

        'smtp_port'                => 587,

        'smtp_secure'              => 'tls',

        'smtp_user'                => '',

        'smtp_from'                => '',

        'smtp_from_name'           => '',

        'mail_transport'           => 'exim',

        'google_client_id'         => '',

        'google_redirect_uri'      => '',

        'facebook_app_id'          => '',

        'facebook_login_config_id' => '',

        'facebook_redirect_uri'    => '',

        'ai_model'                 => '',

    ];

}



/** @return array<string, string> */

function integration_secret_field_map(): array

{

    return [

        'openai_api_key'        => 'OPENAI_API_KEY',

        'meta_app_secret'       => 'META_APP_SECRET',

        'stripe_secret_key'     => 'STRIPE_SECRET_KEY',

        'stripe_webhook_secret' => 'STRIPE_WEBHOOK_SECRET',

        'paypak_merchant_id'    => 'PAYPAK_MERCHANT_ID',

        'paypak_secured_key'    => 'PAYPAK_SECURED_KEY',

        'smtp_pass'             => 'SMTP_PASS',

        'google_client_secret'  => 'GOOGLE_CLIENT_SECRET',

        'facebook_app_secret'   => 'FACEBOOK_APP_SECRET',

        'openai_voice_api_key'  => 'OPENAI_VOICE_API_KEY',

        'openai_image_api_key'  => 'OPENAI_IMAGE_API_KEY',

    ];

}



/** @return array<string, string> */

function integration_public_field_map(): array

{

    return [

        'meta_app_id'            => 'META_APP_ID',

        'meta_config_id'         => 'META_CONFIG_ID',

        'meta_graph_api_version' => 'META_GRAPH_API_VERSION',

        'webhook_verify_token'   => 'WEBHOOK_VERIFY_TOKEN',

        'stripe_price_starter'   => 'STRIPE_PRICE_STARTER',

        'stripe_price_pro'       => 'STRIPE_PRICE_PRO',

        'stripe_price_growth'    => 'STRIPE_PRICE_GROWTH',

        'stripe_price_agency'    => 'STRIPE_PRICE_AGENCY',

        'paypak_merchant_name'   => 'PAYPAK_MERCHANT_NAME',

        'paypak_default_mobile'  => 'PAYPAK_DEFAULT_MOBILE',

        'smtp_host'              => 'SMTP_HOST',

        'smtp_port'              => 'SMTP_PORT',

        'smtp_secure'            => 'SMTP_SECURE',

        'smtp_user'              => 'SMTP_USER',

        'smtp_from'              => 'SMTP_FROM',

        'smtp_from_name'         => 'SMTP_FROM_NAME',

        'mail_transport'         => 'MAIL_TRANSPORT',

        'google_client_id'       => 'GOOGLE_CLIENT_ID',

        'google_redirect_uri'    => 'GOOGLE_REDIRECT_URI',

        'facebook_app_id'        => 'FACEBOOK_APP_ID',

        'facebook_login_config_id' => 'FACEBOOK_LOGIN_CONFIG_ID',

        'facebook_redirect_uri'  => 'FACEBOOK_REDIRECT_URI',

        'ai_model'               => 'OPENAI_MODEL',

    ];

}



/**

 * @return array<string, mixed>

 */

function get_integration_settings(): array

{

    $defaults = integration_settings_defaults();

    $raw = get_setting('integrations_json');

    if ($raw === null || $raw === '') {

        return $defaults;

    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {

        return $defaults;

    }



    $merged = array_merge($defaults, $decoded);
    $rawVer = trim((string) ($merged['meta_graph_api_version'] ?? ''));
    $normVer = integration_normalize_graph_api_version($rawVer !== '' ? $rawVer : 'v25.0');
    if ($rawVer !== $normVer) {
        $merged['meta_graph_api_version'] = $normVer;
        if (empty($GLOBALS['_integration_graph_version_healed'])) {
            $GLOBALS['_integration_graph_version_healed'] = true;
            $decoded['meta_graph_api_version'] = $normVer;
            set_setting(
                'integrations_json',
                json_encode(array_merge($defaults, $decoded), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            $GLOBALS['_integration_override_cache'] = null;
        }
    }

    return $merged;

}



/**

 * @param array<string, mixed> $settings

 * @param array<string, string> $newSecrets Plaintext secrets (empty = keep existing)

 */

function save_integration_settings(array $settings, array $newSecrets = []): void

{

    require_once __DIR__ . '/helpers.php';



    $defaults = integration_settings_defaults();

    $previousSettings = get_integration_settings();
    $previousAppId = trim((string) ($previousSettings['meta_app_id'] ?? ''));

    $clean = [

        'meta_app_id'               => trim((string) ($settings['meta_app_id'] ?? '')),

        'meta_config_id'            => trim((string) ($settings['meta_config_id'] ?? '')),

        'meta_graph_api_version'    => integration_normalize_graph_api_version(
            trim((string) ($settings['meta_graph_api_version'] ?? $defaults['meta_graph_api_version']))
        ),

        'webhook_verify_token'      => trim((string) ($settings['webhook_verify_token'] ?? '')),

        'whatsapp_manual_mode'      => !empty($settings['whatsapp_manual_mode']),

        'media_understanding_enabled'=> !empty($settings['media_understanding_enabled']),

        'visitor_geo_enabled'       => !empty($settings['visitor_geo_enabled']),

        'behavior_learning_enabled' => !empty($settings['behavior_learning_enabled']),

        'google_signin_enabled'     => !empty($settings['google_signin_enabled']),

        'facebook_signin_enabled'   => !empty($settings['facebook_signin_enabled']),

        'instagram_enabled'         => !empty($settings['instagram_enabled']),

        'ai_responses_enabled'      => !empty($settings['ai_responses_enabled']),

        'stripe_price_starter'      => trim((string) ($settings['stripe_price_starter'] ?? '')),

        'stripe_price_pro'          => trim((string) ($settings['stripe_price_pro'] ?? '')),

        'stripe_price_growth'       => trim((string) ($settings['stripe_price_growth'] ?? '')),

        'stripe_price_agency'       => trim((string) ($settings['stripe_price_agency'] ?? '')),

        'paypak_sandbox'            => !empty($settings['paypak_sandbox']),

        'paypak_merchant_name'      => trim((string) ($settings['paypak_merchant_name'] ?? '')),

        'paypak_default_mobile'     => trim((string) ($settings['paypak_default_mobile'] ?? '')),

        'smtp_host'                 => trim((string) ($settings['smtp_host'] ?? $defaults['smtp_host'])),

        'smtp_port'                 => max(1, (int) ($settings['smtp_port'] ?? $defaults['smtp_port'])),

        'smtp_secure'               => trim((string) ($settings['smtp_secure'] ?? $defaults['smtp_secure'])),

        'smtp_user'                 => trim((string) ($settings['smtp_user'] ?? '')),

        'smtp_from'                 => trim((string) ($settings['smtp_from'] ?? '')),

        'smtp_from_name'            => trim((string) ($settings['smtp_from_name'] ?? '')),

        'mail_transport'            => in_array($settings['mail_transport'] ?? 'exim', ['auto', 'exim', 'smtp', 'mail'], true)

            ? $settings['mail_transport']

            : 'exim',

        'google_client_id'          => trim((string) ($settings['google_client_id'] ?? '')),

        'google_redirect_uri'       => trim((string) ($settings['google_redirect_uri'] ?? '')),

        'facebook_app_id'           => trim((string) ($settings['facebook_app_id'] ?? '')),

        'facebook_login_config_id'  => trim((string) ($settings['facebook_login_config_id'] ?? '')),

        'facebook_redirect_uri'     => trim((string) ($settings['facebook_redirect_uri'] ?? '')),

        'ai_model'                  => trim((string) ($settings['ai_model'] ?? '')),

    ];



    set_setting('integrations_json', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));



    if ($clean['ai_model'] !== '') {

        set_setting('ai_model', $clean['ai_model']);

    }



    $existingSecrets = integration_get_stored_secrets();

    $metaSecretSubmitted = false;

    foreach (integration_secret_field_map() as $field => $_constant) {

        if (!array_key_exists($field, $newSecrets)) {

            continue;

        }

        $plain = trim((string) $newSecrets[$field]);

        if ($plain === '' || str_starts_with($plain, '••••')) {

            continue;

        }

        if ($field === 'meta_app_secret') {
            $metaSecretSubmitted = true;
            $plain = preg_replace('/\s+/', '', $plain);
            if ($plain !== '' && !preg_match('/^[a-f0-9]{32}$/i', $plain)) {
                throw new InvalidArgumentException(
                    'Meta App Secret must be exactly 32 characters (hex) from Meta → Settings → Basic. '
                    . 'Got ' . strlen($plain) . ' characters — check for spaces or a partial copy.'
                );
            }
        }

        $existingSecrets[$field] = encrypt_token($plain);

    }



    $adminAppId = trim((string) ($clean['meta_app_id'] ?? ''));

    if (
        !$metaSecretSubmitted
        && $previousAppId !== ''
        && $adminAppId !== ''
        && $adminAppId !== $previousAppId
    ) {
        unset($existingSecrets['meta_app_secret']);
    }

    set_setting('integration_secrets_enc', json_encode($existingSecrets, JSON_UNESCAPED_UNICODE));



    $GLOBALS['_integration_override_cache'] = null;

}



/** @return array<string, string> */

function integration_get_stored_secrets(): array

{

    $raw = get_setting('integration_secrets_enc');

    if ($raw === null || $raw === '') {

        return [];

    }

    $decoded = json_decode($raw, true);



    return is_array($decoded) ? $decoded : [];

}



function integration_secret_is_set(string $field): bool

{

    $stored = integration_get_stored_secrets();



    return !empty($stored[$field]);

}

/** Placeholder for secret inputs — never pre-fill masked dots (avoids accidental overwrite on save). */
function integration_secret_input_placeholder(string $field): string
{
    return integration_secret_is_set($field)
        ? 'Saved — leave blank to keep'
        : 'Paste key and save';
}



/**

 * @return array{app_id: string, app_secret: string}

 */

function integration_meta_credentials(): array
{
    $configAppId = defined('META_APP_ID') ? trim((string) META_APP_ID) : '';
    $configSecret = defined('META_APP_SECRET') ? trim((string) META_APP_SECRET) : '';

    $settings = get_integration_settings();
    $adminAppId = trim((string) ($settings['meta_app_id'] ?? ''));
    $adminSaved = integration_admin_json_saved();

    $stored = integration_get_stored_secrets();
    $adminSecret = '';
    if (!empty($stored['meta_app_secret'])) {
        require_once __DIR__ . '/helpers.php';
        $plain = decrypt_token($stored['meta_app_secret']);
        if ($plain !== false && $plain !== '') {
            $adminSecret = trim((string) $plain);
        } elseif ($adminSaved) {
            error_log('integration_meta_credentials: Admin meta_app_secret saved but decrypt failed — re-save in Admin → Integrations or set META_APP_SECRET in config.local.php');
        }
    }

    $appId = $adminAppId !== ''
        ? $adminAppId
        : ($configAppId !== '' ? $configAppId : trim(integration_config('META_APP_ID')));

    // Admin → Integrations secret wins when saved (paste fresh secret here).
    if ($adminSaved && $adminSecret !== '') {
        return ['app_id' => $appId, 'app_secret' => $adminSecret];
    }

    if ($configAppId !== '' && $configSecret !== '') {
        return ['app_id' => $configAppId, 'app_secret' => $configSecret];
    }

    if ($adminSecret !== '') {
        return ['app_id' => $appId, 'app_secret' => $adminSecret];
    }

    return [
        'app_id'     => $appId,
        'app_secret' => $configSecret,
    ];
}



function integration_config(string $constantName): string

{

    $overrides = integration_load_overrides();

    if (isset($overrides[$constantName]) && $overrides[$constantName] !== '') {

        return (string) $overrides[$constantName];

    }



    return defined($constantName) ? (string) constant($constantName) : '';

}



/** Meta Graph / Facebook OAuth path version — must look like v25.0 (not webhook tokens). */
function integration_normalize_graph_api_version(string $input): string
{
    $v = trim($input);
    if (preg_match('/^v\d+\.\d+$/', $v)) {
        return $v;
    }
    if (preg_match('/^\d+\.\d+$/', $v)) {
        return 'v' . $v;
    }

    return 'v25.0';
}

function integration_meta_graph_api_version(): string
{
    return integration_normalize_graph_api_version(integration_config('META_GRAPH_API_VERSION'));
}



function integration_admin_json_saved(): bool

{

    $raw = get_setting('integrations_json');



    return $raw !== null && $raw !== '';

}



function integration_whatsapp_manual_mode(): bool

{

    if (!integration_admin_json_saved()) {

        return defined('WHATSAPP_MANUAL_MODE') && WHATSAPP_MANUAL_MODE;

    }



    return !empty(get_integration_settings()['whatsapp_manual_mode']);

}



function integration_toggle_enabled(string $field, string $constantName, bool $constantDefault = true): bool

{

    if (!integration_admin_json_saved()) {

        return defined($constantName) ? (bool) constant($constantName) : $constantDefault;

    }



    return !empty(get_integration_settings()[$field]);

}



function integration_ai_responses_enabled(): bool

{

    return integration_toggle_enabled('ai_responses_enabled', 'AI_RESPONSES_ENABLED', true);

}



function integration_instagram_enabled(): bool

{

    return integration_toggle_enabled('instagram_enabled', 'INSTAGRAM_ENABLED', true);

}



function integration_google_signin_enabled(): bool

{

    return integration_toggle_enabled('google_signin_enabled', 'GOOGLE_SIGNIN_ENABLED', true);

}



function integration_facebook_signin_enabled(): bool

{

    return integration_toggle_enabled('facebook_signin_enabled', 'FACEBOOK_SIGNIN_ENABLED', true);

}



function integration_media_understanding_enabled(): bool

{

    return integration_toggle_enabled('media_understanding_enabled', 'MEDIA_UNDERSTANDING_ENABLED', true);

}



function integration_behavior_learning_enabled(): bool

{

    return integration_toggle_enabled('behavior_learning_enabled', 'BEHAVIOR_LEARNING_ENABLED', false);

}



function integration_visitor_geo_enabled(): bool

{

    return integration_toggle_enabled('visitor_geo_enabled', 'VISITOR_GEO_ENABLED', true);

}



function google_signin_available(): bool

{

    require_once __DIR__ . '/google-oauth.php';



    return integration_google_signin_enabled() && google_oauth_configured();

}



function facebook_signin_available(): bool

{

    require_once __DIR__ . '/facebook-oauth.php';



    return integration_facebook_signin_enabled() && facebook_oauth_configured();

}



function integration_mask_secret(string $value): string

{

    $value = trim($value);

    if ($value === '') {

        return 'not set';

    }

    if (strlen($value) <= 8) {

        return '••••••••';

    }



    return substr($value, 0, 4) . '••••' . substr($value, -4);

}



function meta_whatsapp_configured(): bool

{

    return integration_meta_configured();

}



function meta_webhook_verify_configured(): bool

{

    $token = integration_config('WEBHOOK_VERIFY_TOKEN');



    return $token !== '' && !integration_is_placeholder_secret($token);

}



function encryption_configured(): bool

{

    return defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '' && strlen((string) ENCRYPTION_KEY) >= 16;

}



function ai_api_configured(): bool

{

    return integration_ai_configured();

}



function gemini_configured(): bool
{
    return openai_media_configured();
}

function openai_media_configured(): bool
{
    return integration_openai_media_configured();
}

function google_oauth_credentials_ready(): bool
{

    return integration_google_configured();

}



function facebook_oauth_credentials_ready(): bool

{

    return integration_facebook_configured();

}



function cron_secret_configured(): bool

{

    return defined('CRON_SECRET') && CRON_SECRET !== '' && !str_contains((string) CRON_SECRET, 'change-me');

}



function push_notifications_configured(): bool

{

    return defined('FCM_SERVER_KEY') && trim((string) FCM_SERVER_KEY) !== '';

}



/** @return array<string, array{label: string, configured: bool}> */

function integration_status_catalog(): array

{

    $overview = integration_status_overview();

    $catalog = [];

    foreach ($overview as $key => $row) {

        $catalog[$key] = [

            'label'       => $row['label'],

            'configured'  => $row['ok'],

        ];

    }



    return $catalog;

}



/** @return array<string, string> */

function integration_oauth_urls(): array

{

    require_once __DIR__ . '/whatsapp-oauth.php';

    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');



    return [

        'whatsapp' => whatsapp_oauth_redirect_uri(),

        'google'   => integration_config('GOOGLE_REDIRECT_URI') !== ''

            ? integration_config('GOOGLE_REDIRECT_URI')

            : $base . '/api/auth/google-callback.php',

        'facebook' => integration_config('FACEBOOK_REDIRECT_URI') !== ''

            ? integration_config('FACEBOOK_REDIRECT_URI')

            : $base . '/api/auth/facebook-callback.php',

    ];

}



function integration_config_bool(string $constantName, bool $default = false): bool

{

    if ($constantName === 'WHATSAPP_MANUAL_MODE') {

        return integration_whatsapp_manual_mode();

    }

    if ($constantName === 'MEDIA_UNDERSTANDING_ENABLED') {

        return integration_toggle_enabled('media_understanding_enabled', 'MEDIA_UNDERSTANDING_ENABLED', true);

    }

    if ($constantName === 'PAYPAK_SANDBOX') {

        if (!integration_admin_json_saved()) {

            return defined('PAYPAK_SANDBOX') ? (bool) PAYPAK_SANDBOX : true;

        }



        return !empty(get_integration_settings()['paypak_sandbox']);

    }



    if (!defined($constantName)) {

        return $default;

    }



    return (bool) constant($constantName);

}



/** @return array<string, string> */

function integration_load_overrides(): array

{

    if (is_array($GLOBALS['_integration_override_cache'])) {

        return $GLOBALS['_integration_override_cache'];

    }



    require_once __DIR__ . '/helpers.php';



    $overrides = [];

    $settings = get_integration_settings();

    $publicMap = integration_public_field_map();



    foreach ($publicMap as $field => $constant) {

        $value = $settings[$field] ?? '';

        if ($field === 'smtp_port') {

            $value = (string) (int) $value;

        }

        if ($value !== '' && $value !== '0') {
            if ($constant === 'META_GRAPH_API_VERSION') {
                $value = integration_normalize_graph_api_version((string) $value);
            }
            $overrides[$constant] = (string) $value;

        }

    }



    $stored = integration_get_stored_secrets();

    foreach (integration_secret_field_map() as $field => $constant) {

        if (empty($stored[$field])) {

            continue;

        }

        $plain = decrypt_token($stored[$field]);

        if ($plain !== false && $plain !== '') {

            $overrides[$constant] = $plain;

        }

    }



    if (!empty($settings['ai_model'])) {

        $overrides['OPENAI_MODEL'] = (string) $settings['ai_model'];

    }

    // Legacy: migrate stored DeepSeek key to OpenAI chat key if chat key not set yet.
    if (empty($overrides['OPENAI_API_KEY']) && !empty($stored['deepseek_api_key'])) {
        $legacy = decrypt_token($stored['deepseek_api_key']);
        if ($legacy !== false && $legacy !== '') {
            $overrides['OPENAI_API_KEY'] = $legacy;
        }
    }



    $GLOBALS['_integration_override_cache'] = $overrides;



    return $overrides;

}



function integration_is_placeholder_secret(string $value): bool

{

    $value = trim($value);

    if ($value === '') {

        return true;

    }



    return str_contains($value, '...') || str_contains($value, 'xxxx');

}



function integration_stripe_configured(): bool

{

    $key = integration_config('STRIPE_SECRET_KEY');

    if ($key === '' || integration_is_placeholder_secret($key)) {

        return false;

    }



    return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_');

}



function integration_paypak_configured(): bool

{

    return integration_config('PAYPAK_MERCHANT_ID') !== ''

        && integration_config('PAYPAK_SECURED_KEY') !== ''

        && integration_config('PAYPAK_MERCHANT_NAME') !== '';

}



function integration_meta_configured(): bool

{

    $creds = integration_meta_credentials();



    return $creds['app_id'] !== ''

        && $creds['app_secret'] !== ''

        && integration_config('META_CONFIG_ID') !== '';

}



function integration_openai_chat_key(): string
{
    $key = trim(integration_config('OPENAI_API_KEY'));
    if ($key !== '' && !integration_is_placeholder_secret($key)) {
        return $key;
    }

    foreach (['OPENAI_VOICE_API_KEY', 'OPENAI_IMAGE_API_KEY', 'OPENAI_MEDIA_KEY'] as $constant) {
        $fallback = trim(integration_config($constant));
        if ($fallback !== '' && !integration_is_placeholder_secret($fallback)) {
            return $fallback;
        }
    }

    return '';
}

function integration_openai_model(): string
{
    $model = trim((string) get_setting('ai_model', ''));
    if ($model === '') {
        $model = trim((string) get_setting('openai_model', ''));
    }
    if ($model === '') {
        $model = trim(integration_config('OPENAI_MODEL'));
    }

    $model = strtolower($model !== '' ? $model : 'gpt-4o-mini');
    // Normalize common config typos (e.g. "GPT-4o" in config.local.php).
    if ($model === 'gpt-4.0' || $model === 'gpt4o') {
        $model = 'gpt-4o';
    }

    return $model;
}

function integration_openai_api_url(): string
{
    $url = trim(integration_config('OPENAI_API_URL'));
    if ($url === '') {
        return 'https://api.openai.com/v1/chat/completions';
    }

    $url = rtrim($url, '/');
    if (str_ends_with($url, '/chat/completions')) {
        return $url;
    }
    if (str_ends_with($url, '/v1')) {
        return $url . '/chat/completions';
    }
    if (!str_contains($url, 'chat/completions')) {
        return $url . '/chat/completions';
    }

    return $url;
}

function integration_ai_configured(): bool
{
    return integration_openai_chat_key() !== '';
}



function integration_smtp_configured(): bool

{

    return integration_config('SMTP_FROM') !== ''

        && (integration_config('SMTP_PASS') !== '' || integration_config('MAIL_TRANSPORT') === 'exim');

}



function integration_google_configured(): bool

{

    require_once __DIR__ . '/google-oauth.php';

    $clientId = integration_config('GOOGLE_CLIENT_ID');

    $secret = integration_config('GOOGLE_CLIENT_SECRET');



    return google_oauth_is_valid_client_id($clientId) && trim($secret) !== '';

}



function integration_facebook_configured(): bool

{

    require_once __DIR__ . '/facebook-oauth.php';

    return facebook_oauth_configured();

}



function integration_gemini_configured(): bool
{
    return integration_openai_media_configured();
}

function integration_openai_media_configured(): bool
{
    if (integration_openai_chat_key() !== '') {
        return true;
    }

    $voice = trim(integration_config('OPENAI_VOICE_API_KEY'));
    $image = trim(integration_config('OPENAI_IMAGE_API_KEY'));
    $legacy = trim(integration_config('OPENAI_MEDIA_KEY'));

    foreach ([$voice, $image, $legacy] as $key) {
        if ($key !== '' && !integration_is_placeholder_secret($key)) {
            return true;
        }
    }

    return false;
}



/** @return array<string, array{label: string, ok: bool, detail: string}> */

function integration_status_overview(): array

{

    require_once __DIR__ . '/mailer.php';



    return [

        'whatsapp' => [

            'label'  => 'WhatsApp / Meta',

            'ok'     => integration_meta_configured(),

            'detail' => integration_meta_configured()

                ? 'App ' . integration_config('META_APP_ID')

                : 'App ID, secret, or Config ID missing',

        ],

        'stripe' => [

            'label'  => 'Stripe',

            'ok'     => integration_stripe_configured(),

            'detail' => integration_stripe_configured() ? 'Secret key set' : 'Stripe secret key missing',

        ],

        'paypak' => [

            'label'  => 'PayPak',

            'ok'     => integration_paypak_configured(),

            'detail' => integration_paypak_configured()

                ? (integration_config_bool('PAYPAK_SANDBOX') ? 'Sandbox mode' : 'Live mode')

                : 'Merchant credentials missing',

        ],

        'ai' => [

            'label'  => 'OpenAI (chat)',

            'ok'     => integration_ai_configured(),

            'detail' => integration_ai_configured() ? 'API key set' : 'OpenAI chat API key missing',

        ],

        'email' => [

            'label'  => 'Email (SMTP)',

            'ok'     => mail_transport_ready(),

            'detail' => mail_transport_ready() ? 'Transport ready' : 'SMTP not configured',

        ],

        'google' => [

            'label'  => 'Google Sign-In',

            'ok'     => integration_google_configured(),

            'detail' => integration_google_configured() ? 'OAuth configured' : 'Client ID or secret missing',

        ],

        'gemini' => [

            'label'  => 'OpenAI (media)',

            'ok'     => integration_openai_media_configured(),

            'detail' => integration_openai_media_configured() ? 'Voice/image keys set' : 'Set OpenAI voice & image keys',

        ],

    ];

}



function integration_webhook_urls(): array

{

    require_once __DIR__ . '/whatsapp-oauth.php';

    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');



    return [

        'whatsapp'  => $base . '/api/whatsapp-webhook.php',

        'instagram' => $base . '/api/instagram-webhook.php',

        'stripe'    => $base . '/api/stripe-webhook.php',

        'paypak'    => $base . '/api/paypak-callback.php',

        'cron'      => $base . '/api/cron.php',

        'shop'      => $base . '/api/shop-webhook.php',

        'google'    => integration_config('GOOGLE_REDIRECT_URI') !== ''

            ? integration_config('GOOGLE_REDIRECT_URI')

            : $base . '/api/auth/google-callback.php',

        'facebook'  => integration_config('FACEBOOK_REDIRECT_URI') !== ''

            ? integration_config('FACEBOOK_REDIRECT_URI')

            : $base . '/api/auth/facebook-callback.php',

    ];

}



function integration_admin_source_label(string $constantName): string

{

    $overrides = integration_load_overrides();



    return isset($overrides[$constantName]) && $overrides[$constantName] !== ''

        ? 'Admin panel'

        : (defined($constantName) && constant($constantName) !== '' ? 'config.php' : 'Not set');

}


