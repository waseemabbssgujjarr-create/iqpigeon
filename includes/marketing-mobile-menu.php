<?php
/**
 * Marketing site mobile drawer — theme, demo, login, app links.
 * @var bool|null $loggedIn
 * @var bool|null $apkReady
 * @var string|null $waDemoHref
 */
$loggedIn = $loggedIn ?? get_user();
$apkReady = $apkReady ?? android_apk_available();
$waDemoHref = $waDemoHref ?? whatsapp_demo_href();
?>
<div id="marketing-mobile-menu-overlay" class="marketing-mobile-menu-overlay lg:hidden" data-marketing-menu-close aria-hidden="true"></div>
<nav id="marketing-mobile-menu" class="marketing-mobile-menu-drawer lg:hidden" aria-label="Menu" aria-hidden="true">
    <div class="marketing-mobile-menu-drawer__head">
        <span class="marketing-mobile-menu-drawer__title">Menu</span>
        <button type="button" class="marketing-mobile-menu-drawer__close" data-marketing-menu-close aria-label="Close menu">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <div class="marketing-mobile-menu-drawer__body">
        <?php $variant = 'full'; include __DIR__ . '/theme-toggle.php'; ?>

        <div class="marketing-mobile-menu-drawer__links">
        <?php if ($waDemoHref): ?>
        <a href="<?= sanitize($waDemoHref) ?>" target="_blank" rel="noopener noreferrer"
           class="marketing-mobile-menu-link" data-marketing-menu-close>
            <span class="material-symbols-outlined">chat</span>
            <span>Try Live on WhatsApp</span>
        </a>
        <?php else: ?>
        <a href="/#demo" class="marketing-mobile-menu-link nav-scroll" data-marketing-menu-close>
            <span class="material-symbols-outlined">forum</span>
            <span>Live Demo</span>
        </a>
        <?php endif; ?>

        <?php if ($loggedIn): ?>
        <a href="/client/dashboard" class="marketing-mobile-menu-link" data-marketing-menu-close>
            <span class="material-symbols-outlined">dashboard</span>
            <span>Dashboard</span>
        </a>
        <?php else: ?>
        <a href="/login" class="marketing-mobile-menu-link login-btn" data-marketing-menu-close>
            <span class="material-symbols-outlined">login</span>
            <span>Login</span>
        </a>
        <a href="/register" class="marketing-mobile-menu-link marketing-mobile-menu-link--primary" data-marketing-menu-close>
            <span class="material-symbols-outlined">rocket_launch</span>
            <span>Start Free Trial</span>
        </a>
        <?php endif; ?>

        <?php if ($apkReady): ?>
        <a href="<?= sanitize(android_apk_url()) ?>" class="marketing-mobile-menu-link" data-marketing-menu-close>
            <span class="material-symbols-outlined">android</span>
            <span>Get Android App</span>
        </a>
        <?php endif; ?>
        </div>
    </div>
</nav>
