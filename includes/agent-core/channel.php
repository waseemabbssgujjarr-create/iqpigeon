<?php
/**
 * Channel adapters around Agent Core. Core does not ACK, debounce, or Graph-send.
 * WhatsApp compose, Test & Publish (bot-chat), and future widget/get_ai_response
 * call this. When Core is OFF or unusable, the caller keeps its legacy path.
 *
 * @return array{ok: bool, reply: string, path: string, intent?: array, plan?: array, error?: ?string}
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $bot
 * @return array<string, mixed>
 */
function agent_core_channel_try(array $bot, int $leadId, string $message, int $turnId = 0, string $channel = 'whatsapp'): array
{
    $empty = [
        'ok'    => false,
        'reply' => '',
        'path'  => 'core_off',
        'error' => null,
    ];
    if (!function_exists('agent_core_enabled') || !agent_core_enabled($bot)) {
        return $empty;
    }
    try {
        $core = agent_core_reply($bot, $leadId, $message, $turnId, $channel);
        if (agent_core_result_usable($core)) {
            return $core;
        }
        $core['ok'] = false;
        $core['reply'] = '';
        $core['path'] = 'core_unusable';

        return $core;
    } catch (Throwable $e) {
        error_log('agent_core_channel_try ' . $channel . ': ' . $e->getMessage());
        $empty['path'] = 'core_error';
        $empty['error'] = $e->getMessage();

        return $empty;
    }
}
