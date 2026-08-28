<?php
/**
 * Run all database migrations + upload directories in one call.
 * Used by cron, system-check repair, and dashboard bootstrap.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/phase5-schema.php';
require_once __DIR__ . '/phase6-schema.php';
require_once __DIR__ . '/phase7-schema.php';
require_once __DIR__ . '/payment-schema.php';
require_once __DIR__ . '/bot-knowledge.php';
require_once __DIR__ . '/lead-lifecycle.php';
require_once __DIR__ . '/whatsapp-inbound.php';
require_once __DIR__ . '/whatsapp-token.php';
require_once __DIR__ . '/platform-renewals.php';
require_once __DIR__ . '/conversation-turn-engine.php';
require_once __DIR__ . '/migrations/whatsapp_tables.php';
require_once __DIR__ . '/migrations/notifications_tables.php';

/**
 * @return array{success: bool, messages: array<int, string>, errors: array<int, string>}
 */
function platform_ensure_all(): array
{
    $messages = [];
    $errors = [];

    $steps = [
        'commerce'     => static fn () => ensure_commerce_schema(),
        'phase5'       => static fn () => ensure_phase5_schema(),
        'phase6'       => static fn () => ensure_phase6_schema(),
        'phase7'       => static fn () => ensure_phase7_schema(),
        'payment'      => static fn () => ensure_payment_schema(),
        'bots'         => static fn () => ensure_bots_schema(),
        'leads'        => static fn () => ensure_leads_schema(),
        'conversations'=> static fn () => ensure_conversations_schema(),
        'lifecycle'    => static fn () => ensure_lead_lifecycle_schema(),
        'wa_inbound'   => static fn () => ensure_whatsapp_inbound_schema(),
        'wa_token'     => static fn () => ensure_whatsapp_token_schema(),
        'turn_engine'  => static fn () => turn_engine_ensure_schema(),
        'oauth'        => static fn () => ensure_oauth_schema(),
        'users_auth'   => static fn () => ensure_users_auth_schema(),
        'renewals'     => static fn () => ensure_platform_renewals_schema(),
    ];

    foreach ($steps as $name => $fn) {
        try {
            $fn();
            $messages[] = "Schema OK: {$name}";
        } catch (Throwable $e) {
            $errors[] = "{$name}: " . $e->getMessage();
        }
    }

    $wa = run_whatsapp_embedded_signup_migration();
    $messages = array_merge($messages, $wa['messages'] ?? []);
    $errors = array_merge($errors, $wa['errors'] ?? []);

    $notif = run_notifications_migration();
    $messages = array_merge($messages, $notif['messages'] ?? []);
    $errors = array_merge($errors, $notif['errors'] ?? []);

    $uploads = platform_ensure_upload_dirs();
    $messages = array_merge($messages, $uploads['messages'] ?? []);
    $errors = array_merge($errors, $uploads['errors'] ?? []);

    return [
        'success'  => $errors === [],
        'messages' => $messages,
        'errors'   => $errors,
    ];
}

function ensure_oauth_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!db_column_exists('users', 'google_id')) {
        $conn = db_connect();
        schema_exec_sql($conn, 'ALTER TABLE users ADD COLUMN google_id VARCHAR(64) NULL UNIQUE AFTER password');
    }

    if (!db_column_exists('users', 'facebook_id')) {
        $conn = db_connect();
        $after = db_column_exists('users', 'google_id') ? 'google_id' : 'password';
        schema_exec_sql($conn, 'ALTER TABLE users ADD COLUMN facebook_id VARCHAR(64) NULL UNIQUE AFTER ' . $after);
    }

    if (!db_column_exists('users', 'auth_provider')) {
        $conn = db_connect();
        schema_exec_sql($conn, 'ALTER TABLE users ADD COLUMN auth_provider VARCHAR(32) NULL DEFAULT NULL AFTER facebook_id');
    }

    $done = true;
}

function ensure_users_auth_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();
    commerce_ensure_column($conn, 'users', 'reset_token', 'VARCHAR(100) NULL');
    commerce_ensure_column($conn, 'users', 'reset_expires_at', 'DATETIME NULL');

    $done = true;
}

/**
 * @return array{messages: array<int, string>, errors: array<int, string>}
 */
function platform_ensure_upload_dirs(): array
{
    $messages = [];
    $errors = [];
    $root = dirname(__DIR__);

    $dirs = [
        $root . '/uploads',
        $root . '/uploads/shipments',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $errors[] = 'Could not create directory: ' . $dir;
            continue;
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
        }
        if (!is_writable($dir)) {
            $errors[] = 'Directory not writable: ' . $dir;
        } else {
            $messages[] = 'Writable: ' . str_replace($root, '', $dir);
        }
    }

    $guard = $root . '/uploads/index.php';
    if (!is_file($guard)) {
        @file_put_contents($guard, "<?php\nhttp_response_code(403);\nexit('Forbidden');\n");
    }

    $htaccess = $root . '/uploads/shipments/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n");
    }

    return ['messages' => $messages, 'errors' => $errors];
}

function platform_ensure_all_silent(): void
{
    try {
        platform_ensure_all();
    } catch (Throwable $e) {
        error_log('platform_ensure_all: ' . $e->getMessage());
    }
}
