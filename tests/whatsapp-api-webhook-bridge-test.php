<?php

declare(strict_types=1);

/**
 * WhatsApp API webhook bridge — unit tests (no live HTTP).
 */

$root = dirname(__DIR__);
require_once $root . '/includes/whatsapp-api-webhook-bridge.php';
require_once $root . '/includes/whatsapp.php';
require_once $root . '/includes/whatsapp-webhook-log.php';

$failures = 0;

function bridge_assert(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

if (!defined('WHATSAPP_API_WEBHOOK_BRIDGE_ENABLED')) {
    define('WHATSAPP_API_WEBHOOK_BRIDGE_ENABLED', true);
}
if (!defined('WHATSAPP_API_BRIDGE_PHONE_NUMBER_IDS')) {
    define('WHATSAPP_API_BRIDGE_PHONE_NUMBER_IDS', '1259226987283813');
}
if (!defined('WHATSAPP_API_BRIDGE_WABA_IDS')) {
    define('WHATSAPP_API_BRIDGE_WABA_IDS', '1058287677107935');
}
if (!defined('META_APP_SECRET')) {
    define('META_APP_SECRET', 'bridge-test-app-secret-not-real');
}

$statusPayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => '1058287677107935',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'display_phone_number' => '+1 555-959-9302',
                    'phone_number_id' => '1259226987283813',
                ],
                'statuses' => [[
                    'id' => 'wamid.status123',
                    'status' => 'delivered',
                    'timestamp' => '1710000000',
                    'recipient_id' => '923004522663',
                ]],
            ],
        ]],
    ]],
];

$messagePayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => '1058287677107935',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'phone_number_id' => '1259226987283813',
                ],
                'contacts' => [['wa_id' => '923004522663', 'profile' => ['name' => 'Test']]],
                'messages' => [[
                    'from' => '923004522663',
                    'id' => 'wamid.inbound123',
                    'timestamp' => '1710000001',
                    'type' => 'text',
                    'text' => ['body' => 'Hello bridge'],
                ]],
            ],
        ]],
    ]],
];

$unknownPhonePayload = [
    'entry' => [[
        'id' => '999999999',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => '000000000000000'],
                'messages' => [['from' => '1', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'n']]],
            ],
        ]],
    ]],
];

$statusPlan = whatsapp_api_bridge_plan_forward($statusPayload);
bridge_assert($statusPlan !== null && ($statusPlan['forward'] ?? false) === true, 'status event is planned for forward');
bridge_assert(($statusPlan['event_type'] ?? '') === 'status_event', 'status event type classified');
bridge_assert(($statusPlan['phone_number_id'] ?? '') === '1259226987283813', 'status event phone_number_id captured');

$messagePlan = whatsapp_api_bridge_plan_forward($messagePayload);
bridge_assert($messagePlan !== null && ($messagePlan['forward'] ?? false) === true, 'inbound message event is planned for forward');
bridge_assert(($messagePlan['event_type'] ?? '') === 'inbound_message', 'inbound message event type classified');

$unknownPlan = whatsapp_api_bridge_plan_forward($unknownPhonePayload);
bridge_assert($unknownPlan === null, 'unknown phone_number_id is not forwarded');

$GLOBALS['whatsapp_api_bridge_enabled_override'] = false;
$disabledPlan = whatsapp_api_bridge_plan_forward($messagePayload);
bridge_assert($disabledPlan === null, 'bridge disabled skips forward plan');
unset($GLOBALS['whatsapp_api_bridge_enabled_override']);

$rawBody = json_encode($messagePayload, JSON_UNESCAPED_SLASHES);
$signature = 'sha256=' . hash_hmac('sha256', $rawBody, META_APP_SECRET);

$forwardCalls = [];
$GLOBALS['whatsapp_api_bridge_http_post_handler'] = static function (
    string $url,
    string $body,
    string $sig,
    int $timeoutMs,
) use (&$forwardCalls, $rawBody, $signature): array {
    $forwardCalls[] = [
        'url' => $url,
        'body' => $body,
        'signature' => $sig,
        'timeout_ms' => $timeoutMs,
    ];

    return ['http_status' => 200, 'curl_error' => ''];
};

$GLOBALS['wa_webhook_event_id'] = 'bridge-test-event-id';
$logLines = [];
$logHandler = static function (string $event, array $context = []) use (&$logLines): void {
    $logLines[] = ['event' => $event, 'context' => $context];
};
$GLOBALS['whatsapp_webhook_log_test_sink'] = $logHandler;

whatsapp_api_bridge_forward_verified_payload(
    $rawBody,
    $signature,
    'bridge-test-event-id',
    '1259226987283813',
    'inbound_message',
);

bridge_assert(count($forwardCalls) === 1, 'forward invoked once');
bridge_assert($forwardCalls[0]['body'] === $rawBody, 'forward uses original raw JSON body');
bridge_assert($forwardCalls[0]['signature'] === $signature, 'forward preserves X-Hub-Signature-256');
bridge_assert(str_contains($forwardCalls[0]['url'], 'whatsappapi.iqpigeon.com/webhooks/meta'), 'forward targets WhatsApp API webhook');

$GLOBALS['whatsapp_api_bridge_http_post_handler'] = static function (): array {
    return ['http_status' => 503, 'curl_error' => 'timeout'];
};

whatsapp_api_bridge_forward_verified_payload(
    $rawBody,
    $signature,
    'evt-fail-1',
    '1259226987283813',
    'status_event',
);

$recent = whatsapp_webhook_recent_logs(8);
$bridgeLines = array_values(array_filter($recent, static fn (string $line): bool => str_contains($line, 'WhatsApp API bridge')));
$joined = implode("\n", $bridgeLines);
bridge_assert(str_contains($joined, 'WhatsApp API bridge forward failed'), 'forward failure is logged');
bridge_assert(str_contains($joined, 'evt-fail-1'), 'log includes safe event_id');
bridge_assert(str_contains($joined, '1259226987283813'), 'log includes phone_number_id');
bridge_assert(!str_contains($joined, 'Hello bridge'), 'log does not contain message body');
bridge_assert(!str_contains($joined, 'sha256='), 'log does not contain signature header');

$webhookSrc = (string) file_get_contents($root . '/api/whatsapp-webhook.php');
bridge_assert(str_contains($webhookSrc, 'whatsapp-api-webhook-bridge.php'), 'primary webhook loads bridge');
bridge_assert(str_contains($webhookSrc, 'whatsapp_api_bridge_plan_forward'), 'primary webhook plans bridge forward');
bridge_assert(str_contains($webhookSrc, 'whatsapp_api_bridge_forward_verified_payload'), 'primary webhook forwards after ACK');
$ackPos = strpos($webhookSrc, 'wa_webhook_ack_meta();');
$bridgePos = strpos($webhookSrc, 'whatsapp_api_bridge_forward_verified_payload');
bridge_assert($ackPos !== false && $bridgePos !== false && $ackPos < $bridgePos, 'bridge runs after Meta ACK');

$sigRejectPos = strpos($webhookSrc, 'REJECTED invalid signature');
$bridgeIncludePos = strpos($webhookSrc, 'whatsapp_api_bridge_plan_forward');
bridge_assert($sigRejectPos !== false && $bridgeIncludePos !== false && $sigRejectPos < $bridgeIncludePos, 'invalid signature exits before bridge plan');

unset($GLOBALS['whatsapp_api_bridge_http_post_handler']);

exit($failures > 0 ? 1 : 0);
