<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/platform-training.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';

ensure_bots_schema();
ensure_whatsapp_token_schema();

$user = require_login();
$userId = (int) $user['id'];

$botId = (int) ($_GET['id'] ?? 0);
$isNew = !empty($_GET['new']);

if ($isNew) {
    if (!can_add_bot($userId)) {
        redirect('/client/dashboard?error=' . urlencode('Bot limit reached for your plan.'));
    }
    $botId = db_insert(
        'INSERT INTO bots (user_id, name, rep_name, calendly_link) VALUES (?, ?, ?, ?)',
        'isss',
        [$userId, 'My Business', '', get_bot_calendly_link(null)]
    );
    redirect('/client/bot-setup?id=' . $botId);
}

if (!$botId) {
    $existing = db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
    if ($existing) {
        redirect('/client/bot-setup?id=' . (int) $existing['id']);
    }
    redirect('/client/onboarding');
}

$bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
if (!$bot) {
    redirect('/client/dashboard');
}

whatsapp_sync_embedded_account_to_bots($userId);
$bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);

$waEmbeddedAccount = db_fetch(
    'SELECT phone_display_number FROM client_whatsapp_accounts
     WHERE client_id = ? AND connection_status = \'active\'
     ORDER BY connected_at DESC LIMIT 1',
    'i',
    [$userId]
);
$waIsTestNumber = $waEmbeddedAccount && whatsapp_is_meta_test_number($waEmbeddedAccount['phone_display_number'] ?? '');

$waStatus = bot_whatsapp_connection_status($bot);
$waManualConnected = $waStatus['connected'];
$waEmbeddedConnected = whatsapp_client_embedded_connected($userId);
$waShowConnected = $waManualConnected || $waEmbeddedConnected;
$waTokenError = $waStatus['error'];
$waTokenMasked = $waStatus['masked'];
$waHasSavedToken = trim((string) ($bot['whatsapp_token'] ?? '')) !== '';
require_once __DIR__ . '/../includes/integration-settings.php';
$manualWhatsAppMode = integration_whatsapp_manual_mode();
$defaultTab = $_GET['tab'] ?? 'company';
if (!in_array($defaultTab, ['company', 'channels', 'widget'], true)) {
    $defaultTab = 'company';
}

$message = '';
$error = '';
$botMutated = false;
$flashSuccess = trim($_GET['connected'] ?? '') === '1';
$flashError = trim($_GET['error'] ?? '');
if ($flashSuccess) {
    $message = 'WhatsApp connected successfully.';
}
if ($flashError !== '') {
    $error = $flashError;
}
if ($flashSuccess) {
    bot_whatsapp_heal_connection($botId);
    whatsapp_sync_embedded_account_to_bots($userId);
    $bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if ($bot) {
        $waStatus = bot_whatsapp_connection_status($bot);
        $waManualConnected = $waStatus['connected'];
        $waEmbeddedConnected = whatsapp_client_embedded_connected($userId);
        $waShowConnected = $waManualConnected || $waEmbeddedConnected;
        $waTokenError = $waStatus['error'];
        $waTokenMasked = $waStatus['masked'];
        $waHasSavedToken = trim((string) ($bot['whatsapp_token'] ?? '')) !== '';
    }
}
if (!empty($_GET['fetched'])) {
    $message = 'Website fetched — knowledge and catalog updated.';
    $botMutated = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'delete_bot') {
        if (($user['role'] ?? '') !== 'admin') {
            $error = 'Only an administrator can delete bots. Contact support if you need changes.';
        } else {
        db_execute('DELETE FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);

        $demoId = get_setting('demo_bot_id', '');
        if ($demoId === (string) $botId) {
            db_execute('DELETE FROM settings WHERE key_name = \'demo_bot_id\'', '', []);
        }

        $other = db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
        redirect($other ? '/client/bot-setup?id=' . (int) $other['id'] : '/client/onboarding');
        }
    } else {
    $repName = bot_persist_field((string) ($_POST['rep_name'] ?? ''), (string) ($bot['rep_name'] ?? ''));
    $name = bot_persist_field((string) ($_POST['name'] ?? ''), (string) ($bot['name'] ?? ''));
    $businessModel = bot_persist_field((string) ($_POST['business_model'] ?? ''), (string) ($bot['business_model'] ?? ''));
    $knowledgeDoc = bot_persist_field((string) ($_POST['bot_knowledge'] ?? ''), (string) ($bot['bot_knowledge'] ?? ''));
    $websiteUrl = bot_persist_field((string) ($_POST['website_url'] ?? ''), (string) ($bot['website_url'] ?? ''));
    $widgetEnabled = !empty($_POST['widget_enabled']) ? 1 : 0;
    $widgetColor = normalize_widget_color(
        trim($_POST['widget_color'] ?? ''),
        normalize_widget_color((string) ($bot['widget_color'] ?? ''), '#4aad36')
    );

    $oldHost = bot_knowledge_host_from_url((string) ($bot['website_url'] ?? ''));
    $newHost = bot_knowledge_host_from_url($websiteUrl);
    $domainChanged = $oldHost !== '' && $newHost !== '' && $oldHost !== $newHost;
    $knowledgeChanged = trim($knowledgeDoc) !== trim((string) ($bot['bot_knowledge'] ?? ''))
        || trim($businessModel) !== trim((string) ($bot['business_model'] ?? ''))
        || $domainChanged;
    $clearQualify = ($domainChanged && !(qualification_flow_load() && qualification_is_custom($bot))) ? 1 : 0;

    db_execute(
        'UPDATE bots SET rep_name = ?, name = ?, business_model = ?, bot_knowledge = ?, website_url = ?,
         widget_enabled = ?, widget_color = ?, knowledge_updated_at = IF(? = 1, NOW(), knowledge_updated_at),
         qualify_trigger = IF(? = 1, \'\', qualify_trigger),
         qualifying_questions = IF(? = 1, \'[]\', qualifying_questions)
         WHERE id = ? AND user_id = ?',
        'sssssisiiiii',
        [$repName, $name, $businessModel, $knowledgeDoc, $websiteUrl, $widgetEnabled, $widgetColor, $knowledgeChanged ? 1 : 0, $clearQualify, $clearQualify, $botId, $userId]
    );

    if ($knowledgeChanged) {
        bot_refresh_after_knowledge_change($botId);
        if ($domainChanged) {
            $message = 'Business info saved. Website changed — old chat memory cleared on WhatsApp and website widget.';
        } else {
            $message = 'Business info saved. All WhatsApp and website chat threads were reset with your updated business info.';
        }
    } else {
        $message = 'Business info saved.';
    }

    $bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    $botMutated = true;
    }
}

$hasKnowledge = trim($bot['bot_knowledge'] ?? '') !== '' || trim($bot['business_model'] ?? '') !== '';
$knowledgePlaceholder = bot_knowledge_placeholder();
$storedKnowledge = trim($bot['bot_knowledge'] ?? '');
$storedRepName = trim($bot['rep_name'] ?? '');
$storedWebsite = trim($bot['website_url'] ?? '');
$storedBusinessModel = trim($bot['business_model'] ?? '');
$widgetBotName = get_widget_bot_name($bot);
$widgetColorValue = normalize_widget_color((string) ($bot['widget_color'] ?? ''), '#4aad36');
$embedCode = '<script>window.SalesBotConfig = { botId: \'' . (int) $botId
    . '\', color: \'' . sanitize($widgetColorValue)
    . '\', apiBase: \'' . rtrim(APP_URL, '/')
    . '\', botName: \'' . sanitize($widgetBotName)
    . '\', bottomOffset: 20 };</script>' . "\n"
    . '<script src="' . APP_URL . '/assets/js/chat-widget.js?v=' . (@filemtime(__DIR__ . '/../assets/js/chat-widget.js') ?: time()) . '" async></script>';

$botCount = (int) (db_fetch('SELECT COUNT(*) AS cnt FROM bots WHERE user_id = ?', 'i', [$userId])['cnt'] ?? 0);

$activeTab = 'connect';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Bot Setup') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface"
      data-sync-page="connect"
      data-sync-bot-id="<?= (int) $botId ?>">
<script>window.__BOT_SYNC__ = { botId: <?= (int) $botId ?> };</script>
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>

<?php client_layout_start([
    'width'       => 'wide',
    'main_id'     => 'bot-setup-root',
    'main_class'  => 'client-main--form',
    'data'        => [
        'bot-id'       => (string) (int) $botId,
        'csrf'         => csrf_token(),
        'wa-has-token' => $waHasSavedToken ? '1' : '0',
        'client-id'    => (string) $userId,
    ],
]); ?>
    <?php client_page_header('Connect', ['subtitle' => $bot['name']]); ?>

    <?php if ($message): ?>
        <div class="mb-md bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md text-body-md"><?= sanitize($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-md bg-error-container/20 border border-error text-on-error-container rounded-xl p-md text-body-md space-y-sm">
            <p><?= sanitize($error) ?></p>
            <?php if (whatsapp_oauth_is_domain_error($error)):
                $appHost = whatsapp_oauth_app_host();
                $oauthRedirect = whatsapp_oauth_redirect_uri();
            ?>
            <div class="border-t border-error/30 pt-sm mt-sm text-sm">
                <p class="font-medium mb-xs">Fix in Meta App Dashboard (App ID <?= sanitize(META_APP_ID) ?>):</p>
                <ol class="list-decimal list-inside space-y-xs">
                    <li>Settings → Basic → App Domains: add <code><?= sanitize($appHost) ?></code></li>
                    <li>Facebook Login → Valid OAuth Redirect URIs: add <code><?= sanitize($oauthRedirect) ?></code></li>
                </ol>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="client-segment-tabs v2-segment-tabs mb-md">
        <?php foreach (['company' => 'Your Business', 'channels' => 'Channels', 'widget' => 'Widget'] as $key => $label): ?>
            <button type="button" data-tab="<?= $key ?>"
                    class="client-segment-tab v2-segment-tab<?= $key === $defaultTab ? ' is-active' : '' ?>">
                <?= $label ?>
            </button>
        <?php endforeach; ?>
    </div>

    <form method="POST" class="space-y-lg">
        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

        <!-- Your Business -->
        <div data-panel="company" class="space-y-md <?= $defaultTab !== 'company' ? 'hidden' : '' ?>">
            <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <p class="text-[13px] font-semibold text-slate-800">Assistant knowledge</p>
                    <p class="text-[12px] text-slate-500">Notes and catalog are context for the AI model — there is no training job.</p>
                </div>
                <a href="/client/assistant" class="bg-[#1FA855] text-white rounded-lg px-4 py-2 text-[12.5px] font-semibold text-center">Open Assistant</a>
            </div>
            <div class="bg-primary-container/10 border border-primary/30 rounded-2xl p-md">
                <p class="text-body-md text-on-surface-variant">
                    Tell us about your business. Tone, personality, and sales style are managed by <?= sanitize(APP_NAME) ?> —
                    you focus on <strong>who you are</strong> and <strong>what you sell</strong>.
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-md">
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Rep name</label>
                    <input type="text" name="rep_name" value="<?= sanitize($storedRepName) ?>"
                           placeholder="e.g. Sareen"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                    <p class="text-body-md text-outline mt-xs">The human name customers will chat with.</p>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Brand / business name</label>
                    <input type="text" name="name" value="<?= sanitize($bot['name']) ?>"
                           placeholder="e.g. English Shoes"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                    <p class="text-body-md text-outline mt-xs">Your company or store name.</p>
                </div>
            </div>

            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">What you offer</label>
                <textarea name="business_model" rows="4"
                          placeholder="e.g. We sell premium leather shoes for men and women. COD nationwide. Custom sizes available."
                          class="w-full px-md py-md rounded-xl bg-surface-container border-none text-body-md leading-relaxed"><?= sanitize($storedBusinessModel) ?></textarea>
                <p class="text-body-md text-outline mt-xs">Business model, products, services, and who you help.</p>
            </div>

            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Company knowledge</label>
                <textarea name="bot_knowledge" id="bot-knowledge" rows="12"
                          placeholder="<?= sanitize($knowledgePlaceholder) ?>"
                          class="w-full px-md py-md rounded-xl bg-surface-container border-none text-body-md leading-relaxed"><?= sanitize($storedKnowledge) ?></textarea>
                <p class="text-body-md text-outline mt-xs">Pricing, FAQs, policies, qualifying criteria — anything your rep must know.</p>
            </div>

            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Upload document (optional)</label>
                <label class="flex min-w-0 h-14 px-md rounded-xl bg-surface-container border border-dashed border-outline-variant items-center gap-sm cursor-pointer active:scale-[0.99] transition-transform">
                    <span class="material-symbols-outlined text-secondary">upload_file</span>
                    <span id="knowledge-file-label" class="text-body-md text-on-surface-variant truncate">PDF, Word (.docx), or TXT — max 10 MB</span>
                    <input type="file" id="knowledge-file" accept=".pdf,.doc,.docx,.txt,application/pdf,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword" class="sr-only"/>
                </label>
            </div>

            <div class="bg-surface-container-lowest rounded-2xl p-md border border-outline-variant space-y-md">
                <h3 class="font-title text-title-md flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary">language</span>
                    Fetch from website
                </h3>
                <p class="text-body-md text-on-surface-variant">Enter your store or company website — we import products into your catalog and add site content to your knowledge base.</p>
                <div class="flex flex-col sm:flex-row gap-sm">
                    <input type="url" name="website_url" id="website-url" value="<?= sanitize($storedWebsite) ?>"
                           placeholder="https://yourstore.com"
                           class="flex-1 h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                    <button type="button" id="fetch-everything"
                            class="h-14 px-xl rounded-xl bg-secondary text-on-secondary font-title text-title-md inline-flex items-center justify-center gap-sm active:scale-95 shrink-0">
                        <span class="material-symbols-outlined">cloud_download</span>
                        Fetch Everything
                    </button>
                </div>
                <div id="fetch-status" class="hidden rounded-xl p-md text-body-md"></div>
                <?php if ($hasKnowledge): ?>
                <p class="text-body-md text-primary flex items-center gap-xs">
                    <span class="material-symbols-outlined text-sm">check_circle</span>
                    Business info configured
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Channels -->
        <div data-panel="channels" class="space-y-md <?= $defaultTab !== 'channels' ? 'hidden' : '' ?>">
            <div class="bg-primary-container/10 border border-primary/30 rounded-2xl p-md mb-md">
                <p class="text-body-md text-on-surface-variant">
                    <span class="material-symbols-outlined text-primary align-middle text-lg">verified_user</span>
                    Your bot uses your <strong>business info</strong> plus platform-wide training managed by <?= sanitize(APP_NAME) ?>.
                    Update your business tab and click Save — changes apply immediately.
                </p>
            </div>

            <div class="v2-channel-card space-y-md">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary">chat</span>
                        <span class="font-title text-title-md">WhatsApp Business</span>
                    </div>
                    <span id="whatsapp-status" class="<?= $waShowConnected ? 'bg-primary-container text-on-primary-container' : 'bg-error-container text-on-error-container' ?> px-sm py-0.5 rounded-full text-label-sm font-label">
                        <?= $waShowConnected ? 'Connected ✓' : 'Not Connected' ?>
                    </span>
                </div>

                <?php if (!$manualWhatsAppMode && !$waShowConnected): ?>
                <?php
                $waConnectReturn = '/client/bot-setup?id=' . $botId . '&tab=channels';
                ?>
                <div class="bg-secondary-container/20 border border-secondary rounded-xl p-md space-y-md">
                    <p class="text-body-md text-on-surface-variant">
                        <strong>One click:</strong> log in with Facebook, select your WhatsApp number (including an existing <strong>WhatsApp Business app</strong> number), verify with Meta’s OTP — we save everything automatically when you click Finish.
                    </p>
                    <p class="text-label-sm text-outline">Existing Business app numbers require WhatsApp Business app <strong>v2.24.17+</strong> on the phone. Meta may ask to share chat history — optional.</p>
                    <button type="button" data-wa-oauth-connect="1" data-wa-client-id="<?= $userId ?>"
                       class="w-full h-14 rounded-xl bg-secondary text-on-secondary font-title text-title-md flex items-center justify-center gap-sm hover-lift active:scale-95">
                        <span class="material-symbols-outlined">chat</span>
                        Connect WhatsApp
                    </button>
                    <div id="wa-connect-status" class="hidden rounded-xl bg-surface-container px-md py-sm text-body-md text-on-surface-variant flex items-center gap-sm">
                        <span class="material-symbols-outlined animate-spin text-secondary">progress_activity</span>
                        <span>Complete signup in the Meta window, then click <strong>Finish</strong>.</span>
                    </div>
                    <p class="text-label-sm text-outline">Opens Meta signup on this page — you stay on Bot Setup. When you click Finish, this page refreshes as Connected.</p>
                </div>
                <details class="text-body-md">
                    <summary class="cursor-pointer text-secondary">Advanced: connect manually with Phone ID + token</summary>
                    <div class="mt-md space-y-md">
                <?php elseif ($waShowConnected && $waIsTestNumber): ?>
                <div class="bg-tertiary-container/30 border border-tertiary rounded-xl p-md mb-md text-body-md">
                    <p class="font-medium mb-xs">Sandbox number connected</p>
                    <p class="text-on-surface-variant mb-sm">This Meta test line is for API testing only. Reconnect with a <strong>real business phone</strong> before going live with customers.</p>
                    <button type="button" data-wa-oauth-connect="1" data-wa-client-id="<?= $userId ?>"
                       class="text-secondary underline underline-offset-2">Connect real business number</button>
                </div>
                <details class="text-body-md">
                    <summary class="cursor-pointer text-secondary">Advanced: connect manually with Phone ID + token</summary>
                    <div class="mt-md space-y-md">
                <?php elseif ($waShowConnected): ?>
                <details class="text-body-md">
                    <summary class="cursor-pointer text-secondary">Advanced: connect manually with Phone ID + token</summary>
                    <div class="mt-md space-y-md">
                <?php endif; ?>

                <?php if ($waTokenError !== '' && !$waShowConnected): ?>
                <?php $waHostingError = str_contains($waTokenError, 'getaddrinfo') || str_contains($waTokenError, 'cannot reach Meta'); ?>
                <div class="bg-error-container/20 border border-error/40 rounded-xl p-md text-body-md">
                    <p class="font-title text-title-md text-error mb-xs"><?= $waHostingError ? 'Hosting server issue (not your token)' : 'WhatsApp disconnected' ?></p>
                    <p class="text-on-surface-variant"><?= sanitize($waTokenError) ?></p>
                    <?php if ($waHostingError): ?>
                    <p class="text-on-surface-variant mt-xs text-sm">Your Meta token and webhook are fine — Facebook delivers messages to your site. The server cannot send replies outbound. Contact <strong>Hostinger support</strong> and ask them to fix PHP curl DNS for outbound HTTPS to <code class="text-xs">graph.facebook.com</code>.</p>
                    <?php else: ?>
                    <p class="text-on-surface-variant mt-xs text-sm">Paste a new <strong>System User permanent token</strong> below and click Verify &amp; Connect.</p>
                    <?php endif; ?>
                </div>
                <?php elseif ($waTokenError !== '' && $waShowConnected): ?>
                <div class="bg-error-container/20 border border-error/40 rounded-xl p-md text-body-md">
                    <p class="font-title text-title-md text-error mb-xs">WhatsApp needs reconnecting</p>
                    <p class="text-on-surface-variant"><?= sanitize($waTokenError) ?></p>
                </div>
                <?php endif; ?>

                <p class="text-body-md text-on-surface-variant">
                    Connect your WhatsApp Business number. You need <strong>two values</strong> from Meta — both are required the first time.
                    <a href="https://developers.facebook.com/apps/<?= sanitize(META_APP_ID) ?>/whatsapp-business/wa-settings/" target="_blank" rel="noopener" class="text-secondary">Open Meta App Dashboard → WhatsApp → API Setup</a>
                </p>

                <ol class="list-decimal list-inside space-y-xs text-body-md text-on-surface-variant bg-surface-container-low rounded-xl p-md">
                    <li>In Meta, go to <strong>WhatsApp → API Setup</strong></li>
                    <li>Copy <strong>Phone number ID</strong> (long number, not your actual phone number)</li>
                    <li>Click <strong>Generate access token</strong> (or use a permanent System User token)</li>
                    <li>Token must be from the <strong>same Meta app</strong> as this site (App ID <?= sanitize(META_APP_ID) ?>)</li>
                    <li>Paste both below, then click Verify & Connect</li>
                </ol>

                <div>
                    <label for="whatsapp_phone_id" class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">1. Phone Number ID</label>
                    <input type="text" id="whatsapp_phone_id" value="<?= sanitize($bot['whatsapp_phone_id'] ?? '') ?>"
                           placeholder="e.g. 1047530937614296"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                    <p class="text-body-md text-outline mt-xs">Found under WhatsApp → API Setup → “Phone number ID”</p>
                </div>
                <div>
                    <label for="whatsapp_token" class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">2. Access Token <?= $waHasSavedToken ? '(saved)' : '(required)' ?></label>

                    <?php if ($waManualConnected && $waTokenMasked !== ''): ?>
                    <div id="wa-token-saved-wrap" class="space-y-xs">
                        <input type="text" id="whatsapp_token_saved" readonly
                               value="<?= sanitize($waTokenMasked) ?>"
                               class="w-full h-14 px-md rounded-xl bg-surface-container border border-primary/30 text-body-md font-mono tracking-wide text-on-surface"/>
                        <p class="text-body-md text-primary flex items-center gap-xs">
                            <span class="material-symbols-outlined text-lg">lock</span>
                            Token saved securely in your account. Refreshing this page will not remove it.
                        </p>
                        <button type="button" id="wa-token-change-btn" class="text-secondary text-body-md underline underline-offset-2">
                            Replace token
                        </button>
                    </div>
                    <div id="wa-token-edit-wrap" class="hidden space-y-xs">
                        <input type="password" id="whatsapp_token"
                               placeholder="Paste new System User token to replace saved token"
                               autocomplete="off"
                               class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                        <button type="button" id="wa-token-cancel-btn" class="text-outline text-body-md">Cancel — keep saved token</button>
                    </div>
                    <?php else: ?>
                    <input type="password" id="whatsapp_token"
                           placeholder="<?= $waHasSavedToken ? 'Paste token again if reconnect needed' : 'Paste your Meta access token here' ?>"
                           autocomplete="off"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                    <?php if ($waHasSavedToken && !$waManualConnected): ?>
                    <p class="text-body-md text-on-surface-variant mt-xs">A token is on file but could not be read — paste it again and click Verify &amp; Connect.</p>
                    <?php else: ?>
                    <p class="text-body-md text-outline mt-xs">Click “Generate” in Meta API Setup, or create a permanent token under Business Settings → System Users.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <button type="button" data-verify="whatsapp" class="w-full h-12 bg-primary text-on-primary rounded-xl font-title text-title-md active:scale-95">Verify & Connect</button>

                <?php if (!$manualWhatsAppMode): ?>
                    </div>
                </details>
                <?php endif; ?>

                <div class="bg-error-container/10 border border-error/30 rounded-xl p-md text-body-md text-on-surface-variant">
                    <p class="font-title text-title-md text-error mb-xs">If Verify fails</p>
                    <ul class="list-disc list-inside space-y-xs text-sm">
                        <li>Use <strong>Phone number ID</strong> from WhatsApp → API Setup (not App ID or WhatsApp Business Account ID)</li>
                        <li>Token must be generated for an app with WhatsApp enabled</li>
                        <li>System user needs WhatsApp account assigned: Business Settings → Users → System users → Assign assets</li>
                        <li>Token permissions: <code class="text-xs">whatsapp_business_messaging</code>, <code class="text-xs">whatsapp_business_management</code></li>
                    </ul>
                </div>

                <div class="bg-surface-container-low rounded-xl p-md space-y-xs text-body-md">
                    <p class="font-title text-title-md text-on-surface">Webhook (required for bot to reply)</p>
                    <p class="text-on-surface-variant text-sm">If outbound test works but bot stays silent when customers message you, the webhook is not subscribed.</p>
                    <ol class="list-decimal list-inside space-y-xs text-sm text-on-surface-variant">
                        <li>Meta App → WhatsApp → <strong>Configuration</strong> (not Basic setup)</li>
                        <li>Set Callback URL + Verify token below → <strong>Verify and save</strong></li>
                        <li>Click <strong>Manage</strong> next to Webhook fields → subscribe to <strong>messages</strong> (plus <code>history</code>, <code>smb_app_state_sync</code>, <code>smb_message_echoes</code> for Business app numbers)</li>
                    </ol>
                    <dl class="space-y-xs font-label text-label-sm mt-sm">
                        <div><dt class="text-outline inline">Callback URL: </dt><dd class="inline break-all text-secondary"><?= sanitize(app_canonical_url()) ?>/api/whatsapp-webhook.php</dd></div>
                        <div><dt class="text-outline inline">Verify token: </dt><dd class="inline"><?= sanitize(WEBHOOK_VERIFY_TOKEN) ?></dd></div>
                    </dl>
                    <div class="flex flex-wrap gap-sm mt-sm">
                        <a href="/api/whatsapp-diagnose.php?bot_id=<?= (int) $botId ?>&test_phone=923004522663" target="_blank" rel="noopener"
                           class="inline-flex text-secondary text-body-md">Run WhatsApp diagnose →</a>
                        <a href="/api/whatsapp-debug.php?view=1&client_id=<?= (int) $userId ?>" target="_blank" rel="noopener"
                           class="inline-flex text-secondary text-body-md">Run WhatsApp debug →</a>
                    </div>
                </div>

                <div class="bg-tertiary-container/20 border border-tertiary/40 rounded-xl p-md text-body-md space-y-sm">
                    <p class="font-title text-title-sm">Coexistence — existing WhatsApp Business app numbers</p>
                    <p class="text-on-surface-variant text-sm">Embedded Signup supports Business app numbers (v2.24.17+). In Meta Developer Console → WhatsApp → Configuration, subscribe webhook fields:</p>
                    <ul class="text-sm list-disc pl-lg space-y-xs text-on-surface-variant">
                        <li><code>history</code> — past messages (if customer shares history)</li>
                        <li><code>smb_app_state_sync</code> — contacts from WhatsApp Business app</li>
                        <li><code>smb_message_echoes</code> — messages sent from the Business app</li>
                    </ul>
                </div>
            </div>

            <div class="bg-primary-container/10 border border-primary/30 rounded-2xl p-md">
                <p class="text-body-md text-on-surface-variant">
                    <span class="material-symbols-outlined text-primary align-middle text-lg">info</span>
                    Instagram DMs can be added after WhatsApp is connected. Use WhatsApp + website widget for now.
                </p>
            </div>
        </div>

        <!-- Widget -->
        <div data-panel="widget" class="space-y-md <?= $defaultTab !== 'widget' ? 'hidden' : '' ?>">
            <label class="flex items-center gap-md bg-surface-container-lowest rounded-2xl p-md border border-outline-variant cursor-pointer">
                <input type="checkbox" name="widget_enabled" value="1" <?= $bot['widget_enabled'] ? 'checked' : '' ?> class="w-5 h-5 rounded"/>
                <span class="font-title text-title-md">Enable website widget</span>
            </label>

            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-sm font-label">Widget Color</label>
                <input type="hidden" name="widget_color" id="widget_color" value="<?= sanitize($widgetColorValue) ?>"/>
                <div class="flex flex-wrap items-center gap-md">
                    <label class="relative cursor-pointer shrink-0" title="Pick a color">
                        <input type="color" id="widget_color_picker" value="<?= sanitize($widgetColorValue) ?>"
                               class="w-14 h-14 rounded-xl border-2 border-outline-variant cursor-pointer p-0.5 bg-surface-container-lowest"/>
                    </label>
                    <div class="flex items-center gap-xs flex-1 min-w-[12rem] max-w-xs">
                        <span class="text-title-md text-on-surface-variant font-label">#</span>
                        <input type="text" id="widget_color_hex" inputmode="text" autocomplete="off" spellcheck="false"
                               maxlength="6" placeholder="4aad36"
                               value="<?= sanitize(ltrim($widgetColorValue, '#')) ?>"
                               class="flex-1 h-12 px-md rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface font-label uppercase tracking-wide"/>
                    </div>
                </div>
                <p class="text-body-sm text-on-surface-variant mt-sm">
                    Pick a color or enter hex (e.g. <code class="text-xs bg-surface-container px-xs rounded">#4aad36</code>).
                </p>
            </div>

            <div>
                <label class="block text-label-sm text-on-surface-variant uppercase mb-sm font-label">Embed Code</label>
                <div class="v2-embed-block relative">
                    <pre id="embed-code" class="v2-embed-block__code"><?= sanitize($embedCode) ?></pre>
                    <button type="button" id="copy-embed" class="v2-embed-block__copy">Copy code</button>
                </div>
                <p class="text-body-sm text-on-surface-variant mt-sm">
                    Paste before <code class="text-xs bg-surface-container px-xs rounded">&lt;/body&gt;</code> on your site. Enable the widget above, then <strong>Save Changes</strong>.
                </p>

                <details class="v2-embed-help mt-md">
                    <summary>Install tips &amp; troubleshooting</summary>
                    <div class="v2-embed-help__body">
                        <p class="text-body-sm text-on-surface-variant">
                            WordPress: paste in <strong>Insert Headers and Footers → Footer</strong> or use WPCode.
                            Chats use this bot’s knowledge — works on external domains.
                        </p>
                        <p class="text-body-sm text-on-surface-variant">
                            If a WhatsApp button overlaps the bubble, increase <code class="text-xs bg-surface-container px-xs rounded">bottomOffset</code> in the embed script.
                        </p>
                        <div class="v2-embed-help__actions">
                            <a href="<?= sanitize(rtrim(APP_URL, '/') . '/api/chat-widget.php?bot_id=' . (int) $botId) ?>" target="_blank" rel="noopener"
                               class="v2-embed-help__btn">
                                <span class="material-symbols-outlined text-base">open_in_new</span>
                                Test widget
                            </a>
                            <a href="<?= sanitize(rtrim(APP_URL, '/') . '/api/widget-debug.php?bot_id=' . (int) $botId . '&test=1&message=Hello&ai=1') ?>" target="_blank" rel="noopener"
                               class="v2-embed-help__btn">
                                <span class="material-symbols-outlined text-base">bug_report</span>
                                Debug AI reply
                            </a>
                        </div>
                    </div>
                </details>
            </div>

            <div class="relative h-32 bg-surface-container rounded-2xl border border-outline-variant">
                <p class="text-body-md text-outline p-md">Live preview</p>
                <div id="widget-preview-bubble" class="absolute bottom-md right-md w-14 h-14 shadow-xl" aria-hidden="true">
                    <?= iqp_widget_fab_icon_svg($widgetColorValue, 56) ?>
                </div>
            </div>
        </div>

        <div class="client-save-bar">
            <button type="submit" class="w-full max-w-2xl mx-auto block h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 transition-transform">Save Changes</button>
        </div>
    </form>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <section class="mt-xl pb-8">
        <div class="bg-error-container/10 border border-error/30 rounded-2xl p-md">
            <h2 class="font-title text-title-md text-error mb-xs">Delete this bot (admin only)</h2>
            <p class="text-body-md text-on-surface-variant mb-md">
                Permanently removes <?= sanitize($bot['name']) ?>, all its leads, and conversation history.
                <?php if ($botCount <= 1): ?> You will be sent back to setup to create a new bot.<?php endif; ?>
            </p>
            <form method="POST" onsubmit="return confirm('Delete this bot and all its leads? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="delete_bot"/>
                <button type="submit" class="w-full h-12 rounded-xl bg-error-container text-on-error-container font-title text-title-md active:scale-95">
                    Delete Bot
                </button>
            </form>
        </div>
    </section>
    <?php endif; ?>
<?php client_layout_end(); ?>
<?php client_shell_end(); ?>

<script src="/assets/js/bot-setup.js?v=<?= @filemtime(__DIR__ . '/../assets/js/bot-setup.js') ?: time() ?>"></script>
<?php if ($botMutated): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof BotSync !== 'undefined') {
        BotSync.notify('bot:updated', <?= (int) $botId ?>);
    }
});
</script>
<?php endif; ?>
</body>
</html>
