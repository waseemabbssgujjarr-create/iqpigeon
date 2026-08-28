<?php
/**
 * Client team member designations and access levels.
 *
 * Used when assigning agents to leads; documents what each role can do today.
 * Future logins for team members can enforce these flags.
 */

/**
 * @return array<string, array{label: string, summary: string, can_view: bool, can_edit: bool, can_manage_team: bool, can_manage_billing: bool}>
 */
function team_designations(): array
{
    return [
        'owner' => [
            'label'              => 'Owner',
            'summary'            => 'Full access — settings, billing, shop, orders, and team.',
            'can_view'           => true,
            'can_edit'           => true,
            'can_manage_team'    => true,
            'can_manage_billing' => true,
        ],
        'admin' => [
            'label'              => 'Admin',
            'summary'            => 'Manage shop, orders, leads, and team. Cannot change subscription or delete the account.',
            'can_view'           => true,
            'can_edit'           => true,
            'can_manage_team'    => true,
            'can_manage_billing' => false,
        ],
        'manager' => [
            'label'              => 'Manager',
            'summary'            => 'View and edit leads, orders, and catalog. Cannot change settings or billing.',
            'can_view'           => true,
            'can_edit'           => true,
            'can_manage_team'    => false,
            'can_manage_billing' => false,
        ],
        'agent' => [
            'label'              => 'Sales Agent',
            'summary'            => 'View and reply to assigned leads. Cannot edit shop, orders, or settings.',
            'can_view'           => true,
            'can_edit'           => false,
            'can_manage_team'    => false,
            'can_manage_billing' => false,
        ],
        'moderator' => [
            'label'              => 'Moderator',
            'summary'            => 'View-only — read conversations, leads, and orders. Cannot edit anything.',
            'can_view'           => true,
            'can_edit'           => false,
            'can_manage_team'    => false,
            'can_manage_billing' => false,
        ],
        'staff' => [
            'label'              => 'Staff',
            'summary'            => 'Support staff — view leads and reply to assigned conversations.',
            'can_view'           => true,
            'can_edit'           => false,
            'can_manage_team'    => false,
            'can_manage_billing' => false,
        ],
    ];
}

/** Designations selectable when adding/editing team members (excludes account owner). */
function team_member_designation_keys(): array
{
    return ['admin', 'manager', 'agent', 'moderator'];
}

function team_designation_normalize(string $key): string
{
    $key = strtolower(trim($key));
    $all = team_designations();
    return isset($all[$key]) ? $key : 'agent';
}

/**
 * @return array{label: string, summary: string, can_view: bool, can_edit: bool, can_manage_team: bool, can_manage_billing: bool}
 */
function team_designation_meta(string $key): array
{
    $all = team_designations();
    $key = team_designation_normalize($key);
    return $all[$key];
}

function team_designation_label(string $key): string
{
    return team_designation_meta($key)['label'];
}

function team_designation_summary(string $key): string
{
    return team_designation_meta($key)['summary'];
}

/** Profile display titles (account owner settings page). */
function profile_title_options(): array
{
    $opts = [];
    foreach (['owner', 'admin', 'manager', 'agent'] as $key) {
        $opts[$key] = team_designation_label($key);
    }
    $opts['staff'] = 'Staff';
    return $opts;
}

function profile_title_normalize(string $title): string
{
    $title = trim($title);
    foreach (profile_title_options() as $key => $label) {
        if (strcasecmp($title, $key) === 0 || strcasecmp($title, $label) === 0) {
            return $key;
        }
    }
    return 'owner';
}

function profile_title_display(string $stored): string
{
    $stored = strtolower(trim($stored));
    $opts = profile_title_options();
    if (isset($opts[$stored])) {
        return $opts[$stored];
    }
    $all = team_designations();
    if (isset($all[$stored]['label'])) {
        return (string) $all[$stored]['label'];
    }
    return $stored !== '' ? ucfirst($stored) : 'Owner';
}

function profile_title_summary(string $stored): string
{
    $key = profile_title_normalize($stored);
    $all = team_designations();
    return (string) ($all[$key]['summary'] ?? team_designation_summary('owner'));
}
