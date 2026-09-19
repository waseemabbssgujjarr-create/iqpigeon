<?php
/**
 * Generic human handoff / action request records — tenant-scoped CRM tasks.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/commerce-schema.php';

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, handoff_id: int, status: string, error: string}
 */
function handoff_tool_create(
    int $botId,
    int $leadId,
    int $userId,
    string $requestType,
    array $payload = []
): array {
    if ($botId <= 0 || $leadId <= 0) {
        return ['ok' => false, 'handoff_id' => 0, 'status' => 'failed', 'error' => 'invalid_context'];
    }
    if (!empty($GLOBALS['_handoff_tool_create_fixture'])) {
        return is_array($GLOBALS['_handoff_tool_create_fixture'])
            ? $GLOBALS['_handoff_tool_create_fixture']
            : ['ok' => false, 'handoff_id' => 0, 'status' => 'failed', 'error' => 'fixture_failed'];
    }
    if (!empty($GLOBALS['agent_core_no_network'])) {
        return ['ok' => true, 'handoff_id' => 1, 'status' => 'queued', 'error' => ''];
    }
    ensure_commerce_schema();
    if ($userId <= 0) {
        require_once dirname(__DIR__) . '/db.php';
        $bot = db_fetch('SELECT user_id FROM bots WHERE id = ?', 'i', [$botId]);
        $userId = (int) ($bot['user_id'] ?? 0);
    }
    $lead = db_fetch('SELECT id FROM leads WHERE id = ? AND bot_id = ?', 'ii', [$leadId, $botId]);
    if (!$lead) {
        return ['ok' => false, 'handoff_id' => 0, 'status' => 'failed', 'error' => 'invalid_lead'];
    }
    $type = preg_replace('/[^a-z0-9_\-]/i', '', strtolower(trim($requestType))) ?: 'human';
    $id = db_insert(
        'INSERT INTO bot_handoff_requests (bot_id, lead_id, user_id, request_type, status, payload)
         VALUES (?, ?, ?, ?, \'queued\', ?)',
        'iiiss',
        [$botId, $leadId, $userId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE)]
    );

    return [
        'ok'         => $id > 0,
        'handoff_id' => $id,
        'status'     => $id > 0 ? 'queued' : 'failed',
        'error'      => $id > 0 ? '' : 'insert_failed',
    ];
}

/**
 * @return array{ok: bool, handoff_id: int, status: string, error: string}
 */
function handoff_tool_event_invitation(int $botId, int $leadId, int $userId, array $details): array
{
    return handoff_tool_create($botId, $leadId, $userId, 'event_invitation', $details);
}
