<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/platform-renewals.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();
ensure_platform_renewals_schema();
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['template_id'] ?? 0) ?: null;
            platform_renewal_template_save($_POST, $id);
            $message = 'Reminder template saved.';
        } elseif ($action === 'delete') {
            platform_renewal_template_delete((int) ($_POST['template_id'] ?? 0));
            $message = 'Template removed.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$templates = [];
try {
    $templates = platform_renewal_templates_list();
} catch (Throwable $e) {
    $templates = [];
    if ($error === '') {
        $error = 'Could not load templates: ' . $e->getMessage();
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = null;
foreach ($templates as $t) {
    if ((int) $t['id'] === $editId) {
        $edit = $t;
        break;
    }
}

// Stats derived from the loaded list — no extra queries.
$totalTpl  = count($templates);
$activeTpl = 0;
foreach ($templates as $t) {
    if (!empty($t['is_active'])) {
        $activeTpl++;
    }
}
$inactiveTpl = $totalTpl - $activeTpl;

/** Human label for an offset (negative = before renewal, 0 = due day, positive = after). */
$offsetLabel = static function (int $offset): string {
    if ($offset === 0) {
        return 'On renewal day';
    }
    $n    = abs($offset);
    $unit = $n === 1 ? 'day' : 'days';
    return $offset < 0 ? "$n $unit before" : "$n $unit after";
};

iqp_admin_begin($user, 'billing', [
    'title'    => 'Renewal reminders',
    'subtitle' => 'Automated emails sent before and after a client trial or subscription renews',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/subscriptions"><span class="ic" data-ic="card"></span> Subscriptions</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-3" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="mail"></span></span><div class="grow"><div class="stat-card__label">Templates</div><div class="stat-card__value"><?= (int) $totalTpl ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span><div class="grow"><div class="stat-card__label">Active</div><div class="stat-card__value"><?= (int) $activeTpl ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="clock"></span></span><div class="grow"><div class="stat-card__label">Paused</div><div class="stat-card__value"><?= (int) $inactiveTpl ?></div></div></div></div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 400px;align-items:start;gap:16px">
  <!-- Left: templates list -->
  <div class="card">
    <div class="card__head"><span class="card__title">Reminder schedule</span></div>
    <div class="card__body" style="padding:0">
      <div class="table-wrap"><table class="tbl">
        <thead><tr>
          <th>Template</th>
          <th class="nowrap">Timing</th>
          <th>Status</th>
          <th class="right">Actions</th>
        </tr></thead>
        <tbody>
        <?php if ($templates === []): ?>
          <tr><td colspan="4" class="muted">No reminder templates yet. Create one on the right.</td></tr>
        <?php else: foreach ($templates as $tpl):
            $id     = (int) $tpl['id'];
            $offset = (int) ($tpl['offset_days'] ?? 0);
            $active = !empty($tpl['is_active']);
        ?>
          <tr<?= $editId === $id ? ' class="is-selected"' : '' ?>>
            <td>
              <div class="strong"><?= sanitize((string) ($tpl['name'] ?? '')) ?></div>
              <div class="muted small"><?= sanitize((string) ($tpl['email_subject'] ?? '')) ?></div>
            </td>
            <td class="nowrap"><span class="badge <?= $offset < 0 ? 'badge--blue' : ($offset === 0 ? 'badge--amber' : 'badge--red') ?>"><?= sanitize($offsetLabel($offset)) ?></span></td>
            <td><span class="badge <?= $active ? 'badge--green' : 'badge--gray' ?>"><span class="dot"></span><?= $active ? 'Active' : 'Paused' ?></span></td>
            <td class="row-actions">
              <a class="ic" data-ic="edit" href="?edit=<?= $id ?>" title="Edit template"></a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this reminder template?');">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="template_id" value="<?= $id ?>">
                <button type="submit" class="ic danger" data-ic="trash" title="Delete template"></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- Right: editor -->
  <div class="card" style="align-self:start">
    <div class="card__head"><span class="card__title"><?= $edit ? 'Edit template' : 'New template' ?></span>
      <?php if ($edit): ?><span class="spacer"></span><a class="view-all" href="/admin/renewal-reminders">+ New</a><?php endif; ?>
    </div>
    <form method="post" class="card__body">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="template_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

      <div class="field"><label>Label</label>
        <input class="input" name="name" required maxlength="128" placeholder="e.g. Trial ending in 3 days" value="<?= sanitize((string) ($edit['name'] ?? '')) ?>">
      </div>
      <div class="field"><label>Days from renewal <span class="hint">negative = before, 0 = due day, positive = after</span></label>
        <input class="input" name="offset_days" type="number" required value="<?= (int) ($edit['offset_days'] ?? 0) ?>">
      </div>
      <div class="field"><label>Email subject</label>
        <input class="input" name="email_subject" required maxlength="255" placeholder="Your trial ends soon" value="<?= sanitize((string) ($edit['email_subject'] ?? '')) ?>">
      </div>
      <div class="field"><label>Email body</label>
        <textarea class="textarea" name="email_body" rows="8" required placeholder="Hi {name}, ..."><?= sanitize((string) ($edit['email_body'] ?? '')) ?></textarea>
        <div class="hint" style="margin-top:6px">Placeholders: <code>{name}</code> <code>{company}</code> <code>{plan}</code> <code>{expires_at}</code> <code>{days_left}</code> <code>{billing_url}</code> <code>{app}</code></div>
      </div>
      <div class="field">
        <label class="checkbox"><input type="checkbox" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>> <span>Active — include in the daily send</span></label>
      </div>
      <button type="submit" class="btn btn--primary btn--block"><span class="ic" data-ic="check"></span> Save template</button>
    </form>
  </div>
</div>
<?php iqp_admin_end();
