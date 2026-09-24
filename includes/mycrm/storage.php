<?php

declare(strict_types=1);

function mycrm_storage_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/storage/mycrm';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * @return list<array<string, mixed>>
 */
function mycrm_load_messages(): array
{
    $path = mycrm_storage_dir() . '/messages.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $row
 */
function mycrm_append_message(array $row): void
{
    $rows = mycrm_load_messages();
    $rows[] = $row;
    if (count($rows) > 500) {
        $rows = array_slice($rows, -500);
    }
    file_put_contents(mycrm_storage_dir() . '/messages.json', json_encode($rows, JSON_PRETTY_PRINT));
}

/**
 * @return list<string>
 */
function mycrm_load_processed_event_ids(): array
{
    $path = mycrm_storage_dir() . '/processed_events.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
}

function mycrm_mark_event_processed(string $eventId): bool
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return false;
    }
    $ids = mycrm_load_processed_event_ids();
    if (in_array($eventId, $ids, true)) {
        return false;
    }
    $ids[] = $eventId;
    if (count($ids) > 2000) {
        $ids = array_slice($ids, -2000);
    }
    file_put_contents(mycrm_storage_dir() . '/processed_events.json', json_encode($ids, JSON_PRETTY_PRINT));

    return true;
}

/**
 * @param array<string, mixed> $meta
 */
function mycrm_set_state(array $meta): void
{
    $path = mycrm_storage_dir() . '/state.json';
    $existing = [];
    if (is_file($path)) {
        $existing = json_decode((string) file_get_contents($path), true);
        if (!is_array($existing)) {
            $existing = [];
        }
    }
    file_put_contents($path, json_encode(array_merge($existing, $meta), JSON_PRETTY_PRINT));
}

/**
 * @return array<string, mixed>
 */
function mycrm_get_state(): array
{
    $path = mycrm_storage_dir() . '/state.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $row
 */
function mycrm_update_message_status_from_webhook(array $row): void
{
    $messageId = (string) ($row['message_id'] ?? '');
    $status = (string) ($row['status'] ?? '');
    if ($messageId === '' || $status === '') {
        return;
    }

    $path = mycrm_storage_dir() . '/messages.json';
    $rows = mycrm_load_messages();
    $changed = false;
    foreach ($rows as $i => $existing) {
        if (($existing['message_id'] ?? '') === $messageId || ($existing['wa_message_id'] ?? '') === $messageId) {
            $rows[$i]['status'] = $status;
            $rows[$i]['status_updated_at'] = gmdate('c');
            $changed = true;
        }
    }
    if ($changed) {
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT));
    }
}
