<?php
/**
 * Phase 6: promo codes, abandoned cart recovery, quick replies.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/phase5-schema.php';

function ensure_phase6_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_phase5_schema();
    $conn = db_connect();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_promo_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            discount_type ENUM(\'percent\',\'fixed\') NOT NULL DEFAULT \'percent\',
            discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            min_order DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_uses INT NULL,
            used_count INT NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_bot_code (bot_id, code),
            INDEX idx_promo_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS bot_abandoned_cart_settings (
            bot_id INT UNSIGNED NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            delay_hours INT NOT NULL DEFAULT 24,
            message_body TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS abandoned_cart_sends (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lead_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_abandoned_lead (lead_id),
            INDEX idx_abandoned_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS quick_replies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NULL,
            title VARCHAR(80) NOT NULL,
            message_body TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_qr_user (user_id),
            INDEX idx_qr_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    commerce_ensure_column($conn, 'bot_orders', 'promo_code', 'VARCHAR(32) NULL AFTER notes');
    commerce_ensure_column($conn, 'bot_orders', 'discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER promo_code');

    $done = true;
}
