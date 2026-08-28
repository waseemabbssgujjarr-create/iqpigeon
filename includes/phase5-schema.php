<?php
/**
 * Phase 5: team inbox, drip sequences, Meta catalog fields.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/commerce-schema.php';

function ensure_phase5_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_commerce_schema();
    $conn = db_connect();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS team_members (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NULL,
            color VARCHAR(16) NOT NULL DEFAULT \'#6366f1\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_team_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS lead_internal_notes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lead_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            author_name VARCHAR(100) NOT NULL DEFAULT \'Owner\',
            note TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notes_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS drip_sequences (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            delay_hours INT NOT NULL DEFAULT 48,
            message_body TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_drip_bot (bot_id),
            INDEX idx_drip_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS drip_sends (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lead_id INT UNSIGNED NOT NULL,
            sequence_id INT UNSIGNED NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_drip_lead_seq (lead_id, sequence_id),
            INDEX idx_drip_sends_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    commerce_ensure_column($conn, 'team_members', 'designation', "VARCHAR(32) NOT NULL DEFAULT 'agent' AFTER email");

    commerce_ensure_column($conn, 'leads', 'assigned_member_id', 'INT UNSIGNED NULL AFTER notes');
    commerce_ensure_column($conn, 'leads', 'inbox_priority', "ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER assigned_member_id");
    commerce_ensure_column($conn, 'bots', 'whatsapp_catalog_id', 'VARCHAR(64) NULL AFTER widget_enabled');
    commerce_ensure_column($conn, 'bots', 'whatsapp_auto_reply', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER widget_enabled');
    commerce_ensure_column($conn, 'bot_products', 'meta_retailer_id', 'VARCHAR(128) NULL AFTER external_id');

    $done = true;
}
