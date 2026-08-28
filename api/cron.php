<?php
/**
 * Scheduled tasks — bookings, drip, abandoned cart, shipments, tokens.
 * WhatsApp auto-reply is NOT done here (that races the webhook and goes silent).
 *
 * cPanel cron (every 15 min), HTTP only:
 *   curl -s "https://iqpigeon.com/api/cron.php?key=YOUR_CRON_SECRET"
 *
 * Do not use CLI `php api/cron.php?key=...` — PHP CLI ignores ?query strings.
 * Do not add turn-worker.php?run=1 on a short interval; it fights live chats.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/drip.php';
require_once __DIR__ . '/../includes/abandoned-cart.php';
require_once __DIR__ . '/../includes/shipment.php';
require_once __DIR__ . '/../includes/platform-schema.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/../includes/platform-renewals.php';
require_once __DIR__ . '/../includes/ai-ceo.php';
require_once __DIR__ . '/../includes/catalog-image.php';
require_once __DIR__ . '/../includes/meta-catalog-sync.php';

header('Content-Type: application/json');

$key = (string) ($_GET['key'] ?? '');
$expected = defined('CRON_SECRET') ? CRON_SECRET : '';
if ($expected === '' || !hash_equals($expected, $key)) {
    json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

platform_ensure_all_silent();

$reminders = booking_process_reminders();
$drip = drip_process_all();
$abandoned = abandoned_cart_process_all();
$shipments = shipment_sync_all(80);
$whatsappTokens = whatsapp_process_token_health_all();
$platformRenewals = platform_renewal_process_all();
$aiCeoOutreach = ai_ceo_process_outreach_all();
$catalogOriginalsPurge = catalog_purge_expired_originals();
$metaCatalogSync = meta_catalog_process_pending(8, 80);

$turnRecover = ['dispatched' => false];
if (defined('APP_URL') && APP_URL !== '' && defined('CRON_SECRET') && CRON_SECRET !== '') {
    $workerUrl = rtrim((string) APP_URL, '/') . '/api/turn-worker.php';
    $payload = json_encode([
        'key'      => CRON_SECRET,
        'lead_ids' => [],
        'source'   => 'cron',
    ], JSON_UNESCAPED_UNICODE);
    $method = 'none';
    if (function_exists('exec') && stripos(PHP_OS, 'WIN') !== 0 && is_string($payload) && $payload !== '') {
        $payloadFile = tempnam(sys_get_temp_dir(), 'iqp_cron_');
        if ($payloadFile !== false && @file_put_contents($payloadFile, $payload) !== false) {
            @chmod($payloadFile, 0600);
            $cmd = sprintf(
                'nohup curl -sS -m 90 -X POST -H %s --data-binary @%s %s >/dev/null 2>&1; rm -f %s &',
                escapeshellarg('Content-Type: application/json'),
                escapeshellarg($payloadFile),
                escapeshellarg($workerUrl),
                escapeshellarg($payloadFile)
            );
            @exec($cmd);
            $method = 'detached_90s';
        }
    }
    if ($method === 'none' && is_string($payload)) {
        $ch = curl_init($workerUrl);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Connection: Close'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT        => 1,
                CURLOPT_NOSIGNAL       => true,
            ]);
            @curl_exec($ch);
            curl_close($ch);
            $method = 'curl_fallback_1s';
        }
    }
    $turnRecover = [
        'dispatched' => $method !== 'none',
        'method'     => $method,
        'reason'     => 'Stale unanswered turns via turn-worker send_leads_now. Live webhook still ACKs Meta first. Worker skips leads live in the last 20s and bots with no WhatsApp phone ID.',
    ];
}

json_response([
    'success'   => true,
    'reminders' => $reminders,
    'drip'      => $drip,
    'abandoned' => $abandoned,
    'shipments' => $shipments,
    'whatsapp_tokens' => $whatsappTokens,
    'platform_renewals' => $platformRenewals,
    'ai_ceo_outreach' => $aiCeoOutreach,
    'turn_engine' => $turnRecover,
    'catalog_originals_purged' => $catalogOriginalsPurge['deleted'] ?? 0,
    'meta_catalog_sync' => $metaCatalogSync,
    'time'      => date('c'),
]);
