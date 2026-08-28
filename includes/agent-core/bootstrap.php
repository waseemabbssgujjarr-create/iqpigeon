<?php
/**
 * Agent Core Phase 1 — flag and allow-list only.
 * Default OFF. Empty bot-id list means no bot is enabled even if the master flag is true.
 * Do not put production bot 57 here.
 */
declare(strict_types=1);

if (!defined('AGENT_CORE_ENABLED')) {
    define('AGENT_CORE_ENABLED', false);
}

if (!defined('AGENT_CORE_BOT_IDS')) {
    define('AGENT_CORE_BOT_IDS', '');
}

require_once __DIR__ . '/budget.php';

/**
 * Explicit allow-list. Empty = nobody.
 *
 * @return list<int>
 */
function agent_core_bot_ids(): array
{
    $raw = defined('AGENT_CORE_BOT_IDS') ? AGENT_CORE_BOT_IDS : '';
    if (is_array($raw)) {
        $ids = array_map('intval', $raw);
    } else {
        $parts = preg_split('/[\s,]+/', trim((string) $raw)) ?: [];
        $ids = array_map('intval', $parts);
    }
    $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

    return $ids;
}

/**
 * @param array<string, mixed> $bot
 */
function agent_core_enabled(array $bot): bool
{
    if (!defined('AGENT_CORE_ENABLED') || !AGENT_CORE_ENABLED) {
        return false;
    }
    $botId = (int) ($bot['id'] ?? 0);
    if ($botId <= 0) {
        return false;
    }
    $allow = agent_core_bot_ids();
    if ($allow === []) {
        return false;
    }

    return in_array($botId, $allow, true);
}
