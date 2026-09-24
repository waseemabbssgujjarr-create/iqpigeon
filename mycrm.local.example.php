<?php
/**
 * Copy to mycrm.local.php on the test server (gitignored). Never commit secrets.
 */

// direct_meta (default) | iqpigeon_api
define('MYCRM_TRANSPORT', 'iqpigeon_api');

// --- IQPigeon WhatsApp API (whatsappapi.iqpigeon.com) ---
define('IQPIGEON_API_BASE_URL', 'https://whatsappapi.iqpigeon.com');
define('IQPIGEON_API_KEY', '');
define('IQPIGEON_CONNECTION_ID', '');
define('IQPIGEON_WEBHOOK_SECRET', '');

// --- Direct Meta (friend CRM / legacy path only) ---
define('MYCRM_META_ACCESS_TOKEN', '');
define('MYCRM_META_PHONE_NUMBER_ID', '');
define('MYCRM_META_GRAPH_VERSION', 'v21.0');
