<?php
/**
 * Human-like reply timing — typing speed locked to words per minute (WPM).
 */

/**
 * Count words in text (whitespace-separated, Unicode-safe).
 */
function human_agent_word_count(string $text): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return 0;
    }

    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    return is_array($words) ? count($words) : 0;
}

/**
 * When true, send immediately after AI — typing indicator already ran during generation.
 */
function human_reply_delay_enabled(): bool
{
    if (defined('WHATSAPP_FAST_REPLY') && WHATSAPP_FAST_REPLY) {
        return false;
    }

    return !defined('HUMAN_REPLY_DELAY_ENABLED') || HUMAN_REPLY_DELAY_ENABLED;
}

/**
 * Milliseconds to wait before sending a reply.
 *
 * Typing delay = reply word count at HUMAN_REPLY_WPM (default 500 WPM).
 * Plus a short read pause for the customer's message.
 */
function human_agent_delay_ms(string $replyText, ?string $incomingText = null): int
{
    if (!human_reply_delay_enabled()) {
        return 0;
    }

    $wpm = defined('HUMAN_REPLY_WPM') ? (int) HUMAN_REPLY_WPM : 500;
    $wpm = max(20, min(1000, $wpm));

    $readWpm = defined('HUMAN_READ_WPM') ? (int) HUMAN_READ_WPM : 1000;
    $readWpm = max(100, min(2000, $readWpm));

    $replyWords = human_agent_word_count($replyText);
    $incomingWords = $incomingText !== null ? human_agent_word_count($incomingText) : 0;

    $msPerWord = (int) round((60 * 1000) / $wpm);
    $typeMs = $replyWords * $msPerWord;

    $readCapMs = $readWpm >= 500 ? 1200 : 3500;
    $readFloorMs = $readWpm >= 500 ? 200 : 500;
    if ($incomingWords > 0) {
        $readMsPerWord = (int) round((60 * 1000) / $readWpm);
        $readMs = min($readCapMs, max($readFloorMs, $incomingWords * $readMsPerWord));
    } else {
        $readMsPerWord = (int) round((60 * 1000) / $readWpm);
        $readMs = $readFloorMs;
    }

    $total = $readMs + $typeMs;

    // Small random jitter so timing is not perfectly mechanical
    $jitter = (int) round($total * (mt_rand(-4, 4) / 100));
    $total += $jitter;

    $minMs = defined('HUMAN_REPLY_DELAY_MIN_MS') ? (int) HUMAN_REPLY_DELAY_MIN_MS : 400;
    $maxMs = defined('HUMAN_REPLY_DELAY_MAX_MS') ? (int) HUMAN_REPLY_DELAY_MAX_MS : 8000;

    return max($minMs, min($maxMs, $total));
}

/**
 * Block for a natural human-agent delay before sending.
 */
function human_agent_pause(string $replyText, ?string $incomingText = null): void
{
    usleep(human_agent_delay_ms($replyText, $incomingText) * 1000);
}

/**
 * @return array{
 *   incoming_words: int,
 *   reply_words: int,
 *   read_wpm: int,
 *   type_wpm: int,
 *   read_ms: int,
 *   type_ms: int,
 *   subtotal_ms: int,
 *   jitter_ms: int,
 *   total_before_cap_ms: int,
 *   delay_ms: int,
 *   min_ms: int,
 *   max_ms: int,
 *   capped_by_min: bool,
 *   capped_by_max: bool,
 *   ms_per_type_word: int,
 *   ms_per_read_word: int
 * }
 */
function human_agent_delay_breakdown(string $replyText, ?string $incomingText = null): array
{
    if (!human_reply_delay_enabled()) {
        $minMs = defined('HUMAN_REPLY_DELAY_MIN_MS') ? (int) HUMAN_REPLY_DELAY_MIN_MS : 0;
        return [
            'incoming_words'        => human_agent_word_count($incomingText ?? ''),
            'reply_words'           => human_agent_word_count($replyText),
            'read_wpm'              => 0,
            'type_wpm'              => 0,
            'read_ms'               => 0,
            'type_ms'               => 0,
            'subtotal_ms'           => 0,
            'jitter_ms'             => 0,
            'total_before_cap_ms'   => 0,
            'delay_ms'              => 0,
            'min_ms'                => $minMs,
            'max_ms'                => 0,
            'capped_by_min'         => false,
            'capped_by_max'         => false,
            'ms_per_type_word'      => 0,
            'ms_per_read_word'      => 0,
            'fast_reply_mode'       => true,
        ];
    }

    $wpm = defined('HUMAN_REPLY_WPM') ? (int) HUMAN_REPLY_WPM : 500;
    $wpm = max(20, min(1000, $wpm));

    $readWpm = defined('HUMAN_READ_WPM') ? (int) HUMAN_READ_WPM : 1000;
    $readWpm = max(100, min(2000, $readWpm));

    $replyWords = human_agent_word_count($replyText);
    $incomingWords = $incomingText !== null ? human_agent_word_count($incomingText) : 0;

    $msPerTypeWord = (int) round((60 * 1000) / $wpm);
    $typeMs = $replyWords * $msPerTypeWord;

    $readCapMs = $readWpm >= 500 ? 1200 : 3500;
    $readFloorMs = $readWpm >= 500 ? 200 : 500;
    if ($incomingWords > 0) {
        $msPerReadWord = (int) round((60 * 1000) / $readWpm);
        $readMs = min($readCapMs, max($readFloorMs, $incomingWords * $msPerReadWord));
    } else {
        $msPerReadWord = (int) round((60 * 1000) / $readWpm);
        $readMs = $readFloorMs;
    }

    $subtotal = $readMs + $typeMs;

    // Match human_agent_delay_ms jitter (fixed seed not used — show range in UI)
    $jitterMs = 0;
    $totalBeforeCap = $subtotal;

    $minMs = defined('HUMAN_REPLY_DELAY_MIN_MS') ? (int) HUMAN_REPLY_DELAY_MIN_MS : 400;
    $maxMs = defined('HUMAN_REPLY_DELAY_MAX_MS') ? (int) HUMAN_REPLY_DELAY_MAX_MS : 8000;
    $delayMs = human_agent_delay_ms($replyText, $incomingText);

    return [
        'incoming_words'        => $incomingWords,
        'reply_words'           => $replyWords,
        'read_wpm'              => $readWpm,
        'type_wpm'              => $wpm,
        'read_ms'               => $readMs,
        'type_ms'               => $typeMs,
        'subtotal_ms'           => $subtotal,
        'jitter_ms'             => $jitterMs,
        'total_before_cap_ms'   => $totalBeforeCap,
        'delay_ms'              => $delayMs,
        'min_ms'                => $minMs,
        'max_ms'                => $maxMs,
        'capped_by_min'         => $subtotal < $minMs && $delayMs === $minMs,
        'capped_by_max'         => $subtotal > $maxMs && $delayMs === $maxMs,
        'ms_per_type_word'      => $msPerTypeWord,
        'ms_per_read_word'      => $msPerReadWord ?? (int) round((60 * 1000) / $readWpm),
    ];
}

/**
 * @return array{delay_ms: int, reply_words: int, wpm: int}
 */
function human_agent_delay_meta(string $replyText, ?string $incomingText = null): array
{
    $breakdown = human_agent_delay_breakdown($replyText, $incomingText);

    return [
        'delay_ms'    => $breakdown['delay_ms'],
        'reply_words' => $breakdown['reply_words'],
        'wpm'         => $breakdown['type_wpm'],
        'read_ms'     => $breakdown['read_ms'],
        'type_ms'     => $breakdown['type_ms'],
    ];
}
