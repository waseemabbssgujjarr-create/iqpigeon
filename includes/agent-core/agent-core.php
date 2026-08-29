<?php
/**
 * Agent Core entry — 12-stage pipeline, then existing delivery adapters.
 * Does not ACK, debounce, Graph-send, or write RESPONSE_SENT.
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
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/channel.php';

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
    agent_core_observe_begin($ctx);
    try {
        $out = agent_core_pipeline($ctx);
        if (agent_core_result_usable($out)) {
            agent_core_observe('CORE_COMPLETE', [
                'ok'              => true,
                'fallback_reason' => null,
                'intent_kind'     => (string) ($out['intent']['kind'] ?? ''),
                'override'        => (string) ($out['intent']['override'] ?? ''),
            ]);
        }

        return $out;
    } catch (Throwable $e) {
        error_log('agent_core_run: ' . $e->getMessage());
        $class = agent_core_classify_error($e, $ctx);
        $fail = [
            'ok'              => false,
            'reply'           => '',
            'path'            => 'agent_core',
            'intent'          => [],
            'plan'            => [],
            'tool_results'    => [],
            'retryable'       => !empty($class['retryable']),
            'error'           => $e->getMessage(),
            'stage'           => 'FAIL',
            'fallback_reason' => 'exception',
        ];
        agent_core_observe('CORE_FALLBACK', [
            'ok'              => false,
            'fallback_reason' => 'exception',
        ]);

        return $fail;
    }
}
