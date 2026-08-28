<?php
/**
 * Instagram Messaging via Meta Graph API.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Send an Instagram DM reply.
 *
 * @param string $pageId Instagram page / IG account ID
 * @param string $token Access token (decrypted)
 * @param string $recipientId Sender Instagram user ID
 * @param string $text Message body
 * @return array{success: bool, message?: string}
 */
function send_instagram_message(string $pageId, string $token, string $recipientId, string $text): array
{
    $url = 'https://graph.facebook.com/v18.0/' . urlencode($pageId) . '/messages';

    $payload = json_encode([
        'recipient' => ['id' => $recipientId],
        'message'   => ['text' => $text],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        error_log('Instagram send failed: ' . ($response ?: 'curl error'));
        return ['success' => false, 'message' => 'Failed to send Instagram message.'];
    }

    return ['success' => true];
}

/**
 * Verify Instagram page credentials.
 *
 * @param string $pageId
 * @param string $token
 * @return array{success: bool, message: string}
 */
function verify_instagram_credentials(string $pageId, string $token): array
{
    $url = 'https://graph.facebook.com/v18.0/' . urlencode($pageId) . '?fields=id,name';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response ?: '', true);

    if ($httpCode === 200 && !empty($data['id'])) {
        return ['success' => true, 'message' => 'Instagram connected successfully.'];
    }

    $err = $data['error']['message'] ?? 'Invalid Page ID or access token.';
    return ['success' => false, 'message' => $err];
}
