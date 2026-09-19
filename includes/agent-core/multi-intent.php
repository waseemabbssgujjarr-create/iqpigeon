<?php
/**
 * Agent Mind Phase 2D — multi-intent priority, combination, deferral, objection intelligence.
 * Extends Phase 2A structured_intents; influences Phase 2B/2C without replacing them.
 */
declare(strict_types=1);

/** @var array<string, int> Lower = higher priority */
const AGENT_CORE_INTENT_PRIORITY_RANK = [
    'HUMAN_REQUEST'        => 10,
    'CANCELLATION'         => 20,
    'REJECTION'            => 25,
    'COMPLAINT'            => 30,
    'RETURN_REQUEST'       => 35,
    'SUPPORT'              => 40,
    'DELIVERY_QUERY'       => 45,
    'CONFIRMATION'         => 50,
    'ACCEPTANCE'           => 55,
    'BOOKING_REQUEST'      => 60,
    'ORDER_REQUEST'        => 65,
    'PAYMENT_REQUEST'      => 70,
    'PRICE_INQUIRY'        => 75,
    'PRODUCT_AVAILABILITY' => 80,
    'PRODUCT_COMPARISON'   => 82,
    'PRODUCT_SEARCH'       => 85,
    'DISCOUNT_REQUEST'     => 88,
    'NEGOTIATION'          => 89,
    'MENU'                 => 90,
    'GENERAL_INFORMATION'  => 92,
    'CART'                 => 93,
    'GREETING'             => 95,
    'FOLLOW_UP'            => 96,
    'UNKNOWN'              => 99,
];

/** @var list<string> Factual intents combinable in one response */
const AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS = [
    'PRICE_INQUIRY',
    'PRODUCT_AVAILABILITY',
    'DELIVERY_QUERY',
    'PRODUCT_SEARCH',
    'PRODUCT_COMPARISON',
    'GENERAL_INFORMATION',
];

/**
 * Phase 2E: booking date/time split — date and time required separately for booking.
 */
const AGENT_CORE_PHASE2E_BOOKING_ALIAS_NOTE = 'date_time_split_enforced';

/** @var list<string> */
const AGENT_CORE_SUPPORT_INTENTS = [
    'COMPLAINT',
    'SUPPORT',
    'DELIVERY_QUERY',
    'RETURN_REQUEST',
];

/**
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function agent_core_multi_intent_apply(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $pack
): array {
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $structured = is_array($plan['structured_intents'] ?? null) ? $plan['structured_intents'] : [];
    $structured = agent_core_multi_intent_augment_structured($structured, $text);
    $plan['structured_intents'] = $structured;
    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    $objection = agent_core_multi_intent_detect_objection($text, $plan, $conv, $intelligence, $memory);

    $ranked = agent_core_multi_intent_rank($structured, $intelligence, $objection, $text);
    $priorityLabels = array_map(static fn ($r) => (string) ($r['intent'] ?? ''), $ranked);
    $groups = agent_core_multi_intent_groups($ranked, $objection, $text);
    $resolved = agent_core_multi_intent_resolved($ranked, $groups, $objection, $plan, $text);
    $deferred = agent_core_multi_intent_deferred($ranked, $resolved, $plan, $text);
    $combined = agent_core_multi_intent_can_combine($resolved, $deferred, $plan, $objection, $text);
    $conflict = agent_core_multi_intent_conflict($ranked, $text, $plan);

    $plan['multi_intent'] = count($structured) >= 2 || count($priorityLabels) >= 2;
    $plan['intent_priority'] = $priorityLabels;
    $plan['intent_groups'] = $groups;
    $plan['intent_compatibility'] = agent_core_multi_intent_compatibility_map($resolved);
    $plan['combined_action_possible'] = $combined;
    $plan['deferred_intents'] = $deferred;
    $plan['resolved_intents'] = $resolved;
    $plan['multi_intent_reason_codes'] = agent_core_multi_intent_reason_codes($resolved, $deferred, $combined, $objection, $conflict);

    $plan['objection'] = $objection['detected'];
    $plan['objection_type'] = $objection['type'];
    $plan['objection_confidence'] = $objection['confidence'];
    $plan['objection_strategy'] = $objection['strategy'];
    $plan['objection_reason_codes'] = $objection['reason_codes'];

    if ($priorityLabels !== []) {
        $plan['primary_intent'] = $priorityLabels[0];
        $plan['secondary_intents'] = array_slice($priorityLabels, 1);
    }

    if ($combined) {
        $plan['message_budget'] = min((int) ($plan['message_budget'] ?? 2), 1);
        $plan['multi_intent_combined'] = true;
    }

    if ($objection['detected']) {
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Objection (' . $objection['type'] . '): use strategy ' . $objection['strategy'] . '.';
    }
    if ($combined && count($resolved) >= 2) {
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Address together: ' . implode(', ', array_slice($resolved, 0, 4)) . ' — one response, one CTA.';
    }
    if ($deferred !== []) {
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Defer unless natural: ' . implode(', ', array_slice($deferred, 0, 3)) . '.';
    }
    if ($conflict !== '') {
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Conflict resolution: ' . $conflict . '.';
    }

    if (agent_core_multi_intent_is_support_primary($ranked, $text)
        || ($plan['customer_goal'] ?? '') === 'resolve_issue'
    ) {
        $plan['support_need'] = true;
    }

    return $plan;
}

/**
 * Extend CI structured intents with deterministic text patterns (no duplicate classifier).
 *
 * @param list<array{intent: string, role: string, confidence: float}> $structured
 * @return list<array{intent: string, role: string, confidence: float}>
 */
function agent_core_multi_intent_augment_structured(array $structured, string $text): array
{
    $lower = mb_strtolower(trim($text));
    if ($lower === '') {
        return $structured;
    }

    $add = static function (string $intent, string $role, float $conf) use (&$structured): void {
        foreach ($structured as $row) {
            if ((string) ($row['intent'] ?? '') === $intent) {
                return;
            }
        }
        $structured[] = ['intent' => $intent, 'role' => $role, 'confidence' => round($conf, 2)];
    };

    if (preg_match('/\b(want to cancel|i want to cancel|cancel my|cancel order|please cancel)\b/u', $lower)) {
        $add('CANCELLATION', 'primary', 0.91);
    }
    if (preg_match('/\b(order is missing|missing order|order never arrived)\b/u', $lower)) {
        $add('DELIVERY_QUERY', 'primary', 0.91);
        $add('COMPLAINT', 'secondary', 0.82);
    }
    if (preg_match('/\b(order is late|order hasn\'?t arrived|hasn\'?t arrived|still waiting|not received|late delivery)\b/u', $lower)) {
        $add('DELIVERY_QUERY', 'primary', 0.92);
        $add('COMPLAINT', 'secondary', 0.82);
    }
    if (preg_match('/\b(which is better|what(?:\'s| is) better)\b/u', $lower)) {
        $add('PRODUCT_COMPARISON', 'primary', 0.88);
    }
    if (preg_match('/\b(what other products|other products|what else do you (?:sell|have|offer))\b/u', $lower)) {
        $add('PRODUCT_SEARCH', 'secondary', 0.84);
        $add('MENU', 'secondary', 0.78);
    }
    if (preg_match('/\b(is it safe|legitimate|scam|trust)\b/u', $lower) && preg_match('/\b(price|cost|how much)\b/u', $lower)) {
        $add('GENERAL_INFORMATION', 'primary', 0.84);
    }
    if (preg_match('/\b(cheaper option|anything cheaper|lower cost|more affordable)\b/u', $lower)) {
        $add('DISCOUNT_REQUEST', 'primary', 0.86);
        $add('PRODUCT_SEARCH', 'secondary', 0.8);
    }

    $hasKnown = false;
    foreach ($structured as $row) {
        if ((string) ($row['intent'] ?? '') !== 'UNKNOWN') {
            $hasKnown = true;
            break;
        }
    }
    if ($hasKnown) {
        $structured = array_values(array_filter(
            $structured,
            static fn ($row) => (string) ($row['intent'] ?? '') !== 'UNKNOWN'
        ));
    }

    return $structured;
}

function agent_core_multi_intent_is_support_delivery(string $text): bool
{
    $lower = mb_strtolower(trim($text));

    return preg_match(
        '/\b(where is my order|track(?:ing)?|order is late|order hasn\'?t arrived|hasn\'?t arrived|'
        . 'still waiting|not received|late delivery|delivery delay|missing order)\b/u',
        $lower
    ) === 1;
}

function agent_core_multi_intent_is_capability_delivery(string $text): bool
{
    $lower = mb_strtolower(trim($text));

    return preg_match(
        '/\b(can you deliver|do you deliver|deliver to|deliver tomorrow|delivery available|shipping to)\b/u',
        $lower
    ) === 1 && !agent_core_multi_intent_is_support_delivery($text);
}

function agent_core_multi_intent_rank_value(string $intent, string $text): int
{
    if ($intent === 'DELIVERY_QUERY') {
        if (agent_core_multi_intent_is_support_delivery($text)) {
            return AGENT_CORE_INTENT_PRIORITY_RANK['DELIVERY_QUERY'];
        }
        if (agent_core_multi_intent_is_capability_delivery($text)) {
            return 76;
        }

        return agent_core_multi_intent_is_support_delivery($text)
            ? AGENT_CORE_INTENT_PRIORITY_RANK['DELIVERY_QUERY']
            : 76;
    }

    return AGENT_CORE_INTENT_PRIORITY_RANK[$intent] ?? 98;
}

/**
 * @param list<array{intent: string, role: string, confidence: float, rank?: int}> $ranked
 */
function agent_core_multi_intent_is_support_primary(array $ranked, string $text): bool
{
    $primary = (string) ($ranked[0]['intent'] ?? '');
    if ($primary === 'DELIVERY_QUERY') {
        return agent_core_multi_intent_is_support_delivery($text);
    }

    return in_array($primary, ['COMPLAINT', 'SUPPORT', 'RETURN_REQUEST'], true);
}

/**
 * @param list<string> $labels
 * @return list<string>
 */
function agent_core_multi_intent_support_labels(array $labels, string $text): array
{
    $out = [];
    foreach ($labels as $label) {
        if ($label === 'DELIVERY_QUERY' && !agent_core_multi_intent_is_support_delivery($text)) {
            continue;
        }
        if (in_array($label, AGENT_CORE_SUPPORT_INTENTS, true)) {
            $out[] = $label;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $memory
 * @return array{detected: bool, type: string, confidence: float, strategy: string, reason_codes: list<string>}
 */
function agent_core_multi_intent_detect_objection(
    string $text,
    array $plan,
    array $conv,
    array $intelligence,
    array $memory
): array {
    $lower = mb_strtolower(trim($text));
    $none = ['detected' => false, 'type' => '', 'confidence' => 0.0, 'strategy' => '', 'reason_codes' => []];

    if ($lower === '') {
        return $none;
    }

    $codes = [];
    $type = '';
    $strategy = '';
    $confidence = 0.0;

    if (preg_match('/\b(never mind|not interested|leave me alone|stop messaging)\b/u', $lower)) {
        return ['detected' => true, 'type' => 'NOT_INTERESTED', 'confidence' => 0.9, 'strategy' => 'STOP', 'reason_codes' => ['explicit_rejection']];
    }
    if (preg_match('/\b(i\'?ll think about it|maybe later|need to think|not ready yet|let me think)\b/u', $lower)) {
        return ['detected' => true, 'type' => 'TIMING', 'confidence' => 0.86, 'strategy' => 'NONE', 'reason_codes' => ['think_about_it']];
    }
    if (preg_match('/\b(which is better|what(?:\'s| is) better)\b/u', $lower)) {
        return ['detected' => true, 'type' => 'COMPARISON', 'confidence' => 0.84, 'strategy' => 'COMPARE', 'reason_codes' => ['comparison_objection']];
    }
    if (preg_match('/\b(too expensive|too costly|can\'?t afford|price is high|kam karo|cheaper)\b/u', $lower)) {
        $type = 'PRICE';
        $strategy = preg_match('/\b(cheaper|lower|budget|afford)\b/u', $lower) ? 'OFFER_ALTERNATIVE' : 'EXPLAIN_VALUE';
        $confidence = 0.88;
        $codes[] = 'price_objection';
    } elseif (preg_match('/\b(not worth|doesn\'?t seem worth|why so much)\b/u', $lower)) {
        $type = 'VALUE';
        $strategy = 'EXPLAIN_VALUE';
        $confidence = 0.84;
        $codes[] = 'value_objection';
    } elseif (preg_match('/\b(is it safe|legitimate|scam|trust|actually work|real\?)\b/u', $lower)) {
        $type = 'TRUST';
        $strategy = 'REASSURE_WITH_VERIFIED_FACT';
        $confidence = 0.85;
        $codes[] = 'trust_objection';
    } elseif (preg_match('/\b(why should i choose|better than|instead of|vs |versus )\b/u', $lower)) {
        $type = 'COMPARISON';
        $strategy = 'COMPARE';
        $confidence = 0.82;
        $codes[] = 'comparison_objection';
    } elseif (preg_match('/\b(too complicated|too complex|don\'?t understand|confusing)\b/u', $lower)) {
        $type = 'COMPLEXITY';
        $strategy = 'CLARIFY';
        $confidence = 0.8;
        $codes[] = 'complexity_objection';
    } elseif (preg_match('/\b(don\'?t need|not sure i need|no use for)\b/u', $lower)) {
        $type = 'NEED';
        $strategy = 'CLARIFY';
        $confidence = 0.78;
        $codes[] = 'need_objection';
    } elseif (preg_match('/\b(too risky|risk|worried about)\b/u', $lower)) {
        $type = 'RISK';
        $strategy = 'ADDRESS_RISK';
        $confidence = 0.8;
        $codes[] = 'risk_objection';
    } elseif (preg_match('/\b(can you do anything about the price|negotiate|discount|deal)\b/u', $lower)) {
        $type = 'PRICE';
        $strategy = 'CLARIFY';
        $confidence = 0.8;
        $codes[] = 'price_negotiation';
    }

    if ($type === '' && preg_match('/\b(still too expensive|that\'?s expensive)\b/u', $lower)) {
        $type = 'PRICE';
        $strategy = 'OFFER_ALTERNATIVE';
        $confidence = 0.86;
        $codes[] = 'contextual_price_objection';
    }

    if ($type === '') {
        return $none;
    }

    if ($memory !== [] && in_array($type, ['PRICE', 'VALUE'], true)) {
        $codes[] = 'context_product_known';
    }

    return [
        'detected'     => true,
        'type'         => $type,
        'confidence'   => round($confidence, 2),
        'strategy'     => $strategy,
        'reason_codes' => $codes,
    ];
}

/**
 * @param list<array{intent: string, role: string, confidence: float}> $structured
 * @param array<string, mixed> $intelligence
 * @param array{detected: bool, type: string, confidence: float, strategy: string, reason_codes: list<string>} $objection
 * @return list<array{intent: string, role: string, confidence: float, rank: int}>
 */
function agent_core_multi_intent_rank(array $structured, array $intelligence, array $objection, string $text = ''): array
{
    $rows = $structured;
    if ($rows === [] && trim((string) ($intelligence['primary_intent'] ?? '')) !== '') {
        $rows[] = [
            'intent'     => (string) $intelligence['primary_intent'],
            'role'       => 'primary',
            'confidence' => (float) ($intelligence['confidence'] ?? 0.5),
        ];
    }

    usort($rows, static function ($a, $b) use ($objection, $text) {
        $ia = (string) ($a['intent'] ?? '');
        $ib = (string) ($b['intent'] ?? '');
        $ra = agent_core_multi_intent_rank_value($ia, $text);
        $rb = agent_core_multi_intent_rank_value($ib, $text);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0));
    });

    if ($objection['detected'] && in_array($objection['type'], ['NOT_INTERESTED'], true)) {
        array_unshift($rows, ['intent' => 'CANCELLATION', 'role' => 'primary', 'confidence' => 0.9]);
    }

    $out = [];
    $seen = [];
    foreach ($rows as $i => $row) {
        $label = (string) ($row['intent'] ?? '');
        if ($label === '' || isset($seen[$label])) {
            continue;
        }
        $seen[$label] = true;
        $row['rank'] = $i + 1;
        $out[] = $row;
    }

    return $out;
}

/**
 * @param list<array{intent: string, role: string, confidence: float, rank: int}> $ranked
 * @param array{detected: bool, type: string} $objection
 * @return list<list<string>>
 */
function agent_core_multi_intent_groups(array $ranked, array $objection, string $text = ''): array
{
    $labels = array_map(static fn ($r) => (string) ($r['intent'] ?? ''), $ranked);
    $groups = [];

    $factual = array_values(array_filter(
        array_intersect($labels, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS),
        static fn ($label) => $label !== 'DELIVERY_QUERY' || agent_core_multi_intent_is_capability_delivery($text)
            || !agent_core_multi_intent_is_support_delivery($text)
    ));
    if (count($factual) >= 2) {
        $groups[] = $factual;
    }

    $support = agent_core_multi_intent_support_labels($labels, $text);
    if ($support !== []) {
        $groups[] = $support;
    }

    if ($objection['detected'] && in_array($objection['type'], ['PRICE', 'VALUE'], true)) {
        $objGroup = ['DISCOUNT_REQUEST'];
        if (in_array('PRODUCT_SEARCH', $labels, true) || in_array('PRODUCT_AVAILABILITY', $labels, true)) {
            $objGroup[] = in_array('PRODUCT_SEARCH', $labels, true) ? 'PRODUCT_SEARCH' : 'PRODUCT_AVAILABILITY';
        }
        $groups[] = $objGroup;
    }

    if ($groups === [] && $labels !== []) {
        $groups[] = array_slice($labels, 0, 3);
    }

    return $groups;
}

/**
 * @param list<list<string>> $groups
 * @param array{detected: bool, type: string, strategy: string} $objection
 * @return list<string>
 */
function agent_core_multi_intent_resolved(array $ranked, array $groups, array $objection, array $plan, string $text = ''): array
{
    $labels = array_map(static fn ($r) => (string) ($r['intent'] ?? ''), $ranked);
    if ($labels === []) {
        return [];
    }

    $primary = $labels[0];
    $supportLabels = agent_core_multi_intent_support_labels($labels, $text);
    if ($supportLabels !== [] && agent_core_multi_intent_is_support_primary($ranked, $text)) {
        return $supportLabels;
    }

    if ($objection['detected']) {
        if ($objection['type'] === 'NOT_INTERESTED') {
            return ['CANCELLATION'];
        }
        if ($objection['type'] === 'TIMING') {
            $factual = array_values(array_filter(
                array_intersect($labels, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS),
                static fn ($label) => $label !== 'DELIVERY_QUERY' || !agent_core_multi_intent_is_support_delivery($text)
            ));
            return $factual !== [] ? $factual : ['FOLLOW_UP'];
        }
        if (in_array($objection['type'], ['PRICE', 'VALUE'], true)) {
            $resolved = ['DISCOUNT_REQUEST'];
            foreach (['PRODUCT_SEARCH', 'PRODUCT_AVAILABILITY', 'PRICE_INQUIRY'] as $i) {
                if (in_array($i, $labels, true)) {
                    $resolved[] = $i;
                }
            }
            return array_values(array_unique($resolved));
        }
        if (in_array($objection['type'], ['TRUST', 'RISK'], true)) {
            $resolved = array_values(array_intersect($labels, ['GENERAL_INFORMATION', 'PRICE_INQUIRY', 'PRODUCT_AVAILABILITY']));
            if ($resolved === [] && preg_match('/\b(price|cost|how much)\b/u', mb_strtolower($text))) {
                $resolved = ['GENERAL_INFORMATION', 'PRICE_INQUIRY'];
            }

            return $resolved !== [] ? $resolved : [$primary];
        }
        if ($objection['type'] === 'COMPARISON') {
            return array_values(array_intersect($labels, ['PRODUCT_COMPARISON', 'PRODUCT_SEARCH', 'PRICE_INQUIRY'])) ?: [$primary];
        }
    }

    if ($groups !== [] && count($groups[0]) >= 2) {
        $first = $groups[0];
        if (count(array_intersect($first, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS)) >= 2) {
            return $first;
        }
    }

    $factual = array_values(array_filter(
        array_intersect($labels, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS),
        static fn ($label) => $label !== 'DELIVERY_QUERY' || !agent_core_multi_intent_is_support_delivery($text)
    ));
    if (count($factual) >= 2) {
        return $factual;
    }

    return [$primary];
}

/**
 * @param list<string> $resolved
 * @return list<string>
 */
function agent_core_multi_intent_deferred(array $ranked, array $resolved, array $plan, string $text = ''): array
{
    $all = array_map(static fn ($r) => (string) ($r['intent'] ?? ''), $ranked);
    $deferred = array_values(array_diff($all, $resolved));

    if (agent_core_multi_intent_is_support_primary($ranked, $text)) {
        $deferred = array_values(array_filter(
            $deferred,
            static fn ($i) => !in_array($i, agent_core_multi_intent_support_labels([$i], $text), true)
        ));
    }

    return $deferred;
}

/**
 * @param list<string> $resolved
 * @param list<string> $deferred
 * @param array{detected: bool, type: string} $objection
 */
function agent_core_multi_intent_can_combine(array $resolved, array $deferred, array $plan, array $objection, string $text = ''): bool
{
    if (count($resolved) < 2) {
        return false;
    }
    if ($objection['detected'] && in_array($objection['type'], ['NOT_INTERESTED', 'TIMING'], true)) {
        return count(array_intersect($resolved, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS)) >= 2;
    }
    $first = (string) ($resolved[0] ?? '');
    if ($first === 'DELIVERY_QUERY' && agent_core_multi_intent_is_support_delivery($text)) {
        return false;
    }
    if (in_array($first, ['COMPLAINT', 'SUPPORT', 'RETURN_REQUEST'], true)) {
        return false;
    }
    $factualResolved = array_values(array_filter(
        $resolved,
        static fn ($label) => in_array($label, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS, true)
            && ($label !== 'DELIVERY_QUERY' || !agent_core_multi_intent_is_support_delivery($text))
    ));
    if (count($factualResolved) >= 2) {
        return true;
    }
    if (count($resolved) >= 2 && (int) ($plan['message_budget'] ?? 2) <= 1) {
        return count($factualResolved) === count($resolved);
    }

    return false;
}

/**
 * @param list<array{intent: string}> $ranked
 */
function agent_core_multi_intent_conflict(array $ranked, string $text, array $plan): string
{
    $lower = mb_strtolower(trim($text));
    $labels = array_map(static fn ($r) => (string) ($r['intent'] ?? ''), $ranked);

    if (in_array('CANCELLATION', $labels, true) && preg_match('/\b(change|instead|switch|modify)\b/u', $lower)) {
        return 'cancellation_with_modification_request';
    }
    if (in_array('RETURN_REQUEST', $labels, true) && in_array('ORDER_REQUEST', $labels, true)) {
        return 'refund_over_new_order';
    }
    if (in_array('DELIVERY_QUERY', $labels, true) && in_array('DISCOUNT_REQUEST', $labels, true)) {
        return 'support_over_price_objection';
    }

    return '';
}

/**
 * @param list<string> $resolved
 * @return array<string, bool>
 */
function agent_core_multi_intent_compatibility_map(array $resolved): array
{
    $map = [];
    foreach ($resolved as $a) {
        foreach ($resolved as $b) {
            if ($a === $b) {
                continue;
            }
            $key = $a . '+' . $b;
            $map[$key] = in_array($a, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS, true)
                && in_array($b, AGENT_CORE_COMPATIBLE_FACTUAL_INTENTS, true);
        }
    }

    return $map;
}

/**
 * @param list<string> $resolved
 * @param list<string> $deferred
 * @param array{detected: bool, type: string} $objection
 * @return list<string>
 */
function agent_core_multi_intent_reason_codes(
    array $resolved,
    array $deferred,
    bool $combined,
    array $objection,
    string $conflict
): array {
    $codes = [];
    if (count($resolved) >= 2) {
        $codes[] = 'multi_intent';
    }
    if ($combined) {
        $codes[] = 'combined_action_possible';
    }
    if ($deferred !== []) {
        $codes[] = 'deferred_intents';
    }
    if ($objection['detected']) {
        $codes[] = 'objection_' . strtolower($objection['type']);
    }
    if ($conflict !== '') {
        $codes[] = $conflict;
    }
    if (in_array($resolved[0] ?? '', agent_core_multi_intent_support_labels($resolved, ''), true)) {
        $codes[] = 'support_priority';
    }

    return array_values(array_unique($codes));
}
