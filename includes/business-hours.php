<?php
/**
 * Business operating hours — WhatsApp replies, orders, and inquiries by timezone.
 */

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/db.php';

function business_hours_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_commerce_schema();
    $conn = db_connect();
    commerce_ensure_column($conn, 'bots', 'business_timezone', "VARCHAR(64) NOT NULL DEFAULT 'Asia/Karachi'");
    commerce_ensure_column($conn, 'bots', 'business_always_open', 'TINYINT(1) NOT NULL DEFAULT 1');
    commerce_ensure_column($conn, 'bots', 'business_hours', 'JSON NULL');

    $done = true;
}

/**
 * @return array<string, array<int, array{0: string, 1: string}>>>
 */
function business_hours_default_week(): array
{
    $weekday = [['10:00', '22:00']];

    return [
        'mon' => $weekday,
        'tue' => $weekday,
        'wed' => $weekday,
        'thu' => $weekday,
        'fri' => $weekday,
        'sat' => [['11:00', '23:00']],
        'sun' => [['11:00', '23:00']],
    ];
}

/**
 * @return array<string, string>
 */
function business_hours_timezone_options(): array
{
    return [
        'UTC'                 => 'UTC (GMT+0)',
        'Asia/Karachi'        => 'Pakistan (GMT+5)',
        'Asia/Dubai'          => 'UAE (GMT+4)',
        'Asia/Riyadh'         => 'Saudi Arabia (GMT+3)',
        'Asia/Kolkata'        => 'India (GMT+5:30)',
        'Europe/London'       => 'UK (GMT/BST)',
        'Europe/Berlin'       => 'Central Europe (GMT+1)',
        'America/New_York'    => 'US Eastern (GMT-5)',
        'America/Chicago'     => 'US Central (GMT-6)',
        'America/Los_Angeles' => 'US Pacific (GMT-8)',
    ];
}

/**
 * @return array{timezone: string, always_open: bool, hours: array<string, array<int, array{0: string, 1: string}>>}
 */
function business_hours_for_bot(int $botId): array
{
    business_hours_ensure_schema();

    $row = db_fetch(
        'SELECT business_timezone, business_always_open, business_hours FROM bots WHERE id = ?',
        'i',
        [$botId]
    );

    $hours = json_decode((string) ($row['business_hours'] ?? ''), true);
    if (!is_array($hours)) {
        $hours = business_hours_default_week();
    }

    return [
        'timezone'    => trim((string) ($row['business_timezone'] ?? 'Asia/Karachi')) ?: 'Asia/Karachi',
        'always_open' => !isset($row['business_always_open']) || !empty($row['business_always_open']),
        'hours'       => $hours,
    ];
}

/**
 * Parse POST fields into working-hours JSON.
 *
 * @param array<string, mixed> $post
 * @return array<string, array<int, array{0: string, 1: string}>>>
 */
function business_hours_parse_post(array $post): array
{
    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $hours = [];

    foreach ($days as $day) {
        if (empty($post[$day . '_enabled'])) {
            $hours[$day] = [];
            continue;
        }
        $start = trim((string) ($post[$day . '_start'] ?? '10:00'));
        $end = trim((string) ($post[$day . '_end'] ?? '22:00'));
        if ($start === '' || $end === '') {
            $hours[$day] = [];
            continue;
        }
        $hours[$day] = [[substr($start, 0, 5), substr($end, 0, 5)]];
    }

    return $hours;
}

function business_hours_save_for_user(int $userId, array $data): void
{
    business_hours_ensure_schema();

    $timezone = trim((string) ($data['timezone'] ?? 'Asia/Karachi')) ?: 'Asia/Karachi';
    $alwaysOpen = !empty($data['always_open']) ? 1 : 0;
    $hours = $data['hours'] ?? business_hours_default_week();
    if (!is_array($hours)) {
        $hours = business_hours_default_week();
    }

    db_execute(
        'UPDATE bots SET business_timezone = ?, business_always_open = ?, business_hours = ? WHERE user_id = ?',
        'sisi',
        [$timezone, $alwaysOpen, json_encode($hours, JSON_UNESCAPED_UNICODE), $userId]
    );
}

function business_hours_is_open(int $botId): bool
{
    $config = business_hours_for_bot($botId);
    if ($config['always_open']) {
        return true;
    }

    try {
        $tz = new DateTimeZone($config['timezone']);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Karachi');
    }

    $now = new DateTime('now', $tz);
    $dayKey = strtolower($now->format('D'));
    $dayMap = ['mon' => 'mon', 'tue' => 'tue', 'wed' => 'wed', 'thu' => 'thu', 'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun'];
    $key = $dayMap[$dayKey] ?? 'mon';
    $ranges = $config['hours'][$key] ?? [];

    if ($ranges === []) {
        return false;
    }

    $current = (int) $now->format('H') * 60 + (int) $now->format('i');

    foreach ($ranges as $range) {
        if (!is_array($range) || count($range) < 2) {
            continue;
        }
        [$start, $end] = $range;
        $startMin = business_hours_time_to_minutes((string) $start);
        $endMin = business_hours_time_to_minutes((string) $end);
        if ($startMin === null || $endMin === null) {
            continue;
        }
        if ($endMin <= $startMin) {
            if ($current >= $startMin || $current < $endMin) {
                return true;
            }
        } elseif ($current >= $startMin && $current < $endMin) {
            return true;
        }
    }

    return false;
}

function business_hours_time_to_minutes(string $time): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m)) {
        return null;
    }

    return ((int) $m[1]) * 60 + (int) $m[2];
}

function business_hours_next_open_label(int $botId): string
{
    $config = business_hours_for_bot($botId);

    try {
        $tz = new DateTimeZone($config['timezone']);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Karachi');
    }

    $cursor = new DateTime('now', $tz);
    for ($i = 0; $i < 8; $i++) {
        $dayKey = strtolower($cursor->format('D'));
        $map = ['mon' => 'mon', 'tue' => 'tue', 'wed' => 'wed', 'thu' => 'thu', 'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun'];
        $key = $map[$dayKey] ?? 'mon';
        $ranges = $config['hours'][$key] ?? [];
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }
            $open = DateTime::createFromFormat('Y-m-d H:i', $cursor->format('Y-m-d') . ' ' . substr((string) $range[0], 0, 5), $tz);
            if ($open && $open > new DateTime('now', $tz)) {
                return $open->format('D g:i A');
            }
        }
        $cursor->modify('+1 day');
        $cursor->setTime(0, 0);
    }

    return 'soon';
}

function business_hours_closed_reply(int $botId, array $bot = []): string
{
    $brand = trim((string) ($bot['name'] ?? ''));
    $label = $brand !== '' ? $brand : 'We';
    $next = business_hours_next_open_label($botId);

    return $label . " are closed right now.\n\n"
        . "We reopen around *" . $next . "*.\n\n"
        . "Leave your message — we'll reply when we're open.";
}

function business_hours_status_label(int $botId): string
{
    if (business_hours_is_open($botId)) {
        return 'Open now';
    }

    return 'Closed — reopens ' . business_hours_next_open_label($botId);
}
