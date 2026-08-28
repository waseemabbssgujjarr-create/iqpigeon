<?php
/**
 * Conversation pipeline scenario tests — run on server after deploy.
 *
 *   cd ~/public_html
 *   php tests/conversation-scenarios-test.php
 *   php tests/conversation-scenarios-test.php --bot-id=12
 *
 * Exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/cart.php';
require_once $root . '/includes/bot-knowledge.php';
require_once $root . '/includes/catalog.php';
require_once $root . '/includes/conversation-pipeline.php';
require_once $root . '/includes/conversation-router.php';
require_once $root . '/includes/whatsapp-shop-ux.php';

$argv = $argv ?? [];
$botIdArg = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--bot-id=')) {
        $botIdArg = (int) substr($arg, 9);
    }
}

$passed = 0;
$failed = 0;

function cst_assert(bool $ok, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        echo "  PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failed++;
}

echo "Conversation Pipeline Scenario Tests\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

echo "=== Pattern guards (no DB) ===\n";

cst_assert(cart_message_is_farewell_or_decline('Good night'), 'farewell: good night');
cst_assert(cart_message_is_farewell_or_decline('Okay bye'), 'farewell: okay bye');
cst_assert(cart_message_is_farewell_or_decline('Ni chahye bhai'), 'decline: ni chahye bhai');
cst_assert(!cart_message_is_farewell_or_decline('checkout'), 'checkout is not farewell');
cst_assert(!cart_user_wants_checkout('ok'), 'bare ok is not checkout intent');
cst_assert(!cart_user_wants_checkout('yes'), 'bare yes is not checkout intent');
cst_assert(cart_reply_includes_summary("*Your cart*\n\n• Pizza\nReply *checkout* to place order"), 'detect cart summary reply');
cst_assert(cart_user_wants_checkout('checkout'), 'checkout keyword detected');
cst_assert(cart_user_wants_checkout('place order'), 'place order detected');
cst_assert(!pipeline_allows_conversational_shortcuts(true, 'hi'), 'whatsapp hi goes to AI, not canned shortcuts');
cst_assert(!pipeline_allows_conversational_shortcuts(true, 'thanks'), 'whatsapp thanks goes to AI, not canned shortcuts');
cst_assert(pipeline_allows_conversational_shortcuts(false, 'hi'), 'widget hi may use shortcuts');

echo "\n=== Cart affirmative (needs lead context) ===\n";

$botId = $botIdArg;
if ($botId <= 0) {
    $row = db_fetch('SELECT id FROM bots WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $botId = (int) ($row['id'] ?? 0);
}

if ($botId <= 0) {
    echo "  SKIP  no active bot — use --bot-id=N\n";
} else {
    $products = catalog_products_for_bot($botId);
    cst_assert($products !== [], 'bot has catalog products', 'bot_id=' . $botId);

    $leadId = db_insert(
        'INSERT INTO leads (bot_id, external_id, name, platform, status) VALUES (?, ?, ?, \'whatsapp\', \'new\')',
        'iss',
        [$botId, 'test-pipeline-' . time(), 'Pipeline Test']
    );

    cst_assert(!cart_message_is_checkout_affirmative($leadId, 'ok'), 'ok without assistant prompt is not affirmative');

    db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
        'is',
        [$leadId, "Almost there! Please send:\n1. Your full name\n2. Phone number\n3. Full delivery address"]
    );
    cst_assert(cart_message_is_checkout_affirmative($leadId, 'yes'), 'yes after delivery prompt is affirmative');

    db_execute('DELETE FROM conversations WHERE lead_id = ?', 'i', [$leadId]);
    db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
        'is',
        [$leadId, "COD confirmed.\n\nType *checkout* to place your order now."]
    );
    cst_assert(cart_message_is_checkout_affirmative($leadId, 'ok'), 'ok after COD confirm prompt is affirmative');

    db_execute('DELETE FROM conversations WHERE lead_id = ?', 'i', [$leadId]);
    cart_clear($leadId);
    $summary = '';
    if ($products !== []) {
        $summary = cart_add_product($leadId, $botId, 1, 1);
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $summary]
        );
    }
    cst_assert(!cart_message_is_checkout_affirmative($leadId, 'ok'), 'ok after cart summary — not affirmative');
    cst_assert(!cart_message_is_checkout_affirmative($leadId, 'yes'), 'yes after cart summary — not affirmative');

    if ($products !== []) {
        require_once $root . '/includes/conversation-intent.php';
        db_execute('DELETE FROM conversations WHERE lead_id = ?', 'i', [$leadId]);
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, 'Got it — please send your *full delivery address* (area, street, city).']
        );
        cst_assert(
            !cart_should_treat_as_checkout_details($leadId, 'how are you?'),
            'how are you is not saved as the delivery address'
        );
        cst_assert(
            cart_should_treat_as_checkout_details($leadId, 'Innovista Chenab office S9 DHA Multan'),
            'real address is still captured during checkout'
        );
        cst_assert(
            (conversation_active_conversion($leadId, $botId)['type'] ?? '') === 'order',
            'open cart checkout is an order conversion'
        );
        $appended = conversation_append_conversion_resume("I'm good, thanks!", $leadId, $botId, 'how are you?');
        cst_assert(
            !str_contains(mb_strtolower($appended), 'whenever you'),
            'how-are-you does not nag with a conversion closer'
        );
        $weather = conversation_append_conversion_resume("Yeah, brutal out there.", $leadId, $botId, 'what is the weather today?');
        cst_assert(
            str_contains(mb_strtolower($weather), 'name')
            || str_contains(mb_strtolower($weather), 'address')
            || str_contains(mb_strtolower($weather), 'anything else'),
            'unrelated aside still quietly resumes the order'
        );
    }

    db_execute('DELETE FROM conversations WHERE lead_id = ?', 'i', [$leadId]);
    db_execute('DELETE FROM leads WHERE id = ?', 'i', [$leadId]);
}

cst_assert(!knowledge_text_has_unresolved_placeholders(
    knowledge_resolve_placeholders('We serve [cuisine]. Dine-in available.', ['id' => $botId, 'name' => 'Test Pizza'])
), 'placeholder [cuisine] resolved');

echo "\n=== Pipeline direct intents (pattern) ===\n";
require_once $root . '/includes/conversation-intent.php';
cst_assert(conversation_is_hours_question('What are you timings?'), 'hours: what are you timings');
cst_assert(conversation_should_skip_catalog_routing('What are you timings?'), 'hours is not a catalog turn');
cst_assert(catalog_message_is_non_product_topic('What are you timings?'), 'timings is a non-product topic');
cst_assert(catalog_customer_says_media_missing("I don't see it."), 'missing media: i don\'t see it');
cst_assert(catalog_customer_says_media_missing("You didn't mentioned the menu yet."), 'missing media: you didn\'t mention');
cst_assert(catalog_customer_says_media_missing('Niether this time as well') || catalog_customer_says_media_missing('Neither this time as well'), 'missing media: neither this time');
cst_assert(catalog_customer_says_media_missing('Which photos?'), 'missing media: which photos');
cst_assert(catalog_reply_promises_media("We're open every day from 10:00 AM to 10:00 PM. Sending photos now 👇"), 'reply that promises photos is detected');
cst_assert(
    catalog_strip_unsent_media_claims("We're open every day from 10:00 AM to 10:00 PM. Sending photos now 👇")
        === "We're open every day from 10:00 AM to 10:00 PM.",
    'hours reply does not keep a fake photo promise'
);
cst_assert(!conversation_is_identity_question("I didn't ask your name.... I said something different you're replying."), 'complaint about name is not an identity question');
cst_assert(conversation_is_bot_frustration("I didn't ask your name.... I said something different you're replying."), 'name complaint is frustration');
cst_assert(conversation_is_bot_frustration('Order is not being processed'), 'unprocessed order complaint is frustration');
cst_assert(cart_message_declines_anything_else('Nothing'), 'bare nothing means no more items');
cst_assert(cart_message_declines_anything_else('nothing'), 'nothing declines anything else');
cst_assert(!conversation_message_looks_like_person_name('Hii'), 'Hii is not a delivery name');
cst_assert(!conversation_message_looks_like_person_name('Okay'), 'Okay is not a delivery name');
cst_assert(cart_message_looks_like_delivery_details('Amir khan 03004522663'), 'name + phone is checkout details');
$parsedName = cart_parse_delivery_blob('Amir khan 03004522663');
cst_assert(
    str_contains(mb_strtolower((string) ($parsedName['customer_name'] ?? '')), 'amir')
    && (($parsedName['customer_phone'] ?? '') !== ''),
    'name+phone blob stores both fields'
);
cst_assert(cart_parse_delivery_blob('Hii') === [], 'greeting is not stored as the customer name');
cst_assert(catalog_customer_wants_other_menu('Show any other best item?'), 'other best item is a different-menu ask');
cst_assert(catalog_customer_wants_other_menu('This menu I already received and asked for another best item, send me 2, 3 menus'), 'already-received menu is a different-menu ask');
cst_assert(conversation_should_skip_conversion_resume('What time at your location?') || conversation_is_hours_question('What time at your location?'), 'hours is not a checkout nag');
cst_assert(catalog_message_is_browse_intent('Can you show me your menu?'), 'browse: show menu');
cst_assert(catalog_message_is_browse_intent('What You Have Tonight ?'), 'browse: what you have tonight');
cst_assert(catalog_message_is_browse_intent('Hey What You Have Today ?'), 'browse: what you have today');
cst_assert(whatsapp_shop_customer_wants_menu('Hello What You Have Tonight ?', 0), 'shop UX: fragmented tonight');
cst_assert(catalog_message_is_browse_intent('Send me your best item menu'), 'browse: best item menu');
cst_assert(catalog_message_is_browse_intent('Can you send menu pics?'), 'browse: menu pics');
cst_assert(catalog_has_clear_shopping_intent('What you have tonight?'), 'shopping intent: what you have tonight');
cst_assert(catalog_has_clear_shopping_intent('Send me your best item menu'), 'shopping intent: best item menu');
cst_assert(conversation_wants_commercial_context('What you have?'), 'what you have is commercial, not a social lock');
cst_assert(whatsapp_shop_customer_wants_visual_card('Can you send menu pics?'), 'menu pics is a visual card request');
cst_assert(conversation_is_generic_menu_prompt_reply('The Sicilian Restaurant — what are you in the mood for? I can send the menu once you tell me the section or item.'), 'mood-for pitch is a generic menu prompt');
cst_assert(!str_contains(mb_strtolower(whatsapp_shop_copy_offer(['name' => 'Sicilian'], 0)), 'section'), 'offer copy does not ask for a menu section');
cst_assert(conversation_should_skip_conversion_resume('how are you?'), 'skip resume on how are you');
cst_assert(conversation_should_skip_conversion_resume('Good morning'), 'skip resume on good morning');
cst_assert(!conversation_should_skip_conversion_resume('what is the weather today?'), 'weather aside can still resume checkout');
cst_assert(!conversation_message_is_aside_for_type('What you have?', 'parcel'), 'menu browse is not an aside during parcel');
cst_assert(!conversation_message_is_aside_for_type('Send me your best item menu', 'order'), 'best-item menu is on-topic shopping');
cst_assert(!conversation_message_is_aside_for_type('Can you send menu pics?', 'parcel'), 'menu pics is on-topic shopping');
$stripped = conversation_strip_bot_habits(
    "I'm doing well, thanks! How about you? Whenever you're ready, I can also check your parcel / order status.",
    'Hey, how are you?'
);
cst_assert(
    !str_contains(mb_strtolower($stripped), 'parcel'),
    'unsolicited parcel closer is stripped from social replies'
);
cst_assert(catalog_message_is_menu_request(0, 'Menu'), 'menu request: Menu');
cst_assert(catalog_message_is_menu_request(0, 'menu'), 'menu request: menu');
cst_assert(conversation_is_bot_frustration("Don't talk to me you are confused"), 'frustration: confused');
cst_assert(conversation_is_generic_menu_prompt_reply("I'm here — reply *menu* to browse, *add #N* to order, or tell me what you need."), 'generic menu prompt detected');
cst_assert(conversation_is_generic_menu_prompt_reply('Reply *menu* to see our full menu, or tell me what you\'d like to order.'), 'generic reply *menu* detected');
cst_assert(conversation_is_generic_menu_prompt_reply("I'm here with RSK Pizza Multan! Tap below to browse the menu, or tell me what you'd like."), 'shop welcome pitch is generic');
cst_assert(conversation_route_intent('And What Are You Doing Right Now?') === 'meta', 'intent: what are you doing = meta');
cst_assert(conversation_route_intent('Good night') === 'farewell', 'intent: good night = farewell');
cst_assert(conversation_route_intent('Reply only what I asked') === 'frustration', 'intent: reply only = frustration');
cst_assert(conversation_route_intent('What is this you are saying instead of understanding me?') === 'frustration', 'intent: what is this you are saying');
cst_assert(conversation_route_intent('Menu') === 'menu', 'intent: Menu');
cst_assert(conversation_route_intent('What You Have Tonight ?') === 'menu', 'intent: what you have tonight');
cst_assert(!knowledge_message_is_offer_question('And What Are You Doing Right Now?'), 'doing is not an offer question');
cst_assert(!conversation_is_shop_tool_turn('And What Are You Doing Right Now?'), 'doing is not a shop tool turn');
cst_assert(!conversation_is_shop_tool_turn('Good night'), 'good night is not a shop tool turn');
cst_assert(conversation_is_shop_tool_turn('Menu'), 'Menu is a shop tool turn');
cst_assert(conversation_is_shop_pitch_reply("I'm here with RSK Pizza Multan! Tap below to browse the menu, or tell me what you'd like."), 'shop welcome is a pitch');
cst_assert(!conversation_is_shop_tool_turn('Sorry I asked how are you'), 'social is not a shop tool turn');
require_once $root . '/includes/human-agent-prompt.php';
cst_assert(str_contains(human_agent_live_protocol(), 'READ'), 'live protocol teaches READ first');
cst_assert(str_contains(human_agent_live_protocol(), 'Listen to what they actually say') || str_contains(human_agent_live_protocol(), 'LISTEN'), 'live protocol teaches LISTEN');
cst_assert(str_contains(human_agent_doctrine_mind(), 'CHANGED SUBJECT'), 'human mind layer follows a subject change');
$src = file_get_contents($root . '/includes/conversation-pipeline.php') ?: '';
cst_assert(!str_contains($src, "here's our menu. Tap an item"), 'frustration path no longer dumps the menu');
cst_assert(function_exists('pipeline_shop_menu_result'), 'pipeline_shop_menu_result() exists');
cst_assert(function_exists('catalog_send_shop_action_buttons'), 'catalog_send_shop_action_buttons() exists');
cst_assert(function_exists('whatsapp_shop_followup_after_reply'), 'whatsapp_shop_followup_after_reply() exists');
cst_assert(function_exists('whatsapp_shop_customer_wants_visual_card'), 'whatsapp_shop_customer_wants_visual_card() exists');
cst_assert(!whatsapp_shop_customer_wants_visual_card('Do you have Anything in Bar section ?'), 'bar-section question is not a menu-card dump');
cst_assert(whatsapp_shop_customer_wants_visual_card('Show me the menu'), 'show me the menu is a visual card request');
cst_assert(cart_message_looks_like_delivery_details('Innovista Chenab office S9 DHA Multan'), 'DHA/office line is an address');
cst_assert(function_exists('cart_try_place_order'), 'cart_try_place_order() exists');
cst_assert(cart_message_declines_anything_else('no') && cart_message_declines_anything_else("that's all"), 'no / that\'s all declines anything else');
cst_assert(!cart_message_declines_anything_else('yes I want a latte'), 'yes I want a latte is not a decline');
cst_assert(conversation_message_is_aside_for_type('how are you?', 'order'), 'how are you is an aside during an order');
cst_assert(conversation_message_is_aside_for_type('what is the weather today?', 'order'), 'weather is an aside during an order');
cst_assert(!conversation_message_is_aside_for_type('Innovista Chenab office S9 DHA Multan', 'order'), 'address is on-topic for an order');
cst_assert(!conversation_message_is_aside_for_type('Muhammad Ali', 'order'), 'customer name is on-topic for an order');
cst_assert(conversation_message_is_aside_for_type('tell me a joke', 'appointment'), 'joke is an aside during booking');
cst_assert(!conversation_message_is_aside_for_type('2', 'appointment'), 'slot number is on-topic for an appointment');
cst_assert(conversation_message_is_aside_for_type('how are you', 'parcel'), 'social is an aside during a parcel');
cst_assert(!conversation_message_is_aside_for_type('where is my order', 'parcel'), 'tracking is on-topic for a parcel');
$asidePrompt = conversation_conversion_aside_prompt_block([
    'type'   => 'order',
    'resume' => "Whenever you're ready, send your delivery address and I'll continue the order.",
]);
cst_assert(str_contains($asidePrompt, 'ASIDE DURING OPEN CONVERSION'), 'aside prompt tells the model to answer then resume');

echo "\n=== Order flow (functions) ===\n";
cst_assert(function_exists('cart_finalize_order'), 'cart_finalize_order() exists');
cst_assert(function_exists('pipeline_try_direct_intents'), 'pipeline_try_direct_intents() exists');
cst_assert(function_exists('knowledge_resolve_placeholders'), 'knowledge_resolve_placeholders() exists');

echo "\n=== File presence ===\n";

$required = [
    'includes/conversation-pipeline.php',
    'includes/business-hours.php',
    'includes/cart.php',
    'api/ai-respond.php',
    'includes/conversation-turn-engine.php',
    'includes/conversation-router.php',
    'includes/human-agent-prompt.php',
    'includes/human-agent-doctrine.php',
    'includes/whatsapp-shop-ux.php',
];

foreach ($required as $rel) {
    cst_assert(is_file($root . '/' . $rel), 'file exists: ' . $rel);
}

echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
