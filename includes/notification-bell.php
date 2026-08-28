<?php
/**
 * Live notification bell — inline in page header on client; fixed on admin.
 * Pure PHP echo output (no ?> gaps) on admin paths.
 */
declare(strict_types=1);

function notification_bell_context(): array
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }

    $ctx = ['ok' => false, 'unread' => 0, 'allUrl' => '/client/notifications'];

    try {
        if (!function_exists('notifications_tables_ready')) {
            require_once __DIR__ . '/notifications.php';
        }

        $user = get_user();
        if (!$user || !notifications_tables_ready()) {
            return $ctx;
        }

        $isAdmin = ($user['role'] ?? '') === 'admin';
        $ctx = [
            'ok'     => true,
            'unread' => get_unread_notification_count((int) $user['id']),
            'allUrl' => $isAdmin ? '/admin/announcements' : '/client/notifications',
        ];
    } catch (Throwable $e) {
        error_log('notification-bell init failed: ' . $e->getMessage());
    }

    return $ctx;
}

function render_notification_bell_panel_markup(array $ctx): void
{
    $unread = (int) ($ctx['unread'] ?? 0);
    $allUrl = $ctx['allUrl'] ?? '/client/notifications';
    $badgeHidden = $unread > 0 ? '' : ' hidden';
    $badgeText = $unread > 99 ? '99+' : (string) $unread;

    echo '<button type="button" id="notification-bell-btn" class="notification-bell-btn" aria-label="Notifications">';
    echo '<span class="material-symbols-outlined">notifications</span>';
    echo '<span id="notification-badge" class="notification-bell-badge' . $badgeHidden . '" data-count="' . $unread . '">' . sanitize($badgeText) . '</span>';
    echo '</button>';
    echo '<div id="notification-panel" class="notification-panel hidden" role="dialog" aria-label="Notifications">';
    echo '<div class="notification-panel__head">';
    echo '<span class="font-title text-title-md">Notifications</span>';
    echo '<button type="button" id="notification-mark-all" class="notification-panel__mark-all">Mark all read</button>';
    echo '</div>';
    echo '<div id="notification-list" class="notification-panel__list">';
    echo '<p class="p-md text-body-md text-on-surface-variant text-center">Loading…</p>';
    echo '</div>';
    echo '<a href="' . sanitize($allUrl) . '" class="notification-panel__footer">View all updates</a>';
    echo '</div>';
}

function render_notification_bell_inline(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }

    $ctx = notification_bell_context();
    if (!$ctx['ok']) {
        return;
    }

    $rendered = true;
    notification_bell_scripts_once();
    echo '<div id="notification-bell-root" class="notification-bell-root notification-bell-root--inline" data-csrf="' . sanitize(csrf_token()) . '">';
    render_notification_bell_panel_markup($ctx);
    echo '</div>';
}

function render_notification_bell(): void
{
    render_admin_notification_bell();
}

function render_admin_notification_bell(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }

    $ctx = notification_bell_context();
    if (!$ctx['ok']) {
        return;
    }

    $rendered = true;
    notification_bell_scripts_once();
    echo '<div id="notification-bell-root" class="notification-bell-root notification-bell-root--admin" data-csrf="' . sanitize(csrf_token()) . '">';
    render_notification_bell_panel_markup($ctx);
    echo '</div>';
}

function notification_bell_scripts_once(): void
{
    static $scripts = false;
    if ($scripts) {
        return;
    }
    $scripts = true;
    echo '<script src="/assets/js/notifications.js"></script>';
}
