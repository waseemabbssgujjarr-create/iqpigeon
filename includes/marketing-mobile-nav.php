<?php
/**
 * Marketing site mobile bottom navigation — icon-only, color change on active.
 * @var string $activePage
 */
$activePage = $activePage ?? 'home';

$bottomNavItems = [
    'home'         => ['icon' => 'home',         'href' => '/#hero',         'label' => 'Home'],
    'features'     => ['icon' => 'star',         'href' => '/#features',     'label' => 'Features'],
    'how-it-works' => ['icon' => 'route',        'href' => '/#how-it-works', 'label' => 'How It Works'],
    'integrations' => ['icon' => 'hub',          'href' => '/#integrations', 'label' => 'Integrations'],
    'pricing'      => ['icon' => 'payments',     'href' => '/#pricing',      'label' => 'Pricing'],
    'demo'         => ['icon' => 'forum',        'href' => '/#demo',         'label' => 'Demo'],
    'about'        => ['icon' => 'info',         'href' => '/#about',        'label' => 'About'],
    'contact'      => ['icon' => 'mail',         'href' => '/#contact',      'label' => 'Contact'],
];
?>
<nav id="marketing-bottom-nav" class="marketing-bottom-nav lg:hidden" aria-label="Mobile navigation">
    <?php foreach ($bottomNavItems as $key => $item):
        $active = $activePage === $key;
    ?>
    <a href="<?= sanitize($item['href']) ?>"
       data-nav-section="<?= sanitize($key) ?>"
       class="marketing-nav-icon nav-scroll<?= $active ? ' active' : '' ?>"
       aria-label="<?= sanitize($item['label']) ?>"
       title="<?= sanitize($item['label']) ?>"
       <?= $active ? 'aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined" aria-hidden="true"><?= sanitize($item['icon']) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
