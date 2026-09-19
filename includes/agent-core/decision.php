<?php
/**
 * Agent Mind Phase 2 — structured decision layer (need, goal, readiness, information model).
 * Extends Phase 1 fusion; does not replace conversation_intelligence_analyze().
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_READINESS_LEVELS = [
    'LOW',
    'EXPLORING',
    'INTERESTED',
    'QUALIFIED',
    'READY',
    'COMPLETING',
];

/** @var list<string> */
const AGENT_CORE_CUSTOMER_GOALS = [
    'learn',
    'compare',
    'choose',
    'purchase',
    'book',
    'get_support',
    'resolve_issue',
    'obtain_quote',
    'track_order',
    'speak_to_human',
    'cancel',
    'return',
    'understand_options',
    'social',
    'stop',
];

/**
 * Enrich plan with Phase 2 decision fields after intelligence fusion.
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function agent_core_decision_enrich_plan(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $intent,
    array $source,
    array $pack
): array {
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $primary = trim((string) ($plan['primary_intent'] ?? $intelligence['primary_intent'] ?? ''));
    $entities = is_array($intelligence['entities'] ?? null) ? $intelligence['entities'] : [];
    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    $knownList = is_array($plan['known_information'] ?? null) ? $plan['known_information'] : [];
    $affirmation = trim((string) ($intelligence['affirmation'] ?? 'none'));
    $purchaseStage = trim((string) ($intelligence['purchase_stage'] ?? $plan['readiness'] ?? 'interest'));
    $confidence = (float) ($intelligence['confidence'] ?? 0.0);
    $ambiguity = (float) ($intelligence['ambiguity'] ?? 0.0);

    $structuredIntents = agent_core_decision_structured_intents($intelligence);
    $need = agent_core_decision_infer_need($text, $primary, $entities, $memory, $intelligence, $plan);
    $goal = agent_core_decision_infer_goal($text, $primary, $purchaseStage, $affirmation, $intelligence, $plan, $need);
    $readinessLevel = agent_core_decision_infer_readiness($text, $primary, $purchaseStage, $affirmation, $intelligence, $plan, $need);
    $conversationStage = agent_core_decision_conversation_stage($readinessLevel, $primary, $plan);
    $infoModel = agent_core_decision_information_model($plan, $intelligence, $turnCtx, $conv, $pack, $readinessLevel);

    $plan['structured_intents'] = $structuredIntents;
    $plan['customer_need'] = $need['label'];
    $plan['customer_need_detail'] = $need['detail'];
    $plan['customer_goal'] = $goal;
    $plan['readiness'] = $readinessLevel;
    $plan['readiness_raw'] = $purchaseStage;
    $plan['conversation_stage'] = $conversationStage;
    $plan['information'] = $infoModel;
    $plan['known_information'] = $infoModel['known'];
    $plan['required_information'] = $infoModel['required'];
    $plan['missing_information'] = $infoModel['missing'];
    $plan['optional_information'] = $infoModel['optional'];
    $plan['intent_confidence'] = round(max(0.0, min(1.0, $confidence)), 2);
    $plan['need_confidence'] = $need['confidence'];
    $plan['goal_confidence'] = agent_core_decision_goal_confidence($goal, $primary, $confidence);
    $plan['business_capabilities'] = agent_core_decision_capabilities($pack, $turnCtx);
    $plan['message_budget'] = agent_core_decision_message_budget($primary, $readinessLevel, $structuredIntents, $plan);

    $plan['response_goal'] = agent_core_decision_response_goal(
        $plan,
        $need,
        $goal,
        $readinessLevel,
        $conversationStage
    );

    if ($readinessLevel === 'LOW') {
        $plan['advance_allowed'] = false;
        if (($plan['next_best_action'] ?? '') !== 'human_social_reply') {
            $plan['cta_mode'] = 'answer';
        }
        $plan['forbid_generic_loop'] = true;
    }
    if ($goal === 'stop' || $goal === 'social') {
        $plan['stop_allowed'] = true;
        $plan['advance_allowed'] = false;
    }
    if (in_array($readinessLevel, ['READY', 'COMPLETING'], true)
        && !in_array($goal, ['get_support', 'resolve_issue', 'stop', 'social'], true)
    ) {
        $plan['stop_allowed'] = false;
        $plan['advance_allowed'] = true;
        $plan['forbid_generic_loop'] = true;
    }

    if ($ambiguity >= 0.55 && $plan['intent_confidence'] < 0.6) {
        $plan['action_confidence'] = round(max(0.1, 1.0 - $ambiguity), 2);
    } else {
        $plan['action_confidence'] = round(max(0.2, min(1.0, ($plan['intent_confidence'] + $need['confidence']) / 2)), 2);
    }

    $plan['ci_next_best_action'] = trim((string) ($plan['next_best_action'] ?? ''));
    if (function_exists('agent_core_multi_intent_apply')) {
        $plan = agent_core_multi_intent_apply($plan, $intelligence, $turnCtx, $conv, $pack);
    }
    if (function_exists('agent_core_nba_apply')) {
        $plan = agent_core_nba_apply($plan, $intelligence, $turnCtx, $conv, $pack, $need);
    }
    if (function_exists('agent_core_cta_apply')) {
        $plan = agent_core_cta_apply($plan, $intelligence, $turnCtx, $conv, $pack);
    }

    require_once __DIR__ . '/outcome.php';
    $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
    $plan['business_outcome'] = agent_core_business_outcome_for_turn($plan, $bot);
    require_once __DIR__ . '/capabilities.php';
    $plan['capability_states'] = business_capability_states_for_bot($bot);
    $botCaps = business_capabilities_for_bot($bot);
    $existingCaps = is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [];
    $plan['business_capabilities'] = array_values(array_unique(array_merge($existingCaps, $botCaps)));

    if (is_file(__DIR__ . '/booking-state.php')) {
        require_once __DIR__ . '/booking-state.php';
        $plan = agent_booking_plan_enrich($plan, $turnCtx, $conv, $intelligence);
    }

    if (($plan['customer_need'] ?? '') === 'invite_speaker'
        && ($plan['primary_intent'] ?? '') === 'EVENT_INVITATION'
        && in_array('human_handoff', is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [], true)
    ) {
        $missing = is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [];
        $eventRequired = ['event_date', 'event_time', 'event_location', 'event_topic', 'organizer', 'invitation_card'];
        $stillMissing = array_intersect($missing, $eventRequired);
        if ($stillMissing === [] && count($missing) <= 1) {
            $plan['allow_mutating_tools'] = true;
            $plan['tool_calls'] = agent_core_plan_append_tool($plan['tool_calls'] ?? [], 'human_handoff.create', [
                'request_type' => 'event_invitation',
                'payload'      => [
                    'event_date'  => (string) ($intelligence['entities']['date'] ?? ''),
                    'event_time'  => (string) ($intelligence['entities']['time'] ?? ''),
                    'event_topic' => (string) ($intelligence['entities']['event_topic'] ?? ''),
                ],
            ]);
            $plan['response_goal'] = ($plan['response_goal'] ?? '')
                . ' If human_handoff.create succeeds, you may say the request was sent for team review — not that anyone accepted.';
        }
    }

    if (($plan['primary_intent'] ?? '') === 'EVENT_INVITATION' || ($plan['customer_need'] ?? '') === 'invite_speaker') {
        if (function_exists('knowledge_event_date_issues')) {
            require_once dirname(__DIR__) . '/bot-knowledge.php';
            $dateIssues = knowledge_event_date_issues($text);
            if (!empty($dateIssues['conflict']) || !empty($dateIssues['past'])) {
                $plan['clarification_needed'] = true;
                $plan['missing_information'] = array_values(array_unique(array_merge(
                    is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [],
                    ['event_date']
                )));
                $plan['response_goal'] = ($plan['response_goal'] ?? '')
                    . ' Clarify the intended event date — resolve conflicting or past dates before proceeding.';
            }
        }
    }

    return $plan;
}

/**
 * @param array<string, mixed> $intelligence
 * @return list<array{intent: string, role: string, confidence: float}>
 */
function agent_core_decision_structured_intents(array $intelligence): array
{
    $out = [];
    foreach (is_array($intelligence['intents'] ?? null) ? $intelligence['intents'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = trim((string) ($row['intent'] ?? ''));
        if ($label === '') {
            continue;
        }
        $out[] = [
            'intent'     => $label,
            'role'       => (string) ($row['role'] ?? 'secondary'),
            'confidence' => round((float) ($row['confidence'] ?? 0.5), 2),
        ];
    }
    if ($out === [] && trim((string) ($intelligence['primary_intent'] ?? '')) !== '') {
        $out[] = [
            'intent'     => (string) $intelligence['primary_intent'],
            'role'       => 'primary',
            'confidence' => round((float) ($intelligence['confidence'] ?? 0.5), 2),
        ];
    }

    return $out;
}

/**
 * Evidence-based customer need — not invented motivations.
 *
 * @param array<string, mixed> $entities
 * @param array<string, mixed> $memory
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $plan
 * @return array{label: string, detail: string, confidence: float}
 */
function agent_core_decision_infer_need(
    string $text,
    string $primary,
    array $entities,
    array $memory,
    array $intelligence,
    array $plan
): array {
    $lower = mb_strtolower(trim($text));
    $product = trim((string) ($entities['product'] ?? $memory['product'] ?? $memory['last_product'] ?? ''));
    $budget = trim((string) ($entities['budget'] ?? $memory['budget'] ?? ''));
    $color = trim((string) ($entities['color'] ?? ''));
    $service = trim((string) ($memory['service'] ?? ''));

    if (preg_match('/\b(thanks|that\'?s all|that is all|goodbye|bye)\b/u', $lower)) {
        return ['label' => 'close_conversation', 'detail' => 'Customer indicates they are done', 'confidence' => 0.88];
    }
    if (preg_match('/\b(just browsing|only looking|not ready|maybe later)\b/u', $lower)) {
        return ['label' => 'explore_options', 'detail' => 'Browsing without immediate purchase intent', 'confidence' => 0.85];
    }
    if (in_array($primary, ['DELIVERY_QUERY', 'COMPLAINT'], true)
        || preg_match('/\b(order hasn\'?t arrived|not arrived|late delivery|order is late|delivery is late|still waiting|where is my order)\b/u', $lower)
    ) {
        return ['label' => 'resolve_delivery', 'detail' => 'Resolve delivery or order status issue', 'confidence' => 0.9];
    }
    if (preg_match('/\b(payment failed|payment didn\'?t|transaction failed|card declined|duplicate charge|charged twice|double charge|payment not received|payment reversed)\b/u', $lower)
        || ($primary === 'PAYMENT_REQUEST' && preg_match('/\b(failed|declined|not received|reversed|pending|issue|problem)\b/u', $lower))
    ) {
        return ['label' => 'resolve_payment', 'detail' => 'Resolve payment or billing issue', 'confidence' => 0.91];
    }
    if (preg_match('/\b(refund pending|refund hasn\'?t|refund missing|waiting for refund)\b/u', $lower)) {
        return ['label' => 'resolve_refund', 'detail' => 'Refund status support', 'confidence' => 0.9];
    }
    if (in_array($primary, ['RETURN_REQUEST', 'CANCELLATION'], true) || preg_match('/\b(refund|return)\b/u', $lower)) {
        return ['label' => 'obtain_refund', 'detail' => 'Refund or return support', 'confidence' => 0.88];
    }
    if ($primary === 'HUMAN_REQUEST' || ($plan['next_best_action'] ?? '') === 'offer_human') {
        return ['label' => 'speak_to_human', 'detail' => 'Customer requested a person', 'confidence' => 0.92];
    }
    if ($primary === 'EVENT_INVITATION' || preg_match(
        '/\b(invite|invitation|guest speaker|speak at|talk at|keynote|invite (?:your )?(?:ceo|founder|director|speaker|principal|representative))\b/u',
        $lower
    )) {
        return ['label' => 'invite_speaker', 'detail' => 'Collect event invitation details for review', 'confidence' => 0.9];
    }
    if ($primary === 'BOOKING_REQUEST' || preg_match('/\b(book|appointment|see someone|schedule)\b/u', $lower)) {
        $detail = $service !== '' ? "Schedule: {$service}" : 'Schedule an appointment';

        return ['label' => 'book_appointment', 'detail' => $detail, 'confidence' => 0.86];
    }
    if ($primary === 'PRICE_INQUIRY' || ($plan['next_best_action'] ?? '') === 'answer_price') {
        $bits = array_filter([$product !== '' ? $product : null, $budget !== '' ? "budget {$budget}" : null]);
        $detail = $bits !== [] ? 'Pricing for ' . implode(', ', $bits) : 'Verified pricing information';

        return ['label' => 'learn_pricing', 'detail' => $detail, 'confidence' => 0.84];
    }
    if (in_array($primary, ['ORDER_REQUEST', 'CART'], true) || preg_match('/\b(buy|purchase|place order|checkout)\b/u', $lower)) {
        $detail = trim(implode(' ', array_filter([$color, $product]))) ?: 'Complete a purchase';

        return ['label' => 'complete_purchase', 'detail' => $detail, 'confidence' => 0.82];
    }
    if (in_array($primary, ['PRODUCT_SEARCH', 'PRODUCT_AVAILABILITY', 'PRODUCT_COMPARISON'], true)) {
        if ($budget === '' && preg_match('/\b(\d[\d,.\s]*\s*k|\d[\d,.\s]{2,})\b/u', $lower, $bm)) {
            $budget = trim((string) ($bm[1] ?? ''));
        }
        $use = '';
        if (preg_match('/\b(for (?:my )?(office|work|home|gym|travel))\b/u', $lower, $m)) {
            $use = trim((string) ($m[1] ?? ''));
        }
        $bits = array_filter([
            $use !== '' ? ucfirst($use) : null,
            $product !== '' ? $product : null,
            $color !== '' ? $color : null,
            $budget !== '' ? "around {$budget}" : null,
        ]);
        $detail = $bits !== [] ? implode(' ', $bits) : 'Find a suitable product or service';

        return ['label' => 'find_product', 'detail' => $detail, 'confidence' => 0.78];
    }
    if (preg_match('/\b(office|for work|for home|for gym|for travel)\b/u', $lower)) {
        if ($budget === '' && preg_match('/\b(\d[\d,.\s]*\s*k|\d[\d,.\s]{2,})\b/u', $lower, $bm)) {
            $budget = trim((string) ($bm[1] ?? ''));
        }
        $use = '';
        if (preg_match('/\b(for (?:my )?(office|work|home|gym|travel))\b/u', $lower, $m)) {
            $use = trim((string) ($m[1] ?? ''));
        }
        $detail = trim(($use !== '' ? ucfirst($use) . ' ' : '') . ($product !== '' ? $product : 'item') . ($budget !== '' ? " around {$budget}" : ''));

        return ['label' => 'find_product', 'detail' => $detail !== '' ? $detail : 'Product within stated use/budget', 'confidence' => 0.75];
    }
    if (preg_match('/\b(anxiety|worried|stressed|overwhelmed|support)\b/u', $lower) || in_array($intelligence['emotion'] ?? '', ['worried', 'distressed'], true)) {
        return ['label' => 'personal_support', 'detail' => 'Emotional support or appropriate professional conversation', 'confidence' => 0.7];
    }
    if (in_array($primary, ['GENERAL_INFORMATION', 'MENU'], true) || preg_match('/\b(what do you offer|services|what are your)\b/u', $lower)) {
        return ['label' => 'understand_offerings', 'detail' => 'Learn what the business offers', 'confidence' => 0.8];
    }
    if (in_array($primary, ['GREETING', 'FOLLOW_UP'], true) || !empty($intelligence['is_social'])) {
        return ['label' => 'social', 'detail' => 'Social or courtesy exchange', 'confidence' => 0.75];
    }

    return ['label' => 'answer_question', 'detail' => 'Answer the current question directly', 'confidence' => 0.55];
}

function agent_core_decision_infer_goal(
    string $text,
    string $primary,
    string $purchaseStage,
    string $affirmation,
    array $intelligence,
    array $plan,
    array $need = []
): string {
    $lower = mb_strtolower(trim($text));
    $needLabel = trim((string) ($need['label'] ?? ''));

    if (preg_match('/\b(thanks|that\'?s all|that is all|goodbye|bye|nothing else)\b/u', $lower)) {
        return 'stop';
    }
    if ($needLabel === 'resolve_delivery' || $needLabel === 'obtain_refund' || $needLabel === 'resolve_refund') {
        return $needLabel === 'obtain_refund' || $needLabel === 'resolve_refund' ? 'return' : 'resolve_issue';
    }
    if ($needLabel === 'resolve_payment') {
        return 'resolve_issue';
    }
    if ($affirmation === 'cancel' || $primary === 'CANCELLATION') {
        return 'cancel';
    }
    if ($affirmation === 'confirm' || $purchaseStage === 'payment_ready') {
        return in_array($primary, ['BOOKING_REQUEST', 'APPOINTMENT'], true) ? 'book' : 'purchase';
    }
    if (in_array($primary, ['RETURN_REQUEST'], true) || preg_match('/\brefund\b/u', $lower)) {
        return 'return';
    }
    if ($primary === 'HUMAN_REQUEST' || ($plan['next_best_action'] ?? '') === 'offer_human') {
        return 'speak_to_human';
    }
    if (in_array($primary, ['DELIVERY_QUERY', 'COMPLAINT', 'SUPPORT'], true)) {
        return 'resolve_issue';
    }
    if ($primary === 'BOOKING_REQUEST' || preg_match('/\b(book|appointment|schedule)\b/u', $lower)) {
        return 'book';
    }
    if (in_array($primary, ['ORDER_REQUEST', 'CART', 'PAYMENT_REQUEST'], true) || $purchaseStage === 'purchase_intent') {
        return 'purchase';
    }
    if (preg_match('/\b(buy|purchase|place order|how do i order|checkout)\b/u', $lower)) {
        return 'purchase';
    }
    if ($primary === 'PRODUCT_COMPARISON') {
        return 'compare';
    }
    if ($primary === 'PRICE_INQUIRY') {
        return in_array($purchaseStage, ['purchase_intent', 'payment_ready'], true) ? 'purchase' : 'obtain_quote';
    }
    if (in_array($primary, ['PRODUCT_SEARCH', 'PRODUCT_AVAILABILITY'], true)) {
        return $purchaseStage === 'purchase_intent' ? 'choose' : 'understand_options';
    }
    if (preg_match('/\b(just browsing|only looking)\b/u', $lower)) {
        return 'learn';
    }
    if (preg_match('/\b(track|where is my order|order status)\b/u', $lower)) {
        return 'track_order';
    }
    if (in_array($primary, ['GREETING', 'FOLLOW_UP'], true) || !empty($intelligence['is_social'])) {
        return 'social';
    }
    if ($needLabel === 'understand_offerings') {
        return 'learn';
    }

    return 'learn';
}

function agent_core_decision_infer_readiness(
    string $text,
    string $primary,
    string $purchaseStage,
    string $affirmation,
    array $intelligence,
    array $plan,
    array $need = []
): string {
    $lower = mb_strtolower(trim($text));
    $needLabel = trim((string) ($need['label'] ?? ''));

    if ($needLabel === 'close_conversation') {
        return 'COMPLETING';
    }
    if (in_array($needLabel, ['resolve_delivery', 'obtain_refund', 'personal_support'], true)) {
        return 'INTERESTED';
    }
    if ($needLabel === 'understand_offerings' || preg_match('/\b(what do you offer|what services|tell me about)\b/u', $lower)) {
        return 'EXPLORING';
    }

    if (preg_match('/\b(thanks|that\'?s all|that is all|goodbye|bye)\b/u', $lower)) {
        return 'COMPLETING';
    }
    if (preg_match('/\b(just browsing|only looking|maybe later|not ready)\b/u', $lower)) {
        return 'LOW';
    }
    if ($affirmation === 'confirm' || preg_match('/\b(yes,? (?:book|place|order)|place the order|book it)\b/u', $lower)) {
        return 'COMPLETING';
    }
    if ($needLabel === 'complete_purchase' && preg_match('/\b(buy|purchase|place order|how do i order|checkout)\b/u', $lower)) {
        return 'READY';
    }
    if (in_array($primary, ['ORDER_REQUEST', 'CART', 'PAYMENT_REQUEST'], true) && !in_array($needLabel, ['resolve_delivery', 'obtain_refund'], true)) {
        return 'READY';
    }
    if ($purchaseStage === 'purchase_intent' && !in_array($needLabel, ['resolve_delivery', 'obtain_refund'], true)) {
        return 'READY';
    }
    if ($purchaseStage === 'payment_ready') {
        return 'COMPLETING';
    }
    if ($purchaseStage === 'completed') {
        return 'COMPLETING';
    }
    if (in_array($purchaseStage, ['negotiation', 'comparison'], true)) {
        return 'QUALIFIED';
    }
    if ($purchaseStage === 'consideration' || $primary === 'PRICE_INQUIRY') {
        return 'INTERESTED';
    }
    if (preg_match('/\b(sounds good|that works|let\'?s do it)\b/u', $lower)) {
        return 'QUALIFIED';
    }
    if (preg_match('/\b(what do you offer|what services|tell me about)\b/u', $lower) || $primary === 'GENERAL_INFORMATION') {
        return 'EXPLORING';
    }
    if (in_array($primary, ['GREETING', 'FOLLOW_UP'], true) || !empty($intelligence['is_social'])) {
        return 'LOW';
    }
    if (in_array($primary, ['COMPLAINT', 'SUPPORT', 'RETURN_REQUEST', 'DELIVERY_QUERY'], true)) {
        return 'INTERESTED';
    }

    return 'EXPLORING';
}

function agent_core_decision_conversation_stage(string $readiness, string $primary, array $plan): string
{
    if ($readiness === 'COMPLETING') {
        return 'COMPLETED';
    }
    if ($readiness === 'LOW') {
        return 'DISCOVERING';
    }
    if (in_array($primary, ['COMPLAINT', 'SUPPORT', 'RETURN_REQUEST', 'DELIVERY_QUERY'], true)) {
        return 'SUPPORTING';
    }
    if ($readiness === 'READY' || $readiness === 'COMPLETING') {
        return in_array($primary, ['BOOKING_REQUEST'], true) ? 'CONVERTING' : 'READY_TO_CONVERT';
    }
    if ($readiness === 'QUALIFIED') {
        return 'DECIDING';
    }
    if ($readiness === 'INTERESTED') {
        return 'QUALIFYING';
    }
    if ($readiness === 'EXPLORING') {
        return 'DISCOVERING';
    }

    return trim((string) ($plan['conversation_state'] ?? '')) !== '' ? (string) $plan['conversation_state'] : 'UNDERSTANDING';
}

/** @var array<string, list<string>> Required fields per action class (Phase 2E audit). */
const AGENT_CORE_ACTION_REQUIRED_FIELDS = [
    'BOOKING'          => ['service', 'date', 'time'],
    'EVENT'            => ['event_name', 'event_date', 'organizer', 'contact'],
    'ORDER'            => ['product', 'quantity'],
    'CHECKOUT'         => ['product'],
    'DELIVERY'         => ['location'],
    'REFUND'           => ['order_reference'],
    'RETURN'           => ['order_reference', 'reason'],
    'CANCELLATION'     => ['order_reference'],
    'HUMAN_HANDOFF'    => [],
    'QUOTE'            => ['which_item'],
];

/**
 * Booking date/time slots — date and time are distinct; "tomorrow" alone is not sufficient for time.
 *
 * @param list<string> $known
 * @param array<string, mixed> $entities
 * @param array<string, mixed> $memory
 * @return array{date: bool, time: bool, service: bool}
 */
function agent_core_decision_booking_field_status(
    array $known,
    array $entities,
    array $memory,
    string $text
): array {
    $hasDate = false;
    $hasTime = false;
    $hasService = false;

    if (trim((string) ($entities['date'] ?? '')) !== '') {
        $hasDate = true;
    }
    if (trim((string) ($entities['time'] ?? '')) !== '') {
        $hasTime = true;
    }
    if (trim((string) ($entities['service'] ?? '')) !== '') {
        $hasService = true;
    }

    $lower = mb_strtolower(trim($text));
    if (preg_match('/\b(morning|afternoon|evening|noon|night)\b/u', $lower)) {
        $hasTime = true;
    }
    if (preg_match('/\b(at\s+\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\b/u', $lower)) {
        $hasTime = true;
    }
    if (preg_match('/\b(consultation|appointment|table|session|class|cut|massage|coaching)\b/u', $lower)) {
        $hasService = true;
    }

    foreach ($known as $row) {
        $parts = explode('=', (string) $row, 2);
        $key = mb_strtolower(trim($parts[0] ?? ''));
        $val = trim($parts[1] ?? '');
        if ($key === 'date' && $val !== '') {
            $hasDate = true;
        }
        if ($key === 'time' && $val !== '') {
            $hasTime = true;
        }
        if ($key === 'service' && $val !== '') {
            $hasService = true;
        }
        if ($key === 'date_time' && $val !== '') {
            agent_core_decision_apply_date_time_value($val, $hasDate, $hasTime);
        }
    }

    foreach ($memory as $key => $val) {
        $k = mb_strtolower(trim((string) $key));
        $v = trim(is_scalar($val) ? (string) $val : '');
        if ($k === 'date' && $v !== '') {
            $hasDate = true;
        }
        if ($k === 'time' && $v !== '') {
            $hasTime = true;
        }
        if ($k === 'service' && $v !== '') {
            $hasService = true;
        }
        if ($k === 'date_time' && $v !== '') {
            agent_core_decision_apply_date_time_value($v, $hasDate, $hasTime);
        }
    }

    if (preg_match('/\b(today|tomorrow|next week|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u', $lower)) {
        $hasDate = true;
    }
    if (preg_match('/\b(sharp\s+(?:pm|am)|(?:pm|am)\s+sharp|at\s+sharp)\b/u', $lower)
        && !preg_match('/\b(at\s+)?\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/u', $lower)
    ) {
        $hasTime = false;
    }

    return ['date' => $hasDate, 'time' => $hasTime, 'service' => $hasService];
}

function agent_core_decision_apply_date_time_value(string $value, bool &$hasDate, bool &$hasTime): void
{
    $v = mb_strtolower(trim($value));
    if ($v === '') {
        return;
    }
    if (preg_match('/\b(today|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday|sunday|\d{1,2}[\/\-]\d{1,2})/u', $v)) {
        $hasDate = true;
    }
    if (preg_match('/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm)|morning|afternoon|evening|noon|at\s+\d)/u', $v)) {
        $hasTime = true;
    }
}

/**
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $pack
 * @return array{known: list<string>, required: list<string>, missing: list<string>, optional: list<string>}
 */
function agent_core_decision_information_model(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $pack,
    string $readinessLevel = 'EXPLORING'
): array {
    $known = is_array($plan['known_information'] ?? null) ? $plan['known_information'] : [];
    if ($known === [] && function_exists('agent_core_plan_known_information')) {
        $known = agent_core_plan_known_information($conv, $intelligence);
    }

    $nba = trim((string) ($plan['next_best_action'] ?? ''));
    $primary = trim((string) ($plan['primary_intent'] ?? ''));
    $required = [];
    $optional = [];
    $missing = [];

    if (in_array($nba, ['ask_one_clarifier', 'confirm_pending'], true) || !empty($plan['clarification_needed'])) {
        foreach (is_array($intelligence['missing_information'] ?? null) ? $intelligence['missing_information'] : [] as $m) {
            $required[] = (string) $m;
        }
    }
    if ($primary === 'BOOKING_REQUEST' || $nba === 'confirm_pending') {
        $required = array_values(array_unique(array_merge($required, AGENT_CORE_ACTION_REQUIRED_FIELDS['BOOKING'])));
        $optional[] = 'customer_name';
    }
    if ($primary === 'EVENT_INVITATION' || ($plan['customer_need'] ?? '') === 'invite_speaker') {
        $required = array_values(array_unique(array_merge($required, [
            'event_name', 'event_date', 'event_time', 'event_location', 'event_topic', 'organizer', 'invitation_card',
        ])));
        $optional[] = 'audience';
        $optional[] = 'contact_person';
    }
    if (in_array($primary, ['ORDER_REQUEST', 'CART'], true)) {
        $required = array_values(array_unique(array_merge($required, AGENT_CORE_ACTION_REQUIRED_FIELDS['ORDER'])));
    }
    if (($plan['next_best_action'] ?? '') === 'answer_price' && !function_exists('conversation_intelligence_is_general_price_list')) {
        $required[] = 'which_item';
    }

    $entities = is_array($intelligence['entities'] ?? null) ? $intelligence['entities'] : [];
    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    $text = trim((string) ($turnCtx['text'] ?? $plan['asked'] ?? ''));
    $bookingActive = in_array($primary, ['BOOKING_REQUEST'], true) || $nba === 'confirm_pending';
    $bookingSlots = $bookingActive
        ? agent_core_decision_booking_field_status($known, $entities, $memory, $text)
        : null;

    $knownKeys = [];
    foreach ($known as $row) {
        $parts = explode('=', (string) $row, 2);
        if (isset($parts[0])) {
            $knownKeys[] = mb_strtolower(trim($parts[0]));
        }
    }
    $map = [
        'product'       => ['product', 'which_item'],
        'which_item'    => ['product', 'which_item'],
        'service'       => ['service'],
        'customer_name' => ['customer_name', 'name'],
        'quantity'      => ['quantity', 'qty'],
        'date'          => ['date'],
        'time'          => ['time'],
    ];
    foreach ($required as $req) {
        if ($bookingSlots !== null && in_array($req, ['date', 'time', 'service'], true)) {
            if ($req === 'date' && !$bookingSlots['date']) {
                $missing[] = 'date';
            }
            if ($req === 'time' && !$bookingSlots['time']) {
                $missing[] = 'time';
            }
            if ($req === 'service' && !$bookingSlots['service']) {
                $missing[] = 'service';
            }
            continue;
        }
        $aliases = $map[$req] ?? [$req];
        $has = false;
        foreach ($aliases as $alias) {
            if (in_array($alias, $knownKeys, true)) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            $missing[] = $req;
        }
    }

    if ($missing === [] && is_array($intelligence['missing_information'] ?? null)) {
        foreach ($intelligence['missing_information'] as $m) {
            $m = (string) $m;
            if (!in_array($m, $knownKeys, true) && !in_array($m, $missing, true)) {
                $missing[] = $m;
            }
        }
    }

    $qual = trim((string) ($pack['qualify_read'] ?? ''));
    if ($qual !== '' && !in_array($readinessLevel, ['READY', 'COMPLETING', 'QUALIFIED'], true)) {
        $optional[] = 'qualification_fields';
    }

    return [
        'known'    => array_values(array_unique($known)),
        'required' => array_values(array_unique($required)),
        'missing'  => array_values(array_unique($missing)),
        'optional' => array_values(array_unique($optional)),
    ];
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $turnCtx
 * @return list<string>
 */
function agent_core_decision_capabilities(array $pack, array $turnCtx): array
{
    $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
    $profile = is_array($turnCtx['profile'] ?? null) ? $turnCtx['profile'] : [];
    $caps = is_array($profile['capabilities'] ?? null) ? $profile['capabilities'] : [];
    if ($caps === [] && is_array($pack['capabilities'] ?? null)) {
        $caps = $pack['capabilities'];
    }
    if ($caps === [] && $bot !== [] && function_exists('agent_core_business_profile')) {
        $caps = agent_core_business_profile($bot)['capabilities'] ?? [];
    }

    return array_values(array_unique(array_map('strval', $caps)));
}

/**
 * @param list<array{intent: string, role: string, confidence: float}> $structuredIntents
 */
function agent_core_decision_message_budget(string $primary, string $readiness, array $structuredIntents, array $plan): int
{
    if (in_array($primary, ['GREETING', 'FOLLOW_UP'], true) && count($structuredIntents) <= 1) {
        return 1;
    }
    if (($plan['customer_goal'] ?? '') === 'stop') {
        return 1;
    }
    if ($primary === 'PRICE_INQUIRY' || ($plan['next_best_action'] ?? '') === 'answer_price') {
        return 1;
    }
    if (count($structuredIntents) >= 3) {
        return 1;
    }
    if (in_array($readiness, ['READY', 'COMPLETING'], true)) {
        return 2;
    }
    if (in_array($primary, ['COMPLAINT', 'SUPPORT', 'RETURN_REQUEST'], true)) {
        return 3;
    }

    return 2;
}

function agent_core_decision_goal_confidence(string $goal, string $primary, float $intentConfidence): float
{
    $boost = match ($goal) {
        'stop', 'cancel', 'return', 'speak_to_human' => 0.15,
        'resolve_issue' => 0.1,
        default => 0.0,
    };

    return round(max(0.2, min(1.0, $intentConfidence + $boost)), 2);
}

/**
 * @param array{label: string, detail: string, confidence: float} $need
 */
function agent_core_decision_response_goal(
    array $plan,
    array $need,
    string $goal,
    string $readiness,
    string $stage
): string {
    $base = trim((string) ($plan['response_goal'] ?? ''));
    $bits = [];
    if ($base !== '') {
        $bits[] = $base;
    }
    $bits[] = 'Customer need: ' . $need['detail'] . '.';
    $bits[] = 'Customer goal: ' . $goal . '.';
    $bits[] = 'Readiness: ' . $readiness . '. Stage: ' . $stage . '.';
    $budget = (int) ($plan['message_budget'] ?? 2);
    $bits[] = "Aim to satisfy this turn within {$budget} outbound message(s) when possible.";
    if ($readiness === 'LOW') {
        $bits[] = 'Do not push conversion — answer and pause.';
    }
    if (in_array($readiness, ['READY', 'COMPLETING'], true)) {
        $bits[] = 'Skip education — move toward the available action.';
    }
    $missing = is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [];
    if ($missing !== []) {
        $bits[] = 'Missing required only: ' . implode(', ', array_slice($missing, 0, 4)) . '.';
    }

    return implode(' ', $bits);
}
