<?php
/**
 * Live WhatsApp budget / OpenAI contract.
 *
 * turn_engine_send_leads_now() sets:
 *   $GLOBALS['wa_webhook_budget'] = true  → budget compose fork (persist, then mind)
 *   $GLOBALS['wa_skip_openai']     = true  → skip wa_human_openai_reply and
 *                                            wa_webhook_friend_openai
 *
 * Why skip_openai exists:
 * The old compose path could still call wa_human_openai_reply (a second, unbounded
 * OpenAI round-trip) after the 7s quiet window. That extra call was the latency
 * risk: ACK is already flushed, but worker/Meta timing still needs one generation
 * pass, not two. The flag therefore means "do not run the human-layer OpenAI",
 * not "do not think at all".
 *
 * conversation_mind_generate() is already the live WhatsApp brain and already
 * runs on budgeted turns via webhook_mind while skip_openai is true.
 *
 * Minimum safe Core mechanism (Core still OFF in production):
 * 1. Compose stays after ACK + 7s quiet — do not move generation into the webhook.
 * 2. Core may call conversation_mind_generate (same function/timeouts as live mind).
 * 3. Core must not call wa_human_openai_reply, wa_webhook_friend_openai, or a
 *    second ai_chat compose.
 * 4. If that generate cannot run, return an unusable Core result so
 *    wa_auto_reply_compose() falls back to webhook_mind.
 */
declare(strict_types=1);

function agent_core_skip_human_openai(): bool
{
    return !empty($GLOBALS['wa_skip_openai']);
}

/**
 * Mind generate is allowed on budgeted WhatsApp turns — same as webhook_mind.
 * Fixture flag agent_core_no_network is the only local block.
 */
function agent_core_may_call_mind_generate(): bool
{
    if (!empty($GLOBALS['agent_core_no_network'])) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $core
 */
function agent_core_result_usable(array $core): bool
{
    return !empty($core['ok']) && trim((string) ($core['reply'] ?? '')) !== '';
}
