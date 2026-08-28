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
 * @return array{ok: bool, text: string, cached?: bool}|null
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

    return ['ok' => true, 'text' => (string) $data['text'], 'cached' => true];
}

function live_world_cache_put(string $query, string $text, int $ttlSec = 1200): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $file = live_world_cache_dir() . '/' . live_world_cache_key($query) . '.json';
    @file_put_contents($file, json_encode([
        'text'    => mb_substr($text, 0, 4000),
        'expires' => time() + max(60, $ttlSec),
        'at'      => date('c'),
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
 * @return array{ok: bool, text: string, error?: string, cached?: bool, performed: bool}
 */
function live_world_search(string $query): array
{
    $query = trim($query);
    $empty = ['ok' => false, 'text' => '', 'performed' => false, 'error' => 'empty'];
    if ($query === '') {
        return $empty;
    }

    $cached = live_world_cache_get($query);
    if ($cached !== null) {
        error_log('iqp_websearch: cache_hit q=' . mb_substr($query, 0, 80));

        return [
            'ok'        => true,
            'text'      => $cached['text'],
            'cached'    => true,
            'performed' => true,
        ];
    }

    try {
        require_once __DIR__ . '/openai.php';
        require_once __DIR__ . '/integration-settings.php';
        require_once __DIR__ . '/conversation-intelligence.php';
        $apiKey = function_exists('integration_openai_chat_key') ? integration_openai_chat_key() : '';
        if ($apiKey === '' || !function_exists('ai_http_post')) {
            error_log('iqp_websearch: missing_key');

            return ['ok' => false, 'text' => '', 'performed' => false, 'error' => 'no_key'];
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
                error_log('iqp_websearch: api_error ' . $lastErr);
                continue;
            }
            $text = live_world_extract_output_text($data);
            if ($text !== '') {
                error_log('iqp_websearch: tool_ok=' . $toolUsed . ' response_id=' . (string) ($data['id'] ?? ''));
                break;
            }
            $lastErr = 'empty_output';
        }

        if ($text === '') {
            error_log('iqp_websearch: failure ' . $lastErr);

            return ['ok' => false, 'text' => '', 'performed' => true, 'error' => $lastErr];
        }

        if (function_exists('conversation_intelligence_strip_injection')) {
            $text = conversation_intelligence_strip_injection($text);
        }
        live_world_cache_put($query, $text);
        error_log('iqp_websearch: ok chars=' . mb_strlen($text));

        return ['ok' => true, 'text' => $text, 'performed' => true];
    } catch (Throwable $e) {
        error_log('iqp_websearch: exception ' . $e->getMessage());

        return ['ok' => false, 'text' => '', 'performed' => true, 'error' => $e->getMessage()];
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
    $out['performed'] = !empty($found['performed']) || !empty($found['cached']);
    $out['ok'] = !empty($found['ok']);
    $out['error'] = (string) ($found['error'] ?? '');
    $out['evidence'] = trim((string) ($found['text'] ?? ''));
    error_log(
        'iqp_websearch: result needed=1 called=' . ($out['performed'] ? '1' : '0')
        . ' ok=' . ($out['ok'] ? '1' : '0')
        . ' err=' . mb_substr($out['error'], 0, 80)
    );

    return $out;
}
