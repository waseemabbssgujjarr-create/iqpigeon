<?php
/**
 * Unified pre-AI conversation pipeline.
 * One ordered handler chain for WhatsApp, widget, and Instagram — no duplicate routing.
 */

require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/business-hours.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/conversation-router.php';
require_once __DIR__ . '/human-agent-prompt.php';

/**
 * WhatsApp pure-mode still uses deterministic handlers for obvious short intents.
 */
function pipeline_allows_conversational_shortcuts(bool $customerTurn, string $message): bool
{
    // WhatsApp human mode: AI answers unknown chat. No canned greeting/common layers.
    if ($customerTurn && human_agent_pure_mode_enabled()) {
        return false;
    }

    $trimmed = trim($message);
    if ($trimmed === '') {
        return false;
    }

    if (cart_message_is_farewell_or_decline($message)) {
        return true;
    }

    if (mb_strlen($trimmed) > 56) {
        return false;
    }

    $lower = mb_strtolower($trimmed);

    if (preg_match('/^(hi+|hello+|hey+|salam|aoa|assalam|thanks?|thank you|shukriya)[\s!?.]*$/iu', $lower)) {
        return true;
    }

    return (bool) preg_match('/\b(thank you|thanks|shukriya)\b/u', $lower);
}

function pipeline_finalize_text(array $bot, int $leadId, string $reply, string $userMessage, bool $customerTurn): string
{
    if ($customerTurn && human_agent_pure_mode_enabled()) {
        require_once __DIR__ . '/human-agent-prompt.php';

        return human_agent_finalize_reply($bot, $leadId, $reply, $userMessage);
    }

    return conversation_finalize_reply($bot, $leadId, $reply, $userMessage);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function pipeline_store_and_return(
    array $bot,
    int $leadId,
    string $reply,
    string $userMessage,
    bool $customerTurn,
    array $extra = []
): array {
    $final = pipeline_finalize_text($bot, $leadId, $reply, $userMessage, $customerTurn);
    db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
        'is',
        [$leadId, $final]
    );

    return array_merge([
        'success' => true,
        'reply'   => $final,
        'signals' => [],
    ], $extra);
}

/**
 * Restaurant → food menu card. Other industries → their real catalog items, never a burger template.
 *
 * @param array<string, mixed> $bot
 * @return array<string, mixed>|null
 */
function pipeline_industry_catalog_result(
    array $bot,
    int $leadId,
    int $botId,
    string $userMessage,
    bool $customerTurn
): ?array {
    require_once __DIR__ . '/restaurant-menu-card.php';
    require_once __DIR__ . '/catalog.php';

    if ($botId <= 0 || !catalog_bot_has_products($botId)) {
        return null;
    }

    if (catalog_bot_is_restaurant($botId)) {
        return pipeline_shop_menu_result($bot, $leadId, $botId, $userMessage, $customerTurn);
    }

    $browse = catalog_build_visual_browse_response($botId, $userMessage, $bot);
    if ($browse === null) {
        return null;
    }

    conversation_flag_shop_menu_send(true);

    return pipeline_store_and_return(
        $bot,
        $leadId,
        (string) ($browse['reply'] ?? ''),
        $userMessage,
        $customerTurn,
        [
            'signals'         => ['CATALOG'],
            'product_indexes' => $browse['indexes'] ?? [],
            'menu_card'       => !empty($browse['menu_card']),
            'menu_card_title' => (string) ($browse['menu_card_title'] ?? ''),
        ]
    );
}

/**
 * Send the real menu card path (indexes + MENU signal) instead of a type-*menu* CTA.
 *
 * @param array<string, mixed> $bot
 * @return array<string, mixed>
 */
function pipeline_shop_menu_result(
    array $bot,
    int $leadId,
    int $botId,
    string $userMessage,
    bool $customerTurn,
    string $reply = ''
): array {
    require_once __DIR__ . '/restaurant-menu-card.php';
    require_once __DIR__ . '/catalog.php';

    $matched = catalog_menu_card_for_message($botId, $userMessage);
    if ($matched !== null && count($matched['indexes']) >= 2) {
        $indexes = $matched['indexes'];
        $menuTitle = (string) ($matched['title'] ?? 'Menu');
    } else {
        $defaultCard = catalog_default_menu_card($botId);
        if ($defaultCard !== null) {
            $indexes = $defaultCard['indexes'];
            $menuTitle = (string) ($defaultCard['title'] ?? 'Menu highlights');
        } else {
            $indexes = catalog_top_food_indexes($botId, [], RESTAURANT_MENU_CARD_MAX_ITEMS);
            $menuTitle = 'Menu highlights';
        }
    }
    $menuReply = $reply !== '' ? $reply : conversation_shop_menu_open_reply($bot, $userMessage);
    conversation_flag_shop_menu_send(true);

    return pipeline_store_and_return($bot, $leadId, $menuReply, $userMessage, $customerTurn, [
        'signals'         => ['MENU'],
        'product_indexes' => $indexes,
        'menu_card'       => count($indexes) >= 2,
        'menu_card_title' => $menuTitle,
    ]);
}

/**
 * Direct human answers — always run (including WhatsApp customer_turn).
 *
 * @param array<string, mixed> $bot
 * @param array<string, mixed> $lead
 * @return array<string, mixed>|null
 */
function pipeline_try_direct_intents(
    int $leadId,
    int $botId,
    string $userMessage,
    array $bot,
    array $lead,
    bool $customerTurn
): ?array {
    require_once __DIR__ . '/conversation-intent.php';
    require_once __DIR__ . '/bot-knowledge.php';
    require_once __DIR__ . '/restaurant-menu-card.php';

    $normalized = conversation_normalize_intent_text($userMessage);
    if ($normalized === '') {
        return null;
    }

    if (conversation_is_wellbeing_question($userMessage)) {
        $reply = "I'm doing well, thanks for asking! How about you?";

        return pipeline_store_and_return($bot, $leadId, $reply, $userMessage, $customerTurn, ['signals' => ['COMMON']]);
    }

    if (conversation_is_presence_ping($userMessage)) {
        $reply = "Yeah, I'm here — what's up?";

        return pipeline_store_and_return($bot, $leadId, $reply, $userMessage, $customerTurn, ['signals' => ['COMMON']]);
    }

    if (conversation_is_identity_question($userMessage)) {
        $rep = get_bot_rep_name($bot);
        $brand = get_bot_brand_label($bot);
        $reply = "I'm {$rep} from {$brand}.";

        return pipeline_store_and_return($bot, $leadId, $reply, $userMessage, $customerTurn, ['signals' => ['COMMON']]);
    }

    require_once __DIR__ . '/catalog.php';
    if ($botId > 0 && catalog_bot_has_products($botId) && catalog_customer_says_media_missing($userMessage)) {
        $missingReply = "You're right — that didn't come through. Sending the menu now.";

        return pipeline_shop_menu_result($bot, $leadId, $botId, $userMessage, $customerTurn, $missingReply);
    }

    if ($botId > 0 && catalog_bot_has_products($botId) && catalog_message_is_menu_request($botId, $userMessage)) {
        $catalogHit = pipeline_industry_catalog_result($bot, $leadId, $botId, $userMessage, $customerTurn);
        if ($catalogHit !== null) {
            return $catalogHit;
        }
    }

    if (conversation_is_bot_frustration($userMessage)
        || conversation_is_meta_activity_question($userMessage)
        || preg_match('/\b(hello\?+|where are you|anyone there|rights now|don\'?t talk to me)\b/iu', $normalized)) {
        $rep = get_bot_rep_name($bot);
        $brand = get_bot_brand_label($bot);
        $keepGoing = !cart_is_empty($leadId)
            || catalog_has_clear_shopping_intent($userMessage)
            || catalog_message_is_browse_intent($userMessage)
            || catalog_customer_wants_other_menu($userMessage)
            || cart_user_wants_checkout($userMessage);

        if (!$keepGoing) {
            if (conversation_is_meta_activity_question($userMessage)
                && preg_match('/\b(bot|robot|ai|human|person|real)\b/iu', $normalized)) {
                $reply = "I'm {$rep} from {$brand} — right here with you.";
            } elseif (conversation_is_location_question($userMessage)
                || preg_match('/\b(where are you|anyone there)\b/iu', $normalized)) {
                $reply = "I'm here on WhatsApp for {$brand} — with you right now.";
            } elseif (conversation_is_bot_frustration($userMessage)) {
                $reply = "Sorry about that — I missed your point. What did you need?";
            } else {
                $reply = "Just here on WhatsApp — what's going on?";
            }

            return pipeline_store_and_return(
                $bot,
                $leadId,
                $reply,
                $userMessage,
                $customerTurn,
                ['signals' => ['COMMON']]
            );
        }
    }

    if (knowledge_message_is_offer_question($userMessage)) {
        if ($botId > 0 && catalog_bot_has_products($botId) && catalog_bot_is_restaurant($botId)
            && catalog_message_is_menu_request($botId, $userMessage)
        ) {
            return pipeline_shop_menu_result($bot, $leadId, $botId, $userMessage, $customerTurn);
        }

        $offerText = knowledge_offer_reply_text($bot, $userMessage, $leadId);
        $final = pipeline_finalize_text($bot, $leadId, $offerText, $userMessage, $customerTurn);
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [$leadId, $final]
        );

        return [
            'success' => true,
            'reply'   => $final,
            'signals' => ['COMMON'],
        ];
    }

    if (preg_match('/\b(thank you|thanks|shukriya)\b/u', mb_strtolower($normalized)) && mb_strlen($normalized) < 48) {
        $reply = "You're welcome! Message me anytime if you need anything else.";

        return pipeline_store_and_return($bot, $leadId, $reply, $userMessage, $customerTurn, ['signals' => ['COMMON']]);
    }

    return null;
}

/**
 * Ordered deterministic handlers — returns full get_ai_response-style array or null to continue to AI.
 *
 * @param array<string, mixed> $bot
 * @param array<string, mixed> $lead
 * @param array<string, mixed> $options
 * @return array<string, mixed>|null
 */
function pipeline_run_pre_ai(
    int $leadId,
    int $botId,
    string $userMessage,
    array $bot,
    array $lead,
    array $options = []
): ?array {
    $customerTurn = !empty($options['customer_turn']);
    $shortcutsOk = pipeline_allows_conversational_shortcuts($customerTurn, $userMessage);
    $leadWithUser = array_merge($lead, ['bot_user_id' => (int) ($bot['user_id'] ?? 0)]);

    require_once __DIR__ . '/conversation-intent.php';
    $conversionAside = conversation_message_is_conversion_aside($userMessage, $leadId, $botId);

    if (!$conversionAside) {
        $farewell = cart_handle_farewell_or_decline($leadId, $userMessage);
        if ($farewell !== null) {
            return pipeline_store_and_return($bot, $leadId, $farewell, $userMessage, $customerTurn, ['signals' => ['COMMON']]);
        }
    }

    $direct = pipeline_try_direct_intents($leadId, $botId, $userMessage, $bot, $lead, $customerTurn);
    if ($direct !== null) {
        return $direct;
    }

    if (!business_hours_is_open($botId)) {
        $blockingShop = cart_user_wants_checkout($userMessage)
            || (bool) preg_match('/\b(place order|order now|checkout)\b/iu', $userMessage);
        if ($blockingShop) {
            return pipeline_store_and_return(
                $bot,
                $leadId,
                business_hours_closed_reply($botId, $bot),
                $userMessage,
                $customerTurn,
                ['signals' => ['CLOSED']]
            );
        }
    }

    if ($shortcutsOk) {
        $greeting = conversation_try_greeting_response($bot, $leadId, $userMessage);
        if ($greeting !== null) {
            return $greeting;
        }

        $common = conversation_try_common_reply($bot, $leadId, $userMessage);
        if ($common !== null) {
            return $common;
        }
    }

    if ($shortcutsOk && !cart_checkout_in_progress($leadId) && catalog_has_clear_shopping_intent($userMessage)) {
        require_once __DIR__ . '/catalog.php';
        $catalogEarly = catalog_try_resolve_product_request($botId, $userMessage, $bot);
        if ($catalogEarly !== null) {
            $catalogReply = pipeline_finalize_text(
                $bot,
                $leadId,
                (string) ($catalogEarly['reply'] ?? ''),
                $userMessage,
                $customerTurn
            );
            db_insert(
                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                'is',
                [$leadId, $catalogReply]
            );

            return [
                'success'         => true,
                'reply'           => $catalogReply,
                'signals'         => !empty($catalogEarly['checking']) ? ['CHECKING'] : ['CATALOG'],
                'product_indexes' => $catalogEarly['indexes'] ?? [],
            ];
        }
    }

    $cartReply = cart_handle_command($leadId, $botId, $userMessage, $leadWithUser);
    if ($cartReply !== null) {
        return pipeline_store_and_return($bot, $leadId, $cartReply, $userMessage, $customerTurn, ['signals' => ['CART']]);
    }

    require_once __DIR__ . '/restaurant-menu-card.php';
    if ($botId > 0 && catalog_bot_has_products($botId) && catalog_message_is_menu_request($botId, $userMessage)) {
        $catalogHit = pipeline_industry_catalog_result($bot, $leadId, $botId, $userMessage, $customerTurn);
        if ($catalogHit !== null) {
            return $catalogHit;
        }
    }

    require_once __DIR__ . '/shipment.php';
    if (shipment_message_is_tracking_query($userMessage) || shipment_message_wants_receipt($userMessage)) {
        if (!cart_is_empty($leadId) && empty(cart_get($leadId)['anything_else_done'])) {
            $cart = cart_get($leadId);
            $item = $cart['items'][0]['name'] ?? 'your items';
            $reply = "The new order is still in your cart — *{$item}* hasn't been sent to the kitchen yet. "
                . "Say *yes* and I'll place it now.";

            return pipeline_store_and_return($bot, $leadId, $reply, $userMessage, $customerTurn, ['signals' => ['CART']]);
        }
        $trackReply = shipment_handle_customer_query($leadId, $botId, '', ['message' => $userMessage]);
        if ($trackReply !== null && !empty($trackReply['reply'])) {
            return pipeline_store_and_return($bot, $leadId, (string) $trackReply['reply'], $userMessage, $customerTurn, [
                'signals'            => ['SHIPMENT'],
                'send_receipt_image' => !empty($trackReply['send_receipt_image']),
                'shipment_id'        => $trackReply['shipment_id'] ?? null,
            ]);
        }
    }

    if ($shortcutsOk && !cart_checkout_in_progress($leadId) && catalog_has_clear_shopping_intent($userMessage)) {
        require_once __DIR__ . '/catalog.php';
        $catalogHit = catalog_try_resolve_product_request($botId, $userMessage, $bot);
        if ($catalogHit !== null) {
            $catalogReply = pipeline_finalize_text(
                $bot,
                $leadId,
                (string) ($catalogHit['reply'] ?? ''),
                $userMessage,
                $customerTurn
            );
            db_insert(
                'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
                'is',
                [$leadId, $catalogReply]
            );

            return [
                'success'         => true,
                'reply'           => $catalogReply,
                'signals'         => !empty($catalogHit['checking']) ? ['CHECKING'] : ['CATALOG'],
                'product_indexes' => $catalogHit['indexes'] ?? [],
            ];
        }
    }

    return null;
}
