<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/iqpigeon-api-client.php';
require_once __DIR__ . '/direct-meta.php';
require_once __DIR__ . '/storage.php';

/**
 * @return array<string, mixed>
 */
function mycrm_send_text_message(string $to, string $body, ?string $idempotencyKey = null): array
{
    $transport = mycrm_transport();

    if ($transport === 'iqpigeon_api') {
        $key = $idempotencyKey ?: ('mycrm-' . bin2hex(random_bytes(8)));
        $result = mycrm_iqpigeon_send_text($to, $body, $key);
        if ($result['ok']) {
            mycrm_append_message([
                'direction' => 'outbound',
                'transport' => 'iqpigeon_api',
                'to' => $to,
                'body' => $body,
                'message_id' => $result['message']['id'] ?? null,
                'status' => $result['message']['status'] ?? 'queued',
                'request_id' => $result['request_id'] ?? null,
                'sent_at' => gmdate('c'),
            ]);
            mycrm_set_state(['last_api_request_at' => gmdate('c'), 'last_api_request_status' => $result['status'] ?? null]);
        }

        return $result;
    }

    $result = mycrm_direct_meta_send_text($to, $body);
    if ($result['ok']) {
        mycrm_append_message([
            'direction' => 'outbound',
            'transport' => 'direct_meta',
            'to' => $to,
            'body' => $body,
            'sent_at' => gmdate('c'),
        ]);
    }

    return $result;
}
