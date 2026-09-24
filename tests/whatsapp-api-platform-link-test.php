<?php

declare(strict_types=1);

/**
 * Ensures CRM header link to the external WhatsApp API platform is wired correctly.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/iqp-ui.php';

$failures = 0;

function link_assert(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$url = iqp_whatsapp_api_platform_url();
link_assert($url === 'https://whatsappapi.iqpigeon.com', 'platform URL is exact production URL');
link_assert(!str_contains($url, '?'), 'platform URL has no query string');

ob_start();
iqp_whatsapp_api_platform_topbar_link('admin');
$adminHtml = ob_get_clean();
link_assert(str_contains($adminHtml, 'target="_blank"'), 'admin link opens new tab');
link_assert(str_contains($adminHtml, 'rel="noopener noreferrer"'), 'admin link has noopener noreferrer');
link_assert(str_contains($adminHtml, 'https://whatsappapi.iqpigeon.com'), 'admin link href is platform URL');
link_assert(str_contains($adminHtml, 'WhatsApp API'), 'admin link label present');

ob_start();
iqp_whatsapp_api_platform_topbar_link('client');
$clientHtml = ob_get_clean();
link_assert(str_contains($clientHtml, 'target="_blank"'), 'client link opens new tab');
link_assert(str_contains($clientHtml, 'rel="noopener noreferrer"'), 'client link has noopener noreferrer');

$uiSrc = (string) file_get_contents($root . '/includes/iqp-ui.php');
link_assert(str_contains($uiSrc, "iqp_whatsapp_api_platform_topbar_link('client')"), 'client shell includes platform link');
link_assert(str_contains($uiSrc, "iqp_whatsapp_api_platform_topbar_link('admin')"), 'admin shell includes platform link');

exit($failures > 0 ? 1 : 0);
