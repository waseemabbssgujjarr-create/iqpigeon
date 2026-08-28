<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/data-deletion-requests.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();
ensure_data_deletion_schema();

$message = '';
$error   = '';
$filter  = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, ['pending', 'processing', 'completed', 'rejected'], true)) {
    $filter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action    = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($action === 'update_status' && $requestId > 0) {
        $status = $_POST['status'] ?? 'pending';
        $notes  = trim($_POST['admin_notes'] ?? '');
        if (data_deletion_update_status($requestId, $status, $notes)) {
            $message = 'Request #' . $requestId . ' updated.';
        } else {
            $error = 'Could not update request.';
        }
    }
}

$requests     = data_deletion_requests_list($filter !== '' ? $filter : null);
$pendingCount = data_deletion_pending_count();

$detailId = (int) ($_GET['id'] ?? 0);
$detail   = null;
foreach ($requests as $row) {
    if ((int) $row['id'] === $detailId) {
        $detail = $row;
        break;
    }
}
if ($detail === null && $detailId > 0) {
    try {
        $detail = db_fetch(
            'SELECT r.*, u.company_name, u.name AS user_name
             FROM data_deletion_requests r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.id = ?',
            'i',
            [$detailId]
        );
    } catch (Throwable $e) {
        $detail = null;
    }
}

// Counts per status for the filter tabs + stat grid (independent of the current filter).
$counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'rejected' => 0];
try {
    $rows = db_fetch_all('SELECT status, COUNT(*) AS cnt FROM data_deletion_requests GROUP BY status', '', []);
    foreach ($rows as $r) {
        $st = (string) ($r['status'] ?? '');
        if (isset($counts[$st])) {
            $counts[$st] = (int) ($r['cnt'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // leave zeros
}
$totalCount = array_sum($counts);

$statusBadge = [
    'pending'    => 'badge--amber',
    'processing' => 'badge--blue',
    'completed'  => 'badge--green',
    'rejected'   => 'badge--red',
];
$rowHref = static function (int $id) use ($filter): string {
    return '/admin/data-deletion-requests?id=' . $id . ($filter !== '' ? '&status=' . urlencode($filter) : '');
};

$ddSubtitle = 'Meta / GDPR compliance queue';
if ($pendingCount > 0) {
    $ddSubtitle .= ' · ' . (int) $pendingCount . ' open';
}

iqp_admin_begin($user, 'settings', [
    'title'    => 'Data deletion requests',
    'subtitle' => $ddSubtitle,
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/settings"><span class="ic" data-ic="settings"></span> System settings</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="clock"></span></span><div class="grow"><div class="stat-card__label">Pending</div><div class="stat-card__value"><?= (int) $counts['pending'] ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="refresh"></span></span><div class="grow"><div class="stat-card__label">Processing</div><div class="stat-card__value"><?= (int) $counts['processing'] ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span><div class="grow"><div class="stat-card__label">Completed</div><div class="stat-card__value"><?= (int) $counts['completed'] ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile red"><span class="ic" data-ic="x"></span></span><div class="grow"><div class="stat-card__label">Rejected</div><div class="stat-card__value"><?= (int) $counts['rejected'] ?></div></div></div></div>
</div>

<?php if ($detail): ?>
<?php
    $dSt   = (string) ($detail['status'] ?? 'pending');
    $dCls  = $statusBadge[$dSt] ?? 'badge--gray';
    $dType = ($detail['request_type'] ?? '') === 'customer' ? 'End customer' : 'Platform account';
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Request #<?= (int) $detail['id'] ?></span><span class="spacer"></span>
    <span class="badge <?= $dCls ?>"><span class="dot"></span><?= sanitize(ucfirst($dSt)) ?></span>
  </div>
  <div class="card__body">
    <div class="grid grid-2" style="gap:14px 24px;margin-bottom:8px">
      <div><div class="eyebrow">Type</div><div><?= sanitize($dType) ?></div></div>
      <div><div class="eyebrow">Submitted</div><div class="muted"><?= sanitize((string) ($detail['created_at'] ?? '—')) ?></div></div>
      <div><div class="eyebrow">Name</div><div><?= sanitize((string) ($detail['name'] ?? '—')) ?></div></div>
      <div><div class="eyebrow">Contact email</div><div><a href="mailto:<?= sanitize((string) ($detail['email'] ?? '')) ?>"><?= sanitize((string) ($detail['email'] ?? '—')) ?></a></div></div>
      <div><div class="eyebrow">Account email</div><div class="muted"><?= sanitize((string) ($detail['account_email'] ?? '') ?: '—') ?></div></div>
      <?php if (!empty($detail['user_id'])): ?>
      <div><div class="eyebrow">Linked user</div><div><a href="/admin/client-detail?id=<?= (int) $detail['user_id'] ?>">#<?= (int) $detail['user_id'] ?> <?= sanitize((string) ($detail['company_name'] ?? $detail['user_name'] ?? '')) ?></a></div></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($detail['reason'])): ?>
    <div class="divider"></div>
    <div class="eyebrow">Details</div>
    <p class="muted" style="white-space:pre-wrap;margin:6px 0 0"><?= sanitize((string) $detail['reason']) ?></p>
    <?php endif; ?>

    <div class="divider"></div>
    <form method="post" class="stack" style="gap:14px">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="request_id" value="<?= (int) $detail['id'] ?>">
      <div class="grid grid-2" style="gap:16px">
        <div class="field" style="margin:0"><label>Status</label>
          <select class="select" name="status">
            <?php foreach (['pending', 'processing', 'completed', 'rejected'] as $st): ?>
            <option value="<?= sanitize($st) ?>"<?= $dSt === $st ? ' selected' : '' ?>><?= sanitize(ucfirst($st)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field" style="margin:0"><label>Admin notes <span class="hint">internal only</span></label>
        <textarea class="textarea" name="admin_notes" rows="3"><?= sanitize((string) ($detail['admin_notes'] ?? '')) ?></textarea>
      </div>
      <div class="row" style="gap:10px">
        <button type="submit" class="btn btn--primary"><span class="ic" data-ic="check"></span> Save</button>
        <a class="btn btn--ghost" href="/admin/data-deletion-requests<?= $filter !== '' ? '?status=' . urlencode($filter) : '' ?>">Close</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div style="padding:0 12px"><div class="tabs">
    <?php
    $tabDefs = [
        ''           => ['All', $totalCount],
        'pending'    => ['Pending', $counts['pending']],
        'processing' => ['Processing', $counts['processing']],
        'completed'  => ['Completed', $counts['completed']],
        'rejected'   => ['Rejected', $counts['rejected']],
    ];
    foreach ($tabDefs as $key => [$label, $cnt]):
        $href = '/admin/data-deletion-requests' . ($key !== '' ? '?status=' . urlencode($key) : '');
    ?>
    <a class="tab<?= $filter === $key ? ' is-active' : '' ?>" href="<?= sanitize($href) ?>"><?= sanitize($label) ?> <span class="cnt"><?= (int) $cnt ?></span></a>
    <?php endforeach; ?>
  </div></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Request</th>
        <th>Contact</th>
        <th>Status</th>
        <th class="nowrap">Submitted</th>
        <th class="right">Actions</th>
      </tr></thead>
      <tbody>
      <?php if ($requests === []): ?>
        <tr><td colspan="5" class="muted">No requests<?= $filter !== '' ? ' with this status' : '' ?> yet.</td></tr>
      <?php else: foreach ($requests as $row):
          $id   = (int) ($row['id'] ?? 0);
          $st   = (string) ($row['status'] ?? 'pending');
          $cls  = $statusBadge[$st] ?? 'badge--gray';
          $type = ($row['request_type'] ?? '') === 'customer' ? 'End customer' : 'Account';
          $when = iqp_rel_time((string) ($row['created_at'] ?? ''));
      ?>
        <tr<?= $detailId === $id ? ' class="is-selected"' : '' ?>>
          <td class="strong"><a href="<?= sanitize($rowHref($id)) ?>">#<?= $id ?> · <?= sanitize((string) ($row['name'] ?? '')) ?></a></td>
          <td class="muted"><?= sanitize((string) ($row['email'] ?? '')) ?> · <?= sanitize($type) ?></td>
          <td><span class="badge <?= $cls ?>"><span class="dot"></span><?= sanitize(ucfirst($st)) ?></span></td>
          <td class="muted nowrap"><?= $when !== '' ? sanitize($when) : '&mdash;' ?></td>
          <td class="row-actions"><a class="ic" data-ic="eye" href="<?= sanitize($rowHref($id)) ?>" title="Review request"></a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php iqp_admin_end();
