<?php
/**
 * WhatsApp shop cart — stored on lead qualification_data.shop_cart
 */

require_once __DIR__ . '/catalog.php';

/**
 * @return array<string, mixed>
 */
function cart_lead_data(int $leadId): array
{
    $row = db_fetch('SELECT qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
    if (empty($row['qualification_data'])) {
        return [];
    }
    $data = json_decode($row['qualification_data'], true);
    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $data
 */
function cart_save_lead_data(int $leadId, array $data): void
{
    db_execute(
        'UPDATE leads SET qualification_data = ? WHERE id = ?',
        'si',
        [json_encode($data, JSON_UNESCAPED_UNICODE), $leadId]
    );
}

/**
 * @return array{items: array<int, array<string, mixed>>, customer_name: string, customer_phone: string, shipping_address: string, cod_confirmed: bool}
 */
function cart_get(int $leadId): array
{
    $data = cart_lead_data($leadId);
    $cart = $data['shop_cart'] ?? [];
    if (!is_array($cart)) {
        $cart = [];
    }

    return [
        'items'                  => is_array($cart['items'] ?? null) ? $cart['items'] : [],
        'customer_name'          => trim((string) ($cart['customer_name'] ?? '')),
        'customer_phone'         => trim((string) ($cart['customer_phone'] ?? '')),
        'shipping_address'       => trim((string) ($cart['shipping_address'] ?? '')),
        'cod_confirmed'          => !empty($cart['cod_confirmed']),
        'promo_code'             => strtoupper(trim((string) ($cart['promo_code'] ?? ''))),
        'discount_amount'        => max(0, (float) ($cart['discount_amount'] ?? 0)),
        'anything_else_offered'  => !empty($cart['anything_else_offered']),
        'anything_else_done'     => !empty($cart['anything_else_done']),
        'shown_indexes'          => array_values(array_filter(array_map('intval', (array) ($cart['shown_indexes'] ?? [])))),
    ];
}

/**
 * @param array<string, mixed> $cart
 */
function cart_save(int $leadId, array $cart): void
{
    $data = cart_lead_data($leadId);
    $cart['updated_at'] = date('c');
    $data['shop_cart'] = $cart;
    cart_save_lead_data($leadId, $data);

    if (file_exists(__DIR__ . '/abandoned-cart.php')) {
        require_once __DIR__ . '/abandoned-cart.php';
        abandoned_cart_reset($leadId);
    }
}

function cart_clear(int $leadId): void
{
    $data = cart_lead_data($leadId);
    unset($data['shop_cart']);
    cart_save_lead_data($leadId, $data);
}

function cart_is_empty(int $leadId): bool
{
    return cart_get($leadId)['items'] === [];
}

/**
 * Last menu card's catalog indexes (1-based global), so typing "2" adds that card's 2nd item.
 *
 * @param list<int> $indexes
 */
function cart_remember_shown_indexes(int $leadId, array $indexes): void
{
    if ($leadId <= 0) {
        return;
    }
    $indexes = array_values(array_filter(array_map('intval', $indexes), static fn ($n) => $n > 0));
    if ($indexes === []) {
        return;
    }
    $cart = cart_get($leadId);
    $cart['shown_indexes'] = $indexes;
    cart_save($leadId, $cart);
}

function cart_resolve_shown_index(int $leadId, int $pick): int
{
    if ($pick < 1) {
        return $pick;
    }
    $shown = cart_get($leadId)['shown_indexes'] ?? [];
    if (!is_array($shown) || $shown === []) {
        return $pick;
    }
    $shown = array_values(array_filter(array_map('intval', $shown), static fn ($n) => $n > 0));
    if (in_array($pick, $shown, true)) {
        return $pick;
    }
    if ($pick <= count($shown)) {
        return (int) $shown[$pick - 1];
    }

    return $pick;
}

function cart_total(array $cart): float
{
    return cart_grand_total($cart);
}

function cart_subtotal(array $cart): float
{
    $total = 0.0;
    foreach ($cart['items'] as $item) {
        $total += (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 1);
    }
    return $total;
}

function cart_discount(array $cart): float
{
    return max(0, (float) ($cart['discount_amount'] ?? 0));
}

function cart_grand_total(array $cart): float
{
    return max(0, cart_subtotal($cart) - cart_discount($cart));
}

function cart_currency(array $cart): string
{
    foreach ($cart['items'] as $item) {
        if (!empty($item['currency'])) {
            return strtoupper((string) $item['currency']);
        }
    }
    return default_currency();
}

function cart_format_summary(int $leadId): string
{
    $cart = cart_get($leadId);
    if ($cart['items'] === []) {
        require_once __DIR__ . '/whatsapp-shop-ux.php';

        return whatsapp_shop_copy_cart_empty();
    }

    $lines = ['🛒 *Your cart*', ''];
    foreach ($cart['items'] as $item) {
        $qty = (int) ($item['quantity'] ?? 1);
        $price = (float) ($item['unit_price'] ?? 0);
        $cur = (string) ($item['currency'] ?? 'PKR');
        $lines[] = '• ' . ($item['name'] ?? 'Item') . '  ×' . $qty;
        $lines[] = '  ' . catalog_format_price($price * $qty, $cur);
    }
    $lines[] = '';
    $subtotal = cart_subtotal($cart);
    $discount = cart_discount($cart);
    if ($discount > 0) {
        $lines[] = 'Subtotal: ' . catalog_format_price($subtotal, cart_currency($cart));
        $lines[] = 'Discount (' . ($cart['promo_code'] ?? '') . '): −' . catalog_format_price($discount, cart_currency($cart));
        $lines[] = '';
    }
    $lines[] = '*Total:* ' . catalog_format_price(cart_grand_total($cart), cart_currency($cart)) . '  (COD)';
    $lines[] = '';
    $lines[] = 'Tap an option below to checkout or keep browsing.';

    return implode("\n", $lines);
}

/** True when an assistant reply is a cart summary (add-to-cart or *cart* command). */
function cart_reply_includes_summary(string $reply): bool
{
    $reply = trim($reply);

    return $reply !== ''
        && (str_contains($reply, '*Your cart*') || str_contains($reply, 'Your cart') || preg_match('/^Your cart is empty\b/im', $reply) === 1);
}

/** Most recent assistant message for this lead (empty if none). */
function cart_assistant_last_reply(int $leadId): string
{
    $row = db_fetch(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );

    return trim((string) ($row['message'] ?? ''));
}

/**
 * Send WhatsApp reply buttons after a cart summary (Checkout / View menu / Clear cart).
 *
 * @return array{success: bool, message?: string}
 */
function cart_send_whatsapp_action_buttons(int $botId, int $userId, string $phone, int $leadId): array
{
    require_once __DIR__ . '/whatsapp.php';

    if (cart_is_empty($leadId)) {
        return ['success' => false, 'message' => 'Cart empty'];
    }

    $creds = whatsapp_bot_credentials($botId, $userId);
    if (!$creds) {
        return ['success' => false, 'message' => 'No WhatsApp credentials'];
    }

    $cart = cart_get($leadId);
    $totalLine = catalog_format_price(cart_grand_total($cart), cart_currency($cart));

    return send_whatsapp_reply_buttons(
        $creds['phone_id'],
        $creds['token'],
        $phone,
        'Total: ' . $totalLine . ' (COD) — tap an option below.',
        [
            ['id' => 'checkout', 'title' => 'Checkout'],
            ['id' => 'clear_cart', 'title' => 'Clear cart'],
        ]
    );
}

/**
 * @return array<string, mixed>|null
 */
function cart_product_by_index(int $botId, int $index): ?array
{
    $products = catalog_products_for_bot($botId);
    $idx = $index - 1;
    return $products[$idx] ?? null;
}

function cart_add_product(int $leadId, int $botId, int $productIndex, int $qty = 1): string
{
    $product = cart_product_by_index($botId, $productIndex);
    if (!$product) {
        return 'Product #' . $productIndex . ' not found. Ask for the catalog or type *cart*.';
    }

    $qty = max(1, min(99, $qty));
    $cart = cart_get($leadId);
    $found = false;

    foreach ($cart['items'] as &$item) {
        if ((int) ($item['product_id'] ?? 0) === (int) $product['id']) {
            $item['quantity'] = (int) ($item['quantity'] ?? 1) + $qty;
            $found = true;
            break;
        }
    }
    unset($item);

    if (!$found) {
        $cart['items'][] = [
            'product_id'  => (int) $product['id'],
            'name'        => (string) $product['name'],
            'quantity'    => $qty,
            'unit_price'  => (float) $product['price'],
            'currency'    => (string) ($product['currency'] ?? 'PKR'),
        ];
    }

    $cart['anything_else_offered'] = false;
    $cart['anything_else_done'] = false;
    cart_save($leadId, $cart);

    $priceLine = catalog_format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'PKR'));
    $out = '✅ Added *' . $product['name'] . '*  ×' . $qty . ' — ' . $priceLine . "\n\n" . cart_format_summary($leadId);
    $fresh = cart_get($leadId);
    if ($fresh['customer_name'] !== '' && $fresh['shipping_address'] !== '') {
        cart_update_checkout($leadId, ['anything_else_offered' => true, 'anything_else_done' => false]);

        return $out . "\n\n" . cart_anything_else_question($leadId);
    }

    return $out;
}

function cart_remove_line(int $leadId, int $lineIndex): string
{
    $cart = cart_get($leadId);
    $idx = $lineIndex - 1;
    if (!isset($cart['items'][$idx])) {
        return 'No item #' . $lineIndex . ' in cart.';
    }
    $name = $cart['items'][$idx]['name'] ?? 'Item';
    array_splice($cart['items'], $idx, 1);
    cart_save($leadId, $cart);

    return 'Removed *' . $name . "*.\n\n" . cart_format_summary($leadId);
}

/**
 * Merge checkout fields from AI / conversation into cart.
 *
 * @param array<string, mixed> $fields
 */
function cart_update_checkout(int $leadId, array $fields): void
{
    $cart = cart_get($leadId);
    if (!empty($fields['customer_name'])) {
        $cart['customer_name'] = trim((string) $fields['customer_name']);
    }
    if (!empty($fields['customer_phone'])) {
        $cart['customer_phone'] = trim((string) $fields['customer_phone']);
    }
    if (!empty($fields['shipping_address'])) {
        $cart['shipping_address'] = trim((string) $fields['shipping_address']);
    }
    if (!empty($fields['cod_confirmed'])) {
        $cart['cod_confirmed'] = true;
    }
    if (array_key_exists('anything_else_offered', $fields)) {
        $cart['anything_else_offered'] = !empty($fields['anything_else_offered']);
    }
    if (array_key_exists('anything_else_done', $fields)) {
        $cart['anything_else_done'] = !empty($fields['anything_else_done']);
    }
    cart_save($leadId, $cart);
}

/**
 * Parse hidden order payload from AI: [ORDER:name|phone|address|cod]
 */
function cart_parse_order_tag(string $text): array
{
    if (!preg_match('/\[ORDER:([^|\]]*)\|([^|\]]*)\|([^|\]]*)\|(yes|no|1|0)\]/i', $text, $m)) {
        return [];
    }
    $out = [];
    $name = trim($m[1]);
    $phone = trim($m[2]);
    $address = trim($m[3]);
    if ($name !== '') {
        $out['customer_name'] = $name;
    }
    if ($phone !== '') {
        $out['customer_phone'] = $phone;
    }
    if ($address !== '') {
        $out['shipping_address'] = $address;
    }
    if (in_array(strtolower($m[4]), ['yes', '1', 'true'], true)) {
        $out['cod_confirmed'] = true;
    }
    return $out;
}

/**
 * Parse free-form name / phone / address from one WhatsApp message.
 *
 * @return array<string, mixed>
 */
function cart_parse_delivery_blob(string $text): array
{
    $text = trim($text);
    $updates = [];
    if ($text === '') {
        return $updates;
    }

    $working = $text;
    if (preg_match('/(\+?92[\s-]?)?(0?3\d{2}[\s-]?\d{7}|\d{11})/', $working, $m)) {
        $digits = preg_replace('/\D/', '', $m[0]) ?? '';
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            $updates['customer_phone'] = $digits;
            $working = trim(str_replace($m[0], ' ', $working));
        }
    }

    $working = trim(preg_replace('/\s{2,}/u', ' ', $working) ?? $working);
    if ($working !== '' && cart_text_is_not_a_person_name($working) && empty($updates['customer_phone'])) {
        return $updates;
    }

    $hasAddressHint = (bool) preg_match(
        '/street|road|block|phase|house|flat|plot|near|office|society|town|dha|bahria|gulberg|'
        . 'multan|karachi|lahore|islamabad|rawalpindi|delivery|bosan|chenab|innovista|cantt|area|city/iu',
        $working
    );

    if (preg_match('/^(?:name|naam)\s*:?\s*(.+)$/imu', $working, $m)) {
        $updates['customer_name'] = trim($m[1]);
    } elseif (preg_match('/^(?:address|delivery)\s*:?\s*(.+)$/imu', $working, $m)) {
        $updates['shipping_address'] = trim($m[1]);
    } elseif ($hasAddressHint) {
        $updates['shipping_address'] = $working;
    } elseif (preg_match('/^[A-Za-z][A-Za-z\s\'.-]{2,40}$/u', $working)) {
        if (!cart_message_is_farewell_or_decline($working) && !cart_text_is_not_a_person_name($working)) {
            $updates['customer_name'] = $working;
        }
    } elseif (isset($updates['customer_phone']) && preg_match('/^[A-Za-z][A-Za-z\s\'.-]{2,60}$/u', $working)) {
        if (!cart_text_is_not_a_person_name($working)) {
            $updates['customer_name'] = $working;
        }
    }

    return $updates;
}

function cart_text_is_not_a_person_name(string $text): bool
{
    $lower = mb_strtolower(trim($text));
    if ($lower === '' || mb_strlen($lower) < 3) {
        return true;
    }

    if (function_exists('message_is_simple_greeting') && message_is_simple_greeting($text)) {
        return true;
    }

    return (bool) preg_match(
        '/^(hi+|hii+|hey+|hello|ok|okay|yes|yeah|yep|no|nope|nothing|thanks?|hmm+|sure|please)$/iu',
        $lower
    );
}

function cart_user_checkout_frustration(string $message): bool
{
    $text = trim($message);
    if ($text === '') {
        return false;
    }

    require_once __DIR__ . '/catalog.php';
    if (catalog_message_is_browse_intent($text)
        || catalog_message_is_menu_request(0, $text)
        || (function_exists('catalog_customer_wants_other_menu') && catalog_customer_wants_other_menu($text))
    ) {
        return false;
    }

    return (bool) preg_match(
        '/already sent|i sent|sent it| gave you|shared already|told you|same details|repeat/i',
        $text
    );
}

function cart_message_is_farewell_or_decline(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match(
        '/^(okay?|ok)?\s*(bye|goodbye|good night|goodnight|see you|talk later|gn|g\.n\.|allah hafiz|khuda hafiz)\b/u',
        $lower
    )) {
        return true;
    }

    if (preg_match('/\b(good night|goodnight)\b/u', $lower) && mb_strlen($lower) < 40) {
        return true;
    }

    if (preg_match('/^(ni|nahi|no)\s+(chahye|chahiye|chahie)/u', $lower)) {
        return true;
    }

    if (preg_match('/\b(don\'?t want|not interested|cancel order|leave it|forget it|no thanks)\b/u', $lower)) {
        return true;
    }

    return false;
}

function cart_message_is_order_decline(string $message): bool
{
    $lower = mb_strtolower(trim($message));

    return (bool) preg_match('/^(ni|nahi|no)\s+(chahye|chahiye|chahie)/u', $lower)
        || (bool) preg_match('/\b(don\'?t want|not interested|cancel order|leave it|forget it)\b/u', $lower);
}

function cart_handle_farewell_or_decline(int $leadId, string $message): ?string
{
    if (!cart_message_is_farewell_or_decline($message)) {
        return null;
    }

    if (cart_message_is_order_decline($message) && !cart_is_empty($leadId)) {
        cart_clear($leadId);

        return "No problem — I've cleared your cart.\n\nMessage us anytime when you're ready to order.";
    }

    return "Good night! Message us anytime when you're ready.\n\nWe're here whenever you need us.";
}

/**
 * Pull checkout fields from past user turns (name / address / phone blocks).
 *
 * @param array<int, array<string, mixed>> $messages
 */
function cart_hydrate_checkout_from_history(int $leadId, array $messages): void
{
    foreach ($messages as $row) {
        if (($row['role'] ?? '') !== 'user') {
            continue;
        }
        $text = trim((string) ($row['message'] ?? ''));
        if ($text === '') {
            continue;
        }

        cart_merge_hints_from_text($leadId, $text);
        cart_update_checkout($leadId, cart_parse_delivery_blob($text));

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: [])));
        if (count($lines) >= 1 && preg_match('/^(checkout|place order|order confirm|confirm order)$/iu', $lines[0])) {
            array_shift($lines);
        }
        if (count($lines) < 2) {
            if (count($lines) === 1) {
                cart_update_checkout($leadId, cart_parse_delivery_blob($lines[0]));
            }
            continue;
        }

        $updates = [];
        $lastLine = $lines[count($lines) - 1];
        $digits = preg_replace('/\D/', '', $lastLine);
        if ($digits !== null && strlen($digits) >= 10 && strlen($digits) <= 15) {
            $updates['customer_phone'] = $digits;
            array_pop($lines);
        }

        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:name|naam)\s*:?\s*(.+)$/iu', $line, $m)) {
                $updates['customer_name'] = trim($m[1]);
                unset($lines[$i]);
            }
            if (preg_match('/^(?:address|delivery|street)\s*:?\s*(.+)$/iu', $line, $m)) {
                $updates['shipping_address'] = trim($m[1]);
                unset($lines[$i]);
            }
        }
        $lines = array_values($lines);

        if (count($lines) >= 2 && empty($updates['shipping_address'])) {
            $addressLine = $lines[1];
            if (preg_match('/street|road|block|phase|shop|near|multan|karachi|lahore|islamabad|delivery|house|flat|plot/i', $addressLine)) {
                $updates['shipping_address'] = $addressLine;
                if (empty($updates['customer_name'])) {
                    $updates['customer_name'] = $lines[0];
                }
            } elseif (count($lines) >= 2 && mb_strlen($lines[0]) >= 2 && mb_strlen($addressLine) >= 8) {
                $updates['customer_name'] = $lines[0];
                $updates['shipping_address'] = count($lines) > 2
                    ? implode(', ', array_slice($lines, 1))
                    : $addressLine;
            }
        }

        if ($updates !== []) {
            cart_update_checkout($leadId, $updates);
        }
    }
}

function cart_hydrate_from_recent_messages(int $leadId, int $limit = 8): void
{
    $rows = db_fetch_all(
        'SELECT role, message FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT ?',
        'ii',
        [$leadId, max(1, min(20, $limit))]
    );
    if ($rows === []) {
        return;
    }
    cart_hydrate_checkout_from_history($leadId, array_reverse($rows));
}

function cart_message_is_shopping_intent(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    return (bool) preg_match(
        '/order|buy|purchase|cart|checkout|perfume|product|catalog|add\b|same again|one more|another|price|stock|delivery|cod|shop|item|#\\d+/iu',
        $lower
    );
}

function cart_reopen_lead_shopping(int $leadId): void
{
    db_execute(
        'UPDATE leads SET status = \'in_progress\' WHERE id = ? AND status = \'booked\'',
        'i',
        [$leadId]
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function cart_last_order_snapshot(int $leadId): array
{
    $data = cart_lead_data($leadId);
    $items = $data['last_order'] ?? [];
    return is_array($items) ? $items : [];
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function cart_save_last_order_snapshot(int $leadId, int $botId, array $items): void
{
    $products = catalog_products_for_bot($botId);
    $snapshot = [];

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $index = 0;
        foreach ($products as $i => $product) {
            if ((int) ($product['id'] ?? 0) === $productId) {
                $index = $i + 1;
                break;
            }
        }
        if ($index === 0 && $productId === 0) {
            foreach ($products as $i => $product) {
                $name = mb_strtolower((string) ($product['name'] ?? ''));
                $hint = mb_strtolower((string) ($item['name'] ?? ''));
                if ($name !== '' && ($name === $hint || str_contains($name, $hint) || str_contains($hint, $name))) {
                    $index = $i + 1;
                    $productId = (int) ($product['id'] ?? 0);
                    break;
                }
            }
        }

        $snapshot[] = [
            'product_index' => $index,
            'product_id'    => $productId,
            'name'          => (string) ($item['name'] ?? ''),
            'quantity'      => (int) ($item['quantity'] ?? 1),
        ];
    }

    $data = cart_lead_data($leadId);
    $data['last_order'] = $snapshot;
    cart_save_lead_data($leadId, $data);
}

function catalog_whatsapp_list_block(int $botId, int $limit = 8): string
{
    $products = catalog_products_for_bot($botId);
    if ($products === []) {
        return 'Browse our catalog and tell me what you would like.';
    }

    $lines = ['Here are our products — tap an item from the list we send next:'];
    foreach (array_slice($products, 0, max(1, min(20, $limit))) as $i => $product) {
        $lines[] = ($i + 1) . '. ' . catalog_product_summary_for_ai($product);
    }

    return implode("\n", $lines);
}

function cart_increase_last_line_qty(int $leadId, int $amount = 1, int $botId = 0): string
{
    $cart = cart_get($leadId);
    if ($cart['items'] === []) {
        return $botId > 0 ? catalog_whatsapp_list_block($botId) : 'Your cart is empty — tell me which product you want.';
    }

    $amount = max(1, min(99, $amount));
    $idx = count($cart['items']) - 1;
    $cart['items'][$idx]['quantity'] = (int) ($cart['items'][$idx]['quantity'] ?? 1) + $amount;
    cart_save($leadId, $cart);

    $name = (string) ($cart['items'][$idx]['name'] ?? 'Item');
    return 'Added ' . $amount . ' more *' . $name . "*.\n\n" . cart_format_summary($leadId);
}

function cart_add_repeat_last_item(int $leadId, int $botId): string
{
    cart_reopen_lead_shopping($leadId);

    $snapshot = cart_last_order_snapshot($leadId);
    if ($snapshot !== []) {
        $first = $snapshot[0];
        $index = (int) ($first['product_index'] ?? 0);
        $qty = max(1, (int) ($first['quantity'] ?? 1));
        if ($index > 0) {
            return cart_add_product($leadId, $botId, $index, $qty);
        }
    }

    $cart = cart_get($leadId);
    if ($cart['items'] !== []) {
        $last = $cart['items'][count($cart['items']) - 1];
        foreach (catalog_products_for_bot($botId) as $i => $product) {
            if ((int) ($product['id'] ?? 0) === (int) ($last['product_id'] ?? 0)) {
                return cart_add_product($leadId, $botId, $i + 1, (int) ($last['quantity'] ?? 1));
            }
        }
    }

    $lastAi = db_fetch(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 6',
        'i',
        [$leadId]
    );
    if ($lastAi && cart_reconstruct_from_text($leadId, $botId, (string) $lastAi['message'])) {
        $cart = cart_get($leadId);
        if ($cart['items'] !== []) {
            return "Happy to help with another order!\n\n" . cart_format_summary($leadId);
        }
    }

    return "Sure — happy to help with another order!\n\n" . catalog_whatsapp_list_block($botId);
}

function cart_checkout_in_progress(int $leadId): bool
{
    $cart = cart_get($leadId);
    if ($cart['items'] === []) {
        return false;
    }

    return $cart['customer_name'] === ''
        || $cart['shipping_address'] === ''
        || !$cart['cod_confirmed']
        || empty($cart['anything_else_done']);
}

function cart_message_looks_like_delivery_details(string $message): bool
{
    $text = trim($message);
    if ($text === '') {
        return false;
    }

    $parsed = cart_parse_delivery_blob($text);
    if (!empty($parsed['customer_name']) || !empty($parsed['customer_phone']) || !empty($parsed['shipping_address'])) {
        return true;
    }

    if (preg_match('/(?:^|\n)\s*(?:name|naam|address|delivery|phone|mobile|contact)\s*:?\s*\S/imu', $text)) {
        return true;
    }

    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: [])));
    if (count($lines) < 2) {
        return (bool) preg_match(
            '/street|road|block|phase|house|flat|plot|near|lahore|karachi|islamabad|multan|rawalpindi|'
            . 'delivery|society|town|dha|bahria|gulberg|model town|cantt|office|chenab|innovista/iu',
            $text
        );
    }

    $hasPhone = false;
    $hasAddress = false;
    foreach ($lines as $line) {
        $digits = preg_replace('/\D/', '', $line);
        if ($digits !== null && strlen($digits) >= 10 && strlen($digits) <= 15) {
            $hasPhone = true;
        }
        if (preg_match(
            '/street|road|block|phase|house|flat|plot|near|lahore|karachi|islamabad|multan|rawalpindi|'
            . 'delivery|society|town|dha|bahria|gulberg|model town|cantt|area|city/iu',
            $line
        )) {
            $hasAddress = true;
        }
    }

    return $hasAddress || ($hasPhone && count($lines) >= 2);
}

function cart_assistant_asked_for_delivery(int $leadId): bool
{
    return cart_assistant_asked_for_checkout_fields($leadId);
}

function cart_assistant_asked_for_checkout_fields(int $leadId): bool
{
    $msg = cart_assistant_last_reply($leadId);
    if ($msg === '' || cart_reply_includes_summary($msg)) {
        return false;
    }

    return (bool) preg_match(
        '/Almost there|Please send|full delivery address|delivery address|full name|phone number|'
        . 'what is your \*full name\*|share your (full )?name|name and (phone|number)|'
        . 'attach them to your order/iu',
        $msg
    );
}

function cart_message_is_shop_interrupt(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (cart_message_catalog_pick_index($message) !== null) {
        return true;
    }

    if (preg_match(
        '/^(cart|my cart|checkout|place order|menu|view menu|clear cart|empty cart|order from menu)\b/iu',
        $lower
    )) {
        return true;
    }

    require_once __DIR__ . '/catalog.php';

    return catalog_message_is_menu_request(0, $message)
        || catalog_message_is_browse_intent($message)
        || (function_exists('catalog_customer_wants_other_menu') && catalog_customer_wants_other_menu($message));
}

function cart_should_treat_as_checkout_details(int $leadId, string $message): bool
{
    if ($leadId <= 0 || cart_is_empty($leadId) || !cart_checkout_in_progress($leadId)) {
        return false;
    }
    if (cart_message_is_shop_interrupt($message) || cart_message_is_farewell_or_decline($message)) {
        return false;
    }
    require_once __DIR__ . '/conversation-intent.php';
    if (conversation_message_is_conversion_aside($message, $leadId)) {
        return false;
    }
    if (cart_assistant_asked_for_checkout_fields($leadId)) {
        return true;
    }

    return cart_message_looks_like_delivery_details($message);
}

function cart_assistant_asked_for_cod_confirm(int $leadId): bool
{
    $msg = cart_assistant_last_reply($leadId);
    if ($msg === '' || cart_reply_includes_summary($msg)) {
        return false;
    }

    return (bool) preg_match(
        '/^COD confirmed\b|Type \*checkout\* to place your order now/imu',
        $msg
    );
}

function cart_message_declines_anything_else(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match(
        '/^(no|nope|nah|nahi|ni|bas|nothing|that\'?s all|thats all|that is all|nothing else|'
        . 'nothing more|no thanks|no thank you|just (this|it|the order)|'
        . 'this is (it|all|everything)|all good|proceed|place (the )?order|'
        . 'send it|go ahead|confirm|checkout|that\'?s it|thats it)$/iu',
        $lower
    )) {
        return true;
    }

    return (bool) preg_match(
        '/\b(nothing else|no more|that\'?s all|thats all|just this|no thanks|'
        . 'don\'?t (need|want) anything else|bas yeh|sirf yeh)\b/iu',
        $lower
    );
}

function cart_message_wants_more_items(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (cart_message_declines_anything_else($message)) {
        return false;
    }

    return (bool) preg_match(
        '/^(yes|yeah|yep|haan|han|ji|ok|okay|sure|please|add more|one more|another)$/iu',
        $lower
    ) || (bool) preg_match(
        '/\b(add more|another item|one more|also add|and also|i (want|need) more)\b/iu',
        $lower
    );
}

function cart_assistant_asked_to_place_order(int $leadId): bool
{
    $msg = cart_assistant_last_reply($leadId);
    if ($msg === '') {
        return false;
    }

    return (bool) preg_match(
        '/place this order|place the order|send this (to our team )?for processing|'
        . 'send this order|would you like me to place|confirm the order now|'
        . 'ready to (place|send) (this|the) order/iu',
        $msg
    );
}

function cart_anything_else_is_pending(int $leadId): bool
{
    $cart = cart_get($leadId);

    return $cart['items'] !== []
        && $cart['customer_name'] !== ''
        && $cart['shipping_address'] !== ''
        && !empty($cart['anything_else_offered'])
        && empty($cart['anything_else_done']);
}

function cart_anything_else_question(int $leadId): string
{
    $cart = cart_get($leadId);
    $total = catalog_format_price(cart_grand_total($cart), cart_currency($cart));

    return implode("\n", [
        'Before I place your order — need anything else?',
        '',
        '*Total so far:* ' . $total . '  (COD)',
        '',
        'Reply *no* if that\'s everything, or tell me what to add.',
    ]);
}

/**
 * Place the order only after the customer says they do not need anything else.
 *
 * @param array<string, mixed> $lead
 */
function cart_try_place_order(int $leadId, int $botId, array $lead, string $userMessage = ''): ?string
{
    $cart = cart_get($leadId);
    if ($cart['items'] === [] || $cart['customer_name'] === '' || $cart['shipping_address'] === '') {
        return null;
    }

    if (!$cart['cod_confirmed']) {
        cart_update_checkout($leadId, ['cod_confirmed' => true]);
        $cart = cart_get($leadId);
    }

    if (cart_assistant_asked_to_place_order($leadId)
        && preg_match('/^(yes|yeah|yep|haan|han|ji|ok|okay|sure|confirm|place it|send it)$/iu', trim($userMessage))
    ) {
        cart_update_checkout($leadId, ['anything_else_done' => true, 'anything_else_offered' => true]);
        $userId = (int) ($lead['bot_user_id'] ?? 0);
        if ($userId <= 0) {
            $botRow = db_fetch('SELECT user_id FROM bots WHERE id = ?', 'i', [$botId]);
            $userId = (int) ($botRow['user_id'] ?? 0);
        }
        $orderId = cart_finalize_order($leadId, $botId, $userId, $lead, '');
        if ($orderId) {
            return cart_order_confirmation_message($orderId);
        }

        return 'Could not place order — please double-check your name and delivery address.';
    }

    if (cart_message_wants_more_items($userMessage)
        && !empty($cart['anything_else_offered'])
        && !cart_assistant_asked_to_place_order($leadId)
    ) {
        cart_update_checkout($leadId, ['anything_else_offered' => true, 'anything_else_done' => false]);

        return 'Sure — what else can I add before I send this for processing?';
    }

    if (!empty($cart['anything_else_done'])
        || (!empty($cart['anything_else_offered']) && cart_message_declines_anything_else($userMessage))
    ) {
        cart_update_checkout($leadId, ['anything_else_done' => true, 'anything_else_offered' => true]);
        $userId = (int) ($lead['bot_user_id'] ?? 0);
        if ($userId <= 0) {
            $botRow = db_fetch('SELECT user_id FROM bots WHERE id = ?', 'i', [$botId]);
            $userId = (int) ($botRow['user_id'] ?? 0);
        }
        $orderId = cart_finalize_order($leadId, $botId, $userId, $lead, '');
        if ($orderId) {
            return cart_order_confirmation_message($orderId);
        }

        return 'Could not place order — please double-check your name and delivery address.';
    }

    cart_update_checkout($leadId, ['anything_else_offered' => true, 'anything_else_done' => false]);

    return cart_anything_else_question($leadId);
}

function cart_message_is_checkout_affirmative(int $leadId, string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (cart_anything_else_is_pending($leadId) && !cart_assistant_asked_to_place_order($leadId)) {
        return false;
    }

    if (cart_assistant_asked_for_cod_confirm($leadId)
        && preg_match('/^(yes|haan|han|ji|ok|okay|confirm)$/iu', $lower)
    ) {
        return true;
    }

    if (cart_assistant_asked_to_place_order($leadId)
        && preg_match('/^(yes|haan|han|ji|ok|okay|sure|confirm)$/iu', $lower)
    ) {
        return true;
    }

    if (!preg_match('/^(yes|haan|han|ji|confirm)$/iu', $lower)) {
        return false;
    }

    return cart_assistant_asked_for_delivery($leadId);
}

/**
 * Advance checkout: hydrate fields, prompt for missing info, or finalize order.
 *
 * @param array<string, mixed> $lead
 */
function cart_progress_checkout(int $leadId, int $botId, array $lead, string $text): ?string
{
    $cleanText = preg_replace('/^(checkout|place order|order confirm|confirm order)\s*\n/im', '', trim($text)) ?? trim($text);
    if ($cleanText === '') {
        $cleanText = $text;
    }

    cart_hydrate_from_recent_messages($leadId);
    cart_merge_hints_from_text($leadId, $cleanText, $text);
    cart_update_checkout($leadId, cart_parse_delivery_blob($cleanText));

    $cart = cart_get($leadId);
    if ($cart['items'] === []) {
        return null;
    }

    if (cart_user_checkout_frustration($text)) {
        cart_hydrate_from_recent_messages($leadId, 16);
        $cart = cart_get($leadId);
    }

    if ($cart['customer_name'] === '' || $cart['shipping_address'] === '') {
        if (cart_user_checkout_frustration($text)) {
            $missing = [];
            if ($cart['customer_name'] === '') {
                $missing[] = 'full name';
            }
            if ($cart['shipping_address'] === '') {
                $missing[] = 'delivery address';
            }

            return 'Sorry about the repeat — I still need your ' . implode(' and ', $missing)
                . ".\n\nPlease send in one message:\n• Name\n• Phone\n• Address";
        }
        if ($cart['customer_name'] === '' && $cart['shipping_address'] === '') {
            return "Almost there! Please send in one message:\n\n"
                . "• Full name\n"
                . "• Phone number\n"
                . "• Full delivery address\n\n"
                . "Payment: Cash on Delivery (COD).";
        }
        if ($cart['customer_name'] === '') {
            return 'Thanks — what is your *full name* for delivery?';
        }

        return 'Got it — please send your *full delivery address* (area, street, city).';
    }

    if (!$cart['cod_confirmed']) {
        cart_update_checkout($leadId, ['cod_confirmed' => true]);
        $cart = cart_get($leadId);
    }

    return cart_try_place_order($leadId, $botId, $lead, $text);
}

/**
 * Natural-language shop intents before strict commands / AI.
 */
function cart_handle_natural_intent(int $leadId, int $botId, string $message, array $lead = []): ?string
{
    if (catalog_products_for_bot($botId) === []) {
        return null;
    }

    $text = trim($message);
    $lower = mb_strtolower($text);

    if (preg_match(
        '/one more|another (?:order|item|perfume|product)|order again|same again|same (?:perfume|product|item|order)|'
        . 'want (?:one|another)|can i order|order one more|phir se|ek aur|dobara|same wala|repeat order/iu',
        $lower
    )) {
        return cart_add_repeat_last_item($leadId, $botId);
    }

    if (preg_match('/^(?:add|plus)\s+(\d+)\s+more$/iu', $lower, $m)) {
        return cart_increase_last_line_qty($leadId, (int) $m[1], $botId);
    }

    if (preg_match('/^(\d+)\s+more$/iu', $lower, $m) && !cart_is_empty($leadId)) {
        return cart_increase_last_line_qty($leadId, (int) $m[1], $botId);
    }

    if (preg_match('/^(?:show|list|catalog|products|menu)$/iu', $lower)) {
        return catalog_whatsapp_list_block($botId);
    }

    $named = cart_add_named_products($leadId, $botId, $message);
    if ($named !== null) {
        return $named;
    }

    return null;
}

/**
 * Add catalog items mentioned by name (one or several, any menus) into the same cart.
 */
function cart_add_named_products(int $leadId, int $botId, string $message): ?string
{
    require_once __DIR__ . '/catalog.php';
    if (!function_exists('whatsapp_shop_customer_wants_visual_card')) {
        require_once __DIR__ . '/whatsapp-shop-ux.php';
    }

    if (function_exists('catalog_message_is_menu_request') && catalog_message_is_menu_request($botId, $message)) {
        return null;
    }
    if (function_exists('catalog_customer_wants_other_menu') && catalog_customer_wants_other_menu($message)) {
        return null;
    }
    if (function_exists('whatsapp_shop_customer_wants_visual_card') && whatsapp_shop_customer_wants_visual_card($message)) {
        return null;
    }
    if (!catalog_message_could_be_product_query($message)) {
        return null;
    }

    $parts = preg_split('/\s+(?:and|&|aur|plus|or)\s+|,\s+/iu', $message) ?: [$message];
    $added = [];
    $seen = [];

    foreach ($parts as $part) {
        $query = catalog_extract_product_query(trim((string) $part));
        if (mb_strlen($query) < 2) {
            continue;
        }
        $matches = catalog_search_products($botId, $query, 1);
        if ($matches === [] || ($matches[0]['score'] ?? 0) < 55) {
            continue;
        }
        $index = (int) ($matches[0]['index'] ?? 0);
        if ($index < 1 || isset($seen[$index])) {
            continue;
        }
        $seen[$index] = true;
        $added[] = cart_add_product($leadId, $botId, $index, 1);
    }

    if ($added === []) {
        return null;
    }

    return (string) $added[count($added) - 1];
}

/**
 * Detect when customer picks a catalog number (#3, 3, add 3, order #3, etc.).
 */
function cart_message_catalog_pick_index(string|int|float $message): ?int
{
    $text = trim((string) $message);
    if ($text === '') {
        return null;
    }

    if (preg_match('/^(?:add|order|want|get|i want|mujhe|chahiye|yes\s+)?\s*#?\s*(\d{1,3})$/iu', $text, $m)) {
        return (int) $m[1];
    }

    if (preg_match('/^#\s*(\d{1,3})\b/u', $text, $m)) {
        return (int) $m[1];
    }

    if (preg_match('/^#(\d{1,3})$/u', $text, $m)) {
        return (int) $m[1];
    }

    if (preg_match('/^#\s*(\d{1,3})$/u', $text, $m)) {
        return (int) $m[1];
    }

    if (preg_match('/^(\d{1,3})$/u', $text, $m)) {
        return (int) $m[1];
    }

    return null;
}

function cart_message_asks_cart_status(string $message): bool
{
    $lower = mb_strtolower(trim($message));

    return (bool) preg_match(
        '/\b(both|two|2 items|added in the|in (?:my|the) cart|items are added|what\'?s in my cart|show my cart)\b/iu',
        $lower
    );
}

function cart_message_is_order_process_question(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    return (bool) preg_match(
        '/\b(how will|how do i|how does|what happens|what\'s next|whats next|'
        . 'confirm(?:ation)?|send (?:it|this|to me|the order)|place (?:the )?order|checkout process|'
        . 'get (?:it|this)|order (?:confirm|confirmation)|deliver(?:y)?)\b/iu',
        $lower
    );
}

/**
 * Text lines from unanswered turns (menu taps) for this lead — newest burst first in order.
 *
 * @return list<string>
 */
function cart_lead_recent_pick_messages(int $leadId): array
{
    if ($leadId <= 0) {
        return [];
    }

    $rows = db_fetch_all(
        'SELECT ctm.raw_text
         FROM conversation_turn_messages ctm
         INNER JOIN conversation_turns ct ON ct.id = ctm.turn_id
         WHERE ct.lead_id = ?
         AND ctm.message_type = \'text\'
         AND TRIM(COALESCE(ctm.raw_text, \'\')) <> \'\'
         AND NOT EXISTS (
            SELECT 1 FROM conversation_turn_events e
            WHERE e.turn_id = ct.id AND e.event_type = \'RESPONSE_SENT\'
         )
         ORDER BY ctm.id ASC',
        'i',
        [$leadId]
    ) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $t = trim((string) ($row['raw_text'] ?? ''));
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return $out;
}

/**
 * @return list<string>
 */
function cart_split_shop_lines(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn ($l) => $l !== ''));
}

/**
 * Apply every catalog pick from recent unanswered customer messages (multi-menu taps).
 *
 * @param array<string, mixed> $lead
 */
function cart_sync_catalog_picks_from_lead(int $leadId, int $botId, array $lead = []): ?string
{
    if ($leadId <= 0 || $botId <= 0) {
        return null;
    }

    require_once __DIR__ . '/catalog.php';
    if (catalog_products_for_bot($botId) === []) {
        return null;
    }

    $messages = cart_lead_recent_pick_messages($leadId);
    if ($messages === []) {
        return null;
    }

    $lastReply = null;
    $seenPick = [];

    foreach ($messages as $msg) {
        foreach (cart_split_shop_lines($msg) as $line) {
            $pick = cart_message_catalog_pick_index($line);
            if ($pick === null && preg_match('/^add\s+#(\d{1,3})\b/iu', $line, $m)) {
                $pick = (int) $m[1];
            }
            if ($pick === null || $pick < 1) {
                continue;
            }
            $key = 'p' . $pick;
            if (isset($seenPick[$key])) {
                continue;
            }
            $seenPick[$key] = true;
            $products = catalog_products_for_bot($botId);
            if ($pick > count($products)) {
                continue;
            }
            $lastReply = cart_add_product($leadId, $botId, cart_resolve_shown_index($leadId, $pick), 1);
        }
    }

    return $lastReply;
}

function cart_handle_command(int $leadId, int $botId, string $message, array $lead = []): ?string
{
    $natural = cart_handle_natural_intent($leadId, $botId, $message, $lead);
    if ($natural !== null) {
        return $natural;
    }

    $text = trim($message);
    if ($text !== '' && cart_message_is_order_process_question($text) && !cart_is_empty($leadId)) {
        cart_hydrate_from_recent_messages($leadId);
        $progress = cart_progress_checkout($leadId, $botId, $lead, $text);
        if ($progress !== null) {
            return $progress;
        }

        return cart_format_summary($leadId) . "\n\n"
            . 'We confirm *Cash on Delivery* — send your *full name*, *phone*, and *delivery address* in one message, or tap *Checkout* below.';
    }

    if ($text !== '' && cart_message_asks_cart_status($text) && !cart_is_empty($leadId)) {
        return cart_format_summary($leadId);
    }

    require_once __DIR__ . '/conversation-intent.php';
    if (conversation_message_is_conversion_aside($message, $leadId, $botId)) {
        return null;
    }

    if (cart_anything_else_is_pending($leadId) || cart_assistant_asked_to_place_order($leadId)) {
        if (cart_message_declines_anything_else($message)
            && !cart_message_is_order_decline($message)
        ) {
            return cart_try_place_order($leadId, $botId, $lead, $message);
        }
        if (preg_match('/^(yes|yeah|yep|haan|han|ji|ok|okay|sure)$/iu', trim($message))) {
            return cart_try_place_order($leadId, $botId, $lead, $message);
        }
    }

    $unprocessed = cart_handle_unprocessed_complaint($leadId, $botId, $message, $lead);
    if ($unprocessed !== null) {
        return $unprocessed;
    }

    $farewell = cart_handle_farewell_or_decline($leadId, $message);
    if ($farewell !== null) {
        return $farewell;
    }

    if (catalog_products_for_bot($botId) === []) {
        return null;
    }

    $text = trim($message);
    $lower = mb_strtolower($text);

    $catalogPick = cart_message_catalog_pick_index($text);
    if ($catalogPick !== null) {
        $catalogPick = cart_resolve_shown_index($leadId, $catalogPick);
        $products = catalog_products_for_bot($botId);
        if ($catalogPick >= 1 && $catalogPick <= count($products)) {
            return cart_add_product($leadId, $botId, $catalogPick, 1);
        }

        return 'Item #' . $catalogPick . ' is not on the menu. Tap *View menu* to see all products.';
    }

    if (preg_match('/^(cart|my cart|mera cart|basket|order summary)$/i', $lower)) {
        return cart_format_summary($leadId);
    }

    if (preg_match('/^(clear cart|empty cart|cart clear)$/i', $lower)) {
        cart_clear($leadId);
        require_once __DIR__ . '/whatsapp-shop-ux.php';

        return whatsapp_shop_copy_cart_cleared();
    }

    if (preg_match('/^(promo|code|coupon)\s+([A-Z0-9_-]+)$/i', strtoupper($text), $m)) {
        require_once __DIR__ . '/promo-codes.php';
        return promo_apply_to_cart($leadId, $botId, $m[2]);
    }

    if (!cart_is_empty($leadId)) {
        $checkoutFlow = cart_should_treat_as_checkout_details($leadId, $text)
            || (
                cart_checkout_in_progress($leadId)
                && (
                    cart_user_checkout_frustration($text)
                    || preg_match('/^(checkout|place order|order confirm|confirm order)\b/im', $text)
                )
            );
        if ($checkoutFlow) {
            $progress = cart_progress_checkout($leadId, $botId, $lead, $text);
            if ($progress !== null) {
                return $progress;
            }
        }
    }

    if (preg_match('/^(checkout|place order|order confirm|confirm order)$/i', $lower)
        || preg_match('/^(checkout|place order|order confirm|confirm order)\b/im', $text)) {
        require_once __DIR__ . '/business-hours.php';
        if (!business_hours_is_open($botId)) {
            return business_hours_closed_reply($botId);
        }
        return cart_progress_checkout($leadId, $botId, $lead, $text)
            ?? 'Your cart is empty. Tap *View menu* below or tell me what you want.';
    }

    if (cart_message_is_checkout_affirmative($leadId, $text) && !cart_is_empty($leadId)) {
        if (cart_checkout_in_progress($leadId)) {
            cart_hydrate_from_recent_messages($leadId);
            cart_update_checkout($leadId, ['cod_confirmed' => true]);
            $progress = cart_progress_checkout($leadId, $botId, $lead, $text);
            if ($progress !== null) {
                return $progress;
            }
        }
        $cart = cart_get($leadId);
        if ($cart['customer_name'] !== '' && $cart['shipping_address'] !== '' && !$cart['cod_confirmed']) {
            cart_update_checkout($leadId, ['cod_confirmed' => true]);
            $cart = cart_get($leadId);
        }
        if ($cart['customer_name'] !== '' && $cart['shipping_address'] !== '' && $cart['cod_confirmed']) {
            $placed = cart_try_place_order($leadId, $botId, $lead, $text);
            if ($placed !== null) {
                return $placed;
            }
        }
        if (!$cart['cod_confirmed']) {
            return "COD confirmed.\n\nType *checkout* to place your order now.";
        }
    }

    if (preg_match('/^add\s+#(\d+)(?:\s+[x×]\s*(\d+))?$/iu', $text, $m)
        || preg_match('/^order\s+#(\d+)(?:\s+[x×]\s*(\d+))?$/iu', $text, $m)) {
        return cart_add_product($leadId, $botId, cart_resolve_shown_index($leadId, (int) $m[1]), (int) ($m[2] ?? 1));
    }

    if (preg_match('/^add\s+(\d+)$/iu', $text, $m) && !cart_is_empty($leadId)) {
        return cart_increase_last_line_qty($leadId, max(1, (int) $m[1]), $botId);
    }

    if (preg_match('/^add\s+(\d+)(?:\s+[x×]\s*(\d+))?$/iu', $text, $m)) {
        return cart_add_product($leadId, $botId, cart_resolve_shown_index($leadId, (int) $m[1]), (int) ($m[2] ?? 1));
    }

    if (preg_match('/^order\s+(\d+)(?:\s+[x×]\s*(\d+))?$/iu', $text, $m)) {
        return cart_add_product($leadId, $botId, cart_resolve_shown_index($leadId, (int) $m[1]), (int) ($m[2] ?? 1));
    }

    if (preg_match('/^remove\s+#?(\d+)$/i', $lower, $m)) {
        return cart_remove_line($leadId, (int) $m[1]);
    }

    return null;
}

/**
 * Context block for AI including live cart state.
 */
function cart_ai_context_block(int $leadId, int $botId): string
{
    if (catalog_products_for_bot($botId) === []) {
        return '';
    }

    $cart = cart_get($leadId);
    $lines = [
        '',
        '───── SHOP CHECKOUT (COD) ─────',
        'Customers can type: *3*, *#3*, *add #3*, *same again*, *cart*, *checkout*.',
        'When they ask for another order / one more item: help them add to cart — never say "our team will get back to you".',
        'Use catalog number N — they often type just the number (3) or #3, not "add #3". Plain *add 2* adds 2 more of the last cart item (not product #2).',
        'Your job when they want to buy:',
        '1. Help them pick products (by catalog number).',
        '2. Collect full name, phone, delivery address if missing from cart.',
        '3. Confirm COD (Cash on Delivery).',
        '4. When cart has items + name + address + COD, ASK if they need anything else. Do not place the order yet.',
        '5. Only after they say no / that\'s all / nothing else → include [CREATE_ORDER] and [ORDER:Full Name|Phone|Address|yes].',
        '6. NEVER say "order confirmed" until they said they do not need anything else and [CREATE_ORDER] is included.',
        '7. When you learn name, phone, or address mid-chat, save with [ORDER:Name|Phone|Address|no] (yes only when COD confirmed).',
        'Do NOT ask for online/card payment — COD only.',
    ];

    if ($cart['items'] !== []) {
        $lines[] = '';
        $lines[] = 'CURRENT CART (system tracked):';
        foreach ($cart['items'] as $i => $item) {
            $lines[] = ($i + 1) . '. ' . ($item['name'] ?? '') . ' × ' . (int) ($item['quantity'] ?? 1)
                . ' @ ' . catalog_format_price((float) ($item['unit_price'] ?? 0), (string) ($item['currency'] ?? 'PKR'));
        }
        $lines[] = 'Total: ' . catalog_format_price(cart_grand_total($cart), cart_currency($cart));
        if ($cart['promo_code'] !== '') {
            $lines[] = 'Promo: ' . $cart['promo_code'] . ' (-' . catalog_format_price(cart_discount($cart), cart_currency($cart)) . ')';
        }
        if ($cart['customer_name'] !== '') {
            $lines[] = 'Name: ' . $cart['customer_name'];
        }
        if ($cart['customer_phone'] !== '') {
            $lines[] = 'Phone: ' . $cart['customer_phone'];
        }
        if ($cart['shipping_address'] !== '') {
            $lines[] = 'Address: ' . $cart['shipping_address'];
        }
        if ($cart['cod_confirmed']) {
            $lines[] = 'COD confirmed: yes';
        } else {
            $lines[] = 'COD confirmed: no';
        }
        if (cart_checkout_in_progress($leadId)) {
            $lines[] = '';
            if (!empty($cart['anything_else_offered']) && empty($cart['anything_else_done'])) {
                $lines[] = 'ASKED IF THEY NEED ANYTHING ELSE — wait. If they say no / that\'s all, then [CREATE_ORDER]. If they want more, add to cart and ask again.';
            } else {
                $lines[] = 'CHECKOUT IN PROGRESS — collect name, phone, address, then ASK if they need anything else before placing the order.';
                $lines[] = 'Do not include [CREATE_ORDER] until they say they do not need anything else.';
            }
            $lines[] = 'If they chat about something unrelated (how are you, weather, a joke), answer that like a person first, then return to the missing checkout step. Never save that chat as their name or address.';
        }
    }

    return implode("\n", $lines);
}

function cart_order_confirmation_message(int $orderId): string
{
    $order = db_fetch('SELECT * FROM bot_orders WHERE id = ?', 'i', [$orderId]);
    if (!$order) {
        return 'Order placed! Our team will confirm shortly.';
    }
    $items = catalog_order_items($orderId);
    $lines = [
        '✅ *Order #' . $orderId . ' confirmed*',
        '',
        '*Items:*',
    ];
    foreach ($items as $item) {
        $lines[] = '• ' . ($item['product_name'] ?? '') . '  ×' . (int) $item['quantity'];
    }
    $lines[] = '';
    $lines[] = '*Total:* ' . catalog_format_price((float) $order['total_amount'], (string) $order['currency']) . '  (COD)';
    $lines[] = '';
    $lines[] = 'Your order is with our team for processing.';
    $lines[] = 'We will confirm and deliver to your address.';
    $lines[] = '';
    $lines[] = 'Thank you!';

    return implode("\n", $lines);
}

/**
 * Create order from cart + finalize checkout.
 *
 * @param array<string, mixed> $lead
 */
function cart_finalize_order(int $leadId, int $botId, int $userId, array $lead, string $aiReply): ?int
{
    ensure_commerce_schema();
    require_once __DIR__ . '/phase6-schema.php';
    ensure_phase6_schema();

    $cart = cart_get($leadId);
    $parsed = cart_parse_order_tag($aiReply);

    if ($parsed !== []) {
        cart_update_checkout($leadId, $parsed);
        $cart = cart_get($leadId);
    }

    if ($cart['items'] === []) {
        return null;
    }

    $name = $cart['customer_name'] ?: trim((string) ($lead['name'] ?? ''));
    $phone = $cart['customer_phone'] ?: trim((string) ($lead['external_id'] ?? ''));
    $address = $cart['shipping_address'];

    if ($name === '' || $address === '') {
        return null;
    }

    $subtotal = cart_subtotal($cart);
    $discount = cart_discount($cart);
    $total = cart_grand_total($cart);
    $currency = cart_currency($cart);
    $promoCode = $cart['promo_code'] !== '' ? $cart['promo_code'] : null;

    $orderId = db_insert(
        'INSERT INTO bot_orders (bot_id, lead_id, user_id, status, total_amount, currency, cod, customer_name, customer_phone, shipping_address, notes, promo_code, discount_amount)
         VALUES (?, ?, ?, \'new\', ?, ?, 1, ?, ?, ?, ?, ?, ?)',
        'iiidssssssd',
        [
            $botId,
            $leadId,
            $userId,
            $total,
            $currency,
            $name,
            $phone,
            $address,
            'WhatsApp COD order',
            $promoCode,
            $discount,
        ]
    );

    if ($promoCode) {
        require_once __DIR__ . '/promo-codes.php';
        promo_increment_usage($promoCode, $botId);
    }

    foreach ($cart['items'] as $item) {
        db_insert(
            'INSERT INTO bot_order_items (order_id, product_id, product_name, quantity, unit_price)
             VALUES (?, ?, ?, ?, ?)',
            'iisid',
            [
                $orderId,
                max(0, (int) ($item['product_id'] ?? 0)),
                (string) ($item['name'] ?? 'Item'),
                (int) ($item['quantity'] ?? 1),
                (float) ($item['unit_price'] ?? 0),
            ]
        );

        $pid = (int) ($item['product_id'] ?? 0);
        if ($pid > 0) {
            db_execute(
                'UPDATE bot_products SET stock = stock - ? WHERE id = ? AND bot_id = ? AND stock IS NOT NULL',
                'iii',
                [(int) ($item['quantity'] ?? 1), $pid, $botId]
            );
        }
    }

    cart_save_last_order_snapshot($leadId, $botId, $cart['items']);
    cart_clear($leadId);

    require_once __DIR__ . '/lead-lifecycle.php';
    lead_mark_booked($leadId, 'order');

    require_once __DIR__ . '/notifications.php';
    notify_new_order($userId, $orderId, $name !== '' ? $name : ($lead['name'] ?? 'Customer'));
    notify_lead_qualified($botId, $name !== '' ? $name : ($lead['name'] ?? 'Lead'), $leadId);

    require_once __DIR__ . '/industry-order-pipeline.php';
    $botRow = db_fetch('SELECT industry_key, business_mode FROM bots WHERE id = ?', 'i', [$botId]);
    $pipelineKey = industry_order_pipeline_for_bot($botRow)['industry_key'];
    order_status_log_event(
        $orderId,
        $botId,
        $userId,
        null,
        'new',
        false,
        null,
        'whatsapp_order',
        industry_order_status_label('new', $pipelineKey !== 'default' ? $pipelineKey : null)
    );

    if (function_exists('email_new_order')) {
        require_once __DIR__ . '/mailer.php';
        $botRow = db_fetch(
            'SELECT u.email AS client_email FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
            'i',
            [$botId]
        );
        if (!empty($botRow['client_email'])) {
            email_new_order($botRow['client_email'], $orderId, $name !== '' ? $name : 'Customer');
        }
    }

    return $orderId;
}

/**
 * @return array<int, array<string, mixed>>
 */
function catalog_orders_by_status(int $userId, ?int $botId = null): array
{
    ensure_commerce_schema();
    $statuses = ['new', 'confirmed', 'shipped', 'delivered'];
    $out = array_fill_keys($statuses, []);

    $orders = catalog_orders_for_user($userId, $botId, 200);
    foreach ($orders as $order) {
        $st = (string) ($order['status'] ?? 'new');
        if ($st === 'cancelled') {
            continue;
        }
        if (!isset($out[$st])) {
            $out['new'][] = $order;
            continue;
        }
        $out[$st][] = $order;
    }

    return $out;
}

/**
 * @return array<int, array<string, mixed>>
 */
function catalog_order_items(int $orderId): array
{
    ensure_commerce_schema();
    return db_fetch_all('SELECT * FROM bot_order_items WHERE order_id = ? ORDER BY id ASC', 'i', [$orderId]);
}

/**
 * Short one-line order items summary for WhatsApp templates.
 */
function catalog_order_items_line(int $orderId, int $maxItems = 4): string
{
    $items = catalog_order_items($orderId);
    if ($items === []) {
        return '';
    }

    $parts = [];
    foreach (array_slice($items, 0, $maxItems) as $item) {
        $parts[] = trim((string) ($item['product_name'] ?? 'Item')) . ' ×' . (int) ($item['quantity'] ?? 1);
    }
    $line = implode(', ', $parts);
    if (count($items) > $maxItems) {
        $line .= ' +' . (count($items) - $maxItems) . ' more';
    }

    return $line;
}

function catalog_update_order_status(int $orderId, int $userId, string $status, bool $notifyCustomer = true): bool
{
    ensure_commerce_schema();
    $allowed = ['new', 'confirmed', 'shipped', 'delivered', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    $existing = db_fetch(
        'SELECT status, bot_id FROM bot_orders WHERE id = ? AND user_id = ?',
        'ii',
        [$orderId, $userId]
    );
    if (!$existing || (string) $existing['status'] === $status) {
        return (bool) $existing;
    }

    $oldStatus = (string) $existing['status'];
    $botId = (int) ($existing['bot_id'] ?? 0);

    if ($status === 'shipped') {
        require_once __DIR__ . '/shipment.php';
        require_once __DIR__ . '/industry-order-pipeline.php';
        $orderRow = db_fetch('SELECT bot_id FROM bot_orders WHERE id = ? AND user_id = ?', 'ii', [$orderId, $userId]);
        $bot = $orderRow ? db_fetch('SELECT industry_key, business_mode FROM bots WHERE id = ?', 'i', [(int) $orderRow['bot_id']]) : null;
        $industryKey = industry_order_pipeline_for_bot($bot)['industry_key'];
        if (industry_order_requires_shipment('shipped', $industryKey) && !shipment_get_by_order($orderId)) {
            return false;
        }
    }

    db_execute(
        'UPDATE bot_orders SET status = ? WHERE id = ? AND user_id = ?',
        'sii',
        [$status, $orderId, $userId]
    );

    require_once __DIR__ . '/industry-order-pipeline.php';
    $botRow = $botId > 0 ? db_fetch('SELECT industry_key, business_mode FROM bots WHERE id = ?', 'i', [$botId]) : null;
    $industryKey = industry_order_pipeline_for_bot($botRow)['industry_key'];
    $statusLabel = industry_order_status_label($status, $industryKey !== 'default' ? $industryKey : null);

    $notifyResult = ['sent' => false, 'error' => null];
    if ($notifyCustomer) {
        if ($status === 'shipped') {
            require_once __DIR__ . '/shipment.php';
            $shipment = shipment_get_by_order($orderId);
            if ($shipment) {
                shipment_notify_customer((int) $shipment['id'], 'shipped', true);
                $notifyResult = ['sent' => true, 'error' => null];
            } else {
                $notifyResult = catalog_send_order_status_whatsapp($orderId, $status);
            }
        } else {
            $notifyResult = catalog_send_order_status_whatsapp($orderId, $status);
        }
    }

    order_status_log_event(
        $orderId,
        $botId,
        $userId,
        $oldStatus,
        $status,
        !empty($notifyResult['sent']),
        $notifyResult['error'] ?? null,
        'dashboard',
        $statusLabel
    );

    $GLOBALS['_catalog_order_status_last_update'] = [
        'order_id'          => $orderId,
        'old_status'        => $oldStatus,
        'new_status'        => $status,
        'status_label'      => $statusLabel,
        'customer_notified' => !empty($notifyResult['sent']),
        'notify_error'      => $notifyResult['error'] ?? null,
    ];

    return true;
}

function catalog_order_next_status(string $current): ?string
{
    return match ($current) {
        'new'       => 'confirmed',
        'confirmed' => 'shipped',
        'shipped'   => 'delivered',
        default     => null,
    };
}

function catalog_order_status_label(string $status): string
{
    return match ($status) {
        'new'       => 'New',
        'confirmed' => 'Confirmed',
        'shipped'   => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default     => ucfirst($status),
    };
}

/**
 * Last order status update metadata (for API / dashboard feedback).
 *
 * @return array{order_id?: int, old_status?: string, new_status?: string, status_label?: string, customer_notified?: bool, notify_error?: string|null}
 */
function catalog_order_status_last_update(): array
{
    return $GLOBALS['_catalog_order_status_last_update'] ?? [];
}

/**
 * @return array<int, list<array<string, mixed>>>
 */
function order_status_events_for_orders(array $orderIds): array
{
    ensure_commerce_schema();
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if ($orderIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));
    $rows = db_fetch_all(
        "SELECT * FROM bot_order_status_events WHERE order_id IN ({$placeholders}) ORDER BY id ASC",
        $types,
        $orderIds
    );

    $out = [];
    foreach ($rows as $row) {
        $oid = (int) ($row['order_id'] ?? 0);
        $out[$oid][] = $row;
    }

    return $out;
}

function order_status_log_event(
    int $orderId,
    int $botId,
    int $userId,
    ?string $oldStatus,
    string $newStatus,
    bool $customerNotified,
    ?string $notifyError = null,
    string $source = 'dashboard',
    ?string $statusLabel = null
): void {
    ensure_commerce_schema();

    db_insert(
        'INSERT INTO bot_order_status_events
         (order_id, bot_id, user_id, old_status, new_status, status_label, customer_notified, notify_error, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iiisssiss',
        [
            $orderId,
            $botId,
            $userId,
            $oldStatus ?? '',
            $newStatus,
            $statusLabel ?? '',
            $customerNotified ? 1 : 0,
            $notifyError ?? '',
            $source,
        ]
    );
}

function cart_match_product_by_name(array $products, string $hint): ?array
{
    $hintLower = mb_strtolower(trim($hint));
    if ($hintLower === '') {
        return null;
    }

    foreach ($products as $product) {
        $name = mb_strtolower(trim((string) ($product['name'] ?? '')));
        if ($name === $hintLower || str_contains($name, $hintLower) || str_contains($hintLower, $name)) {
            return $product;
        }
    }

    $first = preg_split('/\s+/u', $hintLower)[0] ?? '';
    if ($first !== '' && mb_strlen($first) >= 3) {
        foreach ($products as $product) {
            if (str_contains(mb_strtolower((string) ($product['name'] ?? '')), $first)) {
                return $product;
            }
        }
    }

    return null;
}

/**
 * Parse order line items from confirmation text (1x Item — PKR 380, Item x 1 — PKR 380, etc.).
 *
 * @param array<int, array<string, mixed>> $products
 * @return array<int, array<string, mixed>>
 */
function cart_parse_confirmation_line_items(string $text, array $products): array
{
    $items = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^(total|subtotal|delivery|tax|payment|thank|your order|here\'s|cod\b)/iu', $line)) {
            continue;
        }

        $nameHint = '';
        $qty = 1;
        $lineTotal = 0.0;

        if (preg_match('/^(\d+)x\s+(.+?)\s+[—–-]\s*(?:PKR|Rs\.?|USD|\$|EUR|£)?\s*([\d,.]+)\s*$/iu', $line, $m)) {
            $qty = max(1, (int) $m[1]);
            $nameHint = trim($m[2]);
            $lineTotal = (float) str_replace(',', '', $m[3]);
        } elseif (preg_match('/^[-•*]?\s*(.+?)\s+[x×]\s*(\d+)\s+[—–-]\s*(?:PKR|Rs\.?|USD|\$|EUR|£)?\s*([\d,.]+)\s*$/iu', $line, $m)) {
            $nameHint = trim($m[1]);
            $qty = max(1, (int) $m[2]);
            $lineTotal = (float) str_replace(',', '', $m[3]);
        } elseif (preg_match('/^(\d+)x\s+(.+?)\s*$/iu', $line, $m)) {
            $qty = max(1, (int) $m[1]);
            $nameHint = trim($m[2]);
        } else {
            continue;
        }

        if ($nameHint === '' || preg_match('/^(total|order|delivery|phone|cod|cash)/iu', $nameHint)) {
            continue;
        }

        $product = cart_match_product_by_name($products, $nameHint);
        $unitPrice = $product
            ? (float) ($product['price'] ?? 0)
            : ($qty > 0 && $lineTotal > 0 ? $lineTotal / $qty : $lineTotal);

        $items[] = [
            'product_id' => $product ? (int) ($product['id'] ?? 0) : 0,
            'name'       => $product ? (string) ($product['name'] ?? $nameHint) : $nameHint,
            'quantity'   => $qty,
            'unit_price' => $unitPrice,
            'currency'   => $product ? (string) ($product['currency'] ?? 'PKR') : 'PKR',
        ];
    }

    if ($items !== []) {
        return $items;
    }

    if (preg_match_all(
        '/[-•*]\s*(.+?)\s+[x×]\s*(\d+)\s*[—–-]\s*(?:PKR|Rs\.?|USD|\$|EUR|£)?\s*([\d,.]+)/iu',
        $text,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $nameHint = trim($match[1]);
            $qty = max(1, (int) $match[2]);
            $lineTotal = (float) str_replace(',', '', $match[3]);
            $product = cart_match_product_by_name($products, $nameHint);
            $unitPrice = $product
                ? (float) ($product['price'] ?? 0)
                : ($qty > 0 ? $lineTotal / $qty : $lineTotal);

            $items[] = [
                'product_id' => $product ? (int) ($product['id'] ?? 0) : 0,
                'name'       => $product ? (string) ($product['name'] ?? $nameHint) : $nameHint,
                'quantity'   => $qty,
                'unit_price' => $unitPrice,
                'currency'   => $product ? (string) ($product['currency'] ?? 'PKR') : 'PKR',
            ];
        }
    }

    return $items;
}

/**
 * Rebuild cart lines from an AI order-confirmation message when shop_cart was never saved.
 */
function cart_reconstruct_from_text(int $leadId, int $botId, string $text): bool
{
    if (!cart_is_empty($leadId)) {
        return false;
    }

    $products = catalog_products_for_bot($botId);
    if ($products === []) {
        return false;
    }

    $items = cart_parse_confirmation_line_items($text, $products);
    if ($items === []) {
        return false;
    }

    $cart = cart_get($leadId);
    $cart['items'] = $items;
    cart_save($leadId, $cart);

    return true;
}

function cart_ready_for_finalize(int $leadId, string ...$texts): bool
{
    $cart = cart_get($leadId);
    if ($cart['items'] === [] || $cart['customer_name'] === '' || $cart['shipping_address'] === '') {
        return false;
    }

    $combined = implode("\n", $texts);
    $codOk = $cart['cod_confirmed']
        || cart_reply_implies_order_placed($combined)
        || (bool) preg_match('/\bCOD\b|\bcash on delivery\b/iu', $combined);
    if (!$codOk) {
        return false;
    }

    if (!empty($cart['anything_else_done'])) {
        return true;
    }

    return !empty($cart['anything_else_offered']) && cart_message_declines_anything_else($combined);
}

function cart_checkout_ready(int $leadId): bool
{
    return cart_ready_for_finalize($leadId);
}

function cart_lead_has_open_order(int $leadId): bool
{
    ensure_commerce_schema();
    $row = db_fetch(
        'SELECT id FROM bot_orders WHERE lead_id = ? AND status IN (\'new\', \'confirmed\', \'shipped\') ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );
    return (bool) $row;
}

function cart_handle_unprocessed_complaint(int $leadId, int $botId, string $message, array $lead = []): ?string
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '' || cart_is_empty($leadId)) {
        return null;
    }

    if (!preg_match(
        '/not (being )?process|haven\'?t process|have you not process|order is not|'
        . 'not coming to the dashboard|didn\'?t (place|process|send)/iu',
        $lower
    ) && !cart_user_wants_checkout($message)) {
        return null;
    }

    $cart = cart_get($leadId);
    if ($cart['customer_name'] === '' || $cart['shipping_address'] === '') {
        return cart_progress_checkout($leadId, $botId, $lead, $message);
    }

    cart_update_checkout($leadId, ['anything_else_offered' => true, 'anything_else_done' => true, 'cod_confirmed' => true]);

    return cart_try_place_order($leadId, $botId, $lead, 'nothing');
}

/**
 * Pull name / address / COD hints from AI or user text into cart.
 */
function cart_merge_hints_from_text(int $leadId, string ...$texts): void
{
    $combined = implode("\n", $texts);
    $updates = [];

    if (preg_match('/thank you,?\s+([A-Za-z][A-Za-z\s\'.-]{1,40})!/iu', $combined, $m)) {
        $updates['customer_name'] = trim($m[1]);
    }
    if (preg_match('/thanks,?\s+([A-Za-z][A-Za-z\s\'.-]{1,40})[!.,]/iu', $combined, $m)) {
        $updates['customer_name'] = trim($m[1]);
    }
    if (preg_match('/(?:delivery to|deliver to|ship to|address:?)\s*[*\s:]*(.{8,200})/iu', $combined, $m)) {
        $addr = trim(preg_replace('/[*_]+/', '', $m[1]));
        $addr = preg_replace('/\s*(phone|total|cod).*/iu', '', $addr) ?? $addr;
        $addr = trim(rtrim($addr, '.*'));
        if (mb_strlen($addr) >= 8) {
            $updates['shipping_address'] = $addr;
        }
    }
    if (preg_match('/(?:^|\n)\s*(?:name|naam)\s*:?\s*(.+)$/imu', $combined, $m)) {
        $updates['customer_name'] = trim($m[1]);
    }
    if (preg_match('/(?:^|\n)\s*(?:phone|mobile|contact)\s*:?\s*([\d\s+\-]{10,20})/imu', $combined, $m)) {
        $updates['customer_phone'] = preg_replace('/\D/', '', $m[1]) ?: trim($m[1]);
    }
    if (preg_match('/\[ORDER:([^|\]]*)\|([^|\]]*)\|([^|\]]*)\|(yes|no|1|0)\]/i', $combined, $m)) {
        if (trim($m[1]) !== '') {
            $updates['customer_name'] = trim($m[1]);
        }
        if (trim($m[2]) !== '') {
            $updates['customer_phone'] = trim($m[2]);
        }
        if (trim($m[3]) !== '') {
            $updates['shipping_address'] = trim($m[3]);
        }
        if (in_array(strtolower($m[4]), ['yes', '1'], true)) {
            $updates['cod_confirmed'] = true;
        }
    }

    if ($updates !== []) {
        cart_update_checkout($leadId, $updates);
    }
}

/**
 * Pull name / address / COD hints from user text only (never assistant boilerplate).
 */
function cart_merge_hints_from_user(int $leadId, string $userText): void
{
    cart_merge_hints_from_text($leadId, $userText);
    cart_update_checkout($leadId, cart_parse_delivery_blob($userText));

    $lower = mb_strtolower(trim($userText));
    if (preg_match('/^(yes|haan|han|ji|ok|okay|confirm|cod|cash on delivery)$/iu', $lower)
        || preg_match('/\b(yes|confirm).{0,20}(cod|cash on delivery)\b/iu', $userText)) {
        cart_update_checkout($leadId, ['cod_confirmed' => true]);
    }
}

function cart_reply_implies_order_placed(string $reply): bool
{
    return (bool) preg_match(
        '/order.{0,30}(confirm|placed|received)|your order is|order details|confirmed!|order #\d+/iu',
        $reply
    );
}

function cart_user_wants_checkout(string $message): bool
{
    $lower = mb_strtolower(trim($message));

    return (bool) preg_match(
        '/checkout|place order|confirm order|order confirm|order karo|order kar|'
        . 'process (this|the|my|it)|send (this|the) order|not (being )?process/iu',
        $lower
    );
}

/**
 * Create order when cart is ready — even if AI forgot [CREATE_ORDER].
 *
 * @param array<string, mixed> $lead
 * @return array{order_id: ?int, created: bool}
 */
function cart_maybe_auto_finalize_order(
    int $leadId,
    int $botId,
    int $userId,
    array $lead,
    string $userMessage,
    string $rawAiReply,
    string $finalReply,
    bool $hasCreateOrderSignal
): array {
    if (catalog_products_for_bot($botId) === []) {
        return ['order_id' => null, 'created' => false];
    }

    if (cart_lead_has_open_order($leadId)) {
        return ['order_id' => null, 'created' => false];
    }

    $shouldTry = $hasCreateOrderSignal
        || cart_reply_implies_order_placed($finalReply)
        || cart_reply_implies_order_placed($rawAiReply)
        || cart_user_wants_checkout($userMessage)
        || cart_message_is_checkout_affirmative($leadId, $userMessage);

    cart_merge_hints_from_user($leadId, $userMessage);
    if ($rawAiReply !== '' || $finalReply !== '') {
        cart_merge_hints_from_text($leadId, $rawAiReply, $finalReply);
    }

    cart_hydrate_from_recent_messages($leadId);

    if (cart_is_empty($leadId)) {
        cart_reconstruct_from_text($leadId, $botId, $rawAiReply . "\n" . $finalReply);
        if (cart_is_empty($leadId)) {
            $historyRows = db_fetch_all(
                'SELECT role, message FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT 24',
                'i',
                [$leadId]
            );
            if ($historyRows !== []) {
                $historyText = implode("\n", array_map(
                    static fn(array $row): string => (string) ($row['message'] ?? ''),
                    array_reverse($historyRows)
                ));
                cart_reconstruct_from_text($leadId, $botId, $historyText);
            }
        }
        cart_merge_hints_from_user($leadId, $userMessage);
        if ($rawAiReply !== '' || $finalReply !== '') {
            cart_merge_hints_from_text($leadId, $rawAiReply, $finalReply);
        }
    }

    if (!$shouldTry) {
        return ['order_id' => null, 'created' => false];
    }

    $cart = cart_get($leadId);
    if ($cart['items'] === [] || $cart['customer_name'] === '' || $cart['shipping_address'] === '') {
        return ['order_id' => null, 'created' => false];
    }

    if (!$cart['cod_confirmed']) {
        cart_update_checkout($leadId, ['cod_confirmed' => true]);
        $cart = cart_get($leadId);
    }

    if (empty($cart['anything_else_done'])
        && !(!empty($cart['anything_else_offered']) && cart_message_declines_anything_else($userMessage))
    ) {
        cart_update_checkout($leadId, ['anything_else_offered' => true, 'anything_else_done' => false]);

        return [
            'order_id'           => null,
            'created'            => false,
            'ask_anything_else'  => cart_anything_else_question($leadId),
        ];
    }

    cart_update_checkout($leadId, ['anything_else_done' => true, 'anything_else_offered' => true]);
    $orderId = cart_finalize_order($leadId, $botId, $userId, $lead, $rawAiReply);

    return ['order_id' => $orderId, 'created' => (bool) $orderId];
}
