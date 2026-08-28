<?php
/**
 * Platform admin — oversight of all tenant AI CEO connections.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/ai-ceo.php';

$user = require_admin();
$message = '';
$error   = '';

ai_ceo_ensure_schema();

// ---- POST: enable / disable a connection (CSRF verified) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    $connId = (int) ($_POST['connection_id'] ?? 0);
    try {
        $row = db_fetch('SELECT * FROM ai_ceo_connections WHERE id = ?', 'i', [$connId]);
        if (!$row) {
            $error = 'Connection not found.';
        } elseif ($action === 'disable') {
            db_execute('UPDATE ai_ceo_connections SET enabled = 0 WHERE id = ?', 'i', [$connId]);
            $message = 'Connection disabled.';
        } elseif ($action === 'enable') {
            db_execute('UPDATE ai_ceo_connections SET enabled = 1 WHERE id = ?', 'i', [$connId]);
            $message = 'Connection enabled.';
        }
    } catch (Throwable $e) {
        error_log('ai-ceo-connections POST failed: ' . $e->getMessage());
        $error = 'Could not update the connection.';
    }
}

// ---- oversight list + derived stats (never echo api_key / webhook_secret) ----
$rows = [];
try {
    $rows = ai_ceo_admin_list_connections();
} catch (Throwable $e) {
    error_log('ai-ceo-connections list failed: ' . $e->getMessage());
    $rows = [];
}

$statTotals = ['conns' => 0, 'enabled' => 0, 'received' => 0, 'pending' => 0];
$statById   = [];
foreach ($rows as $r) {
    $cid = (int) ($r['id'] ?? 0);
    $statTotals['conns']++;
    if (!empty($r['enabled'])) {
        $statTotals['enabled']++;
    }
    $statTotals['received'] += (int) ($r['leads_received'] ?? 0);
    $s = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'received_today' => 0];
    try {
        $s = ai_ceo_connection_stats($cid);
    } catch (Throwable $e) {
        // leave zeros on any per-connection failure
    }
    $statById[$cid] = $s;
    $statTotals['pending'] += (int) ($s['pending'] ?? 0);
}

// Subscription-plan badge.
$planBadge = static function (string $status): string {
    $map = ['active' => 'badge--green', 'trialing' => 'badge--amber', 'past_due' => 'badge--red', 'cancelled' => 'badge--gray'];
    $cls = $map[$status] ?? 'badge--gray';
    return '<span class="badge ' . $cls . '">' . sanitize($status !== '' ? ucfirst($status) : '—') . '</span>';
};

iqp_admin_begin($user, 'integrations', [
    'title'    => 'AI CEO Connections',
    'subtitle' => 'Multi-tenant Growth Engine crawler integrations — oversight only',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile purple"><span class="ic" data-ic="plug"></span></span><div class="grow"><div class="stat-card__label">Connections</div><div class="stat-card__value"><?= (int) $statTotals['conns'] ?></div></div></div><div class="stat-card__foot"><span class="muted">clients connected</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span><div class="grow"><div class="stat-card__label">Enabled</div><div class="stat-card__value"><?= (int) $statTotals['enabled'] ?></div></div></div><div class="stat-card__foot"><span class="muted">currently active</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="users"></span></span><div class="grow"><div class="stat-card__label">Leads received</div><div class="stat-card__value"><?= (int) $statTotals['received'] ?></div></div></div><div class="stat-card__foot"><span class="muted">all connections</span></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="clock"></span></span><div class="grow"><div class="stat-card__label">Outreach pending</div><div class="stat-card__value"><?= (int) $statTotals['pending'] ?></div></div></div><div class="stat-card__foot"><span class="muted">queued messages</span></div></div>
</div>

<div class="card" style="margin-bottom:16px"><div class="card__body">
  <p class="muted small" style="margin:0">Each paid client connects their own Growth Engine from <strong>Client &rarr; Connect AI CEO</strong>. Ingest is per-tenant API key; WhatsApp outreach uses that client's selected bot. This page is oversight only.</p>
</div></div>

<div class="card">
  <div class="card__head"><span class="card__title">Tenant connections</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Client</th><th>Bot</th><th>Status</th><th>Plan</th>
        <th class="right">Received</th><th>Queue</th><th class="nowrap">Last ingest</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="8" class="muted">No clients have connected AI CEO yet.</td></tr>
      <?php else: foreach ($rows as $r):
          $cid        = (int) ($r['id'] ?? 0);
          $s          = $statById[$cid] ?? ['pending' => 0, 'sent' => 0];
          $enabled    = !empty($r['enabled']);
          $uid        = (int) ($r['user_id'] ?? 0);
          $clientName = (string) ($r['company_name'] ?: ($r['user_name'] ?? ''));
          $lastIngest = trim((string) ($r['last_ingest_at'] ?? ''));
          $lastRel    = $lastIngest !== '' ? iqp_rel_time($lastIngest) : '';
      ?>
        <tr>
          <td class="strong">
            <?php if ($uid > 0): ?>
            <a href="/admin/client-detail?id=<?= $uid ?>"><?= sanitize($clientName !== '' ? $clientName : ('#' . $uid)) ?></a>
            <?php else: ?>
            <?= sanitize($clientName !== '' ? $clientName : '—') ?>
            <?php endif; ?>
            <div class="muted small"><?= sanitize((string) ($r['user_email'] ?? '')) ?></div>
          </td>
          <td><?= sanitize((string) ($r['bot_name'] ?? ('#' . (int) ($r['bot_id'] ?? 0)))) ?></td>
          <td>
            <?php if ($enabled): ?>
            <span class="badge badge--green"><span class="dot"></span>Enabled</span>
            <?php else: ?>
            <span class="badge badge--gray"><span class="dot"></span>Disabled</span>
            <?php endif; ?>
            <?php if (!empty($r['last_error'])): ?>
            <div class="small" style="color:#ef4444;margin-top:4px"><?= sanitize(mb_strimwidth((string) $r['last_error'], 0, 80, '…')) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $planBadge((string) ($r['subscription_status'] ?? '')) ?></td>
          <td class="right"><?= (int) ($r['leads_received'] ?? 0) ?></td>
          <td class="muted nowrap"><?= (int) ($s['pending'] ?? 0) ?> pending / <?= (int) ($s['sent'] ?? 0) ?> sent</td>
          <td class="muted nowrap"><?= $lastRel !== '' ? sanitize($lastRel) : ($lastIngest !== '' ? sanitize($lastIngest) : '—') ?></td>
          <td>
            <form method="post" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
              <input type="hidden" name="connection_id" value="<?= $cid ?>">
              <?php if ($enabled): ?>
              <button type="submit" name="action" value="disable" class="btn btn--soft btn--sm">Disable</button>
              <?php else: ?>
              <button type="submit" name="action" value="enable" class="btn btn--primary btn--sm">Enable</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<p class="muted small" style="margin-top:12px">Ingest endpoint: <code><?= sanitize(ai_ceo_ingest_url()) ?></code></p>
<?php iqp_admin_end();
