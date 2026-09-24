<?php

declare(strict_types=1);

/**
 * Normalize IQPigeon partner webhook payloads (Meta change field in type, value in data).
 *
 * @return list<array<string, mixed>>
 */
function mycrm_iqpigeon_parse_webhook_events(string $type, mixed $data): array
{
    if (!is_array($data)) {
        return [[
            'event_type' => $type,
            'body' => is_string($data) ? $data : json_encode($data),
            'direction' => 'event',
        ]];
    }

    $rows = [];

    foreach ($data['messages'] ?? [] as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $from = (string) ($msg['from'] ?? '');
        $text = (string) ($msg['text']['body'] ?? $msg['body'] ?? '');
        $rows[] = [
            'event_type' => $type !== '' ? $type : 'messages',
            'direction' => 'inbound',
            'from' => $from,
            'to' => (string) ($data['metadata']['display_phone_number'] ?? ''),
            'message_id' => (string) ($msg['id'] ?? ''),
            'message_type' => (string) ($msg['type'] ?? 'text'),
            'body' => $text,
            'status' => 'received',
        ];
    }

    foreach ($data['statuses'] ?? [] as $status) {
        if (!is_array($status)) {
            continue;
        }
        $rows[] = [
            'event_type' => $type !== '' ? $type : 'statuses',
            'direction' => 'status',
            'from' => (string) ($status['recipient_id'] ?? ''),
            'message_id' => (string) ($status['id'] ?? ''),
            'body' => (string) ($status['status'] ?? ''),
            'status' => (string) ($status['status'] ?? ''),
        ];
    }

    if ($rows === []) {
        $rows[] = [
            'event_type' => $type,
            'direction' => 'event',
            'body' => json_encode($data),
        ];
    }

    return $rows;
}
