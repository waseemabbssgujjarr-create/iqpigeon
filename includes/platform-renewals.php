<?php
/**
 * Platform SaaS renewal reminders — notify YOUR clients (bot account trial/subscription).
 * Admin-managed only; not a client-facing product module.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment-schema.php';

function ensure_platform_renewals_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_payment_schema();
    $conn = db_connect();

    $conn->query(
        'CREATE TABLE IF NOT EXISTS platform_renewal_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            offset_days INT NOT NULL,
            email_subject VARCHAR(255) NOT NULL,
            email_body TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS platform_renewal_sends (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            template_id INT UNSIGNED NOT NULL,
            scheduled_for DATE NOT NULL,
            sent_at DATETIME NULL,
            status ENUM(\'pending\',\'sent\',\'skipped\',\'failed\') NOT NULL DEFAULT \'pending\',
            dedup_key VARCHAR(64) NOT NULL,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_platform_renewal_dedup (dedup_key),
            INDEX idx_platform_renewal_user (user_id),
            INDEX idx_platform_renewal_date (scheduled_for, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function platform_renewal_billing_url(): string
{
    return rtrim(APP_URL, '/') . '/client/billing';
}

/**
 * Renewal date for a platform client (trial end or paid subscription expiry).
 */
function platform_user_renewal_datetime(array $user): ?string
{
    $status = (string) ($user['subscription_status'] ?? '');
    if ($status === 'canceled') {
        return null;
    }
    if ($status === 'trialing' && !empty($user['trial_ends_at'])) {
        return (string) $user['trial_ends_at'];
    }
    if (!empty($user['subscription_expires_at'])) {
        return (string) $user['subscription_expires_at'];
    }
    if ($status === 'trialing' && !empty($user['created_at'])) {
        $days = defined('TRIAL_DAYS') ? max(1, (int) TRIAL_DAYS) : 30;
        return date('Y-m-d H:i:s', strtotime((string) $user['created_at'] . ' +' . $days . ' days'));
    }
    return null;
}

function platform_user_renewal_date(array $user): ?string
{
    $dt = platform_user_renewal_datetime($user);
    return $dt ? date('Y-m-d', strtotime($dt)) : null;
}

function platform_user_days_until_renewal(array $user): ?int
{
    $date = platform_user_renewal_date($user);
    if ($date === null) {
        return null;
    }
    return (int) floor((strtotime($date . ' 23:59:59') - time()) / 86400);
}

function platform_renewal_ensure_default_templates(): void
{
    ensure_platform_renewals_schema();
    $count = db_fetch('SELECT COUNT(*) AS c FROM platform_renewal_templates');
    if ((int) ($count['c'] ?? 0) > 0) {
        return;
    }

    $billing = platform_renewal_billing_url();
    $defaults = [
        ['Trial ending in 7 days', -7,
            'Your ' . APP_NAME . ' trial ends in 7 days',
            "Hi {name},\n\nYour free trial for {company} ends on {expires_at}. Renew your {plan} plan to keep your bot and WhatsApp automation running.\n\nRenew here: {billing_url}\n\n— " . APP_NAME],
        ['Trial ending in 3 days', -3,
            'Trial ending soon — ' . APP_NAME,
            "Hi {name},\n\nReminder: your trial ends on {expires_at} ({days_left} days left). Upgrade now so your leads and bot stay active.\n\n{billing_url}\n\n— " . APP_NAME],
        ['Trial ending tomorrow', -1,
            'Tomorrow is your last trial day',
            "Hi {name},\n\nYour {plan} trial ends tomorrow ({expires_at}). Renew today to avoid interruption.\n\n{billing_url}\n\n— " . APP_NAME],
        ['Trial / plan due today', 0,
            'Renew your ' . APP_NAME . ' account today',
            "Hi {name},\n\nYour account renews today ({expires_at}). Please complete payment to continue using your bot.\n\n{billing_url}\n\n— " . APP_NAME],
        ['Overdue — 1 day', 1,
            'Action needed: account expired',
            "Hi {name},\n\nYour {plan} subscription expired yesterday. Renew now to restore access to your bot and inbox.\n\n{billing_url}\n\n— " . APP_NAME],
        ['Overdue — 3 days', 3,
            'Your bot account is still inactive',
            "Hi {name},\n\nYour account has been inactive for 3 days since {expires_at}. Renew when you're ready:\n\n{billing_url}\n\n— " . APP_NAME],
    ];

    foreach ($defaults as [$name, $offset, $subject, $body]) {
        db_insert(
            'INSERT INTO platform_renewal_templates (name, offset_days, email_subject, email_body) VALUES (?, ?, ?, ?)',
            'siss',
            [$name, $offset, $subject, $body]
        );
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function platform_renewal_templates_list(): array
{
    ensure_platform_renewals_schema();
    platform_renewal_ensure_default_templates();
    return db_fetch_all('SELECT * FROM platform_renewal_templates ORDER BY offset_days ASC');
}

function platform_renewal_template_save(array $data, ?int $templateId = null): int
{
    ensure_platform_renewals_schema();
    $name = trim((string) ($data['name'] ?? ''));
    $subject = trim((string) ($data['email_subject'] ?? ''));
    $body = trim((string) ($data['email_body'] ?? ''));
    $offset = (int) ($data['offset_days'] ?? 0);
    $active = !empty($data['is_active']) ? 1 : 0;

    if ($name === '' || $subject === '' || $body === '') {
        throw new InvalidArgumentException('Name, subject, and body are required.');
    }

    if ($templateId) {
        db_execute(
            'UPDATE platform_renewal_templates SET name=?, offset_days=?, email_subject=?, email_body=?, is_active=? WHERE id=?',
            'sissii',
            [$name, $offset, $subject, $body, $active, $templateId]
        );
        return $templateId;
    }

    return db_insert(
        'INSERT INTO platform_renewal_templates (name, offset_days, email_subject, email_body, is_active) VALUES (?, ?, ?, ?, ?)',
        'sissi',
        [$name, $offset, $subject, $body, $active]
    );
}

function platform_renewal_template_delete(int $templateId): void
{
    ensure_platform_renewals_schema();
    db_execute('DELETE FROM platform_renewal_templates WHERE id = ?', 'i', [$templateId]);
}

function platform_renewal_render(string $text, array $ctx): string
{
    $map = [
        '{name}'       => (string) ($ctx['name'] ?? ''),
        '{company}'    => (string) ($ctx['company'] ?? ''),
        '{plan}'       => (string) ($ctx['plan'] ?? ''),
        '{expires_at}' => (string) ($ctx['expires_at'] ?? ''),
        '{days_left}'  => (string) ($ctx['days_left'] ?? ''),
        '{billing_url}'=> (string) ($ctx['billing_url'] ?? platform_renewal_billing_url()),
        '{app}'        => APP_NAME,
    ];
    return str_replace(array_keys($map), array_values($map), $text);
}

/**
 * @return array<int, array<string, mixed>>
 */
function platform_clients_for_renewals(): array
{
    ensure_payment_schema();
    return db_fetch_all(
        'SELECT id, name, email, company_name, subscription_plan, subscription_status,
                trial_ends_at, subscription_expires_at, created_at
         FROM users
         WHERE role = \'client\'
         AND subscription_status IN (\'trialing\', \'active\', \'past_due\')
         ORDER BY COALESCE(subscription_expires_at, trial_ends_at, created_at) ASC'
    );
}

/**
 * Schedule today's reminder emails (deduped).
 *
 * @return array{queued: int, skipped: int}
 */
function platform_renewal_schedule_today(): array
{
    ensure_platform_renewals_schema();
    platform_renewal_ensure_default_templates();

    $stats = ['queued' => 0, 'skipped' => 0];
    $templates = db_fetch_all('SELECT * FROM platform_renewal_templates WHERE is_active = 1');
    if ($templates === []) {
        return $stats;
    }

    $today = date('Y-m-d');
    $clients = platform_clients_for_renewals();

    foreach ($clients as $client) {
        $renewalDate = platform_user_renewal_date($client);
        if ($renewalDate === null) {
            continue;
        }

        foreach ($templates as $tpl) {
            $offset = (int) $tpl['offset_days'];
            $scheduledFor = date('Y-m-d', strtotime($renewalDate . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' days'));
            if ($scheduledFor !== $today) {
                continue;
            }

            $dedup = 'renew:' . $client['id'] . ':' . $tpl['id'] . ':' . $scheduledFor;
            if (db_fetch('SELECT id FROM platform_renewal_sends WHERE dedup_key = ?', 's', [$dedup])) {
                $stats['skipped']++;
                continue;
            }

            db_insert(
                'INSERT INTO platform_renewal_sends (user_id, template_id, scheduled_for, status, dedup_key) VALUES (?, ?, ?, \'pending\', ?)',
                'iiss',
                [(int) $client['id'], (int) $tpl['id'], $scheduledFor, $dedup]
            );
            $stats['queued']++;
        }
    }

    return $stats;
}

/**
 * Send pending renewal emails.
 *
 * @return array{sent: int, failed: int, skipped: int}
 */
function platform_renewal_send_pending(int $batchSize = 50): array
{
    ensure_platform_renewals_schema();
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/email-templates.php';

    $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    $rows = db_fetch_all(
        'SELECT s.*, t.email_subject, t.email_body, t.name AS template_name
         FROM platform_renewal_sends s
         JOIN platform_renewal_templates t ON t.id = s.template_id
         WHERE s.status = \'pending\' AND s.scheduled_for <= CURDATE()
         ORDER BY s.scheduled_for ASC
         LIMIT ' . max(1, min(100, $batchSize))
    );

    foreach ($rows as $row) {
        $sendId = (int) $row['id'];
        $user = db_fetch('SELECT * FROM users WHERE id = ? AND role = \'client\'', 'i', [(int) $row['user_id']]);
        if (!$user || empty($user['email'])) {
            db_execute('UPDATE platform_renewal_sends SET status=\'skipped\', last_error=? WHERE id=?', 'si', ['no_user_or_email', $sendId]);
            $stats['skipped']++;
            continue;
        }

        $renewalDate = platform_user_renewal_date($user);
        $daysLeft = platform_user_days_until_renewal($user);
        $ctx = [
            'name'       => (string) ($user['name'] ?? ''),
            'company'    => (string) ($user['company_name'] ?? $user['name'] ?? ''),
            'plan'       => ucfirst((string) ($user['subscription_plan'] ?? 'starter')),
            'expires_at' => $renewalDate ? date('M j, Y', strtotime($renewalDate)) : '',
            'days_left'  => $daysLeft !== null ? (string) max(0, $daysLeft) : '',
            'billing_url'=> platform_renewal_billing_url(),
        ];

        $subject = platform_renewal_render((string) $row['email_subject'], $ctx);
        $bodyText = platform_renewal_render((string) $row['email_body'], $ctx);
        $bodyHtml = email_template(
            $subject,
            '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#374151;">'
            . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'))
            . '</p>',
            platform_renewal_billing_url(),
            'Renew account',
            'You received this because you have an account on ' . APP_NAME . '.'
        );

        $ok = send_email((string) $user['email'], $subject, $bodyHtml);
        if ($ok) {
            db_execute('UPDATE platform_renewal_sends SET status=\'sent\', sent_at=NOW() WHERE id=?', 'i', [$sendId]);
            $stats['sent']++;
        } else {
            db_execute('UPDATE platform_renewal_sends SET status=\'failed\', last_error=? WHERE id=?', 'si', ['email_send_failed', $sendId]);
            $stats['failed']++;
        }
    }

    return $stats;
}

function platform_renewal_process_all(): array
{
    $schedule = platform_renewal_schedule_today();
    $send = platform_renewal_send_pending(50);
    return [
        'schedule' => $schedule,
        'send'     => $send,
    ];
}

/**
 * Summary stats for admin dashboard.
 *
 * @return array{expiring_7d: int, expiring_3d: int, overdue: int, trialing: int, active: int}
 */
function platform_renewal_admin_stats(): array
{
    $stats = ['expiring_7d' => 0, 'expiring_3d' => 0, 'overdue' => 0, 'trialing' => 0, 'active' => 0];
    foreach (platform_clients_for_renewals() as $client) {
        $st = (string) ($client['subscription_status'] ?? '');
        if ($st === 'trialing') {
            $stats['trialing']++;
        } elseif ($st === 'active') {
            $stats['active']++;
        }
        $days = platform_user_days_until_renewal($client);
        if ($days === null) {
            continue;
        }
        if ($days < 0) {
            $stats['overdue']++;
        } elseif ($days <= 3) {
            $stats['expiring_3d']++;
        } elseif ($days <= 7) {
            $stats['expiring_7d']++;
        }
    }
    return $stats;
}
