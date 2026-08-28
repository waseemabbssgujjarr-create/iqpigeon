<?php
/**
 * WhatsApp Cloud API sender — uses client_whatsapp_accounts tokens.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/billing-settings.php';
require_once __DIR__ . '/../includes/whatsapp-token.php';
require_once __DIR__ . '/Encryption.php';

class WhatsAppSender
{
    /**
     * Load active WhatsApp account for a client.
     *
     * @param int $clientId
     * @return array<string, mixed>|null
     */
    private static function getAccount(int $clientId): ?array
    {
        return db_fetch(
            'SELECT * FROM client_whatsapp_accounts
             WHERE client_id = ? AND connection_status = \'active\'
             ORDER BY connected_at DESC LIMIT 1',
            'i',
            [$clientId]
        );
    }

    /**
     * Graph API base URL for messages.
     *
     * @param string $phoneNumberId
     * @return string
     */
    private static function messagesUrl(string $phoneNumberId): string
    {
        return 'https://graph.facebook.com/' . META_GRAPH_API_VERSION . '/' . $phoneNumberId . '/messages';
    }

    /**
     * POST JSON to Graph API.
     *
     * @param string $url
     * @param string $token
     * @param array<string, mixed> $payload
     * @return array{success: bool, data?: array<string, mixed>, error?: string, http_code?: int}
     */
    private static function graphPost(string $url, string $token, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'Network error contacting Meta API.', 'http_code' => 0];
        }

        $data = json_decode($response, true) ?: [];

        if ($httpCode >= 400) {
            $err = $data['error']['message'] ?? $response;
            error_log('WhatsApp Graph API error: ' . $err);
            return ['success' => false, 'error' => (string) $err, 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $data, 'http_code' => $httpCode];
    }

    /**
     * Log a message to whatsapp_messages_log.
     *
     * @param array<string, mixed> $row
     * @return void
     */
    public static function logMessage(array $row): void
    {
        $payloadJson = null;
        if (!empty($row['payload'])) {
            $payloadJson = is_string($row['payload'])
                ? $row['payload']
                : json_encode($row['payload']);
        }

        db_insert(
            'INSERT INTO whatsapp_messages_log
             (client_id, phone_number_id, direction, wa_message_id, from_number, to_number, message_body, payload, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'issssssss',
            [
                (int) $row['client_id'],
                $row['phone_number_id'] ?? null,
                $row['direction'],
                $row['wa_message_id'] ?? null,
                $row['from_number'] ?? null,
                $row['to_number'] ?? null,
                $row['message_body'] ?? null,
                $payloadJson,
                $row['status'] ?? null,
            ]
        );
    }

    /**
     * Send a text message via WhatsApp Cloud API.
     *
     * @param int $clientId
     * @param string $toNumber E.164 digits only
     * @param string $messageBody
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public static function sendTextMessage(int $clientId, string $toNumber, string $messageBody): array
    {
        $account = self::getAccount($clientId);
        if (!$account) {
            return ['success' => false, 'error' => 'No active WhatsApp account connected for this client.'];
        }

        $token = Encryption::decrypt($account['business_token']);
        if ($token === false) {
            return ['success' => false, 'error' => 'Failed to decrypt business token.'];
        }

        $to = preg_replace('/\D/', '', $toNumber);
        $phoneNumberId = $account['phone_number_id'];

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $messageBody],
        ];

        $result = self::graphPost(self::messagesUrl($phoneNumberId), $token, $payload);

        $messageId = $result['data']['messages'][0]['id'] ?? null;

        self::logMessage([
            'client_id'       => $clientId,
            'phone_number_id' => $phoneNumberId,
            'direction'       => 'outbound',
            'wa_message_id'   => $messageId,
            'from_number'     => $account['phone_display_number'],
            'to_number'       => $to,
            'message_body'    => $messageBody,
            'payload'         => $payload,
            'status'          => $result['success'] ? 'sent' : 'failed',
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Send failed.'];
        }

        return ['success' => true, 'message_id' => (string) $messageId];
    }

    /**
     * Send a template message.
     *
     * @param int $clientId
     * @param string $toNumber
     * @param string $templateName
     * @param string $langCode
     * @param array<int, mixed> $components
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public static function sendTemplateMessage(
        int $clientId,
        string $toNumber,
        string $templateName,
        string $langCode = 'en_US',
        array $components = []
    ): array {
        $account = self::getAccount($clientId);
        if (!$account) {
            return ['success' => false, 'error' => 'No active WhatsApp account connected.'];
        }

        $token = Encryption::decrypt($account['business_token']);
        if ($token === false) {
            return ['success' => false, 'error' => 'Failed to decrypt business token.'];
        }

        $to = preg_replace('/\D/', '', $toNumber);
        $phoneNumberId = $account['phone_number_id'];

        $template = [
            'name'     => $templateName,
            'language' => ['code' => $langCode],
        ];
        if (!empty($components)) {
            $template['components'] = $components;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $template,
        ];

        $result = self::graphPost(self::messagesUrl($phoneNumberId), $token, $payload);
        $messageId = $result['data']['messages'][0]['id'] ?? null;

        self::logMessage([
            'client_id'       => $clientId,
            'phone_number_id' => $phoneNumberId,
            'direction'       => 'outbound',
            'wa_message_id'   => $messageId,
            'from_number'     => $account['phone_display_number'],
            'to_number'       => $to,
            'message_body'    => '[template:' . $templateName . ']',
            'payload'         => $payload,
            'status'          => $result['success'] ? 'sent' : 'failed',
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Template send failed.'];
        }

        return ['success' => true, 'message_id' => (string) $messageId];
    }

    /**
     * Mark an inbound message as read.
     *
     * @param int $clientId
     * @param string $waMessageId
     * @return array{success: bool, error?: string}
     */
    public static function markMessageRead(int $clientId, string $waMessageId): array
    {
        $account = self::getAccount($clientId);
        if (!$account) {
            return ['success' => false, 'error' => 'No active WhatsApp account.'];
        }

        $token = Encryption::decrypt($account['business_token']);
        if ($token === false) {
            return ['success' => false, 'error' => 'Token decrypt failed.'];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $waMessageId,
        ];

        $result = self::graphPost(self::messagesUrl($account['phone_number_id']), $token, $payload);

        return $result['success']
            ? ['success' => true]
            : ['success' => false, 'error' => $result['error'] ?? 'Mark read failed.'];
    }
}

/**
 * Handle incoming WhatsApp message — auto-reply stub (replace with AI bot).
 *
 * @param int $clientId
 * @param string $from
 * @param string $body
 * @param string $type
 * @return void
 */
function handleIncomingMessage(int $clientId, string $from, string $body, string $type): void
{
    if ($type !== 'text' || $body === '') {
        return;
    }

    $reply = "Hi! 👋 Thanks for reaching out to us.\n"
        . "Our AI Sales Assistant has received your message and will respond shortly.\n"
        . "— IQ Pigeon | iqpigeon.com";

    WhatsAppSender::sendTextMessage($clientId, $from, $reply);
    // TODO: Replace auto-reply with full AI bot logic here
}
