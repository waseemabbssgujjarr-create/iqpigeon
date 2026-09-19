<?php
/**
 * Native booking execution + handoff + support routing — deterministic tests.
 * Run: php tests/agent-mind-booking-execution-test.php
 */
declare(strict_types=1);

$GLOBALS['agent_core_no_network'] = true;

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/conversation-intelligence.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/includes/agent-core/plan.php';
require_once $root . '/includes/agent-core/intelligence.php';
require_once $root . '/includes/agent-core/decision.php';
require_once $root . '/includes/agent-core/nba.php';
require_once $root . '/includes/agent-core/multi-intent.php';
require_once $root . '/includes/agent-core/cta.php';
require_once $root . '/includes/agent-core/tools.php';
require_once $root . '/includes/agent-core/validate.php';
require_once $root . '/includes/agent-core/booking-state.php';
require_once $root . '/includes/agent-core/booking-tools.php';
require_once $root . '/includes/agent-core/handoff-tools.php';
require_once $root . '/includes/agent-core/action-verification.php';

$passed = 0;
$failed = 0;

function be_assert(bool $cond, string $name): void
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
function be_plan(string $text, array $overrides = []): array
{
    $bot = array_merge(['id' => 501, 'name' => 'Test Co', 'business_mode' => 'services'], $overrides['bot'] ?? []);
    $intel = conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []);
    $turn = array_merge([
        'text'    => $text,
        'bot_id'  => (int) ($bot['id'] ?? 501),
        'lead_id' => (int) ($overrides['lead_id'] ?? 9001),
        'bot'     => $bot,
        'profile' => ['capabilities' => $overrides['caps'] ?? ['live_web', 'booking', 'human_handoff']],
    ], $overrides['turn'] ?? []);
    $conv = array_merge(['runtime_facts' => [], 'history' => [], 'last_assistant' => ''], $overrides['conv'] ?? []);
    $intent = array_merge(['kind' => 'BOOKING', 'tools' => []], $overrides['intent'] ?? []);
    $source = ['primary' => 'BUSINESS_KNOWLEDGE'];
    $pack = ['brand' => 'Test Co', 'rep' => 'Alex'];

    return agent_core_plan($turn, $conv, $intent, $source, $pack, $intel);
}

echo "Booking Execution + Action Completion Tests\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

// --- Booking field model ---
$bf1 = agent_core_decision_booking_field_status([], [], [], 'Book tomorrow');
be_assert($bf1['date'] && !$bf1['time'], 'BOOK-1 missing time when only tomorrow');
$bf2 = agent_core_decision_booking_field_status(['date=tomorrow', 'time=5pm'], ['date' => 'tomorrow', 'time' => '5pm'], [], 'tomorrow 5pm');
be_assert($bf2['date'] && $bf2['time'], 'BOOK-2 known date and time');
$req = agent_booking_required_fields([]);
be_assert(in_array('date', $req, true) && in_array('time', $req, true), 'BOOK-3 default required date+time');

// --- State machine ---
$st = agent_booking_state_empty();
be_assert($st['phase'] === 'none', 'STATE-1 empty phase none');
$st2 = agent_booking_state_apply_turn($st, '5 PM', ['date' => true, 'time' => true], ['time' => '5pm'], 'none', 'ask_one_clarifier');
be_assert($st2['time'] === '5pm', 'STATE-2 captures time entity');

// --- Availability tool (fixture) ---
$GLOBALS['_booking_tool_availability_fixture'][501] = [
    'ok' => true, 'availability_verified' => true,
    'slots' => [['index' => 1, 'iso_start' => '2026-09-04T17:00:00+05:00', 'iso_end' => '2026-09-04T17:30:00+05:00', 'label' => 'Thu 4 Sep, 5:00 PM']],
    'message' => 'Thu 4 Sep, 5:00 PM is available. Would you like me to book it?',
    'timezone' => 'Asia/Karachi',
];
$avail = booking_tool_availability(501, 6, 'tomorrow', '5pm');
be_assert(!empty($avail['availability_verified']), 'AVAIL-1 fixture availability verified');

// --- Plan schedules availability when booking cap present ---
$pBook = be_plan('I want to book tomorrow at 5 PM', ['caps' => ['live_web', 'booking']]);
$toolNames = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $pBook['tool_calls'] ?? []);
be_assert(in_array('booking.availability', $toolNames, true), 'PLAN-1 schedules booking.availability');
be_assert(in_array($pBook['booking_phase'] ?? '', ['availability_requested', 'collecting', 'none'], true), 'PLAN-1 booking phase set');

// --- booking.create forbidden without allow_mutating_tools ---
$blocked = agent_core_tool('booking.create', ['iso_start' => '2026-09-04T17:00:00+05:00', 'iso_end' => '2026-09-04T17:30:00+05:00'], ['bot_id' => 501, 'lead_id' => 9001]);
be_assert(($blocked['error'] ?? '') === 'forbidden_phase1', 'TOOL-1 booking.create blocked without flag');

// --- booking.create with fixture success ---
$GLOBALS['_booking_tool_create_fixture'] = [
    'ok' => true, 'booking_id' => 77, 'status' => 'confirmed', 'date' => '2026-09-04', 'time' => '17:00', 'timezone' => 'Asia/Karachi', 'error' => '',
];
$created = agent_core_tool('booking.create', [
    'iso_start' => '2026-09-04T17:00:00+05:00',
    'iso_end'   => '2026-09-04T17:30:00+05:00',
], ['bot_id' => 501, 'lead_id' => 9001, 'allow_mutating_tools' => true]);
be_assert(!empty($created['ok']) && (int) ($created['data']['booking_id'] ?? 0) === 77, 'TOOL-2 booking.create succeeds with flag');
unset($GLOBALS['_booking_tool_create_fixture']);

// --- Post-tools state confirmed ---
$plan = ['booking_state' => agent_booking_state_empty(), 'next_best_action' => 'confirm_pending'];
$plan['booking_state']['phase'] = 'creating';
$results = [['name' => 'booking.create', 'ok' => true, 'data' => ['booking_id' => 88, 'status' => 'confirmed']]];
$after = agent_booking_post_tools($plan, $results, ['lead_id' => 9001, 'bot_id' => 501], []);
be_assert(($after['booking_phase'] ?? '') === 'confirmed', 'POST-1 post_tools sets confirmed');
be_assert(!empty($after['action_executed']['booking_created']), 'POST-2 action_executed booking_created');

// --- Post-tools failure ---
$planF = ['booking_state' => ['phase' => 'creating'], 'next_best_action' => 'confirm_pending'];
$failResults = [['name' => 'booking.create', 'ok' => false, 'data' => ['error' => 'slot_unavailable']]];
$afterF = agent_booking_post_tools($planF, $failResults, ['lead_id' => 9001, 'bot_id' => 501], []);
be_assert(($afterF['booking_phase'] ?? '') === 'failed', 'POST-3 create failure sets failed phase');

// --- Validation: no confirm without create ---
be_assert(agent_core_validate_false_action_claim('Your appointment is confirmed for tomorrow.', []) === true, 'VAL-1 blocks unverified confirm');
$verifiedPlan = ['tool_results' => [['name' => 'booking.create', 'ok' => true, 'data' => ['booking_id' => 1, 'status' => 'confirmed']]], 'action_executed' => ['booking_created' => true, 'booking_id' => 1]];
be_assert(agent_core_validate_false_action_claim('Your appointment is confirmed for tomorrow.', $verifiedPlan) === false, 'VAL-2 allows verified confirm');

// --- Handoff tool ---
$GLOBALS['_handoff_tool_create_fixture'] = ['ok' => true, 'handoff_id' => 5, 'status' => 'queued', 'error' => ''];
$handoff = agent_core_tool('human_handoff.create', ['request_type' => 'event_invitation', 'payload' => ['event_topic' => 'Leadership']], [
    'bot_id' => 501, 'lead_id' => 9001, 'allow_mutating_tools' => true,
]);
be_assert(!empty($handoff['ok']), 'HANDOFF-1 create with fixture');
be_assert(agent_core_validate_false_action_claim("I've sent your invitation to the team for review.", []) === true, 'HANDOFF-2 blocks unverified forward');
$handoffPlan = ['tool_results' => [['name' => 'human_handoff.create', 'ok' => true, 'data' => ['handoff_id' => 5]]], 'action_executed' => ['handoff_created' => true]];
be_assert(agent_action_claim_is_verified('handoff_completed', $handoffPlan, $handoffPlan['tool_results']), 'HANDOFF-3 verified handoff claim');
unset($GLOBALS['_handoff_tool_create_fixture']);

// --- Event invitation intent (generic, no Bot 53 names) ---
$ev1 = conversation_intelligence_analyze("I'd like to invite your CEO to speak at our conference.");
be_assert(($ev1['primary_intent'] ?? '') === 'EVENT_INVITATION', 'EVENT-1 CEO invite intent');
$ev2 = conversation_intelligence_analyze('Can your founder speak at our summit next month?');
be_assert(($ev2['primary_intent'] ?? '') === 'EVENT_INVITATION', 'EVENT-2 founder invite intent');
$pEv = be_plan('We want to invite your director as guest speaker at our gala.');
be_assert(($pEv['customer_need'] ?? '') === 'invite_speaker', 'EVENT-3 invite_speaker need');

// --- Payment support routing ---
$pPay = be_plan('My payment failed during checkout.');
be_assert(($pPay['customer_need'] ?? '') === 'resolve_payment', 'SUP-1 payment failed need');
be_assert(($pPay['business_outcome'] ?? '') === 'resolve_support', 'SUP-2 payment failed outcome resolve_support');
$pLate = be_plan('My order is late.');
be_assert(($pLate['customer_need'] ?? '') === 'resolve_delivery', 'SUP-3 late order delivery');
be_assert(($pLate['business_outcome'] ?? '') === 'resolve_support', 'SUP-4 late order not sell');

// --- No booking tool without capability ---
$pNoCap = be_plan('Book me tomorrow at 3pm', ['caps' => ['live_web']]);
$namesNoCap = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $pNoCap['tool_calls'] ?? []);
be_assert(!in_array('booking.availability', $namesNoCap, true), 'CAP-1 no availability without booking cap');

// --- Async promise still blocked ---
be_assert(agent_core_validate_async_promise("I'll check availability and get back to you.", ['tool_calls' => []], ['profile' => ['capabilities' => []]]) === true, 'ASYNC-1 stall blocked');

// --- Action verification states ---
$v1 = agent_action_verification_context(['missing_information' => ['time']], []);
be_assert($v1['state'] === 'collecting', 'VERIFY-1 collecting');
$v2 = agent_action_verification_context([], [['name' => 'booking.availability', 'ok' => true, 'data' => ['slots' => [1], 'availability_verified' => true]]]);
be_assert($v2['availability_verified'] === true, 'VERIFY-2 availability verified');

// --- Industry routing samples (12) ---
$industryCases = [
    ['Add the blue shirt to my order.', ['complete_purchase', 'find_product']],
    ['Reserve a table for 4 at 8.', ['book_appointment', 'find_product']],
    ['Book me for Tuesday at 3.', ['book_appointment', 'find_product']],
    ['I want to see the property Saturday.', ['book_appointment', 'find_product']],
    ['I want to enroll my daughter.', ['answer_question', 'understand_offerings', 'find_product']],
    ['Book a demo of your platform.', ['book_appointment', 'find_product', 'answer_question']],
    ['Quote for 100 seats please.', ['learn_pricing', 'answer_question']],
    ['I want to apply for the role.', ['answer_question', 'understand_offerings', 'find_product']],
    ['My AC stopped working.', ['answer_question', 'understand_offerings', 'find_product']],
    ['Table for two tonight.', ['book_appointment', 'find_product', 'answer_question']],
    ['7-day trip to Turkey.', ['find_product', 'learn_pricing', 'answer_question']],
    ['Cancel my subscription.', ['obtain_refund', 'answer_question', 'close_conversation']],
];
foreach ($industryCases as $i => [$text, $acceptable]) {
    $p = be_plan($text);
    $got = (string) ($p['customer_need'] ?? '');
    $ok = in_array($got, $acceptable, true);
    be_assert($ok, 'IND-' . ($i + 1) . ' ' . implode('|', $acceptable));
}

// --- Slot resolve from state ---
$resolved = booking_tool_resolve_slot(501, ['date' => 'tomorrow', 'time' => '5pm', 'selected_iso_start' => '', 'selected_iso_end' => ''], 'yes');
be_assert($resolved === null || is_array($resolved), 'SLOT-1 resolve handles missing DB gracefully');

// --- Tenant isolation in create (invalid lead) ---
$GLOBALS['_booking_tool_create_fixture'] = ['ok' => false, 'booking_id' => 0, 'status' => 'failed', 'error' => 'invalid_lead', 'date' => '', 'time' => '', 'timezone' => ''];
$badLead = booking_tool_create(501, 1, 0, new DateTimeImmutable('2026-09-04T17:00:00+05:00'), new DateTimeImmutable('2026-09-04T17:30:00+05:00'));
be_assert(empty($badLead['ok']), 'ISO-1 invalid lead rejected');
unset($GLOBALS['_booking_tool_create_fixture']);

// --- Create failure fixture ---
$GLOBALS['_booking_tool_create_fixture'] = ['ok' => false, 'booking_id' => 0, 'status' => 'failed', 'error' => 'slot_unavailable', 'date' => '', 'time' => '', 'timezone' => 'Asia/Karachi'];
$failCreate = agent_core_tool('booking.create', ['iso_start' => '2026-09-04T17:00:00+05:00', 'iso_end' => '2026-09-04T17:30:00+05:00'], ['bot_id' => 501, 'lead_id' => 9001, 'allow_mutating_tools' => true]);
be_assert(empty($failCreate['ok']), 'FAIL-1 slot unavailable returns not ok');
unset($GLOBALS['_booking_tool_create_fixture']);

// --- Compose hint ---
$hint = agent_booking_compose_hint(['booking_state' => ['phase' => 'confirmed', 'booking_id' => 99]], []);
be_assert(str_contains($hint, 'confirmed'), 'HINT-1 confirmed compose hint');

// --- Safety ---
be_assert(defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false, 'SAFE-1 global core off');

// --- Additional booking state transitions ---
$stCollect = agent_booking_state_apply_turn(agent_booking_state_empty(), 'Book tomorrow', ['date' => true, 'time' => false], ['date' => 'tomorrow'], 'none', 'ask_one_clarifier');
be_assert($stCollect['phase'] === 'collecting', 'STATE-3 collecting on missing time');
$stAwait = agent_booking_state_apply_turn(['phase' => 'availability_checked', 'date' => 'tomorrow', 'time' => '5pm'], 'Yes book it', ['date' => true, 'time' => true], [], 'availability_checked', 'confirm_pending');
be_assert(in_array($stAwait['phase'], ['awaiting_customer_confirmation', 'creating', 'confirmed'], true), 'STATE-4 confirm advances phase');
$stFail = agent_booking_state_apply_turn(['phase' => 'creating'], '', [], [], 'creating', 'confirm_pending');
be_assert($stFail['phase'] === 'creating', 'STATE-5 creating persists until tool result');

// --- Missing service when configured required ---
$reqSvc = agent_booking_required_fields(['require_service' => true]);
be_assert(in_array('service', $reqSvc, true), 'BOOK-4 service required when configured');
$bfSvc = agent_core_decision_booking_field_status([], [], ['service'], 'Book tomorrow at 5');
be_assert(!$bfSvc['service'], 'BOOK-5 missing service flagged');

// --- Awaiting confirmation compose hint ---
$hintAwait = agent_booking_compose_hint(['booking_state' => ['phase' => 'awaiting_customer_confirmation', 'time' => '5pm', 'date' => 'tomorrow']], []);
be_assert($hintAwait !== '', 'HINT-2 awaiting confirmation hint');

// --- Reschedule / cancel need routing ---
$pResched = be_plan('Can I reschedule my appointment to Friday?');
be_assert(in_array($pResched['customer_need'] ?? '', ['book_appointment', 'answer_question', 'personal_support'], true), 'BOOK-6 reschedule routes booking/support');
$pCancel = be_plan('I need to cancel my booking.');
be_assert(in_array($pCancel['customer_need'] ?? '', ['obtain_refund', 'answer_question', 'close_conversation', 'personal_support'], true), 'BOOK-7 cancellation need');

// --- Duplicate create blocked in creating phase ---
$dupPlan = ['booking_state' => ['phase' => 'confirmed', 'booking_id' => 99], 'tool_calls' => []];
$dupEnriched = agent_booking_plan_enrich($dupPlan, be_plan('Book again tomorrow'), ['bot_id' => 501], []);
be_assert(($dupEnriched['booking_state']['phase'] ?? '') === 'confirmed', 'BOOK-8 confirmed state not reset on new request without explicit reschedule');

// --- Timezone in create fixture ---
$GLOBALS['_booking_tool_create_fixture'] = ['ok' => true, 'booking_id' => 78, 'status' => 'confirmed', 'date' => '2026-09-04', 'time' => '17:00', 'timezone' => 'Asia/Karachi', 'error' => ''];
$tzCreate = agent_core_tool('booking.create', ['iso_start' => '2026-09-04T17:00:00+05:00', 'iso_end' => '2026-09-04T17:30:00+05:00'], ['bot_id' => 501, 'lead_id' => 9001, 'allow_mutating_tools' => true]);
be_assert(($tzCreate['data']['timezone'] ?? '') === 'Asia/Karachi', 'TZ-1 create returns timezone');
unset($GLOBALS['_booking_tool_create_fixture']);

// --- Event partial details still collecting ---
$pEvPart = be_plan('I want to invite your CEO to speak.');
be_assert(($pEvPart['customer_need'] ?? '') === 'invite_speaker', 'EVENT-4 partial invite need');
be_assert(!empty($pEvPart['missing_information']), 'EVENT-5 partial invite missing fields');

// --- Refund pending support ---
$pRefund = be_plan('My refund is still pending.');
be_assert(in_array($pRefund['customer_need'] ?? '', ['obtain_refund', 'resolve_payment', 'personal_support'], true), 'SUP-5 refund pending');
$pDupCharge = be_plan('I was charged twice for the same order.');
be_assert(in_array($pDupCharge['customer_need'] ?? '', ['resolve_payment', 'obtain_refund', 'personal_support'], true), 'SUP-6 duplicate charge');

// --- Mixed support + sales ---
$pMixed = be_plan('My order is late and I also want to buy another item.');
be_assert(($pMixed['customer_need'] ?? '') === 'resolve_delivery', 'SUP-7 mixed message support wins');

// --- Handoff without capability ---
$pHandNoCap = be_plan('Connect me to a human please.', ['caps' => ['live_web']]);
$handTools = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $pHandNoCap['tool_calls'] ?? []);
be_assert(!in_array('human_handoff.create', $handTools, true), 'HANDOFF-4 no handoff tool without cap');

// --- Verification selected state ---
$vSel = agent_action_verification_context([
    'booking_phase' => 'availability_checked',
    'next_best_action' => 'confirm_pending',
], [['name' => 'booking.availability', 'ok' => true, 'data' => ['availability_verified' => true, 'slots' => [1]]]]);
be_assert($vSel['state'] === 'awaiting_customer_confirmation', 'VERIFY-3 awaiting confirmation after availability');

// --- Unavailable slot message ---
$GLOBALS['_booking_tool_availability_fixture'][501] = ['ok' => true, 'availability_verified' => true, 'slots' => [], 'message' => 'No slots available tomorrow.', 'timezone' => 'Asia/Karachi'];
$noSlots = booking_tool_availability(501, 6, 'tomorrow', '5pm');
be_assert(empty($noSlots['slots']), 'AVAIL-2 no slots when unavailable');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
