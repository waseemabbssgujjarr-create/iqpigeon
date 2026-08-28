<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

$activePage = 'features';

$features = [
    ['face_3', 'Human-Like AI Rep', 'WhatsApp-style tone. Never says "I\'m a bot."', true, '/demo', 'See it live'],
    ['menu_book', 'Train Your Knowledge Base', 'Paste text, link a site, or add a PDF.', false, '/demo', 'Try training on demo'],
    ['forum', 'WhatsApp + Instagram + Web', 'One dashboard. One AI brain.', false, '/integrations', 'View integrations'],
    ['psychology', 'Smart Lead Qualification', 'Custom questions and 0–100 scoring.', false, '/how-it-works#scoring', 'How qualification works'],
    ['event', 'Auto Calendly Booking', 'Calendar link sent when leads qualify.', false, '/how-it-works#booking', 'See booking flow'],
    ['notifications', 'Live Notifications', 'Bell alerts on every new lead.', false, null, null],
    ['supervisor_account', 'Human Takeover', 'Pause the bot on high-value deals.', false, null, null],
    ['phone_android', 'Mobile-First Dashboard', 'Manage everything from your phone.', false, null, null],
    ['shield', 'Agency-Ready Admin', 'Clients, bots, leads — one panel.', false, '/about#security', 'Security details'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Features') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">Platform</span>
        <h1 class="v2-mkt-hero__title">One AI. <em>All channels.</em></h1>
        <p class="v2-mkt-hero__lead">Human-like replies on WhatsApp, Instagram, and web — not flow menus.</p>
        <div class="v2-mkt-hero__actions">
            <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">
                <span class="material-symbols-outlined">forum</span> Train &amp; Try Demo
            </a>
            <a href="/register" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--outline">Start Free Trial</a>
        </div>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-features hip-features fade-up">
            <?php foreach ($features as [$icon, $title, $desc, $highlight, $link, $linkLabel]): ?>
            <article class="v2-card v2-feature-card hip-feature<?= $highlight ? ' v2-feature-card--highlight hip-feature--highlight' : ' v2-card--hover' ?>">
                <div class="hip-feature__icon"><span class="material-symbols-outlined"><?= $icon ?></span></div>
                <h3><?= sanitize($title) ?></h3>
                <p><?= sanitize($desc) ?></p>
                <?php if ($link): ?>
                <a href="<?= sanitize($link) ?>" class="v2-mkt-card__link"><?= sanitize($linkLabel) ?> <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
                <?php elseif ($title === 'Human Takeover'): ?>
                <span class="inline-flex items-center gap-xs bg-surface-container-lowest px-sm py-1 rounded-full border border-outline-variant text-label-sm font-label mt-sm"><span class="w-2 h-2 bg-primary rounded-full ai-pulse"></span> 60-second pause mode</span>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="v2-section hip-section hip-section--muted">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap">
        <div class="v2-mkt-split fade-up">
            <div>
                <span class="v2-eyebrow">No code</span>
                <h2 class="v2-section-title hip-title">Built for sales teams</h2>
                <p class="v2-section-lead hip-lead">Train in minutes — paste your offer, connect channels, go live.</p>
                <ul class="v2-checklist hip-checklist">
                    <li><span class="material-symbols-outlined">check_circle</span> Text, website, or PDF training</li>
                    <li><span class="material-symbols-outlined">check_circle</span> WhatsApp manual or Meta signup</li>
                    <li><span class="material-symbols-outlined">check_circle</span> Live demo before signup</li>
                </ul>
            </div>
            <div class="v2-card v2-dashboard hip-dashboard">
                <div class="v2-section-header">
                    <span class="v2-section-header__title">Live dashboard</span>
                    <span class="v2-dashboard__status">● Online</span>
                </div>
                <div class="hip-dashboard__bar">
                    <?php foreach ([40, 65, 45, 80, 55, 90, 70] as $h): ?>
                    <span style="height:<?= $h ?>%"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="v2-cta-band hip-cta-band fade-up">
    <h2>See it with your business.</h2>
    <p>Paste your offer on the demo — no signup required.</p>
    <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--white">
        <span class="material-symbols-outlined">forum</span> Open Live Demo
    </a>
</section>

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
