<?php
/**
 * Download WhatsApp Cloud API media (voice, images).
 */

require_once __DIR__ . '/../config.php';

function whatsapp_media_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/whatsapp-media';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @return array{success: bool, path?: string, mime?: string, error?: string}
 */
function whatsapp_download_media(string $mediaId, string $token): array
{
    $mediaId = trim($mediaId);
    if ($mediaId === '' || $token === '') {
        return ['success' => false, 'error' => 'Missing media id or token'];
    }

    $version = defined('META_GRAPH_API_VERSION') ? META_GRAPH_API_VERSION : 'v21.0';
    $metaUrl = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($mediaId);

    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $metaResponse = curl_exec($ch);
    $metaCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($metaResponse === false || $metaCode >= 400) {
        $detail = is_string($metaResponse) ? substr($metaResponse, 0, 200) : 'curl failed';
        return ['success' => false, 'error' => 'Meta media metadata failed (HTTP ' . $metaCode . '): ' . $detail];
    }

    $meta = json_decode($metaResponse, true);
    $downloadUrl = $meta['url'] ?? '';
    $mime = (string) ($meta['mime_type'] ?? 'application/octet-stream');

    if ($downloadUrl === '') {
        return ['success' => false, 'error' => 'Media URL missing from Meta response'];
    }

    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $binary = curl_exec($ch);
    $dlCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($binary === false || $dlCode >= 400 || $binary === '') {
        return ['success' => false, 'error' => 'Media download failed (HTTP ' . $dlCode . ')'];
    }

    $ext = match (true) {
        str_contains($mime, 'ogg')   => 'ogg',
        str_contains($mime, 'mpeg')  => 'mp3',
        str_contains($mime, 'mp4') && str_contains($mime, 'audio') => 'm4a',
        str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
        str_contains($mime, 'png')   => 'png',
        str_contains($mime, 'webp')  => 'webp',
        default => 'bin',
    };

    $path = whatsapp_media_storage_dir() . '/' . $mediaId . '.' . $ext;
    if (@file_put_contents($path, $binary) === false) {
        return ['success' => false, 'error' => 'Could not save media to disk'];
    }

    return ['success' => true, 'path' => $path, 'mime' => $mime];
}

function whatsapp_media_cleanup(?string $path): void
{
    if ($path && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Convert WhatsApp inbound message to text for the AI (text, voice, image).
 *
 * @return array{success: bool, text?: string, kind?: string, error?: string}
 */
function whatsapp_inbound_message_to_text(array $msg, string $token): array
{
    if (defined('MEDIA_UNDERSTANDING_ENABLED') && !MEDIA_UNDERSTANDING_ENABLED) {
        return ['success' => false, 'error' => 'Media understanding disabled'];
    }

    $type = (string) ($msg['type'] ?? '');

    if ($type === 'text') {
        $body = trim($msg['text']['body'] ?? '');
        return $body !== ''
            ? ['success' => true, 'text' => $body, 'kind' => 'text']
            : ['success' => false, 'error' => 'Empty text message'];
    }

    require_once __DIR__ . '/media-understanding.php';

    if ($type === 'audio') {
        $mediaId = (string) ($msg['audio']['id'] ?? '');
        $isVoice = !empty($msg['audio']['voice']);
        $dl = whatsapp_download_media($mediaId, $token);
        if (!$dl['success']) {
            return $dl;
        }
        $result = media_transcribe_voice($dl['path'], $dl['mime'] ?? 'audio/ogg');
        $mediaUrl = null;
        require_once __DIR__ . '/conversation-media.php';
        $persisted = conversation_persist_whatsapp_media($dl['path'], $mediaId, $dl['mime'] ?? 'audio/ogg');
        if (!empty($persisted['success'])) {
            $mediaUrl = $persisted['url'] ?? null;
        }
        whatsapp_media_cleanup($dl['path'] ?? null);
        if (!$result['success']) {
            return $result;
        }
        $transcript = trim($result['text'] ?? '');
        if ($transcript === '') {
            return ['success' => false, 'error' => 'Voice message had no detectable speech'];
        }

        return [
            'success'   => true,
            'kind'      => $isVoice ? 'voice' : 'audio',
            'text'      => '[Voice message from customer]: ' . $transcript,
            'media_url' => $mediaUrl,
        ];
    }

    if ($type === 'image') {
        $mediaId = (string) ($msg['image']['id'] ?? '');
        $caption = trim($msg['image']['caption'] ?? '');
        $dl = whatsapp_download_media($mediaId, $token);
        if (!$dl['success']) {
            return $dl;
        }
        $result = media_understand_image($dl['path'], $dl['mime'] ?? 'image/jpeg', $caption);
        $mediaUrl = null;
        require_once __DIR__ . '/conversation-media.php';
        $persisted = conversation_persist_whatsapp_media($dl['path'], $mediaId, $dl['mime'] ?? 'image/jpeg');
        if (!empty($persisted['success'])) {
            $mediaUrl = $persisted['url'] ?? null;
        }
        whatsapp_media_cleanup($dl['path'] ?? null);
        if (!$result['success']) {
            return $result;
        }
        $analysis = trim($result['text'] ?? '');
        if ($analysis === '') {
            return ['success' => false, 'error' => 'Could not analyze image'];
        }

        $text = '[Customer sent an image] ' . $analysis;
        if ($caption !== '') {
            $text .= ' Caption they wrote: "' . $caption . '"';
        }

        return ['success' => true, 'kind' => 'image', 'text' => $text, 'media_url' => $mediaUrl];
    }

    return ['success' => false, 'error' => 'Unsupported message type: ' . $type];
}
