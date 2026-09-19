<?php
/**
 * TurnContext builder — channel-independent.
 *
 * @return array<string, mixed>
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $bot
 * @param list<array<string, mixed>> $media
 * @return array<string, mixed>
 */
function agent_core_turn_context(
    array $bot,
    int $leadId,
    int $turnId,
    string $channel,
    string $mergedText,
    array $media = []
): array {
    $text = trim($mergedText);
    if ($turnId > 0 && $media === [] && function_exists('turn_engine_build_turn_payload')) {
        try {
            $payload = turn_engine_build_turn_payload($turnId);
            $combined = trim((string) ($payload['combined'] ?? ''));
            if ($combined !== '') {
                $text = $combined;
            }
            $mediaType = (string) ($payload['media_type'] ?? '');
            if ($mediaType !== '') {
                $media[] = [
                    'type'        => $mediaType,
                    'text'        => $combined,
                    'caption'     => '',
                    'url'         => (string) ($payload['media_url'] ?? ''),
                    'description' => $mediaType === 'image' ? $combined : '',
                ];
            }
        } catch (Throwable $e) {
            error_log('agent_core_turn_context payload: ' . $e->getMessage());
        }
    }

    return [
        'channel'      => $channel !== '' ? $channel : 'whatsapp',
        'bot'          => $bot,
        'bot_id'       => (int) ($bot['id'] ?? 0),
        'lead_id'      => $leadId,
        'turn_id'      => $turnId,
        'text'         => $text,
        'media'        => $media,
        'profile'      => agent_core_business_profile($bot),
        'sender_id'    => '',
    ];
}

/**
 * @param array<string, mixed> $bot
 * @return array<string, mixed>
 */
function agent_core_business_profile(array $bot): array
{
    require_once __DIR__ . '/capabilities.php';
    $caps = business_capabilities_for_bot($bot);
    $capStates = business_capability_states_for_bot($bot);

    $brand = function_exists('get_bot_brand_label')
        ? get_bot_brand_label($bot)
        : trim((string) ($bot['company_name'] ?? $bot['name'] ?? ''));
    $rep = function_exists('get_bot_rep_name')
        ? get_bot_rep_name($bot)
        : trim((string) ($bot['rep_name'] ?? 'I'));

    return [
        'industry_key' => (string) ($bot['industry_key'] ?? ''),
        'brand'        => $brand,
        'rep'          => $rep,
        'address'      => trim((string) ($bot['address'] ?? '')),
        'capabilities' => $caps,
        'capability_states' => $capStates,
    ];
}
