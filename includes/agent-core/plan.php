<?php
/**
 * THINK + PLAN. Read-only tools only. Schedules BUSINESS / GENERAL / MIXED / FOLLOW_UP sources together.
 *
 * @return array{outcome: string, answer_kind: string, answer_first: string, source: string, route: array, tool_calls: list<array{name: string, args: array<string, mixed>}>, allow_casual: bool}
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_PHASE1_TOOLS = [
    'catalog.search',
    'cart.view',
    'booking.offer',
    'memory.read',
    'live_web.search',
    'hours.read',
    'orders.read',
];

/**
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 */
function agent_core_answer_kind(array $intent, array $source): string
{
    $flags = 0;
    foreach (['needs_web', 'needs_hours', 'needs_orders', 'needs_catalog', 'needs_memory'] as $key) {
        if (!empty($source[$key])) {
            $flags++;
        }
    }
    if ($flags >= 2 || (string) ($source['primary'] ?? '') === 'MIXED') {
        return 'MIXED';
    }
    $kind = (string) ($intent['kind'] ?? 'FOLLOW_UP');
    if (!empty($source['needs_web'])) {
        return 'GENERAL';
    }
    if (!empty($source['needs_hours']) || !empty($source['needs_orders']) || !empty($source['needs_catalog'])
        || in_array($kind, ['CATALOG', 'BOOKING', 'BUSINESS_INQUIRY'], true)
    ) {
        return 'BUSINESS';
    }
    if (!empty($intent['continue_thread']) || in_array($kind, ['FOLLOW_UP', 'CORRECTION', 'CHASE_UP', 'MEDIA'], true)) {
        return 'FOLLOW_UP';
    }

    return 'GENERAL';
}

/**
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function agent_core_plan(array $turnCtx, array $conv, array $intent, array $source, array $pack): array
{
    $kind = (string) ($intent['kind'] ?? 'FOLLOW_UP');
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $answerFirst = $text !== '' ? $text : 'the customer\'s latest message';
    if ($kind === 'CORRECTION' && trim((string) ($intent['missed_thought'] ?? '')) !== '') {
        $answerFirst = trim((string) $intent['missed_thought']);
    }
    if ($kind === 'FOLLOW_UP' && trim((string) ($intent['referent'] ?? '')) !== '') {
        $answerFirst = $text . ' (referring to: ' . trim((string) $intent['referent']) . ')';
    }
    if ($kind === 'MEDIA') {
        $answerFirst = 'the photo or media they sent';
    }

    $allowed = AGENT_CORE_PHASE1_TOOLS;
    $wanted = [];
    foreach (is_array($intent['tools'] ?? null) ? $intent['tools'] : [] as $name) {
        $wanted[] = (string) $name;
    }
    if (!empty($source['needs_web'])) {
        $wanted[] = 'live_web.search';
    }
    if (!empty($source['needs_hours'])) {
        $wanted[] = 'hours.read';
    }
    if (!empty($source['needs_orders'])) {
        $wanted[] = 'orders.read';
    }
    if (!empty($source['needs_catalog'])) {
        $wanted[] = 'catalog.search';
    }
    if (!empty($source['needs_memory']) || !empty($intent['continue_thread']) || $kind === 'CORRECTION'
        || ((int) ($turnCtx['lead_id'] ?? 0) > 0 && (int) ($turnCtx['bot_id'] ?? 0) > 0)
    ) {
        $wanted[] = 'memory.read';
    }

    $calls = [];
    $seen = [];
    foreach ($wanted as $name) {
        if (!in_array($name, $allowed, true) || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $args = ['query' => $text];
        if ($name === 'catalog.search' && trim((string) ($intent['referent'] ?? '')) !== '') {
            $args['query'] = trim((string) $intent['referent']);
        }
        if ($name === 'live_web.search') {
            $args['query'] = (string) ($source['search_query'] ?? $text);
        }
        $calls[] = ['name' => $name, 'args' => $args];
    }

    $casual = in_array($kind, ['SOCIAL', 'GREETING', 'OFF_TOPIC', 'IDENTITY'], true);
    $answerKind = agent_core_answer_kind($intent, $source);

    return [
        'outcome'      => $kind,
        'answer_kind'  => $answerKind,
        'answer_first' => $answerFirst,
        'source'       => (string) ($source['primary'] ?? 'GENERAL_GPT'),
        'route'        => $source,
        'tool_calls'   => $calls,
        'allow_casual' => $casual,
        'brand'        => (string) ($pack['brand'] ?? ''),
    ];
}
