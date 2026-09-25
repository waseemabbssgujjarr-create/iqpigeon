<?php

declare(strict_types=1);

/**
 * MyCRM IQPigeon API mode tests (no live HTTP, no WhatsApp sends).
 * Run: php tests/mycrm-iqpigeon-api-test.php
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function mc_assert(bool $ok, string $name): void
{
    global $passed, $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
}

putenv('MYCRM_TRANSPORT=iqpigeon_api');
putenv('IQPIGEON_API_KEY=test_key_never_in_html');
putenv('IQPIGEON_WEBHOOK_SECRET=whsec_test');

require_once $root . '/includes/mycrm/config.php';
require_once $root . '/includes/mycrm/webhook-verify.php';
require_once $root . '/includes/mycrm/direct-meta.php';
require_once $root . '/includes/mycrm/storage.php';
require_once $root . '/includes/mycrm/webhook-events.php';
require_once $root . '/includes/mycrm/iqpigeon-api-client.php';

mc_assert(mycrm_is_iqpigeon_api_mode(), 'iqpigeon_api mode selected');
mc_assert(mycrm_iqpigeon_api_key() === 'test_key_never_in_html', 'API key from env server-side');

$blocked = mycrm_direct_meta_send_text('+1', 'hi');
mc_assert($blocked['ok'] === false, 'direct Meta send blocked in iqpigeon_api mode');

$body = '{"id":"evt_1","type":"messages","data":{}}';
$ts = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $body, 'whsec_test');
mc_assert(mycrm_iqpigeon_verify_webhook($body, $sig, $ts), 'webhook signature valid');
mc_assert(!mycrm_iqpigeon_verify_webhook($body, 'bad', $ts), 'webhook signature rejected');

mc_assert(mycrm_mark_event_processed('evt_dup_test'), 'first event id stored');
mc_assert(!mycrm_mark_event_processed('evt_dup_test'), 'duplicate event id ignored');

$clientSrc = (string) file_get_contents($root . '/includes/mycrm/iqpigeon-api-client.php');
mc_assert(str_contains($clientSrc, 'Idempotency-Key'), 'Idempotency-Key header in API client');
mc_assert(str_contains($clientSrc, 'connection_id'), 'connection_id in API client payload');
mc_assert(str_contains($clientSrc, 'function mycrm_iqpigeon_send_template'), 'template send helper exists');
mc_assert(str_contains($clientSrc, "'type' => 'template'"), 'template type in POST /messages payload');
mc_assert(str_contains($clientSrc, "'template' => \$payloadTemplate"), 'template object forwarded to API');
mc_assert(str_contains($clientSrc, 'function mycrm_iqpigeon_get_message'), 'message GET helper exists');
mc_assert(str_contains($clientSrc, 'function mycrm_iqpigeon_wait_for_send_status'), 'send status poll helper exists');
mc_assert(str_contains($clientSrc, 'function mycrm_iqpigeon_format_send_flash'), 'send flash formatter exists');

mc_assert(mycrm_normalize_to_for_api('+923 004-522663') === '923004522663', 'recipient normalized to digits for API');

$sendSrc = (string) file_get_contents($root . '/includes/mycrm/send.php');
mc_assert(str_contains($sendSrc, 'function mycrm_send_template_message'), 'send.php template wrapper exists');
mc_assert(str_contains($sendSrc, 'function mycrm_send_text_message'), 'text send wrapper unchanged');

$parsed = mycrm_iqpigeon_parse_webhook_events('messages', [
    'messages' => [['from' => '1555', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Hello MyCRM']]],
]);
mc_assert(($parsed[0]['body'] ?? '') === 'Hello MyCRM' && ($parsed[0]['direction'] ?? '') === 'inbound', 'inbound webhook parsed');

$statusRows = mycrm_iqpigeon_parse_webhook_events('messages', [
    'statuses' => [['id' => 'wamid.out', 'status' => 'delivered', 'recipient_id' => '1555']],
]);
mc_assert(($statusRows[0]['status'] ?? '') === 'delivered', 'status webhook parsed');

$ui = (string) file_get_contents($root . '/mycrm.php');
mc_assert(!str_contains($ui, 'test_key_never_in_html'), 'API key not in mycrm.php HTML source');
mc_assert(!preg_match('/curl_init|file_get_contents\s*\(\s*[\'"]https:\/\/graph/', $ui), 'no Graph send on page render');
mc_assert(str_contains($ui, 'action" value="send"'), 'text send form action unchanged');
mc_assert(str_contains($ui, 'action" value="send_template"'), 'template send form present');
mc_assert(str_contains($ui, 'template_components_json'), 'template components JSON field present');

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
