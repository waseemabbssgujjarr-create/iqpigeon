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
ac_assert(
    str_contains($composeSrc, 'conversation_mind_generate')
    && !str_contains($composeSrc, 'ai_chat('),
    'compose uses conversation_mind_generate, not a second ai_chat brain'
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
    empty($emptyCore['ok'])
    && trim((string) ($emptyCore['reply'] ?? '')) === ''
    && ($emptyCore['error'] ?? '') === 'empty_compose'
    && ($emptyCore['fallback_reason'] ?? '') === 'empty_generate'
    && ac_compose_path($emptyCore) === 'webhook_mind',
    'empty Core result → webhook_mind fallback'
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
    && str_contains($botChatSrc, 'agent_core_channel_try($bot, 0, $message, 0, \'bot-chat\')'),
    '12-stage pipeline exists; bot-chat is an adapter around Core'
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

ac_assert(ac_compose_path($threw) === 'webhook_mind', '15 Core failure → webhook_mind fallback');
ac_assert(ac_compose_path($emptyCore) === 'webhook_mind', '16 empty generation → fallback');
ac_assert(ac_compose_path($badVal) === 'webhook_mind', '17 validation failure → fallback');

ac_assert(
    AGENT_CORE_ENABLED === false
    && str_contains($coreSrc, 'wa_webhook_mind_reply($bot, $leadId, $userMessage)'),
    '18 Core OFF → legacy webhook_mind path'
);

ac_assert(
    agent_core_enabled(['id' => 57]) === false
    && agent_core_enabled(['id' => 99]) === false
    && agent_core_bot_ids() === [],
    '19 allow-list isolation (empty list, bot 57 off)'
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
    ($emptyRun['fallback_reason'] ?? '') === 'empty_generate'
    && in_array('CORE_GENERATE', ac_sink_names(), true)
    && in_array('CORE_FALLBACK', ac_sink_names(), true),
    '3 empty generation records fallback_reason=empty_generate'
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

$offTry = agent_core_channel_try(['id' => 53], 0, 'hi', 0, 'whatsapp');
ac_assert(
    ($offTry['path'] ?? '') === 'core_off'
    && ($offTry['fallback_reason'] ?? '') === 'disabled'
    && ac_compose_path($offTry) === 'webhook_mind',
    '7 Core fallback when disabled/not allowlisted stays webhook_mind'
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
    && agent_core_enabled(['id' => 53]) === false
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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
