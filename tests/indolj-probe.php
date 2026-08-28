<?php
/**
 * One-off probe — run: php tests/indolj-probe.php
 * Prints product counts only (no tokens).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/website-import.php';

$url = 'https://thesicilian.pk/';
$parsed = website_import_parse_url($url);
$base = $parsed['base'];
$home = website_import_http_get($base . '/');
$homeHtml = $home['body'] ?? '';

$rscHome = website_import_fetch_indolj_rsc($base, '/');
$rscMenu = website_import_fetch_indolj_rsc($base, '/menu');
$combinedHtml = $homeHtml . "\n" . $rscHome . "\n" . $rscMenu;

$ctx = website_import_indolj_merge_context(
    website_import_indolj_context($combinedHtml),
    website_import_indolj_context($rscMenu)
);
$token = website_import_indolj_extract_token($rscMenu !== '' ? $rscMenu : $combinedHtml);
if ($token !== '') {
    $ctx['token'] = $token;
}

echo 'merchant_id: ' . ($ctx['merchant_id'] ?: '(none)') . PHP_EOL;
echo 'token present: ' . ($ctx['token'] !== '' ? 'yes' : 'no') . PHP_EOL;
if ($ctx['token'] !== '') {
    $ttl = website_import_indolj_token_ttl($ctx['token']);
    echo 'token ttl (sec): ' . ($ttl === null ? 'unknown' : (string) $ttl) . PHP_EOL;
}
echo 'browser context: ' . (website_import_indolj_client_context($url) !== null ? 'ok' : 'missing') . PHP_EOL;
echo 'domain: ' . (website_import_indolj_sanitize_domain($ctx['domain'], $base) ?: '(none)') . PHP_EOL;
echo 'api_version: ' . website_import_indolj_sanitize_api_version($ctx['api_version'] ?? '0.0.31') . PHP_EOL;
echo 'branches: ' . implode(', ', array_values($ctx['branches'])) . PHP_EOL;

$htmlCount = count(website_import_extract_indolj_items_from_html($combinedHtml, $ctx['image_base'], $base));
echo 'html embedded items (combined): ' . $htmlCount . PHP_EOL;

$domain = website_import_indolj_sanitize_domain($ctx['domain'], $base);
$apiVersion = website_import_indolj_sanitize_api_version($ctx['api_version'] ?? '0.0.31');
$branches = array_values($ctx['branches']);
if ($branches === [] && $ctx['merchant_id'] !== '') {
    $branches = [$ctx['merchant_id']];
}

if ($ctx['token'] !== '' && $domain !== '' && $branches !== []) {
    echo 'token length: ' . strlen($ctx['token']) . PHP_EOL;
    foreach ($branches as $branchId) {
        $response = website_import_indolj_structured_menu_get(
            $ctx['token'],
            $domain,
            (string) $branchId,
            $apiVersion,
            $base
        );
        $count = 0;
        if ($response['body'] !== '') {
            $payload = website_import_indolj_decode_action_payload($response['body']);
            if (is_array($payload)) {
                $count = count(website_import_indolj_collect_category_data($payload, $ctx['image_base'], $base));
            }
        }
        $urlLen = strlen((string) ($response['url'] ?? ''));
        $auth = (string) ($response['auth'] ?? '');
        echo 'StructuredMenu branch ' . $branchId . ': ' . ($response['method'] ?? 'GET') . ' auth=' . $auth . ' HTTP ' . $response['code'] . ', url_len ' . $urlLen . ', products ' . $count . PHP_EOL;
    }

    foreach ([(string) $branches[0], $ctx['merchant_id']] as $headerId) {
        $response = website_import_indolj_api_get(
            '/v2/chain-restaurants/' . rawurlencode($ctx['merchant_id']) . '/active-menus',
            $ctx['token'],
            $headerId,
            $base
        );
        echo 'active-menus (restaurantid=' . $headerId . '): HTTP ' . $response['code'] . ', body_len ' . strlen($response['body']) . PHP_EOL;
        if ($response['code'] >= 200 && $response['code'] < 300) {
            break;
        }
    }
}

$products = website_import_fetch_indolj($base, $homeHtml);
echo 'fetch_indolj total: ' . count($products) . PHP_EOL;
$zeroPrices = 0;
$withImages = 0;
foreach ($products as $p) {
    if ((float) ($p['price'] ?? 0) <= 0) {
        $zeroPrices++;
    }
    if (trim((string) ($p['image_url'] ?? '')) !== '') {
        $withImages++;
    }
}
echo 'zero price count: ' . $zeroPrices . PHP_EOL;
echo 'with image count: ' . $withImages . PHP_EOL;
if ($products !== []) {
    echo 'sample: ' . ($products[0]['name'] ?? '') . ' — ' . ($products[0]['price'] ?? '') . PHP_EOL;
    $sampleImage = (string) ($products[0]['image_url'] ?? '');
    if ($sampleImage !== '') {
        echo 'sample image: ' . (str_contains($sampleImage, 'assets.indolj.io') ? 'ok' : 'check') . PHP_EOL;
    } else {
        echo 'sample image: missing' . PHP_EOL;
    }
}
