<?php
/**
 * Agent Mind Phase 2A — customer need, goal, readiness, information model.
 * Run: php tests/agent-mind-phase2a-test.php
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

$passed = 0;
$failed = 0;

function p2a_assert(bool $cond, string $name): void
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
function p2a_plan(string $text, array $overrides = []): array
{
    $intel = conversation_intelligence_analyze($text, $overrides['intel_opts'] ?? []);
    $turn = array_merge([
        'text'    => $text,
        'bot_id'  => 1,
        'lead_id' => 0,
        'bot'     => ['id' => 1, 'name' => 'Test Biz'],
    ], $overrides['turn'] ?? []);
    $conv = array_merge(['runtime_facts' => [], 'history' => [], 'last_assistant' => ''], $overrides['conv'] ?? []);
    $intent = array_merge(['kind' => 'FOLLOW_UP', 'tools' => []], $overrides['intent'] ?? []);
    $source = array_merge(['primary' => 'BUSINESS_KNOWLEDGE'], $overrides['source'] ?? []);
    $pack = array_merge(['brand' => 'Test Biz', 'rep' => 'Sam'], $overrides['pack'] ?? []);

    return agent_core_plan($turn, $conv, $intent, $source, $pack, $intel);
}

echo "Agent Mind Phase 2A — Need / Goal / Readiness\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

// Wiring
p2a_assert(is_file($root . '/includes/agent-core/decision.php'), 'WIRING: decision.php exists');
$planSrc = file_get_contents($root . '/includes/agent-core/plan.php') ?: '';
p2a_assert(str_contains($planSrc, 'agent_core_decision_enrich_plan'), 'WIRING: plan calls decision enricher');

// 1 — Office laptop budget → need find_product
$p1 = p2a_plan('I need something for my office around 150k.');
p2a_assert(($p1['customer_need'] ?? '') === 'find_product', 'NEED-1: office budget → find_product');
p2a_assert(str_contains((string) ($p1['customer_need_detail'] ?? ''), '150k'), 'NEED-1: detail mentions budget');

// 2 — Delivery issue → resolve_delivery
$p2 = p2a_plan("My order hasn't arrived.");
p2a_assert(($p2['customer_need'] ?? '') === 'resolve_delivery', 'NEED-2: delivery → resolve_delivery');
p2a_assert(($p2['customer_goal'] ?? '') === 'resolve_issue', 'GOAL-2: resolve_issue');

// 3 — Anxiety support → personal_support
$p3 = p2a_plan("I have anxiety and don't know what to do.");
p2a_assert(($p3['customer_need'] ?? '') === 'personal_support', 'NEED-3: anxiety → personal_support');

// 4 — Price inquiry
$p4 = p2a_plan('What are your prices?');
p2a_assert(($p4['customer_need'] ?? '') === 'learn_pricing', 'NEED-4: prices → learn_pricing');
p2a_assert(in_array($p4['customer_goal'] ?? '', ['obtain_quote', 'purchase', 'learn'], true), 'GOAL-4: price goal');

// 5 — Just browsing → LOW readiness, learn goal
$p5 = p2a_plan("I'm just browsing.");
p2a_assert(($p5['readiness'] ?? '') === 'LOW', 'READY-5: browsing → LOW');
p2a_assert(($p5['customer_goal'] ?? '') === 'learn', 'GOAL-5: browsing → learn');
p2a_assert(empty($p5['advance_allowed']), 'READY-5: no advance on LOW');

// 6 — Consultation price → INTERESTED
$p6 = p2a_plan('How much is your consultation?');
p2a_assert(($p6['readiness'] ?? '') === 'INTERESTED', 'READY-6: consultation price → INTERESTED');
p2a_assert(($p6['customer_need'] ?? '') === 'learn_pricing', 'NEED-6: consultation price need');

// 7 — Book tomorrow → READY
$p7 = p2a_plan('Book me tomorrow.');
p2a_assert(($p7['readiness'] ?? '') === 'READY', 'READY-7: book tomorrow → READY');
p2a_assert(($p7['customer_goal'] ?? '') === 'book', 'GOAL-7: book goal');
p2a_assert(in_array('date', $p7['required_information'] ?? [], true) && in_array('time', $p7['required_information'] ?? [], true), 'INFO-7: booking requires date and time');

// 8 — Yes book it → COMPLETING
$p8 = p2a_plan('Yes, book it.', [
    'intel_opts' => ['state' => ['pending_action' => 'BOOKING_REQUEST']],
    'conv'       => ['runtime_facts' => ['service' => 'Consultation', 'customer_name' => 'Ali']],
]);
p2a_assert(($p8['readiness'] ?? '') === 'COMPLETING', 'READY-8: confirm → COMPLETING');
p2a_assert(($p8['customer_goal'] ?? '') === 'book', 'GOAL-8: confirm booking → book goal');

// 9 — Refund switch
$p9 = p2a_plan('Forget the order, I need a refund.');
p2a_assert(($p9['customer_need'] ?? '') === 'obtain_refund', 'NEED-9: refund need');
p2a_assert(in_array($p9['customer_goal'] ?? '', ['return', 'cancel', 'resolve_issue'], true), 'GOAL-9: return/cancel');

// 10 — Human handoff
$p10 = p2a_plan('Can I speak to a human?');
p2a_assert(($p10['customer_need'] ?? '') === 'speak_to_human', 'NEED-10: human need');
p2a_assert(($p10['customer_goal'] ?? '') === 'speak_to_human', 'GOAL-10: speak_to_human goal');

// 11 — Thanks → stop
$p11 = p2a_plan("Thanks, that's all.");
p2a_assert(($p11['customer_goal'] ?? '') === 'stop', 'GOAL-11: stop');
p2a_assert(($p11['readiness'] ?? '') === 'COMPLETING', 'READY-11: closing → COMPLETING');
p2a_assert(!empty($p11['stop_allowed']), 'STOP-11: stop_allowed');

// 12 — Information model: known name not missing
$p12 = p2a_plan('When can we meet?', [
    'intel_opts' => ['memory' => ['customer_name' => 'Sara', 'service' => 'Consultation']],
    'conv'       => ['runtime_facts' => ['customer_name' => 'Sara', 'service' => 'Consultation']],
]);
p2a_assert(in_array('customer_name=Sara', $p12['known_information'] ?? [], true), 'INFO-12: name known');
p2a_assert(!in_array('customer_name', $p12['missing_information'] ?? [], true), 'INFO-12: name not missing');

// 13 — Qualification optional when exploring
$p13 = p2a_plan('What services do you offer?', [
    'pack' => ['qualify_read' => 'budget,industry'],
]);
p2a_assert(($p13['readiness'] ?? '') === 'EXPLORING', 'READY-13: services → EXPLORING');
p2a_assert(in_array('qualification_fields', $p13['optional_information'] ?? [], true), 'INFO-13: qual optional not required');

// 14 — Message budget simple price = 1
$p14 = p2a_plan('What are your prices?');
p2a_assert((int) ($p14['message_budget'] ?? 0) === 1, 'BUDGET-14: price question budget 1');

// 15 — Structured intents preserved
$p15 = p2a_plan('I want the blue one, how much is it, and can you deliver tomorrow?');
p2a_assert(count($p15['structured_intents'] ?? []) >= 2, 'MULTI-15: structured intents >= 2');
p2a_assert((int) ($p15['message_budget'] ?? 0) === 1, 'BUDGET-15: multi-intent consolidated budget 1');

// 16 — Confidence fields
p2a_assert(isset($p4['intent_confidence']) && isset($p4['need_confidence']) && isset($p4['action_confidence']), 'CONF-16: confidence fields present');

// 17 — Observability bits extended
$bits = agent_core_observe_decision_bits(
    ['primary_intent' => 'PRICE_INQUIRY', 'secondary_intents' => [], 'emotion' => 'curious', 'purchase_stage' => 'interest'],
    [
        'customer_need'     => 'learn_pricing',
        'customer_goal'     => 'obtain_quote',
        'readiness'         => 'INTERESTED',
        'conversation_stage'=> 'QUALIFYING',
        'next_best_action'  => 'answer_price',
        'action_confidence' => 0.82,
        'message_budget'    => 1,
        'outcome'           => 'CATALOG',
        'answer_kind'       => 'BUSINESS',
        'cta_mode'          => 'price_advance',
    ]
);
p2a_assert(($bits['customer_need'] ?? '') === 'learn_pricing', 'OBS-17: customer_need in bits');
p2a_assert(($bits['customer_goal'] ?? '') === 'obtain_quote', 'OBS-17: customer_goal in bits');
p2a_assert(($bits['conv_stage'] ?? '') === 'QUALIFYING', 'OBS-17: conv_stage in bits');
p2a_assert(($bits['message_budget'] ?? 0) === 1, 'OBS-17: message_budget in bits');

// 18 — Sounds good → QUALIFIED not READY
$p18 = p2a_plan('That sounds good.');
p2a_assert(($p18['readiness'] ?? '') === 'QUALIFIED', 'READY-18: sounds good → QUALIFIED not READY');

// 19 — Too expensive (objection path — need still evidence-based)
$p19 = p2a_plan("It's too expensive.");
p2a_assert(($p19['customer_need'] ?? '') !== '', 'NEED-19: objection has need label');

// 20 — readiness_raw preserves purchase_stage
$intel20 = conversation_intelligence_analyze('How much is coaching?');
$p20 = p2a_plan('How much is coaching?');
p2a_assert(($p20['readiness_raw'] ?? '') === ($intel20['purchase_stage'] ?? ''), 'RAW-20: readiness_raw mirrors purchase_stage');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
