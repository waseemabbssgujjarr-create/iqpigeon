<?php
/**
 * Live world information — temporary web evidence for current/off-topic questions.
 * Never written to business knowledge, training, or customer profile.
 */
declare(strict_types=1);

function live_world_normalize(string $message): string
{
    $msg = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));

    return $msg;
}

function live_world_is_business_question(string $message): bool
{
    $msg = live_world_normalize($message);
    if ($msg === '') {
        return false;
    }

    return (bool) preg_match(
        '/\b('
        . 'what (services|products|packages?) (do you|you) (offer|have|sell)'
        . '|what do you (offer|sell|have|do)'
        . '|your (services?|products?|packages?|prices?|menu|hours?|address|location)'
        . '|do you (deliver|offer|have|sell|ship)'
        . '|opening hours|business hours|how much (is|are|for) (your|the)'
        . '|we offer|this (business|shop|restaurant|company)'
        . ')\b/u',
        $msg
    );
}

function live_world_requires_live(string $message, string $thread = ''): bool
{
    return live_world_should_search($message, $thread);
}

/**
 * Search only for current/off-topic world questions — not company, order, or chat-history questions.
 */
function live_world_should_search(string $message, string $thread = ''): bool
{
    require_once __DIR__ . '/conversation-source-router.php';
    $route = conversation_source_route($message, $thread);

    return !empty($route['needs_web']);
}

function live_world_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/world-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\n");
        }
    }

    return $dir;
}

function live_world_cache_key(string $query): string
{
    return hash('sha256', live_world_normalize($query));
}

/**
 * Old cache entries without web_search_ok are ignored (fail closed).
 *
 * @return array{ok: bool, text: string, cached?: bool, has_web_search_call?: bool, web_search_call_status?: string, evidence_usable?: bool}|null
 */
function live_world_cache_get(string $query): ?array
{
    $file = live_world_cache_dir() . '/' . live_world_cache_key($query) . '.json';
    if (!is_readable($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || trim((string) ($data['text'] ?? '')) === '') {
        return null;
    }
    $exp = (int) ($data['expires'] ?? 0);
    if ($exp > 0 && time() > $exp) {
        @unlink($file);

        return null;
    }
    $text = trim((string) $data['text']);
    if (empty($data['web_search_ok']) || live_world_evidence_looks_like_refusal($text)) {
        @unlink($file);

        return null;
    }

    return [
        'ok'                     => true,
        'text'                   => $text,
        'cached'                 => true,
        'has_web_search_call'    => true,
        'web_search_call_status' => 'cached',
        'evidence_usable'        => true,
        'looks_like_refusal'     => false,
        'evidence_chars'         => mb_strlen($text),
    ];
}

/**
 * @param array<string, mixed> $meta
 */
function live_world_cache_put(string $query, string $text, int $ttlSec = 1200, array $meta = []): void
{
    $text = trim($text);
    if ($text === '' || empty($meta['web_search_ok']) || live_world_evidence_looks_like_refusal($text)) {
        return;
    }
    $file = live_world_cache_dir() . '/' . live_world_cache_key($query) . '.json';
    @file_put_contents($file, json_encode([
        'text'                   => mb_substr($text, 0, 4000),
        'expires'                => time() + max(60, $ttlSec),
        'at'                     => date('c'),
        'web_search_ok'          => true,
        'web_search_call_status' => mb_substr((string) ($meta['web_search_call_status'] ?? 'completed'), 0, 40),
    ], JSON_UNESCAPED_UNICODE));
}

function live_world_extract_output_text(array $data): string
{
    $direct = trim((string) ($data['output_text'] ?? ''));
    if ($direct !== '') {
        return $direct;
    }
    $chunks = [];
    foreach ($data['output'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        if ($type === 'message' || $type === '') {
            foreach ($item['content'] ?? [] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $t = trim((string) ($part['text'] ?? $part['output_text'] ?? ''));
                if ($t !== '') {
                    $chunks[] = $t;
                }
            }
        }
        if ($type === 'web_search_call' || $type === 'web_search_preview_call') {
            $status = (string) ($item['status'] ?? '');
            error_log('iqp_websearch: tool_call type=' . $type . ' status=' . $status . ' id=' . (string) ($item['id'] ?? ''));
        }
    }

    return trim(implode("\n", $chunks));
}

/**
 * Positive identification of a Responses API web search tool item.
 * Only status "completed" counts as executed. Omitted, unknown, failed,
 * incomplete, cancelled, error, in_progress, and searching do not.
 * Absence of a call item is not treated as success.
 *
 * @param array<string, mixed> $data
 * @return array{has_web_search_call: bool, web_search_call_status: string, web_search_executed: bool}
 */
function live_world_inspect_web_search(array $data): array
{
    $has = false;
    $status = '';
    $executed = false;
    foreach ($data['output'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        if ($type !== 'web_search_call' && $type !== 'web_search_preview_call') {
            continue;
        }
        $has = true;
        $st = strtolower(trim((string) ($item['status'] ?? '')));
        if ($st === 'completed') {
            $executed = true;
            $status = 'completed';
            continue;
        }
        if ($status === '') {
            $status = $st !== '' ? $st : 'omitted';
        }
    }

    return [
        'has_web_search_call'    => $has,
        'web_search_call_status' => $status,
        'web_search_executed'    => $executed,
    ];
}

function live_world_evidence_looks_like_refusal(string $text): bool
{
    $t = live_world_normalize($text);
    if ($t === '') {
        return false;
    }

    return (bool) preg_match(
        '/('
        . 'can(\'|’)?t provide (real[- ]?time|live|current) (weather|information|data|updates?)'
        . '|cannot provide (real[- ]?time|live|current) (weather|information|data|updates?)'
        . '|unable to provide (real[- ]?time|live|current) (weather|information|data|updates?)'
        . '|cannot access (real[- ]?time|live|current) (weather|information|data)'
        . '|can(\'|’)?t access (real[- ]?time|live|current) (weather|information|data)'
        . '|don(\'|’)?t have access to (live|current|real[- ]?time) (weather|information|data)'
        . '|do not have access to (live|current|real[- ]?time) (weather|information|data)'
        . '|check a reliable (local )?(weather|source)'
        . '|please check .{0,40}(weather (service|source|app|website|site)|reliable local source)'
        . '|can(\'|’)?t (browse|search) the (web|internet)'
        . '|cannot (browse|search) the (web|internet)'
        . '|don(\'|’)?t have (the ability|access) to (search|browse|look up)'
        . '|i(\'|’)?m (not able|unable) to (provide|access|give) .{0,40}(real[- ]?time|live|current)'
        . ')/u',
        $t
    );
}

/**
 * @param array{has_web_search_call?: bool, web_search_call_status?: string, web_search_executed?: bool} $webMeta
 * @return array{
 *   ok: bool,
 *   evidence_usable: bool,
 *   looks_like_refusal: bool,
 *   has_web_search_call: bool,
 *   web_search_call_status: string,
 *   web_search_executed: bool,
 *   evidence_chars: int,
 *   error: string
 * }
 */
function live_world_assess_evidence(string $text, array $webMeta = []): array
{
    $text = trim($text);
    $chars = mb_strlen($text);
    $hasCall = !empty($webMeta['has_web_search_call']);
    $executed = !empty($webMeta['web_search_executed']);
    $status = (string) ($webMeta['web_search_call_status'] ?? '');
    $refusal = $text !== '' && live_world_evidence_looks_like_refusal($text);
    $usable = $text !== '' && !$refusal && $executed;
    $error = '';
    if ($text === '') {
        $error = 'empty_output';
    } elseif (!$hasCall) {
        $error = 'no_web_search_call';
    } elseif (!$executed) {
        $error = 'web_search_not_completed';
    } elseif ($refusal) {
        $error = 'refusal_evidence';
    }

    return [
        'ok'                     => $usable,
        'evidence_usable'        => $usable,
        'looks_like_refusal'     => $refusal,
        'has_web_search_call'    => $hasCall,
        'web_search_call_status' => $status,
        'web_search_executed'    => $executed,
        'evidence_chars'         => $chars,
        'error'                  => $error,
    ];
}

/**
 * @param array<string, mixed> $search
 */
function live_world_search_is_usable(array $search): bool
{
    $evidence = trim((string) ($search['evidence'] ?? $search['text'] ?? ''));
    if ($evidence === '' || empty($search['ok'])) {
        return false;
    }
    if (isset($search['evidence_usable']) && empty($search['evidence_usable'])) {
        return false;
    }
    if (!empty($search['looks_like_refusal']) || live_world_evidence_looks_like_refusal($evidence)) {
        return false;
    }
    if (array_key_exists('has_web_search_call', $search)
        && empty($search['has_web_search_call'])
        && empty($search['cached'])
    ) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $data
 */
function live_world_tool_data_usable(array $data): bool
{
    return live_world_search_is_usable($data);
}

/**
 * @param array<string, mixed> $found
 * @return array<string, mixed>
 */
function live_world_search_to_tool_data(array $found, bool $needed = true): array
{
    $quality = [
        'has_web_search_call'    => !empty($found['has_web_search_call']),
        'web_search_call_status' => (string) ($found['web_search_call_status'] ?? ''),
        'web_search_executed'    => !empty($found['web_search_executed']) || !empty($found['cached']),
        'looks_like_refusal'     => !empty($found['looks_like_refusal']),
        'evidence_chars'         => (int) ($found['evidence_chars'] ?? 0),
        'error'                  => (string) ($found['error'] ?? ''),
    ];
    $text = trim((string) ($found['text'] ?? $found['evidence'] ?? ''));
    if ($quality['evidence_chars'] <= 0 && $text !== '') {
        $quality['evidence_chars'] = mb_strlen($text);
    }
    $usable = $needed && live_world_search_is_usable(array_merge($found, [
        'evidence' => $text,
        'text'     => $text,
    ]));

    return [
        'needed'                 => $needed,
        'performed'              => !empty($found['performed']) || !empty($found['cached']),
        'ok'                     => $usable,
        'evidence'               => $usable ? $text : '',
        'error'                  => $usable ? '' : ($quality['error'] !== '' ? $quality['error'] : 'unusable_evidence'),
        'source'                 => (string) ($found['source'] ?? ''),
        'query'                  => (string) ($found['query'] ?? ''),
        'cached'                 => !empty($found['cached']),
        'has_web_search_call'    => $quality['has_web_search_call'] || !empty($found['cached']),
        'web_search_call_status' => $quality['web_search_call_status'],
        'web_search_executed'    => $quality['web_search_executed'],
        'looks_like_refusal'     => $quality['looks_like_refusal'] || (!$usable && $text !== '' && live_world_evidence_looks_like_refusal($text)),
        'evidence_usable'        => $usable,
        'evidence_chars'         => $quality['evidence_chars'],
    ];
}

/**
 * @param array<string, mixed> $quality
 * @return array<string, mixed>
 */
function live_world_search_pack_result(bool $ok, string $text, bool $performed, array $quality, bool $cached = false): array
{
    return [
        'ok'                     => $ok,
        'text'                   => $ok ? $text : '',
        'performed'              => $performed,
        'cached'                 => $cached,
        'error'                  => $ok ? '' : (string) ($quality['error'] ?? 'unusable_evidence'),
        'has_web_search_call'    => !empty($quality['has_web_search_call']),
        'web_search_call_status' => (string) ($quality['web_search_call_status'] ?? ''),
        'web_search_executed'    => !empty($quality['web_search_executed']),
        'looks_like_refusal'     => !empty($quality['looks_like_refusal']),
        'evidence_usable'        => $ok,
        'evidence_chars'         => (int) ($quality['evidence_chars'] ?? ($ok ? mb_strlen($text) : 0)),
    ];
}

/**
 * @return array<string, mixed>
 */
function live_world_search(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return live_world_search_pack_result(false, '', false, ['error' => 'empty']);
    }

    $cached = live_world_cache_get($query);
    if ($cached !== null) {
        error_log('iqp_websearch: cache_hit chars=' . (int) ($cached['evidence_chars'] ?? 0));

        return live_world_search_pack_result(
            true,
            (string) $cached['text'],
            true,
            [
                'has_web_search_call'    => true,
                'web_search_call_status' => 'cached',
                'web_search_executed'    => true,
                'looks_like_refusal'     => false,
                'evidence_chars'         => (int) ($cached['evidence_chars'] ?? mb_strlen((string) $cached['text'])),
                'error'                  => '',
            ],
            true
        );
    }

    try {
        require_once __DIR__ . '/openai.php';
        require_once __DIR__ . '/integration-settings.php';
        require_once __DIR__ . '/conversation-intelligence.php';
        $apiKey = function_exists('integration_openai_chat_key') ? integration_openai_chat_key() : '';
        if ($apiKey === '' || !function_exists('ai_http_post')) {
            error_log('iqp_websearch: missing_key');

            return live_world_search_pack_result(false, '', false, ['error' => 'no_key']);
        }

        $chatUrl = function_exists('integration_openai_api_url')
            ? integration_openai_api_url()
            : 'https://api.openai.com/v1/chat/completions';
        $apiUrl = preg_replace('#/chat/completions/?$#', '/responses', rtrim($chatUrl, '/')) ?: '';
        if (!is_string($apiUrl) || !str_contains($apiUrl, '/responses')) {
            $apiUrl = 'https://api.openai.com/v1/responses';
        }

        $model = function_exists('integration_openai_model') ? integration_openai_model() : 'gpt-4o-mini';
        $timeoutSec = !empty($GLOBALS['wa_webhook_budget']) ? 10 : 15;
        $verifySsl = defined('OPENAI_SSL_VERIFY') ? (bool) OPENAI_SSL_VERIFY : true;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $prompt = 'Answer using live web search only. If this is a current office-holder or news question, give the current verified name/fact. Do not use training-cutoff knowledge. Question: '
            . mb_substr($query, 0, 400);
        $attempts = [
            ['tools' => [['type' => 'web_search']], 'tool_choice' => ['type' => 'web_search']],
            ['tools' => [['type' => 'web_search']]],
            ['tools' => [['type' => 'web_search_preview']], 'tool_choice' => ['type' => 'web_search_preview']],
            ['tools' => [['type' => 'web_search_preview']]],
        ];
        $lastErr = 'search_failed';
        $text = '';
        $toolUsed = '';
        $quality = ['error' => 'search_failed', 'evidence_chars' => 0];

        foreach ($attempts as $attempt) {
            $body = [
                'model'             => $model,
                'tools'             => $attempt['tools'],
                'input'             => $prompt,
                'max_output_tokens' => 500,
            ];
            if (isset($attempt['tool_choice'])) {
                $body['tool_choice'] = $attempt['tool_choice'];
            }
            $payload = json_encode($body);
            if ($payload === false) {
                continue;
            }
            $toolUsed = (string) ($attempt['tools'][0]['type'] ?? '');
            error_log('iqp_websearch: execute tool=' . $toolUsed . ' forced=' . (isset($attempt['tool_choice']) ? '1' : '0') . ' q=' . mb_substr($query, 0, 80));
            $result = ai_http_post($apiUrl, $headers, $payload, $verifySsl, $timeoutSec);
            if (empty($result['ok'])) {
                $lastErr = (string) ($result['error'] ?? 'http_failed');
                error_log('iqp_websearch: http_fail ' . $lastErr);
                continue;
            }
            $data = json_decode((string) ($result['body'] ?? ''), true);
            if (!is_array($data)) {
                $lastErr = 'bad_json';
                continue;
            }
            $http = (int) ($result['http_code'] ?? 0);
            if ($http >= 400) {
                $lastErr = (string) ($data['error']['message'] ?? ('http_' . $http));
                error_log('iqp_websearch: api_error http=' . $http);
                continue;
            }
            $extracted = live_world_extract_output_text($data);
            if (function_exists('conversation_intelligence_strip_injection') && $extracted !== '') {
                $extracted = conversation_intelligence_strip_injection($extracted);
            }
            $quality = live_world_assess_evidence($extracted, live_world_inspect_web_search($data));
            error_log(
                'iqp_websearch: quality usable=' . (!empty($quality['evidence_usable']) ? '1' : '0')
                . ' web_call=' . (!empty($quality['has_web_search_call']) ? '1' : '0')
                . ' web_status=' . (string) ($quality['web_search_call_status'] ?? '')
                . ' refusal=' . (!empty($quality['looks_like_refusal']) ? '1' : '0')
                . ' chars=' . (int) ($quality['evidence_chars'] ?? 0)
                . ' err=' . (string) ($quality['error'] ?? '')
                . ' tool=' . $toolUsed
            );
            if (!empty($quality['evidence_usable'])) {
                $text = $extracted;
                error_log('iqp_websearch: tool_ok=' . $toolUsed . ' response_id=' . (string) ($data['id'] ?? ''));
                break;
            }
            $lastErr = (string) ($quality['error'] !== '' ? $quality['error'] : 'unusable_evidence');
            $text = '';
        }

        if ($text === '' || empty($quality['evidence_usable'])) {
            error_log('iqp_websearch: failure ' . $lastErr);

            return live_world_search_pack_result(false, '', true, array_merge($quality, ['error' => $lastErr]));
        }

        live_world_cache_put($query, $text, 1200, [
            'web_search_ok'          => true,
            'web_search_call_status' => (string) ($quality['web_search_call_status'] ?? 'completed'),
        ]);
        error_log('iqp_websearch: ok chars=' . mb_strlen($text));

        return live_world_search_pack_result(true, $text, true, $quality);
    } catch (Throwable $e) {
        error_log('iqp_websearch: exception');

        return live_world_search_pack_result(false, '', true, ['error' => 'exception']);
    }
}

/**
 * @return array{needed: bool, performed: bool, ok: bool, evidence: string, error: string}
 */
function live_world_maybe_search(string $userMessage, string $thread = ''): array
{
    $out = [
        'needed'    => false,
        'performed' => false,
        'ok'        => false,
        'evidence'  => '',
        'error'     => '',
        'source'    => '',
        'query'     => '',
    ];
    require_once __DIR__ . '/conversation-source-router.php';
    $route = conversation_source_route($userMessage, $thread);
    $out['source'] = (string) ($route['primary'] ?? '');
    if (empty($route['needs_web'])) {
        error_log('iqp_websearch: decision skip source=' . $out['source']);

        return $out;
    }
    $query = trim((string) ($route['search_query'] ?? ''));
    if ($query === '') {
        $query = conversation_source_web_query($userMessage, $thread);
    }
    $out['needed'] = true;
    $out['query'] = $query;
    error_log('iqp_websearch: decision search source=' . $out['source'] . ' q=' . mb_substr($query, 0, 80));
    $found = live_world_search($query);
    $found['source'] = $out['source'];
    $found['query'] = $query;
    $packed = live_world_search_to_tool_data($found, true);
    error_log(
        'iqp_websearch: result needed=1 called=' . (!empty($packed['performed']) ? '1' : '0')
        . ' ok=' . (!empty($packed['ok']) ? '1' : '0')
        . ' usable=' . (!empty($packed['evidence_usable']) ? '1' : '0')
        . ' web_call=' . (!empty($packed['has_web_search_call']) ? '1' : '0')
        . ' refusal=' . (!empty($packed['looks_like_refusal']) ? '1' : '0')
        . ' chars=' . (int) ($packed['evidence_chars'] ?? 0)
        . ' err=' . mb_substr((string) ($packed['error'] ?? ''), 0, 80)
    );

    return $packed;
}
