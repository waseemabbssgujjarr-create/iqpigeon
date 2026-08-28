<?php
/**
 * Team inbox — members, lead assignment, internal notes.
 */

require_once __DIR__ . '/phase5-schema.php';
require_once __DIR__ . '/team-roles.php';

/**
 * @return array<int, array<string, mixed>>
 */
function team_members_for_user(int $userId, bool $activeOnly = true): array
{
    ensure_phase5_schema();
    $sql = 'SELECT * FROM team_members WHERE user_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';
    return db_fetch_all($sql, 'i', [$userId]);
}

function team_member_get(int $memberId, int $userId): ?array
{
    ensure_phase5_schema();
    $row = db_fetch(
        'SELECT * FROM team_members WHERE id = ? AND user_id = ?',
        'ii',
        [$memberId, $userId]
    );
    return $row ?: null;
}

function team_member_save(int $userId, array $data, ?int $memberId = null): int
{
    ensure_phase5_schema();
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Team member name is required.');
    }

    $email = trim((string) ($data['email'] ?? ''));
    $color = trim((string) ($data['color'] ?? '#6366f1'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#6366f1';
    }
    $designation = team_designation_normalize((string) ($data['designation'] ?? 'agent'));
    if (!in_array($designation, team_member_designation_keys(), true)) {
        $designation = 'agent';
    }
    $active = !empty($data['is_active']) ? 1 : 0;

    if ($memberId) {
        db_execute(
            'UPDATE team_members SET name=?, email=?, designation=?, color=?, is_active=? WHERE id=? AND user_id=?',
            'ssssiii',
            [$name, $email !== '' ? $email : null, $designation, $color, $active, $memberId, $userId]
        );
        return $memberId;
    }

    return db_insert(
        'INSERT INTO team_members (user_id, name, email, designation, color, is_active) VALUES (?, ?, ?, ?, ?, 1)',
        'issss',
        [$userId, $name, $email !== '' ? $email : null, $designation, $color]
    );
}

function team_member_delete(int $memberId, int $userId): void
{
    ensure_phase5_schema();
    db_execute('DELETE FROM team_members WHERE id = ? AND user_id = ?', 'ii', [$memberId, $userId]);
    db_execute('UPDATE leads SET assigned_member_id = NULL WHERE assigned_member_id = ?', 'i', [$memberId]);
}

function team_assign_lead(int $leadId, int $userId, ?int $memberId, string $priority = 'normal'): bool
{
    ensure_phase5_schema();
    $lead = db_fetch(
        'SELECT l.id FROM leads l JOIN bots b ON b.id = l.bot_id WHERE l.id = ? AND b.user_id = ?',
        'ii',
        [$leadId, $userId]
    );
    if (!$lead) {
        return false;
    }

    if ($memberId !== null) {
        $member = team_member_get($memberId, $userId);
        if (!$member) {
            return false;
        }
    }

    $validPriority = ['normal', 'high', 'urgent'];
    if (!in_array($priority, $validPriority, true)) {
        $priority = 'normal';
    }

    db_execute(
        'UPDATE leads SET assigned_member_id = ?, inbox_priority = ?, updated_at = NOW() WHERE id = ?',
        'isi',
        [$memberId, $priority, $leadId]
    );
    return true;
}

function team_add_note(int $leadId, int $userId, string $note, string $authorName = 'Owner'): int
{
    ensure_phase5_schema();
    $note = trim($note);
    if ($note === '') {
        throw new InvalidArgumentException('Note cannot be empty.');
    }

    $lead = db_fetch(
        'SELECT l.id FROM leads l JOIN bots b ON b.id = l.bot_id WHERE l.id = ? AND b.user_id = ?',
        'ii',
        [$leadId, $userId]
    );
    if (!$lead) {
        throw new InvalidArgumentException('Lead not found.');
    }

    return db_insert(
        'INSERT INTO lead_internal_notes (lead_id, user_id, author_name, note) VALUES (?, ?, ?, ?)',
        'iiss',
        [$leadId, $userId, $authorName, $note]
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function team_notes_for_lead(int $leadId, int $userId): array
{
    ensure_phase5_schema();
    return db_fetch_all(
        'SELECT n.* FROM lead_internal_notes n
         JOIN leads l ON l.id = n.lead_id
         JOIN bots b ON b.id = l.bot_id
         WHERE n.lead_id = ? AND b.user_id = ?
         ORDER BY n.created_at DESC',
        'ii',
        [$leadId, $userId]
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function team_inbox_leads(int $userId, ?int $memberId = null, ?string $priority = null, int $limit = 100): array
{
    ensure_phase5_schema();
    $sql = 'SELECT l.*, b.name AS bot_name, tm.name AS assignee_name, tm.color AS assignee_color,
            (SELECT message FROM conversations c WHERE c.lead_id = l.id ORDER BY c.created_at DESC LIMIT 1) AS last_message
            FROM leads l
            JOIN bots b ON b.id = l.bot_id
            LEFT JOIN team_members tm ON tm.id = l.assigned_member_id
            WHERE b.user_id = ?';
    $types = 'i';
    $params = [$userId];

    if ($memberId !== null) {
        if ($memberId === 0) {
            $sql .= ' AND l.assigned_member_id IS NULL';
        } else {
            $sql .= ' AND l.assigned_member_id = ?';
            $types .= 'i';
            $params[] = $memberId;
        }
    }

    if ($priority !== null && $priority !== 'all') {
        $sql .= ' AND l.inbox_priority = ?';
        $types .= 's';
        $params[] = $priority;
    }

    $sql .= ' ORDER BY FIELD(l.inbox_priority, \'urgent\', \'high\', \'normal\'), l.updated_at DESC LIMIT ' . max(1, min(200, $limit));

    return db_fetch_all($sql, $types, $params);
}

function team_priority_label(string $priority): string
{
    return match ($priority) {
        'urgent' => 'Urgent',
        'high'   => 'High',
        default  => 'Normal',
    };
}

function team_priority_badge_class(string $priority): string
{
    return match ($priority) {
        'urgent' => 'bg-error-container text-on-error-container',
        'high'   => 'bg-tertiary-container text-on-tertiary-container',
        default  => 'bg-surface-container text-on-surface-variant',
    };
}
