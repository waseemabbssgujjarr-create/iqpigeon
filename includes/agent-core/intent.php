<?php
/**
 * One intent result. conversation_mind_intent() is the classifier; specialized
 * detections (MEDIA, CORRECTION, …) are evidence/overrides on that same object.
 * tools[] is advisory — never execute catalog because industry_key is restaurant.
 *
 * @return array{kind: string, confidence: float, tools: list<string>, needs_web: bool, continue_thread: bool, clarification_needed: bool, referent?: string, missed_thought?: string, mind?: string, signals?: array, override?: string}
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_core_intent(array $turnCtx, array $conv): array
{
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $msg = mb_strtolower((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    $caps = $turnCtx['profile']['capabilities'] ?? [];
    if (!is_array($caps)) {
        $caps = [];
    }
    $referent = (string) ($conv['referents']['product'] ?? '');

    $mind = agent_core_mind_intent_kind($text, $conv);
    $mapped = $mind !== '' ? agent_core_map_mind_intent($mind) : 'FOLLOW_UP';

    $intent = [
        'kind'                 => $mapped,
        'confidence'           => $mind !== '' ? 0.7 : 0.55,
        'tools'                => [],
        'needs_web'            => false,
        'continue_thread'      => in_array($mapped, ['FOLLOW_UP', 'CORRECTION', 'CHASE_UP'], true),
        'clarification_needed' => false,
        'referent'             => $referent,
        'missed_thought'       => (string) ($conv['missed_thought'] ?? ''),
        'mind'                 => $mind,
        'signals'              => [],
        'override'             => '',
    ];

    $signals = agent_core_intent_signals($turnCtx, $conv, $msg, $caps);
    $intent['signals'] = $signals;

    return agent_core_intent_apply_overrides($intent, $signals, $conv);
}

/**
 * Specialized detectors — evidence only. Do not return a competing intent from here.
 *
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param list<string> $caps
 * @return array<string, mixed>
 */
function agent_core_intent_signals(array $turnCtx, array $conv, string $msg, array $caps): array
{
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $hasBooking = in_array('booking', $caps, true);
    $hasCatalog = in_array('catalog', $caps, true);
    $media = is_array($turnCtx['media'] ?? null) ? $turnCtx['media'] : [];
    $hasImage = false;
    $imageDescribed = false;
    $hasAudio = false;
    $hasDocument = false;
    foreach ($media as $item) {
        $type = mb_strtolower((string) ($item['type'] ?? ''));
        if (in_array($type, ['image', 'photo', 'picture', 'sticker'], true) || str_contains(mb_strtolower((string) ($item['text'] ?? '')), '[customer image]')) {
            $hasImage = true;
            $desc = trim((string) ($item['description'] ?? $item['image_description'] ?? ''));
            if ($desc !== '' && !str_contains(mb_strtolower($desc), 'analysis unavailable')) {
                $imageDescribed = true;
            }
        }
        if (in_array($type, ['audio', 'voice', 'ptt', 'audio_message'], true) || trim((string) ($item['transcript'] ?? '')) !== '') {
            $hasAudio = true;
        }
        if (in_array($type, ['document', 'file', 'pdf', 'doc'], true) || trim((string) ($item['extracted_content'] ?? '')) !== '') {
            $hasDocument = true;
        }
    }
    foreach (is_array($turnCtx['understanding'] ?? null) ? $turnCtx['understanding'] : [] as $u) {
        $ut = (string) ($u['type'] ?? '');
        if ($ut === 'image') {
            $hasImage = true;
            if (trim((string) ($u['image_description'] ?? '')) !== '') {
                $imageDescribed = true;
            }
        }
        if ($ut === 'audio') {
            $hasAudio = true;
        }
        if ($ut === 'document') {
            $hasDocument = true;
        }
    }
    if (str_contains($msg, '[customer image]') || str_contains($msg, 'image received')) {
        $hasImage = true;
        $imageDescribed = !str_contains($msg, 'analysis unavailable');
    }

    $saysPhoto = (bool) preg_match(
        '/\b(i sent (you )?(a )?(photo|image|picture|pic)|sent you a photo|look at (this|the) (photo|image|picture)|this (photo|picture|image))\b/u',
        $msg
    );

    $source = [];
    if (function_exists('agent_core_source_route')) {
        $source = agent_core_source_route($turnCtx, $conv, []);
    }
    $sourcePrimary = (string) ($source['primary'] ?? '');
    $needsWeb = !empty($source['needs_web']) || agent_core_looks_like_live_world($text);
    $shopAsk = (bool) preg_match('/\b(menu|catalog|catalogue|add #\d|checkout|my cart)\b/u', $msg)
        || (bool) preg_match('/\b(what do you (sell|have)|show me (the )?(menu|items))\b/u', $msg);
    $productAsk = (bool) preg_match('/\b(do you have|got any|is there)\b/u', $msg)
        && !preg_match('/\b(the black one|the white one|that one|this one)\b/u', $msg);
    $mixed = ($sourcePrimary === 'MIXED') || ($needsWeb && (
        $shopAsk
        || $productAsk
        || !empty($source['needs_hours'])
        || !empty($source['needs_catalog'])
    ));

    return [
        'media'            => $saysPhoto || $hasDocument || $hasImage,
        'has_audio'        => $hasAudio,
        'has_document'     => $hasDocument,
        'has_image'        => $hasImage,
        'image_described'  => $imageDescribed,
        'correction'       => (bool) preg_match('/\b(why (didn\'?t|did not|don\'?t) you (answer|reply|respond)|you didn\'?t (answer|understand|listen)|that\'?s not what i asked)\b/u', $msg),
        'chase_up'         => (bool) preg_match('/\b(why (don\'?t|didn\'?t|won\'?t) you (reply|respond)|are you there|please reply)\b/u', $msg),
        'live_world'       => $needsWeb && !$mixed,
        'mixed'            => $mixed,
        'needs_hours'      => !empty($source['needs_hours']),
        'booking'          => (bool) preg_match(
            '/\b(book me|book (a |an )?(table|slot|appointment|call)|appointment|reserve|reservation|tomorrow at|at \d{1,2}(:\d{2})?\s*(am|pm)?)\b/u',
            $msg
        ),
        'follow_up'        => (bool) preg_match('/\b(the black one|the white one|that one|this one|the other one|those ones)\b/u', $msg)
            || (bool) preg_match('/\b(how much( is it)?|that one|the same)\b/u', $msg),
        'greeting'         => (bool) preg_match('/^(hi+|hello+|hey+|salam|assalam)/u', $msg) && mb_strlen($msg) < 28,
        'social'           => (bool) preg_match('/\b(just (want to )?chat|be (my )?friends?|how are you|kaise ho)\b/u', $msg)
            && !preg_match('/\b(menu|order|book|price|catalog)\b/u', $msg),
        'catalog'          => $shopAsk && $hasCatalog && !($needsWeb && !agent_core_looks_like_business_catalog($msg)),
        'business_inquiry' => (bool) preg_match('/\b(hours?|open|address|where (are you|is the)|what do you offer|what (services|packages))\b/u', $msg),
        'identity'         => (bool) preg_match('/\b(who are you|are you (a )?(bot|ai|human))\b/u', $msg),
        'company'          => (bool) preg_match('/\b(your (company|business|restaurant|clinic|agency)|about (this|your) (company|business|restaurant)|what (is|does) this (company|business))\b/u', $msg),
        'general'          => (bool) preg_match('/\b(tell me a joke|make me laugh|fun fact|riddle)\b/u', $msg),
        'clarification'    => (bool) preg_match('/^(what|huh|pardon)\??$/u', $msg) || (bool) preg_match('/\b(you didn\'?t understand|didn\'?t (get|understand)|confused)\b/u', $msg),
        'off_topic'        => $text === '' && !$hasImage && !$saysPhoto && !$hasAudio && !$hasDocument,
        'has_booking'      => $hasBooking,
        'has_catalog'      => $hasCatalog,
        'shop_ask'         => $shopAsk,
        'product_ask'      => $productAsk,
        'wants_cart'       => str_contains($msg, 'cart'),
    ];
}

/**
 * Fold specialized evidence onto the mind-based intent. One object, one kind.
 *
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $signals
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_core_intent_apply_overrides(array $intent, array $signals, array $conv): array
{
    $hasBooking = !empty($signals['has_booking']);
    $hasCatalog = !empty($signals['has_catalog']);

    if (!empty($signals['media'])) {
        $intent['kind'] = 'MEDIA';
        $intent['confidence'] = 0.86;
        $intent['clarification_needed'] = !empty($signals['has_image']) && empty($signals['image_described']);
        $intent['tools'] = [];
        $intent['override'] = 'MEDIA';

        return $intent;
    }

    if (!empty($signals['correction'])) {
        $intent['kind'] = 'CORRECTION';
        $intent['confidence'] = 0.9;
        $intent['continue_thread'] = true;
        $intent['tools'] = [];
        $intent['override'] = 'CORRECTION';
        if ($intent['missed_thought'] === '' && trim((string) ($conv['last_user'] ?? '')) !== '') {
            $intent['missed_thought'] = (string) $conv['last_user'];
        }

        return $intent;
    }

    if (!empty($signals['chase_up'])) {
        $intent['kind'] = 'CHASE_UP';
        $intent['confidence'] = 0.85;
        $intent['continue_thread'] = true;
        $intent['tools'] = [];
        $intent['override'] = 'CHASE_UP';

        return $intent;
    }

    if (!empty($signals['mixed'])) {
        $intent['kind'] = 'MIXED';
        $intent['confidence'] = 0.86;
        $intent['needs_web'] = true;
        $tools = ['live_web.search'];
        if (!empty($signals['needs_hours'])) {
            $tools[] = 'hours.read';
        }
        if (!empty($signals['shop_ask']) || !empty($signals['product_ask']) || !empty($signals['catalog'])) {
            $tools[] = 'catalog.search';
        }
        $intent['tools'] = array_values(array_unique($tools));
        $intent['override'] = 'MIXED';

        return $intent;
    }

    if (!empty($signals['live_world'])) {
        $intent['kind'] = 'LIVE_WORLD';
        $intent['confidence'] = 0.88;
        $intent['needs_web'] = true;
        $intent['tools'] = ['live_web.search'];
        if (!empty($signals['needs_hours'])) {
            $intent['tools'][] = 'hours.read';
        }
        $intent['override'] = 'LIVE_WORLD';

        return $intent;
    }

    if (!empty($signals['booking'])) {
        $intent['kind'] = $hasBooking ? 'BOOKING' : 'BUSINESS_INQUIRY';
        $intent['confidence'] = 0.84;
        $intent['tools'] = $hasBooking ? ['booking.offer'] : [];
        $intent['clarification_needed'] = !$hasBooking;
        $intent['override'] = $hasBooking ? 'BOOKING' : 'BUSINESS_INQUIRY';

        return $intent;
    }

    if (!empty($signals['follow_up'])) {
        $intent['kind'] = 'FOLLOW_UP';
        $intent['confidence'] = 0.8;
        $intent['continue_thread'] = true;
        $intent['tools'] = ($hasCatalog && $intent['referent'] !== '') ? ['catalog.search'] : [];
        $intent['clarification_needed'] = $hasCatalog && $intent['referent'] === '';
        $intent['override'] = 'FOLLOW_UP';

        return $intent;
    }

    if (!empty($signals['clarification'])) {
        $intent['kind'] = 'CLARIFICATION';
        $intent['confidence'] = 0.8;
        $intent['continue_thread'] = true;
        $intent['clarification_needed'] = true;
        $intent['tools'] = [];
        $intent['override'] = 'CLARIFICATION';

        return $intent;
    }

    if (!empty($signals['greeting'])) {
        $intent['kind'] = 'GREETING';
        $intent['confidence'] = 0.82;
        $intent['tools'] = [];
        $intent['override'] = 'GREETING';

        return $intent;
    }

    if (!empty($signals['social'])) {
        $intent['kind'] = 'SOCIAL';
        $intent['confidence'] = 0.75;
        $intent['tools'] = [];
        $intent['override'] = 'SOCIAL';

        return $intent;
    }

    if (!empty($signals['general'])) {
        $intent['kind'] = 'GENERAL';
        $intent['confidence'] = 0.72;
        $intent['tools'] = [];
        $intent['continue_thread'] = false;
        $intent['override'] = 'GENERAL';

        return $intent;
    }

    if (!empty($signals['catalog'])) {
        $intent['kind'] = 'CATALOG';
        $intent['confidence'] = 0.78;
        $intent['tools'] = !empty($signals['wants_cart']) ? ['cart.view'] : ['catalog.search'];
        $intent['override'] = 'CATALOG';

        return $intent;
    }

    if (!empty($signals['business_inquiry'])) {
        $intent['kind'] = 'BUSINESS_INQUIRY';
        $intent['confidence'] = 0.77;
        $intent['tools'] = !empty($signals['needs_hours']) ? ['hours.read'] : [];
        $intent['override'] = 'BUSINESS_INQUIRY';

        return $intent;
    }

    if (!empty($signals['identity'])) {
        $intent['kind'] = 'IDENTITY';
        $intent['confidence'] = 0.8;
        $intent['tools'] = [];
        $intent['override'] = 'IDENTITY';

        return $intent;
    }

    if (!empty($signals['company'])) {
        $intent['kind'] = 'BUSINESS_INQUIRY';
        $intent['confidence'] = 0.8;
        $intent['tools'] = [];
        $intent['override'] = 'IDENTITY';

        return $intent;
    }

    if (!empty($signals['off_topic'])) {
        $intent['kind'] = 'OFF_TOPIC';
        $intent['confidence'] = 0.5;
        $intent['tools'] = [];
        $intent['override'] = 'OFF_TOPIC';

        return $intent;
    }

    if (($intent['kind'] ?? '') === 'BUSINESS_INQUIRY' && $hasCatalog && !empty($signals['shop_ask'])) {
        $intent['kind'] = 'CATALOG';
        $intent['tools'] = !empty($signals['wants_cart']) ? ['cart.view'] : ['catalog.search'];
        $intent['override'] = 'CATALOG';
    }

    if (in_array((string) ($intent['kind'] ?? ''), ['FOLLOW_UP', 'CORRECTION', 'CHASE_UP'], true)) {
        $intent['continue_thread'] = true;
    }

    return $intent;
}

/**
 * @param array<string, mixed> $conv
 */
function agent_core_mind_intent_kind(string $text, array $conv): string
{
    $path = dirname(__DIR__) . '/conversation-mind.php';
    if (!is_file($path)) {
        return '';
    }
    require_once $path;
    if (!function_exists('conversation_mind_intent')) {
        return '';
    }
    $history = is_array($conv['history'] ?? null) ? $conv['history'] : [];
    $mode = (string) ($conv['mind_mode'] ?? 'FOLLOW_UP');

    return conversation_mind_intent($text, $history, $mode);
}

function agent_core_map_mind_intent(string $mind): string
{
    return match ($mind) {
        'OBJECTION'             => 'CORRECTION',
        'CHASE_UP'              => 'CHASE_UP',
        'BUSINESS_INQUIRY'      => 'BUSINESS_INQUIRY',
        'PERSONAL_CONVERSATION', 'CASUAL_CONVERSATION' => 'SOCIAL',
        'GREETING'              => 'GREETING',
        'CLARIFICATION'         => 'CLARIFICATION',
        'FOLLOW_UP'             => 'FOLLOW_UP',
        default                 => 'FOLLOW_UP',
    };
}

function agent_core_looks_like_live_world(string $msg): bool
{
    require_once dirname(__DIR__) . '/live-world-info.php';

    return live_world_message_needs_fresh_evidence($msg, '');
}

function agent_core_looks_like_business_catalog(string $msg): bool
{
    return (bool) preg_match('/\b(your|our)\b.{0,40}\b(menu|burger|pizza|catalog|package|service)\b/u', $msg);
}
