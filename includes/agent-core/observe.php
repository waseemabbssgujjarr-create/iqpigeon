<?php
/**
 * Core stage events via conversation_turn_events. Never throws. Never logs secrets or full messages.
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_OBSERVE_DROP_KEYS = [
    'message', 'text', 'user_message', 'prompt', 'system', 'draft', 'reply', 'raw',
    'token', 'access_token', 'secret', 'password', 'authorization', 'api_key',
    'openai', 'whatsapp_token', 'body', 'payload', 'customer', 'content',
];

/**
 * Diagnostic field names that would otherwise match DROP_KEYS by substring
 * (e.g. "token" in has_temperature_token, "openai" in openai_call_ok).
 *
 * @var list<string>
 */
const AGENT_CORE_OBSERVE_KEEP_KEYS = [
    'has_temperature_token',
    'openai_call_ok',
    'openai_call_empty',
];

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_observe_begin(array $ctx): void
{
    $bot = is_array($ctx['bot'] ?? null) ? $ctx['bot'] : [];
    $GLOBALS['agent_core_observe'] = [
        't0'       => microtime(true),
        'turn_id'  => (int) ($ctx['turn_id'] ?? 0),
        'lead_id'  => (int) ($ctx['lead_id'] ?? 0),
        'bot_id'   => (int) ($ctx['bot_id'] ?? $bot['id'] ?? 0),
        'channel'  => (string) ($ctx['channel'] ?? 'whatsapp'),
        'fallback' => false,
    ];
    agent_core_observe('CORE_START', ['ok' => true]);
}

function agent_core_observe_elapsed_ms(): int
{
    $t0 = (float) ($GLOBALS['agent_core_observe']['t0'] ?? microtime(true));

    return (int) round((microtime(true) - $t0) * 1000);
}

/**
 * @param array<string, mixed> $detail
 * @return array<string, mixed>
 */
function agent_core_observe_sanitize(array $detail): array
{
    $out = [];
    foreach ($detail as $key => $value) {
        $k = strtolower((string) $key);
        if ($k === 'evidence') {
            continue;
        }
        $keep = in_array($k, AGENT_CORE_OBSERVE_KEEP_KEYS, true);
        $drop = false;
        if (!$keep) {
            foreach (AGENT_CORE_OBSERVE_DROP_KEYS as $bad) {
                if ($k === $bad || str_contains($k, $bad)) {
                    $drop = true;
                    break;
                }
            }
        }
        if ($drop) {
            continue;
        }
        if (is_array($value)) {
            $out[$key] = agent_core_observe_sanitize($value);
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            $out[$key] = $value;
            continue;
        }
        $s = (string) $value;
        if (preg_match('/EAA[A-Za-z0-9]{8,}/', $s) || preg_match('/sk-[A-Za-z0-9]{8,}/', $s)) {
            continue;
        }
        $out[$key] = mb_substr($s, 0, 120);
    }

    return $out;
}

/**
 * @param array<string, mixed> $detail
 */
function agent_core_observe(string $event, array $detail = []): void
{
    try {
        if (!empty($GLOBALS['agent_core_observe_throw'])) {
            throw new RuntimeException('observe_test_throw');
        }
        $obs = is_array($GLOBALS['agent_core_observe'] ?? null) ? $GLOBALS['agent_core_observe'] : [];
        $payload = array_merge([
            'turn_id'    => (int) ($obs['turn_id'] ?? 0),
            'lead_id'    => (int) ($obs['lead_id'] ?? 0),
            'bot_id'     => (int) ($obs['bot_id'] ?? 0),
            'channel'    => (string) ($obs['channel'] ?? 'whatsapp'),
            'stage'      => $event,
            'elapsed_ms' => agent_core_observe_elapsed_ms(),
        ], $detail);
        $payload = agent_core_observe_sanitize($payload);
        if (array_key_exists('agent_core_event_sink', $GLOBALS) && is_array($GLOBALS['agent_core_event_sink'])) {
            $GLOBALS['agent_core_event_sink'][] = ['event' => $event, 'detail' => $payload];
        }
        $turnId = (int) ($payload['turn_id'] ?? 0);
        if ($turnId > 0 && function_exists('turn_engine_log_event')) {
            turn_engine_log_event($turnId, $event, $payload);
        }
    } catch (Throwable $e) {
        error_log('agent_core_observe: ' . $e->getMessage());
    }
}

function agent_core_map_fail_reason(?string $error): string
{
    $error = trim((string) $error);
    return match ($error) {
        'empty_compose', 'empty_humanize' => 'empty_generate',
        'validation_failed' => 'validation_failed',
        'missing_context' => 'missing_context',
        'missing_source' => 'missing_source',
        'missing_plan' => 'missing_plan',
        'tool_failure' => 'tool_failure',
        'disabled' => 'disabled',
        'not_allowlisted' => 'inactive',
        'inactive'        => 'inactive',
        'exception', 'test_forced_core_failure' => 'exception',
        '' => 'unknown',
        default => str_contains(mb_strtolower($error), 'exception')
            || str_contains(mb_strtolower($error), 'timeout')
            ? 'exception'
            : 'unknown',
    };
}

/**
 * @param array<string, mixed> $core
 */
function agent_core_fallback_reason(array $core): string
{
    if (!empty($core['fallback_reason'])) {
        return (string) $core['fallback_reason'];
    }
    $path = (string) ($core['path'] ?? '');
    if ($path === 'core_off') {
        if (!defined('AGENT_CORE_ENABLED') || !AGENT_CORE_ENABLED) {
            return 'disabled';
        }

        return 'inactive';
    }
    if ($path === 'core_error') {
        return 'exception';
    }

    return agent_core_map_fail_reason(isset($core['error']) ? (string) $core['error'] : null);
}

/**
 * @param array<string, mixed> $intent
 * @return array<string, mixed>
 */
function agent_core_observe_intent_bits(array $intent): array
{
    $tools = [];
    foreach (is_array($intent['tools'] ?? null) ? $intent['tools'] : [] as $name) {
        $tools[] = (string) $name;
    }

    return [
        'intent_kind' => (string) ($intent['kind'] ?? ''),
        'mind'        => (string) ($intent['mind'] ?? ''),
        'override'    => (string) ($intent['override'] ?? ''),
        'tools'       => $tools,
    ];
}

function agent_core_intent_is_live_world(array $intent): bool
{
    return (string) ($intent['kind'] ?? '') === 'LIVE_WORLD'
        || (string) ($intent['override'] ?? '') === 'LIVE_WORLD';
}

/**
 * @param list<array<string, mixed>> $toolResults
 */
function agent_core_live_evidence_present(array $toolResults): bool
{
    foreach ($toolResults as $row) {
        if ((string) ($row['name'] ?? '') !== 'live_web.search') {
            continue;
        }
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        if (agent_core_live_search_data_usable($data)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $data
 */
function agent_core_live_search_data_usable(array $data): bool
{
    if (function_exists('live_world_tool_data_usable')) {
        return live_world_tool_data_usable($data);
    }

    return !empty($data['ok'])
        && !empty($data['evidence_usable'])
        && trim((string) ($data['evidence'] ?? '')) !== ''
        && empty($data['looks_like_refusal']);
}

/**
 * Sanitized LIVE_WORLD metadata only — never the evidence string.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function agent_core_live_observe_bits(array $data): array
{
    $usable = agent_core_live_search_data_usable($data);

    return [
        'evidence_chars'         => (int) ($data['evidence_chars'] ?? 0),
        'has_web_search_call'    => !empty($data['has_web_search_call']),
        'web_search_call_status' => mb_substr((string) ($data['web_search_call_status'] ?? ''), 0, 40),
        'looks_like_refusal'     => !empty($data['looks_like_refusal']),
        'evidence_usable'        => $usable,
        'evidence_present'       => $usable,
    ];
}

/**
 * @param list<array<string, mixed>> $toolResults
 */
function agent_core_tools_hard_failed(array $toolResults): bool
{
    foreach ($toolResults as $row) {
        if (empty($row['ok'])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 */
function agent_core_tool_row_failed(array $row): bool
{
    if (empty($row['ok'])) {
        return true;
    }
    if ((string) ($row['name'] ?? '') !== 'live_web.search') {
        return false;
    }
    $data = is_array($row['data'] ?? null) ? $row['data'] : [];
    if (empty($data['needed'])) {
        return false;
    }

    return !agent_core_live_search_data_usable($data);
}
