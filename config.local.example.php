<?php
/**
 * IQ Pigeon — copy to config.local.php on iqpigeon.com server only.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_MYSQL_PASSWORD');

define('APP_URL', 'https://iqpigeon.com');

define('WEBHOOK_VERIFY_TOKEN', 'your-webhook-verify-token');
define('ADMIN_ACCESS_KEY', 'your-admin-key');
define('CRON_SECRET', 'your-cron-secret');

// Meta / WhatsApp
define('META_APP_ID', '552479924130015');
define('META_APP_SECRET', 'YOUR_META_APP_SECRET');
define('META_CONFIG_ID', '1647730086942089');
define('FACEBOOK_LOGIN_CONFIG_ID', '1716394972899725');
// Google OAuth redirect: https://iqpigeon.com/api/auth/google-callback.php
// define('GOOGLE_CLIENT_ID', '....apps.googleusercontent.com');
// define('GOOGLE_CLIENT_SECRET', 'GOCSPX-....');
