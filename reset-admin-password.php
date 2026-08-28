<?php
/**
 * Secret admin password reset — upload, run once, then delete.
 *
 * https://yoursite.com/reset-admin-password.php?key=YOUR_CRON_SECRET&password=NewSecurePass123
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/security.php';

security_require_privileged_key();

header('Content-Type: text/html; charset=utf-8');

$message = '';
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['password'])) {
    $newPassword = trim((string) ($_POST['password'] ?? $_GET['password'] ?? ''));
    $confirm = trim((string) ($_POST['confirm_password'] ?? $newPassword));

    if (strlen($newPassword) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif ($newPassword !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $admin = db_fetch('SELECT id FROM users WHERE role = \'admin\' ORDER BY id ASC LIMIT 1');
        if (!$admin) {
            $error = 'No admin user found in database.';
        } else {
            db_execute(
                'UPDATE users SET password = ? WHERE id = ?',
                'si',
                [password_hash($newPassword, PASSWORD_BCRYPT), (int) $admin['id']]
            );
            $message = 'Admin password updated successfully.';
            $done = true;
            if (function_exists('security_audit')) {
                security_audit('admin_password_reset_script', ['admin_id' => (int) $admin['id']]);
            }
        }
    }
}

$key = htmlspecialchars((string) ($_GET['key'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Reset Admin Password</title>
<style>
body{font-family:system-ui,sans-serif;padding:2rem;max-width:480px;line-height:1.5}
.ok{color:#166534;background:#dcfce7;padding:1rem;border-radius:8px;margin:1rem 0}
.fail{color:#991b1b;background:#fee2e2;padding:1rem;border-radius:8px;margin:1rem 0}
input{width:100%;padding:.75rem;margin:.5rem 0 1rem;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}
button{width:100%;padding:.75rem;background:#4aad36;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px}
</style>
</head>
<body>
<h1>Reset admin password</h1>
<p>Keep this file offline. Upload only when you need to change the admin password, then <strong>delete it</strong> from the server.</p>

<?php if ($message): ?>
<div class="ok"><?= htmlspecialchars($message) ?></div>
<p>Delete <code>reset-admin-password.php</code> from your server now.</p>
<?php endif; ?>

<?php if ($error): ?>
<div class="fail"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!$done): ?>
<form method="POST" action="?key=<?= $key ?>">
    <label>New admin password (min 12 chars)</label>
    <input type="password" name="password" required minlength="12" autocomplete="new-password"/>
    <label>Confirm password</label>
    <input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"/>
    <button type="submit">Update admin password</button>
</form>
<?php endif; ?>

<p><small>Requires <code>?key=</code> from <code>CRON_SECRET</code> in config.php. Admin password cannot be changed from the admin panel.</small></p>
</body>
</html>
