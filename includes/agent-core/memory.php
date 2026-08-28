<?php
/**
 * Phase 1 memory — read only. Write stays in deliver_turn after RESPONSE_SENT.
 */
declare(strict_types=1);

/**
 * @return array<string, string>
 */
function agent_core_memory_read(int $botId, int $leadId, string $turnText = ''): array
{
    if ($botId <= 0 || $leadId <= 0) {
        return [];
    }
    try {
        require_once dirname(__DIR__) . '/conversation-runtime-memory.php';
        $facts = conversation_runtime_load_facts($botId, $leadId, $turnText);

        return is_array($facts) ? $facts : [];
    } catch (Throwable $e) {
        error_log('agent_core_memory_read: ' . $e->getMessage());

        return [];
    }
}
