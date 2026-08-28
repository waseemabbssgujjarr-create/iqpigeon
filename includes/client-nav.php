<?php
/**
 * Client navigation — prototype sidebar (Home, WhatsApp, Assistant, Shop…).
 */
declare(strict_types=1);

require_once __DIR__ . '/iqp-ui.php';

$activeTab = $activeTab ?? 'home';
$navUser = function_exists('get_user') ? get_user() : null;
if (!is_array($navUser)) {
    $navUser = ['name' => 'Account', 'subscription_plan' => 'starter'];
}
$navMap = [
    'home' => 'home',
    'whatsapp' => 'whatsapp',
    'train' => 'assistant',
    'catalog' => 'shop',
    'orders' => 'orders',
    'leads' => 'leads',
    'billing' => 'billing',
    'notifications' => 'updates',
    'settings' => 'settings',
    'analytics' => 'analytics',
    'team' => 'team',
    'integrations' => 'integrations',
];
$iqpActive = $navMap[$activeTab] ?? $activeTab;

$name = trim((string) ($navUser['name'] ?? 'Account'));
$plan = iqp_plan_label((string) ($navUser['subscription_plan'] ?? 'starter'));
$until = '';
if (!empty($navUser['subscription_expires_at'])) {
    $until = date('d M, Y', strtotime((string) $navUser['subscription_expires_at']));
}
$updates = 0;
try {
    if (!empty($navUser['id'])) {
        $updates = (int) (db_fetch('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0', 'i', [(int) $navUser['id']])['cnt'] ?? 0);
    }
} catch (Throwable $e) {
    $updates = 0;
}

echo '<div class="lg:hidden sticky top-0 z-30 bg-white border-b border-slate-200 flex items-center justify-between px-4 py-3">';
echo iqp_client_brand_logo(28, 'iqp-client-brand-logo--mobile');
echo '<button type="button" class="p-2 rounded-lg border border-slate-200" data-iqp-open>' . iqp_icon_svg('menu', '#334155') . '</button></div>';
echo '<div id="sidebarOverlay" class="hidden fixed inset-0 bg-black/40 z-40 lg:hidden" data-iqp-close></div>';
echo '<aside id="mobileSidebar" class="w-[240px] lg:w-[220px] shrink-0 bg-white border-r border-slate-200 flex flex-col h-screen fixed lg:sticky top-0 left-0 z-50 -translate-x-full lg:translate-x-0 transition-transform duration-200 overflow-y-auto">';
echo '<div class="px-5 pt-6 pb-5 flex items-center justify-between">';
echo iqp_client_brand_logo(36, 'iqp-client-brand-logo--sidebar');
echo '<button type="button" class="lg:hidden p-1 text-slate-400" data-iqp-close>' . iqp_icon_svg('x') . '</button></div>';
echo '<nav class="flex-1 px-3 space-y-1 overflow-y-auto">';
foreach (iqp_user_nav() as $item) {
    [$id, $href, $label, $icon] = $item;
    $on = $id === $iqpActive;
    $cls = $on
        ? 'flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-[14px] font-medium bg-[#1FA855] text-white shadow-sm'
        : 'flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-[14px] font-medium text-slate-600 hover:bg-slate-100';
    $stroke = $on ? 'white' : iqp_nav_color($id);
    echo '<a href="' . sanitize($href) . '" class="' . $cls . '">' . iqp_icon_svg($icon, $stroke) . '<span>' . sanitize($label) . '</span>';
    if ($id === 'updates' && $updates > 0) {
        echo '<span class="ml-auto bg-[#1FA855] text-white text-[11px] font-semibold rounded-full w-5 h-5 flex items-center justify-center">' . $updates . '</span>';
    }
    echo '</a>';
}
echo '</nav><div class="px-3 pb-3 space-y-3">';
echo '<div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5"><div class="text-[11px] text-slate-500">Current Plan</div>';
echo '<div class="text-[14px] font-bold text-[#1FA855] mt-0.5">' . sanitize($plan) . '</div>';
if ($until !== '') {
    echo '<div class="text-[11px] text-slate-500 mt-2">Active until<br/><span class="text-slate-700 font-medium">' . sanitize($until) . '</span></div>';
}
echo '<a href="/client/billing" class="mt-3 w-full text-[12.5px] font-semibold text-[#1FA855] border border-[#1FA855] rounded-lg py-1.5 hover:bg-green-50 block text-center">Manage Subscription</a></div>';
echo '<div class="flex items-center gap-2.5 px-1.5 py-2 border-t border-slate-100 pt-3">';
echo '<div class="w-8 h-8 rounded-full bg-slate-700 text-white text-[12px] font-semibold flex items-center justify-center shrink-0">' . sanitize(iqp_initials($name)) . '</div>';
echo '<div class="leading-tight min-w-0"><div class="text-[13px] font-semibold text-slate-800 truncate">' . sanitize($name) . '</div><div class="text-[11px] text-slate-400">Owner</div></div>';
echo '<a href="/logout" class="ml-auto text-[11px] text-slate-400 shrink-0">Logout</a></div></div></aside>';

function client_nav_items(): array
{
    return [
        ['home', '/client/dashboard', 'home', 'Home'],
        ['whatsapp', '/client/whatsapp-settings', 'chat', 'WhatsApp'],
        ['train', '/client/assistant', 'school', 'Assistant'],
        ['catalog', '/client/catalog', 'storefront', 'Shop'],
        ['orders', '/client/orders', 'package_2', 'Orders'],
        ['leads', '/client/leads', 'forum', 'Leads'],
        ['billing', '/client/billing', 'credit_card', 'Billing'],
        ['notifications', '/client/notifications', 'notifications', 'Updates'],
        ['settings', '/client/settings', 'settings', 'Settings'],
    ];
}

function client_mobile_bottom_nav_keys(): array
{
    return ['home', 'whatsapp', 'train', 'leads', 'settings'];
}

function client_mobile_menu_button(): void
{
}

function client_render_mobile_menu_drawer(): void
{
}
