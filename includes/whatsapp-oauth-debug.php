<?php
/**
 * WhatsApp OAuth flow tracing — file log + stuck-position diagnosis.
 */
declare(strict_types=1);

function whatsapp_oauth_debug_log_path(): string
{
    return dirname(__DIR__) . '/storage/logs/wa-oauth-debug.jsonl';
}

/**
 * @param array<string, mixed> $context
 */
function whatsapp_oauth_debug_log(string $step, array $context = []): void
{
    $path = whatsapp_oauth_debug_log_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $clientId = null;
    if (isset($context['client_id'])) {
        $clientId = (int) $context['client_id'];
    } elseif (!empty($_SESSION['user_id'])) {
        $clientId = (int) $_SESSION['user_id'];
    } elseif (!empty($_SESSION['wa_oauth_client_id'])) {
        $clientId = (int) $_SESSION['wa_oauth_client_id'];
    }

    $entry = [
        'ts'        => date('c'),
        'step'      => $step,
        'client_id' => $clientId,
        'ip'        => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'context'   => $context,
    ];

    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * @return list<array<string, mixed>>
 */
function whatsapp_oauth_debug_read(int $limit = 40, ?int $clientId = null): array
{
    $path = whatsapp_oauth_debug_log_path();
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || $lines === []) {
        return [];
    }

    $rows = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        if ($clientId !== null && (int) ($row['client_id'] ?? 0) !== $clientId) {
            continue;
        }
        $rows[] = $row;
        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

/**
 * @return array{position: string, detail: string, fix: string, severity: string}
 */
function whatsapp_oauth_diagnose_stuck(int $clientId): array
{
    require_once __DIR__ . '/whatsapp-oauth.php';

    $logs = whatsapp_oauth_debug_read(50, $clientId);
    $connected = whatsapp_client_embedded_connected($clientId);

    if ($connected) {
        return [
            'position' => 'complete',
            'detail'   => 'WhatsApp is saved in the database (client_whatsapp_accounts.active).',
            'fix'      => 'Refresh WhatsApp Settings — you should see Connected.',
            'severity' => 'ok',
        ];
    }

    $steps = array_column($logs, 'step');
    $last = $logs[0] ?? null;
    $lastStep = (string) ($last['step'] ?? 'none');
    $lastCtx = is_array($last['context'] ?? null) ? $last['context'] : [];

    if ($steps === []) {
        return [
            'position' => 'never_started',
            'detail'   => 'No OAuth attempt logged for your account yet.',
            'fix'      => 'Click Connect with WhatsApp and watch this page — you should see oauth_start within seconds.',
            'severity' => 'warn',
        ];
    }

    if (in_array('callback_saved', $steps, true)) {
        return [
            'position' => 'saved_but_ui_stale',
            'detail'   => 'Callback reported success but DB row missing — possible DB/encryption error.',
            'fix'      => 'Check server error log and ENCRYPTION_KEY; try Connect again.',
            'severity' => 'error',
        ];
    }

    if (in_array('callback_exchange_failed', $steps, true)) {
        $err = (string) ($lastCtx['error'] ?? 'Token exchange failed');
        return [
            'position' => 'token_exchange_failed',
            'detail'   => $err,
            'fix'      => 'Admin → Integrations: verify App ID + App Secret. Meta → Valid OAuth Redirect URIs must include: '
                . whatsapp_oauth_redirect_uri(),
            'severity' => 'error',
        ];
    }

    if (in_array('callback_no_code', $steps, true) || in_array('callback_meta_error', $steps, true)) {
        $err = (string) ($lastCtx['error'] ?? $lastCtx['meta_error'] ?? 'Meta returned no authorization code');
        return [
            'position' => 'meta_no_code',
            'detail'   => $err,
            'fix'      => 'In Meta signup click through until Finish (not just the green “shared” toast). '
                . 'Add redirect URI in Meta app: ' . whatsapp_oauth_redirect_uri(),
            'severity' => 'error',
        ];
    }

    if (in_array('callback_hit', $steps, true) || in_array('callback_auth_failed', $steps, true)) {
        return [
            'position' => 'callback_rejected',
            'detail'   => (string) ($lastCtx['error'] ?? 'OAuth callback rejected the request.'),
            'fix'      => 'Click Connect again in one tab — do not use browser Back from Meta.',
            'severity' => 'error',
        ];
    }

    if (in_array('exchange_token_failed', $steps, true)) {
        $err = (string) ($lastCtx['error'] ?? 'FB.login code exchange failed.');
        $fix = 'Allow connect.facebook.net (ad blocker) or use “Complete with Meta redirect” on this page.';
        if (str_contains($err, 'Graph API version') || str_contains($err, 'stored value is invalid')) {
            $fix = 'Admin → Integrations → Graph API version: set to v25.0 and Save, then Connect again.';
        } elseif (str_contains($err, 'Could not read WhatsApp account')) {
            $stored = trim(integration_config('META_GRAPH_API_VERSION'));
            if ($stored !== '' && $stored !== integration_meta_graph_api_version()) {
                $fix = 'Admin → Integrations: Graph API version field has an invalid value (was webhook token?). Set to v25.0 and Save, then Connect again.';
            } else {
                $fix = 'In Meta signup click Finish (not just the green toast). If it persists, use “Complete via FB SDK” on this page.';
            }
        }
        return [
            'position' => 'sdk_exchange_failed',
            'detail'   => $err,
            'fix'      => $fix,
            'severity' => 'error',
        ];
    }

    if (in_array('oauth_start', $steps, true) && !in_array('callback_hit', $steps, true)) {
        $startLog = null;
        foreach ($logs as $row) {
            if (($row['step'] ?? '') === 'oauth_start') {
                $startLog = $row;
                break;
            }
        }
        $ctx = is_array($startLog['context'] ?? null) ? $startLog['context'] : [];
        $launchHasRedirect = !empty($ctx['has_redirect_uri']);
        require_once __DIR__ . '/domain.php';
        return [
            'position' => 'stuck_on_meta',
            'detail'   => $launchHasRedirect
                ? 'IQ Pigeon sent you to Meta with redirect_uri, but Meta never called back to IQ Pigeon.'
                : 'Launch URL may be missing redirect_uri (wrong Connect path).',
            'fix'      => 'Meta Developer → Facebook Login → Valid OAuth Redirect URIs — add BOTH:'
                . ' ' . whatsapp_oauth_redirect_uri()
                . ' and ' . rtrim(app_canonical_url(), '/') . '/client/whatsapp-oauth-callback.php'
                . '. After “shared” toast, open /client/whatsapp-oauth-debug and click “Complete via FB SDK”.',
            'severity' => 'error',
        ];
    }

    return [
        'position' => 'unknown_' . $lastStep,
        'detail'   => 'Last logged step: ' . $lastStep,
        'fix'      => 'Try Connect again and refresh this debug page.',
        'severity' => 'warn',
    ];
}
