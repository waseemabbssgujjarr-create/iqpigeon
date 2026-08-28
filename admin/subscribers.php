<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

// update_subscribers is an optional table (absent on a fresh install). Guard with the
// readiness check the app already uses, then wrap the query in try/catch as a belt-and-braces.
$ready       = notifications_tables_ready();
$subscribers = [];
if ($ready) {
    try {
        $subscribers = db_fetch_all(
            'SELECT s.*, u.company_name FROM update_subscribers s
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.status = \'active\'
             ORDER BY s.subscribed_at DESC',
            '',
            []
        );
    } catch (Throwable $e) {
        $subscribers = [];
    }
}

// Breakdown derived from the rows already fetched — no extra queries, degrades to zeros.
$total    = count($subscribers);
$bySource = ['website' => 0, 'settings' => 0, 'register' => 0];
foreach ($subscribers as $s) {
    $src = (string) ($s['source'] ?? '');
    if (isset($bySource[$src])) {
        $bySource[$src]++;
    }
}
$sourceBadge = [
    'website'  => 'badge--blue',
    'settings' => 'badge--purple',
    'register' => 'badge--amber',
];

iqp_admin_begin($user, 'announcements', [
    'title'    => 'Subscribers',
    'subtitle' => $total . ' active email ' . ($total === 1 ? 'subscriber' : 'subscribers') . ' for product & system updates',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/announcements"><span class="ic" data-ic="megaphone"></span> Back to announcements</a>',
]);
?>
<?php if (!$ready): ?>
<div class="card"><div class="card__body">
  <div class="iqp-empty">The subscription system isn&rsquo;t set up on this database yet. Run the notifications migration to start collecting update subscribers.</div>
</div></div>
<?php else: ?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="mail"></span></span><div class="grow"><div class="stat-card__label">Active subscribers</div><div class="stat-card__value"><?= (int) $total ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="globe"></span></span><div class="grow"><div class="stat-card__label">From website</div><div class="stat-card__value"><?= (int) $bySource['website'] ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile purple"><span class="ic" data-ic="settings"></span></span><div class="grow"><div class="stat-card__label">From settings</div><div class="stat-card__value"><?= (int) $bySource['settings'] ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="user"></span></span><div class="grow"><div class="stat-card__label">From signup</div><div class="stat-card__value"><?= (int) $bySource['register'] ?></div></div></div></div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Active subscribers</span><span class="spacer"></span><span class="badge badge--green"><?= (int) $total ?></span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Email</th>
        <th>Name</th>
        <th>Company</th>
        <th>Source</th>
        <th class="nowrap">Subscribed</th>
      </tr></thead>
      <tbody>
      <?php if ($subscribers === []): ?>
        <tr><td colspan="5" class="muted">No subscribers yet.</td></tr>
      <?php else: foreach ($subscribers as $s):
          $src     = (string) ($s['source'] ?? '');
          $srcCls  = $sourceBadge[$src] ?? 'badge--gray';
          $name    = trim((string) ($s['name'] ?? ''));
          $company = trim((string) ($s['company_name'] ?? ''));
          $when    = format_date((string) ($s['subscribed_at'] ?? ''));
      ?>
        <tr>
          <td class="strong"><?= sanitize((string) ($s['email'] ?? '')) ?></td>
          <td class="muted"><?= $name !== '' ? sanitize($name) : '&mdash;' ?></td>
          <td class="muted"><?= $company !== '' ? sanitize($company) : '&mdash;' ?></td>
          <td><span class="badge <?= $srcCls ?>"><?= sanitize($src !== '' ? ucfirst($src) : 'Unknown') ?></span></td>
          <td class="muted nowrap"><?= $when !== '' ? sanitize($when) : '&mdash;' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php endif; ?>
<?php iqp_admin_end();
