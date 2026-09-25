<?php

/**
 * External CRM test console — isolated from IQPigeon product navigation.
 * Transport: direct_meta | iqpigeon_api (see mycrm.local.php).
 */
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/includes/mycrm/config.php';
require_once __DIR__ . '/includes/mycrm/storage.php';
require_once __DIR__ . '/includes/mycrm/iqpigeon-api-client.php';
require_once __DIR__ . '/includes/mycrm/send.php';

function mycrm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$flash = $_SESSION['mycrm_flash'] ?? null;
unset($_SESSION['mycrm_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/mycrm/iqpigeon-api-client.php';
    require_once __DIR__ . '/includes/mycrm/send.php';

    $action = (string) ($_POST['action'] ?? '');
    if (($action === 'test_api' || $action === 'refresh_connection') && mycrm_is_iqpigeon_api_mode()) {
        $test = mycrm_iqpigeon_test_connection();
        $_SESSION['mycrm_flash'] = $test['ok']
            ? 'API connected. Partner: ' . ($test['partner']['status'] ?? 'unknown')
                . ' · Connection: ' . (is_array($test['connection'] ?? null) ? ($test['connection']['status'] ?? 'n/a') : 'not found for configured ID')
            : 'API error: ' . ($test['error'] ?? 'failed') . ' (request_id: ' . ($test['request_id'] ?? 'n/a') . ')';
    }
    if ($action === 'send' && trim((string) ($_POST['body'] ?? '')) !== '') {
        $to = trim((string) ($_POST['to'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $idempotency = trim((string) ($_POST['idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = 'mycrm-' . bin2hex(random_bytes(8));
        }
        $send = mycrm_send_text_message($to, $body, $idempotency);
        if ($send['ok'] ?? false) {
            $_SESSION['mycrm_flash'] = mycrm_is_iqpigeon_api_mode()
                ? mycrm_iqpigeon_format_send_flash($send, 'Text message')
                : 'Sent via Direct Meta';
        } else {
            $_SESSION['mycrm_flash'] = 'Send failed: ' . ($send['error'] ?? 'unknown')
                . (isset($send['error_code']) ? ' [' . $send['error_code'] . ']' : '')
                . (isset($send['request_id']) ? ' request_id=' . $send['request_id'] : '');
        }
    }
    if ($action === 'send_template') {
        $to = trim((string) ($_POST['template_to'] ?? ''));
        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        $languageCode = trim((string) ($_POST['template_language'] ?? ''));
        $componentsRaw = trim((string) ($_POST['template_components_json'] ?? ''));
        $idempotency = trim((string) ($_POST['template_idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = 'mycrm-tpl-' . bin2hex(random_bytes(8));
        }

        if ($to === '' || $templateName === '' || $languageCode === '') {
            $_SESSION['mycrm_flash'] = 'Template send failed: recipient, template name, and language code are required.';
        } elseif (!mycrm_is_iqpigeon_api_mode()) {
            $_SESSION['mycrm_flash'] = 'Template send failed: requires IQPigeon API transport (iqpigeon_api).';
        } else {
            $template = [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ];
            if ($componentsRaw !== '') {
                try {
                    $decoded = json_decode($componentsRaw, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $_SESSION['mycrm_flash'] = 'Template send failed: components JSON is invalid.';
                    header('Location: /mycrm', true, 303);
                    exit;
                }
                if (!is_array($decoded)) {
                    $_SESSION['mycrm_flash'] = 'Template send failed: components JSON must be a JSON array.';
                    header('Location: /mycrm', true, 303);
                    exit;
                }
                $template['components'] = $decoded;
            }

            if (!function_exists('mycrm_send_template_message')) {
                $_SESSION['mycrm_flash'] = 'Template send failed: send helpers are not loaded (deploy includes/mycrm/send.php).';
            } else {
                $send = mycrm_send_template_message($to, $template, $idempotency);
            }
            if (!empty($send) && ($send['ok'] ?? false)) {
                $_SESSION['mycrm_flash'] = mycrm_iqpigeon_format_send_flash($send, 'Template');
            } elseif (!empty($send)) {
                $_SESSION['mycrm_flash'] = 'Template send failed: ' . ($send['error'] ?? 'unknown')
                    . (isset($send['error_code']) ? ' [' . $send['error_code'] . ']' : '')
                    . (isset($send['request_id']) ? ' request_id=' . $send['request_id'] : '');
            }
        }
    }
    header('Location: /mycrm', true, 303);
    exit;
}

$transport = mycrm_transport();
$state = mycrm_get_state();
$messages = array_reverse(mycrm_load_messages());
$webhookUrl = rtrim(mycrm_env('APP_URL', 'https://iqpigeon.com'), '/') . '/mycrm-iqpigeon-webhook';
$apiConfigured = mycrm_is_iqpigeon_api_mode()
    && mycrm_iqpigeon_api_key() !== '' && mycrm_iqpigeon_connection_id() !== '';
$apiConnected = ($state['last_api_test_ok'] ?? false) === true;
$webhookConfigured = mycrm_iqpigeon_webhook_secret() !== '';
$connPrefix = mycrm_iqpigeon_connection_id() !== '' ? substr(mycrm_iqpigeon_connection_id(), 0, 12) . '…' : 'not set';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MyCRM — IQPigeon integration test</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 24px; }
        .wrap { max-width: 960px; margin: 0 auto; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        h1 { font-size: 1.5rem; margin: 0 0 8px; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-top: 10px; }
        input, textarea { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 10px; border-radius: 8px; border: 1px solid #475569; background: #0f172a; color: #fff; }
        button { margin-top: 12px; background: #7c3aed; color: #fff; border: 0; padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .muted { color: #94a3b8; font-size: 13px; }
        .flash { background: #14532d; border: 1px solid #22c55e; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .msg { border-top: 1px solid #334155; padding: 10px 0; font-size: 14px; }
        code { background: #0f172a; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>MyCRM test console</h1>
    <p class="muted">Simulates an external CRM integrating with IQPigeon. Not part of the customer dashboard.</p>

    <?php if ($flash): ?>
        <div class="flash"><?= mycrm_h((string) $flash) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin:0 0 12px;font-size:1.1rem">IQPigeon WhatsApp API</h2>
        <p><strong>API Transport:</strong> <?= $transport === 'iqpigeon_api' ? 'IQPigeon API' : 'Direct Meta' ?></p>
        <?php if ($transport === 'iqpigeon_api'): ?>
            <p><strong>Status:</strong> <?= $apiConnected ? 'Connected' : ($apiConfigured ? 'Not verified — run Test API Connection' : 'Missing server config') ?></p>
            <p class="muted">API: <?= mycrm_h(mycrm_iqpigeon_base_url()) ?></p>
            <p class="muted">Connection: <code><?= mycrm_h($connPrefix) ?></code></p>
            <p class="muted">WhatsApp: <?= mycrm_h((string) ($state['display_phone'] ?? '—')) ?></p>
            <p class="muted">Subscription / partner: <?= mycrm_h((string) ($state['partner_status'] ?? '—')) ?></p>
            <p class="muted">Webhook (MyCRM receiver): <?= $webhookConfigured ? 'Secret configured' : 'Not configured (IQPIGEON_WEBHOOK_SECRET)' ?></p>
            <p class="muted">Messages stored: <?= count($messages) ?></p>
            <form method="post" style="display:inline-block;margin-right:8px">
                <input type="hidden" name="action" value="test_api">
                <button type="submit">Test API Connection</button>
            </form>
            <form method="post" style="display:inline-block;margin-right:8px">
                <input type="hidden" name="action" value="refresh_connection">
                <button type="submit">Refresh Connection</button>
            </form>
            <a href="https://whatsappapi.iqpigeon.com/docs" target="_blank" rel="noopener" style="color:#a78bfa;font-size:14px">View API Docs</a>
            <p class="muted" style="margin-top:12px">Register this webhook URL on the platform (POST events only from IQPigeon, not Meta):<br><code><?= mycrm_h($webhookUrl) ?></code></p>
        <?php else: ?>
            <p class="muted">Direct Meta mode uses MYCRM_META_* credentials server-side only. No IQPigeon API calls.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="margin:0 0 8px;font-size:1.1rem">IQPigeon API Test Console</h2>
        <p class="muted">POST /api/v1/messages — explicit send only (never on page load).</p>
        <form method="post">
            <input type="hidden" name="action" value="send">
            <label>Recipient (E.164)</label>
            <input name="to" required placeholder="+15551234567" value="<?= mycrm_h((string) ($_GET['to'] ?? '')) ?>">
            <label>Message</label>
            <textarea name="body" rows="3" required placeholder="Hello from MyCRM through IQPigeon API"></textarea>
            <label>Idempotency-Key (optional — reuse on retry)</label>
            <input name="idempotency_key" placeholder="mycrm-msg-001">
            <button type="submit">Send via <?= $transport === 'iqpigeon_api' ? 'IQPigeon API' : 'Direct Meta' ?></button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 8px;font-size:1.1rem">Send template (WhatsApp API)</h2>
        <p class="muted">POST /api/v1/messages with <code>type: template</code> — for business-initiated messages outside the 24-hour session window.</p>
        <?php if ($transport !== 'iqpigeon_api'): ?>
            <p class="muted">Switch <code>MYCRM_TRANSPORT</code> to <code>iqpigeon_api</code> to send templates through the platform.</p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="send_template">
            <label>Recipient (E.164)</label>
            <input name="template_to" required placeholder="+923004522663" <?= $transport !== 'iqpigeon_api' ? 'disabled' : '' ?>>
            <label>Template name (approved on connected WABA)</label>
            <input name="template_name" required placeholder="hello_world (Meta API name, not display title)" <?= $transport !== 'iqpigeon_api' ? 'disabled' : '' ?>>
            <label>Language code</label>
            <input name="template_language" required placeholder="en_US" value="en_US" <?= $transport !== 'iqpigeon_api' ? 'disabled' : '' ?>>
            <label>Components JSON (optional — JSON array, e.g. <code>[]</code> or variable parameters)</label>
            <textarea name="template_components_json" rows="3" placeholder="[]" <?= $transport !== 'iqpigeon_api' ? 'disabled' : '' ?>></textarea>
            <label>Idempotency-Key (optional)</label>
            <input name="template_idempotency_key" placeholder="mycrm-tpl-001" <?= $transport !== 'iqpigeon_api' ? 'disabled' : '' ?>>
            <button type="submit" <?= $transport !== 'iqpigeon_api' ? 'disabled' : '' ?>>Send Template</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 8px;font-size:1.1rem">Conversation / events</h2>
        <?php if ($messages === []): ?>
            <p class="muted">No messages yet.</p>
        <?php else: ?>
            <?php foreach (array_slice($messages, 0, 40) as $m): ?>
                <div class="msg">
                    <span class="muted"><?= mycrm_h((string) ($m['received_at'] ?? $m['sent_at'] ?? '')) ?></span>
                    · <?= mycrm_h((string) ($m['direction'] ?? '')) ?>
                    · <?= mycrm_h((string) ($m['transport'] ?? '')) ?>
                    <?php if (!empty($m['event_type'])): ?> · <?= mycrm_h((string) $m['event_type']) ?><?php endif; ?>
                    <?php if (!empty($m['status'])): ?> · status <?= mycrm_h((string) $m['status']) ?><?php endif; ?>
                    <?php if (($m['direction'] ?? '') === 'outbound' && ($m['transport'] ?? '') === 'iqpigeon_api'): ?> · <em>Sent via IQPigeon API</em><?php endif; ?>
                    <br><?= mycrm_h((string) ($m['body'] ?? '')) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p class="muted">Last API request: <?= mycrm_h((string) ($state['last_api_request_at'] ?? '—')) ?> · Last webhook: <?= mycrm_h((string) ($state['last_webhook_at'] ?? '—')) ?></p>
</div>
</body>
</html>
