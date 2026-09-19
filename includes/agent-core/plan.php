<?php
/**
 * THINK + PLAN. Read-only tools only. Schedules BUSINESS / GENERAL / MIXED / FOLLOW_UP sources together.
 *
 * @return array{outcome: string, answer_kind: string, answer_first: string, source: string, route: array, tool_calls: list<array{name: string, args: array<string, mixed>}>, allow_casual: bool}
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_PHASE1_TOOLS = [
    'catalog.search',
    'catalog.get_product',
    'cart.view',
    'booking.offer',
    'booking.availability',
    'memory.read',
    'live_web.search',
    'hours.read',
    'orders.read',
];

/**
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 */
function agent_core_answer_kind(array $intent, array $source): string
{
    $flags = 0;
    foreach (['needs_web', 'needs_hours', 'needs_orders', 'needs_catalog', 'needs_memory'] as $key) {
        if (!empty($source[$key])) {
            $flags++;
        }
    }
    if ($flags >= 2 || (string) ($source['primary'] ?? '') === 'MIXED' || (string) ($intent['kind'] ?? '') === 'MIXED') {
        return 'MIXED';
    }
    $kind = (string) ($intent['kind'] ?? 'FOLLOW_UP');
    if (!empty($source['needs_web'])) {
        return 'GENERAL';
    }
    if (!empty($source['needs_hours']) || !empty($source['needs_orders']) || !empty($source['needs_catalog'])
        || in_array($kind, ['CATALOG', 'BOOKING', 'BUSINESS_INQUIRY'], true)
    ) {
        return 'BUSINESS';
    }
    if (!empty($intent['continue_thread']) || in_array($kind, ['FOLLOW_UP', 'CORRECTION', 'CHASE_UP', 'MEDIA'], true)) {
        return 'FOLLOW_UP';
    }

    return 'GENERAL';
}

/**
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $intelligence Conversation Intelligence analyze() output (Phase 1 fusion)
 * @return array<string, mixed>
 */
function agent_core_plan(array $turnCtx, array $conv, array $intent, array $source, array $pack, array $intelligence = []): array
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

    $allowed = AGENT_CORE_PHASE1_TOOLS;
    $wanted = [];
    foreach (is_array($intent['tools'] ?? null) ? $intent['tools'] : [] as $name) {
        $wanted[] = (string) $name;
    }
    if (!empty($source['needs_web'])) {
        $wanted[] = 'live_web.search';
    }
    if (!empty($source['needs_hours'])) {
        $wanted[] = 'hours.read';
    }
    if (!empty($source['needs_orders'])) {
        $wanted[] = 'orders.read';
    }
    if (!empty($source['needs_catalog'])) {
        $wanted[] = 'catalog.search';
    }
    $productId = (int) ($intent['product_id'] ?? $turnCtx['product_id'] ?? 0);
    if ($productId <= 0 && preg_match('/(?:^|\s)#(\d+)\b/u', $text, $m)) {
        $productId = (int) $m[1];
    }
    if ($productId > 0) {
        $wanted[] = 'catalog.get_product';
    }
    $casualKind = in_array($kind, ['SOCIAL', 'GREETING', 'OFF_TOPIC', 'IDENTITY', 'GENERAL'], true);
    $wantMemory = !empty($intent['continue_thread']) || $kind === 'CORRECTION'
        || ((int) ($turnCtx['lead_id'] ?? 0) > 0 && (int) ($turnCtx['bot_id'] ?? 0) > 0)
        || (is_array($conv['runtime_facts'] ?? null) && $conv['runtime_facts'] !== []);
    if (!$casualKind && !empty($source['needs_memory'])) {
        $wantMemory = true;
    }
    if ($wantMemory) {
        $wanted[] = 'memory.read';
    }

    $calls = [];
    $seen = [];
    foreach ($wanted as $name) {
        if (!in_array($name, $allowed, true) || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $args = ['query' => $text];
        if ($name === 'catalog.search' && trim((string) ($intent['referent'] ?? '')) !== '') {
            $args['query'] = trim((string) $intent['referent']);
        }
        if ($name === 'live_web.search') {
            $args['query'] = (string) ($source['search_query'] ?? $text);
        }
        if ($name === 'catalog.get_product') {
            $args['product_id'] = $productId;
        }
        $calls[] = ['name' => $name, 'args' => $args];
    }

    $casual = in_array($kind, ['SOCIAL', 'GREETING', 'OFF_TOPIC', 'IDENTITY', 'GENERAL'], true);
    $answerKind = agent_core_answer_kind($intent, $source);
    $available = [];
    $missing = [];
    foreach (['needs_web' => 'live_web', 'needs_hours' => 'hours', 'needs_orders' => 'orders', 'needs_catalog' => 'catalog', 'needs_memory' => 'memory'] as $flag => $label) {
        if (!empty($source[$flag])) {
            $available[] = $label;
        }
    }
    if (!empty($intent['needs_web'])) {
        $missing[] = 'live_evidence';
    }
    if (!empty($intent['clarification_needed'])) {
        $missing[] = 'referent';
    }
    if ($kind === 'MEDIA' && !empty($intent['clarification_needed'])) {
        $missing[] = 'media_description';
    }

    $plan = [
        'outcome'               => $kind,
        'answer_kind'           => $answerKind,
        'answer_first'          => $answerFirst,
        'source'                => (string) ($source['primary'] ?? 'GENERAL_GPT'),
        'route'                 => $source,
        'tool_calls'            => $calls,
        'allow_casual'          => $casual,
        'brand'                 => (string) ($pack['brand'] ?? ''),
        'asked'                 => $text,
        'referent'              => (string) ($intent['referent'] ?? ''),
        'available'             => $available,
        'missing'               => array_values(array_unique($missing)),
        'clarification_needed'  => !empty($intent['clarification_needed']),
        'action'                => $answerKind,
    ];

    if ($intelligence !== []) {
        $plan = agent_core_plan_apply_intelligence($plan, $intelligence, $turnCtx, $conv, $intent, $source);
        if (function_exists('agent_core_decision_enrich_plan')) {
            $plan = agent_core_decision_enrich_plan($plan, $intelligence, $turnCtx, $conv, $intent, $source, $pack);
        }
    }

    return $plan;
}

/**
 * Fuse Conversation Intelligence into the structured plan (deterministic — not prompt-only).
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function agent_core_plan_apply_intelligence(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $intent,
    array $source
): array {
    $nba = trim((string) ($intelligence['next_best_action'] ?? 'answer_turn'));
    $ciPrimary = trim((string) ($intelligence['primary_intent'] ?? ''));
    $secondary = is_array($intelligence['secondary_intents'] ?? null) ? $intelligence['secondary_intents'] : [];
    $emotion = trim((string) ($intelligence['emotion'] ?? 'neutral'));
    $readiness = trim((string) ($intelligence['purchase_stage'] ?? 'interest'));
    $convState = trim((string) ($intelligence['conversation_state'] ?? ''));
    $minQ = trim((string) ($intelligence['minimum_question'] ?? ''));
    $ciMissing = is_array($intelligence['missing_information'] ?? null) ? $intelligence['missing_information'] : [];
    $customerGoal = trim((string) ($intelligence['customer_goal'] ?? ''));
    $strategy = trim((string) ($intelligence['strategy'] ?? ''));

    $known = agent_core_plan_known_information($conv, $intelligence);
    $missing = array_values(array_unique(array_merge(
        is_array($plan['missing'] ?? null) ? $plan['missing'] : [],
        $ciMissing
    )));

    $plan['next_best_action'] = $nba;
    $plan['primary_intent'] = $ciPrimary;
    $plan['secondary_intents'] = $secondary;
    $plan['emotion'] = $emotion;
    $plan['readiness'] = $readiness;
    $plan['conversation_state'] = $convState;
    $plan['missing_information'] = $missing;
    $plan['known_information'] = $known;
    $plan['customer_goal'] = $customerGoal;
    $plan['minimum_question'] = $minQ;
    $plan['ci_strategy'] = $strategy;

    $cta = agent_core_plan_cta_from_nba($nba, $minQ, $intelligence, $plan);
    $plan['cta_hint'] = $cta['hint'];
    $plan['cta_mode'] = $cta['mode'];
    $plan['response_goal'] = agent_core_plan_response_goal($nba, $ciPrimary, $emotion, $readiness, $customerGoal);
    $plan['stop_allowed'] = in_array($nba, ['human_social_reply', 'cancel_pending', 'answer_turn'], true)
        && !in_array($readiness, ['decision', 'purchase', 'ready'], true);
    $plan['advance_allowed'] = !in_array($nba, ['human_social_reply', 'cancel_pending'], true);
    $plan['forbid_generic_loop'] = !in_array($nba, ['human_social_reply', 'offer_human'], true)
        && $emotion !== 'distressed';

    if ($nba === 'ask_one_clarifier') {
        $plan['clarification_needed'] = true;
        if ($minQ !== '') {
            $plan['answer_first'] = $minQ;
        }
    }

    if ($nba === 'confirm_pending') {
        $plan['clarification_needed'] = false;
        $plan['response_goal'] = 'Confirm the pending action using information already collected. Do not restart discovery.';
    }

    if ($nba === 'cancel_pending') {
        $plan['clarification_needed'] = false;
        $plan['response_goal'] = 'Acknowledge cancellation of the pending action. Do not push a sale or booking.';
    }

    if ($nba === 'offer_human') {
        $plan['response_goal'] = 'Offer human handoff with context. Do not say "contact support" without explaining why.';
    }

    if (in_array($nba, ['open_menu', 'open_cart', 'answer_price', 'answer_availability'], true)) {
        $plan = agent_core_plan_schedule_nba_tools($plan, $nba, $turnCtx, $intent, $source);
    }
    if (in_array($plan['selected_action'] ?? '', ['OFFER_BOOKING', 'CHECK_AVAILABILITY'], true)
        && in_array('booking', is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [], true)
    ) {
        $plan['tool_calls'] = agent_core_plan_append_tool($plan['tool_calls'] ?? [], 'booking.availability', ['query' => $text]);
    }

    if (in_array($ciPrimary, ['DELIVERY_QUERY', 'COMPLAINT', 'SUPPORT', 'RETURN_REQUEST'], true)) {
        if (empty($source['needs_orders'])) {
            $plan['tool_calls'] = agent_core_plan_append_tool($plan['tool_calls'] ?? [], 'orders.read', ['query' => (string) ($turnCtx['text'] ?? '')]);
        }
        $plan['advance_allowed'] = false;
        $plan['cta_mode'] = 'resolve';
        $plan['response_goal'] = 'Move toward support resolution. Do not pitch unrelated products.';
    }

    if ($ciPrimary === 'CANCELLATION' || $nba === 'cancel_pending') {
        $plan['outcome'] = 'CORRECTION';
        $plan['advance_allowed'] = false;
    }

    if ($emotion === 'distressed' || $emotion === 'worried') {
        $plan['forbid_generic_loop'] = true;
        $plan['response_goal'] = ($plan['response_goal'] ?? '')
            . ' Respond with brief empathy, no diagnosis, no mental-health lecture. Offer a concrete business next step if configured.';
    }

    if (in_array($readiness, ['decision', 'purchase', 'ready'], true)
        && !in_array($nba, ['human_social_reply', 'cancel_pending', 'offer_human'], true)
    ) {
        $plan['stop_allowed'] = false;
        $plan['response_goal'] = ($plan['response_goal'] ?? '')
            . ' Customer appears ready — answer directly and move toward action; skip unnecessary education.';
    }

    if ($plan['next_best_action'] === 'ask_one_clarifier'
        && function_exists('conversation_intelligence_is_general_price_list')
        && conversation_intelligence_is_general_price_list((string) ($turnCtx['text'] ?? ''))
    ) {
        $plan['next_best_action'] = 'answer_price';
        $plan['missing_information'] = array_values(array_diff(
            is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [],
            ['product', 'which_item']
        ));
        $plan['clarification_needed'] = false;
        $plan['response_goal'] = 'Answer with verified business pricing first. Offer a relevant next step if appropriate.';
        $plan = agent_core_plan_schedule_nba_tools($plan, 'answer_price', $turnCtx, $intent, $source);
    }

    return $plan;
}

/**
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intelligence
 * @return list<string>
 */
function agent_core_plan_known_information(array $conv, array $intelligence): array
{
    $known = [];
    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    foreach ($memory as $key => $val) {
        $k = trim((string) $key);
        $v = trim(is_scalar($val) ? (string) $val : '');
        if ($k !== '' && $v !== '') {
            $known[] = $k . '=' . mb_substr($v, 0, 80);
        }
    }
    $entities = is_array($intelligence['entities'] ?? null) ? $intelligence['entities'] : [];
    foreach (['product', 'name', 'customer_name', 'service', 'budget', 'size', 'color', 'location', 'date', 'time'] as $ek) {
        $ev = trim((string) ($entities[$ek] ?? ''));
        if ($ev !== '' && !str_starts_with($ek, '_')) {
            $known[] = $ek . '=' . mb_substr($ev, 0, 80);
        }
    }

    return array_values(array_unique($known));
}

/**
 * @return array{hint: string, mode: string}
 */
function agent_core_plan_cta_from_nba(string $nba, string $minQ, array $intelligence, array $plan): array
{
    $primary = trim((string) ($intelligence['primary_intent'] ?? ''));
    return match ($nba) {
        'ask_one_clarifier' => ['hint' => $minQ !== '' ? $minQ : 'Ask one short clarifying question only.', 'mode' => 'clarify'],
        'open_menu' => ['hint' => 'Offer to show relevant products/menu if business data supports it.', 'mode' => 'show_catalog'],
        'open_cart' => ['hint' => 'Help review cart or proceed toward checkout if available.', 'mode' => 'checkout'],
        'answer_price' => ['hint' => 'State verified price from business data, then offer to help choose or order if appropriate.', 'mode' => 'price_advance'],
        'answer_availability' => ['hint' => 'Answer availability from verified data; offer next step if stock confirmed.', 'mode' => 'availability'],
        'confirm_pending' => ['hint' => 'Confirm the pending step using known details — do not re-ask collected info.', 'mode' => 'confirm'],
        'cancel_pending' => ['hint' => 'Acknowledge cancellation briefly.', 'mode' => 'stop'],
        'offer_human' => ['hint' => 'Offer to connect with a team member with context.', 'mode' => 'handoff'],
        'human_social_reply' => ['hint' => '', 'mode' => 'social'],
        default => match ($primary) {
            'EVENT_INVITATION' => ['hint' => 'Collect event name, date, organizer, venue, and invitation card if available — do not confirm attendance.', 'mode' => 'collect_event'],
            'BOOKING_REQUEST', 'APPOINTMENT' => ['hint' => 'Collect missing booking details only — never promise to check availability or get back later without verified booking data.', 'mode' => 'book'],
            'ORDER_REQUEST', 'CART' => ['hint' => 'Move toward order/checkout with known details.', 'mode' => 'order'],
            'PRICE_INQUIRY' => ['hint' => 'Answer price then offer the most relevant next step.', 'mode' => 'price_advance'],
            'DELIVERY_QUERY', 'COMPLAINT', 'SUPPORT', 'RETURN_REQUEST' => ['hint' => 'Focus on resolution — no sales pitch.', 'mode' => 'resolve'],
            default => ['hint' => '', 'mode' => 'answer'],
        },
    };
}

function agent_core_plan_response_goal(
    string $nba,
    string $primary,
    string $emotion,
    string $readiness,
    string $customerGoal
): string {
    $bits = ['Answer the customer\'s current need first.'];
    if ($customerGoal !== '') {
        $bits[] = 'Customer goal hint: ' . mb_substr($customerGoal, 0, 120) . '.';
    }
    if ($nba === 'answer_price' || $primary === 'PRICE_INQUIRY') {
        $bits[] = 'Give verified pricing, then advance only if context supports it.';
    } elseif ($nba === 'open_menu' || $primary === 'MENU') {
        $bits[] = 'Show relevant options from business knowledge — not a generic intro.';
    } elseif (in_array($primary, ['DELIVERY_QUERY', 'COMPLAINT', 'SUPPORT', 'RETURN_REQUEST'], true)) {
        $bits[] = 'Support/resolution first — no unrelated selling.';
    } elseif ($primary === 'EVENT_INVITATION') {
        $bits[] = 'Event invitation workflow — gather details and invitation card; do not confirm Waqar\'s availability.';
    } elseif ($nba === 'human_social_reply') {
        $bits[] = 'Brief natural reply — no catalog pitch or discovery interrogation.';
    } else {
        $bits[] = 'Advance toward the next useful action when one exists.';
    }
    if (in_array($emotion, ['frustrated', 'urgent', 'urgency'], true)) {
        $bits[] = 'Acknowledge urgency briefly; be direct.';
    }
    if (in_array($readiness, ['decision', 'purchase', 'ready'], true)) {
        $bits[] = 'Customer readiness is high — minimize extra questions.';
    }
    $bits[] = 'Do not repeat information already provided in this thread or known facts.';
    $bits[] = 'Do not use generic empathy/education loops or dead-end closers when an action exists.';

    return implode(' ', $bits);
}

/**
 * @param list<array{name: string, args: array<string, mixed>}> $calls
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $source
 * @return list<array{name: string, args: array<string, mixed>}>
 */
function agent_core_plan_schedule_nba_tools(
    array $plan,
    string $nba,
    array $turnCtx,
    array $intent,
    array $source
): array {
    $calls = is_array($plan['tool_calls'] ?? null) ? $plan['tool_calls'] : [];
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $query = trim((string) ($intent['referent'] ?? ''));
    if ($query === '') {
        $query = $text;
    }

    if (in_array($nba, ['open_menu', 'answer_price', 'answer_availability'], true)) {
        $calls = agent_core_plan_append_tool($calls, 'catalog.search', ['query' => $query]);
    }
    if ($nba === 'open_cart') {
        $calls = agent_core_plan_append_tool($calls, 'cart.view', ['query' => $text]);
    }
    if ($nba === 'answer_availability' && in_array('booking', is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [], true)) {
        $calls = agent_core_plan_append_tool($calls, 'booking.availability', ['query' => $text]);
    }
    if ($nba === 'answer_price' && !empty($source['needs_hours'])) {
        $calls = agent_core_plan_append_tool($calls, 'hours.read', ['query' => $text]);
    }

    $plan['tool_calls'] = $calls;

    return $plan;
}

/**
 * @param list<array{name: string, args: array<string, mixed>}> $calls
 * @param array<string, mixed> $args
 * @return list<array{name: string, args: array<string, mixed>}>
 */
function agent_core_plan_append_tool(array $calls, string $name, array $args): array
{
    if (!in_array($name, AGENT_CORE_PHASE1_TOOLS, true)) {
        return $calls;
    }
    foreach ($calls as $call) {
        if ((string) ($call['name'] ?? '') === $name) {
            return $calls;
        }
    }
    $calls[] = ['name' => $name, 'args' => $args];

    return $calls;
}
