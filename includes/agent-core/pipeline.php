<?php
/**
 * 12-stage Agent Core pipeline. One orchestrator. Delivery is not a Core stage.
 *
 * Stages and contracts (structured decisions, not chain-of-thought):
 *  1 INPUT      in: bot, lead, turn, channel, raw text/media
 *               out: TurnContext
 *  2 UNDERSTAND in: TurnContext
 *               out: same + understanding[] (normalized media) + text
 *  3 CONTEXT    in: understood turn
 *               out: conversation context (history, referents, missed thought)
 *  4 MEMORY     in: turn + context
 *               out: customer facts (read-only)
 *  5 INTENT     in: turn + context
 *               out: one normalized intent object
 *  6 SOURCES    in: turn + context + intent
 *               out: combinable source flags (not a single exclusive choice)
 *  7 PLAN       in: all of the above + knowledge pack
 *               out: structured plan (asked, available, missing, tools)
 *  8 TOOLS      in: plan
 *               out: read-only tool results
 *  9 GENERATE   in: pack + plan + tools + ctx
 *               out: draft via conversation_mind_generate (only generation brain)
 * 10 VALIDATE   in: draft + intent + plan
 *               out: ok / reason
 * 11 HUMANIZE   in: draft
 *               out: customer-facing line (conversation_mind_guard_reply)
 * 12 DELIVERY   adapter-owned: ACK, debounce, Graph, persist, RESPONSE_SENT
 */
declare(strict_types=1);

/**
 * @return list<string>
 */
function agent_core_stage_names(): array
{
    return [
        'INPUT',
        'UNDERSTAND',
        'CONTEXT',
        'MEMORY',
        'INTENT',
        'SOURCES',
        'PLAN',
        'TOOLS',
        'GENERATE',
        'VALIDATE',
        'HUMANIZE',
        'DELIVERY',
    ];
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function agent_core_pipeline(array $ctx): array
{
    $fail = static function (
        ?string $error = null,
        bool $retryable = false,
        array $intent = [],
        array $plan = [],
        array $toolResults = []
    ): array {
        return [
            'ok'           => false,
            'reply'        => '',
            'path'         => 'agent_core',
            'intent'       => $intent,
            'plan'         => $plan,
            'tool_results' => $toolResults,
            'retryable'    => $retryable,
            'error'        => $error,
            'stage'        => 'FAIL',
        ];
    };

    if (!empty($GLOBALS['agent_core_test_throw'])) {
        throw new RuntimeException('test_forced_core_failure');
    }

    $turn = agent_core_stage_input($ctx);
    $turn = agent_core_stage_understand($turn);
    $conv = agent_core_stage_context($turn);
    $conv = agent_core_stage_memory($turn, $conv);
    $pack = agent_core_stage_knowledge($turn);
    $intent = agent_core_stage_intent($turn, $conv);
    $source = agent_core_stage_sources($turn, $conv, $intent);
    if ((string) ($source['primary'] ?? '') === 'MIXED' && ($intent['kind'] ?? '') !== 'CORRECTION') {
        $intent['kind'] = 'MIXED';
        if (($intent['override'] ?? '') === '') {
            $intent['override'] = 'MIXED';
        }
    }
    if (!empty($source['needs_web']) && ($intent['kind'] ?? '') !== 'CORRECTION') {
        $intent['needs_web'] = true;
        $tools = is_array($intent['tools'] ?? null) ? $intent['tools'] : [];
        if (in_array((string) ($intent['kind'] ?? ''), ['LIVE_WORLD', 'MIXED'], true)
            && !in_array('live_web.search', $tools, true)
        ) {
            $tools[] = 'live_web.search';
            $intent['tools'] = $tools;
        }
    }
    $plan = agent_core_stage_plan($turn, $conv, $intent, $source, $pack);
    $toolResults = agent_core_stage_tools($plan, $turn, $conv);
    $draft = trim(agent_core_stage_generate($pack, $plan, $toolResults, $turn, $conv));
    if ($draft === '') {
        return $fail('empty_compose', false, $intent, $plan, $toolResults);
    }
    $check = agent_core_stage_validate($draft, $turn, $intent, $plan);
    if (empty($check['ok'])) {
        $hint = '';
        if (function_exists('conversation_validation_retry_hint')) {
            $hint = conversation_validation_retry_hint((string) ($check['reason'] ?? ''), (string) ($turn['text'] ?? ''));
        }
        $draft = trim(agent_core_stage_generate($pack, $plan, $toolResults, $turn, $conv, $hint));
        if ($draft === '') {
            return $fail('empty_compose', false, $intent, $plan, $toolResults);
        }
        $check = agent_core_stage_validate($draft, $turn, $intent, $plan);
        if (empty($check['ok'])) {
            return $fail('validation_failed', false, $intent, $plan, $toolResults);
        }
    }
    $draft = trim(agent_core_stage_humanize($draft, $pack, $plan, $turn, $conv, $toolResults));
    if ($draft === '') {
        return $fail('empty_humanize', false, $intent, $plan, $toolResults);
    }
    $check = agent_core_stage_validate($draft, $turn, $intent, $plan);
    if (empty($check['ok'])) {
        return $fail('validation_failed', false, $intent, $plan, $toolResults);
    }

    return [
        'ok'           => true,
        'reply'        => $draft,
        'path'         => 'agent_core',
        'intent'       => $intent,
        'plan'         => $plan,
        'tool_results' => $toolResults,
        'retryable'    => false,
        'error'        => null,
        'stage'        => 'HUMANIZE',
    ];
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function agent_core_stage_input(array $ctx): array
{
    if (isset($ctx['bot'], $ctx['text'])) {
        $ctx['channel'] = (string) ($ctx['channel'] ?? 'whatsapp');
        $ctx['media'] = is_array($ctx['media'] ?? null) ? $ctx['media'] : [];
        $ctx['profile'] = is_array($ctx['profile'] ?? null)
            ? $ctx['profile']
            : agent_core_business_profile(is_array($ctx['bot'] ?? null) ? $ctx['bot'] : []);

        return $ctx;
    }
    $bot = is_array($ctx['bot'] ?? null) ? $ctx['bot'] : [];

    return agent_core_turn_context(
        $bot,
        (int) ($ctx['lead_id'] ?? 0),
        (int) ($ctx['turn_id'] ?? 0),
        (string) ($ctx['channel'] ?? 'whatsapp'),
        (string) ($ctx['text'] ?? $ctx['user_message'] ?? ''),
        is_array($ctx['media'] ?? null) ? $ctx['media'] : []
    );
}

/**
 * @param array<string, mixed> $turn
 * @return array<string, mixed>
 */
function agent_core_stage_understand(array $turn): array
{
    $understood = agent_core_understand_media($turn);
    $turn['understanding'] = $understood;
    $merged = trim((string) ($turn['text'] ?? ''));
    foreach ($understood as $item) {
        $bit = trim((string) ($item['text'] ?? ''));
        if ($bit !== '' && $merged === '') {
            $merged = $bit;
        }
        $desc = trim((string) ($item['image_description'] ?? ''));
        if ($desc !== '' && $merged === '') {
            $merged = $desc;
        }
        $extracted = trim((string) ($item['extracted_content'] ?? ''));
        if ($extracted !== '' && $merged === '') {
            $merged = $extracted;
        }
    }
    if ($merged !== '' && trim((string) ($turn['text'] ?? '')) === '') {
        $turn['text'] = $merged;
    }

    return $turn;
}

/**
 * @param array<string, mixed> $turn
 * @return array<string, mixed>
 */
function agent_core_stage_context(array $turn): array
{
    if (!empty($GLOBALS['agent_core_no_network'])) {
        return [
            'history'         => is_array($turn['history'] ?? null) ? $turn['history'] : [],
            'last_assistant'  => (string) ($turn['last_assistant'] ?? ''),
            'last_user'       => (string) ($turn['last_user'] ?? ''),
            'referents'       => is_array($turn['referents'] ?? null) ? $turn['referents'] : ['product' => ''],
            'missed_thought'  => (string) ($turn['missed_thought'] ?? ''),
            'runtime_facts'   => is_array($turn['runtime_facts'] ?? null) ? $turn['runtime_facts'] : [],
            'mind_mode'       => 'FOLLOW_UP',
            'personal_facts'  => [],
            'business_facts'  => [],
            'summary'         => '',
        ];
    }

    return agent_core_conversation_context($turn);
}

/**
 * Read-only memory into context. No memory.write.
 *
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_core_stage_memory(array $turn, array $conv): array
{
    $facts = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    $botId = (int) ($turn['bot_id'] ?? 0);
    $leadId = (int) ($turn['lead_id'] ?? 0);
    if ($facts === [] && $botId > 0 && $leadId > 0 && empty($GLOBALS['agent_core_no_network'])) {
        $facts = agent_core_memory_read($botId, $leadId, (string) ($turn['text'] ?? ''));
    }
    $conv['runtime_facts'] = is_array($facts) ? $facts : [];

    return $conv;
}

/**
 * @param array<string, mixed> $turn
 * @return array<string, mixed>
 */
function agent_core_stage_knowledge(array $turn): array
{
    if (!empty($GLOBALS['agent_core_no_network'])) {
        return [
            'prompt'         => '',
            'rep'            => (string) (($turn['profile']['rep'] ?? 'I')),
            'brand'          => (string) (($turn['profile']['brand'] ?? 'us')),
            'capabilities'   => is_array($turn['profile']['capabilities'] ?? null) ? $turn['profile']['capabilities'] : [],
            'qualify_read'   => '',
            'business_facts' => [],
        ];
    }
    $bot = is_array($turn['bot'] ?? null) ? $turn['bot'] : [];

    return agent_core_knowledge_pack($bot !== [] ? $bot : ['id' => (int) ($turn['bot_id'] ?? 0)]);
}

/**
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_core_stage_intent(array $turn, array $conv): array
{
    return agent_core_intent($turn, $conv);
}

/**
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @return array<string, mixed>
 */
function agent_core_stage_sources(array $turn, array $conv, array $intent): array
{
    return agent_core_source_route($turn, $conv, $intent);
}

/**
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function agent_core_stage_plan(array $turn, array $conv, array $intent, array $source, array $pack): array
{
    return agent_core_plan($turn, $conv, $intent, $source, $pack);
}

/**
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @return list<array<string, mixed>>
 */
function agent_core_stage_tools(array $plan, array $turn, array $conv): array
{
    $results = [];
    $thread = '';
    foreach (is_array($conv['history'] ?? null) ? $conv['history'] : [] as $row) {
        $thread .= ' ' . (string) ($row['message'] ?? '');
    }
    $turn['thread'] = trim($thread);
    foreach (is_array($plan['tool_calls'] ?? null) ? $plan['tool_calls'] : [] as $call) {
        $name = (string) ($call['name'] ?? '');
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];
        if ($name === 'live_web.search' && trim((string) ($args['thread'] ?? '')) === '' && $turn['thread'] !== '') {
            $args['thread'] = $turn['thread'];
        }
        $results[] = agent_core_tool($name, $args, $turn);
    }

    return $results;
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 */
function agent_core_stage_generate(array $pack, array $plan, array $toolResults, array $turn, array $conv, string $retryHint = ''): string
{
    return agent_core_compose($pack, $plan, $toolResults, $turn, $conv, $retryHint);
}

/**
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $plan
 * @return array{ok: bool, reason?: string}
 */
function agent_core_stage_validate(string $draft, array $turn, array $intent, array $plan): array
{
    return agent_core_validate($draft, $turn, $intent, $plan);
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 */
function agent_core_stage_humanize(string $draft, array $pack, array $plan, array $turn, array $conv, array $toolResults = []): string
{
    $draft = trim($draft);
    if ($draft === '' || !empty($GLOBALS['agent_core_no_network'])) {
        return $draft;
    }
    $bot = is_array($turn['bot'] ?? null) ? $turn['bot'] : [];
    $leadId = (int) ($turn['lead_id'] ?? 0);
    $userMessage = (string) ($turn['text'] ?? '');
    if (!function_exists('conversation_mind_guard_reply')) {
        return $draft;
    }
    try {
        $mindCtx = agent_core_mind_ctx_from_plan($pack, $plan, $toolResults, $turn, $conv);

        return trim(conversation_mind_guard_reply($bot, $leadId, $userMessage, $draft, $mindCtx));
    } catch (Throwable $e) {
        error_log('agent_core_stage_humanize: ' . $e->getMessage());

        return $draft;
    }
}
