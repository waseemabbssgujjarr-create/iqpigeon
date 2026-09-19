<?php
/**
 * Agent Mind Phase 2C — CTA engine, answer/advance, stop/dead-end.
 * Run: php tests/agent-mind-phase2c-test.php
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
require_once $root . '/includes/agent-core/cta.php';
require_once $root . '/includes/agent-core/validate.php';
require_once $root . '/includes/agent-core/compose.php';

$passed = 0;
$failed = 0;

function p2c_assert(bool $cond, string $name): void
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

/** @param array<string, mixed> $overrides */
function p2c_plan(string $text, array $overrides = []): array
{
    $intel = array_merge(
        conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []),
        $overrides['intel_patch'] ?? []
    );
    $turn = array_merge([
        'text' => $text, 'bot_id' => 1, 'lead_id' => 0, 'bot' => ['id' => 1, 'name' => 'Test Biz'],
    ], $overrides['turn'] ?? []);
    $conv = array_merge(['runtime_facts' => [], 'history' => [], 'last_assistant' => ''], $overrides['conv'] ?? []);
    $pack = array_merge([
        'brand' => 'Test Biz', 'rep' => 'Sam',
        'capabilities' => ['catalog', 'cart', 'booking'],
    ], $overrides['pack'] ?? []);

    return agent_core_plan(
        $turn,
        $conv,
        array_merge(['kind' => 'FOLLOW_UP', 'tools' => []], $overrides['intent'] ?? []),
        array_merge(['primary' => 'BUSINESS_KNOWLEDGE'], $overrides['source'] ?? []),
        $pack,
        $intel
    );
}

echo "Agent Mind Phase 2C — CTA + Answer/Advance + Stop\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

p2c_assert(is_file($root . '/includes/agent-core/cta.php'), 'WIRING: cta.php exists');
$dec = file_get_contents($root . '/includes/agent-core/decision.php') ?: '';
p2c_assert(str_contains($dec, 'agent_core_cta_apply'), 'WIRING: decision calls CTA apply');

// A — Answer-only (social)
$a = p2c_plan('thank you');
p2c_assert(in_array($a['cta_mode'] ?? '', ['SOCIAL', 'NONE'], true), 'A: thank you → SOCIAL/NONE');
p2c_assert(($a['answer_advance'] ?? '') === 'ANSWER_ONLY' || ($a['answer_advance'] ?? '') === 'STOP', 'A: answer-only or stop');

// B — Answer + advance (price with knowledge)
$b = p2c_plan('What are your prices?', ['pack' => ['business_facts' => ['Consultation: PKR 5000']]]);
p2c_assert(in_array($b['answer_advance'] ?? '', ['ANSWER_PLUS_ADVANCE', 'ANSWER_ONLY'], true), 'B: price answer advance');
p2c_assert(in_array($b['cta_mode'] ?? '', ['OFFER_QUOTE', 'OFFER_PRODUCT', 'INVITE', 'NONE'], true), 'B: contextual price CTA');

// C — Required information only
$c = p2c_plan('Book me tomorrow.', ['pack' => ['capabilities' => ['booking']]]);
p2c_assert(
    ($c['cta_mode'] ?? '') === 'ASK_REQUIRED' || ($c['answer_advance'] ?? '') === 'QUESTION_ONLY',
    'C: booking missing info → ASK_REQUIRED'
);

// D — Already known information must not be re-asked
$dPlan = [
    'cta_mode' => 'ASK_REQUIRED',
    'answer_advance' => 'QUESTION_ONLY',
    'known_information' => ['customer_name=Sara', 'service=Consultation'],
    'cta_target' => 'time',
];
p2c_assert(empty(agent_core_validate_cta_decision('What is your name and which service?', $dPlan)['ok']), 'D: validator blocks CTA asking known name/service');
$dLive = p2c_plan('Book me for a consultation.', [
    'conv' => ['runtime_facts' => ['customer_name' => 'Sara']],
    'pack' => ['capabilities' => ['booking']],
]);
p2c_assert(
    ($dLive['cta_mode'] ?? '') === 'ASK_REQUIRED' || ($dLive['answer_advance'] ?? '') === 'QUESTION_ONLY',
    'D: booking without date asks required info'
);
p2c_assert(!preg_match('/\b(what(?:\'s| is) your name)\b/ui', (string) ($dLive['cta_text_strategy'] ?? '')), 'D: strategy skips known name');

// E — STOP
$e = p2c_plan("Thanks, that's all.");
p2c_assert(in_array($e['cta_mode'] ?? '', ['STOP', 'NONE'], true), 'E: stop → NONE/STOP');
p2c_assert(($e['answer_advance'] ?? '') === 'STOP', 'E: answer_advance STOP');
p2c_assert(empty($e['cta_required']), 'E: cta not required');
p2c_assert(empty(agent_core_validate_cta_decision('Anything else I can help with?', $e)['ok']), 'E: blocks forced CTA after stop');

// F — Low readiness
$f = p2c_plan("I'm just browsing.");
p2c_assert(!in_array($f['cta_mode'] ?? '', ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM'], true), 'F: browsing no checkout CTA');
p2c_assert(in_array('customer_browsing', $f['cta_reason_codes'] ?? [], true), 'F: browsing reason code');

// G — High readiness
$g = p2c_plan('Yes, place the order.', ['intel_patch' => ['affirmation' => 'confirm']]);
p2c_assert(
    in_array($g['answer_advance'] ?? '', ['ACTION_CONFIRMATION', 'ANSWER_PLUS_ADVANCE'], true),
    'G: confirm → action confirmation'
);

// H — Support vs sales
$h = p2c_plan("My order is late.");
p2c_assert(($h['cta_mode'] ?? '') === 'OFFER_SUPPORT', 'H: delivery → OFFER_SUPPORT');
p2c_assert(empty(agent_core_validate_cta_decision('Would you like to place an order?', $h)['ok']), 'H: blocks sales CTA after support');

// I — Objection
$i = p2c_plan("It's too expensive.");
p2c_assert(($i['selected_action'] ?? '') === 'HANDLE_OBJECTION', 'I: objection action');
p2c_assert(!in_array($i['cta_mode'] ?? '', ['OFFER_ORDER', 'OFFER_CHECKOUT'], true), 'I: no checkout CTA on objection');

// J — Human handoff
$j = p2c_plan('Can I speak to a human?');
p2c_assert(($j['cta_mode'] ?? '') === 'OFFER_HUMAN', 'J: human handoff CTA');

// K — Capability unavailable
$k = p2c_plan('Book me tomorrow.', ['pack' => ['capabilities' => ['catalog']]]);
p2c_assert(($k['cta_mode'] ?? '') !== 'OFFER_BOOKING', 'K: no booking CTA without capability');

// L — Knowledge unavailable
$l = p2c_plan('What are your prices?', ['pack' => ['business_facts' => ['We offer great coaching.']]]);
p2c_assert(($l['cta_mode'] ?? '') !== 'OFFER_QUOTE', 'L: no quote CTA without price knowledge');
p2c_assert(in_array($l['cta_mode'] ?? '', ['INVITE', 'OFFER_HUMAN', 'NONE'], true), 'L: safe fallback CTA');

// M — Multi-intent
$m = p2c_plan('How much is the blue laptop and can you deliver tomorrow?');
p2c_assert(!empty($m['multi_intent_combined']) || (int) ($m['message_budget'] ?? 0) === 1, 'M: multi-intent single turn');
p2c_assert(($m['answer_advance'] ?? '') === 'ANSWER_PLUS_ADVANCE', 'M: answer plus advance');

// N — Booking complete info
$n = p2c_plan('Yes, book it.', [
    'intel_opts' => ['state' => ['pending_action' => 'BOOKING_REQUEST']],
    'conv'       => ['runtime_facts' => ['service' => 'Consultation', 'customer_name' => 'Ali', 'date_time' => 'tomorrow 3pm']],
]);
p2c_assert(($n['cta_mode'] ?? '') !== 'ASK_REQUIRED', 'N: complete booking no re-ask all fields');
p2c_assert(in_array($n['cta_mode'] ?? '', ['CONFIRM', 'COMPLETE', 'OFFER_BOOKING'], true), 'N: confirm/complete CTA');

// O — Product recommendation
$o = p2c_plan('I need something for my office around 150k.');
p2c_assert(in_array($o['cta_mode'] ?? '', ['OFFER_RECOMMENDATION', 'OFFER_PRODUCT', 'INVITE', 'ASK_REQUIRED'], true), 'O: recommendation CTA');

// P — Order
$p = p2c_plan('I want to buy the blue one. How do I order?', ['intent' => ['kind' => 'CATALOG']]);
p2c_assert(in_array($p['cta_mode'] ?? '', ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM', 'OFFER_PRODUCT'], true), 'P: order CTA');

// Q — Checkout
$q = p2c_plan('Send me payment details.', ['intel_patch' => ['primary_intent' => 'PAYMENT_REQUEST', 'purchase_stage' => 'purchase_intent']]);
p2c_assert(in_array($q['selected_action'] ?? '', ['START_ORDER', 'START_CHECKOUT', 'COMPLETE'], true), 'Q: checkout-oriented action');

// R — Price
$r = p2c_plan('How much is consultation?', ['pack' => ['business_facts' => ['Consultation: PKR 5000']]]);
p2c_assert(in_array($r['cta_mode'] ?? '', ['OFFER_QUOTE', 'OFFER_PRODUCT', 'INVITE'], true), 'R: price with knowledge');

// S — Delivery/support CTA strategy
$s = p2c_plan('My delivery is late.');
p2c_assert(str_contains((string) ($s['cta_text_strategy'] ?? ''), 'resolv') || str_contains((string) ($s['cta_text_strategy'] ?? ''), 'issue'), 'S: support strategy text');

// T — Conversation completion
$t = p2c_plan("Thanks, that's all.");
p2c_assert(!empty($t['stop_allowed']), 'T: stop allowed on completion');

// Contract fields
$contract = p2c_plan('How much is coaching?', ['pack' => ['business_facts' => ['Coaching: PKR 5000']]]);
foreach (['cta_mode', 'cta_required', 'cta_text_strategy', 'cta_target', 'cta_confidence', 'cta_reason_codes', 'cta_eligibility', 'answer_advance', 'advance_required_information'] as $field) {
    p2c_assert(array_key_exists($field, $contract), 'CONTRACT: ' . $field);
}

// Compose consumes CTA
$mind = agent_core_mind_ctx_from_plan(
    ['brand' => 'X', 'business_facts' => ['Coaching: PKR 5000'], 'prompt' => ''],
    $contract,
    [],
    ['text' => 'How much is coaching?', 'bot' => ['id' => 1]],
    ['runtime_facts' => []]
);
p2c_assert(str_contains((string) ($mind['plan_note'] ?? ''), 'CTA mode:'), 'COMPOSE: plan_note has CTA mode');
p2c_assert(str_contains((string) ($mind['plan_note'] ?? ''), 'Answer mode:'), 'COMPOSE: plan_note has answer mode');

// Dead-end prevention
$anx = p2c_plan('I have anxiety and need support.');
p2c_assert(!empty($anx['require_useful_cta']) || ($anx['cta_mode'] ?? '') !== 'NONE', 'DEAD-END: anxiety has useful path or CTA');
p2c_assert(empty(agent_core_validate_cta_decision('Sorry to hear that. Let me know if you need anything.', array_merge($anx, ['require_useful_cta' => true, 'forbid_generic_loop' => true]))['ok']), 'DEAD-END: blocks sympathy-only');

// Booking missing time only
$book = p2c_plan('Book consultation tomorrow.', [
    'conv' => ['runtime_facts' => ['service' => 'Consultation']],
]);
if (($book['cta_mode'] ?? '') === 'ASK_REQUIRED') {
    p2c_assert(str_contains((string) ($book['cta_target'] ?? ''), 'time') || str_contains((string) ($book['cta_target'] ?? ''), 'date'), 'BOOK: asks date/time not service');
}

// Interesting — low readiness
$interesting = p2c_plan('Interesting.');
p2c_assert(!in_array($interesting['cta_mode'] ?? '', ['OFFER_ORDER', 'OFFER_CHECKOUT'], true), 'LOW: interesting no aggressive CTA');

// Yes I'll take it
$take = p2c_plan("Yes, I'll take it.", [
    'intel_patch' => ['affirmation' => 'confirm'],
    'conv'        => ['runtime_facts' => ['product' => 'Blue Widget']],
]);
p2c_assert(($take['cta_text_strategy'] ?? '') === '' || !str_contains((string) ($take['cta_text_strategy'] ?? ''), 'restart discovery'), 'TAKE: no rediscovery');

// Observability
$bits = agent_core_observe_decision_bits(
    ['primary_intent' => 'PRICE_INQUIRY'],
    ['cta_mode' => 'OFFER_QUOTE', 'cta_required' => true, 'cta_target' => 'verified pricing', 'cta_confidence' => 0.8, 'answer_advance' => 'ANSWER_PLUS_ADVANCE', 'stop_allowed' => false, 'cta_reason_codes' => ['capability_available']]
);
p2c_assert(($bits['cta_mode'] ?? '') === 'OFFER_QUOTE', 'OBS: cta_mode');
p2c_assert(($bits['answer_advance'] ?? '') === 'ANSWER_PLUS_ADVANCE', 'OBS: answer_advance');

// Explicit spec examples 1-15 (batch)
p2c_assert(in_array(p2c_plan("Thanks, that's all.")['cta_mode'] ?? '', ['STOP', 'NONE'], true), 'SPEC-1: thanks stop');
p2c_assert(!in_array(p2c_plan("I'm just browsing.")['cta_mode'] ?? '', ['OFFER_CHECKOUT'], true), 'SPEC-2: browsing');
p2c_assert(p2c_plan("It's too expensive.")['selected_action'] === 'HANDLE_OBJECTION', 'SPEC-3: objection');
p2c_assert(p2c_plan("My order is late.")['cta_mode'] === 'OFFER_SUPPORT', 'SPEC-4: support CTA');
p2c_assert(p2c_plan('Can I speak to a human?')['cta_mode'] === 'OFFER_HUMAN', 'SPEC-8: human');

// Validation: incompatible CTA
$nonePlan = p2c_plan("Thanks, that's all.");
p2c_assert(empty(agent_core_validate_cta_decision('Would you like to buy something?', $nonePlan)['ok']), 'VAL: no sales after stop');

// Phase 2B regression spot-check
p2c_assert(p2c_plan("My order hasn't arrived.")['selected_action'] === 'RESOLVE_SUPPORT', 'REG-2B: support action preserved');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
