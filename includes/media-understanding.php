<?php
/**
 * Voice transcription and image understanding for WhatsApp media (OpenAI).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/integration-settings.php';

function media_api_key_openai_legacy(): string
{
    return trim(integration_config('OPENAI_MEDIA_KEY'));
}

function media_api_key_openai_voice(): string
{
    $key = trim(integration_config('OPENAI_VOICE_API_KEY'));
    if ($key !== '') {
        return $key;
    }

    $chat = integration_openai_chat_key();
    if ($chat !== '') {
        return $chat;
    }

    return media_api_key_openai_legacy();
}

function media_api_key_openai_image(): string
{
    $key = trim(integration_config('OPENAI_IMAGE_API_KEY'));
    if ($key !== '') {
        return $key;
    }

    $chat = integration_openai_chat_key();
    if ($chat !== '') {
        return $chat;
    }

    return media_api_key_openai_legacy();
}

/** @deprecated Use media_api_key_openai_voice() or media_api_key_openai_image() */
function media_api_key_openai(): string
{
    return media_api_key_openai_voice() !== ''
        ? media_api_key_openai_voice()
        : media_api_key_openai_image();
}

/**
 * @return array{success: bool, text?: string, error?: string}
 */
function media_transcribe_voice(string $filePath, string $mime): array
{
    if (!is_readable($filePath)) {
        return ['success' => false, 'error' => 'Audio file not readable'];
    }

    $bytes = file_get_contents($filePath);
    if ($bytes === false || $bytes === '') {
        return ['success' => false, 'error' => 'Empty audio file'];
    }

    $openaiKey = media_api_key_openai_voice();
    if ($openaiKey === '') {
        return ['success' => false, 'error' => 'Set OpenAI voice key in Admin → Integrations → Media'];
    }

    return openai_whisper_transcribe($filePath, $openaiKey);
}

/**
 * @return array{success: bool, text?: string, error?: string}
 */
function media_understand_image(string $filePath, string $mime, string $caption = ''): array
{
    if (!is_readable($filePath)) {
        return ['success' => false, 'error' => 'Image file not readable'];
    }

    $bytes = file_get_contents($filePath);
    if ($bytes === false || $bytes === '') {
        return ['success' => false, 'error' => 'Empty image file'];
    }

    $b64 = base64_encode($bytes);
    $mime = $mime !== '' ? $mime : 'image/jpeg';

    $prompt = 'A customer sent this image on WhatsApp to a sales team. In 2–4 short sentences: '
        . '(1) describe what is in the picture, '
        . '(2) quote any visible text if present, '
        . '(3) infer what the customer likely wants or is asking about. '
        . 'Write as notes for the sales rep — not as a reply to the customer.';
    if ($caption !== '') {
        $prompt .= ' The customer also added this caption: "' . $caption . '"';
    }

    $openaiKey = media_api_key_openai_image();
    if ($openaiKey === '') {
        return ['success' => false, 'error' => 'Set OpenAI image key in Admin → Integrations → Media'];
    }

    return openai_vision_describe($b64, $mime, $prompt, $openaiKey);
}

/**
 * @return array{success: bool, text?: string, error?: string}
 */
function openai_whisper_transcribe(string $filePath, string $apiKey): array
{
    $model = defined('WHISPER_MODEL') ? WHISPER_MODEL : 'whisper-1';

    $postFields = [
        'file'            => new CURLFile($filePath, mime_content_type($filePath) ?: 'audio/ogg', basename($filePath)),
        'model'           => $model,
        'response_format' => 'text',
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_TIMEOUT        => 180,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        $detail = is_string($response) ? trim($response) : '';
        if ($detail !== '') {
            $data = json_decode($detail, true);
            $apiMsg = trim($data['error']['message'] ?? '');
            if ($apiMsg !== '') {
                $detail = $apiMsg;
            }
        }
        error_log('Whisper API failed (' . $httpCode . '): ' . mb_substr($detail, 0, 300));

        return [
            'success' => false,
            'error'   => 'Whisper transcription failed'
                . ($httpCode > 0 ? ' (HTTP ' . $httpCode . ')' : '')
                . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : ''),
        ];
    }

    $text = trim((string) $response);

    return $text !== ''
        ? ['success' => true, 'text' => $text]
        : ['success' => false, 'error' => 'Empty transcription'];
}

/**
 * @return array{success: bool, text?: string, error?: string}
 */
function openai_vision_describe(string $base64, string $mime, string $prompt, string $apiKey): array
{
    $model = defined('VISION_MODEL') ? VISION_MODEL : 'gpt-4o-mini';

    $payload = json_encode([
        'model'    => $model,
        'messages' => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $base64]],
            ],
        ]],
        'max_tokens' => 500,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        $detail = is_string($response) ? trim($response) : '';
        if ($detail !== '') {
            $data = json_decode($detail, true);
            $apiMsg = trim($data['error']['message'] ?? '');
            if ($apiMsg !== '') {
                $detail = $apiMsg;
            }
        }
        error_log('OpenAI vision failed (' . $httpCode . '): ' . mb_substr($detail, 0, 300));

        return [
            'success' => false,
            'error'   => 'Vision API failed'
                . ($httpCode > 0 ? ' (HTTP ' . $httpCode . ')' : '')
                . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : ''),
        ];
    }

    $data = json_decode($response, true);
    $text = trim($data['choices'][0]['message']['content'] ?? '');

    return $text !== ''
        ? ['success' => true, 'text' => $text]
        : ['success' => false, 'error' => 'Empty vision response'];
}

/**
 * Self-test for voice/image setup (used by whatsapp-diagnose.php).
 *
 * @return array{ok: bool, detail: string}
 */
function media_understanding_self_test(): array
{
    if (!integration_media_understanding_enabled()) {
        return ['ok' => false, 'detail' => 'Media understanding disabled in Admin → Integrations'];
    }

    $voiceKey = media_api_key_openai_voice();
    $imageKey = media_api_key_openai_image();

    if ($voiceKey === '' && $imageKey === '') {
        return [
            'ok'     => false,
            'detail' => 'No OpenAI media keys. Set voice and/or image keys in Admin → Integrations → Media.',
        ];
    }

    $storageDir = dirname(__DIR__) . '/storage/whatsapp-media';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0755, true)) {
        return ['ok' => false, 'detail' => 'Cannot create storage/whatsapp-media — check folder permissions'];
    }
    if (!is_writable($storageDir)) {
        return ['ok' => false, 'detail' => 'storage/whatsapp-media is not writable — voice/image download will fail'];
    }

    $details = [];
    if ($voiceKey !== '') {
        $details[] = 'OpenAI voice key set (Whisper)';
    }
    if ($imageKey !== '') {
        $details[] = 'OpenAI image key set (vision)';
    }

    return [
        'ok'     => true,
        'detail' => implode(' | ', $details),
    ];
}
