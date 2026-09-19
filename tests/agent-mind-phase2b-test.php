<?php
/**
 * Agent Mind Phase 2B — candidate actions, eligibility, NBA scoring.
 * Run: php tests/agent-mind-phase2b-test.php
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

$passed = 0;
$failed = 0;

function p2b_assert(bool $cond, string $name): void
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
function p2b_plan(string $text, array $overrides = []): array
{
    $intel = array_merge(
        conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []),
        $overrides['intel_patch'] ?? []
    );
    $turn = array_merge([
        'text'    => $text,
        'bot_id'  => 1,
        'lead_id' => 0,
        'bot'     => ['id' => 1, 'name' => 'Test Biz'],
    ], $overrides['turn'] ?? []);
    $conv = array_merge(['runtime_facts' => [], 'history' => [], 'last_assistant' => ''], $overrides['conv'] ?? []);
    $intent = array_merge(['kind' => 'FOLLOW_UP', 'tools' => []], $overrides['intent'] ?? []);
    $source = array_merge(['primary' => 'BUSINESS_KNOWLEDGE'], $overrides['source'] ?? []);
    $pack = array_merge(['brand' => 'Test Biz', 'rep' => 'Sam', 'capabilities' => ['catalog', 'cart', 'booking']], $overrides['pack'] ?? []);

    return agent_core_plan($turn, $conv, $intent, $source, $pack, $intel);
}

function p2b_selected(string $text, array $overrides = []): string
{
    return (string) (p2b_plan($text, $overrides)['selected_action'] ?? '');
}

function p2b_ineligible(string $text, string $action, array $overrides = []): bool
{
    $plan = p2b_plan($text, $overrides);
    $eligible = is_array($plan['eligible_actions'] ?? null) ? $plan['eligible_actions'] : [];

    return !in_array($action, $eligible, true);
}

echo "Agent Mind Phase 2B — Candidate Actions + NBA Scoring\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

// Wiring
p2b_assert(is_file($root . '/includes/agent-core/nba.php'), 'WIRING: nba.php exists');
$decSrc = file_get_contents($root . '/includes/agent-core/decision.php') ?: '';
p2b_assert(str_contains($decSrc, 'agent_core_nba_apply'), 'WIRING: decision calls NBA apply');

// --- Required scoring tests A–J ---
// A: Support need beats sales
$a = p2b_plan("My order hasn't arrived.");
p2b_assert(($a['selected_action'] ?? '') === 'RESOLVE_SUPPORT', 'A: support need → RESOLVE_SUPPORT');
p2b_assert(p2b_ineligible("My order hasn't arrived.", 'START_ORDER'), 'A: START_ORDER ineligible on delivery issue');
p2b_assert(!in_array('START_ORDER', $a['eligible_actions'] ?? [], true), 'A: START_ORDER not eligible');

// B: Explicit request beats speculative upsell
$b = p2b_plan('Can I speak to a human?');
p2b_assert(($b['selected_action'] ?? '') === 'OFFER_HUMAN_HANDOFF', 'B: explicit human request → handoff');

// C: Ready customer beats unnecessary education
$c = p2b_plan('I want to buy the blue one. How do I order?', ['intent' => ['kind' => 'CATALOG']]);
p2b_assert(in_array($c['selected_action'] ?? '', ['START_ORDER', 'START_CHECKOUT', 'COMPLETE', 'SHOW_PRODUCT', 'ANSWER'], true), 'C: ready purchase → action not QUALIFY');
p2b_assert(($c['selected_action'] ?? '') !== 'QUALIFY', 'C: no unnecessary qualification');

// D: Missing required → collect
$d = p2b_plan('Book me tomorrow.', ['pack' => ['capabilities' => ['booking']]]);
p2b_assert(in_array($d['selected_action'] ?? '', ['COLLECT_REQUIRED_INFORMATION', 'CLARIFY', 'OFFER_BOOKING'], true), 'D: booking with gaps → collect/clarify/book');
p2b_assert(($d['missing_information'] ?? []) !== [] || ($d['selected_action'] ?? '') === 'COLLECT_REQUIRED_INFORMATION', 'D: missing info recognized');

// E: Complete information → progression
$e = p2b_plan('Yes, book it.', [
    'intel_opts' => ['state' => ['pending_action' => 'BOOKING_REQUEST']],
    'conv'       => ['runtime_facts' => ['service' => 'Consultation', 'customer_name' => 'Ali', 'date' => 'tomorrow', 'time' => '2pm']],
]);
p2b_assert(in_array($e['selected_action'] ?? '', ['COMPLETE', 'OFFER_BOOKING'], true), 'E: complete info → COMPLETE/OFFER_BOOKING');
p2b_assert(($e['next_best_action'] ?? '') === 'confirm_pending', 'E: maps to confirm_pending NBA');

// F: Unavailable capability cannot win
$f = p2b_plan('Book me tomorrow at 2 PM.', ['pack' => ['capabilities' => ['catalog', 'cart']]]);
p2b_assert(p2b_ineligible('Book me tomorrow at 2 PM.', 'OFFER_BOOKING', ['pack' => ['capabilities' => ['catalog']]]), 'F: OFFER_BOOKING ineligible without booking');
p2b_assert(($f['selected_action'] ?? '') !== 'OFFER_BOOKING', 'F: booking action not selected without capability');

// G: STOP beats continued engagement
$g = p2b_plan("Thanks, that's all.");
p2b_assert(($g['selected_action'] ?? '') === 'STOP', 'G: closing → STOP');
p2b_assert(!empty($g['stop_allowed']), 'G: stop_allowed set');

// H: Low confidence beats risky action
$h = p2b_plan('maybe buy something?', ['intel_patch' => ['ambiguity' => 0.75, 'confidence' => 0.25]]);
p2b_assert(!in_array($h['selected_action'] ?? '', ['START_ORDER', 'START_CHECKOUT', 'COMPLETE'], true), 'H: low confidence avoids risky conversion');
p2b_assert(in_array($h['selected_action'] ?? '', ['CLARIFY', 'ANSWER', 'RECOMMEND', 'OFFER_HUMAN_HANDOFF', 'COLLECT_REQUIRED_INFORMATION'], true), 'H: low confidence favors safe action');

// I: Multi-intent combined
$i = p2b_plan('I want the blue laptop, how much is it, and can you deliver tomorrow?');
p2b_assert(count($i['structured_intents'] ?? []) >= 2, 'I: multi-intent detected');
p2b_assert(in_array($i['selected_action'] ?? '', ['ANSWER', 'OFFER_QUOTE', 'SHOW_PRODUCT', 'CHECK_AVAILABILITY', 'RECOMMEND'], true), 'I: combined informational action');
p2b_assert(!empty($i['multi_intent_combined']) || (int) ($i['message_budget'] ?? 0) === 1, 'I: single-turn budget/combined flag');

// J: Customer value beats commercial value
$jBrowse = p2b_plan("I'm just browsing.");
p2b_assert(in_array($jBrowse['selected_action'] ?? '', ['ANSWER', 'RECOMMEND', 'SHOW_SERVICE'], true), 'J: browsing → answer/recommend not checkout');
p2b_assert(p2b_ineligible("I'm just browsing.", 'START_CHECKOUT'), 'J: checkout ineligible when browsing');

// --- Information / price ---
p2b_assert(p2b_selected('What are your prices?') === 'OFFER_QUOTE', 'PRICE-1: general prices → OFFER_QUOTE');
p2b_assert((p2b_plan('What are your prices?')['next_best_action'] ?? '') === 'answer_price', 'PRICE-2: legacy NBA answer_price');
p2b_assert(p2b_selected('How much is your consultation?') === 'OFFER_QUOTE', 'PRICE-3: consultation price → OFFER_QUOTE');

// --- Product / service ---
p2b_assert(in_array(p2b_selected('I need a laptop for office around 150k'), ['RECOMMEND', 'SHOW_PRODUCT', 'ANSWER'], true), 'PROD-1: product need');
p2b_assert(in_array(p2b_selected('What services do you offer?'), ['SHOW_SERVICE', 'ANSWER', 'RECOMMEND'], true), 'SVC-1: services question');

// --- Purchase / conversion ---
$purchase = p2b_plan('Yes, place the order.', ['intel_patch' => ['affirmation' => 'confirm', 'purchase_stage' => 'purchase_intent']]);
p2b_assert(in_array($purchase['selected_action'] ?? '', ['START_ORDER', 'START_CHECKOUT', 'COMPLETE', 'COLLECT_REQUIRED_INFORMATION'], true), 'CONV-1: order confirmation action');
p2b_assert(($purchase['next_best_action'] ?? '') === 'confirm_pending', 'CONV-1b: confirm maps to confirm_pending');

// --- Booking ---
p2b_assert(in_array(p2b_selected('Book an appointment tomorrow'), ['OFFER_BOOKING', 'COLLECT_REQUIRED_INFORMATION'], true), 'BOOK-1: booking intent');

// --- Support / delivery / refund ---
p2b_assert(p2b_selected('My delivery is late') === 'RESOLVE_SUPPORT', 'SUP-1: delivery late');
p2b_assert(in_array(p2b_selected('Forget the order, I need a refund.'), ['RESOLVE_SUPPORT', 'OFFER_HUMAN_HANDOFF'], true), 'SUP-2: refund');
p2b_assert(p2b_selected('Where is my order?') === 'TRACK_ORDER' || p2b_selected('Where is my order?') === 'RESOLVE_SUPPORT', 'SUP-3: track order');

// --- Cancellation ---
$cancel = p2b_plan('never mind', ['intel_opts' => ['state' => ['pending_action' => 'BOOKING_REQUEST']]]);
p2b_assert(($cancel['next_best_action'] ?? '') === 'cancel_pending', 'CAN-1: cancel maps to cancel_pending');

// --- Objection ---
p2b_assert(p2b_selected("It's too expensive.") === 'HANDLE_OBJECTION', 'OBJ-1: price objection');

// --- Readiness levels ---
p2b_assert(in_array(p2b_selected('What do you offer?'), ['SHOW_SERVICE', 'ANSWER', 'RECOMMEND'], true), 'READY-LOW: exploring');
p2b_assert(p2b_selected('That sounds good.') !== 'START_ORDER', 'READY-QUAL: sounds good not immediate checkout');

// --- Knowledge unavailable ---
$noPrice = p2b_plan('How much for this one?', ['pack' => ['business_facts' => ['We offer premium coaching.'], 'capabilities' => ['catalog']]]);
p2b_assert(in_array($noPrice['selected_action'] ?? '', ['CLARIFY', 'COLLECT_REQUIRED_INFORMATION', 'ANSWER', 'RECOMMEND'], true), 'KNOW-1: no price evidence → safe action');
p2b_assert(empty($noPrice['knowledge_available']), 'KNOW-2: knowledge_available false');

$withPrice = p2b_plan('What are your prices?', ['pack' => ['business_facts' => ['Consultation: PKR 5000'], 'capabilities' => ['catalog']]]);
p2b_assert(!empty($withPrice['knowledge_available']), 'KNOW-3: knowledge_available when price in facts');

// --- Contract fields ---
$contract = p2b_plan('How much is coaching?');
p2b_assert(is_array($contract['candidate_actions'] ?? null) && ($contract['candidate_actions'] ?? []) !== [], 'CONTRACT: candidate_actions');
p2b_assert(is_array($contract['eligible_actions'] ?? null), 'CONTRACT: eligible_actions');
p2b_assert(($contract['selected_action'] ?? '') !== '', 'CONTRACT: selected_action');
p2b_assert(isset($contract['action_score']), 'CONTRACT: action_score');
p2b_assert(is_array($contract['action_reasons'] ?? null), 'CONTRACT: action_reasons');
p2b_assert(is_array($contract['scored_actions'] ?? null), 'CONTRACT: scored_actions');

// --- Phase 1 NBA compatibility ---
p2b_assert(in_array(p2b_plan('thank you')['next_best_action'] ?? '', ['human_social_reply'], true), 'LEGACY: thank you → human_social_reply');
p2b_assert((p2b_plan('What are your prices?', ['pack' => ['business_facts' => ['Coaching: PKR 15000']]])['next_best_action'] ?? '') === 'answer_price', 'LEGACY: answer_price preserved');

// --- Ambiguous intent ---
$ambig = p2b_plan('maybe', ['intel_patch' => ['ambiguity' => 0.7, 'confidence' => 0.3, 'primary_intent' => 'UNKNOWN']]);
p2b_assert(in_array($ambig['selected_action'] ?? '', ['CLARIFY', 'ANSWER', 'OFFER_HUMAN_HANDOFF'], true), 'AMBIG: low confidence clarifies');

// --- Conflicting signals (ORDER_REQUEST text but delivery need) ---
$conflict = p2b_plan("My order hasn't arrived.");
p2b_assert(($conflict['customer_need'] ?? '') === 'resolve_delivery', 'CONFLICT: need-first correction');
p2b_assert(($conflict['selected_action'] ?? '') === 'RESOLVE_SUPPORT', 'CONFLICT: support wins over order intent');

// --- Human handoff eligibility ---
p2b_assert(p2b_ineligible('thank you', 'OFFER_HUMAN_HANDOFF'), 'HANDOFF: not eligible on casual social');
p2b_assert(p2b_selected('I need a person to help with my refund') === 'OFFER_HUMAN_HANDOFF' || p2b_selected('Can I speak to a human?') === 'OFFER_HUMAN_HANDOFF', 'HANDOFF: explicit request wins');

// --- Memory / no re-collect when complete ---
$memBook = p2b_plan('Yes, book it.', [
    'intel_opts' => ['state' => ['pending_action' => 'BOOKING_REQUEST']],
    'conv'       => ['runtime_facts' => ['service' => 'Consultation', 'customer_name' => 'Sara', 'date_time' => 'tomorrow 2pm']],
]);
p2b_assert(($memBook['selected_action'] ?? '') !== 'COLLECT_REQUIRED_INFORMATION', 'MEM: no collect when info complete');

// --- Observability ---
$bits = agent_core_observe_decision_bits(
    ['primary_intent' => 'PRICE_INQUIRY', 'secondary_intents' => [], 'emotion' => 'curious', 'purchase_stage' => 'interest'],
    [
        'customer_need'   => 'learn_pricing',
        'customer_goal'   => 'obtain_quote',
        'readiness'       => 'INTERESTED',
        'selected_action' => 'OFFER_QUOTE',
        'action_score'    => 82.5,
        'next_best_action'=> 'answer_price',
        'action_confidence' => 0.82,
        'outcome'         => 'CATALOG',
        'answer_kind'     => 'BUSINESS',
        'cta_mode'        => 'price_advance',
    ]
);
p2b_assert(($bits['selected_action'] ?? '') === 'OFFER_QUOTE', 'OBS: selected_action in bits');
p2b_assert(($bits['action_score'] ?? 0) > 0, 'OBS: action_score in bits');

// --- Scoring order: RESOLVE_SUPPORT scores above START_ORDER on support ---
$scoreSupport = p2b_plan("My order hasn't arrived.");
$scored = is_array($scoreSupport['scored_actions'] ?? null) ? $scoreSupport['scored_actions'] : [];
$supportScore = 0.0;
$orderScore = 0.0;
foreach ($scored as $row) {
    if (($row['action'] ?? '') === 'RESOLVE_SUPPORT') {
        $supportScore = (float) ($row['score'] ?? 0);
    }
    if (($row['action'] ?? '') === 'START_ORDER') {
        $orderScore = (float) ($row['score'] ?? 0);
    }
}
p2b_assert($supportScore > $orderScore || !in_array('START_ORDER', $scoreSupport['eligible_actions'] ?? [], true), 'SCORE: support outranks order on delivery issue');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
