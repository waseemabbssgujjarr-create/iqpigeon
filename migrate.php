<?php
/**
 * One-click database migration — run after deploy, then delete.
 *
 * https://yoursite.com/migrate.php?key=YOUR_CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/platform-schema.php';

$key = (string) ($_GET['key'] ?? '');
$allowed = defined('CRON_SECRET') && CRON_SECRET !== '' && hash_equals(CRON_SECRET, $key);
if (!$allowed && admin_access_key_valid($key)) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>403</h1><p>Use <code>?key=</code> from <code>CRON_SECRET</code> in config.php</p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$requiredTables = [
    'shipments',
    'shipment_events',
    'bot_courier_settings',
    'bot_orders',
    'bot_products',
    'bots',
    'users',
];

$before = [];
foreach ($requiredTables as $table) {
    $before[$table] = schema_table_exists($table);
}

$result = platform_ensure_all();

$after = [];
foreach ($requiredTables as $table) {
    $after[$table] = schema_table_exists($table);
}

$missingFiles = [];
foreach ([
    'track.php',
    'api/whatsapp-webhook.php',
    'api/shipment.php',
    'api/order-status.php',
    'includes/phase7-schema.php',
    'includes/shipment.php',
    'includes/platform-schema.php',
    'client/courier-settings.php',
    'system-check.php',
] as $rel) {
    if (!is_file(__DIR__ . '/' . $rel)) {
        $missingFiles[] = $rel;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Migration — IQ Pigeon</title>
<style>
body{font-family:system-ui,sans-serif;padding:2rem;max-width:820px;line-height:1.5}
.ok{color:#166534;background:#dcfce7;padding:1rem;border-radius:8px;margin:1rem 0}
.fail{color:#991b1b;background:#fee2e2;padding:1rem;border-radius:8px;margin:1rem 0}
.warn{color:#854d0e;background:#fef9c3;padding:1rem;border-radius:8px;margin:1rem 0}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px}
ul.log{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.25rem;max-height:420px;overflow:auto}
li{margin:.35rem 0;font-size:.9rem}
.err{color:#991b1b}
table{border-collapse:collapse;width:100%;margin:1rem 0}
td,th{border:1px solid #e5e7eb;padding:8px;text-align:left}
th{background:#f9fafb}
.btn{display:inline-block;margin-top:1rem;padding:.6rem 1rem;background:#4aad36;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}
</style>
</head>
<body>
<h1>Database migration</h1>
<p><?= htmlspecialchars(APP_URL) ?> · <?= date('Y-m-d H:i:s T') ?></p>

<?php if ($result['success'] && !in_array(false, $after, true)): ?>
<div class="ok"><strong>Migration completed.</strong> All required tables exist.</div>
<?php elseif ($result['success']): ?>
<div class="warn"><strong>Migration ran</strong> but some tables are still missing — see table status below.</div>
<?php else: ?>
<div class="fail"><strong>Migration reported errors.</strong> See log and table status below.</div>
<?php endif; ?>

<h2>Table status</h2>
<table>
<tr><th>Table</th><th>Before</th><th>After</th></tr>
<?php foreach ($requiredTables as $table): ?>
<tr>
    <td><code><?= htmlspecialchars($table) ?></code></td>
    <td><?= $before[$table] ? '✓' : '✗' ?></td>
    <td><?= $after[$table] ? '✓' : '<strong style="color:#991b1b">✗ missing</strong>' ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Migration log</h2>
<ul class="log">
<?php foreach ($result['messages'] as $line): ?>
    <li><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
<?php foreach ($result['errors'] as $line): ?>
    <li class="err">ERROR: <?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>

<?php if ($missingFiles !== []): ?>
<div class="fail">
<strong>Missing files on server</strong> — upload these from your project folder:
<ul>
<?php foreach ($missingFiles as $f): ?>
    <li><code><?= htmlspecialchars($f) ?></code></li>
<?php endforeach; ?>
</ul>
</div>
<?php else: ?>
<div class="ok">All critical PHP files are present on disk.</div>
<?php endif; ?>

<p>
    <a class="btn" href="/system-check?key=<?= rawurlencode($key) ?>">Open system check</a>
    <a class="btn" href="/system-check?key=<?= rawurlencode($key) ?>&repair=1" style="background:#374151;margin-left:.5rem">Run repair again</a>
</p>

<p><small>Delete <code>migrate.php</code> after tables show ✓. If <code>uploads/</code> is not writable, set folder permissions to 775 in hPanel File Manager.</small></p>
</body>
</html>
