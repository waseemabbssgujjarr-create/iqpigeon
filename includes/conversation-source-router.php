<?php
/**
 * Meaning-based information source router for the live WhatsApp mind path.
 * Time words (today/yesterday/current/latest) never select LIVE_WEB by themselves.
 */
declare(strict_types=1);

/**
 * @return array{
 *   primary: string,
 *   needs_web: bool,
 *   needs_orders: bool,
 *   needs_hours: bool,
 *   needs_catalog: bool,
 *   needs_memory: bool,
 *   search_query: string
 * }
 */
function conversation_source_route(string $message, string $thread = ''): array
{
    $msg = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));
    $thread = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $thread)));
    $out = [
        'primary'       => 'GENERAL_GPT',
        'needs_web'     => false,
        'needs_orders'  => false,
        'needs_hours'   => false,
        'needs_catalog' => false,
        'needs_memory'  => false,
        'search_query'  => '',
    ];
    if ($msg === '') {
        return $out;
    }

    $customerHistory = (bool) preg_match(
        '/\b('
        . 'what did i (order|buy|get|have|purchase)'
        . '|what (have|did) i (order|buy|get|purchased?)'
        . '|my (last|previous|yesterday|earlier) (order|purchase|buy)'
        . '|(last|previous) (order|purchase)'
        . '|same (thing|order|product|one)( again)?'
        . '|order(ed)? (yesterday|last (week|month|time)|before)'
        . '|what did i (order|buy).{0,24}(yesterday|last (week|month|time)|today)'
        . '|reorder|order again'
        . ')\b/u',
        $msg
    ) || (
        (bool) preg_match('/\b(i|we|my)\b.{0,48}\b(order|ordered|bought|buy|purchased?)\b/u', $msg)
        && (bool) preg_match('/\b(yesterday|last (week|month|time)|today|before|previously|earlier)\b/u', $msg)
        && !preg_match('/\b(pakistan|president|prime minister|news|weather|election)\b/u', $msg)
    );

    $conversationRecall = (bool) preg_match(
        '/\b('
        . 'what did we (discuss|talk|speak|say|chat)'
        . '|what (were|are) we (talking|discussing)'
        . '|what did you (recommend|suggest|mention|say|tell)'
        . '|you (recommended|suggested|mentioned)'
        . '|last time you'
        . '|earlier (you|we)'
        . '|remind me what (we|you)'
        . ')\b/u',
        $msg
    );

    $hoursAsk = (bool) preg_match(
        '/\b('
        . 'are you open'
        . '|open (now|today|tonight|right now|this (evening|afternoon))'
        . '|opening hours|business hours|what time (do you|you) (open|close)'
        . '|when (do you|you) (open|close)'
        . '|are you closed'
        . ')\b/u',
        $msg
    );

    $catalogAsk = (bool) preg_match(
        '/\b('
        . 'your (current )?(menu|catalog|prices?|burgers?|pizzas?|products?)'
        . '|what (pizzas?|burgers?|items?|products?) (do you|you) (have|offer|sell)'
        . '|show me (your )?(menu|burgers?|pizzas?|catalog)'
        . '|current (burger |pizza |menu )?price'
        . '|how much (is|are|for) (your|the)'
        . '|what (deals?|offers?) do you (currently )?offer'
        . ')\b/u',
        $msg
    ) || (function_exists('live_world_is_business_question') && live_world_is_business_question($msg)
        && !preg_match('/\b(president|prime minister|pakistan news|weather|bitcoin)\b/u', $msg));

    $businessAsk = (bool) preg_match(
        '/\b(your (address|location|services?|policy|policies|phone)|what (services|products) do you offer|where are you)\b/u',
        $msg
    );

    $worldAffairs = conversation_source_is_world_affairs($msg, $thread);
    $generalFact = (bool) preg_match(
        '/\b(what is|what\'s|whats)\s+(a |an |the )?(machine learning|artificial intelligence|photosynthesis|gravity|ai)\b/u',
        $msg
    );

    if ($customerHistory && !$worldAffairs) {
        $out['primary'] = 'CUSTOMER_HISTORY';
        $out['needs_orders'] = true;
        $out['needs_memory'] = true;
    } elseif ($conversationRecall && !$worldAffairs) {
        $out['primary'] = 'CONVERSATION_MEMORY';
        $out['needs_memory'] = true;
    } elseif ($hoursAsk && $worldAffairs) {
        $out['primary'] = 'MIXED';
        $out['needs_hours'] = true;
        $out['needs_web'] = true;
        $out['search_query'] = conversation_source_web_query($message, $thread);
    } elseif ($hoursAsk) {
        $out['primary'] = 'BUSINESS_HOURS';
        $out['needs_hours'] = true;
    } elseif ($catalogAsk && $worldAffairs) {
        $out['primary'] = 'MIXED';
        $out['needs_catalog'] = true;
        $out['needs_web'] = true;
        $out['search_query'] = conversation_source_web_query($message, $thread);
    } elseif ($catalogAsk) {
        $out['primary'] = 'BUSINESS_CATALOG';
        $out['needs_catalog'] = true;
    } elseif ($businessAsk && !$worldAffairs) {
        $out['primary'] = 'BUSINESS_KNOWLEDGE';
    } elseif ($worldAffairs) {
        $out['primary'] = 'LIVE_WEB';
        $out['needs_web'] = true;
        $out['search_query'] = conversation_source_web_query($message, $thread);
        $out['needs_memory'] = true;
    } elseif ($generalFact) {
        $out['primary'] = 'GENERAL_GPT';
    } else {
        $out['primary'] = 'GENERAL_GPT';
        $out['needs_memory'] = true;
    }

    error_log(
        'iqp_router: source=' . $out['primary']
        . ' web=' . ($out['needs_web'] ? '1' : '0')
        . ' orders=' . ($out['needs_orders'] ? '1' : '0')
        . ' hours=' . ($out['needs_hours'] ? '1' : '0')
        . ' q=' . mb_substr($out['search_query'], 0, 80)
    );

    return $out;
}

function conversation_source_is_world_affairs(string $msg, string $thread = ''): bool
{
    if (preg_match(
        '/\b('
        . 'president|prime minister|army chief|chief of army|chief of staff'
        . '|election|breaking news|in the news|current affairs'
        . '|exchange rate|usd to pkr|bitcoin|\bbtc\b|crypto price'
        . '|weather|forecast|who won|final score|match result'
        . '|openai model|latest gpt'
        . ')\b/u',
        $msg
    )) {
        if (preg_match('/\b(your|our)\b.{0,40}\b(price|menu|hours|deal|offer|burger|pizza|service)\b/u', $msg)
            && !preg_match('/\b(president|prime minister|army chief|news|weather|bitcoin|election)\b/u', $msg)
        ) {
            return false;
        }

        return true;
    }

    if (preg_match('/\bwho (is|\'s) the (current )?(pm|president|prime minister|army chief)\b/u', $msg)) {
        return true;
    }
    if (preg_match('/\bwho runs (america|the (usa|us|united states)|pakistan)\b/u', $msg)) {
        return true;
    }
    if (preg_match('/\bwhat happened (in )?(pakistan|the world|today|yesterday)\b/u', $msg)
        || preg_match('/\b(latest|today\'?s) (pakistan )?news\b/u', $msg)
        || preg_match('/\bwhat\'?s happening in pakistan\b/u', $msg)
    ) {
        return true;
    }

    $followUp = (bool) preg_match(
        '/\b(and (the )?(army chief|president|pm|prime minister|weather|rate)|what about (him|them|there))\b/u',
        $msg
    );
    if ($followUp && preg_match(
        '/\b(pakistan|president|prime minister|pm|army|election|news|weather)\b/u',
        $thread
    )) {
        return true;
    }

    return false;
}

function conversation_source_web_query(string $message, string $thread = ''): string
{
    $q = trim($message);
    $q = (string) preg_replace('/\+?\d[\d\s\-]{8,}\d/', '', $q);
    $q = (string) preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '', $q);
    $q = (string) preg_replace('/\b(order #?\d+|my address|my phone|card number)\b/iu', '', $q);
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));

    if (preg_match(
        '/\b(who is the current .{0,40}|who runs .{0,30}|current (president|prime minister|pm|army chief).{0,40}|latest .{0,40}news.{0,20}|today\'?s (weather|exchange rate|usd).{0,30}|what happened .{0,40})/iu',
        $q,
        $m
    )) {
        $q = trim($m[0]);
    }

    if (preg_match('/\b(army chief|president|pm|prime minister)\b/iu', $q)
        && preg_match('/\b(pakistan|usa|united states|america)\b/iu', $thread)
        && !preg_match('/\b(pakistan|usa|united states|america)\b/iu', $q)
    ) {
        if (preg_match('/\bpakistan\b/u', mb_strtolower($thread))) {
            $q .= ' Pakistan';
        } elseif (preg_match('/\b(usa|united states|america)\b/u', mb_strtolower($thread))) {
            $q .= ' United States';
        }
    }

    return mb_substr($q, 0, 180);
}
