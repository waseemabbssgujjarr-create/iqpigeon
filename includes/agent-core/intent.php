<?php
/**
 * One intent model. tools[] is advisory — never execute catalog because industry_key is restaurant.
 *
 * @return array{kind: string, confidence: float, tools: list<string>, needs_web: bool, continue_thread: bool, clarification_needed: bool, referent?: string, missed_thought?: string}
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
    $hasBooking = in_array('booking', $caps, true);
    $hasCatalog = in_array('catalog', $caps, true);
    $media = is_array($turnCtx['media'] ?? null) ? $turnCtx['media'] : [];
    $hasImage = false;
    $imageDescribed = false;
    foreach ($media as $item) {
        $type = (string) ($item['type'] ?? '');
        if (in_array($type, ['image', 'photo'], true) || str_contains(mb_strtolower((string) ($item['text'] ?? '')), '[customer image]')) {
            $hasImage = true;
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc !== '' && !str_contains(mb_strtolower($desc), 'analysis unavailable')) {
                $imageDescribed = true;
            }
        }
    }
    if (str_contains($msg, '[customer image]') || str_contains($msg, 'image received')) {
        $hasImage = true;
        $imageDescribed = !str_contains($msg, 'analysis unavailable');
    }

    $intent = [
        'kind'                  => 'FOLLOW_UP',
        'confidence'            => 0.55,
        'tools'                 => [],
        'needs_web'             => false,
        'continue_thread'       => false,
        'clarification_needed'  => false,
        'referent'              => (string) ($conv['referents']['product'] ?? ''),
        'missed_thought'        => (string) ($conv['missed_thought'] ?? ''),
    ];

    $saysPhoto = (bool) preg_match(
        '/\b(i sent (you )?(a )?(photo|image|picture|pic)|sent you a photo|look at (this|the) (photo|image|picture)|this (photo|picture|image))\b/u',
        $msg
    );
    if ($saysPhoto || ($hasImage && $msg === '')) {
        $intent['kind'] = 'MEDIA';
        $intent['confidence'] = 0.86;
        $intent['clarification_needed'] = $hasImage && !$imageDescribed;
        $intent['tools'] = [];

        return $intent;
    }

    if (preg_match('/\b(why (didn\'?t|did not|don\'?t) you (answer|reply|respond)|you didn\'?t (answer|understand|listen)|that\'?s not what i asked)\b/u', $msg)) {
        $intent['kind'] = 'CORRECTION';
        $intent['confidence'] = 0.9;
        $intent['continue_thread'] = true;
        $intent['tools'] = [];
        if ($intent['missed_thought'] === '' && trim((string) ($conv['last_user'] ?? '')) !== '') {
            $intent['missed_thought'] = (string) $conv['last_user'];
        }

        return $intent;
    }

    if (preg_match('/\b(why (don\'?t|didn\'?t|won\'?t) you (reply|respond)|are you there|please reply)\b/u', $msg)) {
        $intent['kind'] = 'CHASE_UP';
        $intent['confidence'] = 0.85;
        $intent['continue_thread'] = true;
        $intent['tools'] = [];

        return $intent;
    }

    $source = [];
    if (function_exists('agent_core_source_route')) {
        $source = agent_core_source_route($turnCtx, $conv, []);
    }
    $needsWeb = !empty($source['needs_web']) || agent_core_looks_like_live_world($msg);
    if ($needsWeb && !agent_core_looks_like_business_catalog($msg)) {
        $intent['kind'] = 'LIVE_WORLD';
        $intent['confidence'] = 0.88;
        $intent['needs_web'] = true;
        $intent['tools'] = ['live_web.search'];

        return $intent;
    }

    $bookingAsk = (bool) preg_match(
        '/\b(book me|book (a |an )?(table|slot|appointment|call)|appointment|reserve|reservation|tomorrow at|at \d{1,2}(:\d{2})?\s*(am|pm)?)\b/u',
        $msg
    );
    if ($bookingAsk) {
        $intent['kind'] = $hasBooking ? 'BOOKING' : 'BUSINESS_INQUIRY';
        $intent['confidence'] = 0.84;
        $intent['tools'] = $hasBooking ? ['booking.offer'] : [];
        $intent['clarification_needed'] = !$hasBooking;

        return $intent;
    }

    $followPronoun = (bool) preg_match('/\b(the black one|the white one|that one|this one|the other one|those ones)\b/u', $msg)
        || (bool) preg_match('/\b(how much( is it)?|that one|the same)\b/u', $msg);
    if ($followPronoun) {
        $intent['kind'] = 'FOLLOW_UP';
        $intent['confidence'] = 0.8;
        $intent['continue_thread'] = true;
        $intent['tools'] = ($hasCatalog && $intent['referent'] !== '') ? ['catalog.search'] : [];
        $intent['clarification_needed'] = $hasCatalog && $intent['referent'] === '';

        return $intent;
    }

    if (preg_match('/^(hi+|hello+|hey+|salam|assalam)/u', $msg) && mb_strlen($msg) < 28) {
        $intent['kind'] = 'GREETING';
        $intent['confidence'] = 0.82;
        $intent['tools'] = [];

        return $intent;
    }

    if (preg_match('/\b(just (want to )?chat|be (my )?friends?|how are you|kaise ho)\b/u', $msg)
        && !preg_match('/\b(menu|order|book|price|catalog)\b/u', $msg)
    ) {
        $intent['kind'] = 'SOCIAL';
        $intent['confidence'] = 0.75;
        $intent['tools'] = [];

        return $intent;
    }

    $shopAsk = (bool) preg_match('/\b(menu|catalog|catalogue|add #\d|checkout|my cart)\b/u', $msg)
        || (bool) preg_match('/\b(what do you (sell|have)|show me (the )?(menu|items))\b/u', $msg);
    if ($shopAsk && $hasCatalog && !$needsWeb) {
        $intent['kind'] = 'CATALOG';
        $intent['confidence'] = 0.78;
        $intent['tools'] = str_contains($msg, 'cart') ? ['cart.view'] : ['catalog.search'];

        return $intent;
    }

    if (preg_match('/\b(hours?|open|address|where (are you|is the)|what do you offer|what (services|packages))\b/u', $msg)) {
        $intent['kind'] = 'BUSINESS_INQUIRY';
        $intent['confidence'] = 0.77;
        $intent['tools'] = [];

        return $intent;
    }

    if (preg_match('/\b(who are you|are you (a )?(bot|ai|human))\b/u', $msg)) {
        $intent['kind'] = 'IDENTITY';
        $intent['confidence'] = 0.8;
        $intent['tools'] = [];

        return $intent;
    }

    $intent['kind'] = 'OFF_TOPIC';
    $intent['confidence'] = 0.5;
    $intent['tools'] = [];

    return $intent;
}

function agent_core_looks_like_live_world(string $msg): bool
{
    return (bool) preg_match(
        '/\b('
        . 'petrol|gasoline|diesel|fuel price'
        . '|president|prime minister|army chief'
        . '|weather|forecast|bitcoin|exchange rate'
        . '|who (is|\'s) the current'
        . ')\b/u',
        $msg
    );
}

function agent_core_looks_like_business_catalog(string $msg): bool
{
    return (bool) preg_match('/\b(your|our)\b.{0,40}\b(menu|burger|pizza|catalog|package|service)\b/u', $msg);
}
