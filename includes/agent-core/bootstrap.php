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

// Optional controlled rollout allow-list. Default empty = no bot-ID restriction (all eligible bots).
if (!defined('AGENT_CORE_ROLLOUT_BOT_IDS')) {
    define('AGENT_CORE_ROLLOUT_BOT_IDS', '');
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
 * Parsed rollout allow-list. Empty = rollout gate off (all eligible bots may use Core).
 * CLI tests may set $GLOBALS['agent_core_rollout_bot_ids_override'] (list<int>).
 *
 * @return list<int>
 */
function agent_core_rollout_bot_ids(): array
{
    if (PHP_SAPI === 'cli' && array_key_exists('agent_core_rollout_bot_ids_override', $GLOBALS)) {
        $ids = [];
        foreach ((array) $GLOBALS['agent_core_rollout_bot_ids_override'] as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    if (!defined('AGENT_CORE_ROLLOUT_BOT_IDS')) {
        return [];
    }

    $raw = trim((string) AGENT_CORE_ROLLOUT_BOT_IDS);
    if ($raw === '') {
        return [];
    }

    $ids = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
        $id = (int) trim((string) $part);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * @param array<string, mixed> $bot
 */
function agent_core_rollout_allows_bot(array $bot): bool
{
    $rollout = agent_core_rollout_bot_ids();
    if ($rollout === []) {
        return true;
    }

    $botId = (int) ($bot['id'] ?? 0);

    return $botId > 0 && in_array($botId, $rollout, true);
}

/**
 * Core is on when the master flag is true and the bot is eligible for the channel.
 * Legacy AGENT_CORE_BOT_IDS is ignored. Optional AGENT_CORE_ROLLOUT_BOT_IDS restricts
 * which eligible bots may use Core when non-empty (default empty = no restriction).
 *
 * @param array<string, mixed> $bot
 */
function agent_core_enabled(array $bot, string $channel = ''): bool
{
    if (!agent_core_master_enabled()) {
        return false;
    }
    if (!agent_core_bot_eligible($bot, $channel)) {
        return false;
    }

    return agent_core_rollout_allows_bot($bot);
}
