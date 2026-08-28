<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();
iqp_ensure_ops_schema();

$message = '';
$error   = '';

// Whitelists (used for validation, badges and select options)
$PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
$STATUSES   = ['open' => 'Open', 'pending' => 'Pending', 'closed' => 'Closed'];

$priBadge = ['low' => 'badge--gray', 'medium' => 'badge--amber', 'high' => 'badge--red'];
$stBadge  = ['open' => 'badge--blue', 'pending' => 'badge--amber', 'closed' => 'badge--green'];

// ---- POST: create ticket / change status (CSRF verified) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $subject  = trim((string) ($_POST['subject'] ?? ''));
            $business = trim((string) ($_POST['business_name'] ?? ''));
            $body     = trim((string) ($_POST['body'] ?? ''));
            $priority = (string) ($_POST['priority'] ?? 'medium');
            if (!isset($PRIORITIES[$priority])) {
                $priority = 'medium';
            }
            if ($subject === '') {
                $error = 'Subject is required.';
            } else {
                db_insert(
                    'INSERT INTO iqp_tickets (user_id, business_name, subject, body, priority, status)
                     VALUES (NULL, ?, ?, ?, ?, ?)',
                    'sssss',
                    [
                        $business !== '' ? $business : null,
                        $subject,
                        $body !== '' ? $body : null,
                        $priority,
                        'open',
                    ]
                );
                $message = 'Ticket created.';
            }
        } elseif ($action === 'set_status') {
            $id     = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            if ($id > 0 && isset($STATUSES[$status])) {
                db_execute('UPDATE iqp_tickets SET status = ? WHERE id = ?', 'si', [$status, $id]);
                $message = 'Ticket status updated.';
            } else {
                $error = 'Invalid status change.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Something went wrong. ' . $e->getMessage();
    }
}

// ---- Tab (server-synced with $_GET['tab'] for deep-links) ----
$validTabs = ['all', 'open', 'pending', 'closed'];
$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'all')) ?: 'all';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

// ---- Stats (SQL aggregate, accurate totals independent of the list limit) ----
$total = $openCnt = $pendingCnt = $closedCnt = 0;
try {
    $agg = db_fetch(
        "SELECT COUNT(*) AS total,
                SUM(status = 'open')    AS open_cnt,
                SUM(status = 'pending') AS pending_cnt,
                SUM(status = 'closed')  AS closed_cnt
         FROM iqp_tickets"
    );
    $total      = (int) ($agg['total'] ?? 0);
    $openCnt    = (int) ($agg['open_cnt'] ?? 0);
    $pendingCnt = (int) ($agg['pending_cnt'] ?? 0);
    $closedCnt  = (int) ($agg['closed_cnt'] ?? 0);
} catch (Throwable $e) {
    // leave zeros
}

// ---- List (fetch once, partition per-status so client-side tab switching stays correct) ----
$tickets = [];
try {
    $tickets = db_fetch_all(
        'SELECT id, user_id, business_name, subject, body, priority, status, created_at
         FROM iqp_tickets
         ORDER BY created_at DESC, id DESC
         LIMIT 500'
    );
} catch (Throwable $e) {
    $tickets = [];
}
$groups = [
    'all'     => $tickets,
    'open'    => array_values(array_filter($tickets, static fn ($t) => ($t['status'] ?? '') === 'open')),
    'pending' => array_values(array_filter($tickets, static fn ($t) => ($t['status'] ?? '') === 'pending')),
    'closed'  => array_values(array_filter($tickets, static fn ($t) => ($t['status'] ?? '') === 'closed')),
];

$tabLabels = ['all' => 'All Tickets', 'open' => 'Open', 'pending' => 'Pending', 'closed' => 'Closed'];
$tabCounts = ['all' => $total, 'open' => $openCnt, 'pending' => $pendingCnt, 'closed' => $closedCnt];
$emptyMsgs = [
    'all'     => 'No tickets yet.',
    'open'    => 'No open tickets.',
    'pending' => 'No pending tickets.',
    'closed'  => 'No closed tickets.',
];

// ---- New-ticket form state (repopulate on validation error, reset after success) ----
$fSubject  = '';
$fBusiness = '';
$fBody     = '';
$fPriority = 'medium';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $fSubject  = (string) ($_POST['subject'] ?? '');
    $fBusiness = (string) ($_POST['business_name'] ?? '');
    $fBody     = (string) ($_POST['body'] ?? '');
    $fPriority = (string) ($_POST['priority'] ?? 'medium');
    if (!isset($PRIORITIES[$fPriority])) {
        $fPriority = 'medium';
    }
}
if ($message !== '') {
    $fSubject  = '';
    $fBusiness = '';
    $fBody     = '';
    $fPriority = 'medium';
}

// ---- Table renderer (shared across every status tab) ----
$renderTable = static function (array $rows, string $emptyMsg) use ($priBadge, $stBadge, $PRIORITIES, $STATUSES) {
    ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Subject</th><th>Business</th><th>Priority</th><th>Status</th>
        <th class="nowrap">Created</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="6" class="muted"><?= sanitize($emptyMsg) ?></td></tr>
      <?php else: foreach ($rows as $r):
          $id    = (int) ($r['id'] ?? 0);
          $pr    = (string) ($r['priority'] ?? 'medium');
          $st    = (string) ($r['status'] ?? 'open');
          $prCls = $priBadge[$pr] ?? 'badge--gray';
          $stCls = $stBadge[$st] ?? 'badge--gray';
          $biz   = trim((string) ($r['business_name'] ?? ''));
          $rel   = iqp_rel_time((string) ($r['created_at'] ?? ''));
      ?>
        <tr>
          <td class="strong">
            <?= sanitize((string) ($r['subject'] ?? '')) ?>
            <div class="muted small">#TK-<?= $id ?></div>
          </td>
          <td class="muted"><?= $biz !== '' ? sanitize($biz) : '&mdash;' ?></td>
          <td><span class="badge <?= $prCls ?>"><?= sanitize($PRIORITIES[$pr] ?? ucfirst($pr)) ?></span></td>
          <td><span class="badge <?= $stCls ?>"><span class="dot"></span><?= sanitize($STATUSES[$st] ?? ucfirst($st)) ?></span></td>
          <td class="muted nowrap"><?= $rel !== '' ? sanitize($rel) : '&mdash;' ?></td>
          <td class="row-actions">
            <form method="post" style="display:flex;align-items:center;gap:6px;margin:0">
              <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= $id ?>">
              <select name="status" class="select" style="min-width:120px;height:34px;padding:4px 30px 4px 10px">
                <?php foreach ($STATUSES as $sv => $sl): ?>
                <option value="<?= sanitize($sv) ?>"<?= $sv === $st ? ' selected' : '' ?>><?= sanitize($sl) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn--soft btn--sm" type="submit">Update</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <?php
};

iqp_admin_begin($user, 'tickets', [
    'title'    => 'Support Tickets',
    'subtitle' => 'Manage and resolve support requests from businesses',
    'actions'  => '<a class="btn btn--primary btn--sm" href="#new-ticket"><span class="ic" data-ic="plus"></span> New Ticket</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile red"><span class="ic" data-ic="ticket"></span></span><div class="grow"><div class="stat-card__label">Open</div><div class="stat-card__value"><?= (int) $openCnt ?></div></div></div><div class="stat-card__foot"><span class="muted">need attention</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="clock"></span></span><div class="grow"><div class="stat-card__label">Pending</div><div class="stat-card__value"><?= (int) $pendingCnt ?></div></div></div><div class="stat-card__foot"><span class="muted">being handled</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span><div class="grow"><div class="stat-card__label">Closed</div><div class="stat-card__value"><?= (int) $closedCnt ?></div></div></div><div class="stat-card__foot"><span class="muted">resolved</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="list"></span></span><div class="grow"><div class="stat-card__label">Total</div><div class="stat-card__value"><?= (int) $total ?></div></div></div><div class="stat-card__foot"><span class="muted">all time</span></div></div>
</div>

<div class="card">
  <div style="padding:12px 16px 8px"><nav class="iqp-tab-nav iqp-tab-nav--admin iqp-tab-nav--pills" aria-label="Ticket filters"><div class="pill-tabs iqp-tab-nav__grid" data-tabset="tk">
    <?php foreach ($tabLabels as $id => $label): ?>
    <button type="button" class="pill-tab<?= $tab === $id ? ' is-active' : '' ?>" data-tab="<?= sanitize($id) ?>"><?= sanitize($label) ?><span class="cnt"><?= (int)($tabCounts[$id] ?? 0) ?></span></button>
    <?php endforeach; ?>
  </div></nav></div>
  <div class="card__body" style="padding:0" data-panelset="tk">
    <?php foreach ($tabLabels as $id => $label): ?>
    <div class="tab-panel<?= $tab === $id ? ' is-active' : '' ?>" data-panel="<?= sanitize($id) ?>">
      <?php $renderTable($groups[$id] ?? [], $emptyMsgs[$id] ?? 'No tickets here yet.'); ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" id="new-ticket" style="margin-top:16px">
  <div class="card__head"><span class="card__title"><span class="ic" data-ic="ticket"></span> New ticket</span></div>
  <form method="post" class="card__body">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid grid-2">
      <div class="field"><label>Subject</label>
        <input class="input" name="subject" required maxlength="255" placeholder="e.g. WhatsApp not connecting" value="<?= sanitize($fSubject) ?>">
      </div>
      <div class="field"><label>Business</label>
        <input class="input" name="business_name" maxlength="190" placeholder="e.g. Urban Bites" value="<?= sanitize($fBusiness) ?>">
      </div>
    </div>
    <div class="field" style="max-width:240px"><label>Priority</label>
      <select class="select" name="priority">
        <?php foreach ($PRIORITIES as $pv => $pl): ?>
        <option value="<?= sanitize($pv) ?>"<?= $fPriority === $pv ? ' selected' : '' ?>><?= sanitize($pl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Message</label>
      <textarea class="textarea" name="body" rows="4" placeholder="Describe the issue&hellip;"><?= sanitize($fBody) ?></textarea>
    </div>
    <div class="row" style="gap:10px">
      <button class="btn btn--primary" type="submit"><span class="ic" data-ic="plus"></span> Create ticket</button>
    </div>
  </form>
</div>
<?php iqp_admin_end();
