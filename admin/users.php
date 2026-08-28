<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

/**
 * Ensure the users.role ENUM can store platform 'staff' members.
 *
 * Additive and guarded: the base schema ships role ENUM('admin','client'),
 * so we widen it once to include 'staff'. If introspection or the ALTER is
 * not possible (permissions, older MySQL), we degrade to admin-only team
 * management instead of erroring.
 */
function iqp_users_staff_role_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $ok = false;
    try {
        $row = db_fetch(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'",
            's',
            [DB_NAME]
        );
        $type = strtolower((string) ($row['t'] ?? ''));
        if ($type === '') {
            return $ok; // could not introspect — stay admin-only
        }
        if (str_contains($type, "'staff'")) {
            $ok = true;
        } else {
            db_connect()->query(
                "ALTER TABLE users MODIFY COLUMN role ENUM('admin','staff','client') DEFAULT 'client'"
            );
            $ok = true;
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

$staffSupported = iqp_users_staff_role_supported();
$allowedRoles   = $staffSupported ? ['admin', 'staff'] : ['admin'];

$message  = '';
$error    = '';
$formName = '';
$formMail = '';
$formRole = $staffSupported ? 'staff' : 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = (string) ($_POST['action'] ?? 'invite');
    try {
        if ($action === 'invite') {
            $formName = trim((string) ($_POST['name'] ?? ''));
            $formMail = trim((string) ($_POST['email'] ?? ''));
            $email    = filter_var($formMail, FILTER_VALIDATE_EMAIL);
            $formRole = in_array($_POST['role'] ?? '', $allowedRoles, true)
                ? (string) $_POST['role']
                : ($staffSupported ? 'staff' : 'admin');

            if ($formName === '') {
                $error = "Please enter the member's name.";
            } elseif (!$email) {
                $error = 'Please enter a valid email address.';
            } else {
                $existing = db_fetch('SELECT id FROM users WHERE email = ?', 's', [$email]);
                if ($existing) {
                    $error = 'A user with this email already exists.';
                } else {
                    // Mirrors create-client.php / register_client(): hash before storing,
                    // never store a plaintext password. Invites get a secure random one.
                    $hash  = password_hash(bin2hex(random_bytes(9)), PASSWORD_DEFAULT);
                    $newId = db_insert(
                        "INSERT INTO users (name, email, password, role, avatar_initials, subscription_status)
                         VALUES (?, ?, ?, ?, ?, 'active')",
                        'sssss',
                        [$formName, $email, $hash, $formRole, get_initials($formName)]
                    );
                    if ($newId > 0) {
                        $message  = ucfirst($formRole) . ' "' . $formName . '" was added to the team.';
                        $formName = '';
                        $formMail = '';
                        $formRole = $staffSupported ? 'staff' : 'admin';
                    } else {
                        $error = 'Could not add the member. Please try again.';
                    }
                }
            }
        } elseif ($action === 'set_role') {
            $memberId = (int) ($_POST['member_id'] ?? 0);
            $newRole  = in_array($_POST['role'] ?? '', $allowedRoles, true) ? (string) $_POST['role'] : '';
            if ($memberId <= 0 || $newRole === '') {
                $error = 'Invalid role change request.';
            } elseif ($memberId === (int) ($user['id'] ?? 0)) {
                $error = 'You cannot change your own role.';
            } else {
                $affected = db_execute(
                    "UPDATE users SET role = ? WHERE id = ? AND role IN ('admin','staff')",
                    'si',
                    [$newRole, $memberId]
                );
                $message = $affected > 0 ? 'Role updated.' : 'No changes were made.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Could not complete the request. Please try again.';
    }
}

// ---- Filters (GET) ----
$q          = trim((string) ($_GET['q'] ?? ''));
$roleFilter = in_array($_GET['role'] ?? '', ['admin', 'staff'], true) ? (string) $_GET['role'] : '';

// ---- Stat aggregates (whole team, unfiltered; guarded) ----
$stats = ['total' => 0, 'admins' => 0, 'staff' => 0, 'active' => 0, 'new_month' => 0];
try {
    $agg = db_fetch(
        "SELECT
            COUNT(*)                                                                 AS total,
            SUM(role = 'admin')                                                      AS admins,
            SUM(role = 'staff')                                                      AS staff,
            SUM(CASE WHEN locked_until IS NULL OR locked_until < NOW() THEN 1 ELSE 0 END) AS active,
            SUM(created_at >= DATE_FORMAT(NOW(), '%Y-%m-01'))                        AS new_month
         FROM users WHERE role IN ('admin','staff')"
    );
    if ($agg) {
        $stats = [
            'total'     => (int) ($agg['total'] ?? 0),
            'admins'    => (int) ($agg['admins'] ?? 0),
            'staff'     => (int) ($agg['staff'] ?? 0),
            'active'    => (int) ($agg['active'] ?? 0),
            'new_month' => (int) ($agg['new_month'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    // keep zeros — degrade gracefully
}

// ---- Team rows (filtered; guarded) ----
$team = [];
try {
    $where  = "role IN ('admin','staff')";
    $types  = '';
    $params = [];
    if ($roleFilter !== '') {
        $where  .= ' AND role = ?';
        $types  .= 's';
        $params[] = $roleFilter;
    }
    if ($q !== '') {
        $where   .= ' AND (name LIKE ? OR email LIKE ?)';
        $types   .= 'ss';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $team = db_fetch_all(
        "SELECT id, name, email, role, avatar_initials, created_at, locked_until
         FROM users WHERE {$where}
         ORDER BY (role = 'admin') DESC, created_at ASC, id ASC",
        $types,
        $params
    );
} catch (Throwable $e) {
    $team = [];
}

$filtered = ($q !== '' || $roleFilter !== '');

iqp_admin_begin($user, 'users', [
    'title'    => 'Users & Team',
    'subtitle' => 'Manage internal team members and their access',
    'actions'  => '<a class="btn btn--primary btn--sm" href="#invite-form"><span class="ic" data-ic="plus"></span> Invite member</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4">
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="users"></span></span>
      <div class="grow"><div class="stat-card__label">Team Members</div><div class="stat-card__value"><?= (int) $stats['total'] ?></div></div></div>
    <div class="stat-card__foot">
      <?php if ($stats['new_month'] > 0): ?>
        <span class="trend up"><span class="ic" data-ic="arrowup"></span> <?= (int) $stats['new_month'] ?></span><span class="muted">this month</span>
      <?php else: ?>
        <span class="muted">internal accounts</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span>
      <div class="grow"><div class="stat-card__label">Active</div><div class="stat-card__value"><?= (int) $stats['active'] ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">able to sign in</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile purple"><span class="ic" data-ic="shieldcheck"></span></span>
      <div class="grow"><div class="stat-card__label">Admins</div><div class="stat-card__value"><?= (int) $stats['admins'] ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">full platform access</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile cyan"><span class="ic" data-ic="user"></span></span>
      <div class="grow"><div class="stat-card__label">Staff</div><div class="stat-card__value"><?= (int) $stats['staff'] ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">support &amp; operations</span></div>
  </div>
</div>

<div class="grid g-main-side" style="margin-top:16px">
  <div class="card">
    <div class="card__head iqp-toolbar">
      <span class="card__title">Team members</span>
      <span class="spacer"></span>
      <form method="get" class="row" style="gap:8px;align-items:center;margin:0">
        <div class="input-icon" style="max-width:240px"><span class="ic" data-ic="search"></span>
          <input class="input" type="search" name="q" value="<?= sanitize($q) ?>" placeholder="Search team..."></div>
        <select class="select" name="role" style="max-width:150px" onchange="this.form.submit()">
          <option value="">All Roles</option>
          <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admins</option>
          <?php if ($staffSupported): ?><option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option><?php endif; ?>
        </select>
        <button class="btn btn--ghost btn--sm" type="submit">Filter</button>
      </form>
    </div>
    <div class="card__body" style="padding:0">
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if ($team === []): ?>
          <tr><td colspan="5" class="muted"><?= $filtered ? 'No team members match your filters.' : 'No internal team members yet — invite your first member.' ?></td></tr>
        <?php else: foreach ($team as $m):
            $rid      = (int) $m['id'];
            $rrole    = (string) $m['role'];
            $isAdmin  = $rrole === 'admin';
            $initials = trim((string) ($m['avatar_initials'] ?? '')) !== ''
                ? (string) $m['avatar_initials']
                : iqp_initials((string) ($m['name'] ?? ''));
            $roleBadge = $isAdmin ? 'badge--purple' : 'badge--blue';
            $locked    = !empty($m['locked_until']) && strtotime((string) $m['locked_until']) > time();
            $isSelf    = $rid === (int) ($user['id'] ?? 0);
        ?>
          <tr>
            <td>
              <div class="cell-media">
                <span class="avatar <?= $isAdmin ? 'purple' : '' ?>"><?= sanitize($initials) ?></span>
                <div>
                  <div class="strong"><?= sanitize((string) ($m['name'] ?? '')) ?><?= $isSelf ? ' <span class="badge badge--gray">You</span>' : '' ?></div>
                  <div class="sub"><?= sanitize((string) ($m['email'] ?? '')) ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge <?= $roleBadge ?>"><?= sanitize(ucfirst($rrole)) ?></span></td>
            <td><span class="badge <?= $locked ? 'badge--red' : 'badge--green' ?>"><span class="dot"></span><?= $locked ? 'Locked' : 'Active' ?></span></td>
            <td class="muted nowrap"><?= sanitize(iqp_rel_time((string) ($m['created_at'] ?? ''))) ?: '&mdash;' ?></td>
            <td>
              <?php if ($isSelf): ?>
                <span class="muted small">Current admin</span>
              <?php else: ?>
                <form method="post" class="row" style="gap:6px;align-items:center;margin:0">
                  <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
                  <input type="hidden" name="action" value="set_role">
                  <input type="hidden" name="member_id" value="<?= $rid ?>">
                  <select class="select" name="role" style="height:34px;padding:4px 8px;max-width:120px">
                    <option value="admin" <?= $isAdmin ? 'selected' : '' ?>>Admin</option>
                    <?php if ($staffSupported): ?><option value="staff" <?= $rrole === 'staff' ? 'selected' : '' ?>>Staff</option><?php endif; ?>
                  </select>
                  <button class="btn btn--ghost btn--sm" type="submit">Save</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="card" id="invite-form">
    <div class="card__head"><span class="card__title">Invite team member</span></div>
    <div class="card__body">
      <form method="post" class="stack" style="gap:14px">
        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
        <input type="hidden" name="action" value="invite">
        <div class="field" style="margin:0">
          <label>Full name</label>
          <input class="input" name="name" required value="<?= sanitize($formName) ?>" placeholder="Jane Doe">
        </div>
        <div class="field" style="margin:0">
          <label>Email</label>
          <input class="input" type="email" name="email" required value="<?= sanitize($formMail) ?>" placeholder="jane@iqpigeon.com">
        </div>
        <div class="field" style="margin:0">
          <label>Role</label>
          <select class="select" name="role">
            <?php if ($staffSupported): ?>
              <option value="staff" <?= $formRole === 'staff' ? 'selected' : '' ?>>Staff &mdash; support &amp; operations</option>
            <?php endif; ?>
            <option value="admin" <?= $formRole === 'admin' ? 'selected' : '' ?>>Admin &mdash; full access</option>
          </select>
        </div>
        <button class="btn btn--primary btn--block" type="submit"><span class="ic" data-ic="plus"></span> Send invite</button>
        <p class="muted small" style="margin:0">
          A secure random password is set on the new account; the member signs in via the password&#8209;reset flow.
          <?php if (!$staffSupported): ?><br><strong>Note:</strong> the staff role is unavailable on this database, so only admins can be added.<?php endif; ?>
        </p>
      </form>
    </div>
  </div>
</div>
<?php iqp_admin_end();
