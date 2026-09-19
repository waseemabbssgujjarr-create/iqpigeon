<?php
/**
 * Bot 53 staging hardening — deterministic business-behavior alignment tests.
 * Run: php tests/agent-mind-bot53-staging-test.php
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
require_once $root . '/includes/agent-core/intent.php';
require_once $root . '/includes/agent-core/compose.php';
require_once $root . '/includes/agent-core/validate.php';

$passed = 0;
$failed = 0;

function b53_assert(bool $cond, string $name): void
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
 * @return array<string, mixed>
 */
function bot53_fixture(): array
{
    $kb = "Waqar Tayyub is a business and performance coach.\n"
        . "Rate \$80/hour — 1:1 coaching sessions\n"
        . "Training: Starting from \$250. Training price depends on the training assignment.\n"
        . "Greet Customers when they text you with: This is Shiza from Waqar Tayyub & Co. How can I help you today?\n"
        . "List of Services Offered:\n"
        . "- Neural Performance Coaching\n"
        . "- Corporate Training\n"
        . "- Workplace Productivity\n"
        . "- Immersive Leadership Development\n"
        . "- AI Growth and Adoption for High Performing Teams\n"
        . "Neural Performance Coaching helps clients work on focus, decision-making, and cognitive endurance.\n"
        . "Business Coaching covers go-to-market, product prioritization, and fundraising narrative.\n"
        . "AI Strategy covers practical AI integration, automation, and WhatsApp business tools.";

    return [
        'id'            => 53,
        'rep_name'      => 'Shiza',
        'name'          => 'Waqar Tayyub & Co.',
        'company_name'  => 'Waqar Tayyub & Co.',
        'industry_key'  => 'freelancer',
        'bot_knowledge' => $kb,
    ];
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function b53_plan(string $text, array $overrides = []): array
{
    $bot = bot53_fixture();
    $intel = conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []);
    $turn = array_merge([
        'text'    => $text,
        'bot_id'  => 53,
        'lead_id' => 0,
        'bot'     => $bot,
        'profile' => ['capabilities' => $overrides['caps'] ?? ['live_web']],
    ], $overrides['turn'] ?? []);
    $conv = array_merge(['runtime_facts' => [], 'history' => [], 'last_assistant' => ''], $overrides['conv'] ?? []);
    $intent = agent_core_intent($turn, $conv);
    if (!empty($overrides['intent_kind'])) {
        $intent['kind'] = (string) $overrides['intent_kind'];
    }
    $source = array_merge(['primary' => 'BUSINESS_KNOWLEDGE'], $overrides['source'] ?? []);
    $pack = array_merge(['brand' => 'Waqar Tayyub & Co.', 'rep' => 'Shiza'], $overrides['pack'] ?? []);

    return agent_core_plan($turn, $conv, $intent, $source, $pack, $intel);
}

echo "Bot 53 Staging Hardening Tests\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$bot = bot53_fixture();

// 1 — New conversation → Shiza introduces herself
$g1 = agent_core_canonical_greeting_draft($bot, 'Hello', ['history' => []]);
b53_assert(
    str_contains($g1, 'Shiza') && str_contains($g1, 'Waqar Tayyub & Co.'),
    'B53-1 new conversation uses configured Shiza greeting'
);

// 2 — Second turn → no reintroduction
$g2 = agent_core_canonical_greeting_draft($bot, 'Hello', [
    'history' => [['role' => 'assistant', 'message' => 'Hi there']],
]);
b53_assert($g2 === '', 'B53-2 second turn does not reintroduce Shiza');

// 3 — Services question → five-service public list
$list = knowledge_offer_list_reply($bot);
b53_assert(
    str_contains($list, 'Neural Performance Coaching')
    && str_contains($list, 'Corporate Training')
    && str_contains($list, 'Workplace Productivity')
    && str_contains($list, 'Immersive Leadership Development')
    && str_contains($list, 'AI Growth and Adoption'),
    'B53-3 public five-service list from training knowledge'
);

// 4 — 1:1 coaching price → $80/hour
$coachPrice = knowledge_contextual_price_reply($bot, 'How much is 1:1 coaching?');
b53_assert(str_contains($coachPrice, '$80') && str_contains($coachPrice, 'hour'), 'B53-4 coaching price $80/hour');

// 5 — Training price → starting from $250
$trainPrice = knowledge_contextual_price_reply($bot, 'How much is corporate training?');
b53_assert(str_contains($trainPrice, '$250') && str_contains($trainPrice, 'depends'), 'B53-5 training starts from $250');

// 6 — Training price does not say $80/hour
b53_assert(!str_contains($trainPrice, '$80/hour'), 'B53-6 training price not conflated with $80/hour');

// 7 — Neural Performance recommendation context
$p7 = b53_plan('Tell me about Neural Performance Coaching for my team.');
b53_assert(
    in_array($p7['customer_need'] ?? '', ['understand_offerings', 'answer_question', 'find_product'], true)
    || str_contains((string) ($p7['response_goal'] ?? ''), 'Answer'),
    'B53-7 neural performance question gets informational plan'
);

// 8 — Unsupported guaranteed outcome blocked
$badOutcome = agent_core_validate_unsupported_business_claim(
    'This coaching will significantly improve your performance guaranteed.',
    []
);
b53_assert($badOutcome === true, 'B53-8 blocks unsupported guaranteed outcome claim');

// 9 — Founder prioritization → relevant coaching need
$p9 = b53_plan("I'm a founder struggling with prioritization.");
b53_assert(
    in_array($p9['customer_need'] ?? '', ['find_product', 'answer_question', 'understand_offerings', 'book_appointment'], true)
    || str_contains((string) ($p9['customer_need_detail'] ?? ''), 'founder')
    || str_contains((string) ($p9['customer_goal'] ?? ''), 'learn'),
    'B53-9 founder prioritization gets relevant coaching-oriented need'
);

// 10 — AI business question
$p10 = b53_plan('We need AI strategy for our company workflows.');
b53_assert(
    ($p10['customer_need'] ?? '') !== 'complete_purchase',
    'B53-10 AI business question is not misrouted to checkout'
);

// 11 — Event invitation intent
$ev = conversation_intelligence_analyze('We would like to invite Mr. Waqar to speak at our conference.');
b53_assert(($ev['primary_intent'] ?? '') === 'EVENT_INVITATION', 'B53-11 event invitation intent detected');

// 12 — Event invitation asks for details (collect action)
$p12 = b53_plan('We want to invite Waqar to our leadership workshop next month.', ['intent_kind' => 'EVENT_INVITATION']);
b53_assert(
    ($p12['customer_need'] ?? '') === 'invite_speaker'
    || in_array($p12['selected_action'] ?? '', ['COLLECT_REQUIRED_INFORMATION', 'ANSWER', 'CLARIFY'], true),
    'B53-12 event invitation routes to detail collection'
);

// 13 — Event invitation plan mentions invitation card guidance
b53_assert(
    str_contains((string) ($p12['response_goal'] ?? ''), 'invitation')
    || str_contains((string) ($p12['cta_hint'] ?? ''), 'event')
    || ($p12['customer_need'] ?? '') === 'invite_speaker',
    'B53-13 event invitation workflow active'
);

// 14 — Event invitation does not claim Waqar accepted
$evBad = agent_core_validate(
    'Great news — Waqar has accepted your invitation and will attend.',
    ['text' => 'invite', 'bot' => $bot, 'lead_id' => 0],
    ['kind' => 'EVENT_INVITATION'],
    ['outcome' => 'EVENT_INVITATION', 'customer_need' => 'invite_speaker', 'next_best_action' => 'ask_one_clarifier'],
    []
);
b53_assert(empty($evBad['ok']), 'B53-14 blocks false event acceptance claim');

// 15 — Event invitation does not claim availability without confirmation
$evAvail = agent_core_validate_async_promise(
    'Waqar is available tomorrow for your event.',
    ['outcome' => 'EVENT_INVITATION', 'tool_calls' => []],
    ['profile' => ['capabilities' => []]]
);
b53_assert($evAvail === true || empty(agent_core_validate(
    'Waqar is available tomorrow for your event.',
    ['text' => 'invite', 'bot' => $bot, 'lead_id' => 0],
    ['kind' => 'EVENT_INVITATION'],
    ['outcome' => 'EVENT_INVITATION'],
    []
)['ok']), 'B53-15 blocks unverified speaker availability claim');

// 16 — Booking tomorrow → missing time
$bookFields = agent_core_decision_booking_field_status([], [], [], 'I would like to book a coaching session tomorrow.');
b53_assert($bookFields['date'] === true && $bookFields['time'] === false, 'B53-16 booking tomorrow missing time');

// 17 — Vague "sharp pm" → time still missing
$sharpFields = agent_core_decision_booking_field_status([], [], [], "I'll be available at sharp pm.");
b53_assert($sharpFields['time'] === false, 'B53-17 vague sharp pm does not count as valid time');

// 18 — No false "I'll check availability" without booking tool
$asyncBad = agent_core_validate_async_promise(
    "I'll check the availability for tomorrow and get back to you as soon as possible.",
    ['outcome' => 'BOOKING', 'tool_calls' => [], 'next_best_action' => 'ask_one_clarifier'],
    ['profile' => ['capabilities' => []]]
);
b53_assert($asyncBad === true, 'B53-18 blocks false async availability check');

// 19 — Verified booking tool evidence may mention availability
$asyncOk = agent_core_validate_async_promise(
    'We have these times available tomorrow: 2pm and 4pm.',
    ['outcome' => 'BOOKING', 'tool_calls' => [['name' => 'booking.offer']], 'next_best_action' => 'answer_availability'],
    ['profile' => ['capabilities' => ['booking']]]
);
b53_assert($asyncOk === false, 'B53-19 allows availability wording with booking tool evidence');

// 20 — Failed/unverified booking → no confirmation
$bookConfirm = agent_core_validate_false_action_claim(
    'Your coaching session is confirmed for tomorrow at 3pm.',
    ['next_best_action' => 'ask_one_clarifier']
);
b53_assert($bookConfirm === true, 'B53-20 blocks unconfirmed booking completion');

// 21 — Booking completion only after verified create
$bookAllowed = agent_core_validate_false_action_claim(
    'Your appointment has been booked for tomorrow.',
    ['next_best_action' => 'confirm_pending', 'tool_results' => [['name' => 'booking.create', 'ok' => true, 'data' => ['booking_id' => 42, 'status' => 'confirmed']]]]
);
b53_assert($bookAllowed === false, 'B53-21 verified booking.create allows confirmation language');
$bookBlocked = agent_core_validate_false_action_claim(
    'Your appointment has been booked for tomorrow.',
    ['next_best_action' => 'confirm_pending']
);
b53_assert($bookBlocked === true, 'B53-21b confirm_pending without create still blocks booked claim');

// 22 — Human handoff claim blocked without execution
$handoff = agent_core_validate_false_action_claim(
    "I've connected you to a team member who will help you shortly.",
    ['next_best_action' => 'offer_human', 'selected_action' => 'OFFER_HUMAN_HANDOFF']
);
b53_assert($handoff === true, 'B53-22 blocks unverified human handoff claim');

// 23 — Cancellation not classified as order request
$cancel = conversation_intelligence_analyze('I want to cancel my order please.');
b53_assert(($cancel['primary_intent'] ?? '') === 'CANCELLATION', 'B53-23 cancellation not order request');

// 24 — Thanks/bye → stop goal
$p24 = b53_plan('Thanks, that is all. Bye!');
b53_assert(($p24['customer_goal'] ?? '') === 'stop', 'B53-24 thanks/bye maps to stop goal');

// 25 — STOP → no generic reopening CTA
$stopVal = agent_core_validate_plan_decision(
    'Goodbye! Feel free to ask if you have any other questions.',
    ['lead_id' => 0, 'text' => 'bye'],
    ['forbid_generic_loop' => true, 'require_useful_cta' => true, 'next_best_action' => 'cancel_pending', 'stop_allowed' => true, 'cta_mode' => 'STOP'],
    []
);
b53_assert(empty($stopVal['ok']), 'B53-25 STOP blocks generic reopening CTA');

// 26 — Objection → objection handling not hard sell
$p26 = b53_plan('That is too expensive for us right now.');
b53_assert(
    !empty($p26['objection']) || in_array($p26['selected_action'] ?? '', ['HANDLE_OBJECTION', 'ANSWER', 'OFFER_QUOTE'], true),
    'B53-26 price objection routes to objection handling'
);

// 27 — Late delivery → support before sales
$p27 = b53_plan('My order is late, where is it?');
b53_assert(($p27['customer_need'] ?? '') === 'resolve_delivery', 'B53-27 late delivery support-first need');

// 28 — Known budget → do not re-ask
$budgetVal = agent_core_validate_plan_decision(
    'What is your budget?',
    ['lead_id' => 0, 'text' => 'recommend'],
    ['known_information' => ['budget=150k'], 'next_best_action' => 'recommend'],
    []
);
b53_assert(empty($budgetVal['ok']) && ($budgetVal['reason'] ?? '') === 'asks_known_info', 'B53-28 never re-ask known budget');

// 29 — Known date/time → do not re-ask (booking fields complete)
$knownFields = agent_core_decision_booking_field_status(
    ['date=tomorrow', 'time=3pm', 'service=coaching'],
    ['date' => 'tomorrow', 'time' => '3pm', 'service' => 'coaching'],
    [],
    'book coaching tomorrow at 3pm'
);
b53_assert($knownFields['date'] && $knownFields['time'] && $knownFields['service'], 'B53-29 known date/time/service recognized');

// 30 — Unknown price → canonical price empty for unknown package
$unknownPrice = agent_core_canonical_price_draft($bot, 'How much is the platinum executive package?');
b53_assert($unknownPrice === '', 'B53-30 unknown package price not fabricated canonically');

// 31 — Unsupported capability → false action claims blocked
$capClaim = agent_core_validate_false_action_claim(
    "I've sent your payment link and booked the studio for you.",
    ['next_best_action' => 'answer']
);
b53_assert($capClaim === true, 'B53-31 unsupported capability action claims blocked');

// 32 — Multi-intent message
$p32 = b53_plan('What is the price for coaching and can you tell me about AI strategy for teams?');
b53_assert(!empty($p32['multi_intent']) || count($p32['resolved_intents'] ?? []) >= 1, 'B53-32 multi-intent message handled');

// Staging safety — bot 53 only when configured
b53_assert(
    defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false,
    'SAFETY: AGENT_CORE_ENABLED remains false in committed config'
);
b53_assert(
    !function_exists('agent_core_staging_bot_ids'),
    'SAFETY: no staging allow-list runtime in bootstrap'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
