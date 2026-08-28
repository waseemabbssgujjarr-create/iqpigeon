<?php
/**
 * Create notifications + subscribers tables.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function run_notifications_migration(): array
{
    $messages = [];
    $errors = [];

    try {
        $conn = db_connect();

        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            type        ENUM('lead','system','billing','bot') NOT NULL DEFAULT 'system',
            title       VARCHAR(200) NOT NULL,
            message     TEXT,
            link        VARCHAR(255) DEFAULT NULL,
            is_read     TINYINT(1) NOT NULL DEFAULT 0,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $messages[] = 'Table notifications ready.';

        $conn->query("CREATE TABLE IF NOT EXISTS system_updates (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            title       VARCHAR(200) NOT NULL,
            body        TEXT NOT NULL,
            created_by  INT DEFAULT NULL,
            sent_at     DATETIME DEFAULT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $messages[] = 'Table system_updates ready.';

        $conn->query("CREATE TABLE IF NOT EXISTS update_subscribers (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            email           VARCHAR(150) NOT NULL,
            user_id         INT DEFAULT NULL,
            name            VARCHAR(100) DEFAULT NULL,
            token           VARCHAR(64) NOT NULL,
            status          ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
            source          ENUM('website','settings','register') NOT NULL DEFAULT 'website',
            subscribed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_subscriber_email (email),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $messages[] = 'Table update_subscribers ready.';

        $indexes = [
            'CREATE INDEX idx_notifications_user ON notifications(user_id)',
            'CREATE INDEX idx_notifications_unread ON notifications(user_id, is_read)',
        ];

        foreach ($indexes as $sql) {
            try {
                $conn->query($sql);
            } catch (mysqli_sql_exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        }

        $messages[] = 'Migration complete.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return ['messages' => $messages, 'errors' => $errors];
}
