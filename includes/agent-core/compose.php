<?php
/**
 * Human-like reply via conversation_mind_generate — not a second OpenAI brain.
 */
declare(strict_types=1);

/**
 * Canonical training offer list when the inbound is an offer/service question.
 * Empty when this is not an offer question or this bot has no listed services.
 * Local knowledge — does not call OpenAI.
 *
 * @param array<string, mixed> $bot
 */
function agent_core_canonical_offer_draft(array $bot, string $userMessage): string
{
    $userMessage = trim($userMessage);
    if ($userMessage === '' || $userMessage === '[Customer sent a message]') {
        return '';
    }
    $knowledge = dirname(__DIR__) . '/bot-knowledge.php';
    if (!is_file($knowledge)) {
        return '';
    }
    require_once $knowledge;
    if (!function_exists('knowledge_message_is_offer_question')
        || !function_exists('knowledge_offer_list_reply')
        || !knowledge_message_is_offer_question($userMessage)
    ) {
        return '';
    }

    return trim(knowledge_offer_list_reply($bot));
}

/**
 * Canonical business location from owner profile / training — no OpenAI.
 *
 * @param array<string, mixed> $bot
 */
function agent_core_canonical_location_draft(array $bot, string $userMessage): string
{
    $userMessage = trim($userMessage);
    if ($userMessage === '' || $userMessage === '[Customer sent a message]') {
        return '';
    }
    require_once dirname(__DIR__) . '/conversation-intent.php';
    if (!function_exists('conversation_is_location_question') || !conversation_is_location_question($userMessage)) {
        return '';
    }
    require_once dirname(__DIR__) . '/bot-knowledge.php';
    if (!function_exists('bot_owner_profile_fields')) {
        return '';
    }

    $profile = bot_owner_profile_fields($bot);
    $address = trim((string) ($profile['address'] ?? ''));
    if ($address !== '') {
        return "We're at {$address}.";
    }

    $corpus = trim((string) ($bot['bot_knowledge'] ?? '') . "\n" . (string) ($bot['business_model'] ?? ''));
    if ($corpus !== '' && preg_match('/\b(?:address|located|location|based in|office)\s*[:\-]\s*([^\n.]{4,120})/iu', $corpus, $m)) {
        $line = trim((string) ($m[1] ?? ''));
        if ($line !== '') {
            return "We're at {$line}.";
        }
    }

    $city = function_exists('bot_extract_city') ? bot_extract_city($corpus) : '';
    if ($city !== '') {
        return "We're based in {$city}.";
    }

    return '';
}

/**
 * LIVE_WORLD fast path — one live-answer call from verified web evidence, no full mind_generate.
 *
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $bot
 */
function agent_core_compose_live_world_draft(
    array $pack,
    array $plan,
    array $toolResults,
    array $turnCtx,
    array $conv,
    string $userMessage,
    array $bot,
    int $leadId
): string {
    if ((string) ($plan['outcome'] ?? '') !== 'LIVE_WORLD') {
        return '';
    }

    $route = is_array($plan['route'] ?? null) ? $plan['route'] : [];
    $needsWeb = !empty($route['needs_web']);
    if (!$needsWeb) {
        require_once dirname(__DIR__) . '/live-world-info.php';
        $needsWeb = live_world_message_needs_fresh_evidence($userMessage, '');
    }
    if (!$needsWeb) {
        return '';
    }

    $live = null;
    foreach ($toolResults as $row) {
        if ((string) ($row['name'] ?? '') === 'live_web.search') {
            $live = is_array($row['data'] ?? null) ? $row['data'] : null;
            break;
        }
    }

    require_once dirname(__DIR__) . '/conversation-mind.php';
    $search = is_array($live) ? $live : ['needed' => true, 'ok' => false, 'evidence' => ''];
    if (empty($search['needed'])) {
        $search['needed'] = true;
    }

    $usable = function_exists('live_world_search_is_usable') && live_world_search_is_usable($search);
    if (!$usable) {
        agent_core_compose_widget_log('live_world_unverified', $turnCtx);

        return function_exists('conversation_mind_unverified_live_reply')
            ? conversation_mind_unverified_live_reply($bot)
            : '';
    }

    $mindCtx = agent_core_mind_ctx_from_plan($pack, $plan, $toolResults, $turnCtx, $conv);
    $answer = trim(conversation_mind_live_answer($bot, $userMessage, $mindCtx, $search));
    if ($answer !== '') {
        agent_core_compose_widget_log('live_world_answer', $turnCtx);
    }

    return $answer;
}

/**
 * Temporary widget compose stage markers — search error_log for widget_core_compose:
 *
 * @param array<string, mixed> $turnCtx
 */
function agent_core_compose_widget_log(string $stage, array $turnCtx): void
{
    if (strtolower(trim((string) ($turnCtx['channel'] ?? ''))) !== 'widget') {
        return;
    }
    error_log(
        'widget_core_compose: stage=' . $stage
        . ' bot=' . (int) ($turnCtx['bot_id'] ?? ($turnCtx['bot']['id'] ?? 0))
        . ' lead=' . (int) ($turnCtx['lead_id'] ?? 0)
    );
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 */
function agent_core_compose(array $pack, array $plan, array $toolResults, array $turnCtx, array $conv, string $retryHint = ''): string
{
    if (isset($GLOBALS['agent_core_test_draft']) && is_string($GLOBALS['agent_core_test_draft'])) {
        return $GLOBALS['agent_core_test_draft'];
    }

    $bot = is_array($turnCtx['bot'] ?? null) ? $turnCtx['bot'] : [];
    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    $userMessage = trim((string) ($turnCtx['text'] ?? ''));
    if ($userMessage === '') {
        $userMessage = '[Customer sent a message]';
    }

    $canonical = agent_core_canonical_offer_draft($bot, $userMessage);
    if ($canonical !== '') {
        agent_core_compose_widget_log('offer_canonical', $turnCtx);
        return mb_substr($canonical, 0, 900);
    }

    $locationDraft = agent_core_canonical_location_draft($bot, $userMessage);
    if ($locationDraft !== '') {
        agent_core_compose_widget_log('location_canonical', $turnCtx);
        return mb_substr($locationDraft, 0, 900);
    }

    $liveDraft = agent_core_compose_live_world_draft($pack, $plan, $toolResults, $turnCtx, $conv, $userMessage, $bot, $leadId);
    if ($liveDraft !== '') {
        return mb_substr($liveDraft, 0, 900);
    }

    // Budget contract: wa_skip_openai blocks the old human-layer OpenAI helpers,
    // not conversation_mind_generate (already the live WhatsApp brain after ACK + 7s quiet).
    if (!agent_core_may_call_mind_generate()) {
        agent_core_compose_widget_log('mind_generate_blocked', $turnCtx);
        return '';
    }

    require_once dirname(__DIR__) . '/conversation-mind.php';
    if (!function_exists('conversation_mind_generate')) {
        agent_core_compose_widget_log('mind_generate_missing', $turnCtx);
        return '';
    }

    agent_core_compose_widget_log('mind_generate_start', $turnCtx);
    $mindCtx = agent_core_mind_ctx_from_plan($pack, $plan, $toolResults, $turnCtx, $conv, $retryHint);
    try {
        $draft = trim(conversation_mind_generate($bot, $leadId, $userMessage, $mindCtx));
        if ($draft === '') {
            agent_core_compose_widget_log('mind_generate_empty', $turnCtx);
            return '';
        }

        agent_core_compose_widget_log('mind_generate_ok', $turnCtx);
        return mb_substr($draft, 0, 900);
    } catch (Throwable $e) {
        error_log('agent_core_compose: ' . $e->getMessage());
        agent_core_compose_widget_log('mind_generate_error', $turnCtx);

        return '';
    }
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_core_mind_ctx_from_plan(array $pack, array $plan, array $toolResults, array $turnCtx, array $conv, string $retryHint = ''): array
{
    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    $hours = '';
    $orders = '';
    $live = null;
    $catalogLines = [];
    foreach ($toolResults as $row) {
        $name = (string) ($row['name'] ?? '');
        $data = $row['data'] ?? null;
        if ($name === 'memory.read' && is_array($data)) {
            $memory = $memory === [] ? $data : array_merge($data, $memory);
        } elseif ($name === 'hours.read' && is_string($data)) {
            $hours = $data;
        } elseif ($name === 'orders.read' && is_string($data)) {
            $orders = $data;
        } elseif ($name === 'live_web.search' && is_array($data)) {
            $live = $data;
        } elseif ($name === 'catalog.search' && is_array($data)) {
            foreach (array_slice($data, 0, 3) as $hit) {
                $p = is_array($hit['product'] ?? null) ? $hit['product'] : [];
                $n = trim((string) ($p['name'] ?? ''));
                if ($n !== '') {
                    $catalogLines[] = 'product: ' . $n;
                }
            }
        } elseif ($name === 'catalog.get_product' && is_array($data)) {
            $n = trim((string) ($data['name'] ?? ''));
            if ($n !== '') {
                $catalogLines[] = 'product: ' . $n;
            }
        } elseif ($name === 'booking.offer' && is_string($data) && $data !== '') {
            $catalogLines[] = $data;
        } elseif ($name === 'cart.view' && is_string($data) && $data !== '') {
            $catalogLines[] = $data;
        }
    }

    $biz = is_array($pack['business_facts'] ?? null) ? $pack['business_facts'] : [];
    if (!is_array($biz)) {
        $biz = $biz !== '' ? [(string) $biz] : [];
    }
    $biz = array_merge($biz, is_array($conv['business_facts'] ?? null) ? $conv['business_facts'] : []);
    $qual = trim((string) ($pack['qualify_read'] ?? ''));
    if ($qual !== '') {
        $biz[] = 'Qualify questions (internal, do not dump as a form): ' . mb_substr($qual, 0, 400);
    }
    if ($catalogLines !== []) {
        $biz[] = implode("\n", $catalogLines);
    }

    $route = is_array($plan['route'] ?? null) ? $plan['route'] : [];
    $answerKind = (string) ($plan['answer_kind'] ?? '');
    $mediaBits = [];
    foreach (is_array($turnCtx['understanding'] ?? null) ? $turnCtx['understanding'] : [] as $u) {
        $t = (string) ($u['type'] ?? '');
        if ($t === 'image' && trim((string) ($u['image_description'] ?? '')) !== '') {
            $mediaBits[] = 'image: ' . mb_substr((string) $u['image_description'], 0, 180);
        } elseif ($t === 'audio' && trim((string) ($u['text'] ?? '')) !== '') {
            $mediaBits[] = 'voice: ' . mb_substr((string) $u['text'], 0, 180);
        } elseif ($t === 'document' && trim((string) ($u['extracted_content'] ?? '')) !== '') {
            $mediaBits[] = 'document: ' . mb_substr((string) $u['extracted_content'], 0, 180);
        }
    }
    $planNote = 'INTERNAL PLAN: Answer this first: ' . (string) ($plan['answer_first'] ?? '')
        . '. Answer kind: ' . ($answerKind !== '' ? $answerKind : (string) ($plan['outcome'] ?? ''))
        . '. Source: ' . (string) ($plan['source'] ?? '')
        . '. Asked: ' . mb_substr((string) ($plan['asked'] ?? ''), 0, 180)
        . '. Referent: ' . (string) ($plan['referent'] ?? '')
        . '. Do not open a menu or catalog unless catalog.search, catalog.get_product, or cart.view ran.'
        . ' If live evidence is missing for a current-world question, say you could not verify it.'
        . ' Do not mention tools, plans, or internal stages.';
    if ($mediaBits !== []) {
        $planNote .= ' Media understanding: ' . implode(' | ', $mediaBits);
    }
    if ($retryHint !== '') {
        $planNote .= ' ' . $retryHint;
    }

    return [
        'mode'             => (string) ($conv['mind_mode'] ?? 'FOLLOW_UP'),
        'intent'           => (string) ($plan['outcome'] ?? 'FOLLOW_UP'),
        'facts'            => is_array($conv['personal_facts'] ?? null) ? $conv['personal_facts'] : [],
        'biz_facts'        => $biz,
        'summary'          => (string) ($conv['summary'] ?? ''),
        'history'          => is_array($conv['history'] ?? null) ? $conv['history'] : [],
        'customer_memory'  => $memory,
        'source_route'     => $route,
        'order_history'    => $orders,
        'hours_now'        => $hours,
        'live_world'       => $live,
        'runtime_prompt'   => (string) ($pack['prompt'] ?? ''),
        'plan_note'        => $planNote,
    ];
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turnCtx
 */
function agent_core_compose_fallback(array $pack, array $plan, array $toolResults, array $turnCtx): string
{
    $kind = (string) ($plan['outcome'] ?? '');
    $rep = (string) ($pack['rep'] ?? 'I');
    $brand = (string) ($pack['brand'] ?? 'us');
    $answer = trim((string) ($plan['answer_first'] ?? ''));

    if ($kind === 'LIVE_WORLD' || (string) ($plan['answer_kind'] ?? '') === 'GENERAL') {
        $ev = '';
        foreach ($toolResults as $row) {
            if (($row['name'] ?? '') !== 'live_web.search') {
                continue;
            }
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $ev = trim((string) ($data['evidence'] ?? ''));
        }
        if ($ev !== '') {
            return mb_substr($ev, 0, 400);
        }
        if ($kind === 'LIVE_WORLD') {
            return "I couldn't verify the latest information just now, so I don't want to give you a stale answer. Ask me again in a moment — or tell me how I can help with {$brand}.";
        }
    }
    if ($kind === 'CORRECTION' && $answer !== '' && $answer !== 'the customer\'s latest message') {
        return "You're right — I missed that. " . mb_substr($answer, 0, 180);
    }
    if ($kind === 'BOOKING') {
        $slots = '';
        foreach ($toolResults as $row) {
            if (($row['name'] ?? '') === 'booking.offer') {
                $slots = trim((string) ($row['data'] ?? ''));
            }
        }
        if ($slots !== '') {
            return $slots;
        }

        return "We don't take appointments in this chat. I can still help with {$brand} — what do you need?";
    }
    if ($kind === 'MEDIA') {
        $text = (string) ($turnCtx['text'] ?? '');
        if (str_contains(mb_strtolower($text), 'analysis unavailable')) {
            return "I can see you sent a photo, but I couldn't read it clearly yet. Tell me what you want me to look at.";
        }

        return "I've got the photo. " . mb_substr($text, 0, 200);
    }
    if ($kind === 'GREETING') {
        return "Hey — I'm {$rep} at {$brand}. How's it going?";
    }

    $hours = '';
    $orders = '';
    foreach ($toolResults as $row) {
        if (($row['name'] ?? '') === 'hours.read') {
            $hours = trim((string) ($row['data'] ?? ''));
        }
        if (($row['name'] ?? '') === 'orders.read') {
            $orders = trim((string) ($row['data'] ?? ''));
        }
    }
    if ((string) ($plan['answer_kind'] ?? '') === 'MIXED' && ($hours !== '' || $orders !== '')) {
        $bits = array_filter([$hours, $orders]);

        return mb_substr(implode(' ', $bits), 0, 400);
    }

    if ($answer !== '' && $answer !== 'the customer\'s latest message' && mb_strlen($answer) < 160 && !str_contains($answer, 'referring to')) {
        return $answer;
    }

    return "Got you. I'm listening — what's on your mind?";
}

/**
 * @param list<array<string, mixed>> $toolResults
 */
function agent_core_tool_evidence_text(array $toolResults): string
{
    $lines = [];
    foreach ($toolResults as $row) {
        $name = (string) ($row['name'] ?? '');
        $data = $row['data'] ?? null;
        if ($name === 'catalog.search' && is_array($data)) {
            foreach (array_slice($data, 0, 3) as $hit) {
                $p = is_array($hit['product'] ?? null) ? $hit['product'] : [];
                $n = trim((string) ($p['name'] ?? ''));
                if ($n !== '') {
                    $lines[] = 'product: ' . $n;
                }
            }
        } elseif (is_string($data) && $data !== '') {
            $lines[] = $name . ': ' . mb_substr($data, 0, 400);
        } elseif (is_array($data) && isset($data['evidence'])) {
            $lines[] = $name . ': ' . mb_substr((string) $data['evidence'], 0, 400);
        }
    }

    return implode("\n", $lines);
}
