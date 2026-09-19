<?php
/**
 * Partner API pre-deploy gate — checks that do not need MySQL.
 * Does not print secrets. Does not send Meta messages.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/partner-api.php';
require_once $root . '/includes/agent-core/bootstrap.php';

$passed = 0;
$failed = 0;

function gate_assert(bool $ok, string $name): void
{
    global $passed, $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    $ok ? $passed++ : $failed++;
}

$srcApi = (string) file_get_contents($root . '/includes/partner-api.php');
$srcRouter = (string) file_get_contents($root . '/api/v1/index.php');
$srcWebhook = (string) file_get_contents($root . '/api/whatsapp-webhook.php');
$srcWa = (string) file_get_contents($root . '/includes/whatsapp.php');
$srcAdmin = (string) file_get_contents($root . '/admin/partner-api.php');
$srcDetail = (string) file_get_contents($root . '/admin/partner-api-detail.php');
$srcHt = (string) file_get_contents($root . '/.htaccess');
$srcCron = (string) file_get_contents($root . '/api/cron.php');
$srcOauth = (string) file_get_contents($root . '/includes/whatsapp-oauth.php');
$srcMedia = (string) file_get_contents($root . '/includes/whatsapp-media.php');

echo "=== HMAC / webhook contract ===\n";
$secret = 'whsec_' . str_repeat('ab', 24);
$ts = '1700000000';
$body = '{"event":"message.received","id":"evt_test"}';
$sig = partner_api_sign_webhook($secret, $ts, $body);
gate_assert(hash_equals($sig, hash_hmac('sha256', $ts . '.' . $body, $secret)), 'valid timestamp+body signature matches');
gate_assert(!hash_equals($sig, partner_api_sign_webhook($secret, $ts, $body . ' ')), 'modified body signature rejected');
gate_assert(!hash_equals($sig, partner_api_sign_webhook($secret, '1700000001', $body)), 'modified timestamp signature rejected');
gate_assert(!hash_equals($sig, 'deadbeef'), 'invalid signature rejected');
gate_assert($sig !== '', 'signature not empty');
gate_assert(!preg_match('/max_age|timestamp window|skew/i', $srcApi), 'IQPigeon is HMAC signer only (no inbound timestamp window)');

echo "\n=== Send path (source; no Meta) ===\n";
gate_assert(str_contains($srcApi, 'send_whatsapp_message($phoneId, $token, $to, $text)'), 'text uses existing send_whatsapp_message');
gate_assert(str_contains($srcApi, 'send_whatsapp_image($phoneId, $token, $to, $url, $caption)'), 'image uses existing send_whatsapp_image');
gate_assert(str_contains($srcApi, 'send_whatsapp_template($phoneId, $token, $to, $tpl'), 'template uses existing send_whatsapp_template');
gate_assert(!str_contains($srcApi, "\$body['access_token']") && !str_contains($srcApi, "\$body['token']"), 'CRM-supplied token not used');
gate_assert(str_contains($srcApi, 'partner_api_decrypt_account_token($conn)'), 'token comes from assigned connection');
gate_assert(str_contains($srcApi, 'partner_api_require_connection'), 'send authorizes connection first');
gate_assert(str_contains($srcApi, "partner_api_require_scope(\$client, 'messages.send')"), 'send requires messages.send');
gate_assert(str_contains($srcWa, 'function send_whatsapp_message'), 'sender still defined in includes/whatsapp.php');

echo "\n=== Idempotency race ===\n";
$start = strpos($srcApi, 'function partner_api_dispatch_send');
$sendFn = $start !== false ? substr($srcApi, $start, 2200) : '';
$beginStart = strpos($srcApi, 'function partner_api_idempotency_begin');
$beginEnd = strpos($srcApi, 'function partner_api_idempotency_finish');
$beginFn = ($beginStart !== false && $beginEnd !== false) ? substr($srcApi, $beginStart, $beginEnd - $beginStart) : '';
gate_assert($sendFn !== '' && $beginFn !== '', 'dispatch_send and idempotency_begin source located');
gate_assert(str_contains($sendFn, 'partner_api_idempotency_begin') && str_contains($sendFn, 'partner_api_send_message'), 'idempotency claim exists before send');
gate_assert(str_contains($sendFn, 'DUPLICATE_REQUEST'), 'conflicting idempotency body is rejected');
$beginBeforeSend = strpos($sendFn, 'partner_api_idempotency_begin') < strpos($sendFn, 'partner_api_send_message');
$finishAfterSend = strpos($sendFn, 'partner_api_send_message') < strpos($sendFn, 'partner_api_idempotency_finish');
gate_assert($beginBeforeSend && $finishAfterSend, 'idempotency order is claim then send then complete');
gate_assert(str_contains($srcApi, 'GET_LOCK') && str_contains($beginFn, 'partner_api_idempotency_lock') && str_contains($beginFn, 'partner_api_idempotency_unlock'), 'idempotency uses MySQL GET_LOCK around the claim');
gate_assert(!str_contains($sendFn, 'GET_LOCK'), 'GET_LOCK is released before Meta send');
gate_assert(str_contains($srcApi, "'pending'") && str_contains($srcApi, "'completed'") && str_contains($srcApi, "'failed'"), 'idempotency pending/completed/failed states');

echo "\n=== Rate limit fail-closed ===\n";
gate_assert(!str_contains($srcApi, "if (\$fp === false) {\n        return;"), 'rate limiter must not fail-open when the quota file cannot be opened');
gate_assert(str_contains($srcApi, 'RATE_LIMITER_UNAVAILABLE') && str_contains($srcApi, 'Retry-After'), '429 sets Retry-After and RATE_LIMITER_UNAVAILABLE');
gate_assert(str_contains($srcApi, 'storage/partner-api-ratelimit'), 'limiter keyed by client file not IP');
gate_assert(str_contains($srcApi, 'partner_api_status_claim'), 'status events claimed before enqueue');
gate_assert(!str_contains($srcApi, 'Access-Control-Allow-Origin') && !str_contains($srcRouter, 'Access-Control-Allow-Origin'), 'no CORS *');

echo "\n=== Meta webhook uniqueness / BBD ===\n";
gate_assert(substr_count($srcWebhook, 'bbd_whatsapp_connection_by_phone_id') >= 1, 'BBD lookup still present');
$bbdPos = strpos($srcWebhook, 'if ($bbdHandled)');
$notifyPos = strpos($srcWebhook, 'partner_api_notify_inbound');
gate_assert($bbdPos !== false && $notifyPos !== false && $bbdPos < $notifyPos, 'partner inbound notify is after BBD branch');
gate_assert(str_contains($srcWebhook, 'partner_api_notify_status'), 'status-only hook is additive on existing receiver');
gate_assert(!str_contains($srcHt, 'whatsapp-webhook') || str_contains($srcHt, 'api/whatsapp'), 'no second Meta webhook rewrite added for Partner');
gate_assert(str_contains($srcCron, 'partner_api_deliver_pending'), 'retries drained by existing cron');
gate_assert(!str_contains($srcOauth, 'partner_api'), 'OAuth file not modified by Partner API');

echo "\n=== Admin gate (source) ===\n";
gate_assert(str_contains($srcAdmin, 'require_admin()') && str_contains($srcDetail, 'require_admin()'), 'admin pages call require_admin');
gate_assert(str_contains($srcAdmin, 'verify_csrf') && str_contains($srcDetail, 'verify_csrf'), 'admin POSTs require CSRF');
gate_assert(!str_contains($srcAdmin, 'require_login();') || str_contains($srcAdmin, 'require_admin()'), 'not client-session only');
gate_assert(str_contains($srcAdmin, 'partner_api_once_secret') && str_contains($srcDetail, 'unset($_SESSION[\'partner_api_once_secret\'])'), 'API secret flashed once then cleared');

echo "\n=== Secret leak (source) ===\n";
gate_assert(!preg_match('/error_log\s*\([^;]*(secret|Authorization|business_token)/i', $srcApi), 'error_log does not include secrets');
gate_assert(!str_contains($srcRouter, 'api_key_hash'), 'router does not echo hash');
gate_assert(str_contains($srcApi, 'password_hash') && str_contains($srcApi, 'password_verify'), 'keys hashed');
gate_assert(!preg_match('/iqp_live_[a-f0-9]{20,}/', $srcApi . $srcAdmin . $srcDetail), 'no live API secret hardcoded');
gate_assert(str_contains($srcMedia, 'rawurlencode($mediaId)'), 'media id is URL-encoded to Graph');

gate_assert(str_contains($srcApi, 'function partner_api_should_suppress_iqpigeon_reply'), 'CRM Controlled suppress helper exists');
$notifyStart = strpos($srcApi, 'function partner_api_notify_inbound');
$notifyEnd = strpos($srcApi, 'function partner_api_status_claim');
$notifyFn = ($notifyStart !== false && $notifyEnd !== false) ? substr($srcApi, $notifyStart, $notifyEnd - $notifyStart) : '';
$sendStartMsg = strpos($srcApi, 'function partner_api_send_message');
$sendEndMsg = strpos($srcApi, 'function partner_api_idempotency_get');
$sendMsgFn = ($sendStartMsg !== false && $sendEndMsg !== false) ? substr($srcApi, $sendStartMsg, $sendEndMsg - $sendStartMsg) : '';
gate_assert($notifyFn !== '' && !str_contains($notifyFn, 'partner_api_should_suppress_iqpigeon_reply'), 'inbound notify still fires in CRM mode');
gate_assert($sendMsgFn !== '' && !str_contains($sendMsgFn, 'partner_api_should_suppress_iqpigeon_reply'), 'Partner API outbound send is not CRM-suppressed');
gate_assert(defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false, 'AGENT_CORE_ENABLED is false');
gate_assert(!function_exists('agent_core_staging_bot_ids'), 'Agent Core bootstrap has no staging allow-list');

echo "\n{$passed} passed, {$failed} failed (offline gate)\n";
exit($failed > 0 ? 1 : 0);
