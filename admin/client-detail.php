<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/platform-renewals.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/commerce-schema.php';

$user     = require_admin();
$clientId = (int)($_GET['id'] ?? 0);
$client   = db_fetch("SELECT * FROM users WHERE id = ? AND role = 'client'", 'i', [$clientId]);
if (!$client) {
    redirect('/admin/businesses');
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $name    = trim((string)($_POST['name']               ?? ''));
        $company = trim((string)($_POST['company_name']       ?? ''));
        $email   = trim((string)($_POST['email']              ?? ''));
        $plan    = trim((string)($_POST['subscription_plan']  ?? ''));
        $status  = trim((string)($_POST['subscription_status']?? ''));
        if ($name !== '' && $email !== '') {
            try {
                db_execute(
                    "UPDATE users SET name=?, company_name=?, email=?, subscription_plan=?, subscription_status=? WHERE id=?",
                    'sssssi',
                    [$name, $company, $email, $plan, $status, $clientId]
                );
                $message = 'Account updated successfully.';
                $client  = db_fetch("SELECT * FROM users WHERE id=?", 'i', [$clientId]) ?? $client;
            } catch (Throwable $e) {
                $error = 'Could not update account: ' . $e->getMessage();
            }
        } else {
            $error = 'Name and email are required.';
        }
    }

    if ($action === 'reset_password') {
        $pw = trim((string)($_POST['new_password'] ?? ''));
        if (strlen($pw) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                db_execute("UPDATE users SET password=? WHERE id=?", 'si', [$hash, $clientId]);
                $message = 'Password reset successfully.';
            } catch (Throwable $e) {
                $error = 'Could not reset password: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        try {
            // Remove leads + bots then user
            $bids = db_fetch_all('SELECT id FROM bots WHERE user_id=?', 'i', [$clientId]);
            foreach ($bids as $b) {
                db_execute('DELETE FROM leads WHERE bot_id=?',     'i', [(int)$b['id']]);
                db_execute('DELETE FROM bots  WHERE id=?',         'i', [(int)$b['id']]);
            }
            db_execute("DELETE FROM users WHERE id=? AND role='client'", 'i', [$clientId]);
            redirect('/admin/businesses?deleted=1');
        } catch (Throwable $e) {
            $error = 'Could not delete client: ' . $e->getMessage();
        }
    }
}

$bots      = db_fetch_all('SELECT * FROM bots WHERE user_id=? ORDER BY created_at DESC', 'i', [$clientId]);
$leadStats = db_fetch(
    "SELECT COUNT(l.id) AS total,
            SUM(CASE WHEN l.status='qualified' THEN 1 ELSE 0 END) AS qualified
     FROM leads l JOIN bots b ON b.id=l.bot_id WHERE b.user_id=?",
    'i', [$clientId]
);
$recentLeads = db_fetch_all(
    'SELECT l.*, b.name AS bot_name,
        (SELECT message FROM conversations c WHERE c.lead_id=l.id ORDER BY c.created_at DESC LIMIT 1) AS last_message
     FROM leads l JOIN bots b ON b.id=l.bot_id WHERE b.user_id=?
     ORDER BY l.created_at DESC LIMIT 10',
    'i', [$clientId]
);

// Plan + renewal
$renewal  = platform_user_renewal_date($client);
$daysLeft = platform_user_days_until_renewal($client);
$planSlug = (string)($client['subscription_plan'] ?? 'starter');
$planPill = match(true) {
    str_contains($planSlug,'pro')        => 'badge--green',
    str_contains($planSlug,'business')   => 'badge--purple',
    str_contains($planSlug,'growth')     => 'badge--blue',
    str_contains($planSlug,'enterprise') => 'badge--amber',
    default                              => 'badge--gray',
};
$statusSlug  = (string)($client['subscription_status'] ?? 'trialing');
$statusPill  = match(strtolower($statusSlug)) {
    'active'   => 'badge--green',
    'trialing' => 'badge--amber',
    default    => 'badge--red',
};
$dName   = trim((string)($client['company_name'] ?? '')) !== '' ? (string)$client['company_name'] : (string)($client['name'] ?? 'Client');
$acolor  = ['green','purple','orange','pink',''][$clientId % 5];
$csrf    = csrf_token();

// Orders / usage stats
$orderCnt = 0; $orderRev = 0.0;
try {
    ensure_commerce_schema();
    $os = db_fetch(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS rev
         FROM bot_orders o JOIN bots b ON b.id=o.bot_id WHERE b.user_id=? AND o.status<>'cancelled'",
        'i', [$clientId]
    );
    $orderCnt = (int)($os['cnt'] ?? 0);
    $orderRev = (float)($os['rev'] ?? 0);
} catch (Throwable $e) {}

iqp_admin_begin($user, 'businesses', [
    'title'    => sanitize($dName),
    'subtitle' => sanitize((string)($client['email'] ?? '')),
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/businesses">← Back to Businesses</a>',
]);
iqp_flash($message);
if ($error !== '') iqp_flash($error, 'err');
?>

<!-- Header identity -->
<div class="grid" style="grid-template-columns:auto 1fr auto;align-items:center;gap:16px;background:#fff;border:1px solid var(--line);border-radius:var(--r-lg);padding:20px 24px;margin-bottom:20px">
  <div class="avatar <?= sanitize($acolor) ?>" style="width:56px;height:56px;border-radius:14px;font-size:16px;font-weight:800"><?= sanitize(iqp_initials($dName)) ?></div>
  <div>
    <div style="font-size:19px;font-weight:800;color:var(--ink)"><?= sanitize($dName) ?></div>
    <div style="font-size:13px;color:var(--muted);margin-top:3px"><?= sanitize((string)($client['email']??'')) ?></div>
  </div>
  <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
    <span class="badge <?= $planPill ?>"><?= sanitize(iqp_plan_label($planSlug)) ?></span>
    <span class="badge <?= $statusPill ?>"><span class="dot"></span><?= sanitize(ucfirst($statusSlug)) ?></span>
  </div>
</div>

<!-- Stat tiles -->
<div class="stat-grid cols-3" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile green"><span class="ic" data-ic="card"></span></span>
      <div class="grow">
        <div class="stat-card__label">Plan</div>
        <div class="stat-card__value" style="font-size:22px"><?= sanitize(iqp_plan_label($planSlug)) ?></div>
      </div>
    </div>
    <div class="stat-card__foot">
      <span class="badge <?= $statusPill ?>"><span class="dot"></span><?= sanitize(ucfirst($statusSlug)) ?></span>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile <?= $daysLeft !== null && $daysLeft < 0 ? 'red' : 'blue' ?>"><span class="ic" data-ic="calendar"></span></span>
      <div class="grow">
        <div class="stat-card__label">Renews</div>
        <div class="stat-card__value" style="font-size:20px"><?= $renewal ? sanitize(date('M j, Y', strtotime($renewal))) : '—' ?></div>
      </div>
    </div>
    <div class="stat-card__foot">
      <span class="muted">
        <?php if ($daysLeft === null): ?>—
        <?php elseif ($daysLeft < 0): ?><span style="color:var(--red-600);font-weight:700"><?= abs($daysLeft) ?> days overdue</span>
        <?php elseif ($daysLeft === 0): ?><span style="color:var(--amber-600);font-weight:700">Due today</span>
        <?php else: ?><?= (int)$daysLeft ?> days left
        <?php endif; ?>
      </span>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile purple"><span class="ic" data-ic="user"></span></span>
      <div class="grow">
        <div class="stat-card__label">Leads</div>
        <div class="stat-card__value"><?= (int)($leadStats['total'] ?? 0) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= (int)($leadStats['qualified'] ?? 0) ?> qualified</span></div>
  </div>
</div>

<!-- Main two-column layout -->
<div class="grid" style="grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start">

  <!-- Left: Edit form + Bots + Recent Leads + Danger Zone -->
  <div class="stack">

    <!-- Edit Account -->
    <div class="card">
      <div class="card__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
        <span class="card__title">Edit Account</span>
      </div>
      <div class="card__body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="update"/>
          <div class="grid grid-2">
            <div class="field"><label class="form-label">Full Name</label><input class="input" name="name" required value="<?= sanitize((string)($client['name']??'')) ?>"/></div>
            <div class="field"><label class="form-label">Company</label><input class="input" name="company_name" value="<?= sanitize((string)($client['company_name']??'')) ?>"/></div>
          </div>
          <div class="field"><label class="form-label">Email</label><input class="input" type="email" name="email" required value="<?= sanitize((string)($client['email']??'')) ?>"/></div>
          <div class="grid grid-2">
            <div class="field">
              <label class="form-label">Plan</label>
              <select class="select" name="subscription_plan">
                <?php foreach (['starter','pro','growth','business','agency','enterprise'] as $p): ?>
                <option value="<?= $p ?>"<?= $planSlug===$p?' selected':'' ?>><?= sanitize(ucfirst($p)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="form-label">Status</label>
              <select class="select" name="subscription_status">
                <?php foreach (['trialing','active','cancelled','past_due','suspended'] as $s): ?>
                <option value="<?= $s ?>"<?= $statusSlug===$s?' selected':'' ?>><?= sanitize(ucfirst(str_replace('_',' ',$s))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="muted small" style="margin-bottom:14px">Renewal reminders are sent automatically from <a href="/admin/subscriptions" style="color:var(--green-700)">Subscriptions</a>.</p>
          <button class="btn btn--primary btn--block">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- Bots -->
    <div class="card">
      <div class="card__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 11V9M16 11V9"/></svg>
        <span class="card__title">Bots (<?= count($bots) ?>)</span>
      </div>
      <div class="card__body" style="padding:0">
        <?php if ($bots === []): ?>
          <?php iqp_empty('No bots configured yet.'); ?>
        <?php else: foreach ($bots as $bot):
            $waConnected = !empty($bot['whatsapp_verified']);
            $widgetOn    = !empty($bot['widget_enabled']);
        ?>
          <a href="/admin/bot-detail?id=<?= (int)$bot['id'] ?>" class="list-row" style="padding:14px 20px">
            <div class="list-row__main">
              <div class="list-row__title"><?= sanitize((string)($bot['name']??'Bot')) ?></div>
              <div class="list-row__sub" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
                <?php if ($waConnected): ?>
                <span class="badge badge--green" style="font-size:11px;padding:2px 7px">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                  WhatsApp
                </span>
                <?php else: ?>
                <span class="badge badge--gray" style="font-size:11px;padding:2px 7px">No WhatsApp</span>
                <?php endif; ?>
                <?php if ($widgetOn): ?>
                <span class="badge badge--blue" style="font-size:11px;padding:2px 7px">Widget</span>
                <?php endif; ?>
              </div>
            </div>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Reset Password -->
    <div class="card">
      <div class="card__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--amber-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>
        <span class="card__title">Reset Password</span>
      </div>
      <div class="card__body">
        <form method="POST" class="row" style="gap:10px;align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="reset_password"/>
          <div class="grow field" style="margin:0"><input class="input" type="password" name="new_password" required minlength="8" placeholder="New password (min 8 chars)"/></div>
          <button class="btn btn--ghost" style="flex-shrink:0">Update Password</button>
        </form>
      </div>
    </div>

    <!-- Recent Leads -->
    <div class="card">
      <div class="card__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--purple-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
        <span class="card__title">Recent Leads</span>
        <span class="spacer"></span>
        <a class="view-all" href="/admin/conversations?client=<?= $clientId ?>">View all →</a>
      </div>
      <div class="card__body" style="padding:0">
        <?php if ($recentLeads === []): ?>
          <?php iqp_empty('No leads yet.'); ?>
        <?php else: foreach ($recentLeads as $lead):
            $ln = (string)($lead['name'] ?? $lead['phone'] ?? 'Lead');
        ?>
          <a href="/admin/conversation?lead_id=<?= (int)$lead['id'] ?>&client_id=<?= $clientId ?>" class="list-row" style="padding:12px 20px">
            <div class="avatar" style="width:34px;height:34px;font-size:11px;flex:0 0 auto;border-radius:50%"><?= sanitize(iqp_initials($ln)) ?></div>
            <div class="list-row__main">
              <div class="list-row__title"><?= sanitize($ln) ?></div>
              <div class="list-row__sub"><?= sanitize((string)($lead['bot_name']??'')) ?></div>
              <?php if (!empty($lead['last_message'])): ?>
              <div class="list-row__sub" style="font-style:italic">"<?= sanitize(mb_strimwidth((string)$lead['last_message'],0,60,'…')) ?>"</div>
              <?php endif; ?>
            </div>
            <div class="list-row__time"><?= sanitize(iqp_rel_time((string)($lead['created_at']??''))) ?></div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Danger Zone -->
    <div class="card" style="border-color:var(--red-100)">
      <div class="card__head" style="border-bottom-color:var(--red-100)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span class="card__title" style="color:var(--red-600)">Danger Zone</span>
      </div>
      <div class="card__body">
        <p class="muted small" style="margin-bottom:14px">Permanently delete this client, all bots, and all leads. <strong>This cannot be undone.</strong></p>
        <form method="POST" onsubmit="return confirm('Permanently delete this client and all their data? This cannot be undone.')">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="delete"/>
          <button type="submit" class="btn btn--danger btn--block">Delete Client Account</button>
        </form>
      </div>
    </div>

  </div><!-- /left -->

  <!-- Right: overview stats + subscription + notes -->
  <div class="stack" style="position:sticky;top:16px">

    <!-- Usage summary -->
    <div class="card">
      <div class="card__head"><span class="card__title">Usage Overview</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
        <div class="between">
          <span class="muted small">Total Leads</span>
          <span class="strong"><?= number_format((int)($leadStats['total']??0)) ?></span>
        </div>
        <div class="between">
          <span class="muted small">Qualified</span>
          <span class="strong"><?= number_format((int)($leadStats['qualified']??0)) ?></span>
        </div>
        <div class="between">
          <span class="muted small">Total Orders</span>
          <span class="strong"><?= number_format($orderCnt) ?></span>
        </div>
        <div class="between">
          <span class="muted small">Order Revenue</span>
          <span class="strong" style="color:var(--green-700)">PKR <?= number_format($orderRev) ?></span>
        </div>
        <div class="between">
          <span class="muted small">Bots</span>
          <span class="strong"><?= count($bots) ?></span>
        </div>
        <div class="between">
          <span class="muted small">Joined</span>
          <span class="strong"><?= sanitize(date('d M, Y', strtotime((string)($client['created_at']??'now')))) ?></span>
        </div>
      </div>
    </div>

    <!-- Subscription detail -->
    <div class="card">
      <div class="card__head"><span class="card__title">Subscription</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
        <div class="between">
          <span class="muted small">Plan</span>
          <span class="badge <?= $planPill ?>"><?= sanitize(iqp_plan_label($planSlug)) ?></span>
        </div>
        <div class="between">
          <span class="muted small">Status</span>
          <span class="badge <?= $statusPill ?>"><span class="dot"></span><?= sanitize(ucfirst($statusSlug)) ?></span>
        </div>
        <?php if ($renewal): ?>
        <div class="between">
          <span class="muted small">Renews</span>
          <span class="strong"><?= sanitize(date('d M, Y', strtotime($renewal))) ?></span>
        </div>
        <?php endif; ?>
        <div class="divider"></div>
        <a class="btn btn--ghost btn--block btn--sm" href="/admin/subscriptions">Manage in Billing</a>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
      <div class="card__head"><span class="card__title">Quick Actions</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:8px">
        <a class="btn btn--ghost btn--sm btn--block" href="/admin/conversations?client=<?= $clientId ?>" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          View Conversations
        </a>
        <a class="btn btn--ghost btn--sm btn--block" href="mailto:<?= sanitize((string)($client['email']??'')) ?>" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          Email Client
        </a>
        <a class="btn btn--ghost btn--sm btn--block" href="/admin/subscriptions?biz=<?= $clientId ?>" style="justify-content:flex-start;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/></svg>
          Change Plan
        </a>
      </div>
    </div>

  </div><!-- /right -->
</div><!-- /grid -->
<?php iqp_admin_end();
