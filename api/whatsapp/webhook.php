<?php
// ─────────────────────────────────────────
// Meta may register either webhook URL:
//   https://iqpigeon.com/api/whatsapp-webhook.php  (primary)
//   https://iqpigeon.com/api/whatsapp/webhook.php  (legacy / Embedded Signup docs)
// Both run the same AI auto-reply pipeline (bots table + get_ai_response).
// ─────────────────────────────────────────

require_once __DIR__ . '/../../config.php';

if (!function_exists('meta_webhook_verify_ok')) {
    $domainFile = __DIR__ . '/../../includes/domain.php';
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

// GET: Meta verification handshake (before DB/includes)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if ($mode === 'subscribe' && meta_webhook_verify_ok($token)) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . (string) strlen($challenge));
        echo $challenge;
        exit;
    }

    http_response_code(403);
    exit;
}

// POST: delegate to the primary WhatsApp webhook (full AI pipeline)
require __DIR__ . '/../whatsapp-webhook.php';
