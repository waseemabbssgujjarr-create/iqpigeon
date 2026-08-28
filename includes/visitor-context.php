<?php
/**
 * Visitor locale / country hints for AI language and tone (optional IP geo).
 */

require_once __DIR__ . '/db.php';

/**
 * Client IP (best effort behind proxies).
 */
function visitor_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $raw) {
        if ($raw === '') {
            continue;
        }
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

/**
 * Country code from Cloudflare or optional IP lookup.
 */
function visitor_country_code(?string $ip = null): string
{
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && strlen($_SERVER['HTTP_CF_IPCOUNTRY']) === 2) {
        return strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
    }

    $ip = $ip ?: visitor_client_ip();
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }

    require_once __DIR__ . '/integration-settings.php';

    if (!integration_visitor_geo_enabled()) {
        return '';
    }

    $cacheKey = 'geo_' . md5($ip);
    if (!empty($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=countryCode';
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data['countryCode'])) {
            $GLOBALS[$cacheKey] = strtoupper($data['countryCode']);
            return $GLOBALS[$cacheKey];
        }
    }

    return '';
}

/**
 * Parse Accept-Language or client-sent locale (e.g. ur-PK, en-US).
 */
function visitor_browser_locale(?string $clientLocale = null): string
{
    if ($clientLocale !== null && $clientLocale !== '') {
        return substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', $clientLocale), 0, 16);
    }

    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($header === '') {
        return '';
    }

    $part = trim(explode(',', $header)[0]);
    return substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', $part), 0, 16);
}

/**
 * Strip WhatsApp media wrappers so language detection uses the customer's actual words.
 */
function customer_message_text_for_language(string $text): string
{
    if (preg_match('/Caption they wrote: "(.+)"\s*$/us', $text, $m)) {
        return trim($m[1]);
    }

    if (preg_match('/^\[Voice message from customer\]:\s*/u', trim($text))) {
        return trim(preg_replace('/^\[Voice message from customer\]:\s*/u', '', trim($text)));
    }

    // AI image descriptions are in English — use caption only, not vision analysis text
    if (preg_match('/^\[Customer sent an image\]/u', trim($text))) {
        return '';
    }

    return trim($text);
}

/**
 * Detect language/script from customer text (latest message drives reply language).
 *
 * @return english|roman_urdu|urdu_script|german|mixed|mirror
 */
function detect_customer_language(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return 'mirror';
    }

    if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $text)) {
        return 'urdu_script';
    }

    $lower = mb_strtolower($text);

    if (preg_match('/^(hi|hello|hey|thanks|thank you|thankyou|ok|okay|yes|no|good morning|good afternoon|good evening|please|help|price|info|information|sure|great|nice)[!.?\s]*$/iu', $text)) {
        return 'english';
    }

    $englishScore = 0;
    foreach ([
        '/\b(what|how|when|where|why|who|which|can|could|would|please|thanks|thank you|hello|hi|hey|sorry)\b/iu',
        '/\b(is|are|was|were|the|this|that|your|my|our|need|want|help|price|cost|service|services|business|about|tell|interested|looking|offer|package|plan)\b/iu',
        '/\b(do you|did you|will you|have you|i want|i need|i am|i\'m|we need|we want|what about|just helping)\b/iu',
    ] as $pattern) {
        if (preg_match_all($pattern, $lower, $matches)) {
            $englishScore += count($matches[0]);
        }
    }

    $romanScore = 0;
    foreach ([
        '/\b(aap|ap|apko|apka|tm|tum|mujhe|mjhe|kya|kia|ky|hai|hain|ha|hn|ho|hain\?|nahi|nhi|nh|ni|shukriya|shukria|theek|thek|batao|bata|bataiye|chahiye|kitna|kaise|meri|mera|khair|bohat|samajh|kahan|kahan|kyun|kyu)\b/iu',
        '/\b(ji|salam|assalam|assalamu|khuda|hafiz|aur|or|phir|abhi|bhi|lekin|agar|agr|toh|yeh|ye|woh|wo|sb|sab|kuch|koi|bs|bas|mn|mein|kr|krn|krna|krte|krdo|kr rhi|rhi|rha|rhe|kr rha|kr rhe)\b/iu',
    ] as $pattern) {
        if (preg_match_all($pattern, $lower, $matches)) {
            $romanScore += count($matches[0]);
        }
    }

    $germanScore = 0;
    foreach ([
        '/\b(wie|was|wer|wo|wann|warum|wieviel|welche|welcher|welches|eigentlich|bitte|danke|hallo|guten|morgen|abend)\b/iu',
        '/\b(ich|du|sie|er|es|wir|ihr|uns|ihnen|ihr|mich|mir|dir|dich|euch|man)\b/iu',
        '/\b(heißen|heißt|können|kann|könnt|müssen|muss|haben|hat|sind|ist|war|waren|wird|werden|werde|helfe|helfen|dabei|schon|auch|noch|nicht|kein|keine|sehr|gut|gerne)\b/iu',
        '/\b(der|die|das|den|dem|des|ein|eine|einer|einem|einen|und|oder|aber|für|mit|auf|aus|bei|von|zu|um|an|in)\b/iu',
        '/\b(umzuwandeln|heißen|können|möchte|möchten|würde|wäre|hätte|könnte|sollte|dürfen|darf)\b/iu',
    ] as $pattern) {
        if (preg_match_all($pattern, $lower, $matches)) {
            $germanScore += count($matches[0]);
        }
    }

    // German umlauts / ß are strong signals even without keyword hits
    if (preg_match('/[äöüß]/u', $lower)) {
        $germanScore += 2;
    }

    $scores = [
        'english'     => $englishScore,
        'roman_urdu'  => $romanScore,
        'german'      => $germanScore,
    ];
    arsort($scores);
    $topLang = (string) array_key_first($scores);
    $topScore = (int) ($scores[$topLang] ?? 0);
    $secondScore = (int) (array_values($scores)[1] ?? 0);

    if ($topScore >= 1 && ($topScore >= 2 || $topScore > $secondScore)) {
        if ($englishScore >= 1 && $romanScore >= 1) {
            return 'mixed';
        }
        return $topLang;
    }

    if ($romanScore >= 1 && $englishScore === 0 && $germanScore === 0) {
        return 'roman_urdu';
    }

    if ($germanScore >= 1 && $englishScore === 0 && $romanScore === 0) {
        return 'german';
    }

    if ($englishScore >= 1 && $romanScore === 0 && $germanScore === 0) {
        return 'english';
    }

    if ($englishScore >= 1 && ($romanScore >= 1 || $germanScore >= 1)) {
        return 'mixed';
    }

    // Do not assume English — let the model mirror the customer's language
    return 'mirror';
}

/**
 * Prefer the latest customer message; scan recent user turns if needed.
 *
 * @param array<int, array{role: string, message: string}> $history
 * @return english|roman_urdu|urdu_script|german|mixed|mirror
 */
function resolve_customer_language(array $history, string $latestUserMessage): string
{
    $text = customer_message_text_for_language($latestUserMessage);
    $lang = detect_customer_language($text);
    if ($lang !== 'mirror') {
        return $lang;
    }

    if ($text === '') {
        foreach (array_reverse($history) as $row) {
            if (($row['role'] ?? '') !== 'user') {
                continue;
            }
            $prior = customer_message_text_for_language((string) ($row['message'] ?? ''));
            if ($prior === '') {
                continue;
            }
            $lang = detect_customer_language($prior);
            if ($lang !== 'mirror') {
                return $lang;
            }
            break;
        }
        return 'mirror';
    }

    foreach (array_reverse($history) as $row) {
        if (($row['role'] ?? '') !== 'user') {
            continue;
        }
        $prior = customer_message_text_for_language((string) ($row['message'] ?? ''));
        if ($prior === '') {
            break;
        }
        $lang = detect_customer_language($prior);
        if ($lang !== 'mirror') {
            return $lang;
        }
        break;
    }

    return 'mirror';
}

/**
 * Human-readable instruction for detected language.
 */
function customer_language_instruction(string $detected): string
{
    return match ($detected) {
        'english'     => 'The customer is writing in ENGLISH. You MUST reply in English only — no Roman Urdu, no Urdu script, no Hindi. Do NOT use words like aap, hai, kya, shukriya, theek. Keep names and brand terms as in the script.',
        'roman_urdu'  => 'The customer is writing in Roman Urdu. Reply in natural Roman Urdu (Pakistani WhatsApp style) — NOT in English. Use words like aap, hai, kya, theek naturally. Only use English for brand names or common loanwords (OK, deal).',
        'urdu_script' => 'The customer is writing in Urdu (Arabic script). Reply in Urdu script with respectful آپ — not English or Roman Urdu.',
        'german'      => 'The customer is writing in GERMAN. Reply in natural German — NOT in English. Match their tone (formal Sie vs informal du) based on how they wrote.',
        'mixed'       => 'The customer is mixing languages. Mirror their mix — use the same blend, leaning toward their latest message style.',
        'mirror'      => 'Read the customer\'s latest message and reply in the EXACT same language they used (German, French, Spanish, Roman Urdu, English, etc.). Do NOT default to English unless they wrote in English.',
        default       => 'Reply in the same language as the customer\'s latest message. Do not default to English.',
    };
}
/**
 * Suggested default language from country (first message only — message text always wins).
 */
function visitor_country_language_hint(string $countryCode): string
{
    $map = [
        'PK' => 'Match customer: English or Urdu as they write',
        'IN' => 'Hindi / English',
        'DE' => 'German / English',
        'FR' => 'French',
        'IT' => 'Italian',
        'NL' => 'Dutch',
        'SA' => 'Arabic',
        'AE' => 'Arabic / English',
        'TR' => 'Turkish',
        'BD' => 'Bengali / English',
        'GB' => 'English',
        'US' => 'English',
    ];

    return $map[strtoupper($countryCode)] ?? '';
}

/**
 * Build visitor context array for AI prompt injection.
 *
 * @param array{locale?: string, country?: string} $clientHints From widget / API body
 * @return array{locale: string, country: string, country_language_hint: string, ip_detected: bool}
 */
function resolve_visitor_context(array $clientHints = []): array
{
    $locale = visitor_browser_locale($clientHints['locale'] ?? null);
    $country = strtoupper(trim($clientHints['country'] ?? ''));
    if (strlen($country) !== 2) {
        $country = visitor_country_code();
    }

    return [
        'locale'                 => $locale,
        'country'                => $country,
        'country_language_hint'  => $country !== '' ? visitor_country_language_hint($country) : '',
        'ip_detected'            => $country !== '' && empty($_SERVER['HTTP_CF_IPCOUNTRY']),
    ];
}

/**
 * Persist visitor context on lead (first touch).
 *
 * @param array<string, mixed> $lead
 */
function store_visitor_context_on_lead(int $leadId, array $context): void
{
    if ($context === []) {
        return;
    }

    $row = db_fetch('SELECT qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
    $data = [];
    if (!empty($row['qualification_data'])) {
        $decoded = json_decode($row['qualification_data'], true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (!isset($data['visitor_context'])) {
        $data['visitor_context'] = $context;
        db_execute(
            'UPDATE leads SET qualification_data = ? WHERE id = ?',
            'si',
            [json_encode($data, JSON_UNESCAPED_UNICODE), $leadId]
        );
    }
}

/**
 * @param array<string, mixed>|null $lead
 * @return array<string, mixed>
 */
function visitor_context_from_lead(?array $lead): array
{
    if (!$lead || empty($lead['qualification_data'])) {
        return [];
    }
    $data = json_decode($lead['qualification_data'], true);
    if (!is_array($data) || empty($data['visitor_context']) || !is_array($data['visitor_context'])) {
        return [];
    }
    return $data['visitor_context'];
}

/**
 * System prompt block: multilingual + professional tone instructions.
 *
 * @param array<string, mixed> $context
 * @param array<int, array{role: string, message: string}> $history
 */
function build_visitor_language_prompt(array $context = [], string $latestUserMessage = '', array $history = []): string
{
    $detected = resolve_customer_language($history, $latestUserMessage);
    $hasUserMessages = trim($latestUserMessage) !== ''
        || array_filter($history, static fn ($r) => ($r['role'] ?? '') === 'user') !== [];

    $lines = [
        '',
        '───── LANGUAGE & TONE (follow strictly) ─────',
        'PRIMARY RULE: Reply in the SAME language and script as the customer\'s latest message.',
        'If they switch language mid-chat, switch with them on the very next reply.',
        'Country or phone location does NOT decide language — only what the customer actually writes.',
        '',
        'English: Polite business English — only when the customer writes in English.',
        'Roman Urdu: Natural Pakistani WhatsApp style — when the customer writes in Roman Urdu.',
        'Urdu (Arabic script): Proper Urdu with respectful آپ — when the customer uses Urdu script.',
        'German / French / Spanish / other: Reply fluently in that language when the customer writes in it.',
        'Never default to English if the customer wrote in another language.',
        'Never default to Roman Urdu if the customer wrote in English.',
        '',
        'TONE: A respectful person on WhatsApp — warm but precise.',
        '- Listen first. Answer the question or point in 1–2 short sentences.',
        '- One follow-up question maximum, and only if you still need something; never stack questions.',
        '- Emojis: at most one per message, often none.',
    ];

    $instruction = customer_language_instruction($detected);
    if ($instruction !== '') {
        $lines[] = '';
        $lines[] = '───── CUSTOMER LANGUAGE THIS TURN (mandatory — overrides everything below) ─────';
        $lines[] = $instruction;
        if ($latestUserMessage !== '') {
            $sample = customer_message_text_for_language($latestUserMessage);
            if ($sample === '') {
                $sample = trim($latestUserMessage);
            }
            $lines[] = 'Their latest message: """' . mb_substr($sample, 0, 280) . '"""';
        }
    }

    if (!$hasUserMessages) {
        $locale = $context['locale'] ?? '';
        $country = $context['country'] ?? '';
        $hint = $context['country_language_hint'] ?? '';

        if ($locale !== '' || $country !== '') {
            $lines[] = '';
            $lines[] = 'FIRST MESSAGE ONLY (before customer writes — then ignore these hints):';
            if ($locale !== '') {
                $lines[] = '- Browser locale: ' . $locale;
            }
            if ($country !== '') {
                $lines[] = '- Country hint: ' . $country . ($hint !== '' ? ' (' . $hint . ')' : '');
            }
        }
    }

    return implode("\n", $lines);
}

/**
 * Final system-prompt footer — overrides Roman Urdu script examples when customer writes English.
 */
function build_language_lock_footer(string $latestUserMessage, array $history = []): string
{
    $detected = resolve_customer_language($history, $latestUserMessage);
    $instruction = customer_language_instruction($detected);

    $lines = [
        '',
        '═════ FINAL RULE — REPLY LANGUAGE (overrides all script examples above) ═════',
        $instruction,
    ];

    if ($detected === 'english') {
        $lines[] = 'The sales script may contain Roman Urdu sample lines — IGNORE those for wording. Write this reply in clear English only.';
        $lines[] = 'FORBIDDEN in this reply: Roman Urdu (aap, ap, hai, hain, kya, shukriya, theek, ji, batao, chahiye, mn, etc.).';
        $lines[] = 'FORBIDDEN in this reply: ANY Hindi language — no Hindi words, no Devanagari script, no Hinglish Hindi vocabulary, under any circumstances.';
    } elseif ($detected === 'roman_urdu') {
        $lines[] = 'The sales script may contain English sample lines — translate the meaning into natural Roman Urdu for this reply.';
        $lines[] = 'FORBIDDEN in this reply: replying in English when the customer wrote in Roman Urdu.';
        $lines[] = 'FORBIDDEN in this reply: ANY Hindi language — no Hindi words, no Devanagari script, no Hinglish Hindi vocabulary.';
    } elseif ($detected === 'german') {
        $lines[] = 'The sales script may be in English or Roman Urdu — translate the meaning into natural German for this reply.';
        $lines[] = 'FORBIDDEN in this reply: replying in English when the customer wrote in German.';
    }

    $sample = customer_message_text_for_language($latestUserMessage);
    if ($sample === '') {
        $sample = trim($latestUserMessage);
    }
    if ($sample !== '') {
        $lines[] = 'Customer wrote: """' . mb_substr($sample, 0, 280) . '""" — match THIS language exactly.';
    }

    return implode("\n", $lines);
}

/**
 * True if assistant text looks Roman Urdu / Urdu script (for English-customer enforcement).
 */
function reply_looks_roman_urdu(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $text)) {
        return true;
    }

    return preg_match(
        '/\b(aap|aapko|mujhe|mjhe|kya|kia|hai|hain|nahi|nhi|shukriya|shukria|theek|thek|batao|bataiye|chahiye|bohat|kitna|kaise|mn|kr|krna|krdo|aur|rhi|rha|rhe|ho)\b/iu',
        $text
    ) === 1;
}

/**
 * True if assistant reply looks like English (for non-English customer enforcement).
 */
function reply_looks_english(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    if (reply_looks_roman_urdu($text)) {
        return false;
    }

    if (reply_looks_german($text)) {
        return false;
    }

    return preg_match(
        '/\b(the|your|our|please|thank|thanks|hello|hi|would|could|help|about|just|what|how|with|from|team|today)\b/i',
        $text
    ) === 1;
}

/**
 * True if assistant reply looks like German.
 */
function reply_looks_german(string $text): bool
{
    return preg_match(
        '/\b(ich|sie|und|der|die|das|ist|sind|haben|kann|können|nicht|bitte|danke|wie|was|für|mit|auf|auch|sehr|gut|gerne|heiße|heißen|helfen|dabei|möchte|würde)\b/iu',
        $text
    ) === 1 || preg_match('/[äöüß]/u', $text) === 1;
}

/**
 * Whether the draft reply should be regenerated for language mismatch.
 */
function ai_reply_needs_language_retry(string $reply, string $customerLang): bool
{
    if ($customerLang === 'english') {
        return reply_looks_roman_urdu($reply);
    }

    if ($customerLang === 'roman_urdu') {
        return reply_looks_english($reply);
    }

    if ($customerLang === 'german') {
        return reply_looks_english($reply) && !reply_looks_german($reply);
    }

    if ($customerLang === 'urdu_script') {
        $clean = trim(strip_tags($reply));
        return $clean !== '' && !preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $clean);
    }

    return false;
}

/**
 * User-message nudge appended only to the API payload (not stored in DB).
 */
function build_language_retry_user_nudge(string $customerLang): string
{
    return match ($customerLang) {
        'english'     => 'Your last draft used Roman Urdu/Urdu but the customer wrote in ENGLISH. Rewrite the reply in clear English only. Same facts from the script. No Roman Urdu words (aap, hai, kya, shukriya, theek, ji, etc.).',
        'roman_urdu'  => 'Your last draft was in English but the customer wrote in Roman Urdu. Rewrite in natural Roman Urdu (Pakistani WhatsApp style). Same facts — Roman Urdu wording, not English.',
        'german'      => 'Your last draft was in English but the customer wrote in GERMAN. Rewrite in natural German. Same facts — German wording, not English.',
        'urdu_script' => 'The customer wrote in Urdu script. Rewrite your reply in Urdu (Arabic script) with respectful آپ.',
        'mirror'      => 'Your last draft was in English but the customer did NOT write in English. Rewrite in the exact same language as their latest message.',
        default       => 'Rewrite your reply in the same language the customer used in their latest message.',
    };
}
