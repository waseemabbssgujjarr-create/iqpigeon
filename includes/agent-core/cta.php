<?php
/**
 * Agent Mind Phase 2C — structured CTA engine, answer/advance, stop/dead-end planner hints.
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_CORE_CTA_MODES = [
    'NONE',
    'SOCIAL',
    'INVITE',
    'ASK_REQUIRED',
    'OFFER_RECOMMENDATION',
    'OFFER_PRODUCT',
    'OFFER_SERVICE',
    'OFFER_AVAILABILITY',
    'OFFER_BOOKING',
    'OFFER_QUOTE',
    'OFFER_ORDER',
    'OFFER_CHECKOUT',
    'OFFER_TRACKING',
    'OFFER_SUPPORT',
    'OFFER_HUMAN',
    'CONFIRM',
    'CONTINUE',
    'COMPLETE',
    'STOP',
];

/** @var list<string> */
const AGENT_CORE_ANSWER_ADVANCE_MODES = [
    'ANSWER_ONLY',
    'ANSWER_PLUS_ADVANCE',
    'QUESTION_ONLY',
    'ACTION_CONFIRMATION',
    'STOP',
];

/**
 * Apply structured CTA decision after Phase 2B NBA selection.
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function agent_core_cta_apply(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $pack
): array {
    $ctx = agent_core_cta_build_context($plan, $intelligence, $turnCtx, $conv, $pack);
    $candidates = agent_core_cta_generate_candidates($ctx);
    $eligible = [];
    foreach ($candidates as $mode) {
        if (agent_core_cta_eligibility($mode, $ctx)['eligible']) {
            $eligible[] = $mode;
        }
    }
    $selected = agent_core_cta_select($eligible, $ctx);
    $answerAdvance = agent_core_cta_answer_advance($selected, $ctx);
    $target = agent_core_cta_target($selected, $ctx);
    $strategy = agent_core_cta_text_strategy($selected, $answerAdvance, $target, $ctx);
    $reasonCodes = agent_core_cta_reason_codes($selected, $answerAdvance, $ctx);
    $confidence = agent_core_cta_confidence($selected, $ctx);
    $advanceInfo = agent_core_cta_advance_required_info($selected, $ctx);

    $plan['cta_mode'] = $selected;
    $plan['cta_required'] = !in_array($selected, ['NONE', 'STOP', 'SOCIAL'], true);
    $plan['cta_text_strategy'] = $strategy;
    $plan['cta_target'] = $target;
    $plan['cta_confidence'] = $confidence;
    $plan['cta_reason_codes'] = $reasonCodes;
    $plan['cta_eligibility'] = $eligible;
    $plan['answer_advance'] = $answerAdvance;
    $plan['advance_type'] = $selected;
    $plan['advance_required_information'] = $advanceInfo;
    $plan['cta_hint'] = $strategy;
    $plan['require_useful_cta'] = agent_core_cta_require_useful($ctx, $selected, $answerAdvance);
    $plan['support_need'] = !empty($ctx['support_need']);

    if (in_array($selected, ['NONE', 'STOP'], true)) {
        $plan['cta_required'] = false;
        $plan['stop_allowed'] = true;
        $plan['advance_allowed'] = false;
    }
    if ($answerAdvance === 'STOP') {
        $plan['stop_allowed'] = true;
        $plan['advance_allowed'] = false;
        $plan['forbid_generic_loop'] = true;
    }
    if ($answerAdvance === 'ANSWER_PLUS_ADVANCE' && $selected !== 'NONE') {
        $plan['advance_allowed'] = true;
        $plan['forbid_generic_loop'] = true;
    }
    if ($answerAdvance === 'QUESTION_ONLY') {
        $plan['clarification_needed'] = true;
        $plan['forbid_generic_loop'] = true;
    }
    if ($answerAdvance === 'ACTION_CONFIRMATION') {
        $plan['forbid_generic_loop'] = true;
    }
    if ($ctx['support_need'] && in_array($selected, ['OFFER_ORDER', 'OFFER_CHECKOUT', 'OFFER_BOOKING'], true)) {
        $plan['cta_mode'] = 'OFFER_SUPPORT';
        $plan['advance_type'] = 'OFFER_SUPPORT';
        $plan['cta_text_strategy'] = agent_core_cta_text_strategy('OFFER_SUPPORT', $answerAdvance, 'order_issue', $ctx);
        $plan['cta_hint'] = $plan['cta_text_strategy'];
    }
    if ($plan['require_useful_cta']) {
        $plan['forbid_generic_loop'] = true;
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Provide a meaningful next step — not sympathy-only or generic closers.';
    }

    if (!empty($ctx['support_need'])
        || in_array((string) ($plan['selected_action'] ?? ''), ['RESOLVE_SUPPORT', 'TRACK_ORDER', 'HANDLE_OBJECTION'], true)
        || in_array((string) ($plan['customer_goal'] ?? ''), ['resolve_issue', 'return', 'cancel'], true)
    ) {
        $plan['advance_allowed'] = false;
    }

    if (!empty($plan['combined_action_possible'])) {
        $plan['response_goal'] = trim((string) ($plan['response_goal'] ?? ''))
            . ' Single combined response — one CTA only.';
    }

    return $plan;
}

/**
 * @return array<string, mixed>
 */
function agent_core_cta_build_context(
    array $plan,
    array $intelligence,
    array $turnCtx,
    array $conv,
    array $pack
): array {
    $text = trim((string) ($turnCtx['text'] ?? ''));
    $missing = is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [];
    $requiredMissing = array_values(array_diff($missing, ['qualification_fields']));
    $known = is_array($plan['known_information'] ?? null) ? $plan['known_information'] : [];
    $caps = is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [];
    $entities = is_array($intelligence['entities'] ?? null) ? $intelligence['entities'] : [];

    return [
        'text'               => $text,
        'lower'              => mb_strtolower($text),
        'selected_action'    => trim((string) ($plan['selected_action'] ?? 'ANSWER')),
        'goal'               => trim((string) ($plan['customer_goal'] ?? '')),
        'need'               => trim((string) ($plan['customer_need'] ?? '')),
        'need_detail'        => trim((string) ($plan['customer_need_detail'] ?? '')),
        'readiness'          => trim((string) ($plan['readiness'] ?? 'EXPLORING')),
        'primary'            => trim((string) ($plan['primary_intent'] ?? '')),
        'emotion'            => trim((string) ($plan['emotion'] ?? 'neutral')),
        'missing'            => $missing,
        'required_missing'   => $requiredMissing,
        'known'              => $known,
        'caps'               => $caps,
        'knowledge_available'=> !empty($plan['knowledge_available']),
        'message_budget'     => (int) ($plan['message_budget'] ?? 2),
        'action_confidence'  => (float) ($plan['action_confidence'] ?? 0.5),
        'multi_intent'       => !empty($plan['multi_intent']) || !empty($plan['multi_intent_combined']),
        'objection'          => !empty($plan['objection']),
        'objection_type'     => trim((string) ($plan['objection_type'] ?? '')),
        'objection_strategy' => trim((string) ($plan['objection_strategy'] ?? '')),
        'combined_action'    => !empty($plan['combined_action_possible']),
        'resolved_intents'   => is_array($plan['resolved_intents'] ?? null) ? $plan['resolved_intents'] : [],
        'deferred_intents'   => is_array($plan['deferred_intents'] ?? null) ? $plan['deferred_intents'] : [],
        'support_need'       => !empty($plan['support_need'])
            || in_array($plan['customer_need'] ?? '', ['resolve_delivery', 'obtain_refund', 'personal_support'], true)
            || in_array($plan['customer_goal'] ?? '', ['resolve_issue', 'return', 'get_support', 'track_order'], true)
            || in_array($plan['selected_action'] ?? '', ['RESOLVE_SUPPORT', 'TRACK_ORDER'], true),
        'affirmation'        => trim((string) ($intelligence['affirmation'] ?? 'none')),
        'entities'           => $entities,
        'plan'               => $plan,
        'pack'               => $pack,
    ];
}

/**
 * @param array<string, mixed> $ctx
 * @return list<string>
 */
function agent_core_cta_generate_candidates(array $ctx): array
{
    $action = (string) ($ctx['selected_action'] ?? 'ANSWER');
    $map = [
        'STOP'                        => ['STOP', 'NONE'],
        'FOLLOW_UP'                   => ['SOCIAL', 'NONE', 'INVITE'],
        'COLLECT_REQUIRED_INFORMATION'=> ['ASK_REQUIRED'],
        'CLARIFY'                     => ['ASK_REQUIRED'],
        'QUALIFY'                     => ['ASK_REQUIRED'],
        'RESOLVE_SUPPORT'             => ['OFFER_SUPPORT', 'OFFER_TRACKING', 'OFFER_HUMAN'],
        'TRACK_ORDER'                 => ['OFFER_TRACKING', 'OFFER_SUPPORT'],
        'OFFER_HUMAN_HANDOFF'         => ['OFFER_HUMAN'],
        'HANDLE_OBJECTION'            => ['CONTINUE', 'OFFER_RECOMMENDATION', 'NONE', 'INVITE'],
        'OFFER_QUOTE'                 => ['OFFER_QUOTE', 'OFFER_PRODUCT', 'OFFER_ORDER', 'INVITE', 'NONE'],
        'RECOMMEND'                   => ['OFFER_RECOMMENDATION', 'OFFER_PRODUCT', 'INVITE'],
        'SHOW_PRODUCT'                => ['OFFER_PRODUCT', 'OFFER_ORDER', 'OFFER_RECOMMENDATION'],
        'SHOW_SERVICE'                => ['OFFER_SERVICE', 'OFFER_BOOKING', 'INVITE'],
        'CHECK_AVAILABILITY'          => ['OFFER_AVAILABILITY', 'OFFER_PRODUCT'],
        'OFFER_BOOKING'               => ['OFFER_BOOKING', 'ASK_REQUIRED', 'CONFIRM'],
        'START_ORDER'                 => ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM'],
        'START_CHECKOUT'              => ['OFFER_CHECKOUT', 'CONFIRM'],
        'COMPLETE'                    => ['CONFIRM', 'COMPLETE'],
        'ANSWER'                      => ['INVITE', 'NONE', 'OFFER_RECOMMENDATION', 'CONTINUE'],
    ];
    $out = $map[$action] ?? ['NONE', 'INVITE'];
    if (($ctx['goal'] ?? '') === 'stop') {
        $out = ['STOP', 'NONE'];
    }
    if (($ctx['readiness'] ?? '') === 'LOW' || ($ctx['need'] ?? '') === 'explore_options') {
        $out = array_values(array_diff($out, ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM', 'COMPLETE']));
    }

    return array_values(array_unique(array_filter($out, static fn ($m) => in_array($m, AGENT_CORE_CTA_MODES, true))));
}

/**
 * @param array<string, mixed> $ctx
 * @return array{eligible: bool, blockers: list<string>}
 */
function agent_core_cta_eligibility(string $mode, array $ctx): array
{
    $blockers = [];
    $caps = is_array($ctx['caps'] ?? null) ? $ctx['caps'] : [];
    $has = static fn (string $c): bool => in_array($c, $caps, true);

    if ($mode === 'OFFER_BOOKING' && !$has('booking')) {
        $blockers[] = 'capability_unavailable';
    }
    if (in_array($mode, ['OFFER_ORDER', 'OFFER_CHECKOUT'], true) && !$has('cart')) {
        $blockers[] = 'capability_unavailable';
    }
    if (in_array($mode, ['OFFER_PRODUCT', 'OFFER_RECOMMENDATION', 'OFFER_AVAILABILITY'], true) && !$has('catalog')) {
        $blockers[] = 'capability_unavailable';
    }
    if ($mode === 'OFFER_QUOTE' && empty($ctx['knowledge_available'])) {
        $blockers[] = 'knowledge_unavailable';
    }
    if (($ctx['support_need'] ?? false) && in_array($mode, ['OFFER_ORDER', 'OFFER_CHECKOUT', 'OFFER_BOOKING', 'OFFER_PRODUCT'], true)) {
        $blockers[] = 'support_resolution';
    }
    if (($ctx['goal'] ?? '') === 'stop' && !in_array($mode, ['STOP', 'NONE', 'SOCIAL'], true)) {
        $blockers[] = 'customer_stopping';
    }
    if (($ctx['readiness'] ?? '') === 'LOW' && in_array($mode, ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM'], true)) {
        $blockers[] = 'customer_browsing';
    }
    if ($mode === 'ASK_REQUIRED' && ($ctx['required_missing'] ?? []) === []) {
        $blockers[] = 'no_missing_required';
    }
    if ($mode === 'OFFER_HUMAN' && ($ctx['goal'] ?? '') === 'social') {
        $blockers[] = 'unnecessary_handoff';
    }
    if (($ctx['objection'] ?? false) && in_array($mode, ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM'], true)) {
        $blockers[] = 'objection';
    }

    return ['eligible' => $blockers === [], 'blockers' => $blockers];
}

/**
 * @param list<string> $eligible
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_select(array $eligible, array $ctx): string
{
    if ($eligible === []) {
        return ($ctx['goal'] ?? '') === 'stop' ? 'STOP' : 'NONE';
    }

    $objType = (string) ($ctx['objection_type'] ?? '');
    if ($objType === 'NOT_INTERESTED') {
        foreach (['STOP', 'NONE'] as $mode) {
            if (in_array($mode, $eligible, true)) {
                return $mode;
            }
        }
    }
    if ($objType === 'TIMING') {
        foreach (['NONE', 'INVITE', 'CONTINUE'] as $mode) {
            if (in_array($mode, $eligible, true)) {
                return $mode;
            }
        }
    }
    if (!empty($ctx['combined_action']) && count($ctx['resolved_intents'] ?? []) >= 2) {
        foreach (['OFFER_QUOTE', 'OFFER_PRODUCT', 'OFFER_AVAILABILITY', 'INVITE', 'CONTINUE'] as $mode) {
            if (in_array($mode, $eligible, true)) {
                return $mode;
            }
        }
    }

    $action = (string) ($ctx['selected_action'] ?? 'ANSWER');
    $priority = match ($action) {
        'STOP' => ['STOP', 'NONE'],
        'FOLLOW_UP' => ['SOCIAL', 'NONE'],
        'COLLECT_REQUIRED_INFORMATION', 'CLARIFY' => ['ASK_REQUIRED'],
        'RESOLVE_SUPPORT' => ['OFFER_SUPPORT', 'OFFER_TRACKING', 'OFFER_HUMAN'],
        'TRACK_ORDER' => ['OFFER_TRACKING', 'OFFER_SUPPORT'],
        'OFFER_HUMAN_HANDOFF' => ['OFFER_HUMAN'],
        'HANDLE_OBJECTION' => ['CONTINUE', 'OFFER_RECOMMENDATION', 'INVITE', 'NONE'],
        'OFFER_QUOTE' => empty($ctx['knowledge_available'])
            ? ['INVITE', 'OFFER_HUMAN', 'NONE']
            : ['OFFER_QUOTE', 'OFFER_PRODUCT', 'OFFER_ORDER', 'INVITE'],
        'RECOMMEND' => ['OFFER_RECOMMENDATION', 'OFFER_PRODUCT', 'INVITE'],
        'SHOW_PRODUCT' => ['OFFER_PRODUCT', 'OFFER_ORDER'],
        'SHOW_SERVICE' => ['OFFER_SERVICE', 'OFFER_BOOKING', 'INVITE'],
        'CHECK_AVAILABILITY' => ['OFFER_AVAILABILITY', 'OFFER_PRODUCT'],
        'OFFER_BOOKING' => ($ctx['required_missing'] ?? []) !== [] ? ['ASK_REQUIRED', 'OFFER_BOOKING'] : ['OFFER_BOOKING', 'CONFIRM'],
        'START_ORDER' => ['OFFER_ORDER', 'OFFER_CHECKOUT', 'CONFIRM'],
        'START_CHECKOUT' => ['OFFER_CHECKOUT', 'CONFIRM'],
        'COMPLETE' => ['CONFIRM', 'COMPLETE'],
        default => ['INVITE', 'NONE', 'CONTINUE'],
    };
    foreach ($priority as $mode) {
        if (in_array($mode, $eligible, true)) {
            return $mode;
        }
    }

    return $eligible[0];
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_answer_advance(string $ctaMode, array $ctx): string
{
    $action = (string) ($ctx['selected_action'] ?? 'ANSWER');
    if (($ctx['goal'] ?? '') === 'stop' || $action === 'STOP' || $ctaMode === 'STOP') {
        return 'STOP';
    }
    if (in_array($action, ['COLLECT_REQUIRED_INFORMATION', 'CLARIFY', 'QUALIFY'], true) || $ctaMode === 'ASK_REQUIRED') {
        return 'QUESTION_ONLY';
    }
    if (in_array($action, ['COMPLETE'], true) || in_array($ctaMode, ['CONFIRM', 'COMPLETE'], true)
        || ($ctx['affirmation'] ?? '') === 'confirm'
    ) {
        return 'ACTION_CONFIRMATION';
    }
    if ($ctaMode === 'NONE' || $ctaMode === 'SOCIAL') {
        return $ctaMode === 'SOCIAL' ? 'ANSWER_ONLY' : 'ANSWER_ONLY';
    }
    if ((int) ($ctx['message_budget'] ?? 2) === 1 || !empty($ctx['multi_intent'])) {
        return 'ANSWER_PLUS_ADVANCE';
    }
    if (in_array($ctx['readiness'] ?? '', ['READY', 'COMPLETING', 'QUALIFIED'], true)) {
        return 'ANSWER_PLUS_ADVANCE';
    }

    return 'ANSWER_ONLY';
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_target(string $ctaMode, array $ctx): string
{
    $missing = is_array($ctx['required_missing'] ?? null) ? $ctx['required_missing'] : [];
    if ($ctaMode === 'ASK_REQUIRED' && $missing !== []) {
        return implode(', ', array_slice($missing, 0, 3));
    }
    if ($ctaMode === 'OFFER_RECOMMENDATION') {
        return ($ctx['need_detail'] ?? '') !== '' ? (string) $ctx['need_detail'] : 'relevant options';
    }
    if ($ctaMode === 'OFFER_PRODUCT') {
        $color = trim((string) ($ctx['entities']['color'] ?? ''));
        $product = trim((string) ($ctx['entities']['product'] ?? ''));

        return trim(($color !== '' ? $color . ' ' : '') . ($product !== '' ? $product : 'matching products'));
    }
    if ($ctaMode === 'OFFER_BOOKING') {
        return 'available appointment slot';
    }
    if ($ctaMode === 'OFFER_SUPPORT') {
        return 'order or delivery issue';
    }
    if ($ctaMode === 'OFFER_HUMAN') {
        return 'team member handoff';
    }
    if ($ctaMode === 'OFFER_QUOTE') {
        return 'verified pricing';
    }

    return '';
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_text_strategy(string $ctaMode, string $answerAdvance, string $target, array $ctx): string
{
    if ($ctaMode === 'NONE' || $answerAdvance === 'STOP') {
        return 'No follow-up CTA — close naturally without "anything else" or generic help offers.';
    }
    if ($ctaMode === 'SOCIAL') {
        return 'Brief warm reply only — no pitch or discovery questions.';
    }
    if ($ctaMode === 'ASK_REQUIRED') {
        $known = is_array($ctx['known'] ?? null) ? $ctx['known'] : [];
        $knownNote = $known !== [] ? ' Do NOT re-ask: ' . implode('; ', array_slice($known, 0, 4)) . '.' : '';

        return 'Ask ONLY for missing required field(s): ' . ($target !== '' ? $target : 'one necessary detail') . '.' . $knownNote;
    }
    if ($ctaMode === 'OFFER_SUPPORT') {
        return 'Focus CTA on resolving the issue — ask for the minimum info needed to investigate (e.g. order reference). No sales pitch.';
    }
    if ($ctaMode === 'OFFER_HUMAN') {
        return 'Offer to connect with a team member — do NOT claim a handoff already happened.';
    }
    if ($ctaMode === 'OFFER_QUOTE' && !empty($ctx['knowledge_available'])) {
        return 'After stating verified price, offer the most relevant next step (compare, choose, or order) if capability exists.';
    }
    if ($ctaMode === 'OFFER_RECOMMENDATION') {
        return 'Offer to show options that fit the customer context' . ($target !== '' ? ': ' . mb_substr($target, 0, 80) : '') . '.';
    }
    if ($ctaMode === 'OFFER_PRODUCT') {
        return 'Offer to show or help with the relevant product' . ($target !== '' ? ' (' . mb_substr($target, 0, 60) . ')' : '') . '.';
    }
    if ($ctaMode === 'OFFER_BOOKING') {
        return 'Invite booking using known details — ask only for missing required slot information.';
    }
    if ($ctaMode === 'OFFER_ORDER' || $ctaMode === 'OFFER_CHECKOUT') {
        return 'Move toward order/checkout with known context — do not restart discovery.';
    }
    if ($ctaMode === 'CONFIRM' || $ctaMode === 'COMPLETE') {
        return 'Confirm the pending action with collected details — do not claim completion unless system confirmed.';
    }
    if ($ctaMode === 'CONTINUE') {
        return 'Continue addressing the concern (e.g. value, alternative, or clarification) — no hard sell.';
    }
    if ($ctaMode === 'INVITE') {
        return 'Optional low-pressure invitation to explore further — only if genuinely useful.';
    }

    return 'Provide a contextual next step tied to the customer need — never a generic "let me know if you need anything".';
}

/**
 * @param array<string, mixed> $ctx
 * @return list<string>
 */
function agent_core_cta_reason_codes(string $ctaMode, string $answerAdvance, array $ctx): array
{
    $codes = [];
    if (($ctx['required_missing'] ?? []) !== []) {
        $codes[] = 'required_info_missing';
    }
    if (in_array($ctx['readiness'] ?? '', ['READY', 'COMPLETING'], true)) {
        $codes[] = 'ready_to_act';
    }
    if ($ctx['support_need'] ?? false) {
        $codes[] = 'support_resolution';
    }
    if (($ctx['goal'] ?? '') === 'stop') {
        $codes[] = 'customer_stopping';
    }
    if (($ctx['need'] ?? '') === 'explore_options' || ($ctx['readiness'] ?? '') === 'LOW') {
        $codes[] = 'customer_browsing';
    }
    if ($ctx['objection'] ?? false) {
        $codes[] = 'objection';
    }
    if (!empty($ctx['knowledge_available'])) {
        $codes[] = 'capability_available';
    }
    if ($ctaMode === 'NONE') {
        $codes[] = 'no_useful_next_step';
    }
    if (($ctx['action_confidence'] ?? 1) < 0.55) {
        $codes[] = 'low_confidence';
    }
    if ($answerAdvance === 'ACTION_CONFIRMATION') {
        $codes[] = 'action_complete';
    }
    if (agent_core_cta_explicit_request($ctx)) {
        $codes[] = 'explicit_customer_request';
    }

    return array_values(array_unique($codes));
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_confidence(string $ctaMode, array $ctx): float
{
    $base = (float) ($ctx['action_confidence'] ?? 0.5);
    if ($ctaMode === 'NONE' || $ctaMode === 'STOP') {
        return round(max(0.7, min(1.0, $base + 0.2)), 2);
    }
    if ($ctaMode === 'ASK_REQUIRED' && ($ctx['required_missing'] ?? []) !== []) {
        return round(max(0.75, min(1.0, $base + 0.15)), 2);
    }
    if (in_array($ctaMode, ['OFFER_QUOTE', 'OFFER_PRODUCT', 'OFFER_BOOKING'], true) && empty($ctx['knowledge_available'])) {
        return round(max(0.2, $base - 0.25), 2);
    }
    if ($ctaMode === 'OFFER_HUMAN') {
        return round(max(0.6, min(1.0, $base + 0.1)), 2);
    }

    return round(max(0.3, min(1.0, $base)), 2);
}

/**
 * @param array<string, mixed> $ctx
 * @return list<string>
 */
function agent_core_cta_advance_required_info(string $ctaMode, array $ctx): array
{
    if (!in_array($ctaMode, ['ASK_REQUIRED', 'OFFER_BOOKING', 'OFFER_SUPPORT', 'CONFIRM'], true)) {
        return [];
    }

    return is_array($ctx['required_missing'] ?? null) ? $ctx['required_missing'] : [];
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_require_useful(array $ctx, string $ctaMode, string $answerAdvance): bool
{
    if ($answerAdvance === 'STOP' || $ctaMode === 'NONE') {
        return false;
    }
    if (($ctx['support_need'] ?? false) && in_array($ctx['selected_action'] ?? '', ['RESOLVE_SUPPORT', 'TRACK_ORDER'], true)) {
        return true;
    }
    if (($ctx['need'] ?? '') === 'personal_support') {
        return true;
    }
    if (in_array($ctx['selected_action'] ?? '', ['COLLECT_REQUIRED_INFORMATION', 'OFFER_BOOKING', 'COMPLETE', 'RECOMMEND'], true)) {
        return true;
    }
    if ($answerAdvance === 'ANSWER_PLUS_ADVANCE' && $ctaMode !== 'NONE') {
        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $ctx
 */
function agent_core_cta_explicit_request(array $ctx): bool
{
    $lower = (string) ($ctx['lower'] ?? '');

    return preg_match('/\b(human|book|order|buy|purchase|checkout|refund|track)\b/u', $lower) === 1;
}

/**
 * Phase 2C plan-bound CTA validation.
 *
 * @param array<string, mixed> $plan
 * @return array{ok: bool, reason?: string}
 */
function agent_core_validate_cta_decision(string $draft, array $plan): array
{
    $lower = mb_strtolower(trim($draft));
    $ctaMode = trim((string) ($plan['cta_mode'] ?? ''));
    $answerAdvance = trim((string) ($plan['answer_advance'] ?? ''));
    $selected = trim((string) ($plan['selected_action'] ?? ''));

    if (in_array($ctaMode, ['NONE', 'STOP'], true) || $answerAdvance === 'STOP') {
        $genericCta = [
            '/\b(anything else i can help|would you like to (?:buy|order|place)|let me know if you (?:need|have) anything|how can i help you further)\b/u',
            '/\b(feel free to ask|hope this helps)\b/u',
        ];
        foreach ($genericCta as $pattern) {
            if (preg_match($pattern, $lower)) {
                return ['ok' => false, 'reason' => 'cta_forced_after_stop'];
            }
        }
    }

    if (($plan['support_need'] ?? false)
        || in_array(trim((string) ($plan['selected_action'] ?? '')), ['RESOLVE_SUPPORT', 'TRACK_ORDER'], true)
    ) {
        if (preg_match('/\b(would you like to (?:buy|order)|place (?:an )?order|check out|add to cart)\b/u', $lower)) {
            return ['ok' => false, 'reason' => 'sales_cta_after_support'];
        }
    }

    if ($ctaMode === 'ASK_REQUIRED' || ($plan['answer_advance'] ?? '') === 'QUESTION_ONLY') {
        $known = is_array($plan['known_information'] ?? null) ? $plan['known_information'] : [];
        foreach ($known as $row) {
            $parts = explode('=', (string) $row, 2);
            $key = mb_strtolower(trim($parts[0] ?? ''));
            if (in_array($key, ['customer_name', 'name'], true) && preg_match('/\b(what(?:\'s| is) your name|may i (?:have|get) your name)\b/u', $lower)) {
                return ['ok' => false, 'reason' => 'cta_asks_known_info'];
            }
            if ($key === 'service' && preg_match('/\b(what service|which service)\b/u', $lower)) {
                return ['ok' => false, 'reason' => 'cta_asks_known_info'];
            }
        }
    }

    if (in_array($ctaMode, ['OFFER_BOOKING'], true) && !in_array('booking', is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [], true)) {
        if (preg_match('/\b(i(?:\'ve| have) booked|your appointment is|slot is reserved)\b/u', $lower)) {
            return ['ok' => false, 'reason' => 'cta_unavailable_capability'];
        }
    }

    if (!empty($plan['require_useful_cta']) && !empty($plan['forbid_generic_loop'])) {
        if (preg_match('/\b(sorry to hear that\.?\s*(let me know|feel free)|i understand\.?\s*(let me know|feel free))\b/u', $lower)) {
            return ['ok' => false, 'reason' => 'dead_end_when_cta_required'];
        }
    }

    if ($ctaMode === 'NONE' && preg_match('/\b(would you like to (?:buy|order|book)|ready to (?:buy|order|checkout))\b/u', $lower)) {
        return ['ok' => false, 'reason' => 'cta_incompatible_with_mode'];
    }

    return ['ok' => true];
}
