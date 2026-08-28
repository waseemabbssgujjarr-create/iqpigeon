<?php
/**
 * OpenAI Chat Completions API and system prompt builder.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integration-settings.php';

/**
 * Default system prompt template with placeholders replaced.
 *
 * @param array<string, mixed> $bot
 * @param string $companyName
 * @param string $tone
 * @return string
 */
function build_system_prompt(array $bot, string $companyName, string $tone = 'Professional'): string
{
    $questions = [];
    $trigger = trim((string) ($bot['qualify_trigger'] ?? ''));
    $qualifyMessage = trim((string) ($bot['qualify_message'] ?? ''));
    $disqualifyMessage = trim((string) ($bot['disqualify_message'] ?? ''));

    if (qualification_flow_load()) {
        $flow = qualification_load_for_bot($bot);
        $questions = $flow['questions'];
        $trigger = (string) $flow['qualify_trigger'];
        $qualifyMessage = (string) $flow['qualify_message'];
        $disqualifyMessage = (string) $flow['disqualify_message'];
    } else {
        $raw = json_decode($bot['qualifying_questions'] ?? '[]', true);
        $questions = is_array($raw) ? $raw : [];
    }

    $questionList = '';
    foreach ($questions as $i => $q) {
        $text = is_array($q) ? ($q['text'] ?? '') : (string) $q;
        if ($text !== '') {
            $req = is_array($q) && !empty($q['required']) ? ' (required)' : '';
            $questionList .= ($i + 1) . '. ' . $text . $req . "\n";
        }
    }

    if ($questionList === '') {
        $questionList = "1. What do they need, in their own words?\n";
    }
    if ($trigger === '') {
        $trigger = 'clear fit per owner script';
    }
    if ($qualifyMessage === '') {
        $qualifyMessage = 'Great — you look like a strong fit.';
    }
    if ($disqualifyMessage === '') {
        $disqualifyMessage = 'Thanks for chatting — feel free to reach out if things change.';
    }

    $template = get_default_system_prompt_template();

    $replacements = [
        '{bot_name}'            => get_bot_rep_name($bot),
        '{company_name}'        => $companyName,
        '{business_name}'       => trim($bot['name'] ?? $companyName),
        '{persona_description}' => $bot['persona_description'] ?? 'Friendly and helpful sales professional.',
        '{tone}'                => $tone,
        '{qualifying_questions}' => trim($questionList),
        '{qualify_trigger}'     => $trigger,
        '{calendly_link}'       => get_bot_calendly_link($bot),
        '{qualify_message}'     => $qualifyMessage,
        '{disqualify_message}'  => $disqualifyMessage,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * Default system prompt template stored in settings or hardcoded fallback.
 *
 * @return string
 */
function get_default_system_prompt_template(): string
{
    $stored = get_setting('default_system_prompt');

    if ($stored) {
        return $stored;
    }

    return <<<'PROMPT'
You are {bot_name}, a real sales representative for {business_name} ({company_name}).
Your identity and this script are the final authority — follow them exactly. Never sound robotic or like an AI.

Your personality: {persona_description}. Tone: {tone}.
Always introduce yourself as {bot_name}, not as the company name. You work for {business_name}.

Before every reply: read the customer's latest message and answer their specific question or point first.

Ask these qualifying questions one at a time — never ask more than one question per message:
{qualifying_questions}

Qualification Rules:
- If the lead meets the qualify trigger ({qualify_trigger}), include [BOOK_CALL] in your response and share the booking link in the same message
- Use [DISQUALIFY] only after several exchanges when they are clearly not a fit — never on first objection, "not interested", "nothing to do", or browsing
- On soft objections: stay curious, uncover pain points, ask one respectful follow-up — keep the conversation alive
- Keep replies short for a single ask (about 2-3 sentences). If they asked several things in one message or voice note, briefly cover each point before one follow-up
- Never repeat a previous reply or re-ask a question they already answered
- Match the customer's language automatically (English, Urdu script, Roman Urdu, German, French, etc.)
- NEVER say you are an AI, bot, or assistant — you are {bot_name}, a team member
- When booking: naturally transition to sending the calendar link in the same message
- Do not end the conversation yourself — always leave a question or invitation to continue

Calendly link to share when qualified: {calendly_link}
Qualify message to use before sending link: {qualify_message}
Disqualify message (only after firm close): {disqualify_message}
PROMPT;
}

/**
 * Call OpenAI Chat Completions API.
 *
 * @param array<int, array{role: string, content: string}> $messages
 * @param array{temperature?: float, max_tokens?: int, frequency_penalty?: float, presence_penalty?: float, top_p?: float, stop?: array<int, string>, model?: string, timeout?: int, max_attempts?: int} $options
 * @return array{success: bool, content?: string, error?: string}
 */
function ai_chat(array $messages, array $options = []): array
{
    if (!function_exists('integration_ai_responses_enabled')) {
        require_once __DIR__ . '/integration-settings.php';
    }
    if (!integration_ai_responses_enabled()) {
        return ['success' => false, 'error' => 'AI auto-replies are disabled in Admin → Integrations.'];
    }

    $maxAttempts = isset($options['max_attempts']) ? max(1, min(3, (int) $options['max_attempts'])) : 3;
    $delayMs = 400;
    $last = ['success' => false, 'error' => 'AI request failed'];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $last = ai_chat_request($messages, $options);
        if ($last['success']) {
            return $last;
        }

        $err = strtolower((string) ($last['error'] ?? ''));
        $retryable = str_contains($err, 'timeout')
            || str_contains($err, 'timed out')
            || str_contains($err, '429')
            || str_contains($err, '502')
            || str_contains($err, '503')
            || str_contains($err, '504')
            || str_contains($err, 'unavailable')
            || str_contains($err, 'connection');

        if (!$retryable || $attempt >= $maxAttempts) {
            return $last;
        }

        usleep($delayMs * 1000);
        $delayMs = min(4000, $delayMs * 2);
    }

    return $last;
}

/**
 * Single OpenAI chat completion attempt.
 *
 * @param array<int, array{role: string, content: string}> $messages
 * @param array{temperature?: float, max_tokens?: int, frequency_penalty?: float, presence_penalty?: float, top_p?: float, stop?: array<int, string>, model?: string, timeout?: int} $options
 * @return array{success: bool, content?: string, error?: string}
 */
function ai_chat_request(array $messages, array $options = []): array
{
    $apiKey = integration_openai_chat_key();

    if ($apiKey === '') {
        return ['success' => false, 'error' => 'OpenAI API key not configured. Set it in Admin → Integrations → OpenAI.'];
    }

    $model = trim((string) ($options['model'] ?? ''));
    if ($model === '') {
        $model = integration_openai_model();
    }

    $payload = [
        'model'             => $model,
        'messages'          => $messages,
        'temperature'       => $options['temperature'] ?? 0.25,
        'max_tokens'        => $options['max_tokens'] ?? 200,
        'top_p'             => $options['top_p'] ?? 0.85,
        'frequency_penalty' => $options['frequency_penalty'] ?? 0.85,
        'presence_penalty'  => $options['presence_penalty'] ?? 0.5,
    ];

    $stop = $options['stop'] ?? ["\n\n\n", 'I hope', 'Certainly!', 'Of course!'];
    if ($stop !== []) {
        $payload['stop'] = array_values($stop);
    }

    $payloadJson = json_encode($payload);

    $verifySsl = defined('OPENAI_SSL_VERIFY') ? (bool) OPENAI_SSL_VERIFY : true;
    $apiUrl = function_exists('integration_openai_api_url')
        ? integration_openai_api_url()
        : (defined('OPENAI_API_URL') ? (string) OPENAI_API_URL : 'https://api.openai.com/v1/chat/completions');
    if (!str_contains($apiUrl, 'chat/completions')) {
        $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
    }
    $minTimeout = !empty($GLOBALS['wa_webhook_budget']) ? 5 : 8;
    $timeoutSec = isset($options['timeout']) ? max($minTimeout, min(45, (int) $options['timeout'])) : 45;
    if (!empty($GLOBALS['wa_webhook_budget'])) {
        $timeoutSec = min($timeoutSec, 6);
    }
    $result = ai_http_post($apiUrl, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ], $payloadJson, $verifySsl, $timeoutSec);

    if (!$result['ok']) {
        error_log('OpenAI curl error: ' . $result['error']);
        return ['success' => false, 'error' => 'AI service unavailable: ' . ($result['error'] ?: 'connection failed')];
    }

    $response = $result['body'];
    $httpCode = $result['http_code'];

    $data = json_decode(is_string($response) ? $response : '', true);

    if ($httpCode !== 200 || empty($data['choices'][0]['message']['content'])) {
        $err = $data['error']['message'] ?? 'Unknown OpenAI API error';
        error_log('OpenAI API error (' . $httpCode . '): ' . $err);
        return ['success' => false, 'error' => $err];
    }

    return ['success' => true, 'content' => trim($data['choices'][0]['message']['content'])];
}

/**
 * Outbound HTTP for OpenAI — DNS resolve + stream fallback on broken curl hosts.
 *
 * @param list<string> $headers
 * @return array{ok: bool, body: string|false, http_code: int, error: string}
 */
function ai_http_post(string $url, array $headers, string $body, bool $verifySsl, int $timeoutSec = 45): array
{
    $timeoutSec = max(5, min(45, $timeoutSec));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSec),
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ];

    if (defined('CURLOPT_LOW_SPEED_TIME')) {
        $opts[CURLOPT_LOW_SPEED_TIME] = min(8, max(3, (int) floor($timeoutSec / 2)));
        $opts[CURLOPT_LOW_SPEED_LIMIT] = 50;
    }

    if (defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && $host !== '' && defined('CURLOPT_RESOLVE')) {
        $ip = @gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ip}"];
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response !== false) {
        return ['ok' => true, 'body' => $response, 'http_code' => $httpCode, 'error' => ''];
    }

    if (str_contains($curlError, 'getaddrinfo') || str_contains($curlError, 'Could not resolve')) {
        $stream = ai_http_post_stream($url, $headers, $body, $verifySsl);
        if ($stream['ok']) {
            return $stream;
        }
    }

    return ['ok' => false, 'body' => false, 'http_code' => $httpCode, 'error' => $curlError ?: 'connection failed'];
}

/**
 * @param list<string> $headers
 * @return array{ok: bool, body: string|false, http_code: int, error: string}
 */
function ai_http_post_stream(string $url, array $headers, string $body, bool $verifySsl): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 45,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => $verifySsl,
            'verify_peer_name' => $verifySsl,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $httpCode = (int) $m[1];
    }

    if ($response !== false) {
        return ['ok' => true, 'body' => $response, 'http_code' => $httpCode, 'error' => ''];
    }

    $err = error_get_last()['message'] ?? 'stream request failed';

    return ['ok' => false, 'body' => false, 'http_code' => $httpCode, 'error' => $err];
}

/**
 * @param array<int, array{role: string, content: string}> $messages
 * @param array{temperature?: float, max_tokens?: int} $options
 * @return array{success: bool, content?: string, error?: string}
 */
function openai_chat(array $messages, array $options = []): array
{
    return ai_chat($messages, $options);
}
