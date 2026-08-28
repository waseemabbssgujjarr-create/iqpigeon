<?php
/**
 * Restaurant menu card — one WhatsApp image with top food items (name + price + photo grid).
 */

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/catalog-image.php';
require_once __DIR__ . '/domain.php';

const RESTAURANT_MENU_CARD_WIDTH = 1080;
const RESTAURANT_MENU_CARD_COLS = 2;
const RESTAURANT_MENU_CARD_MAX_ITEMS = 8;
const RESTAURANT_MENU_CARD_CELL_H = 248;
const RESTAURANT_MENU_CARD_HEADER_H = 124;
const RESTAURANT_MENU_CARD_FOOTER_H = 44;
const RESTAURANT_MENU_CARD_THUMB_W = 200;
const RESTAURANT_MENU_CARD_THUMB_H = 128;

function restaurant_menu_card_gd_available(): bool
{
    return catalog_image_has_gd();
}

function catalog_bot_is_restaurant(int $botId): bool
{
    $bot = db_fetch(
        'SELECT industry_key, business_mode, business_model FROM bots WHERE id = ?',
        'i',
        [$botId]
    );
    if (!$bot) {
        return false;
    }

    $key = mb_strtolower(trim((string) ($bot['industry_key'] ?? '')));
    if ($key === 'restaurant') {
        return true;
    }

    $mode = mb_strtolower(trim((string) ($bot['business_mode'] ?? '')));
    if ($mode === 'restaurant') {
        return true;
    }

    $model = mb_strtolower(trim((string) ($bot['business_model'] ?? '')));

    return preg_match(
        '/\b(restaurant|food|pizza|burger|cafe|café|kitchen|dining|takeaway|take-away|biryani|bbq|bakery|eatery)\b/u',
        $model
    ) === 1;
}

/**
 * Parse [MENU:1,2,3] tags from AI reply.
 *
 * @return array<int, int> 1-based catalog indexes
 */
function catalog_parse_menu_tags(string $text): array
{
    if (!preg_match_all('/\[MENU:([\d,\s]+)\]/i', $text, $matches)) {
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
 * Pick top food items for a menu card (prefer items with photos).
 *
 * @param array<int, int> $preferred 1-based indexes from AI tags
 * @return array<int, int>
 */
function catalog_top_food_indexes(int $botId, array $preferred = [], int $limit = 6): array
{
    $limit = max(2, min(RESTAURANT_MENU_CARD_MAX_ITEMS, $limit));
    $products = catalog_products_for_bot($botId);
    if ($products === []) {
        return [];
    }

    $picked = [];
    foreach ($preferred as $idx) {
        $idx = (int) $idx;
        if ($idx > 0 && isset($products[$idx - 1]) && !in_array($idx, $picked, true)) {
            $picked[] = $idx;
        }
        if (count($picked) >= $limit) {
            return $picked;
        }
    }

    $withImage = [];
    $withoutImage = [];
    foreach ($products as $i => $product) {
        $idx = $i + 1;
        if (in_array($idx, $picked, true)) {
            continue;
        }
        if (trim((string) ($product['image_url'] ?? '')) !== '') {
            $withImage[] = $idx;
        } else {
            $withoutImage[] = $idx;
        }
    }

    foreach ([...$withImage, ...$withoutImage] as $idx) {
        $picked[] = $idx;
        if (count($picked) >= $limit) {
            break;
        }
    }

    return $picked;
}

/**
 * Saved menu cards from Train → Menu tab.
 *
 * @return list<array{id: string, title: string, category: string, product_ids: list<int>}>
 */
function training_menu_cards_for_bot(int $botId): array
{
    require_once __DIR__ . '/bot-knowledge.php';

    $bot = db_fetch('SELECT training_meta FROM bots WHERE id = ?', 'i', [$botId]);
    if (!$bot) {
        return [];
    }

    $meta = bot_training_meta($bot);
    $cards = (array) ($meta['menu_cards'] ?? []);

    return array_values(array_filter($cards, static fn (array $card): bool => !empty($card['product_ids'])));
}

/**
 * @return array<int, int> product id => 1-based catalog index
 */
function catalog_product_id_index_map(int $botId): array
{
    $map = [];
    foreach (catalog_products_for_bot($botId) as $i => $product) {
        $id = (int) ($product['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $i + 1;
        }
    }

    return $map;
}

/**
 * @param list<int> $productIds
 * @return array<int, int> 1-based catalog indexes
 */
function catalog_product_ids_to_indexes(int $botId, array $productIds): array
{
    $map = catalog_product_id_index_map($botId);
    $indexes = [];
    foreach ($productIds as $pid) {
        $idx = $map[(int) $pid] ?? 0;
        if ($idx > 0 && !in_array($idx, $indexes, true)) {
            $indexes[] = $idx;
        }
    }

    return $indexes;
}

/**
 * Normalize a customer query for menu-card matching.
 */
function catalog_menu_card_normalize_query(string $query): string
{
    $query = mb_strtolower(trim($query));
    $query = preg_replace(
        '/\b(menu|card|catalog|catalogue|any|please|show|see|list|options|items|available|have|got|what|which|do you|is there|there|the|a|an|some|about|for|in|of)\b/u',
        ' ',
        $query
    ) ?? $query;
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    $query = str_replace(['broast', 'arabic'], ['roast', 'arabian'], $query);

    return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
}

/**
 * Match a saved menu card to a customer query (category / item name).
 *
 * @return array{title: string, category: string, indexes: array<int, int>}|null
 */
function catalog_match_menu_card(int $botId, string $query): ?array
{
    $cards = training_menu_cards_for_bot($botId);
    if ($cards === []) {
        return null;
    }

    $queryNorm = catalog_menu_card_normalize_query($query);
    if ($queryNorm === '' && mb_strlen(trim($query)) >= 2) {
        $queryNorm = mb_strtolower(trim($query));
    }
    if ($queryNorm === '') {
        return null;
    }

    $products = catalog_products_for_bot($botId);
    $queryTerms = array_values(array_filter(
        preg_split('/\s+/u', $queryNorm) ?: [],
        static fn (string $term): bool => mb_strlen($term) >= 2
    ));

    $best = null;
    $bestScore = 0.0;

    foreach ($cards as $card) {
        $title = mb_strtolower(trim((string) ($card['title'] ?? '')));
        $category = mb_strtolower(trim((string) ($card['category'] ?? '')));
        $score = 0.0;

        if ($title !== '' && ($queryNorm === $title || str_contains($queryNorm, $title) || str_contains($title, $queryNorm))) {
            $score += 90;
        }
        if ($category !== '' && ($queryNorm === $category || str_contains($queryNorm, $category) || str_contains($category, $queryNorm))) {
            $score += 85;
        }

        similar_text($queryNorm, $title, $titlePct);
        $score += $titlePct * 0.75;
        similar_text($queryNorm, $category, $catPct);
        $score += $catPct * 0.65;

        $termHit = false;
        foreach ($queryTerms as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }
            if (($title !== '' && str_contains($title, $term))
                || ($category !== '' && str_contains($category, $term))
            ) {
                $termHit = true;
                $score += 18;
            }
            if ($category !== '' && str_contains($category, $term)) {
                $score += 16;
            }
        }

        foreach ((array) ($card['product_ids'] ?? []) as $pid) {
            foreach ($products as $product) {
                if ((int) ($product['id'] ?? 0) !== (int) $pid) {
                    continue;
                }
                $name = mb_strtolower(trim((string) ($product['name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                if (str_contains($name, $queryNorm) || str_contains($queryNorm, $name)) {
                    $score += 45;
                    $termHit = true;
                }
                foreach ($queryTerms as $term) {
                    if (mb_strlen($term) >= 3 && str_contains($name, $term)) {
                        $score += 12;
                        $termHit = true;
                    }
                }
            }
        }

        $strong = ($title !== '' && ($queryNorm === $title || str_contains($queryNorm, $title) || str_contains($title, $queryNorm)))
            || ($category !== '' && ($queryNorm === $category || str_contains($queryNorm, $category) || str_contains($category, $queryNorm)));

        if (!$strong && !$termHit) {
            continue;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $card;
        }
    }

    if ($best === null || $bestScore < 28) {
        return null;
    }

    $indexes = catalog_product_ids_to_indexes($botId, (array) ($best['product_ids'] ?? []));
    if (count($indexes) < 2) {
        return null;
    }

    return [
        'title'    => trim((string) ($best['title'] ?? $best['category'] ?? 'Menu')),
        'category' => trim((string) ($best['category'] ?? '')),
        'indexes'  => array_slice($indexes, 0, RESTAURANT_MENU_CARD_MAX_ITEMS),
    ];
}

/**
 * Resolve the best menu card for a customer message.
 *
 * @return array{title: string, category: string, indexes: array<int, int>}|null
 */
function catalog_menu_card_for_message(int $botId, string $message): ?array
{
    require_once __DIR__ . '/catalog.php';

    $query = catalog_extract_product_query($message);
    if (mb_strlen($query) >= 2) {
        $matched = catalog_match_menu_card($botId, $query);
        if ($matched !== null) {
            return $matched;
        }
    }

    return catalog_match_menu_card($botId, $message);
}

/**
 * Default menu card when customer asks generically for "menu".
 *
 * @return array{title: string, indexes: array<int, int>}|null
 */
function catalog_default_menu_card(int $botId): ?array
{
    $cards = training_menu_cards_for_bot($botId);
    if ($cards === []) {
        return null;
    }

    foreach ($cards as $card) {
        $title = mb_strtolower(trim((string) ($card['title'] ?? '')));
        if (str_contains($title, 'highlight') || str_contains($title, 'popular') || str_contains($title, 'best')) {
            $indexes = catalog_product_ids_to_indexes($botId, (array) ($card['product_ids'] ?? []));
            if (count($indexes) >= 2) {
                return [
                    'title'   => trim((string) ($card['title'] ?? 'Menu highlights')),
                    'indexes' => array_slice($indexes, 0, RESTAURANT_MENU_CARD_MAX_ITEMS),
                ];
            }
        }
    }

    $first = $cards[0];
    $indexes = catalog_product_ids_to_indexes($botId, (array) ($first['product_ids'] ?? []));
    if (count($indexes) < 2) {
        return null;
    }

    return [
        'title'   => trim((string) ($first['title'] ?? $first['category'] ?? 'Menu')),
        'indexes' => array_slice($indexes, 0, RESTAURANT_MENU_CARD_MAX_ITEMS),
    ];
}

/**
 * @return array<int, string>
 */
function catalog_recent_menu_titles(int $leadId): array
{
    if ($leadId <= 0) {
        return [];
    }
    ensure_conversations_schema();
    $rows = db_fetch_all(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 6',
        'i',
        [$leadId]
    );
    $titles = [];
    foreach ($rows as $row) {
        $msg = mb_strtolower((string) ($row['message'] ?? ''));
        if (preg_match('/\b([a-z][a-z0-9 &\'-]{2,40})\s*(?:—|-)?\s*tap an item/u', $msg, $m)) {
            $titles[] = trim($m[1]);
        }
        if (preg_match('/here\'?s our \*([^*]+)\*/u', $msg, $m)) {
            $titles[] = mb_strtolower(trim($m[1]));
        }
    }

    return array_values(array_unique($titles));
}

/**
 * Menu cards to send for this ask — match BBQ/section, otherwise skip cards already sent.
 *
 * @param array<int, int> $indexes
 * @return array<int, array{title: string, indexes: array<int, int>}>
 */
function catalog_menu_cards_to_send(int $botId, string $message, int $leadId = 0, array $indexes = [], string $menuTitle = ''): array
{
    if (catalog_message_is_category_inquiry($message)
        && !catalog_customer_wants_product_visuals($message)
        && !catalog_customer_says_media_missing($message)
        && $indexes === []
    ) {
        return [];
    }

    $wantOtherMenus = catalog_customer_wants_other_menu($message);
    $matched = catalog_menu_card_for_message($botId, $message);

    if ($matched !== null && count($matched['indexes']) >= 2 && !$wantOtherMenus) {
        return [['title' => trim((string) ($matched['title'] ?? 'Menu')), 'indexes' => $matched['indexes']]];
    }

    if ($indexes !== [] && count($indexes) >= 2) {
        return [[
            'title'   => $menuTitle !== '' ? $menuTitle : catalog_title_for_indexes($botId, $indexes),
            'indexes' => array_slice($indexes, 0, RESTAURANT_MENU_CARD_MAX_ITEMS),
        ]];
    }

    if ($wantOtherMenus) {
        $recent = catalog_recent_menu_titles($leadId);
        $out = [];
        $limit = preg_match('/\b3\b|three/iu', $message) ? 3 : 2;
        foreach (training_menu_cards_for_bot($botId) as $card) {
            $title = trim((string) ($card['title'] ?? $card['category'] ?? 'Menu'));
            $titleKey = mb_strtolower($title);
            $cardIndexes = catalog_product_ids_to_indexes($botId, (array) ($card['product_ids'] ?? []));
            if (count($cardIndexes) < 2) {
                continue;
            }
            if ($recent !== [] && in_array($titleKey, $recent, true)) {
                continue;
            }
            $out[] = ['title' => $title, 'indexes' => array_slice($cardIndexes, 0, RESTAURANT_MENU_CARD_MAX_ITEMS)];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    $wantsVisual = catalog_customer_wants_product_visuals($message)
        || catalog_customer_says_media_missing($message)
        || catalog_message_is_menu_request($botId, $message)
        || catalog_message_is_browse_intent($message);

    if (!$wantsVisual) {
        return [];
    }

    if ($matched !== null && count($matched['indexes']) >= 2) {
        return [['title' => trim((string) ($matched['title'] ?? 'Menu')), 'indexes' => $matched['indexes']]];
    }

    $defaultCard = catalog_default_menu_card($botId);
    if ($defaultCard !== null) {
        return [[
            'title'   => trim((string) ($defaultCard['title'] ?? 'Menu highlights')),
            'indexes' => $defaultCard['indexes'],
        ]];
    }

    $top = catalog_top_food_indexes($botId, [], RESTAURANT_MENU_CARD_MAX_ITEMS);
    if (count($top) >= 2) {
        return [['title' => 'Menu highlights', 'indexes' => $top]];
    }

    return [];
}

/**
 * Find menu card title for a set of catalog indexes.
 */
function catalog_title_for_indexes(int $botId, array $indexes): string
{
    $indexes = array_values(array_unique(array_map('intval', $indexes)));
    if ($indexes === []) {
        return 'Menu highlights';
    }

    $cards = training_menu_cards_for_bot($botId);
    if ($cards === []) {
        return 'Menu highlights';
    }

    $bestTitle = '';
    $bestOverlap = 0;
    foreach ($cards as $card) {
        $cardIndexes = catalog_product_ids_to_indexes($botId, (array) ($card['product_ids'] ?? []));
        $overlap = count(array_intersect($indexes, $cardIndexes));
        if ($overlap > $bestOverlap) {
            $bestOverlap = $overlap;
            $bestTitle = trim((string) ($card['title'] ?? $card['category'] ?? ''));
        }
    }

    if ($bestTitle !== '' && $bestOverlap >= 2) {
        return $bestTitle;
    }

    return 'Menu highlights';
}

function restaurant_menu_cards_dir(int $botId): string
{
    $dir = catalog_product_uploads_dir() . '/menu-cards/' . $botId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function restaurant_menu_card_public_url(int $botId, string $filename): string
{
    return catalog_product_image_public_url('menu-cards/' . $botId . '/' . $filename);
}

function restaurant_menu_card_font_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    foreach ([
        __DIR__ . '/../assets/fonts/DejaVuSans.ttf',
        __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
    ] as $path) {
        if (is_readable($path)) {
            $cached = $path;
            return $cached;
        }
    }

    $cached = '';

    return $cached;
}

function restaurant_menu_card_font_bold_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    foreach ([
        __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf',
        __DIR__ . '/../assets/fonts/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        'C:/Windows/Fonts/arial.ttf',
    ] as $path) {
        if (is_readable($path)) {
            $cached = $path;
            return $cached;
        }
    }

    $cached = restaurant_menu_card_font_path();

    return $cached;
}

function restaurant_menu_card_safe_text(string $text): string
{
    $text = trim($text);
    $text = str_replace(
        ["\u{2026}", "\u{2014}", "\u{2013}", "\u{2022}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{20A8}"],
        ['...', '-', '-', '-', "'", "'", '"', '"', 'Rs'],
        $text
    );

    if (restaurant_menu_card_font_path() === '') {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') {
            $text = $ascii;
        }
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
    }

    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/**
 * @return \GdImage|null
 */
function restaurant_menu_card_load_image(string $url)
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $localPath = restaurant_menu_card_local_path_from_url($url);
    if ($localPath !== null && is_readable($localPath)) {
        $mime = '';
        if (function_exists('mime_content_type')) {
            $mime = (string) @mime_content_type($localPath);
        }
        if ($mime === '') {
            $mime = str_ends_with(strtolower($localPath), '.png') ? 'image/png' : 'image/jpeg';
        }

        return catalog_gd_load_image($localPath, catalog_image_normalize_mime($mime));
    }

    $ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 1]]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false || $bytes === '') {
        return null;
    }

    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    return @imagecreatefromstring($bytes) ?: null;
}

function restaurant_menu_card_local_path_from_url(string $url): ?string
{
    $base = rtrim(app_url(), '/');
    if ($base !== '' && str_starts_with($url, $base)) {
        $rel = ltrim(substr($url, strlen($base)), '/');
        if (str_starts_with($rel, 'uploads/catalog/')) {
            $path = dirname(__DIR__) . '/' . $rel;
            if (is_file($path)) {
                return $path;
            }
        }
    }

    return null;
}

/**
 * Fit image inside rectangle preserving original aspect ratio (no square crop).
 *
 * @param \GdImage|resource $img
 * @return \GdImage|resource
 */
function restaurant_menu_card_fit_rect($img, int $maxW, int $maxH)
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) {
        return $img;
    }

    $scale = min($maxW / $w, $maxH / $h);
    $newW = max(1, (int) round($w * $scale));
    $newH = max(1, (int) round($h * $scale));

    $rect = imagecreatetruecolor($maxW, $maxH);
    if ($rect === false) {
        return $img;
    }

    $bg = imagecolorallocate($rect, 255, 248, 240);
    imagefill($rect, 0, 0, $bg);

    $dstX = (int) floor(($maxW - $newW) / 2);
    $dstY = (int) floor(($maxH - $newH) / 2);
    imagecopyresampled($rect, $img, $dstX, $dstY, 0, 0, $newW, $newH, $w, $h);
    imagedestroy($img);

    return $rect;
}

/**
 * @param \GdImage|resource $canvas
 */
function restaurant_menu_card_draw_text($canvas, string $text, int $x, int $y, int $size, int $color, int $maxWidth = 0, bool $bold = false): void
{
    $text = restaurant_menu_card_safe_text($text);
    if ($text === '') {
        return;
    }

    $font = $bold ? restaurant_menu_card_font_bold_path() : restaurant_menu_card_font_path();
    if ($font !== '' && function_exists('imagettftext')) {
        if ($maxWidth > 0 && function_exists('imagettfbbox')) {
            while ($text !== '') {
                $box = imagettfbbox($size, 0, $font, $text);
                $textWidth = abs(($box[2] ?? 0) - ($box[0] ?? 0));
                if ($textWidth <= $maxWidth || mb_strlen($text) <= 4) {
                    break;
                }
                $text = rtrim(mb_substr($text, 0, mb_strlen($text) - 4)) . '...';
            }
        }
        imagettftext($canvas, $size, 0, $x, $y, $color, $font, $text);

        return;
    }

    $builtinSize = min(5, max(3, (int) round($size / 5)));
    $line = $maxWidth > 0 ? restaurant_menu_card_truncate_builtin($text, 36) : $text;
    imagestring($canvas, $builtinSize, $x, $y - ($builtinSize * 6), $line, $color);
}

function restaurant_menu_card_truncate_builtin(string $text, int $maxChars): string
{
    $text = restaurant_menu_card_safe_text($text);
    if (mb_strlen($text) <= $maxChars) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, max(4, $maxChars - 3))) . '...';
}

function restaurant_menu_card_list_title(int $idx, string $name): string
{
    $prefix = '#' . $idx . ' ';
    $room = max(8, 24 - mb_strlen($prefix));
    $short = mb_substr(trim($name), 0, $room);
    if (mb_strlen(trim($name)) > $room) {
        $short = rtrim($short) . '..';
    }

    return $prefix . $short;
}

function restaurant_menu_card_list_description(string $name, string $price): string
{
    $label = trim($name);
    if (mb_strlen($label) > 42) {
        $label = rtrim(mb_substr($label, 0, 39)) . '...';
    }

    return $label . ' — ' . $price;
}

/**
 * Validate catalog indexes without padding with unrelated products.
 *
 * @param array<int, int> $indexes
 * @return array<int, int>
 */
function restaurant_menu_card_validate_indexes(int $botId, array $indexes, int $limit = RESTAURANT_MENU_CARD_MAX_ITEMS): array
{
    $products = catalog_products_for_bot($botId);
    $valid = [];
    foreach ($indexes as $idx) {
        $idx = (int) $idx;
        if ($idx > 0 && isset($products[$idx - 1]) && !in_array($idx, $valid, true)) {
            $valid[] = $idx;
        }
        if (count($valid) >= $limit) {
            break;
        }
    }

    return $valid;
}

/**
 * Build one menu-card JPEG for WhatsApp.
 *
 * @param array<int, int> $indexes 1-based
 * @return array{success: bool, url?: string, path?: string, error?: string, indexes?: array<int, int>}
 */
function restaurant_menu_card_generate(int $botId, int $userId, array $indexes, string $title = ''): array
{
    if (!restaurant_menu_card_gd_available()) {
        return ['success' => false, 'error' => 'Image library unavailable on server'];
    }

    $validated = restaurant_menu_card_validate_indexes($botId, $indexes, RESTAURANT_MENU_CARD_MAX_ITEMS);
    if (count($validated) >= 2) {
        $indexes = $validated;
    } else {
        $indexes = catalog_top_food_indexes($botId, $indexes, RESTAURANT_MENU_CARD_MAX_ITEMS);
    }
    if (count($indexes) < 2) {
        return ['success' => false, 'error' => 'Need at least 2 items for menu card'];
    }

    $products = catalog_products_for_bot($botId);
    $bot = db_fetch('SELECT name FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    $shopName = restaurant_menu_card_safe_text(trim((string) ($bot['name'] ?? 'Our Menu')));
    if ($title === '') {
        $title = 'Menu highlights';
    }
    $title = restaurant_menu_card_safe_text($title);

    $rows = (int) ceil(count($indexes) / RESTAURANT_MENU_CARD_COLS);
    $height = RESTAURANT_MENU_CARD_HEADER_H + ($rows * RESTAURANT_MENU_CARD_CELL_H) + RESTAURANT_MENU_CARD_FOOTER_H;

    $canvas = imagecreatetruecolor(RESTAURANT_MENU_CARD_WIDTH, $height);
    if ($canvas === false) {
        return ['success' => false, 'error' => 'Could not create menu canvas'];
    }

    $cream = imagecolorallocate($canvas, 255, 248, 240);
    $headerBg = imagecolorallocate($canvas, 139, 21, 56);
    $headerAccent = imagecolorallocate($canvas, 255, 183, 77);
    $titleColor = imagecolorallocate($canvas, 255, 255, 255);
    $subtitleColor = imagecolorallocate($canvas, 255, 224, 178);
    $nameColor = imagecolorallocate($canvas, 45, 24, 16);
    $priceColor = imagecolorallocate($canvas, 139, 21, 56);
    $indexColor = imagecolorallocate($canvas, 120, 90, 70);
    $borderColor = imagecolorallocate($canvas, 230, 210, 195);
    $placeholderBg = imagecolorallocate($canvas, 238, 228, 218);
    $footerColor = imagecolorallocate($canvas, 100, 70, 55);

    imagefill($canvas, 0, 0, $cream);
    imagefilledrectangle($canvas, 0, 0, RESTAURANT_MENU_CARD_WIDTH, RESTAURANT_MENU_CARD_HEADER_H, $headerBg);
    imagefilledrectangle($canvas, 0, RESTAURANT_MENU_CARD_HEADER_H - 6, RESTAURANT_MENU_CARD_WIDTH, RESTAURANT_MENU_CARD_HEADER_H, $headerAccent);

    restaurant_menu_card_draw_text($canvas, $shopName, 40, 54, 36, $titleColor, RESTAURANT_MENU_CARD_WIDTH - 80, true);
    restaurant_menu_card_draw_text($canvas, $title, 40, 92, 24, $subtitleColor, RESTAURANT_MENU_CARD_WIDTH - 80, true);

    $colWidth = (int) floor(RESTAURANT_MENU_CARD_WIDTH / RESTAURANT_MENU_CARD_COLS);
    $padX = 28;
    $padY = RESTAURANT_MENU_CARD_HEADER_H + 12;

    foreach ($indexes as $slot => $idx) {
        $product = $products[$idx - 1] ?? null;
        if (!$product) {
            continue;
        }

        $col = $slot % RESTAURANT_MENU_CARD_COLS;
        $row = (int) floor($slot / RESTAURANT_MENU_CARD_COLS);
        $x = $col * $colWidth + $padX;
        $y = $padY + ($row * RESTAURANT_MENU_CARD_CELL_H);

        $cellW = $colWidth - ($padX * 2);
        $thumbX = $x + (int) floor(($cellW - RESTAURANT_MENU_CARD_THUMB_W) / 2);
        $thumbY = $y;

        imagefilledrectangle(
            $canvas,
            $thumbX - 2,
            $thumbY - 2,
            $thumbX + RESTAURANT_MENU_CARD_THUMB_W + 2,
            $thumbY + RESTAURANT_MENU_CARD_THUMB_H + 2,
            $borderColor
        );

        $photo = restaurant_menu_card_load_image((string) ($product['image_url'] ?? ''));
        if ($photo !== null) {
            $photo = restaurant_menu_card_fit_rect($photo, RESTAURANT_MENU_CARD_THUMB_W, RESTAURANT_MENU_CARD_THUMB_H);
            imagecopy($canvas, $photo, $thumbX, $thumbY, 0, 0, RESTAURANT_MENU_CARD_THUMB_W, RESTAURANT_MENU_CARD_THUMB_H);
            imagedestroy($photo);
        } else {
            imagefilledrectangle(
                $canvas,
                $thumbX,
                $thumbY,
                $thumbX + RESTAURANT_MENU_CARD_THUMB_W,
                $thumbY + RESTAURANT_MENU_CARD_THUMB_H,
                $placeholderBg
            );
            restaurant_menu_card_draw_text($canvas, 'No photo', $thumbX + 52, $thumbY + 72, 16, $indexColor);
        }

        $name = trim((string) ($product['name'] ?? 'Item'));
        $price = catalog_format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'PKR'));

        restaurant_menu_card_draw_text($canvas, '#' . $idx . ' ' . $name, $x, $thumbY + RESTAURANT_MENU_CARD_THUMB_H + 28, 22, $nameColor, $cellW, true);
        restaurant_menu_card_draw_text($canvas, $price, $x, $thumbY + RESTAURANT_MENU_CARD_THUMB_H + 56, 20, $priceColor, $cellW, true);
    }

    $footerY = $height - 18;
    restaurant_menu_card_draw_text(
        $canvas,
        'Tap an item in the list below to order',
        40,
        $footerY,
        16,
        $footerColor,
        RESTAURANT_MENU_CARD_WIDTH - 80
    );

    $hash = substr(hash('sha256', $botId . '|' . implode(',', $indexes) . '|' . $title), 0, 16);
    $filename = 'menu_' . $hash . '.jpg';
    $dir = restaurant_menu_cards_dir($botId);
    $path = $dir . '/' . $filename;

    if (!@imagejpeg($canvas, $path, 90)) {
        imagedestroy($canvas);

        return ['success' => false, 'error' => 'Could not save menu card image'];
    }
    imagedestroy($canvas);

    return [
        'success' => true,
        'url'     => restaurant_menu_card_public_url($botId, $filename),
        'path'    => $path,
        'indexes' => $indexes,
    ];
}

/**
 * Send one menu-card image on WhatsApp (restaurants).
 *
 * @param array<int, int> $indexes
 * @return array{sent: int, failed: int}
 */
function catalog_send_restaurant_menu_card(int $botId, int $userId, string $phone, array $indexes, string $title = '', string $sectionTitle = ''): array
{
    require_once __DIR__ . '/whatsapp.php';

    $stats = ['sent' => 0, 'failed' => 0];
    if ($indexes === []) {
        return $stats;
    }

    $creds = whatsapp_bot_credentials($botId, $userId);
    if (!$creds) {
        return $stats;
    }

    if ($title === '') {
        $title = catalog_title_for_indexes($botId, $indexes);
    }
    if ($sectionTitle === '') {
        $sectionTitle = $title !== '' ? $title : 'Menu';
    }
    $sectionTitle = restaurant_menu_card_safe_text(mb_substr($sectionTitle, 0, 24));

    $generated = restaurant_menu_card_generate($botId, $userId, $indexes, $title);
    if (empty($generated['success']) || empty($generated['url'])) {
        return $stats;
    }

    $products = catalog_products_for_bot($botId);
    $caption = restaurant_menu_card_safe_text(trim((string) ($title !== '' ? $title : 'Menu highlights')))
        . ' — tap an item below to add it.';

    $result = send_whatsapp_image($creds['phone_id'], $creds['token'], $phone, (string) $generated['url'], $caption);
    if (!empty($result['success'])) {
        $stats['sent']++;
    } else {
        $stats['failed']++;

        return $stats;
    }

    $rows = [];
    foreach (array_slice($generated['indexes'] ?? $indexes, 0, 10) as $idx) {
        $idx = (int) $idx;
        $product = $products[$idx - 1] ?? null;
        if (!$product) {
            continue;
        }
        $name = trim((string) ($product['name'] ?? 'Item'));
        $price = catalog_format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'PKR'));
        $rows[] = [
            'id'          => 'add_' . $idx,
            'title'       => restaurant_menu_card_list_title($idx, $name),
            'description' => restaurant_menu_card_list_description($name, $price),
        ];
    }

    if ($rows !== []) {
        $list = send_whatsapp_interactive_list(
            $creds['phone_id'],
            $creds['token'],
            $phone,
            'Tap an item to add it to your cart.',
            'Order from menu',
            [['title' => $sectionTitle, 'rows' => $rows]]
        );
        if (!empty($list['success'])) {
            $stats['sent']++;
        }
    }

    return $stats;
}

/**
 * Whether this suggestion should be delivered as one menu image.
 *
 * @param array<int, int> $indexes
 */
function catalog_should_send_menu_card(int $botId, array $indexes, bool $menuTagUsed = false): bool
{
    if (!catalog_bot_is_restaurant($botId) || count($indexes) < 2) {
        return false;
    }

    if ($menuTagUsed) {
        return true;
    }

    return count($indexes) >= 2 && restaurant_menu_card_gd_available();
}
