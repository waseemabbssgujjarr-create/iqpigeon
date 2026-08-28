<?php
/**
 * Agent Core Phase 1 — source + fixture tests (no Graph send).
 * Run: php tests/agent-core-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/includes/agent-core/agent-core.php';
require_once $root . '/includes/wa-recover-lite.php';

$GLOBALS['agent_core_no_network'] = true;

$passed = 0;
$failed = 0;

function ac_assert(bool $cond, string $name): void
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

$coreSrc = file_get_contents($root . '/includes/whatsapp-auto-reply-core.php') ?: '';
$engineSrc = file_get_contents($root . '/includes/conversation-turn-engine.php') ?: '';
$cronSrc = file_get_contents($root . '/api/cron.php') ?: '';
$webhookSrc = file_get_contents($root . '/api/whatsapp-webhook.php') ?: '';
$recoverSrc = file_get_contents($root . '/includes/wa-recover-lite.php') ?: '';
$toolsSrc = file_get_contents($root . '/includes/agent-core/tools.php') ?: '';
$knowledgeSrc = file_get_contents($root . '/includes/agent-core/knowledge.php') ?: '';
$runSrc = file_get_contents($root . '/includes/agent-core/agent-core.php') ?: '';
$bootSrc = file_get_contents($root . '/includes/agent-core/bootstrap.php') ?: '';

ac_assert(defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false, 'master flag default false');
ac_assert(agent_core_bot_ids() === [], 'allow-list empty by default');
ac_assert(!in_array(57, agent_core_bot_ids(), true), 'production bot 57 is not in the allow-list');
ac_assert(agent_core_enabled(['id' => 57]) === false, 'bot 57 is not enabled');
ac_assert(agent_core_enabled(['id' => 1]) === false, 'unlisted bot is not enabled');
ac_assert(agent_core_enabled(['id' => 0]) === false, 'id 0 is not enabled');

ac_assert(
    wa_recover_diagnostic_hold_turn_ids() === [720, 635, 321, 306, 302, 134],
    'diagnostic hold ids unchanged (720 still held)'
);

ac_assert(
    str_contains($coreSrc, "'path' => 'webhook_mind'")
    && str_contains($coreSrc, 'wa_webhook_mind_reply($bot, $leadId, $userMessage)')
    && str_contains($coreSrc, 'agent_core_enabled($bot)')
    && str_contains($coreSrc, "'path' => 'agent_core'"),
    'budget compose still has webhook_mind and an agent_core fork'
);

$budgetFn = strpos($coreSrc, 'if (!empty($GLOBALS[\'wa_webhook_budget\']))');
$budgetSlice = $budgetFn !== false ? substr($coreSrc, $budgetFn, 1200) : '';
ac_assert(
    str_contains($budgetSlice, 'wa_auto_reply_persist_inbound($leadId, $userMessage)')
    && strpos($budgetSlice, 'wa_auto_reply_persist_inbound') < strpos($budgetSlice, 'agent_core_enabled'),
    'persist_inbound still runs before the Core fork'
);

ac_assert(
    str_contains($engineSrc, "\$GLOBALS['wa_skip_openai'] = true")
    && str_contains($engineSrc, "\$GLOBALS['wa_webhook_budget'] = true"),
    'send_leads_now still sets webhook budget and skip_openai'
);

$sendNowFn = strpos($engineSrc, 'function turn_engine_send_leads_now');
$sendNowSrc = $sendNowFn !== false ? substr($engineSrc, $sendNowFn, 400) : '';
ac_assert(
    !str_contains($sendNowSrc, 'agent_core_enabled'),
    'send_leads_now was not modified for Agent Core'
);

ac_assert(
    (defined('TURN_TEXT_DEBOUNCE_MS') ? (int) TURN_TEXT_DEBOUNCE_MS : 7000) >= 7000,
    'text debounce remains at least 7000ms'
);

ac_assert(
    !str_contains($cronSrc, 'conversation-turn-engine.php')
    && str_contains($cronSrc, 'nohup curl')
    && str_contains($cronSrc, '-m 90'),
    'cron still does not include turn-engine and detaches worker 90s'
);

$ingestPos = strpos($webhookSrc, 'turn_engine_ingest($bot');
$ackPos = $ingestPos === false ? false : strpos($webhookSrc, 'wa_webhook_ack_meta();', $ingestPos);
ac_assert(
    $ingestPos !== false && $ackPos !== false && $ingestPos < $ackPos
    && !str_contains($webhookSrc, 'agent_core_run'),
    'webhook still ACKs after ingest and does not call Agent Core'
);

ac_assert(
    str_contains($coreSrc, '$agentCoreOn || empty($GLOBALS[\'wa_webhook_budget\'])')
    && str_contains($coreSrc, '$mediaBudget = !empty($GLOBALS[\'wa_webhook_budget\']) ? 3.0 : 8.0'),
    'media enrich runs for enabled bots under budget with a 3s deadline'
);

ac_assert(
    substr_count($knowledgeSrc, '$prompt = build_runtime_bot_prompt') === 1
    && !str_contains($knowledgeSrc, 'human_agent_live_protocol()')
    && !str_contains($knowledgeSrc, 'human_agent_live_doctrine_block()'),
    'knowledge pack calls build_runtime_bot_prompt once and does not re-append doctrine'
);

ac_assert(
    !str_contains($toolsSrc, 'cart_add_product')
    && !str_contains($toolsSrc, 'cart_try_place_order')
    && !str_contains($toolsSrc, 'cart_remove_line')
    && !str_contains($toolsSrc, 'booking_create_appointment')
    && !str_contains($toolsSrc, 'qualification_save_for_bot')
    && !str_contains($toolsSrc, 'conversation_runtime_remember_after_send')
    && !str_contains($runSrc, 'wa_recover_send_whatsapp')
    && !str_contains($toolsSrc, 'wa_recover_send_whatsapp'),
    'Phase 1 tools/run do not mutate cart/order/booking/qualify/memory or Graph-send'
);

ac_assert(
    str_contains($toolsSrc, 'forbidden_phase1')
    && str_contains($bootSrc, "define('AGENT_CORE_ENABLED', false)"),
    'forbidden tools rejected; bootstrap defaults ENABLED false'
);

$restaurant = [
    'id'           => 0,
    'industry_key' => 'restaurant',
    'name'         => 'The Sicilian',
    'company_name' => 'The Sicilian',
    'rep_name'     => 'Sara',
];
$baseCtx = [
    'channel' => 'whatsapp',
    'bot'     => $restaurant,
    'bot_id'  => 0,
    'lead_id' => 0,
    'turn_id' => 0,
    'media'   => [],
    'profile' => [
        'industry_key' => 'restaurant',
        'brand'        => 'The Sicilian',
        'rep'          => 'Sara',
        'capabilities' => ['catalog', 'cart', 'live_web'],
    ],
];
$emptyConv = [
    'history'        => [],
    'last_assistant' => '',
    'last_user'      => '',
    'referents'      => ['product' => ''],
    'missed_thought' => '',
    'runtime_facts'  => [],
];

$petrolCtx = $baseCtx;
$petrolCtx['text'] = 'What is the current petrol price in Pakistan?';
$petrolIntent = agent_core_intent($petrolCtx, $emptyConv);
$petrolSource = agent_core_source_route($petrolCtx, $emptyConv, $petrolIntent);
$petrolPlan = agent_core_plan($petrolCtx, $emptyConv, $petrolIntent, $petrolSource, ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
ac_assert(
    ($petrolIntent['kind'] ?? '') === 'LIVE_WORLD'
    && !empty($petrolIntent['needs_web'])
    && ($petrolIntent['tools'] ?? []) === ['live_web.search']
    && empty($petrolSource['needs_catalog'])
    && ($petrolPlan['tool_calls'][0]['name'] ?? '') === 'live_web.search',
    'petrol price is LIVE_WORLD / live_web.search, not catalog'
);
$petrolSteal = agent_core_validate(
    'Reply with a number from our menu',
    $petrolCtx,
    $petrolIntent,
    $petrolPlan
);
ac_assert(empty($petrolSteal['ok']), 'live-world draft that dumps a menu fails validation');

$blackConv = $emptyConv;
$blackConv['last_assistant'] = 'Here is *Black leather bag* — PKR 2,500.';
$blackConv['referents']['product'] = 'Black leather bag';
$blackCtx = $baseCtx;
$blackCtx['text'] = 'Do you have the black one?';
$blackIntent = agent_core_intent($blackCtx, $blackConv);
ac_assert(
    !empty($blackIntent['continue_thread'])
    && ($blackIntent['kind'] ?? '') === 'FOLLOW_UP'
    && ($blackIntent['referent'] ?? '') === 'Black leather bag',
    'the black one continues the thread with a referent'
);

$photoCtx = $baseCtx;
$photoCtx['text'] = 'I sent you a photo';
$photoCtx['media'] = [['type' => 'image', 'description' => '', 'text' => '[Customer image] (image received — analysis unavailable)']];
$photoIntent = agent_core_intent($photoCtx, $emptyConv);
ac_assert(
    ($photoIntent['kind'] ?? '') === 'MEDIA'
    && ($photoIntent['tools'] ?? []) === []
    && !empty($photoIntent['clarification_needed']),
    'photo turn is MEDIA before any catalog tool'
);

$missedConv = $emptyConv;
$missedConv['last_user'] = 'What is the current petrol price in Pakistan?';
$missedConv['missed_thought'] = 'What is the current petrol price in Pakistan?';
$missedCtx = $baseCtx;
$missedCtx['text'] = "Why didn't you answer my question?";
$missedIntent = agent_core_intent($missedCtx, $missedConv);
ac_assert(
    ($missedIntent['kind'] ?? '') === 'CORRECTION'
    && ($missedIntent['tools'] ?? []) === []
    && str_contains((string) ($missedIntent['missed_thought'] ?? ''), 'petrol'),
    'missed-question correction does not restart catalog'
);

$bookCtx = $baseCtx;
$bookCtx['text'] = 'Book me tomorrow at 4';
$bookNo = agent_core_intent($bookCtx, $emptyConv);
ac_assert(
    ($bookNo['kind'] ?? '') === 'BUSINESS_INQUIRY'
    && ($bookNo['tools'] ?? []) === [],
    'booking request without booking capability does not call booking.create'
);

$bookYesCtx = $bookCtx;
$bookYesCtx['profile']['capabilities'] = ['booking', 'live_web'];
$bookYes = agent_core_intent($bookYesCtx, $emptyConv);
ac_assert(
    ($bookYes['kind'] ?? '') === 'BOOKING'
    && ($bookYes['tools'] ?? []) === ['booking.offer'],
    'booking request with capability uses read-only booking.offer'
);

$forbidden = agent_core_tool('cart.add', ['query' => 'add 1'], $baseCtx);
ac_assert(($forbidden['error'] ?? '') === 'forbidden_phase1', 'cart.add is rejected in Phase 1');
$forbiddenOrder = agent_core_tool('order.place', [], $baseCtx);
ac_assert(($forbiddenOrder['error'] ?? '') === 'forbidden_phase1', 'order.place is rejected in Phase 1');
$forbiddenBook = agent_core_tool('booking.create', [], $baseCtx);
ac_assert(($forbiddenBook['error'] ?? '') === 'forbidden_phase1', 'booking.create is rejected in Phase 1');

$textOnlyMedia = str_contains($coreSrc, '!wa_auto_reply_turn_is_text_only($turnId)');
ac_assert($textOnlyMedia, 'text-only turns still skip Whisper');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
