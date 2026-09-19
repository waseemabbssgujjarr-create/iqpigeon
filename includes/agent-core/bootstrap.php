<?php
/**
 * Agent Core eligibility — master flag + active bot + channel, not a bot-ID allow-list.
 * AGENT_CORE_ENABLED remains an emergency/global kill switch (default false in config.php).
 * When enabled, every active bot with a valid channel uses Core automatically.
 */
declare(strict_types=1);

if (!defined('AGENT_CORE_ENABLED')) {
    define('AGENT_CORE_ENABLED', false);
}

// Deprecated: ignored by agent_core_enabled(). Kept so old config.local.php defines do not fatally redefine.
if (!defined('AGENT_CORE_BOT_IDS')) {
    define('AGENT_CORE_BOT_IDS', '');
}

require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/observe.php';

/**
 * @deprecated Allow-list removed. Always empty; kept for older callers/tests.
 * @return list<int>
 */
function agent_core_bot_ids(): array
{
    return [];
}

/**
 * Master kill switch — CLI tests may set $GLOBALS['agent_core_enabled_override'] (bool).
 */
function agent_core_master_enabled(): bool
{
    if (PHP_SAPI === 'cli' && array_key_exists('agent_core_enabled_override', $GLOBALS)) {
        return (bool) $GLOBALS['agent_core_enabled_override'];
    }

    return defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED;
}

/**
 * Bot + channel eligibility without the master kill switch.
 * Missing is_active / channel flags are treated as allowed (fixtures / partial rows).
 *
 * @param array<string, mixed> $bot
 */
function agent_core_bot_eligible(array $bot, string $channel = ''): bool
{
    $botId = (int) ($bot['id'] ?? 0);
    if ($botId <= 0) {
        return false;
    }
    if (array_key_exists('is_active', $bot) && !(int) $bot['is_active']) {
        return false;
    }

    $channel = strtolower(trim($channel));
    if ($channel === 'widget'
        && array_key_exists('widget_enabled', $bot)
        && !(int) $bot['widget_enabled']
    ) {
        return false;
    }
    if ($channel === 'whatsapp'
        && array_key_exists('whatsapp_auto_reply', $bot)
        && !(int) $bot['whatsapp_auto_reply']
    ) {
        return false;
    }

    return true;
}

/**
 * Core is on when the master flag is true and the bot is eligible for the channel.
 * No hard-coded bot IDs. Legacy AGENT_CORE_BOT_IDS is ignored.
 *
 * @param array<string, mixed> $bot
 */
function agent_core_enabled(array $bot, string $channel = ''): bool
{
    if (!agent_core_master_enabled()) {
        return false;
    }

    return agent_core_bot_eligible($bot, $channel);
}
