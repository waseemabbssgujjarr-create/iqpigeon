<?php
/**
 * Native booking — availability slots + appointments (Calendly alternative).
 */

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/helpers.php';

function booking_default_working_hours(): array
{
    $day = [['10:00', '18:00']];
    return [
        'mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day, 'fri' => $day,
        'sat' => [], 'sun' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function booking_settings_for_bot(int $botId): array
{
    ensure_commerce_schema();
    $row = db_fetch('SELECT * FROM bot_booking_settings WHERE bot_id = ?', 'i', [$botId]);
    if (!$row) {
        return [
            'bot_id'             => $botId,
            'enabled'            => 0,
            'slot_duration_min'  => 30,
            'buffer_min'         => 0,
            'timezone'           => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Karachi',
            'working_hours'      => booking_default_working_hours(),
            'use_native_booking' => 1,
            'fallback_calendly'  => defined('DEFAULT_CALENDLY_LINK') ? DEFAULT_CALENDLY_LINK : '',
        ];
    }

    $hours = json_decode((string) ($row['working_hours'] ?? ''), true);
    if (!is_array($hours)) {
        $hours = booking_default_working_hours();
    }
    $row['working_hours'] = $hours;

    return $row;
}

/**
 * @param array<string, mixed> $data
 */
function booking_save_settings(int $botId, int $userId, array $data): void
{
    ensure_commerce_schema();

    $hours = $data['working_hours'] ?? booking_default_working_hours();
    if (!is_array($hours)) {
        $hours = booking_default_working_hours();
    }

    $fallback = trim((string) ($data['fallback_calendly'] ?? ''));
    if ($fallback === '' && defined('DEFAULT_CALENDLY_LINK')) {
        $fallback = trim(DEFAULT_CALENDLY_LINK);
    }

    $existing = db_fetch('SELECT bot_id FROM bot_booking_settings WHERE bot_id = ?', 'i', [$botId]);
    if ($existing) {
        db_execute(
            'UPDATE bot_booking_settings SET enabled=?, slot_duration_min=?, buffer_min=?, timezone=?,
             working_hours=?, use_native_booking=?, fallback_calendly=?, user_id=?
             WHERE bot_id=?',
            'iiissisii',
            [
                !empty($data['enabled']) ? 1 : 0,
                max(15, min(120, (int) ($data['slot_duration_min'] ?? 30))),
                max(0, (int) ($data['buffer_min'] ?? 0)),
                trim((string) ($data['timezone'] ?? 'Asia/Karachi')) ?: 'Asia/Karachi',
                json_encode($hours, JSON_UNESCAPED_UNICODE),
                !empty($data['use_native_booking']) ? 1 : 0,
                $fallback,
                $userId,
                $botId,
            ]
        );
        return;
    }

    db_execute(
        'INSERT INTO bot_booking_settings (bot_id, user_id, enabled, slot_duration_min, buffer_min, timezone, working_hours, use_native_booking, fallback_calendly)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iiiiissis',
        [
            $botId,
            $userId,
            !empty($data['enabled']) ? 1 : 0,
            max(15, min(120, (int) ($data['slot_duration_min'] ?? 30))),
            max(0, (int) ($data['buffer_min'] ?? 0)),
            trim((string) ($data['timezone'] ?? 'Asia/Karachi')) ?: 'Asia/Karachi',
            json_encode($hours, JSON_UNESCAPED_UNICODE),
            !empty($data['use_native_booking']) ? 1 : 0,
            $fallback,
        ]
    );
}

/**
 * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable, label: string}>
 */
function booking_next_slots(int $botId, int $count = 6, ?DateTimeImmutable $from = null): array
{
    $settings = booking_settings_for_bot($botId);
    if (empty($settings['enabled'])) {
        return [];
    }

    $tz = new DateTimeZone((string) ($settings['timezone'] ?? 'Asia/Karachi'));
    $from = ($from ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $duration = max(15, (int) ($settings['slot_duration_min'] ?? 30));
    $buffer = max(0, (int) ($settings['buffer_min'] ?? 0));
    $hours = $settings['working_hours'] ?? booking_default_working_hours();
    $dayKeys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    $booked = db_fetch_all(
        'SELECT slot_start, slot_end FROM bot_appointments
         WHERE bot_id = ? AND status IN (\'pending\', \'confirmed\') AND slot_start >= ?',
        'is',
        [$botId, $from->format('Y-m-d H:i:s')]
    );

    $blocked = [];
    foreach ($booked as $b) {
        try {
            $bs = new DateTimeImmutable($b['slot_start'], $tz);
            $be = new DateTimeImmutable($b['slot_end'], $tz);
            $blocked[] = [$bs->getTimestamp(), $be->getTimestamp()];
        } catch (Throwable $e) {
            continue;
        }
    }

    $slots = [];
    $cursor = $from;
    $maxDays = 21;

    for ($d = 0; $d < $maxDays && count($slots) < $count; $d++) {
        $dayKey = $dayKeys[(int) $cursor->format('w')];
        $ranges = $hours[$dayKey] ?? [];
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }
            [$startH, $endH] = $range;
            try {
                $dayStart = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $startH, $tz);
                $dayEnd = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $endH, $tz);
            } catch (Throwable $e) {
                continue;
            }

            $slotStart = $dayStart;
            if ($slotStart < $from) {
                $mins = (int) ceil($from->getTimestamp() / ($duration * 60)) * ($duration * 60);
                $slotStart = (new DateTimeImmutable('@' . $mins))->setTimezone($tz);
                if ($slotStart < $from) {
                    $slotStart = $from;
                }
            }

            while ($slotStart < $dayEnd && count($slots) < $count) {
                $slotEnd = $slotStart->modify('+' . $duration . ' minutes');
                if ($slotEnd > $dayEnd) {
                    break;
                }

                $ok = true;
                $st = $slotStart->getTimestamp();
                $en = $slotEnd->getTimestamp();
                foreach ($blocked as [$bs, $be]) {
                    if ($st < $be && $en > $bs) {
                        $ok = false;
                        break;
                    }
                }

                if ($ok && $slotStart > $from) {
                    $slots[] = [
                        'start' => $slotStart,
                        'end'   => $slotEnd,
                        'label' => $slotStart->format('D j M, g:i A'),
                    ];
                }

                $slotStart = $slotEnd->modify('+' . $buffer . ' minutes');
            }
        }
        $cursor = $cursor->modify('+1 day')->setTime(0, 0);
    }

    return $slots;
}

/**
 * WhatsApp-friendly numbered slot list for qualified leads.
 */
function booking_slots_message(int $botId, int $count = 6): string
{
    $slots = booking_next_slots($botId, $count);
    if ($slots === []) {
        return '';
    }

    $lines = ['Pick a time (reply with the number):'];
    foreach ($slots as $i => $slot) {
        $lines[] = ($i + 1) . '. ' . $slot['label'];
    }

    return implode("\n", $lines);
}

/**
 * @return array<int, array<string, mixed>>
 */
function booking_appointments_for_user(int $userId, ?int $botId = null, int $limit = 30): array
{
    ensure_commerce_schema();
    if ($botId) {
        return db_fetch_all(
            'SELECT a.*, b.name AS bot_name, l.name AS lead_name
             FROM bot_appointments a
             JOIN bots b ON b.id = a.bot_id
             LEFT JOIN leads l ON l.id = a.lead_id
             WHERE a.user_id = ? AND a.bot_id = ?
             ORDER BY a.slot_start DESC LIMIT ' . max(1, min(100, $limit)),
            'ii',
            [$userId, $botId]
        );
    }
    return db_fetch_all(
        'SELECT a.*, b.name AS bot_name, l.name AS lead_name
         FROM bot_appointments a
         JOIN bots b ON b.id = a.bot_id
         LEFT JOIN leads l ON l.id = a.lead_id
         WHERE a.user_id = ?
         ORDER BY a.slot_start DESC LIMIT ' . max(1, min(100, $limit)),
        'i',
        [$userId]
    );
}

function booking_create_appointment(int $botId, int $userId, int $leadId, DateTimeImmutable $start, DateTimeImmutable $end, ?string $name = null, ?string $phone = null): int
{
    ensure_commerce_schema();
    $id = db_insert(
        'INSERT INTO bot_appointments (bot_id, lead_id, user_id, slot_start, slot_end, status, customer_name, customer_phone)
         VALUES (?, ?, ?, ?, ?, \'confirmed\', ?, ?)',
        'iiissss',
        [$botId, $leadId, $userId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $name, $phone]
    );

    db_execute(
        'UPDATE leads SET status = \'booked\', score = GREATEST(score, 90) WHERE id = ?',
        'i',
        [$leadId]
    );

    return $id;
}

function booking_resolve_slot_choice(int $botId, string $userMessage): ?array
{
    $text = trim($userMessage);
    if (!preg_match('/^(\d{1,2})$/', $text, $m)) {
        return null;
    }
    $idx = (int) $m[1] - 1;
    $slots = booking_next_slots($botId, 8);
    return $slots[$idx] ?? null;
}

function booking_slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'bot';
}

function booking_ensure_public_slug(int $botId, int $userId): string
{
    ensure_commerce_schema();
    $settings = booking_settings_for_bot($botId);
    if (!empty($settings['public_slug'])) {
        return (string) $settings['public_slug'];
    }

    $bot = db_fetch('SELECT name FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    $base = booking_slugify((string) ($bot['name'] ?? 'bot')) . '-' . $botId;
    db_execute(
        'UPDATE bot_booking_settings SET public_slug = ? WHERE bot_id = ?',
        'si',
        [$base, $botId]
    );
    return $base;
}

function booking_public_url(int $botId, int $userId): string
{
    $slug = booking_ensure_public_slug($botId, $userId);
    return rtrim(APP_URL, '/') . '/book.php?slug=' . urlencode($slug);
}

/**
 * @return array<string, mixed>|null
 */
function booking_bot_by_slug(string $slug): ?array
{
    ensure_commerce_schema();
    $row = db_fetch(
        'SELECT b.*, s.enabled AS booking_enabled, s.public_slug, s.timezone
         FROM bots b
         JOIN bot_booking_settings s ON s.bot_id = b.id
         WHERE s.public_slug = ? AND s.enabled = 1 AND b.is_active = 1',
        's',
        [$slug]
    );
    return $row ?: null;
}

function booking_create_public_appointment(int $botId, int $userId, DateTimeImmutable $start, DateTimeImmutable $end, string $name, string $phone, ?string $notes = null): int
{
    ensure_commerce_schema();
    return db_insert(
        'INSERT INTO bot_appointments (bot_id, lead_id, user_id, slot_start, slot_end, status, customer_name, customer_phone, notes, source)
         VALUES (?, NULL, ?, ?, ?, \'confirmed\', ?, ?, ?, \'public\')',
        'iisssss',
        [$botId, $userId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $name, $phone, $notes]
    );
}

/**
 * @return array{sent_24h: int, sent_1h: int}
 */
function booking_process_reminders(): array
{
    ensure_commerce_schema();
    require_once __DIR__ . '/whatsapp.php';

    $sent24 = 0;
    $sent1h = 0;

    $due24 = db_fetch_all(
        'SELECT a.*, b.name AS bot_name FROM bot_appointments a
         JOIN bots b ON b.id = a.bot_id
         WHERE a.status = \'confirmed\' AND a.reminder_24h_sent = 0
         AND a.slot_start BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
         AND a.customer_phone IS NOT NULL AND a.customer_phone != \'\''
    );
    foreach ($due24 as $appt) {
        if (booking_send_reminder($appt, '24h')) {
            db_execute('UPDATE bot_appointments SET reminder_24h_sent = 1 WHERE id = ?', 'i', [(int) $appt['id']]);
            $sent24++;
        }
    }

    $due1 = db_fetch_all(
        'SELECT a.*, b.name AS bot_name FROM bot_appointments a
         JOIN bots b ON b.id = a.bot_id
         WHERE a.status = \'confirmed\' AND a.reminder_1h_sent = 0
         AND a.slot_start BETWEEN DATE_ADD(NOW(), INTERVAL 50 MINUTE) AND DATE_ADD(NOW(), INTERVAL 70 MINUTE)
         AND a.customer_phone IS NOT NULL AND a.customer_phone != \'\''
    );
    foreach ($due1 as $appt) {
        if (booking_send_reminder($appt, '1h')) {
            db_execute('UPDATE bot_appointments SET reminder_1h_sent = 1 WHERE id = ?', 'i', [(int) $appt['id']]);
            $sent1h++;
        }
    }

    return ['sent_24h' => $sent24, 'sent_1h' => $sent1h];
}

/**
 * @param array<string, mixed> $appt
 */
function booking_send_reminder(array $appt, string $window): bool
{
    $phone = preg_replace('/\D/', '', (string) ($appt['customer_phone'] ?? ''));
    if ($phone === '') {
        return false;
    }

    $creds = whatsapp_bot_credentials((int) $appt['bot_id'], (int) $appt['user_id']);
    $slot = date('D j M, g:i A', strtotime($appt['slot_start']));
    $msg = $window === '1h'
        ? "Reminder: your call is in about 1 hour — {$slot}. See you soon!"
        : "Reminder: your appointment is tomorrow — {$slot}. Reply if you need to reschedule.";

    if ($creds) {
        $result = send_whatsapp_message($creds['phone_id'], $creds['token'], $phone, $msg);
        return !empty($result['success']);
    }

    return false;
}

function booking_ai_prompt_block(int $botId): string
{
    $settings = booking_settings_for_bot($botId);
    if (empty($settings['enabled']) || empty($settings['use_native_booking'])) {
        return '';
    }

    $lines = [
        '',
        '───── NATIVE BOOKING ─────',
        'When qualified ([BOOK_CALL]), offer numbered time slots from your availability instead of only an external link.',
        'After customer picks a number, confirm the appointment warmly.',
        'Slot duration: ' . (int) ($settings['slot_duration_min'] ?? 30) . ' minutes.',
    ];
    $fallback = trim((string) ($settings['fallback_calendly'] ?? ''));
    if ($fallback !== '') {
        $lines[] = 'If no slots fit, share fallback calendar: ' . $fallback;
    }

    return implode("\n", $lines);
}
