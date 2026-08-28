<?php
/**
 * Conversation Turn Engine — automated tests (CLI).
 * Run: php tests/conversation-turn-engine-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/whatsapp-inbound.php';
require_once $root . '/includes/conversation-turn-engine.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
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

// TEST 1 — fragmented text
$combined = turn_engine_combine_text_parts(['How', 'are', 'you?']);
assert_test($combined === 'How are you?', 'TEST 1 fragmented text merges to one line');

// TEST 1b — Do You Know About pattern
$frag = turn_engine_combine_text_parts(['Do', 'You', 'Know', 'About']);
assert_test($frag === 'Do You Know About', 'TEST 1b short fragment burst');

$helloBurst = turn_engine_combine_text_parts(['Hello', 'how', 'are', 'you', '?']);
assert_test($helloBurst === 'Hello how are you?', 'TEST 1c Hello/how/are/you/? merges to one line');

// TEST 2 — combine preserves longer parts on newlines when not all short
$long = turn_engine_combine_text_parts(['Hello there', 'I need pricing for your enterprise plan']);
assert_test(str_contains($long, 'Hello there'), 'TEST 2 longer parts combined');

// TEST 3 — normalize message text
$norm = turn_engine_normalize_whatsapp_message([
    'id'   => 'wamid.test123',
    'type' => 'text',
    'text' => ['body' => 'Is this available?'],
]);
assert_test($norm['message_type'] === 'text' && $norm['raw_text'] === 'Is this available?', 'TEST 3 text normalization');

// TEST 4 — image normalization
$img = turn_engine_normalize_whatsapp_message([
    'id'    => 'wamid.img1',
    'type'  => 'image',
    'image' => ['id' => 'media1', 'caption' => 'Which ones?'],
]);
assert_test($img['message_type'] === 'image' && $img['caption'] === 'Which ones?', 'TEST 4 image normalization');

// TEST 5 — infer conversation state
assert_test(turn_engine_infer_state('How much is this?', 'DISCOVERY') === 'PRICE_INQUIRY', 'TEST 5 price inquiry state');
assert_test(turn_engine_infer_state('Do you have this in stock?', 'DISCOVERY') === 'AVAILABILITY_CHECK', 'TEST 5b availability state');

// TEST 6 — duplicate idempotency (DB)
try {
    turn_engine_ensure_schema();
    $exists = turn_engine_wa_message_exists('wamid.nonexistent_' . bin2hex(random_bytes(4)));
    assert_test($exists === false, 'TEST 6 unknown wa_message_id not duplicate');
} catch (Throwable $e) {
    echo "SKIP DB tests: " . $e->getMessage() . "\n";
}

// TEST 7 — build turn payload structure (mock rows via combine)
$payload = turn_engine_build_turn_payload(0);
assert_test(is_array($payload) && array_key_exists('combined', $payload), 'TEST 7 payload shape');

// TEST 8 — repetitive detection helper
assert_test(is_bool(turn_engine_response_is_repetitive(0, 'hello')), 'TEST 8 repetitive check callable');

// TEST 9 — debounce constants (internal engine values, not user switches)
$const = turn_engine_constants();
assert_test(
    function_exists('turn_engine_quiet_seconds')
    && turn_engine_quiet_seconds() >= 7
    && $const['text_debounce_ms'] >= 7000
    && $const['text_debounce_ms'] <= 9000
    && $const['media_debounce_ms'] >= 7000
    && $const['media_debounce_ms'] <= 9000
    && $const['max_window_ms'] >= 20000
    && $const['max_window_ms'] <= 45000,
    'TEST 9 debounce waits 7s after last bubble'
);

// TEST 10 — cost fragment burst: hold disabled in v2 (fixed debounce only)
require_once $root . '/includes/conversation-intent.php';
$costPartial = turn_engine_combine_text_parts(['What', 'Will', 'Be', 'The']);
assert_test(!conversation_should_hold_turn_for_more_input($costPartial, 4), 'TEST 10 hold disabled v2');
$costFull = turn_engine_combine_text_parts(['What', 'Will', 'Be', 'The', 'Cost', '?']);
assert_test(!conversation_should_hold_turn_for_more_input($costFull, 6), 'TEST 10b hold disabled v2');

// TEST 12 — complete wellbeing burst must not hold forever
$wellbeing = turn_engine_combine_text_parts(['Hey', 'How', 'Are', 'You', '?']);
assert_test(!conversation_should_hold_turn_for_more_input($wellbeing, 5), 'TEST 12 complete how are you not held');

// TEST 13 — frustration / follow-up after reply is complete
$frustrated = 'Reading but not replying why?';
assert_test(!conversation_should_hold_turn_for_more_input($frustrated, 3), 'TEST 13 frustration message not held');

// TEST 14 — max window helpers exist
assert_test(function_exists('turn_engine_max_window_exceeded') && function_exists('turn_engine_force_max_window_due'), 'TEST 14 max window helpers');

// TEST 15 — greeting burst must not hold (Hey Hi / Hey Hey silence fix)
$heyBurst = turn_engine_combine_text_parts(['Hey', 'Hi']);
assert_test(!conversation_should_hold_turn_for_more_input($heyBurst, 2), 'TEST 15 Hey Hi greeting burst not held');
$heyHey = turn_engine_combine_text_parts(['Hey', 'Hey']);
assert_test(!conversation_should_hold_turn_for_more_input($heyHey, 2), 'TEST 15b Hey Hey greeting burst not held');

// TEST 16 — knowledge trim never ends mid-word with ellipsis
require_once $root . '/includes/bot-knowledge.php';
$longOffer = 'At Adera Labs, we transform your ideas, products, and brand stories into cinematic advertisements and content strategy.';
$trimmed = knowledge_trim_snippet($longOffer, 95);
assert_test(!preg_match('/(?:…|\.\.\.)$/u', $trimmed), 'TEST 16 no trailing ellipsis on trim');
assert_test(preg_match('/[.!?]$/u', $trimmed) === 1, 'TEST 16 trimmed snippet ends with punctuation');

require_once $root . '/includes/conversation-intelligence.php';
assert_test(function_exists('turn_engine_should_suppress_outbound') && function_exists('turn_engine_prepare_outbound_reply'), 'TEST 21 stale/validation send gates exist');
assert_test(function_exists('conversation_intelligence_analyze') && function_exists('conversation_intelligence_factuality_gate'), 'TEST 21b intelligence engine loaded');
$docNote = conversation_intelligence_wrap_untrusted('DOCUMENT', 'Invoice 12');
assert_test(str_contains($docNote, 'UNTRUSTED'), 'TEST 22 document content marked untrusted');

require_once $root . '/includes/human-agent-prompt.php';
$doctrine = human_agent_runtime_prompt();
assert_test(
    str_contains($doctrine, 'READ')
    && str_contains($doctrine, 'LISTEN')
    && str_contains($doctrine, 'UNDERSTAND')
    && str_contains($doctrine, 'THINK')
    && str_contains($doctrine, 'PLAN')
    && str_contains($doctrine, 'DECIDE')
    && str_contains($doctrine, 'VERDICT')
    && str_contains($doctrine, 'REPLY'),
    'TEST 23 doctrine has read/listen/understand/think/plan/decide/reply loop'
);
assert_test(str_contains(human_agent_live_protocol(), 'LIVE HUMAN AGENT PROTOCOL'), 'TEST 23b live protocol present');
assert_test(human_agent_is_robotic_reply('Thanks for the image.'), 'TEST 24 thanks-for-the-image is robotic');
assert_test(human_agent_is_robotic_reply('I cannot view images.'), 'TEST 24b image denial is robotic');

$sticker = turn_engine_normalize_whatsapp_message(['id' => 'wamid.sticker1', 'type' => 'sticker', 'sticker' => ['id' => 's1']]);
assert_test($sticker['raw_text'] === '[Sticker]', 'TEST 25 sticker normalized into turn text');
$loc = turn_engine_normalize_whatsapp_message([
    'id' => 'wamid.loc1',
    'type' => 'location',
    'location' => ['name' => 'Mall Road', 'latitude' => '31.5', 'longitude' => '74.3'],
]);
assert_test(str_contains((string) $loc['raw_text'], 'Location'), 'TEST 25b location normalized into turn text');

require_once $root . '/includes/platform-training.php';
$promptBot = [
    'id' => 0,
    'name' => 'Northwind',
    'rep_name' => 'Ayesha',
    'bot_knowledge' => 'We sell office chairs. Ergonomic Pro is $199.',
    'business_model' => 'Office furniture',
    'openai_system_prompt' => '',
    'persona_description' => 'Warm and direct Tone: Friendly',
];
try {
    $runtime = build_runtime_bot_prompt($promptBot, 'Northwind Co');
    assert_test(str_contains($runtime, 'live human on WhatsApp'), 'TEST 26 runtime identity is a live human on WhatsApp');
    assert_test(!str_contains($runtime, 'like ChatGPT'), 'TEST 26b runtime does not tell the model to act like ChatGPT');
    assert_test(str_contains($runtime, 'LIVE HUMAN AGENT PROTOCOL'), 'TEST 26c protocol injected into runtime prompt');
    assert_test(str_contains($runtime, 'IDENTITY LOCK'), 'TEST 26d identity lock injected');
    assert_test(
        str_contains($runtime, 'PRIME LAW')
        && str_contains($runtime, 'CHANGED SUBJECT')
        && str_contains($runtime, 'Listen first'),
        'TEST 26e live prompt includes listen-first doctrine, not only a short protocol'
    );
} catch (Throwable $e) {
    echo "SKIP TEST 26 runtime prompt (needs DB): " . $e->getMessage() . "\n";
}
assert_test(function_exists('conversation_engine_core_status') && conversation_engine_core_status() !== [], 'TEST 27 core status is informational');
assert_test(function_exists('turn_engine_force_finalize_all_overdue'), 'TEST 28 worker overdue helper exists');
$fetchSrc = file_get_contents($root . '/includes/conversation-turn-engine.php') ?: '';
assert_test(!str_contains($fetchSrc, 'b.company_name'), 'TEST 29 fetch due turn does not select bots.company_name');

require_once $root . '/includes/conversation-intent.php';
require_once $root . '/includes/human-agent-prompt.php';
$typoBurst = turn_engine_combine_text_parts(['Hey', 'How', 'Are', 'Uou', '?']);
assert_test(conversation_is_wellbeing_question($typoBurst), 'TEST 30 typo burst Hey How Are Uou is wellbeing');
assert_test(conversation_is_wellbeing_question('How are you?'), 'TEST 30b How are you is wellbeing');
assert_test(function_exists('conversation_is_presence_ping') && conversation_is_presence_ping('Are you?'), 'TEST 30c Are you? is a presence ping');
assert_test(
    function_exists('conversation_is_canned_help_intro')
    && conversation_is_canned_help_intro("I'm Amelie from IQ Pigeon — what can I help you with?"),
    'TEST 31 canned Amelie intro is blocked'
);
assert_test(
    human_agent_is_robotic_reply("I'm Amelie from IQ Pigeon — what can I help you with?"),
    'TEST 31b canned intro is robotic'
);
$cannedBot = ['id' => 0, 'name' => 'IQ Pigeon', 'rep_name' => 'Amelie', 'company_name' => 'IQ Pigeon'];
$wellbeingResort = human_agent_warm_last_resort($cannedBot, 'How are you?');
assert_test(
    str_contains(mb_strtolower($wellbeingResort), 'doing well')
    && !str_contains($wellbeingResort, 'what can I help you with'),
    'TEST 32 how-are-you last resort is not the canned intro'
);
$mixedResort = human_agent_warm_last_resort($cannedBot, "Hey how are you now?\nWhat you have right now?");
assert_test(
    trim($mixedResort) !== ''
    && !str_contains(mb_strtolower($mixedResort), 'doing well'),
    'TEST 32d mixed how-are-you + what do you have answers the real ask'
);
assert_test(
    function_exists('turn_engine_send_if_customer_waiting')
    && str_contains(file_get_contents($root . '/api/whatsapp-webhook.php') ?: '', 'turn_engine_send_leads_now'),
    'TEST 32e webhook sends in-request if the customer is still waiting'
);
$typoResort = human_agent_warm_last_resort($cannedBot, $typoBurst);
assert_test(
    str_contains(mb_strtolower($typoResort), 'doing well')
    && !str_contains($typoResort, 'what can I help you with'),
    'TEST 32b typo how-are-you last resort is not the canned intro'
);
$nudge = conversation_conversion_nudge_reply($cannedBot, 'How are you?', 0);
assert_test(!str_contains($nudge, 'what can I help you with'), 'TEST 32c conversion nudge no longer re-intros on how are you');
$workerSrc = file_get_contents($root . '/api/turn-worker.php') ?: '';
assert_test(
    str_contains($workerSrc, 'Manual GET')
    && str_contains($workerSrc, 'INTERVAL ? SECOND')
    && !preg_match('/last_message_at <= DATE_SUB\(NOW\(\), INTERVAL 2 SECOND\)/', $workerSrc)
    && !str_contains($workerSrc, 'turn_engine_force_finalize_overdue($leadId, 2)'),
    'TEST 33 worker does not flush turns after only 2 quiet seconds'
);
$engineSrc = file_get_contents($root . '/includes/conversation-turn-engine.php') ?: '';
assert_test(
    function_exists('turn_engine_quiet_due_sql')
    && str_contains($engineSrc, 'turn_engine_quiet_due_sql')
    && str_contains($engineSrc, 'last_message_at <= DATE_SUB(NOW(), INTERVAL')
    && !preg_match('/finalize_after = NOW\(\).*max_window_force_due/s', $engineSrc)
    && !str_contains($engineSrc, "turn_engine_process_due(1, [\$leadId])"),
    'TEST 38 never due or typing until 7s after last inbound bubble'
);
$keepSrc = file_get_contents($root . '/includes/whatsapp-typing-keepalive.php') ?: '';
assert_test(
    str_contains($keepSrc, 'whatsapp_typing_active_path')
    && str_contains($keepSrc, 'stopped')
    && str_contains($keepSrc, 'Typing keepalive is disabled'),
    'TEST 38d typing keepalive does not pulse in the background'
);
assert_test(
    str_contains($engineSrc, 'whatsapp_acquire_lead_reply_lock($leadId, 20)')
    && str_contains($engineSrc, 'lock busy, rescheduling')
    && !preg_match('/if \(!whatsapp_acquire_lead_reply_lock\(\$leadId, \d+\)\) \{\s+turn_engine_rebuffer/', $engineSrc),
    'TEST 38e lock busy does not yank a live turn back to buffering'
);
$cronSrc = file_get_contents($root . '/api/cron.php') ?: '';
$workerRecover = file_get_contents($root . '/api/turn-worker.php') ?: '';
assert_test(
    str_contains($engineSrc, 'max(1, min(15, $maxAgeMinutes))')
    && !str_contains($cronSrc, 'recover_stuck_turns(2)')
    && !str_contains($cronSrc, 'turn_engine_process_due')
    && !str_contains($cronSrc, 'conversation-turn-engine.php')
    && !str_contains($workerRecover, 'recover_stuck_turns(2)'),
    'TEST 38f cron does not process WhatsApp turns; stuck recovery waits for AI'
);
assert_test(
    str_contains($cronSrc, 'nohup curl')
    && str_contains($cronSrc, '-m 90')
    && !str_contains($cronSrc, 'CURLOPT_TIMEOUT        => 2')
    && !str_contains($cronSrc, 'conversation-turn-engine.php'),
    'TEST 38l cron detaches turn-worker for 90s like webhook dispatch'
);
$recoverSrc = file_get_contents($root . '/includes/wa-recover-lite.php') ?: '';
assert_test(
    str_contains($workerRecover, 'turn_engine_send_leads_now')
    && str_contains($workerRecover, "\$_GET['dry']")
    && str_contains($workerRecover, 'wa_recover_dry_inspect')
    && str_contains($workerRecover, 'diagnostic_hold')
    && str_contains($recoverSrc, 'function wa_recover_trace')
    && str_contains($recoverSrc, 'function wa_recover_dry_inspect')
    && str_contains($recoverSrc, 'Do not bulk-finalize due buffering turns before SELECT')
    && !preg_match('/wa_recover_finalize_buffering\(\$botId\);\s*\n\s*\$result\[\'hung\'\]/', $recoverSrc),
    'TEST 38m recover logs + dry inspect; worker uses send_leads_now; finalize does not hide same-query turns'
);
assert_test(
    function_exists('turn_engine_arm_must_send')
    && str_contains($engineSrc, 'turn_engine_arm_must_send')
    && str_contains($engineSrc, 'turn_engine_shutdown_flush_must_send')
    && str_contains($engineSrc, 'CURLE_OPERATION_TIMEDOUT') === false
    && str_contains($engineSrc, '$errno !== 28'),
    'TEST 38g typing commits a must-send; worker curl timeout is not a dispatch failure'
);
$webhookSrc = file_get_contents($root . '/api/whatsapp-webhook.php') ?: '';
$ingestPos = strpos($webhookSrc, 'turn_engine_ingest($bot');
$ackAfterIngest = $ingestPos === false ? false : strpos($webhookSrc, 'wa_webhook_ack_meta();', $ingestPos);
$markReadPos = strpos($webhookSrc, 'whatsapp_mark_message_read');
assert_test(
    $ingestPos !== false
    && $ackAfterIngest !== false
    && $ingestPos < $ackAfterIngest
    && $markReadPos !== false
    && $markReadPos > $ackAfterIngest
    && str_contains($engineSrc, 'function turn_engine_send_leads_now')
    && str_contains($engineSrc, 'function turn_engine_ensure_lead_replied'),
    'TEST 38h webhook ingests then ACKs Meta before mark-read/compose'
);
$processFn = strpos($engineSrc, 'function turn_engine_process_turn');
$intelReq = $processFn === false ? false : strpos($engineSrc, 'conversation-intelligence.php', $processFn);
$processHead = ($processFn !== false && $intelReq !== false)
    ? substr($engineSrc, $processFn, $intelReq - $processFn)
    : '';
assert_test(
    str_contains($processHead, 'whatsapp_mark_message_read')
    || str_contains($engineSrc, 'whatsapp_mark_message_read($phoneId, $token, $primaryWaId)'),
    'TEST 38i mark-read happens before AI/intelligence work'
);
$workerSrcDebug = file_get_contents($root . '/api/turn-worker.php') ?: '';
assert_test(
    str_contains($workerSrcDebug, "(\$_GET['debug'] ?? '') === '1'")
    && str_contains($workerSrcDebug, 'already_replied_per_turn'),
    'TEST 38j worker health exposes a live debug dump'
);
assert_test(
    str_contains($webhookSrc, 'turn_engine_dispatch_worker')
    && str_contains($webhookSrc, 'function wa_webhook_ack_meta')
    && str_contains($webhookSrc, 'fastcgi_finish_request')
    && strpos($webhookSrc, 'wa_webhook_ack_meta()') < strrpos($webhookSrc, 'turn_engine_send_leads_now')
    && str_contains($engineSrc, 'function turn_engine_send_leads_now')
    && !str_contains($webhookSrc, 'Inline send before Meta ACK'),
    'TEST 38k webhook acks Meta immediately then processes async'
);
assert_test(
    function_exists('security_sanitize_html_output')
    && function_exists('security_output_is_webhook_script')
    && security_sanitize_html_output('12345678901234567890123456789012') === ''
    && str_contains((string) file_get_contents($root . '/config.php'), 'security_output_is_webhook_script'),
    'TEST 38n HTML sanitizer strips 32-char Meta challenges; webhook scripts skip it'
);
assert_test(
    str_contains($webhookSrc, 'function wa_webhook_release_output_buffers')
    && strpos($webhookSrc, 'wa_webhook_release_output_buffers()') < strpos($webhookSrc, 'echo $challenge')
    && str_contains($webhookSrc, 'wa_webhook_release_output_buffers();')
    && str_contains($webhookSrc, "echo 'OK'"),
    'TEST 38o webhook GET/ACK discard output buffers before sending Meta bytes'
);
$recoverSrc = file_get_contents($root . '/includes/wa-recover-lite.php') ?: '';
$coreSrc = file_get_contents($root . '/includes/whatsapp-auto-reply-core.php') ?: '';
$waitFn = strpos($engineSrc, 'function turn_engine_webhook_wait_quiet');
$waitSrc = $waitFn !== false ? substr($engineSrc, $waitFn, 900) : '';
assert_test(
    str_contains($engineSrc, '$maxWaitMs = min(18000')
    && str_contains($waitSrc, 'Silent wait until 7s')
    && !str_contains($waitSrc, 'whatsapp_send_typing_indicator')
    && str_contains($coreSrc, 'function wa_webhook_mind_reply')
    && str_contains($coreSrc, "'path' => 'webhook_mind'")
    && str_contains($engineSrc, "\$GLOBALS['wa_skip_openai'] = true")
    && str_contains($webhookSrc, 'DUPLICATE_REQUEUE_SEND')
    && str_contains($recoverSrc, 'no unanswered inbound turn')
    && !str_contains($coreSrc, 'wa_recover_lead_already_replied($leadId)'),
    'TEST 38l webhook waits silently then sends a mind/menu/cart line (no OpenAI/catalog/GD)'
);
assert_test(
    str_contains($engineSrc, "path' => 'still_waiting'")
    && strpos($engineSrc, 'turn_engine_webhook_wait_quiet([$leadId]') < strpos($engineSrc, 'whatsapp_send_typing_indicator($phoneId, $token, $waId)'),
    'TEST 38m typing starts only after 7s quiet; unfinished bursts stay waiting'
);
assert_test(str_contains(human_agent_live_protocol(), 'Word-by-word'), 'TEST 38b protocol treats split bubbles as one thought');
assert_test(
    str_contains(human_agent_live_protocol(), 'LISTEN')
    && str_contains(human_agent_live_protocol(), 'THINK')
    && str_contains(human_agent_live_protocol(), 'First sentence answers them'),
    'TEST 38c protocol is listen-first then reply to that'
);
$pruneSrc = file_get_contents($root . '/includes/bot-knowledge.php') ?: '';
assert_test(
    !str_contains($pruneSrc, 'DELETE FROM conversations WHERE lead_id')
    && !str_contains($pruneSrc, 'bot_clear_conversations_for_bot($botId);'),
    'TEST 34 retraining does not wipe live chat history'
);

assert_test(
    function_exists('conversation_is_general_chat')
    && conversation_is_general_chat('How are you?')
    && !conversation_wants_commercial_context('How are you?'),
    'TEST 35 how-are-you is general chat, not a template turn'
);
assert_test(
    function_exists('conversation_wants_commercial_context')
    && conversation_wants_commercial_context('What do you offer?')
    && !conversation_is_general_chat('What do you offer?'),
    'TEST 35b what-do-you-offer is a commercial/industry turn'
);
assert_test(function_exists('conversation_is_personal_chat'), 'TEST 35c personal chat helper exists');
require_once $root . '/includes/industry-templates.php';
$svcBot = [
    'id' => 0,
    'name' => 'Adera Labs',
    'company_name' => 'Adera Labs',
    'industry_key' => 'services',
    'business_model' => 'We provide [service] for [target clients]. Packages from [price range].',
];
$resolvedOffer = knowledge_resolve_placeholders((string) $svcBot['business_model'], $svcBot);
assert_test(
    !knowledge_text_has_unresolved_placeholders($resolvedOffer)
    && !str_contains(mb_strtolower($resolvedOffer), 'great food')
    && !str_contains(mb_strtolower($resolvedOffer), 'menu items'),
    'TEST 36 services template fills real context, not restaurant copy'
);
assert_test(function_exists('human_agent_social_turn_lock') && str_contains(human_agent_social_turn_lock(), 'SOCIAL'), 'TEST 37 social turn lock exists');
$promptSrc = file_get_contents($root . '/includes/platform-training.php') ?: '';
assert_test(str_contains($promptSrc, 'conversation_wants_commercial_context'), 'TEST 37b live prompt gates catalog behind commercial intent');

require_once $root . '/includes/whatsapp-shop-ux.php';
require_once $root . '/includes/cart.php';
assert_test(
    !whatsapp_shop_customer_wants_visual_card('Do you have Anything in Bar section ?')
    && !whatsapp_shop_customer_wants_visual_card('Amir Khan 03001234567'),
    'TEST 39 asking if a section exists does not dump a menu card'
);
assert_test(
    whatsapp_shop_customer_wants_visual_card('send the menu')
    && whatsapp_shop_customer_wants_visual_card('show me the menu with photos'),
    'TEST 39b explicit show/send menu still gets a card'
);
assert_test(
    cart_message_looks_like_delivery_details('Innovista Chenab office S9 DHA Multan'),
    'TEST 40 office/DHA address is delivery details without a phone'
);
$addrResort = human_agent_warm_last_resort($cannedBot, 'Innovista Chenab office S9 DHA Multan');
assert_test(
    !str_contains(mb_strtolower($addrResort), 'weather'),
    'TEST 40b address with a city name is not a weather reply'
);
$followSrc = file_get_contents($root . '/includes/whatsapp-shop-ux.php') ?: '';
assert_test(
    str_contains($followSrc, 'whatsapp_shop_customer_wants_visual_card')
    && !str_contains($followSrc, 'catalog_default_menu_card($botId)'),
    'TEST 41 followup does not auto-send a default menu card'
);
assert_test(
    function_exists('cart_message_declines_anything_else')
    && cart_message_declines_anything_else('no')
    && cart_message_declines_anything_else("that's all")
    && !cart_message_declines_anything_else('yes'),
    'TEST 42 no / that\'s all means nothing else to add'
);
assert_test(
    function_exists('cart_try_place_order')
    && function_exists('cart_anything_else_question'),
    'TEST 42b ask-anything-else gate exists before dashboard order'
);
$cartSrc = file_get_contents($root . '/includes/cart.php') ?: '';
assert_test(
    str_contains($cartSrc, 'cart_try_place_order')
    && str_contains($cartSrc, 'do you need anything else'),
    'TEST 42c checkout asks before sending the order for processing'
);

require_once $root . '/includes/conversation-intent.php';
assert_test(
    function_exists('conversation_message_is_aside_for_type')
    && conversation_message_is_aside_for_type('how are you?', 'order')
    && conversation_message_is_aside_for_type('what is the weather today?', 'order')
    && !conversation_message_is_aside_for_type('Innovista Chenab office S9 DHA Multan', 'order')
    && !conversation_message_is_aside_for_type('Muhammad Ali', 'order'),
    'TEST 43 off-topic chat is an aside during checkout; address and name are not'
);
assert_test(
    conversation_message_is_aside_for_type('tell me a joke', 'appointment')
    && !conversation_message_is_aside_for_type('2', 'appointment')
    && conversation_message_is_aside_for_type('how are you', 'parcel')
    && !conversation_message_is_aside_for_type('where is my order', 'parcel')
    && !conversation_message_is_aside_for_type('What you have?', 'parcel')
    && !conversation_message_is_aside_for_type('Send me your best item menu', 'parcel'),
    'TEST 43b asides resume appointment and parcel conversions without dropping shopping'
);
$asidePrompt = conversation_conversion_aside_prompt_block([
    'type'   => 'order',
    'resume' => "Whenever you're ready, send your delivery address and I'll continue the order.",
]);
assert_test(
    str_contains($asidePrompt, 'ASIDE DURING OPEN CONVERSION')
    && str_contains($asidePrompt, 'send your delivery address'),
    'TEST 43c prompt answers the aside first, then returns to the conversion'
);
$promptSrc = file_get_contents($root . '/includes/platform-training.php') ?: '';
assert_test(
    str_contains($promptSrc, 'conversation_message_is_aside_for_type'),
    'TEST 43d live prompt does not dump catalog on conversion asides'
);
assert_test(
    function_exists('conversation_is_hours_question')
    && conversation_is_hours_question('What are you timings?')
    && conversation_should_skip_catalog_routing('What are you timings?'),
    'TEST 44 timings is hours, not a menu dump'
);
assert_test(
    function_exists('catalog_customer_says_media_missing')
    && catalog_customer_says_media_missing("I don't see it.")
    && catalog_customer_says_media_missing('Which photos?')
    && catalog_reply_promises_media('Sending photos now 👇'),
    'TEST 44b missing/promised media is detected so the menu can actually be sent'
);
assert_test(
    function_exists('catalog_strip_unsent_media_claims')
    && !str_contains(
        catalog_strip_unsent_media_claims("We're open 10 to 10. Sending photos now 👇"),
        'Sending photos'
    ),
    'TEST 44c fake sending-photos line is stripped when no card will go out'
);
assert_test(
    function_exists('conversation_should_skip_conversion_resume')
    && conversation_should_skip_conversion_resume('Good morning')
    && conversation_should_skip_conversion_resume('how are you?')
    && conversation_should_skip_conversion_resume('What time at your location?')
    && !conversation_should_skip_conversion_resume('what is the weather today?'),
    'TEST 45 greetings and hours do not get a checkout closer bolted on'
);
assert_test(
    !conversation_is_identity_question("I didn't ask your name")
    && conversation_is_bot_frustration("I didn't ask your name")
    && cart_message_declines_anything_else('Nothing')
    && cart_message_looks_like_delivery_details('Amir khan 03004522663')
    && !conversation_message_looks_like_person_name('Hii'),
    'TEST 46 checkout reads name/phone and does not treat complaints as intros'
);
assert_test(
    catalog_has_clear_shopping_intent('What you have tonight?')
    && catalog_message_is_browse_intent('Send me your best item menu')
    && conversation_is_generic_menu_prompt_reply('The Sicilian Restaurant — what are you in the mood for? I can send the menu once you tell me the section or item.')
    && conversation_wants_commercial_context('Can you send menu pics?'),
    'TEST 45b menu asks are shopping, not a canned section pitch'
);

require_once $root . '/includes/whatsapp-auto-reply-core.php';
$alexBurst = turn_engine_combine_text_parts(['Are you', 'Sure', 'You', 'Are', 'Alex?']);
assert_test(
    str_contains(mb_strtolower($alexBurst), 'alex')
    && str_contains(mb_strtolower($alexBurst), 'are you'),
    'TEST 47 split Are-you-Alex bubbles join as one question'
);
$personaBot = [
    'rep_name' => 'Alex',
    'name' => 'The Sicilian Restaurant',
    'company_name' => 'The Sicilian Restaurant',
    'rep_persona' => 'Sara lives in Lahore, likes cricket and chai, movies — thrillers and comedy.',
];
$hobby = wa_webhook_answer_what_they_asked($personaBot, 0, 'What are your hobbies?', 'what are your hobbies?');
$who = wa_webhook_answer_what_they_asked($personaBot, 0, 'Are you sure you are Alex?', 'are you sure you are alex?');
assert_test(
    str_contains(mb_strtolower($hobby), 'cricket')
    && str_contains(mb_strtolower($who), 'alex')
    && !str_contains(mb_strtolower($who), 'what can i help you with'),
    'TEST 47b replies to hobbies and identity from persona, not a canned intro'
);
require_once $root . '/includes/conversation-mind.php';
$promptDoc = '1. Identity & Role. You are the restaurant\'s Customer Relations & Lead Management Agent. Your name, business name, restaurant details,';
$promptBot = [
    'rep_name' => 'Alex',
    'name' => 'The Sicilian Restaurant',
    'company_name' => 'The Sicilian Restaurant',
    'rep_persona' => $promptDoc,
];
$facts = conversation_mind_personal_facts($promptBot);
$factBlob = mb_strtolower(implode(' ', $facts));
$introCtx = ['intent' => 'PERSONAL_CONVERSATION', 'facts' => $facts, 'mode' => 'PERSONAL_CONVERSATION', 'summary' => '', 'history' => []];
$intro = conversation_mind_grounded_fallback($promptBot, 'Introduce yourself please', $introCtx);
$howYou = conversation_mind_grounded_fallback($promptBot, 'How about you?', ['intent' => 'PERSONAL_CONVERSATION', 'facts' => $facts, 'mode' => 'PERSONAL_CONVERSATION', 'summary' => '', 'history' => []]);
$friends = conversation_mind_grounded_fallback($promptBot, "I don't want anything I just want to be friends", ['intent' => 'CASUAL_CONVERSATION', 'facts' => $facts, 'mode' => 'CASUAL_CONVERSATION', 'summary' => '', 'history' => []]);
$missed = conversation_mind_grounded_fallback($promptBot, "You didn't understand", ['intent' => 'CLARIFICATION', 'facts' => $facts, 'mode' => 'CASUAL_CONVERSATION', 'summary' => '', 'history' => []]);
$guarded = conversation_mind_guard_reply($promptBot, 0, 'Introduce yourself please', 'From my side — 1. Identity & Role You are the restaurant\'s Customer Relations');
assert_test(
    conversation_mind_is_instruction_doc($promptDoc)
    && conversation_mind_is_leak('From my side — 1. Identity & Role')
    && !str_contains($factBlob, 'lead management agent')
    && !conversation_mind_is_leak($intro)
    && str_contains(mb_strtolower($howYou), 'good')
    && str_contains(mb_strtolower($friends), 'chat')
    && str_contains(mb_strtolower($missed), 'right')
    && !str_contains(mb_strtolower($guarded), 'identity')
    && conversation_mind_intent('tell me more', [['role' => 'user', 'message' => 'Introduce yourself please'], ['role' => 'assistant', 'message' => "I'm Alex with The Sicilian"]], 'PERSONAL_CONVERSATION') === 'PERSONAL_CONVERSATION',
    'TEST 47d persona stays internal; intro/friends/how-about-you never dump the prompt'
);
$sendNowFn = strpos($engineSrc, 'function turn_engine_send_leads_now');
$sendNowSrc = $sendNowFn !== false ? substr($engineSrc, $sendNowFn, 3500) : '';
assert_test(
    str_contains($sendNowSrc, 'whatsapp_acquire_lead_reply_lock($leadId, 0)')
    && strpos($sendNowSrc, 'whatsapp_acquire_lead_reply_lock($leadId, 0)') < strpos($sendNowSrc, 'turn_engine_webhook_wait_quiet')
    && str_contains($coreSrc, 'function wa_webhook_answer_what_they_asked')
    && str_contains($coreSrc, "empty(\$GLOBALS['wa_skip_openai'])")
    && str_contains(file_get_contents($root . '/includes/whatsapp-auto-reply-core.php') ?: '', 'conversation_mind_reply')
    && is_file($root . '/includes/conversation-mind.php'),
    'TEST 47c one waiter per lead; conversation mind generates from hidden persona facts'
);

require_once $root . '/includes/industry-templates.php';
require_once $root . '/includes/bot-knowledge.php';
$locBot = [
    'rep_name' => 'Sara',
    'name' => 'The Sicilian Restaurant',
    'company_name' => 'The Sicilian Restaurant',
    'industry_key' => 'restaurant',
    'address' => 'Street Number 10, Shalimar Colony, Main Bosan Road Multan.',
    'rep_persona' => 'Sara lives in Lahore, likes cricket and chai.',
];
$facts = conversation_mind_personal_facts($locBot);
$factBlob = mb_strtolower(implode(' ', $facts));
$bizBlock = mb_strtolower(industry_runtime_facts_block($locBot));
$whereBiz = wa_webhook_answer_what_they_asked($locBot, 0, 'Where is the restaurant?', 'where is the restaurant?');
$whereYou = wa_webhook_answer_what_they_asked($locBot, 0, 'Where are you?', 'where are you?');
assert_test(
    industry_key_from_posted('Education / Coaching') === 'education'
    && industry_key_from_posted('Education / Training') === 'education'
    && industry_key_from_posted('restaurant') === 'restaurant'
    && str_contains($factBlob, 'lives_in: lahore')
    && !str_contains($factBlob, 'city: lahore')
    && str_contains($bizBlock, 'multan')
    && str_contains($bizBlock, 'lahore')
    && str_contains(mb_strtolower($whereBiz), 'multan')
    && !str_contains(mb_strtolower($whereBiz), 'lahore')
    && str_contains(mb_strtolower($whereYou), 'multan')
    && !str_contains(mb_strtolower($whereYou), 'lahore')
    && turn_engine_is_chase_nudge("why don't you reply")
    && !turn_engine_is_chase_nudge('What are your opening hours?')
    && str_contains($engineSrc, 'whatsapp_send_typing_indicator($phoneId, $token, $waId)')
    && str_contains(file_get_contents($root . '/client/training.php') ?: '', 'save_rep_name')
    && str_contains(file_get_contents($root . '/includes/views/client-training.php') ?: '', 'name="industry_key"'),
    'TEST 48 industry keys match admin; venue is Multan not Lahore; typing + chase wait'
);

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
    'rep_name' => 'Salman',
    'name' => 'Waqar Tayyub & Co.',
    'company_name' => 'Waqar Tayyub & Co.',
    'industry_key' => 'freelancer',
    'bot_knowledge' => $coachKb,
];
$offer = mb_strtolower(knowledge_offer_list_reply($coachBot));
$greet = knowledge_configured_greeting($coachBot);
$rate = knowledge_extract_hourly_rate($coachBot);
$protocol = human_agent_live_protocol();
assert_test(
    !bot_uses_shop_catalog($coachBot)
    && !wa_webhook_wants_catalog('what do you offer', $coachBot)
    && !catalog_message_is_browse_intent('What do you offer?')
    && str_contains($offer, 'neural performance coaching')
    && str_contains($offer, 'ai growth')
    && !str_contains($offer, 'menu')
    && str_contains(mb_strtolower($greet), 'salman')
    && $rate === '$80/hour'
    && str_contains($protocol, 'Never mix another business')
    && !str_contains($protocol, 'If they asked for the menu, best items')
    && str_contains(file_get_contents($root . '/includes/whatsapp-auto-reply-core.php') ?: '', 'bot_uses_shop_catalog($bot)')
    && !str_contains(file_get_contents($root . '/includes/whatsapp-auto-reply-core.php') ?: '', 'say *menu* when you want the list'),
    'TEST 49 freelancer/coaching uses training services, never a restaurant menu'
);

$phase1Core = file_get_contents($root . '/includes/whatsapp-auto-reply-core.php') ?: '';
$phase1Hold = file_get_contents($root . '/includes/wa-recover-lite.php') ?: '';
assert_test(
    str_contains($phase1Core, 'agent_core_enabled($bot)')
    && str_contains($phase1Core, "'path' => 'agent_core'")
    && str_contains($phase1Core, "'path' => 'webhook_mind'")
    && str_contains($engineSrc, "\$GLOBALS['wa_skip_openai'] = true")
    && str_contains($phase1Hold, 'return [720, 635, 321, 306, 302, 134]')
    && (defined('AGENT_CORE_ENABLED') ? AGENT_CORE_ENABLED === false : true)
    && str_contains($webhookSrc, 'function wa_webhook_ack_meta'),
    'TEST 50 Phase 1 compose fork exists; default Core OFF; ACK/hold/skip_openai unchanged'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
