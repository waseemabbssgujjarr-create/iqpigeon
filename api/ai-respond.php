<?php

/**

 * Core AI conversation engine.

 */



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/openai.php';

require_once __DIR__ . '/../includes/mailer.php';

require_once __DIR__ . '/../includes/ai-personality.php';

require_once __DIR__ . '/../includes/demo-training.php';

require_once __DIR__ . '/../includes/visitor-context.php';

require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/lead-lifecycle.php';
require_once __DIR__ . '/../includes/shipment.php';
require_once __DIR__ . '/../includes/platform-training.php';
require_once __DIR__ . '/../includes/conversation-pipeline.php';
require_once __DIR__ . '/../includes/human-agent-prompt.php';

/**
 * Process a user message and return AI response.

 *

 * @param int $leadId

 * @param int $botId

 * @param string $userMessage

 * @param array{locale?: string, country?: string, skip_user_insert?: bool, skip_assistant_insert?: bool, user_message_id?: int, system_hint?: string, customer_turn?: bool, ai_only?: bool} $options

 * @return array{success: bool, reply?: string, signals?: array<string>, error?: string, user_message_id?: int}

 */

function get_ai_response(int $leadId, int $botId, string $userMessage, array $options = []): array

{

    ensure_conversations_schema();



    $skipUserInsert = !empty($options['skip_user_insert']);
    $skipAssistantInsert = !empty($options['skip_assistant_insert']);
    $customerTurn = !empty($options['customer_turn']);
    $aiOnly = !empty($options['ai_only']);



    $bot = db_fetch(

        'SELECT b.*, u.company_name, u.email AS client_email

         FROM bots b

         JOIN users u ON u.id = b.user_id

         WHERE b.id = ? AND b.is_active = 1',

        'i',

        [$botId]

    );



    if (!$bot) {

        return ['success' => false, 'error' => 'Bot not found or inactive.'];

    }



    $lead = db_fetch('SELECT * FROM leads WHERE id = ? AND bot_id = ?', 'ii', [$leadId, $botId]);

    if (!$lead) {

        return ['success' => false, 'error' => 'Lead not found.'];

    }

    bot_prune_lead_conversations_before_knowledge($leadId, $bot);



    $userConversationId = (int) ($options['user_message_id'] ?? 0);



    if ($skipUserInsert) {

        if ($userConversationId <= 0) {

            $latestUser = db_fetch(

                'SELECT id FROM conversations WHERE lead_id = ? AND role = \'user\' ORDER BY id DESC LIMIT 1',

                'i',

                [$leadId]

            );

            $userConversationId = (int) ($latestUser['id'] ?? 0);

        }

    } else {

        $userConversationId = db_insert(

            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'user\', ?)',

            'is',

            [$leadId, $userMessage]

        );

        require_once __DIR__ . '/../includes/drip.php';
        drip_reset_on_customer_reply($leadId);

    }

    touch_lead_activity($leadId);

    if (conversation_has_buying_signal($userMessage)) {
        conversation_bump_lead_interest($leadId, $userMessage, 15);
    }

    $lead = db_fetch('SELECT * FROM leads WHERE id = ?', 'i', [$leadId]) ?? $lead;



    if (($lead['status'] ?? '') === 'booked' && catalog_products_for_bot($botId) !== [] && cart_message_is_shopping_intent($userMessage)) {

        cart_reopen_lead_shopping($leadId);

        $lead['status'] = 'in_progress';

    }



    if (is_lead_bot_paused($lead)) {

        return [

            'success' => true,

            'reply'   => '',

            'signals' => [],

            'paused'  => true,

        ];

    }

    if (!$aiOnly) {
        $preAi = pipeline_run_pre_ai($leadId, $botId, $userMessage, $bot, $lead, $options);
        if ($preAi !== null) {
            $preAi['user_message_id'] = $userConversationId;

            return $preAi;
        }
    }



    if (!is_demo_bot($botId) && !within_chat_limit((int) $bot['user_id'], $leadId)) {

        return [

            'success'       => true,

            'reply'         => human_shop_fallback_reply($bot, $userMessage, 'limit', $leadId),

            'signals'       => [],

            'limit_reached' => true,

        ];

    }



    if ($lead['status'] === 'new') {

        db_execute(

            'UPDATE leads SET status = \'in_progress\' WHERE id = ?',

            'i',

            [$leadId]

        );

    } elseif ($lead['status'] === 'disqualified') {

        db_execute(

            'UPDATE leads SET status = \'in_progress\' WHERE id = ?',

            'i',

            [$leadId]

        );

    }

    // Same Core path as WhatsApp / Test & Publish (LIVE_WORLD + live-answer).
    // Skip when ai_only (validation/recovery retries need legacy openai + system_hint).
    if (!$aiOnly) {
        try {
            require_once __DIR__ . '/../includes/agent-core/bootstrap.php';
            $coreChannel = trim((string) ($options['channel'] ?? ''));
            if ($coreChannel === '') {
                $platform = strtolower(trim((string) ($lead['platform'] ?? '')));
                $coreChannel = $platform !== '' ? $platform : 'widget';
            }
            if (function_exists('agent_core_enabled') && agent_core_enabled($bot, $coreChannel)) {
                require_once __DIR__ . '/../includes/agent-core/agent-core.php';
                $core = agent_core_channel_try(
                    $bot,
                    $leadId,
                    $userMessage,
                    (int) ($options['turn_id'] ?? 0),
                    $coreChannel
                );
                if (agent_core_result_usable($core)) {
                    $coreReply = trim((string) $core['reply']);
                    if (!$skipAssistantInsert && $coreReply !== '') {
                        db_insert(
                            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                            'is',
                            [$leadId, $coreReply]
                        );
                    }

                    return [
                        'success'         => true,
                        'reply'           => $coreReply,
                        'signals'         => ['AGENT_CORE'],
                        'path'            => 'agent_core',
                        'user_message_id' => $userConversationId,
                    ];
                }
                if ($coreChannel === 'widget') {
                    $fallbackReply = trim((string) ($core['reply'] ?? ''));
                    if ($fallbackReply === '') {
                        require_once __DIR__ . '/../includes/conversation-mind.php';
                        if (function_exists('conversation_mind_unverified_live_reply')) {
                            $fallbackReply = conversation_mind_unverified_live_reply($bot);
                        }
                    }
                    if ($fallbackReply !== '') {
                        if (!$skipAssistantInsert) {
                            db_insert(
                                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                                'is',
                                [$leadId, $fallbackReply]
                            );
                        }

                        return [
                            'success'         => true,
                            'reply'           => $fallbackReply,
                            'signals'         => ['AGENT_CORE_FALLBACK'],
                            'path'            => 'agent_core_fallback',
                            'user_message_id' => $userConversationId,
                        ];
                    }

                    return [
                        'success'         => false,
                        'error'           => 'Could not process your message. Please try again.',
                        'path'            => 'agent_core_failed',
                        'user_message_id' => $userConversationId,
                    ];
                }
            }
        } catch (Throwable $coreErr) {
            error_log('get_ai_response agent_core: ' . $coreErr->getMessage());
        }
    }



    $history = db_fetch_all(

        'SELECT role, message, created_at FROM conversations WHERE lead_id = ? ORDER BY created_at ASC',

        'i',

        [$leadId]

    );



    $historyMax = defined('AI_HISTORY_MAX_TURNS') ? max(6, (int) AI_HISTORY_MAX_TURNS) : 15;
    if (count($history) > $historyMax) {
        $history = array_slice($history, -$historyMax);
    }



    $storedContext = visitor_context_from_lead($lead);

    $visitorContext = $storedContext !== [] ? $storedContext : resolve_visitor_context($options);

    if ($storedContext === [] && $visitorContext !== []) {

        store_visitor_context_on_lead($leadId, $visitorContext);

    }

    $systemPrompt = build_full_ai_system_prompt(
        $bot,
        $lead,
        $bot['company_name'] ?? APP_NAME,
        $userMessage,
        $history,
        $visitorContext,
        $leadId,
        $botId
    );

    if (!empty($options['system_hint'])) {
        $systemPrompt .= "\n\n" . trim((string) $options['system_hint']);
    }

    $chatOptions = get_ai_sales_chat_options($botId, false, $userMessage);
    if ($customerTurn) {
        $chatOptions['timeout'] = 8;
        $chatOptions['max_attempts'] = 1;
    }



    $messages = [['role' => 'system', 'content' => $systemPrompt]];



    foreach ($history as $row) {

        if ($row['role'] === 'system') {

            continue;

        }

        $messages[] = [

            'role'  => $row['role'] === 'assistant' ? 'assistant' : 'user',

            'content' => $row['message'],

        ];

    }

    $lastTurn = $messages !== [] ? $messages[count($messages) - 1] : null;
    if ($lastTurn === null || $lastTurn['role'] !== 'user' || trim($lastTurn['content']) !== trim($userMessage)) {
        $messages[] = ['role' => 'user', 'content' => $userMessage];
    }



    $lastAssistantBefore = '';

    foreach (array_reverse($history) as $row) {

        if ($row['role'] === 'assistant') {

            $lastAssistantBefore = trim($row['message']);

            break;

        }

    }



    $result = openai_chat($messages, $chatOptions);



    if (!$result['success']) {

        $cartFallback = cart_handle_command($leadId, $botId, $userMessage, array_merge($lead, ['bot_user_id' => (int) $bot['user_id']]));

        if ($cartFallback !== null) {

            db_insert(

                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',

                'is',

                [$leadId, $cartFallback]

            );

            return [

                'success' => true,

                'reply'   => $cartFallback,

                'signals' => ['CART'],

            ];

        }

        $catalogFallback = null;
        if (!cart_checkout_in_progress($leadId) && catalog_has_clear_shopping_intent($userMessage)) {
            $catalogFallback = catalog_try_resolve_product_request($botId, $userMessage, $bot);
        }

        if ($catalogFallback !== null) {

            $catalogReply = conversation_finalize_reply($bot, $leadId, (string) ($catalogFallback['reply'] ?? ''), $userMessage);

            db_insert(

                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',

                'is',

                [$leadId, $catalogReply]

            );

            return [

                'success'         => true,

                'reply'           => $catalogReply,

                'signals'         => ['CATALOG'],

                'product_indexes' => $catalogFallback['indexes'] ?? [],

            ];

        }

        require_once __DIR__ . '/../includes/bot-knowledge.php';
        $knowledgeReply = knowledge_try_local_reply($bot, $userMessage, $leadId);
        if ($knowledgeReply !== null) {
            db_insert(
                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                'is',
                [$leadId, $knowledgeReply]
            );
            return [
                'success' => true,
                'reply'   => $knowledgeReply,
                'signals' => ['KNOWLEDGE'],
            ];
        }

        require_once __DIR__ . '/../includes/human-agent-prompt.php';
        $humanReply = human_agent_finalize_reply(
            $bot,
            $leadId,
            human_agent_warm_last_resort($bot, $userMessage, $leadId),
            $userMessage
        );

        db_insert(

            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',

            'is',

            [$leadId, $humanReply]

        );

        return [

            'success' => true,

            'reply'   => $humanReply,

            'signals' => ['FALLBACK'],

        ];

    }



    $rawReply = $result['content'];

    $customerLang = resolve_customer_language($history, $userMessage);



    if (!$customerTurn && ai_reply_needs_language_retry($rawReply, $customerLang)) {

        $langRetryMessages = $messages;

        $langRetryMessages[] = [

            'role'    => 'user',

            'content' => build_language_retry_user_nudge($customerLang),

        ];

        $langRetry = openai_chat($langRetryMessages, get_ai_sales_chat_options($botId, true, $userMessage));

        if ($langRetry['success'] && trim($langRetry['content'] ?? '') !== '') {

            $rawReply = $langRetry['content'];

        }

    }



    if (!$customerTurn && (ai_reply_is_repetitive($rawReply, $lastAssistantBefore)
        || (function_exists('human_agent_is_bad_fallback') && human_agent_is_bad_fallback($rawReply)))
    ) {

        $retryMessages = $messages;

        $retryMessages[] = [

            'role'    => 'user',

            'content' => $customerTurn
                ? human_agent_recovery_hint($userMessage, 0)
                : 'Your draft repeated your previous message. Write a NEW reply that directly answers the customer\'s latest question. Different wording. Same facts from the script only.',

        ];

        $retry = openai_chat($retryMessages, get_ai_sales_chat_options($botId, true, $userMessage));

        if ($retry['success'] && trim($retry['content'] ?? '') !== '') {

            $rawReply = $retry['content'];

        }

    }



    $signals = [];



    if (str_contains($rawReply, '[BOOK_CALL]')) {

        $signals[] = 'BOOK_CALL';

    }

    if (str_contains($rawReply, '[CREATE_ORDER]')) {

        $signals[] = 'CREATE_ORDER';

    }

    if (str_contains($rawReply, '[DISQUALIFY]')) {

        $userTurns = 0;

        foreach ($history as $row) {

            if ($row['role'] === 'user') {

                $userTurns++;

            }

        }

        if ($userTurns >= 4) {

            $signals[] = 'DISQUALIFY';

        }

    }



    $orderFields = cart_parse_order_tag($rawReply);

    if ($orderFields !== []) {

        cart_update_checkout($leadId, $orderFields);

    }



    $productIndexes = catalog_parse_product_tags($rawReply);

    require_once __DIR__ . '/../includes/restaurant-menu-card.php';
    $menuIndexes = catalog_parse_menu_tags($rawReply);
    $menuCard = false;
    if ($menuIndexes !== []) {
        $productIndexes = $menuIndexes;
        $menuCard = true;
    }

    $productIndexes = catalog_auto_product_indexes($botId, $userMessage, $productIndexes);

    $menuCardTitle = '';
    if ($menuCard || (catalog_bot_is_restaurant($botId) && count($productIndexes) >= 2)) {
        $menuCardTitle = catalog_title_for_indexes($botId, $productIndexes);
        $matchedCard = catalog_menu_card_for_message($botId, $userMessage);
        if ($matchedCard !== null && count(array_intersect($productIndexes, $matchedCard['indexes'])) >= 2) {
            $menuCardTitle = (string) ($matchedCard['title'] ?? $menuCardTitle);
        }
    }

    if (!$menuCard && catalog_bot_is_restaurant($botId) && count($productIndexes) >= 2) {
        $menuCard = true;
    }

    if (function_exists('catalog_message_is_non_product_topic') && catalog_message_is_non_product_topic($userMessage)) {
        $productIndexes = [];
    }

    if (cart_checkout_in_progress($leadId)
        || cart_user_wants_checkout($userMessage)
        || in_array('CREATE_ORDER', $signals, true)
        || cart_message_looks_like_delivery_details($userMessage)) {
        $productIndexes = [];
    }

    if (function_exists('catalog_message_is_category_inquiry')
        && catalog_message_is_category_inquiry($userMessage)
        && !catalog_customer_wants_product_visuals($userMessage)
        && !catalog_customer_says_media_missing($userMessage)
    ) {
        $productIndexes = [];
        $menuCard = false;
    }



    $finalReply = trim(str_replace(['[BOOK_CALL]', '[DISQUALIFY]', '[CREATE_ORDER]'], '', $rawReply));

    $finalReply = preg_replace('/\[ORDER:[^\]]+\]/', '', $finalReply) ?? $finalReply;

    $finalReply = preg_replace('/\[PRODUCT:[^\]]+\]/i', '', $finalReply) ?? $finalReply;

    $finalReply = preg_replace('/\[MENU:[^\]]+\]/i', '', $finalReply) ?? $finalReply;

    $finalReply = trim($finalReply);

    if ($productIndexes !== []) {
        $cardCount = $menuCard ? 1 : count($productIndexes);
        $finalReply = catalog_ensure_visual_reply($finalReply, $cardCount);
    } else {
        $finalReply = catalog_strip_unsent_media_claims($finalReply);
    }

    $finalReply = catalog_sanitize_reply($finalReply);
    $finalReply = conversation_strip_internal_directives($finalReply);
    $finalReply = chat_strip_markdown_asterisks($finalReply);

    require_once __DIR__ . '/../includes/bot-knowledge.php';
    if (!$customerTurn
        && knowledge_message_is_offer_question($userMessage)
        && preg_match('/\b(double-check|verify that|get back to you shortly|one moment please|let me check|confirm the exact detail)\b/iu', $finalReply)
    ) {
        $finalReply = knowledge_offer_reply_text($bot, $userMessage, $leadId);
    }

    if ($finalReply === '') {

        require_once __DIR__ . '/../includes/helpers.php';
        $finalReply = conversation_smart_fallback_reply($bot, $leadId, $userMessage);

    }

    if ($customerTurn) {
        require_once __DIR__ . '/../includes/human-agent-prompt.php';
        $finalReply = human_agent_finalize_reply($bot, $leadId, $finalReply, $userMessage);
    } else {
        $finalReply = conversation_finalize_reply($bot, $leadId, $finalReply, $userMessage);
    }

    if (function_exists('conversation_shop_menu_send_flagged') && conversation_shop_menu_send_flagged()) {
        require_once __DIR__ . '/../includes/restaurant-menu-card.php';
        if (!in_array('MENU', $signals, true)) {
            $signals[] = 'MENU';
        }
        if ($productIndexes === [] && catalog_bot_has_products($botId)) {
            $matched = catalog_menu_card_for_message($botId, $userMessage);
            if ($matched !== null && count($matched['indexes']) >= 2) {
                $productIndexes = $matched['indexes'];
                $menuCardTitle = (string) ($matched['title'] ?? $menuCardTitle);
            } else {
                $productIndexes = catalog_top_food_indexes($botId, [], RESTAURANT_MENU_CARD_MAX_ITEMS);
            }
            $menuCard = count($productIndexes) >= 2;
        }
    }



    if (in_array('BOOK_CALL', $signals, true)) {

        $bookingSettings = booking_settings_for_bot($botId);

        $nativeBooking = !empty($bookingSettings['enabled']) && !empty($bookingSettings['use_native_booking']);

        $calendly = get_bot_calendly_link($bot);

        $qualifyMsg = trim($bot['qualify_message'] ?? '');

        // Calendly / external booking temporarily disabled — native booking coming later.
        $calendly = '';



        if ($nativeBooking) {

            $slotsMsg = booking_slots_message($botId);

            if ($slotsMsg !== '' && !str_contains($finalReply, 'Pick a time')) {

                $finalReply = trim($finalReply . "\n\n" . $slotsMsg);

            }

            db_execute(

                'UPDATE leads SET status = \'qualified\', calendly_link_sent = 0, score = GREATEST(score, 85) WHERE id = ?',

                'i',

                [$leadId]

            );

        } else {

            if ($qualifyMsg !== '' && !str_contains($finalReply, $qualifyMsg)) {

                $finalReply = trim($finalReply . "\n\n" . $qualifyMsg . ($calendly !== '' ? "\n" . $calendly : ''));

            } elseif ($calendly !== '' && !str_contains($finalReply, $calendly)) {

                $finalReply = trim($finalReply . "\n\n" . $calendly);

            }



            db_execute(

                'UPDATE leads SET status = \'qualified\', calendly_link_sent = 1, score = GREATEST(score, 85) WHERE id = ?',

                'i',

                [$leadId]

            );

        }



        if (!empty($bot['client_email'])) {

            email_lead_qualified($bot['client_email'], $lead['name'] ?? 'Lead');

        }



        require_once __DIR__ . '/../includes/notifications.php';

        notify_lead_qualified($botId, $lead['name'] ?? 'Lead', $leadId);

    }



    $hasCreateOrderSignal = in_array('CREATE_ORDER', $signals, true);

    $autoOrder = cart_maybe_auto_finalize_order(
        $leadId,
        $botId,
        (int) $bot['user_id'],
        $lead,
        $userMessage,
        $rawReply,
        $finalReply,
        $hasCreateOrderSignal
    );

    if ($autoOrder['created'] && !empty($autoOrder['order_id'])) {
        $orderId = (int) $autoOrder['order_id'];
        $orderNote = cart_order_confirmation_message($orderId);
        if (!str_contains($finalReply, (string) $orderId)) {
            $finalReply = trim($finalReply . "\n\n" . $orderNote);
        }
        $productIndexes = [];
    } elseif (!empty($autoOrder['ask_anything_else'])) {
        $finalReply = (string) $autoOrder['ask_anything_else'];
        $productIndexes = [];
    }



    if (in_array('DISQUALIFY', $signals, true)) {

        $disqualifyMsg = trim($bot['disqualify_message'] ?? '');

        if ($disqualifyMsg !== '' && !str_contains($finalReply, $disqualifyMsg)) {

            $finalReply = trim($finalReply . "\n\n" . $disqualifyMsg);

        }



        db_execute(

            'UPDATE leads SET status = \'disqualified\', score = LEAST(score, 20) WHERE id = ?',

            'i',

            [$leadId]

        );

    }



    if (!$skipAssistantInsert) {
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $finalReply]
        );
    }

    return [

        'success'         => true,

        'reply'           => $finalReply,

        'signals'         => $signals,

        'product_indexes' => $productIndexes,

        'menu_card'       => $menuCard,

        'menu_card_title' => $menuCardTitle,

        'user_message_id' => $userConversationId,

    ];

}



// Direct API access

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'ai-respond.php') {

    header('Content-Type: application/json');



    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        json_response(['success' => false, 'error' => 'Method not allowed'], 405);

    }



    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

    $leadId = (int) ($input['lead_id'] ?? 0);

    $botId = (int) ($input['bot_id'] ?? 0);

    $message = trim($input['message'] ?? '');



    if (!$leadId || !$botId || $message === '') {

        json_response(['success' => false, 'error' => 'Missing lead_id, bot_id, or message'], 400);

    }



    $result = get_ai_response($leadId, $botId, $message);

    json_response($result, $result['success'] ? 200 : 500);

}

