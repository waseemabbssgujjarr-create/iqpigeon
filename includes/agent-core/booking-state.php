<?php
/**
 * Native booking state machine — persisted in conversation_state.summary.booking.
 * Industry-independent; no Bot-53-specific logic.
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_BOOKING_PHASES = [
    'none',
    'collecting',
    'availability_requested',
    'availability_checked',
    'awaiting_customer_confirmation',
    'creating',
    'confirmed',
    'failed',
    'cancelled',
    'rescheduling',
];

/**
 * @return array<string, mixed>
 */
function agent_booking_state_empty(): array
{
    return [
        'phase'                 => 'none',
        'service'               => '',
        'staff'                 => '',
        'resource'              => '',
        'location'              => '',
        'date'                  => '',
        'time'                  => '',
        'duration'              => '',
        'party_size'            => '',
        'customer_name'         => '',
        'phone'                 => '',
        'offered_slots'         => [],
        'selected_slot_index'   => 0,
        'selected_iso_start'    => '',
        'selected_iso_end'      => '',
        'booking_id'            => 0,
        'last_error'            => '',
        'timezone'              => '',
    ];
}

/**
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_booking_state_load(int $leadId, array $conv = []): array
{
    if ($leadId > 0 && !empty($GLOBALS['_agent_booking_state_fixture'][$leadId])
        && is_array($GLOBALS['_agent_booking_state_fixture'][$leadId])
    ) {
        return array_merge(agent_booking_state_empty(), $GLOBALS['_agent_booking_state_fixture'][$leadId]);
    }
    $summary = is_array($conv['conversation_summary'] ?? null) ? $conv['conversation_summary'] : [];
    if ($summary === [] && $leadId > 0 && empty($GLOBALS['agent_core_no_network'])) {
        require_once dirname(__DIR__) . '/conversation-intelligence.php';
        if (function_exists('conversation_intelligence_load_state')) {
            $row = conversation_intelligence_load_state($leadId);
            $raw = trim((string) ($row['summary'] ?? ''));
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $summary = $decoded;
                }
            }
        }
    }
    $booking = is_array($summary['booking'] ?? null) ? $summary['booking'] : [];

    return array_merge(agent_booking_state_empty(), $booking);
}

/**
 * @param array<string, mixed> $state
 */
function agent_booking_state_save(int $leadId, int $botId, array $state): void
{
    if ($leadId <= 0) {
        return;
    }
    if (!isset($GLOBALS['_agent_booking_state_fixture'])) {
        $GLOBALS['_agent_booking_state_fixture'] = [];
    }
    $GLOBALS['_agent_booking_state_fixture'][$leadId] = $state;
    if (!empty($GLOBALS['agent_core_no_network'])) {
        return;
    }
    require_once dirname(__DIR__) . '/conversation-intelligence.php';
    if (!function_exists('conversation_intelligence_load_state')) {
        return;
    }
    $row = conversation_intelligence_load_state($leadId);
    $summary = [];
    $raw = trim((string) ($row['summary'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $summary = $decoded;
        }
    }
    $summary['booking'] = $state;
    if (function_exists('conversation_intelligence_save_state')) {
        conversation_intelligence_save_state($leadId, ['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'bot_id' => $botId]);
    }
}

/**
 * Required booking fields from tenant settings (extensible per industry).
 *
 * @param array<string, mixed> $settings
 * @return list<string>
 */
function agent_booking_required_fields(array $settings = []): array
{
    $required = ['date', 'time'];
    if (!empty($settings['require_service'])) {
        $required[] = 'service';
    }
    if (!empty($settings['require_staff'])) {
        $required[] = 'staff';
    }
    if (!empty($settings['require_resource'])) {
        $required[] = 'resource';
    }
    if (!empty($settings['require_location'])) {
        $required[] = 'location';
    }
    if (!empty($settings['require_party_size'])) {
        $required[] = 'party_size';
    }

    return array_values(array_unique($required));
}

/**
 * @param array<string, bool> $fields
 * @param list<string> $required
 * @return list<string>
 */
function agent_booking_missing_fields(array $fields, array $required): array
{
    $missing = [];
    foreach ($required as $key) {
        if (empty($fields[$key])) {
            $missing[] = $key;
        }
    }

    return $missing;
}

/**
 * Update booking state from turn context.
 *
 * @param array<string, mixed> $state
 * @param array<string, bool> $bookingFields
 * @param array<string, mixed> $entities
 * @return array<string, mixed>
 */
function agent_booking_state_apply_turn(
    array $state,
    string $text,
    array $bookingFields,
    array $entities,
    string $affirmation,
    string $nba
): array {
    $lower = mb_strtolower(trim($text));
    foreach (['service', 'date', 'time', 'staff', 'resource', 'location', 'party_size'] as $key) {
        if (!empty($bookingFields[$key])) {
            $state[$key] = trim((string) ($entities[$key] ?? $state[$key] ?? ''));
            if ($state[$key] === '' && $key === 'date' && !empty($bookingFields['date'])) {
                $state['date'] = 'tomorrow';
            }
            if ($state[$key] === '' && $key === 'time' && !empty($bookingFields['time'])) {
                if (preg_match('/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm)|morning|afternoon|evening)\b/u', $lower, $tm)) {
                    $state['time'] = trim((string) ($tm[1] ?? ''));
                }
            }
        }
    }
    if (preg_match('/^(\d{1,2})$/', trim($text), $m) && !empty($state['offered_slots'])) {
        $state['selected_slot_index'] = (int) $m[1];
        $idx = (int) $m[1] - 1;
        $slot = is_array($state['offered_slots'][$idx] ?? null) ? $state['offered_slots'][$idx] : [];
        if ($slot !== []) {
            $state['selected_iso_start'] = (string) ($slot['iso_start'] ?? '');
            $state['selected_iso_end'] = (string) ($slot['iso_end'] ?? '');
            $state['phase'] = 'awaiting_customer_confirmation';
        }
    }
    if ($affirmation === 'confirm' && in_array($state['phase'], ['availability_checked', 'awaiting_customer_confirmation'], true)) {
        $state['phase'] = 'awaiting_customer_confirmation';
    }
    if ($nba === 'confirm_pending' && $state['phase'] === 'availability_checked') {
        $state['phase'] = 'awaiting_customer_confirmation';
    }
    if (preg_match('/\b(cancel|never mind|don\'?t book)\b/u', $lower)) {
        $state['phase'] = 'cancelled';
    }
    if (in_array($state['phase'], ['none', ''], true)
        && (!empty($bookingFields['date']) || !empty($bookingFields['time']) || !empty($bookingFields['service']))
    ) {
        $incomplete = empty($bookingFields['date']) || empty($bookingFields['time']);
        if ($incomplete) {
            $state['phase'] = 'collecting';
        }
    }

    return $state;
}

/**
 * Enrich plan with booking tool calls and action state (called from decision enrich).
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @param array<string, mixed> $intelligence
 * @return array<string, mixed>
 */
function agent_booking_plan_enrich(array $plan, array $turn, array $conv, array $intelligence): array
{
    if (!in_array('booking', is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [], true)) {
        return $plan;
    }

    require_once __DIR__ . '/booking-tools.php';

    $botId = (int) ($turn['bot_id'] ?? 0);
    $leadId = (int) ($turn['lead_id'] ?? 0);
    $text = trim((string) ($turn['text'] ?? ''));
    $nba = trim((string) ($plan['next_best_action'] ?? ''));
    $primary = trim((string) ($plan['primary_intent'] ?? ''));
    $affirmation = trim((string) ($intelligence['affirmation'] ?? 'none'));
    $entities = is_array($intelligence['entities'] ?? null) ? $intelligence['entities'] : [];

    $bookingActive = $primary === 'BOOKING_REQUEST'
        || ($plan['customer_need'] ?? '') === 'book_appointment'
        || in_array($nba, ['confirm_pending', 'answer_availability'], true)
        || trim((string) ($intelligence['summary']['pending_action'] ?? '')) === 'BOOKING_REQUEST';

    if (!$bookingActive) {
        return $plan;
    }

    $state = agent_booking_state_load($leadId, $conv);
    $known = is_array($plan['known_information'] ?? null) ? $plan['known_information'] : [];
    $memory = is_array($conv['runtime_facts'] ?? null) ? $conv['runtime_facts'] : [];
    $fieldStatus = function_exists('agent_core_decision_booking_field_status')
        ? agent_core_decision_booking_field_status($known, $entities, $memory, $text)
        : ['date' => false, 'time' => false, 'service' => false];

    $state = agent_booking_state_apply_turn($state, $text, $fieldStatus, $entities, $affirmation, $nba);

    $settings = ['enabled' => 0];
    if ($botId > 0 && in_array('booking', is_array($plan['business_capabilities'] ?? null) ? $plan['business_capabilities'] : [], true)) {
        if (!empty($GLOBALS['agent_core_no_network'])) {
            $settings = ['enabled' => 1, 'timezone' => 'Asia/Karachi'];
        } elseif (function_exists('booking_settings_for_bot')) {
            $settings = booking_settings_for_bot($botId);
        }
    }
    $required = agent_booking_required_fields($settings);
    $missing = agent_booking_missing_fields($fieldStatus, $required);

    if ($missing !== [] && $state['phase'] === 'none') {
        $state['phase'] = 'collecting';
    }

    $plan['booking_state'] = $state;
    $plan['booking_phase'] = $state['phase'];
    $plan['action_state'] = agent_booking_action_state_label($state);

    if ($missing !== [] && $nba !== 'confirm_pending') {
        agent_booking_state_save($leadId, $botId, $state);

        return $plan;
    }

    $shouldCheckAvailability = in_array($state['phase'], ['none', 'collecting', 'availability_requested'], true)
        || ($fieldStatus['date'] && $fieldStatus['time'] && $state['phase'] !== 'confirmed');

    if ($shouldCheckAvailability && empty($settings['enabled'])) {
        $plan['response_goal'] = ($plan['response_goal'] ?? '')
            . ' Booking is not configured for this business — explain honestly; do not promise availability checks.';
        agent_booking_state_save($leadId, $botId, $state);

        return $plan;
    }

    if ($shouldCheckAvailability) {
        $state['phase'] = 'availability_requested';
        $plan['tool_calls'] = agent_core_plan_append_tool($plan['tool_calls'] ?? [], 'booking.availability', [
            'date' => $state['date'],
            'time' => $state['time'],
        ]);
        $plan['response_goal'] = ($plan['response_goal'] ?? '')
            . ' Use verified availability only. If the requested time is available, ask whether to book — do not confirm yet.';
    }

    $executeCreate = ($affirmation === 'confirm' || $nba === 'confirm_pending')
        && in_array($state['phase'], ['availability_checked', 'awaiting_customer_confirmation'], true);

    if ($executeCreate && $fieldStatus['date'] && $fieldStatus['time']) {
        $resolved = booking_tool_resolve_slot($botId, $state, $text);
        if ($resolved !== null) {
            $state['selected_iso_start'] = $resolved['iso_start'];
            $state['selected_iso_end'] = $resolved['iso_end'];
            $state['phase'] = 'creating';
            $plan['allow_mutating_tools'] = true;
            $plan['tool_calls'] = agent_core_plan_append_tool($plan['tool_calls'] ?? [], 'booking.create', [
                'iso_start' => $resolved['iso_start'],
                'iso_end'   => $resolved['iso_end'],
                'service'   => $state['service'],
                'name'      => $state['customer_name'],
                'phone'     => $state['phone'],
            ]);
            $plan['response_goal'] = 'If booking.create succeeds, confirm warmly with date/time. If it fails, explain slot unavailable — offer alternatives if known.';
        }
    }

    agent_booking_state_save($leadId, $botId, $state);
    $plan['booking_state'] = $state;
    $plan['booking_phase'] = $state['phase'];
    $plan['action_state'] = agent_booking_action_state_label($state);

    return $plan;
}

/**
 * @param array<string, mixed> $state
 */
function agent_booking_action_state_label(array $state): string
{
    return match ((string) ($state['phase'] ?? 'none')) {
        'collecting'                       => 'booking_requested',
        'availability_requested'           => 'booking_requested',
        'availability_checked'             => 'availability_verified',
        'awaiting_customer_confirmation'   => 'booking_selected',
        'creating'                         => 'executing',
        'confirmed'                        => 'booking_confirmed',
        'failed'                           => 'booking_failed',
        default                            => 'none',
    };
}

/**
 * After tools run — persist availability/create outcomes.
 *
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turn
 * @param array<string, mixed> $conv
 * @return array<string, mixed>
 */
function agent_booking_post_tools(array $plan, array $toolResults, array $turn, array $conv): array
{
    $leadId = (int) ($turn['lead_id'] ?? 0);
    $botId = (int) ($turn['bot_id'] ?? 0);
    $state = is_array($plan['booking_state'] ?? null)
        ? $plan['booking_state']
        : agent_booking_state_load($leadId, $conv);

    foreach ($toolResults as $row) {
        $name = (string) ($row['name'] ?? '');
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        if (in_array($name, ['booking.availability', 'booking.offer'], true) && !empty($row['ok'])) {
            $state['offered_slots'] = is_array($data['slots'] ?? null) ? $data['slots'] : [];
            $state['timezone'] = (string) ($data['timezone'] ?? '');
            $state['phase'] = !empty($data['availability_verified']) ? 'availability_checked' : 'failed';
            if ($state['phase'] === 'availability_checked' && ($plan['next_best_action'] ?? '') !== 'confirm_pending') {
                $state['phase'] = 'awaiting_customer_confirmation';
            }
        }
        if ($name === 'booking.create') {
            if (!empty($row['ok']) && !empty($data['booking_id'])) {
                $state['booking_id'] = (int) $data['booking_id'];
                $state['phase'] = 'confirmed';
                $plan['action_executed'] = array_merge(
                    is_array($plan['action_executed'] ?? null) ? $plan['action_executed'] : [],
                    ['booking_created' => true, 'booking_id' => (int) $data['booking_id']]
                );
            } else {
                $state['phase'] = 'failed';
                $state['last_error'] = (string) ($data['error'] ?? 'booking_failed');
                $plan['booking_create_failed'] = true;
                $plan['response_goal'] = 'Booking could not be completed — slot may be unavailable. Do not claim success.';
            }
        }
        if ($name === 'human_handoff.create' && !empty($row['ok']) && !empty($data['handoff_id'])) {
            $plan['action_executed'] = array_merge(
                is_array($plan['action_executed'] ?? null) ? $plan['action_executed'] : [],
                ['handoff_created' => true, 'handoff_id' => (int) $data['handoff_id'], 'invitation_forwarded' => true]
            );
        }
    }

    $plan['booking_state'] = $state;
    $plan['booking_phase'] = $state['phase'];
    $plan['action_state'] = agent_booking_action_state_label($state);
    $plan['tool_results'] = $toolResults;
    $plan['tool_result_state'] = (string) ($state['phase'] ?? 'none');

    agent_booking_state_save($leadId, $botId, $state);

    return $plan;
}

/**
 * Compose hint from verified booking state (for plan_note / deterministic tests).
 *
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 */
function agent_booking_compose_hint(array $plan, array $toolResults = []): string
{
    $state = is_array($plan['booking_state'] ?? null) ? $plan['booking_state'] : [];
    $phase = (string) ($state['phase'] ?? '');
    if ($phase === 'confirmed' && (int) ($state['booking_id'] ?? 0) > 0) {
        return 'Verified: appointment confirmed in CRM (booking_id ' . (int) $state['booking_id'] . ').';
    }
    if ($phase === 'awaiting_customer_confirmation' || $phase === 'availability_checked') {
        foreach ($toolResults as $row) {
            if (in_array((string) ($row['name'] ?? ''), ['booking.availability', 'booking.offer'], true)) {
                $msg = is_array($row['data'] ?? null) ? trim((string) ($row['data']['message'] ?? '')) : '';
                if ($msg !== '') {
                    return 'Verified availability: ' . mb_substr($msg, 0, 300);
                }
            }
        }

        return 'Availability verified — ask customer to confirm before booking.';
    }
    if ($phase === 'failed') {
        return 'Booking failed — do not claim confirmation.';
    }

    return '';
}
