<?php
/**
 * Admin navigation — prototype Internal Admin Panel.
 */
declare(strict_types=1);

require_once __DIR__ . '/iqp-ui.php';

$activeTab = $activeTab ?? 'home';
$adminUser = function_exists('get_user') ? get_user() : ['name' => 'Admin'];
$name = trim((string) ($adminUser['name'] ?? 'Admin'));
$map = [
    'home' => 'dashboard',
    'clients' => 'businesses',
    'subscriptions' => 'billing',
    'training' => 'ai',
    'conversation-intelligence' => 'ai',
    'whatsapp' => 'integrations',
    'leads' => 'conversations',
    'bots' => 'businesses',
    'updates' => 'announcements',
    'account' => 'settings',
    'plans' => 'billing',
    'billing' => 'billing',
];
$iqpActive = $map[$activeTab] ?? $activeTab;
$searchQ = sanitize((string) ($_GET['gq'] ?? $_GET['q'] ?? ''));

echo '<div class="iqp-overlay" data-iqp-close></div>';
echo '<div class="iqp-mobile-bar"><div class="iqp-mobile-bar__brand">' . iqp_admin_brand_logo(28, 'iqp-admin-brand-logo--mobile') . '</div>';
echo '<button type="button" class="icon-btn" data-iqp-open>' . iqp_icon_svg('menu') . '</button></div>';
echo '<aside class="sidebar" id="adminSidebar">';
echo '<div class="sidebar__brand"><a class="brand-logo brand-logo--admin" href="/admin/dashboard">';
echo iqp_admin_brand_logo(34, 'iqp-admin-brand-logo--sidebar');
echo '<div class="brand-sub">INTERNAL ADMIN PANEL</div></a></div>';
echo '<nav class="sidebar__nav">';
foreach (iqp_admin_nav() as $item) {
    [$id, $href, $label, $icon] = $item;
    $on = $id === $iqpActive ? ' is-active' : '';
    echo '<a class="nav-item' . $on . '" href="' . sanitize($href) . '">' . iqp_icon_svg($icon) . '<span>' . sanitize($label) . '</span></a>';
}
echo '</nav><div class="sidebar__foot"><div class="help-card"><h5>Need Help?</h5><p>Internal Support Team</p>';
echo '<p class="mail">' . sanitize(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'support@iqpigeon.com') . '</p>';
echo '<a class="btn btn--primary btn--block btn--sm" style="margin-top:12px" href="/admin/tickets">Create Ticket</a></div></div></aside>';
echo '<header class="topbar"><form class="topbar__search" method="get" action="/admin/businesses">';
echo iqp_icon_svg('search') . '<input name="q" placeholder="Search businesses, users, tickets..." value="' . $searchQ . '"/></form>';
echo '<div class="topbar__spacer"></div>';
echo '<a class="icon-btn" href="/admin/announcements">' . iqp_icon_svg('bell') . '</a>';
echo '<div class="topbar__user"><span class="avatar">' . sanitize(iqp_initials($name)) . '</span>';
echo '<div><div class="nm">' . sanitize($name) . '</div><div class="role">Super Admin</div></div>';
echo '<a class="muted small" href="/logout">Logout</a></div></header>';
echo '<script>document.body.classList.add("admin-app");</script>';
