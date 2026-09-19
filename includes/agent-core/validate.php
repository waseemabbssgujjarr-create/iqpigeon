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
 * @param array<string, mixed> $conv
 * @return array{ok: bool, reason?: string}
 */
function agent_core_validate(string $draft, array $turnCtx, array $intent, array $plan, array $conv = []): array
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

    $decision = agent_core_validate_plan_decision($draft, $turnCtx, $plan, $conv);
    if (empty($decision['ok'])) {
        return $decision;
    }

    return ['ok' => true];
}

/**
 * Phase 1 plan-bound checks: known-info asks, dead-ends, repetition, false action claims.
 *
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $conv
 * @return array{ok: bool, reason?: string}
 */
function agent_core_validate_plan_decision(string $draft, array $turnCtx, array $plan, array $conv = []): array
{
    $lower = mb_strtolower(trim($draft));
    if ($lower === '') {
        return ['ok' => false, 'reason' => 'empty'];
    }

    $known = is_array($plan['known_information'] ?? null) ? $plan['known_information'] : [];
    $knownKeys = [];
    foreach ($known as $row) {
        $parts = explode('=', (string) $row, 2);
        if (isset($parts[0])) {
            $knownKeys[] = mb_strtolower(trim($parts[0]));
        }
    }
    $askPatterns = [
        'name'      => '/\b(what(?:\'s| is) your name|may i (?:have|get) your name|tell me your name)\b/u',
        'product'   => '/\b(which (?:item|product)|what (?:item|product) are you)\b/u',
        'service'   => '/\b(what service|which service)\b/u',
        'budget'    => '/\b(what(?:\'s| is) your budget)\b/u',
    ];
    foreach ($askPatterns as $key => $pattern) {
        $hasKnown = in_array($key, $knownKeys, true)
            || in_array('customer_' . $key, $knownKeys, true)
            || ($key === 'name' && in_array('customer_name', $knownKeys, true));
        if (!$hasKnown) {
            continue;
        }
        if (preg_match($pattern, $lower)) {
            return ['ok' => false, 'reason' => 'asks_known_info'];
        }
    }

    $nba = trim((string) ($plan['next_best_action'] ?? ''));
    $actionable = $nba !== '' && !in_array($nba, ['human_social_reply', 'cancel_pending'], true);
    if (!empty($plan['require_useful_cta'])) {
        $actionable = true;
    }
    if ($actionable && !empty($plan['forbid_generic_loop'])) {
        require_once dirname(__DIR__) . '/helpers.php';
        if (function_exists('conversation_is_generic_deflection_reply') && conversation_is_generic_deflection_reply($draft)) {
            return ['ok' => false, 'reason' => 'dead_end_generic'];
        }
        if (function_exists('conversation_is_canned_help_intro') && conversation_is_canned_help_intro($draft)) {
            return ['ok' => false, 'reason' => 'dead_end_generic'];
        }
        if (preg_match('/\b(feel free to (?:ask|reach out)|let me know if you need anything|hope this helps|would you like to know more|if you have any other questions|anything else i can help)\b/u', $lower)) {
            return ['ok' => false, 'reason' => 'dead_end_generic'];
        }
    }

    $last = trim((string) ($conv['last_assistant'] ?? ''));
    if ($last !== '' && agent_core_validate_similar_reply($last, $draft)) {
        return ['ok' => false, 'reason' => 'repetitive'];
    }
    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    if ($leadId > 0 && function_exists('conversation_would_repeat_reply')) {
        require_once dirname(__DIR__) . '/helpers.php';
        if (conversation_would_repeat_reply($leadId, $draft)) {
            return ['ok' => false, 'reason' => 'repetitive'];
        }
    }

    if (agent_core_validate_false_action_claim($draft, $plan)) {
        return ['ok' => false, 'reason' => 'false_action_claim'];
    }

    if (agent_core_validate_async_promise($draft, $plan, $turnCtx)) {
        return ['ok' => false, 'reason' => 'async_promise'];
    }

    $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
    $userMessage = (string) ($turnCtx['text'] ?? '');
    $canonicalOffer = function_exists('agent_core_canonical_offer_draft')
        ? agent_core_canonical_offer_draft($bot, $userMessage)
        : '';
    $isCanonicalOffer = $canonicalOffer !== '' && $draft === $canonicalOffer;
    if (!$isCanonicalOffer && agent_core_validate_unsupported_business_claim($draft, $plan)) {
        return ['ok' => false, 'reason' => 'unsupported_business_claim'];
    }

    $history = is_array($conv['history'] ?? null) ? $conv['history'] : [];
    if ($history !== [] && function_exists('conversation_is_reintroduction_reply')
        && conversation_is_reintroduction_reply($draft)
    ) {
        return ['ok' => false, 'reason' => 'reintroduction_loop'];
    }

    if (function_exists('conversation_intelligence_factuality_gate')) {
        require_once dirname(__DIR__) . '/conversation-intelligence.php';
        $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
        $leadId = (int) ($turnCtx['lead_id'] ?? 0);
        $analysis = [
            'primary_intent' => (string) ($plan['outcome'] ?? $plan['primary_intent'] ?? ''),
            'catalog'        => is_array($plan['catalog'] ?? null) ? $plan['catalog'] : [],
        ];
        $factual = conversation_intelligence_factuality_gate($draft, $bot, $leadId, $analysis);
        if (empty($factual['ok'])) {
            return ['ok' => false, 'reason' => (string) ($factual['reason'] ?? 'factuality')];
        }
    }

    if (agent_core_validate_false_price_claim($draft, $plan, $turnCtx)) {
        return ['ok' => false, 'reason' => 'false_price_claim'];
    }

    if (function_exists('agent_core_validate_cta_decision')) {
        $ctaVal = agent_core_validate_cta_decision($draft, $plan);
        if (empty($ctaVal['ok'])) {
            return $ctaVal;
        }
    }

    return ['ok' => true];
}

function agent_core_validate_similar_reply(string $previous, string $current): bool
{
    $a = mb_strtolower(preg_replace('/\s+/u', ' ', trim($previous)) ?? '');
    $b = mb_strtolower(preg_replace('/\s+/u', ' ', trim($current)) ?? '');
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    similar_text($a, $b, $pct);

    return $pct >= 88.0;
}

/**
 * @param array<string, mixed> $plan
 */
function agent_core_validate_false_action_claim(string $draft, array $plan): bool
{
    $lower = mb_strtolower(trim($draft));
    $toolResults = is_array($plan['tool_results'] ?? null) ? $plan['tool_results'] : [];
    $bookingVerified = false;
    if (is_file(__DIR__ . '/action-verification.php')) {
        require_once __DIR__ . '/action-verification.php';
        $bookingVerified = agent_action_claim_is_verified('booking_confirmed', $plan, $toolResults);
    }

    $claimPatterns = [
        '/\b(your (?:order|appointment|booking|reservation|coaching session|session) (?:is|has been) (?:confirmed|booked|placed|scheduled))\b/u',
        '/\b(i(?:\'ve| have) (?:booked|placed|confirmed|scheduled|paid|processed|sent) (?:your|the|a|payment|payment link))\b/u',
        '/\b(payment (?:is|has been) (?:received|confirmed|processed))\b/u',
        '/\b(it(?:\'s| is) all set — your)\b/u',
        '/\b(i(?:\'ve| have) (?:forwarded|delivered|sent) (?:your|the) (?:invitation|request|message))\b/u',
        '/\b(i(?:\'ve| have) contacted (?:the )?(?:team|owner|speaker|principal))\b/u',
        '/\b((?:the )?(?:speaker|guest|principal) (?:has been|is already) (?:notified|contacted))\b/u',
        '/\b(i(?:\'ve| have) (?:handed off|transferred you|connected you to) (?:a )?(?:human|team member|agent))\b/u',
    ];
    foreach ($claimPatterns as $pattern) {
        if (!preg_match($pattern, $lower)) {
            continue;
        }
        if ($bookingVerified && preg_match('/\b(book|appointment|reservation|scheduled)\b/u', $pattern)) {
            continue;
        }

        return true;
    }

    return false;
}

/**
 * Block fake async work ("I'll check and get back to you") without backend/tool evidence.
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $turnCtx
 */
function agent_core_validate_async_promise(string $draft, array $plan, array $turnCtx): bool
{
    require_once dirname(__DIR__) . '/helpers.php';
    $lower = mb_strtolower(trim($draft));
    if ($lower === '') {
        return false;
    }
    if ((string) ($plan['next_best_action'] ?? '') === 'confirm_pending') {
        return false;
    }

    $hasBookingTool = false;
    foreach (is_array($plan['tool_calls'] ?? null) ? $plan['tool_calls'] : [] as $call) {
        if (in_array((string) ($call['name'] ?? ''), ['booking.offer', 'booking.availability'], true)) {
            $hasBookingTool = true;
            break;
        }
    }
    $caps = is_array($turnCtx['profile']['capabilities'] ?? null) ? $turnCtx['profile']['capabilities'] : [];
    $hasBookingCap = in_array('booking', $caps, true);

    $asyncPatterns = [
        '/\b(i(?:\'ll| will) (?:check|confirm|verify|look into).{0,40}(?:availability|available|schedule|slot))\b/u',
        '/\b(i(?:\'ll| will) get back to you|come back to you shortly|reply (?:here )?soon|get back to you as soon as possible)\b/u',
        '/\b(let me check (?:the )?(?:availability|schedule|calendar)|one moment please)\b/u',
        '/\b(i(?:\'ll| will) notify you|i(?:\'ll| will) follow up|i(?:\'ll| will) confirm and (?:get back|reply))\b/u',
    ];
    $matchesAsync = false;
    foreach ($asyncPatterns as $pattern) {
        if (preg_match($pattern, $lower)) {
            $matchesAsync = true;
            break;
        }
    }
    if (!$matchesAsync && function_exists('conversation_is_stall_reply')) {
        $matchesAsync = conversation_is_stall_reply($draft);
    }
    if (!$matchesAsync) {
        return false;
    }

    $toolResults = is_array($plan['tool_results'] ?? null) ? $plan['tool_results'] : [];
    if (is_file(__DIR__ . '/action-verification.php')) {
        require_once __DIR__ . '/action-verification.php';
        if (preg_match('/\b(check|confirm|verify|availability|available|schedule|slot)\b/u', $lower)
            && agent_action_claim_is_verified('availability_stated', $plan, $toolResults)
        ) {
            return false;
        }
    }

    if ($hasBookingTool && preg_match('/\b(available|slot|opening|these times)\b/u', $lower)) {
        return false;
    }

    return true;
}

/**
 * Block unsupported coaching/event outcome claims and training/coaching price mix-ups.
 *
 * @param array<string, mixed> $plan
 */
function agent_core_validate_unsupported_business_claim(string $draft, array $plan): bool
{
    $lower = mb_strtolower(trim($draft));
    if ($lower === '') {
        return false;
    }

    $outcomePatterns = [
        '/\b(guaranteed to|will significantly improve|will dramatically|will fix your|100% success|proven to cure)\b/u',
        '/\b(medical treatment|clinical therapy|neurological treatment|will cure)\b/u',
    ];
    if ((string) ($plan['outcome'] ?? '') === 'EVENT_INVITATION' || ($plan['customer_need'] ?? '') === 'invite_speaker') {
        $outcomePatterns[] = '/\b(has accepted|can definitely attend|is available (?:tomorrow|on|for))\b/u';
        $outcomePatterns[] = '/\b(i(?:\'ve| have) (?:forwarded|delivered|sent) (?:the |your )?(?:invitation|request))\b/u';
    }
    foreach ($outcomePatterns as $pattern) {
        if (preg_match($pattern, $lower)) {
            return true;
        }
    }

    $bot = is_array($plan['_bot'] ?? null) ? $plan['_bot'] : [];
    if ($bot !== [] && function_exists('knowledge_reply_mixed_training_coaching_prices')) {
        require_once dirname(__DIR__) . '/bot-knowledge.php';
        if (knowledge_reply_mixed_training_coaching_prices($bot, $draft)) {
            return true;
        }
    } elseif (preg_match('/\b(training|workshop|corporate training|seminar)\b/u', $lower)
        && preg_match('/\$\s*\d+\s*(?:\/|\s*(?:per|an)\s*)?(?:hour|hr)\b/u', $lower)
    ) {
        return true;
    }

    return false;
}

/**
 * Block invented prices when plan expects verified business data only.
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $turnCtx
 */
function agent_core_validate_false_price_claim(string $draft, array $plan, array $turnCtx): bool
{
    if ((string) ($plan['next_best_action'] ?? '') !== 'answer_price') {
        return false;
    }
    if (!empty($plan['price_verified'])) {
        return false;
    }
    if (!preg_match('/\b(?:pkr|rs\.?\s?\d|\$\s?\d|usd\s?\d|\d{3,}[,\.]?\d*\s*(?:pkr|rs|\/mo|per))/iu', $draft)) {
        return false;
    }
    $pack = is_array($turnCtx['test_pack'] ?? null) ? $turnCtx['test_pack'] : [];
    $biz = is_array($pack['business_facts'] ?? null) ? $pack['business_facts'] : [];
    if ($biz !== [] && function_exists('agent_core_compose_has_price_evidence')) {
        return !agent_core_compose_has_price_evidence($pack, [], $biz);
    }

    return true;
}
