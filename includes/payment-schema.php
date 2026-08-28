<?php
/**
 * Subscription payment records + user billing columns.
 */

require_once __DIR__ . '/db.php';

function ensure_payment_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();

    payment_ensure_column($conn, 'users', 'payment_provider', "VARCHAR(16) NULL DEFAULT NULL AFTER stripe_subscription_id");
    payment_ensure_column($conn, 'users', 'subscription_expires_at', 'DATETIME NULL AFTER trial_ends_at');
    payment_ensure_column($conn, 'users', 'billing_currency', "VARCHAR(8) NULL DEFAULT 'USD' AFTER subscription_expires_at");

    $conn->query(
        'CREATE TABLE IF NOT EXISTS subscription_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            plan VARCHAR(32) NOT NULL,
            gateway ENUM(\'paypak\',\'stripe\') NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT \'PKR\',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            order_ref VARCHAR(64) NOT NULL,
            status ENUM(\'pending\',\'paid\',\'failed\',\'cancelled\') NOT NULL DEFAULT \'pending\',
            gateway_txn_id VARCHAR(128) NULL,
            gateway_response TEXT NULL,
            paid_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order_ref (order_ref),
            INDEX idx_sub_pay_user (user_id),
            INDEX idx_sub_pay_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function payment_ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
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
            error_log('payment_ensure_column ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
    }
}

/**
 * @return array<string, mixed>|null
 */
function subscription_payment_by_ref(string $orderRef): ?array
{
    ensure_payment_schema();
    $row = db_fetch('SELECT * FROM subscription_payments WHERE order_ref = ?', 's', [$orderRef]);
    return $row ?: null;
}

function subscription_payment_mark_paid(int $paymentId, ?string $gatewayTxnId = null, ?string $response = null): void
{
    ensure_payment_schema();
    db_execute(
        'UPDATE subscription_payments SET status = \'paid\', gateway_txn_id = ?, gateway_response = ?, paid_at = NOW() WHERE id = ?',
        'ssi',
        [$gatewayTxnId, $response ? mb_substr($response, 0, 65000) : null, $paymentId]
    );
}

function subscription_payment_mark_failed(int $paymentId, ?string $response = null): void
{
    ensure_payment_schema();
    db_execute(
        'UPDATE subscription_payments SET status = \'failed\', gateway_response = ? WHERE id = ?',
        'si',
        [$response ? mb_substr($response, 0, 65000) : null, $paymentId]
    );
}

function subscription_activate_from_payment(int $userId, string $plan, string $gateway, string $currency): void
{
    ensure_payment_schema();
    $plan = normalize_plan_slug($plan);
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    db_execute(
        'UPDATE users SET subscription_plan = ?, subscription_status = \'active\', payment_provider = ?,
         billing_currency = ?, subscription_expires_at = ?, trial_ends_at = NULL WHERE id = ?',
        'ssssi',
        [$plan, $gateway, strtoupper($currency), $expires, $userId]
    );
}
