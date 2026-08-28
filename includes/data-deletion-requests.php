<?php
/**
 * Data deletion request storage and admin workflow.
 */

function data_deletion_bootstrap(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers.php';
    $done = true;
}

function ensure_data_deletion_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    data_deletion_bootstrap();

    try {
        db_connect()->query(
            'CREATE TABLE IF NOT EXISTS data_deletion_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                name VARCHAR(200) NOT NULL,
                email VARCHAR(255) NOT NULL,
                account_email VARCHAR(255) NULL,
                request_type VARCHAR(20) NOT NULL DEFAULT \'account\',
                reason TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                admin_notes TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                KEY idx_status (status),
                KEY idx_email (email),
                KEY idx_user (user_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('ensure_data_deletion_schema: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * @return array{success: bool, error?: string, id?: int}
 */
function data_deletion_submit(array $data): array
{
    ensure_data_deletion_schema();

    $name = trim((string) ($data['name'] ?? ''));
    $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $accountEmail = filter_var(trim((string) ($data['account_email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $type = ($data['request_type'] ?? '') === 'customer' ? 'customer' : 'account';
    $reason = trim((string) ($data['reason'] ?? ''));
    $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
    if ($userId !== null && $userId <= 0) {
        $userId = null;
    }

    if ($name === '') {
        return ['success' => false, 'error' => 'Please enter your name.'];
    }
    if (!$email) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }
    if ($type === 'account' && !$accountEmail) {
        $accountEmail = $email;
    }

    try {
        $pending = db_fetch(
            'SELECT id FROM data_deletion_requests
             WHERE email = ? AND status IN (\'pending\', \'processing\')
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 1',
            's',
            [$email]
        );
        if ($pending) {
            return ['success' => false, 'error' => 'You already have an open request. We will email you when it is processed.'];
        }
    } catch (Throwable $e) {
        error_log('data_deletion_submit pending check: ' . $e->getMessage());
    }

    $accountEmailStr = $accountEmail ? (string) $accountEmail : '';
    $reasonStr = $reason !== '' ? $reason : '';
    $ipStr = trim((string) ($data['ip_address'] ?? ''));

    try {
        if ($userId !== null) {
            $id = db_insert(
                'INSERT INTO data_deletion_requests (user_id, name, email, account_email, request_type, reason, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'isssss',
                [$userId, $name, $email, $accountEmailStr, $type, $reasonStr, $ipStr]
            );
        } else {
            $id = db_insert(
                'INSERT INTO data_deletion_requests (name, email, account_email, request_type, reason, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)',
                'ssssss',
                [$name, $email, $accountEmailStr, $type, $reasonStr, $ipStr]
            );
        }
    } catch (Throwable $e) {
        error_log('data_deletion_submit insert: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Could not save your request. Please email ' . (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'support') . '.'];
    }

    return ['success' => true, 'id' => $id];
}

/** @return list<array<string, mixed>> */
function data_deletion_requests_list(?string $status = null, int $limit = 100): array
{
    ensure_data_deletion_schema();
    $limit = max(1, min(500, $limit));

    try {
        if ($status !== null && in_array($status, ['pending', 'processing', 'completed', 'rejected'], true)) {
            return db_fetch_all(
                'SELECT r.*, u.company_name, u.name AS user_name
                 FROM data_deletion_requests r
                 LEFT JOIN users u ON u.id = r.user_id
                 WHERE r.status = ?
                 ORDER BY r.created_at DESC
                 LIMIT ' . $limit,
                's',
                [$status]
            );
        }

        return db_fetch_all(
            'SELECT r.*, u.company_name, u.name AS user_name
             FROM data_deletion_requests r
             LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.created_at DESC
             LIMIT ' . $limit,
            '',
            []
        );
    } catch (Throwable $e) {
        error_log('data_deletion_requests_list: ' . $e->getMessage());
        return [];
    }
}

function data_deletion_update_status(int $id, string $status, string $adminNotes = ''): bool
{
    ensure_data_deletion_schema();

    if (!in_array($status, ['pending', 'processing', 'completed', 'rejected'], true)) {
        return false;
    }

    $completedAt = in_array($status, ['completed', 'rejected'], true) ? date('Y-m-d H:i:s') : null;
    $notes = trim($adminNotes);

    try {
        db_execute(
            'UPDATE data_deletion_requests SET status = ?, admin_notes = ?, completed_at = COALESCE(?, completed_at) WHERE id = ?',
            'sssi',
            [$status, $notes, $completedAt ?? '', $id]
        );
        return true;
    } catch (Throwable $e) {
        error_log('data_deletion_update_status: ' . $e->getMessage());
        return false;
    }
}

function data_deletion_pending_count(): int
{
    try {
        ensure_data_deletion_schema();
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM data_deletion_requests WHERE status IN (\'pending\', \'processing\')',
            '',
            []
        );
        return (int) ($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function data_deletion_notify_admin(int $requestId): void
{
    data_deletion_bootstrap();
    require_once __DIR__ . '/mailer.php';

    $row = db_fetch('SELECT * FROM data_deletion_requests WHERE id = ?', 'i', [$requestId]);
    if (!$row) {
        return;
    }

    $typeLabel = ($row['request_type'] ?? '') === 'customer' ? 'End customer (WhatsApp lead)' : 'Platform account';
    $body = '<h2>Data deletion request #' . (int) $requestId . '</h2>'
        . '<p><strong>Type:</strong> ' . htmlspecialchars($typeLabel) . '</p>'
        . '<p><strong>Name:</strong> ' . htmlspecialchars($row['name']) . '</p>'
        . '<p><strong>Contact email:</strong> ' . htmlspecialchars($row['email']) . '</p>'
        . '<p><strong>Account email:</strong> ' . htmlspecialchars($row['account_email'] ?? '—') . '</p>'
        . '<p><strong>Reason:</strong><br/>' . nl2br(htmlspecialchars($row['reason'] ?? '—')) . '</p>'
        . '<p><a href="' . htmlspecialchars(rtrim(APP_URL, '/') . '/admin/data-deletion-requests?id=' . $requestId) . '">Review in admin panel</a></p>';

    send_email(ADMIN_EMAIL, 'Data deletion request #' . $requestId, $body);
}
