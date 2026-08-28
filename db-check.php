<?php
/**
 * Database connection test — use once after deploy, then delete.
 *
 * https://iqpigeon.com/db-check.php?key=YOUR_CRON_SECRET
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$configPath = __DIR__ . '/config.php';
$localPath = __DIR__ . '/config.local.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo '<h1>config.php missing</h1>';
    exit;
}

require_once $configPath;
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/commerce-schema.php';

$key = (string) ($_GET['key'] ?? '');
$allowed = defined('CRON_SECRET') && CRON_SECRET !== '' && hash_equals(CRON_SECRET, $key);
if (!$allowed && admin_access_key_valid($key)) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo '<h1>403</h1><p>Open with <code>?key=</code> from <code>CRON_SECRET</code> in config.php</p>';
    exit;
}

$localLoaded = is_file($localPath);

function db_check_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $schema = DB_NAME;
    $stmt->bind_param('ss', $schema, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>DB Check — IQ Pigeon</title>
<style>
body{font-family:system-ui,sans-serif;padding:2rem;max-width:720px;line-height:1.5}
.ok{color:#166534;background:#dcfce7;padding:1rem;border-radius:8px;margin:1rem 0}
.fail{color:#991b1b;background:#fee2e2;padding:1rem;border-radius:8px;margin:1rem 0}
.warn{color:#854d0e;background:#fef9c3;padding:1rem;border-radius:8px;margin:1rem 0}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:0.9em}
table{border-collapse:collapse;width:100%;margin:1rem 0}
td,th{border:1px solid #e5e7eb;padding:8px;text-align:left}
th{background:#f9fafb}
.btn{display:inline-block;margin-top:.5rem;padding:.5rem 1rem;background:#4aad36;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}
</style>
</head>
<body>
<h1>Database check</h1>

<table>
<tr><th>Setting</th><th>Active value</th></tr>
<tr><td>Config source</td><td><code><?= $localLoaded ? 'config.local.php (overrides config.php)' : 'config.php defaults only' ?></code></td></tr>
<tr><td>DB_HOST</td><td><code><?= htmlspecialchars(DB_HOST) ?></code></td></tr>
<tr><td>DB_NAME</td><td><code><?= htmlspecialchars(DB_NAME) ?></code></td></tr>
<tr><td>DB_USER</td><td><code><?= htmlspecialchars(DB_USER) ?></code></td></tr>
<tr><td>Password</td><td><code><?= DB_PASS !== '' ? '•••••••• (' . strlen(DB_PASS) . ' chars)' : '(empty)' ?></code></td></tr>
</table>

<?php if (!$localLoaded): ?>
<div class="warn">
<strong>Tip:</strong> Create <code>config.local.php</code> on the server with Hostinger credentials.
Do not put live passwords only in <code>config.php</code> if you re-upload the project from your PC.
</div>
<?php endif; ?>

<?php
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');

    $users = db_check_table_exists($db, 'users')
        ? (int) ($db->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'] ?? 0)
        : 0;
    $bots = db_check_table_exists($db, 'bots')
        ? (int) ($db->query('SELECT COUNT(*) AS c FROM bots')->fetch_assoc()['c'] ?? 0)
        : 0;
    $orders = db_check_table_exists($db, 'bot_orders')
        ? (int) ($db->query('SELECT COUNT(*) AS c FROM bot_orders')->fetch_assoc()['c'] ?? 0)
        : 0;

    echo '<div class="ok"><strong>Connected successfully.</strong><br>';
    echo "Users: {$users} · Bots: {$bots} · Orders: {$orders}</div>";

    $shipTables = ['shipments', 'shipment_events', 'bot_courier_settings'];
    echo '<h2>Shipment tables</h2><table><tr><th>Table</th><th>Status</th></tr>';
    $missingShip = [];
    foreach ($shipTables as $table) {
        $exists = db_check_table_exists($db, $table);
        if (!$exists) {
            $missingShip[] = $table;
        }
        echo '<tr><td><code>' . htmlspecialchars($table) . '</code></td><td>'
            . ($exists ? '✓ exists' : '<strong style="color:#991b1b">missing</strong>') . '</td></tr>';
    }
    echo '</table>';

    if ($missingShip !== []) {
        echo '<div class="warn"><strong>Missing shipment tables.</strong> Upload the latest <code>includes/phase7-schema.php</code> and <code>migrate.php</code>, then open migrate below.</div>';
        echo '<a class="btn" href="/migrate.php?key=' . rawurlencode($key) . '">Run migrate.php</a>';
    } else {
        echo '<div class="ok">All shipment tables exist.</div>';
    }

    echo '<p style="margin-top:1.5rem">Next: <a href="/migrate.php?key=' . rawurlencode($key) . '">migrate.php</a> · ';
    echo '<a href="/system-check.php?key=' . rawurlencode($key) . '&repair=1">system-check.php?repair=1</a></p>';
    $db->close();
} catch (Throwable $e) {
    $msg = $e->getMessage();
    echo '<div class="fail"><strong>Connection failed</strong><br>' . htmlspecialchars($msg) . '</div>';

    if (str_contains($msg, '1045') || str_contains($msg, 'Access denied')) {
        echo '<h2>Fix “Access denied” on Hostinger</h2>';
        echo '<ol>';
        echo '<li>hPanel → <strong>Databases → MySQL Databases</strong></li>';
        echo '<li>Note the <strong>full</strong> database name (e.g. <code>YOUR_DB_NAME</code>) — not a short name.</li>';
        echo '<li>Note the <strong>full</strong> username (e.g. <code>YOUR_DB_USER</code>) — must match exactly.</li>';
        echo '<li>Under <strong>Add user to database</strong>, link the user to the database with <strong>ALL PRIVILEGES</strong>.</li>';
        echo '<li>If unsure of password: <strong>Change password</strong> for that MySQL user in hPanel.</li>';
        echo '<li>Put the three values in <code>config.local.php</code> on the server:</li>';
        echo '</ol>';
        echo '<pre style="background:#f3f4f6;padding:1rem;border-radius:8px;overflow:auto">&lt;?php
define(\'DB_HOST\', \'localhost\');
define(\'DB_NAME\', \'u475685511_YOUR_DB\');
define(\'DB_USER\', \'u475685511_YOUR_USER\');
define(\'DB_PASS\', \'YOUR_NEW_PASSWORD\');</pre>';
        echo '<p>If you edited <code>config.php</code> but still see the wrong user above, check whether <code>config.local.php</code> exists with old credentials — delete or update it.</p>';
    }

    if (str_contains($msg, '1049') || str_contains($msg, 'Unknown database')) {
        echo '<div class="warn">Database name does not exist. Create it in hPanel → MySQL Databases, then update DB_NAME.</div>';
    }
}
?>

<h2>IQ Pigeon table names (not generic “orders”)</h2>
<p>This app uses <code>bot_orders</code>, <code>shipments</code>, <code>bot_courier_settings</code> — not <code>orders</code> or <code>shipment_providers</code>. After DB connects, run migrate.php to create shipment tables.</p>

<p><small>Delete db-check.php after fixing.</small></p>
</body>
</html>
