<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/business-hours.php';
require_once __DIR__ . '/../includes/commerce-schema.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/team-roles.php';

ensure_user_profile_schema();

$user = require_login();
$userId = (int) $user['id'];

if (preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? '')) === 'integrations') {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    $q = preg_replace('/(^|&)tab=integrations(&|$)/', '$1', $q);
    $q = trim($q, '&');
    redirect('/client/integrations' . ($q !== '' ? '?' . $q : ''));
}

$message = trim((string)($_GET['msg'] ?? ''));
$error   = trim((string)($_GET['err'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        redirect('/client/settings?tab=' . urlencode((string) ($_POST['return_tab'] ?? 'profile')) . '&err=' . urlencode('Session expired. Please try again.'));
    }

    $action = $_POST['action'] ?? '';
    $returnTab = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_tab'] ?? 'profile')) ?: 'profile';

    if ($action === 'profile') {
        $name  = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $profileTitle = profile_title_normalize((string) ($_POST['profile_title'] ?? 'owner'));

        if ($name === '') {
            $error = 'Name is required.';
        } else {
            // Handle avatar upload
            $avatarUpdate = '';
            if (!empty($_FILES['avatar_file']['tmp_name'])) {
                $file     = $_FILES['avatar_file'];
                $allowed  = ['image/jpeg','image/png','image/webp'];
                $ftype    = mime_content_type($file['tmp_name']);
                if (!in_array($ftype, $allowed, true)) {
                    $error = 'Invalid image type. Use JPG, PNG, or WebP.';
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    $error = 'Image too large. Max 2MB.';
                } else {
                    $ext     = $ftype === 'image/png' ? 'png' : ($ftype === 'image/webp' ? 'webp' : 'jpg');
                    $dir     = dirname(__DIR__) . '/uploads/avatars';
                    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                    $fname   = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                    $dest    = $dir . '/' . $fname;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $avatarUpdate = '/uploads/avatars/' . $fname;
                    } else {
                        $error = 'Could not save image. Check server permissions.';
                    }
                }
            }

            if ($error === '') {
                ensure_user_profile_schema();
                $sql    = 'UPDATE users SET name = ?, avatar_initials = ?';
                $types  = 'ss';
                $params = [$name, get_initials($name)];
                if (db_column_exists('users', 'phone')) {
                    $sql .= ', phone = ?';
                    $types .= 's';
                    $params[] = $phone !== '' ? $phone : null;
                }
                if (db_column_exists('users', 'profile_title')) {
                    $sql .= ', profile_title = ?';
                    $types .= 's';
                    $params[] = $profileTitle;
                }
                if ($avatarUpdate !== '') {
                    $sql    .= ', avatar_url = ?';
                    $types  .= 's';
                    $params[] = $avatarUpdate;
                }
                $sql    .= ' WHERE id = ?';
                $types  .= 'i';
                $params[] = $userId;
                db_execute($sql, $types, $params);
                $message = 'Profile updated.';
            }
        }

        redirect('/client/settings?tab=profile'
            . ($message !== '' ? '&msg=' . urlencode($message) : '')
            . ($error !== '' ? '&err=' . urlencode($error) : ''));
    }

    if ($action === 'password') {
        // Handled via AJAX â€” legacy POST ignored
    }

    if ($action === 'save_business_hours') {
        business_hours_save_for_user($userId, [
            'timezone'    => $_POST['business_timezone'] ?? 'Asia/Karachi',
            'always_open' => !empty($_POST['business_always_open']),
            'hours'       => business_hours_parse_post($_POST),
        ]);
        $message = 'Operating hours saved.';
    }

    if ($action === 'updates') {
        if (($_POST['subscribe_updates'] ?? '') === '1') {
            $result = subscribe_to_updates($user['email'], $user['name'], $userId, 'settings');
            $message = $result['message'];
            if (!$result['success']) {
                $error = $result['message'];
                $message = '';
            }
        } else {
            $result = unsubscribe_email_from_updates($user['email']);
            $message = $result['message'];
            if (!$result['success']) {
                $error = $result['message'];
                $message = '';
            }
        }
    }

    if ($action === 'save_preferences') {
        ensure_user_profile_schema();
        $allowedLanguages  = ['English', 'Urdu', 'Arabic', 'French'];
        $allowedCurrencies = ['PKR', 'USD', 'EUR', 'GBP', 'AED'];
        $allowedDateFmts   = ['DD MMM, YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD/MM/YYYY'];
        $allowedTimezones  = ['Asia/Karachi', 'UTC', 'Europe/London', 'Asia/Kolkata', 'America/New_York'];

        $lang     = in_array($_POST['pref_language'] ?? '', $allowedLanguages, true)
            ? $_POST['pref_language'] : 'English';
        $tz       = in_array($_POST['pref_timezone'] ?? '', $allowedTimezones, true)
            ? $_POST['pref_timezone'] : 'Asia/Karachi';
        $currency = in_array($_POST['pref_currency'] ?? '', $allowedCurrencies, true)
            ? $_POST['pref_currency'] : 'PKR';
        $datefmt  = in_array($_POST['pref_date_format'] ?? '', $allowedDateFmts, true)
            ? $_POST['pref_date_format'] : 'DD MMM, YYYY';

        if (db_column_exists('users', 'pref_language')) {
            db_execute(
                'UPDATE users SET pref_language = ?, pref_timezone = ?, pref_currency = ?, pref_date_format = ? WHERE id = ?',
                'ssssi',
                [$lang, $tz, $currency, $datefmt, $userId]
            );
            $message = 'Preferences saved.';
        } else {
            $error = 'Preferences could not be saved. Run migrate or contact support.';
        }

        redirect('/client/settings?tab=profile'
            . ($message !== '' ? '&msg=' . urlencode($message) : '')
            . ($error !== '' ? '&err=' . urlencode($error) : ''));
    }

    if ($action === 'team_add') {
        require_once __DIR__ . '/../includes/team.php';
        try {
            team_member_save($userId, $_POST);
            $message = 'Team member added.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        redirect('/client/settings?tab=team' . ($message !== '' ? '&msg=' . urlencode($message) : '') . ($error !== '' ? '&err=' . urlencode($error) : ''));
    }

    if ($action === 'team_update') {
        require_once __DIR__ . '/../includes/team.php';
        try {
            team_member_save($userId, $_POST, (int) ($_POST['member_id'] ?? 0));
            $message = 'Team member updated.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        redirect('/client/settings?tab=team' . ($message !== '' ? '&msg=' . urlencode($message) : '') . ($error !== '' ? '&err=' . urlencode($error) : ''));
    }

    if ($action === 'team_delete') {
        require_once __DIR__ . '/../includes/team.php';
        try {
            team_member_delete((int) ($_POST['member_id'] ?? 0), $userId);
            $message = 'Team member removed.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        redirect('/client/settings?tab=team' . ($message !== '' ? '&msg=' . urlencode($message) : '') . ($error !== '' ? '&err=' . urlencode($error) : ''));
    }
}

$user = require_login();
$userId = (int) $user['id'];

$botRow = db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
$botId = (int) ($botRow['id'] ?? 0);
$hoursConfig = $botId > 0 ? business_hours_for_bot($botId) : [
    'timezone'    => 'Asia/Karachi',
    'always_open' => true,
    'hours'       => business_hours_default_week(),
];
$hoursStatus = $botId > 0 ? business_hours_status_label($botId) : 'Open now';
$dayLabels = [
    'mon' => 'Monday',
    'tue' => 'Tuesday',
    'wed' => 'Wednesday',
    'thu' => 'Thursday',
    'fri' => 'Friday',
    'sat' => 'Saturday',
    'sun' => 'Sunday',
];
$subscribedToUpdates = is_subscribed_to_updates($user['email']);

// Read stored preferences (with safe defaults)
$prefLanguage   = (string)($user['pref_language']    ?? 'English');
$prefTimezone   = (string)($user['pref_timezone']    ?? 'Asia/Karachi');
$prefCurrency   = (string)($user['pref_currency']    ?? 'PKR');
$prefDateFormat = (string)($user['pref_date_format'] ?? 'DD MMM, YYYY');
$avatarUrl      = (string)($user['avatar_url']       ?? '');

$settingsTab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'profile')) ?: 'profile';
$teamMembers = [];
if ($settingsTab === 'team') {
    require_once __DIR__ . '/../includes/team.php';
    $teamMembers = team_members_for_user($userId, false);
}

$activeTab = 'settings';
require_once __DIR__ . '/../includes/iqp-ui.php';
require __DIR__ . '/../includes/views/client-settings.php';
return;
