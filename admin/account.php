<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $result = admin_update_own_profile((int) $user['id'], [
            'name'  => $_POST['name'] ?? '',
            'email' => $_POST['email'] ?? '',
        ]);
        if ($result['success']) {
            $message = $result['message'];
            $user = require_admin();
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'password') {
        $result = change_password(
            (int) $user['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

iqp_admin_begin($user, 'settings', [
    'title'    => 'Account',
    'subtitle' => 'Your profile and security settings',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="grid grid-2" style="align-items:start;max-width:920px">
  <form method="post" class="card">
    <div class="card__head"><span class="card__title">Profile</span></div>
    <div class="card__body">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="profile"/>

      <div class="field">
        <label>Full name</label>
        <input class="input" type="text" name="name" required value="<?= sanitize((string) ($user['name'] ?? '')) ?>"/>
      </div>
      <div class="field">
        <label>Admin email</label>
        <input class="input" type="email" name="email" required value="<?= sanitize((string) ($user['email'] ?? '')) ?>"/>
      </div>
      <div class="field">
        <label>Role</label>
        <span class="badge badge--purple">Admin</span>
      </div>

      <button type="submit" class="btn btn--primary">Save profile</button>
    </div>
  </form>

  <form method="post" class="card">
    <div class="card__head"><span class="card__title">Change password</span></div>
    <div class="card__body">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="password"/>

      <div class="field">
        <label>Current password</label>
        <input class="input" type="password" name="current_password" required autocomplete="current-password"/>
      </div>
      <div class="field">
        <label>New password</label>
        <input class="input" type="password" name="new_password" required minlength="8" autocomplete="new-password"/>
        <span class="hint">Minimum 8 characters.</span>
      </div>
      <div class="field">
        <label>Confirm new password</label>
        <input class="input" type="password" name="confirm_password" required minlength="8" autocomplete="new-password"/>
      </div>

      <button type="submit" class="btn btn--primary">Update password</button>
    </div>
  </form>
</div>
<?php iqp_admin_end();
