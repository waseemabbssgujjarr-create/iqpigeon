<?php
/**
 * Agent Mind Phase 2B — candidate actions, eligibility, deterministic NBA scoring.
 */
declare(strict_types=1);

require_once __DIR__ . '/multi-intent.php';

/** @var list<string> */
const AGENT_CORE_CANDIDATE_ACTIONS = [
    'ANSWER',
    'CLARIFY',
    'RECOMMEND',
    'QUALIFY',
    'COLLECT_REQUIRED_INFORMATION',
    'SHOW_PRODUCT',
    'SHOW_SERVICE',
    'CHECK_AVAILABILITY',
    'OFFER_BOOKING',
    'OFFER_QUOTE',
    'START_ORDER',
    'START_CHECKOUT',
    'TRACK_ORDER',
    'RESOLVE_SUPPORT',
    'HANDLE_OBJECTION',
    'OFFER_HUMAN_HANDOFF',
    'FOLLOW_UP',
    'COMPLETE',
    'STOP',
];

/**
 * Apply Phase 2B NBA selection on top of Phase 2A enrichment.
 *
 * @param array{label: string, detail: string, confidence: float} $need
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function agent_core_nba_apply(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $pack,
    array $need
): array {
    $ctx = agent_core_nba_build_context($plan, $intelligence, $turnCtx, $conv, $pack, $need);
    $candidates = agent_core_nba_generate_candidates($ctx);
    $eligible = [];
    $scored = [];

    foreach ($candidates as $action) {
        $elig = agent_core_nba_eligibility($action, $ctx);
        if (!$elig['eligible']) {
            continue;
        }
        $eligible[] = $action;
        $score = agent_core_nba_score_action($action, $ctx, $elig);
        $scored[] = [
            'action'  => $action,
            'score'   => $score['score'],
            'reasons' => $score['reasons'],
        ];
    }

    usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['action'], $b['action']));

    $selected = $scored[0]['action'] ?? 'ANSWER';
    $selectedScore = (float) ($scored[0]['score'] ?? 0.0);
    $selectedReasons = is_array($scored[0]['reasons'] ?? null) ? $scored[0]['reasons'] : [];

    $legacyNba = agent_core_nba_map_to_legacy($selected, $ctx);
    $ciNba = trim((string) ($plan['ci_next_best_action'] ?? $intelligence['next_best_action'] ?? ''));

    if ($ciNba !== '') {
        $plan['ci_next_best_action'] = $ciNba;
    }

    $plan['candidate_actions'] = $candidates;
    $plan['eligible_actions'] = $eligible;
    $plan['scored_actions'] = array_slice($scored, 0, 12);
    $plan['selected_action'] = $selected;
    $plan['recommended_action'] = $selected;
    $plan['action_score'] = round($selectedScore, 2);
    $plan['action_reasons'] = $selectedReasons;
    $plan['next_best_action'] = $legacyNba;
    $plan['knowledge_available'] = $ctx['knowledge_available'];

    if ($selected === 'COLLECT_REQUIRED_INFORMATION' || $selected === 'CLARIFY') {
        $plan['clarification_needed'] = true;
    }
    if (in_array($selected, ['STOP', 'FOLLOW_UP'], true) && ($ctx['goal'] ?? '') === 'stop') {
        $plan['stop_allowed'] = true;
        $plan['advance_allowed'] = false;
    }
    if (in_array($selected, ['RESOLVE_SUPPORT', 'TRACK_ORDER', 'OFFER_HUMAN_HANDOFF'], true)) {
        $plan['advance_allowed'] = false;
        $plan['cta_mode'] = in_array($selected, ['OFFER_HUMAN_HANDOFF'], true) ? 'handoff' : 'resolve';
    }
    if (in_array($selected, ['OFFER_QUOTE', 'SHOW_PRODUCT', 'START_ORDER', 'START_CHECKOUT', 'OFFER_BOOKING', 'COMPLETE'], true)
        && !in_array($ctx['goal'] ?? '', ['resolve_issue', 'return', 'stop', 'social'], true)
    ) {
        $plan['advance_allowed'] = true;
        $plan['stop_allowed'] = false;
    }

    $plan['action_confidence'] = round(max(0.1, min(1.0, $selectedScore / 100)), 2);

    if (!empty($ctx['multi_intent_combined'])) {
        $plan['multi_intent_combined'] = true;
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Combine compatible answers in one message when business data allows.';
    }

    return $plan;
}

/**
 * @param array{label: string, detail: string, confidence: float} $need
 * @return array<string, mixed>
 */
function agent_core_nba_build_context(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $pack,
    array $need
): array {
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $lower = mb_strtolower($text);
    $caps = is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [];
    $missing = is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [];
    $structured = is_array($plan['structured_intents'] ?? null) ? $plan['structured_intents'] : [];
    $affirmation = trim((string) ($intelligence['affirmation'] ?? 'none'));
    $emotion = trim((string) ($plan['emotion'] ?? $intelligence['emotion'] ?? 'neutral'));
    $intentConfidence = (float) ($plan['intent_confidence'] ?? $intelligence['confidence'] ?? 0.5);
    $ambiguity = (float) ($intelligence['ambiguity'] ?? 0.0);
    $hasPrice = agent_core_nba_has_price_evidence($pack, $plan);
    $objectionDetected = !empty($plan['objection'])
        || preg_match('/\b(too expensive|too costly|can\'?t afford|not worth|cheaper)\b/u', $lower) === 1;
    $objectionType = trim((string) ($plan['objection_type'] ?? ''));
    $objectionStrategy = trim((string) ($plan['objection_strategy'] ?? ''));
    $resolvedIntents = is_array($plan['resolved_intents'] ?? null) ? $plan['resolved_intents'] : [];
    $deferredIntents = is_array($plan['deferred_intents'] ?? null) ? $plan['deferred_intents'] : [];

    $intentLabels = array_map(static fn ($r) => (string) ($r['intent'] ?? ''), $structured);
    if ($intentLabels === [] && trim((string) ($plan['primary_intent'] ?? '')) !== '') {
        $intentLabels[] = (string) $plan['primary_intent'];
    }

    return [
        'text'                  => $text,
        'lower'                 => $lower,
        'primary'               => trim((string) ($plan['primary_intent'] ?? '')),
        'intents'               => $intentLabels,
        'structured_intents'    => $structured,
        'need'                  => $need,
        'need_label'            => (string) ($need['label'] ?? ''),
        'goal'                  => trim((string) ($plan['customer_goal'] ?? '')),
        'readiness'             => trim((string) ($plan['readiness'] ?? 'EXPLORING')),
        'stage'                 => trim((string) ($plan['conversation_stage'] ?? '')),
        'emotion'               => $emotion,
        'caps'                  => $caps,
        'missing'               => $missing,
        'required'              => is_array($plan['required_information'] ?? null) ? $plan['required_information'] : [],
        'optional'              => is_array($plan['optional_information'] ?? null) ? $plan['optional_information'] : [],
        'message_budget'        => (int) ($plan['message_budget'] ?? 2),
        'intent_confidence'     => $intentConfidence,
        'need_confidence'       => (float) ($plan['need_confidence'] ?? 0.5),
        'ambiguity'             => $ambiguity,
        'affirmation'           => $affirmation,
        'pending_action'        => trim((string) ($intelligence['summary']['pending_action'] ?? '')),
        'knowledge_available'   => $hasPrice,
        'objection'             => $objectionDetected,
        'objection_type'        => $objectionType,
        'objection_strategy'    => $objectionStrategy,
        'resolved_intents'      => $resolvedIntents,
        'deferred_intents'      => $deferredIntents,
        'support_need'          => !empty($plan['support_need'])
            || in_array($need['label'] ?? '', ['resolve_delivery', 'obtain_refund', 'personal_support'], true)
            || in_array($plan['customer_goal'] ?? '', ['resolve_issue', 'return', 'get_support', 'track_order'], true),
        'ci_nba'                => trim((string) ($plan['next_best_action'] ?? $intelligence['next_best_action'] ?? '')),
        'multi_intent_combined' => !empty($plan['combined_action_possible'])
            || (count($intentLabels) >= 2 && (int) ($plan['message_budget'] ?? 2) <= 1),
        'pack'                  => $pack,
        'plan'                  => $plan,
        'intelligence'          => $intelligence,
    ];
}

/**
 * @param array<string, mixed> $ctx
 * @return list<string>
 */
function agent_core_nba_generate_candidates(array $ctx): array
{
    $out = ['ANSWER'];
    $goal = (string) ($ctx['goal'] ?? '');
    $need = (string) ($ctx['need_label'] ?? '');
    $readiness = (string) ($ctx['readiness'] ?? '');
    $primary = (string) ($ctx['primary'] ?? '');

    if ($goal === 'stop' || $need === 'close_conversation') {
        return ['STOP', 'FOLLOW_UP', 'ANSWER'];
    }
    if ($goal === 'social' || in_array($primary, ['GREETING', 'FOLLOW_UP'], true)) {
        return ['FOLLOW_UP', 'STOP', 'ANSWER'];
    }
    if ($goal === 'speak_to_human' || $primary === 'HUMAN_REQUEST') {
        return ['OFFER_HUMAN_HANDOFF', 'ANSWER', 'RESOLVE_SUPPORT'];
    }
    if (($ctx['support_need'] ?? false) || in_array($primary, ['COMPLAINT', 'SUPPORT', 'DELIVERY_QUERY', 'RETURN_REQUEST'], true)) {
        $out = ['RESOLVE_SUPPORT', 'TRACK_ORDER', 'OFFER_HUMAN_HANDOFF', 'ANSWER', 'CLARIFY'];
    }
    if ($need === 'obtain_refund' || $goal === 'return' || $goal === 'cancel') {
        $out = array_merge(['RESOLVE_SUPPORT', 'OFFER_HUMAN_HANDOFF'], $out);
    }
    if (!empty($ctx['objection']) || ($ctx['objection_type'] ?? '') !== '') {
        $out = array_merge(['HANDLE_OBJECTION', 'RECOMMEND', 'ANSWER', 'STOP'], $out);
        if (($ctx['objection_strategy'] ?? '') === 'OFFER_ALTERNATIVE') {
            $out[] = 'SHOW_PRODUCT';
        }
    }
    if ($primary === 'PRICE_INQUIRY' || $need === 'learn_pricing') {
        $out = array_merge(['OFFER_QUOTE', 'ANSWER', 'RECOMMEND', 'SHOW_PRODUCT'], $out);
    }
    if (in_array($primary, ['PRODUCT_SEARCH', 'PRODUCT_AVAILABILITY', 'PRODUCT_COMPARISON', 'MENU'], true) || $need === 'find_product') {
        $out = array_merge(['RECOMMEND', 'SHOW_PRODUCT', 'CHECK_AVAILABILITY', 'OFFER_QUOTE'], $out);
    }
    if ($need === 'understand_offerings') {
        $out = array_merge(['SHOW_SERVICE', 'ANSWER', 'RECOMMEND'], $out);
    }
    if ($need === 'invite_speaker' || $primary === 'EVENT_INVITATION') {
        $out = array_merge(['COLLECT_REQUIRED_INFORMATION', 'ANSWER'], $out);
    }
    if ($goal === 'book' || $need === 'book_appointment' || $primary === 'BOOKING_REQUEST') {
        $out = array_merge(['OFFER_BOOKING', 'COLLECT_REQUIRED_INFORMATION', 'COMPLETE'], $out);
    }
    if (in_array($goal, ['purchase', 'choose'], true) || $need === 'complete_purchase') {
        $out = array_merge(['START_ORDER', 'START_CHECKOUT', 'SHOW_PRODUCT', 'CHECK_AVAILABILITY'], $out);
    }
    if ($goal === 'track_order') {
        $out = array_merge(['TRACK_ORDER', 'RESOLVE_SUPPORT'], $out);
    }
    if (($ctx['missing'] ?? []) !== []) {
        $out[] = 'COLLECT_REQUIRED_INFORMATION';
        $out[] = 'CLARIFY';
    }
    if ($readiness === 'LOW' || $need === 'explore_options') {
        $out = array_merge(['ANSWER', 'RECOMMEND'], $out);
    }
    if (in_array($readiness, ['READY', 'COMPLETING'], true)) {
        $out = array_merge(['COMPLETE', 'START_ORDER', 'START_CHECKOUT', 'OFFER_BOOKING'], $out);
    }
    if (($ctx['ambiguity'] ?? 0) >= 0.55 || ($ctx['intent_confidence'] ?? 1) < 0.55) {
        $out[] = 'CLARIFY';
        $out[] = 'OFFER_HUMAN_HANDOFF';
    }
    if (($ctx['optional'] ?? []) !== [] && !in_array($readiness, ['READY', 'COMPLETING', 'QUALIFIED'], true)) {
        $out[] = 'QUALIFY';
    }

    foreach ($ctx['intents'] as $intent) {
        $intent = (string) $intent;
        if ($intent === 'PRICE_INQUIRY') {
            $out[] = 'OFFER_QUOTE';
        }
        if (in_array($intent, ['PRODUCT_SEARCH', 'PRODUCT_AVAILABILITY', 'MENU'], true)) {
            $out[] = 'SHOW_PRODUCT';
        }
        if ($intent === 'DELIVERY_QUERY') {
            $out[] = 'CHECK_AVAILABILITY';
            $out[] = 'RESOLVE_SUPPORT';
        }
        if (in_array($intent, ['ORDER_REQUEST', 'CART'], true)) {
            $out[] = 'START_ORDER';
        }
        if ($intent === 'BOOKING_REQUEST') {
            $out[] = 'OFFER_BOOKING';
        }
    }

    $filtered = [];
    foreach ($out as $action) {
        if (in_array($action, AGENT_CORE_CANDIDATE_ACTIONS, true) && !in_array($action, $filtered, true)) {
            $filtered[] = $action;
        }
    }

    return $filtered;
}

/**
 * @param array<string, mixed> $ctx
 * @return array{eligible: bool, blockers: list<string>}
 */
function agent_core_nba_eligibility(string $action, array $ctx): array
{
    $blockers = [];
    $caps = is_array($ctx['caps'] ?? null) ? $ctx['caps'] : [];
    $goal = (string) ($ctx['goal'] ?? '');
    $need = (string) ($ctx['need_label'] ?? '');
    $missing = is_array($ctx['missing'] ?? null) ? $ctx['missing'] : [];
    $support = !empty($ctx['support_need']);

    $hasCap = static fn (string $cap): bool => in_array($cap, $caps, true);

    if ($need === 'invite_speaker' && in_array($action, ['OFFER_BOOKING', 'START_ORDER', 'START_CHECKOUT', 'QUALIFY'], true)) {
        $blockers[] = 'event_invitation_priority';
    }
    if ($action === 'OFFER_BOOKING' && !$hasCap('booking')) {
        $blockers[] = 'no_booking_capability';
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT'], true) && !$hasCap('cart')) {
        $blockers[] = 'no_cart_capability';
    }
    if (in_array($action, ['SHOW_PRODUCT', 'RECOMMEND', 'CHECK_AVAILABILITY'], true) && !$hasCap('catalog')) {
        $blockers[] = 'no_catalog_capability';
    }
    if ($support && in_array($action, ['START_ORDER', 'START_CHECKOUT', 'OFFER_BOOKING', 'QUALIFY'], true)) {
        $blockers[] = 'support_priority';
    }
    if (($need === 'explore_options' || ($ctx['readiness'] ?? '') === 'LOW')
        && in_array($action, ['START_ORDER', 'START_CHECKOUT'], true)
    ) {
        $blockers[] = 'low_readiness';
    }
    if ($goal === 'stop' && !in_array($action, ['STOP', 'FOLLOW_UP', 'ANSWER'], true)) {
        $blockers[] = 'customer_stopping';
    }
    if ($action === 'OFFER_QUOTE' && empty($ctx['knowledge_available'])
        && !function_exists('conversation_intelligence_is_general_price_list')
    ) {
        $blockers[] = 'price_unknown';
    }
    if ($action === 'OFFER_QUOTE' && empty($ctx['knowledge_available'])) {
        $text = (string) ($ctx['text'] ?? '');
        if (function_exists('conversation_intelligence_is_general_price_list')
            && !conversation_intelligence_is_general_price_list($text)
            && in_array('which_item', $missing, true)
        ) {
            $blockers[] = 'price_unknown';
        }
    }
    if ($action === 'COMPLETE' && $missing !== []) {
        $blockers[] = 'missing_required';
    }
    if ($action === 'COLLECT_REQUIRED_INFORMATION' && $missing === []) {
        $blockers[] = 'nothing_missing';
    }
    $requiredMissing = array_values(array_diff($missing, ['qualification_fields']));
    if ($action === 'COLLECT_REQUIRED_INFORMATION' && $missing !== [] && $requiredMissing === []) {
        $blockers[] = 'optional_only';
    }
    if ($action === 'COLLECT_REQUIRED_INFORMATION' && ($ctx['affirmation'] ?? '') === 'confirm') {
        $blockers[] = 'confirming';
    }
    if ($action === 'COLLECT_REQUIRED_INFORMATION'
        && in_array($ctx['readiness'] ?? '', ['READY', 'COMPLETING'], true)
        && in_array($goal, ['purchase', 'book'], true)
        && $requiredMissing === []
    ) {
        $blockers[] = 'ready_to_act';
    }
    $onlyProductMissing = $requiredMissing !== []
        && array_diff($requiredMissing, ['product', 'which_item']) === [];
    $entities = is_array($ctx['intelligence']['entities'] ?? null) ? $ctx['intelligence']['entities'] : [];
    $hasProductHint = trim((string) ($entities['product'] ?? '')) !== ''
        || trim((string) ($entities['color'] ?? '')) !== '';
    if ($action === 'COLLECT_REQUIRED_INFORMATION'
        && $onlyProductMissing
        && $hasProductHint
        && ($ctx['need_label'] ?? '') === 'complete_purchase'
        && preg_match('/\b(buy|order|checkout|purchase)\b/u', (string) ($ctx['lower'] ?? ''))
    ) {
        $blockers[] = 'product_context_present';
    }
    if ($action === 'START_ORDER' && in_array($need, ['resolve_delivery', 'obtain_refund', 'close_conversation'], true)) {
        $blockers[] = 'need_mismatch';
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT'], true)
        && in_array(($ctx['resolved_intents'][0] ?? ''), AGENT_CORE_SUPPORT_INTENTS, true)
    ) {
        $blockers[] = 'support_first';
    }
    if ($action === 'QUALIFY' && in_array($ctx['readiness'] ?? '', ['READY', 'COMPLETING'], true)) {
        $blockers[] = 'already_ready';
    }
    if ($action === 'OFFER_HUMAN_HANDOFF' && in_array($goal, ['social', 'stop'], true)) {
        $blockers[] = 'unnecessary_handoff';
    }

    return ['eligible' => $blockers === [], 'blockers' => $blockers];
}

/**
 * @param array{eligible: bool, blockers: list<string>} $elig
 * @param array<string, mixed> $ctx
 * @return array{score: float, reasons: array<string, bool|string|float|list<string>>}
 */
function agent_core_nba_score_action(string $action, array $ctx, array $elig): array
{
    $goal = (string) ($ctx['goal'] ?? '');
    $need = (string) ($ctx['need_label'] ?? '');
    $readiness = (string) ($ctx['readiness'] ?? '');
    $primary = (string) ($ctx['primary'] ?? '');
    $missing = is_array($ctx['missing'] ?? null) ? $ctx['missing'] : [];
    $support = !empty($ctx['support_need']);

    $customerValue = agent_core_nba_customer_value($action, $ctx);
    $intentFit = agent_core_nba_intent_fit($action, $ctx);
    $needFit = agent_core_nba_need_fit($action, $need);
    $goalFit = agent_core_nba_goal_fit($action, $goal);
    $capFit = agent_core_nba_capability_fit($action, $ctx);
    $readinessFit = agent_core_nba_readiness_fit($action, $readiness);
    $contextRel = agent_core_nba_context_relevance($action, $ctx);
    $urgency = in_array($ctx['emotion'] ?? '', ['urgent', 'urgency', 'frustrated'], true) ? 85.0 : 50.0;
    $expected = agent_core_nba_expected_outcome($action, $ctx);
    $budgetBonus = ((int) ($ctx['message_budget'] ?? 2) === 1 && in_array($action, ['ANSWER', 'OFFER_QUOTE', 'RECOMMEND', 'RESOLVE_SUPPORT'], true))
        ? 12.0 : 0.0;

    $score = (
        $customerValue * 0.28
        + $needFit * 0.22
        + $goalFit * 0.18
        + $intentFit * 0.10
        + $capFit * 0.10
        + $readinessFit * 0.07
        + $contextRel * 0.05
        + $urgency * 0.03
        + $expected * 0.07
        + $budgetBonus
    );

    $penalties = 0.0;
    if ($action === 'QUALIFY' && !in_array('qualification_fields', $ctx['optional'] ?? [], true)) {
        $penalties += 25;
    }
    if ($action === 'CLARIFY' && $missing === []) {
        $penalties += 20;
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT', 'OFFER_BOOKING'], true)
        && ($ctx['intent_confidence'] ?? 1) < 0.55
    ) {
        $penalties += 30;
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT'], true)
        && preg_match('/\bmaybe\b/u', (string) ($ctx['lower'] ?? ''))
    ) {
        $penalties += 40;
    }
    if ($support && in_array($action, ['START_ORDER', 'RECOMMEND', 'QUALIFY'], true)) {
        $penalties += 40;
    }
    if (($ctx['ambiguity'] ?? 0) >= 0.6 && in_array($action, ['START_ORDER', 'COMPLETE', 'OFFER_BOOKING'], true)) {
        $penalties += 25;
    }
    if ($action === 'COLLECT_REQUIRED_INFORMATION' && $missing !== []) {
        $reqMissing = array_values(array_diff($missing, ['qualification_fields']));
        if ($reqMissing !== []) {
            $score += 15;
        }
    }
    if ($action === 'COMPLETE' && $missing === [] && in_array($readiness, ['READY', 'COMPLETING'], true)) {
        $score += 20;
    }
    if ($action === 'COMPLETE' && ($ctx['affirmation'] ?? '') === 'confirm') {
        $score += 45;
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT'], true)
        && in_array($readiness, ['READY', 'COMPLETING'], true)
        && in_array($goal, ['purchase', 'choose'], true)
    ) {
        $score += 25;
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT'], true)
        && ($ctx['need_label'] ?? '') === 'complete_purchase'
        && preg_match('/\b(buy|order|checkout|purchase)\b/u', (string) ($ctx['lower'] ?? ''))
    ) {
        $score += 30;
    }
    if ($action === 'STOP' && $goal === 'stop') {
        $score += 35;
    }
    if ($action === 'FOLLOW_UP' && $goal === 'social') {
        $score += 35;
    }
    if ($action === 'RESOLVE_SUPPORT' && $support) {
        $score += 30;
    }
    if ($action === 'HANDLE_OBJECTION' && !empty($ctx['objection'])) {
        $score += 35;
    }
    if (!empty($ctx['objection']) && ($ctx['objection_strategy'] ?? '') === 'OFFER_ALTERNATIVE') {
        if (in_array($action, ['RECOMMEND', 'SHOW_PRODUCT', 'HANDLE_OBJECTION'], true)) {
            $score += 28;
        }
    }
    if (!empty($ctx['objection']) && ($ctx['objection_type'] ?? '') === 'PRICE'
        && in_array($action, ['OFFER_QUOTE'], true)
        && !preg_match('/\b(how much|price|cost)\b/u', (string) ($ctx['lower'] ?? ''))
    ) {
        $penalties += 18;
    }
    if (!empty($ctx['multi_intent_combined']) && in_array($action, ['ANSWER', 'OFFER_QUOTE', 'SHOW_PRODUCT', 'CHECK_AVAILABILITY'], true)) {
        $score += 18;
    }

    $score = max(0.0, min(100.0, $score - $penalties));

    $reasons = [
        'customer_value'     => round($customerValue, 1),
        'need_fit'           => $needFit >= 70,
        'goal_fit'           => $goalFit >= 70,
        'capability_fit'     => $capFit >= 60,
        'readiness'          => $readiness,
        'missing_required'   => $missing,
        'support_priority'   => $support && $action === 'RESOLVE_SUPPORT',
        'knowledge_available'=> !empty($ctx['knowledge_available']),
        'multi_intent'       => !empty($ctx['multi_intent_combined']),
        'explicit_request'   => agent_core_nba_explicit_request($action, $ctx),
    ];

    return ['score' => round($score, 2), 'reasons' => $reasons];
}

function agent_core_nba_customer_value(string $action, array $ctx): float
{
    $goal = (string) ($ctx['goal'] ?? '');
    $need = (string) ($ctx['need_label'] ?? '');

    return match (true) {
        $goal === 'stop' && $action === 'STOP' => 98.0,
        $goal === 'resolve_issue' && $action === 'RESOLVE_SUPPORT' => 95.0,
        $need === 'resolve_delivery' && $action === 'RESOLVE_SUPPORT' => 95.0,
        $goal === 'return' && $action === 'RESOLVE_SUPPORT' => 92.0,
        $goal === 'book' && $action === 'OFFER_BOOKING' => 88.0,
        $goal === 'purchase' && in_array($action, ['START_ORDER', 'START_CHECKOUT'], true) => 88.0,
        $need === 'explore_options' && in_array($action, ['ANSWER', 'RECOMMEND'], true) => 85.0,
        $goal === 'obtain_quote' && $action === 'OFFER_QUOTE' => 82.0,
        $goal === 'learn' && in_array($action, ['ANSWER', 'SHOW_SERVICE', 'RECOMMEND'], true) => 80.0,
        !empty($ctx['objection']) && $action === 'HANDLE_OBJECTION' => 82.0,
        !empty($ctx['objection']) && ($ctx['objection_strategy'] ?? '') === 'OFFER_ALTERNATIVE'
            && in_array($action, ['RECOMMEND', 'SHOW_PRODUCT', 'HANDLE_OBJECTION'], true) => 84.0,
        default => 55.0,
    };
}

function agent_core_nba_intent_fit(string $action, array $ctx): float
{
    $primary = (string) ($ctx['primary'] ?? '');
    $map = [
        'PRICE_INQUIRY'        => ['OFFER_QUOTE' => 90, 'ANSWER' => 80],
        'PRODUCT_SEARCH'       => ['RECOMMEND' => 90, 'SHOW_PRODUCT' => 88],
        'ORDER_REQUEST'        => ['START_ORDER' => 90, 'START_CHECKOUT' => 88],
        'BOOKING_REQUEST'      => ['OFFER_BOOKING' => 92, 'COMPLETE' => 85],
        'DELIVERY_QUERY'       => ['RESOLVE_SUPPORT' => 92, 'TRACK_ORDER' => 85],
        'COMPLAINT'            => ['RESOLVE_SUPPORT' => 95],
        'HUMAN_REQUEST'        => ['OFFER_HUMAN_HANDOFF' => 95],
        'GREETING'             => ['FOLLOW_UP' => 90],
        'FOLLOW_UP'            => ['FOLLOW_UP' => 88, 'STOP' => 70],
    ];
    if (isset($map[$primary][$action])) {
        return (float) $map[$primary][$action];
    }

    return 50.0;
}

function agent_core_nba_need_fit(string $action, string $need): float
{
    $map = [
        'resolve_delivery'     => ['RESOLVE_SUPPORT' => 98, 'TRACK_ORDER' => 90],
        'obtain_refund'        => ['RESOLVE_SUPPORT' => 95, 'OFFER_HUMAN_HANDOFF' => 80],
        'learn_pricing'        => ['OFFER_QUOTE' => 92, 'ANSWER' => 85],
        'find_product'         => ['RECOMMEND' => 90, 'SHOW_PRODUCT' => 88],
        'book_appointment'     => ['OFFER_BOOKING' => 92, 'COLLECT_REQUIRED_INFORMATION' => 75],
        'complete_purchase'    => ['START_ORDER' => 92, 'START_CHECKOUT' => 90],
        'understand_offerings' => ['SHOW_SERVICE' => 90, 'ANSWER' => 85],
        'close_conversation'   => ['STOP' => 98, 'FOLLOW_UP' => 85],
        'explore_options'      => ['ANSWER' => 88, 'RECOMMEND' => 82],
        'speak_to_human'       => ['OFFER_HUMAN_HANDOFF' => 98],
    ];

    return (float) ($map[$need][$action] ?? 50.0);
}

function agent_core_nba_goal_fit(string $action, string $goal): float
{
    $map = [
        'stop'            => ['STOP' => 98, 'FOLLOW_UP' => 85],
        'resolve_issue'   => ['RESOLVE_SUPPORT' => 95, 'TRACK_ORDER' => 88],
        'return'          => ['RESOLVE_SUPPORT' => 92],
        'cancel'          => ['RESOLVE_SUPPORT' => 85, 'STOP' => 80],
        'book'            => ['OFFER_BOOKING' => 92, 'COMPLETE' => 88],
        'purchase'        => ['START_ORDER' => 92, 'START_CHECKOUT' => 90],
        'obtain_quote'    => ['OFFER_QUOTE' => 90],
        'learn'           => ['ANSWER' => 88, 'SHOW_SERVICE' => 85],
        'track_order'     => ['TRACK_ORDER' => 95, 'RESOLVE_SUPPORT' => 85],
        'speak_to_human'  => ['OFFER_HUMAN_HANDOFF' => 98],
        'social'          => ['FOLLOW_UP' => 90],
    ];

    return (float) ($map[$goal][$action] ?? 50.0);
}

function agent_core_nba_capability_fit(string $action, array $ctx): float
{
    $caps = is_array($ctx['caps'] ?? null) ? $ctx['caps'] : [];
    $has = static fn (string $c): bool => in_array($c, $caps, true);

    return match ($action) {
        'OFFER_BOOKING' => $has('booking') ? 95.0 : 0.0,
        'START_ORDER', 'START_CHECKOUT' => $has('cart') ? 95.0 : 0.0,
        'SHOW_PRODUCT', 'RECOMMEND', 'CHECK_AVAILABILITY' => $has('catalog') ? 90.0 : 40.0,
        'SHOW_SERVICE' => 75.0,
        'TRACK_ORDER' => 70.0,
        default => 80.0,
    };
}

function agent_core_nba_readiness_fit(string $action, string $readiness): float
{
    $map = [
        'LOW'        => ['ANSWER' => 90, 'RECOMMEND' => 75, 'START_ORDER' => 10],
        'EXPLORING'  => ['ANSWER' => 88, 'SHOW_SERVICE' => 85, 'RECOMMEND' => 80],
        'INTERESTED' => ['OFFER_QUOTE' => 85, 'RECOMMEND' => 82],
        'QUALIFIED'  => ['OFFER_BOOKING' => 82, 'START_ORDER' => 80],
        'READY'      => ['START_ORDER' => 92, 'OFFER_BOOKING' => 90, 'COMPLETE' => 88],
        'COMPLETING' => ['COMPLETE' => 95, 'START_CHECKOUT' => 90],
    ];

    return (float) ($map[$readiness][$action] ?? 55.0);
}

function agent_core_nba_context_relevance(string $action, array $ctx): float
{
    if (($ctx['affirmation'] ?? '') === 'confirm' && $action === 'COMPLETE') {
        return 95.0;
    }
    if (($ctx['affirmation'] ?? '') === 'cancel' && in_array($action, ['RESOLVE_SUPPORT', 'STOP'], true)) {
        return 90.0;
    }
    if (($ctx['ci_nba'] ?? '') === 'confirm_pending' && $action === 'COMPLETE') {
        return 92.0;
    }

    return 60.0;
}

function agent_core_nba_expected_outcome(string $action, array $ctx): float
{
    if ($action === 'COLLECT_REQUIRED_INFORMATION' && ($ctx['missing'] ?? []) !== []) {
        return 88.0;
    }
    if ($action === 'OFFER_QUOTE' && !empty($ctx['knowledge_available'])) {
        return 90.0;
    }
    if ($action === 'RESOLVE_SUPPORT' && !empty($ctx['support_need'])) {
        return 92.0;
    }

    return 60.0;
}

function agent_core_nba_explicit_request(string $action, array $ctx): bool
{
    $lower = (string) ($ctx['lower'] ?? '');
    if ($action === 'OFFER_HUMAN_HANDOFF' && preg_match('/\b(human|person|agent|representative)\b/u', $lower)) {
        return true;
    }
    if ($action === 'OFFER_BOOKING' && preg_match('/\b(book|appointment|schedule)\b/u', $lower)) {
        return true;
    }
    if (in_array($action, ['START_ORDER', 'START_CHECKOUT'], true) && preg_match('/\b(buy|order|checkout|purchase)\b/u', $lower)) {
        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_nba_map_to_legacy(string $action, array $ctx): string
{
    $missing = is_array($ctx['missing'] ?? null) ? $ctx['missing'] : [];
    $affirmation = (string) ($ctx['affirmation'] ?? 'none');
    $goal = (string) ($ctx['goal'] ?? '');

    if ($affirmation === 'cancel') {
        return 'cancel_pending';
    }
    if ($affirmation === 'confirm') {
        return 'confirm_pending';
    }
    $missing = is_array($ctx['missing'] ?? null) ? $ctx['missing'] : [];
    if (($ctx['primary'] ?? '') === 'PRICE_INQUIRY'
        && array_intersect($missing, ['product', 'which_item']) !== []
        && in_array($action, ['ANSWER', 'CLARIFY', 'COLLECT_REQUIRED_INFORMATION'], true)
    ) {
        return 'ask_one_clarifier';
    }
    if (in_array($goal, ['social', 'stop'], true) || in_array($action, ['STOP', 'FOLLOW_UP'], true)) {
        return 'human_social_reply';
    }

    return match ($action) {
        'STOP', 'FOLLOW_UP' => 'human_social_reply',
        'CLARIFY', 'COLLECT_REQUIRED_INFORMATION', 'QUALIFY' => 'ask_one_clarifier',
        'OFFER_QUOTE' => 'answer_price',
        'SHOW_PRODUCT', 'SHOW_SERVICE', 'RECOMMEND' => 'open_menu',
        'CHECK_AVAILABILITY' => 'answer_availability',
        'START_ORDER', 'START_CHECKOUT' => 'open_cart',
        'OFFER_BOOKING', 'COMPLETE' => $missing !== [] ? 'ask_one_clarifier' : 'confirm_pending',
        'OFFER_HUMAN_HANDOFF' => 'offer_human',
        'RESOLVE_SUPPORT', 'TRACK_ORDER', 'HANDLE_OBJECTION', 'ANSWER' => 'answer_turn',
        default => 'answer_turn',
    };
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 */
function agent_core_nba_has_price_evidence(array $pack, array $plan): bool
{
    if (function_exists('agent_core_compose_has_price_evidence')) {
        return agent_core_compose_has_price_evidence($pack, [], is_array($pack['business_facts'] ?? null) ? $pack['business_facts'] : []);
    }
    $facts = is_array($pack['business_facts'] ?? null) ? $pack['business_facts'] : [];
    foreach ($facts as $fact) {
        $fact = (string) $fact;
        if ($fact !== '' && preg_match('/\b(pkr|rs\.?|price|cost|\d[\d,.\s]*(?:\/|per|month|session))\b/ui', $fact)) {
            return true;
        }
    }

    return false;
}
