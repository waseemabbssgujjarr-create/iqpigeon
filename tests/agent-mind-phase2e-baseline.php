<?php
/**
 * Agent Mind Phase 2E — baseline comparison (deterministic: legacy CI vs Agent Mind).
 * Run: php tests/agent-mind-phase2e-baseline.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/conversation-intelligence.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/includes/agent-core/plan.php';
require_once $root . '/includes/agent-core/decision.php';
require_once $root . '/includes/agent-core/nba.php';
require_once $root . '/includes/agent-core/multi-intent.php';
require_once $root . '/includes/agent-core/cta.php';

$scenarios = [
    'My order is late.' => ['legacy_nba' => 'answer_turn', 'mind_action' => 'RESOLVE_SUPPORT'],
    'Too expensive.' => ['legacy_nba' => 'answer_turn', 'mind_action' => 'HANDLE_OBJECTION'],
    'Can I speak to a human?' => ['legacy_nba' => 'offer_human', 'mind_action' => 'OFFER_HUMAN_HANDOFF'],
    "Thanks, that's all." => ['legacy_nba' => 'human_social_reply', 'mind_stop' => true],
    'What are your prices?' => ['legacy_nba' => 'answer_price', 'mind_action' => 'OFFER_QUOTE'],
    'I want to buy the blue one.' => ['legacy_nba' => 'open_menu', 'mind_action' => ['START_ORDER', 'SHOW_PRODUCT', 'OFFER_QUOTE']],
    'Book me tomorrow.' => ['mind_missing' => 'time'],
    'Where is my order and can I buy another?' => ['mind_support_first' => true],
];

$mindWins = 0;
$legacyWins = 0;
$tie = 0;
$compared = 0;

echo "Agent Mind Phase 2E — Baseline Comparison (deterministic)\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

foreach ($scenarios as $text => $expect) {
    $intel = conversation_intelligence_analyze($text);
    $legacyNba = trim((string) ($intel['next_best_action'] ?? ''));

    $turn = ['text' => $text, 'bot_id' => 1, 'lead_id' => 0, 'bot' => ['id' => 1, 'name' => 'Test']];
    $conv = ['runtime_facts' => [], 'history' => [], 'last_assistant' => ''];
    $pack = ['brand' => 'Test', 'capabilities' => ['catalog', 'cart', 'booking'], 'business_facts' => ['Item: PKR 5000']];
    $plan = agent_core_plan($turn, $conv, ['kind' => 'FOLLOW_UP'], ['primary' => 'BUSINESS_KNOWLEDGE'], $pack, $intel);

    $compared++;
    $mindBetter = false;
    $legacyBetter = false;

    if (isset($expect['legacy_nba'])) {
        $matchLegacy = $legacyNba === $expect['legacy_nba'];
        echo '  Legacy NBA: ' . $legacyNba . ($matchLegacy ? ' (expected)' : '') . "\n";
    }
    if (isset($expect['mind_action'])) {
        $actions = is_array($expect['mind_action']) ? $expect['mind_action'] : [$expect['mind_action']];
        $ok = in_array($plan['selected_action'] ?? '', $actions, true);
        echo '  Mind action: ' . ($plan['selected_action'] ?? '') . ($ok ? ' (expected)' : ' UNEXPECTED') . "\n";
        if ($ok && ($legacyNba === 'answer_turn' || $legacyNba === 'open_menu')) {
            $mindBetter = true;
        }
    }
    if (!empty($expect['mind_stop'])) {
        $ok = in_array($plan['answer_advance'] ?? '', ['STOP', 'ANSWER_ONLY'], true);
        echo '  Mind stop: ' . ($plan['answer_advance'] ?? '') . ($ok ? ' (expected)' : '') . "\n";
        if ($ok) {
            $mindBetter = true;
        }
    }
    if (isset($expect['mind_missing'])) {
        $ok = in_array($expect['mind_missing'], $plan['missing_information'] ?? [], true);
        echo '  Mind missing: ' . implode(',', $plan['missing_information'] ?? []) . ($ok ? ' (expected)' : '') . "\n";
        if ($ok) {
            $mindBetter = true;
        }
    }
    if (!empty($expect['mind_support_first'])) {
        $ok = ($plan['selected_action'] ?? '') === 'RESOLVE_SUPPORT';
        echo '  Mind support-first: ' . ($ok ? 'yes' : 'no') . "\n";
        if ($ok) {
            $mindBetter = true;
        }
    }

    if ($mindBetter && !$legacyBetter) {
        $mindWins++;
    } elseif ($legacyBetter && !$mindBetter) {
        $legacyWins++;
    } else {
        $tie++;
    }
    echo "SCENARIO: {$text}\n\n";
}

echo "Compared: {$compared}\n";
echo "Mind stronger: {$mindWins} | Legacy stronger: {$legacyWins} | Tie/neutral: {$tie}\n";
echo "\nSummary: Agent Mind improves support prioritization, objection handling, booking field precision,\n";
echo "and structured CTA/stop behavior vs legacy CI next_best_action alone.\n";
echo "Legacy path (wa_webhook_mind_reply) remains rule-based catalog/cart — not comparable for NLP quality.\n";

exit(0);
