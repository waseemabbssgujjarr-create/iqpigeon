<?php
/**
 * Shared marketing site header + mobile bottom icon nav (see marketing-mobile-nav.php).
 * @var string $activePage home|features|how-it-works|pricing|demo|about|contact|integrations
 */
$activePage = $activePage ?? 'home';
$loggedIn = get_user();
$apkReady = android_apk_available();
$waDemoHref = whatsapp_demo_href();

$navItems = [
    'home'     => ['label' => 'Home',     'href' => '/#hero'],
    'features' => ['label' => 'Features', 'href' => '/#features'],
    'pricing'  => ['label' => 'Pricing',  'href' => '/#pricing'],
    'demo'     => ['label' => 'Demo',     'href' => '/#demo'],
    'contact'  => ['label' => 'Contact',  'href' => '/#contact'],
];
?>
<header id="marketing-header" class="marketing-header z-50 safe-top">
    <div class="px-edge-margin py-md flex items-center justify-between max-w-7xl mx-auto gap-sm">
        <a href="/#hero" class="inline-flex items-center group nav-scroll shrink-0 min-w-0">
            <?= brand_logo_markup('brand-logo-img transition-transform duration-300 group-hover:scale-105') ?>
        </a>

        <nav class="hidden lg:flex items-center gap-xs">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= sanitize($item['href']) ?>"
                   data-nav-section="<?= sanitize($key) ?>"
                   class="nav-link nav-scroll px-md py-sm rounded-xl text-body-md transition-all duration-300 <?= $activePage === $key ? 'bg-primary-container/20 text-primary font-medium' : 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface' ?>">
                    <?= sanitize($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="hidden lg:flex items-center gap-sm shrink-0">
            <?php $variant = 'icon'; include __DIR__ . '/theme-toggle.php'; ?>
            <?php if ($waDemoHref): ?>
                <a href="<?= sanitize($waDemoHref) ?>" target="_blank" rel="noopener noreferrer" class="marketing-header-btn marketing-header-btn--whatsapp">
                    <span class="material-symbols-outlined text-lg">chat</span>
                    Live Demo
                </a>
            <?php else: ?>
                <a href="/#demo" class="marketing-header-btn marketing-header-btn--secondary">
                    <span class="material-symbols-outlined text-lg">forum</span>
                    Live Demo
                </a>
            <?php endif; ?>
            <?php if ($apkReady): ?>
                <a href="<?= sanitize(android_apk_url()) ?>" class="marketing-header-btn marketing-header-btn--outline">
                    <span class="material-symbols-outlined text-lg">android</span>
                    Get App
                </a>
            <?php endif; ?>
            <?php if ($loggedIn): ?>
                <a href="/client/dashboard" class="marketing-header-btn marketing-header-btn--outline">Dashboard</a>
            <?php else: ?>
                <a href="/login" class="login-btn marketing-header-btn marketing-header-btn--outline">Login</a>
                <a href="/register" class="marketing-header-btn marketing-header-btn--primary">Join now</a>
            <?php endif; ?>
        </div>

        <div class="flex lg:hidden items-center gap-xs shrink-0">
            <?php if (!$loggedIn): ?>
            <a href="/register" class="marketing-header-btn marketing-header-btn--primary marketing-header-btn--trial-mobile">Trial</a>
            <?php endif; ?>
            <button type="button"
                    class="marketing-mobile-menu-btn marketing-header-btn marketing-header-btn--icon marketing-header-btn--outline"
                    data-marketing-menu-open
                    aria-controls="marketing-mobile-menu"
                    aria-expanded="false"
                    aria-label="Open menu">
                <span class="material-symbols-outlined">menu</span>
            </button>
        </div>
    </div>
</header>
<?php include __DIR__ . '/marketing-mobile-menu.php'; ?>
