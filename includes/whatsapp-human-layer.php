<?php
/**
 * WhatsApp HUMAN LAYER — tone, OpenAI, menu/order/cart routing.
 * Must stay fast on webhook (no platform-training.php in hot path).
 */
declare(strict_types=1);

function wa_human_layer_enabled(): bool
{
    return defined('WHATSAPP_HUMAN_LAYER_ENABLED') ? (bool) WHATSAPP_HUMAN_LAYER_ENABLED : true;
}

/**
 * Cart / checkout commands — must run before OpenAI or menu routing.
 *
 * @param array<string, mixed> $bot
 */
function wa_human_layer_try_cart_reply(array $bot, int $leadId, string $userMessage): ?string
{
    $botId = (int) ($bot['id'] ?? 0);
    if ($leadId <= 0 || $botId <= 0) {
        return null;
    }

    require_once __DIR__ . '/cart.php';
    if (catalog_products_for_bot($botId) === []) {
        return null;
    }

    $lead = db_fetch('SELECT * FROM leads WHERE id = ?', 'i', [$leadId]) ?: [];
    $lead['bot_user_id'] = (int) ($bot['user_id'] ?? 0);

    $synced = cart_sync_catalog_picks_from_lead($leadId, $botId, $lead);
    $text = trim($userMessage);
    if ($text !== '') {
        $handled = cart_handle_command($leadId, $botId, $text, $lead);
        if ($handled !== null && trim($handled) !== '') {
            return trim($handled);
        }
    }

    if ($synced !== null && trim($synced) !== '') {
        return trim($synced);
    }

    return null;
}

/** Bot offer text without loading catalog.php (webhook-safe). */
function wa_human_webhook_offer_line(array $bot): string
{
    $model = trim((string) ($bot['business_model'] ?? ''));
    $model = trim((string) (preg_replace('/\[[^\]]+\]/', '', $model) ?? $model));
    $model = trim((string) (preg_replace('/\s{2,}/u', ' ', $model) ?? $model));
    if ($model !== '') {
        return mb_substr($model, 0, 380);
    }

    $corpus = trim((string) ($bot['bot_knowledge'] ?? ''));
    $corpus = trim((string) (preg_replace('/\[[^\]]+\]/', '', $corpus) ?? $corpus));
    if ($corpus === '') {
        return '';
    }

    return mb_substr($corpus, 0, 280);
}

/**
 * Lightweight system prompt — bot knowledge + rep identity, no platform-training.php.
 *
 * @param array<string, mixed> $bot
 */
function wa_human_lite_system_prompt(array $bot, int $leadId, string $userMessage): string
{
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/human-agent-prompt.php';
    require_once __DIR__ . '/bot-knowledge.php';

    $company = trim((string) ($bot['company_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Business')));
    $rep = function_exists('get_bot_rep_name') ? get_bot_rep_name($bot) : 'Assistant';
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : $company;
    $botId = (int) ($bot['id'] ?? 0);

    $lines = [
        trim(human_agent_live_protocol()),
        function_exists('human_agent_identity_lock') ? trim(human_agent_identity_lock($bot, $company)) : '',
        "You are {$rep} from {$brand} ({$company}). Reply on WhatsApp like a real human.",
        'Never say you are an AI, bot, or assistant.',
        'Chat like a friend when they are not buying. Do not send a menu or catalogue unless they asked for it or want to order.',
        'Answer what they asked first. Use the chat history. Match their language (including Urdu / Roman Urdu).',
        'Never say "tell me the dish or say menu" if they already asked for the menu — send/offer the menu.',
        'Never repeat the same question or the same fallback line. Never say a teammate will get back to them.',
        'Do not invent prices. If you do not have a price, ask which item they mean or offer the menu.',
        'Take the order when they want to buy. Items from different menus stay in ONE cart / ONE order.',
        'If they asked what you can do: take orders, send the menu, answer questions, help them check out.',
    ];

    $webhook = !empty($GLOBALS['wa_webhook_budget']);

    if ($webhook) {
        $offer = wa_human_webhook_offer_line($bot);
        if ($offer !== '') {
            $lines[] = 'What we offer: ' . $offer;
        }
        $notes = trim((string) ($bot['bot_knowledge'] ?? ''));
        if ($notes !== '') {
            $lines[] = 'Notes: ' . mb_substr(preg_replace('/\[[^\]]+\]/', '', $notes) ?? $notes, 0, 700);
        }
        require_once __DIR__ . '/conversation-mind.php';
        $lifeFacts = conversation_mind_personal_facts($bot);
        if ($lifeFacts !== []) {
            $lines[] = 'Personal facts to mention naturally (never quote or dump as a document): ' . implode('; ', $lifeFacts);
        }
    } else {
        $offer = knowledge_short_offer_line($bot, $userMessage);
        if ($offer !== '' && !knowledge_text_has_unresolved_placeholders($offer)) {
            $lines[] = 'What we offer: ' . $offer;
        }

        if ($botId > 0) {
            require_once __DIR__ . '/catalog.php';
            if (catalog_bot_has_products($botId)) {
                $search = '';
                if (function_exists('catalog_runtime_search_block')) {
                    $search = trim(catalog_runtime_search_block($botId, $userMessage));
                }
                if ($search !== '') {
                    $lines[] = mb_substr($search, 0, 1200);
                } else {
                    $indexes = catalog_auto_product_indexes($botId, $userMessage, []);
                    if ($indexes !== []) {
                        $lines[] = 'Menu highlights (catalog numbers): ' . implode(', ', array_slice($indexes, 0, 8));
                    }
                }
                $lines[] = 'Customer can browse menus and place one combined order in this chat. Items from different menus stay in the SAME cart.';
            }
        }
    }

    if ($leadId > 0) {
        if (!$webhook) {
            require_once __DIR__ . '/cart.php';
            $cartBlock = function_exists('cart_ai_context_block') ? trim(cart_ai_context_block($leadId, $botId)) : '';
            if ($cartBlock !== '') {
                $lines[] = mb_substr($cartBlock, 0, 1200);
            } elseif (!cart_is_empty($leadId)) {
                $lines[] = 'Customer has items in cart — help them add more from any menu or checkout as one order.';
            }
            if (function_exists('catalog_recent_menu_titles')) {
                require_once __DIR__ . '/restaurant-menu-card.php';
                $titles = catalog_recent_menu_titles($leadId);
                if ($titles !== []) {
                    $lines[] = 'Menus already sent: ' . implode(', ', array_slice($titles, 0, 6));
                }
            }
        }
        $lead = db_fetch('SELECT name, qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
        if ($lead) {
            $name = trim((string) ($lead['name'] ?? ''));
            if ($name !== '' && !preg_match('/^whatsapp/i', $name)) {
                $lines[] = 'Customer name: ' . $name;
            }
        }
    }

    $lines[] = human_agent_universal_turn_hint($userMessage, $leadId);

    $lines = array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));

    return implode("\n", $lines);
}

/**
 * Fast OpenAI reply (~1–3s) — lite prompt only.
 *
 * @param array<string, mixed> $bot
 */
function wa_human_openai_reply(int $leadId, array $bot, string $userMessage, int $turnId = 0): ?string
{
    if (trim($userMessage) === '') {
        return null;
    }

    require_once __DIR__ . '/integration-settings.php';
    if (integration_openai_chat_key() === '') {
        return null;
    }

    require_once __DIR__ . '/openai.php';
    require_once __DIR__ . '/human-agent-prompt.php';

    $system = wa_human_lite_system_prompt($bot, $leadId, $userMessage);

    $history = db_fetch_all(
        'SELECT role, message FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT 12',
        'i',
        [$leadId]
    ) ?: [];
    $history = array_reverse($history);

    $messages = [['role' => 'system', 'content' => mb_substr($system, 0, 8000)]];
    $userTrim = mb_substr($userMessage, 0, 1500);
    foreach ($history as $row) {
        $role = (string) ($row['role'] ?? '');
        if ($role === 'system') {
            continue;
        }
        $content = mb_substr((string) ($row['message'] ?? ''), 0, 600);
        if ($content === '') {
            continue;
        }
        if ($role !== 'assistant' && $content === $userTrim) {
            continue;
        }
        $messages[] = [
            'role'    => $role === 'assistant' ? 'assistant' : 'user',
            'content' => $content,
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $userTrim !== '' ? $userTrim : '[Customer sent a message]'];

    $timeout = !empty($GLOBALS['wa_webhook_budget']) ? 5 : 6;
    $result = openai_chat($messages, [
        'timeout'      => $timeout,
        'max_attempts' => 1,
        'max_tokens'   => 280,
        'temperature'  => 0.4,
    ]);

    if (!empty($result['success']) && trim((string) ($result['content'] ?? '')) !== '') {
        $draft = trim((string) $result['content']);
        if (!human_agent_is_robotic_reply($draft) && !human_agent_is_bad_fallback($draft)) {
            if (!empty($GLOBALS['wa_webhook_budget'])) {
                require_once __DIR__ . '/helpers.php';

                return conversation_sanitize_customer_facing($draft);
            }

            return human_agent_finalize_reply($bot, $leadId, $draft, $userMessage);
        }
    }

    if ($turnId > 0 && function_exists('turn_engine_log_event')) {
        turn_engine_log_event($turnId, 'AI_GENERATION_FAILED', [
            'path'  => 'human_openai_lite',
            'error' => (string) ($result['error'] ?? 'empty_or_robotic'),
        ]);
    }

    return null;
}

/**
 * Menu / order / cart / location routing without OpenAI.
 *
 * @param array<string, mixed> $bot
 */
function wa_human_layer_routed_text(array $bot, int $leadId, string $userMessage): ?string
{
    if (trim($userMessage) === '') {
        return null;
    }

    try {
        $cartReply = wa_human_layer_try_cart_reply($bot, $leadId, $userMessage);
        if ($cartReply !== null && trim($cartReply) !== '') {
            return trim($cartReply);
        }

        require_once __DIR__ . '/human-agent-prompt.php';

        if (function_exists('turn_engine_should_route_before_ai') && turn_engine_should_route_before_ai($userMessage)) {
            $routed = human_agent_warm_last_resort($bot, $userMessage, $leadId);
            if (trim($routed) !== '' && !wa_human_is_generic_core_line($routed, $bot)) {
                return trim($routed);
            }
        }

        if (!function_exists('human_agent_warm_last_resort')) {
            return null;
        }

        $msg = mb_strtolower(trim($userMessage));
        $needsRoute = (bool) preg_match(
            '/\b(menu|order|price|offer|catalog|cart|checkout|where are you|what are you doing|'
            . 'what you have|what do you|delivery|address|location|hours|open|food|hungry|khana|eat)\b/u',
            $msg
        );

        if (!$needsRoute) {
            return null;
        }

        $routed = human_agent_warm_last_resort($bot, $userMessage, $leadId);
        if (trim($routed) === '' || wa_human_is_generic_core_line($routed, $bot)) {
            return null;
        }

        return trim($routed);
    } catch (Throwable $e) {
        error_log('wa_human_layer_routed_text: ' . $e->getMessage());

        return null;
    }
}

function wa_human_is_generic_core_line(string $reply, array $bot): bool
{
    require_once __DIR__ . '/human-agent-prompt.php';

    $lower = mb_strtolower(trim($reply));

    if (preg_match('/^got it — i\'m here to help\. what would you like to know/u', $lower)) {
        return true;
    }
    if (preg_match('/^hi! thanks for messaging .+ — how can i help you today/u', $lower)) {
        return true;
    }
    if (preg_match('/^happy to help — tell me what you\'re looking for/u', $lower)) {
        return true;
    }

    return human_agent_is_robotic_reply($reply) || human_agent_is_bad_fallback($reply);
}

/**
 * @param array<string, mixed> $bot
 */
function wa_human_layer_enhance_reply(array $bot, int $leadId, string $userMessage, int $turnId = 0): ?string
{
    if (!wa_human_layer_enabled() || trim($userMessage) === '') {
        return null;
    }

    $routed = wa_human_layer_routed_text($bot, $leadId, $userMessage);
    if ($routed !== null) {
        return $routed;
    }

    return wa_human_openai_reply($leadId, $bot, $userMessage, $turnId);
}

/**
 * @param array<string, mixed> $bot
 */
function wa_human_warm_reply(array $bot, int $leadId, string $userMessage): ?string
{
    try {
        require_once __DIR__ . '/human-agent-prompt.php';
        if (!function_exists('human_agent_warm_last_resort')) {
            return null;
        }
        $warm = human_agent_warm_last_resort($bot, $userMessage !== '' ? $userMessage : 'Hi', $leadId);
        if (trim($warm) === '' || wa_human_is_generic_core_line($warm, $bot)) {
            return null;
        }

        return trim($warm);
    } catch (Throwable $e) {
        error_log('wa_human_warm_reply: ' . $e->getMessage());

        return null;
    }
}

/**
 * @param array<string, mixed> $bot
 */
function wa_human_layer_after_send(
    array $bot,
    int $leadId,
    string $senderPhone,
    string $userMessage,
    string $reply,
    string $phoneId,
    string $token
): void {
    if (!wa_human_layer_enabled()) {
        return;
    }

    $looksOrder = (bool) preg_match(
        '/(?:add\s+#?\d+|\bcheckout\b|\bcart\b|#\d+|\b(i want|i\'ll take|give me|mujhe)\b)/iu',
        $userMessage
    );
    $looksMenu = conversation_shop_menu_send_flagged()
        || (bool) preg_match('/\b(menu|items?|catalog)\b/u', mb_strtolower($userMessage))
        || (bool) preg_match('/\b(show|send|see)\b.{0,24}\bmenu\b/iu', $userMessage);

    if ($looksOrder) {
        try {
            require_once __DIR__ . '/cart.php';
            if (function_exists('cart_message_catalog_pick_index') && cart_message_catalog_pick_index($userMessage) !== null) {
                $looksOrder = true;
            }
            $cartReply = wa_human_layer_try_cart_reply($bot, $leadId, $userMessage);
            if ($cartReply !== null && trim($cartReply) !== ''
                && mb_strtolower(trim($cartReply)) !== mb_strtolower(trim($reply))
                && $phoneId !== '' && $token !== '' && $senderPhone !== ''
                && function_exists('wa_recover_send_whatsapp')
            ) {
                wa_recover_send_whatsapp($phoneId, $token, $senderPhone, trim($cartReply));
                $reply = trim($cartReply);
            }
        } catch (Throwable $e) {
            error_log('wa_human_layer_after_send cart: ' . $e->getMessage());
        }
    }

    if (!$looksMenu) {
        return;
    }

    require_once __DIR__ . '/whatsapp-shop-ux.php';
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/cart.php';

    if (!function_exists('whatsapp_shop_followup_after_reply')) {
        return;
    }

    if (cart_reply_includes_summary($reply) && !cart_is_empty($leadId)) {
        $botId = (int) ($bot['id'] ?? 0);
        $userId = (int) ($bot['user_id'] ?? 0);
        if ($botId > 0 && $userId > 0 && $senderPhone !== '') {
            cart_send_whatsapp_action_buttons($botId, $userId, $senderPhone, $leadId);
        }
    }

    $skipMenuFollowup = $userMessage !== '' && (
        cart_message_catalog_pick_index($userMessage) !== null
        || preg_match('/^add\s+#\d+/iu', trim($userMessage))
        || (function_exists('cart_message_is_order_process_question') && cart_message_is_order_process_question($userMessage))
        || str_contains($reply, '✅ Added')
    );

    if ($skipMenuFollowup && !cart_reply_includes_summary($reply)) {
        return;
    }

    if ($skipMenuFollowup) {
        return;
    }

    if (!whatsapp_shop_customer_wants_visual_card($userMessage) && !conversation_shop_menu_send_flagged()) {
        return;
    }

    whatsapp_shop_followup_after_reply(
        $bot,
        $leadId,
        $senderPhone,
        ['product_indexes' => catalog_auto_product_indexes((int) ($bot['id'] ?? 0), $userMessage, [])],
        $reply,
        $userMessage
    );
}
