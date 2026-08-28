<?php
/**
 * IQ Pigeon — full platform test suite (CLI + optional live checks).
 *
 * Run on server (cPanel terminal or SSH):
 *   cd ~/public_html
 *   php tests/full-system-test.php
 *   php tests/full-system-test.php --live
 *   php tests/full-system-test.php --repair
 *   php tests/full-system-test.php --url=https://example.com
 *
 * Exit code: 0 = all pass, 1 = failures
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/platform-schema.php';

$argv = $argv ?? [];
$runLive = in_array('--live', $argv, true);
$runRepair = in_array('--repair', $argv, true);
$testUrl = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $testUrl = substr($arg, 6);
    }
}

$passed = 0;
$failed = 0;
$warned = 0;

function fst_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failed++;
}

function fst_warn(bool $cond, string $name, string $detail = ''): void
{
    global $warned;
    if ($cond) {
        echo "  PASS  {$name}\n";
        return;
    }
    echo "  WARN  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $warned++;
}

function fst_section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

echo "IQ Pigeon Full System Test\n";
echo 'Time: ' . date('Y-m-d H:i:s') . ' (' . (defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get()) . ")\n";
echo 'APP_URL: ' . (defined('APP_URL') ? APP_URL : 'not set') . "\n";

// ── 1. Schema ─────────────────────────────────────────────────────────────
fst_section('1. Database & schema');

try {
    db_connect();
    fst_assert(true, 'Database connection');
} catch (Throwable $e) {
    fst_assert(false, 'Database connection', $e->getMessage());
    echo "\nCannot continue without DB.\n";
    exit(1);
}

if ($runRepair) {
    $repair = platform_ensure_all();
    foreach ($repair['messages'] ?? [] as $msg) {
        echo "  REPAIR {$msg}\n";
    }
    foreach ($repair['errors'] ?? [] as $err) {
        echo "  REPAIR ERROR {$err}\n";
    }
} else {
    platform_ensure_all_silent();
}

require_once $root . '/includes/commerce-schema.php';
ensure_commerce_schema();
ensure_users_auth_schema();

$schemaChecks = [
    ['users', 'reset_token'],
    ['users', 'reset_expires_at'],
    ['bot_products', 'updated_at'],
    ['bot_products', 'external_source'],
    ['bot_orders', 'id'],
    ['leads', 'id'],
    ['conversations', 'id'],
];
foreach ($schemaChecks as [$table, $col]) {
    fst_assert(
        db_column_exists($table, $col) || ($col === 'id' && schema_table_exists($table)),
        "Column {$table}.{$col} exists"
    );
}

$row = db_fetch('SELECT NOW() AS db_now, @@session.time_zone AS tz', '', []);
fst_assert($row !== null, 'MySQL NOW() readable', 'tz=' . ($row['tz'] ?? '?'));

// ── 2. Password reset ───────────────────────────────────────────────────────
fst_section('2. Password reset token flow');

$testEmail = 'fst-test-' . bin2hex(random_bytes(4)) . '@example.invalid';
try {
    db_execute(
        'INSERT INTO users (email, password, role, company_name) VALUES (?, ?, ?, ?)',
        'ssss',
        [$testEmail, password_hash('test12345', PASSWORD_BCRYPT), 'client', 'FST Test']
    );
    $testUserId = (int) db_connect()->insert_id;
    $token = generate_token(32);
    db_execute(
        'UPDATE users SET reset_token = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?',
        'si',
        [$token, $testUserId]
    );
    $valid = db_fetch(
        'SELECT id, reset_expires_at, TIMESTAMPDIFF(MINUTE, NOW(), reset_expires_at) AS mins_left
         FROM users WHERE reset_token = ? AND reset_expires_at > NOW()',
        's',
        [$token]
    );
    fst_assert($valid !== null, 'Reset token valid immediately after creation');
    if ($valid) {
        $mins = (int) ($valid['mins_left'] ?? 0);
        fst_assert($mins >= 55 && $mins <= 65, 'Reset expiry ~60 minutes ahead', "mins_left={$mins}");
    }
    db_execute('DELETE FROM users WHERE id = ?', 'i', [$testUserId]);
} catch (Throwable $e) {
    fst_assert(false, 'Password reset roundtrip', $e->getMessage());
}

// ── 3. Cart & checkout ──────────────────────────────────────────────────────
fst_section('3. Cart commands & order parsing');

require_once $root . '/includes/catalog.php';
require_once $root . '/includes/cart.php';

$picks = [
    ['msg' => '3', 'expected' => 3],
    ['msg' => '#3', 'expected' => 3],
    ['msg' => 'add #3', 'expected' => 3],
    ['msg' => 'add 3', 'expected' => 3],
    ['msg' => 'order #3', 'expected' => 3],
    ['msg' => '# 3', 'expected' => 3],
];
foreach ($picks as $case) {
    $msg = $case['msg'];
    $expected = $case['expected'];
    $got = cart_message_catalog_pick_index($msg);
    if ($expected === null) {
        fst_assert($got === null, "catalog pick ignores \"{$msg}\"");
    } else {
        fst_assert($got === $expected, "catalog pick \"{$msg}\" → #{$expected}", 'got=' . var_export($got, true));
    }
}

$confirmText = "Thanks, Amir! Here's your order confirmation:\n1x Zinger Burger — PKR 380\n1x Plain Salted Fries — PKR 200\nTotal: PKR 830 (Cash on Delivery)";
fst_assert(cart_reply_implies_order_placed($confirmText), 'Detects order confirmation text');

$fakeProducts = [
    ['id' => 1, 'name' => 'Zinger Burger', 'price' => 380, 'currency' => 'PKR'],
    ['id' => 2, 'name' => 'Plain Salted Fries', 'price' => 200, 'currency' => 'PKR'],
];
$parsedItems = cart_parse_confirmation_line_items($confirmText, $fakeProducts);
fst_assert(count($parsedItems) === 2, 'Parses 1x Product — PKR price lines', 'count=' . count($parsedItems));

// ── 4. Website import helpers ───────────────────────────────────────────────
fst_section('4. Website import');

require_once $root . '/includes/website-import.php';

fst_assert(
    website_import_clean_product_name('Order White Berry Mojito Online | Mojitos Just for You') === 'White Berry Mojito',
    'Cleans "Order X Online | …" titles'
);
fst_assert(website_import_valid_image_url('https://cdn.shopify.com/s/files/1/product.jpg') !== '', 'Accepts valid HTTPS image');
fst_assert(website_import_valid_image_url('/placeholder.png') === '', 'Rejects relative placeholder paths');

$catalogFile = $root . '/includes/catalog.php';
if (is_readable($catalogFile)) {
    $catSrc = file_get_contents($catalogFile);
    fst_assert(
        str_contains($catSrc, "'iissdssssiiiss'"),
        'Import product INSERT uses correct 14-char bind string'
    );
    fst_assert(
        !str_contains($catSrc, "'iissdsssssiiiss'"),
        'Import product INSERT has no 15-char bind typo'
    );
}

$dupCount = 0;
$wiFile = $root . '/includes/website-import.php';
if (is_readable($wiFile)) {
    $src = file_get_contents($wiFile);
    $dupCount = preg_match_all('/function\s+website_import_fetch_products\s*\(/', $src, $m) ?: 0;
}
fst_assert($dupCount === 1, 'website-import.php has single fetch_products()', "found={$dupCount}");

if ($testUrl !== null && function_exists('website_import_preview')) {
    echo "  LIVE  Fetching preview for {$testUrl} …\n";
    $preview = website_import_preview($testUrl, 5);
    fst_assert(!empty($preview['success']), 'Website preview fetch', $preview['message'] ?? '');
    if (!empty($preview['sample'])) {
        $sample = $preview['sample'][0];
        fst_warn(
            (float) ($sample['price'] ?? 0) > 0,
            'Preview sample has price > 0',
            'price=' . ($sample['price'] ?? 0)
        );
        fst_warn(
            trim((string) ($sample['image_url'] ?? '')) !== '',
            'Preview sample has image URL'
        );
    }
}

// ── 5. Order repair ─────────────────────────────────────────────────────────
fst_section('5. Order sync / repair');

require_once $root . '/includes/lead-lifecycle.php';
fst_assert(function_exists('lifecycle_repair_stuck_orders_for_user'), 'lifecycle_repair_stuck_orders_for_user() exists');
fst_assert(function_exists('lifecycle_repair_lead_conversion'), 'lifecycle_repair_lead_conversion() exists');

// ── 6. Critical files ─────────────────────────────────────────────────────
fst_section('6. Critical PHP files');

$criticalFiles = [
    'includes/cart.php',
    'includes/catalog.php',
    'includes/website-import.php',
    'includes/commerce-schema.php',
    'includes/lead-lifecycle.php',
    'includes/bot-context.php',
    'includes/api-json.php',
    'includes/platform-schema.php',
    'api/ai-respond.php',
    'api/website-import.php',
    'api/catalog-product.php',
    'api/fetch-business.php',
    'api/bot-context.php',
    'forgot-password.php',
    'reset-password.php',
    'client/orders.php',
    'client/catalog.php',
    'system-check.php',
];
$canSyntaxLint = function_exists('shell_exec') || function_exists('exec');
if (!$canSyntaxLint) {
    echo "  SKIP syntax lint (shell_exec disabled on host)\n";
}
foreach ($criticalFiles as $rel) {
    $path = $root . '/' . $rel;
    fst_assert(is_file($path), "File {$rel}");
    if (!$canSyntaxLint || !is_file($path)) {
        continue;
    }
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    if (function_exists('shell_exec')) {
        $output = shell_exec($cmd);
    } else {
        $lines = [];
        $code = 1;
        exec($cmd, $lines, $code);
        $output = implode("\n", $lines);
    }
    fst_assert(is_string($output) && str_contains($output, 'No syntax errors'), "Syntax {$rel}", trim((string) $output));
}

// ── 7. JSON / API helpers ───────────────────────────────────────────────────
fst_section('7. JSON response helpers');

require_once $root . '/includes/api-json.php';
fst_assert(function_exists('json_response'), 'json_response() defined');
fst_assert(function_exists('api_json_with_context'), 'api_json_with_context() defined');

// ── 8. Live HTTP (optional) ─────────────────────────────────────────────────
if ($runLive && defined('APP_URL') && APP_URL !== '') {
    fst_section('8. Live HTTP endpoints');

    $endpoints = [
        '/login.php',
        '/system-check.php',
    ];
    foreach ($endpoints as $ep) {
        $url = rtrim(APP_URL, '/') . $ep;
        $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY        => true,
                CURLOPT_TIMEOUT       => 15,
                CURLOPT_FOLLOWLOCATION=> true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        fst_warn($code >= 200 && $code < 500, "HTTP {$ep}", 'code=' . $code);
    }

    if (defined('CRON_SECRET') && CRON_SECRET !== '') {
        $cronUrl = rtrim(APP_URL, '/') . '/api/cron.php?key=' . rawurlencode(CRON_SECRET);
        $body = @file_get_contents($cronUrl);
        fst_warn($body !== false && str_contains((string) $body, 'success'), 'Cron endpoint responds');
    }
}

// ── 9. WhatsApp OAuth ───────────────────────────────────────────────────────
fst_section('9. WhatsApp OAuth');

require_once $root . '/includes/whatsapp-oauth.php';
fst_assert(function_exists('whatsapp_oauth_callback_user'), 'whatsapp_oauth_callback_user() exists');
fst_assert(function_exists('whatsapp_resolve_waba_phones'), 'whatsapp_resolve_waba_phones() exists');

$state = whatsapp_oauth_build_state(1, '/client/dashboard', false);
$parsed = whatsapp_oauth_parse_state($state);
fst_assert($parsed !== null && (int) ($parsed['client_id'] ?? 0) === 1, 'OAuth signed state roundtrip');

$redirectUri = whatsapp_oauth_redirect_uri();
fst_assert(str_contains($redirectUri, '/client/whatsapp-oauth-callback.php'), 'OAuth redirect URI uses .php suffix');

$candidates = whatsapp_oauth_redirect_uri_candidates();
fst_assert(count($candidates) >= 2, 'Multiple OAuth redirect URI candidates', 'count=' . count($candidates));

fst_assert(db_column_exists('client_whatsapp_accounts', 'waba_id'), 'client_whatsapp_accounts.waba_id column');

fst_section('12. Conversation pipeline');
require_once $root . '/includes/conversation-pipeline.php';
require_once $root . '/includes/cart.php';
fst_assert(function_exists('pipeline_run_pre_ai'), 'pipeline_run_pre_ai() exists');
fst_assert(function_exists('pipeline_allows_conversational_shortcuts'), 'pipeline_allows_conversational_shortcuts() exists');
fst_assert(cart_message_is_farewell_or_decline('Good night'), 'farewell pattern: good night');
fst_assert(!cart_user_wants_checkout('ok'), 'bare ok is not checkout');
fst_assert(is_file($root . '/tests/conversation-scenarios-test.php'), 'conversation-scenarios-test.php present');

echo "\n════════════════════════════════════════\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";
echo "WARNINGS: {$warned}\n";
echo "════════════════════════════════════════\n";

if ($failed > 0) {
    echo "\nFix failures above, then re-run:\n  php tests/full-system-test.php --repair\n";
    exit(1);
}

echo "\nAll required tests passed.\n";
exit(0);
