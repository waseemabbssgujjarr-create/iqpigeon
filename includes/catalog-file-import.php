<?php
/**
 * Import products from menu PDF or catalog images (PNG/JPG) with cross-source deduplication.
 */

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/catalog-image.php';
require_once __DIR__ . '/document-text.php';
require_once __DIR__ . '/media-understanding.php';
require_once __DIR__ . '/integration-settings.php';

const CATALOG_MENU_FILE_MAX_BYTES = 12 * 1024 * 1024;

function catalog_menu_uploads_dir(int $botId): string
{
    $dir = catalog_product_uploads_dir() . '/menus/' . $botId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function catalog_menu_file_public_url(int $botId, string $filename): string
{
    return catalog_product_image_public_url('menus/' . $botId . '/' . $filename);
}

/**
 * @return array{success: bool, url?: string, error?: string}
 */
function catalog_menu_store_upload(int $botId, int $userId, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > CATALOG_MENU_FILE_MAX_BYTES) {
        return ['success' => false, 'error' => 'File must be under 12 MB.'];
    }

    $name = (string) ($file['name'] ?? 'menu');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'pdf', 'png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg', 'webp' => 'webp'];
    if (!isset($allowed[$ext])) {
        return ['success' => false, 'error' => 'Use PDF, PNG, JPG, or WebP.'];
    }

    $dir = catalog_menu_uploads_dir($botId);
    $filename = 'menu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$ext];
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $dest)) {
        return ['success' => false, 'error' => 'Could not save file.'];
    }

    return [
        'success' => true,
        'url'     => catalog_menu_file_public_url($botId, $filename),
        'path'    => $dest,
        'ext'     => $ext,
    ];
}

/**
 * Parse menu/catalog text lines into product rows.
 *
 * @return array<int, array<string, mixed>>
 */
function catalog_menu_parse_text_products(string $text, string $currency = 'PKR'): array
{
    $currency = strtoupper(trim($currency) ?: 'PKR');
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $products = [];
    $seen = [];

    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || mb_strlen($line) < 4) {
            continue;
        }

        if (preg_match('/^(menu|category|section|page|\d+\s*of\s*\d+|total|subtotal|tax|note)/iu', $line)) {
            continue;
        }

        $name = '';
        $price = 0.0;
        $category = '';

        if (preg_match('/^(.+?)\s+(?:PKR|Rs\.?|₨|USD|\$)\s*([\d,]+(?:\.\d{1,2})?)\s*$/iu', $line, $m)) {
            $name = trim($m[1]);
            $price = (float) str_replace(',', '', $m[2]);
        } elseif (preg_match('/^(.+?)\s+([\d,]+(?:\.\d{1,2})?)\s*(?:PKR|Rs\.?|₨)\s*$/iu', $line, $m)) {
            $name = trim($m[1]);
            $price = (float) str_replace(',', '', $m[2]);
        } elseif (preg_match('/^(.+?)\s+[-–—]\s+([\d,]+(?:\.\d{1,2})?)\s*$/u', $line, $m)) {
            $name = trim($m[1]);
            $price = (float) str_replace(',', '', $m[2]);
        } elseif (preg_match('/^(.+?)\s{2,}([\d,]+(?:\.\d{1,2})?)\s*$/u', $line, $m)) {
            $name = trim($m[1]);
            $price = (float) str_replace(',', '', $m[2]);
        }

        if ($name === '' || mb_strlen($name) < 2) {
            continue;
        }

        if ($price <= 0 || $price > 5000000) {
            continue;
        }

        $key = catalog_normalize_product_name_key($name);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $products[] = [
            'name'        => mb_substr($name, 0, 150),
            'price'       => $price,
            'currency'    => $currency,
            'description' => '',
            'category'    => $category,
            'is_active'   => 1,
        ];

        if (count($products) >= 200) {
            break;
        }
    }

    return $products;
}

/**
 * @return array<int, array<string, mixed>>
 */
function catalog_menu_parse_vision_json(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            $products = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $products[] = [
                    'name'        => mb_substr($name, 0, 150),
                    'price'       => max(0, (float) ($row['price'] ?? 0)),
                    'currency'    => strtoupper(trim((string) ($row['currency'] ?? default_currency()))) ?: default_currency(),
                    'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 500),
                    'category'    => mb_substr(trim((string) ($row['category'] ?? '')), 0, 80),
                    'is_active'   => 1,
                ];
            }

            return $products;
        }
    }

    return catalog_menu_parse_text_products($text);
}

/**
 * @return array{success: bool, products: array<int, array<string, mixed>>, message?: string, error?: string, menu_url?: string}
 */
function catalog_menu_import_file(int $botId, int $userId, array $file): array
{
    $stored = catalog_menu_store_upload($botId, $userId, $file);
    if (empty($stored['success'])) {
        return ['success' => false, 'products' => [], 'error' => $stored['error'] ?? 'Upload failed'];
    }

    $ext = (string) ($stored['ext'] ?? '');
    $path = (string) ($stored['path'] ?? '');
    $menuUrl = (string) ($stored['url'] ?? '');
    $products = [];

    if ($ext === 'pdf') {
        $extract = extract_document_text_from_path($path, 'menu.pdf');
        if (empty($extract['success'])) {
            return ['success' => false, 'products' => [], 'error' => $extract['error'] ?? 'Could not read PDF', 'menu_url' => $menuUrl];
        }
        $products = catalog_menu_parse_text_products((string) ($extract['text'] ?? ''));
    } else {
        $imageUrl = $menuUrl;
        $key = trim(integration_config('OPENAI_IMAGE_API_KEY'));
        if ($key !== '' && is_readable($path)) {
            $bytes = file_get_contents($path);
            if ($bytes !== false && $bytes !== '') {
                $mime = match ($ext) {
                    'png'  => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                $prompt = 'Extract every food/product item from this menu image. Return ONLY a JSON array: '
                    . '[{"name":"Item name","price":999,"currency":"PKR","description":"short","category":"section"}]. '
                    . 'Use numeric price only. Max 80 items.';
                $vision = openai_vision_describe(base64_encode($bytes), $mime, $prompt, $key);
                if (!empty($vision['success'])) {
                    $products = catalog_menu_parse_vision_json((string) ($vision['text'] ?? ''));
                }
            }
        }

        if ($products === []) {
            return [
                'success' => false,
                'products' => [],
                'error'   => 'Could not read products from this image. Try a PDF menu, or set OpenAI image key in Admin → Integrations for photo menus.',
                'menu_url' => $menuUrl,
            ];
        }

        foreach ($products as $i => $product) {
            if (trim((string) ($product['image_url'] ?? '')) === '') {
                $products[$i]['image_url'] = $imageUrl;
            }
        }
    }

    if ($products === []) {
        return [
            'success' => false,
            'products' => [],
            'error'   => 'No products found in this file. Use a text-based PDF or a clear menu photo.',
            'menu_url' => $menuUrl,
        ];
    }

    $imported = 0;
    $merged = 0;
    $updated = 0;
    $fileKey = substr(hash('sha256', $path), 0, 16);

    foreach ($products as $i => $product) {
        $externalId = $fileKey . '_' . ($i + 1) . '_' . substr(catalog_normalize_product_name_key((string) ($product['name'] ?? '')), 0, 40);
        try {
            $result = catalog_upsert_import_product($botId, $userId, $product, 'menu_file', $externalId);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'merged') {
                $merged++;
            } else {
                $updated++;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    require_once __DIR__ . '/meta-catalog-sync.php';
    meta_catalog_mark_bot_pending($botId);

    $message = sprintf(
        'Menu import: %d new, %d merged with existing, %d updated.',
        $imported,
        $merged,
        $updated
    );

    return [
        'success'  => true,
        'products' => $products,
        'message'  => $message,
        'menu_url' => $menuUrl,
        'stats'    => ['imported' => $imported, 'merged' => $merged, 'updated' => $updated],
    ];
}

function catalog_menu_file_bot_status(int $botId): array
{
    $count = db_fetch(
        'SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id = ? AND external_source = ?',
        'is',
        [$botId, 'menu_file']
    );

    return [
        'count' => (int) ($count['cnt'] ?? 0),
    ];
}

function catalog_bot_menu_keywords(int $botId): array
{
    require_once __DIR__ . '/website-import.php';
    website_import_ensure_bot_columns();

    $row = db_fetch('SELECT catalog_menu_keywords FROM bots WHERE id = ?', 'i', [$botId]);
    $raw = trim((string) ($row['catalog_menu_keywords'] ?? ''));
    if ($raw === '') {
        return ['menu', 'catalog', 'rate list', 'price list', 'food menu'];
    }

    $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
    $keywords = [];
    foreach ($parts as $part) {
        $part = trim(mb_strtolower($part));
        if ($part !== '' && mb_strlen($part) >= 2) {
            $keywords[] = $part;
        }
    }

    return $keywords !== [] ? $keywords : ['menu'];
}

function catalog_save_menu_keywords(int $botId, int $userId, string $keywords): void
{
    require_once __DIR__ . '/website-import.php';
    website_import_ensure_bot_columns();

    $keywords = trim($keywords);
    db_execute(
        'UPDATE bots SET catalog_menu_keywords = ? WHERE id = ? AND user_id = ?',
        'sii',
        [$keywords !== '' ? $keywords : null, $botId, $userId]
    );
}

function catalog_menu_keyword_triggered(int $botId, string $message): bool
{
    $message = trim(mb_strtolower($message));
    if ($message === '') {
        return false;
    }

    foreach (catalog_bot_menu_keywords($botId) as $keyword) {
        if ($keyword === '') {
            continue;
        }
        if ($message === $keyword) {
            return true;
        }
        $quoted = preg_quote($keyword, '/');
        if (preg_match('/(^|[\s[:punct:]])' . $quoted . '($|[\s[:punct:]])/u', $message)) {
            return true;
        }
    }

    return false;
}
