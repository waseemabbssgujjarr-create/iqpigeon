<?php
/** @var array $user */
/** @var array $notifications */
/** @var array $updates */
/** @var int $userId */

$csrf   = csrf_token();
$unread = function_exists('get_unread_notification_count') ? get_unread_notification_count($userId) : 0;

// Active tab filter
$tabFilter = preg_replace('/[^a-z]/', '', (string)($_GET['type'] ?? 'all'));
if (!in_array($tabFilter, ['all','leads','orders','messages','system'], true)) $tabFilter = 'all';

// Type icon / color helper
$notifMeta = static function(string $type): array {
    return match(true) {
        str_contains($type, 'lead')    => ['#DCFCE7','#16A34A','<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>'],
        str_contains($type, 'order')   => ['#FFEDD5','#EA580C','<path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>'],
        str_contains($type, 'message') => ['#DBEAFE','#2563EB','<path d="M14 9a2 2 0 0 1-2 2H7l-3 3V4a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2Z"/>'],
        str_contains($type, 'system')  => ['#F3E8FF','#7C3AED','<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>'],
        default                         => ['#F1F5F9','#64748b','<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'],
    };
};

// Filter notifications by tab
$filtered = $notifications;
if ($tabFilter !== 'all') {
    $filtered = array_values(array_filter($notifications, function($n) use ($tabFilter) {
        $type = strtolower((string)($n['type'] ?? $n['title'] ?? ''));
        return str_contains($type, $tabFilter);
    }));
}

$typeCounts = ['all' => count($notifications), 'leads' => 0, 'orders' => 0, 'messages' => 0, 'system' => 0];
foreach ($notifications as $n) {
    $t = strtolower((string)($n['type'] ?? $n['title'] ?? ''));
    foreach (['leads','orders','messages','system'] as $k) {
        if (str_contains($t, rtrim($k,'s'))) { $typeCounts[$k]++; break; }
    }
}

iqp_user_begin($user, 'updates', ['title' => 'Updates', 'updates' => $unread]);
?>

<!-- Page header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[22px] font-bold text-slate-800">Updates
      <?php if ($unread > 0): ?>
      <span class="ml-2 bg-[#1FA855] text-white text-[12px] font-bold rounded-full px-2 py-0.5"><?= $unread ?></span>
      <?php endif; ?>
    </h1>
    <p class="text-[14px] text-slate-500 mt-1">Alerts and product announcements.</p>
  </div>
  <?php if ($unread > 0): ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="mark_all"/>
    <button class="flex items-center gap-2 border border-slate-200 bg-white rounded-xl px-4 py-2.5 text-[14px] font-medium text-slate-600 hover:bg-slate-50 transition-all shadow-sm">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Mark all read
    </button>
  </form>
  <?php endif; ?>
</div>

<!-- Tab filter -->
<?php
$updateNav = [];
foreach ([
    ['all', 'All Updates', $typeCounts['all']],
    ['leads', 'Leads', $typeCounts['leads']],
    ['orders', 'Orders', $typeCounts['orders']],
    ['messages', 'Messages', $typeCounts['messages']],
    ['system', 'System', $typeCounts['system']],
] as [$tid, $tlabel, $tcnt]) {
    $updateNav[] = ['id' => $tid, 'label' => $tlabel, 'count' => (int) $tcnt, 'href' => '?type=' . $tid];
}
iqp_tab_nav($tabFilter, $updateNav, ['variant' => 'user', 'class' => 'mb-6']);
?>

<!-- Two-column desktop layout -->
<div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-5 notifications-grid">

  <!-- LEFT: Your Alerts -->
  <div class="space-y-3">
    <?php if ($filtered === []): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
      <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      </div>
      <h3 class="text-[16px] font-bold text-slate-700 mb-1">No notifications yet</h3>
      <p class="text-[14px] text-slate-400">New leads and activity will appear here.</p>
    </div>
    <?php else: foreach ($filtered as $n):
        $type  = strtolower((string)($n['type'] ?? $n['title'] ?? ''));
        [$ibg,$icol,$ipath] = $notifMeta($type);
        $isUnread = empty($n['is_read']);
        $link  = (string)($n['link'] ?? '#');
        $title = (string)($n['title'] ?? '');
        $msg   = (string)($n['message'] ?? '');
        $date  = (string)($n['created_at'] ?? '');
    ?>
    <a href="<?= sanitize($link !== '#' ? $link : '#') ?>"
      class="flex items-start gap-4 bg-white rounded-xl border border-slate-200 p-4 transition-all hover:shadow-md group <?= $isUnread ? 'border-l-4 border-l-[#1FA855]' : '' ?>">
      <!-- Icon -->
      <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 transition-transform group-hover:scale-110" style="background:<?= $ibg ?>;color:<?= $icol ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $ipath ?></svg>
      </div>
      <!-- Content -->
      <div class="flex-1 min-w-0">
        <div class="flex items-start justify-between gap-2">
          <div class="text-[14px] font-semibold text-slate-800 <?= $isUnread ? 'text-slate-900' : '' ?> leading-snug"><?= sanitize($title) ?></div>
          <?php if ($isUnread): ?>
          <div class="w-2.5 h-2.5 rounded-full bg-[#1FA855] shrink-0 mt-1.5"></div>
          <?php endif; ?>
        </div>
        <?php if ($msg !== ''): ?>
        <div class="text-[13px] text-slate-500 mt-0.5 leading-relaxed"><?= sanitize($msg) ?></div>
        <?php endif; ?>
        <?php if ($date !== ''): ?>
        <div class="text-[12px] text-slate-400 mt-1.5 flex items-center gap-1">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <?= sanitize(function_exists('format_date') ? format_date($date) : date('d M Y, g:i A', strtotime($date))) ?>
        </div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; endif; ?>
  </div>

  <!-- RIGHT: What's New (system updates) -->
  <div>
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100" style="background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%)">
        <div class="flex items-center gap-2.5">
          <div class="w-9 h-9 rounded-xl bg-[#1FA855] flex items-center justify-center">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          </div>
          <div>
            <div class="text-[15px] font-bold text-slate-800">What's New</div>
            <div class="text-[12px] text-slate-500">Platform updates & features</div>
          </div>
        </div>
      </div>

      <?php if ($updates === []): ?>
      <div class="p-8 text-center">
        <div class="text-[14px] text-slate-400">No announcements yet.</div>
      </div>
      <?php else: ?>
      <div class="divide-y divide-slate-50">
        <?php foreach ($updates as $update):
            $dt = (string)($update['sent_at'] ?? $update['created_at'] ?? '');
        ?>
        <div class="px-5 py-4 hover:bg-slate-50 transition-colors">
          <div class="text-[12px] text-slate-400 mb-1 flex items-center gap-1">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            <?= $dt !== '' ? sanitize(function_exists('format_date') ? format_date($dt) : date('d M Y', strtotime($dt))) : '' ?>
          </div>
          <div class="text-[14px] font-semibold text-slate-800 leading-snug"><?= sanitize((string)$update['title']) ?></div>
          <?php if (!empty($update['body'])): ?>
          <p class="text-[13px] text-slate-500 mt-1 leading-relaxed whitespace-pre-line"><?= sanitize((string)$update['body']) ?></p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Quick links card -->
    <div class="bg-white rounded-xl border border-slate-200 p-5 mt-4">
      <div class="text-[15px] font-bold text-slate-800 mb-3">Quick Links</div>
      <div class="space-y-2">
        <?php foreach ([
          ['/client/leads',   'View Leads',    '#DCFCE7','#16A34A','<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>'],
          ['/client/orders',  'View Orders',   '#FFEDD5','#EA580C','<path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>'],
          ['/client/billing', 'Billing',       '#F3E8FF','#7C3AED','<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/>'],
          ['/client/settings','Settings',      '#F1F5F9','#475569','<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83"/>'],
        ] as [$href,$label,$bg,$col,$path]): ?>
        <a href="<?= sanitize($href) ?>"
          class="flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
          <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 transition-transform group-hover:scale-110" style="background:<?= $bg ?>;color:<?= $col ?>">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $path ?></svg>
          </div>
          <span class="text-[14px] font-medium text-slate-700 flex-1"><?= sanitize($label) ?></span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div><!-- /grid -->
<?php iqp_user_end();
