<?php
/**
 * BIMI logo — Gmail / Yahoo round sender avatar (requires DNS + DMARC).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email-templates.php';

$svg = email_bimi_svg_content();
if ($svg === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'BIMI logo not found';
    exit;
}

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
echo $svg;
