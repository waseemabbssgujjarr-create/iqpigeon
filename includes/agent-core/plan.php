<?php
/**
 * THINK + PLAN. tools to run are copied from intent only if they are Phase-1 read-only.
 *
 * @return array{outcome: string, answer_first: string, source: string, tool_calls: list<array{name: string, args: array<string, mixed>}>, allow_casual: bool}
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_PHASE1_TOOLS = [
    'catalog.search',
    'cart.view',
    'booking.offer',
    'memory.read',
    'live_web.search',
];

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

    $calls = [];
    $allowed = AGENT_CORE_PHASE1_TOOLS;
    foreach (is_array($intent['tools'] ?? null) ? $intent['tools'] : [] as $name) {
        $name = (string) $name;
        if (!in_array($name, $allowed, true)) {
            continue;
        }
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

    return [
        'outcome'      => $kind,
        'answer_first' => $answerFirst,
        'source'       => (string) ($source['primary'] ?? 'GENERAL_GPT'),
        'tool_calls'   => $calls,
        'allow_casual' => $casual,
    ];
}
