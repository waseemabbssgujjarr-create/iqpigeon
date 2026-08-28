<?php
/**
 * Global WhatsApp shop UX — tappable buttons and menu lists, not typed commands.
 * Used by the conversation pipeline and turn engine for every shop / restaurant bot.
 */

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/cart.php';

function whatsapp_shop_copy_menu_intro(): string
{
    return "Here's our menu — tap an item below to add it to your cart.";
}

/**
 * Real product list for WhatsApp. Never send the intro heading alone.
 *
 * @param array<string, mixed> $bot
 */
function whatsapp_shop_copy_menu_with_items(array $bot, int $botId, string $query = ''): string
{
    if ($botId <= 0) {
        $botId = (int) ($bot['id'] ?? 0);
    }
    if ($botId <= 0) {
        return '';
    }
    require_once __DIR__ . '/whatsapp-auto-reply-core.php';
    $page = [];
    $q = trim($query);
    if ($q !== '' && function_exists('wa_webhook_search_products')
        && preg_match('/\b(burger|pizza|broast|deal|drink|dessert|chicken|wrap)\b/iu', $q)
    ) {
        $page = wa_webhook_search_products($botId, $q, 8);
    }
    if ($page === [] && function_exists('wa_webhook_products')) {
        $page = wa_webhook_products($botId, 0, 12);
    }
    if ($page === [] || !function_exists('wa_webhook_menu_text')) {
        return '';
    }

    return wa_webhook_menu_text($bot, $page);
}

function whatsapp_shop_copy_here(array $bot): string
{
    $brand = get_bot_brand_label($bot);

    return "I'm here with {$brand}! Tap below to browse the menu, or tell me what you'd like.";
}

function whatsapp_shop_copy_prices(): string
{
    return 'Happy to share prices — tap *View menu* below to see photos and prices.';
}

function whatsapp_shop_copy_offer(array $bot, int $botId): string
{
    $menu = whatsapp_shop_copy_menu_with_items($bot, $botId);
    if ($menu !== '') {
        return $menu;
    }

    return "Here's the menu — tap an item below.";
}

/**
 * Combined fragmented WhatsApp turns ("What / You / Have / Tonight / ?") should count as browse.
 */
function whatsapp_shop_customer_wants_menu(string $message, int $botId = 0): bool
{
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/conversation-router.php';

    if (preg_match('/^(the\s+)?menu[\s!?.]*$/iu', trim($message))) {
        return true;
    }

    if ($botId > 0 && function_exists('catalog_message_is_menu_request') && catalog_message_is_menu_request($botId, $message)) {
        return true;
    }

    if ($botId > 0 && catalog_menu_keyword_triggered($botId, $message)) {
        return true;
    }

    if (catalog_message_is_browse_intent($message)) {
        return true;
    }

    return conversation_route_is_explicit_menu($message);
}

/** Customer asked to see the menu / photos — not just "do you have X". */
function whatsapp_shop_customer_wants_visual_card(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match('/^(the\s+)?menu[\s!?.]*$/iu', $lower)) {
        return true;
    }

    require_once __DIR__ . '/catalog.php';
    if (function_exists('catalog_customer_says_media_missing') && catalog_customer_says_media_missing($message)) {
        return true;
    }

    return (bool) preg_match(
        '/\b(show|send|share|see|open|view|dikhao|dikha|bhejo|bhej)\b.{0,28}\b(menu|catalog|photos?|pics?|images?|card)\b|'
        . '\b(menu|catalog)\s+(please|pls|with photos)|'
        . '\b(order from menu|view menu|best ?sellers?|best items?|menu pics?|menu photos?)\b/iu',
        $lower
    );
}

function whatsapp_shop_copy_cart_empty(): string
{
    return "Your cart is empty.\n\nTap *View menu* below to browse.";
}

/**
 * Format any shop reply before WhatsApp delivery — clear sections, no run-on text.
 */
function shop_format_outgoing_message(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return $text;
    }

    $breakBefore = [
        'Added ',
        'Added:',
        '✅ Added',
        'Your cart',
        '🛒 Your cart',
        'Items:',
        'Total:',
        'Subtotal:',
        'Before I ',
        'Order #',
        'Tap an option',
        'Tap below',
        'Reply *no*',
        'Reply no',
        'COD confirmed',
        'We\'re processing',
        'Your order is',
    ];
    foreach ($breakBefore as $needle) {
        $text = preg_replace('/(?<!\n)\s*(' . preg_quote($needle, '/') . ')/u', "\n\n$1", $text) ?? $text;
    }

    $text = preg_replace('/ • /u', "\n• ", $text) ?? $text;
    $text = preg_replace('/\s+•\s+/u', "\n• ", $text) ?? $text;

    $lines = explode("\n", $text);
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            if ($clean !== [] && end($clean) !== '') {
                $clean[] = '';
            }
            continue;
        }
        $clean[] = $line;
    }

    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $clean)) ?? implode("\n", $clean));
}

/**
 * Format a short product list for text replies (global — all industries with a catalog).
 *
 * @param list<array{name: string, price?: string, price_label?: string}> $items
 */
function shop_format_product_list(array $items, string $intro = '', string $footer = ''): string
{
    if ($items === []) {
        return $intro;
    }

    $lines = [];
    if ($intro !== '') {
        $lines[] = trim($intro);
        $lines[] = '';
    }
    foreach ($items as $item) {
        $name = trim((string) ($item['name'] ?? 'Item'));
        $price = trim((string) ($item['price_label'] ?? $item['price'] ?? ''));
        $lines[] = $price !== '' ? '• ' . $name . ' — ' . $price : '• ' . $name;
    }
    if ($footer !== '') {
        $lines[] = '';
        $lines[] = trim($footer);
    }

    return implode("\n", $lines);
}

function whatsapp_shop_copy_cart_cleared(): string
{
    return "Cart cleared.\n\nTap *View menu* below whenever you're ready.";
}

/**
 * @return array<int, array{id: string, title: string}>
 */
function whatsapp_shop_nav_button_defs(int $leadId): array
{
    return [
        ['id' => 'menu', 'title' => 'View menu'],
        ['id' => 'cart', 'title' => 'My cart'],
        ['id' => 'checkout', 'title' => 'Checkout'],
    ];
}

/**
 * @return array{success: bool, message?: string}
 */
function whatsapp_shop_send_nav_buttons(int $botId, int $userId, string $phone, int $leadId, string $body = ''): array
{
    require_once __DIR__ . '/whatsapp.php';

    $creds = whatsapp_bot_credentials($botId, $userId);
    if (!$creds) {
        return ['success' => false, 'message' => 'No WhatsApp credentials'];
    }

    if ($body === '') {
        $body = $leadId > 0 && !cart_is_empty($leadId)
            ? 'Tap an option below to continue.'
            : 'Tap *View menu* to browse — no typing needed.';
    }

    return send_whatsapp_reply_buttons(
        $creds['phone_id'],
        $creds['token'],
        $phone,
        $body,
        whatsapp_shop_nav_button_defs($leadId)
    );
}

/**
 * After the text reply is delivered, attach the right interactive layer.
 *
 * @param array<string, mixed> $bot
 * @param array<string, mixed> $ai
 */
function whatsapp_shop_followup_after_reply(
    array $bot,
    int $leadId,
    string $phone,
    array $ai,
    string $reply,
    string $userMessage = ''
): void {
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/cart.php';
    require_once __DIR__ . '/restaurant-menu-card.php';
    require_once __DIR__ . '/conversation-router.php';
    require_once __DIR__ . '/helpers.php';

    $botId = (int) ($bot['id'] ?? 0);
    $userId = (int) ($bot['user_id'] ?? 0);
    if ($botId <= 0 || $userId <= 0 || $phone === '') {
        conversation_consume_shop_menu_send();

        return;
    }
    if (!catalog_bot_has_products($botId)) {
        conversation_consume_shop_menu_send();

        return;
    }

    $signals = array_map('strval', (array) ($ai['signals'] ?? []));
    $intent = $userMessage !== '' ? conversation_route_intent($userMessage) : 'other';
    $askedForCard = $userMessage !== '' && whatsapp_shop_customer_wants_visual_card($userMessage);
    $flaggedMenu = conversation_consume_shop_menu_send();

    if ($userMessage !== '' && cart_message_is_farewell_or_decline($userMessage)) {
        return;
    }
    if (in_array($intent, ['farewell', 'thanks', 'wellbeing', 'identity', 'meta', 'frustration'], true)
        && !catalog_customer_says_media_missing($userMessage)
        && !(function_exists('catalog_customer_wants_other_menu') && catalog_customer_wants_other_menu($userMessage))
        && !catalog_message_is_browse_intent($userMessage)
    ) {
        return;
    }
    if (in_array('SHIPMENT', $signals, true) || in_array('CLOSED', $signals, true)) {
        return;
    }
    if (cart_reply_implies_order_placed($reply) || cart_lead_has_open_order($leadId)) {
        if (!$askedForCard) {
            return;
        }
    }
    if (stripos($reply, 'do you need anything else') !== false) {
        return;
    }
    if (cart_assistant_asked_for_checkout_fields($leadId) || cart_should_treat_as_checkout_details($leadId, $userMessage)) {
        return;
    }
    if (cart_checkout_in_progress($leadId) && !$askedForCard && !cart_reply_includes_summary($reply)
        && !catalog_customer_says_media_missing($userMessage)
        && !(function_exists('catalog_customer_wants_other_menu') && catalog_customer_wants_other_menu($userMessage))
        && !catalog_message_is_browse_intent($userMessage)
    ) {
        return;
    }

    if (cart_reply_includes_summary($reply) && !cart_is_empty($leadId)
        && stripos($reply, 'do you need anything else') === false
    ) {
        cart_send_whatsapp_action_buttons($botId, $userId, $phone, $leadId);

        return;
    }

    require_once __DIR__ . '/conversation-intent.php';
    if (conversation_is_hours_question($userMessage) && !catalog_customer_says_media_missing($userMessage)) {
        return;
    }

    $wantsMenu = $askedForCard
        || $intent === 'menu'
        || $flaggedMenu
        || (!empty($ai['menu_card']) && $indexes !== [])
        || ($userMessage !== '' && catalog_message_is_menu_request($botId, $userMessage))
        || ($userMessage !== '' && catalog_message_is_browse_intent($userMessage) && !catalog_message_is_category_inquiry($userMessage))
        || ($userMessage !== '' && catalog_customer_wants_other_menu($userMessage))
        || catalog_customer_says_media_missing($userMessage)
        || (catalog_reply_promises_media($reply) && !catalog_message_is_category_inquiry($userMessage));

    $indexes = array_values(array_filter(array_map('intval', (array) ($ai['product_indexes'] ?? []))));
    $menuTitle = trim((string) ($ai['menu_card_title'] ?? ''));

    if (!$wantsMenu && $indexes === []) {
        return;
    }

    if (catalog_message_is_category_inquiry($userMessage)
        && !$askedForCard
        && !$flaggedMenu
        && empty($ai['menu_card'])
        && $indexes === []
    ) {
        return;
    }

    $cards = catalog_menu_cards_to_send($botId, $userMessage, $leadId, $indexes, $menuTitle);
    if ($cards === []) {
        return;
    }

    foreach ($cards as $card) {
        $cardIndexes = array_values(array_filter(array_map('intval', $card['indexes'] ?? [])));
        if ($cardIndexes === []) {
            continue;
        }
        $title = trim((string) ($card['title'] ?? ''));
        if ($title === '') {
            $title = catalog_title_for_indexes($botId, $cardIndexes);
        }
        catalog_send_product_cards($botId, $userId, $phone, $cardIndexes, true, $title);
        if (function_exists('cart_remember_shown_indexes')) {
            cart_remember_shown_indexes($leadId, $cardIndexes);
        }
    }
}
