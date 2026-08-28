<?php
/**
 * Platform admin — businesses' WhatsApp connection status & management.
 * Data source: users (role='client') + client_whatsapp_accounts + whatsapp_messages_log.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$message = '';
$error   = '';

// ---- POST: revoke a client's WhatsApp connection (CSRF verified) ----
// Server-side equivalent of /api/admin/whatsapp-revoke.php (same single UPDATE).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'revoke') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        if ($clientId > 0) {
            try {
                db_execute(
                    'UPDATE client_whatsapp_accounts SET connection_status = \'revoked\' WHERE client_id = ?',
                    'i',
                    [$clientId]
                );
                $message = 'WhatsApp connection revoked.';
            } catch (Throwable $e) {
                error_log('whatsapp-clients revoke failed: ' . $e->getMessage());
                $error = 'Could not revoke the connection.';
            }
        } else {
            $error = 'Invalid client.';
        }
    }
}

// ---- filter param (preserved: all|active|revoked|pending) ----
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'active', 'revoked', 'pending'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$stats = [
    'connected'   => 0,
    'today'       => 0,
    'week'        => 0,
    'active_week' => 0,
];
$clients = [];
$waTablesReady = false;

try {
    $tableRow = db_fetch(
        'SELECT COUNT(*) AS cnt FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'client_whatsapp_accounts\'',
        's',
        [DB_NAME]
    );
    $waTablesReady = ((int) ($tableRow['cnt'] ?? 0)) > 0;
} catch (Throwable $e) {
    error_log('whatsapp-clients table check: ' . $e->getMessage());
}

if ($waTablesReady) {
    try {
        $stats = [
            'connected' => (int) (db_fetch('SELECT COUNT(*) AS c FROM client_whatsapp_accounts WHERE connection_status = \'active\'')['c'] ?? 0),
            'today'     => (int) (db_fetch('SELECT COUNT(*) AS c FROM whatsapp_messages_log WHERE DATE(created_at) = CURDATE()')['c'] ?? 0),
            'week'      => (int) (db_fetch('SELECT COUNT(*) AS c FROM whatsapp_messages_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')['c'] ?? 0),
            'active_week' => (int) (db_fetch(
                'SELECT COUNT(DISTINCT client_id) AS c FROM whatsapp_messages_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
            )['c'] ?? 0),
        ];

        $sql = 'SELECT u.id AS client_id, u.name, u.email, u.company_name,
                       cwa.waba_id, cwa.phone_number_id, cwa.phone_display_number,
                       cwa.connection_status, cwa.connected_at,
                       (SELECT COUNT(*) FROM whatsapp_messages_log w WHERE w.client_id = u.id AND w.direction = \'outbound\') AS sent_count,
                       (SELECT COUNT(*) FROM whatsapp_messages_log w WHERE w.client_id = u.id AND w.direction = \'inbound\') AS received_count
                FROM users u
                LEFT JOIN client_whatsapp_accounts cwa ON cwa.client_id = u.id
                    AND cwa.id = (
                        SELECT MAX(c2.id) FROM client_whatsapp_accounts c2 WHERE c2.client_id = u.id
                    )
                WHERE u.role = \'client\'';

        if ($filter === 'active') {
            $sql .= ' AND cwa.connection_status = \'active\'';
        } elseif ($filter === 'revoked') {
            $sql .= ' AND cwa.connection_status = \'revoked\'';
        } elseif ($filter === 'pending') {
            $sql .= ' AND (cwa.connection_status = \'pending\' OR cwa.id IS NULL)';
        }

        $sql .= ' ORDER BY u.name ASC';
        $clients = db_fetch_all($sql);
    } catch (Throwable $e) {
        error_log('whatsapp-clients query failed: ' . $e->getMessage());
    }
}

// Connection-status badge in the admin design system.
$waBadge = static function (?string $status): string {
    $map = [
        'active'  => ['badge--green', 'Connected'],
        'revoked' => ['badge--gray', 'Revoked'],
        'pending' => ['badge--amber', 'Pending'],
    ];
    [$cls, $label] = $map[$status] ?? ['badge--gray', 'Not connected'];
    return '<span class="badge ' . $cls . '"><span class="dot"></span>' . sanitize($label) . '</span>';
};

$filterLabels = [
    'all'     => 'All clients',
    'active'  => 'Connected',
    'revoked' => 'Revoked',
    'pending' => 'Pending / none',
];

iqp_admin_begin($user, 'integrations', [
    'title'    => 'WhatsApp Clients',
    'subtitle' => 'Business WhatsApp connection status across the platform',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/integrations"><span class="ic" data-ic="plug"></span> Integrations</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
if (!$waTablesReady) {
    iqp_flash('WhatsApp tables are not installed yet. Run migrate-whatsapp.php once, then delete it.', 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="whatsapp"></span></span><div class="grow"><div class="stat-card__label">Connected</div><div class="stat-card__value"><?= (int) $stats['connected'] ?></div></div></div><div class="stat-card__foot"><span class="muted">active connections</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="send"></span></span><div class="grow"><div class="stat-card__label">Today</div><div class="stat-card__value"><?= (int) $stats['today'] ?></div></div></div><div class="stat-card__foot"><span class="muted">messages logged</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="calendar"></span></span><div class="grow"><div class="stat-card__label">This week</div><div class="stat-card__value"><?= (int) $stats['week'] ?></div></div></div><div class="stat-card__foot"><span class="muted">last 7 days</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile cyan"><span class="ic" data-ic="activity"></span></span><div class="grow"><div class="stat-card__label">Active 7d</div><div class="stat-card__value"><?= (int) $stats['active_week'] ?></div></div></div><div class="stat-card__foot"><span class="muted">clients messaging</span></div></div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">Clients</span>
    <span class="spacer"></span>
    <form method="get" class="row" style="gap:8px;align-items:center;margin:0">
      <label class="muted small" for="wa-filter">Filter</label>
      <select id="wa-filter" name="filter" class="select" style="min-width:160px" onchange="this.form.submit()">
        <?php foreach ($filterLabels as $fv => $fl): ?>
        <option value="<?= sanitize($fv) ?>"<?= $filter === $fv ? ' selected' : '' ?>><?= sanitize($fl) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn--soft btn--sm" type="submit">Apply</button></noscript>
    </form>
  </div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Client</th><th>Phone</th><th>WABA ID</th><th>Status</th>
        <th class="nowrap">Connected</th><th class="right">Sent</th><th class="right">Received</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if (empty($clients)): ?>
        <tr><td colspan="8" class="muted">No clients found.</td></tr>
      <?php else: foreach ($clients as $c):
          $clientId  = (int) ($c['client_id'] ?? 0);
          $status    = isset($c['connection_status']) && is_string($c['connection_status']) ? $c['connection_status'] : null;
          $phone     = (string) ($c['phone_display_number'] ?? '');
          $waba      = (string) ($c['waba_id'] ?? '');
          $connected = !empty($c['connected_at']) ? date('M j, Y', strtotime((string) $c['connected_at'])) : '';
          $name      = (string) ($c['name'] ?? '');
      ?>
        <tr>
          <td class="strong">
            <a href="/admin/client-detail?id=<?= $clientId ?>"><?= sanitize($name !== '' ? $name : ('#' . $clientId)) ?></a>
            <?php if (!empty($c['company_name'])): ?><div class="muted small"><?= sanitize((string) $c['company_name']) ?></div><?php endif; ?>
          </td>
          <td class="muted nowrap"><?= sanitize($phone !== '' ? $phone : '—') ?></td>
          <td class="muted small"><?= sanitize($waba !== '' ? $waba : '—') ?></td>
          <td><?= $waBadge($status) ?></td>
          <td class="muted nowrap"><?= $connected !== '' ? sanitize($connected) : '—' ?></td>
          <td class="right"><?= (int) ($c['sent_count'] ?? 0) ?></td>
          <td class="right"><?= (int) ($c['received_count'] ?? 0) ?></td>
          <td>
            <div class="row-actions">
              <button type="button" class="btn btn--soft btn--sm" data-wa-log="<?= $clientId ?>" data-wa-name="<?= sanitize($name) ?>">View log</button>
              <?php if ($status === 'active'): ?>
              <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Revoke this WhatsApp connection? This only disconnects it from the app.');">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="client_id" value="<?= $clientId ?>">
                <button type="submit" class="btn btn--danger btn--sm">Revoke</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<!-- Message-log modal (self-contained; fetches /api/admin/whatsapp-log.php, GET, admin-guarded) -->
<div id="wa-log-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:200"></div>
<div id="wa-log-modal" class="card" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:201;width:min(760px,calc(100% - 32px));max-height:82vh;overflow:auto;margin:0">
  <div class="card__head">
    <span class="card__title" id="wa-log-title">Message log</span>
    <span class="spacer"></span>
    <button type="button" class="btn btn--ghost btn--sm" id="wa-log-close">Close</button>
  </div>
  <div class="card__body" id="wa-log-body"><p class="muted">Loading&hellip;</p></div>
</div>
<script>
(function () {
  var overlay = document.getElementById('wa-log-overlay'),
      modal   = document.getElementById('wa-log-modal'),
      titleEl = document.getElementById('wa-log-title'),
      bodyEl  = document.getElementById('wa-log-body'),
      closeBtn = document.getElementById('wa-log-close');
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function openModal() { overlay.style.display = 'block'; modal.style.display = 'block'; document.body.style.overflow = 'hidden'; }
  function closeModal() { overlay.style.display = 'none'; modal.style.display = 'none'; document.body.style.overflow = ''; }
  if (overlay) overlay.addEventListener('click', closeModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  document.querySelectorAll('[data-wa-log]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-wa-log'), name = btn.getAttribute('data-wa-name') || '';
      titleEl.textContent = 'Log: ' + name;
      bodyEl.innerHTML = '<p class="muted">Loading&hellip;</p>';
      openModal();
      fetch('/api/admin/whatsapp-log.php?client_id=' + encodeURIComponent(id))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.success) { bodyEl.innerHTML = '<p class="muted">' + esc(data.error || 'Failed to load log.') + '</p>'; return; }
          if (!data.messages || !data.messages.length) { bodyEl.innerHTML = '<p class="muted">No messages.</p>'; return; }
          var rows = data.messages.map(function (m) {
            var dir = m.direction === 'inbound' ? '&darr; In' : '&uarr; Out';
            var who = m.direction === 'inbound' ? (m.from || '') : (m.to || '');
            return '<tr><td class="nowrap">' + dir + '</td>' +
              '<td class="muted small nowrap">' + esc(who) + '</td>' +
              '<td>' + esc(m.preview) + '</td>' +
              '<td><span class="badge badge--gray">' + esc((m.status || '').toUpperCase()) + '</span></td>' +
              '<td class="muted small nowrap">' + esc(m.created_at) + '</td></tr>';
          }).join('');
          bodyEl.innerHTML = '<div class="table-wrap"><table class="tbl"><thead><tr><th>Dir</th><th>From/To</th><th>Message</th><th>Status</th><th>Time</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
        })
        .catch(function () { bodyEl.innerHTML = '<p class="muted">Failed to load log.</p>'; });
    });
  });
})();
</script>
<?php iqp_admin_end();
