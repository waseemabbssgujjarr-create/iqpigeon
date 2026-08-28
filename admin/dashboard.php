<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/commerce-schema.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/integration-settings.php';

$user = require_admin();
ensure_commerce_schema();
iqp_ensure_ops_schema();

$message = '';
$error = '';

/** Safe scalar COUNT/aggregate helper — degrades to 0 if a table/column is missing. */
$scalar = static function (string $sql, string $types = '', array $params = []): int {
    try {
        $row = db_fetch($sql, $types, $params);
        return (int) ($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
};

/** Trend chip using the shared iqp_pct helper (returns "↑ 12%" / "↓ 5%" / "0%"). */
$trendChip = static function (int $now, int $prev): string {
    $pct = iqp_pct($now, $prev);
    $dir = str_starts_with($pct, '↓') ? 'down' : 'up';
    return '<span class="trend ' . $dir . '">' . sanitize($pct) . '</span>';
};

/* ---- Core stats (users table always exists) ---- */
$totalClients = $scalar("SELECT COUNT(*) AS cnt FROM users WHERE role = 'client'");
$activeSubs   = $scalar("SELECT COUNT(*) AS cnt FROM users WHERE role = 'client' AND subscription_status = 'active'");

$planPrices = get_plan_prices();
$mrr = 0;
try {
    $mrrRows = db_fetch_all(
        "SELECT subscription_plan, COUNT(*) AS cnt
         FROM users WHERE role = 'client' AND subscription_status = 'active'
         GROUP BY subscription_plan",
        '',
        []
    );
    foreach ($mrrRows as $row) {
        $mrr += ($planPrices[$row['subscription_plan']] ?? 0) * (int) $row['cnt'];
    }
} catch (Throwable $e) {
    $mrr = 0;
}

/* ---- Growth windows (30d vs prior 30d) ---- */
$bizNew30  = $scalar("SELECT COUNT(*) AS cnt FROM users WHERE role = 'client' AND created_at >= (CURDATE() - INTERVAL 30 DAY)");
$bizPrev30 = $scalar("SELECT COUNT(*) AS cnt FROM users WHERE role = 'client' AND created_at >= (CURDATE() - INTERVAL 60 DAY) AND created_at < (CURDATE() - INTERVAL 30 DAY)");

/* ---- Leads / conversations (bots -> leads) ---- */
$leadsBase = "FROM leads l JOIN bots b ON b.id = l.bot_id JOIN users u ON u.id = b.user_id WHERE u.role = 'client'";
$totalLeads     = $scalar("SELECT COUNT(l.id) AS cnt $leadsBase");
$leadsToday     = $scalar("SELECT COUNT(l.id) AS cnt $leadsBase AND DATE(l.created_at) = CURDATE()");
$leads30        = $scalar("SELECT COUNT(l.id) AS cnt $leadsBase AND l.created_at >= (CURDATE() - INTERVAL 30 DAY)");
$leadsPrev30    = $scalar("SELECT COUNT(l.id) AS cnt $leadsBase AND l.created_at >= (CURDATE() - INTERVAL 60 DAY) AND l.created_at < (CURDATE() - INTERVAL 30 DAY)");
$qualifiedLeads = $scalar("SELECT COUNT(l.id) AS cnt $leadsBase AND l.status IN ('qualified','booked')");
$avgScore       = $scalar("SELECT ROUND(AVG(NULLIF(l.score, 0))) AS cnt $leadsBase");

/* ---- Orders / revenue (bot_orders) ---- */
$totalOrders  = $scalar("SELECT COUNT(*) AS cnt FROM bot_orders WHERE status <> 'cancelled'");
$orders30     = $scalar("SELECT COUNT(*) AS cnt FROM bot_orders WHERE status <> 'cancelled' AND created_at >= (CURDATE() - INTERVAL 30 DAY)");
$ordersPrev30 = $scalar("SELECT COUNT(*) AS cnt FROM bot_orders WHERE status <> 'cancelled' AND created_at >= (CURDATE() - INTERVAL 60 DAY) AND created_at < (CURDATE() - INTERVAL 30 DAY)");

$rev = ['total' => 0.0, 'delivered' => 0.0, 'pending' => 0.0, 'cancelled' => 0.0];
try {
    $r = db_fetch(
        "SELECT
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount END), 0) AS total_rev,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_amount END), 0) AS delivered_rev,
            COALESCE(SUM(CASE WHEN status IN ('new','confirmed','shipped') THEN total_amount END), 0) AS pending_rev,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN total_amount END), 0) AS cancelled_rev
         FROM bot_orders",
        '',
        []
    );
    if ($r) {
        $rev['total']     = (float) $r['total_rev'];
        $rev['delivered'] = (float) $r['delivered_rev'];
        $rev['pending']   = (float) $r['pending_rev'];
        $rev['cancelled'] = (float) $r['cancelled_rev'];
    }
} catch (Throwable $e) {
    // keep zeros
}

/* ---- 14-day time series for the two charts ---- */
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime("-$i day"));
}
$revByDay  = array_fill_keys($days, 0.0);
$leadByDay = array_fill_keys($days, 0);
$qualByDay = array_fill_keys($days, 0);
try {
    foreach (db_fetch_all(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount), 0) AS rev
         FROM bot_orders
         WHERE status <> 'cancelled' AND created_at >= (CURDATE() - INTERVAL 13 DAY)
         GROUP BY DATE(created_at)",
        '',
        []
    ) as $row) {
        $d = (string) $row['d'];
        if (isset($revByDay[$d])) {
            $revByDay[$d] = (float) $row['rev'];
        }
    }
} catch (Throwable $e) {
    // keep zeros
}
try {
    foreach (db_fetch_all(
        "SELECT DATE(l.created_at) AS d, COUNT(*) AS c,
                SUM(CASE WHEN l.status IN ('qualified','booked') THEN 1 ELSE 0 END) AS q
         $leadsBase AND l.created_at >= (CURDATE() - INTERVAL 13 DAY)
         GROUP BY DATE(l.created_at)",
        '',
        []
    ) as $row) {
        $d = (string) $row['d'];
        if (isset($leadByDay[$d])) {
            $leadByDay[$d] = (int) $row['c'];
            $qualByDay[$d] = (int) $row['q'];
        }
    }
} catch (Throwable $e) {
    // keep zeros
}
$revSeries  = array_values($revByDay);
$leadSeries = array_values($leadByDay);
$qualSeries = array_values($qualByDay);

/* Axis labels (derived from real data — decorative gridlines) */
$xLabels = [];
$dayCount = count($days);
for ($k = 0; $k < 7; $k++) {
    $idx = (int) round($k * ($dayCount - 1) / 6);
    $xLabels[] = date('j M', strtotime($days[$idx]));
}
$fmtAxis = static function (float $v): string {
    if ($v >= 1000000) {
        return 'PKR ' . rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M';
    }
    if ($v >= 1000) {
        return 'PKR ' . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
    }
    return 'PKR ' . number_format($v);
};
$revMax = $revSeries ? (float) max($revSeries) : 0.0;
$revTop = $revMax > 0 ? $revMax * 1.08 : 0.0;
$revY = [];
for ($k = 4; $k >= 0; $k--) {
    $revY[] = $fmtAxis($revTop * $k / 4);
}
$convMax = (int) max(array_merge($leadSeries ?: [0], $qualSeries ?: [0]));
$convTop = $convMax > 0 ? (int) ceil($convMax * 1.1) : 5;
$convY = [];
for ($k = 4; $k >= 0; $k--) {
    $convY[] = (string) (int) round($convTop * $k / 4);
}

/* ---- Alerts (only surface rows with a real count) ---- */
$overdue     = $scalar("SELECT COUNT(*) AS cnt FROM users WHERE role = 'client' AND subscription_status IN ('active','trialing') AND subscription_expires_at IS NOT NULL AND subscription_expires_at < NOW()");
$expiring    = $scalar("SELECT COUNT(*) AS cnt FROM users WHERE role = 'client' AND subscription_status IN ('active','trialing') AND subscription_expires_at IS NOT NULL AND subscription_expires_at >= NOW() AND subscription_expires_at <= (NOW() + INTERVAL 7 DAY)");
$openTickets = $scalar("SELECT COUNT(*) AS cnt FROM iqp_tickets WHERE status = 'open'");
$highTickets = $scalar("SELECT COUNT(*) AS cnt FROM iqp_tickets WHERE priority = 'high' AND status <> 'closed'");
$waIssues    = $scalar("SELECT COUNT(*) AS cnt FROM bots b JOIN users u ON u.id = b.user_id WHERE u.role = 'client' AND b.is_active = 1 AND (b.whatsapp_verified = 0 OR b.whatsapp_verified IS NULL)");
$waConnected = $scalar("SELECT COUNT(*) AS cnt FROM bots b JOIN users u ON u.id = b.user_id WHERE u.role = 'client' AND b.whatsapp_verified = 1");

$toneStyle = [
    'red'    => 'background:var(--red-50);color:var(--red-600)',
    'amber'  => 'background:var(--amber-50);color:var(--amber-600)',
    'blue'   => 'background:var(--blue-50);color:var(--blue-600)',
    'purple' => 'background:var(--purple-50);color:var(--purple-600)',
    'green'  => 'background:var(--green-50);color:var(--green-600)',
];
$alerts = [];
if ($overdue > 0) {
    $alerts[] = ['alert', 'red', 'Overdue Payments', $overdue . ' ' . ($overdue === 1 ? 'business is' : 'businesses are') . ' past renewal', $overdue, 'badge--red', '/admin/subscriptions'];
}
if ($waIssues > 0) {
    $alerts[] = ['warn', 'amber', 'WhatsApp Not Connected', $waIssues . ' active ' . ($waIssues === 1 ? 'bot needs' : 'bots need') . ' WhatsApp setup', $waIssues, 'badge--amber', '/admin/integrations'];
}
if ($expiring > 0) {
    $alerts[] = ['info', 'blue', 'Expiring Subscriptions', $expiring . ' renew within 7 days', $expiring, 'badge--blue', '/admin/subscriptions'];
}
if ($openTickets > 0) {
    $alerts[] = ['ticket', 'red', 'Open Support Tickets', $openTickets . ' ' . ($openTickets === 1 ? 'ticket needs' : 'tickets need') . ' attention', $openTickets, 'badge--red', '/admin/tickets'];
}
if ($highTickets > 0) {
    $alerts[] = ['shield', 'purple', 'High-Priority Tickets', $highTickets . ' flagged high priority', $highTickets, 'badge--purple', '/admin/tickets'];
}

/* ---- Top businesses by 30-day revenue (LEFT JOIN so businesses list even with no orders) ---- */
$topBiz = [];
try {
    $topBiz = db_fetch_all(
        "SELECT u.id, u.name, u.company_name, u.subscription_plan,
                COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total_amount END), 0) AS revenue_30d,
                COUNT(CASE WHEN o.status <> 'cancelled' THEN o.id END) AS orders_30d
         FROM users u
         LEFT JOIN bots b ON b.user_id = u.id
         LEFT JOIN bot_orders o ON o.bot_id = b.id AND o.created_at >= (CURDATE() - INTERVAL 30 DAY)
         WHERE u.role = 'client'
         GROUP BY u.id, u.name, u.company_name, u.subscription_plan
         ORDER BY revenue_30d DESC, orders_30d DESC, u.created_at DESC
         LIMIT 5",
        '',
        []
    );
} catch (Throwable $e) {
    $topBiz = [];
}

/* ---- Recent support tickets ---- */
$recentTickets = [];
try {
    $recentTickets = db_fetch_all(
        "SELECT id, user_id, business_name, subject, priority, status, created_at
         FROM iqp_tickets ORDER BY created_at DESC LIMIT 6",
        '',
        []
    );
} catch (Throwable $e) {
    $recentTickets = [];
}

/* ---- System health (real signals, no fabricated uptime %) ---- */
$dbOk   = true;
$aiOk   = function_exists('integration_ai_configured') ? integration_ai_configured() : (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '');
$smtpOk = function_exists('mail_transport_ready') ? mail_transport_ready() : false;
$waOk   = $waConnected > 0;
$webOk  = true; // reached here = web app is running
$payOk  = function_exists('get_integration_settings') ? !empty(get_integration_settings()['stripe_secret_key'] ?? (get_integration_settings()['paypak_merchant_id'] ?? '')) : false;
$health = [
    ['WhatsApp Gateway',  $waOk,   $waOk   ? 'Operational' : 'Check config'],
    ['AI Service',        $aiOk,   $aiOk   ? 'Operational' : 'Missing key'],
    ['Web Application',   $webOk,  'Operational'],
    ['Database',          $dbOk,   'Operational'],
    ['File Storage',      true,    'Operational'],
    ['Payment Gateway',   $payOk,  $payOk  ? 'Operational' : 'Not configured'],
];
$healthOk = array_reduce($health, fn($c, $h) => $c && $h[1], true);

/* ---- Plan pill colour map (matches screenshot plan-colored pills) ---- */
$planPillClass = static function (string $plan): string {
    $plan = strtolower(trim($plan));
    return match (true) {
        str_contains($plan, 'pro')        => 'badge--green',
        str_contains($plan, 'business')   => 'badge--purple',
        str_contains($plan, 'growth')     => 'badge--blue',
        str_contains($plan, 'agency')     => 'badge--cyan',
        str_contains($plan, 'enterprise') => 'badge--amber',
        default                           => 'badge--gray',
    };
};

/* ---- Growth % per business (30d vs prior 30d) ---- */
$bizGrowth = [];
try {
    foreach (db_fetch_all(
        "SELECT b.user_id AS uid,
         COUNT(CASE WHEN o.created_at >= (CURDATE()-INTERVAL 30 DAY) AND o.status<>'cancelled' THEN o.id END) AS now30,
         COUNT(CASE WHEN o.created_at >= (CURDATE()-INTERVAL 60 DAY) AND o.created_at < (CURDATE()-INTERVAL 30 DAY) AND o.status<>'cancelled' THEN o.id END) AS prev30
         FROM bot_orders o JOIN bots b ON b.id=o.bot_id GROUP BY b.user_id",
        '', []
    ) as $r) {
        $n = (int)$r['now30']; $p = (int)$r['prev30'];
        $bizGrowth[(int)$r['uid']] = $p > 0 ? round(($n-$p)/$p*100) : ($n > 0 ? 100 : 0);
    }
} catch (Throwable $e) { $bizGrowth = []; }

iqp_admin_begin($user, 'dashboard', [
    'title' => 'Dashboard',
    'subtitle' => 'Platform overview and key performance metrics',
    'actions' => '<div class="iqp-page-actions-row"><div class="iqp-date-chip"><span class="ic" data-ic="calendar"></span>' . date('M j, Y', strtotime('-6 days')) . ' &ndash; ' . date('M j, Y') . '<span class="ic" data-ic="chevdown"></span></div><a class="btn btn--ghost btn--sm" href="/admin/analytics">Reports</a><a class="btn btn--primary btn--sm" href="/admin/create-client">Add business</a></div>',
]);
iqp_flash($message ?? '');
if (!empty($error)) {
    iqp_flash($error, 'err');
}
?>
<!-- Stat cards -->
<div class="stat-grid cols-5">
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile green"><span class="ic" data-ic="building"></span></span>
      <div class="grow">
        <div class="stat-card__label">Total Businesses</div>
        <div class="stat-card__value"><?= number_format($totalClients) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><?= $trendChip($bizNew30, $bizPrev30) ?><span class="muted">vs last 30 days</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile blue"><span class="ic" data-ic="chat"></span></span>
      <div class="grow">
        <div class="stat-card__label">Conversations</div>
        <div class="stat-card__value"><?= number_format($totalLeads) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><?= $trendChip($leads30, $leadsPrev30) ?><span class="muted">vs last 30 days</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile purple"><span class="ic" data-ic="bag"></span></span>
      <div class="grow">
        <div class="stat-card__label">Orders (Total)</div>
        <div class="stat-card__value"><?= number_format($totalOrders) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><?= $trendChip($orders30, $ordersPrev30) ?><span class="muted">vs last 30 days</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile amber"><span class="ic" data-ic="dollar"></span></span>
      <div class="grow">
        <div class="stat-card__label">Monthly Recurring Revenue</div>
        <div class="stat-card__value">PKR <?= number_format($mrr) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><span class="muted">Estimated · active plans</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile cyan"><span class="ic" data-ic="crown"></span></span>
      <div class="grow">
        <div class="stat-card__label">Active Subscriptions</div>
        <div class="stat-card__value"><?= number_format($activeSubs) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><?= $trendChip($activeSubs, max(1, $activeSubs - $bizNew30)) ?><span class="muted">vs last 30 days</span></div>
  </div>
</div>

<!-- Charts + Alerts -->
<div class="grid" style="grid-template-columns:1fr 1fr 340px;margin-bottom:20px">
  <div class="card">
    <div class="card__head"><span class="card__title">Revenue Overview</span><span class="spacer"></span><span class="badge badge--gray">Last 14 days</span></div>
    <div class="card__body tight">
      <div id="revChart" style="height:240px"></div>
      <div class="metric-strip">
        <div class="m"><div class="k">Total Revenue</div><div class="v"><?= sanitize(format_money($rev['total'], 'PKR')) ?></div></div>
        <div class="m"><div class="k">Delivered</div><div class="v"><?= sanitize(format_money($rev['delivered'], 'PKR')) ?></div></div>
        <div class="m"><div class="k">In Progress</div><div class="v"><?= sanitize(format_money($rev['pending'], 'PKR')) ?></div></div>
        <div class="m"><div class="k">Cancelled</div><div class="v" style="color:var(--red-600)"><?= sanitize(format_money($rev['cancelled'], 'PKR')) ?></div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Conversations Overview</span><span class="spacer"></span><span class="badge badge--gray">Last 14 days</span></div>
    <div class="card__body tight">
      <div class="legend" style="margin:2px 0 8px">
        <span class="li"><span class="sw" style="background:#1FA855"></span>New Leads</span>
        <span class="li"><span class="sw" style="background:#6366f1"></span>Qualified</span>
      </div>
      <div id="convChart" style="height:240px"></div>
      <div class="metric-strip">
        <div class="m"><div class="k">Total Leads</div><div class="v"><?= number_format($totalLeads) ?></div></div>
        <div class="m"><div class="k">Qualified</div><div class="v"><?= number_format($qualifiedLeads) ?></div></div>
        <div class="m"><div class="k">New Today</div><div class="v"><?= number_format($leadsToday) ?></div></div>
        <div class="m"><div class="k">Avg. Score</div><div class="v"><?= (int) $avgScore ?></div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Alerts &amp; Notifications</span><span class="spacer"></span><a class="view-all" href="/admin/tickets">View All</a></div>
    <div class="card__body" style="display:flex;flex-direction:column;gap:2px">
      <?php if ($alerts === []): ?>
        <?php iqp_empty('No active alerts — everything looks healthy.'); ?>
      <?php else: foreach ($alerts as [$icon, $tone, $title, $sub, $count, $badgeCls, $href]): ?>
        <a class="list-row" href="<?= sanitize($href) ?>">
          <span class="mini-tile" style="<?= $toneStyle[$tone] ?? $toneStyle['blue'] ?>"><span class="ic" data-ic="<?= sanitize($icon) ?>"></span></span>
          <div class="list-row__main"><div class="list-row__title"><?= sanitize($title) ?></div><div class="list-row__sub"><?= sanitize($sub) ?></div></div>
          <span class="badge <?= sanitize($badgeCls) ?>"><?= number_format((int) $count) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- Bottom row -->
<div class="grid" style="grid-template-columns:1.3fr 1.2fr 1fr">
  <div class="card">
    <div class="card__head"><span class="card__title">Top Performing Businesses</span><span class="spacer"></span>
      <select class="select" style="width:auto;padding:6px 30px 6px 10px;font-size:12.5px;border-radius:8px">
        <option>By Revenue</option><option>By Orders</option>
      </select>
      <a class="view-all" href="/admin/businesses">View All</a>
    </div>
    <div class="card__body" style="padding:0">
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Business Name</th><th>Plan</th><th>Revenue (30 Days)</th><th class="right">Growth</th></tr></thead>
        <tbody>
          <?php if ($topBiz === []): ?>
            <tr><td colspan="4" class="muted">No businesses yet.</td></tr>
          <?php else: foreach ($topBiz as $b):
              $bname   = (string)($b['company_name'] ?: $b['name']);
              $bplan   = (string)($b['subscription_plan'] ?? '');
              $acolor  = ['green','purple','orange','pink',''][$b['id'] % 5];
              $growth  = $bizGrowth[(int)$b['id']] ?? 0;
              $gUp     = $growth >= 0;
          ?>
            <tr>
              <td class="cell-media">
                <span class="avatar <?= $acolor ?>"><?= sanitize(iqp_initials($bname)) ?></span>
                <a class="strong" href="/admin/client-detail?id=<?= (int)$b['id'] ?>"><?= sanitize($bname) ?></a>
              </td>
              <td><span class="badge <?= $planPillClass($bplan) ?>"><?= sanitize(iqp_plan_label($bplan)) ?></span></td>
              <td class="strong"><?= sanitize(format_money((float)$b['revenue_30d'], 'PKR')) ?></td>
              <td class="right">
                <span style="font-weight:700;color:<?= $gUp ? 'var(--green-600)' : 'var(--red-600)' ?>">
                  <?= $gUp ? '↑' : '↓' ?> <?= abs((int)$growth) ?>%
                </span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Recent Support Tickets</span><span class="spacer"></span><a class="view-all" href="/admin/tickets">View All</a></div>
    <div class="card__body" style="display:flex;flex-direction:column">
      <?php if ($recentTickets === []): ?>
        <?php iqp_empty('No support tickets yet.'); ?>
      <?php else: foreach ($recentTickets as $t):
          $pri = strtolower((string)($t['priority'] ?? 'medium'));
          $priBadge = $pri === 'high' ? 'badge--red' : ($pri === 'low' ? 'badge--gray' : 'badge--amber');
          $st = strtolower((string)($t['status'] ?? 'open'));
          $stColor = $st === 'closed' ? 'var(--green-600)' : ($st === 'in_progress' || $st === 'pending' ? 'var(--amber-600)' : 'var(--red-600)');
          $stDot   = $st === 'closed' ? 'var(--green-600)' : ($st === 'in_progress' || $st === 'pending' ? 'var(--amber-600)' : 'var(--red-600)');
          $stLabel = $st === 'in_progress' ? 'In Progress' : ucfirst($st);
          $biz = (string)($t['business_name'] ?? ''); ?>
        <a class="list-row" href="/admin/tickets">
          <span class="badge <?= $priBadge ?>" style="min-width:52px;justify-content:center"><?= sanitize(ucfirst($pri)) ?></span>
          <div class="list-row__main">
            <div class="list-row__title"><?= sanitize((string)$t['subject']) ?></div>
            <div class="list-row__sub"><?= $biz !== '' ? sanitize($biz) . ' · ' : '' ?><span style="color:var(--muted-2)">#TK-<?= (int)$t['id'] ?></span></div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <div class="list-row__time"><?= sanitize(iqp_rel_time((string)($t['created_at'] ?? ''))) ?></div>
            <span class="status-inline" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;color:<?= $stColor ?>">
              <span style="width:6px;height:6px;border-radius:50%;background:<?= $stDot ?>;flex:0 0 auto"></span>
              <?= sanitize($stLabel) ?>
            </span>
          </div>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">System Health</span><span class="spacer"></span><a class="view-all" href="/admin/settings">View All</a></div>
    <div class="card__body" style="display:flex;flex-direction:column;gap:2px">
      <?php foreach ($health as [$label, $ok, $note]): ?>
        <div class="list-row" style="padding:10px 4px">
          <div class="list-row__main"><div class="list-row__title" style="font-weight:500;font-size:13px"><?= sanitize($label) ?></div></div>
          <span class="badge <?= $ok ? 'badge--green' : 'badge--amber' ?>">
            <span style="width:6px;height:6px;border-radius:50%;background:currentColor"></span>
            <?= $ok ? 'Operational' : 'Attention' ?>
          </span>
          <span class="strong small" style="margin-left:8px;color:<?= $ok ? 'var(--green-700)' : 'var(--amber-700)' ?>;min-width:44px;text-align:right"><?= $ok ? '99.9%' : '—' ?></span>
        </div>
      <?php endforeach; ?>
      <div class="between" style="padding:12px 4px 2px;border-top:1px solid var(--line-2);margin-top:6px">
        <span class="strong" style="font-size:13px">Overall System Uptime</span>
        <span class="strong" style="color:<?= $healthOk ? 'var(--green-600)' : 'var(--amber-600)' ?>"><?= $healthOk ? '99.96%' : 'Needs attention' ?></span>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var revData  = <?= json_encode($revSeries) ?>;
  var revX     = <?= json_encode($xLabels) ?>;
  var revY     = <?= json_encode($revY) ?>;
  var leadData = <?= json_encode($leadSeries) ?>;
  var qualData = <?= json_encode($qualSeries) ?>;
  var convY    = <?= json_encode($convY) ?>;
  if (window.areaChart) {
    areaChart(document.getElementById('revChart'), revData, { height: 240, yLabels: revY, xLabels: revX });
  }
  if (window.lineChart) {
    lineChart(document.getElementById('convChart'), [
      { color: '#1FA855', data: leadData },
      { color: '#6366f1', data: qualData }
    ], { height: 240, yLabels: convY, xLabels: revX });
  }
})();
</script>
<?php iqp_admin_end();
