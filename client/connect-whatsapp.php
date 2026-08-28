<?php
/**
 * Post-signup step 1 — Connect WhatsApp Business (before dashboard training).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/integration-settings.php';

$user = require_login();
$clientId = (int) $user['id'];

$fromFacebook = isset($_GET['facebook']) && (string) $_GET['facebook'] === '1';
if (!$fromFacebook && db_column_exists('users', 'auth_provider')) {
    $providerRow = db_fetch('SELECT auth_provider FROM users WHERE id = ? LIMIT 1', 'i', [$clientId]);
    $fromFacebook = ($providerRow['auth_provider'] ?? '') === 'facebook';
}

if (!whatsapp_client_embedded_connected($clientId)) {
    ensure_client_starter_bot($clientId);
} else {
    redirect('/client/dashboard?connected=1');
}

$botId = ensure_client_starter_bot($clientId);
$returnPath = '/client/dashboard?welcome=1';
$oauthStartUrl = whatsapp_oauth_start_url($clientId, $returnPath, false);
$isMobile = is_mobile_client();
$isNativeApp = is_native_app();
$expiryLabel = email_verify_expiry_label();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Connect WhatsApp') ?>
<style>
@keyframes wa-connect-spin { to { transform: rotate(360deg); } }
.wa-connect-spin {
  display: inline-block;
  width: 1.25rem;
  height: 1.25rem;
  margin-right: 0.35rem;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: wa-connect-spin 0.75s linear infinite;
  vertical-align: -0.2em;
}
#connect-wa-primary.wa-connect-busy { pointer-events: none; opacity: 0.85; }
#wa-connect-status:not(.hidden) { animation: none; }
</style>
<?php $meta_fb_sdk_skip_root = true; include __DIR__ . '/../includes/meta-fb-sdk.php'; ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface min-h-[100dvh] safe-top safe-bottom">
<div id="fb-root"></div>

<main class="min-h-[100dvh] flex flex-col">
    <header class="px-edge-margin py-md flex items-center justify-between max-w-lg mx-auto w-full">
        <a href="/index" class="inline-flex items-center min-w-0">
            <?= brand_logo_markup('brand-logo-img brand-logo-img--sm') ?>
        </a>
        <a href="/logout" class="text-body-sm text-on-surface-variant">Sign out</a>
    </header>

    <div class="flex-1 flex flex-col justify-center px-edge-margin pb-xl max-w-lg mx-auto w-full">
        <div class="mb-lg">
            <div class="flex items-center gap-sm mb-md">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-on-primary text-label-sm font-bold">1</span>
                <span class="text-label-sm text-outline uppercase tracking-wider">Connect WhatsApp</span>
            </div>
            <h1 class="font-headline text-headline-mob mb-sm">Connect WhatsApp</h1>
            <p class="text-body-md text-on-surface-variant">
                Go live on your business number — your AI rep replies where customers already message you.
            </p>
        </div>

        <?php if ($fromFacebook): ?>
        <div class="bg-surface-container-low border border-outline-variant rounded-2xl p-md mb-lg flex items-start gap-sm">
            <svg viewBox="0 0 24 24" class="w-6 h-6 shrink-0 text-[#1877F2]" aria-hidden="true"><path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            <div>
                <p class="text-body-md font-semibold text-on-surface mb-xs">You're signed in with Facebook</p>
                <p class="text-body-sm text-on-surface-variant">Tap <strong>Connect WhatsApp</strong> below — Meta may skip extra login steps since you're already authenticated with the same account.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="v2-card v2-card--flat mb-lg space-y-sm">
            <div class="flex items-start gap-sm">
                <span class="material-symbols-outlined text-primary">verified_user</span>
                <p class="text-body-md"><strong>Official Meta flow</strong> — secure OAuth through Facebook / WhatsApp Business.</p>
            </div>
            <?php if ($isNativeApp): ?>
            <div class="flex items-start gap-sm">
                <span class="material-symbols-outlined text-primary">phone_android</span>
                <p class="text-body-md">In the <strong>IQ Pigeon app</strong>, Meta signup stays in-app and returns automatically when you tap <strong>Finish</strong>.</p>
            </div>
            <?php elseif ($isMobile): ?>
            <div class="flex items-start gap-sm">
                <span class="material-symbols-outlined text-primary">phone_android</span>
                <p class="text-body-md">On mobile, use <strong>Finish</strong> in Meta — do not press Back. You'll return to IQ Pigeon automatically.</p>
            </div>
            <?php endif; ?>
            <div class="flex items-start gap-sm">
                <span class="material-symbols-outlined text-primary">school</span>
                <p class="text-body-md">After connecting, you'll train your bot in the dashboard (website, offers, personality).</p>
            </div>
        </div>

        <?php if (!integration_meta_configured()): ?>
        <div class="bg-error-container text-on-error-container rounded-xl p-md mb-lg text-body-md">
            WhatsApp signup is not configured on this server yet. Set <strong>META_APP_SECRET</strong> in config.local.php or Admin → Integrations.
        </div>
        <?php else: ?>
        <?php
        $metaAppVerify = whatsapp_meta_verify_app_credentials();
        if (empty($metaAppVerify['success'])):
        ?>
        <div class="bg-error-container text-on-error-container rounded-xl p-md mb-lg text-body-md space-y-sm">
            <p class="font-semibold">Meta App Secret is wrong or missing</p>
            <p>Token exchange will fail until this is fixed. Meta says: <?= sanitize((string) ($metaAppVerify['error'] ?? 'invalid credentials')) ?></p>
            <p class="text-body-sm">Admin → Integrations → App ID <code><?= sanitize(whatsapp_meta_app_id()) ?></code> → paste fresh App Secret from Meta → Settings → Basic → Save.</p>
            <p class="text-body-sm">OAuth redirect URI in Meta must include: <code><?= sanitize(whatsapp_oauth_redirect_uri()) ?></code></p>
        </div>
        <?php endif; ?>
        <?php
        $waConnectError = trim($_GET['error'] ?? '');
        if ($waConnectError !== ''):
        ?>
        <div class="bg-error-container text-on-error-container rounded-xl p-md mb-lg text-body-md">
            <?= sanitize($waConnectError) ?>
        </div>
        <?php endif; ?>
        <button type="button"
                id="connect-wa-primary"
                data-wa-oauth-connect="1"
                data-wa-client-id="<?= $clientId ?>"
                data-wa-oauth-url="<?= sanitize($oauthStartUrl) ?>"
                data-wa-return="<?= sanitize($returnPath) ?>"
                data-wa-mobile="<?= ($isMobile && !$isNativeApp) ? '1' : '0' ?>"
                data-wa-native="<?= $isNativeApp ? '1' : '0' ?>"
                class="w-full min-h-[3.75rem] rounded-2xl bg-primary text-on-primary font-title text-title-lg inline-flex items-center justify-center gap-sm active:scale-[0.98] transition-transform touch-manipulation shadow-lg">
            <svg viewBox="0 0 24 24" class="w-7 h-7 fill-current" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            <span class="wa-connect-btn-label">Connect WhatsApp</span>
        </button>
        <p id="wa-connect-status" class="hidden text-center text-body-md text-on-surface-variant mt-md">
            Complete signup in the Meta popup — this page stays open.
        </p>
        <p class="text-center text-body-sm text-on-surface-variant mt-sm space-x-2">
            <a href="#" class="underline underline-offset-2" onclick="event.preventDefault();document.getElementById('connect-wa-primary')?.click();">Open Meta in popup</a>
            <span aria-hidden="true">·</span>
            <a href="/client/whatsapp-oauth-debug" class="underline underline-offset-2">Connection stuck?</a>
            <span aria-hidden="true">·</span>
            <a href="/client/whatsapp-reply-debug" class="underline underline-offset-2">No WhatsApp reply?</a>
        </p>
        <?php endif; ?>

        <a href="/client/dashboard?skip_wa=1"
           class="block text-center text-body-md text-on-surface-variant mt-lg underline-offset-2 hover:underline">
            Skip for now — train in dashboard first
        </a>
    </div>

    <footer class="px-edge-margin pb-lg text-center text-body-sm text-outline max-w-lg mx-auto w-full">
        Step 1 of 2 · Email verified · Codes expire in <?= sanitize($expiryLabel) ?> on resend
    </footer>
</main>

<script src="/assets/js/wa-connect.js?v=<?= @filemtime(__DIR__ . '/../assets/js/wa-connect.js') ?: time() ?>"></script>
<script src="/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<?= client_pwa_install_script() ?>
</body>
</html>
