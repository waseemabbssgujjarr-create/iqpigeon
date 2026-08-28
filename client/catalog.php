<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/catalog-image.php';
require_once __DIR__ . '/../includes/website-import.php';
require_once __DIR__ . '/../includes/meta-catalog-sync.php';
require_once __DIR__ . '/../includes/catalog-file-import.php';

$user = require_login();
$userId = (int) $user['id'];
$message = '';
$error = '';
$catalogMutated = false;

if (isset($_GET['imported'])) {
    $importedN = (int) $_GET['imported'];
    if ($importedN > 0) {
        $message = 'Imported ' . $importedN . ' product' . ($importedN === 1 ? '' : 's') . ' from your website.';
        $catalogMutated = true;
    }
}

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? ($bots[0]['id'] ?? 0));
if ($botId > 0 && $bots !== []) {
    $ownedBotIds = array_map(static fn(array $b): int => (int) $b['id'], $bots);
    if (!in_array($botId, $ownedBotIds, true)) {
        $botId = (int) ($bots[0]['id'] ?? 0);
    }
}

if (isset($_GET['download_template']) && in_array($_GET['download_template'], array_keys(catalog_csv_templates()), true)) {
    $tpl = (string) $_GET['download_template'];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="catalog-' . $tpl . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'price', 'description', 'category', 'sku', 'stock', 'image_url', 'currency']);
    foreach (catalog_template_rows($tpl) as $row) {
        fputcsv($out, [$row['name'], $row['price'], $row['description'], $row['category'], $row['sku'], $row['stock'], $row['image_url'], $row['currency']]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $botId = (int) ($_POST['bot_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        $error = 'Invalid bot.';
    } elseif ($action === 'add_product') {
        try {
            $productData = $_POST;
            $compressed = false;
            if (($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = catalog_process_product_image($_FILES['product_image'], $userId);
                if (!$upload['success']) {
                    throw new RuntimeException($upload['error'] ?? 'Image upload failed.');
                }
                $productData['image_url'] = $upload['url'] ?? '';
                $compressed = !empty($upload['compressed']);
            }
            catalog_save_product($botId, $userId, $productData);
            $message = $compressed
                ? 'Product added (image compressed for WhatsApp).'
                : 'Product added.';
            $catalogMutated = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete_product') {
        catalog_delete_product((int) ($_POST['product_id'] ?? 0), $botId, $userId);
        $message = 'Product removed.';
        $catalogMutated = true;
    } elseif ($action === 'delete_products_bulk') {
        $ids = $_POST['product_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $removed = catalog_delete_products_bulk($ids, $botId, $userId);
        if ($removed === 0) {
            $error = 'Select at least one product to remove.';
        } else {
            $message = $removed . ' product' . ($removed === 1 ? '' : 's') . ' removed.';
            $catalogMutated = true;
        }
    } elseif ($action === 'delete_all_products') {
        $removed = catalog_delete_all_products($botId, $userId);
        $message = $removed === 0
            ? 'No products to remove.'
            : 'All ' . $removed . ' product' . ($removed === 1 ? '' : 's') . ' removed.';
        if ($removed > 0) {
            $catalogMutated = true;
        }
    } elseif ($action === 'import_csv') {
        if (empty($_FILES['csv_file']['tmp_name'])) {
            $error = 'Choose a CSV file to upload.';
        } else {
            try {
                $result = catalog_import_csv($botId, $userId, (string) $_FILES['csv_file']['tmp_name']);
                $message = 'Imported ' . $result['imported'] . ' products.';
                if ($result['skipped'] > 0) {
                    $message .= ' Skipped ' . $result['skipped'] . '.';
                }
                if ($result['imported'] > 0) {
                    $catalogMutated = true;
                }
                if ($result['errors'] !== []) {
                    $error = implode(' ', $result['errors']);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'save_meta_catalog') {
        try {
            catalog_save_bot_meta_settings($botId, $userId, (string) ($_POST['whatsapp_catalog_id'] ?? ''));
            $message = 'Meta catalog settings saved.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'sync_meta_catalog') {
        $result = meta_catalog_sync_bot($botId, 100, ['retry_after_reset' => true]);
        if (!empty($result['success'])) {
            $message = 'Synced ' . (int) ($result['synced'] ?? 0) . ' products to Meta WhatsApp catalog.';
            if (($result['failed'] ?? 0) > 0) {
                $error = (int) $result['failed'] . ' products failed. ' . meta_catalog_human_error(implode(' ', $result['errors'] ?? []));
            }
        } else {
            $error = meta_catalog_human_error(implode(' ', $result['errors'] ?? ['Meta catalog sync failed.']));
        }
    } elseif ($action === 'reset_meta_catalog') {
        meta_catalog_reset_stale_catalog($botId);
        $result = meta_catalog_sync_bot($botId, 100, ['retry_after_reset' => true]);
        if (!empty($result['success'])) {
            $message = 'Rediscovered WhatsApp catalog and synced ' . (int) ($result['synced'] ?? 0) . ' products.';
        } else {
            $message = 'Stored catalog ID cleared.';
            $error = meta_catalog_human_error(implode(' ', $result['errors'] ?? ['Could not rediscover the WhatsApp catalog. Reconnect WhatsApp and finish Catalogue in Meta.']));
        }
    } elseif ($action === 'save_menu_keywords') {
        catalog_save_menu_keywords($botId, $userId, (string) ($_POST['catalog_menu_keywords'] ?? ''));
        $message = 'Menu keyword triggers saved.';
    } elseif ($action === 'import_website') {
        $url = trim((string) ($_POST['website_url'] ?? ''));
        if ($url === '') {
            $error = 'Enter your store website URL.';
        } else {
            $result = website_import_sync($botId, $userId, $url);
            if ($result['success']) {
                $message = $result['message'] ?? 'Website products imported.';
                $catalogMutated = true;
            } else {
                $error = $result['message'] ?? ($result['errors'][0] ?? 'Import failed.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $botId > 0 && !isset($_GET['download_template'])) {
    $waCatalogFlag = (string) ($_GET['wa_catalog'] ?? '');
    if ($waCatalogFlag === '') {
        $auto = meta_catalog_maybe_auto_sync_shop($botId);
        if (!empty($auto['ran'])) {
            $qs = $_GET;
            $qs['bot_id'] = $botId;
            $qs['wa_catalog'] = !empty($auto['success']) ? 'ok' : 'fail';
            if (!empty($auto['success'])) {
                $qs['wa_synced'] = (int) ($auto['synced'] ?? 0);
            }
            header('Location: /client/catalog?' . http_build_query($qs));
            exit;
        }
    }
}

if (($_GET['wa_catalog'] ?? '') === 'ok' && $message === '') {
    $syncedN = (int) ($_GET['wa_synced'] ?? 0);
    $message = $syncedN > 0
        ? 'Linked your connected WhatsApp number and synced ' . $syncedN . ' product' . ($syncedN === 1 ? '' : 's') . ' to the native catalog.'
        : 'Linked the WhatsApp catalog on your connected number.';
}

$metaCatalogId = $botId ? catalog_bot_whatsapp_catalog_id($botId) : '';
$metaCatalogStatus = $botId ? meta_catalog_bot_status($botId) : ['status' => 'unknown', 'label' => '', 'detail' => '', 'catalog_id' => ''];
$websiteImport = $botId ? website_import_bot_status($botId) : ['source_url' => '', 'synced_at' => null, 'count' => 0];
$menuFileImport = $botId ? catalog_menu_file_bot_status($botId) : ['count' => 0];
$menuKeywords = $botId ? (db_fetch('SELECT catalog_menu_keywords FROM bots WHERE id = ?', 'i', [$botId])['catalog_menu_keywords'] ?? '') : '';

$products = $botId ? catalog_products_for_bot($botId, false) : [];

if ($botId && $products !== []) {
    $seenKeys = [];
    $hasDuplicateNames = false;
    foreach ($products as $p) {
        $nameKey = catalog_normalize_product_name_key((string) ($p['name'] ?? ''));
        if ($nameKey === '') {
            continue;
        }
        if (isset($seenKeys[$nameKey])) {
            $hasDuplicateNames = true;
            break;
        }
        $seenKeys[$nameKey] = true;
    }
    if ($hasDuplicateNames) {
        $deduped = catalog_deduplicate_bot_products($botId, $userId);
        if ($deduped > 0) {
            $message = $deduped === 1
                ? 'Removed 1 duplicate product (same name).'
                : 'Removed ' . $deduped . ' duplicate products (same name).';
            $catalogMutated = true;
            $products = catalog_products_for_bot($botId, false);
        }
    }
}

$orders = catalog_orders_for_user($userId, $botId ?: null, 10);
$waDisplayPhone = $userId > 0 ? whatsapp_client_display_phone($userId) : '';
$waCatalogConnected = $botId > 0 && meta_catalog_bot_access($botId) !== null;

$activeTab = 'catalog';
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-shop.php';
return;
