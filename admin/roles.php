<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/platform-settings.php';

$user = require_admin();

/**
 * Fixed platform permission list (key => human label).
 *
 * @return array<string,string>
 */
function iqp_roles_permission_list(): array
{
    return [
        'view_businesses'      => 'View Businesses',
        'manage_businesses'    => 'Manage Businesses',
        'view_billing'         => 'View Billing',
        'manage_billing'       => 'Manage Billing',
        'manage_users'         => 'Manage Users',
        'manage_settings'      => 'Manage Settings',
        'view_analytics'       => 'View Analytics',
        'manage_announcements' => 'Manage Announcements',
        'view_audit'           => 'View Audit Log',
    ];
}

/**
 * Seed sensible default roles + permission maps.
 *
 * @param string[] $permKeys
 * @return array<string,array<string,mixed>>
 */
function iqp_roles_defaults(array $permKeys): array
{
    $mk = static function (array $on) use ($permKeys): array {
        $m = [];
        foreach ($permKeys as $k) {
            $m[$k] = in_array($k, $on, true);
        }
        return $m;
    };

    return [
        'super_admin' => [
            'name'    => 'Super Admin',
            'system'  => true,
            'locked'  => true,           // always all-permissions, cannot be edited/removed
            'maps_to' => '',
            'perms'   => $mk($permKeys), // all true
        ],
        'admin' => [
            'name'    => 'Admin',
            'system'  => true,
            'locked'  => false,
            'maps_to' => 'admin',
            'perms'   => $mk(['view_businesses', 'manage_businesses', 'view_billing', 'manage_billing', 'manage_users', 'view_analytics', 'manage_announcements', 'view_audit']),
        ],
        'support' => [
            'name'    => 'Support',
            'system'  => true,
            'locked'  => false,
            'maps_to' => 'staff',
            'perms'   => $mk(['view_businesses', 'view_billing', 'view_analytics', 'manage_announcements']),
        ],
        'billing' => [
            'name'    => 'Billing',
            'system'  => true,
            'locked'  => false,
            'maps_to' => '',
            'perms'   => $mk(['view_businesses', 'view_billing', 'manage_billing', 'view_analytics']),
        ],
        'readonly' => [
            'name'    => 'Read-only',
            'system'  => true,
            'locked'  => false,
            'maps_to' => '',
            'perms'   => $mk(['view_businesses', 'view_billing', 'view_analytics', 'view_audit']),
        ],
    ];
}

/**
 * Load + normalize roles from the platform_roles setting (JSON). Seeds defaults when empty/invalid.
 *
 * @param string[] $permKeys
 * @return array<string,array<string,mixed>>
 */
function iqp_roles_load(array $permKeys): array
{
    $raw = get_setting('platform_roles', '');
    $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($decoded) || $decoded === []) {
        return iqp_roles_defaults($permKeys);
    }

    $roles = [];
    foreach ($decoded as $key => $role) {
        if (!is_string($key) || $key === '' || !is_array($role)) {
            continue;
        }
        $storedPerms = (isset($role['perms']) && is_array($role['perms'])) ? $role['perms'] : [];
        $perms = [];
        foreach ($permKeys as $pk) {
            $perms[$pk] = !empty($storedPerms[$pk]);
        }
        $mapsTo = isset($role['maps_to']) && in_array($role['maps_to'], ['admin', 'staff'], true) ? (string) $role['maps_to'] : '';
        $roles[$key] = [
            'name'    => isset($role['name']) && $role['name'] !== '' ? (string) $role['name'] : ucwords(str_replace('_', ' ', $key)),
            'system'  => !empty($role['system']),
            'locked'  => !empty($role['locked']),
            'maps_to' => $mapsTo,
            'perms'   => $perms,
        ];
    }

    return $roles !== [] ? $roles : iqp_roles_defaults($permKeys);
}

/**
 * Persist roles as JSON under settings key `platform_roles`.
 *
 * @param array<string,array<string,mixed>> $roles
 */
function iqp_roles_save(array $roles): void
{
    set_setting('platform_roles', (string) json_encode($roles, JSON_UNESCAPED_UNICODE));
}

/** Slugify a role name into a storage key. */
function iqp_roles_slug(string $name): string
{
    $s = strtolower(trim($name));
    $s = (string) preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

$permList = iqp_roles_permission_list();
$permKeys = array_keys($permList);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'save';
    try {
        $roles = iqp_roles_load($permKeys);

        if ($action === 'add_role') {
            $name = trim((string) ($_POST['role_name'] ?? ''));
            if ($name === '') {
                $error = 'Enter a role name.';
            } else {
                $key = iqp_roles_slug($name);
                if ($key === '') {
                    $error = 'Role name must contain letters or numbers.';
                } elseif (isset($roles[$key])) {
                    $error = 'A role with that name already exists.';
                } else {
                    $perms = [];
                    foreach ($permKeys as $pk) {
                        $perms[$pk] = false;
                    }
                    $roles[$key] = [
                        'name'    => $name,
                        'system'  => false,
                        'locked'  => false,
                        'maps_to' => '',
                        'perms'   => $perms,
                    ];
                    iqp_roles_save($roles);
                    $message = 'Role "' . $name . '" created.';
                }
            }
        } elseif ($action === 'delete_role') {
            $key = (string) ($_POST['role_key'] ?? '');
            if ($key !== '' && isset($roles[$key]) && empty($roles[$key]['system'])) {
                $deleted = (string) $roles[$key]['name'];
                unset($roles[$key]);
                iqp_roles_save($roles);
                $message = 'Role "' . $deleted . '" deleted.';
            } else {
                $error = 'That role cannot be deleted.';
            }
        } else {
            // Save the permission matrix. Unchecked switches are absent from POST.
            $submitted = (isset($_POST['perm']) && is_array($_POST['perm'])) ? $_POST['perm'] : [];
            foreach ($roles as $rk => $role) {
                if (!empty($role['locked'])) {
                    foreach ($permKeys as $pk) {
                        $roles[$rk]['perms'][$pk] = true; // locked roles keep every permission
                    }
                    continue;
                }
                $row = (isset($submitted[$rk]) && is_array($submitted[$rk])) ? $submitted[$rk] : [];
                foreach ($permKeys as $pk) {
                    $roles[$rk]['perms'][$pk] = !empty($row[$pk]);
                }
            }
            iqp_roles_save($roles);
            $message = 'Permissions updated.';
        }
    } catch (Throwable $e) {
        error_log('admin/roles.php save failed: ' . $e->getMessage());
        $error = 'Could not save changes. Please try again.';
    }
}

// Reload for rendering so the view reflects the persisted state (and seeds defaults on first load).
$roles = iqp_roles_load($permKeys);

// Member counts: users.role only stores 'admin'/'staff' for platform team members.
$roleCounts = ['admin' => 0, 'staff' => 0];
try {
    $rows = db_fetch_all("SELECT role, COUNT(*) AS cnt FROM users WHERE role IN ('admin','staff') GROUP BY role", '', []);
    foreach ($rows as $r) {
        $rk = (string) ($r['role'] ?? '');
        if (isset($roleCounts[$rk])) {
            $roleCounts[$rk] = (int) ($r['cnt'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $roleCounts = ['admin' => 0, 'staff' => 0];
}

$memberLabel = static function (array $role) use ($roleCounts): string {
    $mt = (string) ($role['maps_to'] ?? '');
    if ($mt === 'admin' || $mt === 'staff') {
        $n = $roleCounts[$mt] ?? 0;
        return $n . ' member' . ($n === 1 ? '' : 's');
    }
    return '—';
};

$csrf = csrf_token();

iqp_admin_begin($user, 'roles', [
    'title'    => 'Roles & Permissions',
    'subtitle' => 'Define roles and control access to platform features',
    'actions'  => '<a class="btn btn--primary btn--sm" href="#add-role-form"><span class="ic" data-ic="plus"></span> Create Role</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="grid iqp-roles-layout" style="grid-template-columns:280px minmax(0,1fr);align-items:start;gap:18px">
  <div class="stack">
    <div class="card">
      <div class="card__head"><span class="card__title">Roles</span><span class="badge badge--gray"><?= count($roles) ?></span></div>
      <div class="card__body" style="padding:8px">
        <?php if ($roles === []): ?>
          <div class="muted small" style="padding:12px">No roles defined yet.</div>
        <?php else: foreach ($roles as $rk => $role):
            $isSystem = !empty($role['system']);
            $hi = !empty($role['locked']);
        ?>
        <div class="list-row" style="padding:11px;border:1px solid <?= $hi ? 'var(--green-200)' : 'var(--line-2)' ?>;border-radius:10px;margin-bottom:6px;<?= $hi ? 'background:var(--green-50)' : '' ?>">
          <span class="mini-tile" style="width:34px;height:34px;background:var(--field);color:var(--muted)"><span class="ic" data-ic="shieldcheck"></span></span>
          <div class="list-row__main">
            <div class="list-row__title" style="font-size:13.5px"><?= sanitize((string) $role['name']) ?></div>
            <div class="list-row__sub"><?= sanitize($memberLabel($role)) ?></div>
          </div>
          <?php if ($isSystem): ?>
            <span class="badge badge--purple">System</span>
          <?php else: ?>
            <form method="POST" style="margin:0" onsubmit="return confirm('Delete this role?')">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="delete_role"/>
              <input type="hidden" name="role_key" value="<?= sanitize((string) $rk) ?>"/>
              <button class="btn btn--ghost btn--sm" type="submit" title="Delete role"><span class="ic" data-ic="trash"></span></button>
            </form>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="card" id="add-role-form">
      <div class="card__head"><span class="card__title">Create role</span></div>
      <div class="card__body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="add_role"/>
          <div class="field">
            <label>Role name</label>
            <input class="input" name="role_name" maxlength="40" placeholder="e.g. Content Moderator" required/>
          </div>
          <button class="btn btn--primary btn--block" type="submit"><span class="ic" data-ic="plus"></span> Add role</button>
        </form>
      </div>
    </div>
  </div>

  <form method="POST" class="card iqp-perm-matrix-form">
    <div class="card__head iqp-perm-matrix-form__head">
      <span class="card__title">Permission Matrix</span>
      <span class="spacer"></span>
      <button class="btn btn--primary btn--sm iqp-perm-matrix-form__save-top" type="submit">Save changes</button>
    </div>
    <div class="card__body" style="padding:0">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="save"/>
      <div class="table-wrap tbl-perm-matrix"><table class="tbl">
        <thead>
          <tr>
            <th>Permission</th>
            <?php foreach ($roles as $role): ?>
              <th class="right nowrap"><?= sanitize((string) $role['name']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($permList as $pk => $label): ?>
          <tr>
            <td class="strong"><?= sanitize($label) ?></td>
            <?php foreach ($roles as $rk => $role):
                $on = !empty($role['perms'][$pk]);
                $locked = !empty($role['locked']);
            ?>
            <td class="right">
              <label class="switch" title="<?= sanitize((string) $role['name'] . ' · ' . $label) ?>">
                <input type="checkbox" name="perm[<?= sanitize((string) $rk) ?>][<?= sanitize((string) $pk) ?>]" value="1"<?= $on ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>/>
                <span class="track"></span>
              </label>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="between iqp-perm-matrix-form__foot" style="padding:14px 16px;border-top:1px solid var(--line-2)">
        <span class="muted small">Changes apply to every role at once. Super Admin always keeps full access.</span>
        <button class="btn btn--primary btn--sm iqp-perm-matrix-form__save-bottom" type="submit">Save changes</button>
      </div>
    </div>
  </form>
</div>
<?php
iqp_admin_end();
