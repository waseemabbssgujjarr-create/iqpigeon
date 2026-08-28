<?php
/**
 * One-time migration: remember-me tokens + push device tokens.
 * Run once: https://yoursite.com/migrate-remember-push.php
 * Delete this file after success.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

security_require_privileged_key();

header('Content-Type: text/plain; charset=UTF-8');

$messages = [];

try {
    $conn = db_connect();

    $conn->query("CREATE TABLE IF NOT EXISTS user_remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(32) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_selector (selector),
        KEY idx_user (user_id),
        KEY idx_expires (expires_at),
        CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = 'OK: user_remember_tokens';

    $conn->query("CREATE TABLE IF NOT EXISTS device_push_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(512) NOT NULL,
        platform ENUM('android','ios','web') NOT NULL DEFAULT 'android',
        app_version VARCHAR(32) DEFAULT '',
        last_seen_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_token (user_id, token(191)),
        KEY idx_token (token(191)),
        CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = 'OK: device_push_tokens';

    echo implode("\n", $messages) . "\n\nDone. Delete migrate-remember-push.php from the server.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration failed: ' . $e->getMessage();
}
