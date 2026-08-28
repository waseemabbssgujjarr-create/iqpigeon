<?php

/**

 * Embeds the platform's own chat widget on marketing pages.

 * Requires a bot with widget enabled (Bot Setup → Widget tab).

 */

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/demo-training.php';



$demoBot = get_demo_bot();



if (!$demoBot) {

    return;

}



$widgetConfig = [

    'botId'    => (int) $demoBot['id'],

    'color'    => $demoBot['widget_color'] ?? '#4aad36',

    'botName'  => get_bot_rep_name($demoBot),

    'apiBase'  => rtrim(APP_URL, '/'),

    'autoOpen' => (($activePage ?? '') === 'demo'),

    'isDemo'   => true,

];

?>

<script>window.SalesBotConfig = <?= json_encode($widgetConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;</script>

<script src="/assets/js/chat-widget.js?v=<?= @filemtime(__DIR__ . '/../assets/js/chat-widget.js') ?: time() ?>" async></script>

