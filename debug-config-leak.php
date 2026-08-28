<?php
/**
 * Diagnose plain-text key leak on admin pages.
 * Open: /debug-config-leak.php?key=YOUR_CRON_SECRET
 * DELETE after fixing.
 */
declare(strict_types=1);

$root = __DIR__;
$key = trim((string) ($_GET['key'] ?? ''));
$fatalError = '';

function dcl_read_secret(string $root, string $const): ?string
{
    foreach (['config.local.php', 'config.php'] as $file) {
        $path = $root . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path) ?: '';
        if (preg_match("/define\\s*\\(\\s*['\"]{$const}['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $raw, $m)) {
            return $m[1];
        }
    }
    return null;
}

function dcl_mask(string $s): string
{
    $len = strlen($s);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($s, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($s, -4);
}

function dcl_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function dcl_status(string $label, bool $ok, string $detail = ''): string
{
    $badge = $ok ? 'ok' : 'fail';
    $text = $ok ? 'OK' : 'ISSUE';
    $detailHtml = $detail !== '' ? '<div class="detail">' . dcl_h($detail) . '</div>' : '';
    return '<div class="row ' . $badge . '"><strong>' . dcl_h($label) . '</strong> — ' . $text . $detailHtml . '</div>';
}

function dcl_file_has_runtime_leak(string $raw, string $knownLeak): bool
{
    if ($knownLeak === '' || !str_contains($raw, $knownLeak)) {
        return false;
    }
    $stripped = preg_replace(
        '/str_replace\s*\(\s*[\'"]' . preg_quote($knownLeak, '/') . '[\'"]/',
        '',
        $raw
    ) ?? $raw;
    return str_contains($stripped, $knownLeak);
}

function dcl_scan_globs(string $root, string $knownLeak): array
{
    if ($knownLeak === '') {
        return [];
    }
    $hits = [];
    $patterns = [
        $root . '/includes/*.php',
        $root . '/admin/*.php',
        $root . '/config.php',
        $root . '/config.local.php',
        $root . '/storage/security/config.local.*.php',
    ];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path) ?: '';
            if (dcl_file_has_runtime_leak($raw, $knownLeak)) {
                $hits[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
            }
        }
    }
    return $hits;
}

$knownLeak = (string) (dcl_read_secret($root, 'SECURITY_HTML_LEAK_NEEDLE') ?? '');
$cron = dcl_read_secret($root, 'CRON_SECRET');
$allowed = ($cron !== null && $cron !== '' && hash_equals($cron, $key));

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head><body>';
    echo '<h1>403</h1><p>Use <code>?key=</code> from <code>CRON_SECRET</code></p></body></html>';
    exit;
}

try {
    $navPath = $root . '/includes/admin-navigation.php';
    $navLegacyPath = $root . '/includes/admin-nav.php';
    $adminNavPath = is_file($navPath) ? $navPath : $navLegacyPath;
    $adminNavRaw = is_file($adminNavPath) ? (file_get_contents($adminNavPath) ?: '') : '';
    $configRaw = is_file($root . '/config.php') ? (file_get_contents($root . '/config.php') ?: '') : '';
    $securityOutputPath = $root . '/includes/security-output.php';
    $leakHits = dcl_scan_globs($root, $knownLeak);

    $navTestOut = '';
    if (is_file($adminNavPath)) {
        ob_start();
        if (!function_exists('get_user')) {
            function get_user(): ?array { return null; }
        }
        if (!function_exists('sanitize')) {
            function sanitize(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
        }
        if (!function_exists('brand_logo_markup')) {
            function brand_logo_markup(string $c = '', string $m = 'auto'): string { return ''; }
        }
        if (!function_exists('render_admin_notification_bell')) {
            function render_admin_notification_bell(): void {}
        }
        include $adminNavPath;
        $navTestOut = ob_get_clean() ?: '';
    }

    $configHasFilter = str_contains($configRaw, 'security_sanitize_html_output')
        || (str_contains($configRaw, 'security-output.php') && str_contains($configRaw, 'ob_start'));
} catch (Throwable $e) {
    $fatalError = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    $adminNavRaw = $adminNavRaw ?? '';
    $configRaw = $configRaw ?? '';
    $leakHits = $leakHits ?? [];
    $navTestOut = $navTestOut ?? '';
    $configHasFilter = false;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Config leak debug</title>
    <style>
        body { font-family: ui-monospace, Consolas, monospace; background: #0f1219; color: #e5e7eb; margin: 0; padding: 24px; line-height: 1.5; }
        h1, h2 { font-family: system-ui, sans-serif; }
        .wrap { max-width: 960px; margin: 0 auto; }
        .row { padding: 12px 14px; border-radius: 10px; margin-bottom: 10px; border: 1px solid #333; background: #1a1f2e; }
        .row.ok { border-color: #3d9430; }
        .row.fail { border-color: #ba1a1a; background: #2a1518; }
        pre { background: #111827; padding: 14px; border-radius: 10px; overflow: auto; font-size: 12px; border: 1px solid #374151; }
        code { color: #6bc956; }
        .muted { color: #9ca3af; font-size: 13px; }
        ul { margin: 8px 0; padding-left: 20px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Config leak debugger</h1>
    <p class="muted">Delete this file after fixing.</p>

    <?php if ($fatalError !== ''): ?>
    <div class="row fail"><strong>Debug error</strong> — <?= dcl_h($fatalError) ?></div>
    <?php endif; ?>

    <h2>Summary</h2>
    <?php
    echo dcl_status('PHP version', true, PHP_VERSION);
    echo dcl_status('Site folder', true, $root);
    echo dcl_status('admin-navigation.php (v2)', is_file($navPath), is_file($navPath) ? 'New file — bypasses OPcache on old admin-nav.php' : 'Upload includes/admin-navigation.php');
    echo dcl_status('admin-nav.php is thin wrapper', is_file($navLegacyPath) && str_contains(file_get_contents($navLegacyPath) ?: '', 'admin-navigation.php'), is_file($navLegacyPath) ? 'Must be 3-line require only — delete old fat copy on server' : 'Missing');
    echo dcl_status('Key in admin-nav.php file', $knownLeak === '' || !dcl_file_has_runtime_leak($adminNavRaw, $knownLeak), $knownLeak === '' ? 'Set SECURITY_HTML_LEAK_NEEDLE in config.local.php to scan for a specific leaked string' : (dcl_file_has_runtime_leak($adminNavRaw, $knownLeak) ? 'Bare key pasted in file — re-upload clean copy' : 'Not in file (stripper code ignored)'));
    echo dcl_status('config.php output filter', $configHasFilter, $configHasFilter ? 'Filter wired' : 'Upload latest config.php');
    echo dcl_status('security-output.php exists', is_file($securityOutputPath), is_file($securityOutputPath) ? (string) filesize($securityOutputPath) . ' bytes' : 'Missing');
    $navClean = $knownLeak === '' || !str_contains($navTestOut, $knownLeak);
    echo dcl_status('admin-nav runtime test', $navClean, $knownLeak === '' ? 'Exact-string scan skipped' : ($navClean ? 'No key in output' : 'KEY STILL OUTPUT — check OPcache or file on disk'));
    ?>

    <?php if ($leakHits !== []): ?>
    <h2>Files that still contain the key</h2>
    <ul class="muted">
        <?php foreach ($leakHits as $hit): ?>
        <li><code><?= dcl_h($hit) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <h2>Files that still contain the key</h2>
    <p class="muted">None found on disk — if line remains, PHP OPcache is serving an old file. Rename <code>admin-nav.php</code> to force reload, or wait 5 min.</p>
    <?php endif; ?>

    <h2>admin-nav test output (first 200 chars)</h2>
    <pre><?= dcl_h(substr(strip_tags($navTestOut), 0, 200) ?: '(empty)') ?></pre>

    <h2>Fix steps</h2>
    <ol class="muted">
        <li>Delete OLD <code>includes/admin-nav.php</code> on server (the big file), then upload new thin wrapper + <code>includes/admin-navigation.php</code></li>
        <li>Upload all <code>admin/*.php</code> + <code>assets/js/app.js</code></li>
        <li>Delete all <code>storage/security/config.local.*.php</code></li>
        <li>Hard refresh admin: Ctrl+Shift+R (or Incognito)</li>
    </ol>
</div>
</body>
</html>
