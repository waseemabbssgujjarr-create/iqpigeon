<?php
/**
 * Growth Engine → iqpigeon lead ingest (multi-tenant).
 *
 * Auth: Authorization: Bearer <tenant api_key>
 * Sign: X-IQP-Timestamp + X-IQP-Signature = HMAC-SHA256(ts + '.' + rawBody, webhook_secret)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai-ceo.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-IQP-Timestamp, X-IQP-Signature');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$verified = ai_ceo_verify_ingest_request();
if (empty($verified['ok'])) {
    json_response(['success' => false, 'error' => $verified['error'] ?? 'Unauthorized'], 401);
}

/** @var array<string, mixed> $connection */
$connection = $verified['connection'];
/** @var array<string, mixed> $body */
$body = $verified['body'];

// Batch: { "leads": [ {...}, ... ] } or single lead object.
$items = [];
if (isset($body['leads']) && is_array($body['leads'])) {
    $items = $body['leads'];
} else {
    $items = [$body];
}

$results = [];
$errors = [];
foreach ($items as $i => $dto) {
    if (!is_array($dto)) {
        $errors[] = ['index' => $i, 'error' => 'Invalid lead object'];
        continue;
    }
    try {
        $results[] = ai_ceo_ingest_lead($connection, $dto);
    } catch (Throwable $e) {
        $errors[] = ['index' => $i, 'error' => $e->getMessage(), 'businessId' => $dto['businessId'] ?? null];
        db_execute(
            'UPDATE ai_ceo_connections SET last_error = ? WHERE id = ?',
            'si',
            [$e->getMessage(), (int) $connection['id']]
        );
    }
}

$status = $results === [] && $errors !== [] ? 422 : 200;
json_response([
    'success' => $errors === [],
    'ingested' => count($results),
    'results'  => $results,
    'errors'   => $errors,
], $status);
