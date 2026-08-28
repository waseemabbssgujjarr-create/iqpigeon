<?php
/**
 * Source router — wraps conversation_source_route and adds live-world commodities
 * (petrol/fuel) so restaurant catalogs are not selected.
 *
 * @return array{primary: string, needs_web: bool, needs_orders: bool, needs_hours: bool, needs_catalog: bool, needs_memory: bool, search_query: string}
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @return array<string, mixed>
 */
function agent_core_source_route(array $turnCtx, array $conv, array $intent): array
{
    $message = (string) ($turnCtx['text'] ?? '');
    $thread = '';
    foreach (is_array($conv['history'] ?? null) ? $conv['history'] : [] as $row) {
        $thread .= ' ' . (string) ($row['message'] ?? '');
    }
    $thread = trim($thread);

    $out = [
        'primary'       => 'GENERAL_GPT',
        'needs_web'     => false,
        'needs_orders'  => false,
        'needs_hours'   => false,
        'needs_catalog' => false,
        'needs_memory'  => false,
        'search_query'  => '',
    ];

    require_once dirname(__DIR__) . '/conversation-source-router.php';
    if (function_exists('conversation_source_route')) {
        $out = array_merge($out, conversation_source_route($message, $thread));
    }

    $msg = mb_strtolower($message);
    if (agent_core_looks_like_live_world($msg) && !agent_core_looks_like_business_catalog($msg)) {
        $out['primary'] = 'LIVE_WEB';
        $out['needs_web'] = true;
        $out['needs_catalog'] = false;
        $out['search_query'] = $out['search_query'] !== '' ? $out['search_query'] : mb_substr(trim($message), 0, 180);
    }

    if (($intent['kind'] ?? '') === 'CORRECTION') {
        $out['primary'] = 'CONVERSATION_MEMORY';
        $out['needs_web'] = false;
        $out['needs_catalog'] = false;
        $out['needs_memory'] = true;
    }

    return $out;
}
