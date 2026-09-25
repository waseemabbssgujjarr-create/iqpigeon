<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/mycrm/iqpigeon-api-client.php';

$failures = 0;

function mc_err_assert(bool $ok, string $msg): void
{
    global $failures;
    echo ($ok ? 'OK' : 'FAIL') . ': ' . $msg . PHP_EOL;
    if (! $ok) {
        $failures++;
    }
}

$parsed = mycrm_iqpigeon_parse_api_error([
    'status' => 502,
    'body' => [
        'success' => false,
        'error' => [
            'code' => 'templates_unavailable',
            'message' => 'Unable to fetch templates from Meta.',
        ],
    ],
    'error' => 'Unable to fetch templates from Meta.',
]);

mc_err_assert($parsed['http_status'] === 502, 'http status parsed');
mc_err_assert($parsed['code'] === 'templates_unavailable', 'api error code parsed');
mc_err_assert(str_contains($parsed['message'], 'Unable to fetch'), 'api error message parsed');

$line = mycrm_iqpigeon_format_api_error_line([
    'http_status' => 422,
    'error_code' => 'connection_not_found',
    'error' => 'Connection not found.',
    'request_id' => 'req_abc',
]);

mc_err_assert(str_contains($line, 'HTTP 422'), 'flash line includes HTTP status');
mc_err_assert(str_contains($line, 'code connection_not_found'), 'flash line includes API code');
mc_err_assert(str_contains($line, 'Connection not found'), 'flash line includes message');
mc_err_assert(str_contains($line, 'request_id req_abc'), 'flash line includes request_id');

$flashFailed = mycrm_iqpigeon_format_send_flash([
    'ok' => false,
    'http_status' => 401,
    'error_code' => 'unauthenticated',
    'error' => 'Invalid or expired API key.',
], 'Template');

mc_err_assert(str_contains($flashFailed, 'Template send failed'), 'send flash prefix for API failure');
mc_err_assert(str_contains($flashFailed, 'HTTP 401'), 'send flash includes HTTP for API failure');
mc_err_assert(! str_contains($flashFailed, '[api_error]'), 'send flash does not use bare api_error bracket');

exit($failures > 0 ? 1 : 0);
