<?php
/**
 * Copy this file to config.php and fill in your values.
 */

// DATABASE
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'your_db_password');

// OPENAI — text, voice & vision — https://platform.openai.com/api-keys
define('OPENAI_API_KEY', '');  // sk-...
define('OPENAI_MODEL', 'gpt-4o-mini');
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');
define('OPENAI_SSL_VERIFY', false);  // cPanel shared hosting often needs false

// STRIPE
define('STRIPE_SECRET_KEY', 'sk_test_...');
define('STRIPE_WEBHOOK_SECRET', 'whsec_...');
define('STRIPE_PRICE_STARTER', 'price_...');
define('STRIPE_PRICE_PRO', 'price_...');      // $30/mo Pro plan
define('STRIPE_PRICE_GROWTH', 'price_...');   // legacy alias — point to Pro price in Stripe
define('STRIPE_PRICE_AGENCY', 'price_...');

// PAYPAK — Pakistan billing (JazzCash, Easypaisa, PayPak cards, banks via PayFast gateway)
// Sign up at https://gopayfast.com — use sandbox credentials for testing
define('PAYPAK_SANDBOX', true);
define('PAYPAK_MERCHANT_ID', '');
define('PAYPAK_SECURED_KEY', '');
define('PAYPAK_MERCHANT_NAME', 'IQ Pigeon');
define('PAYPAK_DEFAULT_MOBILE', '03000000000');
define('PAYPAK_SANDBOX_API_URL', 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/');
define('PAYPAK_SANDBOX_CHECKOUT_URL', 'https://ipguat.apps.net.pk/Ecommerce/api/Checkout');
define('PAYPAK_LIVE_API_URL', 'https://ipg.apps.net.pk/Ecommerce/api/Transaction/');
define('PAYPAK_LIVE_CHECKOUT_URL', 'https://ipg.apps.net.pk/Ecommerce/api/Checkout');

// Plan display amounts — PKR for Pakistan IP, USD elsewhere
define('PLAN_PRICE_STARTER_PKR', 1440);
define('PLAN_PRICE_PRO_PKR', 9000);

// META / WHATSAPP Embedded Signup
define('META_APP_ID', '2227351001435841');
define('META_APP_SECRET', '');
define('META_CONFIG_ID', '881059205043199');
define('META_GRAPH_API_VERSION', 'v21.0');
define('WEBHOOK_VERIFY_TOKEN', 'your-webhook-verify-token');
define('APP_URL', 'https://iqpigeon.com');
define('ENCRYPTION_KEY', 'change-this-to-random-32-chars!!');
// Extras must include featureType=whatsapp_business_app_onboarding for coexistence.
// whatsapp_embedded_onboard_url() builds this automatically; override only if needed.
define('META_EMBEDDED_SIGNUP_URL', 'https://business.facebook.com/messaging/whatsapp/onboard/?app_id=YOUR_APP_ID&config_id=YOUR_CONFIG_ID&extras=%7B%22version%22%3A%22v4%22%2C%22sessionInfoVersion%22%3A%223%22%2C%22featureType%22%3A%22whatsapp_business_app_onboarding%22%7D');
define('WHATSAPP_VERIFY_TOKEN', WEBHOOK_VERIFY_TOKEN);
define('INSTAGRAM_VERIFY_TOKEN', 'your_random_verify_token_ig');

// APP — iqpigeon.com production (see config.local.php on server)
define('APP_URL', 'https://iqpigeon.com');
define('APP_NAME', 'IQ Pigeon');
define('ADMIN_EMAIL', 'info@iqpigeon.com');
define('TRIAL_DAYS', 14);

// Dev scripts only — never displayed on login pages
define('SHOW_TEST_CREDENTIALS', false);
define('TEST_ADMIN_EMAIL', ADMIN_EMAIL);
define('TEST_ADMIN_PASSWORD', '');
define('TEST_CLIENT_EMAIL', 'demo@iqpigeon.com');
define('TEST_CLIENT_PASSWORD', '');

// Admin portal at /admin/login.php (not linked publicly). Set a long random string:
define('ADMIN_ACCESS_KEY', '');

define('REQUIRE_EMAIL_VERIFICATION', true);
define('EMAIL_VERIFY_EXPIRY_MINUTES', 30);
define('REMEMBER_ME_DAYS', 30);

// SMTP
define('SMTP_HOST', 'mail.yourdomain.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USER', 'info@iqpigeon.com');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_FROM', 'info@iqpigeon.com');
define('SMTP_FROM_NAME', 'IQ Pigeon');

define('DEMO_BOT_ID', 0);
define('WHATSAPP_MANUAL_MODE', false);  // true = manual Phone ID + token only
define('WHATSAPP_PRE_REPLY_TYPING_MS', 4000);
// Human tone only — set false to use simple fallback text (Meta send still works).
define('WHATSAPP_HUMAN_LAYER_ENABLED', true);

// Conversation turn engine (internal — not user-facing switches)
define('TURN_TEXT_DEBOUNCE_MS', 7000);
define('TURN_MEDIA_DEBOUNCE_MS', 7000);
define('TURN_MAX_WINDOW_MS', 30000);
define('HUMAN_AGENT_PURE_MODE', true);

// Agent Core (WhatsApp compose). Default OFF. Empty BOT_IDS = nobody.
// Phase 2 orchestrates conversation_mind_generate; does not replace live webhook_mind while OFF.
// wa_skip_openai (set by send_leads_now) skips the old human_openai layer, not mind generate.
// To opt in a NON-production test bot later: ENABLED true AND comma-separated ids (never 57 until authorized).
define('AGENT_CORE_ENABLED', false);
define('AGENT_CORE_BOT_IDS', '');

// Live WhatsApp demo — wa.me link shown on landing page for instant testing
define('WHATSAPP_DEMO_URL', 'https://wa.me/923114522101');
define('WHATSAPP_DEMO_MESSAGE', 'Hi! I would like to test the AI sales bot.');
define('WHATSAPP_DEMO_LABEL', 'Sareen');

// Login — keep signed in (days)
define('REMEMBER_ME_DAYS', 30);

// Push notifications (Firebase Cloud Messaging) — optional
define('FCM_PROJECT_ID', '');
define('FCM_SERVICE_ACCOUNT_PATH', '');

define('ENCRYPT_KEY', ENCRYPTION_KEY);
define('APP_DEBUG', false);
define('APP_TIMEZONE', 'Asia/Karachi');

define('CRON_SECRET', 'change-this-cron-secret-key');

// Google Sign-In — https://console.cloud.google.com/apis/credentials
// Create OAuth 2.0 Client ID → Web application
// Authorized redirect URI (exact): https://YOUR-DOMAIN.com/api/auth/google-callback.php
define('GOOGLE_CLIENT_ID', '');
define('GOOGLE_CLIENT_SECRET', '');
// Optional if APP_URL differs from Google Console:
// define('GOOGLE_REDIRECT_URI', 'https://yoursite.com/api/auth/google-callback.php');

// Facebook Sign-In — Meta Developer → your app → Facebook Login
// Valid OAuth redirect URI (exact): https://YOUR-DOMAIN.com/api/auth/facebook-callback.php
// Leave empty to use META_APP_ID / META_APP_SECRET from WhatsApp integration
define('FACEBOOK_APP_ID', '');
define('FACEBOOK_APP_SECRET', '');
// Required for Business-type Meta apps (Facebook Login for Business):
define('FACEBOOK_LOGIN_CONFIG_ID', '1716394972899725');
// define('FACEBOOK_REDIRECT_URI', 'https://yoursite.com/api/auth/facebook-callback.php');
// define('FACEBOOK_SIGNIN_ENABLED', true);

// Security — see SECURITY.md
define('SECURITY_ENABLED', true);
define('SECURITY_RATE_LIMIT_ENABLED', true);
define('SECURITY_AUDIT_ENABLED', true);

// false = allow Claude/ChatGPT/etc. to fetch public pages; true = block known AI crawlers
if (!defined('BLOCK_AI_CRAWLERS')) {
    define('BLOCK_AI_CRAWLERS', false);
}

require_once __DIR__ . '/includes/app-bootstrap.php';
require_once __DIR__ . '/includes/security.php';
security_bootstrap();
require_once __DIR__ . '/includes/domain.php';