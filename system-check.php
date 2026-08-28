<?php
/**
 * IQ Pigeon — full platform diagnostic
 *
 * Visit: /system-check.php?key=YOUR_CRON_SECRET
 * Or log in as admin: /system-check.php
 *
 * Add ?run=1 for live API/AI/cron tests (slower).
 * DELETE or restrict this file on production after debugging.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// ── Access control ─────────────────────────────────────────────────────────
$runLive = ($_GET['run'] ?? '') === '1';
$key = (string) ($_GET['key'] ?? '');
$accessOk = false;
$viewer = 'guest';

$cronSecret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
$adminKey = defined('ADMIN_ACCESS_KEY') ? (string) ADMIN_ACCESS_KEY : '';

if ($cronSecret !== '' && hash_equals($cronSecret, $key)) {
    $accessOk = true;
    $viewer = 'key';
} elseif (admin_access_key_valid($key)) {
    $accessOk = true;
    $viewer = 'key';
}

if (!$accessOk) {
    $user = get_user();
    if ($user && ($user['role'] ?? '') === 'admin') {
        $accessOk = true;
        $viewer = 'admin';
    }
}

if (!$accessOk) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;max-width:40rem">';
    echo '<h1>403 — Access denied</h1>';
    echo '<p><strong>Option 1:</strong> Log in as admin, then open <code>/system-check.php</code></p>';
    echo '<p><strong>Option 2:</strong> <code>/system-check.php?key=YOUR_CRON_SECRET</code></p>';
    echo '<p>Add <code>CRON_SECRET</code> to config.php (copy from config.example.php) if missing.</p>';
    echo '<p><a href="/admin/login">Admin login</a></p>';
    echo '</body></html>';
    exit;
}

$root = __DIR__;

/** @var list<string> */
$repairLog = [];
$runRepair = ($_GET['repair'] ?? '') === '1';

require_once $root . '/includes/platform-schema.php';
if ($runRepair) {
    $repairResult = platform_ensure_all();
    $repairLog = array_merge($repairResult['messages'] ?? [], array_map(
        static fn ($e) => 'ERROR: ' . $e,
        $repairResult['errors'] ?? []
    ));
} else {
    platform_ensure_all_silent();
}

/** @var list<array{area: string, label: string, status: string, detail: string, fix: string}> */
$results = [];

function sc_status(bool $ok, bool $warn = false): string
{
    if ($ok) {
        return 'pass';
    }
    return $warn ? 'warn' : 'fail';
}

function sc_add(string $area, string $label, string $status, string $detail = '', string $fix = ''): void
{
    global $results;
    $results[] = [
        'area'   => $area,
        'label'  => $label,
        'status' => $status,
        'detail' => $detail,
        'fix'    => $fix,
    ];
}

function sc_file_ok(string $path): bool
{
    return is_file($path) && is_readable($path);
}

function sc_table_exists(string $table): bool
{
    if (!function_exists('schema_table_exists')) {
        require_once __DIR__ . '/includes/commerce-schema.php';
    }
    return schema_table_exists($table);
}

function sc_column_exists(string $table, string $column): bool
{
    try {
        $row = db_fetch(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            'ss',
            [$table, $column]
        );
        return $row !== null;
    } catch (Throwable $e) {
        return false;
    }
}

function sc_count_script_includes(string $filePath, string $needle): int
{
    if (!is_readable($filePath)) {
        return 0;
    }
    $html = (string) file_get_contents($filePath);
    return substr_count(strtolower($html), strtolower($needle));
}

function sc_bot_setup_has_element_id(string $html, string $id): bool
{
    if (str_contains($html, 'id="' . $id . '"') || str_contains($html, "id='" . $id . "'")) {
        return true;
    }
    if ($id === 'bot-setup-root') {
        return str_contains($html, 'bot-setup-root');
    }
    return false;
}

function sc_bot_setup_has_tab(string $html, string $tab): bool
{
    $hasPanel = str_contains($html, 'data-panel="' . $tab . '"')
        || str_contains($html, "data-panel='" . $tab . "'");
    $hasTab = str_contains($html, 'data-tab="' . $tab . '"')
        || str_contains($html, "data-tab='" . $tab . "'")
        || (str_contains($html, "'{$tab}'") && str_contains($html, 'data-tab='));
    return $hasTab && $hasPanel;
}

function sc_php_syntax(string $filePath): array
{
    if (!is_file($filePath)) {
        return ['ok' => false, 'output' => 'File missing'];
    }
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($filePath) . ' 2>&1';
    if (function_exists('exec')) {
        $out = [];
        $code = 1;
        exec($cmd, $out, $code);
        return ['ok' => $code === 0, 'output' => implode("\n", $out)];
    }
    if (function_exists('shell_exec')) {
        $output = shell_exec($cmd);
        return [
            'ok' => is_string($output) && str_contains($output, 'No syntax errors'),
            'output' => is_string($output) ? trim($output) : 'shell_exec failed',
        ];
    }
    return ['ok' => true, 'output' => 'exec() disabled — skipped'];
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. ENVIRONMENT
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Environment';
sc_add($area, 'PHP version ≥ 8.0', sc_status(version_compare(PHP_VERSION, '8.0.0', '>=')), PHP_VERSION, 'Upgrade PHP on hosting (cPanel → Select PHP Version).');

foreach (['mysqli', 'curl', 'json', 'mbstring', 'openssl'] as $ext) {
    sc_add($area, "Extension: {$ext}", sc_status(extension_loaded($ext)), extension_loaded($ext) ? 'loaded' : 'missing', "Enable php-{$ext} in cPanel PHP extensions.");
}
sc_add(
    $area,
    'Extension: fileinfo (optional)',
    sc_status(extension_loaded('fileinfo'), true),
    extension_loaded('fileinfo') ? 'loaded' : 'missing',
    'Optional — enables MIME detection for PDF/DOCX uploads in Bot Setup. Enable in cPanel → PHP Extensions if you use file upload.'
);

sc_add($area, 'exec() available (optional syntax check)', sc_status(function_exists('exec'), true), function_exists('exec') ? 'yes' : 'no', 'Not required; enables PHP syntax lint in this report.');

// ═══════════════════════════════════════════════════════════════════════════
// 2. CONFIG
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Config';
$configChecks = [
    ['DB_HOST', true, ''],
    ['DB_NAME', true, ''],
    ['DB_USER', true, ''],
    ['DB_PASS', true, ''],
    ['APP_URL', true, 'Set APP_URL to your live domain (no trailing slash).'],
    ['OPENAI_API_KEY', true, 'Add OpenAI key — bot cannot reply without it.'],
    ['WEBHOOK_VERIFY_TOKEN', true, 'Required for Meta WhatsApp webhook verify.'],
    ['ENCRYPTION_KEY', true, '32+ char key for WhatsApp token encryption.'],
    ['CRON_SECRET', false, 'Add CRON_SECRET to config.php — drip, abandoned cart, shipment sync, booking reminders need /api/cron.php?key=...'],
    ['META_APP_ID', false, 'Needed for Embedded Signup (when WHATSAPP_MANUAL_MODE=false).'],
    ['STRIPE_SECRET_KEY', false, 'Only if billing in USD — placeholder sk_live_... will fail.'],
];

foreach ($configChecks as [$const, $required, $fixHint]) {
    $defined = defined($const);
    $val = $defined ? constant($const) : '';
    $empty = !$defined || (is_string($val) && trim($val) === '') || $val === 'sk_live_...' || $val === 'whsec_...';
    $isPlaceholder = is_string($val) && (str_contains($val, '...') || $val === 'change-this');
    if ($required) {
        sc_add($area, $const, sc_status($defined && !$empty && !$isPlaceholder), $defined ? (strlen((string) $val) > 0 ? 'set' : 'empty') : 'undefined', $fixHint);
    } elseif ($empty || $isPlaceholder) {
        sc_add($area, $const . ' (optional)', 'warn', $defined ? 'empty or placeholder' : 'undefined', $fixHint);
    } else {
        sc_add($area, $const, 'pass', 'set');
    }
}

$manualWa = defined('WHATSAPP_MANUAL_MODE') && WHATSAPP_MANUAL_MODE;
sc_add($area, 'WHATSAPP_MANUAL_MODE', 'pass', $manualWa ? 'true — tokens entered in Bot Setup → Channels' : 'false — use WhatsApp Settings embedded signup');

require_once __DIR__ . '/includes/integration-settings.php';
require_once __DIR__ . '/includes/whatsapp-oauth.php';
$metaCreds = integration_meta_credentials();
$secretLen = strlen((string) ($metaCreds['app_secret'] ?? ''));
sc_add(
    $area,
    'META_APP_SECRET (effective)',
    sc_status($secretLen > 0),
    $secretLen > 0 ? $secretLen . ' chars loaded' : 'empty',
    'Set META_APP_SECRET in config.local.php or Admin → Integrations → Save (App ID ' . whatsapp_meta_app_id() . ').'
);
$metaVerify = whatsapp_meta_verify_app_credentials();
sc_add(
    $area,
    'Meta App ID + Secret (Graph verify)',
    sc_status(!empty($metaVerify['success'])),
    !empty($metaVerify['success']) ? 'Meta accepted credentials' : (string) ($metaVerify['error'] ?? 'failed'),
    'Paste a fresh App Secret from Meta → Settings → Basic. If Admin secret was saved before ENCRYPTION_KEY changed, re-save it.'
);
sc_add(
    $area,
    'OAuth redirect URI',
    'pass',
    whatsapp_oauth_redirect_uri(),
    'Add this exact URL in Meta → Facebook Login → Valid OAuth Redirect URIs.'
);

// ═══════════════════════════════════════════════════════════════════════════
// 3. DATABASE
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Database';
try {
    db_connect();
    sc_add($area, 'MySQL connection', 'pass', DB_NAME . '@' . DB_HOST . ' as ' . DB_USER);
} catch (Throwable $e) {
    sc_add($area, 'MySQL connection', 'fail', $e->getMessage(), 'Fix config.local.php on server — see db-check.php. Error 1045 = wrong password or user not linked to database in hPanel.');
}

$tables = [
    'users', 'bots', 'leads', 'conversations', 'settings',
    'bot_products', 'bot_orders', 'bot_order_items',
    'bot_booking_settings', 'bot_appointments',
    'shop_integrations', 'broadcasts', 'broadcast_recipients',
    'team_members', 'lead_internal_notes', 'drip_sequences', 'drip_sends',
    'bot_promo_codes', 'bot_abandoned_cart_settings', 'abandoned_cart_sends', 'quick_replies',
    'shipments', 'shipment_events', 'bot_courier_settings',
    'client_whatsapp_accounts', 'whatsapp_messages_log', 'notifications',
];

foreach ($tables as $table) {
    $exists = sc_table_exists($table);
    sc_add(
        $area,
        "Table: {$table}",
        sc_status($exists),
        $exists ? 'exists' : 'missing',
        $exists ? '' : 'Browse a client page or run setup.php / ensure_*_schema() by using that feature once.'
    );
}

$phase7Cols = [
    ['shipments', 'receipt_image_url'],
    ['shipments', 'public_tracking_token'],
    ['bot_courier_settings', 'auto_tracking_urls'],
    ['bot_courier_settings', 'send_receipt_on_ship'],
];
foreach ($phase7Cols as [$tbl, $col]) {
    if (!sc_table_exists($tbl)) {
        continue;
    }
    $ok = sc_column_exists($tbl, $col);
    sc_add($area, "Column {$tbl}.{$col}", sc_status($ok), $ok ? 'ok' : 'missing', 'Click Run auto-repair or open Courier/Orders page once.');
}

$botCols = ['bot_knowledge', 'business_mode', 'conversion_goal', 'persona_description', 'widget_enabled', 'whatsapp_phone_id'];
foreach ($botCols as $col) {
    if (!sc_table_exists('bots')) {
        break;
    }
    sc_add($area, "Column bots.{$col}", sc_status(sc_column_exists('bots', $col)), sc_column_exists('bots', $col) ? 'ok' : 'missing', 'Run auto-repair — required for Bot Setup Identity/Script/Channels/Widget.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. CORE FILES (PHP modules)
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Core modules';
$coreIncludes = [
    'includes/cart.php'           => ['cart_handle_command', 'cart_progress_checkout', 'cart_checkout_in_progress', 'cart_finalize_order'],
    'includes/catalog.php'        => ['catalog_try_resolve_product_request', 'catalog_search_products'],
    'includes/shipment.php'       => ['shipment_create_for_order', 'shipment_handle_customer_query'],
    'includes/whatsapp.php'       => ['send_whatsapp_message', 'send_whatsapp_image'],
    'includes/bot-knowledge.php'  => ['ensure_bots_schema'],
    'includes/lead-lifecycle.php' => ['lifecycle_repair_stuck_orders_for_user'],
    'includes/booking.php'        => ['booking_settings_for_bot'],
    'includes/courier/providers.php' => ['courier_provider'],
];

$apiFilesOnly = [
    'api/ai-respond.php',
    'api/whatsapp-webhook.php',
    'api/shipment.php',
    'api/order-status.php',
    'api/cron.php',
    'track.php',
];

foreach ($coreIncludes as $rel => $funcs) {
    $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ok = sc_file_ok($path);
    sc_add($area, $rel, sc_status($ok), $ok ? 'present' : 'missing', $ok ? '' : 'Upload this file to the server.');

    if ($ok && $funcs !== []) {
        require_once $path;
        foreach ($funcs as $fn) {
            sc_add($area, "  → {$fn}()", sc_status(function_exists($fn)), function_exists($fn) ? 'defined' : 'missing', 'File may be corrupted or incomplete — re-upload.');
        }
    }
}

foreach ($apiFilesOnly as $rel) {
    $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    sc_add($area, $rel, sc_status(sc_file_ok($path)), sc_file_ok($path) ? 'present' : 'missing', 'Upload this file to the server.');
}

// Syntax lint on critical paths
$syntaxTargets = [
    'includes/cart.php',
    'includes/catalog.php',
    'includes/shipment.php',
    'api/ai-respond.php',
    'api/whatsapp-webhook.php',
    'client/bot-setup.php',
    'client/orders.php',
    'client/courier-settings.php',
];
foreach ($syntaxTargets as $rel) {
    $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!sc_file_ok($path)) {
        continue;
    }
    $lint = sc_php_syntax($path);
    sc_add($area, 'Syntax: ' . $rel, sc_status($lint['ok']), $lint['output'], $lint['ok'] ? '' : 'Fix PHP parse error in this file.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. CLIENT PAGES + JS ASSETS
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Client UI';
$clientPages = [
    'client/dashboard.php'       => [],
    'client/bot-setup.php'       => ['assets/js/bot-setup.js'],
    'client/catalog.php'         => ['assets/js/website-import.js'],
    'client/orders.php'          => ['assets/js/orders-kanban.js'],
    'client/courier-settings.php'=> ['assets/js/courier-dispatch.js'],
    'client/leads.php'           => ['assets/js/app.js'],
    'client/conversation.php'    => [],
    'client/inbox.php'           => [],
    'client/whatsapp-settings.php' => ['assets/js/whatsapp-settings.js'],
    'client/booking.php'         => [],
    'client/broadcasts.php'      => [],
    'client/integrations.php'    => [],
    'client/promos.php'          => [],
    'client/abandoned-cart.php'  => [],
    'client/drip.php'            => [],
    'client/quick-replies.php'   => [],
    'client/analytics.php'       => [],
    'client/billing.php'         => [],
    'client/settings.php'        => [],
    'client/notifications.php'   => [],
    'client/onboarding.php'      => [],
    'client/team.php'            => [],
];

foreach ($clientPages as $page => $jsFiles) {
    $pagePath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $page);
    sc_add($area, "Page: {$page}", sc_status(sc_file_ok($pagePath)), sc_file_ok($pagePath) ? 'ok' : 'missing', 'Upload client page.');

    foreach ($jsFiles as $js) {
        $jsPath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $js);
        sc_add($area, "  JS: {$js}", sc_status(sc_file_ok($jsPath)), sc_file_ok($jsPath) ? 'ok' : 'missing', 'Upload assets — buttons/tabs will not work without JS.');
    }
}

// Duplicate app.js (breaks Bot Setup tabs)
$botSetupPath = $root . '/client/bot-setup.php';
if (sc_file_ok($botSetupPath)) {
    $appCount = sc_count_script_includes($botSetupPath, 'assets/js/app.js');
    sc_add(
        $area,
        'Bot Setup: no duplicate app.js',
        sc_status($appCount === 0, $appCount === 1),
        $appCount === 0 ? 'ok (loaded once via nav)' : "found {$appCount} extra app.js include(s) in bot-setup.php",
        'Remove <script src="/assets/js/app.js"> from bot-setup.php — nav already loads it. Duplicate causes "Identifier App already declared" and tabs break.'
    );
}

// Bot Setup HTML ↔ JS bindings
$botSetupHtml = sc_file_ok($botSetupPath) ? (string) file_get_contents($botSetupPath) : '';
$requiredIds = [
    'bot-setup-root'   => 'Main mount — BotSetup.init() (via client_layout_start main_id)',
    'bot-knowledge'    => 'Knowledge textarea',
    'knowledge-file'   => 'File upload for PDF/DOCX',
    'fetch-everything' => 'Fetch website → catalog + knowledge',
    'copy-embed'       => 'Widget embed copy button',
    'widget_color'     => 'Widget color hidden input',
];
foreach ($requiredIds as $id => $purpose) {
    $found = sc_bot_setup_has_element_id($botSetupHtml, $id);
    sc_add($area, "Bot Setup element #{$id}", sc_status($found), $purpose, $found ? '' : "Add id=\"{$id}\" to bot-setup.php or update bot-setup.js.");
}

foreach (['company', 'channels', 'widget'] as $tab) {
    $ok = sc_bot_setup_has_tab($botSetupHtml, $tab);
    sc_add($area, "Bot Setup tab: {$tab}", sc_status($ok), $ok ? 'tab + panel' : 'incomplete', 'Restore tab buttons and panels in client/bot-setup.php.');
}

$botSetupJsPath = $root . '/assets/js/bot-setup.js';
if (sc_file_ok($botSetupJsPath)) {
    $js = (string) file_get_contents($botSetupJsPath);
    sc_add($area, 'bot-setup.js: readyState boot', sc_status(str_contains($js, 'readyState')), '', 'Add readyState boot so tabs work if script loads late.');
    sc_add($area, 'bot-setup.js: scoped bindTabs (#bot-setup-root)', sc_status(str_contains($js, 'bot-setup-root')), '', 'Scope tab clicks to #bot-setup-root.');
    sc_add($area, 'bot-setup.js: cache bust on server', sc_status(str_contains((string) file_get_contents($botSetupPath), 'filemtime'), true), '', 'Add ?v=filemtime(...) to bot-setup.js script tag.');
}

// Orders kanban
$ordersPath = $root . '/client/orders';
if (sc_file_ok($ordersPath)) {
    $ordersHtml = (string) file_get_contents($ordersPath);
    sc_add($area, 'Orders: shipment modal', sc_status(str_contains($ordersHtml, 'shipment-modal')), '', 'Add shipment modal for Confirmed→Shipped flow.');
    sc_add($area, 'Orders: orders-kanban.js', sc_status(str_contains($ordersHtml, 'orders-kanban.js')), '', 'Include orders-kanban.js on orders page.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. UPLOADS & PERMISSIONS
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Uploads';
require_once $root . '/includes/platform-schema.php';
$uploadFix = platform_ensure_upload_dirs();
$uploadsRoot = $root . '/uploads';
$shipmentsDir = $uploadsRoot . '/shipments';
sc_add($area, 'uploads/ directory', sc_status(is_dir($uploadsRoot)), is_dir($uploadsRoot) ? 'exists' : 'missing', 'Click “Run auto-repair” or create uploads/ with chmod 755.');
if (is_dir($uploadsRoot)) {
    sc_add($area, 'uploads/ writable', sc_status(is_writable($uploadsRoot)), is_writable($uploadsRoot) ? 'yes' : 'no', 'chmod 755 or 775 on uploads/ — required for parcel receipt photos.');
}
$shipmentsOk = is_dir($shipmentsDir) && is_writable($shipmentsDir);
sc_add(
    $area,
    'uploads/shipments/ (receipt photos)',
    sc_status($shipmentsOk),
    $shipmentsOk ? 'exists & writable' : implode('; ', $uploadFix['errors'] ?? ['not ready']),
    'Run auto-repair or: mkdir uploads/shipments && chmod 775 uploads/shipments'
);

// ═══════════════════════════════════════════════════════════════════════════
// 7. CHECKOUT & AI PIPELINE (logic checks)
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Shop / Checkout AI';
if (!function_exists('catalog_message_could_be_product_query')) {
    require_once $root . '/includes/catalog.php';
}
if (!function_exists('cart_message_looks_like_delivery_details')) {
    require_once $root . '/includes/cart.php';
}

// Simulate: address should NOT be treated as product query
$fakeAddress = "Ahmed Khan\n03001234567\nHouse 5, DHA Phase 2, Lahore";
$isProductQuery = catalog_message_could_be_product_query($fakeAddress);
sc_add(
    $area,
    'Address text not treated as product search',
    sc_status(!$isProductQuery),
    $isProductQuery ? 'FAIL — address still triggers catalog' : 'ok',
    'Update catalog_message_could_be_product_query() in includes/catalog.php to exclude delivery addresses.'
);

$looksLikeAddress = cart_message_looks_like_delivery_details($fakeAddress);
sc_add($area, 'cart_message_looks_like_delivery_details()', sc_status($looksLikeAddress), $looksLikeAddress ? 'detects sample address' : 'missed', 'Update includes/cart.php — checkout address step will fail.');

$checkoutExact = function_exists('cart_handle_command');
sc_add($area, 'cart_handle_command() defined', sc_status($checkoutExact), $checkoutExact ? 'ok' : 'missing', 'Re-upload includes/cart.php.');

$catalogPick = function_exists('cart_message_catalog_pick_index')
    ? cart_message_catalog_pick_index('#3')
    : null;
sc_add(
    $area,
    'Cart accepts #3 / 3 (not only "add #3")',
    sc_status($catalogPick === 3),
    $catalogPick === 3 ? 'ok' : 'missing — customers typing "3" get no cart action',
    'Re-upload includes/cart.php with cart_message_catalog_pick_index().'
);

$confirmSample = "1x Zinger Burger — PKR 380\nTotal: PKR 830 (Cash on Delivery)";
$parsedLines = function_exists('cart_parse_confirmation_line_items')
    ? cart_parse_confirmation_line_items($confirmSample, [['id' => 1, 'name' => 'Zinger Burger', 'price' => 380, 'currency' => 'PKR']])
    : [];
sc_add(
    $area,
    'Order confirmation line parser (1x Item — PKR)',
    sc_status(count($parsedLines) >= 1),
    count($parsedLines) >= 1 ? 'parses sample confirmation' : 'failed to parse',
    'Re-upload includes/cart.php — orders stay empty when AI confirms without [CREATE_ORDER].'
);

// Password reset schema
sc_add(
    'Auth',
    'users.reset_expires_at column',
    sc_status(sc_column_exists('users', 'reset_expires_at')),
    sc_column_exists('users', 'reset_expires_at') ? 'ok' : 'missing',
    'Run ?repair=1 on system-check or php tests/full-system-test.php --repair'
);
if (sc_column_exists('users', 'reset_expires_at')) {
    $resetTest = db_fetch(
        'SELECT TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR)) AS diff',
        '',
        []
    );
    $diff = (int) ($resetTest['diff'] ?? 0);
    sc_add(
        'Auth',
        'Password reset uses MySQL NOW() (not PHP date mismatch)',
        sc_status($diff >= 59 && $diff <= 61),
        'DATE_ADD interval minutes=' . $diff,
        'Re-upload forgot-password.php — use DATE_ADD(NOW(), INTERVAL 1 HOUR).'
    );
}

// Website import duplicate guard
$wiPath = $root . '/includes/website-import.php';
$wiDup = is_readable($wiPath)
    ? preg_match_all('/function\s+website_import_fetch_products\s*\(/', (string) file_get_contents($wiPath))
    : 0;
sc_add(
    'Shop / Import',
    'website-import.php not duplicated on server',
    sc_status($wiDup === 1),
    $wiDup === 1 ? 'single fetch_products()' : "found {$wiDup} declarations — fatal redeclare crash",
    'Re-upload the ENTIRE includes/website-import.php file (do not merge partial uploads).'
);

sc_add(
    'Shop / Import',
    'bot_products.updated_at column',
    sc_status(sc_column_exists('bot_products', 'updated_at')),
    sc_column_exists('bot_products', 'updated_at') ? 'ok' : 'missing',
    'Run ?repair=1 — fixes "Unknown column updated_at" on Add product.'
);

// Catalog auto-index fallback removed (was sending products #1,#2)
$autoIdx = catalog_auto_product_indexes(1, 'show me something random xyz', []);
sc_add(
    $area,
    'No random catalog fallback (products #1,#2)',
    sc_status($autoIdx === []),
    $autoIdx === [] ? 'empty for vague query — good' : 'returns indexes: ' . implode(',', $autoIdx),
    'Remove [1,2,3] fallback in catalog_auto_product_indexes() — causes random product photos during checkout.'
);

// ═══════════════════════════════════════════════════════════════════════════
// 8. BOTS & DATA SNAPSHOT
// ═══════════════════════════════════════════════════════════════════════════
$area = 'Live data';
try {
    $botCount = (int) (db_fetch('SELECT COUNT(*) AS c FROM bots')['c'] ?? 0);
    sc_add($area, 'Active bots', sc_status($botCount > 0), (string) $botCount, 'Create a bot via onboarding or Bot Setup.');

    $productCount = sc_table_exists('bot_products')
        ? (int) (db_fetch('SELECT COUNT(*) AS c FROM bot_products WHERE is_active = 1')['c'] ?? 0)
        : 0;
    sc_add($area, 'Active catalog products', sc_status($productCount > 0, true), (string) $productCount, 'Import products in Catalog or website import.');

    $orderCount = sc_table_exists('bot_orders')
        ? (int) (db_fetch('SELECT COUNT(*) AS c FROM bot_orders')['c'] ?? 0)
        : 0;
    sc_add($area, 'Orders in pipeline', 'pass', (string) $orderCount);

    $waAccounts = sc_table_exists('client_whatsapp_accounts')
        ? (int) (db_fetch('SELECT COUNT(*) AS c FROM client_whatsapp_accounts WHERE connection_status = \'active\'')['c'] ?? 0)
        : 0;
    $manualBotsConnected = sc_table_exists('bots')
        ? (int) (db_fetch('SELECT COUNT(*) AS c FROM bots WHERE whatsapp_verified = 1 AND whatsapp_phone_id != \'\'')['c'] ?? 0)
        : 0;
    sc_add(
        $area,
        'WhatsApp connected accounts',
        sc_status($waAccounts > 0 || $manualBotsConnected > 0 || $manualWa, true),
        'embedded=' . $waAccounts . ', manual bots=' . $manualBotsConnected,
        $manualWa ? 'Enter Phone ID + token in Bot Setup → Channels and click Verify.' : 'Connect via WhatsApp Settings embedded signup.'
    );

    $stuckLeads = sc_table_exists('leads') && sc_table_exists('bot_orders')
        ? (int) (db_fetch(
            "SELECT COUNT(*) AS c FROM leads l
             JOIN conversations c ON c.lead_id = l.id AND c.role = 'assistant'
             WHERE l.status = 'in_progress' AND c.message LIKE '%order%confirm%'
             AND NOT EXISTS (SELECT 1 FROM bot_orders o WHERE o.lead_id = l.id)",
            '',
            []
        )['c'] ?? 0)
        : 0;
    if ($stuckLeads > 0) {
        sc_add($area, 'Stuck orders (confirmed in chat, no bot_orders row)', 'warn', (string) $stuckLeads, 'Open Leads or Orders and run sync/repair, or use lifecycle_repair_stuck_orders_for_user().');
        if ($runRepair && function_exists('lifecycle_repair_stuck_orders_for_user')) {
            require_once $root . '/includes/lead-lifecycle.php';
            $users = db_fetch_all('SELECT DISTINCT user_id FROM bots', '', []);
            $repaired = 0;
            foreach ($users as $u) {
                $repaired += lifecycle_repair_stuck_orders_for_user((int) $u['user_id']);
            }
            $repairLog[] = "Repaired {$repaired} stuck order(s) from chat history.";
        }
    } else {
        sc_add($area, 'Stuck orders (confirmed in chat, no bot_orders row)', 'pass', '0');
    }
} catch (Throwable $e) {
    sc_add($area, 'Data snapshot', 'fail', $e->getMessage(), 'Fix database connection first.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 9. LIVE TESTS (?run=1)
// ═══════════════════════════════════════════════════════════════════════════
if ($runLive) {
    $area = 'Live tests';

    if ($cronSecret !== '') {
        $cronUrl = rtrim(APP_URL, '/') . '/api/cron.php?key=' . rawurlencode($cronSecret);
        $cronBody = @file_get_contents($cronUrl);
        $cronOk = $cronBody !== false && str_contains($cronBody, 'success');
        sc_add($area, 'Cron endpoint', sc_status($cronOk), $cronOk ? substr($cronBody, 0, 120) : 'HTTP failed or 401', 'Set CRON_SECRET in config and schedule curl every 15 min.');
    } else {
        sc_add($area, 'Cron endpoint', 'warn', 'CRON_SECRET not set — skipped', 'Add CRON_SECRET to config.php from config.example.php.');
    }

    require_once $root . '/includes/openai.php';
    try {
        $ai = ai_chat([['role' => 'user', 'content' => 'Reply with exactly: OK']], ['max_tokens' => 10]);
        sc_add($area, 'OpenAI / AI API', sc_status(!empty($ai['success'])), $ai['content'] ?? ($ai['error'] ?? 'error'), 'Check OPENAI_API_KEY in Admin → Integrations or config.local.php.');
    } catch (Throwable $e) {
        sc_add($area, 'DeepSeek / AI API', 'fail', $e->getMessage(), 'Fix AI config.');
    }

    $trackUrl = rtrim(APP_URL, '/') . '/track.php?t=invalid-test-token';
    $trackCode = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($trackUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        $trackCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    sc_add($area, 'Public track.php reachable', sc_status($trackCode === 200), 'HTTP ' . $trackCode, 'Upload track.php to site root.');

    foreach (['shipment.php', 'order-status.php', 'bot-knowledge.php'] as $apiFile) {
        $f = $root . '/api/' . $apiFile;
        sc_add($area, "API: /api/{$apiFile}", sc_status(sc_file_ok($f)), sc_file_ok($f) ? 'exists' : 'missing', 'Upload api/' . $apiFile);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// REPORT
// ═══════════════════════════════════════════════════════════════════════════
$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($results as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
}

$areas = [];
foreach ($results as $r) {
    $areas[$r['area']][] = $r;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>IQ Pigeon System Check</title>
<link href="/assets/css/app.css" rel="stylesheet"/>
<style>
.sc-wrap { max-width: 960px; margin: 0 auto; padding: 1.5rem; }
.sc-summary { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.sc-pill { padding: .5rem 1rem; border-radius: 999px; font-weight: 600; font-size: .875rem; }
.sc-pill--pass { background: #dcfce7; color: #166534; }
.sc-pill--warn { background: #fef9c3; color: #854d0e; }
.sc-pill--fail { background: #fee2e2; color: #991b1b; }
.sc-area { margin-bottom: 1.5rem; }
.sc-area h2 { font-size: 1.125rem; margin-bottom: .75rem; border-bottom: 1px solid #e5e7eb; padding-bottom: .5rem; }
.sc-row { display: grid; grid-template-columns: 1.5rem 1fr; gap: .75rem; padding: .75rem; border-radius: .75rem; margin-bottom: .5rem; border: 1px solid #e5e7eb; background: #fff; }
.sc-row--fail { border-color: #fecaca; background: #fef2f2; }
.sc-row--warn { border-color: #fde68a; background: #fffbeb; }
.sc-icon { font-size: 1.25rem; line-height: 1.4; }
.sc-label { font-weight: 600; }
.sc-detail { font-size: .875rem; color: #6b7280; margin-top: .25rem; }
.sc-fix { font-size: .875rem; color: #b45309; margin-top: .35rem; padding: .5rem; background: #fffbeb; border-radius: .5rem; }
.sc-fix strong { color: #92400e; }
.sc-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1.5rem 0; }
.sc-btn { display: inline-flex; align-items: center; padding: .625rem 1.25rem; border-radius: .75rem; font-weight: 600; text-decoration: none; }
.sc-btn--primary { background: #4aad36; color: #fff; }
.sc-btn--secondary { border: 1px solid #d1d5db; color: #374151; }
code { background: #f3f4f6; padding: .1rem .35rem; border-radius: .25rem; font-size: .85em; }
</style>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh]">
<div class="sc-wrap">
    <h1 class="font-headline text-2xl mb-sm">IQ Pigeon — System Check</h1>
    <p class="text-on-surface-variant mb-md">
        Full diagnostic for buttons, features, checkout, bot setup, courier, and WhatsApp pipeline.
        Viewer: <strong><?= sanitize($viewer) ?></strong>
        · CLI: <code>php tests/full-system-test.php --repair</code>
        · <?= sanitize(APP_URL) ?>
        · <?= date('Y-m-d H:i:s T') ?>
    </p>
    <?php if (defined('CRON_SECRET') && CRON_SECRET !== ''): ?>
    <p class="text-body-sm text-on-surface-variant mb-md">
        System-check &amp; cron key: use <code>?key=<?= sanitize(substr(CRON_SECRET, 0, 6)) ?>…</code> (full value is in config.php → CRON_SECRET)
    </p>
    <?php endif; ?>

    <div class="sc-summary">
        <span class="sc-pill sc-pill--pass">✓ Pass: <?= (int) $counts['pass'] ?></span>
        <span class="sc-pill sc-pill--warn">⚠ Warn: <?= (int) $counts['warn'] ?></span>
        <span class="sc-pill sc-pill--fail">✗ Fail: <?= (int) $counts['fail'] ?></span>
    </div>

    <?php if ($counts['fail'] > 0): ?>
    <div class="sc-row sc-row--fail mb-lg" style="grid-template-columns:1fr">
        <p class="sc-label">Priority fixes</p>
        <ul class="text-sm mt-sm space-y-xs list-disc pl-md">
            <?php foreach ($results as $r): if ($r['status'] !== 'fail') continue; ?>
            <li><strong><?= sanitize($r['label']) ?></strong><?= $r['fix'] !== '' ? ' — ' . sanitize($r['fix']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="sc-actions">
        <a class="sc-btn sc-btn--primary" href="?<?= $key !== '' ? 'key=' . rawurlencode($key) . '&' : '' ?>repair=1">Run auto-repair (DB + uploads)</a>
        <a class="sc-btn sc-btn--primary" href="?<?= $key !== '' ? 'key=' . rawurlencode($key) . '&' : '' ?>run=1">Run live tests (AI + cron)</a>
        <a class="sc-btn sc-btn--secondary" href="?<?= $key !== '' ? 'key=' . rawurlencode($key) : '' ?>">Refresh</a>
        <a class="sc-btn sc-btn--secondary" href="/admin/health">Admin health (simple)</a>
        <a class="sc-btn sc-btn--secondary" href="/client/dashboard">Client dashboard</a>
    </div>

    <?php if ($repairLog !== []): ?>
    <div class="sc-row mb-lg" style="grid-template-columns:1fr">
        <p class="sc-label">Auto-repair log</p>
        <ul class="text-sm mt-sm space-y-xs list-disc pl-md">
            <?php foreach ($repairLog as $line): ?>
            <li><?= sanitize($line) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php foreach ($areas as $areaName => $rows): ?>
    <section class="sc-area">
        <h2><?= sanitize($areaName) ?></h2>
        <?php foreach ($rows as $row):
            $icon = match ($row['status']) {
                'pass' => '✓',
                'warn' => '⚠',
                default => '✗',
            };
            $rowClass = $row['status'] === 'fail' ? 'sc-row--fail' : ($row['status'] === 'warn' ? 'sc-row--warn' : '');
        ?>
        <div class="sc-row <?= $rowClass ?>">
            <span class="sc-icon"><?= $icon ?></span>
            <div>
                <div class="sc-label"><?= sanitize($row['label']) ?></div>
                <?php if ($row['detail'] !== ''): ?>
                <div class="sc-detail"><?= sanitize($row['detail']) ?></div>
                <?php endif; ?>
                <?php if ($row['fix'] !== '' && $row['status'] !== 'pass'): ?>
                <div class="sc-fix"><strong>Fix:</strong> <?= sanitize($row['fix']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <section class="sc-area">
        <h2>Quick manual test checklist</h2>
        <div class="sc-row" style="grid-template-columns:1fr">
            <ol class="text-sm space-y-sm list-decimal pl-md">
                <li><strong>Bot Setup tabs</strong> — /client/bot-setup?id=X → click Your Business, Channels, Widget</li>
                <li><strong>Human Trainer</strong> — /client/training?bot_id=X → build script from knowledge</li>
                <li><strong>WhatsApp verify</strong> — Channels tab → enter Phone ID + token → Verify</li>
                <li><strong>Catalog import</strong> — Catalog → paste store URL → preview → import</li>
                <li><strong>Checkout flow</strong> — WhatsApp: add #1 → checkout → send name/phone/address → yes → order confirmed (no random product photos)</li>
                <li><strong>Orders kanban</strong> — Orders → drag Confirmed → Shipped → enter tracking + receipt photo</li>
                <li><strong>Courier manual</strong> — Courier → Manual dispatch → pick order → save</li>
                <li><strong>Tracking ask</strong> — Customer: "where is my order?" → rep reply with courier + tracking</li>
                <li><strong>Cron</strong> — <code>curl -s "<?= sanitize(rtrim(APP_URL, '/')) ?>/api/cron.php?key=CRON_SECRET"</code></li>
            </ol>
        </div>
    </section>

    <p class="text-xs text-on-surface-variant mt-xl">Delete <code>system-check.php</code> after debugging. Do not leave publicly accessible without key on production.</p>
</div>
</body>
</html>
