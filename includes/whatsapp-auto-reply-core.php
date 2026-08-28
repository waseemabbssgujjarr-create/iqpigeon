<?php
/**
 * WhatsApp AUTO-REPLY CORE — Meta send + turn completion. Never loads human-agent by default.
 *
 * Rule: this layer ALWAYS sends when bot whatsapp_auto_reply is ON.
 * Human tone is optional — toggle WHATSAPP_HUMAN_LAYER_ENABLED only.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function wa_auto_reply_require_recover_lite(): void
{
    require_once __DIR__ . '/wa-recover-lite.php';
}

/** Core delivery is never disabled by config — only bot whatsapp_auto_reply matters. */
function wa_auto_reply_core_enabled(): bool
{
    return true;
}

/** Safe fallback when OpenAI misses the budget — never the old rotating canned lines. */
function wa_core_fallback_text(array $bot, string $userMessage, int $leadId = 0): string
{
    return wa_auto_reply_safe_fallback($bot, $leadId, $userMessage);
}

function wa_auto_reply_last_assistant(int $leadId): string
{
    if ($leadId <= 0) {
        return '';
    }
    if (function_exists('conversation_last_assistant_reply')) {
        return trim(conversation_last_assistant_reply($leadId));
    }
    $row = db_fetch(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 1',
        'i',
        [$leadId]
    );

    return trim((string) ($row['message'] ?? ''));
}

/**
 * @param array<string, mixed> $bot
 */
function wa_auto_reply_safe_fallback(array $bot, int $leadId, string $userMessage): string
{
    $last = mb_strtolower(wa_auto_reply_last_assistant($leadId));

    try {
        require_once __DIR__ . '/whatsapp-human-layer.php';
        $warm = wa_human_warm_reply($bot, $leadId, $userMessage);
        if ($warm !== null && trim($warm) !== '' && mb_strtolower(trim($warm)) !== $last) {
            return trim($warm);
        }
    } catch (Throwable $e) {
        error_log('wa_auto_reply_safe_fallback warm: ' . $e->getMessage());
    }

    if ($leadId > 0) {
        try {
            require_once __DIR__ . '/cart.php';
            if (!cart_is_empty($leadId)) {
                $summary = trim(cart_format_summary($leadId));
                if ($summary !== '' && mb_strtolower($summary) !== $last) {
                    return $summary;
                }
            }
        } catch (Throwable $e) {
            error_log('wa_auto_reply_safe_fallback cart: ' . $e->getMessage());
        }
    }

    $rep = function_exists('get_bot_rep_name') ? get_bot_rep_name($bot) : 'I';
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : trim((string) ($bot['company_name'] ?? 'us'));
    $line = "I'm {$rep} with {$brand} — I read your message. Tell me the next detail you want.";
    if (mb_strtolower($line) === $last) {
        $line = 'Still here — what should I do next?';
    }

    return $line;
}

function wa_auto_reply_persist_inbound(int $leadId, string $userText): void
{
    $userText = trim($userText);
    if ($leadId <= 0 || $userText === '' || !function_exists('conversation_insert')) {
        return;
    }

    try {
        $lastRow = db_fetch(
            'SELECT message FROM conversations WHERE lead_id = ? AND role = \'user\' ORDER BY id DESC LIMIT 1',
            'i',
            [$leadId]
        );
        $lastUser = trim((string) ($lastRow['message'] ?? ''));
        if ($lastUser !== '' && $lastUser === $userText) {
            return;
        }
        conversation_insert($leadId, 'user', $userText);
        require_once __DIR__ . '/drip.php';
        if (function_exists('drip_reset_on_customer_reply')) {
            drip_reset_on_customer_reply($leadId);
        }
    } catch (Throwable $e) {
        error_log('wa_auto_reply_persist_inbound #' . $leadId . ': ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function wa_webhook_products(int $botId, int $offset = 0, int $limit = 12): array
{
    if ($botId <= 0) {
        return [];
    }
    $offset = max(0, $offset);
    $limit = max(1, min(12, $limit));
    try {
        $rows = db_fetch_all(
            'SELECT id, name, price, currency, category, description FROM bot_products
             WHERE bot_id = ? AND is_active = 1
             ORDER BY sort_order ASC, name ASC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            'i',
            [$botId]
        );
        if (!is_array($rows)) {
            $rows = [];
        }

        return $rows;
    } catch (Throwable $e) {
        try {
            $rows = db_fetch_all(
                'SELECT id, name, price, currency FROM bot_products
                 WHERE bot_id = ? AND is_active = 1
                 ORDER BY sort_order ASC, name ASC
                 LIMIT ' . $limit . ' OFFSET ' . $offset,
                'i',
                [$botId]
            );

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e2) {
            error_log('wa_webhook_products: ' . $e2->getMessage());

            return [];
        }
    }
}

function wa_webhook_product_count(int $botId): int
{
    if ($botId <= 0) {
        return 0;
    }
    try {
        $row = db_fetch(
            'SELECT COUNT(*) AS c FROM bot_products WHERE bot_id = ? AND is_active = 1',
            'i',
            [$botId]
        );

        return (int) ($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function wa_webhook_is_social(string $msg): bool
{
    $msg = trim($msg);
    if ($msg === '') {
        return false;
    }
    if (preg_match('/^(haha+|hehe+|hihi+|lol+|lmao+|ok+|okay|hmm+|hmmm+|oh+|aha+|wow+|nice|cool|yeah|yep|yup)[.!?]*$/u', $msg)) {
        return true;
    }
    $compact = (string) (preg_replace('/\s+/u', '', $msg) ?? $msg);
    if (mb_strlen($compact) >= 3 && preg_match('/^(.)\1+$/u', $compact)) {
        return true;
    }

    return (bool) preg_match(
        '/\b(friend|dost|bored|joke|funny|love you|miss you|long time|good to (hear|see) you|'
        . 'are you (real|a bot|ai|chatgpt|human)|you a bot|who made you|marry|'
        . 'how was your day|what are you doing|kya kar rahe)\b/u',
        $msg
    );
}

/**
 * @return list<string>
 */
function wa_webhook_search_keywords(string $query): array
{
    $stop = [
        'the', 'and', 'for', 'you', 'have', 'any', 'item', 'items', 'what', 'do', 'does',
        'can', 'please', 'pls', 'want', 'give', 'send', 'show', 'some', 'with', 'from',
        'this', 'that', 'your', 'our', 'got', 'are', 'was', 'there', 'tell', 'whats',
        'anyhow', 'condition', 'side', 'waiting', 'need', 'now', 'here', 'just',
        'about', 'whats', 'how', 'why', 'when', 'where', 'who',
    ];
    $words = preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [];
    $out = [];
    foreach ($words as $word) {
        $word = trim((string) (preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? $word));
        if (mb_strlen($word) < 4 || in_array($word, $stop, true)) {
            continue;
        }
        $out[] = $word;
    }

    return array_values(array_unique($out));
}

function wa_webhook_wants_catalog(string $msg, array $bot = []): bool
{
    if ($bot !== [] && function_exists('bot_uses_shop_catalog') && !bot_uses_shop_catalog($bot)) {
        return false;
    }
    if (preg_match('/^(the\s+)?menu[\s!?.]*$/u', $msg)) {
        return true;
    }
    if (preg_match('/\b(price|how much|cost)\b/u', $msg)
        && preg_match('/\b(burger|pizza|menu|item|items|product|deal|this|broast)\b/u', $msg)
    ) {
        return true;
    }
    if (preg_match('/\b(show|send|see|browse)\b.{0,40}\b(menu|items|products|catalog|catalogue|services|packages|burgers?|pizzas?|broast|deals?|drinks?|desserts?)\b/u', $msg)) {
        return true;
    }
    if (preg_match('/\b(burgers?|pizzas?|broast deals?)\b/u', $msg)
        && preg_match('/\b(show|send|see|your|have|any|want)\b/u', $msg)
    ) {
        return true;
    }
    if (preg_match('/\bwhat (do you (have|sell|offer)|you (have|sell|offer)|is on (the )?(menu|list))\b/u', $msg)) {
        return true;
    }
    if (preg_match('/\b(do you have|have you got)\b.{0,48}\b(menu|item|items|food|dish|product|products|service|package|room|slot)\b/u', $msg)) {
        return true;
    }
    if (preg_match('/\b(breakfast|brunch|lunch|dinner|dessert|starter|appetizer)\b/u', $msg)
        && preg_match('/\b(menu|item|items|food|dish|order|have|any)\b/u', $msg)
    ) {
        return true;
    }
    if (preg_match('/\bhow to add\b.{0,24}\bitem\b/u', $msg)) {
        return true;
    }
    if (preg_match('/\b(book|booking|appointment|quote|sku)\b/u', $msg)
        && !preg_match('/\b(facebook|instagram|copy of|notebook)\b/u', $msg)
    ) {
        return true;
    }

    return false;
}

/**
 * @return list<array{role: string, message: string}>
 */
function wa_webhook_recent_chat(int $leadId, int $limit = 8): array
{
    if ($leadId <= 0) {
        return [];
    }
    $limit = max(2, min(12, $limit));
    try {
        $rows = db_fetch_all(
            'SELECT role, message FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT ' . $limit,
            'i',
            [$leadId]
        );
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_reverse($rows);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string, mixed> $bot
 */
function wa_webhook_bot_persona_text(array $bot): string
{
    $persona = trim((string) ($bot['rep_persona'] ?? ''));
    if ($persona === '') {
        $persona = trim((string) preg_replace('/ Tone: .+$/', '', (string) ($bot['persona_description'] ?? '')));
    }

    return $persona;
}

function wa_webhook_persona_looks_like_prompt(string $persona): bool
{
    if ($persona === '') {
        return false;
    }

    return (bool) preg_match(
        '/\b(identity\s*&\s*role|you are the|lead management agent|customer relations|'
        . 'never say you are|system prompt|your name, business name|tone:|'
        . 'do not (?:invent|reveal)|act as (?:an? )?ai)\b/iu',
        $persona
    );
}

function wa_webhook_persona_hobby_line(string $persona, string $rep): string
{
    $bits = [];
    if (preg_match('/cricket/iu', $persona)) {
        $bits[] = 'cricket';
    }
    if (preg_match('/\b(movie|movies|film|films|thriller|comedy)/iu', $persona)) {
        $bits[] = 'movies';
    }
    if (preg_match('/\b(food|chai|cooking)/iu', $persona)) {
        $bits[] = 'food';
    }
    if (preg_match('/\b(football|soccer)/iu', $persona)) {
        $bits[] = 'football';
    }
    if (preg_match('/\b(music|songs)/iu', $persona)) {
        $bits[] = 'music';
    }
    if ($bits !== []) {
        $tail = count($bits) > 1 ? ' and ' . array_pop($bits) : '';
        $list = implode(', ', $bits) . $tail;

        return "I like {$list}. What about you?";
    }

    return "Nothing fancy — food, films, hanging out. You?";
}

function wa_webhook_human_intro(array $bot): string
{
    $rep = function_exists('get_bot_rep_name') ? get_bot_rep_name($bot) : 'I';
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : trim((string) ($bot['company_name'] ?? $bot['name'] ?? 'us'));
    $persona = wa_webhook_bot_persona_text($bot);
    $hobby = wa_webhook_persona_hobby_line($persona, $rep);
    $hobbyShort = trim((string) preg_replace('/\s*What about you\??$/iu', '', $hobby));
    require_once __DIR__ . '/bot-knowledge.php';
    $bizCity = bot_extract_city((string) (bot_owner_profile_fields($bot)['address'] ?? ''));
    $line = "I'm {$rep}. I work with {$brand}";
    if ($bizCity !== '') {
        $line .= " in {$bizCity}";
    }
    $line .= '. ' . $hobbyShort . '.';

    return $line;
}

function wa_webhook_persona_place(string $persona): string
{
    if (preg_match('/\b(?:live[s]? in|based in|from)\s+([A-Za-z][A-Za-z]+(?:\s+[A-Za-z][A-Za-z]+)?)/u', $persona, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\b(Lahore|Karachi|Islamabad|Rawalpindi|Multan|Faisalabad|Peshawar|Quetta|London|Dubai|Riyadh)\b/u', $persona, $m)) {
        return (string) $m[1];
    }

    return '';
}

/**
 * Reply to the customer's actual ask using persona + last turn. Empty = not this kind of question.
 *
 * @param array<string, mixed> $bot
 */
function wa_webhook_answer_what_they_asked(array $bot, int $leadId, string $userMessage, string $msg): string
{
    $rep = function_exists('get_bot_rep_name') ? get_bot_rep_name($bot) : 'I';
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : trim((string) ($bot['company_name'] ?? $bot['name'] ?? 'us'));
    $persona = wa_webhook_bot_persona_text($bot);
    $repEsc = preg_quote($rep, '/');

    $isUnderstand = (bool) preg_match(
        '/\b(did you (understand|understood|get (it|that|me)|hear)|do you understand|you understand|samajh|samjh)\b/u',
        $msg
    ) || (bool) preg_match('/\bunderstood what i (said|asked)\b/u', $msg);

    $priorAsk = '';
    if ($isUnderstand && $leadId > 0) {
        $history = wa_webhook_recent_chat($leadId, 10);
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $t = trim((string) ($history[$i]['message'] ?? ''));
            $tl = mb_strtolower($t);
            if ($t === '' || $tl === $msg || mb_strtolower(trim($userMessage)) === $tl) {
                continue;
            }
            if (preg_match('/\b(understand|understood)\b/u', $tl)) {
                continue;
            }
            $priorAsk = $t;
            break;
        }
    }
    if ($isUnderstand) {
        $priorMsg = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $priorAsk)));
        if ($priorMsg !== '' && (
            preg_match('/\b(hobb(?:y|ies)|what do you like|free time|pastime)\b/u', $priorMsg)
            || preg_match('/\b(name|who are you|where are you|are you)\b/u', $priorMsg)
        )) {
            $again = wa_webhook_answer_what_they_asked($bot, 0, $priorAsk, $priorMsg);
            if ($again !== '') {
                return 'Yes — ' . $again;
            }
        }
        if ($priorAsk !== '') {
            return 'Yes, I got it. You said: "' . mb_substr($priorAsk, 0, 80) . '". What should I do with that?';
        }

        return "Yes, I'm with you. Ask me again in one message if that came out split.";
    }

    if (preg_match('/\b(hobb(?:y|ies)|what do you like|what you like to do|free time|pastime|interests)\b/u', $msg)) {
        return wa_webhook_persona_hobby_line($persona, $rep);
    }

    if (preg_match('/\b(how about you|how r you|and you|aap kaise)\b/u', $msg)
        && !preg_match('/\b(menu|order|cart)\b/u', $msg)
    ) {
        return "I'm good — busy but alright. How's your day going?";
    }

    if (preg_match('/\b(introduce yourself|tell me about yourself|who are you|your name|what(?:\'s| is) your name)\b/u', $msg)
        && !preg_match('/\b(order|cart|menu)\b/u', $msg)
    ) {
        return wa_webhook_human_intro($bot);
    }

    if (preg_match('/\b(tell me more|more about you)\b/u', $msg)
        && !preg_match('/\b(menu|item|product|order)\b/u', $msg)
    ) {
        return wa_webhook_persona_hobby_line($persona, $rep);
    }

    if (preg_match('/\b(friends?|dost|yaar)\b/u', $msg)
        && preg_match('/\b(be|just|only|want|wanna|need|nothing|don\'?t want)\b/u', $msg)
    ) {
        return "Yeah, we can just chat. What's going on with you?";
    }

    if (preg_match('/^(what|huh|pardon|sorry)\??$/u', $msg)
        || preg_match('/\b(you didn\'?t understand|didn\'?t (get|understand)|not what i (said|meant)|confused)\b/u', $msg)
    ) {
        return "Sorry — I missed that. Say it again in one message and I'll answer that.";
    }

    if ($repEsc !== '' && (
        preg_match('/\bare you(?:\s+sure\s+you\s+are)?\s+' . $repEsc . '\b/iu', $msg)
        || preg_match('/\byou are\s+' . $repEsc . '\b/iu', $msg)
        || preg_match('/^' . $repEsc . '\s*\?*$/iu', $msg)
    )) {
        return "Yes — I'm {$rep}, with {$brand}.";
    }

    if (preg_match('/\b(where are you|where you (from|based)|which city|your location|where is (the |your )?(restaurant|shop|store|clinic|office)|your address)\b/u', $msg)
        && !preg_match('/\b(order|delivery)\b/u', $msg)
    ) {
        $address = '';
        $bizCity = '';
        require_once __DIR__ . '/bot-knowledge.php';
        $address = trim((string) (bot_owner_profile_fields($bot)['address'] ?? ''));
        $bizCity = bot_extract_city($address);
        $isPersonal = (bool) preg_match('/\b(where do you live|where are you from|your home|hometown)\b/u', $msg);
        if (!$isPersonal && $address !== '') {
            return $bizCity !== ''
                ? "We're in {$bizCity} — {$address}."
                : "We're at {$address}.";
        }
        $place = wa_webhook_persona_place($persona);
        if ($isPersonal && $place !== '' && !wa_webhook_persona_looks_like_prompt($persona)) {
            if ($address !== '') {
                return "I live in {$place}. The {$brand} location is {$address}.";
            }

            return "I live in {$place}.";
        }
        if ($address !== '') {
            return "We're at {$address}.";
        }
        if ($place !== '' && !wa_webhook_persona_looks_like_prompt($persona)) {
            return "I'm {$rep} with {$brand}.";
        }

        return "I'm {$rep} with {$brand}.";
    }

    return '';
}

/**
 * Everyday chat — answer what they said. OpenAI only when the webhook skip flag is off.
 *
 * @param array<string, mixed> $bot
 */
function wa_webhook_friend_talk(array $bot, int $leadId, string $userMessage, string $msg): string
{
    require_once __DIR__ . '/conversation-mind.php';

    return conversation_mind_reply($bot, $leadId, $userMessage !== '' ? $userMessage : $msg);
}

/**
 * @param array<string, mixed> $bot
 * @param list<array{role?: string, message?: string}> $history
 */
function wa_webhook_friend_openai(array $bot, string $rep, string $brand, string $persona, array $history, string $userMessage): string
{
    if (!empty($GLOBALS['wa_skip_openai']) || trim($userMessage) === '') {
        return '';
    }
    try {
        require_once __DIR__ . '/openai.php';
        if (!function_exists('ai_chat')) {
            return '';
        }
        $sys = "You are {$rep}, a real person who works with {$brand}. "
            . 'Talk like a friend on WhatsApp. Never say you are an AI, bot, assistant, or chatbot. '
            . 'Do not send a menu, catalogue, product list, or prices unless they clearly want to buy or asked for the list. '
            . 'You do not have live sports scores or news feeds — chat as a person who has not checked yet. '
            . 'Use the chat history. 1–3 short sentences. Match their language (English, Urdu, Roman Urdu).';
        if ($persona !== '' && !wa_webhook_persona_looks_like_prompt($persona)) {
            $sys .= ' Personal life you may share (do not invent extra facts): ' . mb_substr($persona, 0, 3500);
        }
        $messages = [['role' => 'system', 'content' => $sys]];
        foreach ($history as $row) {
            $role = (($row['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $text = trim((string) ($row['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => mb_substr($text, 0, 400)];
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 500)];
        $out = ai_chat($messages, [
            'timeout'      => 4,
            'max_attempts' => 1,
            'max_tokens'   => 120,
            'temperature'  => 0.7,
        ]);
        $text = trim((string) ($out['content'] ?? ''));
        if ($text === '' || empty($out['success'])) {
            return '';
        }
        if (preg_match('/\b(i am (an? )?(ai|bot|assistant|chatbot)|as an ai)\b/iu', $text)) {
            return '';
        }
        if (preg_match('/reply with a number|view catalog|showing \d/iu', $text)) {
            return '';
        }

        return mb_substr($text, 0, 500);
    } catch (Throwable $e) {
        error_log('wa_webhook_friend_openai: ' . $e->getMessage());

        return '';
    }
}

function wa_webhook_looks_like_delivery(string $message): bool
{
    $text = trim($message);
    if ($text === '') {
        return false;
    }
    $digits = preg_replace('/\D/', '', $text) ?? '';
    $hasPhone = strlen($digits) >= 10 && strlen($digits) <= 15;
    $hasAddr = (bool) preg_match(
        '/street|road|block|phase|house|flat|plot|near|office|dha|bahria|gulberg|town|society|'
        . 'lahore|karachi|islamabad|multan|rawalpindi|cantt|address|delivery/iu',
        $text
    );
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $lineCount = count(array_filter(array_map('trim', $lines)));

    return $hasPhone && ($hasAddr || $lineCount >= 2);
}

function wa_webhook_parse_add_index(string $msg, string $raw): ?int
{
    if (preg_match('/\b(more|next|out of these|another page)\b/u', $msg)) {
        return null;
    }
    if (preg_match('/^(?:add\s*(?:this|that|one|it)?\s*)?#?\s*(\d{1,3})$/u', $msg, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/\b(?:also\s+)?add(?:\s+(?:this|that|one|it))?(?:\s+one)?\s+#?\s*(\d{1,3})\b/u', $msg, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/\b(?:this|that)\s+one\s+#?\s*(\d{1,3})\b/u', $msg, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/#\s*(\d{1,3})\b/u', $msg, $m) && preg_match('/\b(add|also|one)\b/u', $msg)) {
        return (int) $m[1];
    }
    if (preg_match('/^(\d{1,2})$/u', trim($raw), $m)) {
        return (int) $m[1];
    }

    return null;
}

function wa_webhook_catalog_id(int $botId): string
{
    if ($botId <= 0) {
        return '';
    }
    try {
        $row = db_fetch('SELECT whatsapp_catalog_id FROM bots WHERE id = ?', 'i', [$botId]);

        return trim((string) ($row['whatsapp_catalog_id'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * @return list<array<string, mixed>>
 */
function wa_webhook_search_products(int $botId, string $query, int $limit = 8): array
{
    $query = trim($query);
    if ($botId <= 0 || mb_strlen($query) < 3) {
        return [];
    }
    $limit = max(1, min(8, $limit));
    $try = static function (int $botId, string $needle, int $limit): array {
        $needle = str_replace(['%', '_'], '', trim($needle));
        $needle = mb_substr($needle, 0, 80);
        if (mb_strlen($needle) < 3) {
            return [];
        }
        try {
            $rows = db_fetch_all(
                'SELECT id, name, price, currency, category, description FROM bot_products
                 WHERE bot_id = ? AND is_active = 1 AND name LIKE ?
                 ORDER BY sort_order ASC, name ASC
                 LIMIT ' . $limit,
                'is',
                [$botId, '%' . $needle . '%']
            );

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    };

    $direct = $try($botId, $query, $limit);
    if ($direct !== []) {
        return $direct;
    }

    $seen = [];
    $merged = [];
    foreach (wa_webhook_search_keywords($query) as $word) {
        foreach ($try($botId, $word, $limit) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            if (!preg_match('/\b' . preg_quote($word, '/') . '\b/u', $name)) {
                continue;
            }
            $merged[] = $row;
            if (count($merged) >= $limit) {
                return $merged;
            }
        }
    }

    return $merged;
}

function wa_webhook_product_by_sku(int $botId, string $sku): ?array
{
    $sku = trim($sku);
    if ($botId <= 0 || $sku === '') {
        return null;
    }
    try {
        if (preg_match('/^iqp_(\d+)$/i', $sku, $m)) {
            $row = db_fetch(
                'SELECT id, name, price, currency FROM bot_products WHERE bot_id = ? AND id = ? AND is_active = 1 LIMIT 1',
                'ii',
                [$botId, (int) $m[1]]
            );

            return $row ?: null;
        }
        $row = db_fetch(
            'SELECT id, name, price, currency FROM bot_products
             WHERE bot_id = ? AND is_active = 1 AND (sku = ? OR external_id = ?) LIMIT 1',
            'iss',
            [$botId, $sku, $sku]
        );

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function wa_webhook_filter_products_for_message(int $botId, string $userMessage, array $page): array
{
    $msg = mb_strtolower(trim($userMessage));
    $needles = [];
    if (preg_match('/\bburgers?\b/u', $msg)) {
        $needles[] = 'burger';
    }
    if (preg_match('/\bpizzas?\b/u', $msg)) {
        $needles[] = 'pizza';
    }
    if (preg_match('/\bbroast\b/u', $msg)) {
        $needles[] = 'broast';
    }
    if (preg_match('/\bdeals?\b/u', $msg)) {
        $needles[] = 'deal';
    }
    if (preg_match('/\bdesserts?\b/u', $msg)) {
        $needles[] = 'dessert';
    }
    if (preg_match('/\bdrinks?\b/u', $msg)) {
        $needles[] = 'drink';
    }
    if ($needles === []) {
        return [];
    }
    $hits = [];
    foreach ($page as $p) {
        $blob = mb_strtolower(
            trim((string) ($p['name'] ?? '')) . ' ' . trim((string) ($p['category'] ?? ''))
        );
        foreach ($needles as $n) {
            if (str_contains($blob, $n)) {
                $hits[] = $p;
                break;
            }
        }
    }
    if ($hits !== []) {
        return $hits;
    }
    if (function_exists('wa_webhook_search_products')) {
        foreach ($needles as $n) {
            $found = wa_webhook_search_products($botId, $n, 8);
            if ($found !== []) {
                return $found;
            }
        }
    }

    return [];
}

/**
 * After the text reply is already sent — native catalog + 3 buttons. No GD.
 *
 * @param array<string, mixed> $bot
 */
function wa_webhook_send_browse_ui(
    array $bot,
    string $phoneId,
    string $token,
    string $to,
    int $leadId
): void {
    if ($phoneId === '' || $token === '' || $to === '') {
        return;
    }
    $botId = (int) ($bot['id'] ?? 0);
    $catalogId = wa_webhook_catalog_id($botId);
    $catalogSent = false;
    if ($catalogId !== '' && function_exists('send_whatsapp_catalog_message')) {
        try {
            $sentCat = send_whatsapp_catalog_message(
                $phoneId,
                $token,
                $to,
                'Tap *View catalog* to scroll everything with photos. Or type a product name to add it.'
            );
            $catalogSent = !empty($sentCat['success']);
            if (!$catalogSent) {
                error_log('wa_webhook_send_browse_ui catalog_fail: ' . (string) ($sentCat['message'] ?? 'unknown'));
            }
        } catch (Throwable $e) {
            error_log('wa_webhook_send_browse_ui catalog: ' . $e->getMessage());
        }
    }
    if (function_exists('send_whatsapp_reply_buttons')) {
        try {
            send_whatsapp_reply_buttons($phoneId, $token, $to, 'Or tap below:', [
                ['id' => 'more', 'title' => 'More items'],
                ['id' => 'cart', 'title' => 'View cart'],
                ['id' => 'checkout', 'title' => 'Checkout'],
            ]);
        } catch (Throwable $e) {
            error_log('wa_webhook_send_browse_ui buttons: ' . $e->getMessage());
        }
    }
    unset($leadId);
}

/**
 * @return array<string, mixed>
 */
function wa_webhook_cart(int $leadId): array
{
    $row = db_fetch('SELECT qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
    $data = json_decode((string) ($row['qualification_data'] ?? ''), true);
    if (!is_array($data)) {
        $data = [];
    }
    $cart = $data['shop_cart'] ?? [];
    if (!is_array($cart)) {
        $cart = [];
    }

    return [
        'data'  => $data,
        'items' => is_array($cart['items'] ?? null) ? $cart['items'] : [],
        'name'  => trim((string) ($cart['customer_name'] ?? '')),
        'phone' => trim((string) ($cart['customer_phone'] ?? '')),
        'addr'  => trim((string) ($cart['shipping_address'] ?? '')),
        'shown' => array_values(array_filter(array_map('intval', (array) ($cart['shown_indexes'] ?? [])))),
        'raw'   => $cart,
    ];
}

/**
 * @param array<string, mixed> $cart
 */
function wa_webhook_cart_save(int $leadId, array $cart): void
{
    $row = db_fetch('SELECT qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
    $data = json_decode((string) ($row['qualification_data'] ?? ''), true);
    if (!is_array($data)) {
        $data = [];
    }
    $cart['updated_at'] = date('c');
    $data['shop_cart'] = $cart;
    db_execute(
        'UPDATE leads SET qualification_data = ? WHERE id = ?',
        'si',
        [json_encode($data, JSON_UNESCAPED_UNICODE), $leadId]
    );
}

function wa_webhook_menu_category_label(array $product): string
{
    $cat = trim((string) ($product['category'] ?? ''));
    if ($cat !== '') {
        return mb_strtoupper($cat);
    }
    $name = mb_strtolower((string) ($product['name'] ?? ''));
    if (str_contains($name, 'pizza')) {
        return 'PIZZAS';
    }
    if (str_contains($name, 'burger')) {
        return 'BURGERS';
    }
    if (preg_match('/\b(drink|juice|shake|soda|mocktail|lassi|tea|coffee)\b/u', $name)) {
        return 'DRINKS';
    }
    if (preg_match('/\b(cake|dessert|ice cream|cheesecake|brownie)\b/u', $name)) {
        return 'DESSERTS';
    }
    if (preg_match('/\b(broast|chicken|wings|tikka)\b/u', $name)) {
        return 'CHICKEN';
    }

    return 'MENU';
}

function wa_webhook_menu_category_emoji(string $label): string
{
    $l = mb_strtolower($label);
    if (str_contains($l, 'pizza')) {
        return '🍕';
    }
    if (str_contains($l, 'burger')) {
        return '🍔';
    }
    if (str_contains($l, 'drink') || str_contains($l, 'beverage')) {
        return '🥤';
    }
    if (str_contains($l, 'dessert') || str_contains($l, 'sweet')) {
        return '🍰';
    }
    if (str_contains($l, 'chicken') || str_contains($l, 'broast')) {
        return '🍗';
    }
    if (str_contains($l, 'deal') || str_contains($l, 'combo')) {
        return '🔥';
    }

    return '🍽️';
}

/**
 * @param list<array<string, mixed>> $products
 */
function wa_webhook_menu_text(array $bot, array $products): string
{
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : trim((string) ($bot['name'] ?? 'our'));
    if ($products === []) {
        return "I can take your order and answer questions for {$brand}. Tell me what you want.";
    }

    $groups = [];
    foreach ($products as $p) {
        $label = wa_webhook_menu_category_label($p);
        $groups[$label][] = $p;
    }

    $lines = [
        'Here is the ' . $brand . ' list — reply with a number or the name to add it. Tap *View catalog* for photos of the full catalog.',
        '',
    ];
    $n = 0;
    foreach ($groups as $label => $items) {
        $emoji = wa_webhook_menu_category_emoji((string) $label);
        $lines[] = $emoji . ' ' . $label;
        $lines[] = '────────────';
        foreach ($items as $p) {
            $n++;
            $name = trim((string) ($p['name'] ?? 'Item'));
            $price = (float) ($p['price'] ?? 0);
            $cur = strtoupper(trim((string) ($p['currency'] ?? 'PKR'))) ?: 'PKR';
            $priceTxt = $price > 0
                ? ($cur === 'PKR' ? 'PKR ' . number_format($price, 0) : $cur . ' ' . number_format($price, 2))
                : '';
            $num = $n <= 9 ? ($n . '️⃣') : (string) $n;
            $lines[] = $num . ' ' . $name;
            if ($priceTxt !== '') {
                $lines[] = '💰 ' . $priceTxt;
            }
            $desc = trim((string) ($p['description'] ?? ''));
            if ($desc !== '') {
                $lines[] = '📝 ' . mb_substr($desc, 0, 80);
            }
            $lines[] = '';
        }
    }
    $lines[] = '👉 Reply with the number to add, or the item name.';

    return trim(implode("\n", $lines));
}

/**
 * @param list<array<string, mixed>> $items
 */
function wa_webhook_cart_summary(array $items): string
{
    if ($items === []) {
        return 'Your cart is empty.';
    }
    $lines = ['Your cart:'];
    $total = 0.0;
    $cur = 'PKR';
    foreach ($items as $i => $item) {
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $price = (float) ($item['unit_price'] ?? 0);
        $cur = strtoupper(trim((string) ($item['currency'] ?? $cur))) ?: 'PKR';
        $total += $price * $qty;
        $lines[] = ($i + 1) . '. ' . trim((string) ($item['name'] ?? 'Item')) . ' ×' . $qty;
    }
    $lines[] = 'Total: ' . ($cur === 'PKR' ? 'PKR ' . number_format($total, 0) : $cur . ' ' . number_format($total, 2));

    return implode("\n", $lines);
}

/**
 * @param list<array<string, mixed>> $products
 */
function wa_webhook_add_product(int $leadId, array $product, int $qty = 1): string
{
    $state = wa_webhook_cart($leadId);
    $items = $state['items'];
    $qty = max(1, min(99, $qty));
    $pid = (int) ($product['id'] ?? 0);
    $found = false;
    foreach ($items as &$item) {
        if ((int) ($item['product_id'] ?? 0) === $pid) {
            $item['quantity'] = (int) ($item['quantity'] ?? 1) + $qty;
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        $items[] = [
            'product_id' => $pid,
            'name'       => (string) ($product['name'] ?? 'Item'),
            'quantity'   => $qty,
            'unit_price' => (float) ($product['price'] ?? 0),
            'currency'   => (string) ($product['currency'] ?? 'PKR'),
        ];
    }
    $raw = $state['raw'];
    $raw['items'] = $items;
    $raw['customer_name'] = $state['name'];
    $raw['customer_phone'] = $state['phone'];
    $raw['shipping_address'] = $state['addr'];
    $raw['shown_indexes'] = $state['shown'];
    if (isset($state['raw']['menu_offset'])) {
        $raw['menu_offset'] = (int) $state['raw']['menu_offset'];
    }
    wa_webhook_cart_save($leadId, $raw);

    return 'Added *' . trim((string) ($product['name'] ?? 'item')) . '* ×' . $qty . ".\n\n"
        . wa_webhook_cart_summary($items)
        . "\n\nAnything else, or send your name, phone, and address to confirm Cash on Delivery.";
}

function wa_webhook_offer(array $bot): string
{
    $model = trim((string) ($bot['business_model'] ?? ''));
    $model = trim((string) (preg_replace('/\[[^\]]+\]/', '', $model) ?? $model));
    $model = trim((string) (preg_replace('/\bwe serve\s*[.]/iu', '', $model) ?? $model));
    $model = trim((string) (preg_replace('/\s{2,}/u', ' ', $model) ?? $model));
    $model = trim($model, " \t.-");
    if ($model !== '' && !preg_match('/^we serve\.?$/iu', $model)) {
        return mb_substr($model, 0, 280);
    }
    $notes = trim((string) ($bot['bot_knowledge'] ?? ''));
    $notes = trim((string) (preg_replace('/\[[^\]]+\]/', '', $notes) ?? $notes));

    return mb_substr($notes, 0, 220);
}

function wa_webhook_place_order(array $bot, int $leadId, string $userMessage): ?string
{
    $state = wa_webhook_cart($leadId);
    if ($state['items'] === []) {
        return null;
    }
    $name = $state['name'];
    $phone = $state['phone'];
    $addr = $state['addr'];
    if (preg_match('/(\+?\d[\d\s\-]{9,}\d)/u', $userMessage, $m)) {
        $phone = preg_replace('/\s+/', '', $m[1]) ?? $phone;
    }
    if (mb_strlen(trim($userMessage)) >= 16) {
        $addr = trim($userMessage);
        $first = trim((string) (preg_split('/\d/', $userMessage, 2)[0] ?? ''));
        if ($first !== '' && mb_strlen($first) <= 48) {
            $name = $name !== '' ? $name : $first;
        }
    }
    if ($phone === '' || $addr === '') {
        $raw = $state['raw'];
        $raw['items'] = $state['items'];
        $raw['customer_name'] = $name;
        $raw['customer_phone'] = $phone;
        $raw['shipping_address'] = $addr;
        wa_webhook_cart_save($leadId, $raw);

        return wa_webhook_cart_summary($state['items'])
            . "\n\nTo confirm Cash on Delivery, send your *full name*, *phone*, and *address* in one message.";
    }

    $total = 0.0;
    $cur = 'PKR';
    foreach ($state['items'] as $item) {
        $total += (float) ($item['unit_price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        $cur = (string) ($item['currency'] ?? $cur);
    }
    try {
        $orderId = db_insert(
            'INSERT INTO bot_orders (bot_id, lead_id, user_id, status, total_amount, currency, cod, customer_name, customer_phone, shipping_address, notes)
             VALUES (?, ?, ?, \'new\', ?, ?, 1, ?, ?, ?, ?)',
            'iiidsssss',
            [
                (int) ($bot['id'] ?? 0),
                $leadId,
                (int) ($bot['user_id'] ?? 0),
                $total,
                $cur,
                $name !== '' ? $name : 'WhatsApp customer',
                $phone,
                $addr,
                'WhatsApp webhook order',
            ]
        );
        if ($orderId > 0) {
            foreach ($state['items'] as $item) {
                db_insert(
                    'INSERT INTO bot_order_items (order_id, product_id, product_name, quantity, unit_price)
                     VALUES (?, ?, ?, ?, ?)',
                    'iisid',
                    [
                        $orderId,
                        (int) ($item['product_id'] ?? 0),
                        (string) ($item['name'] ?? 'Item'),
                        max(1, (int) ($item['quantity'] ?? 1)),
                        (float) ($item['unit_price'] ?? 0),
                    ]
                );
            }
        }
        $raw = $state['raw'];
        $raw['items'] = [];
        $raw['customer_name'] = $name;
        $raw['customer_phone'] = $phone;
        $raw['shipping_address'] = $addr;
        wa_webhook_cart_save($leadId, $raw);

        return 'Order #' . $orderId . ' is in — Cash on Delivery.' . "\n"
            . wa_webhook_cart_summary($state['items']) . "\n"
            . 'We will deliver to: ' . $addr;
    } catch (Throwable $e) {
        error_log('wa_webhook_place_order: ' . $e->getMessage());

        return wa_webhook_cart_summary($state['items'])
            . "\n\nI have your cart. Send name, phone, and address again to confirm COD.";
    }
}

/**
 * Webhook mind: history + menu SQL + cart. No OpenAI, no catalog.php, no GD.
 *
 * @param array<string, mixed> $bot
 */
function wa_webhook_mind_reply(array $bot, int $leadId, string $userMessage): string
{
    $rep = function_exists('get_bot_rep_name') ? get_bot_rep_name($bot) : 'I';
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : trim((string) ($bot['company_name'] ?? $bot['name'] ?? 'us'));
    $msg = mb_strtolower(trim((string) (preg_replace('/\s+/u', ' ', $userMessage) ?? $userMessage)));
    $botId = (int) ($bot['id'] ?? 0);
    $total = wa_webhook_product_count($botId);
    $last = mb_strtolower(wa_auto_reply_last_assistant($leadId));
    $state = $leadId > 0
        ? wa_webhook_cart($leadId)
        : ['items' => [], 'raw' => [], 'shown' => [], 'name' => '', 'phone' => '', 'addr' => ''];
    $offset = max(0, (int) ($state['raw']['menu_offset'] ?? 0));
    $products = wa_webhook_products($botId, $offset, 12);
    if ($products === [] && $offset > 0) {
        $offset = 0;
        $products = wa_webhook_products($botId, 0, 12);
    }
    require_once __DIR__ . '/bot-knowledge.php';
    if (!bot_uses_shop_catalog($bot)) {
        $products = [];
        $total = 0;
    }

    $finish = static function (string $line) use ($last, $msg): string {
        $line = trim($line);
        if ($line !== '' && mb_strtolower($line) !== $last) {
            return $line;
        }
        if (preg_match('/\b(friends?|dost|yaar)\b/u', $msg)) {
            return "Yeah — just chatting is fine. What's up?";
        }
        if (preg_match('/\?|\b(what|how|who|introduc|understand)\b/u', $msg)) {
            return "Sorry, say that once more and I'll answer you directly.";
        }

        return "I'm listening. What's on your mind?";
    };

    $savePage = static function (array $state, int $leadId, int $offset, array $products): void {
        if ($leadId <= 0) {
            return;
        }
        $raw = $state['raw'];
        $raw['items'] = $state['items'];
        $raw['customer_name'] = $state['name'];
        $raw['customer_phone'] = $state['phone'];
        $raw['shipping_address'] = $state['addr'];
        $raw['menu_offset'] = $offset;
        $raw['shown_indexes'] = range(1, count($products));
        wa_webhook_cart_save($leadId, $raw);
    };

    $menuBlock = static function (array $page, int $off) use ($bot, $total): string {
        $text = wa_webhook_menu_text($bot, $page);
        if ($total > 12) {
            $from = $off + 1;
            $to = min($total, $off + count($page));
            $text .= "\n\nShowing {$from}–{$to} of {$total}. Say *more* for the next page.";
        }

        return $text;
    };

    $justOrdered = str_contains($last, 'order #')
        || str_contains($last, 'we will deliver')
        || str_contains($last, 'is in —');
    $askedCheckout = !$justOrdered && str_contains($last, 'name')
        && (str_contains($last, 'address') || str_contains($last, 'phone') || str_contains($last, 'cash on delivery'));
    $lastWasMenu = str_contains($last, 'reply with a number')
        || str_contains($last, 'say *more*')
        || str_contains($last, 'say more')
        || str_contains($last, 'showing ')
        || str_contains($last, 'view catalog')
        || str_contains($last, '💰');

    if (preg_match('/\b(are you (a )?bot|you a bot|chatgpt|are you ai|are you (even )?real|are you human)\b/u', $msg)) {
        return $finish("I'm {$rep} from {$brand} — happy to help you personally. What's on your mind?");
    }
    if (preg_match('/\bhow\b.{0,24}\bare\b.{0,24}\byou\b/u', $msg)
        || preg_match('/\b(kaise ho|kia haal|how r u|how about you)\b/u', $msg)
    ) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }
    if (preg_match('/\b(name|who are you|introduc)\b/u', $msg) && !preg_match('/\b(order|cart|menu)\b/u', $msg)) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }
    if (
        (preg_match('/\btell me more\b/u', $msg) || preg_match('/\byou didn\'?t understand\b/u', $msg) || preg_match('/^what\??$/u', $msg))
        && !preg_match('/\b(menu|order|cart)\b/u', $msg)
    ) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }
    if (preg_match('/\b(friends?|dost)\b/u', $msg) && preg_match('/\b(be|ban|my|mera|need|want|just)\b/u', $msg)) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }
    if (preg_match('/\b(good to (hear|see) you|long time|missed you)\b/u', $msg)) {
        return $finish("Good to hear from you too. How have you been?");
    }
    if (preg_match('/\b(taste was good|loved it|delicious|nice food|was good)\b/u', $msg)
        && !preg_match('/\b(menu|what you have|add)\b/u', $msg)
    ) {
        return $finish('Glad it landed. Want something else, or are you set?');
    }
    if ($justOrdered && preg_match('/\b(waiting|wait|on the way|how long|eta|where is my (order|food))\b/u', $msg)) {
        return $finish("Your order is with the kitchen — hang tight. I'll be here if you need anything.");
    }
    if ($justOrdered && preg_match('/\b(thanks|thank you|shukriya|thx)\b/u', $msg)) {
        return $finish("You're welcome. We're on your order now.");
    }
    if (preg_match('/\b(no need|not now|not yet|maybe later|that\'s all|thats all|no thanks|no thank)\b/u', $msg)) {
        return $finish("No problem — I'm here whenever you want to pick this up.");
    }
    if (preg_match('/\b(weather|temperature|rain|forecast)\b/u', $msg)
        || preg_match('/\btell me what\'?s\b/u', $msg)
    ) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }
    if ((str_contains($last, 'how about you') || str_contains($last, 'doing well') || str_contains($last, 'how have you been'))
        && preg_match('/\b(good|fine|great|ok|okay|alhamdulillah|theek|well)\b/u', $msg)
        && mb_strlen($msg) < 48
        && !preg_match('/\b(menu|add|cart|order)\b/u', $msg)
    ) {
        return $finish("Glad to hear it. I'm here if you need anything.");
    }
    if (preg_match('/\b(thanks|thank you|shukriya|thx)\b/u', $msg)
        && mb_strlen($msg) < 40
        && !preg_match('/\b(wait|waiting|order|food|menu)\b/u', $msg)
    ) {
        return $finish('Anytime.');
    }
    if (preg_match('/\b(bye|good night|allah hafiz|khuda hafiz)\b/u', $msg)) {
        return $finish('Take care — message anytime.');
    }
    if (preg_match('/\b(how (can|do) i order|how to order|where (can|do) i (order|buy))\b/u', $msg)
        && !preg_match('/\b(menu|cart|checkout|add)\b/u', $msg)
    ) {
        require_once __DIR__ . '/conversation-mind.php';
        $how = conversation_mind_how_to_order_reply($bot);
        if ($how !== '') {
            return $finish($how);
        }
    }
    if (preg_match('/\b(hi+|hello+|hey+|salam|assalam)\b/u', $msg) && mb_strlen($msg) < 28) {
        require_once __DIR__ . '/bot-knowledge.php';

        return $finish(knowledge_first_greeting($bot));
    }
    if (wa_webhook_is_social($msg) && !preg_match('/\b(menu|cart|checkout|order|price|add)\b/u', $msg)) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }

    $wantsCatalog = wa_webhook_wants_catalog($msg, $bot);
    $wantsPhotos = (bool) preg_match('/\b(photo|photos|image|images|pic|pics|picture|pictures|designed|catalog)\b/u', $msg)
        && (bool) preg_match('/\b(show|send|see|provide|like|professional|whatsapp|menu|item|items|card|cards|view)\b/u', $msg);
    if ($wantsPhotos) {
        $wantsCatalog = true;
    }
    if (!$wantsCatalog
        && !($lastWasMenu && preg_match('/^(more|next|\d{1,2})[.!?]*$/u', $msg))
        && !preg_match('/\badd\b.{0,16}\d/u', $msg)
    ) {
        return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
    }
    $wantsMore = (bool) preg_match('/^(more|next|next page)[.!?]*$/u', $msg)
        || (
            (bool) preg_match('/\b(more|next|another page|other items|rest of|out of these)\b/u', $msg)
            && (bool) preg_match('/\b(item|items|menu|product|products|these|show|page)\b/u', $msg)
            && !preg_match('/\b(how to add|add (this |that |one )?more)\b/u', $msg)
        );
    if ($lastWasMenu && preg_match('/^(more|next)[.!?]*$/u', $msg)) {
        $wantsMore = true;
    }
    $wantsMenu = $wantsCatalog;
    $wantsHelp = (bool) preg_match('/\b(what can you|how can you help|what do you (do|offer)|can do for me)\b/u', $msg)
        || (bool) preg_match('/\bwhat .{0,40}can (you )?do\b/u', $msg);

    if ($wantsPhotos) {
        $GLOBALS['wa_webhook_want_menu_cards'] = true;
        if (function_exists('conversation_flag_shop_menu_send')) {
            conversation_flag_shop_menu_send(true);
        }
        $savePage($state, $leadId, $offset, $products);

        return $finish('I can send the picture cards. Meanwhile pick from this list — reply with a number:' . "\n\n" . $menuBlock($products, $offset));
    }

    if ($wantsMore && $total > 0) {
        $GLOBALS['wa_webhook_want_menu_cards'] = true;
        $next = $offset + 12;
        if ($next >= $total) {
            $next = 0;
        }
        $page = wa_webhook_products($botId, $next, 12);
        $savePage($state, $leadId, $next, $page);
        $products = $page;
        $offset = $next;

        return $finish($next === 0
            ? "That's the full list — starting from the top:\n\n" . $menuBlock($page, $next)
            : $menuBlock($page, $next));
    }

    if ($wantsHelp && !$wantsMenu) {
        require_once __DIR__ . '/bot-knowledge.php';
        $listed = knowledge_offer_list_reply($bot);
        if ($listed !== '') {
            return $finish($listed);
        }
        $offer = wa_webhook_offer($bot);
        $line = "I'm {$rep} at {$brand}.";
        if ($offer !== '') {
            $line .= ' ' . $offer;
        } else {
            $line .= ' What would you like to know?';
        }

        return $finish($line);
    }

    $addIdx = wa_webhook_parse_add_index($msg, $userMessage);
    if (!$lastWasMenu && !preg_match('/\badd\b|#\s*\d/u', $msg)) {
        $addIdx = null;
    }
    if ($addIdx === null && $lastWasMenu && preg_match('/^(\d{1,2})$/u', $msg, $m)) {
        $addIdx = (int) $m[1];
    }
    if ($leadId > 0 && $products !== [] && $addIdx !== null && $addIdx > 0) {
        $shown = $state['shown'] !== [] ? $state['shown'] : range(1, count($products));
        $pos = $addIdx;
        if (isset($shown[$addIdx - 1])) {
            $pos = (int) $shown[$addIdx - 1];
        }
        if (isset($products[$pos - 1])) {
            return $finish(wa_webhook_add_product($leadId, $products[$pos - 1]));
        }
        if (isset($products[$addIdx - 1])) {
            return $finish(wa_webhook_add_product($leadId, $products[$addIdx - 1]));
        }
    }
    if ($leadId > 0 && $products !== []) {
        foreach ($products as $product) {
            $name = mb_strtolower(trim((string) ($product['name'] ?? '')));
            if ($name !== '' && mb_strlen($name) >= 6 && preg_match('/\b' . preg_quote($name, '/') . '\b/u', $msg)) {
                return $finish(wa_webhook_add_product($leadId, $product));
            }
        }
    }

    if ($leadId > 0 && $botId > 0 && preg_match_all('/add\s+(\d+)\s+sku:(\S+)/u', $msg, $skuHits, PREG_SET_ORDER)) {
        $bits = [];
        foreach ($skuHits as $hit) {
            $found = wa_webhook_product_by_sku($botId, (string) $hit[2]);
            if ($found) {
                $bits[] = wa_webhook_add_product($leadId, $found, (int) $hit[1]);
            }
        }
        if ($bits !== []) {
            return $finish(implode("\n\n", $bits));
        }
    }
    if ($leadId > 0 && $botId > 0 && !$wantsMenu && !$wantsMore && !$wantsHelp
        && !wa_webhook_is_social($msg)
        && !preg_match('/^(more|next|cart|checkout|hi|hey|hello|thanks)\b/u', $msg)
        && !str_contains($msg, '?')
        && !preg_match('/\b(what|how|why|when|where|tell me|weather|waiting|anytime)\b/u', $msg)
    ) {
        $q = trim((string) (preg_replace(
            '/\b(add|please|pls|want|i want|give me|send|show me|the|a|an|one|also)\b/iu',
            ' ',
            $userMessage
        ) ?? $userMessage));
        $q = trim((string) (preg_replace('/\s+/u', ' ', $q) ?? $q));
        if (mb_strlen($q) >= 3) {
            $hits = wa_webhook_search_products($botId, $q, 8);
            if (count($hits) === 1) {
                return $finish(wa_webhook_add_product($leadId, $hits[0]));
            }
            if (count($hits) > 1) {
                $savePage($state, $leadId, 0, $hits);

                return $finish($menuBlock($hits, 0) . "\n\nThese match what you typed. Reply with a number or a clearer name.");
            }
        }
    }

    if ($wantsMenu && $products !== []) {
        $filtered = wa_webhook_filter_products_for_message($botId, $userMessage, $products);
        if ($filtered !== []) {
            $products = $filtered;
            $offset = 0;
        }
        $GLOBALS['wa_webhook_want_menu_cards'] = true;
        $savePage($state, $leadId, $offset, $products);
        $leadIn = preg_match('/\b(taste was good|loved it|was good)\b/u', $msg) ? 'Glad it landed. ' : '';

        return $finish($leadIn . $menuBlock($products, $offset));
    }

    if ($leadId > 0 && preg_match('/^(cart|my cart|basket)$/u', $msg)) {
        return $finish($state['items'] === [] ? 'Cart is empty — say *menu* or name an item.' : wa_webhook_cart_summary($state['items']));
    }
    if ($leadId > 0 && preg_match('/^(checkout|place order|confirm order)$/u', $msg)) {
        if ($state['items'] === []) {
            return $finish('Nothing in the cart yet. Say *menu* and pick an item.');
        }

        return $finish(wa_webhook_cart_summary($state['items']) . "\n\nSend your full name, phone, and address for Cash on Delivery.");
    }

    if ($leadId > 0 && $state['items'] !== [] && $askedCheckout && wa_webhook_looks_like_delivery($userMessage)) {
        $placed = wa_webhook_place_order($bot, $leadId, $userMessage);
        if ($placed !== null) {
            return $finish($placed);
        }
    }
    if ($leadId > 0 && $state['items'] !== [] && !$askedCheckout && wa_webhook_looks_like_delivery($userMessage)
        && !wa_webhook_is_social($msg)
    ) {
        $placed = wa_webhook_place_order($bot, $leadId, $userMessage);
        if ($placed !== null) {
            return $finish($placed);
        }
    }

    if (preg_match('/\b(price|how much|cost|rate)\b/u', $msg)
        && !preg_match('/\b(weather|waiting|anytime)\b/u', $msg)
    ) {
        if ($products !== []) {
            $savePage($state, $leadId, $offset, $products);

            return $finish($menuBlock($products, $offset));
        }
        require_once __DIR__ . '/bot-knowledge.php';
        $price = knowledge_price_from_training($bot);
        if ($price !== '') {
            return $finish($price);
        }
        $listed = knowledge_offer_list_reply($bot);
        if ($listed !== '') {
            return $finish($listed);
        }

        return $finish("I don't have a specific price for that in the information I have. I can share what we do offer.");
    }

    require_once __DIR__ . '/bot-knowledge.php';
    if (knowledge_message_is_offer_question($userMessage)) {
        $listed = knowledge_offer_list_reply($bot);
        if ($listed !== '') {
            return $finish($listed);
        }
    }

    return $finish(wa_webhook_friend_talk($bot, $leadId, $userMessage, $msg));
}

function wa_webhook_instant_reply(array $bot, int $leadId, string $userMessage): string
{
    return wa_webhook_mind_reply($bot, $leadId, $userMessage);
}

/**
 * Compose reply: cart tools, then OpenAI with memory. Always returns a sendable line.
 *
 * @param array<string, mixed> $bot
 * @return array{reply: string, path: string}
 */
function wa_auto_reply_compose(array $bot, int $leadId, string $userMessage, int $turnId = 0, bool $allowHuman = true): array
{
    if (!$allowHuman) {
        return ['reply' => wa_auto_reply_safe_fallback($bot, $leadId, $userMessage), 'path' => 'core_fallback'];
    }

    if (!empty($GLOBALS['wa_webhook_budget'])) {
        wa_auto_reply_persist_inbound($leadId, $userMessage);

        require_once __DIR__ . '/agent-core/bootstrap.php';
        if (agent_core_enabled($bot)) {
            require_once __DIR__ . '/agent-core/agent-core.php';
            try {
                $core = agent_core_channel_try($bot, $leadId, $userMessage, $turnId, 'whatsapp');
                if (agent_core_result_usable($core)) {
                    return ['reply' => trim((string) $core['reply']), 'path' => 'agent_core'];
                }
            } catch (Throwable $coreErr) {
                error_log('wa_auto_reply_compose agent_core #' . $turnId . ': ' . $coreErr->getMessage());
            }
        }

        return ['reply' => wa_webhook_mind_reply($bot, $leadId, $userMessage), 'path' => 'webhook_mind'];
    }

    require_once __DIR__ . '/whatsapp-human-layer.php';
    require_once __DIR__ . '/conversation-media.php';
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/whatsapp-shop-ux.php';

    wa_auto_reply_persist_inbound($leadId, $userMessage);

    try {
        $cartReply = wa_human_layer_try_cart_reply($bot, $leadId, $userMessage);
        if ($cartReply !== null && trim($cartReply) !== '') {
            return ['reply' => trim($cartReply), 'path' => 'human_cart'];
        }
    } catch (Throwable $e) {
        error_log('wa_auto_reply_compose cart #' . $turnId . ': ' . $e->getMessage());
    }

    if (function_exists('whatsapp_shop_customer_wants_visual_card')
        && function_exists('conversation_flag_shop_menu_send')
        && whatsapp_shop_customer_wants_visual_card($userMessage)
    ) {
        conversation_flag_shop_menu_send(true);
    } elseif (function_exists('catalog_message_is_menu_request')
        && function_exists('conversation_flag_shop_menu_send')
        && catalog_message_is_menu_request((int) ($bot['id'] ?? 0), $userMessage)
    ) {
        conversation_flag_shop_menu_send(true);
    }

    $last = mb_strtolower(wa_auto_reply_last_assistant($leadId));

    if (!wa_human_layer_enabled()) {
        return ['reply' => wa_auto_reply_safe_fallback($bot, $leadId, $userMessage), 'path' => 'core_fallback'];
    }

    $allowOpenai = empty($GLOBALS['wa_skip_openai']);

    try {
        if ($allowOpenai) {
            $ai = wa_human_openai_reply($leadId, $bot, $userMessage !== '' ? $userMessage : '[Customer sent a message]', $turnId);
            if ($ai !== null && trim($ai) !== '' && mb_strtolower(trim($ai)) !== $last) {
                return ['reply' => trim($ai), 'path' => 'human_openai'];
            }
        }

        $warm = wa_human_warm_reply($bot, $leadId, $userMessage);
        if ($warm !== null && trim($warm) !== '' && mb_strtolower(trim($warm)) !== $last) {
            return ['reply' => trim($warm), 'path' => $allowOpenai ? 'human_warm' : 'human_warm_after_wait'];
        }
    } catch (Throwable $e) {
        error_log('wa_auto_reply_compose human #' . $turnId . ': ' . $e->getMessage());
        $warm = wa_human_warm_reply($bot, $leadId, $userMessage);
        if ($warm !== null && trim($warm) !== '' && mb_strtolower(trim($warm)) !== $last) {
            return ['reply' => trim($warm), 'path' => 'human_warm_after_error'];
        }
    }

    return ['reply' => wa_auto_reply_safe_fallback($bot, $leadId, $userMessage), 'path' => 'core_fallback'];
}

function wa_auto_reply_turn_is_text_only(int $turnId): bool
{
    if ($turnId <= 0) {
        return true;
    }

    $row = db_fetch(
        'SELECT COUNT(*) AS c FROM conversation_turn_messages
         WHERE turn_id = ? AND message_type IS NOT NULL AND message_type <> \'text\'',
        'i',
        [$turnId]
    );

    return (int) ($row['c'] ?? 0) === 0;
}

/**
 * Resolve customer message text for a turn (merged bubbles when turn engine available).
 */
function wa_auto_reply_turn_text(int $turnId, int $leadId = 0): string
{
    wa_auto_reply_require_recover_lite();

    if (empty($GLOBALS['wa_webhook_budget']) && $leadId > 0 && function_exists('wa_recover_lead_user_text')) {
        $text = wa_recover_lead_user_text($leadId, $turnId);
        if ($text !== '') {
            return $text;
        }
    }

    if ($turnId > 0 && function_exists('turn_engine_build_turn_payload')) {
        try {
            $payload = turn_engine_build_turn_payload($turnId);
            $combined = trim((string) ($payload['combined'] ?? ''));
            if ($combined !== '') {
                return $combined;
            }
        } catch (Throwable $e) {
            error_log('wa_auto_reply_turn_text payload #' . $turnId . ': ' . $e->getMessage());
        }
    }

    return wa_recover_turn_text($turnId);
}

/**
 * Deliver one turn — Meta send + DB persist. Human layer optional; core always wins on failure.
 *
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $bot
 * @return array{ok: bool, turn_id: int, lead_id: int, reply?: string, error?: string, path?: string}
 */
function wa_auto_reply_deliver_turn(array $turn, array $bot, string $phoneId, string $token, bool $allowHuman = true): array
{
    wa_auto_reply_require_recover_lite();

    $turnId = (int) ($turn['id'] ?? 0);
    $leadId = (int) ($turn['lead_id'] ?? 0);
    $out = ['ok' => false, 'turn_id' => $turnId, 'lead_id' => $leadId];

    if ($turnId <= 0 || $leadId <= 0) {
        $out['error'] = 'invalid turn';

        return $out;
    }

    if (wa_recover_response_sent($turnId)) {
        $out['ok'] = true;
        $out['path'] = 'already_sent';

        return $out;
    }

    if (empty($GLOBALS['wa_reply_lock_held'][$leadId])
        && function_exists('whatsapp_acquire_lead_reply_lock')
        && !whatsapp_acquire_lead_reply_lock($leadId, 0)
    ) {
        $out['ok'] = true;
        $out['path'] = 'lock_busy';

        return $out;
    }

    $releaseLock = empty($GLOBALS['wa_reply_lock_held'][$leadId]);

    try {
        $sender = trim((string) ($turn['sender_phone'] ?? ''));
        if ($sender === '') {
            $lead = db_fetch('SELECT phone, whatsapp_id FROM leads WHERE id = ?', 'i', [$leadId]);
            $sender = trim((string) ($lead['phone'] ?? $lead['whatsapp_id'] ?? ''));
        }
        if ($sender === '' || $phoneId === '' || $token === '') {
            $out['error'] = 'missing sender or whatsapp creds';

            return $out;
        }

        if ((int) ($bot['whatsapp_auto_reply'] ?? 1) !== 1) {
            $out['error'] = 'auto_reply_off';

            return $out;
        }

        if ($turnId > 0 && function_exists('turn_engine_log_event')) {
            try {
                db_execute(
                    'UPDATE conversation_turns SET status = \'processing\', processing_started_at = NOW() WHERE id = ? AND status = \'buffering\'',
                    'i',
                    [$turnId]
                );
                turn_engine_log_event($turnId, 'AI_GENERATION_STARTED', ['path' => 'auto_reply_core']);
            } catch (Throwable $e) {
                error_log('wa_auto_reply_deliver_turn start #' . $turnId . ': ' . $e->getMessage());
            }
        }

        if (empty($GLOBALS['wa_webhook_budget'])) {
            wa_auto_reply_human_ux_before_send($turnId, $leadId, $phoneId, $token);
        }

        require_once __DIR__ . '/agent-core/bootstrap.php';
        require_once __DIR__ . '/agent-core/media.php';
        $agentCoreOn = agent_core_enabled($bot);
        if (($agentCoreOn || empty($GLOBALS['wa_webhook_budget']))
            && $turnId > 0
            && !wa_auto_reply_turn_is_text_only($turnId)
        ) {
            $mediaBudget = !empty($GLOBALS['wa_webhook_budget']) ? 3.0 : 8.0;
            $GLOBALS['wa_media_deadline'] = microtime(true) + $mediaBudget;
            try {
                agent_core_media_enrich($turnId, $token);
            } catch (Throwable $e) {
                error_log('wa_auto_reply_deliver_turn media #' . $turnId . ': ' . $e->getMessage());
            }
            unset($GLOBALS['wa_media_deadline']);
        }

        $userText = wa_auto_reply_turn_text($turnId, $leadId);
        try {
            $composed = wa_auto_reply_compose($bot, $leadId, $userText, $turnId, $allowHuman);
        } catch (Throwable $composeErr) {
            error_log('wa_auto_reply_deliver_turn compose #' . $turnId . ': ' . $composeErr->getMessage());
            $composed = [
                'reply' => !empty($GLOBALS['wa_webhook_budget'])
                    ? wa_webhook_mind_reply($bot, $leadId, $userText)
                    : wa_auto_reply_safe_fallback($bot, $leadId, $userText),
                'path'  => 'core_fallback_compose_error',
            ];
        }
        $reply = $composed['reply'];
        $path = $composed['path'];
        if (function_exists('conversation_mind_guard_reply') || is_file(__DIR__ . '/conversation-mind.php')) {
            require_once __DIR__ . '/conversation-mind.php';
            $reply = conversation_mind_guard_reply($bot, $leadId, $userText, $reply);
        }

        if (function_exists('turn_engine_must_send_bag') && turn_engine_must_send_is_armed()) {
            $bag = turn_engine_must_send_bag();
            if (is_array($bag)) {
                $bag['text'] = $reply;
                turn_engine_must_send_bag($bag);
            }
        }

        $typeWaId = '';
        if (function_exists('turn_engine_latest_wa_message_id')) {
            $typeWaId = turn_engine_latest_wa_message_id($leadId);
        }
        if ($typeWaId === '' && function_exists('turn_engine_build_turn_payload')) {
            try {
                $payload = turn_engine_build_turn_payload($turnId);
                $waIds = $payload['wa_message_ids'] ?? [];
                $typeWaId = $waIds !== [] ? (string) $waIds[count($waIds) - 1] : '';
            } catch (Throwable $ignored) {
            }
        }
        if ($typeWaId !== '' && function_exists('whatsapp_send_typing_indicator')) {
            try {
                whatsapp_send_typing_indicator($phoneId, $token, $typeWaId);
            } catch (Throwable $ignored) {
            }
        }

        $sent = wa_recover_send_whatsapp($phoneId, $token, $sender, $reply);
        if (function_exists('turn_engine_mark_must_send_done')) {
            turn_engine_mark_must_send_done();
        }
        if (empty($sent['success'])) {
            db_execute(
                'UPDATE conversation_turns SET status = \'failed\', suppression_reason = ?, processing_completed_at = NOW() WHERE id = ?',
                'si',
                [mb_substr((string) ($sent['message'] ?? 'send_failed'), 0, 200), $turnId]
            );
            $out['error'] = (string) ($sent['message'] ?? 'send failed');

            return $out;
        }

        try {
            wa_auto_reply_persist($turnId, $leadId, $userText, $reply);
            db_execute(
                'UPDATE conversation_turns SET status = \'completed\', ai_response_text = ?,
                 processing_completed_at = NOW(), suppression_reason = ? WHERE id = ?',
                'ssi',
                [$reply, $path, $turnId]
            );
            wa_recover_log_event($turnId, 'RESPONSE_SENT', ['path' => $path, 'layer' => 'auto_reply_core']);
            wa_recover_close_lead_turns($leadId, $turnId, $path, $reply);
            if (is_file(__DIR__ . '/conversation-runtime-memory.php')) {
                try {
                    require_once __DIR__ . '/conversation-runtime-memory.php';
                    conversation_runtime_remember_after_send($bot, $leadId, $userText, $reply);
                } catch (Throwable $memErr) {
                    error_log('iqp_memory: after_send ' . $memErr->getMessage());
                }
            }
            if (function_exists('whatsapp_webhook_log_event')) {
                whatsapp_webhook_log_event('Auto-reply delivered', [
                    'turn_id' => $turnId,
                    'lead_id' => $leadId,
                    'path'    => $path,
                ]);
            }
        } catch (Throwable $e) {
            error_log('wa_auto_reply_deliver_turn persist #' . $turnId . ': ' . $e->getMessage());
            try {
                wa_recover_log_event($turnId, 'RESPONSE_SENT', [
                    'path'          => $path,
                    'layer'         => 'auto_reply_core',
                    'persist_error' => $e->getMessage(),
                ]);
            } catch (Throwable $ignored) {
            }
            $out['ok'] = true;
            $out['reply'] = $reply;
            $out['path'] = $path;
            $out['warning'] = 'persist_failed';

            return $out;
        }

        if ($allowHuman && !empty($GLOBALS['wa_webhook_budget']) && !empty($GLOBALS['wa_webhook_want_menu_cards'])) {
            try {
                wa_webhook_send_browse_ui($bot, $phoneId, $token, $sender, $leadId);
            } catch (Throwable $e) {
                error_log('wa_webhook_send_browse_ui #' . $turnId . ': ' . $e->getMessage());
            }
        } elseif ($allowHuman && empty($GLOBALS['wa_webhook_budget'])) {
            $t0 = (float) ($GLOBALS['wa_webhook_t0'] ?? 0);
            $stillTime = $t0 <= 0 || (microtime(true) - $t0) < 18;
            if ($stillTime) {
                try {
                    require_once __DIR__ . '/whatsapp-human-layer.php';
                    if (function_exists('wa_human_layer_after_send')) {
                        wa_human_layer_after_send($bot, $leadId, $sender, $userText, $reply, $phoneId, $token);
                    }
                } catch (Throwable $e) {
                    error_log('wa_human_layer_after_send #' . $turnId . ': ' . $e->getMessage());
                }
            }
        }

        $out['ok'] = true;
        $out['reply'] = $reply;
        $out['path'] = $path;

        return $out;
    } finally {
        if (!empty($releaseLock) && function_exists('whatsapp_release_lead_reply_lock')) {
            whatsapp_release_lead_reply_lock($leadId);
        }
    }
}

/** Typing + read receipts — human UX only; failures ignored. */
function wa_auto_reply_human_ux_before_send(int $turnId, int $leadId, string $phoneId, string $token): void
{
    if ($phoneId === '' || $token === '' || !function_exists('turn_engine_build_turn_payload')) {
        return;
    }

    try {
        $payload = turn_engine_build_turn_payload($turnId);
        $waIds = $payload['wa_message_ids'] ?? [];
        $primaryWaId = $waIds !== [] ? (string) $waIds[count($waIds) - 1] : '';
        if ($primaryWaId === '') {
            return;
        }
        if (function_exists('whatsapp_mark_message_read')) {
            whatsapp_mark_message_read($phoneId, $token, $primaryWaId);
        }
        if (function_exists('whatsapp_pre_reply_typing')) {
            whatsapp_pre_reply_typing($phoneId, $token, $primaryWaId, 1200);
        }
    } catch (Throwable $e) {
        error_log('wa_auto_reply_human_ux_before_send #' . $turnId . ': ' . $e->getMessage());
    }
}

function wa_auto_reply_persist(int $turnId, int $leadId, string $userText, string $reply): void
{
    wa_auto_reply_require_recover_lite();

    if (function_exists('turn_engine_build_turn_payload') && function_exists('conversation_insert')) {
        try {
            $payload = turn_engine_build_turn_payload($turnId);
            $combined = trim((string) ($payload['combined'] ?? $userText));
            if ($combined !== '') {
                $lastRow = db_fetch(
                    'SELECT message FROM conversations WHERE lead_id = ? AND role = \'user\' ORDER BY id DESC LIMIT 1',
                    'i',
                    [$leadId]
                );
                $lastUser = trim((string) ($lastRow['message'] ?? ''));
                if ($lastUser !== $combined) {
                    conversation_insert(
                        $leadId,
                        'user',
                        $combined,
                        $payload['media_type'] ?? null,
                        $payload['media_url'] ?? null
                    );
                }
            }
            if (function_exists('conversation_store_sent_assistant_reply')) {
                conversation_store_sent_assistant_reply($leadId, $reply);
            }
            $waIds = $payload['wa_message_ids'] ?? [];
            if ($waIds !== [] && function_exists('whatsapp_mark_many_inbound_replied')) {
                whatsapp_mark_many_inbound_replied($waIds);
            }

            return;
        } catch (Throwable $e) {
            error_log('wa_auto_reply_persist rich #' . $turnId . ': ' . $e->getMessage());
        }
    }

    wa_recover_persist_chat($leadId, $userText, $reply);
}
