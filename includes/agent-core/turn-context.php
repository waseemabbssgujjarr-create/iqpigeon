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
    $caps = [];
    $botId = (int) ($bot['id'] ?? 0);
    try {
        if (function_exists('bot_uses_shop_catalog') || is_file(dirname(__DIR__) . '/bot-knowledge.php')) {
            require_once dirname(__DIR__) . '/bot-knowledge.php';
            if (bot_uses_shop_catalog($bot)) {
                $caps[] = 'catalog';
                $caps[] = 'cart';
            }
        }
        if ($botId > 0 && is_file(dirname(__DIR__) . '/catalog.php')) {
            require_once dirname(__DIR__) . '/catalog.php';
            if (function_exists('catalog_bot_has_products') && catalog_bot_has_products($botId)) {
                if (!in_array('catalog', $caps, true)) {
                    $caps[] = 'catalog';
                }
                if (!in_array('cart', $caps, true)) {
                    $caps[] = 'cart';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('agent_core_business_profile catalog: ' . $e->getMessage());
    }
    try {
        if ($botId > 0 && is_file(dirname(__DIR__) . '/booking.php')) {
            require_once dirname(__DIR__) . '/booking.php';
            $settings = booking_settings_for_bot($botId);
            if (!empty($settings['enabled'])) {
                $caps[] = 'booking';
            }
        }
    } catch (Throwable $e) {
        error_log('agent_core_business_profile booking: ' . $e->getMessage());
    }
    $caps[] = 'live_web';
    $caps = array_values(array_unique($caps));

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
    ];
}
