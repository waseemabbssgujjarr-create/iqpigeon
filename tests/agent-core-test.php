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
$budgetSrc = file_get_contents($root . '/includes/agent-core/budget.php') ?: '';
$intentSrc = file_get_contents($root . '/includes/agent-core/intent.php') ?: '';
$pipelineSrc = file_get_contents($root . '/includes/agent-core/pipeline.php') ?: '';
$channelSrc = file_get_contents($root . '/includes/agent-core/channel.php') ?: '';
$botChatSrc = file_get_contents($root . '/api/bot-chat.php') ?: '';
$aiRespondSrc = file_get_contents($root . '/api/ai-respond.php') ?: '';
$chatWidgetSrc = file_get_contents($root . '/api/chat-widget.php') ?: '';

ac_assert(defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED === false, 'master flag default false');
ac_assert(agent_core_bot_ids() === [], 'deprecated allow-list helper is always empty');
ac_assert(agent_core_enabled(['id' => 57, 'is_active' => 1]) === false, 'ELIG-1 master OFF → Core disabled');
ac_assert(agent_core_enabled(['id' => 1, 'is_active' => 1]) === false, 'ELIG-2 master OFF → active bot still disabled');
ac_assert(agent_core_enabled(['id' => 0]) === false, 'ELIG-3 id 0 is not enabled');
ac_assert(
    agent_core_bot_eligible(['id' => 99, 'is_active' => 1], 'whatsapp') === true
    && agent_core_bot_eligible(['id' => 99, 'is_active' => 1], 'widget') === true
    && agent_core_bot_eligible(['id' => 99, 'is_active' => 1], 'bot-chat') === true,
    'ELIG-4 active bot is channel-eligible without bot-ID allow-list'
);
ac_assert(
    agent_core_bot_eligible(['id' => 99, 'is_active' => 0], 'whatsapp') === false,
    'ELIG-5 inactive bot is not channel-eligible'
);
ac_assert(
    agent_core_bot_eligible(['id' => 12, 'is_active' => 1, 'widget_enabled' => 0], 'widget') === false
    && agent_core_bot_eligible(['id' => 12, 'is_active' => 1, 'widget_enabled' => 1], 'widget') === true,
    'ELIG-6 widget channel requires widget_enabled when the flag is present'
);
ac_assert(
    agent_core_bot_eligible(['id' => 12, 'is_active' => 1, 'whatsapp_auto_reply' => 0], 'whatsapp') === false
    && agent_core_bot_eligible(['id' => 12, 'is_active' => 1, 'whatsapp_auto_reply' => 1], 'whatsapp') === true,
    'ELIG-7 whatsapp channel requires whatsapp_auto_reply when the flag is present'
);
ac_assert(
    str_contains($bootSrc, 'function agent_core_bot_eligible')
    && str_contains($bootSrc, 'AGENT_CORE_BOT_IDS is ignored')
    && !str_contains($bootSrc, 'AGENT_CORE_STAGING_BOT_IDS')
    && !str_contains($bootSrc, 'agent_core_staging_bot_ids'),
    'ELIG-8 bootstrap uses master flag + eligibility only; no staging allow-list'
);
ac_assert(
    agent_core_bot_ids() === []
    && !preg_match('/function agent_core_enabled[\s\S]*AGENT_CORE_BOT_IDS/', $bootSrc),
    'ELIG-9 deprecated AGENT_CORE_BOT_IDS does not gate runtime'
);

$activeWaBot = ['id' => 9001, 'is_active' => 1, 'whatsapp_auto_reply' => 1, 'widget_enabled' => 0, 'name' => 'Test WA'];
$activeWiBot = ['id' => 9001, 'is_active' => 1, 'whatsapp_auto_reply' => 0, 'widget_enabled' => 1, 'name' => 'Test WI'];

$GLOBALS['agent_core_enabled_override'] = true;
ac_assert(
    agent_core_enabled($activeWaBot, 'whatsapp') === true,
    'ELIG-10 master ON + active WhatsApp bot → Core enabled'
);
ac_assert(
    agent_core_enabled(['id' => 9001, 'is_active' => 0, 'whatsapp_auto_reply' => 1], 'whatsapp') === false,
    'ELIG-11 master ON + inactive bot → Core disabled'
);
ac_assert(
    agent_core_enabled($activeWiBot, 'widget') === true
    && agent_core_enabled(['id' => 9001, 'is_active' => 1, 'widget_enabled' => 0], 'widget') === false
    && agent_core_enabled(['id' => 9001, 'is_active' => 1], 'bot-chat') === true,
    'ELIG-12 master ON + widget/bot-chat channel rules apply'
);
unset($GLOBALS['agent_core_enabled_override']);

ac_assert(
    str_contains($channelSrc, 'agent_core_enabled($bot, $channel)')
    && str_contains($channelSrc, 'function agent_core_channel_off_reason')
    && agent_core_enabled(['id' => 8888, 'is_active' => 1, 'whatsapp_auto_reply' => 1], 'whatsapp') === false,
    'ELIG-13 channel gate blocks Core when master OFF'
);

$GLOBALS['agent_core_enabled_override'] = true;
$inactiveChannelTry = agent_core_channel_try(
    ['id' => 808, 'is_active' => 0, 'whatsapp_auto_reply' => 1],
    0,
    'hi',
    0,
    'whatsapp'
);
ac_assert(
    ($inactiveChannelTry['path'] ?? '') === 'core_off'
    && ($inactiveChannelTry['fallback_reason'] ?? '') === 'inactive',
    'ELIG-14 master ON + inactive bot → fallback_reason inactive'
);
unset($GLOBALS['agent_core_enabled_override']);

foreach ([
    'intelligence.php',
    'decision.php',
    'capabilities.php',
    'outcome.php',
    'nba.php',
    'cta.php',
    'multi-intent.php',
    'action-verification.php',
    'booking-state.php',
    'booking-tools.php',
    'handoff-tools.php',
] as $mindModule) {
    ac_assert(str_contains($runSrc, $mindModule), 'ELIG-15 Agent Mind module loaded: ' . $mindModule);
}

$rolloutBot53 = ['id' => 53, 'is_active' => 1, 'whatsapp_auto_reply' => 1, 'widget_enabled' => 0];
$rolloutBotOther = ['id' => 9002, 'is_active' => 1, 'whatsapp_auto_reply' => 1, 'widget_enabled' => 0];

ac_assert(
    agent_core_enabled($rolloutBot53, 'whatsapp') === false,
    'ROLLOUT-1 master OFF → bot 53 blocked regardless of rollout config'
);
$GLOBALS['agent_core_enabled_override'] = true;
unset($GLOBALS['agent_core_rollout_bot_ids_override']);
ac_assert(
    agent_core_enabled($rolloutBot53, 'whatsapp') === true
    && agent_core_enabled($rolloutBotOther, 'whatsapp') === true,
    'ROLLOUT-2 master ON + rollout empty → all eligible bots allowed (unchanged)'
);
$GLOBALS['agent_core_rollout_bot_ids_override'] = [53];
ac_assert(
    agent_core_enabled($rolloutBot53, 'whatsapp') === true,
    'ROLLOUT-3 master ON + rollout [53] → bot 53 allowed'
);
ac_assert(
    agent_core_enabled($rolloutBotOther, 'whatsapp') === false,
    'ROLLOUT-4 master ON + rollout [53] → other eligible bot blocked'
);
ac_assert(
    agent_core_enabled(['id' => 53, 'is_active' => 0, 'whatsapp_auto_reply' => 1], 'whatsapp') === false,
    'ROLLOUT-5 inactive bot 53 blocked even when on rollout list'
);
ac_assert(
    agent_core_enabled(['id' => 53, 'is_active' => 1, 'whatsapp_auto_reply' => 0], 'whatsapp') === false,
    'ROLLOUT-6 wrong channel (whatsapp off) blocked even when on rollout list'
);
unset($GLOBALS['agent_core_enabled_override'], $GLOBALS['agent_core_rollout_bot_ids_override']);
ac_assert(
    defined('AGENT_CORE_ROLLOUT_BOT_IDS') && trim((string) AGENT_CORE_ROLLOUT_BOT_IDS) === '',
    'ROLLOUT-7 rollout list default empty (off)'
);
ac_assert(
    str_contains($bootSrc, 'AGENT_CORE_ROLLOUT_BOT_IDS')
    && str_contains($bootSrc, 'function agent_core_rollout_allows_bot')
    && !preg_match('/function agent_core_enabled[\s\S]*AGENT_CORE_BOT_IDS/', $bootSrc),
    'ROLLOUT-8 uses AGENT_CORE_ROLLOUT_BOT_IDS not deprecated AGENT_CORE_BOT_IDS'
);

ac_assert(
    wa_recover_diagnostic_hold_turn_ids() === [720, 635, 321, 306, 302, 134],
    'diagnostic hold ids unchanged (720 still held)'
);

ac_assert(
    str_contains($coreSrc, "'path' => 'webhook_mind'")
    && str_contains($coreSrc, 'wa_webhook_mind_reply($bot, $leadId, $userMessage)')
    && str_contains($coreSrc, "agent_core_enabled(\$bot, 'whatsapp')")
    && str_contains($coreSrc, "'path' => 'agent_core'")
    && str_contains($coreSrc, 'agent_core_result_usable($core)'),
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
    && str_contains($coreSrc, '$mediaBudget = !empty($GLOBALS[\'wa_webhook_budget\']) ? 3.0 : 8.0')
    && str_contains($coreSrc, 'agent_core_media_enrich($turnId, $token)'),
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

$composeSrc = file_get_contents($root . '/includes/agent-core/compose.php') ?: '';
$validateSrc = file_get_contents($root . '/includes/agent-core/validate.php') ?: '';
$budgetComposeFn = strpos($coreSrc, 'function wa_auto_reply_compose');
$budgetComposeSrc = $budgetComposeFn !== false ? substr($coreSrc, $budgetComposeFn, 2800) : '';
ac_assert(
    str_contains($composeSrc, 'conversation_mind_generate')
    && !str_contains($composeSrc, 'ai_chat(')
    && str_contains($composeSrc, 'function agent_core_canonical_offer_draft')
    && str_contains($composeSrc, 'function agent_core_canonical_location_draft')
    && str_contains($composeSrc, 'knowledge_message_is_offer_question')
    && str_contains($composeSrc, 'conversation_is_location_question')
    && str_contains($composeSrc, 'knowledge_offer_list_reply')
    && str_contains($composeSrc, 'agent_core_canonical_offer_draft($bot, $userMessage)')
    && str_contains($composeSrc, 'agent_core_canonical_location_draft($bot, $userMessage)')
    && str_contains($validateSrc, "in_array(\$reason, ['marketing_dump', 'truncated'], true)")
    && !str_contains($budgetComposeSrc, 'knowledge_offer_list_reply')
    && !str_contains($budgetComposeSrc, 'knowledge_message_is_offer_question'),
    'compose uses conversation_mind_generate; offer-list is inside Core compose, not a webhook_mind bypass'
);

ac_assert(
    str_contains($toolsSrc, "'hours.read'")
    && str_contains($toolsSrc, "'orders.read'")
    && str_contains($toolsSrc, "'catalog.get_product'")
    && str_contains($toolsSrc, 'conversation_runtime_hours_now')
    && str_contains($toolsSrc, 'conversation_runtime_load_orders'),
    'hours and order history are real read-only tools'
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

$mixedCtx = $baseCtx;
$mixedCtx['text'] = 'Are you open tonight and who is the current PM of Pakistan?';
$mixedIntent = agent_core_intent($mixedCtx, $emptyConv);
$mixedSource = agent_core_source_route($mixedCtx, $emptyConv, $mixedIntent);
$mixedPlan = agent_core_plan($mixedCtx, $emptyConv, $mixedIntent, $mixedSource, ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
$mixedNames = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $mixedPlan['tool_calls'] ?? []);
ac_assert(
    !empty($mixedSource['needs_web'])
    && !empty($mixedSource['needs_hours'])
    && ($mixedSource['primary'] ?? '') === 'MIXED'
    && ($mixedPlan['answer_kind'] ?? '') === 'MIXED'
    && in_array('live_web.search', $mixedNames, true)
    && in_array('hours.read', $mixedNames, true),
    'hours + world question is MIXED and loads both hours.read and live_web.search'
);

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

ac_assert(
    ($petrolIntent['mind'] ?? '') !== ''
    && ($petrolIntent['override'] ?? '') === 'LIVE_WORLD'
    && is_array($petrolIntent['signals'] ?? null)
    && !empty($petrolIntent['signals']['live_world'])
    && strpos($intentSrc, 'agent_core_mind_intent_kind') !== false
    && strpos($intentSrc, 'agent_core_mind_intent_kind') < strpos($intentSrc, 'agent_core_intent_apply_overrides')
    && str_contains($intentSrc, 'function conversation_mind_intent') === false
    && str_contains($intentSrc, 'conversation_mind_intent('),
    'mind classifier always runs; specialized LIVE_WORLD is an override on one intent object'
);

$hiCtx = $baseCtx;
$hiCtx['text'] = 'hi';
$hiIntent = agent_core_intent($hiCtx, $emptyConv);
ac_assert(
    ($hiIntent['kind'] ?? '') === 'GREETING'
    && ($hiIntent['mind'] ?? '') === 'GREETING'
    && ($hiIntent['override'] ?? '') === 'GREETING',
    'greeting is one intent: mind GREETING plus GREETING overlay'
);

$idCtx = $baseCtx;
$idCtx['text'] = 'who are you';
$idIntent = agent_core_intent($idCtx, $emptyConv);
ac_assert(
    ($idIntent['kind'] ?? '') === 'IDENTITY'
    && ($idIntent['mind'] ?? '') !== ''
    && ($idIntent['override'] ?? '') === 'IDENTITY',
    'identity overlay feeds one intent object without skipping mind'
);

$catCtx = $baseCtx;
$catCtx['text'] = 'show me the menu';
$catIntent = agent_core_intent($catCtx, $emptyConv);
ac_assert(
    ($catIntent['kind'] ?? '') === 'CATALOG'
    && ($catIntent['tools'] ?? []) === ['catalog.search']
    && ($catIntent['mind'] ?? '') === 'BUSINESS_INQUIRY'
    && ($catIntent['override'] ?? '') === 'CATALOG',
    'catalog overlay sits on mind BUSINESS_INQUIRY, not a second brain'
);

$offCtx = $baseCtx;
$offCtx['text'] = '';
$offIntent = agent_core_intent($offCtx, $emptyConv);
ac_assert(
    ($offIntent['kind'] ?? '') === 'OFF_TOPIC'
    && ($offIntent['override'] ?? '') === 'OFF_TOPIC'
    && function_exists('agent_core_map_mind_intent')
    && function_exists('agent_core_looks_like_live_world'),
    'empty turn is OFF_TOPIC; existing intent helpers are kept'
);

ac_assert(
    !str_contains($pipelineSrc, "Got you. I'm listening")
    && str_contains($pipelineSrc, "return \$fail('validation_failed'")
    && str_contains($pipelineSrc, 'empty_compose'),
    'Core run does not send a generic listening line on failure'
);

function ac_compose_path(array $core): string
{
    return agent_core_result_usable($core) ? 'agent_core' : 'webhook_mind';
}

$GLOBALS['agent_core_test_throw'] = true;
$threw = agent_core_run($petrolCtx);
unset($GLOBALS['agent_core_test_throw']);
ac_assert(
    empty($threw['ok'])
    && trim((string) ($threw['reply'] ?? '')) === ''
    && ac_compose_path($threw) === 'webhook_mind'
    && ($threw['error'] ?? '') === 'test_forced_core_failure'
    && ($threw['fallback_reason'] ?? '') === 'exception',
    'Core exception → webhook_mind fallback'
);

$emptyCore = agent_core_run($petrolCtx);
ac_assert(
    !empty($emptyCore['ok'])
    && str_contains(mb_strtolower((string) ($emptyCore['reply'] ?? '')), "couldn't verify")
    && ac_compose_path($emptyCore) === 'agent_core',
    'LIVE_WORLD without evidence returns transparent unverified reply, not webhook_mind stale GPT'
);

$GLOBALS['agent_core_test_draft'] = 'Reply with a number from our menu';
$badVal = agent_core_run($petrolCtx);
unset($GLOBALS['agent_core_test_draft']);
ac_assert(
    empty($badVal['ok'])
    && trim((string) ($badVal['reply'] ?? '')) === ''
    && ($badVal['error'] ?? '') === 'validation_failed'
    && ($badVal['fallback_reason'] ?? '') === 'validation_failed'
    && ac_compose_path($badVal) === 'webhook_mind'
    && !str_contains((string) json_encode($badVal), "Got you"),
    'validation failure after retry → webhook_mind fallback'
);

ac_assert(
    str_contains($budgetSrc, 'wa_human_openai_reply')
    && str_contains($budgetSrc, 'conversation_mind_generate')
    && str_contains($bootSrc, "require_once __DIR__ . '/budget.php'")
    && !str_contains($composeSrc, 'wa_human_openai_reply(')
    && !str_contains($composeSrc, 'wa_webhook_friend_openai('),
    'Core documents skip_openai contract and does not call human-layer OpenAI'
);

$prevSkip = $GLOBALS['wa_skip_openai'] ?? null;
$prevNet = $GLOBALS['agent_core_no_network'] ?? null;
$GLOBALS['wa_skip_openai'] = true;
$GLOBALS['agent_core_no_network'] = false;
$skipOn = agent_core_skip_human_openai();
$mayGen = agent_core_may_call_mind_generate();
$GLOBALS['agent_core_no_network'] = true;
$blocked = agent_core_may_call_mind_generate();
if ($prevSkip === null) {
    unset($GLOBALS['wa_skip_openai']);
} else {
    $GLOBALS['wa_skip_openai'] = $prevSkip;
}
$GLOBALS['agent_core_no_network'] = $prevNet;
ac_assert(
    $skipOn === true
    && $mayGen === true
    && $blocked === false
    && str_contains($engineSrc, "\$GLOBALS['wa_skip_openai'] = true"),
    'skip_openai blocks human OpenAI but still allows mind generate on budgeted turns'
);

ac_assert(
    agent_core_stage_names() === [
        'INPUT', 'UNDERSTAND', 'CONTEXT', 'MEMORY', 'INTENT', 'SOURCES',
        'PLAN', 'TOOLS', 'GENERATE', 'VALIDATE', 'HUMANIZE', 'DELIVERY',
    ]
    && str_contains($pipelineSrc, 'function agent_core_pipeline')
    && str_contains($runSrc, 'agent_core_pipeline($ctx)')
    && str_contains($channelSrc, 'function agent_core_channel_try')
    && str_contains($botChatSrc, 'agent_core_channel_try($bot, 0, $message, 0, \'bot-chat\')')
    && str_contains($aiRespondSrc, 'agent_core_channel_try')
    && str_contains($aiRespondSrc, 'agent_core_enabled($bot, $coreChannel)')
    && str_contains($chatWidgetSrc, "'channel' => 'widget'")
    && !str_contains($chatWidgetSrc, 'human_agent_pause('),
    '12-stage pipeline exists; bot-chat + widget adapters around Core'
);

$bizAsk = $baseCtx;
$bizAsk['text'] = 'What do you offer?';
$bizIntent = agent_core_intent($bizAsk, $emptyConv);
ac_assert(
    ($bizIntent['kind'] ?? '') === 'BUSINESS_INQUIRY'
    && empty($bizIntent['needs_web']),
    '1 business question is BUSINESS_INQUIRY'
);

$jokeCtx = $baseCtx;
$jokeCtx['text'] = 'Tell me a joke';
$jokeIntent = agent_core_intent($jokeCtx, $emptyConv);
$jokePlan = agent_core_plan($jokeCtx, $emptyConv, $jokeIntent, agent_core_source_route($jokeCtx, $emptyConv, $jokeIntent), ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
ac_assert(
    ($jokeIntent['kind'] ?? '') === 'GENERAL'
    && ($jokePlan['tool_calls'] ?? []) === []
    && ($jokePlan['allow_casual'] ?? false) === true,
    '2 general question (joke) is GENERAL, not catalog'
);

$pastaCtx = $baseCtx;
$pastaCtx['text'] = 'Do you have pasta and what is the weather today?';
$pastaIntent = agent_core_intent($pastaCtx, $emptyConv);
$pastaSource = agent_core_source_route($pastaCtx, $emptyConv, $pastaIntent);
$pastaPlan = agent_core_plan($pastaCtx, $emptyConv, $pastaIntent, $pastaSource, ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
$pastaNames = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $pastaPlan['tool_calls'] ?? []);
ac_assert(
    ($pastaIntent['kind'] ?? '') === 'MIXED'
    && in_array('live_web.search', $pastaNames, true)
    && in_array('catalog.search', $pastaNames, true),
    '3 mixed pasta+weather loads catalog.search and live_web.search'
);

$followPlan = agent_core_plan($blackCtx, $blackConv, $blackIntent, agent_core_source_route($blackCtx, $blackConv, $blackIntent), ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
$followNames = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $followPlan['tool_calls'] ?? []);
ac_assert(
    ($blackIntent['kind'] ?? '') === 'FOLLOW_UP'
    && ($followPlan['referent'] ?? '') === 'Black leather bag'
    && in_array('memory.read', $followNames, true),
    '4 follow-up uses referent and memory.read'
);

$corrPlan = agent_core_plan($missedCtx, $missedConv, $missedIntent, ['primary' => 'CONVERSATION_MEMORY', 'needs_web' => false, 'needs_catalog' => false, 'needs_memory' => true, 'needs_hours' => false, 'needs_orders' => false, 'search_query' => ''], ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
ac_assert(
    ($missedIntent['kind'] ?? '') === 'CORRECTION'
    && str_contains((string) ($corrPlan['answer_first'] ?? ''), 'petrol'),
    '5 correction plans the missed thought first'
);

$usaCtx = $baseCtx;
$usaCtx['text'] = 'Who is the president of the USA?';
$usaIntent = agent_core_intent($usaCtx, $emptyConv);
ac_assert(
    ($usaIntent['kind'] ?? '') === 'LIVE_WORLD'
    && ($usaIntent['tools'] ?? []) === ['live_web.search'],
    '6 current-world question is LIVE_WORLD'
);

$coCtx = $baseCtx;
$coCtx['text'] = 'What is this company?';
$coIntent = agent_core_intent($coCtx, $emptyConv);
ac_assert(
    ($coIntent['kind'] ?? '') === 'BUSINESS_INQUIRY'
    && ($coIntent['override'] ?? '') === 'IDENTITY',
    '7 business identity/company question'
);

ac_assert(
    ($offIntent['kind'] ?? '') === 'OFF_TOPIC',
    '8 off-topic empty turn'
);

$ambCtx = $baseCtx;
$ambCtx['text'] = 'the other one';
$ambIntent = agent_core_intent($ambCtx, $emptyConv);
ac_assert(
    ($ambIntent['kind'] ?? '') === 'FOLLOW_UP'
    && !empty($ambIntent['clarification_needed'])
    && ($ambIntent['referent'] ?? '') === '',
    '9 ambiguous request needs clarification'
);

$imgU = agent_core_normalize_media_item([
    'type' => 'image',
    'description' => 'A plate of pasta with tomato sauce',
    'text' => '',
]);
$imgCtx = $baseCtx;
$imgCtx['text'] = '';
$imgCtx['media'] = [['type' => 'image', 'description' => 'A plate of pasta with tomato sauce', 'text' => '']];
$imgTurn = agent_core_stage_understand($imgCtx);
$imgIntent = agent_core_intent($imgTurn, $emptyConv);
ac_assert(
    ($imgU['type'] ?? '') === 'image'
    && ($imgU['image_description'] ?? '') === 'A plate of pasta with tomato sauce'
    && ($imgTurn['understanding'][0]['type'] ?? '') === 'image'
    && ($imgIntent['kind'] ?? '') === 'MEDIA',
    '10 image input is first-class MEDIA understanding'
);

$audioItem = agent_core_normalize_media_item([
    'type' => 'audio',
    'transcript' => 'What is the petrol price',
    'text' => '',
]);
$audioCtx = $baseCtx;
$audioCtx['text'] = '';
$audioCtx['media'] = [['type' => 'audio', 'transcript' => 'What is the petrol price', 'text' => '']];
$audioTurn = agent_core_stage_understand($audioCtx);
ac_assert(
    ($audioItem['type'] ?? '') === 'audio'
    && ($audioItem['text'] ?? '') === 'What is the petrol price'
    && ($audioTurn['text'] ?? '') === 'What is the petrol price',
    '11 audio/transcript becomes Core text'
);

$memConv = $blackConv;
$memConv['runtime_facts'] = ['customer_name' => 'Ahmed', 'interest' => 'black bag'];
$memPlan = agent_core_plan($blackCtx, $memConv, $blackIntent, agent_core_source_route($blackCtx, $memConv, $blackIntent), ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
$memCtx = agent_core_mind_ctx_from_plan(['prompt' => '', 'business_facts' => []], $memPlan, [['name' => 'memory.read', 'data' => $memConv['runtime_facts']]], $blackCtx, $memConv);
ac_assert(
    ($memCtx['customer_memory']['customer_name'] ?? '') === 'Ahmed'
    && in_array('memory.read', array_map(static fn ($c) => (string) ($c['name'] ?? ''), $memPlan['tool_calls'] ?? []), true),
    '12/13 context referent and memory reach planner and generate ctx'
);

$idPlan = agent_core_plan($idCtx, $emptyConv, $idIntent, agent_core_source_route($idCtx, $emptyConv, $idIntent), ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
$getProd = agent_core_tool('catalog.get_product', ['product_id' => 9, 'query' => '#9'], $baseCtx);
ac_assert(
    ($petrolPlan['tool_calls'][0]['name'] ?? '') === 'live_web.search'
    && ($getProd['name'] ?? '') === 'catalog.get_product'
    && ($getProd['error'] ?? '') !== 'forbidden_phase1',
    '14 tool selection: live_web for petrol; catalog.get_product is allowed read-only'
);

/**
 * @param array<string, mixed> $data
 * @return list<array<string, mixed>>
 */
function ac_live_row(array $data): array
{
    return [['ok' => true, 'name' => 'live_web.search', 'data' => $data]];
}

/**
 * @return array<string, mixed>
 */
function ac_live_payload(string $text, ?string $callStatus = 'completed', string $callType = 'web_search_call'): array
{
    $output = [];
    if ($callStatus !== null) {
        $output[] = [
            'type'   => $callType,
            'id'     => 'ws_test',
            'status' => $callStatus,
        ];
    }
    $output[] = [
        'type'    => 'message',
        'role'    => 'assistant',
        'content' => [['type' => 'output_text', 'text' => $text]],
    ];

    return ['output_text' => $text, 'output' => $output];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ac_live_tool_data(array $payload): array
{
    $text = live_world_extract_output_text($payload);
    $quality = live_world_assess_evidence($text, live_world_inspect_web_search($payload));

    return live_world_search_to_tool_data(
        live_world_search_pack_result(!empty($quality['ok']), $text, true, $quality),
        true
    );
}

$lahoreCtx = $baseCtx;
$lahoreCtx['text'] = 'What is the weather in Lahore today?';
$lahoreIntent = agent_core_intent($lahoreCtx, $emptyConv);
$lahoreSource = agent_core_source_route($lahoreCtx, $emptyConv, $lahoreIntent);
$lahorePlan = agent_core_plan($lahoreCtx, $emptyConv, $lahoreIntent, $lahoreSource, ['prompt' => '', 'rep' => 'Sara', 'brand' => 'The Sicilian']);
$lahoreQuery = '';
foreach (is_array($lahorePlan['tool_calls'] ?? null) ? $lahorePlan['tool_calls'] : [] as $call) {
    if (($call['name'] ?? '') === 'live_web.search') {
        $lahoreQuery = (string) ($call['args']['query'] ?? '');
    }
}
ac_assert(
    ($lahoreIntent['kind'] ?? '') === 'LIVE_WORLD'
    && in_array('live_web.search', $lahoreIntent['tools'] ?? [], true)
    && str_contains(mb_strtolower($lahoreQuery), 'lahore')
    && str_contains(mb_strtolower($lahoreQuery), 'weather'),
    'Lahore weather query is preserved on the live_web.search tool call'
);

ac_assert(ac_compose_path($threw) === 'webhook_mind', '15 Core failure → webhook_mind fallback');
ac_assert(
    !empty($emptyCore['ok'])
    && str_contains(mb_strtolower((string) ($emptyCore['reply'] ?? '')), "couldn't verify"),
    '16 LIVE_WORLD without evidence stays on agent_core with transparent fallback'
);
ac_assert(ac_compose_path($badVal) === 'webhook_mind', '17 validation failure → fallback');

ac_assert(
    AGENT_CORE_ENABLED === false
    && str_contains($coreSrc, 'wa_webhook_mind_reply($bot, $leadId, $userMessage)'),
    '18 Core OFF → legacy webhook_mind path'
);

ac_assert(
    agent_core_enabled(['id' => 57, 'is_active' => 1]) === false
    && agent_core_enabled(['id' => 99, 'is_active' => 1]) === false
    && agent_core_bot_ids() === []
    && agent_core_bot_eligible(['id' => 57, 'is_active' => 1], 'whatsapp') === true
    && agent_core_bot_eligible(['id' => 99, 'is_active' => 1], 'widget') === true,
    '19 master off disables all; active bots remain channel-eligible'
);

ac_assert(
    !str_contains($runSrc, 'wa_recover_send_whatsapp')
    && !str_contains($pipelineSrc, 'wa_recover_send_whatsapp')
    && !str_contains($toolsSrc, 'wa_recover_send_whatsapp')
    && !str_contains($channelSrc, 'wa_recover_send_whatsapp'),
    '20 no Graph call from Core'
);

ac_assert(
    ($forbidden['error'] ?? '') === 'forbidden_phase1'
    && agent_core_tool('memory.write', [], $baseCtx)['error'] === 'forbidden_phase1'
    && agent_core_tool('qualification.update', [], $baseCtx)['error'] === 'forbidden_phase1'
    && agent_core_tool('cart.remove', [], $baseCtx)['error'] === 'forbidden_phase1',
    '21 no mutating tools'
);

ac_assert(
    !str_contains($toolsSrc, 'cart_add_product')
    && !str_contains($toolsSrc, 'qualification_save_for_bot')
    && !str_contains($toolsSrc, 'booking_create_appointment')
    && !str_contains($pipelineSrc, 'db_execute')
    && !str_contains($runSrc, 'INSERT INTO'),
    '22 no accidental DB mutation from Core tools/run'
);

function ac_sink_names(): array
{
    $names = [];
    foreach ($GLOBALS['agent_core_event_sink'] ?? [] as $row) {
        $names[] = (string) ($row['event'] ?? '');
    }

    return $names;
}

function ac_sink_blob(): string
{
    return (string) json_encode($GLOBALS['agent_core_event_sink'] ?? [], JSON_UNESCAPED_UNICODE);
}

function ac_sink_last(string $event): array
{
    $last = [];
    foreach ($GLOBALS['agent_core_event_sink'] ?? [] as $row) {
        if ((string) ($row['event'] ?? '') === $event) {
            $last = is_array($row['detail'] ?? null) ? $row['detail'] : [];
        }
    }

    return $last;
}

function ac_live_observe_begin(): void
{
    $GLOBALS['agent_core_event_sink'] = [];
    $GLOBALS['agent_core_observe'] = [
        't0'       => microtime(true),
        'turn_id'  => 0,
        'lead_id'  => 112,
        'bot_id'   => 53,
        'channel'  => 'whatsapp',
        'fallback' => false,
    ];
}

$observeSrc = file_get_contents($root . '/includes/agent-core/observe.php') ?: '';
$engineNow = file_get_contents($root . '/includes/conversation-turn-engine.php') ?: '';

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_draft'] = 'Hello.';
$okRun = agent_core_run($hiCtx);
unset($GLOBALS['agent_core_test_draft']);
$okNames = ac_sink_names();
ac_assert(
    !empty($okRun['ok'])
    && trim((string) ($okRun['reply'] ?? '')) !== ''
    && ac_compose_path($okRun) === 'agent_core'
    && in_array('CORE_START', $okNames, true)
    && in_array('CORE_CONTEXT', $okNames, true)
    && in_array('CORE_INTENT', $okNames, true)
    && in_array('CORE_SOURCE', $okNames, true)
    && in_array('CORE_PLAN', $okNames, true)
    && in_array('CORE_TOOLS', $okNames, true)
    && in_array('CORE_GENERATE', $okNames, true)
    && in_array('CORE_VALIDATE', $okNames, true)
    && in_array('CORE_COMPLETE', $okNames, true)
    && !in_array('CORE_FALLBACK', $okNames, true)
    && (($okRun['fallback_reason'] ?? null) === null),
    '1 Core success emits stage events and stays on agent_core'
);

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_throw'] = true;
$exRun = agent_core_run($petrolCtx);
unset($GLOBALS['agent_core_test_throw']);
ac_assert(
    ($exRun['fallback_reason'] ?? '') === 'exception'
    && in_array('CORE_FALLBACK', ac_sink_names(), true)
    && ac_compose_path($exRun) === 'webhook_mind',
    '2 Core exception records fallback_reason=exception'
);

$GLOBALS['agent_core_event_sink'] = [];
$emptyRun = agent_core_run($petrolCtx);
ac_assert(
    !empty($emptyRun['ok'])
    && str_contains(mb_strtolower((string) ($emptyRun['reply'] ?? '')), "couldn't verify")
    && in_array('CORE_GENERATE', ac_sink_names(), true)
    && !in_array('CORE_FALLBACK', ac_sink_names(), true),
    '3 LIVE_WORLD without evidence succeeds with transparent fallback (no empty_generate)'
);

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_draft'] = 'Reply with a number from our menu';
$valRun = agent_core_run($petrolCtx);
unset($GLOBALS['agent_core_test_draft']);
ac_assert(
    ($valRun['fallback_reason'] ?? '') === 'validation_failed'
    && in_array('CORE_VALIDATE', ac_sink_names(), true)
    && in_array('CORE_FALLBACK', ac_sink_names(), true),
    '4 validation failure records fallback_reason=validation_failed'
);

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_fail_reason'] = 'tool_failure';
$toolFail = agent_core_run($petrolCtx);
unset($GLOBALS['agent_core_test_fail_reason']);
ac_assert(
    ($toolFail['fallback_reason'] ?? '') === 'tool_failure'
    && ac_compose_path($toolFail) === 'webhook_mind'
    && in_array('CORE_TOOLS', ac_sink_names(), true)
    && in_array('CORE_FALLBACK', ac_sink_names(), true),
    '5 tool failure records fallback_reason=tool_failure'
);

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_draft'] = 'I could not verify the latest information just now.';
$lwRun = agent_core_run($petrolCtx);
unset($GLOBALS['agent_core_test_draft']);
$lwNames = ac_sink_names();
ac_assert(
    in_array('LIVE_WORLD_DETECTED', $lwNames, true)
    && in_array('LIVE_WORLD_TOOL_SELECTED', $lwNames, true)
    && in_array('LIVE_WORLD_TOOL_START', $lwNames, true)
    && (in_array('LIVE_WORLD_TOOL_COMPLETE', $lwNames, true) || in_array('LIVE_WORLD_TOOL_FAILED', $lwNames, true))
    && in_array('LIVE_WORLD_EVIDENCE_PRESENT', $lwNames, true)
    && in_array('LIVE_WORLD_GENERATE', $lwNames, true)
    && in_array('CORE_COMPLETE', $lwNames, true)
    && (($lwRun['intent']['override'] ?? '') === 'LIVE_WORLD' || ($lwRun['intent']['kind'] ?? '') === 'LIVE_WORLD'),
    '6 LIVE_WORLD selection and tool events are recorded'
);

$offTry = agent_core_channel_try(['id' => 53, 'is_active' => 1], 0, 'hi', 0, 'whatsapp');
ac_assert(
    ($offTry['path'] ?? '') === 'core_off'
    && ($offTry['fallback_reason'] ?? '') === 'disabled'
    && ac_compose_path($offTry) === 'webhook_mind',
    '7 Core fallback when master flag disabled stays webhook_mind'
);

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_observe_throw'] = true;
$GLOBALS['agent_core_test_draft'] = 'Hello.';
$safeRun = agent_core_run($hiCtx);
unset($GLOBALS['agent_core_observe_throw'], $GLOBALS['agent_core_test_draft']);
ac_assert(
    !empty($safeRun['ok'])
    && trim((string) ($safeRun['reply'] ?? '')) !== ''
    && ac_compose_path($safeRun) === 'agent_core',
    '8 instrumentation cannot break a Core reply'
);

$GLOBALS['agent_core_event_sink'] = [];
$secretCtx = $hiCtx;
$secretCtx['text'] = 'CUSTOMER_SECRET_PHRASE_9921 please call me';
$secretCtx['access_token'] = 'EAABBBSECRETTOKEN999';
$GLOBALS['agent_core_test_draft'] = 'Hello.';
$secretRun = agent_core_run($secretCtx);
unset($GLOBALS['agent_core_test_draft']);
$secretBlob = ac_sink_blob();
ac_assert(
    !empty($secretRun['ok'])
    && !str_contains($secretBlob, 'CUSTOMER_SECRET_PHRASE_9921')
    && !str_contains($secretBlob, 'EAABBBSECRETTOKEN999')
    && !str_contains($secretBlob, 'sk-')
    && str_contains($observeSrc, 'AGENT_CORE_OBSERVE_DROP_KEYS'),
    '9 diagnostic events do not store secrets or the customer message'
);

ac_assert(
    AGENT_CORE_ENABLED === false
    && agent_core_bot_ids() === []
    && agent_core_enabled(['id' => 53, 'is_active' => 1]) === false
    && str_contains($bootSrc, "define('AGENT_CORE_ENABLED', false)"),
    '10 existing default-OFF behavior remains unchanged'
);

ac_assert(
    str_contains($engineNow, 'LOCK_DRAIN')
    && str_contains($engineNow, 'LOCK_BUSY')
    && str_contains($engineNow, 'for ($drain = 0; $drain < 4; $drain++)')
    && str_contains($engineNow, '$drain === 0 && turn_engine_lead_just_got_reply')
    && str_contains($engineNow, "preg_match('/^[\\?？]{1,4}$/u'")
    && str_contains($coreSrc, "'path' => 'RESPONSE_SENT'") === false
    && str_contains($coreSrc, 'PROCESSING_TO_RESPONSE')
    && str_contains($coreSrc, 'wa_recover_log_event($turnId, \'RESPONSE_SENT\''),
    'lock drain + ? chase + processing_to_response; RESPONSE_SENT event name unchanged'
);

$azSrc = file_get_contents($root . '/api/wa-az-audit.php') ?: '';
$eventsPos = strpos($azSrc, "if (\$part === 'events')");
$composePos = strpos($azSrc, "if (\$part === 'compose')");
$eventsSlice = ($eventsPos !== false && $composePos !== false && $composePos > $eventsPos)
    ? substr($azSrc, $eventsPos, $composePos - $eventsPos)
    : '';
$authPos = strpos($azSrc, 'hash_equals($cron, $key)');
ac_assert(
    $authPos !== false
    && $eventsPos !== false
    && $authPos < $eventsPos
    && str_contains($azSrc, '&part=events')
    && str_contains($azSrc, 'function az_sanitize_event_detail')
    && str_contains($azSrc, 'function az_watched_core_events')
    && str_contains($azSrc, 'agent_core_observe_sanitize')
    && str_contains($eventsSlice, 'FROM conversation_turn_events')
    && str_contains($eventsSlice, 'WHERE turn_id = ?')
    && str_contains($eventsSlice, 'ORDER BY created_at ASC')
    && str_contains($eventsSlice, 'turn_id_required')
    && str_contains($eventsSlice, 'turn_not_found')
    && str_contains($eventsSlice, 'no_events')
    && str_contains($eventsSlice, "'status' => 'error'")
    && str_contains($azSrc, 'LIVE_WORLD_DETECTED')
    && str_contains($azSrc, 'LIVE_WORLD_TOOL_FAILED')
    && str_contains($azSrc, 'LIVE_ANSWER_START')
    && str_contains($azSrc, 'LIVE_ANSWER_COMPLETE')
    && str_contains($azSrc, 'LIVE_ANSWER_FALLBACK')
    && str_contains($azSrc, 'CORE_FALLBACK')
    && str_contains($azSrc, "'RESPONSE_SENT'")
    && str_contains($azSrc, "'PROCESSING_TO_RESPONSE'")
    && str_contains($eventsSlice, "'select_only' => true")
    && str_contains($eventsSlice, "'sends'       => false")
    && !str_contains($eventsSlice, 'INSERT ')
    && !str_contains($eventsSlice, 'UPDATE ')
    && !str_contains($eventsSlice, 'DELETE ')
    && !str_contains($eventsSlice, 'wa_recover_run')
    && !str_contains($eventsSlice, 'wa_recover_send_whatsapp')
    && !str_contains($eventsSlice, 'graph.facebook')
    && !str_contains($eventsSlice, 'raw_text')
    && !str_contains($eventsSlice, 'wa_auto_reply_compose'),
    'part=events is CRON-gated SELECT-only Core event dump with no send/recovery'
);

$secretDetail = agent_core_observe_sanitize([
    'ok'           => true,
    'message'      => 'What is the weather in Lahore today?',
    'access_token' => 'EAABBBSECRETTOKEN999',
    'prompt'       => 'You are a sales agent',
    'fallback_reason' => 'empty_generate',
]);
ac_assert(
    ($secretDetail['ok'] ?? false) === true
    && ($secretDetail['fallback_reason'] ?? '') === 'empty_generate'
    && !array_key_exists('message', $secretDetail)
    && !array_key_exists('access_token', $secretDetail)
    && !array_key_exists('prompt', $secretDetail),
    'event detail sanitizer drops customer text, tokens, and prompts'
);

require_once $root . '/includes/bot-knowledge.php';
$coachKb = "Waqar Tayyub is a business and performance coach.\n"
    . "Rate \$80/hour — 1:1 coaching sessions\n"
    . "Greet Customers when they text you with: This is Salman from Waqar Tayyub & Co. How can I help you today?\n"
    . "List of Services Offered:\n"
    . "- Neural Performance Coaching\n"
    . "- Corporate Training\n"
    . "- Workplace Productivity\n"
    . "- Immersive Leadership Development\n"
    . "- AI Growth and Adoption for High Performing Teams";
$coachBot = [
    'id'            => 53,
    'rep_name'      => 'Salman',
    'name'          => 'Waqar Tayyub & Co.',
    'company_name'  => 'Waqar Tayyub & Co.',
    'industry_key'  => 'freelancer',
    'bot_knowledge' => $coachKb,
];
$coachListed = knowledge_offer_list_reply($coachBot);
$coachCtx = [
    'channel' => 'whatsapp',
    'bot'     => $coachBot,
    'bot_id'  => 53,
    'lead_id' => 0,
    'turn_id' => 0,
    'media'   => [],
    'text'    => 'What services do you offer?',
    'profile' => [
        'industry_key' => 'freelancer',
        'brand'        => 'Waqar Tayyub & Co.',
        'rep'          => 'Salman',
        'capabilities' => ['live_web'],
    ],
];

unset($GLOBALS['agent_core_test_draft']);
$GLOBALS['agent_core_event_sink'] = [];
$svcRun = agent_core_run($coachCtx);
$svcBlob = ac_sink_blob();
ac_assert(
    $coachListed !== ''
    && mb_strlen($coachListed) > 180
    && knowledge_message_is_offer_question('What services do you offer?')
    && trim((string) ($svcRun['reply'] ?? '')) === $coachListed
    && !empty($svcRun['ok'])
    && ac_compose_path($svcRun) === 'agent_core'
    && (($svcRun['fallback_reason'] ?? null) === null)
    && !in_array('CORE_FALLBACK', ac_sink_names(), true)
    && in_array('CORE_COMPLETE', ac_sink_names(), true)
    && !str_contains((string) ($svcRun['reply'] ?? ''), "Got you. I'm listening")
    && !str_contains($svcBlob, 'What services do you offer?'),
    'A offer question uses canonical knowledge list on agent_core, not webhook_mind'
);

$offerWordCtx = $coachCtx;
$offerWordCtx['text'] = 'What do you offer?';
$GLOBALS['agent_core_event_sink'] = [];
$offerWordRun = agent_core_run($offerWordCtx);
ac_assert(
    knowledge_message_is_offer_question('What do you offer?')
    && !empty($offerWordRun['ok'])
    && trim((string) ($offerWordRun['reply'] ?? '')) === $coachListed
    && ac_compose_path($offerWordRun) === 'agent_core',
    'B "What do you offer?" is Core-usable with the canonical list'
);

$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_draft'] = 'Hi there! How can I assist you today?';
$helloCoach = $coachCtx;
$helloCoach['text'] = 'Hello';
$helloRun = agent_core_run($helloCoach);
unset($GLOBALS['agent_core_test_draft']);
ac_assert(
    !knowledge_message_is_offer_question('Hello')
    && agent_core_canonical_offer_draft($coachBot, 'Hello') === ''
    && !empty($helloRun['ok'])
    && ac_compose_path($helloRun) === 'agent_core'
    && trim((string) ($helloRun['reply'] ?? '')) === 'Hi there! How can I assist you today?',
    'C greeting stays Core-usable and does not take the offer-list branch'
);

$jokeAsk = $coachCtx;
$jokeAsk['text'] = 'Tell me a joke';
$GLOBALS['agent_core_event_sink'] = [];
$GLOBALS['agent_core_test_draft'] = 'A short clean joke.';
$jokeRun = agent_core_run($jokeAsk);
unset($GLOBALS['agent_core_test_draft']);
ac_assert(
    !knowledge_message_is_offer_question('Tell me a joke')
    && agent_core_canonical_offer_draft($coachBot, 'Tell me a joke') === ''
    && !empty($jokeRun['ok'])
    && trim((string) ($jokeRun['reply'] ?? '')) === 'A short clean joke.'
    && ac_compose_path($jokeRun) === 'agent_core'
    && str_contains($composeSrc, 'conversation_mind_generate'),
    'D non-catalog questions still use normal Core generate, not the offer-list draft'
);

$noListBot = $restaurant;
$noListCtx = $baseCtx;
$noListCtx['text'] = 'What services do you offer?';
$noListCtx['bot'] = $noListBot;
unset($GLOBALS['agent_core_test_draft']);
$GLOBALS['agent_core_event_sink'] = [];
$noListRun = agent_core_run($noListCtx);
ac_assert(
    knowledge_message_is_offer_question('What services do you offer?')
    && knowledge_offer_list_reply($noListBot) === ''
    && agent_core_canonical_offer_draft($noListBot, 'What services do you offer?') === ''
    && empty($noListRun['ok'])
    && trim((string) ($noListRun['reply'] ?? '')) === ''
    && ac_compose_path($noListRun) === 'webhook_mind'
    && in_array('CORE_FALLBACK', ac_sink_names(), true),
    'E missing canonical offer list leaves Core unusable; webhook_mind remains fallback'
);

$marketingDump = 'We can develop concepts and transform your ideas into brand stories. '
    . 'Our services include dramatic product reveals, lifestyle storytelling, high-impact social, '
    . 'content strategy, cinematic advertisements, and marketing packages for every brand concept.';
ac_assert(mb_strlen($marketingDump) > 180, 'marketing dump fixture is long enough');
$dumpCheck = agent_core_validate($marketingDump, $petrolCtx, $petrolIntent, $petrolPlan);
$dumpOnOffer = agent_core_validate($marketingDump, $coachCtx, agent_core_intent($coachCtx, $emptyConv), [
    'source'      => 'BUSINESS_CATALOG',
    'answer_kind' => 'BUSINESS',
    'outcome'     => 'BUSINESS_INQUIRY',
]);
ac_assert(
    empty($dumpCheck['ok'])
    && ($dumpCheck['reason'] ?? '') === 'marketing_dump'
    && empty($dumpOnOffer['ok'])
    && ($dumpOnOffer['reason'] ?? '') === 'marketing_dump',
    'F arbitrary long marketing-like replies still fail marketing_dump validation'
);

$listedCheck = agent_core_validate(
    $coachListed,
    $coachCtx,
    agent_core_intent($coachCtx, $emptyConv),
    ['source' => 'BUSINESS_CATALOG', 'answer_kind' => 'BUSINESS', 'outcome' => 'BUSINESS_INQUIRY']
);
$listedOnPetrol = agent_core_validate($coachListed, $petrolCtx, $petrolIntent, $petrolPlan);
ac_assert(
    conversation_is_marketing_dump_reply($coachListed) === true
    && !empty($listedCheck['ok'])
    && empty($listedOnPetrol['ok'])
    && ($listedOnPetrol['reason'] ?? '') === 'marketing_dump',
    'G canonical offer list is not rejected as marketing_dump on an offer question; still rejected off-path'
);

ac_assert(
    !str_contains($pipelineSrc, "Got you. I'm listening")
    && !str_contains((string) ($svcRun['reply'] ?? ''), "Got you. I'm listening")
    && !str_contains((string) ($noListRun['reply'] ?? ''), "Got you. I'm listening"),
    'H Core offer path does not send a generic listening fallback'
);

$offerSink = (string) json_encode($GLOBALS['agent_core_event_sink'] ?? [], JSON_UNESCAPED_UNICODE);
ac_assert(
    !str_contains($svcBlob, 'Neural Performance Coaching')
    && !str_contains($svcBlob, 'CUSTOMER_SECRET')
    && !str_contains($offerSink, $coachListed)
    && str_contains($observeSrc, 'AGENT_CORE_OBSERVE_DROP_KEYS'),
    'I offer-list instrumentation still drops customer text and reply bodies'
);

require_once $root . '/includes/live-world-info.php';
$weatherEvidence = 'Lahore is currently 33 C and partly cloudy, with light wind this afternoon.';
$refusalEvidence = "I'm sorry, but I can't provide real-time weather updates. For the latest weather in Lahore, please check a reliable local source.";
$genericEvidence = 'Thanks for your question. How can I help you today?';

$liveWeatherData = ac_live_tool_data(ac_live_payload($weatherEvidence, 'completed'));
$liveWeatherRow = ac_live_row($liveWeatherData);
$liveCtx = agent_core_mind_ctx_from_plan(
    ['prompt' => '', 'business_facts' => []],
    $petrolPlan,
    $liveWeatherRow,
    $petrolCtx,
    $emptyConv
);
$mindSrc = file_get_contents($root . '/includes/conversation-mind.php') ?: '';
$lwSrc = file_get_contents($root . '/includes/live-world-info.php') ?: '';
ac_assert(
    !empty($liveWeatherData['evidence_usable'])
    && agent_core_live_evidence_present($liveWeatherRow) === true
    && agent_core_tool_row_failed($liveWeatherRow[0]) === false
    && (string) ($liveWeatherData['evidence'] ?? '') === $weatherEvidence
    && (string) ($liveCtx['live_world']['evidence'] ?? '') === $weatherEvidence
    && live_world_search_is_usable($liveWeatherData) === true
    && str_contains($mindSrc, 'conversation_mind_live_answer')
    && str_contains($mindSrc, 'live_world_search_is_usable')
    && str_contains($lwSrc, 'web_search_call'),
    'A real-looking weather evidence + completed web_search_call is usable and reaches generate ctx unchanged'
);

$liveEmptyData = ac_live_tool_data(ac_live_payload('', 'completed'));
require_once $root . '/includes/openai.php';
require_once $root . '/includes/conversation-mind.php';
$emptyLiveReply = conversation_mind_generate($restaurant, 0, 'What is the weather in Lahore today?', [
    'history'      => [],
    'live_world'   => $liveEmptyData,
    'source_route' => ['needs_web' => true, 'primary' => 'LIVE_WEB'],
]);
ac_assert(
    empty($liveEmptyData['evidence_usable'])
    && agent_core_live_evidence_present(ac_live_row($liveEmptyData)) === false
    && $emptyLiveReply === conversation_mind_unverified_live_reply($restaurant)
    && str_contains($emptyLiveReply, 'couldn\'t verify'),
    'B empty evidence is unusable and uses the truthful unverified live reply'
);

$liveRefusalData = ac_live_tool_data(ac_live_payload($refusalEvidence, 'completed'));
$refusalLiveReply = conversation_mind_generate($restaurant, 0, 'What is the weather in Lahore today?', [
    'history'      => [],
    'live_world'   => $liveRefusalData,
    'source_route' => ['needs_web' => true, 'primary' => 'LIVE_WEB'],
]);
$refusalBits = agent_core_live_observe_bits($liveRefusalData);
$refusalSan = agent_core_observe_sanitize($refusalBits + ['prompt' => 'secret-prompt', 'evidence' => $refusalEvidence]);
ac_assert(
    !empty($liveRefusalData['looks_like_refusal'])
    && empty($liveRefusalData['evidence_usable'])
    && (string) ($liveRefusalData['evidence'] ?? '') === ''
    && agent_core_live_evidence_present(ac_live_row($liveRefusalData)) === false
    && agent_core_tool_row_failed(ac_live_row($liveRefusalData)[0]) === true
    && $refusalLiveReply === conversation_mind_unverified_live_reply($restaurant)
    && ($refusalBits['looks_like_refusal'] ?? false) === true
    && ($refusalBits['evidence_usable'] ?? true) === false
    && !array_key_exists('prompt', $refusalSan)
    && ($refusalSan['evidence_chars'] ?? 0) > 0
    && !in_array($refusalEvidence, $refusalBits, true),
    'C refusal-shaped evidence is unusable, not present, and does not leak into events or generate'
);

$liveGenericNoSearch = ac_live_tool_data(ac_live_payload($genericEvidence, null));
$liveGenericWithSearch = live_world_assess_evidence($genericEvidence, live_world_inspect_web_search(ac_live_payload($genericEvidence, 'completed')));
ac_assert(
    empty($liveGenericNoSearch['evidence_usable'])
    && agent_core_live_evidence_present(ac_live_row($liveGenericNoSearch)) === false
    && trim($genericEvidence) !== ''
    && empty($liveGenericWithSearch['looks_like_refusal']),
    'D nonempty generic text is not verified evidence without a web search call'
);

$liveNoCallWeather = ac_live_tool_data(ac_live_payload($weatherEvidence, null));
$liveFailedCall = ac_live_tool_data(ac_live_payload($weatherEvidence, 'failed'));
$liveIncompleteCall = ac_live_tool_data(ac_live_payload($weatherEvidence, 'incomplete'));
$liveCancelledCall = ac_live_tool_data(ac_live_payload($weatherEvidence, 'cancelled'));
$liveInProgressCall = ac_live_tool_data(ac_live_payload($weatherEvidence, 'in_progress'));
$liveSearchingCall = ac_live_tool_data(ac_live_payload($weatherEvidence, 'searching'));
$liveOmittedCall = ac_live_tool_data(ac_live_payload($weatherEvidence, ''));
$liveUnknownCall = ac_live_tool_data(ac_live_payload($weatherEvidence, 'succeeded'));
$livePreviewOk = ac_live_tool_data(ac_live_payload($weatherEvidence, 'completed', 'web_search_preview_call'));
$petrolEvidence = 'Petrol in Pakistan is currently around 272 PKR per litre.';
$livePetrolData = ac_live_tool_data(ac_live_payload($petrolEvidence, 'completed'));
ac_assert(
    empty($liveNoCallWeather['has_web_search_call'])
    && empty($liveNoCallWeather['evidence_usable'])
    && empty($liveFailedCall['evidence_usable'])
    && (string) ($liveFailedCall['web_search_call_status'] ?? '') === 'failed'
    && empty($liveIncompleteCall['evidence_usable'])
    && empty($liveCancelledCall['evidence_usable'])
    && empty($liveInProgressCall['evidence_usable'])
    && empty($liveSearchingCall['evidence_usable'])
    && empty($liveOmittedCall['evidence_usable'])
    && empty($liveUnknownCall['evidence_usable'])
    && !empty($livePreviewOk['evidence_usable'])
    && (string) ($livePreviewOk['evidence'] ?? '') === $weatherEvidence
    && !empty($livePetrolData['evidence_usable'])
    && (string) ($livePetrolData['evidence'] ?? '') === $petrolEvidence,
    'E web search not executed/unknown is unusable; completed preview_call and non-weather live facts still count'
);

require_once $root . '/includes/conversation-intelligence.php';
$lahoreWeatherEvidence = 'Lahore weather is currently 33 C and partly cloudy this afternoon.';
$weatherFlags = live_world_evidence_content_flags($lahoreWeatherEvidence);
$weatherFlagSan = agent_core_observe_sanitize($weatherFlags + [
    'evidence'              => $lahoreWeatherEvidence,
    'prompt'                => 'secret-prompt',
    'openai_call_ok'        => true,
    'openai_call_empty'     => false,
    'has_temperature_token' => true,
]);
ac_assert(
    ($weatherFlags['has_lahore'] ?? false) === true
    && ($weatherFlags['has_weather'] ?? false) === true
    && ($weatherFlags['has_temperature_token'] ?? false) === true
    && ($weatherFlags['looks_like_refusal'] ?? true) === false
    && $lahoreWeatherEvidence === 'Lahore weather is currently 33 C and partly cloudy this afternoon.'
    && (string) ($liveWeatherData['evidence'] ?? '') === $weatherEvidence
    && !array_key_exists('evidence', $weatherFlagSan)
    && !array_key_exists('prompt', $weatherFlagSan)
    && ($weatherFlagSan['openai_call_ok'] ?? false) === true
    && ($weatherFlagSan['has_temperature_token'] ?? false) === true
    && !in_array($lahoreWeatherEvidence, $weatherFlags, true)
    && !in_array($lahoreWeatherEvidence, $weatherFlagSan, true),
    'LA-A usable Lahore/weather/temp evidence flags true; evidence stays full internally; metadata has no body'
);

$petrolFlags = live_world_evidence_content_flags($petrolEvidence);
$petrolFlagSan = agent_core_observe_sanitize($petrolFlags + ['evidence' => $petrolEvidence]);
ac_assert(
    ($petrolFlags['has_lahore'] ?? true) === false
    && ($petrolFlags['has_weather'] ?? true) === false
    && ($petrolFlags['has_temperature_token'] ?? true) === false
    && ($petrolFlags['looks_like_refusal'] ?? true) === false
    && !empty($livePetrolData['evidence_usable'])
    && (string) ($livePetrolData['evidence'] ?? '') === $petrolEvidence
    && !array_key_exists('evidence', $petrolFlagSan)
    && !in_array($petrolEvidence, $petrolFlagSan, true),
    'LA-B generic live facts: location/weather/temp flags false; no evidence text leakage'
);

$refusalFlags = live_world_evidence_content_flags($refusalEvidence);
ac_assert(
    ($refusalFlags['looks_like_refusal'] ?? false) === true
    && ($refusalFlags['has_lahore'] ?? false) === true
    && ($refusalFlags['has_weather'] ?? false) === true
    && ($refusalFlags['has_temperature_token'] ?? true) === false
    && !empty($liveRefusalData['looks_like_refusal'])
    && empty($liveRefusalData['evidence_usable'])
    && (string) ($liveRefusalData['evidence'] ?? '') === ''
    && $refusalLiveReply === conversation_mind_unverified_live_reply($restaurant)
    && !in_array($refusalEvidence, $refusalFlags, true),
    'LA-C refusal-shaped evidence: quality gate still unusable; metadata records looks_like_refusal'
);

ac_live_observe_begin();
$GLOBALS['conversation_mind_test_live_openai'] = ['success' => true, 'content' => 'Lahore is about 33 C and partly cloudy.'];
$shortLive = conversation_mind_live_answer($restaurant, 'weather ask', ['history' => []], [
    'needed'   => true,
    'ok'       => true,
    'evidence' => $weatherEvidence,
]);
$shortStart = ac_sink_last('LIVE_ANSWER_START');
$shortComplete = ac_sink_last('LIVE_ANSWER_COMPLETE');
$shortBlob = ac_sink_blob();
ac_assert(
    ($shortStart['evidence_truncated'] ?? true) === false
    && ($shortComplete['evidence_truncated'] ?? true) === false
    && ($shortStart['evidence_chars'] ?? 0) === mb_strlen($weatherEvidence)
    && mb_strlen($weatherEvidence) < 1800
    && $shortLive !== ''
    && !str_contains($shortBlob, $weatherEvidence),
    'LA-D evidence shorter than 1800: evidence_truncated=false'
);

$longEvidence = trim(str_repeat('Lahore weather is 33 C today. ', 80));
ac_assert(mb_strlen($longEvidence) > 1800, 'long evidence fixture exceeds 1800');
ac_live_observe_begin();
$GLOBALS['conversation_mind_test_live_openai'] = ['success' => true, 'content' => 'It is hot in Lahore today.'];
$longLive = conversation_mind_live_answer($restaurant, 'weather ask', ['history' => []], [
    'needed'   => true,
    'ok'       => true,
    'evidence' => $longEvidence,
]);
$longStart = ac_sink_last('LIVE_ANSWER_START');
$longComplete = ac_sink_last('LIVE_ANSWER_COMPLETE');
$longBlob = ac_sink_blob();
ac_assert(
    ($longStart['evidence_truncated'] ?? false) === true
    && ($longComplete['evidence_truncated'] ?? false) === true
    && ($longStart['evidence_chars'] ?? 0) === mb_strlen($longEvidence)
    && $longLive !== ''
    && !str_contains($longBlob, mb_substr($longEvidence, 0, 80))
    && !str_contains($longBlob, $longEvidence),
    'LA-E evidence longer than 1800: evidence_truncated=true'
);

ac_live_observe_begin();
$openaiReply = 'Lahore is about 33 C and partly cloudy this afternoon.';
$GLOBALS['conversation_mind_test_live_openai'] = ['success' => true, 'content' => $openaiReply];
$usedLive = conversation_mind_live_answer($restaurant, 'weather ask', ['history' => []], [
    'needed'   => true,
    'ok'       => true,
    'evidence' => $weatherEvidence,
]);
$usedComplete = ac_sink_last('LIVE_ANSWER_COMPLETE');
$usedBlob = ac_sink_blob();
ac_assert(
    $usedLive === $openaiReply
    && in_array('LIVE_ANSWER_START', ac_sink_names(), true)
    && in_array('LIVE_ANSWER_COMPLETE', ac_sink_names(), true)
    && !in_array('LIVE_ANSWER_FALLBACK', ac_sink_names(), true)
    && ($usedComplete['live_answer_used'] ?? false) === true
    && ($usedComplete['live_answer_source'] ?? '') === 'openai'
    && ($usedComplete['live_answer_chars'] ?? 0) === mb_strlen($openaiReply)
    && ($usedComplete['openai_call_ok'] ?? false) === true
    && ($usedComplete['openai_call_empty'] ?? true) === false
    && ($usedComplete['has_lahore'] ?? false) === true
    && ($usedComplete['bot_id'] ?? 0) === 53
    && ($usedComplete['lead_id'] ?? 0) === 112
    && ($usedComplete['channel'] ?? '') === 'whatsapp'
    && !str_contains($usedBlob, $weatherEvidence)
    && !str_contains($usedBlob, $openaiReply)
    && !str_contains($usedBlob, 'weather ask'),
    'LA-F nonempty live_answer: source=openai used=true chars recorded; no body leak'
);

$fallbackEvidence = str_repeat('Lahore weather is 33 C today. ', 20);
$fallbackExpected = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $fallbackEvidence)), 0, 280);
ac_live_observe_begin();
$GLOBALS['conversation_mind_test_live_openai'] = ['success' => false, 'content' => ''];
$fbLive = conversation_mind_live_answer($restaurant, 'weather ask', ['history' => []], [
    'needed'   => true,
    'ok'       => true,
    'evidence' => $fallbackEvidence,
]);
$fbEvent = ac_sink_last('LIVE_ANSWER_FALLBACK');
$fbBlob = ac_sink_blob();
unset($GLOBALS['conversation_mind_test_live_openai']);
ac_assert(
    $fbLive === $fallbackExpected
    && mb_strlen($fbLive) === 280
    && in_array('LIVE_ANSWER_START', ac_sink_names(), true)
    && in_array('LIVE_ANSWER_FALLBACK', ac_sink_names(), true)
    && ($fbEvent['live_answer_used'] ?? true) === false
    && ($fbEvent['live_answer_source'] ?? '') === 'evidence_fallback'
    && ($fbEvent['live_answer_chars'] ?? 0) === 280
    && ($fbEvent['openai_call_ok'] ?? true) === false
    && ($fbEvent['openai_call_empty'] ?? false) === true
    && !str_contains($fbBlob, $fallbackEvidence)
    && !str_contains($fbBlob, mb_substr($fallbackEvidence, 0, 60)),
    'LA-G empty live_answer: source=evidence_fallback used=false chars=fallback length'
);

ac_live_observe_begin();
$noneLive = conversation_mind_live_answer($restaurant, 'weather ask', ['history' => []], [
    'needed'   => true,
    'ok'       => false,
    'evidence' => '',
]);
$noneEvent = ac_sink_last('LIVE_ANSWER_COMPLETE');
$noneBlob = ac_sink_blob();
ac_assert(
    $noneLive === ''
    && ($noneEvent['live_answer_source'] ?? '') === 'none'
    && ($noneEvent['live_answer_used'] ?? true) === false
    && ($noneEvent['live_answer_chars'] ?? -1) === 0
    && ($noneEvent['evidence_chars'] ?? -1) === 0
    && ($noneEvent['has_lahore'] ?? true) === false
    && !str_contains($noneBlob, 'weather ask')
    && !str_contains($noneBlob, $weatherEvidence)
    && $emptyLiveReply === conversation_mind_unverified_live_reply($restaurant),
    'LA-H no evidence: no leak; generate still uses unverified live reply'
);

// --- Automatic active-bot Core routing (global master OR staging allow-list) ---
$activeWa = ['id' => 101, 'is_active' => 1, 'whatsapp_auto_reply' => 1, 'widget_enabled' => 0, 'name' => 'Biz A'];
$activeWi = ['id' => 202, 'is_active' => 1, 'whatsapp_auto_reply' => 0, 'widget_enabled' => 1, 'name' => 'Biz B'];
$inactive = ['id' => 303, 'is_active' => 0, 'whatsapp_auto_reply' => 1, 'widget_enabled' => 1, 'name' => 'Biz C'];
ac_assert(
    agent_core_bot_eligible($activeWa, 'whatsapp') === true
    && agent_core_bot_eligible($activeWa, 'widget') === false
    && agent_core_bot_eligible($activeWi, 'widget') === true
    && agent_core_bot_eligible($activeWi, 'whatsapp') === false
    && agent_core_bot_eligible($inactive, 'whatsapp') === false
    && agent_core_bot_eligible($inactive, 'widget') === false,
    'ROUTE-A active bot + matching channel eligible; wrong channel / inactive blocked'
);

ac_assert(
    str_contains($aiRespondSrc, "agent_core_channel_try")
    && str_contains($aiRespondSrc, '$coreChannel')
    && str_contains($chatWidgetSrc, "'channel' => 'widget'")
    && str_contains($coreSrc, "agent_core_channel_try(\$bot, \$leadId, \$userMessage, \$turnId, 'whatsapp')")
    && str_contains($botChatSrc, "agent_core_channel_try(\$bot, 0, \$message, 0, 'bot-chat')")
    && str_contains($runSrc, 'function agent_core_reply')
    && str_contains($pipelineSrc, 'function agent_core_pipeline'),
    'ROUTE-B Widget + WhatsApp + bot-chat enter the same Agent Core / Conversation Engine'
);

ac_assert(
    str_contains($knowledgeSrc, 'build_runtime_bot_prompt($bot')
    && str_contains($knowledgeSrc, 'conversation_mind_business_facts($bot)')
    && !str_contains($knowledgeSrc, 'The Sicilian'),
    'ROUTE-C knowledge pack is per-bot (no hard-coded business)'
);

ac_assert(
    str_contains($bootSrc, 'Legacy AGENT_CORE_BOT_IDS is ignored')
    && !str_contains($bootSrc, 'AGENT_CORE_STAGING_BOT_IDS')
    && !preg_match('/function agent_core_enabled[\s\S]*AGENT_CORE_BOT_IDS/', $bootSrc),
    'ROUTE-C2 AGENT_CORE_BOT_IDS deprecated; master flag + eligibility only'
);

$offerAsk = $baseCtx;
$offerAsk['text'] = 'What do you offer?';
$offerAsk['bot'] = ['id' => 404, 'is_active' => 1, 'name' => 'Custom Co', 'company_name' => 'Custom Co'];
$offerIntent = agent_core_intent($offerAsk, $emptyConv);
$weatherAsk = $baseCtx;
$weatherAsk['text'] = 'What is the weather in Lahore today?';
$weatherAsk['bot'] = ['id' => 505, 'is_active' => 1, 'name' => 'Any Biz', 'company_name' => 'Any Biz'];
$weatherIntent = agent_core_intent($weatherAsk, $emptyConv);
ac_assert(
    ($offerIntent['kind'] ?? '') !== 'LIVE_WORLD'
    && empty($offerIntent['needs_web'])
    && (($weatherIntent['kind'] ?? '') === 'LIVE_WORLD' || ($weatherIntent['override'] ?? '') === 'LIVE_WORLD')
    && !empty($weatherIntent['needs_web']),
    'ROUTE-D business questions skip LIVE_WORLD; current-world questions can invoke it for any bot'
);

ac_assert(
    str_contains($knowledgeSrc, 'function agent_core_knowledge_pack(array $bot)')
    && str_contains($knowledgeSrc, 'build_runtime_bot_prompt($bot, $brand)')
    && str_contains($knowledgeSrc, 'conversation_mind_business_facts($bot)')
    && str_contains($pipelineSrc, 'agent_core_knowledge_pack($bot')
    && !preg_match('/AGENT_CORE_BOT_IDS|in_array\(\s*\$botId/', $knowledgeSrc)
    && !preg_match('/AGENT_CORE_BOT_IDS|bot_ids\(\)/', $intentSrc),
    'ROUTE-E knowledge + LIVE_WORLD intent are per-bot and not legacy allow-list gated'
);

$inactiveTry = agent_core_channel_try(
    ['id' => 808, 'is_active' => 0, 'whatsapp_auto_reply' => 1],
    0,
    'hi',
    0,
    'whatsapp'
);
ac_assert(
    ($inactiveTry['path'] ?? '') === 'core_off'
    && in_array(($inactiveTry['fallback_reason'] ?? ''), ['disabled', 'inactive'], true),
    'ROUTE-F inactive bots do not enter Core (master-off → disabled; else inactive)'
);

$convPipelineSrc = file_get_contents($root . '/includes/conversation-pipeline.php') ?: '';
ac_assert(
    str_contains($convPipelineSrc, 'function pipeline_widget_defer_business_faq_to_core')
    && str_contains($convPipelineSrc, 'pipeline_try_direct_intents($leadId, $botId, $userMessage, $bot, $lead, $customerTurn, $options)')
    && str_contains($convPipelineSrc, '$deferLocationToCore')
    && str_contains($convPipelineSrc, 'if ($widgetDeferFaq)')
    && str_contains($convPipelineSrc, 'widget_pre_ai: defer_offer_to_core')
    && str_contains($convPipelineSrc, 'widget_pre_ai: defer_location_to_core')
    && str_contains($convPipelineSrc, 'knowledge_offer_reply_text($bot, $userMessage, $leadId)'),
    'WIDGET-FAQ widget defers offer/location pre-AI to Core; WhatsApp handlers unchanged'
);

require_once $root . '/includes/conversation-intent.php';

$locBot = [
    'id'             => 501,
    'name'           => 'Danish Khan Co',
    'company_name'   => 'Danish Khan Co',
    'address'        => 'Gulberg III, Lahore, Pakistan',
    'industry_key'   => 'services',
    'is_active'      => 1,
];
ac_assert(
    conversation_is_location_question('Where are you located?')
    && agent_core_canonical_location_draft($locBot, 'Where are you located?') === "We're at Gulberg III, Lahore, Pakistan."
    && agent_core_canonical_location_draft($locBot, 'What do you offer?') === '',
    'LOC-B location question returns address draft without OpenAI'
);

$locCtx = $coachCtx;
$locCtx['text'] = 'Where are you located?';
$locCtx['channel'] = 'widget';
$locCtx['bot'] = array_merge(is_array($locCtx['bot'] ?? null) ? $locCtx['bot'] : [], $locBot);
$locRun = agent_core_run($locCtx);
ac_assert(
    !empty($locRun['ok'])
    && str_contains((string) ($locRun['reply'] ?? ''), 'Lahore')
    && ac_compose_path($locRun) === 'agent_core',
    'LOC-C widget location reaches Core with canonical address reply'
);

require_once $root . '/includes/live-world-info.php';
require_once $root . '/includes/conversation-source-router.php';

ac_assert(
    function_exists('live_world_message_needs_fresh_evidence')
    && live_world_message_needs_fresh_evidence('What happened recently in tech?', '')
    && live_world_message_needs_fresh_evidence('Who is currently the governor?', '')
    && live_world_message_needs_fresh_evidence('Latest match scores please', '')
    && live_world_message_needs_fresh_evidence('What is the weather today?', '')
    && !live_world_message_needs_fresh_evidence('What do you offer?', '')
    && !live_world_message_needs_fresh_evidence('Where are you located?', ''),
    'FRESH-A global freshness lexicon routes current-world, not business FAQ'
);

$recentCtx = $baseCtx;
$recentCtx['text'] = 'What happened recently in the news?';
$recentIntent = agent_core_intent($recentCtx, $emptyConv);
ac_assert(
    ($recentIntent['kind'] ?? '') === 'LIVE_WORLD' && !empty($recentIntent['needs_web']),
    'FRESH-B recent/news phrasing is LIVE_WORLD'
);

$sportsCtx = $baseCtx;
$sportsCtx['text'] = 'Who won the latest football match?';
$sportsIntent = agent_core_intent($sportsCtx, $emptyConv);
ac_assert(
    ($sportsIntent['kind'] ?? '') === 'LIVE_WORLD',
    'FRESH-C sports results phrasing is LIVE_WORLD'
);

$bizLiveCtx = $baseCtx;
$bizLiveCtx['text'] = 'Where are you and who is the current president?';
$bizLiveIntent = agent_core_intent($bizLiveCtx, $emptyConv);
$bizLiveSource = agent_core_source_route($bizLiveCtx, $emptyConv, $bizLiveIntent);
ac_assert(
    ($bizLiveSource['primary'] ?? '') === 'MIXED' && !empty($bizLiveSource['needs_web']),
    'FRESH-D business + current-world combines as MIXED'
);

$liveWorldSrc = file_get_contents($root . '/includes/live-world-info.php') ?: '';
$routerSrcFresh = file_get_contents($root . '/includes/conversation-source-router.php') ?: '';
ac_assert(
    str_contains($liveWorldSrc, 'function live_world_message_needs_fresh_evidence')
    && str_contains($routerSrcFresh, 'live_world_message_needs_fresh_evidence')
    && !str_contains($routerSrcFresh, 'who runs (america')
    && !str_contains($routerSrcFresh, 'pakistan news'),
    'FRESH-E single global freshness gate; no topic-specific router handlers'
);

$composeSrcFresh = file_get_contents($root . '/includes/agent-core/compose.php') ?: '';
$widgetSrcFresh = file_get_contents($root . '/api/chat-widget.php') ?: '';
$aiRespondSrcFresh = file_get_contents($root . '/api/ai-respond.php') ?: '';
ac_assert(
    str_contains($composeSrcFresh, 'agent_core_compose_live_world_draft')
    && str_contains($widgetSrcFresh, 'register_shutdown_function')
    && str_contains($aiRespondSrcFresh, 'agent_core_fallback'),
    'FRESH-F LIVE_WORLD fast compose + widget fatal JSON guard + widget Core fallback'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
