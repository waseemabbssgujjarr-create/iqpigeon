<?php
/** @var array $user */
/** @var array $bots */
/** @var int $botId */
/** @var string $message */
/** @var array $columns */
/** @var array $pipeline */
/** @var array $columnMeta */
/** @var array $statusOrder */
/** @var string $industryKey */
/** @var array $shipmentMap */
/** @var array $courierPresets */
/** @var array $orderStatusEvents */

$tab        = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'all')) ?: 'all';
$q          = trim((string)($_GET['q'] ?? ''));
$sortOrder  = trim((string)($_GET['sort'] ?? 'newest'));
$statusFilt = trim((string)($_GET['filter_status'] ?? ''));
$selectedId = (int)($_GET['order_id'] ?? 0);

// Flatten all orders
$flat = [];
foreach ($columns as $st => $list) {
    foreach ($list as $o) {
        $o['_status'] = (string)$st;
        $flat[] = $o;
    }
}
usort($flat, static fn($a,$b) =>
    strtotime((string)($b['created_at']??'')) <=> strtotime((string)($a['created_at']??''))
);

$styleMap = [
    'new'             => ['New Order',        '#EDE9FE','#6D28D9'],
    'confirmed'       => ['Preparing',        '#FEF3C7','#B45309'],
    'preparing'       => ['Preparing',        '#FEF3C7','#B45309'],
    'shipped'         => ['Out for Delivery', '#DBEAFE','#1D4ED8'],
    'out_for_delivery'=> ['Out for Delivery', '#DBEAFE','#1D4ED8'],
    'delivered'       => ['Delivered',        '#DCFCE7','#15803D'],
    'cancelled'       => ['Cancelled',        '#FEE2E2','#B91C1C'],
    'canceled'        => ['Cancelled',        '#FEE2E2','#B91C1C'],
];

$orderStatusPill = static function (string $st) use ($columnMeta, $styleMap): array {
    $colors = $styleMap[$st] ?? ['', '#F1F5F9', '#475569'];
    $label = (string) ($columnMeta[$st]['label'] ?? ($colors[0] !== '' ? $colors[0] : ucfirst($st)));
    return [$label, $colors[1], $colors[2]];
};

// KPI icons per status position
$kpiIcons = [
    '<path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',                             // New Orders / bag
    '<path d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7"/><path d="M3 7h18"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',  // Preparing / hat
    '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',                                          // Out for delivery / scooter
    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',         // Delivered / check
    '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 7h6M9 11h6M9 15h4"/>',                // Total / clipboard
];
$kpiColors = [
    ['#EDE9FE','#7C3AED'],
    ['#FFEDD5','#EA580C'],
    ['#DBEAFE','#2563EB'],
    ['#DCFCE7','#16A34A'],
    ['#F1F5F9','#475569'],
];

$counts = [];
foreach ($statusOrder as $st) {
    $counts[$st] = count($columns[$st] ?? []);
}
$totalN = count($flat);

$tabs = [['all','All Orders']];
foreach ($statusOrder as $st) {
    $meta = $columnMeta[$st] ?? ['label' => ucfirst($st)];
    $tabs[] = [$st, (string)$meta['label']];
}
$tabs[] = ['cancelled','Cancelled'];

// Build filtered list
$filtered = [];
foreach ($flat as $o) {
    if ($tab !== 'all' && ($o['_status']??'') !== $tab) continue;
    if ($statusFilt !== '' && ($o['_status']??'') !== $statusFilt) continue;
    if ($q !== '') {
        $hay = ($o['customer_name']??'').' '.($o['customer_phone']??'').' #'.($o['id']??'');
        if (stripos($hay,$q)===false) continue;
    }
    $filtered[] = $o;
}
if ($sortOrder === 'oldest') {
    usort($filtered, fn($a,$b) => strtotime((string)($a['created_at']??'')) <=> strtotime((string)($b['created_at']??'')));
}

// Pagination
$perPage    = 7;
$page       = max(1,(int)($_GET['page']??1));
$totalFilt  = count($filtered);
$totalPages = max(1,(int)ceil($totalFilt/$perPage));
$page       = min($page,$totalPages);
$paged      = array_slice($filtered,($page-1)*$perPage,$perPage);

if ($selectedId <= 0 && $filtered !== []) {
    $selectedId = (int)$filtered[0]['id'];
}
$selected = null;
foreach ($flat as $o) {
    if ((int)$o['id'] === $selectedId) { $selected = $o; break; }
}
$selectedItems = $selected ? catalog_order_items((int)$selected['id']) : [];

// Order timeline from pre-fetched events
$timelineEvents = [];
if (!empty($orderStatusEvents[$selectedId])) {
    $timelineEvents = $orderStatusEvents[$selectedId];
}

$orderTimelineSteps = [];
if ($selected) {
    $currentSt = (string) ($selected['_status'] ?? 'new');
    $currentIdx = array_search($currentSt, $statusOrder, true);
    if ($currentIdx === false) {
        $currentIdx = 0;
    }
    $eventTimeByStatus = [];
    foreach ($timelineEvents as $ev) {
        $st = (string) ($ev['status'] ?? '');
        if ($st !== '') {
            $eventTimeByStatus[$st] = (string) ($ev['created_at'] ?? '');
        }
    }
    foreach ($statusOrder as $i => $stOpt) {
        if ($i > $currentIdx) {
            break;
        }
        $meta = $columnMeta[$stOpt] ?? ['label' => ucfirst($stOpt)];
        $orderTimelineSteps[] = [
            'status'     => $stOpt,
            'label'      => (string) $meta['label'],
            'created_at' => $eventTimeByStatus[$stOpt] ?? '',
            'is_current' => ($stOpt === $currentSt),
        ];
    }
}

$csrf = csrf_token();
$storeName = trim((string) ($user['company_name'] ?? '')) ?: 'Your business';
$pipelineLabel = (string) ($pipeline['industry_label'] ?? 'General');
$pipelineSubtitle = (string) ($pipeline['page_subtitle'] ?? 'Manage and track all customer orders in one place.');
$stageLabels = [];
foreach (array_slice($statusOrder, 0, 4) as $st) {
    $stageLabels[] = (string) (($columnMeta[$st]['label'] ?? ucfirst($st)));
}
iqp_user_begin($user, 'orders', ['title' => 'Orders']);
if ($message !== '') iqp_flash($message);
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[20px] lg:text-[22px] font-bold text-slate-800">Orders</h1>
    <p class="text-[13px] text-slate-500 mt-0.5"><?= sanitize($pipelineSubtitle) ?></p>
    <?php if ($stageLabels !== []): ?>
    <p class="text-[11.5px] text-slate-400 mt-1">
      Stages for <span class="font-medium text-slate-500"><?= sanitize($pipelineLabel) ?></span>:
      <?= sanitize(implode(' → ', $stageLabels)) ?>
      <?php if ($pipelineLabel !== 'All industries'): ?>
      · synced from Training → Industry
      <?php endif; ?>
    </p>
    <?php endif; ?>
  </div>
  <div class="flex gap-2 flex-wrap">
    <?php if (count($bots) > 1): ?>
    <form method="get" class="flex flex-col gap-1">
      <?php if ($q): ?><input type="hidden" name="q" value="<?= sanitize($q) ?>"/><?php endif; ?>
      <label class="text-[10px] uppercase tracking-wide text-slate-400 font-semibold px-0.5">Assistant</label>
      <select name="bot_id" onchange="this.form.submit()"
        class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-600 bg-white min-w-[180px]">
        <?php foreach ($bots as $b):
          $bKey = trim((string) ($b['industry_key'] ?? ''));
          $bTpl = $bKey !== '' ? industry_template($bKey) : null;
          $bIndustry = is_array($bTpl) ? (string) ($bTpl['label'] ?? '') : '';
        ?>
        <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id']===$botId?' selected':'' ?>>
          <?= sanitize($b['name']) ?><?= $bIndustry !== '' ? ' · ' . sanitize($bIndustry) : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php elseif (count($bots) === 1): ?>
    <div class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-600 bg-slate-50">
      <?= sanitize($bots[0]['name'] ?? 'Assistant') ?>
      <?php
      $onlyKey = trim((string) ($bots[0]['industry_key'] ?? ''));
      $onlyTpl = $onlyKey !== '' ? industry_template($onlyKey) : null;
      if (is_array($onlyTpl) && ($onlyTpl['label'] ?? '') !== ''):
      ?>
      <span class="text-slate-400">· <?= sanitize((string) $onlyTpl['label']) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <a href="/client/orders?export=csv<?= $botId?'&bot_id='.$botId:'' ?>"
      class="flex items-center gap-1.5 border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600 hover:bg-slate-50">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export
    </a>
    <a href="/client/orders?<?= $botId?'bot_id='.$botId.'&':'' ?>settings=1"
      class="flex items-center gap-1.5 bg-[#1FA855] text-white rounded-lg px-3 py-2 text-[12px] font-semibold hover:bg-[#18934a]">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
      Settings
    </a>
  </div>
</div>

<!-- KPI Tiles -->
<?php
$orderStatCards = [];
foreach (array_slice($statusOrder, 0, 4) as $i => $st) {
    $meta = $columnMeta[$st] ?? ['label' => ucfirst($st)];
    $kc = $kpiColors[$i] ?? $kpiColors[4];
    $tabSt = array_slice($statusOrder, 0, 4)[$i] ?? 'all';
    $orderStatCards[] = [
        'label' => (string) $meta['label'],
        'value' => (string) ($counts[$st] ?? 0),
        'sub_html' => '<a href="/client/orders?tab=' . urlencode($tabSt) . ($botId ? '&bot_id=' . $botId : '') . '" class="text-[#1FA855] font-medium">View all</a>',
        'icon_bg' => $kc[0],
        'icon_color' => $kc[1],
        'icon_svg' => $kpiIcons[$i] ?? $kpiIcons[4],
    ];
}
$kc = $kpiColors[4];
$orderStatCards[] = [
    'label' => 'Total Orders',
    'value' => (string) $totalN,
    'sub' => 'This Week',
    'icon_bg' => $kc[0],
    'icon_color' => $kc[1],
    'icon_svg' => $kpiIcons[4],
];
iqp_stat_cards($orderStatCards, ['class' => 'iqp-orders-kpi-grid']);
?>

<!-- Main: table + detail panel -->
<div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-5">

  <!-- Orders Table -->
  <div class="bg-white rounded-xl border border-slate-200 p-5">
    <!-- Tab navigation -->
    <?php
    $orderNavTabs = [];
    foreach ($tabs as [$tid, $tlabel]) {
        $orderNavTabs[] = [
            'id' => $tid,
            'label' => $tlabel,
            'href' => '/client/orders?tab=' . urlencode($tid) . ($botId ? '&bot_id=' . $botId : '') . ($q !== '' ? '&q=' . urlencode($q) : ''),
        ];
    }
    iqp_tab_nav($tab, $orderNavTabs, ['variant' => 'user', 'class' => 'mb-4', 'select_label' => 'Order status']);
    ?>

    <!-- Filters -->
    <details class="iqp-filter-drawer lg:open mb-4">
      <summary class="iqp-filter-drawer__toggle lg:hidden">
        <span class="iqp-filter-drawer__toggle-main">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
          Search &amp; filters
        </span>
        <span class="iqp-filter-drawer__hint">Tap to refine orders</span>
      </summary>
    <form method="get" class="iqp-filter-drawer__body iqp-orders-filters flex flex-wrap gap-2">
      <input type="hidden" name="tab" value="<?= sanitize($tab) ?>"/>
      <?php if ($botId): ?><input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/><?php endif; ?>
      <div class="iqp-search-wrap flex-1 min-w-[160px]">
        <span class="iqp-search-icon" aria-hidden="true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </span>
        <input name="q" value="<?= sanitize($q) ?>" class="iqp-search-input w-full border border-slate-200 rounded-lg pr-3 py-2 text-[12.5px] focus:outline-none focus:border-[#1FA855]" placeholder="Search orders..."/>
      </div>
      <select name="filter_status" onchange="this.form.submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-500 bg-white">
        <option value="">All Status</option>
        <?php foreach ($statusOrder as $stOpt):
            $slab = $columnMeta[$stOpt]['label'] ?? ucfirst($stOpt);
        ?>
        <option value="<?= sanitize($stOpt) ?>"<?= $statusFilt===$stOpt?' selected':'' ?>><?= sanitize($slab) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="flex items-center gap-1.5 border border-slate-200 rounded-lg px-2.5 py-2 text-[12px] text-slate-500 bg-white">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <span><?= date('M j',strtotime('-6 days')) ?> – <?= date('M j, Y') ?></span>
      </div>
      <select name="sort" onchange="this.form.submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-500 bg-white">
        <option value="newest"<?= $sortOrder==='newest'?' selected':'' ?>>Sort: Newest</option>
        <option value="oldest"<?= $sortOrder==='oldest'?' selected':'' ?>>Sort: Oldest</option>
      </select>
    </form>
    </details>

    <!-- Column headers -->
    <div class="hidden md:grid grid-cols-[90px_1.5fr_70px_90px_110px_60px_50px] gap-2 text-[11.5px] text-slate-400 font-medium pb-2 border-b border-slate-100">
      <span>Order ID</span><span>Customer</span><span>Items</span><span>Amount</span><span>Status</span><span>Time</span><span>Actions</span>
    </div>

    <!-- Rows -->
    <?php if ($paged === []): ?>
      <div class="text-[13px] text-slate-400 py-10 text-center">No orders found.</div>
    <?php else: foreach ($paged as $o):
        $st   = (string)($o['_status']??'new');
        $pill = $orderStatusPill($st);
        $items= catalog_order_items((int)$o['id']);
        $n    = count($items);
        $href = '/client/orders?order_id='.(int)$o['id'].'&tab='.urlencode($tab).($botId?'&bot_id='.$botId:'');
        $price= function_exists('catalog_format_price')
            ? catalog_format_price((float)($o['total_amount']??0),(string)($o['currency']??'PKR'))
            : 'PKR '.number_format((float)($o['total_amount']??0));
        $cn   = (string)($o['customer_name']?:'Customer');
        $ph   = (string)($o['customer_phone']??'');
        $ts   = iqp_rel_time((string)($o['created_at']??''));
        $isSelected = (int)$o['id']===$selectedId;
    ?>
    <div class="grid grid-cols-[1fr_auto] md:grid-cols-[90px_1.5fr_70px_90px_110px_60px_50px] gap-2 items-center py-3 border-b border-slate-50 last:border-0 <?= $isSelected?'bg-emerald-50/50 rounded-lg -mx-1 px-1':'' ?>">
      <a href="<?= sanitize($href) ?>" class="font-semibold text-[12.5px] text-slate-700 truncate">#ORD-<?= (int)$o['id'] ?></a>
      <a href="<?= sanitize($href) ?>" class="min-w-0">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 rounded-full text-white text-[10px] font-bold flex items-center justify-center shrink-0"
            style="background:#<?= substr(md5($cn),0,6) ?>">
            <?= sanitize(iqp_initials($cn)) ?>
          </div>
          <div class="min-w-0">
            <div class="text-[12.5px] font-medium text-slate-700 truncate flex items-center gap-1.5">
              <?= sanitize($cn) ?>
              <?php if ($ph): ?>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
              <?php endif; ?>
            </div>
            <div class="text-[10.5px] text-slate-400 truncate"><?= sanitize($ph) ?></div>
          </div>
        </div>
      </a>
      <span class="hidden md:block text-[12px] text-slate-500"><?= $n ?> <?= $n===1?'Item':'Items' ?></span>
      <span class="hidden md:block text-[12.5px] font-semibold text-slate-700"><?= sanitize($price) ?></span>
      <span class="text-[10.5px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap text-center w-fit"
        style="background:<?= $pill[1] ?>;color:<?= $pill[2] ?>"><?= sanitize($pill[0]) ?></span>
      <span class="hidden md:block text-[11px] text-slate-400 whitespace-nowrap"><?= sanitize($ts) ?></span>
      <div class="flex items-center gap-1.5">
        <a href="<?= sanitize($href) ?>" class="text-slate-400 hover:text-[#1FA855]" title="View">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </a>
        <button type="button" class="text-slate-400 hover:text-slate-600" title="More">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
      </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- Pagination + count -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4 pt-3 border-t border-slate-100">
      <div class="text-[12px] text-slate-500">
        Showing <?= $totalFilt>0?($page-1)*$perPage+1:0 ?> to <?= min($page*$perPage,$totalFilt) ?> of <?= $totalFilt ?> orders
      </div>
      <?php if ($totalPages > 1):
        $bh = '/client/orders?tab='.urlencode($tab).($botId?'&bot_id='.$botId:'').($q?'&q='.urlencode($q):'').($statusFilt?'&filter_status='.urlencode($statusFilt):'').($sortOrder!=='newest'?'&sort='.urlencode($sortOrder):'');
      ?>
      <div class="flex items-center gap-1">
        <?php if ($page>1): ?>
        <a href="<?= sanitize($bh.'&page='.($page-1)) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <?php else: ?>
        <span class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-100 text-slate-300">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
        </span>
        <?php endif;
        $ps = max(1,$page-2); $pe = min($totalPages,$page+2);
        if ($ps>1): ?><a href="<?= sanitize($bh.'&page=1') ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-[12px] text-slate-600 hover:bg-slate-50">1</a>
          <?php if ($ps>2): ?><span class="text-slate-400 text-[12px] px-1">…</span><?php endif; ?>
        <?php endif;
        for ($pg=$ps;$pg<=$pe;$pg++): ?>
        <a href="<?= sanitize($bh.'&page='.$pg) ?>"
          class="w-8 h-8 flex items-center justify-center rounded-lg border text-[12px]
          <?= $pg===$page?'bg-[#1FA855] border-[#1FA855] text-white font-semibold':'border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
          <?= $pg ?>
        </a>
        <?php endfor;
        if ($pe<$totalPages):
          if ($pe<$totalPages-1): ?><span class="text-slate-400 text-[12px] px-1">…</span><?php endif; ?>
          <a href="<?= sanitize($bh.'&page='.$totalPages) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-[12px] text-slate-600 hover:bg-slate-50"><?= $totalPages ?></a>
        <?php endif;
        if ($page<$totalPages): ?>
        <a href="<?= sanitize($bh.'&page='.($page+1)) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
        </a>
        <?php else: ?>
        <span class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-100 text-slate-300">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- WhatsApp Notification Composer -->
    <div class="mt-6 border-t border-slate-100 pt-5">
      <div class="flex items-center justify-between mb-3">
        <div>
          <div class="text-[13.5px] font-bold text-slate-800">WhatsApp Notification (When Order is Out for Delivery)</div>
          <div class="text-[11.5px] text-slate-400 mt-0.5">Send automated message to customers when their order is out for delivery.</div>
        </div>
        <label class="flex items-center gap-2 cursor-pointer shrink-0">
          <span class="text-[12px] text-slate-600 font-medium">Enable Notification</span>
          <button type="button" id="iqpNotifToggle" onclick="this.dataset.on=this.dataset.on==='1'?'0':'1';updateNotifToggle()"
            data-on="1"
            class="relative w-10 h-5 rounded-full transition-colors bg-[#1FA855]">
            <span id="iqpNotifDot" class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-all"></span>
          </button>
        </label>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Template editor -->
        <div>
          <div class="text-[11.5px] text-slate-500 font-medium mb-1.5">Message Template</div>
          <div class="flex flex-wrap gap-1.5 mb-2">
            <?php foreach (['{order_id}','{customer_name}','{store_name}','{delivery_time}','{tracking_link}'] as $v): ?>
            <button type="button" onclick="insertNotifVar('<?= $v ?>')"
              class="border border-slate-200 bg-slate-50 rounded-full px-2 py-0.5 text-[10.5px] text-slate-500 hover:bg-slate-100 font-mono">
              <?= sanitize($v) ?>
            </button>
            <?php endforeach; ?>
          </div>
          <textarea id="iqpNotifTemplate" rows="5"
            class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[12.5px] resize-none focus:outline-none focus:border-[#1FA855]"
            oninput="updateNotifPreview();document.getElementById('iqpNotifCount').textContent=this.value.length"
            placeholder="Type your notification message..."
>Great news! 🎉
Your order #{order_id} is out for delivery and will arrive soon.
Thank you for ordering with {store_name}.

If you need any help, reply to this message.</textarea>
          <div class="flex justify-between mt-1">
            <span></span>
            <span class="text-[10.5px] text-slate-400"><span id="iqpNotifCount">0</span>/500</span>
          </div>
        </div>
        <!-- Live preview -->
        <div>
          <div class="text-[11.5px] text-slate-500 font-medium mb-1.5">Preview</div>
          <div class="bg-[#E5DDD5] rounded-xl p-3 min-h-[130px]">
            <div class="flex justify-end">
              <div class="bg-white rounded-xl rounded-tr-none px-3 py-2 text-[12px] text-slate-700 max-w-[240px] shadow-sm whitespace-pre-line leading-relaxed" id="iqpNotifPreview">
                Great news! 🎉
Your order #ORD-1024 is out for delivery and will arrive soon.
Thank you for ordering with <?= sanitize($storeName) ?>!

If you need any help, reply to this message.
              </div>
            </div>
            <div class="flex justify-end mt-1">
              <span class="text-[9.5px] text-slate-400"><?= date('g:i A') ?> ✓✓</span>
            </div>
          </div>
          <button type="button" onclick="sendNotifTest()"
            class="mt-3 flex items-center gap-2 border border-slate-200 rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600 hover:bg-slate-50 w-full justify-center">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
            Send Test Message
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Order Details Panel -->
  <div class="bg-white rounded-xl border border-slate-200 p-5 h-fit">
    <div class="flex items-center justify-between mb-4">
      <div class="text-[15px] font-bold text-slate-800">Order Details</div>
      <?php if ($selected): ?>
      <button type="button" onclick="history.pushState(null,'','/client/orders<?= $botId?'?bot_id='.$botId:'' ?>')" class="text-slate-400 hover:text-slate-600">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
      <?php endif; ?>
    </div>

    <?php if (!$selected): ?>
      <div class="text-[12px] text-slate-400 py-6 text-center">Select an order to see details.</div>
    <?php else:
      $st   = (string)($selected['_status']??'new');
      $pill = $orderStatusPill($st);
      $cn   = (string)($selected['customer_name']?:'Customer');
      $ph   = (string)($selected['customer_phone']??'');
      $price= function_exists('catalog_format_price')
          ? catalog_format_price((float)($selected['total_amount']??0),(string)($selected['currency']??'PKR'))
          : 'PKR '.number_format((float)($selected['total_amount']??0));
      // Delivery fee: show if column exists
      $deliveryFee = (float)($selected['delivery_fee']??0);
      $subtotal = (float)($selected['total_amount']??0) - $deliveryFee;
    ?>

    <!-- Order # + status badge -->
    <div class="flex items-center justify-between mb-3">
      <div class="text-[14px] font-bold text-slate-800">#ORD-<?= (int)$selected['id'] ?></div>
      <span class="text-[11px] font-semibold px-2.5 py-0.5 rounded-full" style="background:<?= $pill[1] ?>;color:<?= $pill[2] ?>"><?= sanitize($pill[0]) ?></span>
    </div>

    <!-- Customer info -->
    <div class="flex items-center justify-between bg-slate-50 rounded-lg p-3 mb-4">
      <div class="flex items-center gap-2.5">
        <div class="w-9 h-9 rounded-full text-white text-[11px] font-bold flex items-center justify-center shrink-0"
          style="background:#<?= substr(md5($cn),0,6) ?>">
          <?= sanitize(iqp_initials($cn)) ?>
        </div>
        <div class="min-w-0">
          <div class="text-[12.5px] font-semibold text-slate-800 truncate"><?= sanitize($cn) ?></div>
          <div class="text-[11px] text-slate-400"><?= sanitize($ph) ?></div>
        </div>
      </div>
      <?php if (!empty($selected['lead_id'])): ?>
      <a href="/client/conversation?lead_id=<?= (int)$selected['lead_id'] ?>"
        class="flex items-center gap-1.5 border border-slate-200 rounded-lg px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 shrink-0">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
        View on WhatsApp
      </a>
      <?php endif; ?>
    </div>

    <!-- Order Items -->
    <div class="text-[12px] font-semibold text-slate-600 mb-2">Order Items</div>
    <div class="space-y-2.5 mb-4">
      <?php if ($selectedItems === []): ?>
        <div class="text-[12px] text-slate-400">No line items.</div>
      <?php else: foreach ($selectedItems as $it):
          $itImg = trim((string)($it['image_url']??''));
          $itPrice = function_exists('catalog_format_price')
              ? catalog_format_price((float)($it['line_total']??$it['price']??0),(string)($selected['currency']??'PKR'))
              : 'PKR '.number_format((float)($it['line_total']??0));
      ?>
      <div class="flex items-center gap-2.5">
        <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
          <?php if ($itImg !== ''): ?>
          <img src="<?= sanitize($itImg) ?>" alt="" class="w-full h-full object-cover"/>
          <?php else: ?><span class="text-[16px]">🍔</span><?php endif; ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-[12px] font-semibold text-slate-700 truncate"><?= sanitize((string)$it['product_name']) ?></div>
        </div>
        <div class="text-[11px] text-slate-400 shrink-0">x<?= (int)$it['quantity'] ?></div>
        <div class="text-[12px] font-semibold text-slate-700 shrink-0"><?= sanitize($itPrice) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Price breakdown -->
    <div class="border-t border-slate-100 pt-3 space-y-1.5 mb-4 text-[12.5px]">
      <?php if ($deliveryFee > 0): ?>
      <div class="flex justify-between text-slate-500">
        <span>Subtotal</span>
        <span><?= sanitize(function_exists('catalog_format_price') ? catalog_format_price($subtotal,(string)($selected['currency']??'PKR')) : 'PKR '.number_format($subtotal)) ?></span>
      </div>
      <div class="flex justify-between text-slate-500">
        <span>Delivery Fee</span>
        <span><?= sanitize(function_exists('catalog_format_price') ? catalog_format_price($deliveryFee,(string)($selected['currency']??'PKR')) : 'PKR '.number_format($deliveryFee)) ?></span>
      </div>
      <div class="flex justify-between font-bold text-slate-800 text-[13px] pt-1 border-t border-slate-100">
        <span>Total</span>
        <span class="text-[#1FA855]"><?= sanitize($price) ?></span>
      </div>
      <?php else: ?>
      <div class="flex justify-between font-bold text-slate-800 text-[13px]">
        <span>Total</span>
        <span class="text-[#1FA855]"><?= sanitize($price) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Order Timeline — completed pipeline steps only (green ticks + labels) -->
    <?php if ($orderTimelineSteps !== []): ?>
    <div class="mb-4">
      <div class="text-[12px] font-semibold text-slate-600 mb-3">Order Timeline</div>
      <div class="space-y-3">
        <?php foreach ($orderTimelineSteps as $step):
            $stepTime = trim((string) ($step['created_at'] ?? ''));
            $stepRel  = $stepTime !== '' ? iqp_rel_time($stepTime) : '';
            $isCurrent = !empty($step['is_current']);
        ?>
        <div class="flex items-start gap-2.5">
          <div class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 mt-0.5 bg-[#1FA855]">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-[12px] font-semibold text-slate-700"><?= sanitize((string) $step['label']) ?></div>
            <?php if ($stepRel !== ''): ?>
            <div class="text-[10.5px] text-slate-400"><?= sanitize($stepRel) ?></div>
            <?php elseif ($isCurrent): ?>
            <div class="text-[10.5px] text-[#1FA855] font-medium">Current stage</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Update status -->
    <form method="POST" class="space-y-2">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="order_id" value="<?= (int)$selected['id'] ?>"/>
      <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
      <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] focus:outline-none focus:border-[#1FA855]">
        <?php foreach ($statusOrder as $stOpt):
            $lab = $columnMeta[$stOpt]['label'] ?? ucfirst($stOpt);
        ?>
        <option value="<?= sanitize($stOpt) ?>"<?= $st===$stOpt?' selected':'' ?>><?= sanitize($lab) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="w-full bg-[#1FA855] text-white rounded-lg py-2.5 text-[12.5px] font-semibold flex items-center justify-center gap-2 hover:bg-[#18934a]">
        Update Status
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
      </button>
    </form>
    <?php endif; ?>
  </div>

</div>

<script>
// Notif toggle
function updateNotifToggle(){
  const btn=document.getElementById('iqpNotifToggle');
  const dot=document.getElementById('iqpNotifDot');
  if(btn.dataset.on==='1'){btn.classList.add('bg-[#1FA855]');btn.classList.remove('bg-slate-200');dot.style.right='2px';dot.style.left='';}
  else{btn.classList.remove('bg-[#1FA855]');btn.classList.add('bg-slate-200');dot.style.left='2px';dot.style.right='';}
}
// Notification preview
function updateNotifPreview(){
  const tmpl=document.getElementById('iqpNotifTemplate').value;
  const rendered=tmpl
    .replace(/\{order_id\}/g,'ORD-<?= (int)($selected['id']??1024) ?>')
    .replace(/\{customer_name\}/g,'<?= sanitize(addslashes($cn??'Customer')) ?>')
    .replace(/\{store_name\}/g,<?= json_encode($storeName, JSON_UNESCAPED_UNICODE) ?>)
    .replace(/\{delivery_time\}/g,'Soon')
    .replace(/\{tracking_link\}/g,'https://track.example.com');
  document.getElementById('iqpNotifPreview').textContent=rendered;
}
function insertNotifVar(v){
  const ta=document.getElementById('iqpNotifTemplate');
  const s=ta.selectionStart,e=ta.selectionEnd;
  ta.value=ta.value.substring(0,s)+v+ta.value.substring(e);
  ta.selectionStart=ta.selectionEnd=s+v.length;
  ta.focus(); updateNotifPreview();
}
function sendNotifTest(){
  var tmpl = document.getElementById('iqpNotifTemplate');
  var preview = document.getElementById('iqpNotifPreview');
  var msg = (preview ? preview.textContent : (tmpl ? tmpl.value : '')).trim();
  if(!msg){ alert('Add a message template first.'); return; }

  var phone = prompt('Enter the WhatsApp number to send a test to (e.g. +923001234567):');
  if(!phone || !phone.trim()){ return; }

  fetch('/api/whatsapp/send-test.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_id: <?= (int)($userId ?? $user['id'] ?? 0) ?>,
      to_number: phone.trim(),
      message_body: msg
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.success){
      alert('✓ Test message sent to ' + phone.trim() + (d.warning ? '\n\nNote: ' + d.warning : ''));
    } else {
      alert('✗ Failed: ' + (d.error || 'Unknown error'));
    }
  })
  .catch(function(){ alert('✗ Network error. Please check your connection.'); });
}
// Init counter
(function(){
  const ta=document.getElementById('iqpNotifTemplate');
  if(ta) document.getElementById('iqpNotifCount').textContent=ta.value.length;
})();
</script>
<?php iqp_user_end();
