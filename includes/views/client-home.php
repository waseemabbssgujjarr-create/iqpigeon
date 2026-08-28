<?php
/** @var array $user */
/** @var string $greeting */
ensure_commerce_schema();
$userId = (int) $user['id'];
$first = trim(explode(' ', (string) ($user['name'] ?? 'there'))[0]);
$from = date('Y-m-d 00:00:00', strtotime('-6 days'));
$prevFrom = date('Y-m-d 00:00:00', strtotime('-13 days'));
$prevTo = date('Y-m-d 23:59:59', strtotime('-7 days'));

$convNow  = (int)(db_fetch('SELECT COUNT(c.id) AS cnt FROM conversations c JOIN leads l ON l.id=c.lead_id JOIN bots b ON b.id=l.bot_id WHERE b.user_id=? AND c.created_at>=?','is',[$userId,$from])['cnt']??0);
$convPrev = (int)(db_fetch('SELECT COUNT(c.id) AS cnt FROM conversations c JOIN leads l ON l.id=c.lead_id JOIN bots b ON b.id=l.bot_id WHERE b.user_id=? AND c.created_at>=? AND c.created_at<=?','iss',[$userId,$prevFrom,$prevTo])['cnt']??0);
$leadsNow  = (int)(db_fetch('SELECT COUNT(l.id) AS cnt FROM leads l JOIN bots b ON b.id=l.bot_id WHERE b.user_id=? AND l.created_at>=?','is',[$userId,$from])['cnt']??0);
$leadsPrev = (int)(db_fetch('SELECT COUNT(l.id) AS cnt FROM leads l JOIN bots b ON b.id=l.bot_id WHERE b.user_id=? AND l.created_at>=? AND l.created_at<=?','iss',[$userId,$prevFrom,$prevTo])['cnt']??0);
$ordersNow  = (int)(db_fetch('SELECT COUNT(*) AS cnt FROM bot_orders WHERE user_id=? AND created_at>=?','is',[$userId,$from])['cnt']??0);
$ordersPrev = (int)(db_fetch('SELECT COUNT(*) AS cnt FROM bot_orders WHERE user_id=? AND created_at>=? AND created_at<=?','iss',[$userId,$prevFrom,$prevTo])['cnt']??0);
$revNow  = (float)(db_fetch('SELECT COALESCE(SUM(total_amount),0) AS amt FROM bot_orders WHERE user_id=? AND created_at>=? AND status!=\'cancelled\'','is',[$userId,$from])['amt']??0);
$revPrev = (float)(db_fetch('SELECT COALESCE(SUM(total_amount),0) AS amt FROM bot_orders WHERE user_id=? AND created_at>=? AND created_at<=? AND status!=\'cancelled\'','iss',[$userId,$prevFrom,$prevTo])['amt']??0);

$wa = db_fetch('SELECT * FROM client_whatsapp_accounts WHERE client_id=? AND connection_status=\'active\' ORDER BY connected_at DESC LIMIT 1','i',[$userId]);
$recentOrders = db_fetch_all('SELECT * FROM bot_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 4','i',[$userId]);
$homeLeads = db_fetch_all('SELECT l.*,COALESCE((SELECT message FROM conversations c WHERE c.lead_id=l.id AND c.role=\'user\' ORDER BY c.created_at DESC LIMIT 1),\'\') AS last_message FROM leads l JOIN bots b ON b.id=l.bot_id WHERE b.user_id=? ORDER BY l.created_at DESC LIMIT 4','i',[$userId]);
$orderTotal = (int)(db_fetch('SELECT COUNT(*) AS cnt FROM bot_orders WHERE user_id=?','i',[$userId])['cnt']??0);
$topProducts = db_fetch_all('SELECT oi.product_name, cp.image_url AS img, SUM(oi.quantity) AS qty FROM bot_order_items oi JOIN bot_orders o ON o.id=oi.order_id LEFT JOIN bot_products cp ON cp.name=oi.product_name AND cp.user_id=o.user_id WHERE o.user_id=? GROUP BY oi.product_name,cp.image_url ORDER BY qty DESC LIMIT 4','i',[$userId]);

// Orders by status for donut
$ordersByStatus = db_fetch_all('SELECT status, COUNT(*) AS cnt FROM bot_orders WHERE user_id=? GROUP BY status','i',[$userId]);
$statusDonut = ['new'=>0,'confirmed'=>0,'preparing'=>0,'shipped'=>0,'out_for_delivery'=>0,'delivered'=>0,'cancelled'=>0];
foreach ($ordersByStatus as $row) {
    $k = strtolower(trim((string)($row['status']??'')));
    if (isset($statusDonut[$k])) $statusDonut[$k] += (int)$row['cnt'];
}
$donutNew       = $statusDonut['new'];
$donutPreparing = $statusDonut['confirmed'] + $statusDonut['preparing'];
$donutOut       = $statusDonut['shipped'] + $statusDonut['out_for_delivery'];
$donutDelivered = $statusDonut['delivered'];

// Updates feed
$updates = 0;
$updatesFeed = [];
try {
    $updates = (int)(db_fetch('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=? AND is_read=0','i',[$userId])['cnt']??0);
    $updatesFeed = db_fetch_all('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 6','i',[$userId]);
} catch (Throwable $e) {}

$statusStyle = [
    'new'           => ['New','#EDE9FE','#6D28D9'],
    'confirmed'     => ['Preparing','#FEF3C7','#B45309'],
    'preparing'     => ['Preparing','#FEF3C7','#B45309'],
    'shipped'       => ['Out for Delivery','#DBEAFE','#1D4ED8'],
    'out_for_delivery'=>['Out for Delivery','#DBEAFE','#1D4ED8'],
    'delivered'     => ['Delivered','#DCFCE7','#15803D'],
    'cancelled'     => ['Cancelled','#FEE2E2','#B91C1C'],
];

$kpis = [
    ['Total Conversations',(string)$convNow, iqp_pct($convNow,$convPrev),'#DCFCE7','#16A34A','<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9a2 2 0 0 1-2 2H7l-3 3V4a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2Z"/><path d="M18 9h1a2 2 0 0 1 2 2v9l-3-3h-5a2 2 0 0 1-2-2"/></svg>'],
    ['New Leads',(string)$leadsNow,iqp_pct($leadsNow,$leadsPrev),'#DBEAFE','#2563EB','<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>'],
    ['New Orders',(string)$ordersNow,iqp_pct($ordersNow,$ordersPrev),'#FFEDD5','#EA580C','<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>'],
    ['Revenue','PKR '.number_format($revNow),iqp_pct((int)$revNow,(int)$revPrev),'#F3E8FF','#7C3AED','<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12h6M12 9v6"/></svg>'],
];

iqp_user_begin($user, 'home', ['title' => 'Home', 'updates' => $updates]);
?>
<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[20px] lg:text-[22px] font-bold text-slate-800"><?= sanitize($greeting) ?>, <?= sanitize($first) ?>! 👋</h1>
    <p class="text-[13px] text-slate-500 mt-0.5">Here's what's happening with your business today.</p>
  </div>
  <div class="flex items-center gap-2 border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12.5px] text-slate-600 cursor-pointer select-none">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
    <span><?= date('M j, Y', strtotime('-6 days')) ?> – <?= date('M j, Y') ?></span>
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
  </div>
</div>

<!-- KPI tiles -->
<?php
$homeStatCards = [];
foreach ($kpis as $k) {
    $up = !str_starts_with($k[2], '↓');
    $homeStatCards[] = [
        'label' => $k[0],
        'value' => $k[1],
        'sub_html' => '<span class="' . ($up ? 'text-emerald-600' : 'text-red-500') . ' font-medium">' . sanitize($k[2]) . '</span> <span class="text-slate-400">vs last 7 days</span>',
        'icon_bg' => $k[3],
        'icon_color' => $k[4],
        'icon_html' => $k[5],
    ];
}
iqp_stat_cards($homeStatCards);
?>

<!-- Row 2: WhatsApp + Recent Orders + Recent Leads -->
<div class="iqp-home-row2 grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">

  <!-- WhatsApp Connection -->
  <div class="iqp-home-card bg-white rounded-xl border border-slate-200 flex flex-col min-w-0">
    <div class="iqp-card-head px-4 sm:px-5 py-3.5 border-b border-slate-100 wa-dash-head">
      <div class="text-[15px] font-bold text-slate-800">WhatsApp Connection</div>
    </div>
    <?php if ($wa): ?>
      <div class="wa-dash-body wa-dash-body--home iqp-card-body">
        <div class="wa-dash-status">
          <div class="wa-dash-logo" aria-hidden="true">
            <?= iqp_whatsapp_logo_svg(36) ?>
          </div>
          <div class="wa-dash-title">Connected</div>
          <div class="wa-dash-phone"><?= sanitize((string)($wa['phone_display_number']??'')) ?></div>
          <?php if (!empty($wa['verified_name'])): ?>
          <div class="wa-dash-sub"><?= sanitize((string)$wa['verified_name']) ?></div>
          <?php endif; ?>
          <span class="wa-dash-badge">Active</span>
        </div>
        <a href="/client/whatsapp-settings" class="wa-dash-action">Manage WhatsApp</a>
      </div>
    <?php else: ?>
      <div class="p-4 sm:p-5 flex flex-col gap-4">
        <div class="flex items-start gap-3">
          <div class="wa-dash-logo shrink-0" aria-hidden="true">
            <?= iqp_whatsapp_logo_svg(32) ?>
          </div>
          <div class="min-w-0">
            <div class="text-[14px] font-bold text-slate-800 leading-snug">Connect WhatsApp Business</div>
            <p class="text-[12px] text-slate-500 mt-1 leading-relaxed">Link your number so your sales rep can reply automatically.</p>
          </div>
        </div>
        <a href="/client/connect-whatsapp" class="w-full bg-[#25D366] text-white text-[13px] font-semibold rounded-lg py-2.5 text-center block hover:bg-[#1da851]">Connect with WhatsApp</a>
        <div class="pt-3 border-t border-slate-100">
          <div class="text-[12px] font-semibold text-slate-700 mb-0.5">Send Test Message</div>
          <div class="text-[11px] text-slate-400 mb-2">Test your bot by sending a message</div>
          <div class="flex flex-col sm:flex-row gap-2">
            <div class="flex items-center gap-1.5 flex-1 border border-slate-200 rounded-lg px-2.5 py-2 min-h-[40px]">
              <span class="text-[13px]">🇵🇰</span>
              <input type="tel" id="iqpTestPhone" placeholder="+92 300 1234567" class="flex-1 min-w-0 text-[12px] outline-none text-slate-600 bg-transparent" />
            </div>
            <button type="button" id="iqpTestSendBtn" onclick="iqpSendTestMsg()"
              class="bg-[#1FA855] text-white text-[12px] font-semibold rounded-lg px-4 py-2.5 hover:bg-[#18934a] min-h-[40px] shrink-0">
              Send Test
            </button>
          </div>
          <div id="iqpTestMsgStatus" class="text-[11px] mt-1.5 hidden"></div>
        </div>
      </div>
      <script>
      async function iqpSendTestMsg(){
        var inp = document.getElementById('iqpTestPhone');
        var btn = document.getElementById('iqpTestSendBtn');
        var status = document.getElementById('iqpTestMsgStatus');
        var phone = (inp ? inp.value : '').trim();
        if(!phone){ status.textContent='Please enter a phone number.'; status.className='text-[11px] mt-1.5 text-red-500'; status.classList.remove('hidden'); return; }
        btn.disabled = true; btn.textContent = 'Sending…';
        status.classList.add('hidden');
        try {
          var r = await fetch('/api/whatsapp/send-test.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
              client_id: <?= (int)$userId ?>,
              to_number: phone,
              message_body: 'Hello! This is a test message from your IQPigeon bot. If you received this, your WhatsApp connection is working correctly. 🎉'
            })
          });
          var d = await r.json();
          if(d.success){
            status.textContent = '✓ Message sent' + (d.warning ? ' — ' + d.warning : '');
            status.className = 'text-[11px] mt-1.5 ' + (d.warning ? 'text-amber-600' : 'text-emerald-600');
          } else {
            status.textContent = '✗ ' + (d.error || 'Send failed.');
            status.className = 'text-[11px] mt-1.5 text-red-500';
          }
        } catch(e) {
          status.textContent = '✗ Network error. Please try again.';
          status.className = 'text-[11px] mt-1.5 text-red-500';
        }
        status.classList.remove('hidden');
        btn.disabled = false; btn.textContent = 'Send Test';
      }
      </script>
    <?php endif; ?>
  </div>

  <!-- Recent Orders -->
  <div class="iqp-home-card bg-white rounded-xl border border-slate-200 p-4 sm:p-5 min-w-0">
    <div class="flex items-center justify-between mb-3">
      <div class="text-[15px] font-bold text-slate-800">Recent Orders</div>
      <a href="/client/orders" class="text-[12px] text-[#1FA855] font-medium">View All</a>
    </div>
    <?php if ($recentOrders === []): ?>
      <div class="text-[12px] text-slate-400 py-6 text-center">No orders yet.</div>
    <?php else: $rn = 1; foreach ($recentOrders as $o):
        $st = $statusStyle[strtolower((string)($o['status']??'new'))] ?? $statusStyle['new'];
        $ts = iqp_rel_time((string)($o['created_at']??''));
    ?>
      <div class="flex items-center gap-2 py-2.5 border-b border-slate-50 last:border-0">
        <div class="text-[12px] text-slate-400 w-4 shrink-0"><?= $rn++ ?></div>
        <div class="flex-1 min-w-0">
          <div class="text-[12.5px] font-semibold text-slate-800">#ORD-<?= (int)$o['id'] ?></div>
          <div class="text-[11px] text-slate-400 truncate"><?= sanitize((string)($o['customer_name']?:'Customer')) ?></div>
        </div>
        <span class="text-[10.5px] font-medium px-2 py-0.5 rounded-full whitespace-nowrap shrink-0" style="background:<?= $st[1] ?>;color:<?= $st[2] ?>"><?= sanitize($st[0]) ?></span>
        <?php if ($ts): ?><div class="text-[10.5px] text-slate-400 shrink-0 hidden sm:block"><?= sanitize($ts) ?></div><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Recent Leads -->
  <div class="iqp-home-card bg-white rounded-xl border border-slate-200 p-4 sm:p-5 min-w-0">
    <div class="flex items-center justify-between mb-3">
      <div class="text-[15px] font-bold text-slate-800">Recent Leads</div>
      <a href="/client/leads" class="text-[12px] text-[#1FA855] font-medium">View All</a>
    </div>
    <?php if ($homeLeads === []): ?>
      <div class="text-[12px] text-slate-400 py-6 text-center">No leads yet.</div>
    <?php else: foreach ($homeLeads as $lead):
        $ln = trim((string)($lead['name']??$lead['phone']??'Lead'));
        $ts = iqp_rel_time((string)($lead['created_at']??''));
    ?>
      <a href="/client/conversation?lead_id=<?= (int)$lead['id'] ?>" class="flex items-center gap-2.5 py-2.5 border-b border-slate-50 last:border-0">
        <div class="w-8 h-8 rounded-full bg-slate-100 text-[11px] font-bold text-slate-600 flex items-center justify-center shrink-0"><?= sanitize(iqp_initials($ln)) ?></div>
        <div class="min-w-0 flex-1">
          <div class="flex items-center justify-between gap-1">
            <div class="text-[12.5px] font-semibold text-slate-800 truncate"><?= sanitize($ln) ?></div>
            <?php if ($ts): ?><div class="text-[10.5px] text-slate-400 shrink-0"><?= sanitize($ts) ?></div><?php endif; ?>
          </div>
          <div class="text-[11.5px] text-slate-400 truncate"><?= sanitize((string)($lead['last_message']??'')) ?></div>
        </div>
        <div class="w-4 h-4 rounded-full bg-[#1FA855] text-white text-[9px] font-bold flex items-center justify-center shrink-0">1</div>
      </a>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Row 3: Orders Overview + Top Products + AI Status -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">

  <!-- Orders Overview with Donut -->
  <div class="bg-white rounded-xl border border-slate-200 p-5">
    <div class="flex items-center justify-between mb-4">
      <div class="text-[15px] font-bold text-slate-800">Orders Overview</div>
      <a href="/client/orders" class="text-[12px] text-[#1FA855] font-medium">View All</a>
    </div>
    <?php
    $donutTotal = $donutNew + $donutPreparing + $donutOut + $donutDelivered;
    $donutData = [
        ['New Order',       $donutNew,       '#7C3AED'],
        ['Preparing',       $donutPreparing, '#F59E0B'],
        ['Out for Delivery',$donutOut,       '#3B82F6'],
        ['Delivered',       $donutDelivered, '#10B981'],
    ];
    ?>
    <div class="flex items-center gap-4">
      <div class="relative shrink-0" style="width:100px;height:100px">
        <svg width="100" height="100" viewBox="0 0 36 36" class="transform -rotate-90">
          <?php
          $circumference = 2 * M_PI * 15.9155;
          $offset = 0;
          if ($donutTotal > 0):
            foreach ($donutData as $seg):
              $pct = $seg[1] / $donutTotal;
              $dash = $pct * $circumference;
              $gap  = $circumference - $dash;
          ?>
          <circle cx="18" cy="18" r="15.9155" fill="transparent" stroke="<?= $seg[2] ?>" stroke-width="3.5"
            stroke-dasharray="<?= round($dash,2) ?> <?= round($gap,2) ?>"
            stroke-dashoffset="-<?= round($offset * $circumference,2) ?>" />
          <?php $offset += $pct; endforeach;
          else: ?>
          <circle cx="18" cy="18" r="15.9155" fill="transparent" stroke="#E2E8F0" stroke-width="3.5" stroke-dasharray="100 0" />
          <?php endif; ?>
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
          <div class="text-[17px] font-bold text-slate-800 leading-none"><?= $donutTotal ?></div>
          <div class="text-[9px] text-slate-400 mt-0.5">Total</div>
        </div>
      </div>
      <div class="space-y-1.5 flex-1">
        <?php foreach ($donutData as $seg):
            $pct = $donutTotal > 0 ? round(($seg[1]/$donutTotal)*100) : 0;
        ?>
        <div class="flex items-center justify-between text-[11.5px]">
          <div class="flex items-center gap-1.5">
            <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background:<?= $seg[2] ?>"></div>
            <span class="text-slate-600"><?= sanitize($seg[0]) ?></span>
          </div>
          <span class="text-slate-500"><?= $seg[1] ?> <span class="text-slate-400">(<?= $pct ?>%)</span></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Top Products -->
  <div class="bg-white rounded-xl border border-slate-200 p-5">
    <div class="flex items-center justify-between mb-3">
      <div class="text-[15px] font-bold text-slate-800">Top Products</div>
      <a href="/client/catalog" class="text-[12px] text-[#1FA855] font-medium">View All</a>
    </div>
    <?php if ($topProducts === []): ?>
      <div class="text-[12px] text-slate-400 py-6 text-center">No product sales yet.</div>
    <?php else: $i=1; foreach ($topProducts as $p):
        $img = trim((string)($p['img']??''));
    ?>
      <div class="flex items-center gap-2.5 py-2 border-b border-slate-50 last:border-0">
        <div class="text-[12px] text-slate-400 w-4 shrink-0 font-medium"><?= $i++ ?></div>
        <div class="w-9 h-9 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
          <?php if ($img !== ''): ?>
            <img src="<?= sanitize($img) ?>" alt="" class="w-full h-full object-cover"/>
          <?php else: ?>
            <span class="text-[14px]">🍔</span>
          <?php endif; ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-[12.5px] font-semibold text-slate-800 truncate"><?= sanitize((string)$p['product_name']) ?></div>
        </div>
        <div class="text-[12px] text-slate-400 shrink-0"><?= (int)$p['qty'] ?> Orders</div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- AI Assistant Status -->
  <div class="bg-white rounded-xl border border-slate-200 p-5">
    <div class="flex items-center justify-between mb-4">
      <div class="text-[15px] font-bold text-slate-800">AI Assistant Status</div>
      <span class="bg-emerald-50 text-emerald-600 text-[11px] font-semibold px-2.5 py-0.5 rounded-full">Online</span>
    </div>
    <div class="flex items-start gap-3 mb-5">
      <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 mt-0.5">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
      </div>
      <div>
        <div class="text-[13px] font-semibold text-slate-800">Your AI Assistant is active</div>
        <div class="text-[12px] text-slate-500 mt-0.5">and ready to help your customers 24/7.</div>
      </div>
    </div>
    <div class="grid grid-cols-3 gap-3 border-t border-slate-100 pt-4">
      <div class="text-center">
        <div class="text-[18px] font-bold text-slate-800">98%</div>
        <div class="text-[10.5px] text-slate-400 mt-0.5">Response Rate</div>
      </div>
      <div class="text-center border-x border-slate-100">
        <div class="text-[18px] font-bold text-slate-800">2.3s</div>
        <div class="text-[10.5px] text-slate-400 mt-0.5">Avg. Response Time</div>
      </div>
      <div class="text-center">
        <div class="text-[14px] font-bold text-emerald-600">Always On</div>
        <div class="text-[10.5px] text-slate-400 mt-0.5">Availability</div>
      </div>
    </div>
  </div>
</div>

<!-- Updates Activity Strip -->
<div class="bg-white rounded-xl border border-slate-200 p-4">
  <div class="flex items-center justify-between mb-3">
    <div class="flex items-center gap-2">
      <div class="text-[15px] font-bold text-slate-800">Updates</div>
      <?php if ($updates > 0): ?>
      <span class="bg-[#1FA855] text-white text-[11px] font-bold rounded-full min-w-[20px] h-5 flex items-center justify-center px-1"><?= $updates ?></span>
      <?php endif; ?>
    </div>
    <a href="/client/notifications" class="text-[12px] text-[#1FA855] font-medium">View All</a>
  </div>
  <?php if ($updatesFeed === []): ?>
    <div class="text-[12px] text-slate-400 py-2">No recent updates.</div>
  <?php else: ?>
    <div class="flex flex-wrap gap-x-6 gap-y-2">
      <?php foreach ($updatesFeed as $upd):
          $uts = iqp_rel_time((string)($upd['created_at']??''));
          $msg = (string)($upd['message']??'');
          $type = (string)($upd['type']??'info');
          $dot = match($type) {
              'lead'  => '#10B981',
              'order' => '#8B5CF6',
              'message'=> '#F59E0B',
              default => '#3B82F6',
          };
          $icon = match($type) {
              'lead'   => '👤',
              'order'  => '📦',
              'message'=> '💬',
              default  => '🔔',
          };
      ?>
      <div class="flex items-center gap-2 text-[12px] text-slate-600 shrink-0">
        <div class="w-2 h-2 rounded-full shrink-0" style="background:<?= $dot ?>"></div>
        <span class="font-medium"><?= $icon ?></span>
        <span class="max-w-[200px] truncate"><?= sanitize($msg) ?></span>
        <?php if ($uts): ?><span class="text-slate-400"><?= sanitize($uts) ?></span><?php endif; ?>
        <span class="text-slate-200 last:hidden">•</span>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php iqp_user_end();
