<?php

/**

 * Admin-configurable billing & payment gateway settings.

 */



declare(strict_types=1);



require_once __DIR__ . '/platform-settings.php';

require_once __DIR__ . '/payment-schema.php';

require_once __DIR__ . '/integration-settings.php';



/**

 * @return array<string, mixed>

 */

function billing_settings_defaults(): array

{

    return [

        'stripe_enabled'              => true,

        'paypak_enabled'              => true,

        'default_currency'            => 'auto',

        'client_billing_note'         => 'Platform subscription is billed here. Meta WhatsApp conversation fees are paid by ' . (defined('APP_NAME') ? APP_NAME : 'your provider') . ' and included in your plan allowance.',

        'whatsapp_billing_note'       => 'WhatsApp messaging requires an active subscription. Connect your real business phone number in Bot Setup → Channels.',

        'block_whatsapp_when_unpaid'  => false,

    ];

}



/**

 * @return array<string, mixed>

 */

function get_billing_settings(): array

{

    $defaults = billing_settings_defaults();

    $raw = get_setting('billing_settings_json');

    if ($raw === null || $raw === '') {

        return $defaults;

    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {

        return $defaults;

    }



    return array_merge($defaults, $decoded);

}



/**

 * @param array<string, mixed> $settings

 */

function save_billing_settings(array $settings): void

{

    $defaults = billing_settings_defaults();

    $clean = [

        'stripe_enabled'             => !empty($settings['stripe_enabled']),

        'paypak_enabled'             => !empty($settings['paypak_enabled']),

        'default_currency'           => in_array($settings['default_currency'] ?? 'auto', ['auto', 'USD', 'PKR'], true)

            ? $settings['default_currency']

            : 'auto',

        'client_billing_note'        => trim((string) ($settings['client_billing_note'] ?? $defaults['client_billing_note'])),

        'whatsapp_billing_note'      => trim((string) ($settings['whatsapp_billing_note'] ?? $defaults['whatsapp_billing_note'])),

        'block_whatsapp_when_unpaid' => !empty($settings['block_whatsapp_when_unpaid']),

    ];

    set_setting('billing_settings_json', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

}



function stripe_configured(): bool

{

    return integration_stripe_configured();

}



function stripe_webhook_configured(): bool

{

    $secret = integration_config('STRIPE_WEBHOOK_SECRET');



    return $secret !== '' && !integration_is_placeholder_secret($secret);

}



/**

 * @return 'paypak'|'stripe'

 */

function billing_resolve_gateway(?string $currency = null): string

{

    require_once __DIR__ . '/currency.php';

    require_once __DIR__ . '/paypak.php';

    $settings = get_billing_settings();



    $mode = $settings['default_currency'] ?? 'auto';

    if ($mode === 'PKR') {

        return !empty($settings['paypak_enabled']) && paypak_configured() ? 'paypak' : 'stripe';

    }

    if ($mode === 'USD') {

        return !empty($settings['stripe_enabled']) && stripe_configured() ? 'stripe' : 'paypak';

    }



    $currency = strtoupper($currency ?? visitor_currency());

    if ($currency === 'PKR' && !empty($settings['paypak_enabled']) && paypak_configured()) {

        return 'paypak';

    }

    if (!empty($settings['stripe_enabled']) && stripe_configured()) {

        return 'stripe';

    }

    if (!empty($settings['paypak_enabled']) && paypak_configured()) {

        return 'paypak';

    }



    return $currency === 'PKR' ? 'paypak' : 'stripe';

}



function billing_whatsapp_blocked_for_user(array $user): bool

{

    $settings = get_billing_settings();

    if (empty($settings['block_whatsapp_when_unpaid'])) {

        return false;

    }

    $status = (string) ($user['subscription_status'] ?? '');

    if ($status === 'active') {

        return false;

    }

    if ($status === 'trialing' && !empty($user['trial_ends_at']) && strtotime((string) $user['trial_ends_at']) > time()) {

        return false;

    }



    return true;

}



function billing_whatsapp_blocked_for_client(int $clientId): bool

{

    require_once __DIR__ . '/db.php';

    $user = db_fetch(

        'SELECT id, subscription_status, trial_ends_at FROM users WHERE id = ? AND role = \'client\' LIMIT 1',

        'i',

        [$clientId]

    );

    if (!$user) {

        return false;

    }



    return billing_whatsapp_blocked_for_user($user);

}



/**

 * @return array<string, mixed>

 */

function billing_admin_stats(): array

{

    ensure_payment_schema();

    require_once __DIR__ . '/helpers.php';



    $planPrices = get_plan_prices();

    $byPlan = db_fetch_all(

        'SELECT subscription_plan, COUNT(*) AS cnt FROM users

         WHERE role = \'client\' AND subscription_status = \'active\'

         GROUP BY subscription_plan'

    );

    $mrrUsd = 0.0;

    foreach ($byPlan as $row) {

        $mrrUsd += ($planPrices[$row['subscription_plan']] ?? 0) * (int) $row['cnt'];

    }



    $paidMonth = db_fetch(

        'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total

         FROM subscription_payments

         WHERE status = \'paid\' AND paid_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')'

    );



    $recent = db_fetch_all(

        'SELECT sp.*, u.name, u.email, u.company_name

         FROM subscription_payments sp

         JOIN users u ON u.id = sp.user_id

         ORDER BY sp.created_at DESC LIMIT 15'

    );



    $waConnected = 0;

    if (function_exists('db_table_exists') || true) {

        try {

            $waConnected = (int) (db_fetch(

                'SELECT COUNT(DISTINCT client_id) AS cnt FROM client_whatsapp_accounts WHERE connection_status = \'active\''

            )['cnt'] ?? 0);

        } catch (Throwable $e) {

            $waConnected = 0;

        }

    }



    return [

        'mrr_usd'           => $mrrUsd,

        'active_paid'       => (int) (db_fetch(

            'SELECT COUNT(*) AS cnt FROM users WHERE role = \'client\' AND subscription_status = \'active\''

        )['cnt'] ?? 0),

        'trialing'          => (int) (db_fetch(

            'SELECT COUNT(*) AS cnt FROM users WHERE role = \'client\' AND subscription_status = \'trialing\''

        )['cnt'] ?? 0),

        'past_due'          => (int) (db_fetch(

            'SELECT COUNT(*) AS cnt FROM users WHERE role = \'client\' AND subscription_status = \'past_due\''

        )['cnt'] ?? 0),

        'payments_month'    => (int) ($paidMonth['cnt'] ?? 0),

        'revenue_month'     => (float) ($paidMonth['total'] ?? 0),

        'whatsapp_connected'=> $waConnected,

        'recent_payments'   => $recent,

        'by_plan'           => $byPlan,

    ];

}


