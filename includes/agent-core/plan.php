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
    'catalog.get_product',
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
    if ($flags >= 2 || (string) ($source['primary'] ?? '') === 'MIXED' || (string) ($intent['kind'] ?? '') === 'MIXED') {
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
    $productId = (int) ($intent['product_id'] ?? $turnCtx['product_id'] ?? 0);
    if ($productId <= 0 && preg_match('/(?:^|\s)#(\d+)\b/u', $text, $m)) {
        $productId = (int) $m[1];
    }
    if ($productId > 0) {
        $wanted[] = 'catalog.get_product';
    }
    $casualKind = in_array($kind, ['SOCIAL', 'GREETING', 'OFF_TOPIC', 'IDENTITY', 'GENERAL'], true);
    $wantMemory = !empty($intent['continue_thread']) || $kind === 'CORRECTION'
        || ((int) ($turnCtx['lead_id'] ?? 0) > 0 && (int) ($turnCtx['bot_id'] ?? 0) > 0)
        || (is_array($conv['runtime_facts'] ?? null) && $conv['runtime_facts'] !== []);
    if (!$casualKind && !empty($source['needs_memory'])) {
        $wantMemory = true;
    }
    if ($wantMemory) {
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
        if ($name === 'catalog.get_product') {
            $args['product_id'] = $productId;
        }
        $calls[] = ['name' => $name, 'args' => $args];
    }

    $casual = in_array($kind, ['SOCIAL', 'GREETING', 'OFF_TOPIC', 'IDENTITY', 'GENERAL'], true);
    $answerKind = agent_core_answer_kind($intent, $source);
    $available = [];
    $missing = [];
    foreach (['needs_web' => 'live_web', 'needs_hours' => 'hours', 'needs_orders' => 'orders', 'needs_catalog' => 'catalog', 'needs_memory' => 'memory'] as $flag => $label) {
        if (!empty($source[$flag])) {
            $available[] = $label;
        }
    }
    if (!empty($intent['needs_web'])) {
        $missing[] = 'live_evidence';
    }
    if (!empty($intent['clarification_needed'])) {
        $missing[] = 'referent';
    }
    if ($kind === 'MEDIA' && !empty($intent['clarification_needed'])) {
        $missing[] = 'media_description';
    }

    return [
        'outcome'               => $kind,
        'answer_kind'           => $answerKind,
        'answer_first'          => $answerFirst,
        'source'                => (string) ($source['primary'] ?? 'GENERAL_GPT'),
        'route'                 => $source,
        'tool_calls'            => $calls,
        'allow_casual'          => $casual,
        'brand'                 => (string) ($pack['brand'] ?? ''),
        'asked'                 => $text,
        'referent'              => (string) ($intent['referent'] ?? ''),
        'available'             => $available,
        'missing'               => array_values(array_unique($missing)),
        'clarification_needed'  => !empty($intent['clarification_needed']),
        'action'                => $answerKind,
    ];
}
