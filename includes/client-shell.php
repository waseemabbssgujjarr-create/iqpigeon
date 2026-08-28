<?php
/**
 * Client dashboard layout shell — all /client/* pages.
 *
 *   client_shell_begin();
 *   include client-nav.php;
 *   client_layout_start(['width' => 'default|narrow|wide', ...]);
 *   ... content ...
 *   client_layout_end();
 *   client_shell_end();
 */

require_once __DIR__ . '/iqp-ui.php';

function client_shell_begin(): void
{
    static $criticalCssEmitted = false;
    if (!$criticalCssEmitted) {
        echo '<style id="client-layout-critical">'
            . 'body.client-app{font-family:Inter,system-ui,sans-serif!important;background:#F7F8FA!important}'
            . '@media(min-width:1024px){'
            . '.client-layout{display:flex;min-height:100vh}'
            . '.client-layout .client-main{margin-left:0!important;min-width:0;flex:1}'
            . '#client-mobile-nav,.app-bottom-nav,.client-fab{display:none!important}'
            . '}'
            . '@media(max-width:1023px){'
            . '.client-layout .client-main{margin-left:0!important;padding-bottom:16px}'
            . '#client-mobile-nav{display:none!important}'
            . '}'
            . '</style>';
        $criticalCssEmitted = true;
    }
    echo '<div class="client-layout">';
}

function client_shell_end(): void
{
    echo '</div>';
    echo client_pwa_install_script();
}

/**
 * Mobile bottom navigation — rendered from client-nav.php on every client page.
 */
function client_render_mobile_nav(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    global $activeTab;
    $activeTab = $activeTab ?? 'home';
    require_once __DIR__ . '/integration-settings.php';
    $manualWhatsApp = integration_whatsapp_manual_mode();
    $whatsappHref = $manualWhatsApp ? '/client/bot-setup?tab=channels' : '/client/whatsapp-settings';

    $bottomKeys = client_mobile_bottom_nav_keys();
    $mobileNav = [];
    foreach (client_nav_items() as $item) {
        if (in_array($item[0], $bottomKeys, true)) {
            $mobileNav[] = $item;
        }
    }
    if ($mobileNav === []) {
        $mobileNav = [
            ['home', '/client/dashboard', 'home', 'Home'],
            ['connect', '/client/bot-setup', 'hub', 'Connect'],
            ['leads', '/client/leads', 'forum', 'Leads'],
            ['whatsapp', $whatsappHref, 'chat', 'WhatsApp'],
            ['settings', '/client/settings', 'settings', 'Settings'],
        ];
    }
    ?>
<nav id="client-mobile-nav" class="app-bottom-nav v2-client-bottom-nav" aria-label="Mobile navigation">
    <?php foreach ($mobileNav as $navItem):
        [$key, $href, $icon, $label] = $navItem;
        $active = $activeTab === $key;
    ?>
    <a href="<?= sanitize($href) ?>" class="app-nav-link<?= $active ? ' active' : '' ?>" data-nav="<?= sanitize($key) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
        <span class="app-nav-icon-slot">
            <span class="material-symbols-outlined"><?= sanitize($icon) ?></span>
        </span>
        <span class="app-nav-label"><?= sanitize($label) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
    <?php
}

/**
 * @param array{width?: string, main_class?: string, main_id?: string, data?: array<string, string>} $opts
 */
function client_layout_start(array $opts = []): void
{
    $width = $opts['width'] ?? 'default';
    $innerClass = match ($width) {
        'narrow' => 'client-inner client-inner--narrow',
        'wide'   => 'client-inner client-inner--wide',
        'full'   => 'client-inner client-inner--full',
        default  => 'client-inner',
    };

    $mainClass = 'client-main v2-app-canvas';
    if (!empty($opts['main_class'])) {
        $mainClass .= ' ' . preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $opts['main_class']);
    }

    $mainId = '';
    if (!empty($opts['main_id'])) {
        $mainId = ' id="' . sanitize($opts['main_id']) . '"';
    }

    $dataAttrs = '';
    foreach ($opts['data'] ?? [] as $key => $val) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $key);
        $dataAttrs .= ' data-' . $safeKey . '="' . sanitize((string) $val) . '"';
    }

    echo '<main class="' . $mainClass . '"' . $mainId . $dataAttrs . '>';
    echo '<div class="' . $innerClass . '">';
}

function client_layout_end(): void
{
    if (function_exists('client_page_body_close')) {
        client_page_body_close();
    }
    echo '</div></main>';
}
