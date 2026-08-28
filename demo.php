<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/db.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/auth.php';



$activePage = 'demo';

$demoBot = get_demo_bot();

$repName = $demoBot ? get_bot_rep_name($demoBot) : '';

$waLabel = defined('WHATSAPP_DEMO_LABEL') && WHATSAPP_DEMO_LABEL !== '' ? WHATSAPP_DEMO_LABEL : 'Sareen';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Live Demo') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">Live demo</span>
        <h1 class="v2-mkt-hero__title">Chat with our <em>AI rep.</em></h1>
        <p class="v2-mkt-hero__lead">Same engine as WhatsApp and web — trained on real business knowledge. No signup.</p>

        <?php if (whatsapp_demo_available()): ?>
        <div class="v2-mkt-hero__actions">
            <?php $variant = 'hippo-hero'; include __DIR__ . '/includes/marketing-whatsapp-demo.php'; ?>
            <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--outline">
                <span class="material-symbols-outlined">forum</span> Website Chat
            </a>
        </div>
        <p class="v2-mkt-hero__meta">WhatsApp demo · Message <?= sanitize($waLabel) ?> on +92 320 4522667</p>
        <?php endif; ?>

        <?php if ($demoBot): ?>
        <p class="v2-mkt-hero__meta">Use the chat button bottom-right to talk with <?= sanitize($repName) ?>.</p>
        <div class="v2-glass inline-flex items-center gap-sm px-lg py-md mt-md">
            <span class="material-symbols-outlined text-primary text-2xl animate-pulse">chat</span>
            <span class="text-body-md text-on-surface-variant">Click the green chat icon →</span>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap max-w-3xl">
        <?php if ($demoBot): ?>
        <div class="v2-card v2-mkt-card fade-up">
            <h2 class="v2-section-title hip-title mb-sm"><?= sanitize($repName) ?> · <?= sanitize($demoBot['name']) ?></h2>
            <p>Ask about products, pricing, or book a call — leads appear in your dashboard when you run your own bot.</p>
        </div>
        <?php else:
            $demoUnavailable = get_demo_bot_unavailable_reason();
            $configuredDemoId = get_configured_demo_bot_id();
        ?>
        <div class="v2-card v2-mkt-success fade-up">
            <span class="material-symbols-outlined text-outline text-5xl mb-md">smart_toy</span>
            <h2 class="v2-section-title hip-title mb-sm">Demo bot not configured yet</h2>
            <p class="v2-section-lead hip-lead hip-lead--center mb-md">
                <?= sanitize($demoUnavailable !== '' ? $demoUnavailable : 'Enable the website widget on a bot, or set it as the public demo in Admin.') ?>
            </p>
            <?php if ($configuredDemoId > 0): ?>
            <p class="v2-mkt-hero__meta mb-lg">Configured demo bot ID: <code><?= (int) $configuredDemoId ?></code></p>
            <?php endif; ?>
            <div class="v2-mkt-hero__actions">
            <?php if (get_user() && (get_user()['role'] ?? '') === 'admin'): ?>
                <a href="/admin/bots" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">Admin → All Bots</a>
            <?php elseif (get_user()): ?>
                <a href="/client/bot-setup" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">Configure Your Bot</a>
            <?php else: ?>
                <a href="/register" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">Start Free Trial</a>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
