<?php
/**
 * Product catalog helpers for WhatsApp shop.
 */

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/helpers.php';

function catalog_format_price(float $price, string $currency = 'PKR'): string
{
    $currency = strtoupper(trim($currency) ?: 'PKR');
    if ($currency === 'PKR') {
        return 'PKR ' . number_format($price, 0);
    }
    return $currency . ' ' . number_format($price, 2);
}

/**
 * @return array<int, array<string, mixed>>
 */
function catalog_products_for_bot(int $botId, bool $activeOnly = true): array
{
    ensure_commerce_schema();
    $sql = 'SELECT * FROM bot_products WHERE bot_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    return db_fetch_all($sql, 'i', [$botId]);
}

function catalog_bot_has_products(int $botId): bool
{
    return catalog_products_for_bot($botId) !== [];
}

/**
 * @param array<string, mixed> $bot
 */
function catalog_bot_is_shop(int $botId, array $bot = []): bool
{
    if (catalog_bot_has_products($botId)) {
        return true;
    }

    $model = mb_strtolower(trim((string) ($bot['business_model'] ?? '')));

    return preg_match('/\b(ecommerce|shop|store|product|delivery|order|catalog|retail)\b/u', $model) === 1;
}

function catalog_message_is_product_intent(string $message): bool
{
    require_once __DIR__ . '/conversation-intent.php';
    if (conversation_should_skip_catalog_routing($message)) {
        return false;
    }

    if (catalog_customer_wants_product_visuals($message)) {
        return true;
    }

    $lower = mb_strtolower(trim(conversation_strip_internal_directives($message)));
    if ($lower === '') {
        return false;
    }

    if (preg_match('/\b(going to the products?|about products?|asked your name|your name)\b/u', $lower)) {
        return false;
    }

    return (bool) preg_match(
        '/\b(product|products|catalog|catalogue|menu|price|pricing|pkr|rs\.?|'
        . 'buy|order item|add to cart|show me (?:your )?(?:product|catalog|menu)|'
        . 'send me (?:the )?(?:product|photo|pic|image)|do you have|have you got|any product|what do you sell|'
        . 'kya aap ke paas|mujhe chahiye|dikhao|bhejo)\b/u',
        $lower
    );
}

function catalog_message_is_non_product_topic(string $message): bool
{
    require_once __DIR__ . '/conversation-intent.php';

    return conversation_should_skip_catalog_routing($message)
        || conversation_is_hours_question($message);
}

/** Customer already got a menu card and wants a different section / more sections. */
function catalog_customer_wants_other_menu(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (catalog_message_is_category_inquiry($message)) {
        return false;
    }

    return (bool) preg_match(
        '/\b(other|another|different|more)\b.{0,24}\b(menu|section|category|card)|'
        . '\b(this menu i already|already (sent|received|got)|again you have sent|'
        . 'already sent that|send me 2|2,?\s*3 menus|two or three menus|'
        . 'another best|other best)\b/iu',
        $lower
    );
}

/**
 * Customer asks what is available in a category (text answer) — not a request to open the visual menu.
 */
function catalog_message_is_category_inquiry(string $message): bool
{
    if (catalog_customer_wants_product_visuals($message)) {
        return false;
    }
    if (catalog_message_is_menu_request(0, $message)) {
        return false;
    }

    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    return (bool) preg_match(
        '/\b(do you have|have you got|got any|any (other )?(item|product|option)s?\b.{0,16}\b(in|for|from)|'
        . 'what (else )?(do you )?(have|offer|sell)\b.{0,20}\b(in|for|from)|'
        . 'which .{2,40} (do you )?have|other .{2,30} (in|for|from) )\b/iu',
        $lower
    );
}

/** Customer says a promised photo/menu never arrived. */
function catalog_customer_says_media_missing(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match('/^which photos?\??$/iu', $lower)) {
        return true;
    }

    return (bool) preg_match(
        '/\b(i don\'?t see (it|any|the menu|the photo|them)|'
        . 'can\'?t see (it|any|the menu)|nothing (came|arrived|showed)|'
        . 'you didn\'?t (send|sent|mention)|didn\'?t (send|sent) (it|them|any)|'
        . 'neither this time|niether this time|not this time (as well|either)|'
        . 'resend|send (it|them|the menu) again|didn\'?t (go|come) through)\b/iu',
        $lower
    );
}

function catalog_reply_promises_media(string $reply): bool
{
    return (bool) preg_match(
        '/sending (the )?(photo|photos|menu)|here\'?s our menu|let me (re)?send|'
        . 'sending it (now|again)|photos now/iu',
        $reply
    );
}

function catalog_strip_unsent_media_claims(string $reply): string
{
    $reply = preg_replace(
        '/\n*Sending (the )?(photo|photos|menu) now 👇?[^\n]*/iu',
        '',
        $reply
    ) ?? $reply;
    $reply = preg_replace(
        '/\n*Here\'?s our (menu|menu highlights) 👇[^\n]*/iu',
        '',
        $reply
    ) ?? $reply;
    $reply = preg_replace('/\s*👇\s*$/u', '', trim($reply)) ?? $reply;

    return trim(preg_replace('/\n{3,}/', "\n\n", $reply) ?? $reply);
}

function catalog_has_clear_shopping_intent(string $message): bool
{
    if (catalog_message_is_non_product_topic($message)) {
        return false;
    }

    return catalog_message_is_product_intent($message)
        || catalog_customer_wants_product_visuals($message)
        || catalog_message_is_browse_intent($message)
        || catalog_message_is_menu_request(0, $message);
}

function catalog_message_is_browse_intent(string $message): bool
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '') {
        return false;
    }

    if (catalog_message_is_category_inquiry($message)) {
        return false;
    }

    if (preg_match('/^(the\s+)?menu[\s!?.]*$/u', $lower)) {
        return true;
    }

    return (bool) preg_match(
        '/\b(any product|any item|'
        . 'what(?:\'?s| is)? (?:on )?(?:the )?(?:menu|tonight|today)|'
        . 'what(?:\'?s| is) available|(?:available|specials?) (?:today|tonight)|'
        . 'tonight(?:\'?s)? (?:menu|special)|today(?:\'?s)? (?:menu|special)|'
        . 'what (?:do )?you have (?:today|tonight)|what you have (?:today|tonight)|'
        . 'show (me )?(your )?(catalog|products|menu)|'
        . 'can you show.*menu|send.*menu|see.*menu|menu please|'
        . 'best ?item|best ?seller|menu pics?|menu photos?|bbq|barbeque|barbecue|'
        . 'product list|all products|browse|kya kya hai|kya available|kya hai)\b/u',
        $lower
    );
}

/** Bare "menu" / keyword / browse — always open the catalog, even if custom keywords omit "menu". */
function catalog_message_is_menu_request(int $botId, string $message): bool
{
    $trimmed = trim($message);
    if ($trimmed === '') {
        return false;
    }

    if (preg_match('/^(the\s+)?menu[\s!?.]*$/iu', $trimmed)) {
        return true;
    }

    require_once __DIR__ . '/conversation-router.php';

    return conversation_route_is_explicit_menu($message);
}

function catalog_query_is_generic(string $query): bool
{
    $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn ($t) => mb_strlen($t) >= 2));
    if ($terms === []) {
        return true;
    }

    $generic = [
        'product', 'products', 'any', 'item', 'items', 'image', 'images', 'photo', 'photos',
        'picture', 'pictures', 'pic', 'pics', 'something', 'anything', 'stuff', 'pricing', 'price',
        'pkr', 'have', 'need', 'want', 'with', 'the', 'for', 'and', 'you', 'your', 'our', 'catalog',
    ];

    foreach ($terms as $term) {
        if (!in_array($term, $generic, true)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $product
 */
function catalog_product_summary_for_ai(array $product): string
{
    $line = ($product['name'] ?? 'Product') . ' — ' . catalog_format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'PKR'));
    if (!empty($product['category'])) {
        $line .= ' [' . $product['category'] . ']';
    }
    if (!empty($product['description'])) {
        $line .= ': ' . mb_substr(trim((string) $product['description']), 0, 120);
    }
    return $line;
}

/**
 * JSON-safe product row for client catalog UI.
 *
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function catalog_product_client_payload(array $product): array
{
    $description = trim((string) ($product['description'] ?? ''));

    return [
        'id'          => (int) ($product['id'] ?? 0),
        'name'        => (string) ($product['name'] ?? ''),
        'price_label' => catalog_format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'PKR')),
        'category'    => trim((string) ($product['category'] ?? '')),
        'description' => $description !== '' ? mb_substr($description, 0, 120) : '',
        'image_url'   => trim((string) ($product['image_url'] ?? '')),
        'is_active'   => (int) ($product['is_active'] ?? 1),
        'external_source' => trim((string) ($product['external_source'] ?? '')),
        'meta_retailer_id' => trim((string) ($product['meta_retailer_id'] ?? '')),
    ];
}

/**
 * Text block injected into bot system prompt when products exist.
 */
function catalog_ai_prompt_block(int $botId): string
{
    $products = catalog_products_for_bot($botId);
    if ($products === []) {
        return '';
    }

    require_once __DIR__ . '/restaurant-menu-card.php';
    require_once __DIR__ . '/industry-order-pipeline.php';
    $isRestaurant = catalog_bot_is_restaurant($botId);
    $botRow = db_fetch('SELECT industry_key, business_mode FROM bots WHERE id = ?', 'i', [$botId]) ?: [];
    $pipeline = industry_order_pipeline_for_bot($botRow);
    $useShopMenu = $isRestaurant || !empty($pipeline['show_catalog']);

    $lines = [
        '',
        '───── PRODUCT CATALOG (WhatsApp Shop) ─────',
        'You HAVE a live product catalog on WhatsApp. The system automatically sends product visuals when you append tags.',
        '',
        'CRITICAL — IMAGES & LINKS:',
        '- You CAN send product pictures. NEVER say you cannot send photos, images, pictures, or links.',
        '- NEVER say "I can\'t send pictures here directly" or similar — that is FALSE.',
    ];

    if ($isRestaurant) {
        $lines[] = '- RESTAURANT: For menu / bestsellers / multiple dishes → append [MENU:1,2,3,4,5,6] (up to 6 items). Customer gets ONE menu image with all items.';
        $lines[] = '- RESTAURANT: Single dish only → append [PRODUCT:N].';
        $lines[] = '- When customer asks for menu, popular items, or recommendations: pick top items from the list and use [MENU:…].';
        $lines[] = '- When customer asks for a CATEGORY (e.g. "Arabian Roast", "Beef Burgers"): use ONLY items from that category in [MENU:…] — never random items from other categories.';
        $lines[] = '- NEVER write "sending photos now" or "here\'s our menu 👇" unless you also append [MENU:…] or [PRODUCT:…] on the SAME reply. The system sends the image after your text. No tag = no photo — do not promise one.';
        $lines[] = '- Hours, timings, location, how are you: answer only that. No menu. No photos.';
        $lines[] = '- Example: customer says "show menu" → brief reply + [MENU:1,2,3,4,5,6] on the same reply.';
        $lines[] = '- Example: customer says "Arabian roast?" → list those items in text + [MENU:N,N,N] for the Arabian Roast items only.';

        require_once __DIR__ . '/bot-knowledge.php';
        $botRow = db_fetch('SELECT training_meta FROM bots WHERE id = ?', 'i', [$botId]);
        if ($botRow) {
            $meta = bot_training_meta($botRow);
            $menuCards = (array) ($meta['menu_cards'] ?? []);
            if ($menuCards !== []) {
                $idMap = [];
                foreach ($products as $i => $p) {
                    $idMap[(int) ($p['id'] ?? 0)] = $i + 1;
                }
                $lines[] = '';
                $lines[] = 'Menu cards (use these indexes for [MENU:…] when customer asks for that category):';
                foreach (array_slice($menuCards, 0, 30) as $card) {
                    $cardTitle = trim((string) ($card['title'] ?? $card['category'] ?? ''));
                    if ($cardTitle === '') {
                        continue;
                    }
                    $idxList = [];
                    foreach ((array) ($card['product_ids'] ?? []) as $pid) {
                        $idx = $idMap[(int) $pid] ?? 0;
                        if ($idx > 0) {
                            $idxList[] = (string) $idx;
                        }
                    }
                    if ($idxList !== []) {
                        $lines[] = '- ' . $cardTitle . ' → [MENU:' . implode(',', array_slice($idxList, 0, 8)) . ']';
                    }
                }
            }
        }
    } elseif ($useShopMenu) {
        $lines[] = '- When customer asks for pics/photos/catalog/products to see/show/send: reply briefly AND append [PRODUCT:1,2,3] (up to 3 numbers from the list below).';
        $lines[] = '- When customer mentions a product BY NAME (any of ' . count($products) . ' items): find it in SEARCH RESULTS (injected each turn) or the list below, then append [PRODUCT:N] for the match.';
        $lines[] = '- Example: customer says "show catalog" → brief reply + [PRODUCT:1,2,3] on the same reply.';
    } else {
        $lines[] = '- These items are packages/services for this industry — mention them by name when they ask what you offer. Do not send a restaurant food menu.';
        $lines[] = '- If they ask to see a specific item, append [PRODUCT:N] for that match.';
    }
    $lines[] = '- Customer adds to cart with *add #1* (number from list). They often type just *3* or *#3* — that works too.';
    $lines[] = '';
    $lines[] = 'PRODUCT LIST FORMAT (always use for 2+ items in text — never run items together on one line):';
    $lines[] = '• Item name — PKR 399';
    $lines[] = '• Another item — PKR 499';
    $lines[] = '(blank line, then a short natural question like "Want me to add one?")';
    $lines[] = '- When customer only asks "do you have X?" or "any other items in juices?" — answer in text ONLY. Do NOT append [MENU:…] unless they ask to see the menu card / photos.';
    $lines[] = 'Payment: Cash on Delivery (COD) only — no online payment.';
    if (count($products) > 80) {
        $lines[] = 'Catalog size: ' . count($products) . ' products (search by name works for all — use SEARCH RESULTS block when present).';
        $lines[] = 'Sample products (full catalog searchable by name):';
    } else {
        $lines[] = 'Products:';
    }
    foreach (array_slice($products, 0, 80) as $i => $p) {
        $lines[] = ($i + 1) . '. ' . catalog_product_summary_for_ai($p);
    }

    return implode("\n", $lines);
}

/**
 * Detect when customer wants product photos / catalog visuals.
 */
function catalog_customer_wants_product_visuals(string $message): bool
{
    $m = mb_strtolower(trim($message));
    if ($m === '') {
        return false;
    }

    if (preg_match('/how much|price|cost|rate|kitne|daam|available|in stock/iu', $m)
        && catalog_message_could_be_product_query($message)) {
        return true;
    }

    $needles = [
        'pic', 'pics', 'photo', 'photos', 'image', 'images', 'picture', 'pictures',
        'catalog', 'catalogue', 'menu', 'dikhao', 'dikha', 'bhejo', 'bhej', 'send me',
        'show me', 'dekhna', 'dekhao', 'visual', 'screenshot', 'link', 'url',
    ];

    foreach ($needles as $needle) {
        if (str_contains($m, $needle)) {
            return true;
        }
    }

    if (preg_match('/\b(show|send|share|display)\b.{0,30}\b(product|item|perfume|option)/iu', $m)) {
        return true;
    }

    return false;
}

/**
 * How many product cards to send when customer asks for visuals.
 */
function catalog_requested_product_count(string $message): int
{
    if (preg_match('/\b(\d+)\s*(pic|photo|product|item|perfume|option|dish|food)/iu', $message, $m)) {
        return min(6, max(1, (int) $m[1]));
    }

    if (preg_match('/\b(menu|bestseller|popular|special|recommend|today\'?s|combo)\b/iu', $message)) {
        return 6;
    }

    if (preg_match('/\b(two|2)\b/iu', $message)) {
        return 2;
    }

    return 3;
}

/**
 * Extract likely product name from a customer message.
 */
function catalog_extract_product_query(string $message): string
{
    $m = conversation_strip_internal_directives(trim($message));
    $m = preg_replace(
        '/^(?:do you have|have you got|i want|i need|i\'d like|show me|send me|share|price of|how much is|what is the price of|'
        . 'kya aap ke paas|mujhe chahiye|mujhe|chahiye|bhej do|bhejo|dikhao|dekhao|available|is there)\s+/iu',
        '',
        $m
    ) ?? $m;
    $m = preg_replace('/[?.!]+$/u', '', $m) ?? $m;
    return trim($m);
}

function catalog_message_could_be_product_query(string $message): bool
{
    require_once __DIR__ . '/conversation-intent.php';
    if (conversation_should_skip_catalog_routing($message)) {
        return false;
    }

    $lower = mb_strtolower(trim($message));
    if ($lower === '' || mb_strlen($lower) < 2) {
        return false;
    }

    if (preg_match(
        '/^(cart|checkout|promo|remove|clear|yes|no|ok|okay|confirm|hi|hello|hey|salam|aoa|thanks|thank you|bye|ji|han|haan)\b/iu',
        $lower
    )) {
        return false;
    }

    if (preg_match('/one more|same again|another order|order again|can i order/iu', $lower)) {
        return false;
    }

    if (preg_match('/^(add|order)\s+#?\d+/iu', $lower)) {
        return false;
    }

    if (preg_match('/delivery time|return policy|refund|complaint|speak to|human agent|manager|office hours|location/iu', $lower)) {
        return false;
    }

    if (preg_match(
        '/street|road|block|phase|house|flat|plot|near|lahore|karachi|islamabad|multan|rawalpindi|'
        . 'delivery address|society|town|dha|bahria|gulberg|cantt|\b03\d{9}\b|\b92\d{10}\b/iu',
        $lower
    ) && !preg_match('/\b(perfume|product|price|add|catalog|show me|do you have)\b/iu', $lower)) {
        return false;
    }

    return true;
}

/**
 * @return array<int, array{index: int, product: array<string, mixed>, score: float}>
 */
function catalog_search_products(int $botId, string $query, int $limit = 5): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $products = catalog_products_for_bot($botId);
    if ($products === []) {
        return [];
    }

    $queryLower = mb_strtolower($query);
    $terms = array_values(array_filter(preg_split('/\s+/u', $queryLower) ?: [], static fn ($t) => mb_strlen($t) >= 2));

    $scored = [];
    foreach ($products as $i => $product) {
        $name = mb_strtolower(trim((string) ($product['name'] ?? '')));
        $haystack = $name . ' '
            . mb_strtolower(trim((string) ($product['description'] ?? ''))) . ' '
            . mb_strtolower(trim((string) ($product['category'] ?? ''))) . ' '
            . mb_strtolower(trim((string) ($product['sku'] ?? '')));

        $score = 0.0;
        if ($name === $queryLower) {
            $score += 120;
        } elseif (str_contains($name, $queryLower) || str_contains($queryLower, $name)) {
            $score += 80;
        } elseif (str_contains($haystack, $queryLower)) {
            $score += 55;
        }

        foreach ($terms as $term) {
            if (str_contains($name, $term)) {
                $score += 18;
            } elseif (str_contains($haystack, $term)) {
                $score += 8;
            }
        }

        similar_text($name, $queryLower, $pct);
        $score += $pct * 0.45;

        if ($score >= 12) {
            $scored[] = [
                'index'   => $i + 1,
                'product' => $product,
                'score'   => $score,
            ];
        }
    }

    usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($scored, 0, max(1, min(10, $limit)));
}

function catalog_runtime_search_block(int $botId, string $userMessage): string
{
    if (!catalog_message_could_be_product_query($userMessage)) {
        return '';
    }

    $query = catalog_extract_product_query($userMessage);
    if (mb_strlen($query) < 2) {
        $query = trim($userMessage);
    }

    $matches = catalog_search_products($botId, $query, 6);
    if ($matches === []) {
        return "\n\n───── SEARCH RESULTS ─────\nNo exact catalog match for: \"" . $query . "\"\n"
            . 'Say you are checking the catalog and ask for size/variant. NEVER say "our team will contact you".';
    }

    $lines = [
        '',
        '───── SEARCH RESULTS (customer may mean one of these — use [PRODUCT:N]) ─────',
        'Query: "' . $query . '"',
    ];
    foreach ($matches as $match) {
        $lines[] = $match['index'] . '. ' . catalog_product_summary_for_ai($match['product']);
    }
    $lines[] = 'If the top match fits, reply with price/details and append [PRODUCT:N]. Send photo — never say you cannot.';

    return implode("\n", $lines);
}

/**
 * Text that always includes real product names/prices — never a heading with no items.
 *
 * @param list<int> $indexes 1-based catalog indexes
 */
function catalog_browse_text_with_items(int $botId, array $indexes, string $heading): string
{
    $products = catalog_products_for_bot($botId);
    $lines = [rtrim($heading, " \n👇")];
    $n = 0;
    foreach ($indexes as $idx) {
        $i = (int) $idx;
        $p = $products[$i - 1] ?? null;
        if (!is_array($p)) {
            continue;
        }
        $n++;
        $name = trim((string) ($p['name'] ?? 'Item'));
        $price = catalog_format_price((float) ($p['price'] ?? 0), (string) ($p['currency'] ?? 'PKR'));
        $lines[] = '';
        $lines[] = $n . '. *' . $name . '*';
        if ($price !== '') {
            $lines[] = '💰 ' . $price;
        }
    }
    if ($n === 0) {
        return '';
    }
    $lines[] = '';
    $lines[] = 'Reply with a number to add, or tap an item below if photos appear.';

    return implode("\n", $lines);
}

/**
 * Send top catalog items with photos when customer browses or asks generically.
 *
 * @param array<string, mixed> $bot
 * @return array{reply: string, indexes: array<int, int>}|null
 */
function catalog_build_visual_browse_response(int $botId, string $message, array $bot = []): ?array
{
    $products = catalog_products_for_bot($botId);
    if ($products === []) {
        return null;
    }

    require_once __DIR__ . '/restaurant-menu-card.php';
    $isRestaurant = catalog_bot_is_restaurant($botId);
    $maxCount = $isRestaurant ? 6 : 3;
    $count = min(catalog_requested_product_count($message), count($products), $maxCount);
    $count = max(1, $count);

    if ($isRestaurant && $count >= 2) {
        $matched = catalog_menu_card_for_message($botId, $message);
        if ($matched !== null && count($matched['indexes']) >= 2) {
            $title = (string) ($matched['title'] ?? 'Menu highlights');
            $reply = catalog_browse_text_with_items($botId, $matched['indexes'], "Here's our *{$title}* menu");
            return ['reply' => $reply, 'indexes' => $matched['indexes'], 'menu_card' => true, 'menu_card_title' => $title];
        }

        $defaultCard = catalog_default_menu_card($botId);
        if ($defaultCard !== null && catalog_query_is_generic(catalog_extract_product_query($message))) {
            $title = (string) ($defaultCard['title'] ?? 'Menu highlights');
            $reply = catalog_browse_text_with_items($botId, $defaultCard['indexes'], "Here's our *{$title}*");
            return ['reply' => $reply, 'indexes' => $defaultCard['indexes'], 'menu_card' => true, 'menu_card_title' => $title];
        }

        $indexes = catalog_top_food_indexes($botId, [], $count);
        $reply = catalog_browse_text_with_items($botId, $indexes, "Here's our menu");
        return ['reply' => $reply, 'indexes' => $indexes, 'menu_card' => true];
    }

    $slice = array_slice($products, 0, $count);
    $indexes = [];
    $lines = [];

    foreach ($slice as $i => $p) {
        $idx = $i + 1;
        $indexes[] = $idx;
        $lines[] = '*' . $idx . '.* ' . ($p['name'] ?? 'Product') . ' — '
            . catalog_format_price((float) ($p['price'] ?? 0), (string) ($p['currency'] ?? 'PKR'));
    }

    if ($count === 1) {
        $p = $slice[0];
        $reply = 'Here\'s one from our catalog 👇' . "\n\n*"
            . ($p['name'] ?? 'Product') . '* — '
            . catalog_format_price((float) ($p['price'] ?? 0), (string) ($p['currency'] ?? 'PKR'))
            . ' (COD).';
        $reply .= "\n\nSending the photo now — tap *Add to cart* below.";
    } else {
        $reply = "Here are a few from our catalog 👇\n\n" . implode("\n", $lines)
            . "\n\nTap an item below to add it to your cart.";
    }

    return ['reply' => $reply, 'indexes' => $indexes];
}

/**
 * @param array<string, mixed> $bot
 * @return array{reply: string, indexes: array<int, int>, checking?: bool}|null
 */
function catalog_try_resolve_product_request(int $botId, string $message, array $bot = []): ?array
{
    $message = conversation_strip_internal_directives($message);
    if (!catalog_has_clear_shopping_intent($message)) {
        return null;
    }

    if (catalog_products_for_bot($botId) === []) {
        return null;
    }

    $query = catalog_extract_product_query($message);
    if (mb_strlen($query) < 2) {
        $query = trim($message);
    }

    $wantsVisuals = catalog_customer_wants_product_visuals($message);
    $browseIntent = catalog_message_is_browse_intent($message) || catalog_query_is_generic($query);

    if ($browseIntent) {
        $browse = catalog_build_visual_browse_response($botId, $message, $bot);
        if ($browse !== null) {
            return $browse;
        }
    }

    $matches = catalog_search_products($botId, $query, 5);

    if ($matches === []) {
        if ($wantsVisuals || catalog_message_is_product_intent($message)) {
            $browse = catalog_build_visual_browse_response($botId, $message, $bot);
            if ($browse !== null) {
                return $browse;
            }
        }
        if (!$wantsVisuals && mb_strlen($query) < 4) {
            return null;
        }
        return [
            'reply'    => catalog_human_checking_reply($query, $bot),
            'indexes'  => [],
            'checking' => true,
        ];
    }

    $top = $matches[0];
    if ($browseIntent && $top['score'] < 50) {
        $browse = catalog_build_visual_browse_response($botId, $message, $bot);
        if ($browse !== null) {
            return $browse;
        }
    }
    if ($top['score'] < 35 && count($matches) > 1) {
        $lines = ['Let me pull that up — did you mean one of these?'];
        foreach (array_slice($matches, 0, 3) as $match) {
            $lines[] = '*' . $match['index'] . '.* ' . catalog_product_summary_for_ai($match['product']);
        }
        $lines[] = "\nReply *add #" . $matches[0]['index'] . '* to add to cart, or tell me the exact name.';
        return ['reply' => implode("\n", $lines), 'indexes' => []];
    }

    if ($top['score'] >= 35 || $wantsVisuals) {
        $indexes = [$top['index']];
        if ($wantsVisuals && count($matches) > 1) {
            $count = min(catalog_requested_product_count($message), 3, count($matches));
            $indexes = array_column(array_slice($matches, 0, $count), 'index');
        }

        $p = $top['product'];
        $reply = 'Here\'s *' . ($p['name'] ?? 'Product') . '* — '
            . catalog_format_price((float) ($p['price'] ?? 0), (string) ($p['currency'] ?? 'PKR'))
            . ' (COD).';
        if (!empty($p['description'])) {
            $reply .= "\n" . mb_substr(trim((string) $p['description']), 0, 200);
        }
        if (count($indexes) === 1) {
            $reply .= "\n\nSending the photo now 👇\nReply *add #" . $indexes[0] . '* to add to cart.';
        } else {
            $reply .= "\n\nSending photos now 👇";
        }

        return ['reply' => $reply, 'indexes' => $indexes];
    }

    return null;
}

/**
 * @param array<string, mixed> $bot
 */
function catalog_human_checking_reply(string $query, array $bot = []): string
{
    $q = conversation_strip_internal_directives(trim($query));

    if ($q === '' || mb_strlen($q) > 60 || str_contains($q, "\n") || catalog_query_is_generic($q)) {
        return 'Sure — let me pull up our catalog for you. One moment 👇';
    }

    return 'Let me check our catalog for "' . $q . '" — I\'ll get back to you shortly.';
}

/**
 * @param array<string, mixed> $bot
 */
function human_shop_fallback_reply(array $bot, string $userMessage, string $reason = 'error', int $leadId = 0): string
{
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/bot-knowledge.php';

    if ($reason === 'limit') {
        return conversation_finalize_reply(
            $bot,
            $leadId,
            "I'm still here — go ahead."
        );
    }

    $local = knowledge_try_local_reply($bot, $userMessage, $leadId);
    if ($local !== null) {
        return $local;
    }

    $botId = (int) ($bot['id'] ?? 0);
    if ($botId > 0 && catalog_has_clear_shopping_intent($userMessage)) {
        $hit = catalog_try_resolve_product_request($botId, $userMessage, $bot);
        if ($hit !== null) {
            return conversation_finalize_reply($bot, $leadId, (string) ($hit['reply'] ?? ''), $userMessage);
        }
    }

    $isShop = catalog_bot_is_shop($botId, $bot);
    if ($isShop && catalog_has_clear_shopping_intent($userMessage)) {
        $query = catalog_extract_product_query($userMessage);
        if ($query !== '') {
            return conversation_finalize_reply(
                $bot,
                $leadId,
                catalog_human_checking_reply($query, $bot),
                $userMessage
            );
        }
    }

    require_once __DIR__ . '/human-agent-prompt.php';

    return conversation_finalize_reply(
        $bot,
        $leadId,
        human_agent_warm_last_resort($bot, $userMessage, $leadId),
        $userMessage
    );
}

/**
 * Strip robotic "team will contact you" deflections from AI text.
 */
function catalog_sanitize_reply(string $reply): string
{
    $reply = conversation_strip_internal_directives($reply);

    if (!preg_match('/team will (get back|contact|reach)|we(?:\'ll| will) get back|someone will (contact|reach)|our team has received/iu', $reply)) {
        return $reply;
    }

    return 'Wait a bit — let me check that in our catalog and I\'ll get back to you here shortly.';
}

/**
 * Auto-select catalog indexes when AI forgot [PRODUCT:N] but customer asked for visuals or a product name.
 *
 * @param array<int, int> $existing
 * @return array<int, int>
 */
function catalog_auto_product_indexes(int $botId, string $userMessage, array $existing = []): array
{
    require_once __DIR__ . '/restaurant-menu-card.php';

    if (catalog_message_is_non_product_topic($userMessage)
        && !catalog_customer_says_media_missing($userMessage)
    ) {
        return [];
    }

    if ($existing !== []) {
        if (!catalog_customer_wants_product_visuals($userMessage)
            && !catalog_customer_says_media_missing($userMessage)
            && catalog_message_is_category_inquiry($userMessage)
        ) {
            return [];
        }

        return $existing;
    }

    if (catalog_message_is_category_inquiry($userMessage)
        && !catalog_customer_wants_product_visuals($userMessage)
        && !catalog_customer_says_media_missing($userMessage)
    ) {
        return [];
    }

    $wantsVisual = catalog_customer_wants_product_visuals($userMessage)
        || catalog_customer_says_media_missing($userMessage)
        || catalog_message_is_menu_request($botId, $userMessage);

    if ($wantsVisual) {
        $matched = catalog_menu_card_for_message($botId, $userMessage);
        if ($matched !== null && count($matched['indexes']) >= 2) {
            return $matched['indexes'];
        }
    }

    if (!catalog_has_clear_shopping_intent($userMessage) && !catalog_customer_says_media_missing($userMessage)) {
        return [];
    }

    if (catalog_message_could_be_product_query($userMessage)) {
        $query = catalog_extract_product_query($userMessage);
        if (mb_strlen($query) >= 2) {
            $matches = catalog_search_products($botId, $query, catalog_bot_is_restaurant($botId) ? 6 : 3);
            if ($matches !== [] && $matches[0]['score'] >= 35) {
                if (catalog_customer_wants_product_visuals($userMessage)) {
                    $count = min(
                        catalog_requested_product_count($userMessage),
                        count($matches),
                        catalog_bot_is_restaurant($botId) ? 6 : 3
                    );
                    return array_column(array_slice($matches, 0, $count), 'index');
                }
                return [$matches[0]['index']];
            }
        }
    }

    if (catalog_bot_is_restaurant($botId) && (
        catalog_customer_says_media_missing($userMessage)
        || (catalog_customer_wants_product_visuals($userMessage) && catalog_message_is_menu_request($botId, $userMessage))
    )) {
        $lower = mb_strtolower(trim($userMessage));
        if (catalog_customer_says_media_missing($userMessage)
            || preg_match('/\b(menu|bestseller|best seller|popular|recommend|special|combo|highlights|kya hai|kya milta)\b/u', $lower)
        ) {
            $defaultCard = catalog_default_menu_card($botId);
            if ($defaultCard !== null) {
                return $defaultCard['indexes'];
            }

            return catalog_top_food_indexes($botId, [], min(6, catalog_requested_product_count($userMessage)));
        }
    }

    return $existing;
}

/**
 * Remove "can't send pictures" disclaimers and ensure a positive visual reply.
 */
function catalog_ensure_visual_reply(string $reply, int $cardCount): string
{
    $reply = preg_replace(
        '/\b(I can\'?t|cannot|unable to|not able to|I\'m unable to)[^.!\n]{0,140}(picture|photo|image|pic|link|directly|here)[^.!\n]*[.!]?/iu',
        '',
        $reply
    ) ?? $reply;

    $reply = trim(preg_replace('/\n{3,}/', "\n\n", $reply) ?? $reply);

    if ($reply === '' || preg_match('/can\'?t|cannot|unable to send/i', $reply)) {
        return $cardCount === 1
            ? 'Here is the item.'
            : 'Here is the menu.';
    }

    return $reply;
}

/**
 * Normalized key for cross-source deduplication (website + menu PDF + images).
 */
function catalog_normalize_product_name_key(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return trim($name);
}

/**
 * Find an existing catalog row to merge (same bot) — avoids duplicates across import sources.
 *
 * @return array<string, mixed>|null
 */
function catalog_find_existing_product(int $botId, string $name, float $price = -1, string $sku = '', string $externalSource = '', string $externalId = ''): ?array
{
    ensure_commerce_schema();

    $sku = trim($sku);
    if ($sku !== '') {
        $row = db_fetch(
            'SELECT * FROM bot_products WHERE bot_id = ? AND sku = ? LIMIT 1',
            'is',
            [$botId, $sku]
        );
        if ($row) {
            return $row;
        }
    }

    if ($externalSource !== '' && $externalId !== '') {
        $row = db_fetch(
            'SELECT * FROM bot_products WHERE bot_id = ? AND external_source = ? AND external_id = ? LIMIT 1',
            'iss',
            [$botId, $externalSource, $externalId]
        );
        if ($row) {
            return $row;
        }
    }

    $key = catalog_normalize_product_name_key($name);
    if ($key === '' || mb_strlen($key) < 2) {
        return null;
    }

    $products = catalog_products_for_bot($botId, false);
    $best = null;
    $bestScore = 0.0;

    foreach ($products as $product) {
        $productKey = catalog_normalize_product_name_key((string) ($product['name'] ?? ''));
        if ($productKey === '') {
            continue;
        }

        $score = 0.0;
        if ($productKey === $key) {
            $score = 100.0;
        } elseif (str_contains($productKey, $key) || str_contains($key, $productKey)) {
            $score = 85.0;
        } else {
            similar_text($productKey, $key, $pct);
            $score = (float) $pct;
        }

        if ($price >= 0 && (float) ($product['price'] ?? 0) > 0) {
            $existingPrice = (float) $product['price'];
            if (abs($existingPrice - $price) <= max(1.0, $existingPrice * 0.02)) {
                $score += 10.0;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $product;
        }
    }

    return ($best !== null && $bestScore >= 88.0) ? $best : null;
}

/**
 * Import upsert with deduplication across website, menu file, shop sync, manual.
 *
 * @param array<string, mixed> $product
 * @return 'imported'|'updated'|'merged'|'skipped'
 */
function catalog_upsert_import_product(int $botId, int $userId, array $product, string $source, string $externalId): string
{
    ensure_commerce_schema();

    $name = trim((string) ($product['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Missing product name.');
    }

    $price = max(0, (float) ($product['price'] ?? 0));
    $sku = trim((string) ($product['sku'] ?? ''));
    $existing = catalog_find_existing_product($botId, $name, $price, $sku, $source, $externalId);

    $fields = [
        'name'        => $name,
        'description' => trim((string) ($product['description'] ?? '')),
        'price'       => $price,
        'currency'    => strtoupper(substr(trim((string) ($product['currency'] ?? default_currency())), 0, 8)) ?: default_currency(),
        'image_url'   => trim((string) ($product['image_url'] ?? '')),
        'sku'         => $sku,
        'category'    => trim((string) ($product['category'] ?? '')),
        'stock'       => array_key_exists('stock', $product) && $product['stock'] !== null ? (int) $product['stock'] : null,
        'is_active'   => array_key_exists('is_active', $product) ? (!empty($product['is_active']) ? 1 : 0) : 1,
        'sort_order'  => (int) ($product['sort_order'] ?? 0),
    ];

    if ($existing) {
        $existingId = (int) $existing['id'];
        $mergedImage = $fields['image_url'] !== '' ? $fields['image_url'] : trim((string) ($existing['image_url'] ?? ''));
        $mergedDesc = $fields['description'] !== '' ? $fields['description'] : trim((string) ($existing['description'] ?? ''));
        $mergedPrice = $fields['price'] > 0 ? $fields['price'] : (float) ($existing['price'] ?? 0);
        $mergedCategory = $fields['category'] !== '' ? $fields['category'] : trim((string) ($existing['category'] ?? ''));
        $mergedSku = $fields['sku'] !== '' ? $fields['sku'] : trim((string) ($existing['sku'] ?? ''));

        $sameSource = (string) ($existing['external_source'] ?? '') === $source
            && (string) ($existing['external_id'] ?? '') === $externalId;

        $mergedSource = $source !== '' ? $source : trim((string) ($existing['external_source'] ?? 'manual'));
        $mergedExtId = $externalId !== '' ? $externalId : trim((string) ($existing['external_id'] ?? ''));

        db_execute(
            'UPDATE bot_products SET name=?, description=?, price=?, currency=?, image_url=?, sku=?, category=?, stock=?, is_active=?, external_source=?, external_id=?, updated_at=NOW()
             WHERE id=? AND bot_id=? AND user_id=?',
            'ssdsssssiissiii',
            [
                $fields['name'], $mergedDesc, $mergedPrice, $fields['currency'],
                $mergedImage, $mergedSku !== '' ? $mergedSku : null, $mergedCategory !== '' ? $mergedCategory : null,
                $fields['stock'], $fields['is_active'],
                $mergedSource !== '' ? $mergedSource : 'manual',
                $mergedExtId !== '' ? $mergedExtId : null,
                $existingId, $botId, $userId,
            ]
        );

        catalog_save_product_finish_meta_sync($botId);

        return $sameSource ? 'updated' : 'merged';
    }

    db_insert(
        'INSERT INTO bot_products (bot_id, user_id, name, description, price, currency, image_url, sku, category, stock, is_active, sort_order, external_source, external_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iissdssssiiiss',
        [
            $botId, $userId, $fields['name'], $fields['description'], $fields['price'], $fields['currency'],
            $fields['image_url'], $fields['sku'] !== '' ? $fields['sku'] : null,
            $fields['category'] !== '' ? $fields['category'] : null,
            $fields['stock'], $fields['is_active'], $fields['sort_order'], $source, $externalId,
        ]
    );

    catalog_save_product_finish_meta_sync($botId);

    return 'imported';
}

/**
 * @param array<string, mixed> $data
 */
function catalog_save_product(int $botId, int $userId, array $data, ?int $productId = null): int
{
    ensure_commerce_schema();
    require_once __DIR__ . '/phase5-schema.php';
    ensure_phase5_schema();

    $name = trim((string) ($data['name'] ?? $data['product_name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Product name required');
    }

    $stockRaw = $data['stock'] ?? '';
    $stock = ($stockRaw === '' || $stockRaw === null) ? null : (int) $stockRaw;

    $fields = [
        'name'              => $name,
        'description'       => trim((string) ($data['description'] ?? '')),
        'price'             => max(0, (float) ($data['price'] ?? 0)),
        'currency'          => strtoupper(substr(trim((string) ($data['currency'] ?? default_currency())), 0, 8)) ?: default_currency(),
        'image_url'         => trim((string) ($data['image_url'] ?? '')),
        'sku'               => trim((string) ($data['sku'] ?? '')),
        'category'          => trim((string) ($data['category'] ?? '')),
        'meta_retailer_id'  => trim((string) ($data['meta_retailer_id'] ?? '')),
        'stock'             => $stock,
        'is_active'         => !empty($data['is_active']) ? 1 : 0,
        'sort_order'        => (int) ($data['sort_order'] ?? 0),
    ];

    if ($productId) {
        db_execute(
            'UPDATE bot_products SET name=?, description=?, price=?, currency=?, image_url=?, sku=?, category=?, meta_retailer_id=?, stock=?, is_active=?, sort_order=?
             WHERE id=? AND bot_id=? AND user_id=?',
            'ssdsssssiiiiii',
            [
                $fields['name'], $fields['description'], $fields['price'], $fields['currency'],
                $fields['image_url'], $fields['sku'], $fields['category'], $fields['meta_retailer_id'] !== '' ? $fields['meta_retailer_id'] : null,
                $fields['stock'], $fields['is_active'], $fields['sort_order'], $productId, $botId, $userId,
            ]
        );
        catalog_save_product_finish_meta_sync($botId);
        return $productId;
    }

    $newId = db_insert(
        'INSERT INTO bot_products (bot_id, user_id, name, description, price, currency, image_url, sku, category, meta_retailer_id, stock, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iissdsssssiii',
        [
            $botId, $userId, $fields['name'], $fields['description'], $fields['price'], $fields['currency'],
            $fields['image_url'], $fields['sku'], $fields['category'],
            $fields['meta_retailer_id'] !== '' ? $fields['meta_retailer_id'] : null,
            $fields['stock'], $fields['is_active'], $fields['sort_order'],
        ]
    );
    catalog_save_product_finish_meta_sync($botId);
    return $newId;
}

function catalog_save_product_finish_meta_sync(int $botId): void
{
    if (!function_exists('meta_catalog_mark_bot_pending')) {
        require_once __DIR__ . '/meta-catalog-sync.php';
    }
    meta_catalog_mark_bot_pending($botId);
}

/**
 * Remove duplicate catalog rows with the same normalized name (keeps best row: image + external id).
 */
function catalog_deduplicate_bot_products(int $botId, int $userId): int
{
    ensure_commerce_schema();
    $products = catalog_products_for_bot($botId, false);
    $groups = [];

    foreach ($products as $product) {
        $key = catalog_normalize_product_name_key((string) ($product['name'] ?? ''));
        if ($key === '') {
            continue;
        }
        $groups[$key][] = $product;
    }

    $removed = 0;
    foreach ($groups as $rows) {
        if (count($rows) < 2) {
            continue;
        }

        usort($rows, static function (array $a, array $b): int {
            $score = static function (array $row): int {
                $s = 0;
                if (trim((string) ($row['image_url'] ?? '')) !== '') {
                    $s += 4;
                }
                if (trim((string) ($row['external_id'] ?? '')) !== '') {
                    $s += 2;
                }
                if (trim((string) ($row['external_source'] ?? '')) !== '' && (string) ($row['external_source'] ?? '') !== 'manual') {
                    $s += 1;
                }
                return $s * 1000000 + (int) ($row['id'] ?? 0);
            };
            return $score($b) <=> $score($a);
        });

        array_shift($rows);
        foreach ($rows as $dup) {
            catalog_delete_product((int) $dup['id'], $botId, $userId);
            $removed++;
        }
    }

    return $removed;
}

function catalog_delete_product(int $productId, int $botId, int $userId): bool
{
    ensure_commerce_schema();
    db_execute('DELETE FROM bot_products WHERE id=? AND bot_id=? AND user_id=?', 'iii', [$productId, $botId, $userId]);
    return true;
}

/**
 * Delete multiple products (must belong to bot + user).
 *
 * @param array<int|string> $productIds
 */
function catalog_delete_products_bulk(array $productIds, int $botId, int $userId): int
{
    ensure_commerce_schema();

    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn (int $id): bool => $id > 0
    )));

    if ($productIds === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $types = str_repeat('i', count($productIds)) . 'ii';

    return db_execute(
        "DELETE FROM bot_products WHERE id IN ({$placeholders}) AND bot_id = ? AND user_id = ?",
        $types,
        array_merge($productIds, [$botId, $userId])
    );
}

function catalog_delete_all_products(int $botId, int $userId): int
{
    ensure_commerce_schema();

    $row = db_fetch(
        'SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id = ? AND user_id = ?',
        'ii',
        [$botId, $userId]
    );
    $count = (int) ($row['cnt'] ?? 0);

    if ($count > 0) {
        db_execute('DELETE FROM bot_products WHERE bot_id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    }

    return $count;
}

/**
 * @return array<string, string>
 */
function catalog_csv_templates(): array
{
    return [
        'fashion'    => 'Fashion store',
        'restaurant' => 'Restaurant / food',
        'services'   => 'Services',
    ];
}

/**
 * @return array<int, array<string, string>>
 */
function catalog_template_rows(string $template): array
{
    $currency = default_currency();
    return match ($template) {
        'restaurant' => [
            ['name' => 'Chicken Biryani', 'price' => '450', 'description' => 'Full plate', 'category' => 'Mains', 'sku' => 'BRY-1', 'stock' => '50', 'image_url' => '', 'currency' => $currency],
            ['name' => 'Beef Karahi', 'price' => '850', 'description' => 'Half kg', 'category' => 'Mains', 'sku' => 'KRH-1', 'stock' => '30', 'image_url' => '', 'currency' => $currency],
            ['name' => 'Naan', 'price' => '40', 'description' => 'Fresh tandoor', 'category' => 'Sides', 'sku' => 'NAN-1', 'stock' => '100', 'image_url' => '', 'currency' => $currency],
        ],
        'services' => [
            ['name' => 'Consultation Call', 'price' => '5000', 'description' => '30 min strategy call', 'category' => 'Consulting', 'sku' => 'CON-30', 'stock' => '', 'image_url' => '', 'currency' => $currency],
            ['name' => 'Website Audit', 'price' => '15000', 'description' => 'Full site review', 'category' => 'Audit', 'sku' => 'AUD-1', 'stock' => '', 'image_url' => '', 'currency' => $currency],
        ],
        default => [
            ['name' => 'Blue Kurta — Medium', 'price' => '3500', 'description' => 'Cotton kurta', 'category' => 'Kurta', 'sku' => 'KRT-BLU-M', 'stock' => '12', 'image_url' => '', 'currency' => $currency],
            ['name' => 'White Shalwar', 'price' => '2200', 'description' => 'Cotton shalwar', 'category' => 'Shalwar', 'sku' => 'SHL-WHT', 'stock' => '20', 'image_url' => '', 'currency' => $currency],
            ['name' => 'Embroidered Dupatta', 'price' => '1800', 'description' => 'Festive dupatta', 'category' => 'Dupatta', 'sku' => 'DPT-EMB', 'stock' => '8', 'image_url' => '', 'currency' => $currency],
        ],
    };
}

/**
 * @return array{imported: int, skipped: int, errors: array<int, string>}
 */
function catalog_import_csv(int $botId, int $userId, string $csvPath): array
{
    ensure_commerce_schema();

    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        throw new RuntimeException('Could not read CSV file.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('CSV is empty.');
    }

    $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
    $nameIdx = array_search('name', $header, true);
    if ($nameIdx === false) {
        fclose($handle);
        throw new RuntimeException('CSV must have a "name" column.');
    }

    $imported = 0;
    $skipped = 0;
    $errors = [];
    $rowNum = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if ($row === [null] || trim(implode('', $row)) === '') {
            continue;
        }

        $get = static function (string $col) use ($header, $row): string {
            $idx = array_search($col, $header, true);
            return $idx !== false ? trim((string) ($row[$idx] ?? '')) : '';
        };

        $name = $get('name');
        if ($name === '') {
            $skipped++;
            continue;
        }

        try {
            catalog_save_product($botId, $userId, [
                'name'        => $name,
                'price'       => $get('price') !== '' ? $get('price') : 0,
                'description' => $get('description'),
                'category'    => $get('category'),
                'sku'         => $get('sku'),
                'stock'       => $get('stock'),
                'image_url'   => $get('image_url'),
                'currency'    => $get('currency') !== '' ? $get('currency') : default_currency(),
                'is_active'   => 1,
            ]);
            $imported++;
        } catch (Throwable $e) {
            $skipped++;
            if (count($errors) < 8) {
                $errors[] = 'Row ' . $rowNum . ': ' . $e->getMessage();
            }
        }
    }

    fclose($handle);

    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

function catalog_send_order_status_whatsapp(int $orderId, string $newStatus): array
{
    ensure_commerce_schema();
    require_once __DIR__ . '/whatsapp.php';

    $order = db_fetch(
        'SELECT o.*, b.user_id AS bot_owner FROM bot_orders o
         JOIN bots b ON b.id = o.bot_id WHERE o.id = ?',
        'i',
        [$orderId]
    );
    if (!$order || in_array($newStatus, ['new', 'cancelled'], true)) {
        return ['sent' => false, 'error' => 'Status does not trigger customer notification'];
    }

    $phone = preg_replace('/\D/', '', (string) ($order['customer_phone'] ?? ''));
    if ($phone === '' && !empty($order['lead_id'])) {
        $lead = db_fetch('SELECT external_id FROM leads WHERE id = ?', 'i', [(int) $order['lead_id']]);
        $phone = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
    }
    if ($phone === '') {
        return ['sent' => false, 'error' => 'No customer phone on file'];
    }

    $creds = whatsapp_bot_credentials((int) $order['bot_id'], (int) $order['bot_owner']);
    if (!$creds) {
        return ['sent' => false, 'error' => 'WhatsApp not connected for this bot'];
    }

    $label = catalog_order_status_label($newStatus);
    $total = catalog_format_price((float) $order['total_amount'], (string) ($order['currency'] ?? 'PKR'));

    require_once __DIR__ . '/industry-order-pipeline.php';
    $industryKey = industry_order_key_for_order($order);
    $msg = industry_order_whatsapp_message($newStatus, $order, $industryKey);
    if ($msg === '') {
        $msg = match ($newStatus) {
            'confirmed' => "✅ Order #{$orderId} confirmed!\nTotal: {$total} (COD)\nWe'll prepare it soon.",
            'shipped'   => "🚚 Order #{$orderId} has shipped!\nTotal: {$total}\nIt should arrive soon.",
            'delivered' => "📦 Order #{$orderId} delivered.\nThank you for shopping with us!",
            default     => "Order #{$orderId} update: {$label}",
        };
    }

    $result = send_whatsapp_message($creds['phone_id'], $creds['token'], $phone, $msg);
    if (empty($result['success'])) {
        return ['sent' => false, 'error' => (string) ($result['message'] ?? 'WhatsApp send failed')];
    }

    if (!empty($order['lead_id'])) {
        db_insert(
            'INSERT INTO conversations (lead_id, role, message) VALUES (?, \'assistant\', ?)',
            'is',
            [(int) $order['lead_id'], '[Order update] ' . $msg]
        );
    }

    return ['sent' => true, 'error' => null, 'phone' => $phone];
}

/**
 * @return array<int, array<string, mixed>>
 */
function catalog_orders_for_user(int $userId, ?int $botId = null, int $limit = 50): array
{
    ensure_commerce_schema();
    if ($botId) {
        return db_fetch_all(
            'SELECT o.*, b.name AS bot_name FROM bot_orders o
             JOIN bots b ON b.id = o.bot_id
             WHERE o.user_id = ? AND o.bot_id = ?
             ORDER BY o.created_at DESC LIMIT ' . max(1, min(200, $limit)),
            'ii',
            [$userId, $botId]
        );
    }
    return db_fetch_all(
        'SELECT o.*, b.name AS bot_name FROM bot_orders o
         JOIN bots b ON b.id = o.bot_id
         WHERE o.user_id = ?
         ORDER BY o.created_at DESC LIMIT ' . max(1, min(200, $limit)),
        'i',
        [$userId]
    );
}

function catalog_order_status_badge(string $status): string
{
    return match ($status) {
        'confirmed' => 'bg-primary-container text-on-primary-container',
        'shipped'   => 'bg-secondary-container text-on-secondary-container',
        'delivered' => 'bg-tertiary-container text-on-tertiary-container',
        'cancelled' => 'bg-error-container text-on-error-container',
        default     => 'bg-surface-container-high text-on-surface-variant',
    };
}

function catalog_bot_whatsapp_catalog_id(int $botId): string
{
    require_once __DIR__ . '/phase5-schema.php';
    ensure_phase5_schema();
    $row = db_fetch('SELECT whatsapp_catalog_id FROM bots WHERE id = ?', 'i', [$botId]);
    return trim((string) ($row['whatsapp_catalog_id'] ?? ''));
}

function catalog_save_bot_meta_settings(int $botId, int $userId, string $catalogId): void
{
    require_once __DIR__ . '/phase5-schema.php';
    ensure_phase5_schema();

    $catalogId = trim($catalogId);
    if ($catalogId !== '') {
        require_once __DIR__ . '/meta-catalog-sync.php';
        $access = meta_catalog_bot_access($botId);
        if ($access !== null) {
            $valid = meta_catalog_validate_catalog($catalogId, $access['token']);
            if (empty($valid['valid'])) {
                throw new InvalidArgumentException(
                    'Invalid Catalog ID: ' . ($valid['error'] ?? 'not a product catalog')
                    . ' Leave blank for automatic setup.'
                );
            }
        }
    }

    db_execute(
        'UPDATE bots SET whatsapp_catalog_id = ? WHERE id = ? AND user_id = ?',
        'sii',
        [$catalogId !== '' ? $catalogId : null, $botId, $userId]
    );
}

/**
 * Resolve Meta Commerce retailer ID for native WhatsApp product cards.
 */
function catalog_resolve_meta_retailer_id(array $product): string
{
    $id = trim((string) ($product['meta_retailer_id'] ?? ''));
    if ($id !== '') {
        return $id;
    }

    foreach (['sku', 'external_id'] as $key) {
        $value = trim((string) ($product[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $productId = (int) ($product['id'] ?? 0);

    return $productId > 0 ? 'iqp_' . $productId : '';
}

/**
 * Parse [PRODUCT:1] or [PRODUCT:1,2] tags from AI reply.
 *
 * @return array<int, int> 1-based catalog indexes
 */
function catalog_parse_product_tags(string $text): array
{
    if (!preg_match_all('/\[PRODUCT:([\d,\s]+)\]/i', $text, $matches)) {
        return [];
    }

    $indexes = [];
    foreach ($matches[1] as $group) {
        foreach (preg_split('/\s*,\s*/', trim($group)) as $part) {
            $n = (int) $part;
            if ($n > 0) {
                $indexes[] = $n;
            }
        }
    }

    return array_values(array_unique($indexes));
}

/**
 * Send WhatsApp product cards for catalog indexes (Meta catalog or image fallback).
 *
 * @param array<int, int> $indexes 1-based
 * @return array{sent: int, failed: int}
 */
function catalog_send_product_cards(int $botId, int $userId, string $phone, array $indexes, bool $forceMenuCard = false, string $menuTitle = ''): array
{
    require_once __DIR__ . '/whatsapp.php';
    require_once __DIR__ . '/restaurant-menu-card.php';

    $stats = ['sent' => 0, 'failed' => 0];
    if ($indexes === []) {
        return $stats;
    }

    $creds = whatsapp_bot_credentials($botId, $userId);
    if (!$creds) {
        return $stats;
    }

    if (catalog_should_send_menu_card($botId, $indexes, $forceMenuCard)) {
        if ($menuTitle === '') {
            $menuTitle = catalog_title_for_indexes($botId, $indexes);
        }
        $menuStats = catalog_send_restaurant_menu_card($botId, $userId, $phone, $indexes, $menuTitle, $menuTitle);
        if (($menuStats['sent'] ?? 0) > 0) {
            return $menuStats;
        }
    }

    $catalogId = catalog_bot_whatsapp_catalog_id($botId);
    $products = catalog_products_for_bot($botId);
    $metaItems = [];
    $fallbackItems = [];

    foreach (array_slice($indexes, 0, 3) as $idx) {
        $product = $products[$idx - 1] ?? null;
        if (!$product) {
            $stats['failed']++;
            continue;
        }

        $retailerId = catalog_resolve_meta_retailer_id($product);
        if ($catalogId !== '' && $retailerId !== '') {
            $metaItems[] = ['product_retailer_id' => $retailerId];
            continue;
        }

        $fallbackItems[] = ['index' => $idx, 'product' => $product];
    }

    if ($fallbackItems !== []) {
        if (count($fallbackItems) === 1) {
            $item = $fallbackItems[0];
            $idx = (int) $item['index'];
            $product = $item['product'];
            $name = trim((string) ($product['name'] ?? 'Product'));
            $priceLine = catalog_format_price(
                (float) ($product['price'] ?? 0),
                (string) ($product['currency'] ?? 'PKR')
            );
            $imageCaption = '*' . $name . "*\n" . $priceLine;
            $imageUrl = trim((string) ($product['image_url'] ?? ''));

            if ($imageUrl !== '') {
                $result = send_whatsapp_image($creds['phone_id'], $creds['token'], $phone, $imageUrl, $imageCaption);
                if (!empty($result['success'])) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                }
            }

            $body = $imageUrl === '' ? ('*' . $name . "*\n" . $priceLine) : $name . ' — ' . $priceLine;
            $desc = trim((string) ($product['description'] ?? ''));
            if ($desc !== '') {
                $body .= "\n\n" . mb_substr($desc, 0, 280);
            }

            $result = send_whatsapp_reply_buttons(
                $creds['phone_id'],
                $creds['token'],
                $phone,
                $body,
                [['id' => 'add_' . $idx, 'title' => 'Add to cart']]
            );
            if (!empty($result['success'])) {
                $stats['sent']++;
            } else {
                $fallbackText = $body . "\n\nReply *add #{$idx}* to order.";
                $result = send_whatsapp_message($creds['phone_id'], $creds['token'], $phone, $fallbackText);
                if (!empty($result['success'])) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                }
            }
        } else {
            foreach ($fallbackItems as $item) {
                $idx = (int) $item['index'];
                $product = $item['product'];
                $name = trim((string) ($product['name'] ?? 'Product'));
                $priceLine = catalog_format_price(
                    (float) ($product['price'] ?? 0),
                    (string) ($product['currency'] ?? 'PKR')
                );
                $imageUrl = trim((string) ($product['image_url'] ?? ''));
                if ($imageUrl !== '') {
                    $result = send_whatsapp_image(
                        $creds['phone_id'],
                        $creds['token'],
                        $phone,
                        $imageUrl,
                        '*' . $name . "*\n" . $priceLine
                    );
                    if (!empty($result['success'])) {
                        $stats['sent']++;
                    } else {
                        $stats['failed']++;
                    }
                }
            }

            $rows = [];
            foreach ($fallbackItems as $item) {
                $idx = (int) $item['index'];
                $product = $item['product'];
                $rows[] = [
                    'id'          => 'add_' . $idx,
                    'title'       => mb_substr(trim((string) ($product['name'] ?? 'Product')), 0, 24),
                    'description' => catalog_format_price(
                        (float) ($product['price'] ?? 0),
                        (string) ($product['currency'] ?? 'PKR')
                    ),
                ];
            }

            $result = send_whatsapp_interactive_list(
                $creds['phone_id'],
                $creds['token'],
                $phone,
                'Tap a product below to add it to your cart.',
                'Choose item',
                [['title' => 'Recommended', 'rows' => $rows]]
            );
            if (!empty($result['success'])) {
                $stats['sent']++;
            } else {
                $lines = ["*Recommended for you:*"];
                foreach ($fallbackItems as $item) {
                    $idx = (int) $item['index'];
                    $product = $item['product'];
                    $lines[] = "#{$idx} " . trim((string) ($product['name'] ?? 'Product')) . ' — ' . catalog_format_price(
                        (float) ($product['price'] ?? 0),
                        (string) ($product['currency'] ?? 'PKR')
                    );
                }
                $lines[] = '';
                $lines[] = 'Tap an item above, or tap *View menu* to browse.';
                $result = send_whatsapp_message($creds['phone_id'], $creds['token'], $phone, implode("\n", $lines));
                if (!empty($result['success'])) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                }
            }
        }
    }

    if ($metaItems !== [] && $catalogId !== '') {
        if (count($metaItems) === 1) {
            $result = send_whatsapp_catalog_product(
                $creds['phone_id'],
                $creds['token'],
                $phone,
                $catalogId,
                $metaItems[0]['product_retailer_id'],
                'Tap to view this product'
            );
        } else {
            $result = send_whatsapp_catalog_product_list(
                $creds['phone_id'],
                $creds['token'],
                $phone,
                $catalogId,
                [['title' => 'Recommended', 'product_items' => $metaItems]],
                'Recommended for you',
                'Tap a product to view details'
            );
        }

        if (!empty($result['success'])) {
            $stats['sent'] += count($metaItems);
        } else {
            $stats['failed'] += count($metaItems);
        }
    }

    return $stats;
}

/**
 * Shop action buttons (View menu / My cart / Checkout) — tap instead of typing commands.
 *
 * @return array{success: bool, message?: string}
 */
function catalog_send_shop_action_buttons(int $botId, int $userId, string $phone): array
{
    require_once __DIR__ . '/whatsapp-shop-ux.php';

    return whatsapp_shop_send_nav_buttons($botId, $userId, $phone, 0);
}
