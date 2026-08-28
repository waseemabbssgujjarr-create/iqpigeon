<?php
/**
 * Shopify / WooCommerce product sync into bot_products.
 */

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/catalog.php';

function shop_platforms(): array
{
    return [
        'shopify'      => 'Shopify',
        'woocommerce'  => 'WooCommerce',
    ];
}

function shop_normalize_store_url(string $url, string $platform): string
{
    $url = trim($url);
    $url = preg_replace('#^https?://#i', '', $url);
    $url = rtrim($url, '/');

    if ($platform === 'shopify' && !str_contains($url, '.')) {
        $url .= '.myshopify.com';
    }

    return $url;
}

/**
 * @return array<string, mixed>|null
 */
function shop_integration_for_bot(int $botId, string $platform): ?array
{
    ensure_commerce_schema();
    if (!array_key_exists($platform, shop_platforms())) {
        return null;
    }

    $row = db_fetch(
        'SELECT * FROM shop_integrations WHERE bot_id = ? AND platform = ?',
        'is',
        [$botId, $platform]
    );

    return $row ?: null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function shop_integrations_for_user(int $userId, ?int $botId = null): array
{
    ensure_commerce_schema();
    if ($botId) {
        return db_fetch_all(
            'SELECT si.*, b.name AS bot_name FROM shop_integrations si
             JOIN bots b ON b.id = si.bot_id
             WHERE si.user_id = ? AND si.bot_id = ?
             ORDER BY si.platform ASC',
            'ii',
            [$userId, $botId]
        );
    }
    return db_fetch_all(
        'SELECT si.*, b.name AS bot_name FROM shop_integrations si
         JOIN bots b ON b.id = si.bot_id
         WHERE si.user_id = ?
         ORDER BY si.updated_at DESC',
        'i',
        [$userId]
    );
}

/**
 * @param array<string, mixed> $data
 */
function shop_integration_save(int $botId, int $userId, string $platform, array $data): int
{
    ensure_commerce_schema();
    if (!array_key_exists($platform, shop_platforms())) {
        throw new InvalidArgumentException('Unsupported platform.');
    }

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        throw new InvalidArgumentException('Invalid bot.');
    }

    $storeUrl = shop_normalize_store_url((string) ($data['store_url'] ?? ''), $platform);
    if ($storeUrl === '') {
        throw new InvalidArgumentException('Store URL is required.');
    }

    $existing = shop_integration_for_bot($botId, $platform);
    $syncEnabled = !empty($data['sync_enabled']) ? 1 : 0;

    $accessToken = trim((string) ($data['access_token'] ?? ''));
    $apiKey = trim((string) ($data['api_key'] ?? ''));
    $apiSecret = trim((string) ($data['api_secret'] ?? ''));
    $webhookSecret = trim((string) ($data['webhook_secret'] ?? ''));

    if ($platform === 'shopify') {
        if ($accessToken === '' && empty($existing['access_token'])) {
            throw new InvalidArgumentException('Shopify Admin API access token is required.');
        }
    } else {
        if (($apiKey === '' || $apiSecret === '') && (empty($existing['api_key']) || empty($existing['api_secret']))) {
            throw new InvalidArgumentException('WooCommerce consumer key and secret are required.');
        }
    }

    $encAccess = $accessToken !== '' ? encrypt_token($accessToken) : ($existing['access_token'] ?? null);
    $encKey = $apiKey !== '' ? encrypt_token($apiKey) : ($existing['api_key'] ?? null);
    $encSecret = $apiSecret !== '' ? encrypt_token($apiSecret) : ($existing['api_secret'] ?? null);
    $encWebhook = $webhookSecret !== '' ? encrypt_token($webhookSecret) : ($existing['webhook_secret'] ?? null);

    if ($existing) {
        db_execute(
            'UPDATE shop_integrations SET store_url=?, api_key=?, api_secret=?, access_token=?, webhook_secret=?, sync_enabled=?, updated_at=NOW()
             WHERE id=? AND user_id=?',
            'sssssiii',
            [$storeUrl, $encKey, $encSecret, $encAccess, $encWebhook, $syncEnabled, (int) $existing['id'], $userId]
        );
        return (int) $existing['id'];
    }

    return db_insert(
        'INSERT INTO shop_integrations (bot_id, user_id, platform, store_url, api_key, api_secret, access_token, webhook_secret, sync_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iissssssi',
        [$botId, $userId, $platform, $storeUrl, $encKey, $encSecret, $encAccess, $encWebhook, $syncEnabled]
    );
}

function shop_integration_delete(int $integrationId, int $userId): bool
{
    ensure_commerce_schema();
    db_execute('DELETE FROM shop_integrations WHERE id = ? AND user_id = ?', 'ii', [$integrationId, $userId]);
    return true;
}

/**
 * @return array{success: bool, imported: int, updated: int, errors: array<int, string>, message?: string}
 */
function shop_sync_products(int $botId, int $userId, string $platform): array
{
    ensure_commerce_schema();

    $integration = shop_integration_for_bot($botId, $platform);
    if (!$integration || (int) ($integration['user_id'] ?? 0) !== $userId) {
        return ['success' => false, 'imported' => 0, 'updated' => 0, 'errors' => ['Integration not configured.'], 'message' => 'Not configured'];
    }

    if (empty($integration['sync_enabled'])) {
        return ['success' => false, 'imported' => 0, 'updated' => 0, 'errors' => ['Sync is disabled for this store.'], 'message' => 'Sync disabled'];
    }

    try {
        $products = $platform === 'shopify'
            ? shop_fetch_shopify_products($integration)
            : shop_fetch_woocommerce_products($integration);
    } catch (Throwable $e) {
        return ['success' => false, 'imported' => 0, 'updated' => 0, 'errors' => [$e->getMessage()], 'message' => $e->getMessage()];
    }

    $imported = 0;
    $updated = 0;
    $errors = [];

    foreach ($products as $ext) {
        try {
            $result = shop_upsert_external_product($botId, $userId, $platform, $ext);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        } catch (Throwable $e) {
            if (count($errors) < 8) {
                $errors[] = ($ext['name'] ?? 'Product') . ': ' . $e->getMessage();
            }
        }
    }

    db_execute(
        'UPDATE shop_integrations SET last_sync_at = NOW() WHERE id = ? AND user_id = ?',
        'ii',
        [(int) $integration['id'], $userId]
    );

    require_once __DIR__ . '/meta-catalog-sync.php';
    meta_catalog_mark_bot_pending($botId);

    return [
        'success'  => true,
        'imported' => $imported,
        'updated'  => $updated,
        'errors'   => $errors,
        'message'  => sprintf('Synced %d new, %d updated.', $imported, $updated),
    ];
}

/**
 * @param array<string, mixed> $integration
 * @return array<int, array<string, mixed>>
 */
function shop_fetch_shopify_products(array $integration): array
{
    $token = decrypt_token($integration['access_token'] ?? '');
    if ($token === false || $token === '') {
        throw new RuntimeException('Missing Shopify access token.');
    }

    $store = shop_normalize_store_url((string) $integration['store_url'], 'shopify');
    $url = 'https://' . $store . '/admin/api/2024-10/products.json?limit=250&status=active';

    $response = shop_http_get($url, [
        'X-Shopify-Access-Token: ' . $token,
        'Content-Type: application/json',
    ]);

    $data = json_decode($response['body'], true);
    if ($response['code'] >= 400 || !is_array($data)) {
        $msg = is_array($data) ? ($data['errors'] ?? 'Shopify API error') : 'Shopify API error';
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        throw new RuntimeException((string) $msg);
    }

    $out = [];
    foreach ($data['products'] ?? [] as $p) {
        $variant = $p['variants'][0] ?? [];
        $price = (float) ($variant['price'] ?? 0);
        $image = $p['image']['src'] ?? ($p['images'][0]['src'] ?? '');
        $out[] = [
            'external_id' => (string) ($p['id'] ?? ''),
            'name'        => (string) ($p['title'] ?? 'Product'),
            'description' => strip_tags((string) ($p['body_html'] ?? '')),
            'price'       => $price,
            'currency'    => strtoupper((string) ($variant['currency'] ?? 'USD')),
            'image_url'   => (string) $image,
            'sku'         => (string) ($variant['sku'] ?? ''),
            'category'    => (string) ($p['product_type'] ?? ''),
            'stock'       => isset($variant['inventory_quantity']) ? (int) $variant['inventory_quantity'] : null,
            'is_active'   => ($p['status'] ?? '') === 'active' ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $integration
 * @return array<int, array<string, mixed>>
 */
function shop_fetch_woocommerce_products(array $integration): array
{
    $key = decrypt_token($integration['api_key'] ?? '');
    $secret = decrypt_token($integration['api_secret'] ?? '');
    if ($key === false || $secret === false || $key === '' || $secret === '') {
        throw new RuntimeException('Missing WooCommerce API credentials.');
    }

    $store = shop_normalize_store_url((string) $integration['store_url'], 'woocommerce');
    $base = 'https://' . $store . '/wp-json/wc/v3/products?per_page=100&status=publish';
    $auth = base64_encode($key . ':' . $secret);

    $response = shop_http_get($base, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
    ]);

    $data = json_decode($response['body'], true);
    if ($response['code'] >= 400 || !is_array($data)) {
        $msg = is_array($data) ? ($data['message'] ?? 'WooCommerce API error') : 'WooCommerce API error';
        throw new RuntimeException((string) $msg);
    }

    $out = [];
    foreach ($data as $p) {
        if (!is_array($p)) {
            continue;
        }
        $out[] = [
            'external_id' => (string) ($p['id'] ?? ''),
            'name'        => (string) ($p['name'] ?? 'Product'),
            'description' => strip_tags((string) ($p['short_description'] ?? $p['description'] ?? '')),
            'price'       => (float) ($p['price'] ?? $p['regular_price'] ?? 0),
            'currency'    => strtoupper((string) ($p['currency'] ?? 'USD')),
            'image_url'   => (string) ($p['images'][0]['src'] ?? ''),
            'sku'         => (string) ($p['sku'] ?? ''),
            'category'    => (string) ($p['categories'][0]['name'] ?? ''),
            'stock'       => ($p['manage_stock'] ?? false) ? (int) ($p['stock_quantity'] ?? 0) : null,
            'is_active'   => ($p['status'] ?? '') === 'publish' ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $product
 * @return 'imported'|'updated'|'skipped'
 */
function shop_upsert_external_product(int $botId, int $userId, string $platform, array $product): string
{
    require_once __DIR__ . '/catalog.php';

    $externalId = trim((string) ($product['external_id'] ?? ''));
    if ($externalId === '') {
        throw new InvalidArgumentException('Missing external product id.');
    }

    $result = catalog_upsert_import_product($botId, $userId, [
        'name'        => trim((string) ($product['name'] ?? 'Product')),
        'description' => trim((string) ($product['description'] ?? '')),
        'price'       => max(0, (float) ($product['price'] ?? 0)),
        'currency'    => strtoupper(substr(trim((string) ($product['currency'] ?? 'USD')), 0, 8)) ?: 'USD',
        'image_url'   => trim((string) ($product['image_url'] ?? '')),
        'sku'         => trim((string) ($product['sku'] ?? '')),
        'category'    => trim((string) ($product['category'] ?? '')),
        'stock'       => array_key_exists('stock', $product) && $product['stock'] !== null ? (int) $product['stock'] : null,
        'is_active'   => !empty($product['is_active']) ? 1 : 0,
        'sort_order'  => 0,
    ], $platform, $externalId);

    if ($result === 'merged') {
        return 'updated';
    }

    return $result;
}

/**
 * @param array<int, string> $headers
 * @return array{code: int, body: string}
 */
function shop_http_get(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    $defaultHeaders = [
        'Accept: application/json, text/html, */*',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '',
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => is_string($body) ? $body : '',
    ];
}

function shop_webhook_url(int $botId, string $platform): string
{
    $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host;
    }

    return $base . '/api/shop-webhook.php?bot_id=' . $botId . '&platform=' . urlencode($platform);
}
