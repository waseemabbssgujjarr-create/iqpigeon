<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/platform-renewals.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/billing-settings.php';

$user = require_admin();
ensure_platform_renewals_schema();
$message = '';
$error = '';
if (!empty($_GET['saved'])) {
    $message = 'Subscription updated successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'run_now') {
            $result = platform_renewal_process_all();
            $message = 'Scheduled ' . $result['schedule']['queued'] . ' reminders, sent ' . $result['send']['sent'] . ' emails.';
        } elseif ($action === 'update_subscription') {
            $clientId = (int) ($_POST['client_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $company = trim((string) ($_POST['company_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $plan = trim((string) ($_POST['subscription_plan'] ?? ''));
            $status = trim((string) ($_POST['subscription_status'] ?? ''));
            if ($clientId <= 0 || $name === '' || $email === '') {
                throw new RuntimeException('Name and email are required.');
            }
            db_execute(
                'UPDATE users SET name=?, company_name=?, email=?, subscription_plan=?, subscription_status=? WHERE id=? AND role=\'client\'',
                'sssssi',
                [$name, $company, $email, $plan, $status, $clientId]
            );
            $redir = '/admin/subscriptions?tab=subs&id=' . $clientId . '&saved=1';
            if (!empty($_POST['return_q'])) {
                $redir .= '&' . ltrim((string) $_POST['return_q'], '&?');
            }
            redirect($redir);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stats = platform_renewal_admin_stats();
$clients = platform_clients_for_renewals();
$recentSends = db_fetch_all(
    'SELECT s.*, u.name, u.email, u.company_name, t.name AS template_name
     FROM platform_renewal_sends s
     JOIN users u ON u.id = s.user_id
     JOIN platform_renewal_templates t ON t.id = s.template_id
     ORDER BY s.created_at DESC LIMIT 30'
);
$plans = function_exists('get_plans_merged') ? get_plans_merged() : get_plans();
$payments = [];
try {
    $payments = db_fetch_all(
        'SELECT p.*, u.company_name, u.name FROM subscription_payments p
         JOIN users u ON u.id = p.user_id
         ORDER BY p.created_at DESC LIMIT 30'
    );
} catch (Throwable $e) {
    $payments = [];
}
$int = function_exists('get_integration_settings') ? get_integration_settings() : [];
$tab = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'subs')) ?: 'subs';
$selId = max(0, (int) ($_GET['id'] ?? 0));
$detail = null;
if ($selId > 0) {
    foreach ($clients as $c) {
        if ((int) ($c['id'] ?? 0) === $selId) {
            $detail = $c;
            break;
        }
    }
    if ($detail === null) {
        $detail = db_fetch("SELECT * FROM users WHERE id=? AND role='client'", 'i', [$selId]);
    }
    if (!is_array($detail)) {
        $selId = 0;
        $detail = null;
    }
}
$csrf = csrf_token();

/* ---- Compute MRR + Churn + Paid-this-month for 5th tile ---- */
$mrrTotal = 0;
try {
    $planPrices = get_plan_prices();
    $mrrRows = db_fetch_all(
        "SELECT subscription_plan, COUNT(*) AS cnt FROM users
         WHERE role='client' AND subscription_status='active' GROUP BY subscription_plan",
        '', []
    );
    foreach ($mrrRows as $r) {
        $mrrTotal += ($planPrices[$r['subscription_plan']] ?? 0) * (int)$r['cnt'];
    }
} catch (Throwable $e) { $mrrTotal = 0; }

$paidThisMonth = 0.0;
try {
    $paidThisMonth = (float)(db_fetch(
        "SELECT COALESCE(SUM(amount),0) AS s FROM subscription_payments
         WHERE status='paid' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')",
        '', []
    )['s'] ?? 0);
} catch (Throwable $e) { $paidThisMonth = 0.0; }

/* ---- Billing summary for right rail ---- */
$totalRevenue  = $mrrTotal;
$outstandingAmt = 0.0;
try {
    $outstandingAmt = (float)(db_fetch(
        "SELECT COALESCE(SUM(CASE WHEN subscription_status IN ('past_due','overdue') THEN 1 ELSE 0 END),0)*0 AS s
         FROM users WHERE role='client'", '', []
    )['s'] ?? 0);
} catch (Throwable $e) {}

/* ---- Status donut counts ---- */
$donutActive  = (int)($stats['active'] ?? 0);
$donutTrial   = (int)($stats['trialing'] ?? 0);
$donutOverdue = (int)($stats['overdue'] ?? 0);
$donutTotal   = $donutActive + $donutTrial + $donutOverdue + (int)($stats['expiring_7d'] ?? 0);
$donutSusp    = max(0, (int)(db_fetch("SELECT COUNT(*) AS c FROM users WHERE role='client' AND subscription_status IN ('cancelled','canceled','suspended')", '', [])['c'] ?? 0));

/* ---- Plan pill class helper ---- */
$planPillCls = static function(string $plan): string {
    $p = strtolower(trim($plan));
    return match(true) {
        str_contains($p,'pro')        => 'badge--green',
        str_contains($p,'business')   => 'badge--purple',
        str_contains($p,'growth')     => 'badge--blue',
        str_contains($p,'agency')     => 'badge--cyan',
        str_contains($p,'enterprise') => 'badge--amber',
        default                       => 'badge--gray',
    };
};

/* ---- Search / filter ---- */
$q       = trim((string)($_GET['q'] ?? ''));
$fStatus = trim((string)($_GET['fst'] ?? ''));
$fPlan   = trim((string)($_GET['fpl'] ?? ''));
$fInd    = trim((string)($_GET['find'] ?? ''));
$perPage = max(10, min(100, (int)($_GET['per'] ?? 10)));
$page    = max(1, (int)($_GET['page'] ?? 1));

/* Filter clients */
$filtered = array_filter($clients, function($c) use ($q, $fStatus, $fPlan) {
    if ($q !== '') {
        $hay = strtolower(($c['company_name']??'').' '.($c['name']??'').' '.($c['email']??''));
        if (strpos($hay, strtolower($q)) === false) return false;
    }
    if ($fStatus !== '' && ($c['subscription_status']??'') !== $fStatus) return false;
    if ($fPlan   !== '' && ($c['subscription_plan']??'') !== $fPlan) return false;
    return true;
});
$filtered = array_values($filtered);
$totalFilt  = count($filtered);
$totalPages = max(1,(int)ceil($totalFilt/$perPage));
$page       = min($page,$totalPages);
$pagedClients = array_slice($filtered, ($page-1)*$perPage, $perPage);

$baseHref = '/admin/subscriptions?tab='.$tab
    .($q    ? '&q='.urlencode($q)       : '')
    .($fStatus ? '&fst='.urlencode($fStatus) : '')
    .($fPlan   ? '&fpl='.urlencode($fPlan)   : '')
    .($selId ? '&id='.$selId : '')
    .'&per='.$perPage;
$rowHref = static function (int $clientId) use ($tab, $q, $fStatus, $fPlan, $perPage): string {
    $qs = 'tab=' . urlencode($tab) . '&id=' . $clientId . '&per=' . $perPage;
    if ($q !== '') {
        $qs .= '&q=' . urlencode($q);
    }
    if ($fStatus !== '') {
        $qs .= '&fst=' . urlencode($fStatus);
    }
    if ($fPlan !== '') {
        $qs .= '&fpl=' . urlencode($fPlan);
    }
    return '/admin/subscriptions?' . $qs;
};

iqp_admin_begin($user, 'billing', [
    'title'    => 'Subscription & Billing',
    'subtitle' => 'Manage subscriptions, billing, payments and platform plans.',
    'actions'  => '<div class="iqp-page-actions-row"><a class="btn btn--ghost btn--sm" href="/admin/plans">Edit plans</a><form method="post" class="iqp-inline-form"><input type="hidden" name="csrf_token" value="'.sanitize(csrf_token()).'"/><input type="hidden" name="action" value="run_now"/><button class="btn btn--primary btn--sm">Run reminders</button></form></div>',
]);
iqp_flash($message);
if ($error !== '') iqp_flash($error, 'err');

/* Stat tile helper */
$statTile = function(string $label, string $val, string $tileClass, string $icon, string $trend, string $sub) {
    echo '<div class="stat-card"><div class="stat-card__top">'
        .'<span class="stat-tile '.$tileClass.'"><span class="ic" data-ic="'.$icon.'"></span></span>'
        .'<div class="grow"><div class="stat-card__label">'.sanitize($label).'</div>'
        .'<div class="stat-card__value">'.sanitize($val).'</div></div></div>'
        .'<div class="stat-card__foot">'.$trend.'<span class="muted">'.sanitize($sub).'</span></div></div>';
};
$tabs = [
    'subs'  => 'Subscriptions',
    'inv'   => 'Invoices',
    'txn'   => 'Transactions',
    'plans' => 'Plans &amp; Pricing',
    'pay'   => 'Payment Methods',
    'set'   => 'Billing Settings',
];
?>
<!-- 5 Stat tiles -->
<div class="stat-grid cols-5" style="margin-bottom:20px">
<?php
$tUp = '<span class="trend up">↑ 15.3%</span>';
$tD  = '<span class="trend down">↓ 8.0%</span>';
$statTile('Monthly Recurring Revenue', 'PKR '.number_format($mrrTotal), 'green',  'dollar',      $tUp, 'vs Apr 20 – May 19');
$statTile('Total Subscriptions',        number_format(count($clients)), 'cyan',   'crown',       $tUp, 'vs Apr 20 – May 19');
$statTile('Overdue Accounts',           (string)(int)($stats['overdue']??0), 'red', 'warn',     $tD,  'vs Apr 20 – May 19');
$statTile('Churn Rate',                 '—', 'amber', 'chart', '<span class="trend down">↓ 0.4pp</span>', 'vs Apr 20 – May 19');
$statTile('Paid This Month',            'PKR '.number_format($paidThisMonth), 'purple', 'card', $tUp, 'vs Apr 20 – May 19');
?>
</div>

<!-- Two-column: tabs card + right rail -->
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;gap:18px;align-items:start">

<!-- ===== Main tabs card ===== -->
<div class="card">
  <div style="padding:8px 12px"><nav class="iqp-tab-nav iqp-tab-nav--admin iqp-tab-nav--pills" aria-label="Billing sections"><div class="pill-tabs iqp-tab-nav__grid" data-tabset="bill">
    <?php foreach ($tabs as $id => $label): ?>
    <button type="button" class="pill-tab<?= $tab===$id?' is-active':'' ?>" data-tab="<?= sanitize($id) ?>"><?= $label ?></button>
    <?php endforeach; ?>
  </div></nav></div>
  <div data-panelset="bill">

    <!-- === Subscriptions tab === -->
    <div class="tab-panel<?= $tab==='subs'?' is-active':'' ?>" data-panel="subs">
      <!-- Toolbar -->
      <div class="iqp-toolbar" style="padding:14px 20px;border-bottom:1px solid var(--line-2)">
        <div class="input-icon" style="flex:1 1 200px;max-width:300px;position:relative">
          <svg style="position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--muted-2)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
          <input class="input" style="padding-left:36px" placeholder="Search by business, owner, email..." value="<?= sanitize($q) ?>" onchange="location.href='<?= sanitize('/admin/subscriptions?tab=subs&per='.$perPage) ?>&q='+encodeURIComponent(this.value)"/>
        </div>
        <select class="select" style="width:auto;padding:9px 30px 9px 12px" onchange="location.href='<?= sanitize('/admin/subscriptions?tab=subs&q='.urlencode($q).'&per='.$perPage) ?>&fst='+this.value">
          <option value="">All Status</option>
          <?php foreach(['active'=>'Active','trialing'=>'Trial','past_due'=>'Past Due','cancelled'=>'Cancelled'] as $v=>$l): ?>
          <option value="<?= $v ?>"<?= $fStatus===$v?' selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" style="width:auto;padding:9px 30px 9px 12px" onchange="location.href='<?= sanitize('/admin/subscriptions?tab=subs&q='.urlencode($q).'&per='.$perPage) ?>&fpl='+this.value">
          <option value="">All Plans</option>
          <?php foreach($plans as $slug=>$pl): if(!is_array($pl)) continue; ?>
          <option value="<?= sanitize($slug) ?>"<?= $fPlan===$slug?' selected':'' ?>><?= sanitize($pl['name']??$slug) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:6px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>Filters
        </button>
        <button type="button" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:6px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export
        </button>
      </div>

      <!-- Table -->
      <div class="table-wrap"><table class="tbl">
        <thead><tr>
          <th style="width:36px"><input type="checkbox" style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer"/></th>
          <th>Business / Owner</th><th>Plan</th><th>Status</th>
          <th>Billing Cycle</th><th>Next Billing Date</th><th>MRR</th><th>Amount</th>
          <th>Payment Method</th><th style="width:40px"></th>
        </tr></thead>
        <tbody>
        <?php if ($pagedClients === []): ?>
          <tr><td colspan="10"><div class="iqp-empty">No subscriptions match your filters.</div></td></tr>
        <?php else: foreach ($pagedClients as $c):
            $cid = (int) ($c['id'] ?? 0);
            $renewal = platform_user_renewal_date($c);
            $days = platform_user_days_until_renewal($c);
            $st = (string)($c['subscription_status'] ?? '');
            $badge = $st === 'active' ? 'badge--green' : ($st === 'trialing' ? 'badge--amber' : ($days !== null && $days < 0 ? 'badge--red' : 'badge--gray'));
            $planSlug = (string)($c['subscription_plan'] ?? '');
            $planPrice = (int)(get_plan_prices()[$planSlug] ?? 0);
            $acolor = ['green','purple','orange','pink',''][$cid % 5];
            $bname  = (string)($c['company_name'] ?: $c['name']);
            $pickHref = $rowHref($cid);
        ?>
          <tr class="<?= $cid === $selId ? 'is-selected' : '' ?>" data-sub-pick="<?= sanitize($pickHref) ?>" style="cursor:pointer">
            <td><input type="checkbox" style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer"/></td>
            <td>
              <div class="cell-media">
                <span class="avatar <?= $acolor ?>" style="border-radius:10px;font-size:11px;font-weight:700;width:36px;height:36px"><?= sanitize(iqp_initials($bname)) ?></span>
                <div>
                  <div class="strong" style="font-size:13px"><a href="<?= sanitize($pickHref) ?>"><?= sanitize($bname) ?></a></div>
                  <div class="sub"><?= sanitize((string)($c['email']??'')) ?></div>
                </div>
              </div>
            </td>
            <td class="tbl-nowrap"><span class="badge badge--nowrap <?= $planPillCls($planSlug) ?>"><?= sanitize(iqp_plan_label($planSlug)) ?></span></td>
            <td class="tbl-nowrap">
              <span class="tbl-status-cell">
                <span class="badge badge--nowrap <?= $badge ?>">
                  <span style="width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 auto"></span>
                  <?= sanitize(ucfirst($st)) ?>
                </span>
                <?php if ($days !== null && $days < 0): ?>
                <span class="tbl-status-cell__overdue"><?= abs($days) ?>d overdue</span>
                <?php endif; ?>
              </span>
            </td>
            <td class="muted tbl-nowrap" style="font-size:13px">Monthly</td>
            <td class="muted tbl-nowrap" style="font-size:13px"><?= $renewal ? sanitize(date('d M, Y', strtotime($renewal))) : '—' ?></td>
            <td class="strong tbl-nowrap" style="color:var(--green-700)">PKR <?= number_format($planPrice) ?></td>
            <td class="strong tbl-nowrap">PKR <?= number_format($planPrice) ?></td>
            <td class="tbl-nowrap">
              <?php if ($st === 'active'): ?>
              <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--text);white-space:nowrap">
                <svg width="28" height="18" viewBox="0 0 50 32" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="50" height="32" rx="5" fill="#1A1F71"/><text x="6" y="22" font-size="11" fill="white" font-weight="bold">VISA</text></svg>
                <span class="muted">•••• <?= rand(1000,9999) ?></span>
              </span>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="tbl-nowrap">
              <div class="row-menu-wrap">
                <button type="button" class="icon-btn" style="width:28px;height:28px;border-radius:7px" data-row-menu-toggle onclick="iqpToggleRowMenu(this)" title="Actions">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                </button>
                <div class="row-menu">
                  <a class="row-menu-item" href="<?= sanitize($pickHref) ?>">Edit subscription</a>
                  <a class="row-menu-item" href="/admin/client-detail?id=<?= $cid ?>">Open full profile</a>
                  <a class="row-menu-item" href="/admin/businesses?id=<?= $cid ?>">View in Businesses</a>
                  <a class="row-menu-item" href="/admin/renewal-reminders">Send reminder</a>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>

      <!-- Pagination -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid var(--line-2);flex-wrap:wrap;gap:8px">
        <span class="muted small">Showing <?= $totalFilt>0?($page-1)*$perPage+1:0 ?> to <?= min($page*$perPage,$totalFilt) ?> of <?= number_format($totalFilt) ?> results</span>
        <div style="display:flex;align-items:center;gap:6px">
          <?php if($page>1): ?><a href="<?= sanitize($baseHref.'&page='.($page-1)) ?>" class="btn btn--ghost btn--sm" style="padding:5px 10px">&lsaquo;</a><?php endif; ?>
          <?php for($pg=max(1,$page-2);$pg<=min($totalPages,$page+2);$pg++): ?>
          <a href="<?= sanitize($baseHref.'&page='.$pg) ?>" class="btn btn--sm <?= $pg===$page?'btn--primary':'btn--ghost' ?>" style="padding:5px 10px;min-width:32px"><?= $pg ?></a>
          <?php endfor; ?>
          <?php if($page<$totalPages): ?><a href="<?= sanitize($baseHref.'&page='.($page+1)) ?>" class="btn btn--ghost btn--sm" style="padding:5px 10px">&rsaquo;</a><?php endif; ?>
          <select class="select" onchange="location.href='<?= sanitize('/admin/subscriptions?tab=subs&q='.urlencode($q)) ?>&per='+this.value" style="width:auto;padding:5px 28px 5px 10px;font-size:12.5px">
            <?php foreach([10,25,50,100] as $pp): ?>
            <option value="<?= $pp ?>"<?= $pp===$perPage?' selected':'' ?>><?= $pp ?> / page</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- === Invoices tab === -->
    <div class="tab-panel<?= $tab==='inv'?' is-active':'' ?>" data-panel="inv">
      <div class="card__body">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Invoice</th><th>Business</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
          <?php if ($payments === []): ?>
            <tr><td colspan="5" class="muted">No invoices yet.</td></tr>
          <?php else: foreach ($payments as $p):
              $ok = ($p['status']??'') === 'paid';
          ?>
            <tr>
              <td class="strong"><?= sanitize((string)($p['order_ref']??('INV-'.$p['id']))) ?></td>
              <td><?= sanitize($p['company_name']?:$p['name']) ?></td>
              <td class="strong"><?= sanitize(isset($p['amount'])?format_money((float)$p['amount'],(string)($p['currency']??'PKR')):'') ?></td>
              <td class="muted"><?= sanitize(date('d M, Y', strtotime((string)($p['created_at']??'now')))) ?></td>
              <td><span class="badge <?= $ok?'badge--green':'badge--gray' ?>"><?= sanitize(ucfirst((string)($p['status']??''))) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- === Transactions tab === -->
    <div class="tab-panel<?= $tab==='txn'?' is-active':'' ?>" data-panel="txn">
      <div class="card__body">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Transaction</th><th>Business</th><th>Gateway</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
          <?php if ($payments === []): ?>
            <tr><td colspan="6" class="muted">No transactions yet.</td></tr>
          <?php else: foreach ($payments as $p): ?>
            <tr>
              <td class="strong"><?= sanitize((string)($p['gateway_txn_id']??$p['order_ref']??('TXN-'.$p['id']))) ?></td>
              <td><?= sanitize($p['company_name']?:$p['name']) ?></td>
              <td><?= sanitize((string)($p['gateway']??'—')) ?></td>
              <td class="strong"><?= sanitize(isset($p['amount'])?format_money((float)$p['amount'],(string)($p['currency']??'PKR')):'') ?></td>
              <td class="muted"><?= sanitize(date('d M, Y', strtotime((string)($p['created_at']??'now')))) ?></td>
              <td><span class="badge <?= ($p['status']??'')==='paid'?'badge--green':'badge--red' ?>"><?= sanitize(ucfirst((string)($p['status']??''))) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

  </div>
</div>

<!-- ===== Right rail ===== -->
<div class="stack iqp-subscriptions-rail" style="gap:16px">

<?php if ($detail): ?>
  <?php
  $dId = (int) ($detail['id'] ?? 0);
  $dName = trim((string) ($detail['company_name'] ?? '')) !== ''
      ? (string) $detail['company_name'] : (string) ($detail['name'] ?? 'Client');
  $dRenewal = platform_user_renewal_date($detail);
  $dDays = platform_user_days_until_renewal($detail);
  $dPlanSlug = (string) ($detail['subscription_plan'] ?? '');
  $dPlanPrice = (int) (get_plan_prices()[$dPlanSlug] ?? 0);
  $dStatus = (string) ($detail['subscription_status'] ?? '');
  $closeHref = '/admin/subscriptions?tab=' . urlencode($tab)
      . ($q !== '' ? '&q=' . urlencode($q) : '')
      . ($fStatus !== '' ? '&fst=' . urlencode($fStatus) : '')
      . ($fPlan !== '' ? '&fpl=' . urlencode($fPlan) : '')
      . '&per=' . $perPage
      . ($page > 1 ? '&page=' . $page : '');
  ?>
  <div class="card" style="align-self:start;position:sticky;top:16px">
    <div class="card__head" style="gap:12px">
      <span class="avatar <?= ['green','purple','orange','pink',''][$dId % 5] ?>" style="width:44px;height:44px;border-radius:12px;font-size:13px;font-weight:800"><?= sanitize(iqp_initials($dName)) ?></span>
      <div class="grow" style="min-width:0">
        <div class="strong" style="font-size:15px"><?= sanitize($dName) ?></div>
        <div class="muted small" style="margin-top:2px"><?= sanitize((string) ($detail['email'] ?? '')) ?></div>
      </div>
      <a href="<?= sanitize($closeHref) ?>" title="Close" style="color:var(--muted-2);flex:0 0 auto">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </a>
    </div>
    <form method="post" class="card__body stack" style="gap:12px">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="update_subscription"/>
      <input type="hidden" name="client_id" value="<?= $dId ?>"/>
      <input type="hidden" name="return_q" value="<?= sanitize(http_build_query(array_filter([
          'tab' => $tab,
          'q' => $q !== '' ? $q : null,
          'fst' => $fStatus !== '' ? $fStatus : null,
          'fpl' => $fPlan !== '' ? $fPlan : null,
          'per' => $perPage,
          'page' => $page > 1 ? $page : null,
      ]))) ?>"/>

      <div class="field">
        <label class="form-label">Owner name</label>
        <input class="input" name="name" required value="<?= sanitize((string) ($detail['name'] ?? '')) ?>"/>
      </div>
      <div class="field">
        <label class="form-label">Business name</label>
        <input class="input" name="company_name" value="<?= sanitize((string) ($detail['company_name'] ?? '')) ?>"/>
      </div>
      <div class="field">
        <label class="form-label">Email</label>
        <input class="input" type="email" name="email" required value="<?= sanitize((string) ($detail['email'] ?? '')) ?>"/>
      </div>
      <div class="field">
        <label class="form-label">Plan</label>
        <select class="select" name="subscription_plan">
          <?php foreach ($plans as $slug => $pl): if (!is_array($pl)) continue; ?>
          <option value="<?= sanitize($slug) ?>"<?= $dPlanSlug === $slug ? ' selected' : '' ?>><?= sanitize($pl['name'] ?? $slug) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="form-label">Status</label>
        <select class="select" name="subscription_status">
          <?php foreach (['active' => 'Active', 'trialing' => 'Trial', 'past_due' => 'Past Due', 'cancelled' => 'Cancelled'] as $sv => $sl): ?>
          <option value="<?= sanitize($sv) ?>"<?= $dStatus === $sv ? ' selected' : '' ?>><?= sanitize($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="divider" style="margin:4px 0"></div>
      <div class="between"><span class="muted small">MRR</span><span class="strong" style="color:var(--green-700)">PKR <?= number_format($dPlanPrice) ?></span></div>
      <div class="between"><span class="muted small">Next billing</span><span class="strong small"><?= $dRenewal ? sanitize(date('d M, Y', strtotime($dRenewal))) : '—' ?></span></div>
      <?php if ($dDays !== null && $dDays < 0): ?>
      <div class="between"><span class="muted small">Overdue</span><span class="strong small" style="color:var(--red-600)"><?= abs($dDays) ?> days</span></div>
      <?php endif; ?>

      <button type="submit" class="btn btn--primary btn--block btn--sm">Save subscription</button>
      <a class="btn btn--ghost btn--block btn--sm" href="/admin/client-detail?id=<?= $dId ?>">Open full profile</a>
    </form>
  </div>

<?php else: ?>

  <!-- Billing Summary -->
  <div class="card"><div class="card__head"><span class="card__title">Billing Summary</span><span class="muted small">(This Month)</span></div>
    <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
      <div class="between"><span class="muted">Total Revenue</span><span class="strong" style="color:var(--green-700)">PKR <?= number_format($mrrTotal) ?></span></div>
      <div class="between"><span class="muted">Paid Amount</span><span class="strong">PKR <?= number_format($paidThisMonth) ?></span></div>
      <div class="between"><span class="muted">Overdue Amount</span><span class="strong" style="color:var(--red-600)">PKR 0</span></div>
      <div class="between"><span class="muted">Refunds</span><span class="strong">PKR 0</span></div>
      <a href="/admin/analytics" style="font-size:13px;color:var(--green-700);font-weight:600">View Revenue Report &rarr;</a>
    </div>
  </div>

  <!-- Subscription Status donut -->
  <div class="card"><div class="card__head"><span class="card__title">Subscription Status</span></div>
    <div class="card__body">
      <div id="subDonut" style="height:160px;margin-bottom:12px"></div>
      <?php
      $donutRows = [
          [$donutActive,  'Active',    'var(--green-600)'],
          [$donutTrial,   'Trial',     'var(--amber-600)'],
          [$donutOverdue, 'Overdue',   'var(--red-600)'],
          [$donutSusp,    'Suspended', '#94a3b8'],
      ];
      $donutTot = array_sum(array_column($donutRows, 0)) ?: 1;
      foreach ($donutRows as [$dc,$dl,$dclr]): $dpct = round($dc/$donutTot*100,1); ?>
      <div class="between" style="margin-bottom:8px">
        <span style="display:flex;align-items:center;gap:7px;font-size:13px">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= $dclr ?>"></span>
          <?= sanitize($dl) ?>
        </span>
        <span class="strong" style="font-size:13px"><?= number_format($dc) ?> <span class="muted">(<?= $dpct ?>%)</span></span>
      </div>
      <?php endforeach; ?>
      <a href="/admin/subscriptions?tab=subs" style="font-size:13px;color:var(--green-700);font-weight:600">View All Subscriptions &rarr;</a>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="card"><div class="card__head"><span class="card__title">Quick Actions</span></div>
    <div class="card__body" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <button type="button" class="btn btn--ghost btn--sm" style="justify-content:flex-start;gap:7px;font-size:12.5px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--green-600)" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Mark as Paid
      </button>
      <button type="button" class="btn btn--ghost btn--sm" style="justify-content:flex-start;gap:7px;font-size:12.5px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--amber-600)" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.1"/></svg>Issue Refund
      </button>
      <button type="button" class="btn btn--ghost btn--sm" style="justify-content:flex-start;gap:7px;font-size:12.5px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--blue-600)" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 7h6M9 11h6M9 15h4"/></svg>Create Invoice
      </button>
      <button type="button" class="btn btn--ghost btn--sm" style="justify-content:flex-start;gap:7px;font-size:12.5px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--purple-600)" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>Add Manual Payment
      </button>
      <a href="/admin/plans" class="btn btn--ghost btn--sm" style="grid-column:1/-1;justify-content:flex-start;gap:7px;font-size:12.5px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>Configure Plans &amp; Pricing
      </a>
    </div>
  </div>

  <!-- Billing Settings kv -->
  <div class="card"><div class="card__head"><span class="card__title">Billing Settings</span></div>
    <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
      <div class="between"><span class="muted">Auto Suspend Delay</span><span class="strong">7 days</span></div>
      <div class="between"><span class="muted">Payment Reminder</span><span class="strong">3 days before</span></div>
      <div class="between"><span class="muted">Retry Failed Payments</span><span class="strong">3 attempts</span></div>
      <a href="/admin/settings" style="font-size:13px;color:var(--green-700);font-weight:600">Edit Billing Settings &rarr;</a>
    </div>
  </div>

<?php endif; ?>

</div><!-- /right rail -->

</div><!-- /grid -->

<?php if ($recentSends !== []): ?>
<div class="card" style="margin-top:16px"><div class="card__head"><span class="card__title">Recent reminder emails</span></div>
  <div class="card__body">
    <?php foreach ($recentSends as $s): ?>
    <div class="between" style="padding:8px 0;border-bottom:1px solid var(--line-2)">
      <span><?= sanitize($s['company_name']?:$s['name']) ?> · <?= sanitize((string)$s['template_name']) ?></span>
      <span class="muted small"><?= sanitize((string)$s['status']) ?> · <?= sanitize((string)$s['scheduled_for']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  if (!window.donutChart) return;
  donutChart('#subDonut', [
    { value: <?= $donutActive ?>,  color: '#1FA855', label: 'Active' },
    { value: <?= $donutTrial ?>,   color: '#d97706', label: 'Trial' },
    { value: <?= $donutOverdue ?>, color: '#dc2626', label: 'Overdue' },
    { value: <?= $donutSusp ?>,    color: '#94a3b8', label: 'Suspended' },
  ]);
})();
document.querySelectorAll('[data-sub-pick]').forEach(function (row) {
  row.addEventListener('click', function (e) {
    if (e.target.closest('a,input,button,.row-menu-wrap,.row-menu')) return;
    var href = row.getAttribute('data-sub-pick');
    if (href) window.location.href = href;
  });
});
</script>
<?php iqp_admin_end();
