<?php
/**
 * Turn buffering + catalog guards only.
 * All customer-facing replies go through AI (no canned per-question scripts here).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function conversation_normalize_intent_text(string $message): string
{
    $message = conversation_strip_internal_directives($message);
    $message = preg_replace('/[\r\n]+/u', ' ', $message) ?? $message;
    $message = preg_replace('/\s{2,}/u', ' ', $message) ?? $message;

    return trim($message);
}

/** Chat typos so "how are uou" / "how r u" still count as the same thought. */
function conversation_normalize_casual_typos(string $message): string
{
    $text = mb_strtolower(conversation_normalize_intent_text($message));
    if ($text === '') {
        return '';
    }

    $map = [
        '/\b(uou|yuo|yu|u)\b/u' => 'you',
        '/\b(r)\b/u'            => 'are',
        '/\b(hwo|hw)\b/u'       => 'how',
        '/\b(wher|wer)\b/u'     => 'where',
        '/\bur\b/u'             => 'your',
    ];

    foreach ($map as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }

    return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
}

function conversation_is_identity_question(string $message): bool
{
    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match(
        '/didn\'?t ask.{0,20}name|not (asking|ask).{0,12}name|i didn\'?t ask|haven\'?t read|'
        . 'you haven\'?t read/u',
        $lower
    )) {
        return false;
    }

    if (preg_match(
        '/\b(your name|what(?:\'?s| is) your name|what are you called|who are you|who is this|'
        . 'what(?:\'?s| is) ur name|may i know your name|tell me your name|naam|aap ka naam|ap ka naam|'
        . 'tumhara naam|what should i call you)\b/u',
        $lower
    )) {
        return true;
    }

    if (preg_match('/^(you|your)\s*\??$/iu', $lower)) {
        return true;
    }

    if (preg_match('/^(you|your)\s*\??$/iu', $lower)) {
        return true;
    }

    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));
    $identityTokens = ['what', 'is', 'your', 'name', 'who', 'are', 'you', 'ur', 'naam', 'aap', 'ap', 'ka'];
    if ($words !== [] && count($words) <= 6) {
        $allIdentity = true;
        foreach ($words as $word) {
            if (!in_array($word, $identityTokens, true)) {
                $allIdentity = false;
                break;
            }
        }
        if ($allIdentity && in_array('name', $words, true)) {
            return true;
        }
    }

    return false;
}

function conversation_is_meta_activity_question(string $message): bool
{
    $lower = mb_strtolower(conversation_normalize_intent_text($message));

    return (bool) preg_match(
        '/\b(what are you doing|what(?:\'?re| are) you doing|what you doing|'
        . 'what do you do(?: right)? now|what(?:\'?re| are) you up to|'
        . 'are you a (bot|robot|human|person|real))\b/u',
        $lower
    );
}

function conversation_is_location_question(string $message): bool
{
    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    return (bool) preg_match(
        '/\b(where are you|where(?:\'?re| are) you|where you at|where r u|where are u|'
        . 'where are you based|where are you located|your location|which city are you in|'
        . 'where is (the |your )?(restaurant|shop|store|clinic|office|branch)|your address)\b/u',
        $lower
    );
}

function conversation_is_hours_question(string $message): bool
{
    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    return (bool) preg_match(
        '/\b(timings?|opening hours|operating hours|business hours|office hours|'
        . 'what time (?:do you|are you|at)|what time.{0,24}(open|close|location)|'
        . 'when (?:are you|do you) (?:open|close)|'
        . 'kis time|kitne baje|open (?:till|until|from)|close at)\b/u',
        $lower
    );
}

function conversation_is_wellbeing_question(string $message): bool
{
    if (conversation_is_location_question($message)) {
        return false;
    }

    $lower = conversation_normalize_casual_typos($message);
    if ($lower === '') {
        return false;
    }

    // Bare "are you?" is a fragment (usually "where are you?" or "how are you?") — not a check-in.
    if (preg_match('/^are\s+you\s*\??$/iu', $lower)) {
        return false;
    }

    if (preg_match(
        '/\b(how are you|how r u|how\'?re you|how\'?s it going|how do you do|how have you been|kaise ho|kia haal)\b/u',
        $lower
    )) {
        return true;
    }

    return (bool) preg_match('/\b(you good|are you ok|are you okay|are you there)\b/u', $lower);
}

/** Short presence pings after the quiet window — "are you?" / "you there?" */
function conversation_is_presence_ping(string $message): bool
{
    $lower = conversation_normalize_casual_typos($message);
    if ($lower === '') {
        return false;
    }

    return (bool) preg_match(
        '/^(?:hey\s+|hi\s+|hello\s+)*(?:are you|you there|anyone there|hello\?+)\s*\??$/iu',
        $lower
    );
}

/**
 * Customer still typing "where / are / you" across bubbles.
 */
function conversation_is_building_location_burst(string $message, int $textPartCount = 0): bool
{
    if ($textPartCount < 2) {
        return false;
    }

    if (conversation_is_location_question($message)) {
        return false;
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));

    if (in_array('where', $words, true) && !conversation_is_location_question($message)) {
        return true;
    }

    if (in_array('are', $words, true) && in_array('you', $words, true)
        && !preg_match('/\bhow\s+are\s+you\b/u', $lower)
        && !conversation_is_location_question($message)
    ) {
        return true;
    }

    return false;
}

/**
 * Customer still typing "how / are / you" across bubbles.
 */
function conversation_is_building_wellbeing_burst(string $message, int $textPartCount = 0): bool
{
    if ($textPartCount < 2) {
        return false;
    }

    if (conversation_is_wellbeing_question($message)) {
        return false;
    }

    $lower = conversation_normalize_casual_typos($message);
    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));

    return in_array('how', $words, true) && (in_array('are', $words, true) || in_array('you', $words, true));
}

/**
 * Turn contains more than one distinct question (offer + location, etc.).
 */
function conversation_has_multiple_intents(string $message): bool
{
    require_once __DIR__ . '/bot-knowledge.php';

    $count = 0;
    if (knowledge_message_is_offer_question($message)) {
        $count++;
    }
    if (conversation_is_location_question($message)) {
        $count++;
    }
    if (conversation_is_wellbeing_question($message)) {
        $count++;
    }
    if (conversation_is_identity_question($message)) {
        $count++;
    }
    if (conversation_is_meta_activity_question($message)) {
        $count++;
    }

    return $count >= 2;
}

function conversation_is_bot_frustration(string $message): bool
{
    require_once __DIR__ . '/bot-knowledge.php';
    $lower = mb_strtolower(conversation_normalize_intent_text($message));

    return (bool) preg_match(
        '/\b(i asked|i said|you didn\'?t answer|didn\'?t answer|not about product|wrong answer|'
        . 'stop sending|that(?:\'?s| is) not what|didn\'?t ask for|didn\'?t ask your name|not what i asked|'
        . 'going to the products?|not replying|reading but not|why aren\'?t you replying|you are not|'
        . 'you\'?re not|misunderstood|silent\??|waiting for you|i am waiting|you are confused|'
        . 'you\'?re confused|don\'?t talk to me|stop talking|haven\'?t read|you haven\'?t read|'
        . 'replying something else|said something (else|different)|already (sent|received)|'
        . 'order is not being processed|not process(ed)? it)\b/u',
        $lower
    );
}

function conversation_is_likely_message_fragment(string $message): bool
{
    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    if (conversation_is_identity_question($message) || conversation_is_meta_activity_question($message)) {
        return false;
    }
    if (conversation_is_wellbeing_question($message) || conversation_is_location_question($message)) {
        return false;
    }

    require_once __DIR__ . '/bot-knowledge.php';
    if (knowledge_message_is_offer_question($message)) {
        return false;
    }

    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));
    if ($words === [] || count($words) > 5) {
        return false;
    }

    if (in_array('menu', $words, true) || in_array('catalog', $words, true) || in_array('catalogue', $words, true)) {
        return false;
    }

    if (in_array('offer', $words, true) || in_array('offers', $words, true) || in_array('services', $words, true)) {
        return false;
    }

    $fragmentWords = [
        'what', 'is', 'your', 'name', 'who', 'are', 'you', 'doing', 'right', 'now', 'the', 'a', 'an', 'my',
        'how', 'where', 'when', 'why', 'can', 'do', 'did', 'hello', 'hey', 'hi', 'ur', 'its', "it's", 'tell', 'me',
        'will', 'be', 'cost', 'price', 'know', 'about', 'were', 'gone', 'much',
    ];

    foreach ($words as $word) {
        if (!in_array($word, $fragmentWords, true) && mb_strlen($word) > 4) {
            return false;
        }
    }

    return count($words) <= 4;
}

function conversation_is_semantic_partial_question(string $message): bool
{
    require_once __DIR__ . '/bot-knowledge.php';

    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    if (conversation_is_identity_question($message)
        || conversation_is_location_question($message)
        || (conversation_is_wellbeing_question($message) && !conversation_is_building_wellbeing_burst($message, 0))
        || conversation_is_meta_activity_question($message)
        || (knowledge_message_is_offer_question($message) && !conversation_is_building_location_burst($message, 0))
    ) {
        return false;
    }

    if (preg_match('/^[?!.]+$/u', $lower)) {
        return true;
    }

    if (preg_match('/^(you|your|what|how|where|tell|me|are|who|okay|ok|thanks?|thank|now|good|hear|doing|right|hey|hi|will|be|the|cost|price)$/iu', $lower)) {
        return true;
    }

    if (conversation_is_incomplete_fragment_burst($message, 0)) {
        return true;
    }

    if (preg_match('/\b(tell me what|what are you|what do you|what you|what are|tell me)\b/u', $lower)
        && !preg_match('/\b(offer(?:ing)?|provid(?:e|ing)?|sell(?:ing)?|name|price|cost|help|services?|products?)\b/u', $lower)
    ) {
        return true;
    }

    return false;
}

/**
 * All words are short burst tokens — customer still typing (What / Will / Be / The / Cost).
 */
function conversation_is_incomplete_fragment_burst(string $message, int $textPartCount = 0): bool
{
    if ($textPartCount > 0 && $textPartCount < 2) {
        return false;
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match('/\b(how are you|what is your name|what do you offer|what you offer|where are you|'
        . 'what will be the cost|what(?:\'?s| is) the (cost|price)|how much)\b/u', $lower)) {
        return false;
    }

    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));
    if ($words === [] || count($words) > 8) {
        return false;
    }

    $burstWords = [
        'what', 'is', 'your', 'name', 'who', 'are', 'you', 'doing', 'right', 'now', 'the', 'a', 'an', 'my',
        'how', 'where', 'when', 'why', 'can', 'do', 'did', 'tell', 'me', 'will', 'be', 'cost', 'price', 'much',
        'know', 'about', 'offer', 'hey', 'hi', 'hello', 'were', 'gone', 'was', 'silent',
    ];

    foreach ($words as $word) {
        if (!in_array($word, $burstWords, true) && mb_strlen($word) > 4) {
            return false;
        }
    }

    if (in_array('cost', $words, true) || in_array('price', $words, true)) {
        return !preg_match('/\?$/u', $lower) && !in_array('?', $words, true);
    }

    if (in_array('what', $words, true) && count($words) >= 2) {
        return true;
    }

    return count($words) <= 3;
}

function conversation_is_building_question_burst(string $message, int $textPartCount = 0): bool
{
    if ($textPartCount < 2) {
        return false;
    }

    if (conversation_is_incomplete_fragment_burst($message, $textPartCount)) {
        return true;
    }

    require_once __DIR__ . '/bot-knowledge.php';

    if (conversation_is_identity_question($message)
        || conversation_is_meta_activity_question($message)
        || conversation_is_bot_frustration($message)
    ) {
        return false;
    }

    if (knowledge_message_is_offer_question($message)) {
        if (!conversation_is_building_location_burst($message, $textPartCount)
            && !conversation_is_building_wellbeing_burst($message, $textPartCount)
        ) {
            return false;
        }
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));

    $completionWords = [
        'offer', 'offering', 'providing', 'provide', 'name', 'price', 'cost', 'sell', 'services', 'service',
        'products', 'product', 'available', 'catalog', 'help',
    ];
    $hasCompletion = false;
    foreach ($completionWords as $word) {
        if (in_array($word, $words, true)) {
            $hasCompletion = true;
            break;
        }
    }

    if (in_array('tell', $words, true) && in_array('me', $words, true) && !$hasCompletion) {
        return true;
    }

    if (in_array('what', $words, true) && in_array('you', $words, true) && !$hasCompletion && count($words) <= 4) {
        return true;
    }

    if (in_array('your', $words, true) && in_array('name', $words, true) && count($words) < 4) {
        return true;
    }

    if (in_array('you', $words, true) && count($words) <= 2 && !$hasCompletion) {
        return true;
    }

    if (in_array('where', $words, true) && !preg_match('/\bwhere\s+are\s+you\b/u', $lower)) {
        return true;
    }

    return false;
}

function conversation_should_hold_turn_for_more_input(string $message, int $textPartCount = 0): bool
{
    // v2: related bubbles are aggregated by debounce / max-window only.
    // Semantic hold left complete "how are you" / "Hey Hi" waiting forever (2 ticks, no reply).
    unset($message, $textPartCount);

    return false;
}

function conversation_is_personal_chat(string $message): bool
{
    require_once __DIR__ . '/helpers.php';

    if (function_exists('conversation_is_presence_ping') && conversation_is_presence_ping($message)) {
        return true;
    }

    return conversation_is_casual_chat($message);
}

/** Small talk / check-ins — no catalog, no industry pitch. */
function conversation_is_general_chat(string $message): bool
{
    require_once __DIR__ . '/bot-knowledge.php';
    if (knowledge_message_is_offer_question($message)) {
        return false;
    }

    if (conversation_should_skip_catalog_routing($message)) {
        return true;
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return true;
    }

    if (preg_match('/\b(thank you|thanks|shukriya|bye|goodbye|good night|ok|okay|hmm+|haha|lol)\b/u', $lower)
        && mb_strlen($lower) < 48
    ) {
        require_once __DIR__ . '/bot-knowledge.php';
        if (!knowledge_message_is_offer_question($message)) {
            return true;
        }
    }

    return false;
}

/** Products, prices, packages, catalog — use the real industry offer. */
function conversation_wants_commercial_context(string $message): bool
{
    if (conversation_is_general_chat($message)) {
        return false;
    }

    require_once __DIR__ . '/bot-knowledge.php';
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/helpers.php';

    if (knowledge_message_is_offer_question($message)
        || knowledge_user_wants_detailed_answer($message)
        || catalog_has_clear_shopping_intent($message)
        || catalog_message_is_browse_intent($message)
        || conversation_has_buying_signal($message)
    ) {
        return true;
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($message));

    return (bool) preg_match(
        '/\b(price|cost|how much|package|packages|plan|plans|quote|order|book|'
        . 'appointment|menu|catalog|catalogue|product|products|service|services|'
        . 'delivery|available|in stock|do you (have|sell|offer)|what you have|'
        . 'best ?item|menu pics?)\b/u',
        $lower
    );
}

function conversation_should_skip_catalog_routing(string $message): bool
{
    $normalized = conversation_normalize_intent_text($message);
    if ($normalized === '') {
        return true;
    }

    if (message_is_simple_greeting($normalized)) {
        return true;
    }
    if (conversation_is_identity_question($message)) {
        return true;
    }
    if (conversation_is_wellbeing_question($message)) {
        return true;
    }
    if (function_exists('conversation_is_presence_ping') && conversation_is_presence_ping($message)) {
        return true;
    }
    if (conversation_is_meta_activity_question($message)) {
        return true;
    }
    if (conversation_is_bot_frustration($message)) {
        return true;
    }
    if (conversation_is_location_question($message)) {
        return true;
    }
    if (conversation_is_hours_question($message)) {
        return true;
    }
    if (conversation_is_personal_chat($message)) {
        return true;
    }

    require_once __DIR__ . '/bot-knowledge.php';
    if (knowledge_message_is_offer_question($message)) {
        return false;
    }

    $lower = mb_strtolower(trim($message));
    if (preg_match('/\b(thank you|thanks|shukriya|bye|goodbye|see you)\b/u', $lower) && mb_strlen($lower) < 40) {
        return true;
    }

    if (conversation_is_likely_message_fragment($message)) {
        return true;
    }

    return false;
}

/**
 * AI handles all conversational replies — no canned scripts here.
 *
 * @param array<string, mixed> $bot
 */
function conversation_try_human_context_reply(array $bot, int $leadId, string $userMessage): ?string
{
    return null;
}

/**
 * Open commercial thread for this lead: checkout, booking, or shipment.
 *
 * @return array{type: 'order'|'appointment'|'parcel'|'none', resume: string}
 */
function conversation_active_conversion(int $leadId, int $botId = 0): array
{
    $none = ['type' => 'none', 'resume' => ''];
    if ($leadId <= 0) {
        return $none;
    }

    if ($botId <= 0) {
        $botId = conversation_lead_bot_id($leadId);
    }

    require_once __DIR__ . '/cart.php';
    $cart = cart_get($leadId);
    if ($cart['items'] !== [] && cart_checkout_in_progress($leadId)) {
        return [
            'type'   => 'order',
            'resume' => conversation_order_resume_line($cart),
        ];
    }

    $recent = conversation_recent_assistant_text_without_resume($leadId, 4);
    $looksBooking = (bool) preg_match(
        '/\b(appointment|pick (a |your )?time|time slot|available slots?|book you|'
        . 'calendly|slot \d|reply with (a )?(number|slot))\b/iu',
        $recent
    );
    $hasUpcoming = conversation_lead_has_upcoming_appointment($leadId);
    if ($looksBooking && !$hasUpcoming) {
        return [
            'type'   => 'appointment',
            'resume' => "Whenever you're ready, pick a time from the slots I sent — or tell me what works.",
        ];
    }

    if (cart_lead_has_open_order($leadId)
        && conversation_customer_recently_asked_tracking($leadId)
        && preg_match(
            '/\b(parcel|shipment|tracking|courier|order status|out for delivery|'
            . 'being prepared|order #)\b/iu',
            $recent
        )
    ) {
        return [
            'type'   => 'parcel',
            'resume' => "Whenever you're ready, I can also check your parcel / order status.",
        ];
    }

    return $none;
}

function conversation_strip_resume_boilerplate(string $text): string
{
    $clean = preg_replace(
        '/whenever you.?re ready[^\n]*/iu',
        '',
        $text
    ) ?? $text;

    return trim(preg_replace('/\n{2,}/u', "\n", $clean) ?? $clean);
}

function conversation_recent_assistant_text_without_resume(int $leadId, int $limit = 4): string
{
    return conversation_strip_resume_boilerplate(conversation_recent_assistant_text($leadId, $limit));
}

function conversation_customer_recently_asked_tracking(int $leadId): bool
{
    if ($leadId <= 0) {
        return false;
    }
    require_once __DIR__ . '/shipment.php';
    ensure_conversations_schema();
    $rows = db_fetch_all(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'user\' ORDER BY id DESC LIMIT 3',
        'i',
        [$leadId]
    );
    foreach ($rows as $row) {
        $msg = (string) ($row['message'] ?? '');
        if (shipment_message_is_tracking_query($msg) || shipment_message_wants_receipt($msg)) {
            return true;
        }
    }

    return false;
}

function conversation_lead_bot_id(int $leadId): int
{
    if ($leadId <= 0) {
        return 0;
    }
    $row = db_fetch('SELECT bot_id FROM leads WHERE id = ?', 'i', [$leadId]);

    return (int) ($row['bot_id'] ?? 0);
}

function conversation_recent_assistant_text(int $leadId, int $limit = 4): string
{
    if ($leadId <= 0 || $limit <= 0) {
        return '';
    }
    ensure_conversations_schema();
    $rows = db_fetch_all(
        'SELECT message FROM conversations WHERE lead_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT ?',
        'ii',
        [$leadId, $limit]
    );
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = trim((string) ($row['message'] ?? ''));
    }

    return trim(implode("\n", $parts));
}

function conversation_lead_has_upcoming_appointment(int $leadId): bool
{
    if ($leadId <= 0) {
        return false;
    }
    require_once __DIR__ . '/commerce-schema.php';
    ensure_commerce_schema();
    $row = db_fetch(
        "SELECT id FROM bot_appointments
         WHERE lead_id = ? AND status IN ('pending','confirmed') AND slot_start >= NOW()
         ORDER BY id DESC LIMIT 1",
        'i',
        [$leadId]
    );

    return (bool) $row;
}

/**
 * @param array<string, mixed> $cart
 */
function conversation_order_resume_line(array $cart): string
{
    if (!empty($cart['anything_else_offered']) && empty($cart['anything_else_done'])) {
        return "When you're ready — do you need anything else before I send this order for processing?";
    }
    if (trim((string) ($cart['customer_name'] ?? '')) === '') {
        return "Whenever you're ready, I still need your full name for the order.";
    }
    if (trim((string) ($cart['shipping_address'] ?? '')) === '') {
        return "Whenever you're ready, send your delivery address and I'll continue the order.";
    }
    if (empty($cart['cod_confirmed'])) {
        return "Whenever you're ready, I can confirm this as Cash on Delivery and continue.";
    }

    return "When you're ready — do you need anything else before I send this order for processing?";
}

function conversation_message_looks_like_person_name(string $message): bool
{
    $text = trim($message);
    if ($text === '' || mb_strlen($text) > 60 || str_contains($text, '?')) {
        return false;
    }
    if (preg_match('/^(hi+|hii+|hey+|hello|ok|okay|yes|yeah|no|nothing|thanks?|hmm+)$/iu', $text)) {
        return false;
    }
    if (preg_match('/^(i|we|my|you|the|this|that|it|just|so|well|how|what|why|who|when|where)\b/iu', $text)) {
        return false;
    }
    if (!preg_match('/^[\p{L}][\p{L}\s.\'-]{1,58}$/u', $text)) {
        return false;
    }
    $words = preg_split('/\s+/u', $text) ?: [];

    return count($words) >= 1 && count($words) <= 6;
}

function conversation_message_looks_like_phone(string $message): bool
{
    $digits = preg_replace('/\D/', '', trim($message)) ?? '';

    return strlen($digits) >= 10 && strlen($digits) <= 15 && mb_strlen(trim($message)) <= 22;
}

/**
 * True when the customer is still on the open conversion (not a side chat).
 */
function conversation_message_is_on_topic_for_conversion(string $message, string $type, int $leadId = 0, int $botId = 0): bool
{
    $text = trim($message);
    if ($text === '' || $type === '' || $type === 'none') {
        return false;
    }

    require_once __DIR__ . '/cart.php';
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/shipment.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/whatsapp-shop-ux.php';

    if (catalog_has_clear_shopping_intent($text)
        || catalog_message_is_browse_intent($text)
        || catalog_message_is_menu_request($botId, $text)
        || whatsapp_shop_customer_wants_visual_card($text)
        || catalog_customer_says_media_missing($text)
        || (function_exists('catalog_customer_wants_other_menu') && catalog_customer_wants_other_menu($text))
        || conversation_wants_commercial_context($text)
    ) {
        return true;
    }

    if ($type === 'order') {
        $wantsMore = cart_message_wants_more_items($text)
            && $leadId > 0
            && cart_anything_else_is_pending($leadId);
        if (cart_message_is_shop_interrupt($text)
            || cart_message_looks_like_delivery_details($text)
            || cart_message_declines_anything_else($text)
            || $wantsMore
            || cart_message_is_order_decline($text)
            || conversation_message_looks_like_person_name($text)
            || conversation_message_looks_like_phone($text)
        ) {
            return true;
        }
        if (preg_match(
            '/\b(checkout|place order|confirm order|cod|cash on delivery|'
            . 'delivery (time|fee|charges)|when will (it|this)|eta)\b/iu',
            $text
        )) {
            return true;
        }

        return catalog_has_clear_shopping_intent($text) || conversation_has_buying_signal($text);
    }

    if ($type === 'appointment') {
        if (preg_match('/^\d{1,2}$/u', $text)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(book|booking|appointment|slot|reschedule|available|calendly|'
            . 'tomorrow|today|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/iu',
            $text
        );
    }

    if ($type === 'parcel') {
        return shipment_message_is_tracking_query($text)
            || shipment_message_wants_receipt($text)
            || (bool) preg_match('/\b(order|parcel|shipment|courier|deliver|tracking)\b/iu', $text);
    }

    return false;
}

/**
 * Off-topic chat while an order, appointment, or parcel is still open.
 */
function conversation_message_is_aside_for_type(string $message, string $type, int $leadId = 0, int $botId = 0): bool
{
    if ($type === '' || $type === 'none') {
        return false;
    }
    if (conversation_message_is_on_topic_for_conversion($message, $type, $leadId, $botId)) {
        return false;
    }

    return true;
}

function conversation_message_is_conversion_aside(string $message, int $leadId = 0, int $botId = 0): bool
{
    $conv = conversation_active_conversion($leadId, $botId);

    return conversation_message_is_aside_for_type($message, (string) ($conv['type'] ?? 'none'), $leadId, $botId);
}

/**
 * @param array{type?: string, resume?: string} $conversion
 */
function conversation_conversion_aside_prompt_block(array $conversion): string
{
    $type = (string) ($conversion['type'] ?? 'none');
    $resume = trim((string) ($conversion['resume'] ?? ''));
    if ($type === 'none' || $resume === '') {
        return '';
    }

    $label = 'an open request';
    if ($type === 'order') {
        $label = 'an open order / checkout';
    } elseif ($type === 'appointment') {
        $label = 'an appointment booking';
    } elseif ($type === 'parcel') {
        $label = 'a parcel / shipment';
    }

    return "\n───── ASIDE DURING OPEN CONVERSION ({$type}) ─────\n"
        . "They changed the subject away from {$label}. Listen first: answer THAT in 1–2 lines, like a person.\n"
        . "Do not dump menu, catalog, industry template, or treat this message as name/address/slot.\n"
        . "Only after that is cleared, one quiet line back to the conversion:\n"
        . $resume . "\n";
}

function conversation_reply_already_resumes_conversion(string $reply, string $type): bool
{
    $lower = mb_strtolower($reply);
    if ($lower === '' || $type === '' || $type === 'none') {
        return false;
    }

    if ($type === 'order') {
        return (bool) preg_match(
            '/anything else|delivery address|full name|when you.?re ready|send this order|for the order|cash on delivery/u',
            $lower
        );
    }
    if ($type === 'appointment') {
        return (bool) preg_match('/slot|appointment|pick a time|when you.?re ready|book/u', $lower);
    }
    if ($type === 'parcel') {
        return (bool) preg_match('/parcel|shipment|tracking|order status|when you.?re ready/u', $lower);
    }

    return false;
}

function conversation_should_skip_conversion_resume(string $userMessage): bool
{
    require_once __DIR__ . '/helpers.php';

    if (message_is_simple_greeting($userMessage)
        || conversation_is_wellbeing_question($userMessage)
        || conversation_is_identity_question($userMessage)
        || conversation_is_presence_ping($userMessage)
        || conversation_is_hours_question($userMessage)
        || conversation_is_location_question($userMessage)
    ) {
        return true;
    }

    $lower = mb_strtolower(trim($userMessage));

    return (bool) preg_match('/^(good morning|good afternoon|good evening|gm|gn)[\s!.?]*$/iu', $lower);
}

function conversation_strip_bot_habits(string $reply, string $userMessage = ''): string
{
    $reply = trim($reply);
    if ($reply === '') {
        return '';
    }

    require_once __DIR__ . '/shipment.php';
    $askedTracking = $userMessage !== '' && (
        shipment_message_is_tracking_query($userMessage)
        || shipment_message_wants_receipt($userMessage)
        || (bool) preg_match('/\b(parcel|order status|where is my (order|parcel))\b/iu', $userMessage)
    );

    if (!$askedTracking) {
        $reply = preg_replace(
            '/\n*whenever you.?re ready,?\s*i can also check your parcel[^\n.]*(?:\.[^\n]*)?/iu',
            '',
            $reply
        ) ?? $reply;
    }

    if ($userMessage !== '' && conversation_should_skip_conversion_resume($userMessage)) {
        $reply = preg_replace('/\n*whenever you.?re ready[^\n]*/iu', '', $reply) ?? $reply;
    }

    return trim(preg_replace('/\n{3,}/u', "\n\n", $reply) ?? $reply);
}

function conversation_append_conversion_resume(string $reply, int $leadId, int $botId = 0, string $userMessage = ''): string
{
    $reply = trim($reply);
    if ($reply === '' || $leadId <= 0) {
        return $reply;
    }

    $conv = conversation_active_conversion($leadId, $botId);
    $type = (string) ($conv['type'] ?? 'none');
    $resume = trim((string) ($conv['resume'] ?? ''));
    if ($type === 'none' || $resume === '') {
        return $reply;
    }
    if ($userMessage === '' || !conversation_message_is_aside_for_type($userMessage, $type, $leadId, $botId)) {
        return $reply;
    }
    if (conversation_should_skip_conversion_resume($userMessage)) {
        return $reply;
    }
    if (conversation_reply_already_resumes_conversion($reply, $type)) {
        return $reply;
    }

    $last = conversation_last_assistant_reply($leadId);
    if ($last !== '' && str_contains($last, $resume)) {
        return $reply;
    }

    return $reply . "\n\n" . $resume;
}
