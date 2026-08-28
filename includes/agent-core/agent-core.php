<?php
/**
 * Agent Core entry — planning only. Does not ACK, debounce, Graph-send, or write RESPONSE_SENT.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/turn-context.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/conversation-context.php';
require_once __DIR__ . '/intent.php';
require_once __DIR__ . '/source.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/compose.php';

/**
 * @param array<string, mixed> $bot
 * @return array<string, mixed>
 */
function agent_core_reply(array $bot, int $leadId, string $userMessage, int $turnId = 0, string $channel = 'whatsapp'): array
{
    $ctx = agent_core_turn_context($bot, $leadId, $turnId, $channel, $userMessage);

    return agent_core_run($ctx);
}

/**
 * @param array<string, mixed> $ctx
 * @return array{ok: bool, reply: string, path: string, intent: array, plan: array, tool_results: list, retryable: bool, error: ?string}
 */
function agent_core_run(array $ctx): array
{
    $empty = [
        'ok'           => false,
        'reply'        => '',
        'path'         => 'agent_core',
        'intent'       => [],
        'plan'         => [],
        'tool_results' => [],
        'retryable'    => false,
        'error'        => null,
    ];

    try {
        $bot = is_array($ctx['bot'] ?? null) ? $ctx['bot'] : [];
        $conv = agent_core_conversation_context($ctx);
        $pack = agent_core_knowledge_pack($bot !== [] ? $bot : ['id' => (int) ($ctx['bot_id'] ?? 0)]);
        $intent = agent_core_intent($ctx, $conv);
        $source = agent_core_source_route($ctx, $conv, $intent);
        if (!empty($source['needs_web']) && ($intent['kind'] ?? '') !== 'CORRECTION') {
            $intent['needs_web'] = true;
            if (!in_array('live_web.search', $intent['tools'], true) && ($intent['kind'] ?? '') === 'LIVE_WORLD') {
                $intent['tools'] = ['live_web.search'];
            }
        }
        $plan = agent_core_plan($ctx, $conv, $intent, $source, $pack);

        $toolResults = [];
        foreach (is_array($plan['tool_calls'] ?? null) ? $plan['tool_calls'] : [] as $call) {
            $name = (string) ($call['name'] ?? '');
            $args = is_array($call['args'] ?? null) ? $call['args'] : [];
            $toolResults[] = agent_core_tool($name, $args, $ctx);
        }

        $draft = agent_core_compose($pack, $plan, $toolResults, $ctx, $conv);
        $check = agent_core_validate($draft, $ctx, $intent, $plan);
        if (empty($check['ok'])) {
            $hint = '';
            if (function_exists('conversation_validation_retry_hint')) {
                $hint = conversation_validation_retry_hint((string) ($check['reason'] ?? ''), (string) ($ctx['text'] ?? ''));
            }
            $draft = agent_core_compose($pack, $plan, $toolResults, $ctx, $conv, $hint);
            $check = agent_core_validate($draft, $ctx, $intent, $plan);
            if (empty($check['ok'])) {
                $draft = agent_core_compose_fallback($pack, $plan, $toolResults, $ctx);
            }
        }

        return [
            'ok'           => trim($draft) !== '',
            'reply'        => trim($draft) !== '' ? trim($draft) : agent_core_compose_fallback($pack, $plan, $toolResults, $ctx),
            'path'         => 'agent_core',
            'intent'       => $intent,
            'plan'         => $plan,
            'tool_results' => $toolResults,
            'retryable'    => false,
            'error'        => null,
        ];
    } catch (Throwable $e) {
        error_log('agent_core_run: ' . $e->getMessage());
        $class = agent_core_classify_error($e, $ctx);
        $empty['error'] = $e->getMessage();
        $empty['retryable'] = !empty($class['retryable']);
        $empty['reply'] = 'Got you. I\'m listening — what\'s on your mind?';
        $empty['ok'] = true;

        return $empty;
    }
}
