<?php
/**
 * Commerce + booking database tables (catalog, orders, appointments).
 */

require_once __DIR__ . '/db.php';

function ensure_commerce_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT \'PKR\',
            image_url VARCHAR(512) NULL,
            sku VARCHAR(64) NULL,
            category VARCHAR(100) NULL,
            stock INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bot_products_bot (bot_id),
            INDEX idx_bot_products_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            lead_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            status ENUM(\'new\',\'confirmed\',\'shipped\',\'delivered\',\'cancelled\') NOT NULL DEFAULT \'new\',
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT \'PKR\',
            cod TINYINT(1) NOT NULL DEFAULT 1,
            customer_name VARCHAR(255) NULL,
            customer_phone VARCHAR(32) NULL,
            shipping_address TEXT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bot_orders_bot (bot_id),
            INDEX idx_bot_orders_lead (lead_id),
            INDEX idx_bot_orders_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            INDEX idx_order_items_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_booking_settings (
            bot_id INT UNSIGNED NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            slot_duration_min INT NOT NULL DEFAULT 30,
            buffer_min INT NOT NULL DEFAULT 0,
            timezone VARCHAR(64) NOT NULL DEFAULT \'Asia/Karachi\',
            working_hours JSON NULL,
            use_native_booking TINYINT(1) NOT NULL DEFAULT 1,
            fallback_calendly VARCHAR(512) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_appointments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            lead_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            slot_start DATETIME NOT NULL,
            slot_end DATETIME NOT NULL,
            status ENUM(\'pending\',\'confirmed\',\'cancelled\',\'completed\') NOT NULL DEFAULT \'confirmed\',
            customer_name VARCHAR(255) NULL,
            customer_phone VARCHAR(32) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appointments_bot (bot_id),
            INDEX idx_appointments_lead (lead_id),
            INDEX idx_appointments_slot (slot_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    commerce_ensure_column($conn, 'bot_products', 'external_source', "VARCHAR(32) NULL DEFAULT 'manual' AFTER sort_order");
    commerce_ensure_column($conn, 'bot_products', 'external_id', 'VARCHAR(128) NULL AFTER external_source');
    commerce_ensure_column($conn, 'bot_products', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
    commerce_ensure_column($conn, 'bot_products', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    commerce_ensure_column($conn, 'bots', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    require_once __DIR__ . '/lead-lifecycle.php';
    ensure_lead_lifecycle_schema();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS shop_integrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            platform ENUM(\'shopify\',\'woocommerce\') NOT NULL,
            store_url VARCHAR(512) NOT NULL,
            api_key VARCHAR(255) NULL,
            api_secret VARCHAR(255) NULL,
            access_token VARCHAR(512) NULL,
            webhook_secret VARCHAR(255) NULL,
            last_sync_at TIMESTAMP NULL,
            sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_bot_platform (bot_id, platform),
            INDEX idx_shop_int_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS broadcasts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            message_body TEXT NOT NULL,
            segment VARCHAR(64) NOT NULL DEFAULT \'all\',
            send_mode ENUM(\'session\',\'template\') NOT NULL DEFAULT \'session\',
            template_name VARCHAR(128) NULL,
            template_lang VARCHAR(16) NULL DEFAULT \'en\',
            status ENUM(\'draft\',\'sending\',\'completed\',\'failed\') NOT NULL DEFAULT \'draft\',
            total_recipients INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            INDEX idx_broadcasts_bot (bot_id),
            INDEX idx_broadcasts_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS broadcast_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            broadcast_id INT UNSIGNED NOT NULL,
            lead_id INT UNSIGNED NULL,
            phone VARCHAR(32) NOT NULL,
            status ENUM(\'pending\',\'sent\',\'failed\',\'skipped\') NOT NULL DEFAULT \'pending\',
            error_message VARCHAR(512) NULL,
            sent_at TIMESTAMP NULL,
            INDEX idx_bcast_rec_broadcast (broadcast_id),
            INDEX idx_bcast_rec_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    commerce_ensure_column($conn, 'bot_booking_settings', 'public_slug', 'VARCHAR(64) NULL AFTER fallback_calendly');
    commerce_ensure_column($conn, 'bot_appointments', 'reminder_24h_sent', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER notes');
    commerce_ensure_column($conn, 'bot_appointments', 'reminder_1h_sent', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_24h_sent');
    commerce_ensure_column($conn, 'bot_appointments', 'source', "VARCHAR(32) NULL DEFAULT 'whatsapp' AFTER reminder_1h_sent");

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_order_status_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            old_status VARCHAR(32) NULL,
            new_status VARCHAR(32) NOT NULL,
            status_label VARCHAR(120) NULL,
            customer_notified TINYINT(1) NOT NULL DEFAULT 0,
            notify_error VARCHAR(255) NULL,
            source VARCHAR(32) NOT NULL DEFAULT \'dashboard\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_status_events_order (order_id),
            INDEX idx_order_status_events_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function schema_table_exists(string $table): bool
{
    try {
        $row = db_fetch(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            'ss',
            [DB_NAME, $table]
        );
        return $row !== null;
    } catch (Throwable $e) {
        return false;
    }
}

function schema_exec_sql(mysqli $conn, string $sql, string $label = ''): void
{
    try {
        $conn->query($sql);
    } catch (Throwable $e) {
        $hint = $label !== '' ? $label . ': ' : '';
        throw new RuntimeException($hint . $e->getMessage());
    }
}

/**
 * Extra `users` profile columns referenced across settings/training pages
 * (avatar, bio, address, phone, industry) — several call sites already read
 * or write these without ever having created them; this makes it safe.
 */
function ensure_user_profile_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();
    $columns = [
        'avatar_url'       => "VARCHAR(255) NULL DEFAULT NULL AFTER avatar_initials",
        'bio'              => "TEXT NULL AFTER company_name",
        'address'          => "VARCHAR(255) NULL AFTER bio",
        'phone'            => "VARCHAR(30) NULL AFTER address",
        'industry'         => "VARCHAR(80) NULL AFTER phone",
        'profile_title'    => "VARCHAR(40) NULL DEFAULT 'Owner' AFTER industry",
        'pref_language'    => "VARCHAR(32) NOT NULL DEFAULT 'English' AFTER profile_title",
        'pref_timezone'    => "VARCHAR(64) NOT NULL DEFAULT 'Asia/Karachi' AFTER pref_language",
        'pref_currency'    => "VARCHAR(8) NOT NULL DEFAULT 'PKR' AFTER pref_timezone",
        'pref_date_format' => "VARCHAR(32) NOT NULL DEFAULT 'DD MMM, YYYY' AFTER pref_currency",
        'business_email'   => "VARCHAR(120) NULL DEFAULT NULL AFTER phone",
    ];
    foreach ($columns as $col => $def) {
        commerce_ensure_column($conn, 'users', $col, $def);
    }

    $done = true;
}

function commerce_ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!schema_table_exists($table)) {
        error_log('commerce_ensure_column skipped — table missing: ' . $table);
        return;
    }

    $row = db_fetch(
        'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        'sss',
        [DB_NAME, $table, $column]
    );
    if ((int) ($row['cnt'] ?? 0) === 0) {
        try {
            $conn->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        } catch (Throwable $e) {
            error_log('commerce_ensure_column ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
    }
}
