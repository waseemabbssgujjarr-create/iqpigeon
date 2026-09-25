<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/whatsapp-oauth.php';

$failures = 0;

function es_v4_assert(bool $ok, string $msg): void
{
    global $failures;
    echo ($ok ? 'OK' : 'FAIL') . ': ' . $msg . PHP_EOL;
    if (! $ok) {
        $failures++;
    }
}

$extras = whatsapp_embedded_signup_extras();
es_v4_assert($extras === ['version' => 'v4'], 'extras are exactly version v4');
es_v4_assert(whatsapp_embedded_signup_extras_json() === '{"version":"v4"}', 'extras JSON is canonical');

$oauthSource = (string) file_get_contents($root . '/includes/whatsapp-oauth.php');
es_v4_assert(str_contains($oauthSource, "'version' => 'v4'"), 'whatsapp-oauth.php defines v4 version');
es_v4_assert(! str_contains($oauthSource, 'whatsapp_business_app_onboarding'), 'whatsapp-oauth.php must not use legacy featureType');
es_v4_assert(! preg_match("/['\"]sessionInfoVersion['\"]/", $oauthSource), 'whatsapp-oauth.php must not set sessionInfoVersion');

$appJs = (string) file_get_contents($root . '/assets/js/app.js');
es_v4_assert(! str_contains($appJs, 'whatsapp_business_app_onboarding'), 'app.js must not use legacy featureType');
es_v4_assert(! str_contains($appJs, 'sessionInfoVersion'), 'app.js must not use sessionInfoVersion');

exit($failures > 0 ? 1 : 0);
