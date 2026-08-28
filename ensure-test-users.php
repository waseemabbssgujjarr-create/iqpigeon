<?php
/**
 * One-time: DB migration + create/reset admin & demo test accounts.
 * Run: https://yoursite.com/ensure-test-users.php then DELETE this file.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/openai.php';

header('Content-Type: text/html; charset=utf-8');

$messages = [];
$errors = [];

$adminEmail = defined('TEST_ADMIN_EMAIL') ? TEST_ADMIN_EMAIL : ADMIN_EMAIL;
$adminPass = defined('TEST_ADMIN_PASSWORD') ? TEST_ADMIN_PASSWORD : 'Admin@12345';
$clientEmail = defined('TEST_CLIENT_EMAIL') ? TEST_CLIENT_EMAIL : 'demo@iqpigeon.com';
$clientPass = defined('TEST_CLIENT_PASSWORD') ? TEST_CLIENT_PASSWORD : 'Demo@12345';

try {
    $conn = db_connect();

    $columns = [
        'email_verified_at' => 'DATETIME NULL DEFAULT NULL',
        'verify_token'      => 'VARCHAR(100) NULL DEFAULT NULL',
        'verify_code'       => 'VARCHAR(6) NULL DEFAULT NULL',
        'verify_expires_at' => 'DATETIME NULL DEFAULT NULL',
    ];

    foreach ($columns as $col => $definition) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN {$col} {$definition}");
            $messages[] = "Added DB column: {$col}";
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }

    $adminHash = password_hash($adminPass, PASSWORD_BCRYPT);
    $existingAdmin = db_fetch('SELECT id FROM users WHERE email = ? AND role = \'admin\'', 's', [$adminEmail]);

    if (!$existingAdmin) {
        $adminId = db_insert(
            'INSERT INTO users (name, email, password, role, company_name, avatar_initials, subscription_status)
             VALUES (?, ?, ?, \'admin\', ?, ?, \'active\')',
            'sssss',
            ['Super Admin', $adminEmail, $adminHash, APP_NAME, 'SA']
        );
        db_mark_email_verified($adminId);
        $messages[] = "Admin CREATED: {$adminEmail} / {$adminPass}";
    } else {
        db_execute(
            'UPDATE users SET password = ?, login_attempts = 0, locked_until = NULL WHERE email = ? AND role = \'admin\'',
            'ss',
            [$adminHash, $adminEmail]
        );
        db_mark_email_verified((int) $existingAdmin['id']);
        $messages[] = "Admin password RESET: {$adminEmail} / {$adminPass}";
    }

    $clientHash = password_hash($clientPass, PASSWORD_BCRYPT);
    $existingClient = db_fetch('SELECT id FROM users WHERE email = ?', 's', [$clientEmail]);

    if (!$existingClient) {
        $clientId = db_insert(
            'INSERT INTO users (name, email, password, role, company_name, avatar_initials,
                                subscription_plan, subscription_status, trial_ends_at)
             VALUES (?, ?, ?, \'client\', ?, ?, \'growth\', \'active\', ?)',
            'ssssss',
            [
                'Demo User',
                $clientEmail,
                $clientHash,
                'Demo Company',
                'DU',
                date('Y-m-d H:i:s', strtotime('+1 year')),
            ]
        );
        db_mark_email_verified($clientId);

        $botId = db_insert(
            'INSERT INTO bots (user_id, name, persona_description, widget_enabled, widget_color, is_active, openai_system_prompt)
             VALUES (?, ?, ?, 1, ?, 1, ?)',
            'issisis',
            [
                $clientId,
                'Demo Sales Bot',
                'Friendly AI sales assistant for demos.',
                '#006d2f',
                build_system_prompt([
                    'name' => 'Demo Sales Bot',
                    'persona_description' => 'Friendly and helpful.',
                    'qualifying_questions' => '[]',
                ], 'Demo Company'),
            ]
        );

        db_execute(
            'INSERT INTO settings (key_name, value) VALUES (\'demo_bot_id\', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            's',
            [(string) $botId]
        );

        $messages[] = "Demo client CREATED: {$clientEmail} / {$clientPass} (bot #{$botId})";
    } else {
        $clientId = (int) $existingClient['id'];
        db_execute(
            'UPDATE users SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?',
            'si',
            [$clientHash, $clientId]
        );
        db_mark_email_verified($clientId);
        $messages[] = "Demo client password RESET: {$clientEmail} / {$clientPass}";
    }

    $messages[] = 'Done — admin: ' . admin_login_url() . ' | client: /login.php';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"/><title>Setup Test Users</title></head>
<body style="font-family:Inter,sans-serif;max-width:560px;margin:40px auto;padding:20px;">
<h1>Test account setup</h1>

<?php foreach ($errors as $err): ?>
<p style="color:#ba1a1a;background:#fce8e6;padding:12px;border-radius:8px;"><?= htmlspecialchars($err) ?></p>
<?php endforeach; ?>

<ul>
<?php foreach ($messages as $m): ?>
    <li><?= htmlspecialchars($m) ?></li>
<?php endforeach; ?>
</ul>

<p><a href="<?= htmlspecialchars(admin_login_url()) ?>"><strong>Admin sign in →</strong></a></p>
<p><a href="/login"><strong>Client login →</strong></a></p>
<p style="color:#ba1a1a;margin-top:2rem;"><strong>Delete ensure-test-users.php from your server now.</strong></p>
</body>
</html>
