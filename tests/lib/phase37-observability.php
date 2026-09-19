<?php
/**
 * Phase 3.7 — read-only production observability normalization and aggregation.
 * Consumes wa-az-audit n_events / Phase 3.6 evidence shapes. No DB, no network, no inference.
 */
declare(strict_types=1);

require_once __DIR__ . '/phase36-evidence.php';

/** @return array<string, string> event_type => canonical stage label */
function phase37_event_stage_map(): array
{
    return [
        'CORE_START'          => 'INPUT',
        'PROCESSING_TO_RESPONSE' => 'INPUT',
        'CORE_CONTEXT'        => 'CONTEXT',
        'CORE_INTELLIGENCE'   => 'UNDERSTAND',
        'CORE_INTENT'         => 'INTENT',
        'CORE_SOURCE'         => 'SOURCES',
        'CORE_PLAN'           => 'PLAN',
        'CORE_TOOLS'          => 'TOOLS',
        'CORE_TOOL_START'     => 'TOOLS',
        'CORE_TOOL_COMPLETE'  => 'TOOLS',
        'CORE_TOOL_FAIL'      => 'TOOLS',
        'LIVE_WORLD_DETECTED' => 'INTENT',
        'LIVE_WORLD_TOOL_SELECTED' => 'TOOLS',
        'LIVE_WORLD_TOOL_START' => 'TOOLS',
        'LIVE_WORLD_TOOL_COMPLETE' => 'TOOLS',
        'LIVE_WORLD_TOOL_FAILED' => 'TOOLS',
        'LIVE_WORLD_EVIDENCE_PRESENT' => 'TOOLS',
        'LIVE_WORLD_GENERATE' => 'GENERATE',
        'LIVE_ANSWER_START'   => 'GENERATE',
        'LIVE_ANSWER_COMPLETE' => 'GENERATE',
        'LIVE_ANSWER_FALLBACK' => 'GENERATE',
        'CORE_GENERATE'       => 'GENERATE',
        'CORE_VALIDATE'       => 'VALIDATE',
        'CORE_COMPLETE'       => 'COMPLETE',
        'CORE_DECISION'       => 'COMPLETE',
        'CORE_FALLBACK'       => 'FALLBACK',
        'RESPONSE_SENT'       => 'DELIVERY',
    ];
}

/** @return list<string> */
function phase37_canonical_stages(): array
{
    return ['INPUT', 'CONTEXT', 'UNDERSTAND', 'INTENT', 'SOURCES', 'PLAN', 'TOOLS', 'GENERATE', 'VALIDATE', 'COMPLETE', 'DELIVERY', 'FALLBACK'];
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 */
function phase37_observed_stages(array $events): array
{
    $map = phase37_event_stage_map();
    $seen = [];
    foreach ($events as $ev) {
        $stage = $map[$ev['event_type']] ?? null;
        if ($stage !== null) {
            $seen[$stage] = true;
        }
    }

    return array_keys($seen);
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 * @return array<string, string> stage => OBSERVED|NOT_OBSERVED
 */
function phase37_stage_presence_report(array $events): array
{
    $observed = array_fill_keys(phase37_observed_stages($events), 'OBSERVED');
    $out = [];
    foreach (phase37_canonical_stages() as $stage) {
        if ($stage === 'FALLBACK') {
            continue;
        }
        $out[$stage] = isset($observed[$stage]) ? 'OBSERVED' : 'NOT_OBSERVED';
    }

    return $out;
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 */
function phase37_path_from_events(array $events): string
{
    $p36 = phase36_classify_path_from_events($events);

    return match ($p36) {
        'agent_core' => 'agent_core',
        'fallback' => 'fallback',
        default => 'unknown',
    };
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 */
function phase37_latest_event_detail(array $events, string $eventType): ?array
{
    $found = null;
    foreach ($events as $ev) {
        if ($ev['event_type'] === $eventType && is_array($ev['detail_json'])) {
            $found = $ev['detail_json'];
        }
    }

    return $found;
}

/**
 * Merge decision/intelligence fields from last relevant event detail (extraction only).
 *
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 * @return array<string, mixed>
 */
function phase37_extract_intelligence(array $events): array
{
    foreach (['CORE_COMPLETE', 'CORE_DECISION', 'CORE_PLAN', 'CORE_INTELLIGENCE'] as $type) {
        $d = phase37_latest_event_detail($events, $type);
        if ($d === null) {
            continue;
        }

        return [
            'intent'             => ($d['primary_intent'] ?? '') !== '' ? (string) $d['primary_intent'] : null,
            'emotion'            => ($d['emotion'] ?? '') !== '' ? (string) $d['emotion'] : null,
            'readiness'          => ($d['readiness'] ?? '') !== '' ? (string) $d['readiness'] : null,
            'nba'                => ($d['next_best_action'] ?? '') !== '' ? (string) $d['next_best_action'] : null,
            'missing_information' => is_array($d['missing_info'] ?? null) ? $d['missing_info'] : null,
            'confidence'         => isset($d['action_confidence']) ? (float) $d['action_confidence'] : null,
        ];
    }

    return [
        'intent' => null, 'emotion' => null, 'readiness' => null, 'nba' => null,
        'missing_information' => null, 'confidence' => null,
    ];
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase37_extract_decision(array $events): array
{
    foreach (['CORE_DECISION', 'CORE_COMPLETE', 'CORE_PLAN'] as $type) {
        $d = phase37_latest_event_detail($events, $type);
        if ($d === null) {
            continue;
        }

        return [
            'action'        => ($d['selected_action'] ?? '') !== '' ? (string) $d['selected_action'] : null,
            'cta_mode'      => ($d['cta_mode'] ?? '') !== '' ? (string) $d['cta_mode'] : null,
            'cta_required'  => array_key_exists('cta_required', $d) ? (bool) $d['cta_required'] : null,
            'response_goal' => ($d['response_goal'] ?? '') !== '' ? (string) $d['response_goal'] : null,
            'customer_need' => ($d['customer_need'] ?? '') !== '' ? (string) $d['customer_need'] : null,
        ];
    }

    return ['action' => null, 'cta_mode' => null, 'cta_required' => null, 'response_goal' => null, 'customer_need' => null];
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase37_extract_validation(array $events): array
{
    $d = phase37_latest_event_detail($events, 'CORE_VALIDATE');
    if ($d === null) {
        return [
            'executed' => false,
            'status'   => null,
            'blocked'  => false,
            'reason'   => null,
        ];
    }
    $ok = !empty($d['ok']);
    $validation = (string) ($d['validation'] ?? '');
    $reason = (string) ($d['reason'] ?? '');

    return [
        'executed' => true,
        'status'   => $validation !== '' ? $validation : ($ok ? 'ok' : 'failed'),
        'blocked'  => !$ok || $validation === 'failed',
        'reason'   => $reason !== '' ? $reason : null,
    ];
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase37_extract_action(array $events): array
{
    $planned = null;
    $executed = false;
    $verified = false;
    $failed = false;
    $type = null;
    $bookingOutcome = null;
    $handoffOutcome = null;

    $plan = phase37_latest_event_detail($events, 'CORE_PLAN');
    if ($plan !== null) {
        if (($plan['tool_selected'] ?? '') !== '') {
            $planned = (string) $plan['tool_selected'];
            $type = $planned;
        } elseif (($plan['selected_action'] ?? '') !== '') {
            $planned = (string) $plan['selected_action'];
            $type = $planned;
        }
    }

    foreach ($events as $ev) {
        $t = $ev['event_type'];
        $d = is_array($ev['detail_json']) ? $ev['detail_json'] : [];
        if ($t === 'CORE_TOOL_START') {
            $type = (string) ($d['tool'] ?? $type ?? '');
        }
        if ($t === 'CORE_TOOL_COMPLETE' && !empty($d['ok'])) {
            $executed = true;
            $tool = (string) ($d['tool'] ?? '');
            if ($tool !== '') {
                $type = $tool;
            }
            if ($tool === 'booking.create') {
                $bookingOutcome = 'executed';
            }
            if (str_contains($tool, 'handoff')) {
                $handoffOutcome = 'executed';
            }
        }
        if ($t === 'CORE_TOOL_FAIL' || ($t === 'CORE_TOOL_COMPLETE' && empty($d['ok']))) {
            $failed = true;
        }
    }

    $complete = phase37_latest_event_detail($events, 'CORE_COMPLETE');
    if ($complete !== null && ($complete['validation_result'] ?? '') === 'verified') {
        $verified = true;
    }

    return [
        'type'            => $type,
        'planned'         => $planned,
        'executed'        => $executed,
        'verified'        => $verified,
        'failed'          => $failed,
        'booking_outcome' => $bookingOutcome,
        'handoff_outcome' => $handoffOutcome,
    ];
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase37_extract_delivery(array $events): array
{
    $attempted = phase36_has_event($events, 'PROCESSING_TO_RESPONSE')
        || phase36_has_event($events, 'RESPONSE_SENT');
    $succeeded = phase36_has_event($events, 'RESPONSE_SENT');
    $status = null;
    if ($succeeded) {
        $status = 'succeeded';
    } elseif ($attempted) {
        $status = 'attempted';
    }

    return [
        'attempted' => $attempted,
        'succeeded' => $succeeded,
        'failed'    => $attempted && !$succeeded,
        'status'    => $status,
    ];
}

/**
 * @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events
 * @return array{started_at: ?string, completed_at: ?string, duration_ms: int|string|null}
 */
function phase37_extract_timing(array $events): array
{
    $startedAt = null;
    $completedAt = null;
    foreach ($events as $ev) {
        if ($ev['event_type'] === 'CORE_START' && $ev['created_at'] !== null) {
            $startedAt = $ev['created_at'];
        }
        if (in_array($ev['event_type'], ['CORE_COMPLETE', 'RESPONSE_SENT'], true) && $ev['created_at'] !== null) {
            $completedAt = $ev['created_at'];
        }
    }
    $durationMs = phase36_max_elapsed_ms($events);
    if ($durationMs === null && $startedAt !== null && $completedAt !== null) {
        $t0 = strtotime($startedAt);
        $t1 = strtotime($completedAt);
        if ($t0 !== false && $t1 !== false && $t1 >= $t0) {
            $durationMs = ($t1 - $t0) * 1000;
        }
    }

    return [
        'started_at'   => $startedAt,
        'completed_at' => $completedAt,
        'duration_ms'  => $durationMs ?? 'NOT_AVAILABLE',
    ];
}

/** @param list<array{event_type: string, created_at: ?string, detail_json: ?array}> $events */
function phase37_lifecycle(array $events): array
{
    $received = phase36_has_event($events, 'PROCESSING_TO_RESPONSE') || phase36_has_event($events, 'CORE_START');
    $started = phase36_has_event($events, 'CORE_START');
    $completed = phase36_has_event($events, 'CORE_COMPLETE') || phase36_has_event($events, 'RESPONSE_SENT');
    $failed = phase36_has_event($events, 'CORE_FALLBACK');

    return [
        'received'  => $received ? 'OBSERVED' : 'NOT_AVAILABLE',
        'started'   => $started ? 'OBSERVED' : 'NOT_AVAILABLE',
        'completed' => $completed ? 'OBSERVED' : 'NOT_AVAILABLE',
        'failed'    => $failed ? 'OBSERVED' : 'NOT_AVAILABLE',
    ];
}

/**
 * @param array<string, mixed> $nEvents wa-az-audit n_events
 * @param array<string, mixed> $meta conversation_id, lead_id, etc.
 * @return array<string, mixed>
 */
function phase37_normalize_turn_from_n_events(array $nEvents, array $meta = []): array
{
    $events = phase36_parse_audit_events($nEvents);
    $metaFromEvents = phase36_extract_turn_meta($events);
    $turnId = $meta['turn_id'] ?? $nEvents['turn_id'] ?? $metaFromEvents['turn_id'];
    $botId = $meta['bot_id'] ?? $metaFromEvents['bot_id'];
    $channel = $meta['channel'] ?? $metaFromEvents['channel'] ?? 'whatsapp';
    $path = phase37_path_from_events($events);
    $fallbackReason = phase36_extract_fallback_reason($events);
    $leadId = null;
    foreach ($events as $ev) {
        if (is_array($ev['detail_json']) && !empty($ev['detail_json']['lead_id'])) {
            $leadId = (int) $ev['detail_json']['lead_id'];
            break;
        }
    }
    $conversationId = $meta['conversation_id'] ?? ($leadId !== null ? 'lead:' . $leadId : null);

    return [
        'turn_id'         => $turnId !== null ? (int) $turnId : null,
        'conversation_id' => $conversationId,
        'bot_id'          => $botId !== null ? (int) $botId : null,
        'channel'         => (string) $channel,
        'path'            => $path,
        'fallback'        => $path === 'fallback' || $fallbackReason !== null,
        'fallback_reason' => $fallbackReason,
        'stages'          => phase37_observed_stages($events),
        'stage_presence'  => phase37_stage_presence_report($events),
        'events'          => array_map(static fn ($e) => $e['event_type'], $events),
        'lifecycle'       => phase37_lifecycle($events),
        'intelligence'    => phase37_extract_intelligence($events),
        'decision'        => phase37_extract_decision($events),
        'validation'      => phase37_extract_validation($events),
        'action'          => phase37_extract_action($events),
        'delivery'        => phase37_extract_delivery($events),
        'timing'          => phase37_extract_timing($events),
    ];
}

/**
 * @param array<string, mixed> $phase36Evidence from phase36_build_evidence_from_audit
 */
function phase37_normalize_from_phase36_evidence(array $phase36Evidence): array
{
    $eventRows = [];
    foreach (is_array($phase36Evidence['events'] ?? null) ? $phase36Evidence['events'] : [] as $type) {
        $eventRows[] = ['event_type' => (string) $type, 'created_at' => null, 'detail_json' => null];
    }
    $nEvents = [
        'turn_id' => $phase36Evidence['turn_id'] ?? null,
        'events'  => $eventRows,
    ];
    $norm = phase37_normalize_turn_from_n_events($nEvents, [
        'turn_id'         => $phase36Evidence['turn_id'] ?? null,
        'bot_id'          => $phase36Evidence['bot_id'] ?? null,
        'channel'         => $phase36Evidence['channel'] ?? 'whatsapp',
        'conversation_id' => $phase36Evidence['conversation_id'] ?? null,
    ]);
    $norm['path'] = match ((string) ($phase36Evidence['path'] ?? '')) {
        'agent_core', 'fallback', 'unknown' => (string) $phase36Evidence['path'],
        'agent_core_incomplete', 'webhook_mind_or_unknown', 'NOT_VERIFIED' => 'unknown',
        default => $norm['path'],
    };
    $norm['fallback'] = !empty($phase36Evidence['fallback']);
    $norm['fallback_reason'] = $phase36Evidence['fallback_reason'] ?? $norm['fallback_reason'];

    return $norm;
}

/**
 * @param list<int|float> $values
 */
function phase37_percentile(array $values, float $p): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $idx = (int) ceil(($p / 100) * $n) - 1;
    $idx = max(0, min($n - 1, $idx));

    return (float) $values[$idx];
}

/**
 * @param list<array<string, mixed>> $turns normalized turns
 * @return array<string, mixed>
 */
function phase37_aggregate_metrics(array $turns): array
{
    $total = count($turns);
    $knownPath = 0;
    $core = 0;
    $fallback = 0;
    $unknown = 0;
    $fallbackReasons = [];
    $stageCounts = [];
    $validationExecuted = 0;
    $validationBlocked = 0;
    $validationReasons = [];
    $actionExecuted = 0;
    $actionFailed = 0;
    $actionVerified = 0;
    $deliveryAttempted = 0;
    $deliverySuccess = 0;
    $deliveryFailed = 0;
    $durations = [];

    foreach ($turns as $t) {
        $path = (string) ($t['path'] ?? 'unknown');
        if (in_array($path, ['agent_core', 'fallback', 'unknown'], true)) {
            $knownPath++;
        }
        if ($path === 'agent_core') {
            $core++;
        } elseif ($path === 'fallback') {
            $fallback++;
        } else {
            $unknown++;
        }
        $fr = $t['fallback_reason'] ?? null;
        if ($fr !== null && $fr !== '') {
            $fallbackReasons[(string) $fr] = ($fallbackReasons[(string) $fr] ?? 0) + 1;
        }
        foreach (is_array($t['stages'] ?? null) ? $t['stages'] : [] as $stage) {
            $stageCounts[(string) $stage] = ($stageCounts[(string) $stage] ?? 0) + 1;
        }
        $val = is_array($t['validation'] ?? null) ? $t['validation'] : [];
        if (!empty($val['executed'])) {
            $validationExecuted++;
            if (!empty($val['blocked'])) {
                $validationBlocked++;
                $r = (string) ($val['reason'] ?? 'unknown');
                $validationReasons[$r] = ($validationReasons[$r] ?? 0) + 1;
            }
        }
        $act = is_array($t['action'] ?? null) ? $t['action'] : [];
        if (!empty($act['executed'])) {
            $actionExecuted++;
        }
        if (!empty($act['failed'])) {
            $actionFailed++;
        }
        if (!empty($act['verified'])) {
            $actionVerified++;
        }
        $del = is_array($t['delivery'] ?? null) ? $t['delivery'] : [];
        if (!empty($del['attempted'])) {
            $deliveryAttempted++;
        }
        if (!empty($del['succeeded'])) {
            $deliverySuccess++;
        }
        if (!empty($del['failed'])) {
            $deliveryFailed++;
        }
        $timing = is_array($t['timing'] ?? null) ? $t['timing'] : [];
        $dm = $timing['duration_ms'] ?? null;
        if (is_int($dm) || is_float($dm)) {
            $durations[] = (float) $dm;
        }
    }

    $pathDenom = $knownPath > 0 ? $knownPath : 0;

    return [
        'volume' => [
            'total_turns'      => $total,
            'known_path_turns' => $knownPath,
            'agent_core_turns' => $core,
            'fallback_turns'   => $fallback,
            'unknown_turns'    => $unknown,
        ],
        'path_rates' => [
            'agent_core_rate' => $pathDenom > 0 ? round($core / $pathDenom, 4) : 'NOT_AVAILABLE',
            'fallback_rate'   => $pathDenom > 0 ? round($fallback / $pathDenom, 4) : 'NOT_AVAILABLE',
            'unknown_rate'    => $pathDenom > 0 ? round($unknown / $pathDenom, 4) : 'NOT_AVAILABLE',
            'denominator'     => 'turns_with_known_execution_path',
            'denominator_n'   => $pathDenom,
        ],
        'fallback_by_reason' => $fallbackReasons,
        'stage_presence_counts' => $stageCounts,
        'validation' => [
            'executed'       => $validationExecuted,
            'blocked'        => $validationBlocked,
            'block_by_reason' => $validationReasons,
        ],
        'actions' => [
            'executed' => $actionExecuted,
            'failed'   => $actionFailed,
            'verified' => $actionVerified,
        ],
        'delivery' => [
            'attempted' => $deliveryAttempted,
            'success'   => $deliverySuccess,
            'failed'    => $deliveryFailed,
        ],
        'timing_ms' => $durations === [] ? [
            'count' => 0,
            'avg'   => 'NOT_AVAILABLE',
            'min'   => 'NOT_AVAILABLE',
            'max'   => 'NOT_AVAILABLE',
            'p50'   => 'NOT_AVAILABLE',
            'p95'   => 'NOT_AVAILABLE',
        ] : [
            'count' => count($durations),
            'avg'   => round(array_sum($durations) / count($durations), 2),
            'min'   => min($durations),
            'max'   => max($durations),
            'p50'   => phase37_percentile($durations, 50),
            'p95'   => phase37_percentile($durations, 95),
        ],
    ];
}
