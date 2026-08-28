<?php
/**
 * Add catalog product via AJAX (supports image upload + compression).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/catalog-image.php';
require_once __DIR__ . '/../includes/bot-context.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = get_user();
if (!$user) {
    json_response(['success' => false, 'error' => 'Sign in required.'], 401);
}

if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $limit = ini_get('post_max_size') ?: '8M';
    json_response([
        'success' => false,
        'error'   => 'Upload too large for the server (limit ' . $limit . '). Use a smaller image or paste an image URL instead.',
    ], 413);
}

$csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
if ($csrfToken === '') {
    $csrfToken = trim((string) (security_api_csrf_token() ?? ''));
}
if (!verify_csrf($csrfToken)) {
    json_response(['success' => false, 'error' => 'Invalid request. Refresh the page and try again.'], 403);
}

$botId = (int) ($_POST['bot_id'] ?? 0);
$userId = (int) $user['id'];
$action = (string) ($_POST['action'] ?? '');
$productId = (int) ($_POST['product_id'] ?? 0);
$isEdit = $action === 'edit_product' || ($action === 'add_product' && $productId > 0);

if (!in_array($action, ['add_product', 'edit_product'], true)) {
    json_response(['success' => false, 'error' => 'Unsupported action.'], 400);
}

if ($isEdit && $productId <= 0) {
    json_response(['success' => false, 'error' => 'Missing product to update.'], 400);
}

if ($botId <= 0) {
    json_response(['success' => false, 'error' => 'No bot selected. Refresh the page and try again.'], 400);
}

$owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
if (!$owned) {
    json_response(['success' => false, 'error' => 'Invalid bot. Refresh the page and pick your bot again.'], 404);
}

try {
    $productData = $_POST;
    $compressed = false;

    if (($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fileError = (int) ($_FILES['product_image']['error'] ?? UPLOAD_ERR_OK);
        if ($fileError !== UPLOAD_ERR_OK) {
            $limit = ini_get('upload_max_filesize') ?: '2M';
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'Image file exceeds server limit (' . $limit . '). Try a smaller file or paste an image URL.',
                UPLOAD_ERR_FORM_SIZE  => 'Image file is too large. Try a smaller file or paste an image URL.',
                UPLOAD_ERR_PARTIAL    => 'Upload interrupted — please try again.',
                UPLOAD_ERR_NO_FILE    => 'No image received.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder missing — contact support.',
                UPLOAD_ERR_CANT_WRITE => 'Server could not save the image — contact support.',
                UPLOAD_ERR_EXTENSION  => 'This image type is blocked by the server.',
            ];
            throw new RuntimeException($messages[$fileError] ?? 'Image upload failed (code ' . $fileError . ').');
        }

        $upload = catalog_process_product_image($_FILES['product_image'], $userId);
        if (!$upload['success']) {
            throw new RuntimeException($upload['error'] ?? 'Image upload failed.');
        }
        $productData['image_url'] = $upload['url'] ?? '';
        $compressed = !empty($upload['compressed']);
    }

    if ($isEdit) {
        $existing = db_fetch(
            'SELECT id FROM bot_products WHERE id = ? AND bot_id = ? AND user_id = ?',
            'iii',
            [$productId, $botId, $userId]
        );
        if (!$existing) {
            json_response(['success' => false, 'error' => 'Product not found.'], 404);
        }
    }

    $savedId = catalog_save_product(
        $botId,
        $userId,
        $productData,
        $isEdit ? $productId : null
    );
    $row = db_fetch(
        'SELECT * FROM bot_products WHERE id = ? AND bot_id = ? AND user_id = ?',
        'iii',
        [$savedId, $botId, $userId]
    );

    if (!$row) {
        throw new RuntimeException('Product saved but could not be loaded.');
    }

    if ($isEdit) {
        $message = $compressed
            ? 'Product updated (image compressed for WhatsApp).'
            : 'Product updated.';
    } else {
        $message = $compressed
            ? 'Product added (image compressed for WhatsApp).'
            : 'Product added.';
    }

    json_response(bot_context_api_envelope($botId, $userId, [
        'success'    => true,
        'updated'    => $isEdit,
        'message'    => $message,
        'compressed' => $compressed,
        'product'    => catalog_product_client_payload($row),
        'count'      => count(catalog_products_for_bot($botId, false)),
    ]));
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 422);
}
