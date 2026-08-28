<?php
/** @var array $user */
/** @var array $leads */
/** @var string $filter */
/** @var string $search */
/** @var array $validFilters */
/** @var int $selectedId */
/** @var string $syncMessage */
/** @var int $defaultBotId */
/** @var int $userId */

require_once dirname(__DIR__) . '/quick-replies.php';
require_once dirname(__DIR__) . '/team.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = (string) ($_POST['action'] ?? '');
    $lid = (int) ($_POST['lead_id'] ?? 0);
    if ($act === 'send' && $lid > 0 && trim((string) ($_POST['message'] ?? '')) !== '') {
        conversation_send_to_lead($lid, $userId, trim((string) $_POST['message']));
        redirect('/client/leads?lead_id=' . $lid . ($filter !== 'all' ? '&status=' . urlencode($filter) : ''));
    }
    if ($act === 'intervene' && $lid > 0) {
        pause_lead_bot($lid, 60);
        redirect('/client/leads?lead_id=' . $lid);
    }
    if ($act === 'resume_ai' && $lid > 0) {
        resume_lead_bot($lid);
        redirect('/client/leads?lead_id=' . $lid);
    }
}

if ($selectedId <= 0 && $leads !== []) {
    $selectedId = (int) $leads[0]['id'];
}
$selected = null;
foreach ($leads as $row) {
    if ((int) $row['id'] === $selectedId) {
        $selected = $row;
        break;
    }
}
$msgs = [];
$threadCount = 0;
if ($selected) {
    $msgs = db_fetch_all('SELECT * FROM conversations WHERE lead_id = ? ORDER BY created_at ASC LIMIT 80', 'i', [$selectedId]);
    $threadCount = (int) (db_fetch('SELECT COUNT(*) AS cnt FROM conversations WHERE lead_id = ?', 'i', [$selectedId])['cnt'] ?? 0);
}
$pausedUntil = (string) ($selected['bot_paused_until'] ?? '');
$paused = $pausedUntil !== '' && $pausedUntil !== '0000-00-00 00:00:00' && strtotime($pausedUntil) > time();
$unreadN = 0;
foreach ($leads as $row) {
    if (($row['status'] ?? '') === 'new') {
        $unreadN++;
    }
}
$assignedN = 0;
foreach ($leads as $row) {
    if (!empty($row['assigned_member_id']) || !empty($row['assignee_name'])) {
        $assignedN++;
    }
}

$csrf = csrf_token();
iqp_user_begin($user, 'leads', ['title' => 'Leads']);
if ($syncMessage !== '') {
    iqp_flash($syncMessage);
}
?>
<div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[19px] lg:text-[22px] font-bold text-slate-800">Leads / Inbox</h1>
    <p class="text-[13px] lg:text-[13.5px] text-slate-500 mt-1">View and manage all conversations with your customers.</p>
  </div>
  <div class="flex gap-2">
    <form method="get" class="hidden sm:block">
      <?php if ($filter !== 'all'): ?><input type="hidden" name="status" value="<?= sanitize($filter) ?>"/><?php endif; ?>
      <input name="q" value="<?= sanitize($search) ?>" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px]" placeholder="Search conversations..."/>
    </form>
    <a href="/client/leads?sync_orders=1" class="border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600">Sync</a>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="export"/><button class="border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600">Export</button></form>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[300px_1fr_300px] gap-4 bg-white rounded-xl border border-slate-200 overflow-hidden" style="min-height:600px">
  <div class="border-r border-slate-100">
    <?php
    $inboxTabs = [
        ['all', 'All', count($leads)],
        ['new', 'Unread', $unreadN],
        ['in_progress', 'Assigned', $assignedN],
    ];
    $leadNavTabs = [];
    foreach ($inboxTabs as [$id, $lab, $n]) {
        $leadNavTabs[] = [
            'id' => $id,
            'label' => $lab,
            'count' => (int) $n,
            'href' => '/client/leads?status=' . urlencode($id) . ($search !== '' ? '&q=' . urlencode($search) : ''),
        ];
    }
    $leadFilter = $filter === 'all' ? 'all' : ($filter === 'in_progress' ? 'in_progress' : $filter);
    iqp_tab_nav($leadFilter, $leadNavTabs, ['variant' => 'user', 'class' => 'px-3 pt-3 pb-2 border-b border-slate-100', 'mobile' => 'grid', 'select_label' => 'Inbox filter']);
    ?>
    <div class="max-h-[520px] overflow-y-auto">
      <?php if ($leads === []): ?>
        <div class="p-6 text-[12px] text-slate-400">No conversations yet.</div>
      <?php else: foreach ($leads as $lead):
          $ln = trim((string) ($lead['name'] ?? $lead['phone'] ?? 'Lead'));
          $on = (int) $lead['id'] === $selectedId;
          $href = '/client/leads?lead_id=' . (int) $lead['id'] . ($filter !== 'all' ? '&status=' . urlencode($filter) : '') . ($search !== '' ? '&q=' . urlencode($search) : '');
      ?>
      <a href="<?= sanitize($href) ?>" class="flex items-start gap-2.5 p-3 border-b border-slate-50 <?= $on ? 'bg-emerald-50' : '' ?>">
        <div class="w-9 h-9 rounded-full bg-slate-700 text-white text-[11px] font-semibold flex items-center justify-center shrink-0"><?= sanitize(iqp_initials($ln)) ?></div>
        <div class="flex-1 min-w-0">
          <div class="flex justify-between gap-2"><span class="text-[12.5px] font-semibold text-slate-700 truncate"><?= sanitize($ln) ?></span><span class="text-[10.5px] text-slate-400 shrink-0"><?= sanitize(iqp_rel_time((string) ($lead['last_activity_at'] ?? $lead['updated_at'] ?? ''))) ?></span></div>
          <div class="text-[11.5px] text-slate-400 truncate"><?= sanitize((string) ($lead['last_message'] ?? '')) ?></div>
        </div>
      </a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="flex flex-col border-r border-slate-100 min-h-[520px]">
    <?php if (!$selected): ?>
      <div class="p-8 text-[13px] text-slate-400 text-center">Select a conversation.</div>
    <?php else:
        $ln = trim((string) ($selected['name'] ?? 'Lead'));
        $phone = (string) ($selected['phone'] ?? $selected['external_id'] ?? '');
    ?>
    <div class="flex items-center justify-between p-3 border-b border-slate-100">
      <div class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-full bg-slate-700 text-white text-[11px] font-semibold flex items-center justify-center"><?= sanitize(iqp_initials($ln)) ?></div>
        <div><div class="text-[13px] font-semibold text-slate-700"><?= sanitize($ln) ?></div><div class="text-[11px] text-slate-400"><?= sanitize($phone) ?></div></div>
      </div>
      <a href="/client/conversation?lead_id=<?= $selectedId ?>" class="hidden sm:inline border border-slate-200 rounded-lg px-2.5 py-1.5 text-[11.5px] font-medium text-slate-600">Open full chat</a>
    </div>
    <div class="bg-amber-50 border-b border-amber-100 px-3 py-2 flex items-center justify-between">
      <span class="text-[11.5px] text-amber-700"><?= $paused ? 'AI paused — you are handling this conversation' : 'AI Assistant is handling this conversation' ?></span>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="lead_id" value="<?= $selectedId ?>"/>
        <input type="hidden" name="action" value="<?= $paused ? 'resume_ai' : 'intervene' ?>"/>
        <button class="text-[11px] font-semibold text-amber-700 border border-amber-300 rounded px-2 py-0.5"><?= $paused ? 'Resume AI' : 'Intervene' ?></button>
      </form>
    </div>
    <div class="flex-1 p-3 space-y-3 overflow-y-auto text-[12.5px]">
      <?php foreach ($msgs as $m):
          $isBot = ($m['role'] ?? '') !== 'user';
      ?>
        <?php if ($isBot): ?>
        <div class="bg-emerald-500 text-white rounded-lg rounded-tr-none px-3 py-2 w-fit max-w-[85%] ml-auto"><?= nl2br(sanitize((string) $m['message'])) ?><div class="text-[10px] text-right opacity-80 mt-1">Sent by AI Assistant</div></div>
        <?php else: ?>
        <div class="bg-slate-100 rounded-lg rounded-tl-none px-3 py-2 w-fit max-w-[85%]"><?= nl2br(sanitize((string) $m['message'])) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($msgs === []): ?><div class="text-slate-400 text-[12px]">No messages yet.</div><?php endif; ?>
    </div>
    <div class="border-t border-slate-100 p-3">
      <div class="flex gap-3 text-[12px] font-medium text-slate-400 mb-2"><span class="text-[#1FA855] border-b-2 border-[#1FA855] pb-1">Reply</span></div>
      <form method="POST" class="flex gap-2">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="send"/>
        <input type="hidden" name="lead_id" value="<?= $selectedId ?>"/>
        <input name="message" required class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-[12.5px]" placeholder="Type your message..."/>
        <button class="bg-[#1FA855] text-white rounded-lg px-3 shrink-0 text-[13px] font-semibold">Send</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="p-4 hidden lg:block overflow-y-auto max-h-[600px]">
    <?php if ($selected):
        $ln = trim((string) ($selected['name'] ?? 'Lead'));
    ?>
    <div class="flex justify-between mb-3"><div class="text-[13.5px] font-bold text-slate-800">Lead Information</div><a href="/client/conversation?lead_id=<?= $selectedId ?>" class="text-[11.5px] text-[#1FA855] font-medium">Edit</a></div>
    <div class="flex items-center gap-2.5 mb-3">
      <div class="w-9 h-9 rounded-full bg-slate-700 text-white text-[11px] font-semibold flex items-center justify-center"><?= sanitize(iqp_initials($ln)) ?></div>
      <div><div class="text-[13px] font-semibold text-slate-700"><?= sanitize($ln) ?></div><div class="text-[11px] text-slate-400"><?= sanitize((string) ($selected['phone'] ?? $selected['external_id'] ?? '')) ?></div></div>
    </div>
    <div class="space-y-1.5 text-[11.5px] text-slate-500 mb-4">
      <div class="flex justify-between"><span>First Contact</span><span class="text-slate-700"><?= sanitize(iqp_rel_time((string) ($selected['created_at'] ?? ''))) ?></span></div>
      <div class="flex justify-between"><span>Last Seen</span><span class="text-slate-700"><?= sanitize(iqp_rel_time((string) ($selected['last_activity_at'] ?? $selected['updated_at'] ?? ''))) ?></span></div>
      <div class="flex justify-between"><span>Total Conversations</span><span class="text-slate-700"><?= $threadCount ?></span></div>
      <div class="flex justify-between items-center"><span>Status</span><span class="bg-blue-50 text-blue-600 text-[10.5px] px-2 py-0.5 rounded-full"><?= sanitize(ucwords(str_replace('_', ' ', (string) ($selected['status'] ?? 'new')))) ?></span></div>
      <div class="flex justify-between"><span>Assigned To</span><span class="text-slate-700"><?= sanitize((string) ($selected['assignee_name'] ?? 'Unassigned')) ?></span></div>
    </div>
    <form method="POST" onsubmit="return confirm('Delete this lead?')">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="delete"/>
      <input type="hidden" name="lead_id" value="<?= $selectedId ?>"/>
      <button class="text-[12px] text-red-500 font-medium">Delete lead</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
iqp_user_end();
