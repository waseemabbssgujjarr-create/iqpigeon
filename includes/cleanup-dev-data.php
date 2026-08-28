<?php
/**
 * Dev cleanup helpers — delete bots, leads, and optional test accounts.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Preview counts before cleanup.
 *
 * @return array<string, mixed>
 */
function dev_cleanup_preview(): array
{
    $bots = db_fetch_all(
        'SELECT b.id, b.name, b.created_at, u.email AS client_email, u.company_name,
                (SELECT COUNT(*) FROM leads l WHERE l.bot_id = b.id) AS lead_count
         FROM bots b
         JOIN users u ON u.id = b.user_id
         ORDER BY b.id ASC',
        '',
        []
    );

    $leadTotal = db_fetch('SELECT COUNT(*) AS cnt FROM leads', '', []);
    $convoTotal = db_fetch('SELECT COUNT(*) AS cnt FROM conversations', '', []);
    $demoBotId = get_setting('demo_bot_id', '');

    $testEmails = [];
    if (defined('TEST_CLIENT_EMAIL') && TEST_CLIENT_EMAIL !== '') {
        $testEmails[] = TEST_CLIENT_EMAIL;
    }

    $testClients = [];
    if ($testEmails) {
        $placeholders = implode(',', array_fill(0, count($testEmails), '?'));
        $types = str_repeat('s', count($testEmails));
        $testClients = db_fetch_all(
            "SELECT id, email, name, company_name FROM users WHERE role = 'client' AND email IN ({$placeholders})",
            $types,
            $testEmails
        );
    }

    return [
        'bots'           => $bots,
        'bot_count'      => count($bots),
        'lead_count'     => (int) ($leadTotal['cnt'] ?? 0),
        'convo_count'    => (int) ($convoTotal['cnt'] ?? 0),
        'demo_bot_id'    => $demoBotId,
        'test_clients'   => $testClients,
        'client_count'   => (int) (db_fetch('SELECT COUNT(*) AS cnt FROM users WHERE role = \'client\'', '', [])['cnt'] ?? 0),
    ];
}

/**
 * Delete all bots (leads + conversations cascade) and reset demo bot setting.
 *
 * @return array{success: bool, messages: string[], errors: string[]}
 */
function dev_cleanup_all_bots(): array
{
    $messages = [];
    $errors = [];

    try {
        $conn = db_connect();
        $botCount = (int) (db_fetch('SELECT COUNT(*) AS cnt FROM bots', '', [])['cnt'] ?? 0);
        $leadCount = (int) (db_fetch('SELECT COUNT(*) AS cnt FROM leads', '', [])['cnt'] ?? 0);

        $conn->query('DELETE FROM bots');

        db_execute('DELETE FROM settings WHERE key_name = \'demo_bot_id\'', '', []);

        $messages[] = "Deleted {$botCount} bot(s) and {$leadCount} lead(s) (conversations removed automatically).";
        $messages[] = 'Cleared public demo bot setting (demo_bot_id).';
        $messages[] = 'Client user accounts were kept — they will see onboarding when they log in again.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return [
        'success'  => empty($errors),
        'messages' => $messages,
        'errors'   => $errors,
    ];
}

/**
 * Delete configured test/demo client accounts and all their data (bots cascade).
 *
 * @return array{success: bool, messages: string[], errors: string[]}
 */
function dev_cleanup_test_clients(): array
{
    $messages = [];
    $errors = [];

    $emails = array_filter(array_unique([
        defined('TEST_CLIENT_EMAIL') ? TEST_CLIENT_EMAIL : '',
        'demo@iqpigeon.com',
    ]));

    if (empty($emails)) {
        return ['success' => false, 'messages' => [], 'errors' => ['No test client emails configured.']];
    }

    try {
        $deleted = 0;
        foreach ($emails as $email) {
            $user = db_fetch('SELECT id FROM users WHERE email = ? AND role = \'client\'', 's', [$email]);
            if ($user) {
                db_execute('DELETE FROM users WHERE id = ?', 'i', [(int) $user['id']]);
                $deleted++;
                $messages[] = 'Deleted test client: ' . $email;
            }
        }

        if ($deleted === 0) {
            $messages[] = 'No test client accounts found to delete.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return [
        'success'  => empty($errors),
        'messages' => $messages,
        'errors'   => $errors,
    ];
}

/**
 * Full dev reset: all bots + optional test clients + WhatsApp logs.
 *
 * @param bool $includeTestClients
 * @param bool $clearWhatsappLogs
 * @return array{success: bool, messages: string[], errors: string[]}
 */
function dev_cleanup_full(bool $includeTestClients = false, bool $clearWhatsappLogs = true): array
{
    $result = dev_cleanup_all_bots();
    $messages = $result['messages'];
    $errors = $result['errors'];

    if ($clearWhatsappLogs) {
        try {
            $conn = db_connect();
            $tables = ['whatsapp_messages_log', 'client_whatsapp_accounts'];
            foreach ($tables as $table) {
                $check = $conn->query("SHOW TABLES LIKE '{$table}'");
                if ($check && $check->num_rows > 0) {
                    $conn->query("DELETE FROM {$table}");
                    $messages[] = "Cleared table: {$table}";
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'WhatsApp log cleanup: ' . $e->getMessage();
        }
    }

    if ($includeTestClients) {
        $clientResult = dev_cleanup_test_clients();
        $messages = array_merge($messages, $clientResult['messages']);
        $errors = array_merge($errors, $clientResult['errors']);
    }

    return [
        'success'  => empty($errors),
        'messages' => $messages,
        'errors'   => $errors,
    ];
}
