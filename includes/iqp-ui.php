<?php
/**
 * IQPigeon prototype UI shell — Inter + prototype CSS for client and admin.
 */
declare(strict_types=1);

function iqp_css_ver(string $rel): int
{
    $path = dirname(__DIR__) . '/assets/' . ltrim($rel, '/');
    return (int) (@filemtime($path) ?: time());
}

function iqp_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'IQ';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $a = mb_strtoupper(mb_substr($parts[0] ?? 'I', 0, 1));
    $b = mb_strtoupper(mb_substr($parts[1] ?? ($parts[0] ?? 'Q'), 0, 1));
    return $a . $b;
}

function iqp_rel_time(?string $dt): string
{
    if ($dt === null || $dt === '' || $dt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hr ago';
    }
    if ($diff < 172800) {
        return 'Yesterday';
    }
    return date('d M, Y', $ts);
}

function iqp_pct(int $now, int $prev): string
{
    if ($prev <= 0) {
        return $now > 0 ? '↑ 100%' : '0%';
    }
    $pct = (int) round((($now - $prev) / $prev) * 100);
    if ($pct > 0) {
        return '↑ ' . $pct . '%';
    }
    if ($pct < 0) {
        return '↓ ' . abs($pct) . '%';
    }
    return '0%';
}

function iqp_pct_class(int $now, int $prev): string
{
    if ($prev <= 0) {
        return $now > 0 ? 'text-emerald-600' : 'text-slate-400';
    }
    return $now >= $prev ? 'text-emerald-600' : 'text-red-500';
}

function iqp_plan_label(?string $plan): string
{
    $plan = strtolower(trim((string) $plan));
    $map = [
        'starter' => 'Starter Plan',
        'pro' => 'Pro Plan',
        'growth' => 'Growth Plan',
        'business' => 'Business Plan',
        'agency' => 'Agency Plan',
        'enterprise' => 'Enterprise Plan',
    ];
    return $map[$plan] ?? (ucfirst($plan !== '' ? $plan : 'starter') . ' Plan');
}

function iqp_user_nav(): array
{
    return [
        ['home', '/client/dashboard', 'Home', 'home'],
        ['whatsapp', '/client/whatsapp-settings', 'WhatsApp', 'wa'],
        ['train', '/client/training', 'Train', 'layers'],
        ['shop', '/client/catalog', 'Shop', 'bag'],
        ['orders', '/client/orders', 'Orders', 'box'],
        ['leads', '/client/leads', 'Leads', 'user'],
        ['analytics', '/client/analytics', 'Analytics', 'chart'],
        ['integrations', '/client/integrations', 'Integrations', 'plug'],
        ['team', '/client/team', 'Team', 'users'],
        ['billing', '/client/billing', 'Billing', 'card'],
        ['updates', '/client/notifications', 'Updates', 'bell'],
        ['settings', '/client/settings', 'Settings', 'gear'],
    ];
}

function iqp_admin_nav(): array
{
    return [
        ['dashboard',     '/admin/dashboard',     'Dashboard',                    'grid'],
        ['businesses',    '/admin/businesses',    'Businesses',                   'building'],
        ['users',         '/admin/users',         'Internal Team',                'users'],
        ['ai',            '/admin/ai',            'AI Training &amp; Templates',  'brain'],
        ['billing',       '/admin/subscriptions', 'Subscription &amp; Billing',   'card'],
        ['integrations',  '/admin/integrations',  'Integrations',                 'plug'],
        ['conversations', '/admin/conversations', 'Conversations',                'chats'],
        ['orders',        '/admin/orders',        'Orders',                       'bag'],
        ['tickets',       '/admin/tickets',       'Support Tickets',              'ticket'],
        ['announcements', '/admin/announcements', 'Announcements',                'bell'],
        ['analytics',     '/admin/analytics',     'Analytics',                    'chart'],
        ['roles',         '/admin/roles',         'Roles &amp; Permissions',      'shield'],
        ['settings',      '/admin/settings',      'System Settings',              'gear'],
        ['audit',         '/admin/audit',         'Audit Logs',                   'list'],
    ];
}

/**
 * Best-suggested brand/semantic colour per nav item id, used so sidebar icons
 * are colourful instead of flat grey/black (kept subtle when inactive; the
 * active state still uses a solid green pill + white icon).
 */
function iqp_nav_color(string $id): string
{
    static $map = [
        // admin
        'dashboard'     => '#2563eb',
        'businesses'    => '#6366f1',
        'users'         => '#0891b2',
        'ai'            => '#7c3aed',
        'billing'       => '#16a34a',
        'integrations'  => '#ea580c',
        'conversations' => '#0ea5e9',
        'orders'        => '#d97706',
        'tickets'       => '#dc2626',
        'announcements' => '#db2777',
        'analytics'     => '#0d9488',
        'roles'         => '#475569',
        'settings'      => '#64748b',
        'audit'         => '#7c3aed',
        // client
        'home'          => '#2563eb',
        'whatsapp'      => '#25D366',
        'train'         => '#7c3aed',
        'assistant'     => '#7c3aed',
        'shop'          => '#ea580c',
        'orders'        => '#d97706',
        'leads'         => '#0891b2',
        'inbox'         => '#0ea5e9',
        'analytics'     => '#0d9488',
        'broadcasts'    => '#db2777',
        'integrations'  => '#ea580c',
        'team'          => '#6366f1',
        'billing'       => '#16a34a',
        'updates'       => '#db2777',
        'settings'      => '#64748b',
    ];
    return $map[$id] ?? '#64748b';
}

function iqp_icon_svg(string $name, string $stroke = 'currentColor'): string
{
    $paths = [
        'home' => '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5Z"/>',
        'wa' => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/>',
        'layers' => '<path d="M12 3 3 7l9 4 9-4-9-4Z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>',
        'bag' => '<path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',
        'box' => '<path d="M4 5h16v3H4z"/><path d="M4 5v14a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V5"/><path d="M9 12h6"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'card' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        'bell' => '<path d="M12 4a5 5 0 0 0-5 5v3.5l-1.5 3h13L17 12.5V9a5 5 0 0 0-5-5Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h.01M15 8h.01M9 12h.01M15 12h.01M9 16h6"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'brain' => '<path d="M9.5 3A2.5 2.5 0 0 0 7 5.5v.5a3 3 0 0 0-1 5.83V13a3 3 0 0 0 3 3h.5"/><path d="M14.5 3A2.5 2.5 0 0 1 17 5.5v.5a3 3 0 0 1 1 5.83V13a3 3 0 0 1-3 3h-.5"/>',
        'plug' => '<path d="M9 2v6M15 2v6"/><path d="M7 8h10v3a5 5 0 0 1-10 0Z"/><path d="M12 16v6"/>',
        'chats' => '<path d="M14 9a2 2 0 0 1-2 2H7l-3 3V4a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2Z"/><path d="M18 9h1a2 2 0 0 1 2 2v9l-3-3h-5a2 2 0 0 1-2-2"/>',
        'ticket' => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4Z"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l3-4 3 3 4-6"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        /* ---- extra icons used in redesigned pages ---- */
        'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chevdown'   => '<path d="m6 9 6 6 6-6"/>',
        'dollar'     => '<circle cx="12" cy="12" r="9"/><path d="M9 12h6M12 9v6"/>',
        'crown'      => '<path d="M2 20h20M4 9l4 4 4-8 4 8 4-4 1 11H3z"/>',
        'warn'       => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'send'       => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'filetext'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'chat'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'check'      => '<polyline points="20 6 9 17 4 12"/>',
        'checkcircle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'clock'      => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'slash'      => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
        'refresh'    => '<path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'eye'        => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'trash'      => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'mail'       => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'sparkles'   => '<path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/>',
        'globe'      => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    ];
    $d = $paths[$name] ?? $paths['grid'];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . sanitize($stroke) . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

function iqp_logo_svg(int $size = 36): string
{
    return '<svg viewBox="0 0 64 64" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
        . '<path d="M6 40 C2 41 1 45 3 47 C7 46 12 44 15 42 Z" fill="#3f4a5a"/>'
        . '<path d="M14 34 C12 22 20 13 31 12 C43 11 52 19 52 31 C52 42 44 50 33 50 C22 50 15 44 14 34 Z" fill="#4a5568"/>'
        . '<path d="M28 26 C33 27 37 33 37 40 C37 45 34 48 30 48 C25 47 22 40 23 32 C24 28 26 26 28 26 Z" fill="#1FA855"/>'
        . '<circle cx="42" cy="20" r="12" fill="#4a5568"/>'
        . '<circle cx="45" cy="19" r="3.4" fill="#0f172a"/>'
        . '<circle cx="46.1" cy="17.9" r="1.1" fill="#fff"/>'
        . '<path d="M53 20 L61 22 L53 25 Z" fill="#f59e0b"/>'
        . '</svg>';
}

function iqp_client_brand_logo(int $height = 32, string $extraClass = ''): string
{
    if (!function_exists('brand_logo_markup')) {
        $helpers = dirname(__DIR__) . '/includes/helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    if (!function_exists('brand_logo_markup')) {
        return '<a href="/client/dashboard" class="iqp-client-brand-link">' . iqp_logo_svg($height) . '</a>';
    }

    $class = trim('iqp-client-brand-logo ' . $extraClass);
    return '<a href="/client/dashboard" class="iqp-client-brand-link inline-flex items-center shrink-0" aria-label="'
        . sanitize(defined('APP_NAME') ? APP_NAME : 'IQPigeon') . ' home">'
        . brand_logo_markup($class, 'auto')
        . '</a>';
}

/** Admin shell logo — PNG wordmarks with light/dark swap. */
function iqp_admin_brand_logo(int $height = 32, string $extraClass = ''): string
{
    if (!function_exists('brand_logo_markup')) {
        $helpers = dirname(__DIR__) . '/includes/helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    if (!function_exists('brand_logo_markup')) {
        return iqp_logo_svg($height);
    }

    $class = trim('iqp-admin-brand-logo brand-logo-img ' . $extraClass);
    return brand_logo_markup($class, 'auto');
}

/** Official-style WhatsApp mark (white on green tile — use inside `.wa-dash-logo`). */
function iqp_whatsapp_logo_svg(int $size = 36): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . '<path fill="#fff" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>'
        . '<path fill="#fff" d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.75.75 0 0 0 .914.914l4.458-1.495A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 0 1-5.006-1.366l-.358-.213-2.642.887.887-2.575-.233-.375A9.818 9.818 0 1 1 12 21.818z"/>'
        . '</svg>';
}

function iqp_whatsapp_logo_tile(int $size = 36, string $class = 'wa-dash-logo'): string
{
    return '<div class="' . sanitize($class) . '" aria-hidden="true">' . iqp_whatsapp_logo_svg($size) . '</div>';
}

/** Floating chat widget launcher — squircle background + white bubble + text lines. */
function iqp_widget_fab_icon_svg(string $color, int $size = 44): string
{
    $fill = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1A66FF';

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="iqp-widget-fab-icon">'
        . '<rect width="64" height="64" rx="18" fill="' . sanitize($fill) . '" data-widget-fab-bg/>'
        . '<path fill="#FFFFFF" d="M32 14.5c-9.9 0-18 8.1-18 18 0 4.8 1.9 9.1 5 12.3L14.5 50.5l8.2-6.5c3.1 2 6.7 3.2 10.8 3.2 9.9 0 18-8.1 18-18s-8.1-18-18-18z"/>'
        . '<rect x="23.5" y="26" width="17" height="3.5" rx="1.75" fill="#0D2355"/>'
        . '<rect x="23.5" y="32" width="12.5" height="3.5" rx="1.75" fill="#0D2355"/>'
        . '</svg>';
}

function iqp_head(string $title, string $which): void
{
    $app = defined('APP_NAME') ? APP_NAME : 'IQPigeon';
    $css = $which === 'admin' ? 'css/iqp-admin.css' : 'css/iqp-user.css';
    $v = iqp_css_ver($css);
    $jsV = iqp_css_ver('js/iqp-ui.js');
    echo '<meta charset="UTF-8"/>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0"/>';
    echo '<title>' . sanitize($title) . ' · ' . sanitize($app) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"/>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>';
    echo '<link rel="stylesheet" href="/assets/' . $css . '?v=' . $v . '"/>';
    if ($which === 'admin') {
        echo '<script src="/assets/js/iqp-icons.js?v=' . iqp_css_ver('js/iqp-icons.js') . '"></script>';
        echo '<script src="/assets/js/iqp-charts.js?v=' . iqp_css_ver('js/iqp-charts.js') . '"></script>';
    }
    echo '<script src="/assets/js/iqp-ui.js?v=' . $jsV . '" defer></script>';
    echo '<style>*{font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif}</style>';
}

/**
 * Profile title label for shell UI.
 */
function iqp_user_profile_label(array $user): string
{
    if (!function_exists('profile_title_display')) {
        require_once __DIR__ . '/team-roles.php';
    }
    if (!empty($user['profile_title'])) {
        return profile_title_display((string) $user['profile_title']);
    }
    return 'Owner';
}

/**
 * Avatar markup for client shell topbar / sidebar.
 */
function iqp_user_avatar_markup(array $user, string $name, int $size = 32): string
{
    $initials = iqp_initials($name);
    $avatarUrl = trim((string) ($user['avatar_url'] ?? ''));
    $sizeAttr = 'width:' . $size . 'px;height:' . $size . 'px';
    $font = $size <= 28 ? '10px' : '12px';

    $shell = static function (string $inner) use ($size, $sizeAttr, $font, $initials): string {
        return '<div class="iqp-topbar-avatar iqp-topbar-avatar--initials" style="' . $sizeAttr . ';font-size:' . $font . '" aria-hidden="true">'
            . $inner
            . '<span class="iqp-topbar-avatar__initials">' . sanitize($initials) . '</span>'
            . '</div>';
    };

    if ($avatarUrl !== '' && (str_starts_with($avatarUrl, '/') || filter_var($avatarUrl, FILTER_VALIDATE_URL))) {
        return $shell(
            '<img src="' . sanitize($avatarUrl) . '" alt="" class="iqp-topbar-avatar__photo" onerror="this.remove();this.parentElement.classList.add(\'iqp-topbar-avatar--initials\');"/>'
        );
    }

    return $shell('');
}

/**
 * Top-right profile + theme + logout cluster.
 */
function iqp_user_topbar_actions(array $user, string $name, bool $compact = false): void
{
    $profileLabel = iqp_user_profile_label($user);
    $avatarSize = $compact ? 32 : 32;
    echo '<div class="iqp-topbar-actions flex items-center gap-2 sm:gap-3 ml-auto shrink-0">';
    echo '<div class="iqp-topbar-usermenu" data-iqp-usermenu>';
    echo '<button type="button" class="iqp-topbar-profile flex items-center gap-2 min-w-0" aria-haspopup="true" aria-expanded="false" title="Account menu">';
    echo iqp_user_avatar_markup($user, $name, $avatarSize);
    if (!$compact) {
        echo '<div class="leading-tight min-w-0 hidden md:block max-w-[160px] text-left">';
        echo '<div class="text-[13px] font-semibold text-slate-800 truncate">' . sanitize($name) . '</div>';
        echo '<div class="text-[11px] text-slate-400 truncate">' . sanitize($profileLabel) . '</div>';
        echo '</div>';
        echo '<svg class="hidden md:block text-slate-400 shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
    }
    echo '</button>';
    echo '<div class="iqp-topbar-usermenu__panel" data-usermenu-panel role="menu">';
    echo '<div class="iqp-topbar-usermenu__head lg:hidden">';
    echo '<div class="font-semibold text-slate-800 text-[13px] truncate">' . sanitize($name) . '</div>';
    echo '<div class="text-[11px] text-slate-400 truncate">' . sanitize($profileLabel) . '</div>';
    echo '</div>';
    echo '<a href="/client/settings?tab=profile" class="iqp-topbar-usermenu__item" role="menuitem"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg> Profile</a>';
    echo '<a href="/client/billing" class="iqp-topbar-usermenu__item" role="menuitem"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg> Billing</a>';
    echo '<a href="/client/notifications" class="iqp-topbar-usermenu__item" role="menuitem"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Help</a>';
    echo '<div class="iqp-topbar-usermenu__sep"></div>';
    echo '<a href="/logout" class="iqp-topbar-usermenu__item iqp-topbar-usermenu__item--danger lg:hidden" role="menuitem"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Log out</a>';
    echo '</div></div>';
    echo '<button type="button" id="' . ($compact ? 'clientDarkToggleBtnMobile' : 'clientDarkToggleBtnTop') . '" title="Toggle dark mode" onclick="toggleClientDark()" class="iqp-topbar-icon-btn" aria-label="Toggle dark mode">';
    echo '<svg id="' . ($compact ? 'clientDarkIconMobile' : 'clientDarkIconTop') . '" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    echo '</button>';
    echo '<a href="/logout" class="iqp-topbar-logout' . ($compact ? ' iqp-topbar-logout--mobile' : '') . '">Log out</a>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $user
 * @param array{title?: string, subtitle?: string, actions?: string, updates?: int, plan?: string, until?: string} $opts
 */
function iqp_user_begin(array $user, string $active, array $opts = []): void
{
    $name = trim((string) ($user['name'] ?? 'Account'));
    $plan = iqp_plan_label((string) ($user['subscription_plan'] ?? 'starter'));
    $until = trim((string) ($opts['until'] ?? ''));
    if ($until === '' && !empty($user['subscription_expires_at'])) {
        $until = date('d M, Y', strtotime((string) $user['subscription_expires_at']));
    }
    $updates = (int) ($opts['updates'] ?? 0);
    $title = (string) ($opts['title'] ?? 'IQPigeon');
    echo '<!DOCTYPE html><html lang="en"><head>';
    iqp_head($title, 'user');
    echo '</head><body class="text-slate-800" style="background:#F8FAFC;min-height:100dvh"><div class="lg:flex min-h-screen">';
    echo '<div id="sidebarOverlay" class="hidden fixed inset-0 bg-black/40 z-40 lg:hidden" data-iqp-close></div>';
    echo '<aside id="mobileSidebar" class="w-[240px] lg:w-[220px] shrink-0 bg-white border-r border-slate-200 flex flex-col h-screen fixed lg:sticky top-0 left-0 z-50 -translate-x-full lg:translate-x-0 transition-transform duration-200 overflow-y-auto">';
    echo '<div class="px-3 pt-4 pb-2 flex items-center justify-end lg:justify-start">';
    echo '<button type="button" class="lg:hidden p-2 rounded-lg text-slate-400 hover:bg-slate-100" data-iqp-close aria-label="Close menu">' . iqp_icon_svg('x') . '</button></div>';
    echo '<nav class="flex-1 px-3 space-y-1 overflow-y-auto">';
    foreach (iqp_user_nav() as $item) {
        [$id, $href, $label, $icon] = $item;
        $on = $id === $active;
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
    echo '<a href="/client/billing" class="iqp-plan-manage-btn mt-3">Manage Plan</a></div>';
    echo '</div></aside>';
    echo '<main class="flex-1 min-w-0 iqp-main-content flex flex-col">';
    echo '<div class="iqp-mobile-topbar lg:hidden sticky top-0 z-30 bg-white border-b border-slate-200 flex items-center gap-3 px-4 py-3 w-full">';
    echo '<button type="button" class="p-2 rounded-lg border border-slate-200 shrink-0" data-iqp-open aria-label="Open menu">' . iqp_icon_svg('menu', '#334155') . '</button>';
    iqp_user_topbar_actions($user, $name, true);
    echo '</div>';
    echo '<div class="iqp-client-topbar hidden lg:flex items-center justify-end gap-2 px-6 lg:px-8 py-3 border-b border-slate-200/80 bg-white/90 backdrop-blur-sm sticky top-0 z-20 w-full">';
    iqp_user_topbar_actions($user, $name, false);
    echo '</div>';
    echo '<div class="px-4 py-5 sm:px-6 lg:px-8 lg:py-7 flex-1 min-w-0 iqp-page-content">';
    echo '<script>
function toggleClientDark(){
  var on = document.documentElement.classList.toggle("client-dark");
  localStorage.setItem("iqClientDark", on ? "1" : "0");
  var moon = \'<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>\';
  var sun = \'<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>\';
  ["clientDarkIconMobile","clientDarkIconTop"].forEach(function(id){
    var icon = document.getElementById(id);
    if(icon) icon.innerHTML = on ? sun : moon;
  });
}
(function(){
  var s=localStorage.getItem("iqClientDark");
  if(s==="1"){
    document.documentElement.classList.add("client-dark");
    var sun=\'<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>\';
    ["clientDarkIconMobile","clientDarkIconTop"].forEach(function(id){
      var icon=document.getElementById(id);
      if(icon) icon.innerHTML=sun;
    });
  }
})();
</script>';
}

function iqp_user_end(): void
{
    echo '</div></main></div></body></html>';
}

function iqp_auth_begin(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head>';
    iqp_head($title, 'user');
    echo '</head><body class="text-slate-800 bg-[#F7F8FA]">';
}

function iqp_auth_end(): void
{
    echo '</body></html>';
}

function iqp_toggle(bool $on): string
{
    if ($on) {
        return '<span class="w-9 h-5 bg-[#1FA855] rounded-full relative inline-block shrink-0"><span class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full"></span></span>';
    }
    return '<span class="w-9 h-5 bg-slate-200 rounded-full relative inline-block shrink-0"><span class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full"></span></span>';
}

/**
 * Interactive on/off switch (button) for channel toggles.
 */
function iqp_switch_control(string $field, bool $on, string $label = ''): string
{
    $pressed = $on ? 'true' : 'false';
    $stateLabel = $on ? 'On' : 'Off';
    $aria = $label !== '' ? ' aria-label="' . sanitize($label . ' — ' . $stateLabel) . '"' : '';

    return '<button type="button" class="iqp-switch' . ($on ? ' iqp-switch--on' : '')
        . '" data-wa-toggle="' . sanitize($field) . '" data-enabled="' . ($on ? '1' : '0') . '"'
        . ' aria-pressed="' . $pressed . '"' . $aria . '>'
        . '<span class="iqp-switch__track" aria-hidden="true"><span class="iqp-switch__thumb"></span></span>'
        . '<span class="iqp-switch__text">' . sanitize($stateLabel) . '</span>'
        . '</button>';
}

/**
 * @param array<string, mixed> $user
 * @param array{title?: string, subtitle?: string, actions?: string, search?: string} $opts
 */
function iqp_admin_begin(array $user, string $active, array $opts = []): void
{
    $title = (string) ($opts['title'] ?? 'Admin');
    $sub = (string) ($opts['subtitle'] ?? '');
    $actions = (string) ($opts['actions'] ?? '');
    $search = (string) ($opts['search'] ?? 'Search businesses, users, tickets...');
    $name = trim((string) ($user['name'] ?? 'Admin'));
    echo '<!DOCTYPE html><html lang="en"><head>';
    iqp_head($title, 'admin');
    echo '</head><body>';
    echo '<div class="iqp-overlay" data-iqp-close></div>';
    echo '<div class="iqp-mobile-bar"><div class="iqp-mobile-bar__brand">' . iqp_admin_brand_logo(28, 'iqp-admin-brand-logo--mobile') . '</div>';
    echo '<button type="button" class="icon-btn" data-iqp-open>' . iqp_icon_svg('menu') . '</button></div>';
    echo '<div class="app-shell"><aside class="sidebar" id="adminSidebar">';
    echo '<div class="sidebar__brand"><a class="brand-logo brand-logo--admin" href="/admin/dashboard">';
    echo iqp_admin_brand_logo(34, 'iqp-admin-brand-logo--sidebar');
    echo '<div class="brand-sub">INTERNAL ADMIN PANEL</div></a></div>';
    echo '<nav class="sidebar__nav">';
    foreach (iqp_admin_nav() as $item) {
        [$id, $href, $label, $icon] = $item;
        $on = $id === $active ? ' is-active' : '';
        $iconStyle = $id === $active ? '' : ' style="color:' . iqp_nav_color($id) . '"';
        echo '<a class="nav-item' . $on . '" href="' . sanitize($href) . '">'
            . '<span class="nav-item__ic"' . $iconStyle . '>' . iqp_icon_svg($icon) . '</span>'
            . '<span>' . $label . '</span></a>';
    }
    echo '</nav>';
    // Sidebar footer: Platform Status + Dark Mode + Bulk Actions + Import/Export + Help
    echo '<div class="sidebar__foot">';
    echo '<div class="platform-status">'
        . '<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">'
        . '<span class="dot"></span><span class="label">Platform Status</span></div>'
        . '<div class="sub">All Systems Operational</div>'
        . '<div class="sub" style="margin-top:3px">Uptime: 99.96%</div>'
        . '<a class="link" href="/admin/health" style="margin-top:4px;display:inline-block">View System Health →</a>'
        . '</div>';
    echo '<div class="dark-toggle-row">'
        . '<span class="label" style="display:flex;align-items:center;gap:8px">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
        . 'Dark Mode</span>'
        . '<label class="switch" style="cursor:pointer"><input type="checkbox" id="iqpDarkMode" onchange="document.documentElement.classList.toggle(\'dark\',this.checked);localStorage.setItem(\'iqDark\',this.checked?\'1\':\'0\')"><span class="track"></span></label>'
        . '</div>';
    echo '<a class="sidebar-foot-link" href="/admin/businesses">'
        . '<span style="display:flex;align-items:center;gap:8px"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Bulk Actions</span>'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>'
        . '</a>';
    echo '<a class="sidebar-foot-link" href="/admin/businesses?export=1">'
        . '<span style="display:flex;align-items:center;gap:8px"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Import / Export</span>'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>'
        . '</a>';
    echo '<div class="help-card" style="margin-top:8px"><h5>Need Help?</h5><p>Internal Support Team</p>';
    echo '<p class="mail">' . sanitize(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'support@iqpigeon.com') . '</p>';
    echo '<a class="btn btn--primary btn--block btn--sm" style="margin-top:12px" href="/admin/tickets">Create Ticket</a></div>';
    echo '</div></aside>';
    // Main area
    echo '<div class="app-main"><header class="topbar">';
    // Search box
    echo '<form class="topbar__search" method="get" action="/admin/businesses">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;color:var(--muted-2)"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>';
    echo '<input name="q" placeholder="' . sanitize($search) . '" value="' . sanitize((string)($_GET['gq'] ?? $_GET['q'] ?? '')) . '"/></form>';
    echo '<div class="topbar__spacer"></div>';
    // Notification bell with badge
    $notifCount = 12; // static decorative
    echo '<a class="notif-bell" href="/admin/announcements" title="Notifications">'
        . '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4a5 5 0 0 0-5 5v3.5l-1.5 3h13L17 12.5V9a5 5 0 0 0-5-5Z"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>'
        . '<span class="badge-count">' . $notifCount . '</span>'
        . '</a>';
    // Avatar pill with logout dropdown
    $initials = sanitize(iqp_initials($name));
    $roleLabel = trim((string) ($user['role'] ?? 'Admin'));
    if (strcasecmp($roleLabel, 'admin') === 0) {
        $roleLabel = 'Super Admin';
    } elseif ($roleLabel === '') {
        $roleLabel = 'Admin';
    }
    $showRole = strcasecmp(trim($name), $roleLabel) !== 0;
    echo '<div class="avatar-pill" data-iqp-usermenu tabindex="0">'
        . '<div class="av">' . $initials . '</div>'
        . '<div class="avatar-pill__meta">'
        . '<div class="nm">' . sanitize($name) . '</div>';
    if ($showRole) {
        echo '<div class="role">' . sanitize($roleLabel) . '</div>';
    }
    echo '</div>'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chev"><path d="m6 9 6 6 6-6"/></svg>'
        . '<div class="user-menu" data-usermenu-panel>'
        . '<a class="user-menu__item" href="/admin/settings"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg> System Settings</a>'
        . '<div class="user-menu__sep"></div>'
        . '<a class="user-menu__item user-menu__item--danger" href="/logout"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Logout</a>'
        . '</div>'
        . '</div>';
    echo '</header>';
    echo '<main class="page">';
    echo '<div class="page-head"><div class="page-head__titles"><h1 class="page-title">' . sanitize($title) . '</h1>';
    if ($sub !== '') {
        echo '<p class="page-sub">' . sanitize($sub) . '</p>';
    }
    echo '</div>';
    if ($actions !== '') {
    echo '<div class="page-head__actions iqp-page-actions">' . $actions . '</div>';
    }
    echo '</div>';
    // Dark mode init script
    echo '<script>if(localStorage.getItem("iqDark")==="1"){document.documentElement.classList.add("dark");var d=document.getElementById("iqpDarkMode");if(d)d.checked=true;}</script>';
}

function iqp_admin_end(): void
{
    echo '</main><footer class="footer">© 2025 IQPigeon. All rights reserved.</footer></div></div></body></html>';
}

function iqp_empty(string $text): void
{
    echo '<div class="iqp-empty">' . sanitize($text) . '</div>';
}

function iqp_flash(string $msg, string $type = 'ok'): void
{
    if ($msg === '') {
        return;
    }
    echo '<div class="iqp-flash ' . ($type === 'err' ? 'err' : 'ok') . '">' . sanitize($msg) . '</div>';
}

/**
 * Unified KPI stat card grid (Integrations-style: label top, value + icon row).
 *
 * List cards: [label, value, sub?, icon_bg, icon_color, icon_svg_or_path]
 * Short list (no sub): [label, value, icon_bg, icon_color, icon_svg_or_path]
 * Associative: label, value, sub?, sub_html?, icon_bg, icon_color, icon_svg?, icon_html?
 *
 * @param array<int, array<string, mixed>|list<mixed>> $cards
 * @param array{class?: string} $opts
 */
function iqp_stat_cards(array $cards, array $opts = []): void
{
    $items = [];
    foreach ($cards as $card) {
        if (array_is_list($card)) {
            $n = count($card);
            if ($n >= 6) {
                $items[] = [
                    'label' => (string) ($card[0] ?? ''),
                    'value' => (string) ($card[1] ?? ''),
                    'sub' => (string) ($card[2] ?? ''),
                    'icon_bg' => (string) ($card[3] ?? '#F1F5F9'),
                    'icon_color' => (string) ($card[4] ?? '#64748B'),
                    'icon_raw' => (string) ($card[5] ?? ''),
                ];
            } elseif ($n === 5) {
                $items[] = [
                    'label' => (string) ($card[0] ?? ''),
                    'value' => (string) ($card[1] ?? ''),
                    'sub' => '',
                    'icon_bg' => (string) ($card[2] ?? '#F1F5F9'),
                    'icon_color' => (string) ($card[3] ?? '#64748B'),
                    'icon_raw' => (string) ($card[4] ?? ''),
                ];
            }
            continue;
        }
        $items[] = [
            'label' => (string) ($card['label'] ?? ''),
            'value' => (string) ($card['value'] ?? ''),
            'sub' => (string) ($card['sub'] ?? ''),
            'sub_html' => (string) ($card['sub_html'] ?? ''),
            'icon_bg' => (string) ($card['icon_bg'] ?? '#F1F5F9'),
            'icon_color' => (string) ($card['icon_color'] ?? '#64748B'),
            'icon_raw' => (string) ($card['icon_svg'] ?? ''),
            'icon_html' => (string) ($card['icon_html'] ?? ''),
        ];
    }

    $count = count($items);
    if ($count === 0) {
        return;
    }

    $gridClass = 'iqp-stat-grid grid gap-3 lg:gap-4';
    $margin = trim((string) ($opts['margin'] ?? 'mb-5'));
    if ($margin !== '') {
        $gridClass .= ' ' . $margin;
    }
    if ($count >= 3 && $count <= 6) {
        $gridClass .= ' iqp-stat-grid--' . $count;
    }
    $extraClass = trim((string) ($opts['class'] ?? ''));
    if ($extraClass !== '') {
        $gridClass .= ' ' . $extraClass;
    }

    echo '<div class="' . sanitize($gridClass) . '">';
    foreach ($items as $item) {
        if ($item['label'] === '') {
            continue;
        }
        echo '<div class="iqp-stat-card bg-white rounded-xl border border-slate-200 p-4 min-w-0">';
        echo '<div class="iqp-stat-card__label text-slate-500 font-medium">' . sanitize($item['label']) . '</div>';
        echo '<div class="iqp-stat-card__row">';
        echo '<div class="iqp-stat-card__value text-slate-800 font-bold">' . sanitize($item['value']) . '</div>';

        $iconWrap = ' style="background:' . sanitize($item['icon_bg']) . ';color:' . sanitize($item['icon_color']) . '"';
        $iconHtml = trim((string) ($item['icon_html'] ?? ''));
        $iconRaw = trim((string) ($item['icon_raw'] ?? ''));
        if ($iconHtml !== '') {
            echo '<div class="iqp-stat-card__icon"' . $iconWrap . '>' . $iconHtml . '</div>';
        } elseif ($iconRaw !== '') {
            echo '<div class="iqp-stat-card__icon"' . $iconWrap . '>';
            if (str_contains($iconRaw, '<svg')) {
                echo $iconRaw;
            } else {
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $iconRaw . '</svg>';
            }
            echo '</div>';
        }

        echo '</div>';

        $subHtml = trim((string) ($item['sub_html'] ?? ''));
        if ($subHtml !== '') {
            echo '<div class="iqp-stat-card__sub">' . $subHtml . '</div>';
        } elseif (($item['sub'] ?? '') !== '') {
            echo '<div class="iqp-stat-card__sub text-slate-400">' . sanitize((string) $item['sub']) . '</div>';
        }

        echo '</div>';
    }
    echo '</div>';
}

function iqp_ensure_ops_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $conn = db_connect();
    $conn->query(
        'CREATE TABLE IF NOT EXISTS iqp_tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            business_name VARCHAR(190) NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NULL,
            priority VARCHAR(20) NOT NULL DEFAULT \'medium\',
            status VARCHAR(20) NOT NULL DEFAULT \'open\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_iqp_tickets_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $conn->query(
        'CREATE TABLE IF NOT EXISTS iqp_announcements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'draft\',
            audience VARCHAR(190) NULL,
            channels VARCHAR(190) NULL,
            scheduled_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $conn->query(
        'CREATE TABLE IF NOT EXISTS iqp_audit (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor VARCHAR(190) NULL,
            action VARCHAR(190) NOT NULL,
            detail TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_iqp_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/**
 * Mobile-friendly tab navigation — 2-column cards on small screens, compact bar on desktop.
 *
 * Each tab: ['id'=>..., 'label'=>..., 'href'=>..., 'icon'?=>svg paths, 'count'?=>int]
 * Or legacy tuple: [id, label, icon?, href?, count?]
 *
 * @param array<int, array<string, mixed>|list<mixed>> $tabs
 * @param array{variant?: string, class?: string, aria?: string} $opts
 */
function iqp_tab_nav(string $activeId, array $tabs, array $opts = []): void
{
    $variant = (string) ($opts['variant'] ?? 'user');
    $extraClass = trim((string) ($opts['class'] ?? ''));
    $aria = trim((string) ($opts['aria'] ?? 'Page sections'));
    $mobileMode = (string) ($opts['mobile'] ?? 'auto');
    $selectLabel = trim((string) ($opts['select_label'] ?? 'Section'));

    $items = [];
    foreach ($tabs as $tab) {
        if (array_is_list($tab)) {
            $id = (string) ($tab[0] ?? '');
            $label = (string) ($tab[1] ?? '');
            $icon = isset($tab[2]) && is_string($tab[2]) ? $tab[2] : '';
            $href = isset($tab[3]) && is_string($tab[3]) ? $tab[3] : '#';
            $count = isset($tab[4]) ? (int) $tab[4] : null;
        } else {
            $id = (string) ($tab['id'] ?? '');
            $label = (string) ($tab['label'] ?? '');
            $icon = (string) ($tab['icon'] ?? '');
            $href = (string) ($tab['href'] ?? '#');
            $count = isset($tab['count']) ? (int) $tab['count'] : null;
        }
        if ($id === '' || $label === '') {
            continue;
        }
        $items[] = compact('id', 'label', 'icon', 'href', 'count');
    }

    $navCount = count($items);
    $useMobileDropdown = $mobileMode === 'dropdown' || ($mobileMode === 'auto' && $navCount >= 4);

    echo '<nav class="iqp-tab-nav iqp-tab-nav--' . sanitize($variant);
    if ($useMobileDropdown) {
        echo ' iqp-tab-nav--mobile-dropdown';
    }
    if ($extraClass !== '') {
        echo ' ' . sanitize($extraClass);
    }
    echo '" aria-label="' . sanitize($aria) . '">';

    if ($useMobileDropdown) {
        $activeLabel = $selectLabel;
        foreach ($items as $item) {
            if ($item['id'] === $activeId) {
                $activeLabel = $item['label'];
                if ($item['count'] !== null) {
                    $activeLabel .= ' (' . (int) $item['count'] . ')';
                }
                break;
            }
        }
        echo '<label class="iqp-tab-nav__select-wrap">';
        echo '<span class="iqp-tab-nav__select-label">' . sanitize($selectLabel) . '</span>';
        echo '<div class="iqp-tab-nav__select-field">';
        echo '<select class="iqp-tab-nav__select" aria-label="' . sanitize($aria) . '">';
        foreach ($items as $item) {
            $optLabel = $item['label'];
            if ($item['count'] !== null) {
                $optLabel .= ' (' . (int) $item['count'] . ')';
            }
            echo '<option value="' . sanitize($item['href']) . '"';
            if ($item['id'] === $activeId) {
                echo ' selected';
            }
            echo '>' . sanitize($optLabel) . '</option>';
        }
        echo '</select>';
        echo '<svg class="iqp-tab-nav__select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
        echo '</div>';
        echo '</label>';
    }

    echo '<div class="iqp-tab-nav__grid iqp-stat-grid';
    if (!$useMobileDropdown && $navCount >= 3 && $navCount <= 6) {
        echo ' iqp-stat-grid--' . $navCount;
    }
    echo '">';

    foreach ($items as $item) {
        $on = $activeId === $item['id'];
        $cls = 'iqp-tab-card' . ($on ? ' is-active' : '');

        echo '<a href="' . sanitize($item['href']) . '" class="' . $cls . '">';
        if ($item['icon'] !== '') {
            echo '<span class="iqp-tab-card__icon" aria-hidden="true">';
            echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
            echo $item['icon'];
            echo '</svg></span>';
        }
        echo '<span class="iqp-tab-card__label">' . sanitize($item['label']);
        if ($item['count'] !== null) {
            echo ' <span class="iqp-tab-card__count">' . (int) $item['count'] . '</span>';
        }
        echo '</span></a>';
    }

    echo '</div></nav>';
}

/**
 * Vertical section nav (admin side panels) — card grid on mobile, list on desktop.
 *
 * @param array<int, array{id: string, label: string, href: string}|list<string>> $sections
 */
function iqp_section_nav(string $activeId, array $sections, array $opts = []): void
{
    $extraClass = trim((string) ($opts['class'] ?? ''));
    $tag = (string) ($opts['tag'] ?? 'nav');

    echo '<' . ($tag === 'div' ? 'div' : 'nav');
    echo ' class="inner-nav iqp-section-nav__grid';
    if ($extraClass !== '') {
        echo ' ' . sanitize($extraClass);
    }
    echo '" aria-label="Section navigation">';

    foreach ($sections as $sec) {
        if (array_is_list($sec)) {
            $id = (string) ($sec[0] ?? '');
            $label = (string) ($sec[1] ?? '');
            $href = (string) ($sec[2] ?? '#');
        } else {
            $id = (string) ($sec['id'] ?? '');
            $label = (string) ($sec['label'] ?? '');
            $href = (string) ($sec['href'] ?? '#');
        }

        if ($id === '') {
            continue;
        }

        $on = $activeId === $id;
        echo '<a href="' . sanitize($href) . '" class="' . ($on ? 'is-active' : '') . '">';
        echo sanitize($label);
        echo '</a>';
    }

    echo $tag === 'div' ? '</div>' : '</nav>';
}
