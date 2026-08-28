<?php

/**

 * Phase 7: Shipments & courier integration settings.

 */



require_once __DIR__ . '/db.php';

require_once __DIR__ . '/phase6-schema.php';



function ensure_phase7_schema(): void

{

    static $done = false;

    if ($done) {

        return;

    }



    ensure_phase6_schema();

    $conn = db_connect();



    schema_exec_sql($conn, 'CREATE TABLE IF NOT EXISTS shipments (

            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            order_id INT UNSIGNED NOT NULL,

            bot_id INT UNSIGNED NOT NULL,

            user_id INT UNSIGNED NOT NULL,

            lead_id INT UNSIGNED NULL,

            courier_name VARCHAR(120) NOT NULL DEFAULT \'\',

            tracking_number VARCHAR(120) NOT NULL DEFAULT \'\',

            tracking_url VARCHAR(512) NULL,

            dispatch_date DATE NULL,

            estimated_delivery DATE NULL,

            current_status VARCHAR(64) NOT NULL DEFAULT \'shipment_created\',

            last_synced_at DATETIME NULL,

            courier_provider VARCHAR(64) NULL,

            api_enabled TINYINT(1) NOT NULL DEFAULT 0,

            notes TEXT NULL,

            receipt_image_url VARCHAR(512) NULL,

            pod_image_url VARCHAR(512) NULL,

            public_tracking_token VARCHAR(64) NULL,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_shipment_order (order_id),

            INDEX idx_shipments_user (user_id),

            INDEX idx_shipments_status (current_status),

            INDEX idx_shipments_api (api_enabled, current_status)

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'CREATE shipments');



    schema_exec_sql($conn, 'CREATE TABLE IF NOT EXISTS shipment_events (

            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            shipment_id INT UNSIGNED NOT NULL,

            status VARCHAR(64) NOT NULL,

            title VARCHAR(255) NOT NULL,

            description TEXT NULL,

            location VARCHAR(255) NULL,

            event_at DATETIME NOT NULL,

            source ENUM(\'manual\',\'system\',\'api\') NOT NULL DEFAULT \'system\',

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_shipment_events_shipment (shipment_id),

            INDEX idx_shipment_events_at (event_at)

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'CREATE shipment_events');



    schema_exec_sql($conn, 'CREATE TABLE IF NOT EXISTS bot_courier_settings (

            bot_id INT UNSIGNED NOT NULL PRIMARY KEY,

            user_id INT UNSIGNED NOT NULL,

            provider VARCHAR(64) NOT NULL DEFAULT \'manual\',

            api_username VARCHAR(255) NULL,

            api_password VARCHAR(255) NULL,

            api_key VARCHAR(255) NULL,

            api_secret VARCHAR(255) NULL,

            account_number VARCHAR(120) NULL,

            environment ENUM(\'sandbox\',\'production\') NOT NULL DEFAULT \'production\',

            api_enabled TINYINT(1) NOT NULL DEFAULT 0,

            auto_tracking_urls TINYINT(1) NOT NULL DEFAULT 1,

            send_receipt_on_ship TINYINT(1) NOT NULL DEFAULT 1,

            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_courier_user (user_id)

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'CREATE bot_courier_settings');



    commerce_ensure_column($conn, 'bot_courier_settings', 'auto_tracking_urls', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER api_enabled');

    commerce_ensure_column($conn, 'bot_courier_settings', 'send_receipt_on_ship', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_tracking_urls');



    commerce_ensure_column($conn, 'shipments', 'receipt_image_url', 'VARCHAR(512) NULL AFTER notes');

    commerce_ensure_column($conn, 'shipments', 'pod_image_url', 'VARCHAR(512) NULL AFTER receipt_image_url');

    commerce_ensure_column($conn, 'shipments', 'public_tracking_token', 'VARCHAR(64) NULL AFTER pod_image_url');



    foreach (['shipments', 'shipment_events', 'bot_courier_settings'] as $table) {

        if (!schema_table_exists($table)) {

            throw new RuntimeException(

                "Table {$table} was not created. Check MySQL user privileges (CREATE TABLE) in hPanel → Databases."

            );

        }

    }



    $done = true;

}

