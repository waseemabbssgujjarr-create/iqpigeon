<?php
/**
 * Answer-fit validator. Folds conversation_validate_customer_reply; blocks catalog steal.
 *
 * @return array{ok: bool, reason?: string}
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $intent
 * @param array<string, mixed> $plan
 * @return array{ok: bool, reason?: string}
 */
function agent_core_validate(string $draft, array $turnCtx, array $intent, array $plan): array
{
    $draft = trim($draft);
    if ($draft === '') {
        return ['ok' => false, 'reason' => 'empty'];
    }

    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    $userMessage = (string) ($turnCtx['text'] ?? '');
    if (function_exists('conversation_validate_customer_reply') || is_file(dirname(__DIR__) . '/conversation-response-validator.php')) {
        require_once dirname(__DIR__) . '/conversation-response-validator.php';
        $base = conversation_validate_customer_reply($leadId, $draft, $userMessage);
        if (empty($base['ok'])) {
            $reason = (string) ($base['reason'] ?? '');
            $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
            $canonical = function_exists('agent_core_canonical_offer_draft')
                ? agent_core_canonical_offer_draft($bot, $userMessage)
                : '';
            $allowCatalog = $canonical !== ''
                && $draft === $canonical
                && in_array($reason, ['marketing_dump', 'truncated'], true);
            if (!$allowCatalog) {
                return $base;
            }
        }
    }

    $lower = mb_strtolower($draft);
    $tools = is_array($intent['tools'] ?? null) ? $intent['tools'] : [];
    $noShop = $tools === [] || (!in_array('catalog.search', $tools, true) && !in_array('cart.view', $tools, true));
    if ($noShop && (string) ($intent['kind'] ?? '') !== 'CATALOG') {
        if (preg_match('/\b(reply with a number|say \*menu\*|view catalog|showing \d)/u', $lower)) {
            return ['ok' => false, 'reason' => 'pitch_steal'];
        }
    }

    if (($intent['kind'] ?? '') === 'LIVE_WORLD' && preg_match('/\b(menu|add #\d|cash on delivery)\b/u', $lower)) {
        return ['ok' => false, 'reason' => 'pitch_steal'];
    }

    if (($intent['kind'] ?? '') === 'CORRECTION' && preg_match('/\b(reply with a number|here is (our|the) menu)\b/u', $lower)) {
        return ['ok' => false, 'reason' => 'pitch_steal'];
    }

    if (($intent['kind'] ?? '') === 'MEDIA' && preg_match('/\b(got (your|the) (image|photo|picture))\b/u', $lower)
        && str_contains(mb_strtolower($userMessage), 'analysis unavailable')
    ) {
        return ['ok' => false, 'reason' => 'media_unseen'];
    }

    if (function_exists('conversation_mind_is_leak') && conversation_mind_is_leak($draft)) {
        return ['ok' => false, 'reason' => 'leak'];
    }

    return ['ok' => true];
}
