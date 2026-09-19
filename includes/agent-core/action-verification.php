<?php
/**
 * Action verification states — claims require matching backend/tool evidence.
 */
declare(strict_types=1);

/** @var list<string> */
const AGENT_ACTION_STATES = [
    'none',
    'requested',
    'collecting',
    'information_collected',
    'availability_requested',
    'availability_checked',
    'slot_selected',
    'awaiting_confirm',
    'awaiting_customer_confirmation',
    'executing',
    'creating',
    'executed',
    'confirmed',
    'succeeded',
    'failed',
    'cancelled',
];

/**
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @return array{state: string, booking_id: int, availability_verified: bool, handoff_verified: bool, booking_confirmed: bool, handoff_id: int}
 */
function agent_action_verification_context(array $plan, array $toolResults = []): array
{
    $ctx = [
        'state'                 => 'none',
        'booking_id'            => 0,
        'handoff_id'            => 0,
        'availability_verified' => false,
        'handoff_verified'      => false,
        'booking_confirmed'     => false,
    ];

    $bookingPhase = trim((string) ($plan['booking_phase'] ?? ''));
    if ($bookingPhase === '' && is_array($plan['booking_state'] ?? null)) {
        $bookingPhase = trim((string) ($plan['booking_state']['phase'] ?? ''));
    }
    if ($bookingPhase !== '') {
        $ctx['state'] = $bookingPhase;
    }

    $missing = is_array($plan['missing_information'] ?? null) ? $plan['missing_information'] : [];
    if ($missing !== [] && $ctx['state'] === 'none') {
        $ctx['state'] = 'collecting';
    }

    foreach ($toolResults as $row) {
        $name = (string) ($row['name'] ?? '');
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        if (in_array($name, ['booking.offer', 'booking.availability'], true) && !empty($row['ok'])) {
            if (!empty($data['slots']) || !empty($data['availability_verified'])) {
                $ctx['availability_verified'] = true;
                $ctx['state'] = 'availability_checked';
            }
        }
        if ($name === 'booking.create') {
            if (!empty($row['ok']) && !empty($data['booking_id'])) {
                $ctx['booking_id'] = (int) $data['booking_id'];
                $ctx['booking_confirmed'] = ($data['status'] ?? '') === 'confirmed';
                $ctx['state'] = $ctx['booking_confirmed'] ? 'confirmed' : 'executed';
            } elseif ($ctx['state'] !== 'confirmed') {
                $ctx['state'] = 'failed';
            }
        }
        if ($name === 'human_handoff.create' && !empty($row['ok']) && !empty($data['handoff_id'])) {
            $ctx['handoff_id'] = (int) $data['handoff_id'];
            $ctx['handoff_verified'] = true;
            $ctx['state'] = 'succeeded';
        }
    }

    if (!empty($plan['action_executed']['booking_created'])) {
        $ctx['booking_confirmed'] = true;
        $ctx['booking_id'] = (int) ($plan['action_executed']['booking_id'] ?? $ctx['booking_id']);
        $ctx['state'] = 'confirmed';
    }
    if (!empty($plan['action_executed']['handoff_created'])) {
        $ctx['handoff_verified'] = true;
        $ctx['handoff_id'] = (int) ($plan['action_executed']['handoff_id'] ?? 0);
    }

    if ((string) ($plan['next_best_action'] ?? '') === 'confirm_pending'
        && !in_array($ctx['state'], ['confirmed', 'creating', 'executed'], true)
    ) {
        $ctx['state'] = in_array($ctx['state'], ['availability_checked', 'slot_selected'], true)
            ? 'awaiting_customer_confirmation'
            : 'awaiting_confirm';
    }

    return $ctx;
}

/**
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 */
function agent_action_claim_is_verified(string $claimType, array $plan, array $toolResults = []): bool
{
    $v = agent_action_verification_context($plan, $toolResults);

    return match ($claimType) {
        'booking_confirmed'      => $v['booking_confirmed'] || $v['state'] === 'confirmed',
        'availability_stated'    => $v['availability_verified'],
        'handoff_completed'      => $v['handoff_verified'],
        'invitation_forwarded'   => !empty($plan['action_executed']['invitation_forwarded']) || $v['handoff_verified'],
        default                  => false,
    };
}
