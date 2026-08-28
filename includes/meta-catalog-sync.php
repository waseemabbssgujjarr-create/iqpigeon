<?php
/**
 * Auto-provision Meta Commerce catalogs and sync bot_products → native WhatsApp product cards.
 * Runs per tenant when WhatsApp is connected — no manual Catalog ID paste required at scale.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/phase5-schema.php';
require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/whatsapp-token.php';

function meta_catalog_auto_sync_enabled(): bool
{
    return !defined('META_CATALOG_AUTO_SYNC') || (bool) META_CATALOG_AUTO_SYNC;
}

function meta_catalog_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_phase5_schema();
    ensure_commerce_schema();

    $conn = db_connect();
    commerce_ensure_column($conn, 'bots', 'meta_business_id', 'VARCHAR(64) NULL AFTER whatsapp_catalog_id');
    commerce_ensure_column($conn, 'bots', 'meta_catalog_status', "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER meta_business_id");
    commerce_ensure_column($conn, 'bots', 'meta_catalog_error', 'TEXT NULL AFTER meta_catalog_status');
    commerce_ensure_column($conn, 'bots', 'meta_catalog_sync_pending', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER meta_catalog_error');
    commerce_ensure_column($conn, 'bots', 'meta_catalog_synced_at', 'TIMESTAMP NULL AFTER meta_catalog_sync_pending');
    commerce_ensure_column($conn, 'bot_products', 'meta_product_id', 'VARCHAR(64) NULL AFTER meta_retailer_id');
    commerce_ensure_column($conn, 'bot_products', 'meta_synced_at', 'TIMESTAMP NULL AFTER meta_product_id');

    $done = true;
}

function meta_catalog_set_status(int $botId, string $status, string $error = ''): void
{
    meta_catalog_ensure_schema();
    if (meta_catalog_is_rate_limit_error($error)) {
        $status = 'catalog_rate_limited';
        $error = meta_catalog_rate_limit_message();
    }
    if ($status === 'catalog_rate_limited') {
        db_execute(
            'UPDATE bots SET meta_catalog_status = ?, meta_catalog_error = ?, meta_catalog_synced_at = NOW() WHERE id = ?',
            'ssi',
            [$status, $error !== '' ? $error : meta_catalog_rate_limit_message(), $botId]
        );
        return;
    }
    db_execute(
        'UPDATE bots SET meta_catalog_status = ?, meta_catalog_error = ? WHERE id = ?',
        'ssi',
        [$status, $error !== '' ? $error : null, $botId]
    );
}

function meta_catalog_mark_bot_pending(int $botId): void
{
    if (!meta_catalog_auto_sync_enabled() || $botId <= 0) {
        return;
    }

    meta_catalog_ensure_schema();
    db_execute(
        'UPDATE bots SET meta_catalog_sync_pending = 1 WHERE id = ? AND whatsapp_verified = 1',
        'i',
        [$botId]
    );
}

/**
 * @return array{token: string, waba_id: string, user_id: int, catalog_id: string, business_id: string, bot_name: string, phone_number_id: string}|null
 */
function meta_catalog_bot_access(int $botId): ?array
{
    meta_catalog_ensure_schema();

    $bot = db_fetch(
        'SELECT id, user_id, name, whatsapp_phone_id, whatsapp_token, whatsapp_verified,
                whatsapp_catalog_id, meta_business_id
         FROM bots WHERE id = ?',
        'i',
        [$botId]
    );

    if (!$bot || empty($bot['whatsapp_verified']) || empty($bot['whatsapp_phone_id'])) {
        return null;
    }

    $token = bot_whatsapp_token_plain((string) ($bot['whatsapp_token'] ?? ''));
    if ($token === false || $token === '') {
        $account = db_fetch(
            'SELECT business_token, waba_id FROM client_whatsapp_accounts WHERE client_id = ? ORDER BY id DESC LIMIT 1',
            'i',
            [(int) $bot['user_id']]
        );
        if ($account) {
            $token = decrypt_token((string) ($account['business_token'] ?? ''));
            $wabaId = trim((string) ($account['waba_id'] ?? ''));
        } else {
            return null;
        }
    } else {
        $account = db_fetch(
            'SELECT waba_id FROM client_whatsapp_accounts WHERE client_id = ? ORDER BY id DESC LIMIT 1',
            'i',
            [(int) $bot['user_id']]
        );
        $wabaId = trim((string) ($account['waba_id'] ?? ''));
    }

    if (!is_string($token) || $token === '') {
        return null;
    }

    return [
        'token'           => $token,
        'waba_id'         => $wabaId,
        'user_id'         => (int) $bot['user_id'],
        'catalog_id'      => trim((string) ($bot['whatsapp_catalog_id'] ?? '')),
        'business_id'     => trim((string) ($bot['meta_business_id'] ?? '')),
        'bot_name'        => trim((string) ($bot['name'] ?? 'Shop')),
        'phone_number_id' => trim((string) ($bot['whatsapp_phone_id'] ?? '')),
    ];
}

function meta_catalog_normalize_id(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (str_starts_with($raw, '[')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded[0])) {
            $raw = trim((string) $decoded[0]);
        }
    }
    if (str_contains($raw, ',')) {
        $raw = trim(explode(',', $raw, 2)[0]);
    }
    if ($raw === '' || !preg_match('/^\d{5,32}$/', $raw)) {
        return '';
    }

    return $raw;
}

/**
 * @param array<string, mixed> $payload
 */
function meta_catalog_extract_catalog_id_from_graph(array $payload): string
{
    $queue = [$payload];
    $guard = 0;
    while ($queue !== [] && $guard < 40) {
        $node = array_shift($queue);
        $guard++;
        if (!is_array($node)) {
            continue;
        }
        if (isset($node['catalog_id']) && is_scalar($node['catalog_id'])) {
            $id = meta_catalog_normalize_id((string) $node['catalog_id']);
            if ($id !== '') {
                return $id;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $queue[] = $value;
            }
        }
    }

    return '';
}

function meta_catalog_graph_error_message(array $result): string
{
    return trim((string) ($result['data']['error']['message'] ?? ''));
}

function meta_catalog_graph_error_code(array $result): int
{
    return (int) ($result['data']['error']['code'] ?? 0);
}

function meta_catalog_is_rate_limit_error(string $error): bool
{
    $lower = strtolower($error);

    return str_contains($lower, '#80008')
        || str_contains($lower, '(#80008)')
        || str_contains($lower, 'too many calls')
        || str_contains($lower, 'rate limit')
        || str_contains($lower, 'rate-limit')
        || str_contains($lower, 'request limit reached');
}

function meta_catalog_rate_limit_message(): string
{
    return 'Meta is temporarily rate-limiting this WhatsApp Business account (#80008). Wait about 30 minutes, then click Sync now once. Refreshing this page will make the wait longer.';
}

function meta_catalog_bot_in_rate_limit_cooldown(int $botId): bool
{
    $row = db_fetch(
        'SELECT meta_catalog_status, meta_catalog_error, meta_catalog_synced_at FROM bots WHERE id = ?',
        'i',
        [$botId]
    );
    if (!$row) {
        return false;
    }

    $status = (string) ($row['meta_catalog_status'] ?? '');
    $error = (string) ($row['meta_catalog_error'] ?? '');
    if ($status !== 'catalog_rate_limited' && !meta_catalog_is_rate_limit_error($error)) {
        return false;
    }

    $syncedAt = strtotime((string) ($row['meta_catalog_synced_at'] ?? '')) ?: 0;
    if ($syncedAt <= 0) {
        return true;
    }

    return $syncedAt > (time() - 1800);
}

function meta_catalog_is_write_permission_error(string $error): bool
{
    $lower = strtolower($error);

    return str_contains($lower, 'missing permissions')
        || str_contains($lower, 'catalog_management')
        || str_contains($lower, 'catalog write permission')
        || str_contains($lower, 'write permission')
        || str_contains($lower, '(#200)')
        || str_contains($lower, 'permission denied')
        || str_contains($lower, 'not authorized')
        || str_contains($lower, 'insufficient permission');
}

/**
 * @return list<string>
 */
function meta_catalog_problem_statuses(): array
{
    return [
        'error',
        'needs_business',
        'no_catalog_linked',
        'catalog_discovery_failed',
        'catalog_access_denied',
        'catalog_validation_failed',
        'catalog_write_permission_missing',
        'catalog_rate_limited',
        'business_id_required_for_catalog_creation',
    ];
}

function meta_catalog_status_is_problem(string $status): bool
{
    return in_array($status, meta_catalog_problem_statuses(), true);
}

/**
 * Customer Business Portfolio ID — only for explicit catalog CREATE.
 * Never falls back to a platform META_BUSINESS_ID constant.
 */
function meta_catalog_resolve_business_id(string $token, string $wabaId): string
{
    if ($wabaId !== '') {
        $waba = whatsapp_graph_get(rawurlencode($wabaId) . '?fields=owner_business_info', $token);
        $bizId = trim((string) ($waba['data']['owner_business_info']['id'] ?? ''));
        if ($bizId !== '') {
            return $bizId;
        }
    }

    return '';
}

/**
 * @return array{catalog_id: string, error: string}
 */
function meta_catalog_fetch_linked_catalog_id(string $wabaId, string $token, array $rejectIds = []): array
{
    if ($wabaId === '') {
        return ['catalog_id' => '', 'error' => ''];
    }

    $reject = array_fill_keys(array_map('strval', $rejectIds), true);

    $linked = whatsapp_graph_get(rawurlencode($wabaId) . '/product_catalogs?fields=id,name', $token);
    $graphErr = meta_catalog_graph_error_message($linked);
    if (meta_catalog_graph_error_code($linked) === 80008 || meta_catalog_is_rate_limit_error($graphErr)) {
        return ['catalog_id' => '', 'error' => $graphErr !== '' ? $graphErr : meta_catalog_rate_limit_message()];
    }
    foreach ($linked['data']['data'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = meta_catalog_normalize_id((string) ($row['id'] ?? ''));
        if ($id === '' || isset($reject[$id])) {
            continue;
        }

        return ['catalog_id' => $id, 'error' => ''];
    }

    return ['catalog_id' => '', 'error' => $graphErr];
}

/**
 * Discover the catalog already attached to this WhatsApp phone number.
 *
 * @return array{catalog_id: string, error: string, http_code: int}
 */
function meta_catalog_fetch_commerce_catalog_id(string $phoneNumberId, string $token, array $rejectIds = []): array
{
    $phoneNumberId = trim($phoneNumberId);
    if ($phoneNumberId === '') {
        return ['catalog_id' => '', 'error' => '', 'http_code' => 0];
    }

    $reject = array_fill_keys(array_map('strval', $rejectIds), true);
    $paths = [
        rawurlencode($phoneNumberId) . '/whatsapp_commerce_settings?fields=catalog_id,is_catalog_visible,is_cart_enabled',
        rawurlencode($phoneNumberId) . '/whatsapp_commerce_settings',
        rawurlencode($phoneNumberId) . '?fields=whatsapp_commerce_settings',
    ];

    $lastError = '';
    $lastCode = 0;
    foreach ($paths as $path) {
        $result = whatsapp_graph_get($path, $token);
        $lastCode = (int) ($result['http_code'] ?? 0);
        if ($lastCode >= 400) {
            $lastError = meta_catalog_graph_error_message($result);
            if ($lastError === '') {
                $lastError = 'Commerce settings request failed';
            }
            if (meta_catalog_graph_error_code($result) === 80008 || meta_catalog_is_rate_limit_error($lastError)) {
                return ['catalog_id' => '', 'error' => $lastError, 'http_code' => $lastCode];
            }
            continue;
        }
        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        $id = meta_catalog_extract_catalog_id_from_graph($payload);
        if ($id !== '' && !isset($reject[$id])) {
            return ['catalog_id' => $id, 'error' => '', 'http_code' => $lastCode];
        }
    }

    return ['catalog_id' => '', 'error' => $lastError, 'http_code' => $lastCode];
}

/**
 * List product catalogs on the customer's Business Portfolio (Embedded Signup business_id).
 *
 * @return array{catalog_id: string, error: string}
 */
function meta_catalog_fetch_business_catalog_id(string $businessId, string $token, array $rejectIds = []): array
{
    $businessId = meta_catalog_normalize_id($businessId);
    if ($businessId === '') {
        return ['catalog_id' => '', 'error' => ''];
    }

    $reject = array_fill_keys(array_map('strval', $rejectIds), true);
    $edges = ['owned_product_catalogs', 'client_product_catalogs', 'product_catalogs'];
    $lastError = '';

    foreach ($edges as $edge) {
        $result = whatsapp_graph_get(
            rawurlencode($businessId) . '/' . $edge . '?fields=id,name&limit=25',
            $token
        );
        $graphErr = meta_catalog_graph_error_message($result);
        if (meta_catalog_graph_error_code($result) === 80008 || meta_catalog_is_rate_limit_error($graphErr)) {
            return ['catalog_id' => '', 'error' => $graphErr !== '' ? $graphErr : meta_catalog_rate_limit_message()];
        }
        if ((int) ($result['http_code'] ?? 0) >= 400) {
            $lastError = $graphErr;
            continue;
        }
        foreach ($result['data']['data'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = meta_catalog_normalize_id((string) ($row['id'] ?? ''));
            if ($id === '' || isset($reject[$id])) {
                continue;
            }

            return ['catalog_id' => $id, 'error' => ''];
        }
    }

    return ['catalog_id' => '', 'error' => $lastError];
}

/**
 * @return array{catalog_id: string, source: string, error: string, status: string}
 */
function meta_catalog_discover_existing(string $phoneNumberId, string $wabaId, string $token, array $rejectIds = [], string $businessId = ''): array
{
    $commerce = meta_catalog_fetch_commerce_catalog_id($phoneNumberId, $token, $rejectIds);
    if ($commerce['catalog_id'] !== '') {
        return [
            'catalog_id' => $commerce['catalog_id'],
            'source'     => 'commerce_settings',
            'error'      => '',
            'status'     => '',
        ];
    }

    if (meta_catalog_is_rate_limit_error($commerce['error']) || (int) $commerce['http_code'] === 80008) {
        return [
            'catalog_id' => '',
            'source'     => '',
            'error'      => meta_catalog_rate_limit_message(),
            'status'     => 'catalog_rate_limited',
        ];
    }

    $linked = meta_catalog_fetch_linked_catalog_id($wabaId, $token, $rejectIds);
    if ($linked['catalog_id'] !== '') {
        return [
            'catalog_id' => $linked['catalog_id'],
            'source'     => 'waba_product_catalogs',
            'error'      => '',
            'status'     => '',
        ];
    }
    if (meta_catalog_is_rate_limit_error($linked['error'])) {
        return [
            'catalog_id' => '',
            'source'     => '',
            'error'      => meta_catalog_rate_limit_message(),
            'status'     => 'catalog_rate_limited',
        ];
    }

    $fromBusiness = meta_catalog_fetch_business_catalog_id($businessId, $token, $rejectIds);
    if ($fromBusiness['catalog_id'] !== '') {
        return [
            'catalog_id' => $fromBusiness['catalog_id'],
            'source'     => 'customer_business',
            'error'      => '',
            'status'     => '',
        ];
    }
    if (meta_catalog_is_rate_limit_error($fromBusiness['error'])) {
        return [
            'catalog_id' => '',
            'source'     => '',
            'error'      => meta_catalog_rate_limit_message(),
            'status'     => 'catalog_rate_limited',
        ];
    }

    $commerceErr = strtolower($commerce['error']);
    $status = 'no_catalog_linked';
            $error = 'No WhatsApp catalog ID was returned for this number. In Meta Embedded Signup, select your Catalogue when asked, then click Finish.';
    if (meta_catalog_is_rate_limit_error($commerce['error'])) {
        $status = 'catalog_rate_limited';
        $error = meta_catalog_rate_limit_message();
    } elseif ($commerce['http_code'] >= 400 && (meta_catalog_is_write_permission_error($commerce['error']) || str_contains($commerceErr, 'permission'))) {
        $status = 'catalog_access_denied';
        $error = 'Meta denied reading WhatsApp commerce settings for this number.';
    } elseif ($commerce['http_code'] >= 400 || $commerce['error'] !== '') {
        $status = 'catalog_discovery_failed';
        $error = $commerce['error'] !== ''
            ? $commerce['error']
            : 'Could not discover a WhatsApp catalog for this number.';
    }

    return [
        'catalog_id' => '',
        'source'     => '',
        'error'      => $error,
        'status'     => $status,
    ];
}

/**
 * Accept a catalog ID from a trusted Meta source (Embedded Signup / commerce settings)
 * even when /products listing is denied.
 *
 * @return array{valid: bool, error?: string, id?: string}
 */
function meta_catalog_existing_id_ok(string $catalogId, string $token, bool $trustedSource = false): array
{
    $catalogId = meta_catalog_normalize_id($catalogId);
    if ($catalogId === '') {
        return ['valid' => false, 'error' => 'Catalog ID empty'];
    }

    if ($trustedSource) {
        return ['valid' => true, 'id' => $catalogId];
    }

    $valid = meta_catalog_validate_catalog($catalogId, $token);
    if (!empty($valid['valid'])) {
        return $valid;
    }

    $result = whatsapp_graph_get(rawurlencode($catalogId) . '?fields=id,name,vertical', $token);
    $http = (int) ($result['http_code'] ?? 0);
    $graphErr = meta_catalog_graph_error_message($result);
    $vertical = trim((string) ($result['data']['vertical'] ?? ''));
    if ($http < 400 && !empty($result['data']['id']) && $vertical !== '') {
        return ['valid' => true, 'id' => (string) $result['data']['id']];
    }

    if ($trustedSource) {
        $combined = strtolower($graphErr . ' ' . (string) ($valid['error'] ?? ''));
        if (!str_contains($combined, 'does not exist')) {
            return ['valid' => true, 'id' => $catalogId];
        }
    }

    return [
        'valid' => false,
        'error' => ($valid['error'] ?? '') !== '' ? (string) $valid['error'] : ($graphErr !== '' ? $graphErr : 'Catalog could not be validated'),
    ];
}

function meta_catalog_save_existing_id(int $botId, string $catalogId): void
{
    db_execute(
        'UPDATE bots SET whatsapp_catalog_id = ?, meta_catalog_status = \'active\', meta_catalog_error = NULL WHERE id = ?',
        'si',
        [$catalogId, $botId]
    );
}

/**
 * @return array{valid: bool, error?: string, id?: string}
 */
function meta_catalog_validate_catalog(string $catalogId, string $token): array
{
    $catalogId = trim($catalogId);
    if ($catalogId === '') {
        return ['valid' => false, 'error' => 'Catalog ID empty'];
    }

    $result = whatsapp_graph_get(rawurlencode($catalogId) . '?fields=id,name,vertical', $token);
    if ($result['http_code'] >= 400 || empty($result['data']['id'])) {
        $err = (string) ($result['data']['error']['message'] ?? 'Catalog not accessible');

        return ['valid' => false, 'error' => $err];
    }

    // Reject WABA / page / other IDs mistaken for a product catalog.
    $products = whatsapp_graph_get(rawurlencode($catalogId) . '/products?limit=1&fields=id', $token);
    if ($products['http_code'] >= 400 || !isset($products['data']['data'])) {
        $err = (string) ($products['data']['error']['message'] ?? 'ID is not a Meta product catalog');

        return ['valid' => false, 'error' => $err];
    }

    return ['valid' => true, 'id' => (string) $result['data']['id']];
}

/**
 * @param array{reject_catalog_ids?: array<int, string>, force_create?: bool} $opts
 */
function meta_catalog_ensure_options(array $opts): array
{
    $reject = [];
    foreach ($opts['reject_catalog_ids'] ?? [] as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $reject[] = $id;
        }
    }

    return [
        'reject_catalog_ids' => $reject,
        'force_create'       => !empty($opts['force_create']),
    ];
}

function meta_catalog_is_inaccessible_error(string $error): bool
{
    $lower = strtolower($error);

    return str_contains($lower, 'does not exist')
        || str_contains($lower, 'unsupported post')
        || str_contains($lower, 'unsupported get')
        || str_contains($lower, 'missing permissions');
}

function meta_catalog_human_error(string $error): string
{
    $error = trim($error);
    if ($error === '') {
        return 'Meta catalog sync failed.';
    }
    if (meta_catalog_is_rate_limit_error($error)) {
        return meta_catalog_rate_limit_message();
    }
    if (stripos($error, 'Could not resolve Meta Business ID') !== false) {
        return 'No WhatsApp catalog could be discovered for this number. Reconnect WhatsApp and finish Catalogue in Meta, then Sync now.';
    }
    if (meta_catalog_is_write_permission_error($error)) {
        return 'Meta rejected writing products to this catalog (catalog write permission missing). The catalog ID was saved. Messaging still works.';
    }

    return $error;
}

function meta_catalog_permission_message(): string
{
    $configId = function_exists('integration_config') ? trim((string) integration_config('META_CONFIG_ID')) : '';
    $want = '1647730086942089';
    $configNote = $configId === $want
        ? ' Connect is already using the catalog Config ID (' . $want . ').'
        : ' In Admin → Integrations set Config ID to ' . $want . ' (catalog config), then Save. Current: ' . ($configId !== '' ? $configId : 'empty') . '.';

    return 'WhatsApp is connected for messaging, but this token still cannot manage catalogs (no catalog_management).'
        . $configNote
        . ' If the app is Live, that permission also needs App Review → Advanced access.'
        . ' Then WhatsApp → Disconnect → Connect → Get started → approve the catalog → Finish. Do not stop at the green “account shared” bar.'
        . ' After Connected, click Sync now. Your 161 products in IQ Pigeon stay as they are.';
}

/**
 * @return array{connected: bool, has_catalog: ?bool, scopes: array<int, string>}
 */
function meta_catalog_token_scopes(int $botId): array
{
    $access = meta_catalog_bot_access($botId);
    if ($access === null) {
        return ['connected' => false, 'has_catalog' => null, 'scopes' => []];
    }

    $inspect = whatsapp_inspect_token($access['token']);
    if (!empty($inspect['inspect_failed']) || !empty($inspect['inspect_skipped']) || empty($inspect['is_valid'])) {
        return ['connected' => true, 'has_catalog' => null, 'scopes' => []];
    }

    $scopes = $inspect['scopes'] ?? [];
    if (!is_array($scopes)) {
        $scopes = [];
    }

    return [
        'connected'   => true,
        'has_catalog' => !empty($inspect['has_catalog_scope']),
        'scopes'      => $scopes,
    ];
}

function meta_catalog_reset_stale_catalog(int $botId): void
{
    meta_catalog_ensure_schema();
    db_execute(
        'UPDATE bots SET whatsapp_catalog_id = NULL, meta_business_id = NULL, meta_catalog_status = \'pending\', meta_catalog_error = NULL WHERE id = ?',
        'i',
        [$botId]
    );
    db_execute(
        'UPDATE bot_products SET meta_synced_at = NULL, meta_product_id = NULL WHERE bot_id = ?',
        'i',
        [$botId]
    );
}

function meta_catalog_permission_hint(string $error): string
{
    if (meta_catalog_is_inaccessible_error($error) || stripos($error, 'permission') !== false) {
        return meta_catalog_human_error($error);
    }

    return $error;
}

/**
 * @return array{valid: bool, error?: string, id?: string}
 */
function meta_catalog_validate_business(string $businessId, string $token): array
{
    $businessId = trim($businessId);
    if ($businessId === '') {
        return ['valid' => false, 'error' => 'Business ID empty'];
    }

    $owned = whatsapp_graph_get(
        rawurlencode($businessId) . '/owned_product_catalogs?limit=1&fields=id',
        $token
    );
    if ($owned['http_code'] < 400 && isset($owned['data']['data'])) {
        return ['valid' => true, 'id' => $businessId];
    }

    $catalogs = whatsapp_graph_get(
        rawurlencode($businessId) . '/product_catalogs?limit=1&fields=id',
        $token
    );
    if ($catalogs['http_code'] < 400 && isset($catalogs['data']['data'])) {
        return ['valid' => true, 'id' => $businessId];
    }

    $err = (string) ($owned['data']['error']['message'] ?? $catalogs['data']['error']['message'] ?? 'Not a Meta Business with catalog access');

    return ['valid' => false, 'error' => $err];
}

/**
 * @return array{http_code: int, data: array<string, mixed>}
 */
function meta_catalog_create_on_business(string $businessId, string $token, string $catalogName): array
{
    $payload = [
        'name'     => mb_substr($catalogName, 0, 100),
        'vertical' => 'commerce',
    ];

    $create = whatsapp_graph_post(
        rawurlencode($businessId) . '/owned_product_catalogs',
        $token,
        $payload
    );

    if ($create['http_code'] >= 400 || empty($create['data']['id'])) {
        $create = whatsapp_graph_post(
            rawurlencode($businessId) . '/product_catalogs',
            $token,
            $payload
        );
    }

    return $create;
}

/**
 * Discover (or, if explicitly requested, create) a Meta catalog for this bot's WABA.
 *
 * Existing-catalog path never requires a Business Portfolio ID.
 *
 * @param array{reject_catalog_ids?: array<int, string>, force_create?: bool} $opts
 * @return array{success: bool, catalog_id?: string, created?: bool, error?: string, skipped?: bool, status?: string}
 */
function meta_catalog_ensure_for_bot(int $botId, array $opts = []): array
{
    if (!meta_catalog_auto_sync_enabled()) {
        return ['success' => false, 'skipped' => true];
    }

    $opts = meta_catalog_ensure_options($opts);
    $rejectIds = $opts['reject_catalog_ids'];
    $forceCreate = $opts['force_create'];

    meta_catalog_ensure_schema();
    $access = meta_catalog_bot_access($botId);
    if ($access === null) {
        return ['success' => false, 'error' => 'WhatsApp not connected'];
    }

    if ($access['waba_id'] === '') {
        meta_catalog_set_status($botId, 'catalog_discovery_failed', 'Missing WABA ID on account');
        return ['success' => false, 'error' => 'Missing WABA ID', 'status' => 'catalog_discovery_failed'];
    }

    if (meta_catalog_bot_in_rate_limit_cooldown($botId)) {
        return [
            'success' => false,
            'error'   => meta_catalog_rate_limit_message(),
            'status'  => 'catalog_rate_limited',
            'skipped' => true,
        ];
    }

    $catalogId = $forceCreate ? '' : meta_catalog_normalize_id($access['catalog_id']);
    if ($catalogId !== '' && in_array($catalogId, $rejectIds, true)) {
        $catalogId = '';
    }
    $fromStored = $catalogId !== '';

    $trustedSource = $fromStored;
    if (!$forceCreate && $catalogId === '') {
        $discovered = meta_catalog_discover_existing(
            $access['phone_number_id'] ?? '',
            $access['waba_id'],
            $access['token'],
            $rejectIds,
            $access['business_id'] ?? ''
        );
        $catalogId = $discovered['catalog_id'];
        $trustedSource = in_array($discovered['source'], ['commerce_settings', 'embedded_signup', 'customer_business'], true);
        if ($catalogId === '') {
            $status = $discovered['status'] !== '' ? $discovered['status'] : 'no_catalog_linked';
            $err = $discovered['error'] !== '' ? $discovered['error'] : 'No WhatsApp catalog is linked to this number.';
            meta_catalog_set_status($botId, $status, $err);
            return ['success' => false, 'error' => $err, 'status' => $status];
        }
    }

    if (!$forceCreate && $catalogId !== '') {
        $valid = meta_catalog_existing_id_ok($catalogId, $access['token'], $trustedSource);
        if (empty($valid['valid'])) {
            $failErr = (string) ($valid['error'] ?? 'Catalog could not be validated');
            error_log('meta_catalog_ensure_for_bot: catalog ' . $catalogId . ' bot=' . $botId . ' — ' . $failErr);
            $missing = str_contains(strtolower($failErr), 'does not exist');
            if ($trustedSource && !$missing) {
                meta_catalog_save_existing_id($botId, $catalogId);
                return ['success' => true, 'catalog_id' => $catalogId, 'created' => false];
            }
            if ($missing) {
                $rejectIds[] = $catalogId;
            }
            $discovered = meta_catalog_discover_existing(
                $access['phone_number_id'] ?? '',
                $access['waba_id'],
                $access['token'],
                $rejectIds,
                $access['business_id'] ?? ''
            );
            if ($discovered['catalog_id'] !== '') {
                $catalogId = $discovered['catalog_id'];
                $trustedSource = in_array($discovered['source'], ['commerce_settings', 'embedded_signup', 'customer_business'], true);
                $valid = meta_catalog_existing_id_ok($catalogId, $access['token'], $trustedSource);
                if (empty($valid['valid']) && $trustedSource && !str_contains(strtolower((string) ($valid['error'] ?? '')), 'does not exist')) {
                    meta_catalog_save_existing_id($botId, $catalogId);
                    return ['success' => true, 'catalog_id' => $catalogId, 'created' => false];
                }
            } else {
                $status = meta_catalog_is_write_permission_error($failErr) ? 'catalog_access_denied' : 'catalog_validation_failed';
                meta_catalog_set_status($botId, $status, $failErr);
                return ['success' => false, 'error' => $failErr, 'status' => $status];
            }
        }
        if (!empty($valid['valid'])) {
            $catalogId = (string) ($valid['id'] ?? $catalogId);
            meta_catalog_save_existing_id($botId, $catalogId);
            return ['success' => true, 'catalog_id' => $catalogId, 'created' => false];
        }
        $status = 'catalog_validation_failed';
        $err = (string) ($valid['error'] ?? 'Catalog could not be validated');
        meta_catalog_set_status($botId, $status, $err);
        return ['success' => false, 'error' => $err, 'status' => $status];
    }

    if (!$forceCreate) {
        $err = 'No WhatsApp catalog is linked to this number. Complete Catalogue in Meta Embedded Signup, then Sync now.';
        meta_catalog_set_status($botId, 'no_catalog_linked', $err);
        return ['success' => false, 'error' => $err, 'status' => 'no_catalog_linked'];
    }

    $businessId = trim((string) ($access['business_id'] ?? ''));
    if ($businessId !== '') {
        $bizValid = meta_catalog_validate_business($businessId, $access['token']);
        if (empty($bizValid['valid'])) {
            error_log('meta_catalog_ensure_for_bot: stale business ' . $businessId . ' — ' . ($bizValid['error'] ?? ''));
            db_execute('UPDATE bots SET meta_business_id = NULL WHERE id = ?', 'i', [$botId]);
            $businessId = '';
        }
    }
    if ($businessId === '') {
        $businessId = meta_catalog_resolve_business_id($access['token'], $access['waba_id']);
    }

    if ($businessId === '') {
        $err = 'Cannot create a new Meta catalog without the customer Business Portfolio ID. Use the catalog already linked to this WhatsApp number.';
        meta_catalog_set_status($botId, 'business_id_required_for_catalog_creation', $err);
        return ['success' => false, 'error' => $err, 'status' => 'business_id_required_for_catalog_creation'];
    }

    $catalogName = 'IQ Pigeon — ' . ($access['bot_name'] !== '' ? $access['bot_name'] : 'Shop') . ' #' . $botId;
    $create = meta_catalog_create_on_business($businessId, $access['token'], $catalogName);

    if (($create['http_code'] >= 400 || empty($create['data']['id'])) && meta_catalog_is_inaccessible_error((string) ($create['data']['error']['message'] ?? ''))) {
        db_execute('UPDATE bots SET meta_business_id = NULL WHERE id = ?', 'i', [$botId]);
        $resolved = meta_catalog_resolve_business_id($access['token'], $access['waba_id']);
        if ($resolved !== '' && $resolved !== $businessId) {
            $businessId = $resolved;
            $create = meta_catalog_create_on_business($businessId, $access['token'], $catalogName);
        }
    }

    if ($create['http_code'] >= 400 || empty($create['data']['id'])) {
        $err = meta_catalog_human_error((string) ($create['data']['error']['message'] ?? 'Meta catalog creation failed'));
        $status = meta_catalog_is_write_permission_error($err) ? 'catalog_write_permission_missing' : 'error';
        meta_catalog_set_status($botId, $status, $err);
        return ['success' => false, 'error' => $err, 'status' => $status];
    }

    $catalogId = (string) $create['data']['id'];

    $link = whatsapp_graph_post(
        rawurlencode($access['waba_id']) . '/product_catalogs',
        $access['token'],
        ['catalog_id' => $catalogId]
    );

    if ($link['http_code'] >= 400) {
        $linkErr = (string) ($link['data']['error']['message'] ?? 'Could not link catalog to WABA');
        error_log('meta_catalog_ensure_for_bot link: bot=' . $botId . ' ' . $linkErr);
        meta_catalog_set_status($botId, 'error', $linkErr);
    }

    db_execute(
        'UPDATE bots SET whatsapp_catalog_id = ?, meta_business_id = ?, meta_catalog_status = ?, meta_catalog_error = NULL WHERE id = ?',
        'sssi',
        [$catalogId, $businessId, ($link['http_code'] ?? 500) < 400 ? 'active' : 'error', $botId]
    );

    return ['success' => true, 'catalog_id' => $catalogId, 'created' => true];
}

/**
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function meta_catalog_product_batch_row(array $product, string $retailerId, string $method = 'UPDATE'): array
{
    $price = max(0, (float) ($product['price'] ?? 0));
    $currency = strtoupper(trim((string) ($product['currency'] ?? default_currency()))) ?: default_currency();
    $priceStr = number_format($price, 2, '.', '') . ' ' . $currency;

    $active = !empty($product['is_active']);
    $stock = $product['stock'] ?? null;
    if ($stock !== null && (int) $stock <= 0) {
        $active = false;
    }

    $imageUrl = trim((string) ($product['image_url'] ?? ''));
    $data = [
        'id'            => $retailerId,
        'title'         => mb_substr(trim((string) ($product['name'] ?? 'Product')), 0, 150),
        'description'   => mb_substr(trim((string) ($product['description'] ?? '')), 0, 5000),
        'availability'  => $active ? 'in stock' : 'out of stock',
        'condition'     => 'new',
        'price'         => $priceStr,
        'brand'         => 'Store',
    ];

    if ($imageUrl !== '') {
        $data['image_link'] = $imageUrl;
    }

    $category = trim((string) ($product['category'] ?? ''));
    if ($category !== '') {
        $data['google_product_category'] = mb_substr($category, 0, 250);
    }

    return [
        'method' => $method,
        'data'   => $data,
    ];
}

/**
 * Sync one product to Meta catalog.
 *
 * @return array{success: bool, error?: string, retailer_id?: string}
 */
function meta_catalog_sync_product(int $botId, int $productId): array
{
    if (!meta_catalog_auto_sync_enabled()) {
        return ['success' => false, 'error' => 'Auto sync disabled'];
    }

    meta_catalog_ensure_schema();
    require_once __DIR__ . '/catalog.php';

    $ensure = meta_catalog_ensure_for_bot($botId);
    if (empty($ensure['success'])) {
        return ['success' => false, 'error' => $ensure['error'] ?? 'Catalog not ready'];
    }

    $access = meta_catalog_bot_access($botId);
    if ($access === null || $access['catalog_id'] === '') {
        return ['success' => false, 'error' => 'Catalog ID missing'];
    }

    $product = db_fetch(
        'SELECT * FROM bot_products WHERE id = ? AND bot_id = ?',
        'ii',
        [$productId, $botId]
    );
    if (!$product) {
        return ['success' => false, 'error' => 'Product not found'];
    }

    $retailerId = catalog_resolve_meta_retailer_id($product);
    if ($retailerId === '') {
        return ['success' => false, 'error' => 'No retailer ID'];
    }

    $method = !empty($product['meta_synced_at']) ? 'UPDATE' : 'CREATE';
    $batch = meta_catalog_product_batch_row($product, $retailerId, $method);

    $result = whatsapp_graph_post(
        rawurlencode($access['catalog_id']) . '/items_batch',
        $access['token'],
        [
            'item_type' => 'PRODUCT_ITEM',
            'requests'  => [$batch],
        ]
    );

    if ($result['http_code'] >= 400) {
        $err = (string) ($result['data']['error']['message'] ?? 'Meta product sync failed');
        return ['success' => false, 'error' => $err];
    }

    $handles = $result['data']['handles'] ?? [];
    $validation = $result['data']['validation_status'] ?? null;
    if ($validation !== null && is_array($validation)) {
        foreach ($validation as $v) {
            if (!empty($v['errors'])) {
                return ['success' => false, 'error' => json_encode($v['errors'])];
            }
        }
    }

    db_execute(
        'UPDATE bot_products SET meta_retailer_id = ?, meta_synced_at = NOW() WHERE id = ? AND bot_id = ?',
        'sii',
        [$retailerId, $productId, $botId]
    );

    return ['success' => true, 'retailer_id' => $retailerId, 'handles' => $handles];
}

/**
 * Batch-sync active products for a bot (cron + manual).
 *
 * @return array{success: bool, synced: int, failed: int, errors: array<int, string>, catalog_id?: string}
 */
function meta_catalog_sync_bot(int $botId, int $batchSize = 80, array $opts = []): array
{
    $retryAfterReset = ($opts['retry_after_reset'] ?? true) !== false;
    $ensureOpts = meta_catalog_ensure_options($opts);
    $stats = ['success' => true, 'synced' => 0, 'failed' => 0, 'errors' => []];

    if (!meta_catalog_auto_sync_enabled()) {
        $stats['success'] = false;
        $stats['errors'][] = 'Auto sync disabled';
        return $stats;
    }

    $ensure = meta_catalog_ensure_for_bot($botId, $ensureOpts);
    if (empty($ensure['success'])) {
        $stats['success'] = false;
        $err = $ensure['error'] ?? 'Catalog setup failed';
        $stats['errors'][] = $err;
        if (empty($ensure['skipped'])) {
            $status = trim((string) ($ensure['status'] ?? ''));
            if ($status === '') {
                $status = 'error';
            }
            meta_catalog_set_status($botId, $status, $err);
        }
        return $stats;
    }

    $stats['catalog_id'] = $ensure['catalog_id'] ?? '';

    $access = meta_catalog_bot_access($botId);
    if ($access === null || $access['catalog_id'] === '') {
        $stats['success'] = false;
        $stats['errors'][] = 'Catalog not available';
        return $stats;
    }

    require_once __DIR__ . '/catalog.php';

    $products = db_fetch_all(
        'SELECT * FROM bot_products
         WHERE bot_id = ? AND is_active = 1
         ORDER BY (meta_synced_at IS NULL) DESC, sort_order ASC, id ASC
         LIMIT ?',
        'ii',
        [$botId, max(1, min(200, $batchSize))]
    );

    if ($products === []) {
        meta_catalog_ensure_schema();
        db_execute(
            'UPDATE bots SET meta_catalog_sync_pending = 0, meta_catalog_synced_at = NOW() WHERE id = ?',
            'i',
            [$botId]
        );
        return $stats;
    }

    $requests = [];
    $productMap = [];

    foreach ($products as $product) {
        $retailerId = catalog_resolve_meta_retailer_id($product);
        if ($retailerId === '') {
            continue;
        }
        $productId = (int) $product['id'];
        $method = empty($product['meta_synced_at']) ? 'CREATE' : 'UPDATE';
        $requests[] = meta_catalog_product_batch_row($product, $retailerId, $method);
        $productMap[] = ['id' => $productId, 'retailer_id' => $retailerId];
    }

    foreach (array_chunk($requests, 50) as $chunkIndex => $chunk) {
        $mapChunk = array_slice($productMap, $chunkIndex * 50, 50);

        $result = whatsapp_graph_post(
            rawurlencode($access['catalog_id']) . '/items_batch',
            $access['token'],
            [
                'item_type' => 'PRODUCT_ITEM',
                'requests'  => $chunk,
            ]
        );

        if ($result['http_code'] >= 400) {
            $rawErr = (string) ($result['data']['error']['message'] ?? 'Batch sync failed');
            $err = meta_catalog_human_error($rawErr);
            if (meta_catalog_is_rate_limit_error($rawErr) || meta_catalog_graph_error_code($result) === 80008) {
                $stats['success'] = false;
                $stats['errors'][] = meta_catalog_rate_limit_message();
                meta_catalog_set_status($botId, 'catalog_rate_limited', $rawErr);
                break;
            }
            if (meta_catalog_is_write_permission_error($rawErr)) {
                $stats['failed'] += count($chunk);
                $stats['success'] = false;
                $stats['errors'][] = $err;
                meta_catalog_set_status($botId, 'catalog_write_permission_missing', $err);
                break;
            }
            if (stripos($rawErr, 'does not exist') !== false) {
                $failedCatalogId = $access['catalog_id'];
                meta_catalog_reset_stale_catalog($botId);
                if ($retryAfterReset) {
                    $reject = $ensureOpts['reject_catalog_ids'];
                    if ($failedCatalogId !== '') {
                        $reject[] = $failedCatalogId;
                    }

                    return meta_catalog_sync_bot($botId, $batchSize, [
                        'retry_after_reset'  => false,
                        'reject_catalog_ids' => $reject,
                    ]);
                }
                $stats['errors'][] = $err . ' Stale catalog cleared — click Sync now again.';
                $stats['success'] = false;
                break;
            }
            $stats['failed'] += count($chunk);
            if (count($stats['errors']) < 5) {
                $stats['errors'][] = $err;
            }
            continue;
        }

        foreach ($mapChunk as $row) {
            db_execute(
                'UPDATE bot_products SET meta_retailer_id = ?, meta_synced_at = NOW() WHERE id = ? AND bot_id = ?',
                'sii',
                [$row['retailer_id'], $row['id'], $botId]
            );
            $stats['synced']++;
        }
    }

    $remaining = db_fetch(
        'SELECT COUNT(*) AS c FROM bot_products WHERE bot_id = ? AND is_active = 1 AND meta_synced_at IS NULL',
        'i',
        [$botId]
    );
    $pending = (int) ($remaining['c'] ?? 0) > 0;

    meta_catalog_ensure_schema();
    if (!$stats['success'] && $stats['errors'] !== []) {
        $writeErr = implode(' ', $stats['errors']);
        $failStatus = 'error';
        if (meta_catalog_is_rate_limit_error($writeErr)) {
            $failStatus = 'catalog_rate_limited';
        } elseif (meta_catalog_is_write_permission_error($writeErr)) {
            $failStatus = 'catalog_write_permission_missing';
        }
        meta_catalog_set_status($botId, $failStatus, $writeErr);
    } else {
        db_execute(
            'UPDATE bots SET meta_catalog_sync_pending = ?, meta_catalog_synced_at = NOW(), meta_catalog_status = \'active\', meta_catalog_error = NULL WHERE id = ?',
            'ii',
            [$pending ? 1 : 0, $botId]
        );
    }

    return $stats;
}

/**
 * Persist catalog/business IDs from Embedded Signup onto the client's bots.
 *
 * @return array{success: bool, catalog_id: string, business_id: string, error?: string}
 */
function meta_catalog_apply_signup_assets(int $clientId, string $catalogId, string $businessId = ''): array
{
    meta_catalog_ensure_schema();
    $catalogId = meta_catalog_normalize_id($catalogId);
    $businessId = meta_catalog_normalize_id($businessId);
    if ($clientId <= 0 || ($catalogId === '' && $businessId === '')) {
        return ['success' => false, 'catalog_id' => '', 'business_id' => '', 'error' => 'Missing catalog_id'];
    }

    $bots = db_fetch_all('SELECT id FROM bots WHERE user_id = ?', 'i', [$clientId]);
    if ($bots === []) {
        return ['success' => true, 'catalog_id' => $catalogId, 'business_id' => $businessId];
    }

    foreach ($bots as $botRow) {
        $botId = (int) $botRow['id'];
        if ($businessId !== '') {
            db_execute(
                'UPDATE bots SET meta_business_id = ? WHERE id = ?',
                'si',
                [$businessId, $botId]
            );
        }
        if ($catalogId !== '') {
            meta_catalog_save_existing_id($botId, $catalogId);
            if (!meta_catalog_bot_in_rate_limit_cooldown($botId)) {
                meta_catalog_mark_bot_pending($botId);
            }
        }
    }

    return ['success' => true, 'catalog_id' => $catalogId, 'business_id' => $businessId];
}

/**
 * After WhatsApp OAuth — attach the existing WhatsApp catalog and queue product sync.
 * business_id here is the customer's Meta Business Portfolio from Embedded Signup, never IQ Pigeon's.
 */
function meta_catalog_after_whatsapp_connect(int $clientId, string $wabaId, string $token, string $catalogId = '', string $businessId = ''): void
{
    unset($wabaId, $token);

    if (!meta_catalog_auto_sync_enabled() || $clientId <= 0) {
        return;
    }

    meta_catalog_ensure_schema();
    $catalogId = meta_catalog_normalize_id($catalogId);
    $businessId = meta_catalog_normalize_id($businessId);

    if ($catalogId !== '' || $businessId !== '') {
        meta_catalog_apply_signup_assets($clientId, $catalogId, $businessId);
    }

    $bots = db_fetch_all('SELECT id FROM bots WHERE user_id = ?', 'i', [$clientId]);
    foreach ($bots as $botRow) {
        $botId = (int) $botRow['id'];
        $row = db_fetch('SELECT whatsapp_catalog_id FROM bots WHERE id = ?', 'i', [$botId]);
        $storedCatalog = meta_catalog_normalize_id((string) ($row['whatsapp_catalog_id'] ?? ''));
        if ($storedCatalog !== '') {
            meta_catalog_mark_bot_pending($botId);
            continue;
        }
        meta_catalog_ensure_for_bot($botId);
        meta_catalog_mark_bot_pending($botId);
    }
}

/**
 * Cron: process bots flagged for Meta catalog sync.
 *
 * @return array{processed: int, synced: int, failed: int, bots: array<int, array<string, mixed>>}
 */
function meta_catalog_process_pending(int $maxBots = 8, int $productsPerBot = 80): array
{
    if (!meta_catalog_auto_sync_enabled()) {
        return ['processed' => 0, 'synced' => 0, 'failed' => 0, 'bots' => []];
    }

    meta_catalog_ensure_schema();

    $rows = db_fetch_all(
        'SELECT id FROM bots
         WHERE whatsapp_verified = 1
           AND (
                meta_catalog_sync_pending = 1
             OR whatsapp_catalog_id IS NULL OR whatsapp_catalog_id = \'\'
             OR meta_catalog_status IN (\'error\', \'pending\', \'needs_business\', \'no_catalog_linked\', \'catalog_discovery_failed\', \'catalog_access_denied\', \'catalog_validation_failed\', \'catalog_write_permission_missing\')
           )
           AND (
                meta_catalog_sync_pending = 1
             OR meta_catalog_synced_at IS NULL
             OR meta_catalog_synced_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
           )
         ORDER BY meta_catalog_synced_at IS NULL DESC, meta_catalog_synced_at ASC
         LIMIT ?',
        'i',
        [max(1, min(20, $maxBots))]
    );

    $summary = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'bots' => []];

    foreach ($rows as $row) {
        $botId = (int) $row['id'];
        $result = meta_catalog_sync_bot($botId, $productsPerBot);
        $summary['processed']++;
        $summary['synced'] += (int) ($result['synced'] ?? 0);
        $summary['failed'] += (int) ($result['failed'] ?? 0);
        $summary['bots'][] = [
            'bot_id'     => $botId,
            'catalog_id' => $result['catalog_id'] ?? '',
            'synced'     => $result['synced'] ?? 0,
            'failed'     => $result['failed'] ?? 0,
            'errors'     => $result['errors'] ?? [],
        ];
    }

    return $summary;
}

/**
 * Use the catalog already on this bot's connected WhatsApp number (WABA).
 * Clears a stale stored ID, then syncs products.
 *
 * @return array{success: bool, synced: int, failed: int, errors: array<int, string>, catalog_id?: string}
 */
function meta_catalog_auto_link_connected_whatsapp(int $botId): array
{
    $access = meta_catalog_bot_access($botId);
    if ($access === null) {
        return ['success' => false, 'synced' => 0, 'failed' => 0, 'errors' => ['WhatsApp is not connected for this bot.']];
    }

    @set_time_limit(120);

    return meta_catalog_sync_bot($botId, 100, ['retry_after_reset' => true]);
}

/**
 * Shop page: auto-link/sync once when the catalog is missing or in error.
 *
 * @return array{ran: bool, success?: bool, synced?: int, error?: string}
 */
function meta_catalog_maybe_auto_sync_shop(int $botId): array
{
    if ($botId <= 0 || meta_catalog_bot_access($botId) === null) {
        return ['ran' => false];
    }

    if (meta_catalog_bot_in_rate_limit_cooldown($botId)) {
        return ['ran' => false];
    }

    $status = meta_catalog_bot_status($botId);
    if (meta_catalog_status_is_problem($status['status'])) {
        return ['ran' => false];
    }
    if ($status['status'] !== 'pending' || $status['catalog_id'] !== '') {
        return ['ran' => false];
    }

    $row = db_fetch('SELECT meta_catalog_synced_at FROM bots WHERE id = ?', 'i', [$botId]);
    $syncedAt = strtotime((string) ($row['meta_catalog_synced_at'] ?? '')) ?: 0;
    if ($syncedAt > 0) {
        return ['ran' => false];
    }

    $result = meta_catalog_auto_link_connected_whatsapp($botId);
    db_execute('UPDATE bots SET meta_catalog_synced_at = NOW() WHERE id = ?', 'i', [$botId]);

    return [
        'ran'     => true,
        'success' => !empty($result['success']),
        'synced'  => (int) ($result['synced'] ?? 0),
        'error'   => (string) (($result['errors'][0] ?? '') ?: ($result['error'] ?? '')),
    ];
}

/**
 * Human-readable sync status for Shop UI.
 *
 * @return array{status: string, label: string, detail: string, catalog_id: string}
 */
function meta_catalog_bot_status(int $botId): array
{
    meta_catalog_ensure_schema();

    $bot = db_fetch(
        'SELECT whatsapp_catalog_id, meta_catalog_status, meta_catalog_error, meta_catalog_synced_at, meta_catalog_sync_pending, whatsapp_verified
         FROM bots WHERE id = ?',
        'i',
        [$botId]
    );

    if (!$bot) {
        return ['status' => 'unknown', 'label' => 'Unknown', 'detail' => '', 'catalog_id' => ''];
    }

    $catalogId = trim((string) ($bot['whatsapp_catalog_id'] ?? ''));
    $status = (string) ($bot['meta_catalog_status'] ?? 'pending');
    $error = trim((string) ($bot['meta_catalog_error'] ?? ''));

    if (empty($bot['whatsapp_verified'])) {
        return [
            'status'     => 'disconnected',
            'label'      => 'Connect WhatsApp first',
            'detail'     => 'Native product cards auto-enable when WhatsApp is connected.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'catalog_rate_limited' || meta_catalog_is_rate_limit_error($error)) {
        return [
            'status'     => 'catalog_rate_limited',
            'label'      => 'Meta rate limit — wait, then Sync now',
            'detail'     => meta_catalog_rate_limit_message(),
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'needs_business' || $status === 'business_id_required_for_catalog_creation') {
        return [
            'status'     => $status,
            'label'      => 'Catalog not created',
            'detail'     => $error !== '' ? $error : 'Use the catalog already linked to this WhatsApp number. IQ Pigeon does not create catalogs on the platform Business Portfolio.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'no_catalog_linked') {
        return [
            'status'     => $status,
            'label'      => 'No WhatsApp catalog linked',
            'detail'     => $error !== '' ? $error : 'In Meta Embedded Signup, select your Catalogue when asked, then click Finish. The Catalogue button on the profile preview is not enough.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'catalog_discovery_failed') {
        return [
            'status'     => $status,
            'label'      => 'Catalog discovery failed',
            'detail'     => $error !== '' ? $error : 'Could not discover a WhatsApp catalog for this number.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'catalog_access_denied') {
        return [
            'status'     => $status,
            'label'      => 'Catalog access denied',
            'detail'     => $error !== '' ? $error : 'Meta denied catalog access for this WhatsApp token.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'catalog_validation_failed') {
        return [
            'status'     => $status,
            'label'      => 'Catalog validation failed',
            'detail'     => $error !== '' ? $error : 'The catalog ID could not be validated with Meta.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'catalog_write_permission_missing') {
        return [
            'status'     => $status,
            'label'      => 'Catalog found — product write denied',
            'detail'     => $error !== '' ? $error : 'The WhatsApp catalog is linked, but Meta denied writing products to it.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($status === 'error') {
        return [
            'status'     => $status,
            'label'      => 'Sync error',
            'detail'     => $error !== '' ? $error : meta_catalog_permission_message(),
            'catalog_id' => $catalogId,
        ];
    }

    if (!empty($bot['meta_catalog_sync_pending'])) {
        return [
            'status'     => 'syncing',
            'label'      => 'Syncing products to Meta…',
            'detail'     => 'Native WhatsApp product cards will appear once sync completes.',
            'catalog_id' => $catalogId,
        ];
    }

    if ($catalogId !== '') {
        return [
            'status'     => 'active',
            'label'      => 'Native catalog active',
            'detail'     => 'Products send as official WhatsApp catalog cards.',
            'catalog_id' => $catalogId,
        ];
    }

    return [
        'status'     => 'pending',
        'label'      => 'Catalog provisioning pending',
        'detail'     => 'Runs automatically after WhatsApp connect or product import.',
        'catalog_id' => '',
    ];
}
