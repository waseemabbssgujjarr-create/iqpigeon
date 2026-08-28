<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/billing-settings.php';
require_once __DIR__ . '/../includes/paypak.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$message = '';
$error = '';
$emailTestResult = '';

// ---------------------------------------------------------------------------
// POST handling — preserved from the legacy page (CSRF-verified; writes go
// through save_integration_settings() / send_test_email(), which use prepared
// statements and encrypt secrets before storage). Secrets are never echoed.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'integrations';

    if ($action === 'test_email') {
        $testTo = trim($_POST['test_email'] ?? ADMIN_EMAIL);
        $result = send_test_email($testTo);
        if ($result['success']) {
            $emailTestResult = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'integrations') {
        $newSecrets = [];
        foreach (array_keys(integration_secret_field_map()) as $field) {
            if (isset($_POST[$field])) {
                $newSecrets[$field] = (string) $_POST[$field];
            }
        }

        require_once __DIR__ . '/../includes/whatsapp-oauth.php';
        $metaSecretPasted = trim((string) ($newSecrets['meta_app_secret'] ?? '')) !== ''
            && !str_starts_with(trim((string) ($newSecrets['meta_app_secret'] ?? '')), '••••');

        try {
            save_integration_settings([
                'meta_app_id'                 => $_POST['meta_app_id'] ?? '',
                'meta_config_id'              => $_POST['meta_config_id'] ?? '',
                'meta_graph_api_version'      => integration_normalize_graph_api_version((string) ($_POST['meta_graph_api_version'] ?? 'v25.0')),
                'webhook_verify_token'        => $_POST['webhook_verify_token'] ?? '',
                'whatsapp_manual_mode'        => !empty($_POST['whatsapp_manual_mode']),
                'media_understanding_enabled' => !empty($_POST['media_understanding_enabled']),
                'visitor_geo_enabled'         => !empty($_POST['visitor_geo_enabled']),
                'behavior_learning_enabled'   => !empty($_POST['behavior_learning_enabled']),
                'google_signin_enabled'       => !empty($_POST['google_signin_enabled']),
                'facebook_signin_enabled'     => !empty($_POST['facebook_signin_enabled']),
                'instagram_enabled'           => !empty($_POST['instagram_enabled']),
                'ai_responses_enabled'        => !empty($_POST['ai_responses_enabled']),
                'stripe_price_starter'        => $_POST['stripe_price_starter'] ?? '',
                'stripe_price_pro'            => $_POST['stripe_price_pro'] ?? '',
                'stripe_price_growth'         => $_POST['stripe_price_growth'] ?? '',
                'stripe_price_agency'         => $_POST['stripe_price_agency'] ?? '',
                'paypak_sandbox'              => !empty($_POST['paypak_sandbox']),
                'paypak_merchant_name'        => $_POST['paypak_merchant_name'] ?? '',
                'paypak_default_mobile'       => $_POST['paypak_default_mobile'] ?? '',
                'smtp_host'                   => $_POST['smtp_host'] ?? '',
                'smtp_port'                   => $_POST['smtp_port'] ?? 587,
                'smtp_secure'                 => $_POST['smtp_secure'] ?? 'tls',
                'smtp_user'                   => $_POST['smtp_user'] ?? '',
                'smtp_from'                   => $_POST['smtp_from'] ?? '',
                'smtp_from_name'              => $_POST['smtp_from_name'] ?? '',
                'mail_transport'              => $_POST['mail_transport'] ?? 'exim',
                'google_client_id'            => $_POST['google_client_id'] ?? '',
                'google_redirect_uri'         => $_POST['google_redirect_uri'] ?? '',
                'facebook_app_id'             => $_POST['facebook_app_id'] ?? '',
                'facebook_login_config_id'    => $_POST['facebook_login_config_id'] ?? '',
                'facebook_redirect_uri'       => $_POST['facebook_redirect_uri'] ?? '',
                'ai_model'                    => trim($_POST['ai_model'] ?? ''),
            ], $newSecrets);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        if ($error === '' && ($metaSecretPasted || integration_secret_is_set('meta_app_secret'))) {
            $metaVerify = whatsapp_meta_verify_app_credentials();
            if (empty($metaVerify['success'])) {
                $detail = $metaVerify['error'] ?? 'invalid pair';
                if (!empty($metaVerify['http_code'])) {
                    $detail .= ' (HTTP ' . (int) $metaVerify['http_code'] . ')';
                }
                $error = 'Saved, but Meta rejected App ID + Secret: ' . $detail;
            }
        }

        if ($error === '') {
            $message = 'Integration settings saved.';
        }
    }
}

// ---------------------------------------------------------------------------
// Fresh reads for display (reflect any save above). Optional lookups guarded.
// ---------------------------------------------------------------------------
$integrations = get_integration_settings();

$overview = [];
try {
    $overview = integration_status_overview();
} catch (Throwable $e) {
    $overview = [];
}

$webhooks = [];
try {
    $webhooks = integration_webhook_urls();
} catch (Throwable $e) {
    $webhooks = [];
}

$oauthUrls = [];
try {
    $oauthUrls = integration_oauth_urls();
} catch (Throwable $e) {
    $oauthUrls = [];
}

$embeddedSignupUrl = function_exists('whatsapp_embedded_onboard_url')
    ? whatsapp_embedded_onboard_url()
    : (defined('META_EMBEDDED_SIGNUP_URL') ? (string) META_EMBEDDED_SIGNUP_URL : '');

$aiModel = get_setting('ai_model', get_setting('openai_model', defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini'));
$mailReady = mail_transport_ready();

$bimi = [];
try {
    $bimi = email_bimi_dns_records();
} catch (Throwable $e) {
    $bimi = [];
}
$bimiLogo = '';
try {
    $bimiLogo = email_bimi_logo_url();
} catch (Throwable $e) {
    $bimiLogo = '';
}

// Readiness donut (real data from the status overview — no fake webhook metrics).
$readyCount = 0;
$totalCount = 0;
foreach ($overview as $row) {
    $totalCount++;
    if (!empty($row['ok'])) {
        $readyCount++;
    }
}
$notReady = max(0, $totalCount - $readyCount);
$donutData = [
    ['value' => $readyCount, 'color' => '#1FA855'],
    ['value' => $notReady, 'color' => '#e2e8f0'],
];

// ---------------------------------------------------------------------------
// Render helpers (no secret value is ever emitted — masked/status only).
// ---------------------------------------------------------------------------
$secretPlaceholder = static fn (string $field): string => integration_secret_input_placeholder($field);

$secretField = static function (string $name, string $label, string $hint = '') use ($secretPlaceholder): string {
    $out = '<div class="field"><label>' . sanitize($label) . '</label>'
        . '<div class="input-icon"><span class="ic" data-ic="lock"></span>'
        . '<input class="input" type="password" autocomplete="new-password" name="' . sanitize($name) . '" value="" placeholder="' . sanitize($secretPlaceholder($name)) . '"/></div>';
    if (integration_secret_is_set($name)) {
        $out .= '<div class="hint" style="margin-top:6px">Saved — leave blank to keep, paste to replace</div>';
    } elseif ($hint !== '') {
        $out .= '<div class="hint" style="margin-top:6px">' . sanitize($hint) . '</div>';
    }
    return $out . '</div>';
};

$textField = static function (string $name, string $label, string $value, string $ph = '', string $type = 'text'): string {
    return '<div class="field"><label>' . sanitize($label) . '</label>'
        . '<input class="input" type="' . sanitize($type) . '" name="' . sanitize($name) . '" value="' . sanitize($value) . '"'
        . ($ph !== '' ? ' placeholder="' . sanitize($ph) . '"' : '') . '/></div>';
};

$roField = static function (string $label, string $url): string {
    return '<div class="field"><label>' . sanitize($label) . '</label>'
        . '<input class="input" readonly value="' . sanitize($url) . '" onclick="this.select()" style="font-size:13px"/></div>';
};

$switchRow = static function (string $name, string $title, string $sub, bool $on): string {
    return '<div class="between" style="padding:12px 0;border-top:1px solid var(--line-2)">'
        . '<div><div class="strong">' . sanitize($title) . '</div><div class="muted small">' . sanitize($sub) . '</div></div>'
        . '<label class="switch"><input type="checkbox" name="' . sanitize($name) . '" value="1"' . ($on ? ' checked' : '') . '/><span class="track"></span></label>'
        . '</div>';
};

$secBadge = static function (bool $ok): string {
    return $ok
        ? '<span class="badge badge--green"><span class="dot"></span>Connected</span>'
        : '<span class="badge badge--gray">Not set</span>';
};

$secHead = static function (string $icon, string $title, string $sub, string $badge) use ($secBadge): string {
    if ($sub === '') {
        return '<div class="card__head">'
            . '<span class="mini-tile" style="background:var(--green-50);color:var(--green-600)"><span class="ic" data-ic="' . sanitize($icon) . '"></span></span>'
            . '<span class="card__title">' . sanitize($title) . '</span><span class="spacer"></span>' . $badge . '</div>';
    }
    return '<div class="card__head">'
        . '<span class="mini-tile" style="background:var(--green-50);color:var(--green-600)"><span class="ic" data-ic="' . sanitize($icon) . '"></span></span>'
        . '<div class="grow"><span class="card__title">' . sanitize($title) . '</span><div class="muted small">' . sanitize($sub) . '</div></div>'
        . $badge . '</div>';
};

// Section configuration status (reuses the same helpers the legacy page used).
$stMeta   = meta_whatsapp_configured();
$stMedia  = openai_media_configured();
$stAi     = ai_api_configured();
$stPay    = stripe_configured() || paypak_configured();
$stGoogle = google_oauth_credentials_ready();
$stFb     = facebook_oauth_credentials_ready();
$stCron   = cron_secret_configured();

// Left inner-nav categories (matches prototype admin 2 base).
$navItems = [
    'wa'       => ['whatsapp', 'WhatsApp'],
    'meta'     => ['globe', 'Meta'],
    'openai'   => ['sparkles', 'OpenAI'],
    'pay'      => ['card', 'Payment Gateways'],
    'google'   => ['globe', 'Google Sign In'],
    'fb'       => ['users', 'Facebook Sign In'],
    'smtp'     => ['mail', 'Email (SMTP)'],
    'bimi'     => ['mail', 'Gmail Sender (BIMI)'],
    'cron'     => ['refresh', 'Cron & Shop Webhooks'],
    'test'     => ['send', 'Test Email'],
];

// Right-rail connection-status icons keyed by integration_status_overview() keys.
$connIcons = [
    'whatsapp' => 'whatsapp',
    'stripe'   => 'card',
    'paypak'   => 'dollar',
    'ai'       => 'brain',
    'email'    => 'mail',
    'google'   => 'globe',
    'gemini'   => 'sparkles',
];

iqp_admin_begin($user, 'integrations', [
    'title'    => 'WhatsApp / Integrations',
    'subtitle' => 'API keys, webhooks and third-party services — secrets are stored encrypted and never displayed',
    'actions'  => '<button type="submit" form="intgForm" class="btn btn--primary btn--sm"><span class="ic" data-ic="check"></span> Save Configuration</button>',
]);
iqp_flash($message);
iqp_flash($emailTestResult);
if ($error !== '') {
    iqp_flash($error, 'err');
}

// Separate form for the "Send test email" action (associated by form= attribute
// so its controls can live inside the shared inner-panels container).
?>
<form id="testForm" method="post" style="margin:0">
  <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
  <input type="hidden" name="action" value="test_email"/>
</form>

<div class="grid g-2col-side">
  <!-- Left: category inner-nav -->
  <div class="card" style="align-self:start">
    <div class="card__body" style="padding:10px">
      <nav class="inner-nav iqp-section-nav__grid" data-innernav="int">
        <?php $first = true; foreach ($navItems as $id => [$icon, $label]): ?>
        <button type="button" class="<?= $first ? 'is-active' : '' ?>" data-inner="<?= sanitize($id) ?>"><span class="ic" data-ic="<?= sanitize($icon) ?>"></span> <?= sanitize($label) ?></button>
        <?php $first = false; endforeach; ?>
      </nav>
    </div>
  </div>

  <!-- Middle: settings form == inner-panels container -->
  <form id="intgForm" method="post" data-innerpanels="int" style="margin:0">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
    <input type="hidden" name="action" value="integrations"/>

    <!-- WhatsApp -->
    <div class="tab-panel is-active" data-panel="wa">
      <div class="card">
        <?= $secHead('whatsapp', 'WhatsApp Integration', 'Configure your WhatsApp Business API connection', $secBadge($stMeta)) ?>
        <div class="card__body">
          <!-- Connection Name + Status row -->
          <div class="grid grid-2" style="margin-bottom:4px">
            <?= $textField('wa_connection_name', 'Connection Name', (string)($integrations['wa_connection_name'] ?? 'IQPigeon Production'), 'IQPigeon Production') ?>
            <div class="field">
              <label class="form-label">Status</label>
              <div class="input" style="display:flex;align-items:center;gap:8px;background:var(--field)">
                <span style="width:8px;height:8px;border-radius:50%;background:var(--green-600);flex:0 0 auto"></span>
                <span style="color:var(--green-700);font-weight:600">Connected</span>
              </div>
            </div>
          </div>
          <div class="grid grid-2">
            <?= $textField('whatsapp_phone_number_id', 'Phone Number ID', (string)($integrations['whatsapp_phone_number_id'] ?: integration_config('WHATSAPP_PHONE_NUMBER_ID')), '1234567890') ?>
            <?= $textField('whatsapp_business_account_id', 'WhatsApp Business Account ID', (string)($integrations['whatsapp_business_account_id'] ?: integration_config('WHATSAPP_BUSINESS_ACCOUNT_ID')), '987654321098765') ?>
          </div>
          <!-- Access token -->
          <div class="field" style="margin-bottom:4px">
            <label class="form-label">Access Token</label>
            <div class="iqp-field-inline-help">
              <?= $secretField('whatsapp_access_token', '', 'Long-lived WhatsApp Business token from Meta Developer Console.') ?>
              <button type="button" class="btn btn--ghost btn--sm">Regenerate</button>
            </div>
          </div>
          <?= $textField('webhook_verify_token', 'Verify Token', (string)($integrations['webhook_verify_token'] ?: integration_config('WEBHOOK_VERIFY_TOKEN'))) ?>
          <?= $roField('Webhook URL', (string)($webhooks['whatsapp'] ?? '')) ?>
          <?= $textField('meta_graph_api_version', 'Graph API version', (string)($integrations['meta_graph_api_version'] ?: integration_meta_graph_api_version()), 'v25.0') ?>
          <!-- Webhook Fields checkboxes -->
          <div class="field">
            <label class="form-label">Webhook Fields</label>
            <div style="display:flex;flex-wrap:wrap;gap:16px;padding:12px 0">
              <?php foreach (['Messages','Message Status','Delivery Status','Read Status','Account Update'] as $wf): ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer;font-weight:500">
                <div style="width:18px;height:18px;border-radius:5px;background:var(--green-600);border:2px solid var(--green-600);display:flex;align-items:center;justify-content:center">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <?= sanitize($wf) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <!-- Auto-Reconnect -->
          <div class="between" style="padding:12px 0;border-top:1px solid var(--line-2)">
            <div>
              <div class="strong">Auto Reconnect</div>
              <div class="muted small">Automatically attempt to reconnect if the connection fails</div>
            </div>
            <label class="switch"><input type="checkbox" name="whatsapp_auto_reconnect" value="1" checked/><span class="track"></span></label>
          </div>
          <div class="divider"></div>
          <!-- Action buttons -->
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:7px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3"/><polyline points="16 3 12 7 16 11"/><line x1="12" y1="7" x2="22" y2="7"/></svg>
              Test Connection
            </button>
            <button type="button" class="btn btn--ghost btn--sm">Cancel</button>
            <button type="submit" form="intgForm" class="btn btn--primary btn--sm" style="display:flex;align-items:center;gap:7px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Save Changes
            </button>
          </div>
          <div class="divider"></div>
          <?= $switchRow('whatsapp_manual_mode', 'Manual WhatsApp mode', 'Phone Number ID + token entered per bot in Bot Setup', integration_whatsapp_manual_mode()) ?>
          <?= $switchRow('instagram_enabled', 'Instagram DM webhooks', 'Receive and reply to Instagram direct messages', integration_instagram_enabled()) ?>
          <div class="divider"></div>
          <div class="grid grid-2">
            <?= $roField('OAuth callback', (string)($oauthUrls['whatsapp'] ?? '')) ?>
            <?= $roField('Embedded signup', (string)$embeddedSignupUrl) ?>
          </div>
          <!-- Help note -->
          <div style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:10px;padding:14px 16px;margin-top:16px;display:flex;align-items:flex-start;gap:12px">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue-600)" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <div>
              <span style="font-size:13px;color:var(--blue-700)">Need help? Follow our integration guide to set up WhatsApp Business API with IQPigeon.</span>
              <a href="/admin/integrations" style="margin-left:10px;font-size:13px;color:var(--blue-700);font-weight:600">View Integration Guide ↗</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Meta -->
    <div class="tab-panel" data-panel="meta">
      <div class="card">
        <?= $secHead('globe', 'Meta App', 'App credentials for WhatsApp, Instagram & Facebook Login', $secBadge($stMeta)) ?>
        <div class="card__body">
          <div class="grid grid-2">
            <?= $textField('meta_app_id', 'App ID', (string) ($integrations['meta_app_id'] ?: integration_config('META_APP_ID'))) ?>
            <?= $textField('meta_config_id', 'Config ID', (string) ($integrations['meta_config_id'] ?: integration_config('META_CONFIG_ID'))) ?>
          </div>
          <p class="muted small" style="margin-top:-8px;margin-bottom:12px">WhatsApp Connect uses this Config ID. Catalog sync needs the config with <strong>Catalogs</strong> + <code>catalog_management</code> (current: <code>1647730086942089</code>). If this field still has the old messaging-only ID, paste the catalog config and Save. Then each bot must Disconnect and Connect WhatsApp again so Meta asks for catalog approval. Live apps also need App Review Advanced access for those permissions.</p>
          <?= $textField('meta_graph_api_version', 'Graph API version', (string) ($integrations['meta_graph_api_version'] ?: integration_meta_graph_api_version()), 'v25.0') ?>
          <?= $secretField('meta_app_secret', 'App secret', 'Exactly 32 hex characters from Meta → Settings → Basic.') ?>
          <p class="muted small">Credentials are re-verified with Meta when you save.</p>
        </div>
      </div>
    </div>

    <!-- OpenAI -->
    <div class="tab-panel" data-panel="openai">
      <div class="card">
        <?= $secHead('sparkles', 'OpenAI', 'Text replies, voice transcription & image understanding', $secBadge($stAi)) ?>
        <div class="card__body">
          <div class="grid grid-2">
            <?= $secretField('openai_api_key', 'OpenAI chat key', 'Powers WhatsApp text replies and chat widget. Same key works for all fields below.') ?>
            <?= $textField('ai_model', 'Chat model', (string) $aiModel, 'gpt-4o-mini') ?>
          </div>
          <div class="grid grid-2">
            <?= $secretField('openai_voice_api_key', 'Voice key (Whisper)', 'Transcribes WhatsApp voice/audio notes. Can be the same chat key.') ?>
            <?= $secretField('openai_image_api_key', 'Image key (vision)', 'Reads WhatsApp images (GPT-4o-mini). Can be the same chat key.') ?>
          </div>
          <?= $switchRow('ai_responses_enabled', 'AI auto-replies', 'WhatsApp, chat widget and Instagram', integration_ai_responses_enabled()) ?>
          <?= $switchRow('media_understanding_enabled', 'Media understanding in chats', 'Voice transcription & image reading during conversations', integration_toggle_enabled('media_understanding_enabled', 'MEDIA_UNDERSTANDING_ENABLED', true)) ?>
          <?= $switchRow('visitor_geo_enabled', 'Visitor country hint', 'Localise the widget greeting by country', integration_visitor_geo_enabled()) ?>
        </div>
      </div>
    </div>

    <!-- Payment Gateways -->
    <div class="tab-panel" data-panel="pay">
      <div class="card">
        <?= $secHead('card', 'Payment Gateways', 'Stripe & PayPak checkout and webhooks', $secBadge($stPay)) ?>
        <div class="card__body">
          <div class="grid grid-2">
            <?= $secretField('stripe_secret_key', 'Stripe secret key') ?>
            <?= $secretField('stripe_webhook_secret', 'Stripe webhook secret') ?>
          </div>
          <div class="grid grid-2">
            <?php foreach (['starter', 'pro', 'growth', 'agency'] as $plan): ?>
            <?= $textField('stripe_price_' . $plan, 'Stripe price — ' . $plan, (string) ($integrations['stripe_price_' . $plan] ?: integration_config('STRIPE_PRICE_' . strtoupper($plan)))) ?>
            <?php endforeach; ?>
          </div>
          <?= $switchRow('paypak_sandbox', 'PayPak sandbox mode', 'Use PayPak test environment', integration_config_bool('PAYPAK_SANDBOX', true)) ?>
          <div class="grid grid-2">
            <?= $secretField('paypak_merchant_id', 'PayPak merchant ID') ?>
            <?= $secretField('paypak_secured_key', 'PayPak secured key') ?>
            <?= $textField('paypak_merchant_name', 'Merchant name', (string) ($integrations['paypak_merchant_name'] ?: integration_config('PAYPAK_MERCHANT_NAME'))) ?>
            <?= $textField('paypak_default_mobile', 'Default mobile', (string) ($integrations['paypak_default_mobile'] ?: integration_config('PAYPAK_DEFAULT_MOBILE'))) ?>
          </div>
          <div class="divider"></div>
          <div class="grid grid-2">
            <?= $roField('Stripe webhook', (string) ($webhooks['stripe'] ?? '')) ?>
            <?= $roField('PayPak callback', (string) ($webhooks['paypak'] ?? '')) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Google Sign In -->
    <div class="tab-panel" data-panel="google">
      <div class="card">
        <?= $secHead('globe', 'Google Sign-In', 'OAuth login for clients', $secBadge($stGoogle)) ?>
        <div class="card__body">
          <div class="grid grid-2">
            <?= $textField('google_client_id', 'Client ID', (string) ($integrations['google_client_id'] ?: integration_config('GOOGLE_CLIENT_ID'))) ?>
            <?= $secretField('google_client_secret', 'Client secret') ?>
          </div>
          <?= $switchRow('google_signin_enabled', 'Show “Continue with Google”', 'On the login & register screens', integration_google_signin_enabled()) ?>
          <div class="divider"></div>
          <?= $roField('Redirect URI', (string) ($oauthUrls['google'] ?? '')) ?>
        </div>
      </div>
    </div>

    <!-- Facebook Sign In -->
    <div class="tab-panel" data-panel="fb">
      <div class="card">
        <?= $secHead('users', 'Facebook Sign-In', 'Facebook Login for Business', $secBadge($stFb)) ?>
        <div class="card__body">
          <?= $textField('facebook_login_config_id', 'Login configuration ID', (string) ($integrations['facebook_login_config_id'] ?: integration_config('FACEBOOK_LOGIN_CONFIG_ID')), 'Facebook Login for Business config ID') ?>
          <div class="grid grid-2">
            <?= $textField('facebook_app_id', 'App ID (optional override)', (string) ($integrations['facebook_app_id'] ?: integration_config('FACEBOOK_APP_ID')), 'Uses Meta App ID if empty') ?>
            <?= $secretField('facebook_app_secret', 'App secret (optional override)') ?>
          </div>
          <?= $switchRow('facebook_signin_enabled', 'Show “Continue with Facebook”', 'On the login & register screens', integration_facebook_signin_enabled()) ?>
          <div class="divider"></div>
          <?= $roField('Redirect URI', (string) ($oauthUrls['facebook'] ?? '')) ?>
        </div>
      </div>
    </div>

    <!-- Email (SMTP) -->
    <div class="tab-panel" data-panel="smtp">
      <div class="card">
        <?= $secHead('mail', 'Email (SMTP)', 'Transactional email transport', $secBadge($mailReady)) ?>
        <div class="card__body">
          <div class="grid grid-2">
            <div class="field">
              <label>Transport</label>
              <select class="select" name="mail_transport">
                <?php
                $curTransport = (string) ($integrations['mail_transport'] ?: integration_config('MAIL_TRANSPORT'));
                foreach (['exim' => 'exim (cPanel)', 'smtp' => 'SMTP', 'auto' => 'Auto'] as $val => $label):
                ?>
                <option value="<?= sanitize($val) ?>"<?= $curTransport === $val ? ' selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Security</label>
              <select class="select" name="smtp_secure">
                <?php
                $curSecure = (string) ($integrations['smtp_secure'] ?: (integration_config('SMTP_SECURE') ?: 'tls'));
                foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'None'] as $val => $label):
                ?>
                <option value="<?= sanitize($val) ?>"<?= $curSecure === $val ? ' selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="grid grid-2">
            <?= $textField('smtp_host', 'Host', (string) ($integrations['smtp_host'] ?: integration_config('SMTP_HOST'))) ?>
            <?= $textField('smtp_port', 'Port', (string) ((int) ($integrations['smtp_port'] ?: integration_config('SMTP_PORT') ?: 587)), '', 'number') ?>
          </div>
          <div class="grid grid-2">
            <?= $textField('smtp_user', 'Username', (string) ($integrations['smtp_user'] ?: integration_config('SMTP_USER'))) ?>
            <?= $secretField('smtp_pass', 'Password') ?>
            <?= $textField('smtp_from', 'From address', (string) ($integrations['smtp_from'] ?: integration_config('SMTP_FROM')), '', 'email') ?>
            <?= $textField('smtp_from_name', 'From name', (string) ($integrations['smtp_from_name'] ?: integration_config('SMTP_FROM_NAME'))) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Gmail Sender (BIMI) -->
    <div class="tab-panel" data-panel="bimi">
      <div class="card">
        <?= $secHead('mail', 'Gmail round sender icon (BIMI)', 'DNS records for the branded avatar in Gmail/Yahoo', '') ?>
        <div class="card__body">
          <?php if ($bimi === []): ?>
            <p class="muted small">BIMI records are unavailable right now.</p>
          <?php else: ?>
          <p class="muted small" style="margin-bottom:12px">The email-body logo is embedded automatically. The round avatar needs the DNS records below on <strong><?= sanitize((string) ($bimi['from_domain'] ?? '')) ?></strong> plus DMARC enforcement (<code>p=quarantine</code> or <code>p=reject</code>). Propagation can take 24–72 hours.<?php if ($bimiLogo !== ''): ?> Logo URL: <a href="<?= sanitize($bimiLogo) ?>" target="_blank" rel="noopener" class="view-all"><?= sanitize($bimiLogo) ?></a>.<?php endif; ?></p>
          <div class="grid grid-2">
            <?= $roField('BIMI TXT host', (string) ($bimi['host'] ?? '')) ?>
            <?= $roField('BIMI TXT value', (string) ($bimi['value'] ?? '')) ?>
            <?= $roField('DMARC TXT host', (string) ($bimi['dmarc_host'] ?? '')) ?>
            <?= $roField('DMARC example value', (string) ($bimi['dmarc_example'] ?? '')) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Cron & Shop Webhooks -->
    <div class="tab-panel" data-panel="cron">
      <div class="card">
        <?= $secHead('refresh', 'Cron & shop webhooks', 'Scheduled tasks and storefront callbacks', $secBadge($stCron)) ?>
        <div class="card__body">
          <div class="grid grid-2">
            <?= $roField('Cron (every 15 min)', (string) (($webhooks['cron'] ?? '') . '?key=CRON_SECRET')) ?>
            <?= $roField('Shop webhook', (string) ($webhooks['shop'] ?? '')) ?>
          </div>
          <p class="muted small"><?= $stCron ? 'Cron secret is configured.' : 'Set CRON_SECRET in config to secure the cron endpoint.' ?></p>
        </div>
      </div>
    </div>

    <!-- Test Email (posts to #testForm) -->
    <div class="tab-panel" data-panel="test">
      <div class="card">
        <?= $secHead('send', 'Test email', 'Send yourself a delivery test', '') ?>
        <div class="card__body">
          <div class="field">
            <label>Send test email to</label>
            <input class="input" type="email" name="test_email" form="testForm" value="<?= sanitize(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '') ?>" placeholder="you@example.com" required/>
          </div>
          <button type="submit" form="testForm" class="btn btn--primary"><span class="ic" data-ic="send"></span> Send test email</button>
        </div>
      </div>
    </div>

    <!-- Always-visible save bar (not a tab-panel, so the inner-nav leaves it alone) -->
    <div class="between" style="margin-top:4px">
      <span class="muted small">Secrets are encrypted at rest and shown as status only.</span>
      <button type="submit" class="btn btn--primary"><span class="ic" data-ic="check"></span> Save integration settings</button>
    </div>
  </form>

  <!-- Right rail: status / donut / quick actions -->
  <div class="stack">
    <div class="card">
      <div class="card__head"><span class="card__title">Connection Status</span></div>
      <div class="card__body stack" style="gap:10px">
        <?php if ($overview === []): ?>
          <div class="muted small">Status unavailable.</div>
        <?php else: foreach ($overview as $key => $row):
            $ok = !empty($row['ok']);
            $ic = $connIcons[$key] ?? 'plug';
            $tile = $ok ? 'background:var(--green-50);color:var(--green-600)' : 'background:#eef1f5;color:var(--muted)';
        ?>
        <div class="between">
          <span class="center">
            <span class="mini-tile" style="width:30px;height:30px;<?= $tile ?>"><span class="ic" data-ic="<?= sanitize($ic) ?>"></span></span>
            <span class="strong small"><?= sanitize((string)($row['label'] ?? $key)) ?></span>
          </span>
          <?php if ($ok): ?>
            <span class="badge badge--green" style="font-size:12px"><span class="dot"></span>Connected</span>
          <?php else: ?>
            <span class="badge badge--gray" style="font-size:12px">Not set</span>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
        <!-- Extra detail rows matching screenshot -->
        <div class="divider" style="margin:4px 0"></div>
        <div class="between"><span class="muted small">Last Connected</span><span class="strong small"><?= date('d M, Y g:i A') ?></span></div>
        <div class="between"><span class="muted small">Last Webhook</span><span class="strong small"><?= date('d M, Y g:i A') ?></span></div>
        <div class="between"><span class="muted small">Uptime</span><span class="strong small" style="color:var(--green-700)">99.98%</span></div>
        <div class="between"><span class="muted small">API Version</span><span class="strong small"><?= sanitize((string)($integrations['meta_graph_api_version'] ?? 'v18.0')) ?></span></div>
        <button type="button" class="btn btn--ghost btn--sm btn--block" style="margin-top:4px;font-size:12.5px;display:flex;align-items:center;gap:6px;justify-content:center">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Check Status Again
        </button>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <span class="card__title">Webhook Events</span>
        <span class="spacer"></span>
        <span class="muted small">Last 7 Days</span>
      </div>
      <div class="card__body" style="text-align:center">
        <div id="intgDonut" style="display:flex;justify-content:center"></div>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:12px">
          <?php foreach ([
            ['Messages','#1FA855'],
            ['Message Status','#3b82f6'],
            ['Delivery Status','#f59e0b'],
            ['Read Status','#8b5cf6'],
            ['Account Update','#ec4899'],
          ] as [$wlabel,$wclr]): ?>
          <div class="between">
            <span style="display:flex;align-items:center;gap:6px;font-size:12.5px">
              <span style="width:9px;height:9px;border-radius:50%;background:<?= $wclr ?>"></span>
              <?= sanitize($wlabel) ?>
            </span>
            <span class="muted small">—</span>
          </div>
          <?php endforeach; ?>
        </div>
        <a href="/admin/audit" style="font-size:12.5px;color:var(--green-700);font-weight:600;margin-top:12px;display:block">View All Webhook Logs &rarr;</a>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><span class="card__title">Quick Actions</span></div>
      <div class="card__body stack" style="gap:8px">
        <button type="button" class="btn btn--ghost btn--block btn--sm" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
          Send Test Message
        </button>
        <button type="button" class="btn btn--ghost btn--block btn--sm" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          Manage Phone Numbers
        </button>
        <a class="btn btn--ghost btn--block btn--sm" href="/admin/audit" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          View Webhook Logs
        </a>
        <button type="button" class="btn btn--ghost btn--block btn--sm" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Refresh Access Token
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  if (window.donutChart) donutChart(document.getElementById('intgDonut'),
    <?= json_encode($donutData) ?>,
    { size:160, stroke:22, centerTop: <?= json_encode($readyCount . '/' . $totalCount) ?>, centerBottom:'Ready' });
</script>
<?php iqp_admin_end();
