<?php
/**
 * Media enrich + first-class understanding for the same Core pipeline.
 * Voice/image/document via turn_engine_process_turn_media (not a Graph send).
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $bot
 */
function agent_core_media_should_enrich(array $bot): bool
{
    return function_exists('agent_core_enabled') && agent_core_enabled($bot, 'whatsapp');
}

function agent_core_media_enrich(int $turnId, string $downloadToken): void
{
    if ($turnId <= 0) {
        return;
    }
    if (!function_exists('turn_engine_process_turn_media')) {
        $engine = dirname(__DIR__) . '/conversation-turn-engine.php';
        if (is_file($engine)) {
            require_once $engine;
        }
    }
    if (!function_exists('turn_engine_process_turn_media')) {
        return;
    }
    turn_engine_process_turn_media($turnId, $downloadToken);
}

/**
 * Normalize media into Core understanding records.
 *
 * @param array<string, mixed> $turn
 * @return list<array{type: string, text: string, image_description: string, extracted_content: string, confidence: float, metadata: array}>
 */
function agent_core_understand_media(array $turn): array
{
    $out = [];
    $media = is_array($turn['media'] ?? null) ? $turn['media'] : [];
    foreach ($media as $item) {
        if (!is_array($item)) {
            continue;
        }
        $out[] = agent_core_normalize_media_item($item);
    }

    return $out;
}

/**
 * @param array<string, mixed> $item
 * @return array{type: string, text: string, image_description: string, extracted_content: string, confidence: float, metadata: array}
 */
function agent_core_normalize_media_item(array $item): array
{
    $rawType = mb_strtolower(trim((string) ($item['type'] ?? $item['media_type'] ?? 'text')));
    $type = 'text';
    if (in_array($rawType, ['image', 'photo', 'picture', 'sticker'], true)) {
        $type = 'image';
    } elseif (in_array($rawType, ['audio', 'voice', 'ptt', 'audio_message', 'ogg'], true)) {
        $type = 'audio';
    } elseif (in_array($rawType, ['document', 'file', 'pdf', 'doc'], true)) {
        $type = 'document';
    } elseif (in_array($rawType, ['video'], true)) {
        $type = 'video';
    }

    $text = trim((string) ($item['text'] ?? ''));
    if ($text === '') {
        $text = trim((string) ($item['transcript'] ?? $item['caption'] ?? ''));
    }
    $description = trim((string) ($item['description'] ?? $item['image_description'] ?? ''));
    $extracted = trim((string) ($item['extracted_content'] ?? $item['extracted'] ?? ''));
    if ($type === 'audio' && $text === '') {
        $text = $description;
    }
    if ($type === 'image' && $description === '' && $text !== '' && !str_contains(mb_strtolower($text), 'analysis unavailable')) {
        $description = $text;
    }
    if ($type === 'document' && $extracted === '') {
        $extracted = $text !== '' ? $text : $description;
    }

    $confidence = 0.5;
    if (isset($item['confidence']) && is_numeric($item['confidence'])) {
        $confidence = (float) $item['confidence'];
    } elseif ($type === 'image' && $description !== '' && !str_contains(mb_strtolower($description), 'analysis unavailable')) {
        $confidence = 0.8;
    } elseif ($type === 'audio' && $text !== '') {
        $confidence = 0.75;
    } elseif ($type === 'document' && $extracted !== '') {
        $confidence = 0.7;
    } elseif (str_contains(mb_strtolower($description . ' ' . $text), 'analysis unavailable')) {
        $confidence = 0.2;
    }

    return [
        'type'               => $type,
        'text'               => $text,
        'image_description'  => $description,
        'extracted_content'  => $extracted,
        'confidence'         => $confidence,
        'metadata'           => [
            'url'      => (string) ($item['url'] ?? $item['media_url'] ?? ''),
            'mime'     => (string) ($item['mime'] ?? $item['mime_type'] ?? ''),
            'filename' => (string) ($item['filename'] ?? $item['name'] ?? ''),
            'raw_type' => $rawType,
        ],
    ];
}
