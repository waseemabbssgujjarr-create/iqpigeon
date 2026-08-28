<?php
/**
 * Facebook JS SDK for WhatsApp Embedded Signup (FB.login on-page, no full redirect).
 */
declare(strict_types=1);

require_once __DIR__ . '/integration-settings.php';
require_once __DIR__ . '/whatsapp.php';

if (!function_exists('integration_meta_configured') || !integration_meta_configured()) {
    return;
}

$metaAppId = whatsapp_meta_app_id();
$configId = trim((string) integration_config('META_CONFIG_ID'));
$apiVersion = integration_meta_graph_api_version();

if ($metaAppId === '' || $configId === '') {
    return;
}

$jsConfig = [
    'appId'      => $metaAppId,
    'configId'   => $configId,
    'apiVersion' => $apiVersion,
];

$emitFbRoot = !isset($meta_fb_sdk_skip_root) || $meta_fb_sdk_skip_root !== true;
if ($emitFbRoot) {
    echo '<div id="fb-root"></div>';
}
?>
<link rel="preconnect" href="https://connect.facebook.net" crossorigin>
<link rel="dns-prefetch" href="https://connect.facebook.net">
<script>
window.metaWaSignup = <?= json_encode($jsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.fbSdkReady = false;
window.fbSdkFailed = false;
window.fbAsyncInit = function () {
    FB.init({
        appId: window.metaWaSignup.appId,
        cookie: true,
        xfbml: false,
        version: window.metaWaSignup.apiVersion,
    });
    window.fbSdkReady = true;
    document.dispatchEvent(new Event('fb-sdk-ready'));
};
</script>
<script
    id="facebook-jssdk"
    async
    crossorigin="anonymous"
    src="https://connect.facebook.net/en_US/sdk.js"
    onerror="window.fbSdkFailed=true;document.dispatchEvent(new Event('fb-sdk-error'));"
></script>
