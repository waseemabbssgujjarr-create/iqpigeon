<?php
/**
 * One-time migration: email verification columns on users table.
 * Run at /migrate-email-verification.php then DELETE this file.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

security_require_privileged_key();

$messages = [];
$errors = [];

function run_email_verification_migration(): array
{
    $messages = [];
    $errors = [];

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
                $messages[] = "Added column: {$col}";
            } catch (mysqli_sql_exception $e) {
                if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                    $messages[] = "Column already exists: {$col}";
                } else {
                    throw $e;
                }
            }
        }

        $conn->query('UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL');
        $messages[] = 'Existing users marked as email-verified.';
        $messages[] = 'Migration complete.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return ['messages' => $messages, 'errors' => $errors];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = run_email_verification_migration();
    $messages = $result['messages'];
    $errors = $result['errors'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Email Verification Migration') ?>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh] flex items-center justify-center p-edge-margin">
    <div class="w-full max-w-md bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant shadow-lg">
        <h1 class="font-headline text-headline-mob mb-md">Email Verification Migration</h1>
        <p class="text-body-md text-on-surface-variant mb-md">
            Adds verification columns to the users table.
        </p>

        <?php foreach ($errors as $err): ?>
            <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md"><?= sanitize($err) ?></div>
        <?php endforeach; ?>

        <?php foreach ($messages as $msg): ?>
            <div class="bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md mb-md text-body-md"><?= sanitize($msg) ?></div>
        <?php endforeach; ?>

        <?php if (empty($messages)): ?>
        <form method="POST">
            <button type="submit" class="w-full h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95">
                Run Migration
            </button>
        </form>
        <?php else: ?>
        <p class="text-error text-body-md font-medium mt-md">Delete migrate-email-verification.php from your server now.</p>
        <?php endif; ?>
    </div>
</body>
</html>
