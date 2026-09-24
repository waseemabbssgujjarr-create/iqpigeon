<?php

declare(strict_types=1);

/**
 * MyCRM configuration — server-side only. Secrets live in mycrm.local.php (gitignored).
 */

function mycrm_load_local_config(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $root = dirname(__DIR__, 2);
    if (is_file($root . '/config.php')) {
        require_once $root . '/config.php';
    }
    if (is_file($root . '/mycrm.local.php')) {
        require_once $root . '/mycrm.local.php';
    }
}

function mycrm_env(string $key, string $default = ''): string
{
    mycrm_load_local_config();
    if (defined($key)) {
        return trim((string) constant($key));
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return trim((string) $v);
    }

    return $default;
}

function mycrm_transport(): string
{
    $t = strtolower(mycrm_env('MYCRM_TRANSPORT', 'direct_meta'));
    if ($t === 'iqpigeon_api' || $t === 'direct_meta') {
        return $t;
    }

    return 'direct_meta';
}

function mycrm_is_iqpigeon_api_mode(): bool
{
    return mycrm_transport() === 'iqpigeon_api';
}

function mycrm_iqpigeon_base_url(): string
{
    return rtrim(mycrm_env('IQPIGEON_API_BASE_URL', 'https://whatsappapi.iqpigeon.com'), '/');
}

function mycrm_iqpigeon_api_key(): string
{
    return mycrm_env('IQPIGEON_API_KEY');
}

function mycrm_iqpigeon_connection_id(): string
{
    return mycrm_env('IQPIGEON_CONNECTION_ID');
}

function mycrm_iqpigeon_webhook_secret(): string
{
    return mycrm_env('IQPIGEON_WEBHOOK_SECRET');
}

function mycrm_direct_meta_configured(): bool
{
    return mycrm_env('MYCRM_META_ACCESS_TOKEN') !== '' && mycrm_env('MYCRM_META_PHONE_NUMBER_ID') !== '';
}
