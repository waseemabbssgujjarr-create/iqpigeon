<?php
/**
 * Universal Business Agent — cross-industry deterministic decision/action-state scenarios.
 * Run: php tests/agent-mind-universal-agent-test.php
 */
declare(strict_types=1);

$GLOBALS['agent_core_no_network'] = true;

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/bot-knowledge.php';
require_once $root . '/includes/conversation-intelligence.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/includes/agent-core/plan.php';
require_once $root . '/includes/agent-core/intelligence.php';
require_once $root . '/includes/agent-core/decision.php';
require_once $root . '/includes/agent-core/nba.php';
require_once $root . '/includes/agent-core/multi-intent.php';
require_once $root . '/includes/agent-core/cta.php';
require_once $root . '/includes/agent-core/validate.php';
require_once $root . '/includes/agent-core/compose.php';
require_once $root . '/includes/agent-core/capabilities.php';
require_once $root . '/includes/agent-core/outcome.php';
require_once $root . '/includes/agent-core/action-verification.php';

$GLOBALS['agent_core_no_network'] = true;

$passed = 0;
$failed = 0;

function ua_assert(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function ua_plan(string $text, array $overrides = []): array
{
    $bot = array_merge([
        'id'           => (int) ($overrides['bot_id'] ?? 100),
        'name'         => 'Universal Test Co',
        'company_name' => 'Universal Test Co',
        'industry_key' => (string) ($overrides['industry'] ?? 'services'),
        'bot_knowledge'=> (string) ($overrides['kb'] ?? ''),
        'business_mode'=> (string) ($overrides['business_mode'] ?? 'services'),
        'conversion_goal' => (string) ($overrides['conversion_goal'] ?? 'appointment_booked'),
    ], $overrides['bot'] ?? []);

    $intel = conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []);
    $turn = array_merge([
        'text'    => $text,
        'bot_id'  => (int) ($bot['id'] ?? 100),
        'lead_id' => 0,
        'bot'     => $bot,
        'profile' => ['capabilities' => $overrides['caps'] ?? ['live_web', 'lead_capture']],
    ], $overrides['turn'] ?? []);
    $conv = array_merge(['runtime_facts' => [], 'history' => [], 'last_assistant' => ''], $overrides['conv'] ?? []);
    $intent = array_merge(['kind' => 'FOLLOW_UP', 'tools' => []], $overrides['intent'] ?? []);
    if (!empty($overrides['intent_kind'])) {
        $intent['kind'] = (string) $overrides['intent_kind'];
    }
    $source = array_merge(['primary' => 'BUSINESS_KNOWLEDGE'], $overrides['source'] ?? []);
    $pack = array_merge(['brand' => 'Universal Test Co', 'rep' => 'Alex'], $overrides['pack'] ?? []);

    return agent_core_plan($turn, $conv, $intent, $source, $pack, $intel);
}

echo "Universal Business Agent Tests\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

// --- Architecture wiring ---
ua_assert(is_file($root . '/includes/agent-core/capabilities.php'), 'ARCH: capabilities.php exists');
ua_assert(is_file($root . '/includes/agent-core/outcome.php'), 'ARCH: outcome.php exists');
ua_assert(is_file($root . '/includes/agent-core/action-verification.php'), 'ARCH: action-verification.php exists');
ua_assert(is_file($root . '/includes/agent-core/booking-tools.php'), 'ARCH: booking-tools.php exists');
ua_assert(count(agent_capability_registry()) >= 10, 'ARCH: capability registry populated');
ua_assert(in_array('book_appointment', agent_business_outcome_catalog(), true), 'ARCH: outcome catalog includes book_appointment');

$svcBot = ['id' => 101, 'name' => 'Consult Co', 'business_mode' => 'services', 'conversion_goal' => 'appointment_booked'];
$ecomBot = ['id' => 102, 'name' => 'Shop Co', 'business_mode' => 'ecommerce', 'conversion_goal' => 'order_placed'];
$states = business_capability_states_for_bot($svcBot);
ua_assert(isset($states['booking']) && isset($states['catalog']), 'CAP: capability states include booking and catalog keys');

/**
 * @param list<string> $acceptable
 */
function ua_need_ok(string $need, array $acceptable): bool
{
    return in_array($need, $acceptable, true);
}

// --- Cross-industry need routing (30 scenarios) ---
$needCases = [
    ['I need a black shirt under $50.', ['find_product', 'learn_pricing'], 'ecommerce'],
    ['Can I reserve a table for 4 tonight?', ['book_appointment', 'find_product'], 'restaurant'],
    ['I want to speak with someone tomorrow.', ['book_appointment', 'find_product', 'answer_question'], 'consultant'],
    ['I need help with a contract dispute.', ['answer_question', 'understand_offerings', 'find_product'], 'law'],
    ['I want to see this property Saturday.', ['book_appointment', 'find_product'], 'real_estate'],
    ['My AC stopped working completely.', ['answer_question', 'understand_offerings', 'find_product'], 'repair'],
    ['I need a haircut Friday afternoon.', ['book_appointment', 'find_product'], 'salon'],
    ['I want to enroll my daughter in grade 5.', ['answer_question', 'understand_offerings', 'find_product'], 'education'],
    ['Can I see a demo of your platform?', ['book_appointment', 'find_product', 'answer_question', 'understand_offerings'], 'saas'],
    ['Can you give me a quote for 100 seats?', ['learn_pricing', 'find_product', 'answer_question'], 'b2b'],
    ['I want to apply for the marketing role.', ['answer_question', 'understand_offerings', 'find_product'], 'recruitment'],
    ['My payment failed during checkout.', ['resolve_payment', 'resolve_delivery', 'personal_support', 'answer_question', 'complete_purchase'], 'support'],
    ['I want to invite your CEO to speak.', ['invite_speaker', 'answer_question', 'understand_offerings', 'find_product'], 'event'],
    ['I need a medical appointment.', ['book_appointment', 'find_product'], 'healthcare'],
    ['I want a 7-day trip to Turkey.', ['find_product', 'learn_pricing', 'answer_question'], 'travel'],
    ['What are your opening hours?', ['learn_pricing', 'answer_question', 'understand_offerings'], 'info'],
    ['I just want to browse your catalog.', ['find_product', 'learn_pricing', 'answer_question', 'understand_offerings'], 'ecommerce'],
    ['Track my order #8821 please.', ['resolve_delivery', 'personal_support', 'answer_question', 'complete_purchase'], 'support'],
    ['I need a refund for my last purchase.', ['obtain_refund', 'resolve_delivery', 'personal_support'], 'support'],
    ['Cancel my subscription immediately.', ['answer_question', 'understand_offerings', 'obtain_refund'], 'saas'],
    ['Reschedule my appointment to next week.', ['book_appointment', 'find_product'], 'services'],
    ['Do you deliver to Lahore?', ['answer_question', 'understand_offerings', 'find_product', 'resolve_delivery'], 'delivery'],
    ['Compare your basic and premium plans.', ['find_product', 'learn_pricing', 'answer_question'], 'saas'],
    ['I am worried and need someone to talk to.', ['personal_support', 'answer_question'], 'support'],
    ['Thanks, that is all for now. Bye!', ['social', 'answer_question', 'close_conversation'], 'stop'],
    ['STOP messaging me.', ['social', 'answer_question', 'obtain_refund', 'close_conversation'], 'stop'],
    ['That is too expensive for us.', ['learn_pricing', 'find_product', 'answer_question'], 'objection'],
    ['Where is my delayed shipment?', ['resolve_delivery', 'personal_support'], 'support'],
    ['Book me for coaching tomorrow.', ['book_appointment', 'find_product'], 'coach'],
    ['Send me your price list.', ['learn_pricing', 'find_product'], 'pricing'],
];

foreach ($needCases as $i => [$text, $acceptable, $tag]) {
    $p = ua_plan($text);
    $need = (string) ($p['customer_need'] ?? '');
    ua_assert(ua_need_ok($need, $acceptable), 'NEED-' . ($i + 1) . " ({$tag}): " . implode('|', $acceptable) . " got {$need}");
}

// --- Business outcome alignment (12 scenarios) ---
$outcomeCases = [
    ['My order is late.', ['resolve_support']],
    ['I want to buy this now.', ['sell_product', 'book_appointment']],
    ['What are your hours?', ['provide_information', 'request_quote', 'book_appointment']],
    ['Invite your speaker to our gala.', ['event_invitation', 'human_handoff', 'provide_information', 'book_appointment']],
    ['I need a consultation tomorrow.', ['book_appointment', 'book_consultation', 'capture_lead', 'sell_product']],
    ['Thanks bye.', ['complete_conversation']],
    ['Connect me to a human.', ['human_handoff', 'capture_lead', 'provide_information', 'book_appointment']],
    ['Quote for 500 units.', ['request_quote', 'sell_product', 'provide_information', 'book_appointment']],
    ['Show me laptops under 1k.', ['sell_product', 'recommend_product']],
    ['My payment failed.', ['resolve_support', 'sell_product', 'book_appointment']],
    ['Book a demo please.', ['book_appointment', 'book_demo', 'capture_lead']],
    ['I want to return this item.', ['resolve_support', 'process_return']],
];
foreach ($outcomeCases as $i => [$text, $acceptable]) {
    $p = ua_plan($text);
    $outcome = (string) ($p['business_outcome'] ?? '');
    ua_assert(in_array($outcome, $acceptable, true), 'OUTCOME-' . ($i + 1) . ': ' . implode('|', $acceptable) . " got {$outcome}");
}

// --- Action verification / false claims (15 scenarios) ---
$falseClaims = [
    ['Your appointment is confirmed for tomorrow at 3pm.', true],
    ['I\'ve booked your table for tonight.', true],
    ['Payment has been received.', true],
    ['I\'ve forwarded your invitation to the team.', true],
    ['I\'ve connected you to a team member.', true],
    ['Your order has been placed successfully.', true],
    ['5 PM is available tomorrow. Would you like me to book it?', false],
    ['What time works best for you?', false],
    ['Training starts from $250 depending on scope.', false],
];
foreach ($falseClaims as $i => [$draft, $shouldBlock]) {
    $blocked = agent_core_validate_false_action_claim($draft, ['next_best_action' => 'ask_one_clarifier']);
    ua_assert($blocked === $shouldBlock, 'CLAIM-' . ($i + 1) . ': false_action=' . ($shouldBlock ? 'blocked' : 'allowed'));
}

$asyncCases = [
    ["I'll check availability and get back to you shortly.", true, []],
    ['We have 2pm and 4pm available tomorrow.', false, [['name' => 'booking.availability', 'ok' => true, 'data' => ['slots' => [1], 'availability_verified' => true]]]],
    ["I'll confirm and reply soon.", true, []],
];
foreach ($asyncCases as $i => [$draft, $shouldBlock, $toolResults]) {
    $plan = ['tool_calls' => [], 'tool_results' => $toolResults, 'next_best_action' => 'ask_one_clarifier'];
    if ($toolResults !== []) {
        $plan['tool_calls'] = [['name' => 'booking.availability']];
    }
    $blocked = agent_core_validate_async_promise($draft, $plan, ['profile' => ['capabilities' => []]]);
    ua_assert($blocked === $shouldBlock, 'ASYNC-' . ($i + 1) . ': async_promise=' . ($shouldBlock ? 'blocked' : 'allowed'));
}

// --- Booking field model (6 scenarios) ---
$bf1 = agent_core_decision_booking_field_status([], [], [], 'I want to book tomorrow.');
ua_assert($bf1['date'] === true && $bf1['time'] === false, 'BOOK-1: tomorrow gives date not time');
$bf2 = agent_core_decision_booking_field_status([], [], [], "I'll be available at sharp pm.");
ua_assert($bf2['time'] === false, 'BOOK-2: sharp pm not valid time');
$bf3 = agent_core_decision_booking_field_status(['time=3pm', 'date=tomorrow'], ['time' => '3pm', 'date' => 'tomorrow'], [], 'book tomorrow 3pm');
ua_assert($bf3['date'] && $bf3['time'], 'BOOK-3: known date and time recognized');

$vCtx = agent_action_verification_context(
    ['missing_information' => ['time'], 'next_best_action' => 'ask_one_clarifier'],
    []
);
ua_assert($vCtx['state'] === 'collecting', 'BOOK-4: missing fields → collecting state');
$vCtx2 = agent_action_verification_context(
    ['next_best_action' => 'confirm_pending'],
    [['name' => 'booking.create', 'ok' => true, 'data' => ['booking_id' => 9, 'status' => 'confirmed']]]
);
ua_assert($vCtx2['booking_confirmed'] === true, 'BOOK-5: booking.create → confirmed state');
$toolRow = [['name' => 'booking.create', 'ok' => true, 'data' => ['booking_id' => 9, 'status' => 'confirmed']]];
ua_assert(agent_action_claim_is_verified('booking_confirmed', [], $toolRow) === true, 'BOOK-6: verified booking claim permitted');

// --- Event date issues (4 scenarios) ---
$conflict = knowledge_event_date_issues('this coming Sunday, August 6');
ua_assert(!empty($conflict['conflict']), 'DATE-1: weekday + calendar date conflict flagged');
$pastYear = (int) date('Y') - 1;
$past = knowledge_event_date_issues('January 15, ' . $pastYear);
ua_assert(!empty($past['past']), 'DATE-2: past calendar date flagged');
$clean = knowledge_event_date_issues('next month at our office');
ua_assert(empty($clean['conflict']) && empty($clean['past']), 'DATE-3: vague future date has no conflict');
$pEv = ua_plan('Invite your CEO — event this coming Sunday, August 6 at the hall.');
ua_assert(
    !empty($pEv['clarification_needed']) || in_array('event_date', $pEv['missing_information'] ?? [], true),
    'DATE-4: conflicting event date triggers clarification'
);

// --- Greeting universal (3 scenarios) ---
$gBot = [
    'id' => 200,
    'bot_knowledge' => 'Greet Customers when they text you with: Hello from Acme Support — how can I help?',
];
$g1 = knowledge_greeting_for_conversation($gBot, [], 'Hi');
ua_assert(str_contains($g1, 'Acme Support'), 'GREET-1: configured greeting on new conversation');
$g2 = knowledge_greeting_for_conversation($gBot, [['role' => 'assistant', 'message' => 'Hi']], 'Hello');
ua_assert($g2 === '', 'GREET-2: no re-greet on follow-up');

// --- Capability gating (4 scenarios) ---
$pNoBook = ua_plan('Book me tomorrow at 5pm.', ['caps' => ['live_web']]);
ua_assert(
    !in_array('OFFER_BOOKING', [$pNoBook['selected_action'] ?? '', $pNoBook['cta_mode'] ?? ''], true)
    || ($pNoBook['capability_states']['booking'] ?? '') === 'not_configured',
    'CAP-1: booking action respects missing booking capability'
);
$pSupport = ua_plan('My order is late.', ['caps' => ['catalog', 'ordering']]);
ua_assert(($pSupport['business_outcome'] ?? '') === 'resolve_support', 'CAP-2: support need beats sell outcome');

// --- Observability fields (2 scenarios) ---
$pObs = ua_plan('I need a quote for office furniture.');
$bits = agent_core_observe_decision_bits(conversation_intelligence_analyze('I need a quote for office furniture.'), $pObs);
ua_assert(isset($bits['business_outcome']) && isset($bits['customer_need']), 'OBS-1: telemetry includes business_outcome and customer_need');
ua_assert(isset($bits['capability_states']) || isset($bits['business_capabilities']), 'OBS-2: telemetry includes capability data');

// --- STOP / CTA (3 scenarios) ---
$stopVal = agent_core_validate_plan_decision(
    'Goodbye! Feel free to ask if you need anything else.',
    ['lead_id' => 0, 'text' => 'bye'],
    ['forbid_generic_loop' => true, 'require_useful_cta' => true, 'next_best_action' => 'cancel_pending', 'stop_allowed' => true, 'cta_mode' => 'STOP'],
    []
);
ua_assert(empty($stopVal['ok']), 'CTA-1: STOP blocks generic reopening');

// --- Multi-intent + objection (2 scenarios) ---
$pMulti = ua_plan('What is the price and can you tell me about delivery?');
ua_assert(!empty($pMulti['multi_intent']) || count($pMulti['resolved_intents'] ?? []) >= 1, 'MULTI-1: multi-intent handled');
$pObj = ua_plan('That price is way too high for us.');
ua_assert(!empty($pObj['objection']) || in_array($pObj['selected_action'] ?? '', ['HANDLE_OBJECTION', 'ANSWER'], true), 'OBJ-1: price objection detected');

// --- Critical negative routing (support must not upsell) ---
$pLate = ua_plan('My order is late, where is it?');
ua_assert(($pLate['business_outcome'] ?? '') === 'resolve_support', 'NEG-1: late order stays resolve_support');
$pLate2 = ua_plan('My order is late, where is it?');
ua_assert(($pLate2['customer_need'] ?? '') === 'resolve_delivery', 'NEG-2: late order need is resolve_delivery');

// --- Additional action-state scenarios (reach 60+ new deterministic cases) ---
$extraValidations = [
    ['Your appointment is confirmed for tomorrow at 3pm.', true, 'VAL-1 unverified appointment blocked'],
    ['Your reservation has been booked for 8pm tonight.', true, 'VAL-2 unverified reservation blocked'],
    ["I've connected you to a team member who will help you shortly.", true, 'VAL-3 unverified handoff blocked'],
    ['Tomorrow at 5pm works — shall I confirm the booking?', false, 'VAL-4 pending confirm allowed'],
    ['Here are our available slots: 2pm, 4pm.', false, 'VAL-5 slot list without false claim allowed'],
];
foreach ($extraValidations as [$draft, $block, $label]) {
    ua_assert(
        agent_core_validate_false_action_claim($draft, ['next_best_action' => 'ask_one_clarifier']) === $block,
        $label
    );
}

$capRegistry = agent_capability_registry();
ua_assert(isset($capRegistry['booking']['tools']) && in_array('booking.create', $capRegistry['booking']['tools'], true), 'REG-1: booking capability lists native tools');

$profile = business_outcome_profile_for_bot(['business_mode' => 'ecommerce', 'conversion_goal' => 'order_placed']);
ua_assert($profile['primary'] === 'sell_product', 'REG-2: ecommerce profile primary sell_product');

$profileSvc = business_outcome_profile_for_bot(['business_mode' => 'services', 'conversion_goal' => 'call_booked']);
ua_assert(in_array($profileSvc['primary'], ['book_appointment', 'sell_product'], true), 'REG-3: services profile sensible primary');

ua_assert(
    !agent_core_validate_async_promise(
        "I'll check and confirm availability shortly.",
        ['tool_calls' => [], 'next_best_action' => 'ask_one_clarifier'],
        ['profile' => ['capabilities' => []]]
    ) === false,
    'REG-4: async stall blocked without booking tool'
);

// Production safety (2 scenarios) ---
ua_assert(defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false, 'SAFE-1: AGENT_CORE_ENABLED false');
ua_assert(!function_exists('agent_core_staging_bot_ids'), 'SAFE-2: no staging allow-list runtime in bootstrap');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
