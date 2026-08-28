<?php
/**
 * Shop-tool vs human-AI policy.
 * Unknown chat always goes to the AI. Tools run only on explicit shop actions.
 */

require_once __DIR__ . '/conversation-intent.php';
require_once __DIR__ . '/cart.php';

function conversation_is_shop_pitch_reply(string $reply): bool
{
    $lower = mb_strtolower(trim($reply));
    if ($lower === '') {
        return false;
    }

    if (function_exists('conversation_is_generic_menu_prompt_reply')
        && conversation_is_generic_menu_prompt_reply($reply)
    ) {
        return true;
    }

    return (bool) preg_match(
        '/i\'m here with .+ tap below|tap below to browse the menu|'
        . 'reply \*?menu\*? to (browse|see|view)|add #n to order|'
        . 'tap \*?view menu\*? to browse/u',
        $lower
    );
}

function conversation_route_is_explicit_menu(string $message): bool
{
    $trimmed = trim($message);
    if ($trimmed === '') {
        return false;
    }

    if (preg_match('/^(the\s+)?menu[\s!?.]*$/iu', $trimmed)) {
        return true;
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($trimmed));

    if (preg_match(
        '/\b(show (me )?(your )?menu|send (me )?(the )?menu|see (the )?menu|food menu|'
        . 'what(?:\'?s| is)? on (the )?menu|tonight(?:\'?s)? menu|today(?:\'?s)? menu|'
        . 'what (?:do )?you have (?:today|tonight)|what you have (?:today|tonight)|'
        . 'specials? (?:today|tonight)|browse (the )?(menu|catalog))\b/u',
        $lower
    )) {
        return true;
    }

    require_once __DIR__ . '/catalog.php';

    return catalog_message_is_browse_intent($message);
}

/** High-confidence shop action — otherwise the AI must talk. */
function conversation_is_shop_tool_turn(string $message): bool
{
    if (conversation_route_is_explicit_menu($message)) {
        return true;
    }

    $lower = mb_strtolower(trim($message));

    return (bool) preg_match(
        '/^(menu|cart|checkout|clear cart)$/iu',
        $lower
    ) || (bool) preg_match(
        '/^(add|order)\s*#?\s*\d+/iu',
        $lower
    );
}

/**
 * Label for tests / logging only — never used as a canned-reply switchboard.
 *
 * @return 'farewell'|'thanks'|'wellbeing'|'identity'|'meta'|'frustration'|'menu'|'other'
 */
function conversation_route_intent(string $message): string
{
    $text = trim($message);
    if ($text === '') {
        return 'other';
    }

    if (cart_message_is_farewell_or_decline($text)) {
        return 'farewell';
    }

    if (conversation_route_is_explicit_menu($text)) {
        return 'menu';
    }

    $lower = mb_strtolower(conversation_normalize_intent_text($text));
    if (preg_match('/\b(thank you|thanks|shukriya)\b/u', $lower) && mb_strlen($lower) < 48) {
        return 'thanks';
    }
    if (conversation_is_wellbeing_question($text)) {
        return 'wellbeing';
    }
    if (conversation_is_identity_question($text)) {
        return 'identity';
    }
    if (conversation_is_meta_activity_question($text)
        || preg_match('/\b(what are you doing|what you doing|right now|what are you up to)\b/u', $lower)
    ) {
        return 'meta';
    }
    if (conversation_is_bot_frustration($text)
        || preg_match(
            '/\b(reply only|only what i asked|you are saying|instead of understanding|'
            . 'don\'?t talk to me|you are confused|where are you|anyone there)\b/iu',
            $lower
        )
    ) {
        return 'frustration';
    }

    return 'other';
}

function conversation_route_allows_shop_buttons(string $intent, array $signals = []): bool
{
    $signals = array_map('strval', $signals);

    return in_array('MENU', $signals, true)
        || in_array('CART', $signals, true)
        || in_array('CATALOG', $signals, true)
        || $intent === 'menu';
}
