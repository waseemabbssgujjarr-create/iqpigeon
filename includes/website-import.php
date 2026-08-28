<?php
/**
 * Import products from a store website URL into bot_products (no API keys required).
 * Supports Shopify public JSON, WooCommerce Store API, JSON-LD / HTML scraping.
 */

if (defined('IQ_WEBSITE_IMPORT_LOADED')) {
    return;
}
define('IQ_WEBSITE_IMPORT_LOADED', true);

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/shop-integrations.php';

const WEBSITE_IMPORT_MAX_PRODUCTS = 200;
const WEBSITE_IMPORT_MAX_PAGES = 12;
const WEBSITE_IMPORT_FETCH_TIMEOUT = 25;
const WEBSITE_IMPORT_PREVIEW_MAX_PRODUCTS = 40;
const WEBSITE_IMPORT_PREVIEW_MAX_PAGES = 2;
const WEBSITE_IMPORT_PREVIEW_MAX_GENERIC_FETCHES = 6;
const WEBSITE_IMPORT_PREVIEW_FETCH_TIMEOUT = 12;
const WEBSITE_IMPORT_BATCH_SIZE = 25;
const WEBSITE_IMPORT_JOB_TTL = 3600;
const WEBSITE_IMPORT_GENERIC_MAX_FETCHES = 8;

/**
 * @param array{max_products?: int, max_pages?: int, max_generic_fetches?: int, fetch_timeout?: int} $limits
 * @return mixed
 */
function website_import_with_limits(array $limits, callable $callback)
{
    $previous = $GLOBALS['_website_import_limits'] ?? null;
    $GLOBALS['_website_import_limits'] = $limits;
    try {
        return $callback();
    } finally {
        $GLOBALS['_website_import_limits'] = $previous;
    }
}

function website_import_limit(string $key, int $default): int
{
    $limits = $GLOBALS['_website_import_limits'] ?? [];

    return (int) ($limits[$key] ?? $default);
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_fetch_products(string $url): array
{
    $parsed = website_import_parse_url($url);
    if (!$parsed['valid']) {
        throw new InvalidArgumentException('Enter a valid website URL (e.g. https://yourstore.com).');
    }

    $base = $parsed['base'];
    $home = website_import_http_get($base . '/');
    $homeHtml = $home['body'] ?? '';
    $maxProducts = website_import_limit('max_products', WEBSITE_IMPORT_MAX_PRODUCTS);

    $products = website_import_fetch_shopify_public($base);
    if ($products !== []) {
        return array_slice($products, 0, $maxProducts);
    }

    $products = website_import_fetch_woocommerce_public($base);
    if ($products !== []) {
        return array_slice($products, 0, $maxProducts);
    }

    $products = website_import_fetch_shopify_collections_json($base);
    if ($products !== []) {
        return array_slice($products, 0, $maxProducts);
    }

    $products = website_import_fetch_indolj($base, $homeHtml);
    if ($products !== []) {
        return array_slice($products, 0, $maxProducts);
    }

    if (website_import_is_indolj_html($homeHtml)) {
        throw new RuntimeException(
            'This site uses Indolj (restaurant menu platform). Could not read the menu — try Preview first, or contact support.'
        );
    }

    $products = website_import_fetch_generic($base, $homeHtml);
    if ($products === []) {
        throw new RuntimeException('No products found on this website. If the store is password-protected, use Integrations with API keys.');
    }

    return array_slice($products, 0, $maxProducts);
}

/**
 * @return array{valid: bool, url: string, base: string, host: string}
 */
function website_import_parse_url(string $url): array
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return ['valid' => false, 'url' => '', 'base' => '', 'host' => ''];
    }

    $scheme = strtolower($parts['scheme'] ?? 'https');
    $host = strtolower($parts['host']);
    $base = $scheme . '://' . $host;

    return [
        'valid' => true,
        'url'   => $base . (isset($parts['path']) && $parts['path'] !== '' ? rtrim($parts['path'], '/') : ''),
        'base'  => $base,
        'host'  => $host,
    ];
}

function website_import_detect_platform(string $url, string $html = ''): string
{
    $parsed = website_import_parse_url($url);
    if (!$parsed['valid']) {
        return 'generic';
    }

    if (str_contains($parsed['host'], 'myshopify.com')) {
        return 'shopify';
    }

    $hay = strtolower($html);
    if ($hay !== '') {
        if (str_contains($hay, 'cdn.shopify.com') || str_contains($hay, 'shopify.theme') || preg_match('/shopify\.shop\s*=/i', $html)) {
            return 'shopify';
        }
        if (str_contains($hay, 'woocommerce') || str_contains($hay, 'wc-block') || str_contains($hay, 'wp-content/plugins/woocommerce')) {
            return 'woocommerce';
        }
        if (website_import_is_indolj_html($html)) {
            return 'indolj';
        }
    }

    if (website_import_shopify_json_works($parsed['base'])) {
        return 'shopify';
    }

    $wooProbe = website_import_http_get($parsed['base'] . '/wp-json/wc/store/v1/products?per_page=1');
    if ($wooProbe['code'] === 200 && str_contains($wooProbe['body'], '"id"')) {
        return 'woocommerce';
    }

    return 'generic';
}

function website_import_shopify_json_works(string $baseUrl): bool
{
    $response = website_import_http_get(rtrim($baseUrl, '/') . '/products.json?limit=1');
    return $response['code'] === 200 && str_contains($response['body'], '"products"');
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_fetch_shopify_public(string $baseUrl): array
{
    $out = [];
    $base = rtrim($baseUrl, '/');
    $seenIds = [];

    $endpoints = [
        $base . '/products.json?limit=250',
    ];

    foreach ($endpoints as $startUrl) {
        $nextUrl = $startUrl;
        $pages = 0;

        while ($nextUrl !== '' && $pages < website_import_limit('max_pages', WEBSITE_IMPORT_MAX_PAGES) && count($out) < website_import_limit('max_products', WEBSITE_IMPORT_MAX_PRODUCTS)) {
            $response = website_import_http_get($nextUrl);
            if ($response['code'] >= 400) {
                break;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
                break;
            }

            if ($data['products'] === []) {
                break;
            }

            foreach ($data['products'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $id = (string) ($p['id'] ?? '');
                if ($id !== '' && isset($seenIds[$id])) {
                    continue;
                }
                if ($id !== '') {
                    $seenIds[$id] = true;
                }

                $mapped = website_import_map_shopify_product($p, $base);
                if ($mapped) {
                    $out[] = $mapped;
                }
            }

            $nextUrl = website_import_parse_link_header($response['headers'], 'next') ?? '';
            if ($nextUrl === '' && count($data['products']) >= 250) {
                $pages++;
                $nextUrl = $base . '/products.json?limit=250&page=' . ($pages + 1);
            } else {
                $pages++;
            }

            if (count($data['products']) < 250) {
                break;
            }
        }

        if ($out !== []) {
            break;
        }
    }

    return $out;
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_fetch_shopify_collections_json(string $baseUrl): array
{
    $out = [];
    $base = rtrim($baseUrl, '/');
    $seenIds = [];

    $collectionUrls = [
        $base . '/collections/all/products.json?limit=250',
    ];

    $collIndex = website_import_http_get($base . '/collections.json?limit=50');
    if ($collIndex['code'] === 200) {
        $collData = json_decode($collIndex['body'], true);
        foreach ($collData['collections'] ?? [] as $coll) {
            if (!is_array($coll)) {
                continue;
            }
            $handle = (string) ($coll['handle'] ?? '');
            if ($handle !== '' && $handle !== 'all') {
                $collectionUrls[] = $base . '/collections/' . rawurlencode($handle) . '/products.json?limit=250';
            }
        }
    }

    foreach ($collectionUrls as $startUrl) {
        if (count($out) >= website_import_limit('max_products', WEBSITE_IMPORT_MAX_PRODUCTS)) {
            break;
        }

        $response = website_import_http_get($startUrl);
        if ($response['code'] >= 400) {
            continue;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['products'])) {
            continue;
        }

        foreach ($data['products'] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = (string) ($p['id'] ?? '');
            if ($id !== '' && isset($seenIds[$id])) {
                continue;
            }
            if ($id !== '') {
                $seenIds[$id] = true;
            }

            $mapped = website_import_map_shopify_product($p, $base);
            if ($mapped) {
                $out[] = $mapped;
            }
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>|null
 */
function website_import_map_shopify_product(array $p, string $baseUrl): ?array
{
    $title = trim((string) ($p['title'] ?? ''));
    if ($title === '') {
        return null;
    }

    $variants = is_array($p['variants'] ?? null) ? $p['variants'] : [];
    $variant = is_array($variants[0] ?? null) ? $variants[0] : [];

    $price = PHP_FLOAT_MAX;
    foreach ($variants as $v) {
        if (!is_array($v)) {
            continue;
        }
        $vp = (float) ($v['price'] ?? 0);
        if ($vp > 0 && $vp < $price) {
            $price = $vp;
            $variant = $v;
        }
    }
    if ($price === PHP_FLOAT_MAX) {
        $price = (float) ($variant['price'] ?? 0);
    }

    $handle = (string) ($p['handle'] ?? $p['id'] ?? '');
    $image = (string) ($p['image']['src'] ?? ($p['images'][0]['src'] ?? ''));
    $description = website_import_clean_html((string) ($p['body_html'] ?? ''));

    return website_import_normalize_product([
        'external_id' => (string) ($p['id'] ?? $handle),
        'name'        => $title,
        'description' => $description,
        'price'       => $price,
        'currency'    => website_import_guess_currency($baseUrl, $variant),
        'image_url'   => $image,
        'sku'         => (string) ($variant['sku'] ?? ''),
        'category'    => (string) ($p['product_type'] ?? ''),
        'stock'       => isset($variant['inventory_quantity']) ? (int) $variant['inventory_quantity'] : null,
        'source_url'  => $handle !== '' ? rtrim($baseUrl, '/') . '/products/' . $handle : '',
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_fetch_woocommerce_public(string $baseUrl): array
{
    $out = [];
    $base = rtrim($baseUrl, '/');
    $seenIds = [];

    $apiPaths = [
        '/wp-json/wc/store/v1/products?per_page=100',
        '/wp-json/wc/v3/products?per_page=100&status=publish',
    ];

    foreach ($apiPaths as $apiPath) {
        for ($page = 1; $page <= website_import_limit('max_pages', WEBSITE_IMPORT_MAX_PAGES) && count($out) < website_import_limit('max_products', WEBSITE_IMPORT_MAX_PRODUCTS); $page++) {
            $url = $base . $apiPath . (str_contains($apiPath, '?') ? '&page=' : '?page=') . $page;
            $response = website_import_http_get($url);
            if ($response['code'] >= 400) {
                break;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $id = (string) ($p['id'] ?? $p['slug'] ?? '');
                if ($id !== '' && isset($seenIds[$id])) {
                    continue;
                }
                if ($id !== '') {
                    $seenIds[$id] = true;
                }

                $prices = is_array($p['prices'] ?? null) ? $p['prices'] : [];
                $priceRaw = $prices['price'] ?? $p['price'] ?? $p['regular_price'] ?? 0;
                $price = website_import_parse_price($priceRaw);
                if ($price <= 0 && isset($prices['regular_price'])) {
                    $price = website_import_parse_price($prices['regular_price']);
                }

                $images = is_array($p['images'] ?? null) ? $p['images'] : [];
                $image = is_array($images[0] ?? null) ? (string) ($images[0]['src'] ?? $images[0]['thumbnail'] ?? '') : (string) ($images[0] ?? '');

                $cats = is_array($p['categories'] ?? null) ? $p['categories'] : [];
                $category = is_array($cats[0] ?? null) ? (string) ($cats[0]['name'] ?? '') : '';

                $desc = strip_tags((string) ($p['short_description'] ?? $p['description'] ?? ''));
                if ($desc === '' && isset($p['description'])) {
                    $desc = website_import_clean_html((string) $p['description']);
                }

                $out[] = website_import_normalize_product([
                    'external_id' => $id,
                    'name'        => (string) ($p['name'] ?? 'Product'),
                    'description' => $desc,
                    'price'       => $price,
                    'currency'    => strtoupper((string) ($prices['currency_code'] ?? $prices['currency'] ?? default_currency())),
                    'image_url'   => $image,
                    'sku'         => (string) ($p['sku'] ?? ''),
                    'category'    => $category,
                    'stock'       => ($p['is_in_stock'] ?? true) ? null : 0,
                    'source_url'  => (string) ($p['permalink'] ?? $p['link'] ?? ''),
                ]);
            }

            if (count($data) < 100) {
                break;
            }
        }

        if ($out !== []) {
            break;
        }
    }

    return $out;
}

// ── Indolj (Next.js restaurant menus — thesicilian.pk, etc.) ─────────────────

function website_import_is_indolj_html(string $html): bool
{
    if ($html === '') {
        return false;
    }

    return str_contains($html, 'assets.indolj.io')
        || str_contains($html, 'console.indolj.io')
        || preg_match('/"merchant_id"\s*:\s*\d+/i', $html) === 1
        || preg_match('/\\"merchant_id\\"\s*:\s*\d+/i', $html) === 1
        || preg_match('/"merchantId"\s*:\s*\d+/i', $html) === 1
        || preg_match('/\\"merchantId\\"\s*:\s*\d+/i', $html) === 1;
}

/**
 * @return list<string>
 */
function website_import_indolj_html_sources(string $html): array
{
    $sources = [$html];

    if (preg_match_all('/self\.__next_f\.push\(\[1,"((?:\\\\.|[^"\\\\])*)"\]\)/s', $html, $chunks)) {
        $sources[] = stripcslashes(implode("\n", $chunks[1]));
    }

    $unescaped = stripcslashes($html);
    if ($unescaped !== $html) {
        $sources[] = $unescaped;
    }

    return array_values(array_unique(array_filter($sources)));
}

/**
 * @return array{merchant_id: string, token: string, image_base: string, branches: array<int, string>}
 */
function website_import_indolj_context(string $html): array
{
    $ctx = [
        'merchant_id' => '',
        'token'       => '',
        'image_base'  => 'https://assets.indolj.io/upload/',
        'branches'    => [],
        'api_version' => '0.0.31',
        'domain'      => '',
    ];

    foreach (website_import_indolj_html_sources($html) as $source) {
        if ($ctx['merchant_id'] === '' && preg_match('/"merchant_id"\s*:\s*(\d+)/i', $source, $m)) {
            $ctx['merchant_id'] = (string) $m[1];
        }
        if ($ctx['merchant_id'] === '' && preg_match('/\\"merchant_id\\"\s*:\s*(\d+)/i', $source, $m)) {
            $ctx['merchant_id'] = (string) $m[1];
        }
        if ($ctx['merchant_id'] === '' && preg_match('/"merchantId"\s*:\s*"?(\d+)"?/i', $source, $m)) {
            $ctx['merchant_id'] = (string) $m[1];
        }
        if ($ctx['merchant_id'] === '' && preg_match('/\\"merchantId\\"\s*:\s*(\d+)/i', $source, $m)) {
            $ctx['merchant_id'] = (string) $m[1];
        }

        if ($ctx['token'] === '' && preg_match('/"token"\s*:\s*"(eyJ[^"]+)"/i', $source, $m)) {
            $ctx['token'] = $m[1];
        }
        if ($ctx['token'] === '' && preg_match('/\\"token\\"\s*:\s*\\"(eyJ[^\\\\]+)\\"/i', $source, $m)) {
            $ctx['token'] = stripcslashes($m[1]);
        }

        if ($ctx['image_base'] === 'https://assets.indolj.io/upload/' && preg_match('/"imageBaseURL"\s*:\s*"([^"]+)"/i', $source, $m)) {
            $ctx['image_base'] = rtrim($m[1], '/') . '/';
        }
        if ($ctx['image_base'] === 'https://assets.indolj.io/upload/' && preg_match('/\\"imageBaseURL\\"\s*:\s*\\"([^\\\\]+)\\"/i', $source, $m)) {
            $ctx['image_base'] = rtrim(stripcslashes($m[1]), '/') . '/';
        }

        if ($ctx['api_version'] === '0.0.31' && preg_match('/"api_version"\s*:\s*"(\d+\.\d+\.\d+)"/i', $source, $m)) {
            $ctx['api_version'] = $m[1];
        }
        if ($ctx['api_version'] === '0.0.31' && preg_match('/\\"api_version\\"\s*:\s*\\"(\d+\.\d+\.\d+)\\"/i', $source, $m)) {
            $ctx['api_version'] = stripcslashes($m[1]);
        }

        if ($ctx['domain'] === '' && preg_match('/"domain"\s*:\s*"([a-z0-9][a-z0-9.-]*\.[a-z]{2,})"/i', $source, $m)) {
            $ctx['domain'] = $m[1];
        }
        if ($ctx['domain'] === '' && preg_match('/\\"domain\\"\s*:\s*\\"([a-z0-9][a-z0-9.-]*\.[a-z]{2,})\\"/i', $source, $m)) {
            $ctx['domain'] = stripcslashes($m[1]);
        }

        if ($ctx['branches'] === [] && preg_match('/"active_branches"\s*:\s*\[(.*?)\]\s*,/s', $source, $block)) {
            if (preg_match_all('/"id"\s*:\s*"(\d{4,6})"/', $block[1], $ids)) {
                foreach ($ids[1] as $branchId) {
                    $ctx['branches'][(string) $branchId] = (string) $branchId;
                }
            }
        }
        if ($ctx['branches'] === [] && preg_match('/\\"active_branches\\"\s*:\s*\[(.*?)\]\s*,/s', $source, $block)) {
            if (preg_match_all('/\\"id\\"\s*:\s*\\"(\d{4,6})\\"/', $block[1], $ids)) {
                foreach ($ids[1] as $branchId) {
                    $ctx['branches'][(string) $branchId] = (string) $branchId;
                }
            }
        }

        if ($ctx['branches'] === [] && preg_match('/"branches(_(?:delivery|pickup|dinein))?"\s*:\s*\{/i', $source)) {
            if (preg_match_all('/"(\d{4,6})"\s*:\s*\{\s*"id"\s*:\s*"\1"/', $source, $branchKeys)) {
                foreach ($branchKeys[1] as $branchId) {
                    $ctx['branches'][(string) $branchId] = (string) $branchId;
                }
            }
        }

        if ($ctx['branches'] === [] && preg_match_all('/"id"\s*:\s*"(\d{4,6})"[^}]{0,400}"name"\s*:\s*"The Sicilian/i', $source, $branchMatches)) {
            foreach ($branchMatches[1] as $branchId) {
                $ctx['branches'][(string) $branchId] = (string) $branchId;
            }
        }

        if ($ctx['merchant_id'] !== '' && $ctx['token'] !== '' && $ctx['branches'] !== []) {
            break;
        }
    }

    $ctx['api_version'] = website_import_indolj_sanitize_api_version($ctx['api_version']);

    return $ctx;
}

function website_import_indolj_sanitize_api_version(string $version): string
{
    $version = trim($version);
    if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m)) {
        return $m[1];
    }

    return '0.0.31';
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> ...$overrides Prefer later contexts (menu RSC over homepage).
 * @return array{merchant_id: string, token: string, image_base: string, branches: array<int, string>, api_version: string, domain: string}
 */
function website_import_indolj_merge_context(array $base, array ...$overrides): array
{
    $merged = $base;
    foreach ($overrides as $ctx) {
        foreach (['merchant_id', 'token', 'domain', 'image_base'] as $key) {
            if (!empty($ctx[$key])) {
                $merged[$key] = (string) $ctx[$key];
            }
        }
        if (!empty($ctx['api_version'])) {
            $merged['api_version'] = website_import_indolj_sanitize_api_version((string) $ctx['api_version']);
        }
        if (!empty($ctx['branches']) && is_array($ctx['branches'])) {
            $merged['branches'] = array_merge($merged['branches'], $ctx['branches']);
        }
    }

    $merged['api_version'] = website_import_indolj_sanitize_api_version((string) ($merged['api_version'] ?? '0.0.31'));
    $merged['branches'] = array_values(array_unique(array_filter($merged['branches'])));

    return $merged;
}

/**
 * Seconds until JWT expiry (negative if expired). Null if unknown.
 */
function website_import_indolj_token_ttl(string $token): ?int
{
    $parts = explode('.', trim($token));
    if (count($parts) < 2) {
        return null;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);
    if (!is_array($payload) || !isset($payload['exp'])) {
        return null;
    }

    return (int) $payload['exp'] - time();
}

/**
 * Prefer menu-page token adjacent to domain/merchantId in RSC payload.
 */
function website_import_indolj_extract_token(string $html): string
{
    foreach (website_import_indolj_html_sources($html) as $source) {
        if (preg_match('/"token"\s*:\s*"(eyJ[^"]+)"[^}{]{0,240}"domain"\s*:\s*"[a-z0-9.-]+\.[a-z]{2,}"/i', $source, $m)) {
            return $m[1];
        }
        if (preg_match('/"domain"\s*:\s*"[a-z0-9.-]+\.[a-z]{2,}"[^}{]{0,240}"token"\s*:\s*"(eyJ[^"]+)"/i', $source, $m)) {
            return $m[1];
        }
        if (preg_match('/\\"token\\"\s*:\s*\\"(eyJ[^\\\\]+)\\"[^}{]{0,240}\\"domain\\"\s*:\s*\\"[a-z0-9.-]+\\.[a-z]{2,}\\"/i', $source, $m)) {
            return stripcslashes($m[1]);
        }
    }

    $ctx = website_import_indolj_context($html);

    return $ctx['token'];
}

/**
 * Build Indolj client-fetch payload for browser (user IP bypasses datacenter blocks).
 *
 * @return array{domain: string, api_version: string, branches: list<string>, token: string, image_base: string, base: string, token_ttl: int|null}|null
 */
function website_import_indolj_client_context(string $url): ?array
{
    $parsed = website_import_parse_url($url);
    if (!$parsed['valid']) {
        return null;
    }

    $base = $parsed['base'];
    $rscMenu = website_import_fetch_indolj_rsc($base, '/menu');
    $home = website_import_http_get($base . '/');
    $combined = ($home['body'] ?? '') . "\n" . $rscMenu;
    if (!website_import_is_indolj_html($combined)) {
        return null;
    }

    $ctx = website_import_indolj_merge_context(
        website_import_indolj_context($combined),
        website_import_indolj_context($rscMenu)
    );
    $token = website_import_indolj_extract_token($rscMenu !== '' ? $rscMenu : $combined);
    if ($token === '') {
        return null;
    }

    $domain = website_import_indolj_sanitize_domain($ctx['domain'], $base);
    $branches = array_values($ctx['branches']);
    if ($branches === [] && $ctx['merchant_id'] !== '') {
        $branches = [$ctx['merchant_id']];
    }

    return [
        'domain'      => $domain,
        'api_version' => website_import_indolj_sanitize_api_version($ctx['api_version'] ?? '0.0.31'),
        'branches'    => $branches,
        'token'       => $token,
        'image_base'  => $ctx['image_base'],
        'base'        => $base,
        'token_ttl'   => website_import_indolj_token_ttl($token),
    ];
}

/**
 * @param array<int, mixed> $menuResponses Raw Indolj API JSON objects from browser fetch.
 * @return array<int, array<string, mixed>>
 */
function website_import_indolj_products_from_responses(array $menuResponses, string $imageBase, string $baseUrl): array
{
    $merged = [];
    $seen = [];

    foreach ($menuResponses as $response) {
        if (!is_array($response)) {
            continue;
        }
        $payload = isset($response['details']) && is_array($response['details'])
            ? $response['details']
            : $response;
        foreach (website_import_indolj_collect_category_data($payload, $imageBase, $baseUrl) as $product) {
            $key = (string) ($product['external_id'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $product;
        }
    }

    return $merged;
}

function website_import_indolj_domain_from_base(string $baseUrl): string
{
    $host = parse_url($baseUrl, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }

    return preg_replace('/^www\./i', '', $host) ?? $host;
}

function website_import_indolj_sanitize_domain(string $domain, string $fallbackBase = ''): string
{
    $domain = trim(strtolower($domain));
    if (preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
        return $domain;
    }

    return website_import_indolj_domain_from_base($fallbackBase);
}

/**
 * @return array{code: int, body: string}
 */
function website_import_indolj_http_json(
    string $url,
    string $token,
    string $method = 'GET',
    ?string $postBody = null,
    string $authMode = 'bearer',
    string $restaurantHeaderId = '',
    string $origin = ''
): array {
    require_once __DIR__ . '/whatsapp.php';

    $token = trim($token);
    $headers = ['Accept: application/json, text/plain, */*'];
    if ($token !== '' && ($authMode === 'bearer' || $authMode === 'both')) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($token !== '' && ($authMode === 'token' || $authMode === 'both')) {
        $headers[] = 'token: ' . $token;
        $headers[] = 'istokenv2: true';
    }
    if ($restaurantHeaderId !== '' && $authMode === 'token') {
        $headers[] = 'restaurantid: ' . $restaurantHeaderId;
    }
    if ($origin !== '') {
        $headers[] = 'Origin: ' . rtrim($origin, '/');
        $headers[] = 'Referer: ' . rtrim($origin, '/') . '/menu';
        $headers[] = 'Sec-Fetch-Site: cross-site';
        $headers[] = 'Sec-Fetch-Mode: cors';
        $headers[] = 'Sec-Fetch-Dest: empty';
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if (defined('CURL_HTTP_VERSION_1_1')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    }

    $method = strtoupper($method);
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $postBody ?? '';
        if ($postBody !== null && $postBody !== '') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, whatsapp_curl_opts($opts));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (($code === 414 || $code === 0) && function_exists('stream_context_create')) {
        $headerLines = $headers;
        if ($method === 'POST' && $postBody !== null && $postBody !== '') {
            $headerLines[] = 'Content-Length: ' . strlen($postBody);
        }
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines),
                'content'       => $method === 'POST' ? ($postBody ?? '') : null,
                'timeout'       => 45,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => !(defined('META_GRAPH_SSL_VERIFY') && !META_GRAPH_SSL_VERIFY),
                'verify_peer_name' => !(defined('META_GRAPH_SSL_VERIFY') && !META_GRAPH_SSL_VERIFY),
            ],
        ]);
        $streamBody = @file_get_contents($url, false, $context);
        if (is_string($streamBody) && $streamBody !== '') {
            $body = $streamBody;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }
    }

    return [
        'code' => $code,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * Indolj Next.js stores call StructuredMenu on console.indolj.io/mobileapp/WebApiV2/
 * with Authorization: Bearer only (no restaurantid header).
 *
 * @return array{code: int, body: string}
 */
function website_import_indolj_bearer_get(string $url, string $token, string $origin): array
{
    return website_import_indolj_http_json($url, $token, 'GET', null, 'bearer', '', $origin);
}

/**
 * @return array{code: int, body: string, url: string, method: string, auth: string}
 */
function website_import_indolj_structured_menu_get(
    string $token,
    string $domain,
    string $branchId,
    string $apiVersion,
    string $origin
): array {
    $domain = website_import_indolj_sanitize_domain($domain, $origin);
    $apiVersion = website_import_indolj_sanitize_api_version($apiVersion);
    $query = http_build_query([
        'domain'      => $domain,
        'json'        => '1',
        'branch_id'   => $branchId,
        'api_version' => $apiVersion,
    ]);

    $bases = [
        'https://console.indolj.io/mobileapp/WebApiV2/StructuredMenu',
        'https://menu.indolj.io/mobileapp/WebApiV2/StructuredMenu',
    ];

    $attempts = [
        ['GET', '?' . $query, 'bearer', true],
        ['GET', '?' . $query, 'both', true],
        ['GET', '?' . $query, 'token', true],
        ['GET', '?' . $query, 'bearer', false],
        ['POST', '', 'bearer', true],
    ];

    $best = ['code' => 0, 'body' => '', 'url' => '', 'method' => '', 'auth' => ''];
    $last = $best;
    foreach ($bases as $base) {
        foreach ($attempts as [$method, $suffix, $authMode, $sendOrigin]) {
            $url = $base . $suffix;
            $response = website_import_indolj_http_json(
                $url,
                $token,
                $method,
                $method === 'POST' ? $query : null,
                $authMode,
                $branchId,
                $sendOrigin ? $origin : ''
            );
            $last = [
                'code'   => $response['code'],
                'body'   => $response['body'],
                'url'    => $url,
                'method' => $method,
                'auth'   => $authMode,
            ];
            if ($best['code'] === 0 || ($response['code'] >= 200 && $response['code'] < $best['code']) || ($response['code'] < 400 && $best['code'] >= 400)) {
                $best = $last;
            }
            if ($response['code'] >= 200 && $response['code'] < 300 && $response['body'] !== '') {
                $data = json_decode($response['body'], true);
                if (is_array($data) && (int) ($data['code'] ?? 0) === 1) {
                    return $last;
                }
            }
        }
    }

    return $best['code'] > 0 ? $best : $last;
}

function website_import_fetch_indolj_rsc(string $baseUrl, string $path = '/'): string
{
    $base = rtrim($baseUrl, '/');
    $path = $path === '' ? '/' : (str_starts_with($path, '/') ? $path : '/' . $path);
    $url = $base . $path;

    require_once __DIR__ . '/whatsapp.php';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => website_import_limit('fetch_timeout', WEBSITE_IMPORT_FETCH_TIMEOUT),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/x-component,text/html,application/xhtml+xml,*/*;q=0.8',
            'RSC: 1',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, whatsapp_curl_opts($opts));
    $body = curl_exec($ch);
    curl_close($ch);

    return is_string($body) ? $body : '';
}

/**
 * @param array<string, string> $extraHeaders
 * @return array{code: int, body: string}
 */
function website_import_indolj_api_request(string $url, string $token, string $restaurantHeaderId, string $origin, array $extraHeaders = []): array
{
    require_once __DIR__ . '/whatsapp.php';
    $headers = [
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'token: ' . $token,
        'restaurantid: ' . $restaurantHeaderId,
        'istokenv2: true',
        'Authorization: Bearer ' . $token,
    ];
    if ($origin !== '') {
        $headers[] = 'Origin: ' . rtrim($origin, '/');
        $headers[] = 'Referer: ' . rtrim($origin, '/') . '/menu';
    }
    foreach ($extraHeaders as $key => $value) {
        $headers[] = $key . ': ' . $value;
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if (defined('CURL_HTTP_VERSION_1_1')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, whatsapp_curl_opts($opts));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * @return array{code: int, body: string}
 */
function website_import_indolj_api_get(string $endpoint, string $token, string $restaurantHeaderId, string $origin): array
{
    $last = ['code' => 0, 'body' => ''];
    foreach (['https://res.indolj.io'] as $host) {
        $response = website_import_indolj_api_request(
            rtrim($host, '/') . $endpoint,
            $token,
            $restaurantHeaderId,
            $origin
        );
        $last = $response;
        if ($response['code'] >= 200 && $response['code'] < 300 && $response['body'] !== '') {
            return $response;
        }
    }

    return $last;
}

/**
 * Indolj web stores call action-style endpoints (GetEntireMenu, BranchDetails) on res.indolj.io/api/v2/.
 *
 * @param array<string, scalar|null> $query
 * @return array{code: int, body: string, url: string}
 */
function website_import_indolj_action_get(string $action, string $token, string $restaurantHeaderId, string $origin, array $query = []): array
{
    $query['json'] = $query['json'] ?? '1';
    $qs = http_build_query(array_filter($query, static fn ($v) => $v !== null && $v !== ''));

    $bases = [
        'https://res.indolj.io/api/v2/',
        'https://res.indolj.io/api/v1/',
        'https://console.indolj.io/api/v2/',
        'https://console.indolj.io/api/v1/',
    ];

    $last = ['code' => 0, 'body' => '', 'url' => ''];
    foreach ($bases as $base) {
        $url = rtrim($base, '/') . '/' . ltrim($action, '/') . ($qs !== '' ? '?' . $qs : '');
        $response = website_import_indolj_api_request($url, $token, $restaurantHeaderId, $origin);
        $last = ['code' => $response['code'], 'body' => $response['body'], 'url' => $url];
        if ($response['code'] >= 200 && $response['code'] < 300 && $response['body'] !== '') {
            $data = json_decode($response['body'], true);
            if (is_array($data) && (int) ($data['code'] ?? 0) === 1) {
                return $last;
            }
            if (is_array($data) && isset($data['categories'], $data['items'])) {
                return $last;
            }
            if (is_array($data) && website_import_indolj_collect_products($data, '', $origin) !== []) {
                return $last;
            }
        }
    }

    return $last;
}

/**
 * @return array<int, array<string, mixed>>|null
 */
function website_import_indolj_decode_action_payload(string $body): ?array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }

    if (isset($data['details']) && is_array($data['details'])) {
        return $data['details'];
    }

    return $data;
}

/**
 * Walk Indolj categoryData / GetEntireMenu.details (catId => { items: { id => item } }).
 *
 * @return array<int, array<string, mixed>>
 */
function website_import_indolj_collect_category_data(mixed $data, string $imageBase, string $baseUrl, string $branchId = ''): array
{
    if (!is_array($data)) {
        return [];
    }

    $root = $data;
    if (isset($data['categoryData']) && is_array($data['categoryData'])) {
        $root = $data;
        $data = $data['categoryData'];
    }

    $ctx = website_import_indolj_payload_context(is_array($root) ? $root : [], $branchId);

    $out = [];
    $seen = [];

    $add = static function (?array $item, string $categoryName = '') use (&$out, &$seen, $imageBase, $baseUrl, $ctx): void {
        if ($item === null) {
            return;
        }
        $mapped = website_import_map_indolj_item($item, $imageBase, $baseUrl, $categoryName, $ctx);
        if ($mapped === null) {
            return;
        }
        $key = (string) ($mapped['external_id'] ?? '');
        if ($key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $out[] = $mapped;
    };

    foreach ($data as $catKey => $category) {
        if (!is_array($category)) {
            continue;
        }
        $catName = trim((string) (
            $category['category_name']
            ?? $category['name']
            ?? $category['cat_name']
            ?? $category['title']
            ?? (is_string($catKey) && !preg_match('/^\d+$/', (string) $catKey) ? $catKey : '')
        ));
        if ($catName === '' && isset($category['catId'])) {
            $catName = trim((string) $category['catId']);
        }

        foreach (['items', 'products', 'branchProducts', 'branch_products', 'chainProducts'] as $itemsKey) {
            if (!isset($category[$itemsKey]) || !is_array($category[$itemsKey])) {
                continue;
            }
            foreach ($category[$itemsKey] as $item) {
                if (is_array($item)) {
                    $add($item, $catName);
                }
            }
        }
    }

    if ($out !== [] && is_array($root)) {
        $out = website_import_indolj_enrich_products(
            $out,
            website_import_indolj_build_enrichment_index($root),
            $imageBase,
            $baseUrl,
            $branchId
        );
    }

    return $out;
}

/**
 * @param array<string, mixed> $payload StructuredMenu.details or similar
 * @return array{sizeData: array<string, mixed>, priceLookup: array<string, mixed>, branchId: string}
 */
function website_import_indolj_payload_context(array $payload, string $branchId = ''): array
{
    $sizeData = [];
    foreach (['sizeData', 'size_data', 'sizes', 'itemSizes', 'item_sizes'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $sizeData = array_merge($sizeData, $payload[$key]);
        }
    }

    $priceLookup = [];
    foreach (['priceData', 'price_data', 'itemPriceData', 'item_price_data', 'branchProducts', 'branch_products'] as $key) {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            continue;
        }
        foreach ($payload[$key] as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $itemId = (string) ($entry['item_id'] ?? $entry['id'] ?? $entry['productOID'] ?? $entry['chainProductOID'] ?? $id);
            if ($itemId !== '') {
                $priceLookup[$itemId] = $entry;
            }
        }
    }

    return [
        'sizeData'     => $sizeData,
        'priceLookup'  => $priceLookup,
        'branchId'     => $branchId,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array<string, array<string, mixed>>
 */
function website_import_indolj_build_enrichment_index(array $data): array
{
    $index = [];
    $walk = static function (mixed $node) use (&$walk, &$index): void {
        if (!is_array($node)) {
            return;
        }

        $id = trim((string) (
            $node['item_id']
            ?? $node['id']
            ?? $node['product_id']
            ?? $node['productOID']
            ?? $node['chainProductOID']
            ?? $node['oid']
            ?? ''
        ));
        $name = trim((string) (
            $node['item_name']
            ?? $node['productName']
            ?? $node['product_name']
            ?? $node['name']
            ?? ''
        ));

        if ($id !== '' && $name !== '') {
            if (!isset($index[$id])) {
                $index[$id] = $node;
            } else {
                $index[$id] = array_merge($index[$id], array_filter($node, static fn ($v) => $v !== '' && $v !== null));
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };

    $walk($data);

    return $index;
}

/**
 * @param array<string, mixed> $item
 * @param array{sizeData?: array<string, mixed>, priceLookup?: array<string, mixed>, branchId?: string} $ctx
 */
function website_import_indolj_extract_item_price(array $item, array $ctx = []): float
{
    $branchId = (string) ($ctx['branchId'] ?? '');
    $sizeData = is_array($ctx['sizeData'] ?? null) ? $ctx['sizeData'] : [];
    $priceLookup = is_array($ctx['priceLookup'] ?? null) ? $ctx['priceLookup'] : [];

    $priceKeys = [
        'price', 'item_price', 'amount', 'productPrice', 'salePrice', 'default_price',
        'base_price', 'regular_price', 'unit_price', 'branch_price', 'item_branch_price',
        'min_price', 'starting_price', 'display_price',
    ];

    foreach ($priceKeys as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $val = $item[$key];
        if (is_array($val)) {
            if ($branchId !== '' && isset($val[$branchId])) {
                $parsed = website_import_parse_indolj_price($val[$branchId]);
                if ($parsed > 0) {
                    return $parsed;
                }
            }
            $parsed = website_import_parse_indolj_price($val);
            if ($parsed > 0) {
                return $parsed;
            }
            continue;
        }
        $parsed = website_import_parse_indolj_price($val);
        if ($parsed > 0) {
            return $parsed;
        }
    }

    foreach (['sizes', 'item_sizes', 'productSizes', 'size', 'itemSizes'] as $sizesKey) {
        if (!isset($item[$sizesKey]) || !is_array($item[$sizesKey])) {
            continue;
        }
        $best = 0.0;
        foreach ($item[$sizesKey] as $sizeKey => $size) {
            if (is_array($size)) {
                $p = website_import_indolj_extract_item_price($size, $ctx);
            } else {
                $p = website_import_parse_indolj_price($size);
            }
            if ($p > 0 && ($best <= 0 || $p < $best)) {
                $best = $p;
            }
            if (is_string($sizeKey) && $sizeData !== [] && isset($sizeData[$sizeKey]) && is_array($sizeData[$sizeKey])) {
                $p = website_import_indolj_extract_item_price($sizeData[$sizeKey], $ctx);
                if ($p > 0 && ($best <= 0 || $p < $best)) {
                    $best = $p;
                }
            }
        }
        if ($best > 0) {
            return $best;
        }
    }

    foreach (['size_id', 'default_size_id', 'sizeId'] as $sizeIdKey) {
        if (!isset($item[$sizeIdKey])) {
            continue;
        }
        $sizeIds = is_array($item[$sizeIdKey]) ? $item[$sizeIdKey] : [$item[$sizeIdKey]];
        foreach ($sizeIds as $sizeId) {
            $sid = (string) $sizeId;
            if ($sid === '' || !isset($sizeData[$sid]) || !is_array($sizeData[$sid])) {
                continue;
            }
            $p = website_import_indolj_extract_item_price($sizeData[$sid], $ctx);
            if ($p > 0) {
                return $p;
            }
        }
    }

    $itemId = trim((string) ($item['item_id'] ?? $item['id'] ?? $item['productOID'] ?? $item['chainProductOID'] ?? ''));
    if ($itemId !== '' && isset($priceLookup[$itemId]) && is_array($priceLookup[$itemId])) {
        $p = website_import_indolj_extract_item_price($priceLookup[$itemId], $ctx);
        if ($p > 0) {
            return $p;
        }
    }

    if (isset($item['variants']) && is_array($item['variants'])) {
        foreach ($item['variants'] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $p = website_import_indolj_extract_item_price($variant, $ctx);
            if ($p > 0) {
                return $p;
            }
        }
    }

    if (isset($item['prices']) && is_array($item['prices'])) {
        $p = website_import_parse_indolj_price($item['prices']);
        if ($p > 0) {
            return $p;
        }
    }

    return 0.0;
}

function website_import_indolj_is_placeholder_image(string $url): bool
{
    if ($url === '') {
        return true;
    }

    $lower = strtolower($url);

    return preg_match('#/(logo|favicon|placeholder|default-item|no-image|noimage|dummy)#i', $lower) === 1
        || preg_match('#logo\.(png|jpe?g|webp|gif)(\?|$)#i', $lower) === 1;
}

/**
 * @param array<string, mixed> $item
 */
function website_import_indolj_resolve_photo(array $item, string $imageBase): string
{
    $candidates = [];
    foreach (['photo', 'photo_webp', 'image', 'image_url', 'imageUrl', 'thumbnail', 'thumbnailUrl', 'item_image', 'image_name'] as $key) {
        if (!empty($item[$key]) && is_string($item[$key])) {
            $candidates[] = trim($item[$key]);
        }
    }

    foreach ($candidates as $photo) {
        if ($photo === '' || website_import_indolj_is_placeholder_image($photo)) {
            continue;
        }

        if (preg_match('#^https?://#i', $photo)) {
            $imageUrl = $photo;
        } else {
            $imageUrl = rtrim($imageBase, '/') . '/' . ltrim($photo, '/');
        }

        return preg_replace('#(https?://assets\.indolj\.io/upload/)+#i', 'https://assets.indolj.io/upload/', $imageUrl) ?? $imageUrl;
    }

    return '';
}

/**
 * @param array<int, array<string, mixed>> $products
 * @param array<string, array<string, mixed>> $enrichmentIndex
 * @return array<int, array<string, mixed>>
 */
function website_import_indolj_enrich_products(array $products, array $enrichmentIndex, string $imageBase, string $baseUrl, string $branchId = ''): array
{
    if ($enrichmentIndex === []) {
        return $products;
    }

    $ctx = ['branchId' => $branchId, 'sizeData' => [], 'priceLookup' => []];
    $out = [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $externalId = (string) ($product['external_id'] ?? '');
        $rawId = preg_replace('/^indolj-/', '', $externalId) ?? $externalId;
        $extra = $enrichmentIndex[$rawId] ?? null;

        if (is_array($extra)) {
            if ((float) ($product['price'] ?? 0) <= 0) {
                $price = website_import_indolj_extract_item_price($extra, $ctx);
                if ($price > 0) {
                    $product['price'] = $price;
                }
            }
            if (trim((string) ($product['image_url'] ?? '')) === '') {
                $photo = website_import_indolj_resolve_photo($extra, $imageBase);
                if ($photo !== '') {
                    $product['image_url'] = $photo;
                }
            }
            if (trim((string) ($product['description'] ?? '')) === '' && !empty($extra['item_description'])) {
                $product['description'] = website_import_clean_html((string) $extra['item_description']);
            }
        }

        $out[] = website_import_normalize_product($product);
    }

    return $out;
}

function website_import_parse_indolj_price(mixed $price): float
{
    if (is_numeric($price)) {
        return max(0, (float) $price);
    }

    if (is_string($price)) {
        $trim = trim($price);
        if ($trim !== '' && $trim[0] === '[') {
            $decoded = json_decode($trim, true);
            if (is_array($decoded) && isset($decoded[0])) {
                return website_import_parse_indolj_price($decoded[0]);
            }
        }

        if (str_contains($trim, '|')) {
            foreach (explode('|', $trim) as $part) {
                $parsed = website_import_parse_price(trim($part));
                if ($parsed > 0) {
                    return $parsed;
                }
            }
        }

        if (str_contains($trim, ',') && !str_contains($trim, '.')) {
            foreach (explode(',', $trim) as $part) {
                $parsed = website_import_parse_price(trim($part));
                if ($parsed > 0) {
                    return $parsed;
                }
            }
        }

        return website_import_parse_price($trim);
    }

    if (is_array($price)) {
        foreach ($price as $v) {
            $parsed = website_import_parse_indolj_price($v);
            if ($parsed > 0) {
                return $parsed;
            }
        }
    }

    return 0.0;
}

/**
 * @param array<string, mixed> $item
 * @param array{sizeData?: array<string, mixed>, priceLookup?: array<string, mixed>, branchId?: string} $ctx
 * @return array<string, mixed>|null
 */
function website_import_map_indolj_item(array $item, string $imageBase, string $baseUrl, string $categoryName = '', array $ctx = []): ?array
{
    $name = trim((string) (
        $item['item_name']
        ?? $item['productName']
        ?? $item['product_name']
        ?? $item['name']
        ?? $item['title']
        ?? $item['product_name_en']
        ?? ''
    ));
    if ($name === '') {
        return null;
    }

    $externalId = trim((string) (
        $item['item_id']
        ?? $item['id']
        ?? $item['product_id']
        ?? $item['oid']
        ?? $item['productOID']
        ?? $item['chainProductOID']
        ?? ''
    ));
    if ($externalId === '') {
        $externalId = 'indolj-' . md5($name . '|' . ($item['photo'] ?? ''));
    }

    $price = website_import_indolj_extract_item_price($item, $ctx);
    $imageUrl = website_import_indolj_resolve_photo($item, $imageBase);

    $desc = website_import_clean_html((string) ($item['item_description'] ?? $item['description'] ?? ''));
    if ($categoryName === '' && isset($item['category_name'])) {
        $categoryName = trim((string) $item['category_name']);
    }

    return website_import_normalize_product([
        'external_id' => 'indolj-' . $externalId,
        'name'        => $name,
        'description' => $desc,
        'price'       => $price,
        'currency'    => website_import_guess_currency($baseUrl, $item),
        'image_url'   => $imageUrl,
        'sku'         => trim((string) ($item['item_sku'] ?? $item['sku'] ?? '')),
        'category'    => $categoryName,
        'source_url'  => rtrim($baseUrl, '/'),
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_extract_indolj_items_from_html(string $html, string $imageBase, string $baseUrl): array
{
    $out = [];
    $seen = [];
    $sources = [$html, stripcslashes($html), html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')];

    foreach ($sources as $source) {
        if (preg_match_all('/\{"item_id"\s*:\s*"(\d+)"[^}]{10,1200}\}/u', $source, $objects, PREG_SET_ORDER)) {
            foreach ($objects as $objMatch) {
                $jsonStr = $objMatch[0];
                $item = json_decode($jsonStr, true);
                if (!is_array($item)) {
                    continue;
                }
                $id = (string) ($item['item_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $mapped = website_import_map_indolj_item($item, $imageBase, $baseUrl);
                if ($mapped) {
                    $out[] = $mapped;
                }
            }
        }

        if (preg_match_all(
            '/\{"item_id"\s*:\s*"(\d+)".*?"item_name"\s*:\s*"((?:\\\\.|[^"\\\\])*)".*?"price"\s*:\s*"(\[[^\]]*\])".*?"photo"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/us',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id = $m[1];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $mapped = website_import_map_indolj_item([
                    'item_id'   => $id,
                    'item_name' => stripcslashes($m[2]),
                    'price'     => stripcslashes($m[3]),
                    'photo'     => stripcslashes($m[4]),
                ], $imageBase, $baseUrl);
                if ($mapped) {
                    $out[] = $mapped;
                }
            }
        }

        if (preg_match_all('/"items"\s*:\s*\[(.*?)\]/s', $source, $itemBlocks)) {
            foreach ($itemBlocks[1] as $block) {
                if (!str_contains($block, 'item_id')) {
                    continue;
                }
                if (preg_match_all('/\{"item_id"\s*:\s*"(\d+)"[^}]{10,2000}\}/u', $block, $objects, PREG_SET_ORDER)) {
                    foreach ($objects as $objMatch) {
                        $item = json_decode($objMatch[0], true);
                        if (!is_array($item)) {
                            continue;
                        }
                        $id = (string) ($item['item_id'] ?? '');
                        if ($id === '' || isset($seen[$id])) {
                            continue;
                        }
                        $seen[$id] = true;
                        $mapped = website_import_map_indolj_item($item, $imageBase, $baseUrl);
                        if ($mapped) {
                            $out[] = $mapped;
                        }
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * @param mixed $data
 * @return array<int, array<string, mixed>>
 */
function website_import_indolj_collect_products(mixed $data, string $imageBase, string $baseUrl, string $categoryName = ''): array
{
    $out = [];
    $seen = [];

    $walk = static function (mixed $node, string $cat = '') use (&$walk, &$out, &$seen, $imageBase, $baseUrl): void {
        if (!is_array($node)) {
            return;
        }

        $isItem = isset($node['item_id']) || isset($node['item_name'])
            || isset($node['productName']) || isset($node['productOID']) || isset($node['chainProductOID'])
            || (isset($node['name'], $node['price']) && !isset($node['categories']) && !isset($node['productCategories']));
        if ($isItem) {
            $mapped = website_import_map_indolj_item($node, $imageBase, $baseUrl, $cat);
            if ($mapped) {
                $key = (string) ($mapped['external_id'] ?? '');
                if ($key !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $mapped;
                }
            }

            return;
        }

        $catName = trim((string) ($node['category_name'] ?? $node['name'] ?? $cat));
        if (isset($node['categories']) && is_array($node['categories'])) {
            foreach ($node['categories'] as $category) {
                if (!is_array($category)) {
                    continue;
                }
                $label = trim((string) ($category['category_name'] ?? $category['name'] ?? $catName));
                if (isset($category['items']) && is_array($category['items'])) {
                    foreach ($category['items'] as $item) {
                        $walk($item, $label);
                    }
                }
                $walk($category, $label);
            }
        }

        if (isset($node['productCategories']) && is_array($node['productCategories'])) {
            foreach ($node['productCategories'] as $category) {
                if (!is_array($category)) {
                    continue;
                }
                $label = trim((string) ($category['name'] ?? $category['category_name'] ?? $catName));
                if (isset($category['products']) && is_array($category['products'])) {
                    foreach ($category['products'] as $item) {
                        $walk($item, $label);
                    }
                }
                if (isset($category['chainProducts']) && is_array($category['chainProducts'])) {
                    foreach ($category['chainProducts'] as $item) {
                        $walk($item, $label);
                    }
                }
                $walk($category, $label);
            }
        }

        foreach (['items', 'products', 'menu_items', 'data', 'related_items', 'chainProducts', 'branchProducts'] as $listKey) {
            if (!isset($node[$listKey]) || !is_array($node[$listKey])) {
                continue;
            }
            $list = $node[$listKey];
            if ($listKey === 'data' && !array_is_list($list)) {
                continue;
            }
            foreach ($list as $item) {
                $walk($item, $catName);
            }
        }
    };

    $walk($data, $categoryName);

    return $out;
}

/**
 * @return list<string>
 */
function website_import_indolj_menu_oids(mixed $data): array
{
    $oids = [];
    $walk = static function (mixed $node) use (&$walk, &$oids): void {
        if (!is_array($node)) {
            return;
        }
        foreach (['menuOID', 'menuOid', 'oid', 'id', 'menu_id'] as $key) {
            if (isset($node[$key]) && is_scalar($node[$key]) && preg_match('/^\d+$/', (string) $node[$key])) {
                $oids[(string) $node[$key]] = (string) $node[$key];
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };
    $walk($data);

    return array_values($oids);
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_fetch_indolj(string $baseUrl, string $homeHtml = ''): array
{
    $base = rtrim($baseUrl, '/');
    $htmlParts = [$homeHtml];

    foreach (['/', '/menu'] as $path) {
        $chunk = website_import_fetch_indolj_rsc($base, $path);
        if ($chunk !== '') {
            $htmlParts[] = $chunk;
        }
    }

    $combined = implode("\n", array_filter($htmlParts));
    if (!website_import_is_indolj_html($combined)) {
        return [];
    }

    $menuRsc = website_import_fetch_indolj_rsc($base, '/menu');
    $ctx = website_import_indolj_merge_context(
        website_import_indolj_context($combined),
        website_import_indolj_context($menuRsc)
    );
    $merchantId = $ctx['merchant_id'];
    $token = $ctx['token'];
    $imageBase = $ctx['image_base'];
    $branches = array_values($ctx['branches']);

    foreach ($branches as $branchId) {
        foreach ([
            '/menu?branch_id=' . rawurlencode($branchId),
            '/menu?branch=' . rawurlencode($branchId),
        ] as $path) {
            $chunk = website_import_fetch_indolj_rsc($base, $path);
            if ($chunk !== '') {
                $htmlParts[] = $chunk;
            }
            $page = website_import_http_get($base . $path);
            if (($page['body'] ?? '') !== '') {
                $htmlParts[] = $page['body'];
            }
        }
    }

    $combined = implode("\n", array_filter($htmlParts));
    $ctx = website_import_indolj_merge_context(
        $ctx,
        website_import_indolj_context($combined),
        website_import_indolj_context($menuRsc)
    );

    $merged = [];
    $seen = [];

    $addProducts = static function (array $batch) use (&$merged, &$seen): void {
        foreach ($batch as $product) {
            $key = (string) ($product['external_id'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $product;
        }
    };

    if ($merchantId !== '' && $token !== '') {
        $apiVersion = website_import_indolj_sanitize_api_version($ctx['api_version'] ?? '0.0.31');

        $domain = website_import_indolj_sanitize_domain($ctx['domain'], $base);

        $branchIds = array_values(array_unique(array_filter($branches)));
        if ($branchIds === []) {
            $branchIds = [$merchantId];
        }

        if ($domain !== '') {
            foreach ($branchIds as $branchId) {
                $actionResponse = website_import_indolj_action_get(
                    'GetEntireMenu',
                    $token,
                    (string) $branchId,
                    $base,
                    ['branch_id' => (string) $branchId, 'domain' => $domain, 'api_version' => $apiVersion]
                );
                if ($actionResponse['code'] >= 200 && $actionResponse['code'] < 300 && $actionResponse['body'] !== '') {
                    $actionPayload = website_import_indolj_decode_action_payload($actionResponse['body']);
                    if (is_array($actionPayload)) {
                        $fromAction = website_import_indolj_collect_category_data($actionPayload, $imageBase, $base, (string) $branchId);
                        if ($fromAction !== []) {
                            $addProducts($fromAction);
                        }
                    }
                }

                $response = website_import_indolj_structured_menu_get($token, $domain, (string) $branchId, $apiVersion, $base);
                if ($response['code'] >= 400 || $response['body'] === '') {
                    continue;
                }
                $payload = website_import_indolj_decode_action_payload($response['body']);
                if (!is_array($payload)) {
                    continue;
                }
                $fromCategories = website_import_indolj_collect_category_data($payload, $imageBase, $base, (string) $branchId);
                if ($fromCategories !== []) {
                    $addProducts($fromCategories);
                }
            }
        }

        // v2 product catalog often has prices/images missing from StructuredMenu skeleton items.
        foreach ($branchIds as $branchId) {
            foreach ([
                '/v2/restaurants/' . rawurlencode((string) $branchId) . '/products?includeTemplates=false',
                '/v2/restaurants/' . rawurlencode((string) $branchId) . '/products?includeTemplates=false&includeRequests=false',
            ] as $endpoint) {
                $response = website_import_indolj_api_get($endpoint, $token, (string) $branchId, $base);
                if ($response['code'] >= 400 || $response['body'] === '') {
                    continue;
                }
                $data = json_decode($response['body'], true);
                if (!is_array($data)) {
                    continue;
                }
                $v2Products = website_import_indolj_collect_products($data, $imageBase, $base);
                if ($v2Products === []) {
                    continue;
                }
                $index = [];
                foreach ($v2Products as $vp) {
                    $key = (string) ($vp['external_id'] ?? '');
                    if ($key !== '') {
                        $index[$key] = $vp;
                    }
                }
                if ($index === []) {
                    continue;
                }
                foreach ($merged as $i => $product) {
                    $key = (string) ($product['external_id'] ?? '');
                    if ($key === '' || !isset($index[$key])) {
                        continue;
                    }
                    $extra = $index[$key];
                    if ((float) ($product['price'] ?? 0) <= 0 && (float) ($extra['price'] ?? 0) > 0) {
                        $merged[$i]['price'] = $extra['price'];
                    }
                    if (trim((string) ($product['image_url'] ?? '')) === '' && trim((string) ($extra['image_url'] ?? '')) !== '') {
                        $merged[$i]['image_url'] = $extra['image_url'];
                    }
                }
                break 2;
            }
        }

        $menuOids = [];
        foreach ($branchIds as $branchId) {
            foreach ([(string) $branchId, $merchantId] as $headerId) {
                $response = website_import_indolj_api_get(
                    '/v2/chain-restaurants/' . rawurlencode($merchantId) . '/active-menus',
                    $token,
                    $headerId,
                    $base
                );
                if ($response['code'] >= 400 || $response['body'] === '') {
                    continue;
                }
                $data = json_decode($response['body'], true);
                if (!is_array($data)) {
                    continue;
                }
                foreach (website_import_indolj_menu_oids($data) as $oid) {
                    $menuOids[$oid] = $oid;
                }
                if ($menuOids !== []) {
                    break 2;
                }
            }
        }

        foreach ($menuOids as $menuOid) {
            foreach ($branchIds as $branchId) {
                foreach ([
                    '/v2/menus/' . rawurlencode($menuOid) . '?includeTemplates=false',
                    '/v2/menus/' . rawurlencode($menuOid),
                ] as $endpoint) {
                    $response = website_import_indolj_api_get($endpoint, $token, (string) $branchId, $base);
                    if ($response['code'] >= 400 || $response['body'] === '') {
                        continue;
                    }
                    $data = json_decode($response['body'], true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $fromCategories = website_import_indolj_collect_category_data($data, $imageBase, $base);
                    if ($fromCategories !== []) {
                        $addProducts($fromCategories);
                        continue 2;
                    }
                    $products = website_import_indolj_collect_products($data, $imageBase, $base);
                    if ($products !== []) {
                        $addProducts($products);
                        continue 2;
                    }
                }
            }
        }

        $restaurantIds = array_values(array_unique(array_filter([$merchantId, ...$branchIds])));

        $endpoints = [
            '/chain-restaurants/' . rawurlencode($merchantId) . '/menu/without-option-categories',
        ];

        foreach ($restaurantIds as $restaurantId) {
            $endpoints[] = '/v2/restaurants/' . rawurlencode($restaurantId) . '/products?includeTemplates=false';
            $endpoints[] = '/v2/restaurants/' . rawurlencode($restaurantId) . '/products?includeTemplates=false&includeRequests=false';
            $endpoints[] = '/restaurants/' . rawurlencode($restaurantId) . '/menu';
        }

        foreach ($endpoints as $endpoint) {
            $headerId = str_contains($endpoint, 'chain-restaurants')
                ? $merchantId
                : (string) ($branchIds[0] ?? $merchantId);

            $response = website_import_indolj_api_get($endpoint, $token, $headerId, $base);
            if ($response['code'] >= 400 || $response['body'] === '') {
                continue;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                continue;
            }

            $fromCategories = website_import_indolj_collect_category_data($data, $imageBase, $base);
            if ($fromCategories !== []) {
                $addProducts($fromCategories);
                continue;
            }

            $products = website_import_indolj_collect_products($data, $imageBase, $base);
            if ($products !== []) {
                $addProducts($products);
            }
        }
    }

    if ($merged !== []) {
        return array_slice($merged, 0, website_import_limit('max_products', WEBSITE_IMPORT_MAX_PRODUCTS));
    }

    $embedded = website_import_extract_indolj_items_from_html($combined, $imageBase, $base);
    if ($embedded !== []) {
        if (count($embedded) <= 5) {
            if ($token === '' || $merchantId === '') {
                error_log('website_import: Indolj HTML fallback (' . count($embedded) . ' items) — missing token or merchant_id in page HTML');
            } else {
                error_log('website_import: Indolj API returned 0 products; HTML fallback found ' . count($embedded) . ' related_items only');
            }
        }

        return $embedded;
    }

    return [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_fetch_generic(string $baseUrl, string $homeHtml = ''): array
{
    $base = rtrim($baseUrl, '/');
    $urls = website_import_discover_product_urls($base, $homeHtml);
    $out = [];
    $seen = [];
    $maxProducts = website_import_limit('max_products', WEBSITE_IMPORT_MAX_PRODUCTS);
    $maxFetches = website_import_limit('max_generic_fetches', min($maxProducts, WEBSITE_IMPORT_GENERIC_MAX_FETCHES));
    $fetches = 0;

    foreach ($urls as $productUrl) {
        if (count($out) >= $maxProducts || $fetches >= $maxFetches) {
            break;
        }

        $key = md5($productUrl);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $response = website_import_http_get($productUrl);
        $fetches++;
        if ($response['code'] >= 400 || $response['body'] === '') {
            continue;
        }

        $html = $response['body'];
        $items = website_import_extract_product_from_page($html, $productUrl);
        foreach ($items as $item) {
            if (count($out) >= $maxProducts) {
                break 2;
            }
            $out[] = website_import_normalize_product($item);
        }
    }

    return $out;
}

/**
 * Extract product data from a single product page using multiple strategies.
 *
 * @return array<int, array<string, mixed>>
 */
function website_import_extract_product_from_page(string $html, string $pageUrl): array
{
    $embedded = website_import_extract_shopify_page_product($html, $pageUrl);
    if ($embedded !== null) {
        return [$embedded];
    }

    $jsonLd = website_import_extract_json_ld_products($html, $pageUrl);
    if ($jsonLd !== []) {
        $enriched = [];
        foreach ($jsonLd as $item) {
            if ((float) ($item['price'] ?? 0) <= 0) {
                $item['price'] = website_import_extract_price_from_html($html);
            }
            if (trim((string) ($item['description'] ?? '')) === '') {
                $item['description'] = website_import_extract_description_from_html($html);
            }
            if (trim((string) ($item['currency'] ?? '')) === '' || ($item['currency'] ?? '') === default_currency()) {
                $item['currency'] = website_import_guess_currency($pageUrl);
            }
            $enriched[] = $item;
        }
        return $enriched;
    }

    $og = website_import_extract_og_product($html, $pageUrl);
    if ($og !== []) {
        foreach ($og as &$item) {
            if ((float) ($item['price'] ?? 0) <= 0) {
                $item['price'] = website_import_extract_price_from_html($html);
            }
            if (trim((string) ($item['description'] ?? '')) === '') {
                $item['description'] = website_import_extract_description_from_html($html);
            }
        }
        unset($item);
        return $og;
    }

    return [];
}

/**
 * @return array<string, mixed>|null
 */
function website_import_extract_shopify_page_product(string $html, string $pageUrl): ?array
{
    if (preg_match('#<script[^>]+type=["\']application/json["\'][^>]*data-product-json[^>]*>(.*?)</script>#is', $html, $m)) {
        $data = json_decode(trim($m[1]), true);
        if (is_array($data)) {
            $base = website_import_parse_url($pageUrl)['base'] ?? $pageUrl;
            return website_import_map_shopify_product($data, $base);
        }
    }

    if (preg_match('#<script[^>]+type=["\']application/json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
        $data = json_decode(trim($m[1]), true);
        if (is_array($data) && isset($data['id'], $data['title'])) {
            $base = website_import_parse_url($pageUrl)['base'] ?? $pageUrl;
            return website_import_map_shopify_product($data, $base);
        }
    }

    if (preg_match('#var\s+meta\s*=\s*(\{.*?"product".*?\});#is', $html, $m)) {
        $data = json_decode($m[1], true);
        if (is_array($data) && is_array($data['product'] ?? null)) {
            $base = website_import_parse_url($pageUrl)['base'] ?? $pageUrl;
            return website_import_map_shopify_product($data['product'], $base);
        }
    }

    return null;
}

function website_import_extract_price_from_html(string $html): float
{
    $patterns = [
        '#itemprop=["\']price["\'][^>]*content=["\']([\d.,]+)#i',
        '#content=["\']([\d.,]+)["\'][^>]*itemprop=["\']price["\']#i',
        '#data-product-price=["\']([\d.,]+)#i',
        '#class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*(?:From\s+)?(?:Rs\.?|PKR|USD|\$)\s*([\d,]+(?:\.\d+)?)#i',
        '#(?:Rs\.?|PKR)\s*([\d,]+(?:\.\d+)?)#i',
        '#\$\s*([\d,]+(?:\.\d+)?)#i',
        '#"price"\s*:\s*"?([\d.]+)"?#i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $price = website_import_parse_price($m[1]);
            if ($price > 0) {
                return $price;
            }
        }
    }

    return 0.0;
}

function website_import_extract_description_from_html(string $html): string
{
    $patterns = [
        '#itemprop=["\']description["\'][^>]*>(.*?)</(?:div|p|span)#is',
        '#class=["\'][^"\']*product[^"\']*description[^"\']*["\'][^>]*>(.*?)</(?:div|section)#is',
        '#id=["\']ProductDescription[^"\']*["\'][^>]*>(.*?)</(?:div|section)#is',
        '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)#i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $text = website_import_clean_html($m[1]);
            if (mb_strlen($text) > 20) {
                return mb_substr($text, 0, 2000);
            }
        }
    }

    return '';
}

function website_import_clean_html(string $html): string
{
    $html = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html);
    $html = preg_replace('/<\/(?:p|div|h[1-6]|li|ul|ol|section|article|main|header|footer|tr|td|th|blockquote|figure|figcaption|span)>/i', ' ', $html);
    $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = website_import_normalize_visible_text($text);

    return mb_substr($text, 0, 2000);
}

/** Fix words merged when HTML tags are stripped (e.g. "FilmsCreate", "AdEra"). */
function website_import_normalize_visible_text(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text) ?? $text;
    $text = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $text) ?? $text;
    $text = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $text) ?? $text;
    $text = preg_replace('/([.!?])([A-Z])/', '$1 $2', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Loopback fetch when outbound DNS fails for the app's own domain.
 *
 * @return array{url: string, headers: list<string>}|null
 */
function website_import_loopback_url(string $url): ?array
{
    if (!defined('APP_URL')) {
        return null;
    }

    $targetHost = strtolower((string) parse_url($url, PHP_URL_HOST));
    $appHost = strtolower((string) parse_url(APP_URL, PHP_URL_HOST));
    if ($targetHost === '' || $appHost === '' || $targetHost !== $appHost) {
        return null;
    }

    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $query = parse_url($url, PHP_URL_QUERY);
    $loopUrl = 'http://127.0.0.1' . $path . ($query ? '?' . $query : '');

    return [
        'url'     => $loopUrl,
        'headers' => ['Host: ' . $targetHost],
    ];
}

/**
 * @param array<string, mixed> $data
 */
function website_import_jsonld_to_text(array $data): string
{
    $chunks = [];
    $type = $data['@type'] ?? '';
    if (is_array($type)) {
        $type = $type[0] ?? '';
    }

    foreach (['name', 'headline', 'description', 'slogan'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            $chunks[] = trim($data[$key]);
        }
    }

    if (!empty($data['offers']) && is_array($data['offers'])) {
        $offer = $data['offers'];
        if (isset($offer['price'], $offer['priceCurrency'])) {
            $chunks[] = 'Price: ' . $offer['priceCurrency'] . ' ' . $offer['price'];
        }
    }

    if ($chunks !== []) {
        return implode('. ', array_unique($chunks));
    }

    if (isset($data['@graph']) && is_array($data['@graph'])) {
        foreach ($data['@graph'] as $node) {
            if (is_array($node)) {
                $nested = website_import_jsonld_to_text($node);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }
    }

    return '';
}

/**
 * Extract readable business copy from HTML (skip nav chrome and script noise).
 */
function website_import_extract_business_text(string $html, string $url = ''): string
{
    if ($html === '') {
        return '';
    }

    $parts = [];

    if (preg_match('#<title[^>]*>([^<]+)</title>#i', $html, $m)) {
        $parts[] = 'Page: ' . website_import_clean_html($m[1]);
    }

    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
        $parts[] = website_import_clean_html($m[1]);
    } elseif (preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']#i', $html, $m)) {
        $parts[] = website_import_clean_html($m[1]);
    }

    if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $jsonBlocks)) {
        foreach ($jsonBlocks[1] as $block) {
            $data = json_decode(trim($block), true);
            if (!is_array($data)) {
                continue;
            }
            $text = website_import_jsonld_to_text($data);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }

    $contentHtml = preg_replace('/<(nav|header|footer|aside)[^>]*>.*?<\/\1>/is', ' ', $html);
    $contentHtml = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $contentHtml);

    $mainChunks = [];
    if (preg_match_all('#<(main|article|section)[^>]*>(.*?)</\1>#is', $contentHtml, $sections)) {
        foreach ($sections[2] as $chunk) {
            $t = website_import_clean_html($chunk);
            if (mb_strlen($t) > 80) {
                $mainChunks[] = $t;
            }
        }
    }

    if ($mainChunks === [] && preg_match_all('#<(h[1-3]|p)[^>]*>(.*?)</\1>#is', $contentHtml, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $b) {
            $t = website_import_clean_html($b[2]);
            if (mb_strlen($t) > 25) {
                $mainChunks[] = $t;
            }
        }
    }

    if ($mainChunks !== []) {
        $parts[] = implode("\n\n", array_slice($mainChunks, 0, 12));
    } else {
        $fallback = website_import_clean_html($contentHtml);
        if (mb_strlen($fallback) > 100) {
            $parts[] = $fallback;
        }
    }

    $text = trim(implode("\n\n", array_unique(array_filter($parts))));
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $lines = array_map(static function (string $line): string {
        return preg_replace('/[ \t]+/u', ' ', trim($line)) ?? trim($line);
    }, $lines);
    $text = trim(implode("\n", array_filter($lines, static fn (string $l): bool => $l !== '')));
    $text = website_import_normalize_visible_text($text);
    $text = website_import_dedupe_lines($text);

    return mb_substr($text, 0, 8000);
}

/** Remove repeated lines (duplicate prices, headers, etc.). */
function website_import_dedupe_lines(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $seen = [];
    $out = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $out[] = '';
            continue;
        }

        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
        if (mb_strlen($key) >= 10 && isset($seen[$key])) {
            continue;
        }
        if (mb_strlen($key) >= 10) {
            $seen[$key] = true;
        }
        $out[] = $trimmed;
    }

    return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $out)) ?? implode("\n", $out));
}

function website_import_parse_price(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) {
        $num = (float) $raw;
        if ($num > 1000000) {
            return $num / 100;
        }
        return max(0, $num);
    }

    $str = trim((string) $raw);
    if ($str === '') {
        return 0.0;
    }

    $str = preg_replace('/[^\d.,]/', '', $str);
    $str = str_replace(',', '', $str);
    return max(0, (float) $str);
}

/**
 * @return array<int, string>
 */
function website_import_discover_product_urls(string $baseUrl, string $homeHtml = ''): array
{
    $base = rtrim($baseUrl, '/');
    $urls = [];

    $sitemaps = website_import_find_sitemaps($base);
    foreach ($sitemaps as $sitemapUrl) {
        $response = website_import_http_get($sitemapUrl);
        if ($response['code'] >= 400 || $response['body'] === '') {
            continue;
        }

        if (preg_match_all('#<loc>([^<]+)</loc>#i', $response['body'], $matches)) {
            foreach ($matches[1] as $loc) {
                $loc = html_entity_decode(trim($loc));
                if (website_import_looks_like_product_url($loc)) {
                    $urls[] = $loc;
                } elseif (str_ends_with(strtolower($loc), '.xml')) {
                    $sub = website_import_http_get($loc);
                    if ($sub['code'] === 200 && preg_match_all('#<loc>([^<]+)</loc>#i', $sub['body'], $subMatches)) {
                        foreach ($subMatches[1] as $subLoc) {
                            $subLoc = html_entity_decode(trim($subLoc));
                            if (website_import_looks_like_product_url($subLoc)) {
                                $urls[] = $subLoc;
                            }
                        }
                    }
                }
            }
        }

        if (count($urls) >= WEBSITE_IMPORT_MAX_PRODUCTS) {
            break;
        }
    }

    if ($homeHtml === '') {
        $home = website_import_http_get($base . '/');
        $homeHtml = $home['body'] ?? '';
    }

    $pagesToScan = [$homeHtml];
    $listingPages = [
        $base . '/collections/all',
        $base . '/shop',
        $base . '/products',
        $base . '/collections/all/products',
    ];

    for ($page = 1; $page <= 8; $page++) {
        $listingPages[] = $base . '/collections/all?page=' . $page;
        $listingPages[] = $base . '/shop/page/' . $page;
    }

    foreach ($listingPages as $listingUrl) {
        if (is_string($listingUrl) && !str_contains($listingUrl, '<')) {
            $response = website_import_http_get($listingUrl);
            if ($response['code'] < 400 && $response['body'] !== '') {
                $pagesToScan[] = $response['body'];
            }
        }
    }

    foreach ($pagesToScan as $pageHtml) {
        if (!is_string($pageHtml) || $pageHtml === '') {
            continue;
        }
        if (preg_match_all('#href=["\']([^"\']+)["\']#i', $pageHtml, $hrefMatches)) {
            foreach ($hrefMatches[1] as $href) {
                $abs = website_import_absolute_url($base, $href);
                if ($abs && website_import_looks_like_product_url($abs)) {
                    $urls[] = $abs;
                }
            }
        }
        if (preg_match_all('#https?://[^\s"\'<>]+/products/[^\s"\'<>]+#i', $pageHtml, $directMatches)) {
            foreach ($directMatches[0] as $direct) {
                if (website_import_looks_like_product_url($direct)) {
                    $urls[] = $direct;
                }
            }
        }
    }

    return array_values(array_unique($urls));
}

/**
 * @return array<int, string>
 */
function website_import_find_sitemaps(string $baseUrl): array
{
    $base = rtrim($baseUrl, '/');
    $found = [];

    $candidates = [
        $base . '/sitemap_products_1.xml',
        $base . '/sitemap.xml',
        $base . '/sitemap_products.xml',
        $base . '/product-sitemap.xml',
        $base . '/wp-sitemap-posts-product-1.xml',
    ];

    $robots = website_import_http_get($base . '/robots.txt');
    if ($robots['code'] === 200 && preg_match_all('#Sitemap:\s*(.+)$#mi', $robots['body'], $m)) {
        foreach ($m[1] as $sm) {
            $candidates[] = trim($sm);
        }
    }

    foreach ($candidates as $url) {
        $probe = website_import_http_get($url);
        if ($probe['code'] === 200 && str_contains($probe['body'], '<loc>')) {
            $found[] = $url;
        }
    }

    return array_values(array_unique($found));
}

function website_import_looks_like_product_url(string $url): bool
{
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    if ($path === '' || $path === '/') {
        return false;
    }

    if (preg_match('#/(cart|checkout|account|login|search|policy|pages/)#i', $path)) {
        return false;
    }

    return (bool) preg_match('#/(products?/[^/]+|product/[^/]+|item/[^/]+|p/[^/]+|shop/[^/]+)$#i', rtrim($path, '/'));
}

function website_import_absolute_url(string $base, string $href): ?string
{
    $href = trim(html_entity_decode($href));
    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
        return null;
    }

    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }

    if (str_starts_with($href, '//')) {
        return 'https:' . $href;
    }

    $baseParts = parse_url($base);
    if (!$baseParts || empty($baseParts['host'])) {
        return null;
    }

    $scheme = $baseParts['scheme'] ?? 'https';
    $origin = $scheme . '://' . $baseParts['host'];

    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }

    return rtrim($base, '/') . '/' . ltrim($href, '/');
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_extract_json_ld_products(string $html, string $pageUrl): array
{
    $out = [];

    if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
        return [];
    }

    foreach ($blocks[1] as $jsonText) {
        $jsonText = trim(html_entity_decode($jsonText));
        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded)) {
            continue;
        }

        foreach (website_import_flatten_json_ld($decoded) as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (website_import_is_product_type($node)) {
                $mapped = website_import_map_json_ld_product($node, $pageUrl);
                if ($mapped) {
                    $out[] = $mapped;
                }
            } elseif (website_import_is_type($node, 'itemlist') && is_array($node['itemListElement'] ?? null)) {
                foreach ($node['itemListElement'] as $el) {
                    if (!is_array($el)) {
                        continue;
                    }
                    $item = is_array($el['item'] ?? null) ? $el['item'] : $el;
                    if (website_import_is_product_type($item)) {
                        $mapped = website_import_map_json_ld_product($item, (string) ($item['url'] ?? $pageUrl));
                        if ($mapped) {
                            $out[] = $mapped;
                        }
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $node
 */
function website_import_is_product_type(array $node): bool
{
    return website_import_is_type($node, 'product');
}

/**
 * @param array<string, mixed> $node
 */
function website_import_is_type(array $node, string $type): bool
{
    $raw = $node['@type'] ?? '';
    if (is_array($raw)) {
        foreach ($raw as $t) {
            if (strtolower((string) $t) === $type) {
                return true;
            }
        }
        return false;
    }
    return strtolower((string) $raw) === $type;
}

/**
 * @return array<int, mixed>
 */
function website_import_flatten_json_ld(array $node): array
{
    if (isset($node['@graph']) && is_array($node['@graph'])) {
        return $node['@graph'];
    }

    return [$node];
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>|null
 */
function website_import_map_json_ld_product(array $node, string $pageUrl): ?array
{
    $name = trim((string) ($node['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $offers = $node['offers'] ?? [];
    if (isset($offers[0]) && is_array($offers[0])) {
        $offers = $offers[0];
    }
    if (!is_array($offers)) {
        $offers = [];
    }

    $price = website_import_parse_price($offers['price'] ?? $offers['lowPrice'] ?? $offers['highPrice'] ?? 0);
    $currency = strtoupper((string) ($offers['priceCurrency'] ?? default_currency()));

    $image = '';
    if (isset($node['image'])) {
        if (is_string($node['image'])) {
            $image = $node['image'];
        } elseif (is_array($node['image'])) {
            $image = is_string($node['image'][0] ?? null) ? $node['image'][0] : (string) ($node['image']['url'] ?? ($node['image']['contentUrl'] ?? ''));
        }
    }

    $externalId = (string) ($node['sku'] ?? $node['productID'] ?? '');
    if ($externalId === '') {
        $externalId = substr(md5($pageUrl . $name), 0, 16);
    }

    $desc = website_import_clean_html((string) ($node['description'] ?? ''));

    return website_import_normalize_product([
        'external_id' => $externalId,
        'name'        => $name,
        'description' => $desc,
        'price'       => $price,
        'currency'    => $currency !== '' ? $currency : website_import_guess_currency($pageUrl),
        'image_url'   => $image,
        'sku'         => (string) ($node['sku'] ?? ''),
        'category'    => is_string($node['category'] ?? null) ? (string) $node['category'] : '',
        'source_url'  => (string) ($node['url'] ?? $pageUrl),
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function website_import_extract_og_product(string $html, string $pageUrl): array
{
    $getMeta = static function (string $prop) use ($html): string {
        if (preg_match('#<meta[^>]+property=["\']' . preg_quote($prop, '#') . '["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }
        if (preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . preg_quote($prop, '#') . '["\']#i', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }
        if (preg_match('#<meta[^>]+name=["\']' . preg_quote($prop, '#') . '["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }
        return '';
    };

    $title = $getMeta('og:title');
    if ($title === '') {
        if (preg_match('#<title[^>]*>([^<]+)</title>#i', $html, $m)) {
            $title = html_entity_decode(trim($m[1]));
            $title = preg_replace('/\s*[|\-–—]\s*.*$/', '', $title);
        }
    }

    if ($title === '') {
        return [];
    }

    $priceMeta = $getMeta('product:price:amount');
    if ($priceMeta === '') {
        $priceMeta = $getMeta('og:price:amount');
    }
    $currencyMeta = $getMeta('product:price:currency');
    if ($currencyMeta === '') {
        $currencyMeta = $getMeta('og:price:currency');
    }

    $desc = $getMeta('og:description');
    if ($desc === '') {
        $desc = $getMeta('description');
    }

    return [website_import_normalize_product([
        'external_id' => substr(md5($pageUrl . $title), 0, 16),
        'name'        => $title,
        'description' => $desc,
        'price'       => $priceMeta !== '' ? website_import_parse_price($priceMeta) : 0,
        'currency'    => $currencyMeta !== '' ? strtoupper($currencyMeta) : website_import_guess_currency($pageUrl),
        'image_url'   => $getMeta('og:image'),
        'source_url'  => $pageUrl,
    ])];
}

/**
 * Clean scraped page titles into short product names.
 */
function website_import_clean_product_name(string $name): string
{
    $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($name === '') {
        return '';
    }

    if (preg_match('/^Order\s+(.+?)\s+Online(?:\s*[|\-–—]\s*.+)?$/iu', $name, $m)) {
        $name = trim($m[1]);
    } elseif (preg_match('/^(.+?)\s+Online\s*[|\-–—]/iu', $name, $m)) {
        $name = trim($m[1]);
    }

    if (str_contains($name, '|')) {
        $parts = array_values(array_filter(array_map('trim', explode('|', $name))));
        usort($parts, static fn (string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 3
                && mb_strlen($part) <= 80
                && !preg_match('/^(order|online|menu|home|shop|store)$/iu', $part)) {
                $name = $part;
                break;
            }
        }
    }

    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return trim($name);
}

/**
 * Drop broken/placeholder image URLs from scrapers.
 */
function website_import_valid_image_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }

    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }

    if (preg_match('/placeholder|1x1|pixel|spacer|data:image|\.svg(\?|$)/i', $url)) {
        return '';
    }

    return $url;
}

/**
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function website_import_normalize_product(array $product): array
{
    $name = website_import_clean_product_name(trim((string) ($product['name'] ?? '')));
    $sourceUrl = trim((string) ($product['source_url'] ?? ''));
    $externalId = trim((string) ($product['external_id'] ?? ''));
    if ($externalId === '') {
        $externalId = substr(md5($sourceUrl . $name), 0, 20);
    }

    $image = website_import_valid_image_url(trim((string) ($product['image_url'] ?? '')));

    $description = trim((string) ($product['description'] ?? ''));
    if ($description === '' && $name !== '') {
        $description = $name;
    }

    return [
        'external_id' => $externalId,
        'name'        => $name !== '' ? $name : 'Product',
        'description' => mb_substr($description, 0, 2000),
        'price'       => max(0, (float) ($product['price'] ?? 0)),
        'currency'    => strtoupper(substr(trim((string) ($product['currency'] ?? default_currency())), 0, 8)) ?: default_currency(),
        'image_url'   => $image,
        'sku'         => trim((string) ($product['sku'] ?? '')),
        'category'    => trim((string) ($product['category'] ?? '')),
        'stock'       => array_key_exists('stock', $product) && $product['stock'] !== null ? (int) $product['stock'] : null,
        'is_active'   => 1,
        'source_url'  => $sourceUrl,
    ];
}

function website_import_guess_currency(string $baseUrl, array $variant = []): string
{
    $cur = strtoupper(trim((string) ($variant['currency'] ?? '')));
    if ($cur !== '') {
        return $cur;
    }

    $host = parse_url($baseUrl, PHP_URL_HOST) ?? '';
    if (str_ends_with($host, '.pk') || str_contains(strtolower($host), 'pakistan')) {
        return 'PKR';
    }

    return default_currency();
}

/**
 * @return array{code: int, body: string, headers: string}
 */
function website_import_http_get(string $url): array
{
    require_once __DIR__ . '/whatsapp.php';

    $baseOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => website_import_limit('fetch_timeout', WEBSITE_IMPORT_FETCH_TIMEOUT),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/json,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ];

    $attempts = [['url' => $url, 'headers' => []]];
    $loopback = website_import_loopback_url($url);
    if ($loopback !== null) {
        $attempts[] = ['url' => $loopback['url'], 'headers' => $loopback['headers']];
    }

    foreach ($attempts as $attempt) {
        $tryUrl = $attempt['url'];
        $host = parse_url($tryUrl, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($tryUrl, PHP_URL_SCHEME));
        $port = (int) (parse_url($tryUrl, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443));
        $ipCandidates = is_string($host) && $host !== '' && defined('CURLOPT_RESOLVE')
            ? whatsapp_resolve_host_ip_candidates($host)
            : [null];

        foreach ($ipCandidates as $ip) {
            $opts = $baseOpts;
            if ($attempt['headers'] !== []) {
                $opts[CURLOPT_HTTPHEADER] = array_merge($opts[CURLOPT_HTTPHEADER], $attempt['headers']);
            }
            if ($ip !== null && is_string($host)) {
                $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ip}"];
            }

            $ch = curl_init($tryUrl);
            curl_setopt_array($ch, whatsapp_curl_opts($opts));
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (is_string($raw) && $raw !== '') {
                return [
                    'code'    => $code,
                    'body'    => substr($raw, $headerSize),
                    'headers' => substr($raw, 0, $headerSize),
                ];
            }

            if ($curlErr !== '' && (str_contains($curlErr, 'getaddrinfo') || str_contains($curlErr, 'Could not resolve'))) {
                $streamOpts = $opts;
                unset($streamOpts[CURLOPT_HEADER]);
                $stream = whatsapp_stream_request($tryUrl, $streamOpts, $curlErr);
                if ($stream['ok'] && is_string($stream['body'])) {
                    return [
                        'code'    => $stream['http_code'],
                        'body'    => $stream['body'],
                        'headers' => '',
                    ];
                }
            }
        }
    }

    return ['code' => 0, 'body' => '', 'headers' => ''];
}

function website_import_parse_link_header(string $headers, string $rel): ?string
{
    if (!preg_match_all('#<([^>]+)>;\s*rel="([^"]+)"#i', $headers, $matches, PREG_SET_ORDER)) {
        return null;
    }

    foreach ($matches as $m) {
        $rels = array_map('trim', explode(',', $m[2]));
        if (in_array($rel, $rels, true)) {
            return $m[1];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $product
 * @return 'imported'|'updated'|'skipped'
 */
function website_import_upsert_product(int $botId, int $userId, array $product): string
{
    ensure_commerce_schema();

    $externalId = trim((string) ($product['external_id'] ?? ''));
    if ($externalId === '') {
        throw new InvalidArgumentException('Missing product id.');
    }

    return catalog_upsert_import_product($botId, $userId, [
        'name'        => trim((string) ($product['name'] ?? 'Product')),
        'description' => trim((string) ($product['description'] ?? '')),
        'price'       => max(0, (float) ($product['price'] ?? 0)),
        'currency'    => strtoupper(substr(trim((string) ($product['currency'] ?? default_currency())), 0, 8)) ?: default_currency(),
        'image_url'   => trim((string) ($product['image_url'] ?? '')),
        'sku'         => trim((string) ($product['sku'] ?? '')),
        'category'    => trim((string) ($product['category'] ?? '')),
        'stock'       => array_key_exists('stock', $product) && $product['stock'] !== null ? (int) $product['stock'] : null,
        'is_active'   => array_key_exists('is_active', $product) ? (!empty($product['is_active']) ? 1 : 0) : 1,
        'sort_order'  => 0,
    ], 'website', $externalId);
}

/**
 * @return array{success: bool, platform: string, total: int, sample: array<int, array<string, mixed>>, message?: string}
 */
function website_import_preview(string $url, int $sampleLimit = 8): array
{
    try {
        @set_time_limit(60);

        return website_import_with_limits([
            'max_products'        => WEBSITE_IMPORT_PREVIEW_MAX_PRODUCTS,
            'max_pages'           => WEBSITE_IMPORT_PREVIEW_MAX_PAGES,
            'max_generic_fetches'   => WEBSITE_IMPORT_PREVIEW_MAX_GENERIC_FETCHES,
            'fetch_timeout'       => WEBSITE_IMPORT_PREVIEW_FETCH_TIMEOUT,
        ], static function () use ($url, $sampleLimit): array {
            $parsed = website_import_parse_url($url);
            if (!$parsed['valid']) {
                return ['success' => false, 'platform' => '', 'total' => 0, 'sample' => [], 'message' => 'Invalid URL.'];
            }

            $home = website_import_http_get($parsed['base'] . '/');
            $platform = website_import_detect_platform($url, $home['body'] ?? '');
            $products = website_import_fetch_products($url);
            $total = count($products);
            $note = $total >= WEBSITE_IMPORT_PREVIEW_MAX_PRODUCTS
                ? ' (preview scan — full import finds more)'
                : '';
            if ($platform === 'indolj' && $total > 0 && $total <= 5) {
                $note = ' — server could not reach Indolj API; import will try from your browser';
            }

            $response = [
                'success'  => true,
                'platform' => $platform,
                'total'    => $total,
                'sample'   => array_map(static function (array $p): array {
                    return [
                        'name'        => $p['name'],
                        'price'       => $p['price'],
                        'currency'    => $p['currency'],
                        'description' => mb_substr((string) ($p['description'] ?? ''), 0, 120),
                        'image_url'   => $p['image_url'],
                        'category'    => $p['category'],
                    ];
                }, array_slice($products, 0, max(1, min(12, $sampleLimit)))),
                'message'  => sprintf('Found %d+ products (%s store)%s. Click Import for the full catalog.', $total, ucfirst($platform), $note),
            ];

            if ($platform === 'indolj' && $total <= 5) {
                $browserCtx = website_import_indolj_client_context($url);
                if ($browserCtx !== null) {
                    $response['needs_browser'] = true;
                    $response['indolj_browser'] = $browserCtx;
                }
            }

            return $response;
        });
    } catch (Throwable $e) {
        return ['success' => false, 'platform' => '', 'total' => 0, 'sample' => [], 'message' => $e->getMessage()];
    }
}

/**
 * @return array{success: bool, imported: int, updated: int, total: int, platform: string, errors: array<int, string>, message?: string}
 */
function website_import_apply_products(int $botId, int $userId, array $products, string $platform, string $sourceBase, bool $replaceExisting = true): array
{
    ensure_commerce_schema();
    website_import_ensure_bot_columns();

    if ($replaceExisting) {
        db_execute(
            'DELETE FROM bot_products WHERE bot_id = ? AND user_id = ?',
            'ii',
            [$botId, $userId]
        );
    }

    $imported = 0;
    $updated = 0;
    $errors = [];

    foreach ($products as $product) {
        try {
            $result = website_import_upsert_product($botId, $userId, $product);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        } catch (Throwable $e) {
            if (count($errors) < 8) {
                $errors[] = ($product['name'] ?? 'Product') . ': ' . $e->getMessage();
            }
        }
    }

    db_execute(
        'UPDATE bots SET catalog_source_url = ?, catalog_source_synced_at = NOW() WHERE id = ? AND user_id = ?',
        'sii',
        [$sourceBase, $botId, $userId]
    );

    require_once __DIR__ . '/meta-catalog-sync.php';
    meta_catalog_mark_bot_pending($botId);

    return [
        'success'  => true,
        'imported' => $imported,
        'updated'  => $updated,
        'total'    => count($products),
        'platform' => $platform,
        'errors'   => $errors,
        'message'  => sprintf(
            'Imported %d products from %s store. Your previous catalog was replaced.',
            count($products),
            ucfirst($platform)
        ),
    ];
}

/**
 * Import Indolj menu payloads fetched in the user's browser (bypasses server IP blocks).
 *
 * @param array<int, mixed> $menuResponses
 * @return array{success: bool, imported?: int, updated?: int, total?: int, platform?: string, message?: string, error?: string}
 */
function website_import_indolj_browser(int $botId, int $userId, string $url, array $menuResponses): array
{
    $parsed = website_import_parse_url($url);
    if (!$parsed['valid']) {
        return ['success' => false, 'error' => 'Invalid URL.'];
    }

    $ctx = website_import_indolj_client_context($url);
    if ($ctx === null) {
        return ['success' => false, 'error' => 'Could not read Indolj store context.'];
    }

    $products = website_import_indolj_products_from_responses($menuResponses, $ctx['image_base'], $parsed['base']);
    if ($products === []) {
        return ['success' => false, 'error' => 'No menu items in browser response. Open your menu page and try again.'];
    }

    return website_import_apply_products($botId, $userId, $products, 'indolj', $parsed['base'], true);
}

function website_import_jobs_key(): string
{
    return 'website_import_jobs';
}

function website_import_jobs_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/website-import-jobs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function website_import_job_file(string $jobId): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId));

    return website_import_jobs_dir() . '/' . $safe . '.json';
}

/** @return array<string, mixed>|null */
function website_import_read_job(string $jobId): ?array
{
    $path = website_import_job_file($jobId);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $job = json_decode($raw, true);

    return is_array($job) ? $job : null;
}

/** @param array<string, mixed> $job */
function website_import_write_job(string $jobId, array $job): bool
{
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return false;
    }

    return @file_put_contents(website_import_job_file($jobId), $json, LOCK_EX) !== false;
}

function website_import_delete_job(string $jobId): void
{
    $path = website_import_job_file($jobId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function website_import_cleanup_jobs(): void
{
    $dir = website_import_jobs_dir();
    if (!is_dir($dir)) {
        return;
    }

    $now = time();
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $mtime = (int) @filemtime($path);
        if ($mtime > 0 && ($now - $mtime) > WEBSITE_IMPORT_JOB_TTL) {
            @unlink($path);
        }
    }

    // Legacy session jobs (older builds).
    if (!isset($_SESSION[website_import_jobs_key()]) || !is_array($_SESSION[website_import_jobs_key()])) {
        return;
    }

    foreach ($_SESSION[website_import_jobs_key()] as $jobId => $job) {
        $created = (int) (is_array($job) ? ($job['created_at'] ?? 0) : 0);
        if ($created > 0 && ($now - $created) > WEBSITE_IMPORT_JOB_TTL) {
            unset($_SESSION[website_import_jobs_key()][$jobId]);
        }
    }
}

/**
 * @return array{success: bool, job_id?: string, total?: int, platform?: string, message?: string, error?: string}
 */
function website_import_start_job(int $botId, int $userId, string $url): array
{
    website_import_cleanup_jobs();

    try {
        $parsed = website_import_parse_url($url);
        if (!$parsed['valid']) {
            throw new InvalidArgumentException('Enter a valid website URL.');
        }

        $home = website_import_http_get($parsed['base'] . '/');
        $platform = website_import_detect_platform($url, $home['body'] ?? '');
        $products = website_import_fetch_products($url);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    if ($platform === 'indolj' && count($products) <= 5) {
        $browserCtx = website_import_indolj_client_context($url);
        if ($browserCtx !== null) {
            return [
                'success'       => true,
                'needs_browser' => true,
                'indolj_browser'=> $browserCtx,
                'total'         => count($products),
                'platform'      => $platform,
                'message'       => 'Indolj menu will be fetched from your browser (restaurant API blocks server imports).',
            ];
        }
    }

    if ($products === []) {
        return [
            'success' => false,
            'error'   => 'No products found on this website — your existing catalog was kept unchanged.',
        ];
    }

    $jobId = bin2hex(random_bytes(12));
    $job = [
        'bot_id'    => $botId,
        'user_id'   => $userId,
        'url'       => $url,
        'base'      => $parsed['base'],
        'platform'  => $platform,
        'products'  => $products,
        'offset'    => 0,
        'imported'  => 0,
        'updated'   => 0,
        'errors'    => [],
        'cleared'   => false,
        'created_at'=> time(),
    ];

    if (!website_import_write_job($jobId, $job)) {
        return [
            'success' => false,
            'error'   => 'Could not start import (storage error). Contact support or try a smaller catalog.',
        ];
    }

    return [
        'success'  => true,
        'job_id'   => $jobId,
        'total'    => count($products),
        'platform' => $platform,
        'message'  => sprintf('Found %d products (%s). Importing in batches…', count($products), ucfirst($platform)),
    ];
}

/**
 * @return array{success: bool, done?: bool, imported?: int, updated?: int, total?: int, processed?: int, message?: string, error?: string}
 */
function website_import_run_batch(string $jobId, int $botId, int $userId): array
{
    website_import_cleanup_jobs();
    $job = website_import_read_job($jobId);

    // Legacy session fallback.
    if ($job === null) {
        $jobs = $_SESSION[website_import_jobs_key()] ?? [];
        $job = is_array($jobs[$jobId] ?? null) ? $jobs[$jobId] : null;
    }

    if ($job === null) {
        return ['success' => false, 'error' => 'Import session expired. Click Fetch & Import again.'];
    }

    if ((int) ($job['bot_id'] ?? 0) !== $botId || (int) ($job['user_id'] ?? 0) !== $userId) {
        return ['success' => false, 'error' => 'Unauthorized import job.'];
    }

    $products = is_array($job['products'] ?? null) ? $job['products'] : [];
    $total = count($products);
    $offset = (int) ($job['offset'] ?? 0);

    if ($total === 0) {
        website_import_delete_job($jobId);
        unset($_SESSION[website_import_jobs_key()][$jobId]);

        return ['success' => false, 'error' => 'No products in import job.'];
    }

    ensure_commerce_schema();
    website_import_ensure_bot_columns();

    if (empty($job['cleared'])) {
        db_execute(
            'DELETE FROM bot_products WHERE bot_id = ? AND user_id = ?',
            'ii',
            [$botId, $userId]
        );
        $job['cleared'] = true;
        website_import_write_job($jobId, $job);
    }

    $batch = array_slice($products, $offset, WEBSITE_IMPORT_BATCH_SIZE);
    $imported = (int) ($job['imported'] ?? 0);
    $updated = (int) ($job['updated'] ?? 0);
    $errors = is_array($job['errors'] ?? null) ? $job['errors'] : [];

    foreach ($batch as $product) {
        if (!is_array($product)) {
            continue;
        }
        try {
            $result = website_import_upsert_product($botId, $userId, $product);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        } catch (Throwable $e) {
            if (count($errors) < 8) {
                $errors[] = ($product['name'] ?? 'Product') . ': ' . $e->getMessage();
            }
        }
    }

    $offset += count($batch);
    $done = $offset >= $total;

    if ($done) {
        db_execute(
            'UPDATE bots SET catalog_source_url = ?, catalog_source_synced_at = NOW() WHERE id = ? AND user_id = ?',
            'sii',
            [(string) ($job['base'] ?? ''), $botId, $userId]
        );
        $deduped = catalog_deduplicate_bot_products($botId, $userId);
        if ($deduped > 0) {
            $imported = max(0, $imported - $deduped);
        }
        require_once __DIR__ . '/meta-catalog-sync.php';
        meta_catalog_mark_bot_pending($botId);
        website_import_delete_job($jobId);
        unset($_SESSION[website_import_jobs_key()][$jobId]);
    } else {
        $job['offset'] = $offset;
        $job['imported'] = $imported;
        $job['updated'] = $updated;
        $job['errors'] = $errors;
        if (!website_import_write_job($jobId, $job)) {
            return ['success' => false, 'error' => 'Import progress could not be saved. Try again.'];
        }
    }

    $savedCount = (int) (db_fetch(
        'SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id = ? AND user_id = ?',
        'ii',
        [$botId, $userId]
    )['cnt'] ?? 0);

    $message = $done
        ? ($savedCount > 0
            ? sprintf('Imported %d products (%s store).', $savedCount, ucfirst((string) ($job['platform'] ?? 'website')))
            : sprintf(
                'Import finished but 0 products were saved%s.',
                $errors !== [] ? ': ' . $errors[0] : '. Try Preview, then Fetch & Import again.'
            ))
        : sprintf('Importing… %d / %d products', $offset, $total);

    return [
        'success'     => true,
        'done'        => $done,
        'imported'    => $imported,
        'updated'     => $updated,
        'total'       => $total,
        'processed'   => $offset,
        'saved_count' => $savedCount,
        'errors'      => $errors,
        'message'     => $message,
    ];
}

/**
 * @return array{success: bool, imported: int, updated: int, total: int, platform: string, errors: array<int, string>, message?: string}
 */
function website_import_sync(int $botId, int $userId, string $url): array
{
    ensure_commerce_schema();
    website_import_ensure_bot_columns();

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        return ['success' => false, 'imported' => 0, 'updated' => 0, 'total' => 0, 'platform' => '', 'errors' => ['Invalid bot.'], 'message' => 'Invalid bot.'];
    }

    try {
        $parsed = website_import_parse_url($url);
        if (!$parsed['valid']) {
            throw new InvalidArgumentException('Enter a valid website URL.');
        }

        $home = website_import_http_get($parsed['base'] . '/');
        $platform = website_import_detect_platform($url, $home['body'] ?? '');
        $products = website_import_fetch_products($url);
    } catch (Throwable $e) {
        return ['success' => false, 'imported' => 0, 'updated' => 0, 'total' => 0, 'platform' => '', 'errors' => [$e->getMessage()], 'message' => $e->getMessage()];
    }

    if ($products === []) {
        return [
            'success'  => false,
            'imported' => 0,
            'updated'  => 0,
            'total'    => 0,
            'platform' => $platform ?? '',
            'errors'   => ['No products found on this website.'],
            'message'  => 'No products found — your existing catalog was kept unchanged.',
        ];
    }

    return website_import_apply_products($botId, $userId, $products, $platform ?? 'website', $parsed['base'], true);
}

function website_import_bot_status(int $botId): array
{
    website_import_ensure_bot_columns();

    $bot = db_fetch('SELECT catalog_source_url, catalog_source_synced_at FROM bots WHERE id = ?', 'i', [$botId]);
    $count = db_fetch(
        'SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id = ? AND external_source = ?',
        'is',
        [$botId, 'website']
    );

    return [
        'source_url' => trim((string) ($bot['catalog_source_url'] ?? '')),
        'synced_at'  => $bot['catalog_source_synced_at'] ?? null,
        'count'      => (int) ($count['cnt'] ?? 0),
    ];
}

/**
 * Disconnect linked website from Shop (optionally remove imported products).
 *
 * @return array{success: bool, removed?: int, message?: string, error?: string}
 */
function website_import_clear_bot(int $botId, int $userId, bool $deleteProducts = false): array
{
    ensure_commerce_schema();
    website_import_ensure_bot_columns();

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        return ['success' => false, 'error' => 'Invalid bot.'];
    }

    $removed = 0;
    if ($deleteProducts) {
        $removed = db_execute(
            'DELETE FROM bot_products WHERE bot_id = ? AND user_id = ? AND external_source = ?',
            'iis',
            [$botId, $userId, 'website']
        );
    }

    db_execute(
        'UPDATE bots SET catalog_source_url = NULL, catalog_source_synced_at = NULL WHERE id = ? AND user_id = ?',
        'ii',
        [$botId, $userId]
    );

    if ($deleteProducts && $removed > 0) {
        return [
            'success' => true,
            'removed' => $removed,
            'message' => 'Website disconnected and ' . $removed . ' imported product(s) removed.',
        ];
    }

    return [
        'success' => true,
        'removed' => $removed,
        'message' => $deleteProducts
            ? 'Website disconnected. No imported products were found to remove.'
            : 'Website link removed. Your imported products are still in the catalog.',
    ];
}

function website_import_ensure_bot_columns(): void
{
    ensure_commerce_schema();
    $conn = db_connect();
    commerce_ensure_column($conn, 'bots', 'catalog_source_url', 'VARCHAR(512) NULL');
    commerce_ensure_column($conn, 'bots', 'catalog_source_synced_at', 'TIMESTAMP NULL');
    commerce_ensure_column($conn, 'bots', 'catalog_menu_keywords', 'VARCHAR(512) NULL');
}
