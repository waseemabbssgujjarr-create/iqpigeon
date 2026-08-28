<?php
/**
 * WhatsApp Embedded Signup table migration (shared runner).
 *
 * @return array{messages: string[], errors: string[]}
 */
function run_whatsapp_embedded_signup_migration(): array
{
    $messages = [];
    $errors = [];

    try {
        $conn = db_connect();

        $conn->query("
            CREATE TABLE IF NOT EXISTS client_whatsapp_accounts (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                client_id             INT NOT NULL,
                waba_id               VARCHAR(50) NOT NULL,
                phone_number_id       VARCHAR(50) NOT NULL,
                business_token        TEXT NOT NULL,
                phone_display_number  VARCHAR(30) DEFAULT NULL,
                connection_status     ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
                connected_at          DATETIME DEFAULT NULL,
                created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_waba_phone (waba_id, phone_number_id),
                KEY idx_client (client_id),
                KEY idx_phone_number (phone_number_id),
                KEY idx_status (connection_status),
                CONSTRAINT fk_cwa_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS whatsapp_messages_log (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                client_id       INT NOT NULL,
                phone_number_id VARCHAR(50) DEFAULT NULL,
                direction       ENUM('inbound','outbound') NOT NULL,
                wa_message_id   VARCHAR(100) DEFAULT NULL,
                from_number     VARCHAR(30) DEFAULT NULL,
                to_number       VARCHAR(30) DEFAULT NULL,
                message_body    TEXT,
                payload         JSON DEFAULT NULL,
                status          VARCHAR(30) DEFAULT NULL,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_client (client_id),
                KEY idx_wa_message (wa_message_id),
                KEY idx_created (created_at),
                CONSTRAINT fk_wml_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $messages[] = 'Tables client_whatsapp_accounts and whatsapp_messages_log created successfully.';
        $messages[] = 'Delete migrate-whatsapp.php from your server now.';
    } catch (Throwable $e) {
        error_log('WhatsApp migration error: ' . $e->getMessage());
        $errors[] = $e->getMessage();
    }

    return ['messages' => $messages, 'errors' => $errors];
}
