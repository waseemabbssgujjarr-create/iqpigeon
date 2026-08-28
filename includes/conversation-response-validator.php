<?php
/**
 * Response validation — guide §27. Ensures replies answer the turn, not generic scripts.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function conversation_is_generic_bot_reply(string $reply): bool
{
    if (conversation_is_generic_deflection_reply($reply)) {
        return true;
    }

    $lower = mb_strtolower(trim($reply));
    if ($lower === '') {
        return true;
    }

    return (bool) preg_match(
        '/^(i\'m here|i am here|ask me anything|got it — what specifically)/u',
        $lower
    ) || conversation_is_generic_menu_prompt_reply($reply)
        || (function_exists('conversation_is_shop_pitch_reply') && conversation_is_shop_pitch_reply($reply));
}

/**
 * @return array{ok: bool, reason?: string}
 */
function conversation_validate_customer_reply(int $leadId, string $reply, string $userMessage): array
{
    $reply = trim($reply);
    if ($reply === '') {
        return ['ok' => false, 'reason' => 'empty'];
    }

    if (conversation_is_generic_bot_reply($reply)) {
        return ['ok' => false, 'reason' => 'generic_deflection'];
    }

    if (conversation_is_marketing_dump_reply($reply)) {
        return ['ok' => false, 'reason' => 'marketing_dump'];
    }

    if (preg_match('/(?:…|\.\.\.)\s*$/u', $reply)
        || (mb_strlen($reply) > 90 && !preg_match('/[.!?]$/u', $reply))) {
        return ['ok' => false, 'reason' => 'truncated'];
    }

    if ($leadId > 0) {
        require_once __DIR__ . '/whatsapp-inbound.php';
        if (whatsapp_lead_has_prior_reply($leadId) && conversation_is_reintroduction_reply($reply)) {
            return ['ok' => false, 'reason' => 'reintroduction'];
        }

        if (conversation_would_repeat_reply($leadId, $reply)) {
            return ['ok' => false, 'reason' => 'repetitive'];
        }
    }

    return ['ok' => true];
}

/**
 * System hint when regenerating after a failed validation.
 */
function conversation_validation_retry_hint(string $reason, string $userMessage): string
{
    $base = 'Answer ONLY what the customer asked in their latest message. One short WhatsApp reply. '
        . 'Do NOT re-introduce yourself. Do NOT say "how can I help" or "ask me anything". '
        . 'Do NOT paste marketing copy.';

    if ($reason === 'repetitive') {
        return $base . ' Use different wording from your last reply.';
    }
    if ($reason === 'marketing_dump') {
        return $base . ' Give a direct factual answer in 1–2 sentences max.';
    }
    if ($reason === 'reintroduction') {
        return $base . ' You already introduced yourself in this chat.';
    }
    if ($reason === 'truncated') {
        return $base . ' Finish your answer completely — end with a full sentence, never with "..." or mid-word cuts.';
    }

    return $base . ' Their message: "' . mb_substr($userMessage, 0, 200) . '"';
}
