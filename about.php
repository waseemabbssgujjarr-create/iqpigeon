<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

$activePage = 'about';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('About Us') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">About</span>
        <h1 class="v2-mkt-hero__title">AI that sells <em>human.</em></h1>
        <p class="v2-mkt-hero__lead">DeepSeek + WhatsApp UX — qualify, book, and notify from your phone.</p>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-mkt-split fade-up">
            <div class="v2-about-copy">
                <h2 class="v2-section-title hip-title">Our mission</h2>
                <p class="v2-section-lead hip-lead">Slow replies kill deals. Every inquiry gets an instant, human-quality response — trained on your business, 24/7.</p>
                <p class="v2-section-lead hip-lead">Rapport first, qualify second. Human takeover, live notifications, and agency admin included.</p>
            </div>
            <div class="v2-mkt-grid v2-mkt-grid--2">
                <?php foreach ([['3', 'Channels unified'], ['24/7', 'AI qualifying'], ['100%', 'Your brand voice'], ['60s', 'Human takeover']] as [$val, $lbl]): ?>
                <div class="v2-card v2-mkt-stat">
                    <p class="v2-mkt-stat__value"><?= sanitize($val) ?></p>
                    <p class="v2-mkt-stat__label"><?= sanitize($lbl) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<section class="v2-section hip-section hip-section--muted">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap">
        <div class="v2-section-head hip-section-head fade-up">
            <h2 class="v2-section-title hip-title">What makes us different</h2>
        </div>
        <div class="v2-mkt-grid v2-mkt-grid--3 fade-up">
            <?php foreach ([
                ['face_3', 'Human-Like AI', 'Warm WhatsApp-style messages. Never says "I\'m a bot."'],
                ['menu_book', 'Train Your Knowledge', 'Paste text, your website, or a PDF — speaks as your brand.'],
                ['notifications', 'Live Alerts', 'Bell + email the moment a lead messages you.'],
            ] as [$icon, $title, $desc]): ?>
            <article class="v2-card v2-mkt-card">
                <span class="material-symbols-outlined v2-mkt-card__icon"><?= $icon ?></span>
                <h3><?= sanitize($title) ?></h3>
                <p><?= sanitize($desc) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="security" class="v2-section v2-section--alt hip-section hip-section--alt scroll-mt-24">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-section-head hip-section-head fade-up">
            <h2 class="v2-section-title hip-title">Security &amp; trust</h2>
        </div>
        <div class="v2-mkt-grid v2-mkt-grid--3 fade-up">
            <?php foreach ([
                ['lock', 'Encrypted Credentials', 'WhatsApp and Instagram tokens encrypted at rest with AES-256.'],
                ['group', 'Tenant Isolation', 'Every client\'s data scoped by user ID — zero cross-account visibility.'],
                ['verified_user', 'Access Control', 'Role-based admin/client separation with CSRF and rate limiting.'],
            ] as [$icon, $title, $desc]): ?>
            <article class="v2-card v2-mkt-card v2-mkt-card--left">
                <span class="material-symbols-outlined v2-mkt-card__icon"><?= $icon ?></span>
                <h3><?= sanitize($title) ?></h3>
                <p><?= sanitize($desc) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="v2-cta-band hip-cta-band fade-up">
    <h2>Train before you buy.</h2>
    <p>Paste your business info and chat — no signup required.</p>
    <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--white">
        <span class="material-symbols-outlined">forum</span> Start Interactive Demo
    </a>
</section>

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
