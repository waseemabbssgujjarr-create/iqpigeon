<?php
/** @var array $user */
/** @var array $bots */
/** @var int $botId */
/** @var array $stats */
/** @var array $topProducts */
/** @var array $revenueDays */
/** @var string $currency */

require_once __DIR__ . '/../catalog.php';

$updates = 0;
try {
    $updates = (int) (db_fetch('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0', 'i', [(int) $user['id']])['cnt'] ?? 0);
} catch (Throwable $e) {
    $updates = 0;
}

$kpis = [
    [
        'Leads',
        (string) ($stats['leads_total'] ?? 0),
        ($stats['leads_week'] ?? 0) . ' this week',
        '#DBEAFE',
        '#2563EB',
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>',
    ],
    [
        'Qualified',
        (string) ($stats['leads_qualified'] ?? 0),
        ($stats['qualify_rate'] ?? 0) . '% rate',
        '#EDE9FE',
        '#7C3AED',
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    ],
    [
        'Booked',
        (string) ($stats['leads_booked'] ?? 0),
        ($stats['book_rate'] ?? 0) . '% rate',
        '#DCFCE7',
        '#16A34A',
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
    ],
    [
        'Orders',
        (string) ($stats['orders_total'] ?? 0),
        ($stats['orders_month'] ?? 0) . ' this month',
        '#FFEDD5',
        '#EA580C',
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>',
    ],
    [
        'Revenue',
        catalog_format_price((float) ($stats['revenue_total'] ?? 0), $currency),
        ($stats['order_rate'] ?? 0) . '% lead→order',
        '#F3E8FF',
        '#7C3AED',
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12h6M12 9v6"/></svg>',
    ],
    [
        'Upcoming calls',
        (string) ($stats['appointments_upcoming'] ?? 0),
        ($stats['appointments_total'] ?? 0) . ' total booked',
        '#E0F2FE',
        '#0284C7',
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    ],
];

$pipeline = [
    ['New', (int) ($stats['orders_new'] ?? 0), '#EDE9FE', '#6D28D9'],
    ['Confirmed', (int) ($stats['orders_confirmed'] ?? 0), '#FEF3C7', '#B45309'],
    ['Shipped', (int) ($stats['orders_shipped'] ?? 0), '#DBEAFE', '#1D4ED8'],
    ['Delivered', (int) ($stats['orders_delivered'] ?? 0), '#DCFCE7', '#15803D'],
];

$growth = [
    ['Products in catalog', (int) ($stats['products'] ?? 0), '#FFF7ED', '#EA580C', 'bag'],
    ['Broadcast campaigns', (int) ($stats['broadcasts'] ?? 0), '#FCE7F3', '#DB2777', 'send'],
    ['Messages sent', (int) ($stats['broadcasts_sent'] ?? 0), '#ECFEFF', '#0891B2', 'mail'],
];

iqp_user_begin($user, 'analytics', ['title' => 'Analytics', 'updates' => $updates]);
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[20px] lg:text-[22px] font-bold text-slate-800">Analytics</h1>
    <p class="text-[13px] text-slate-500 mt-0.5">Performance overview for your sales pipeline and growth.</p>
  </div>
  <form method="get" class="flex items-center gap-2 shrink-0">
    <label for="analytics-bot" class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Bot</label>
    <select id="analytics-bot" name="bot_id" onchange="this.form.submit()"
      class="border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] text-slate-600 bg-white min-w-[140px] focus:outline-none focus:border-[#1FA855]">
      <option value="0">All bots</option>
      <?php foreach ($bots as $b): ?>
      <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === $botId ? ' selected' : '' ?>><?= sanitize((string) $b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- KPI tiles -->
<?php iqp_stat_cards($kpis); ?>

<!-- Pipeline + Growth -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="iqp-card-head px-4 sm:px-5 py-3.5 border-b border-slate-100 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#FFEDD5;color:#EA580C">
          <?= iqp_icon_svg('box', '#EA580C') ?>
        </div>
        <div class="text-[15px] font-bold text-slate-800 truncate">Order pipeline</div>
      </div>
      <a href="/client/orders" class="text-[12px] text-[#1FA855] font-medium shrink-0 whitespace-nowrap">Open kanban →</a>
    </div>
    <div class="iqp-card-body p-4 sm:p-5 space-y-2.5">
      <?php foreach ($pipeline as [$label, $count, $bg, $color]): ?>
      <div class="flex items-center justify-between gap-3 py-2 border-b border-slate-50 last:border-0">
        <div class="flex items-center gap-2.5 min-w-0">
          <span class="w-2 h-2 rounded-full shrink-0" style="background:<?= $color ?>"></span>
          <span class="text-[13px] text-slate-700"><?= sanitize($label) ?></span>
        </div>
        <span class="text-[13px] font-bold px-2.5 py-0.5 rounded-full" style="background:<?= $bg ?>;color:<?= $color ?>"><?= $count ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="iqp-card-head px-4 sm:px-5 py-3.5 border-b border-slate-100 flex items-center gap-2.5">
      <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#FCE7F3;color:#DB2777">
        <?= iqp_icon_svg('send', '#DB2777') ?>
      </div>
      <div class="text-[15px] font-bold text-slate-800">Growth tools</div>
    </div>
    <div class="iqp-card-body p-4 sm:p-5 space-y-2.5">
      <?php foreach ($growth as [$label, $count, $bg, $color, $icon]): ?>
      <div class="flex items-center justify-between gap-3 py-2 border-b border-slate-50 last:border-0">
        <div class="flex items-center gap-2.5 min-w-0">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:<?= $bg ?>;color:<?= $color ?>">
            <?= iqp_icon_svg($icon, $color) ?>
          </span>
          <span class="text-[13px] text-slate-700"><?= sanitize($label) ?></span>
        </div>
        <span class="text-[14px] font-bold text-slate-800"><?= number_format($count) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- Top products + Revenue trend -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="iqp-card-head px-4 sm:px-5 py-3.5 border-b border-slate-100 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#DCFCE7;color:#16A34A">
          <?= iqp_icon_svg('bag', '#16A34A') ?>
        </div>
        <div class="text-[15px] font-bold text-slate-800 truncate">Top products</div>
      </div>
      <a href="/client/catalog" class="text-[12px] text-[#1FA855] font-medium shrink-0 whitespace-nowrap">View shop →</a>
    </div>
    <?php if ($topProducts === []): ?>
      <div class="text-[12px] text-slate-400 py-10 text-center">No product sales yet.</div>
    <?php else: ?>
      <div class="divide-y divide-slate-50">
        <?php foreach ($topProducts as $p): ?>
        <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3">
          <div class="min-w-0 flex-1">
            <div class="text-[13px] font-semibold text-slate-800 truncate"><?= sanitize((string) $p['product_name']) ?></div>
            <div class="text-[11px] text-slate-400 mt-0.5"><?= (int) $p['qty'] ?> sold</div>
          </div>
          <div class="text-[13px] font-bold text-[#1FA855] shrink-0"><?= sanitize(catalog_format_price((float) $p['revenue'], $currency)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="iqp-card-head px-4 sm:px-5 py-3.5 border-b border-slate-100 flex items-center gap-2.5">
      <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#E0F2FE;color:#0284C7">
        <?= iqp_icon_svg('chart', '#0284C7') ?>
      </div>
      <div class="text-[15px] font-bold text-slate-800">Orders (last 14 days)</div>
    </div>
    <?php if ($revenueDays === []): ?>
      <div class="text-[12px] text-slate-400 py-10 text-center">No orders in the last 14 days.</div>
    <?php else: ?>
      <div class="divide-y divide-slate-50 max-h-[280px] overflow-y-auto">
        <?php foreach ($revenueDays as $day): ?>
        <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3">
          <div class="text-[13px] font-medium text-slate-700"><?= sanitize(date('M j', strtotime((string) $day['day']))) ?></div>
          <div class="text-[12px] text-slate-500 shrink-0">
            <span class="font-semibold text-slate-700"><?= (int) $day['orders'] ?></span> orders
            <span class="text-slate-300 mx-1">·</span>
            <span class="font-semibold text-[#1FA855]"><?= sanitize(catalog_format_price((float) $day['revenue'], $currency)) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Quick links -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
  <?php
  $quickLinks = [
      ['Leads', '/client/leads', '#0891B2', 'user'],
      ['Orders', '/client/orders', '#EA580C', 'box'],
      ['Shop', '/client/catalog', '#16A34A', 'bag'],
      ['Integrations', '/client/integrations', '#EA580C', 'plug'],
  ];
  foreach ($quickLinks as [$label, $href, $color, $icon]):
  ?>
  <a href="<?= sanitize($href) ?>" class="bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-3 hover:border-[#1FA855]/40 transition-colors">
    <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background:<?= $color ?>18;color:<?= $color ?>">
      <?= iqp_icon_svg($icon, $color) ?>
    </span>
    <span class="text-[13px] font-semibold text-slate-700"><?= sanitize($label) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php
iqp_user_end();
