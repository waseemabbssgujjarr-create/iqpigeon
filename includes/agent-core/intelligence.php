<?php
/**
 * Bridge Conversation Intelligence → Agent Core (Phase 1 fusion).
 * One analyze() call per turn; no duplicate decision engine.
 */
declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function agent_core_intelligence_empty(): array
{
    return [
        'intents'             => [],
        'primary_intent'      => '',
        'secondary_intents'   => [],
        'entities'            => [],
        'emotion'             => 'neutral',
        'language'            => 'en',
        'strategy'            => 'direct_answer',
        'confidence'          => 0.0,
        'ambiguity'           => 0.0,
        'missing_information' => [],
        'next_best_action'    => 'answer_turn',
        'minimum_question'    => '',
        'purchase_stage'      => 'interest',
        'affirmation'         => 'none',
        'is_social'           => false,
        'summary'             => [],
        'conversation_state' => '',
        'customer_goal'       => '',
        'context_pack'        => '',
    ];
}

/**
 * Load state + memory and run conversation_intelligence_analyze() once per turn.
 *
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_core_intelligence_for_turn(array $turn, array $conv): array
{
    $text = trim((string) ($turn['text'] ?? ''));
    if ($text === '' || $text === '[Customer sent a message]') {
        return agent_core_intelligence_empty();
    }

    $intelPath = dirname(__DIR__) . '/conversation-intelligence.php';
    if (!is_file($intelPath)) {
        return agent_core_intelligence_empty();
    }
    require_once $intelPath;

    $botId = (int) ($turn['bot_id'] ?? 0);
    $leadId = (int) ($turn['lead_id'] ?? 0);
    $turnId = (int) ($turn['turn_id'] ?? 0);

    $state = [];
    if (isset($turn['test_intelligence_state']) && is_array($turn['test_intelligence_state'])) {
        $state = $turn['test_intelligence_state'];
    } elseif ($leadId > 0 && empty($GLOBALS['agent_core_no_network'])) {
        $state = conversation_intelligence_load_state($leadId);
    }

    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    if ($memory === [] && $botId > 0 && $leadId > 0 && function_exists('conversation_intelligence_memory_get')) {
        $mem = conversation_intelligence_memory_get($botId, $leadId, $text);
        if ($mem !== []) {
            $memory = $mem;
        }
    }

    $analysis = conversation_intelligence_analyze($text, [
        'state'   => $state,
        'memory'  => $memory,
        'bot_id'  => $botId,
        'lead_id' => $leadId,
        'turn_id' => $turnId,
    ]);

    $secondary = [];
    foreach (is_array($analysis['intents'] ?? null) ? $analysis['intents'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['role'] ?? '') === 'primary') {
            continue;
        }
        $label = trim((string) ($row['intent'] ?? ''));
        if ($label !== '' && !in_array($label, $secondary, true)) {
            $secondary[] = $label;
        }
    }

    $summary = is_array($analysis['summary'] ?? null) ? $analysis['summary'] : [];
    $analysis['secondary_intents'] = $secondary;
    $analysis['conversation_state'] = trim((string) ($state['state'] ?? ''));
    $analysis['customer_goal'] = trim((string) ($summary['customer_goal'] ?? ''));
    if ($analysis['customer_goal'] === '') {
        $analysis['customer_goal'] = trim((string) ($analysis['entities']['product'] ?? ''));
    }

    return $analysis;
}

/**
 * Observability-safe decision fields (no chain-of-thought, no secrets).
 *
 * @param array<string, mixed> $intelligence
 * @param array<string, mixed> $plan
 * @return array<string, mixed>
 */
function agent_core_observe_decision_bits(array $intelligence, array $plan): array
{
    $secondary = is_array($intelligence['secondary_intents'] ?? null)
        ? $intelligence['secondary_intents']
        : [];
    $missing = is_array($plan['missing_information'] ?? null)
        ? $plan['missing_information']
        : (is_array($intelligence['missing_information'] ?? null) ? $intelligence['missing_information'] : []);

    return [
        'primary_intent'    => mb_substr((string) ($intelligence['primary_intent'] ?? ''), 0, 48),
        'secondary_intents' => array_slice(array_map(static fn ($s) => mb_substr((string) $s, 0, 32), $secondary), 0, 6),
        'customer_need'     => mb_substr((string) ($plan['customer_need'] ?? ''), 0, 48),
        'customer_goal'     => mb_substr((string) ($plan['customer_goal'] ?? ''), 0, 32),
        'next_best_action'  => mb_substr((string) ($plan['next_best_action'] ?? $intelligence['next_best_action'] ?? ''), 0, 48),
        'missing_info'      => array_slice(array_map(static fn ($s) => mb_substr((string) $s, 0, 24), $missing), 0, 8),
        'readiness'         => mb_substr((string) ($plan['readiness'] ?? $intelligence['purchase_stage'] ?? ''), 0, 24),
        'conv_stage'        => mb_substr((string) ($plan['conversation_stage'] ?? $plan['conversation_state'] ?? ''), 0, 32),
        'emotion'           => mb_substr((string) ($intelligence['emotion'] ?? ''), 0, 24),
        'action_confidence' => (float) ($plan['action_confidence'] ?? 0),
        'action_score'      => (float) ($plan['action_score'] ?? 0),
        'selected_action'   => mb_substr((string) ($plan['selected_action'] ?? ''), 0, 32),
        'cta_mode'          => mb_substr((string) ($plan['cta_mode'] ?? ''), 0, 24),
        'cta_required'      => !empty($plan['cta_required']),
        'cta_target'        => mb_substr((string) ($plan['cta_target'] ?? ''), 0, 48),
        'cta_confidence'    => (float) ($plan['cta_confidence'] ?? 0),
        'answer_advance'    => mb_substr((string) ($plan['answer_advance'] ?? ''), 0, 24),
        'stop_allowed'      => !empty($plan['stop_allowed']),
        'cta_reason_codes'  => array_slice(is_array($plan['cta_reason_codes'] ?? null) ? $plan['cta_reason_codes'] : [], 0, 8),
        'multi_intent'      => !empty($plan['multi_intent']),
        'combined_action'   => !empty($plan['combined_action_possible']),
        'deferred_intents'  => array_slice(is_array($plan['deferred_intents'] ?? null) ? $plan['deferred_intents'] : [], 0, 6),
        'resolved_intents'  => array_slice(is_array($plan['resolved_intents'] ?? null) ? $plan['resolved_intents'] : [], 0, 6),
        'objection'         => !empty($plan['objection']),
        'objection_type'    => mb_substr((string) ($plan['objection_type'] ?? ''), 0, 24),
        'objection_strategy' => mb_substr((string) ($plan['objection_strategy'] ?? ''), 0, 32),
        'message_budget'    => (int) ($plan['message_budget'] ?? 0),
        'plan_outcome'      => mb_substr((string) ($plan['outcome'] ?? ''), 0, 32),
        'answer_kind'       => mb_substr((string) ($plan['answer_kind'] ?? ''), 0, 24),
        'business_outcome'  => mb_substr((string) ($plan['business_outcome'] ?? ''), 0, 48),
        'business_capabilities' => array_slice(
            is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [],
            0,
            12
        ),
        'capability_states' => array_slice(
            is_array($plan['capability_states'] ?? null) ? array_keys(array_filter($plan['capability_states'], static fn ($s) => in_array((string) $s, ['available', 'configured'], true))) : [],
            0,
            12
        ),
        'candidate_actions' => array_slice(is_array($plan['candidate_actions'] ?? null) ? $plan['candidate_actions'] : [], 0, 8),
        'eligible_actions'  => array_slice(is_array($plan['eligible_actions'] ?? null) ? $plan['eligible_actions'] : [], 0, 8),
        'required_information' => array_slice(is_array($plan['required_information'] ?? null) ? $plan['required_information'] : [], 0, 8),
        'response_goal'     => mb_substr((string) ($plan['response_goal'] ?? ''), 0, 48),
        'validation_result' => mb_substr((string) ($plan['validation_result'] ?? ''), 0, 24),
        'tool_selected'     => mb_substr((string) ($plan['tool_selected'] ?? ''), 0, 32),
        'tool_result_state' => mb_substr((string) ($plan['tool_result_state'] ?? ''), 0, 32),
    ];
}
