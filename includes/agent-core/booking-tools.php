<?php
/**
 * Structured native IQPigeon booking tool results — canonical CRM state, not third-party.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/booking.php';

/**
 * @return array{ok: bool, availability_verified: bool, slots: list<array<string, mixed>>, message: string, timezone: string}
 */
function booking_tool_availability(int $botId, int $count = 6, ?string $dateHint = null, ?string $timeHint = null): array
{
    if ($botId <= 0) {
        return ['ok' => false, 'availability_verified' => false, 'slots' => [], 'message' => '', 'timezone' => ''];
    }
    if (!empty($GLOBALS['_booking_tool_availability_fixture'][$botId])
        && is_array($GLOBALS['_booking_tool_availability_fixture'][$botId])
    ) {
        return $GLOBALS['_booking_tool_availability_fixture'][$botId];
    }
    $settings = booking_settings_for_bot($botId);
    if (empty($settings['enabled'])) {
        return ['ok' => false, 'availability_verified' => false, 'slots' => [], 'message' => '', 'timezone' => (string) ($settings['timezone'] ?? '')];
    }
    $slots = booking_next_slots($botId, $count);
    $structured = [];
    $i = 1;
    foreach ($slots as $slot) {
        /** @var DateTimeImmutable $st */
        $st = $slot['start'];
        /** @var DateTimeImmutable $en */
        $en = $slot['end'];
        $structured[] = [
            'index'     => $i,
            'start'     => $st->format('Y-m-d H:i:s'),
            'end'       => $en->format('Y-m-d H:i:s'),
            'label'     => (string) ($slot['label'] ?? ''),
            'iso_start' => $st->format(DateTimeInterface::ATOM),
            'iso_end'   => $en->format(DateTimeInterface::ATOM),
        ];
        $i++;
    }
    $matched = null;
    if ($dateHint !== null && $timeHint !== null && $dateHint !== '' && $timeHint !== '') {
        $matched = booking_match_requested_slot($botId, $dateHint, $timeHint);
    }
    $message = booking_slots_message($botId, $count);
    if ($matched !== null) {
        /** @var DateTimeImmutable $mst */
        $mst = $matched['start'];
        $message = $mst->format('D j M, g:i A') . ' is available. Would you like me to book it?';
    }

    return [
        'ok'                    => $structured !== [] || $matched !== null,
        'availability_verified' => $structured !== [] || $matched !== null,
        'slots'                 => $structured,
        'message'               => $message,
        'timezone'              => (string) ($settings['timezone'] ?? 'UTC'),
        'matched_slot'          => $matched !== null ? [
            'iso_start' => $matched['start']->format(DateTimeInterface::ATOM),
            'iso_end'   => $matched['end']->format(DateTimeInterface::ATOM),
            'label'     => (string) ($matched['label'] ?? ''),
        ] : null,
    ];
}

/**
 * Resolve slot from persisted state + customer message.
 *
 * @param array<string, mixed> $state
 * @return array{iso_start: string, iso_end: string, label: string}|null
 */
function booking_tool_resolve_slot(int $botId, array $state, string $userMessage): ?array
{
    if ($botId <= 0) {
        return null;
    }
    if ((string) ($state['selected_iso_start'] ?? '') !== '' && (string) ($state['selected_iso_end'] ?? '') !== '') {
        return [
            'iso_start' => (string) $state['selected_iso_start'],
            'iso_end'   => (string) $state['selected_iso_end'],
            'label'     => '',
        ];
    }
    $choice = booking_resolve_slot_choice($botId, $userMessage);
    if ($choice !== null) {
        /** @var DateTimeImmutable $st */
        $st = $choice['start'];
        /** @var DateTimeImmutable $en */
        $en = $choice['end'];

        return [
            'iso_start' => $st->format(DateTimeInterface::ATOM),
            'iso_end'   => $en->format(DateTimeInterface::ATOM),
            'label'     => (string) ($choice['label'] ?? ''),
        ];
    }
    $date = (string) ($state['date'] ?? '');
    $time = (string) ($state['time'] ?? '');
    if ($date === '' || $time === '') {
        return null;
    }
    $matched = booking_match_requested_slot($botId, $date, $time);
    if ($matched === null) {
        return null;
    }

    return [
        'iso_start' => $matched['start']->format(DateTimeInterface::ATOM),
        'iso_end'   => $matched['end']->format(DateTimeInterface::ATOM),
        'label'     => (string) ($matched['label'] ?? ''),
    ];
}

/**
 * @return array{ok: bool, booking_id: int, status: string, service: string, date: string, time: string, timezone: string, error: string}
 */
function booking_tool_create(
    int $botId,
    int $userId,
    int $leadId,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    ?string $name = null,
    ?string $phone = null,
    ?string $service = null
): array {
    $fail = static fn (string $error): array => [
        'ok' => false, 'booking_id' => 0, 'status' => 'failed',
        'service' => (string) ($service ?? ''), 'date' => '', 'time' => '', 'timezone' => '', 'error' => $error,
    ];
    if ($botId <= 0 || $leadId <= 0) {
        return $fail('invalid_context');
    }
    if (!empty($GLOBALS['_booking_tool_create_fixture'])) {
        return is_array($GLOBALS['_booking_tool_create_fixture'])
            ? $GLOBALS['_booking_tool_create_fixture']
            : $fail('fixture_failed');
    }
    $settings = booking_settings_for_bot($botId);
    if (empty($settings['enabled'])) {
        return $fail('booking_not_configured');
    }
    $tz = new DateTimeZone((string) ($settings['timezone'] ?? 'Asia/Karachi'));
    $start = $start->setTimezone($tz);
    $end = $end->setTimezone($tz);
    if (!booking_slot_is_free($botId, $start, $end)) {
        return $fail('slot_unavailable');
    }
    if ($userId <= 0) {
        require_once dirname(__DIR__) . '/db.php';
        $bot = db_fetch('SELECT user_id FROM bots WHERE id = ?', 'i', [$botId]);
        $userId = (int) ($bot['user_id'] ?? 0);
    }
    if ($userId <= 0) {
        return $fail('invalid_tenant');
    }
    if ($phone === null || $phone === '') {
        require_once dirname(__DIR__) . '/db.php';
        $lead = db_fetch('SELECT phone, name FROM leads WHERE id = ? AND bot_id = ?', 'ii', [$leadId, $botId]);
        if (!$lead) {
            return $fail('invalid_lead');
        }
        $phone = trim((string) ($lead['phone'] ?? ''));
        if ($name === null || $name === '') {
            $name = trim((string) ($lead['name'] ?? ''));
        }
    }
    try {
        $id = booking_create_appointment($botId, $userId, $leadId, $start, $end, $name, $phone);

        return [
            'ok'         => $id > 0,
            'booking_id' => $id,
            'status'     => $id > 0 ? 'confirmed' : 'failed',
            'service'    => (string) ($service ?? ''),
            'date'       => $start->format('Y-m-d'),
            'time'       => $start->format('H:i'),
            'timezone'   => (string) ($settings['timezone'] ?? 'UTC'),
            'error'      => $id > 0 ? '' : 'create_failed',
        ];
    } catch (Throwable $e) {
        return $fail('create_error');
    }
}
