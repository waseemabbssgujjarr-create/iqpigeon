<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/platform-training.php';

$user = require_login();
ensure_bots_schema();

if (!needs_onboarding((int) $user['id']) && empty($_GET['force'])) {
    if (needs_whatsapp_connect((int) $user['id']) && empty($_GET['skip_wa'])) {
        redirect('/client/connect-whatsapp');
    }
    redirect('/client/dashboard');
}

$step = max(1, min(4, (int) ($_POST['step'] ?? $_GET['step'] ?? 1)));
$error = '';
$botId = (int) ($_SESSION['onboarding_bot_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    if ($step === 1) {
        $repName = trim($_POST['rep_name'] ?? '');
        $brandName = trim($_POST['bot_name'] ?? '');

        if ($brandName === '' || $repName === '') {
            $error = 'Rep name and business name are required.';
        } else {
            if (!$botId) {
                $botId = db_insert(
                    'INSERT INTO bots (user_id, name, rep_name, calendly_link) VALUES (?, ?, ?, ?)',
                    'isss',
                    [(int) $user['id'], $brandName, $repName, get_bot_calendly_link(null)]
                );
                $_SESSION['onboarding_bot_id'] = $botId;
            } else {
                db_execute(
                    'UPDATE bots SET name = ?, rep_name = ? WHERE id = ? AND user_id = ?',
                    'ssii',
                    [$brandName, $repName, $botId, (int) $user['id']]
                );
            }
            redirect('/client/onboarding?step=2');
        }
    } elseif ($step === 2 && $botId) {
        $knowledgeDoc = trim($_POST['bot_knowledge'] ?? '');
        $websiteUrl = trim($_POST['website_url'] ?? '');

        if ($knowledgeDoc === '' && $websiteUrl === '') {
            $error = 'Add company knowledge or enter your website URL.';
        } else {
            db_execute(
                'UPDATE bots SET bot_knowledge = ?, website_url = ? WHERE id = ? AND user_id = ?',
                'ssii',
                [$knowledgeDoc, $websiteUrl, $botId, (int) $user['id']]
            );
            if ($websiteUrl !== '') {
                require_once __DIR__ . '/../includes/business-fetch.php';
                fetch_business_from_website($botId, (int) $user['id'], $websiteUrl, true);
            }
            redirect('/client/onboarding?step=3');
        }
    } elseif ($step === 3 && $botId) {
        $channel = $_POST['channel'] ?? '';
        $connected = false;

        if ($channel === 'widget') {
            db_execute(
                'UPDATE bots SET widget_enabled = 1 WHERE id = ? AND user_id = ?',
                'ii',
                [$botId, (int) $user['id']]
            );
            $connected = true;
        } elseif ($channel === 'whatsapp') {
            $phoneId = trim($_POST['whatsapp_phone_id'] ?? '');
            $token = trim($_POST['whatsapp_token'] ?? '');
            if ($phoneId && $token) {
                require_once __DIR__ . '/../includes/whatsapp.php';
                $v = verify_whatsapp_credentials($phoneId, $token);
                if ($v['success']) {
                    require_once __DIR__ . '/../includes/whatsapp-token.php';
                    $resolvedPhoneId = $v['phone_id'] ?? $phoneId;
                    bot_whatsapp_token_save($botId, (int) $user['id'], (string) $resolvedPhoneId, $token);
                    $connected = true;
                } else {
                    $error = $v['message'];
                }
            }
        } elseif ($channel === 'instagram') {
            $pageId = trim($_POST['instagram_page_id'] ?? '');
            $token = trim($_POST['instagram_token'] ?? '');
            if ($pageId && $token) {
                require_once __DIR__ . '/../includes/instagram.php';
                $v = verify_instagram_credentials($pageId, $token);
                if ($v['success']) {
                    db_execute(
                        'UPDATE bots SET instagram_page_id = ?, instagram_token = ?, instagram_verified = 1 WHERE id = ? AND user_id = ?',
                        'ssii',
                        [$pageId, encrypt_token($token), $botId, (int) $user['id']]
                    );
                    $connected = true;
                } else {
                    $error = $v['message'];
                }
            }
        }

        if (!$connected && !$error) {
            $error = 'Connect at least one channel or enable the website widget.';
        } elseif ($connected) {
            redirect('/client/onboarding?step=4');
        }
    } elseif ($step === 4 && $botId) {
        db_execute(
            'UPDATE bots SET is_active = 1 WHERE id = ? AND user_id = ?',
            'ii',
            [$botId, (int) $user['id']]
        );
        unset($_SESSION['onboarding_bot_id']);
        redirect('/client/dashboard?success=1');
    }
}

$step = max(1, min(4, (int) ($_GET['step'] ?? $step)));
$bot = $botId ? db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, (int) $user['id']]) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Onboarding') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface min-h-[100dvh] flex flex-col safe-top safe-bottom">
    <header class="px-edge-margin py-md safe-top max-w-3xl mx-auto w-full">
        <p class="text-label-sm text-outline uppercase tracking-wider font-label">Step <?= $step ?> of 4</p>
        <div class="flex gap-xs mt-xs">
            <?php for ($i = 1; $i <= 4; $i++): ?>
                <div class="h-1 flex-1 rounded-full <?= $i <= $step ? 'bg-primary' : 'bg-surface-container' ?>"></div>
            <?php endfor; ?>
        </div>
    </header>

    <main class="flex-1 w-full mx-auto px-edge-margin pb-32 max-w-lg sm:max-w-xl md:max-w-2xl lg:max-w-3xl">
        <?php if ($error): ?>
            <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h1 class="font-headline text-headline-mob mb-sm">Your business</h1>
            <p class="text-body-lg text-on-surface-variant mb-lg">Name your rep and brand. Add company details later from Connect after you sign in.</p>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="step" value="1"/>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Rep name</label>
                    <input type="text" name="rep_name" required placeholder="e.g. Sareen"
                           value="<?= sanitize($bot['rep_name'] ?? '') ?>"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Brand / business name</label>
                    <input type="text" name="bot_name" required placeholder="e.g. English Shoes"
                           value="<?= sanitize($bot['name'] ?? '') ?>"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                </div>
                <div class="auth-flow-footer">
                    <div class="auth-flow-footer-inner max-w-3xl">
                        <button type="submit" class="flex-1 h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 touch-manipulation">Continue</button>
                    </div>
                </div>
            </form>

        <?php elseif ($step === 2): ?>
            <h1 class="font-headline text-headline-mob mb-sm">Company knowledge</h1>
            <p class="text-body-lg text-on-surface-variant mb-lg">Add pricing, FAQs, and policies — or enter your website and we'll fetch products and content automatically.</p>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="step" value="2"/>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Website (optional — auto-fetch)</label>
                    <input type="url" name="website_url" placeholder="https://yourstore.com"
                           value="<?= sanitize($bot['website_url'] ?? '') ?>"
                           class="w-full h-14 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                </div>
                <div>
                    <label class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Company knowledge</label>
                    <textarea name="bot_knowledge" rows="12"
                              placeholder="<?= sanitize(bot_knowledge_placeholder()) ?>"
                              class="w-full px-md py-md rounded-xl bg-surface-container border-none text-body-md leading-relaxed"><?= sanitize(trim($bot['bot_knowledge'] ?? '')) ?></textarea>
                </div>
                <div class="auth-flow-footer">
                    <div class="auth-flow-footer-inner max-w-3xl">
                        <a href="/client/onboarding?step=1" class="h-14 px-md sm:px-lg rounded-xl border border-outline-variant flex items-center justify-center text-body-md active:scale-95 shrink-0 touch-manipulation">Back</a>
                        <button type="submit" class="flex-1 h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 touch-manipulation">Continue</button>
                    </div>
                </div>
            </form>

        <?php elseif ($step === 3): ?>
            <h1 class="font-headline text-headline-mob mb-sm">Connect a channel</h1>
            <p class="text-body-lg text-on-surface-variant mb-lg">Choose WhatsApp, Instagram, or enable the website widget</p>

            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="step" value="3"/>

                <div class="space-y-md">
                    <label class="onboarding-channel-card bg-surface-container-lowest rounded-2xl p-md md:p-lg border border-outline-variant cursor-pointer block w-full">
                        <input type="radio" name="channel" value="widget" class="mr-sm" checked/>
                        <span class="font-title text-title-md">Website Widget</span>
                        <p class="text-body-md text-on-surface-variant mt-xs ml-6 sm:ml-0 sm:pl-6">Enable chat on your website — no API keys needed</p>
                    </label>

                    <label class="onboarding-channel-card bg-surface-container-lowest rounded-2xl p-md md:p-lg border border-outline-variant block w-full">
                        <input type="radio" name="channel" value="whatsapp" class="mr-sm"/>
                        <span class="font-title text-title-md">WhatsApp Business</span>
                        <div class="mt-md space-y-sm hidden whatsapp-fields pl-0 sm:pl-6">
                            <input type="text" name="whatsapp_phone_id" placeholder="Phone Number ID" class="w-full h-12 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                            <input type="password" name="whatsapp_token" placeholder="Access Token" class="w-full h-12 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                        </div>
                    </label>

                    <label class="onboarding-channel-card bg-surface-container-lowest rounded-2xl p-md md:p-lg border border-outline-variant block w-full">
                        <input type="radio" name="channel" value="instagram" class="mr-sm"/>
                        <span class="font-title text-title-md">Instagram DMs</span>
                        <div class="mt-md space-y-sm hidden instagram-fields pl-0 sm:pl-6">
                            <input type="text" name="instagram_page_id" placeholder="Page ID" class="w-full h-12 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                            <input type="password" name="instagram_token" placeholder="Access Token" class="w-full h-12 px-md rounded-xl bg-surface-container border-none text-body-md"/>
                        </div>
                    </label>
                </div>

                <div class="auth-flow-footer">
                    <div class="auth-flow-footer-inner max-w-3xl">
                        <a href="/client/onboarding?step=2" class="h-14 px-md sm:px-lg rounded-xl border border-outline-variant flex items-center justify-center text-body-md active:scale-95 shrink-0 touch-manipulation">Back</a>
                        <button type="submit" class="flex-1 h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 touch-manipulation">Continue</button>
                    </div>
                </div>
            </form>
            <script>
            document.querySelectorAll('input[name="channel"]').forEach(r => {
                r.addEventListener('change', () => {
                    document.querySelector('.whatsapp-fields').classList.toggle('hidden', r.value !== 'whatsapp' || !r.checked);
                    document.querySelector('.instagram-fields').classList.toggle('hidden', r.value !== 'instagram' || !r.checked);
                    if (r.value === 'whatsapp' && r.checked) document.querySelector('.whatsapp-fields').classList.remove('hidden');
                    if (r.value === 'instagram' && r.checked) document.querySelector('.instagram-fields').classList.remove('hidden');
                });
            });
            </script>

        <?php else: ?>
            <h1 class="font-headline text-headline-mob mb-sm">Go live 🚀</h1>
            <p class="text-body-lg text-on-surface-variant mb-lg">Your rep is ready. Activate and start receiving leads.</p>
            <form method="POST" class="space-y-md">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="step" value="4"/>
                <div class="auth-flow-footer">
                    <div class="auth-flow-footer-inner max-w-3xl">
                        <a href="/client/onboarding?step=3" class="h-14 px-md sm:px-lg rounded-xl border border-outline-variant flex items-center justify-center text-body-md active:scale-95 shrink-0 touch-manipulation">Back</a>
                        <button type="submit" class="flex-1 h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 touch-manipulation">Go Live 🚀</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <script src="/assets/js/app.js"></script>
<?= client_pwa_install_script() ?>
</body>
</html>
