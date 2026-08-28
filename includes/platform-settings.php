<?php
/**
 * Platform-wide settings: feature flags, editable pricing plans.
 */

declare(strict_types=1);

/**
 * Persist a setting value.
 */
function set_setting(string $key, string $value): void
{
    require_once __DIR__ . '/db.php';
    db_execute(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)',
        'ss',
        [$key, $value]
    );
}

/**
 * Default pricing plans (used when admin has not customized).
 *
 * @return array<string, array<string, mixed>>
 */
function plan_defaults(): array
{
    return [
        'starter' => [
            'name'         => 'Starter',
            'price'        => 8,
            'price_usd'    => 8,
            'price_pkr'    => defined('PLAN_PRICE_STARTER_PKR') ? (int) PLAN_PRICE_STARTER_PKR : 1440,
            'bots'         => 1,
            'chats'        => 100,
            'popular'      => false,
            'contact_only' => false,
            'features'     => [
                '1 AI sales bot',
                '100 client chats/month',
                'WhatsApp + website widget',
                'Document upload & auto-script',
                'Calendly + email alerts',
            ],
        ],
        'pro' => [
            'name'         => 'Pro',
            'price'        => 30,
            'price_usd'    => 30,
            'price_pkr'    => defined('PLAN_PRICE_PRO_PKR') ? (int) PLAN_PRICE_PRO_PKR : 9000,
            'bots'         => 1,
            'chats'        => 500,
            'popular'      => true,
            'contact_only' => false,
            'features'     => [
                '1 AI sales bot',
                '500 client chats/month',
                'Everything in Starter',
                'Higher volume for active sales teams',
                'CSV export + priority support',
            ],
        ],
        'enterprise' => [
            'name'         => 'Enterprise',
            'price'        => null,
            'bots'         => 'Multiple',
            'chats'        => 'Custom',
            'popular'      => false,
            'contact_only' => true,
            'features'     => [
                'Multiple bots for brands or clients',
                'Custom monthly chat volume',
                'Dedicated onboarding & setup',
                'Talk to a sales agent',
                'White-label & API options',
            ],
        ],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function get_admin_plans_raw(): array
{
    $raw = get_setting('plans_json');
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Merge admin-edited plan data with defaults.
 *
 * @return array<string, array<string, mixed>>
 */
function get_plans_merged(): array
{
    $defaults = plan_defaults();
    $custom = get_admin_plans_raw();
    if ($custom === []) {
        return $defaults;
    }

    $plans = [];
    foreach ($custom as $slug => $plan) {
        if (!is_string($slug) || !is_array($plan)) {
            continue;
        }
        if (!empty($plan['deleted'])) {
            continue;
        }
        if (array_key_exists('enabled', $plan) && empty($plan['enabled'])) {
            continue;
        }

        $base = $defaults[$slug] ?? [
            'name'         => ucfirst($slug),
            'price'        => 0,
            'price_usd'    => 0,
            'price_pkr'    => 0,
            'bots'         => 1,
            'chats'        => 100,
            'popular'      => false,
            'contact_only' => false,
            'features'     => [],
        ];

        $merged = array_merge($base, $plan);
        unset($merged['enabled'], $merged['deleted']);

        if (!empty($merged['features_text'])) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $merged['features_text']) ?: [];
            $merged['features'] = array_values(array_filter(array_map('trim', $lines)));
            unset($merged['features_text']);
        }

        $plans[$slug] = $merged;
    }

    return $plans !== [] ? $plans : $defaults;
}

/**
 * Save admin plan configuration.
 *
 * @param array<string, array<string, mixed>> $plans
 */
function save_admin_plans(array $plans): void
{
    set_setting('plans_json', json_encode($plans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** @return array<string, string> */
function client_feature_flag_keys(): array
{
    return [
        'broadcasts'    => 'feature_broadcasts',
        'cart_recovery' => 'feature_cart_recovery',
        'courier'       => 'feature_courier',
        'team'          => 'feature_team',
    ];
}

/**
 * Whether a client-facing feature is visible (admin can toggle).
 */
function client_feature_enabled(string $feature): bool
{
    $map = client_feature_flag_keys();
    $key = $map[$feature] ?? null;
    if ($key === null) {
        return true;
    }
    $default = $feature === 'team' ? '1' : '0';
    return get_setting($key, $default) === '1';
}

/**
 * Redirect if feature is disabled for clients.
 */
function require_client_feature(string $feature): void
{
    if (!client_feature_enabled($feature)) {
        redirect('/client/dashboard');
    }
}

/** @return array<string, bool> */
function get_client_feature_flags(): array
{
    $flags = [];
    foreach (client_feature_flag_keys() as $feature => $settingKey) {
        $flags[$feature] = get_setting($settingKey, '0') === '1';
    }
    return $flags;
}

/**
 * @param array<string, bool> $flags
 */
function save_client_feature_flags(array $flags): void
{
    foreach (client_feature_flag_keys() as $feature => $settingKey) {
        set_setting($settingKey, !empty($flags[$feature]) ? '1' : '0');
    }
}
