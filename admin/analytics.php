<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/industry-templates.php';

$user = require_admin();

// Optional table used by the conversations metric; ensure it exists on fresh installs.
if (function_exists('ensure_conversations_schema')) {
    try {
        ensure_conversations_schema();
    } catch (Throwable $e) {
        // Non-fatal: metric degrades to 0 below.
    }
}

/* ---------------------------------------------------------------------------
 * Small page-local presentation helpers (defined before use; not global UI).
 * ------------------------------------------------------------------------- */
if (!function_exists('iqp_money_compact')) {
    function iqp_money_compact(float $v, string $prefix = 'PKR '): string
    {
        if ($v >= 1000000) {
            return $prefix . rtrim(rtrim(number_format($v / 1000000, 2), '0'), '.') . 'M';
        }
        if ($v >= 1000) {
            return $prefix . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
        }
        return $prefix . number_format($v, 0);
    }
}
if (!function_exists('iqp_axis_money')) {
    function iqp_axis_money(float $v): string
    {
        if ($v >= 1000000) {
            return rtrim(rtrim(number_format($v / 1000000, 2), '0'), '.') . 'M';
        }
        if ($v >= 1000) {
            return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
        }
        return (string) (int) round($v);
    }
}
if (!function_exists('iqp_axis_labels')) {
    /**
     * Build top-to-bottom y-axis labels aligned to the chart's auto max (data*1.08).
     *
     * @return array<int, string>
     */
    function iqp_axis_labels(float $max, bool $money): array
    {
        if (!$money) {
            // Clean integer axis for small counts.
            $m = (int) ceil($max);
            if ($m <= 5) {
                $labels = [];
                for ($v = max($m, 1); $v >= 0; $v--) {
                    $labels[] = (string) $v;
                }
                return $labels;
            }
        }
        $top = $max > 0 ? $max * 1.08 : 1;
        $count = $money ? 6 : 5;
        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $v = $top * ($count - 1 - $i) / ($count - 1);
            $labels[] = $money ? iqp_axis_money($v) : (string) (int) round($v);
        }
        return $labels;
    }
}
if (!function_exists('iqp_trend_foot')) {
    function iqp_trend_foot(int $now, int $prev): string
    {
        if ($prev <= 0) {
            if ($now <= 0) {
                return '<span class="muted">No prior data</span>';
            }
            return '<span class="trend up"><span class="ic" data-ic="arrowup"></span> New</span><span class="muted">vs prev 30d</span>';
        }
        $pct = (int) round((($now - $prev) / $prev) * 100);
        $up = $pct >= 0;
        return '<span class="trend ' . ($up ? 'up' : 'down') . '"><span class="ic" data-ic="'
            . ($up ? 'arrowup' : 'arrowdown') . '"></span> ' . ($up ? '+' : '') . $pct
            . '%</span><span class="muted">vs prev 30d</span>';
    }
}

/* ---------------------------------------------------------------------------
 * Plans & prices (from platform settings) — used for MRR + plan labels.
 * ------------------------------------------------------------------------- */
$plans = function_exists('get_plans_merged')
    ? get_plans_merged()
    : (function_exists('get_plans') ? get_plans() : []);

$planName = [];        // slug => display name
$planPricePkr = [];    // slug => monthly PKR price (0 when custom/contact-only)
foreach ($plans as $slug => $p) {
    if (!is_string($slug) || !is_array($p)) {
        continue;
    }
    $planName[$slug] = (string) ($p['name'] ?? ucfirst($slug));
    $planPricePkr[$slug] = !empty($p['contact_only'])
        ? 0
        : (int) ($p['price_pkr'] ?? 0);
}

/* ---------------------------------------------------------------------------
 * Active businesses grouped by plan → MRR, active count.
 * ------------------------------------------------------------------------- */
$activeByPlan = [];
try {
    $rows = db_fetch_all(
        "SELECT LOWER(COALESCE(subscription_plan,'')) AS plan, COUNT(*) AS cnt
         FROM users WHERE role='client' AND subscription_status='active'
         GROUP BY LOWER(COALESCE(subscription_plan,''))"
    );
    foreach ($rows as $r) {
        $activeByPlan[(string) $r['plan']] = (int) $r['cnt'];
    }
} catch (Throwable $e) {
    $activeByPlan = [];
}

$mrr = 0.0;
foreach ($activeByPlan as $slug => $cnt) {
    $norm = function_exists('normalize_plan_slug') ? normalize_plan_slug($slug) : $slug;
    $price = $planPricePkr[$slug] ?? $planPricePkr[$norm] ?? 0; // guard missing/unknown plan
    $mrr += $cnt * $price;
}
$activeCount = array_sum($activeByPlan);

/* ---------------------------------------------------------------------------
 * All businesses (clients) — total + plan distribution for the donut.
 * ------------------------------------------------------------------------- */
$totalBiz = 0;
$bizByPlan = [];
try {
    $totalBiz = (int) (db_fetch("SELECT COUNT(*) AS c FROM users WHERE role='client'")['c'] ?? 0);
    $rows = db_fetch_all(
        "SELECT LOWER(COALESCE(NULLIF(subscription_plan,''),'(none)')) AS plan, COUNT(*) AS cnt
         FROM users WHERE role='client'
         GROUP BY LOWER(COALESCE(NULLIF(subscription_plan,''),'(none)'))
         ORDER BY cnt DESC"
    );
    foreach ($rows as $r) {
        $bizByPlan[(string) $r['plan']] = (int) $r['cnt'];
    }
} catch (Throwable $e) {
    $totalBiz = 0;
    $bizByPlan = [];
}

/* ---------------------------------------------------------------------------
 * New businesses (this 30d vs prior 30d) for the tile + trend.
 * ------------------------------------------------------------------------- */
$new30 = 0;
$newPrev30 = 0;
try {
    $r = db_fetch(
        "SELECT
            SUM(created_at >= (NOW() - INTERVAL 30 DAY)) AS cur,
            SUM(created_at >= (NOW() - INTERVAL 60 DAY) AND created_at < (NOW() - INTERVAL 30 DAY)) AS prev
         FROM users WHERE role='client'"
    );
    $new30 = (int) ($r['cur'] ?? 0);
    $newPrev30 = (int) ($r['prev'] ?? 0);
} catch (Throwable $e) {
    $new30 = 0;
    $newPrev30 = 0;
}

/* ---------------------------------------------------------------------------
 * Conversations total + 30d trend (optional table → try/catch).
 * ------------------------------------------------------------------------- */
$convTotal = 0;
$conv30 = 0;
$convPrev30 = 0;
$convHasData = false;
try {
    $r = db_fetch(
        "SELECT COUNT(*) AS total,
            SUM(created_at >= (NOW() - INTERVAL 30 DAY)) AS cur,
            SUM(created_at >= (NOW() - INTERVAL 60 DAY) AND created_at < (NOW() - INTERVAL 30 DAY)) AS prev
         FROM conversations"
    );
    $convTotal = (int) ($r['total'] ?? 0);
    $conv30 = (int) ($r['cur'] ?? 0);
    $convPrev30 = (int) ($r['prev'] ?? 0);
    $convHasData = true;
} catch (Throwable $e) {
    $convHasData = false;
}

/* ---------------------------------------------------------------------------
 * Leads by status → conversion rate (qualified + booked / total).
 * ------------------------------------------------------------------------- */
$leadsByStatus = [];
try {
    $rows = db_fetch_all("SELECT status, COUNT(*) AS cnt FROM leads GROUP BY status");
    foreach ($rows as $r) {
        $leadsByStatus[(string) $r['status']] = (int) $r['cnt'];
    }
} catch (Throwable $e) {
    $leadsByStatus = [];
}
$totalLeads = array_sum($leadsByStatus);
$converted = ($leadsByStatus['qualified'] ?? 0) + ($leadsByStatus['booked'] ?? 0);
$conversionRate = $totalLeads > 0 ? round($converted / $totalLeads * 100, 1) : 0.0;

/* ---------------------------------------------------------------------------
 * Monthly time-series (last 12 months) for the area chart.
 * Prefer real paid revenue (subscription_payments); fall back to new businesses.
 * ------------------------------------------------------------------------- */
$months = [];
$cursor = new DateTimeImmutable('first day of this month');
$cursor = $cursor->modify('-11 months');
for ($i = 0; $i < 12; $i++) {
    $months[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M')];
    $cursor = $cursor->modify('+1 month');
}
$since = $months[0]['key'] . '-01 00:00:00';
$monthKeys = array_column($months, 'key');

$revByMonth = array_fill_keys($monthKeys, 0.0);
$revMode = false;
try {
    $rows = db_fetch_all(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, SUM(amount) AS tot
         FROM subscription_payments
         WHERE status='paid' AND created_at >= ?
         GROUP BY DATE_FORMAT(created_at,'%Y-%m')",
        's',
        [$since]
    );
    foreach ($rows as $r) {
        $k = (string) $r['ym'];
        if (array_key_exists($k, $revByMonth)) {
            $revByMonth[$k] = (float) $r['tot'];
        }
    }
    $revMode = array_sum($revByMonth) > 0;
} catch (Throwable $e) {
    $revMode = false;
}

$newByMonth = array_fill_keys($monthKeys, 0);
try {
    $rows = db_fetch_all(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS c
         FROM users WHERE role='client' AND created_at >= ?
         GROUP BY DATE_FORMAT(created_at,'%Y-%m')",
        's',
        [$since]
    );
    foreach ($rows as $r) {
        $k = (string) $r['ym'];
        if (array_key_exists($k, $newByMonth)) {
            $newByMonth[$k] = (int) $r['c'];
        }
    }
} catch (Throwable $e) {
    // leave zeros
}

if ($revMode) {
    $areaSeries = array_map('floatval', array_values($revByMonth));
    $areaTitle = 'Revenue Growth';
    $areaSub = 'Monthly paid subscription revenue (PKR)';
} else {
    $areaSeries = array_map('intval', array_values($newByMonth));
    $areaTitle = 'Business Growth';
    $areaSub = 'New businesses added per month';
}
$areaX = array_column($months, 'label');
$areaY = iqp_axis_labels((float) (max($areaSeries) ?: 0), $revMode);

/* ---------------------------------------------------------------------------
 * Donut: businesses by plan (real distribution).
 * ------------------------------------------------------------------------- */
$palette = ['#1FA855', '#6366f1', '#f59e0b', '#ef4444', '#06b6d4', '#94a3b8'];
$planColorMap = [
    'starter'    => '#1FA855',
    'pro'        => '#6366f1',
    'growth'     => '#6366f1',
    'enterprise' => '#f59e0b',
    'agency'     => '#ef4444',
];
$donutSegs = [];
$donutLegend = [];
$ci = 0;
foreach ($bizByPlan as $slug => $cnt) {
    if ($cnt <= 0) {
        continue;
    }
    $color = $planColorMap[$slug] ?? $palette[$ci % count($palette)];
    $ci++;
    $label = $planName[$slug]
        ?? ($slug === '(none)'
            ? 'No plan'
            : (function_exists('iqp_plan_label') ? iqp_plan_label($slug) : ucfirst($slug)));
    $pct = $totalBiz > 0 ? (int) round($cnt / $totalBiz * 100) : 0;
    $donutSegs[] = ['value' => $cnt, 'color' => $color];
    $donutLegend[] = ['label' => $label, 'color' => $color, 'cnt' => $cnt, 'pct' => $pct];
}

/* ---------------------------------------------------------------------------
 * Top Industries — real, from bots.industry_key (skip empty/default).
 * ------------------------------------------------------------------------- */
$topIndustries = [];
$industryTotal = 0;
try {
    $hasCol = !function_exists('db_column_exists') || db_column_exists('bots', 'industry_key');
    if ($hasCol) {
        $industryTotal = (int) (db_fetch(
            "SELECT COUNT(DISTINCT u.id) AS c
             FROM bots b JOIN users u ON u.id = b.user_id
             WHERE u.role='client' AND b.industry_key IS NOT NULL
               AND b.industry_key <> '' AND b.industry_key <> 'default'"
        )['c'] ?? 0);
        if ($industryTotal > 0) {
            $rows = db_fetch_all(
                "SELECT b.industry_key AS k, COUNT(DISTINCT u.id) AS cnt
                 FROM bots b JOIN users u ON u.id = b.user_id
                 WHERE u.role='client' AND b.industry_key IS NOT NULL
                   AND b.industry_key <> '' AND b.industry_key <> 'default'
                 GROUP BY b.industry_key ORDER BY cnt DESC LIMIT 6"
            );
            foreach ($rows as $r) {
                $key = (string) $r['k'];
                $label = ucfirst(str_replace('_', ' ', $key));
                if (function_exists('industry_template')) {
                    $tpl = industry_template($key);
                    if ($tpl && !empty($tpl['label'])) {
                        $label = (string) $tpl['label'];
                    }
                }
                $cnt = (int) $r['cnt'];
                $topIndustries[] = [
                    'label' => $label,
                    'cnt'   => $cnt,
                    'pct'   => (int) round($cnt / $industryTotal * 100),
                ];
            }
        }
    }
} catch (Throwable $e) {
    $topIndustries = [];
}

/* ---------------------------------------------------------------------------
 * Key metrics: ARPU / LTV (approximation) / CAC (unavailable) / Active-Total.
 * ------------------------------------------------------------------------- */
$arpu = $activeCount > 0 ? $mrr / $activeCount : null;      // PKR / active business
$ltvMultiple = 12;                                          // assumed ~12-month avg lifespan
$ltv = $arpu !== null ? $arpu * $ltvMultiple : null;
$activeTotalPct = $totalBiz > 0 ? round($activeCount / $totalBiz * 100, 1) : null;

$donutCenterTop = $totalBiz >= 1000 ? iqp_axis_money((float) $totalBiz) : number_format($totalBiz);

iqp_admin_begin($user, 'analytics', [
    'title'    => 'Analytics & Reports',
    'subtitle' => 'Platform performance metrics and business breakdowns',
    'actions'  => '<div class="iqp-page-actions-row"><button type="button" class="btn btn--ghost btn--sm"><span class="ic" data-ic="calendar"></span> All time <span class="ic" data-ic="chevdown"></span></button>'
        . '<button type="button" class="btn btn--primary btn--sm"><span class="ic" data-ic="download"></span> Export</button></div>',
]);
?>
<div class="stat-grid cols-4">
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile green"><span class="ic" data-ic="dollar"></span></span>
      <div class="grow">
        <div class="stat-card__label">MRR</div>
        <div class="stat-card__value"><?= sanitize(iqp_money_compact($mrr)) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= number_format($activeCount) ?> active paying</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile blue"><span class="ic" data-ic="chats"></span></span>
      <div class="grow">
        <div class="stat-card__label">Conversations</div>
        <div class="stat-card__value"><?= number_format($convTotal) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><?= $convHasData ? iqp_trend_foot($conv30, $convPrev30) : '<span class="muted">All-time total</span>' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile purple"><span class="ic" data-ic="building"></span></span>
      <div class="grow">
        <div class="stat-card__label">New Businesses</div>
        <div class="stat-card__value"><?= number_format($new30) ?></div>
      </div>
    </div>
    <div class="stat-card__foot"><?= iqp_trend_foot($new30, $newPrev30) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile amber"><span class="ic" data-ic="trending"></span></span>
      <div class="grow">
        <div class="stat-card__label">Conversion Rate</div>
        <div class="stat-card__value"><?= number_format($conversionRate, 1) ?>%</div>
      </div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= number_format($converted) ?> of <?= number_format($totalLeads) ?> leads</span></div>
  </div>
</div>

<div class="grid grid-2 mb-3">
  <div class="card">
    <div class="card__head"><span class="card__title"><?= sanitize($areaTitle) ?></span><span class="spacer"></span><span class="muted small"><?= sanitize($areaSub) ?></span></div>
    <div class="card__body tight"><div id="revChart" style="height:230px"></div></div>
  </div>
  <div class="card">
    <div class="card__head"><span class="card__title">Businesses by Plan</span></div>
    <div class="card__body" style="display:flex;gap:24px;align-items:center;justify-content:center;min-height:200px">
      <?php if ($donutSegs === []): ?>
        <div class="muted small">No businesses yet.</div>
      <?php else: ?>
        <div id="planDonut"></div>
        <div class="legend" style="flex-direction:column;gap:12px">
          <?php foreach ($donutLegend as $l): ?>
          <span class="li"><span class="sw" style="background:<?= sanitize($l['color']) ?>"></span><?= sanitize($l['label']) ?> — <?= (int) $l['cnt'] ?> (<?= (int) $l['pct'] ?>%)</span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-3">
  <div class="card">
    <div class="card__head"><span class="card__title">Top Industries</span></div>
    <div class="card__body stack" style="gap:14px">
      <?php if ($topIndustries === []): ?>
        <div class="muted small">No industry data yet. Businesses set their industry during bot setup.</div>
      <?php else: foreach ($topIndustries as $ind): ?>
      <div>
        <div class="between mb-1"><span class="strong"><?= sanitize($ind['label']) ?></span><span class="muted"><?= (int) $ind['pct'] ?>%</span></div>
        <div class="bar"><span style="width:<?= (int) $ind['pct'] ?>%"></span></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Saved Reports</span></div>
    <div class="card__body">
      <div class="list-row">
        <span class="mini-tile" style="background:var(--blue-50);color:var(--blue-600)"><span class="ic" data-ic="filetext"></span></span>
        <div class="list-row__main">
          <div class="list-row__title" style="font-size:13.5px">No saved reports yet</div>
          <div class="list-row__sub">Scheduled report exports aren't available yet.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Key Metrics</span></div>
    <div class="card__body stack" style="gap:12px">
      <div class="between"><span class="muted">ARPU</span><span class="strong"><?= $arpu !== null ? sanitize(format_plan_price((int) round($arpu), 'PKR')) : '—' ?></span></div>
      <div class="between"><span class="muted">LTV</span><span class="strong"><?= $ltv !== null ? sanitize(format_plan_price((int) round($ltv), 'PKR')) : '—' ?></span></div>
      <div class="between"><span class="muted">CAC</span><span class="strong">—</span></div>
      <div class="between"><span class="muted">Net Retention</span><span class="strong">—</span></div>
      <div class="between"><span class="muted">Active / Total</span><span class="strong"<?= $activeTotalPct !== null ? ' style="color:var(--green-600)"' : '' ?>><?= $activeTotalPct !== null ? number_format($activeTotalPct, 1) . '%' : '—' ?></span></div>
      <div class="divider"></div>
      <div class="muted small">ARPU = MRR ÷ active businesses. LTV ≈ ARPU × <?= (int) $ltvMultiple ?> (assumed ~<?= (int) $ltvMultiple ?>-month lifespan). CAC & net retention not tracked yet.</div>
    </div>
  </div>
</div>

<script>
(function () {
  var rev = document.getElementById('revChart');
  if (window.areaChart && rev) {
    areaChart(rev, <?= json_encode($areaSeries) ?>, {
      height: 230,
      yLabels: <?= json_encode($areaY) ?>,
      xLabels: <?= json_encode($areaX) ?>
    });
  }
<?php if ($donutSegs !== []): ?>
  var dn = document.getElementById('planDonut');
  if (window.donutChart && dn) {
    donutChart(dn, <?= json_encode($donutSegs) ?>, {
      size: 180, stroke: 24,
      centerTop: <?= json_encode($donutCenterTop) ?>,
      centerBottom: 'Businesses'
    });
  }
<?php endif; ?>
})();
</script>
<?php
iqp_admin_end();
