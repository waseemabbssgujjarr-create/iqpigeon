<?php
/**
 * WhatsApp webhook — IQ Pigeon (Clinicos bridge compatible).
 *
 * GET: Meta hub.verify handshake.
 * POST: ingest + mark-read, ACK HTTP 200 immediately, then process asynchronously.
 * Never hold Meta's request for OpenAI, web search, or the 7-second quiet wait.
 */

require_once __DIR__ . '/../config.php';

if (!function_exists('meta_webhook_verify_ok')) {
    $domainFile = __DIR__ . '/../includes/domain.php';
    if (is_readable($domainFile)) {
        require_once $domainFile;
    }
}

if (!function_exists('meta_webhook_verify_ok')) {
    function meta_webhook_verify_ok(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        foreach (['WEBHOOK_VERIFY_TOKEN', 'WHATSAPP_VERIFY_TOKEN'] as $name) {
            if (!defined($name)) {
                continue;
            }
            $expected = trim((string) constant($name));
            if ($expected !== '' && hash_equals($expected, $token)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * HTML output sanitizer in config.php strips 32+ alphanumeric Meta hub.challenge
 * strings and can hold "OK" in a buffer until shutdown. Webhook responses must
 * be raw bytes, flushed immediately.
 */
function wa_webhook_release_output_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && meta_webhook_verify_ok($token)) {
        wa_webhook_release_output_buffers();
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . (string) strlen($challenge));
        echo $challenge;
        exit;
    }

    if ($mode === '' && $token === '' && $challenge === '') {
        wa_webhook_release_output_buffers();
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
        echo 'WhatsApp webhook is online. Meta verifies with hub.mode=subscribe — opening this URL in a browser is normal.';
        exit;
    }

    wa_webhook_release_output_buffers();
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/whatsapp-webhook-log.php';
require_once __DIR__ . '/../includes/whatsapp-inbound.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/whatsapp-reply-debug-log.php';

function wa_webhook_log(string $message, array $context = []): void
{
    if (!isset($context['elapsed_ms']) && !empty($GLOBALS['wa_webhook_t0'])) {
        $context['elapsed_ms'] = (int) round((microtime(true) - (float) $GLOBALS['wa_webhook_t0']) * 1000);
    }
    whatsapp_webhook_log_event($message, $context);
    if (function_exists('whatsapp_reply_debug_log')) {
        whatsapp_reply_debug_log('webhook:' . $message, $context);
    }
}

function wa_webhook_ack_meta(): void
{
    if (!empty($GLOBALS['wa_webhook_acked'])) {
        return;
    }
    $GLOBALS['wa_webhook_acked'] = true;
    wa_webhook_release_output_buffers();
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Connection: close');
        header('Content-Length: 2');
    }
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        @flush();
    }
    try {
        wa_webhook_log('ACK 200 sent', ['stage' => 'meta_ack']);
    } catch (Throwable $ignored) {
    }
}

$eventId = bin2hex(random_bytes(8));
$GLOBALS['wa_webhook_event_id'] = $eventId;
$GLOBALS['wa_webhook_t0'] = microtime(true);
$GLOBALS['wa_webhook_acked'] = false;
ignore_user_abort(true);
@set_time_limit(90);
register_shutdown_function(static function (): void {
    if (empty($GLOBALS['wa_webhook_acked']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        wa_webhook_ack_meta();
    }
});

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
$isDiagnosePost = isset($_SERVER['HTTP_X_AILEADS_DIAGNOSE']) || isset($_SERVER['HTTP_X_AILEADS-DIAGNOSE']);

wa_webhook_log($isDiagnosePost ? 'POST received (diagnose self-test)' : 'POST received (meta)', [
    'bytes'      => strlen($payload),
    'has_sig'    => $signature !== null && $signature !== '',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80),
    'uri'        => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 120),
]);

$sigOk = $isDiagnosePost || verify_meta_signature($payload, $signature);
if (!$sigOk) {
    $secret = whatsapp_meta_app_secret();
    $hint = ($secret === '' || $secret === 'your_app_secret')
        ? 'Meta App Secret is missing — set it in Admin → Integrations'
        : 'Meta App Secret does not match Meta App → Settings → Basic';
    wa_webhook_log('REJECTED invalid signature — ACK 200 without processing', ['hint' => $hint]);
    wa_webhook_ack_meta();
    exit;
}

$turnEngineOk = false;
try {
    require_once __DIR__ . '/../includes/conversation-turn-engine.php';
    $turnEngineOk = function_exists('turn_engine_ingest');
} catch (Throwable $e) {
    wa_webhook_log('Turn engine include failed', ['error' => $e->getMessage()]);
}

$data = json_decode($payload, true);
if (!$data || empty($data['entry'])) {
    wa_webhook_log('Empty or non-message payload', ['stage' => 'parse']);
    wa_webhook_ack_meta();
    exit;
}

/** @var array<int, array{bot: array<string, mixed>, phone_id: string, token: string, lead_ids: array<int, int>}> $jobs */
$jobs = [];
/** @var list<array{phone_id: string, token: string, wa_id: string, from: string}> $pendingReads */
$pendingReads = [];

foreach ($data['entry'] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $field = (string) ($change['field'] ?? '');
        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        $phoneId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $messages = $value['messages'] ?? [];
        $statuses = $value['statuses'] ?? [];

        if (in_array($field, ['history', 'smb_app_state_sync', 'smb_message_echoes', 'account_update'], true)) {
            wa_webhook_log('Coexistence webhook received', ['field' => $field, 'phone_id' => $phoneId]);
            continue;
        }

        if ($messages === [] && $statuses !== []) {
            wa_webhook_log('Status-only webhook (delivery/read receipts)', [
                'field'    => $field,
                'phone_id' => $phoneId,
                'count'    => is_array($statuses) ? count($statuses) : 0,
            ]);
            continue;
        }

        if ($phoneId === '' || !is_array($messages) || $messages === []) {
            wa_webhook_log('No inbound customer messages in change', [
                'field'    => $field !== '' ? $field : 'unknown',
                'phone_id' => $phoneId,
            ]);
            continue;
        }

        wa_webhook_log('Inbound message for phone_id=' . $phoneId, [
            'count' => count($messages),
            'field' => $field !== '' ? $field : 'messages',
        ]);

        $bot = bot_resolve_by_whatsapp_phone_id($phoneId);
        if (!$bot) {
            wa_webhook_log('No active bot matched phone_id=' . $phoneId, ['stage' => 'bot_resolve']);
            continue;
        }

        $token = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));
        if ($token === false || $token === '') {
            wa_webhook_log('Could not decrypt WhatsApp token for bot #' . (int) $bot['id'], [
                'stage' => 'token',
            ]);
            whatsapp_mark_token_failure((int) $bot['id'], 'Could not read saved token — reconnect in Bot Setup.');
            continue;
        }

        try {
            bot_whatsapp_heal_connection((int) $bot['id']);
        } catch (Throwable $e) {
            wa_webhook_log('Heal connection failed', ['error' => $e->getMessage()]);
        }

        $contacts = $value['contacts'] ?? [];
        $contactNames = [];
        foreach (is_array($contacts) ? $contacts : [] as $contact) {
            $waId = (string) ($contact['wa_id'] ?? '');
            if ($waId !== '') {
                $contactNames[$waId] = (string) ($contact['profile']['name'] ?? 'WhatsApp Lead');
            }
        }

        $jobKey = $phoneId . ':' . (int) $bot['id'];
        if (!isset($jobs[$jobKey])) {
            $jobs[$jobKey] = [
                'bot'      => $bot,
                'phone_id' => $phoneId,
                'token'    => $token,
                'lead_ids' => [],
            ];
        }

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $senderPhone = (string) ($msg['from'] ?? '');
            $waId = trim((string) ($msg['id'] ?? ''));
            $msgType = (string) ($msg['type'] ?? '');
            if ($senderPhone === '') {
                wa_webhook_log('Skipped message with empty sender', ['wa_id' => $waId, 'type' => $msgType]);
                continue;
            }

            if (!in_array($msgType, ['text', 'audio', 'image', 'video', 'document', 'sticker', 'location', 'contacts', 'interactive', 'order'], true)) {
                wa_webhook_log('Skipped unsupported message type=' . ($msgType !== '' ? $msgType : 'unknown'), [
                    'wa_id' => $waId,
                ]);
                continue;
            }

            $contactName = $contactNames[$senderPhone] ?? 'WhatsApp Lead';
            if ($waId !== '') {
                $pendingReads[] = [
                    'phone_id' => $phoneId,
                    'token'    => $token,
                    'wa_id'    => $waId,
                    'from'     => $senderPhone,
                ];
            } else {
                wa_webhook_log('Mark read skipped — empty message id', [
                    'from' => $senderPhone,
                    'type' => $msgType,
                ]);
            }

            if (!$turnEngineOk) {
                wa_webhook_log('Turn engine unavailable — cannot buffer inbound', [
                    'from' => $senderPhone,
                    'type' => $msgType,
                    'wa_id' => $waId,
                ]);
                continue;
            }

            try {
                $result = turn_engine_ingest($bot, $phoneId, $token, $senderPhone, $msg, $contactName);
            } catch (Throwable $e) {
                wa_webhook_log('Turn ingest exception', [
                    'error' => $e->getMessage(),
                    'from'  => $senderPhone,
                    'wa_id' => $waId,
                ]);
                continue;
            }

            if (!empty($result['duplicate'])) {
                $dupLead = (int) ($result['lead_id'] ?? 0);
                $stillOpen = $dupLead > 0 && function_exists('turn_engine_customer_awaiting_reply')
                    && turn_engine_customer_awaiting_reply($dupLead)
                    && !(function_exists('turn_engine_lead_just_got_reply') && turn_engine_lead_just_got_reply($dupLead, 20));
                if ($stillOpen) {
                    $jobs[$jobKey]['lead_ids'][$dupLead] = $dupLead;
                    wa_webhook_log('DUPLICATE_REQUEUE_SEND', [
                        'wa_id'   => $waId,
                        'lead_id' => $dupLead,
                    ]);
                } else {
                    wa_webhook_log('DUPLICATE_EVENT_IGNORED', ['wa_id' => $waId]);
                }
                continue;
            }

            if (empty($result['success'])) {
                wa_webhook_log('Turn ingest failed', [
                    'error' => $result['error'] ?? 'unknown',
                    'from'  => $senderPhone,
                    'type'  => $msgType,
                    'wa_id' => $waId,
                ]);
                continue;
            }

            if (!empty($result['lead_id'])) {
                $jobs[$jobKey]['lead_ids'][(int) $result['lead_id']] = (int) $result['lead_id'];
            }

            wa_webhook_log('Message buffered for turn', [
                'turn_id'  => $result['turn_id'] ?? null,
                'lead_id'  => $result['lead_id'] ?? null,
                'type'     => $msgType,
                'wa_id'    => $waId,
                'from'     => $senderPhone,
            ]);
        }
    }
}

$allLeadIds = [];
foreach ($jobs as $job) {
    foreach (array_values($job['lead_ids']) as $leadId) {
        $allLeadIds[] = (int) $leadId;
    }
}
$allLeadIds = array_values(array_unique(array_filter($allLeadIds)));

$workerDispatched = false;
if ($allLeadIds !== [] && function_exists('turn_engine_dispatch_worker')) {
    try {
        $workerDispatched = turn_engine_dispatch_worker($allLeadIds, false);
        wa_webhook_log('Async worker detached (pre-ACK)', [
            'leads'      => $allLeadIds,
            'dispatched' => $workerDispatched,
            'stage'      => 'pre_ack_detached',
        ]);
    } catch (Throwable $e) {
        wa_webhook_log('Async worker pre-ACK dispatch failed', ['error' => $e->getMessage(), 'leads' => $allLeadIds]);
    }
}

wa_webhook_ack_meta();

foreach ($pendingReads as $read) {
    try {
        $readOk = whatsapp_mark_message_read($read['phone_id'], $read['token'], $read['wa_id']);
        wa_webhook_log('Mark read attempted', [
            'wa_id' => $read['wa_id'],
            'ok'    => $readOk,
            'from'  => $read['from'],
            'stage' => 'after_ack',
        ]);
    } catch (Throwable $e) {
        wa_webhook_log('Mark read exception', [
            'error' => $e->getMessage(),
            'wa_id' => $read['wa_id'],
        ]);
    }
}

if ($allLeadIds !== [] && function_exists('turn_engine_dispatch_worker')) {
    try {
        $dispatched = turn_engine_dispatch_worker($allLeadIds, true);
        wa_webhook_log('Async worker dispatched', [
            'leads'      => $allLeadIds,
            'dispatched' => $dispatched,
            'pre_ack'    => $workerDispatched,
            'stage'      => 'after_ack',
        ]);
    } catch (Throwable $e) {
        wa_webhook_log('Async worker dispatch failed', ['error' => $e->getMessage(), 'leads' => $allLeadIds]);
    }
}

foreach ($jobs as $job) {
    $leadIds = array_values($job['lead_ids']);
    if ($leadIds === []) {
        continue;
    }
    wa_webhook_log('Post-ACK compose (same process backup)', ['leads' => $leadIds]);
    try {
        $sentNow = turn_engine_send_leads_now($leadIds, $job['bot'], $job['phone_id'], $job['token']);
        wa_webhook_log('Post-ACK compose result', is_array($sentNow) ? $sentNow : ['raw' => $sentNow]);
    } catch (Throwable $e) {
        wa_webhook_log('Post-ACK compose failed', ['error' => $e->getMessage(), 'leads' => $leadIds]);
        try {
            foreach ($leadIds as $leadId) {
                turn_engine_finalize_webhook_leads([$leadId], $job['bot'], $job['phone_id'], $job['token']);
            }
        } catch (Throwable $ignored) {
        }
    }
}

