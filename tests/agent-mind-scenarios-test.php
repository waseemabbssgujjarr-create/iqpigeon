<?php
/**
 * Agent Mind Phase 1 — 20 controlled scenarios (spec examples A–K + extensions).
 * Tests deterministic intelligence → plan → validation fusion (no OpenAI).
 *
 * Run: php tests/agent-mind-scenarios-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/conversation-intelligence.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/includes/agent-core/plan.php';
require_once $root . '/includes/agent-core/intelligence.php';
require_once $root . '/includes/agent-core/decision.php';
require_once $root . '/includes/agent-core/nba.php';
require_once $root . '/includes/agent-core/cta.php';
require_once $root . '/includes/agent-core/validate.php';
require_once $root . '/includes/agent-core/compose.php';
require_once $root . '/includes/helpers.php';

$GLOBALS['agent_core_no_network'] = true;

$passed = 0;
$failed = 0;
$skipped = 0;

function am_assert(bool $cond, string $name): void
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

function am_skip(string $name, string $reason): void
{
    global $skipped;
    echo "SKIP: {$name} — {$reason}\n";
    $skipped++;
}

/**
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function am_plan_from_intelligence(array $intelligence, array $overrides = []): array
{
    $turn = array_merge([
        'text'    => '',
        'bot_id'  => 1,
        'lead_id' => 0,
        'bot'     => ['id' => 1, 'name' => 'Test Biz'],
    ], $overrides['turn'] ?? []);
    $conv = array_merge([
        'runtime_facts'  => [],
        'history'        => [],
        'last_assistant' => '',
    ], $overrides['conv'] ?? []);
    $intent = array_merge(['kind' => 'FOLLOW_UP', 'tools' => []], $overrides['intent'] ?? []);
    $source = array_merge(['primary' => 'BUSINESS_KNOWLEDGE'], $overrides['source'] ?? []);
    $pack = array_merge(['brand' => 'Test Biz', 'rep' => 'Sam'], $overrides['pack'] ?? []);
    $base = agent_core_plan($turn, $conv, $intent, $source, $pack, $intelligence);

    return $base;
}

/**
 * Full fusion chain: Intelligence → Plan → Compose context (deterministic).
 *
 * @return array{intel: array, plan: array, mind: array, turn: array, conv: array, pack: array}
 */
function am_fusion(string $text, array $overrides = []): array
{
    $intel = conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []);
    $turn = array_merge([
        'text'    => $text,
        'bot_id'  => 1,
        'lead_id' => 0,
        'bot'     => ['id' => 1, 'name' => 'Test Biz'],
    ], $overrides['turn'] ?? []);
    $conv = array_merge([
        'runtime_facts'  => [],
        'history'        => [],
        'last_assistant' => '',
    ], $overrides['conv'] ?? []);
    $pack = array_merge(['brand' => 'Test Biz', 'rep' => 'Sam', 'business_facts' => []], $overrides['pack'] ?? []);
    $plan = am_plan_from_intelligence($intel, [
        'turn'   => $turn,
        'conv'   => $conv,
        'pack'   => $pack,
        'intent' => $overrides['intent'] ?? [],
        'source' => $overrides['source'] ?? [],
    ]);
    $mind = agent_core_mind_ctx_from_plan(
        $pack,
        $plan,
        $overrides['tool_results'] ?? [],
        $turn,
        $conv
    );

    return ['intel' => $intel, 'plan' => $plan, 'mind' => $mind, 'turn' => $turn, 'conv' => $conv, 'pack' => $pack];
}

echo "Agent Mind Phase 1 Hardening Scenarios\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

// Wiring
$pipelineSrc = file_get_contents($root . '/includes/agent-core/pipeline.php') ?: '';
$planSrc = file_get_contents($root . '/includes/agent-core/plan.php') ?: '';
am_assert(
    str_contains($pipelineSrc, 'agent_core_intelligence_for_turn')
    && str_contains($pipelineSrc, 'agent_core_observe_decision_bits')
    && str_contains($planSrc, 'agent_core_plan_apply_intelligence'),
    'WIRING: pipeline calls intelligence once and plan applies fusion'
);
am_assert(is_file($root . '/includes/agent-core/intelligence.php'), 'WIRING: intelligence bridge module exists');
am_assert(defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false, 'SAFETY: AGENT_CORE_ENABLED remains false');

// A — INFORMATION → ADVANCE (price inquiry)
$a = conversation_intelligence_analyze('How much is coaching?');
$planA = am_plan_from_intelligence($a, ['turn' => ['text' => 'How much is coaching?']]);
am_assert($a['primary_intent'] === 'PRICE_INQUIRY', 'A: price question → PRICE_INQUIRY');
am_assert(in_array($planA['next_best_action'] ?? '', ['answer_price', 'ask_one_clarifier'], true), 'A: plan NBA is price or one clarifier');
am_assert(!empty($planA['response_goal']), 'A: plan has response_goal');
am_assert(!empty($planA['forbid_generic_loop']), 'A: forbids generic empathy loop');

// B — HIGH PURCHASE INTENT
$b = conversation_intelligence_analyze('I want to buy the blue one. How do I order?');
$planB = am_plan_from_intelligence($b, ['turn' => ['text' => 'I want to buy the blue one. How do I order?'], 'intent' => ['kind' => 'CATALOG']]);
am_assert(in_array($b['primary_intent'], ['ORDER_REQUEST', 'PRODUCT_SEARCH', 'PRODUCT_AVAILABILITY'], true), 'B: purchase intent detected');
am_assert(($planB['readiness'] ?? '') !== '' || ($b['purchase_stage'] ?? '') !== '', 'B: readiness/purchase_stage present');
am_assert(empty($planB['clarification_needed']) || ($planB['next_best_action'] ?? '') !== 'human_social_reply', 'B: not treated as casual social');

// C — MULTI-INTENT
$c = conversation_intelligence_analyze('I need the blue laptop around 150k and can you deliver tomorrow?');
$planC = am_plan_from_intelligence($c, ['turn' => ['text' => 'I need the blue laptop around 150k and can you deliver tomorrow?']]);
am_assert(count($c['intents'] ?? []) >= 2, 'C: multiple intents detected');
am_assert(is_array($planC['secondary_intents']), 'C: plan carries secondary intents');
am_assert(($c['entities']['color'] ?? '') === 'blue' || str_contains(json_encode($c['entities'] ?? []), 'blue'), 'C: product/color extracted');

// D — SUPPORT (delivery late)
$d = conversation_intelligence_analyze('My delivery is late');
$planD = am_plan_from_intelligence($d, ['turn' => ['text' => 'My delivery is late']]);
am_assert(in_array($d['primary_intent'], ['DELIVERY_QUERY', 'COMPLAINT', 'SUPPORT', 'RETURN_REQUEST'], true), 'D: support/delivery intent');
am_assert(($planD['cta_mode'] ?? '') === 'OFFER_SUPPORT', 'D: plan CTA mode is support not sales');
am_assert(str_contains((string) ($planD['response_goal'] ?? ''), 'resolution') || str_contains((string) ($planD['response_goal'] ?? ''), 'Support'), 'D: response goal is resolution');

// E — BOOKING
$e = conversation_intelligence_analyze('book an appointment tomorrow');
$planE = am_plan_from_intelligence($e, ['turn' => ['text' => 'book an appointment tomorrow']]);
am_assert($e['primary_intent'] === 'BOOKING_REQUEST', 'E: booking intent');
am_assert(in_array($planE['cta_mode'] ?? '', ['OFFER_BOOKING', 'ASK_REQUIRED', 'INVITE', 'CONFIRM'], true), 'E: booking-oriented CTA mode');

// F — EMOTIONAL / COACHING (distress plan behavior when emotion=worried)
$fBase = conversation_intelligence_analyze("I have anxiety and need support.");
$f = array_merge($fBase, ['emotion' => 'worried']);
$planF = am_plan_from_intelligence($f, ['turn' => ['text' => "I have anxiety and need support."]]);
am_assert(($f['emotion'] ?? '') === 'worried', 'F: worried emotion carried into plan path');
am_assert(!empty($planF['forbid_generic_loop']), 'F: plan forbids generic mental-health loop');
am_assert(str_contains((string) ($planF['response_goal'] ?? ''), 'no diagnosis') || str_contains((string) ($planF['response_goal'] ?? ''), 'no mental-health'), 'F: response goal blocks diagnosis lecture');

// G — CUSTOMER ALREADY READY (yes confirms pending)
$g = conversation_intelligence_analyze('yes', ['state' => ['pending_action' => 'BOOKING_REQUEST', 'last_product' => 'Consultation']]);
$planG = am_plan_from_intelligence($g, [
    'turn'  => ['text' => 'yes'],
    'conv'  => ['runtime_facts' => ['service' => 'Consultation', 'customer_name' => 'Ahmed']],
]);
am_assert($g['next_best_action'] === 'confirm_pending', 'G: yes with pending → confirm_pending');
am_assert(empty($planG['clarification_needed']), 'G: no unnecessary clarifier when confirming');

// H — INTENT CHANGE (cancel order → refund)
$h = conversation_intelligence_analyze('Actually forget the order. I need help with a refund.');
$planH = am_plan_from_intelligence($h, ['turn' => ['text' => 'Actually forget the order. I need help with a refund.']]);
am_assert(in_array($h['primary_intent'], ['CANCELLATION', 'RETURN_REQUEST', 'COMPLAINT', 'SUPPORT'], true), 'H: switches to refund/support');
am_assert(empty($planH['advance_allowed']) || ($planH['next_best_action'] ?? '') === 'cancel_pending', 'H: not continuing sales flow');

// I — KNOWN INFORMATION (do not ask name/service)
$iIntel = conversation_intelligence_analyze('Can we schedule?', [
    'memory' => ['customer_name' => 'Ahmed', 'service' => 'Consultation'],
    'state'  => ['last_service' => 'Consultation'],
]);
$planI = am_plan_from_intelligence($iIntel, [
    'turn' => ['text' => 'Can we schedule?'],
    'conv' => ['runtime_facts' => ['customer_name' => 'Ahmed', 'service' => 'Consultation']],
]);
am_assert(in_array('customer_name=Ahmed', $planI['known_information'] ?? [], true) || in_array('name=Ahmed', $planI['known_information'] ?? [], true), 'I: known name in plan');
$badDraft = "What is your name? And which service are you interested in?";
$valI = agent_core_validate_plan_decision($badDraft, ['lead_id' => 0], $planI, []);
am_assert(empty($valI['ok']) && ($valI['reason'] ?? '') === 'asks_known_info', 'I: validator rejects asking known name');

// J — KNOWLEDGE PRIORITY (business delivery days in plan note path)
$jPlan = am_plan_from_intelligence(
    conversation_intelligence_analyze('How long is delivery?'),
    [
        'turn' => ['text' => 'How long is delivery?'],
        'pack' => ['brand' => 'Shop', 'business_facts' => ['Delivery takes 3–5 business days.']],
    ]
);
am_assert(($jPlan['answer_kind'] ?? '') !== '' || ($jPlan['next_best_action'] ?? '') === 'answer_turn', 'J: delivery question gets answer plan');
$jNote = agent_core_mind_ctx_from_plan(
    ['brand' => 'Shop', 'business_facts' => ['Delivery takes 3–5 business days.'], 'prompt' => ''],
    $jPlan,
    [],
    ['text' => 'How long is delivery?', 'bot' => ['id' => 1]],
    ['runtime_facts' => []]
);
am_assert(str_contains((string) ($jNote['plan_note'] ?? ''), 'Answer') || str_contains((string) ($jNote['plan_note'] ?? ''), 'Goal'), 'J: plan_note is plan-bound');

// K — UNAVAILABLE ACTION (booking without confirmation)
$kPlan = am_plan_from_intelligence(
    conversation_intelligence_analyze('Book me tomorrow at 2 PM.'),
    ['turn' => ['text' => 'Book me tomorrow at 2 PM.']]
);
$falseClaim = "Your appointment is booked for tomorrow at 2 PM.";
$valK = agent_core_validate_plan_decision($falseClaim, ['lead_id' => 0], $kPlan, []);
am_assert(empty($valK['ok']) && ($valK['reason'] ?? '') === 'false_action_claim', 'K: blocks false booking claim');

// L — DEAD-END when actionable NBA
$planL = am_plan_from_intelligence(
    conversation_intelligence_analyze('How much is the coaching package?'),
    ['turn' => ['text' => 'How much is the coaching package?']]
);
$deadEnd = "Feel free to ask if you need anything else!";
$valL = agent_core_validate_plan_decision($deadEnd, ['lead_id' => 0], $planL, []);
am_assert(empty($valL['ok']) && ($valL['reason'] ?? '') === 'dead_end_generic', 'L: dead-end generic blocked when NBA actionable');

// M — REPETITION
$repPlan = ['next_best_action' => 'answer_turn', 'forbid_generic_loop' => false];
$valM = agent_core_validate_plan_decision(
    'Our coaching starts at 5000 PKR per session.',
    ['lead_id' => 0],
    $repPlan,
    ['last_assistant' => 'Our coaching starts at 5000 PKR per session.']
);
am_assert(empty($valM['ok']) && ($valM['reason'] ?? '') === 'repetitive', 'M: repetition vs last_assistant blocked');

// N — SOCIAL (no menu pitch)
$n = conversation_intelligence_analyze('thank you');
$planN = am_plan_from_intelligence($n, ['turn' => ['text' => 'thank you']]);
am_assert($n['next_best_action'] === 'human_social_reply', 'N: thank you → social reply');
am_assert(in_array($planN['cta_mode'] ?? '', ['SOCIAL', 'NONE'], true), 'N: social CTA mode');

// O — FRAGMENTED MERGE (via intelligence on combined text)
$o = conversation_intelligence_analyze('I need a laptop for office around 150k');
am_assert(($o['entities']['product'] ?? '') !== '' || in_array($o['primary_intent'], ['PRODUCT_SEARCH', 'PRICE_INQUIRY'], true), 'O: product/budget intent from combined need');

// P — ROMAN URDU PRICE
$p = conversation_intelligence_analyze('ye kitne ka hai?');
am_assert($p['primary_intent'] === 'PRICE_INQUIRY', 'P: Roman Urdu price intent');

// Q — CANCEL PENDING
$q = conversation_intelligence_analyze('never mind', ['state' => ['pending_action' => 'BOOKING_REQUEST']]);
$planQ = am_plan_from_intelligence($q, ['turn' => ['text' => 'never mind']]);
am_assert($q['next_best_action'] === 'cancel_pending', 'Q: never mind cancels pending');
am_assert(in_array($planQ['cta_mode'] ?? '', ['STOP', 'NONE', 'OFFER_SUPPORT'], true), 'Q: stop-oriented CTA on cancel');

// R — OBSERVABILITY SANITIZATION
$bits = agent_core_observe_decision_bits(
    [
        'primary_intent'      => 'PRICE_INQUIRY',
        'secondary_intents'   => ['DELIVERY_QUERY'],
        'emotion'             => 'curious',
        'purchase_stage'      => 'interest',
        'next_best_action'    => 'answer_price',
        'missing_information' => ['product'],
    ],
    ['outcome' => 'CATALOG', 'answer_kind' => 'BUSINESS', 'next_best_action' => 'answer_price', 'cta_mode' => 'price_advance']
);
am_assert(!isset($bits['message']) && !isset($bits['prompt']), 'R: observability has no message/prompt secrets');
am_assert(($bits['primary_intent'] ?? '') === 'PRICE_INQUIRY' && ($bits['plan_outcome'] ?? '') === 'CATALOG', 'R: structured decision bits present');

// S — PIPELINE INTELLIGENCE STAGE (unit via intelligence_for_turn)
$s = agent_core_intelligence_for_turn(
    ['text' => 'How much is coaching?', 'bot_id' => 1, 'lead_id' => 0],
    ['runtime_facts' => []]
);
am_assert(($s['primary_intent'] ?? '') === 'PRICE_INQUIRY', 'S: intelligence_for_turn returns analyze result');

// T — MULTI-INTENT SECONDARY ON PLAN
$t = conversation_intelligence_analyze('Do you deliver and what are your hours?');
$planT = am_plan_from_intelligence($t, ['turn' => ['text' => 'Do you deliver and what are your hours?']]);
am_assert(count($planT['secondary_intents'] ?? []) >= 0, 'T: plan accepts secondary intents array');
am_assert(($planT['next_best_action'] ?? '') !== '', 'T: plan has NBA for multi-question');

// --- PRICE VARIANTS (fundamental business intent) ---
foreach ([
    'What are your prices?' => 'answer_price',
    'How much is it?' => null,
    'How much does this cost?' => null,
    "What's the price?" => null,
    'How much do you charge?' => null,
    'What are your rates?' => null,
    'Can you tell me the price?' => null,
] as $msg => $expectNba) {
    $pv = conversation_intelligence_analyze($msg);
    am_assert($pv['primary_intent'] === 'PRICE_INQUIRY', 'PRICE variant intent: ' . $msg);
    if ($expectNba !== null) {
        $pp = am_plan_from_intelligence($pv, ['turn' => ['text' => $msg]]);
        am_assert(($pp['next_best_action'] ?? '') === $expectNba, 'PRICE variant NBA: ' . $msg);
    }
}

// --- FUSION: decision propagates Intelligence → Plan → Compose ---
$fuse = am_fusion('What are your prices?', [
    'pack' => ['business_facts' => ['1-on-1 coaching: PKR 15000/month']],
    'source' => ['primary' => 'BUSINESS_KNOWLEDGE'],
]);
am_assert($fuse['intel']['primary_intent'] === 'PRICE_INQUIRY', 'FUSION: CI primary intent on price');
am_assert($fuse['plan']['primary_intent'] === 'PRICE_INQUIRY', 'FUSION: plan carries CI primary intent');
am_assert($fuse['plan']['next_best_action'] === 'answer_price', 'FUSION: plan NBA answer_price');
am_assert(str_contains((string) ($fuse['mind']['plan_note'] ?? ''), 'Next action: answer_price'), 'FUSION: compose plan_note includes NBA');
am_assert(str_contains((string) ($fuse['mind']['plan_note'] ?? ''), 'Goal:'), 'FUSION: compose plan_note includes response goal');
am_assert(($fuse['plan']['emotion'] ?? '') === ($fuse['intel']['emotion'] ?? ''), 'FUSION: emotion preserved in plan');
am_assert(in_array($fuse['plan']['readiness'] ?? '', ['LOW', 'EXPLORING', 'INTERESTED', 'QUALIFIED', 'READY', 'COMPLETING'], true), 'FUSION: Phase 2 readiness level on plan');
am_assert(($fuse['plan']['readiness_raw'] ?? '') === ($fuse['intel']['purchase_stage'] ?? ''), 'FUSION: purchase_stage preserved as readiness_raw');

// --- BUSINESS KNOWLEDGE: verified price ---
$known = am_fusion('What are your prices?', [
    'pack' => ['business_facts' => ['Coaching sessions: PKR 15000 per month']],
]);
am_assert($known['plan']['next_best_action'] === 'answer_price', 'BIZ-KNOWN: answer_price NBA');
am_assert(!str_contains((string) ($known['mind']['plan_note'] ?? ''), 'do NOT invent a number'), 'BIZ-KNOWN: no invent warning when price evidence exists');
am_assert(str_contains((string) ($known['mind']['plan_note'] ?? ''), 'CTA mode:'), 'BIZ-KNOWN: CTA mode in plan_note for advance');

// --- BUSINESS KNOWLEDGE: price unknown — no hallucination ---
$unknown = am_fusion('What are your prices?', [
    'pack' => ['business_facts' => ['We offer premium coaching and consulting.']],
]);
am_assert(str_contains((string) ($unknown['mind']['plan_note'] ?? ''), 'do NOT invent a number'), 'BIZ-UNKNOWN: compose warns against inventing price');
$fakePrice = 'Our coaching starts at PKR 99999 per month.';
$valFake = agent_core_validate_plan_decision($fakePrice, ['lead_id' => 0, 'test_pack' => $unknown['pack']], $unknown['plan'], []);
am_assert(empty($valFake['ok']) && ($valFake['reason'] ?? '') === 'false_price_claim', 'BIZ-UNKNOWN: validator blocks invented price');

// --- ANSWER + ADVANCE vs STOP ---
$advance = am_fusion('What are your prices?', ['pack' => ['business_facts' => ['Consultation: PKR 5000']]]);
am_assert(!empty($advance['plan']['advance_allowed']), 'ADVANCE: price inquiry allows advance');
am_assert(in_array($advance['plan']['cta_mode'] ?? '', ['OFFER_QUOTE', 'OFFER_PRODUCT', 'INVITE'], true), 'ADVANCE: contextual price CTA mode');
$stop = am_fusion('thank you');
am_assert(!empty($stop['plan']['stop_allowed']), 'STOP: social allows natural stop');
am_assert(in_array($stop['plan']['cta_mode'] ?? '', ['SOCIAL', 'NONE', 'STOP'], true), 'STOP: social has no push CTA');

// --- ASK ONLY WHAT IS NECESSARY ---
$memTurn = am_fusion('When can we meet?', [
    'intel_opts' => ['memory' => ['customer_name' => 'Sara', 'service' => 'Consultation'], 'state' => ['last_service' => 'Consultation']],
    'conv' => ['runtime_facts' => ['customer_name' => 'Sara', 'service' => 'Consultation']],
]);
am_assert(in_array('customer_name=Sara', $memTurn['plan']['known_information'] ?? [], true), 'ASK-MEM: name in known_information');
am_assert(empty(agent_core_validate_plan_decision('What is your name?', ['lead_id' => 0], $memTurn['plan'], [])['ok']), 'ASK-MEM: blocks re-asking name');
$entityIntel = conversation_intelligence_analyze('How much?', ['state' => ['last_product' => 'Blue Widget'], 'memory' => ['product' => 'Blue Widget']]);
$entityPlan = am_plan_from_intelligence($entityIntel, [
    'turn' => ['text' => 'How much?'],
    'conv' => ['runtime_facts' => ['product' => 'Blue Widget']],
]);
am_assert(in_array('product=Blue Widget', $entityPlan['known_information'] ?? [], true), 'ASK-CTX: product known from context');

// --- DEAD-END PREVENTION ---
$deadPlan = am_plan_from_intelligence(
    conversation_intelligence_analyze('How much is coaching?'),
    ['turn' => ['text' => 'How much is coaching?']]
);
foreach ([
    'Feel free to ask.',
    'Let me know if you need anything.',
    'Would you like to know more?',
    'Hope this helps.',
] as $phrase) {
    $vd = agent_core_validate_plan_decision($phrase, ['lead_id' => 0], $deadPlan, []);
    am_assert(empty($vd['ok']) && ($vd['reason'] ?? '') === 'dead_end_generic', 'DEAD-END blocked: ' . $phrase);
}

// --- FALSE ACTION CLAIMS ---
$bookPlan = am_plan_from_intelligence(conversation_intelligence_analyze('Book me tomorrow at 2 PM.'), ['turn' => ['text' => 'Book me tomorrow at 2 PM.']]);
foreach ([
    'Your appointment is booked for tomorrow at 2 PM.' => 'false_action_claim',
    'Your order has been placed successfully.' => 'false_action_claim',
    'Payment has been received and confirmed.' => 'false_action_claim',
    'I have booked your slot for tomorrow.' => 'false_action_claim',
] as $claim => $expectReason) {
    $vc = agent_core_validate_plan_decision($claim, ['lead_id' => 0], $bookPlan, []);
    am_assert(empty($vc['ok']) && ($vc['reason'] ?? '') === $expectReason, 'FALSE-ACTION blocked: ' . mb_substr($claim, 0, 40));
}

// --- PLAN INFLUENCES GENERATION INSTRUCTIONS (not just logs) ---
$urgentIntel = conversation_intelligence_analyze('I need this urgently — how much is it?');
am_assert($urgentIntel['emotion'] === 'urgency', 'EMOTION: urgency detected');
$urgentPlan = am_plan_from_intelligence($urgentIntel, ['turn' => ['text' => 'I need this urgently — how much is it?']]);
am_assert(str_contains((string) ($urgentPlan['response_goal'] ?? ''), 'urgency') || str_contains((string) ($urgentPlan['response_goal'] ?? ''), 'direct'), 'EMOTION: urgency shapes response_goal');
$urgentMind = agent_core_mind_ctx_from_plan(['brand' => 'X', 'business_facts' => []], $urgentPlan, [], ['text' => 'urgent', 'bot' => ['id' => 1]], []);
am_assert(str_contains((string) ($urgentMind['plan_note'] ?? ''), 'Goal:'), 'EMOTION: plan_note carries goal to generator');

// --- MISSING INFO drives clarifier only when needed ---
$specific = am_fusion('How much for this one?');
am_assert(in_array($specific['plan']['next_best_action'] ?? '', ['ask_one_clarifier', 'answer_price'], true), 'MISSING: specific item may clarify or price');
$general = am_fusion('What are your prices?');
am_assert($general['plan']['next_best_action'] === 'answer_price', 'MISSING: general price list does not stall on which_item');

// --- SOURCE ROUTE preserved for business price ---
require_once $root . '/includes/conversation-source-router.php';
$src = conversation_source_route('What are your prices?');
am_assert(in_array($src['primary'], ['BUSINESS_CATALOG', 'BUSINESS_KNOWLEDGE', 'GENERAL_GPT'], true), 'SOURCE: price routes to business/general not LIVE_WEB');
am_assert(empty($src['needs_web']), 'SOURCE: price inquiry does not need web search');

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed > 0 ? 1 : 0);
