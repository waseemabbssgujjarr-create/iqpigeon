<?php
/**
 * Phase 3.6 — read-only production evidence helpers (no network, no DB writes).
 * Parses existing wa-az-audit.php?part=events (n_events) shapes and normalizes evidence records.
 */
declare(strict_types=1);

/** @return list<string> Mirrors api/wa-az-audit.php az_watched_core_events() — keep in sync manually. */
function phase36_watched_core_event_names(): array
{
    return [
        'CORE_START',
        'CORE_CONTEXT',
        'CORE_INTENT',
        'CORE_SOURCE',
        'CORE_PLAN',
        'CORE_TOOLS',
        'CORE_GENERATE',
        'CORE_VALIDATE',
        'CORE_COMPLETE',
        'CORE_FALLBACK',
        'CORE_TOOL_START',
        'CORE_TOOL_COMPLETE',
        'CORE_TOOL_FAIL',
        'LIVE_WORLD_DETECTED',
        'LIVE_WORLD_TOOL_SELECTED',
        'LIVE_WORLD_TOOL_START',
        'LIVE_WORLD_TOOL_COMPLETE',
        'LIVE_WORLD_TOOL_FAILED',
        'LIVE_WORLD_EVIDENCE_PRESENT',
        'LIVE_WORLD_GENERATE',
        'LIVE_ANSWER_START',
        'LIVE_ANSWER_COMPLETE',
        'LIVE_ANSWER_FALLBACK',
        'RESPONSE_SENT',
        'PROCESSING_TO_RESPONSE',
    ];
}

/**
 * @param array<string, mixed> $nEvents wa-az-audit report['n_events']
 * @return list<array{event_type: string, created_at: ?string, detail_json: ?array}>
 */
function phase36_parse_audit_events(array $nEvents): array
{
    $out = [];
    foreach (is_array($nEvents['events'] ?? null) ? $nEvents['events'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = trim((string) ($row['event_type'] ?? ''));
        if ($type === '') {
            continue;
        }
        $detail = $row['detail_json'] ?? null;
        $out[] = [
            'event_type'  => $type,
            'created_at'  => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'detail_json' => is_array($detail) ? $detail : null,
        ];
    }

    return $out;
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase36_event_types_present(array $events): array
{
    $types = [];
    foreach ($events as $ev) {
        $types[$ev['event_type']] = true;
    }

    return array_keys($types);
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase36_has_event(array $events, string $type): bool
{
    foreach ($events as $ev) {
        if ($ev['event_type'] === $type) {
            return true;
        }
    }

    return false;
}

/**
 * Classify observed path from stored events only (NOT_VERIFIED if ambiguous).
 *
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 */
function phase36_classify_path_from_events(array $events): string
{
    if ($events === []) {
        return 'NOT_VERIFIED';
    }
    $hasStart = phase36_has_event($events, 'CORE_START');
    $hasComplete = phase36_has_event($events, 'CORE_COMPLETE');
    $hasFallback = phase36_has_event($events, 'CORE_FALLBACK');

    if ($hasFallback) {
        return 'fallback';
    }
    if ($hasStart && $hasComplete) {
        return 'agent_core';
    }
    if ($hasStart) {
        return 'agent_core_incomplete';
    }
    if (phase36_has_event($events, 'RESPONSE_SENT') || phase36_has_event($events, 'PROCESSING_TO_RESPONSE')) {
        return 'webhook_mind_or_unknown';
    }

    return 'NOT_VERIFIED';
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 */
function phase36_extract_fallback_reason(array $events): ?string
{
    foreach ($events as $ev) {
        if ($ev['event_type'] !== 'CORE_FALLBACK') {
            continue;
        }
        $detail = $ev['detail_json'] ?? [];
        if (is_array($detail) && isset($detail['fallback_reason']) && $detail['fallback_reason'] !== '') {
            return (string) $detail['fallback_reason'];
        }
    }

    return null;
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 */
function phase36_extract_turn_meta(array $events): array
{
    foreach ($events as $ev) {
        $d = $ev['detail_json'];
        if (!is_array($d)) {
            continue;
        }
        $turnId = (int) ($d['turn_id'] ?? 0);
        $botId = (int) ($d['bot_id'] ?? 0);
        $channel = trim((string) ($d['channel'] ?? ''));
        if ($turnId > 0 || $botId > 0 || $channel !== '') {
            return [
                'turn_id' => $turnId > 0 ? $turnId : null,
                'bot_id'  => $botId > 0 ? $botId : null,
                'channel' => $channel !== '' ? $channel : null,
            ];
        }
    }

    return ['turn_id' => null, 'bot_id' => null, 'channel' => null];
}

/**
 * @param array<string, mixed> $nEvents
 * @param array<string, mixed> $meta scenario_id, notes, conversation_id, etc.
 * @return array<string, mixed>
 */
function phase36_build_evidence_from_audit(array $nEvents, array $meta = []): array
{
    $events = phase36_parse_audit_events($nEvents);
    $path = phase36_classify_path_from_events($events);
    $fallbackReason = phase36_extract_fallback_reason($events);
    $fromDetail = phase36_extract_turn_meta($events);
    $turnId = $meta['turn_id'] ?? ($nEvents['turn_id'] ?? $fromDetail['turn_id']);
    $botId = $meta['bot_id'] ?? $fromDetail['bot_id'];
    $channel = $meta['channel'] ?? $fromDetail['channel'] ?? 'whatsapp';

    return [
        'scenario_id'      => (string) ($meta['scenario_id'] ?? ''),
        'timestamp'        => (string) ($meta['timestamp'] ?? date('c')),
        'channel'          => (string) $channel,
        'bot_id'           => $botId !== null ? (int) $botId : null,
        'turn_id'          => $turnId !== null ? (int) $turnId : null,
        'conversation_id'  => isset($meta['conversation_id']) ? (string) $meta['conversation_id'] : null,
        'path'             => $path,
        'fallback'         => $path === 'fallback' || $fallbackReason !== null,
        'fallback_reason'  => $fallbackReason,
        'events'           => array_map(static fn ($e) => $e['event_type'], $events),
        'stages'           => phase36_stages_from_events($events),
        'duration_ms'      => phase36_max_elapsed_ms($events),
        'audit_status'     => (string) ($nEvents['status'] ?? 'NOT_AVAILABLE'),
        'audit_ok'         => (bool) ($nEvents['ok'] ?? false),
        'watched_missing'  => is_array($nEvents['watched_missing'] ?? null) ? $nEvents['watched_missing'] : [],
        'result'           => (string) ($meta['result'] ?? 'NOT_RUN'),
        'notes'            => (string) ($meta['notes'] ?? ''),
    ];
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 * @return list<string>
 */
function phase36_stages_from_events(array $events): array
{
    $core = [];
    foreach ($events as $ev) {
        if (str_starts_with($ev['event_type'], 'CORE_') || str_starts_with($ev['event_type'], 'LIVE_')) {
            $core[] = $ev['event_type'];
        }
    }

    return $core;
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase36_max_elapsed_ms(array $events): ?int
{
    $max = null;
    foreach ($events as $ev) {
        $d = $ev['detail_json'];
        if (!is_array($d) || !isset($d['elapsed_ms'])) {
            continue;
        }
        $ms = (int) $d['elapsed_ms'];
        if ($max === null || $ms > $max) {
            $max = $ms;
        }
    }

    return $max;
}

/** @return list<string> */
function phase36_evidence_required_fields(): array
{
    return [
        'scenario_id',
        'timestamp',
        'channel',
        'bot_id',
        'turn_id',
        'path',
        'fallback',
        'result',
    ];
}

/** @return list<string> */
function phase36_evidence_result_values(): array
{
    return ['PASS', 'FAIL', 'BLOCKED', 'NOT_RUN'];
}

/**
 * @param array<string, mixed> $record
 * @return list<string> validation errors (empty = ok)
 */
function phase36_validate_evidence_record(array $record): array
{
    $errors = [];
    foreach (phase36_evidence_required_fields() as $field) {
        if (!array_key_exists($field, $record)) {
            $errors[] = 'missing field: ' . $field;
        }
    }
    $result = (string) ($record['result'] ?? '');
    if ($result !== '' && !in_array($result, phase36_evidence_result_values(), true)) {
        $errors[] = 'invalid result: ' . $result;
    }
    $path = (string) ($record['path'] ?? '');
    $allowedPaths = ['agent_core', 'fallback', 'agent_core_incomplete', 'webhook_mind_or_unknown', 'NOT_VERIFIED', 'EXPECTED'];
    if ($path !== '' && !in_array($path, $allowedPaths, true)) {
        $errors[] = 'unknown path classification: ' . $path;
    }

    return $errors;
}

/**
 * @param array<string, mixed> $scenario row from phase36-scenarios.json
 * @return list<string>
 */
function phase36_validate_scenario_definition(array $scenario): array
{
    $errors = [];
    $required = ['scenario_id', 'dimension', 'trigger_message', 'expected_path', 'pass_criteria'];
    foreach ($required as $key) {
        if (!isset($scenario[$key]) || (string) $scenario[$key] === '') {
            $errors[] = 'scenario missing ' . $key;
        }
    }
    if (!preg_match('/^P36-\d{3}$/', (string) ($scenario['scenario_id'] ?? ''))) {
        $errors[] = 'scenario_id must match P36-NNN';
    }

    return $errors;
}

/**
 * @param array<string, mixed> $nEvents
 * @return list<string>
 */
function phase36_validate_audit_n_events_shape(array $nEvents): array
{
    $errors = [];
    if (!array_key_exists('select_only', $nEvents) || $nEvents['select_only'] !== true) {
        $errors[] = 'n_events.select_only must be true';
    }
    if (!array_key_exists('events', $nEvents) || !is_array($nEvents['events'])) {
        $errors[] = 'n_events.events must be array';
    }
    if (!array_key_exists('turn_id', $nEvents)) {
        $errors[] = 'n_events.turn_id missing';
    }

    return $errors;
}
