<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

$activePage = 'integrations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Integrations') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">Integrations</span>
        <h1 class="v2-mkt-hero__title">Your existing <em>stack.</em></h1>
        <p class="v2-mkt-hero__lead">WhatsApp, Instagram, web, Calendly, and DeepSeek — one mobile dashboard.</p>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-mkt-grid v2-mkt-grid--4 fade-up">
            <?php foreach ([
                ['chat', 'WhatsApp Business', 'Manual token or Meta Embedded Signup. Two-way AI replies.'],
                ['photo_camera', 'Instagram DMs', 'Instagram Business via Meta Graph — same AI brain.'],
                ['language', 'Website Widget', 'Embeddable chat with your brand color.'],
                ['menu_book', 'Knowledge Training', 'Text, website scrape, or PDF/doc link.'],
                ['psychology', 'DeepSeek AI', 'Warm rep persona — never robotic or menu-driven.'],
                ['event', 'Calendly', 'Auto-send booking links when leads qualify.'],
                ['notifications', 'Live Alerts', 'In-app bell + email on every new lead.'],
                ['credit_card', 'Stripe Billing', 'Starter, Growth & Agency with ' . TRIAL_DAYS . '-day trial.'],
            ] as [$icon, $title, $desc]): ?>
            <article class="v2-card v2-mkt-card">
                <span class="v2-mkt-card__icon"><span class="material-symbols-outlined"><?= $icon ?></span></span>
                <h3><?= sanitize($title) ?></h3>
                <p><?= sanitize($desc) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="widget" class="v2-section hip-section hip-section--muted scroll-mt-24">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap">
        <div class="v2-glass v2-integrations-panel fade-up">
            <span class="v2-eyebrow">Embed</span>
            <h2 class="v2-section-title hip-title">Website chat widget</h2>
            <p class="v2-section-lead hip-lead mb-lg">Add AI sales chat to any site in 30 seconds.</p>
            <pre class="v2-mkt-code mb-lg"><code>&lt;script&gt;window.SalesBotConfig = { botId: 'YOUR_BOT_ID', color: '#4aad36' };&lt;/script&gt;
&lt;script src="<?= sanitize(APP_URL) ?>/assets/js/chat-widget.js" async&gt;&lt;/script&gt;</code></pre>
            <div class="v2-mkt-hero__actions" style="justify-content:flex-start;">
                <a href="/register" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">Get Embed Code</a>
                <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--outline">
                    <span class="material-symbols-outlined">forum</span> Try Demo First
                </a>
            </div>
        </div>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-mkt-split fade-up">
            <div class="v2-card v2-mkt-card v2-mkt-card--left">
                <h2 class="v2-section-title hip-title">WhatsApp setup</h2>
                <p class="v2-section-lead hip-lead mb-md">Two ways to connect:</p>
                <ul class="v2-checklist hip-checklist">
                    <li><span class="material-symbols-outlined">key</span> <strong>Manual</strong> — Meta Cloud API token + phone ID</li>
                    <li><span class="material-symbols-outlined">login</span> <strong>Embedded Signup</strong> — Meta OAuth in one click</li>
                </ul>
            </div>
            <div class="v2-card v2-mkt-card v2-mkt-card--left">
                <h2 class="v2-section-title hip-title">Email &amp; notifications</h2>
                <p class="v2-section-lead hip-lead">SMTP for password resets, lead alerts, and updates. In-app bell for instant mobile notifications.</p>
            </div>
        </div>
        <div class="v2-logo-strip fade-up mt-xl" aria-label="Supported channels">
            <?php foreach (['WhatsApp', 'Instagram', 'Web Widget', 'Calendly', 'Stripe', 'PayPak'] as $logo): ?>
            <span class="v2-logo-strip__item"><?= sanitize($logo) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="v2-cta-band hip-cta-band fade-up">
    <h2>See integrations in action.</h2>
    <p>Train the bot with your business — no signup.</p>
    <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--white">
        <span class="material-symbols-outlined">forum</span> Train &amp; Try Live Demo
    </a>
</section>

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
