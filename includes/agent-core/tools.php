<?php
/**
 * Read-only tools. Mutating cart/order/booking/qualification/memory.write are rejected.
 *
 * @return array{ok: bool, name: string, data: mixed, error?: string}
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_FORBIDDEN_TOOLS = [
    'cart.add',
    'cart.remove',
    'order.place',
    'qualification.update',
    'memory.write',
];

/** @var list<string> */
const AGENT_CORE_PHASE2_MUTATING_TOOLS = [
    'booking.create',
    'human_handoff.create',
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
    if (in_array($name, AGENT_CORE_PHASE2_MUTATING_TOOLS, true)) {
        if (empty($turnCtx['allow_mutating_tools'])) {
            return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'forbidden_phase1'];
        }

        return agent_core_mutating_tool($name, $args, $turnCtx);
    }
    if (!in_array($name, AGENT_CORE_PHASE1_TOOLS, true)) {
        return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'unknown_or_not_phase1'];
    }

    $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
    $botId = (int) ($turnCtx['bot_id'] ?? $bot['id'] ?? 0);
    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    $query = trim((string) ($args['query'] ?? $turnCtx['text'] ?? ''));

    try {
        if ($name === 'catalog.search') {
            require_once dirname(__DIR__) . '/catalog.php';
            $hits = $botId > 0 ? catalog_search_products($botId, $query, 5) : [];

            return ['ok' => true, 'name' => $name, 'data' => $hits];
        }
        if ($name === 'catalog.get_product') {
            require_once dirname(__DIR__) . '/catalog.php';
            $productId = (int) ($args['product_id'] ?? 0);
            if ($productId <= 0 && preg_match('/(\d+)/', $query, $m)) {
                $productId = (int) $m[1];
            }
            $product = null;
            if ($botId > 0 && $productId > 0 && function_exists('catalog_products_for_bot')) {
                foreach (catalog_products_for_bot($botId) as $row) {
                    if ((int) ($row['id'] ?? 0) === $productId) {
                        $product = $row;
                        break;
                    }
                }
            }

            return ['ok' => $product !== null, 'name' => $name, 'data' => $product];
        }
        if ($name === 'cart.view') {
            require_once dirname(__DIR__) . '/cart.php';
            $block = $leadId > 0 && $botId > 0 ? cart_ai_context_block($leadId, $botId) : '';
            if ($block === '' && $leadId > 0 && function_exists('cart_format_summary')) {
                $block = cart_format_summary($leadId);
            }

            return ['ok' => true, 'name' => $name, 'data' => $block];
        }
        if ($name === 'booking.offer' || $name === 'booking.availability') {
            require_once __DIR__ . '/booking-tools.php';
            $settings = $botId > 0 && function_exists('booking_settings_for_bot')
                ? booking_settings_for_bot($botId)
                : ['enabled' => 0];
            if (empty($settings['enabled'])) {
                return ['ok' => true, 'name' => $name, 'data' => [
                    'availability_verified' => false,
                    'slots'                 => [],
                    'message'               => '',
                ]];
            }
            $data = booking_tool_availability(
                $botId,
                6,
                trim((string) ($args['date'] ?? '')),
                trim((string) ($args['time'] ?? ''))
            );

            return ['ok' => !empty($data['ok']), 'name' => $name, 'data' => $data];
        }
        if ($name === 'memory.read') {
            $facts = agent_core_memory_read($botId, $leadId, $query);

            return ['ok' => true, 'name' => $name, 'data' => $facts];
        }
        if ($name === 'hours.read') {
            require_once dirname(__DIR__) . '/conversation-runtime-memory.php';
            $hours = conversation_runtime_hours_now($bot !== [] ? $bot : ['id' => $botId]);

            return ['ok' => true, 'name' => $name, 'data' => $hours];
        }
        if ($name === 'orders.read') {
            require_once dirname(__DIR__) . '/conversation-runtime-memory.php';
            $orders = conversation_runtime_load_orders($botId, $leadId);

            return ['ok' => true, 'name' => $name, 'data' => $orders];
        }
        if ($name === 'live_web.search') {
            if (!empty($GLOBALS['agent_core_no_network'])) {
                return ['ok' => true, 'name' => $name, 'data' => [
                    'needed'                 => true,
                    'ok'                     => false,
                    'evidence'               => '',
                    'skipped'                => 'no_network',
                    'evidence_usable'        => false,
                    'has_web_search_call'    => false,
                    'web_search_call_status' => '',
                    'looks_like_refusal'     => false,
                    'evidence_chars'         => 0,
                ]];
            }
            require_once dirname(__DIR__) . '/live-world-info.php';
            $thread = trim((string) ($args['thread'] ?? $turnCtx['thread'] ?? ''));
            $found = live_world_maybe_search($query, $thread);
            if (empty($found['needed']) && $query !== '' && function_exists('live_world_search')) {
                $direct = live_world_search($query);
                $found = live_world_search_to_tool_data($direct, true);
            }

            return ['ok' => true, 'name' => $name, 'data' => $found];
        }
    } catch (Throwable $e) {
        error_log('agent_core_tool ' . $name . ': ' . $e->getMessage());

        return ['ok' => false, 'name' => $name, 'data' => null, 'error' => $e->getMessage()];
    }

    return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'unhandled'];
}

/**
 * Phase 2 mutating business tools — executed only when plan.allow_mutating_tools is set.
 *
 * @param array<string, mixed> $args
 * @param array<string, mixed> $turnCtx
 * @return array<string, mixed>
 */
function agent_core_mutating_tool(string $name, array $args, array $turnCtx): array
{
    $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
    $botId = (int) ($turnCtx['bot_id'] ?? $bot['id'] ?? 0);
    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    $userId = (int) ($bot['user_id'] ?? 0);

    try {
        if ($name === 'booking.create') {
            require_once __DIR__ . '/booking-tools.php';
            $isoStart = trim((string) ($args['iso_start'] ?? ''));
            $isoEnd = trim((string) ($args['iso_end'] ?? ''));
            if ($isoStart === '' || $isoEnd === '') {
                return ['ok' => false, 'name' => $name, 'data' => ['ok' => false, 'error' => 'missing_slot'], 'error' => 'missing_slot'];
            }
            $start = new DateTimeImmutable($isoStart);
            $end = new DateTimeImmutable($isoEnd);
            $data = booking_tool_create(
                $botId,
                $userId,
                $leadId,
                $start,
                $end,
                trim((string) ($args['name'] ?? '')) ?: null,
                trim((string) ($args['phone'] ?? '')) ?: null,
                trim((string) ($args['service'] ?? '')) ?: null
            );

            return ['ok' => !empty($data['ok']), 'name' => $name, 'data' => $data];
        }
        if ($name === 'human_handoff.create') {
            require_once __DIR__ . '/handoff-tools.php';
            $payload = is_array($args['payload'] ?? null) ? $args['payload'] : [];
            $type = trim((string) ($args['request_type'] ?? 'human'));
            $data = handoff_tool_create($botId, $leadId, $userId, $type, $payload);

            return ['ok' => !empty($data['ok']), 'name' => $name, 'data' => $data];
        }
    } catch (Throwable $e) {
        error_log('agent_core_mutating_tool ' . $name . ': ' . $e->getMessage());

        return ['ok' => false, 'name' => $name, 'data' => null, 'error' => $e->getMessage()];
    }

    return ['ok' => false, 'name' => $name, 'data' => null, 'error' => 'unhandled_mutating'];
}
