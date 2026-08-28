<?php
/**
 * Quick syntax check — visit /catalog-load-test.php then delete.
 */
header('Content-Type: text/plain; charset=utf-8');
try {
    require __DIR__ . '/includes/catalog.php';
    echo "OK catalog.php loaded\n";
    echo 'Functions: catalog_message_is_non_product_topic=' . (function_exists('catalog_message_is_non_product_topic') ? 'yes' : 'no') . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'FAIL: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
