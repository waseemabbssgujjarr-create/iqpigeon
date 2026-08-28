<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$error = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $formData = [
        'name'         => trim($_POST['name'] ?? ''),
        'company_name' => trim($_POST['company_name'] ?? ''),
        'email'        => trim($_POST['email'] ?? ''),
        'password'     => $_POST['password'] ?? '',
        'plan'         => in_array($_POST['plan'] ?? '', ['starter', 'growth', 'agency'], true) ? $_POST['plan'] : 'starter',
    ];

    $result = register_client(array_merge($formData, ['confirm_password' => $formData['password']]));

    if ($result['success']) {
        db_execute(
            'UPDATE users SET subscription_plan = ?, subscription_status = \'active\' WHERE id = ?',
            'si',
            [$formData['plan'], $result['user_id']]
        );
        email_admin_new_client($formData['name'], $formData['email'], $formData['company_name']);
        redirect('/admin/businesses');
    }

    $error = $result['message'];
}

iqp_admin_begin($user, 'businesses', [
    'title'    => 'Add Business',
    'subtitle' => 'Create a new business (SaaS customer) account',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/businesses">Back to Businesses</a>',
]);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<form method="post" class="card" style="max-width:720px">
  <div class="card__head"><span class="card__title">Business details</span></div>
  <div class="card__body">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

    <div class="grid grid-2">
      <div class="field">
        <label>Full name</label>
        <input class="input" type="text" name="name" required value="<?= sanitize($formData['name'] ?? '') ?>"/>
      </div>
      <div class="field">
        <label>Company</label>
        <input class="input" type="text" name="company_name" required value="<?= sanitize($formData['company_name'] ?? '') ?>"/>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label>Email</label>
        <input class="input" type="email" name="email" required value="<?= sanitize($formData['email'] ?? '') ?>"/>
      </div>
      <div class="field">
        <label>Password</label>
        <input class="input" type="password" name="password" required minlength="8" autocomplete="new-password"/>
        <span class="hint">Minimum 8 characters.</span>
      </div>
    </div>

    <div class="field">
      <label>Plan</label>
      <select class="select" name="plan">
        <?php foreach (['starter', 'growth', 'agency'] as $p): ?>
          <option value="<?= sanitize($p) ?>"<?= ($formData['plan'] ?? 'starter') === $p ? ' selected' : '' ?>><?= sanitize(ucfirst($p)) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">The business starts active on the selected plan.</span>
    </div>

    <div class="row" style="margin-top:4px">
      <button type="submit" class="btn btn--primary">Create business</button>
      <a class="btn btn--ghost" href="/admin/businesses">Cancel</a>
    </div>
  </div>
</form>
<?php iqp_admin_end();
