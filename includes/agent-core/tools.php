<?php
/**
 * Phase 1 read-only tools. Mutating cart/order/booking/qualification/memory.write are rejected.
 *
 * @return array{ok: bool, name: string, data: mixed, error?: string}
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_FORBIDDEN_TOOLS = [
    'cart.add',
    'cart.remove',
    'order.place',
    'booking.create',
    'qualification.update',
    'memory.write',
];

/**
 * @param array<string, mixed> $args
 * @param array<string, mixed> $turnCtx
 * @return array<string, mixed>
 */
function agent_core_tool(string $name, array $args, array $turnCtx): array
{
    $name = trim($name);
    if ($name === '' || in_array($name, AGENT_CORE_FORBIDDEN_TOOLS, true)) {
        return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'forbidden_phase1'];
    }
    if (!in_array($name, AGENT_CORE_PHASE1_TOOLS, true)) {
        return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'unknown_or_not_phase1'];
    }

    $botId = (int) ($turnCtx['bot_id'] ?? 0);
    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    $query = trim((string) ($args['query'] ?? $turnCtx['text'] ?? ''));

    try {
        if ($name === 'catalog.search') {
            require_once dirname(__DIR__) . '/catalog.php';
            $hits = $botId > 0 ? catalog_search_products($botId, $query, 5) : [];

            return ['ok' => true, 'name' => $name, 'data' => $hits];
        }
        if ($name === 'cart.view') {
            require_once dirname(__DIR__) . '/cart.php';
            $block = $leadId > 0 && $botId > 0 ? cart_ai_context_block($leadId, $botId) : '';
            if ($block === '' && $leadId > 0 && function_exists('cart_format_summary')) {
                $block = cart_format_summary($leadId);
            }

            return ['ok' => true, 'name' => $name, 'data' => $block];
        }
        if ($name === 'booking.offer') {
            require_once dirname(__DIR__) . '/booking.php';
            $settings = $botId > 0 ? booking_settings_for_bot($botId) : ['enabled' => 0];
            if (empty($settings['enabled'])) {
                return ['ok' => true, 'name' => $name, 'data' => ''];
            }
            $msg = booking_slots_message($botId, 6);

            return ['ok' => true, 'name' => $name, 'data' => $msg];
        }
        if ($name === 'memory.read') {
            $facts = agent_core_memory_read($botId, $leadId, $query);

            return ['ok' => true, 'name' => $name, 'data' => $facts];
        }
        if ($name === 'live_web.search') {
            if (!empty($GLOBALS['agent_core_no_network'])) {
                return ['ok' => true, 'name' => $name, 'data' => ['needed' => true, 'ok' => false, 'evidence' => '', 'skipped' => 'no_network']];
            }
            require_once dirname(__DIR__) . '/live-world-info.php';
            $thread = '';
            $found = live_world_maybe_search($query, $thread);
            if (empty($found['needed']) && $query !== '' && function_exists('live_world_search')) {
                $direct = live_world_search($query);
                $found = [
                    'needed'    => true,
                    'performed' => !empty($direct['performed']) || !empty($direct['cached']),
                    'ok'        => !empty($direct['ok']),
                    'evidence'  => trim((string) ($direct['text'] ?? '')),
                    'error'     => (string) ($direct['error'] ?? ''),
                ];
            }

            return ['ok' => true, 'name' => $name, 'data' => $found];
        }
    } catch (Throwable $e) {
        error_log('agent_core_tool ' . $name . ': ' . $e->getMessage());

        return ['ok' => false, 'name' => $name, 'data' => null, 'error' => $e->getMessage()];
    }

    return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'unhandled'];
}
