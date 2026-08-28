<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$search = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT b.*, u.company_name, u.name AS client_name, u.email AS client_email,
               u.subscription_plan, u.subscription_status,
               (SELECT COUNT(*) FROM leads l WHERE l.bot_id = b.id) AS lead_count
        FROM bots b
        JOIN users u ON u.id = b.user_id
        WHERE u.role = 'client'";
$types  = '';
$params = [];

if ($search !== '') {
    $sql   .= ' AND (b.name LIKE ? OR u.company_name LIKE ? OR u.name LIKE ?)';
    $types  = 'sss';
    $like   = '%' . $search . '%';
    $params = [$like, $like, $like];
}
$sql  .= ' ORDER BY b.created_at DESC';
$bots  = db_fetch_all($sql, $types, $params);

$totalBots   = count($bots);
$activeBots  = count(array_filter($bots, fn($b) => !empty($b['is_active'])));
$waBots      = count(array_filter($bots, fn($b) => !empty($b['whatsapp_verified'])));
$widgetBots  = count(array_filter($bots, fn($b) => !empty($b['widget_enabled'])));

$avatarColor = static fn(int $id): string => ['green','purple','orange','pink',''][$id % 5];

iqp_admin_begin($user, 'businesses', [
    'title'    => 'All Bots',
    'subtitle' => 'Every AI bot deployed across all client businesses.',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/businesses">View Businesses</a>',
]);
if (!empty($_GET['deleted'])) {
    iqp_flash('Bot deleted successfully.');
}
?>

<!-- Stat tiles -->
<div class="stat-grid cols-4" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="box"></span></span>
      <div class="grow"><div class="stat-card__label">Total Bots</div><div class="stat-card__value"><?= number_format($totalBots) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">across all businesses</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span>
      <div class="grow"><div class="stat-card__label">Active</div><div class="stat-card__value"><?= number_format($activeBots) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">responding to messages</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile" style="background:rgba(37,211,102,.15)">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
      </span>
      <div class="grow"><div class="stat-card__label">WhatsApp Connected</div><div class="stat-card__value"><?= number_format($waBots) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">of <?= $totalBots ?> total bots</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile cyan"><span class="ic" data-ic="globe"></span></span>
      <div class="grow"><div class="stat-card__label">Widget Enabled</div><div class="stat-card__value"><?= number_format($widgetBots) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">website chat widget</span></div>
  </div>
</div>

<!-- Search & table -->
<div class="card">
  <div class="card__head" style="flex-wrap:wrap;gap:10px">
    <form method="GET" action="/admin/bots" class="input-icon" style="flex:1 1 240px;max-width:360px;position:relative">
      <svg style="position:absolute;left:13px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--muted-2)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" class="input" value="<?= sanitize($search) ?>" placeholder="Search bots, businesses…" style="padding-left:38px"/>
    </form>
    <span class="spacer"></span>
    <span class="muted small"><?= number_format($totalBots) ?> bot<?= $totalBots !== 1 ? 's' : '' ?></span>
  </div>

  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Bot</th>
        <th>Business</th>
        <th>Channels</th>
        <th>Plan</th>
        <th>Leads</th>
        <th>Status</th>
        <th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if ($bots === []): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:28px">
          <?= $search !== '' ? 'No bots match "' . sanitize($search) . '".' : 'No bots yet.' ?>
        </td></tr>
      <?php else: foreach ($bots as $bot):
          $bId      = (int)$bot['id'];
          $bName    = (string)($bot['name'] ?? 'Bot');
          $bCo      = (string)($bot['company_name'] ?: $bot['client_name'] ?? '');
          $bEmail   = (string)($bot['client_email'] ?? '');
          $planSlug = (string)($bot['subscription_plan'] ?? '');
          $planPill = match(true) {
              str_contains(strtolower($planSlug),'pro')      => 'badge--green',
              str_contains(strtolower($planSlug),'business') => 'badge--purple',
              str_contains(strtolower($planSlug),'growth')   => 'badge--blue',
              default                                         => 'badge--gray',
          };
          $aColor   = $avatarColor($bId);
          $waOk     = !empty($bot['whatsapp_verified']);
          $widgetOn = !empty($bot['widget_enabled']);
          $isActive = !empty($bot['is_active']);
          $leads    = (int)($bot['lead_count'] ?? 0);
      ?>
        <tr>
          <td>
            <div class="cell-media">
              <span class="avatar <?= $aColor ?>" style="border-radius:10px;font-size:11px;width:36px;height:36px"><?= sanitize(iqp_initials($bName)) ?></span>
              <div>
                <a href="/admin/bot-detail?id=<?= $bId ?>" class="strong" style="font-size:13.5px"><?= sanitize($bName) ?></a>
                <div class="muted small">#<?= $bId ?></div>
              </div>
            </div>
          </td>
          <td>
            <a href="/admin/client-detail?id=<?= (int)$bot['user_id'] ?>" style="color:var(--ink);font-size:13px;font-weight:600"><?= sanitize($bCo) ?></a>
            <div class="muted small"><?= sanitize($bEmail) ?></div>
          </td>
          <td>
            <div style="display:flex;flex-wrap:wrap;gap:5px">
              <?php if ($waOk): ?>
              <span class="badge badge--green" style="font-size:11px;padding:2px 7px">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                WhatsApp
              </span>
              <?php else: ?>
              <span class="badge badge--gray" style="font-size:11px;padding:2px 7px">No WA</span>
              <?php endif; ?>
              <?php if ($widgetOn): ?>
              <span class="badge badge--blue" style="font-size:11px;padding:2px 7px">Widget</span>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="badge <?= $planPill ?>"><?= sanitize(iqp_plan_label($planSlug)) ?></span></td>
          <td class="strong"><?= number_format($leads) ?></td>
          <td>
            <span class="badge <?= $isActive ? 'badge--green' : 'badge--amber' ?>">
              <span style="width:6px;height:6px;border-radius:50%;background:currentColor"></span>
              <?= $isActive ? 'Active' : 'Paused' ?>
            </span>
          </td>
          <td>
            <div class="row-actions">
              <a class="ic" href="/admin/bot-detail?id=<?= $bId ?>" title="View bot">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>
              <a class="ic" href="/admin/client-detail?id=<?= (int)$bot['user_id'] ?>" title="View client">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </a>
              <form method="POST" action="/admin/bot-detail?id=<?= $bId ?>" style="display:inline;margin:0"
                    onsubmit="return confirm('Delete this bot and all its leads? This cannot be undone.')">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="delete"/>
                <button class="ic danger" type="submit" title="Delete bot">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php iqp_admin_end();
