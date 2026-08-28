<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/quick-replies.php';
require_once __DIR__ . '/../includes/conversation-media.php';

$user = require_login();
$userId = (int) $user['id'];
$leadId = (int) ($_GET['lead_id'] ?? 0);

if (!$leadId) {
    redirect('/client/leads');
}

$lead = db_fetch(
    'SELECT l.*, b.name AS bot_name, b.rep_name, b.calendly_link, b.user_id,
            tm.name AS assignee_name, tm.color AS assignee_color
     FROM leads l
     JOIN bots b ON b.id = l.bot_id
     LEFT JOIN team_members tm ON tm.id = l.assigned_member_id
     WHERE l.id = ? AND b.user_id = ?',
    'ii',
    [$leadId, $userId]
);

if (!$lead) {
    redirect('/client/leads');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'send' && !empty(trim($_POST['message'] ?? ''))) {
        conversation_send_to_lead($leadId, $userId, trim($_POST['message']));
        redirect('/client/conversation?lead_id=' . $leadId);
    }

    if ($action === 'quick_send') {
        $replyId = (int) ($_POST['reply_id'] ?? 0);
        $reply = db_fetch(
            'SELECT * FROM quick_replies WHERE id = ? AND user_id = ? AND is_active = 1',
            'ii',
            [$replyId, $userId]
        );
        if ($reply) {
            conversation_send_to_lead($leadId, $userId, (string) $reply['message_body']);
        }
        redirect('/client/conversation?lead_id=' . $leadId);
    }

    if ($action === 'intervene') {
        pause_lead_bot($leadId, 60);
        redirect('/client/conversation?lead_id=' . $leadId);
    }

    if ($action === 'resume_ai') {
        resume_lead_bot($leadId);
        redirect('/client/conversation?lead_id=' . $leadId);
    }

    if ($action === 'export') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="conversation-' . $leadId . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Time', 'Role', 'Message']);
        $msgs = db_fetch_all('SELECT * FROM conversations WHERE lead_id = ? ORDER BY created_at ASC', 'i', [$leadId]);
        foreach ($msgs as $m) {
            fputcsv($out, [$m['created_at'], $m['role'], $m['message']]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'assign') {
        $assignId = ($_POST['assigned_member_id'] ?? '') === '' ? null : (int) $_POST['assigned_member_id'];
        $priority = (string) ($_POST['inbox_priority'] ?? 'normal');
        team_assign_lead($leadId, $userId, $assignId, $priority);
        redirect('/client/conversation?lead_id=' . $leadId);
    }

    if ($action === 'add_note' && !empty(trim($_POST['note'] ?? ''))) {
        team_add_note($leadId, $userId, trim($_POST['note']), $user['name'] ?? 'Owner');
        redirect('/client/conversation?lead_id=' . $leadId);
    }
}

$lead = db_fetch(
    'SELECT l.*, b.name AS bot_name, b.rep_name, b.calendly_link, b.user_id,
            tm.name AS assignee_name, tm.color AS assignee_color
     FROM leads l
     JOIN bots b ON b.id = l.bot_id
     LEFT JOIN team_members tm ON tm.id = l.assigned_member_id
     WHERE l.id = ? AND b.user_id = ?',
    'ii',
    [$leadId, $userId]
) ?: $lead;

$repName = get_bot_rep_name($lead);
$aiCopilotOn = !is_lead_bot_paused($lead);
$lastMessageId = 0;

$messages = db_fetch_all(
    'SELECT * FROM conversations WHERE lead_id = ? ORDER BY created_at ASC',
    'i',
    [$leadId]
);

$qualData = json_decode($lead['qualification_data'] ?? '{}', true) ?: [];
$budget = $qualData['budget'] ?? $qualData['Budget'] ?? 'Not captured';
$pain = $qualData['pain'] ?? $qualData['pain_point'] ?? 'Not captured';
$score = (int) ($lead['score'] ?? 0);

$teamMembers = team_members_for_user($userId);
$internalNotes = team_notes_for_lead($leadId, $userId);
$quickReplies = quick_replies_for_user($userId, (int) $lead['bot_id']);

$activeTab = 'leads';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Conversation') ?>
</head>
<body class="client-app v2-client client-chat-view bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>

<?php client_layout_start(['width' => 'full', 'main_class' => 'client-main--chat']); ?>
<div class="client-chat-frame v2-chat-frame">
    <!-- Header -->
    <header class="conversation-header">
        <a href="/client/leads" class="conversation-header__back" aria-label="Back to leads">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>

        <div class="conversation-header__identity">
            <span class="conversation-header__avatar">
                <?= sanitize(get_lead_initial($lead['name'] ?? 'L')) ?>
            </span>
            <div class="conversation-header__text">
                <span class="conversation-header__name"><?= sanitize($lead['name'] ?? 'Unknown') ?></span>
                <div class="conversation-header__meta">
                    <?= status_badge($lead['status']) ?>
                    <span class="conversation-header__channel">
                        <span class="material-symbols-outlined"><?= sanitize(platform_icon($lead['platform'])) ?></span>
                        <?= sanitize(ucfirst($lead['platform'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="conversation-header__actions">
            <div class="conversation-header__actions-secondary hidden md:flex">
                <?php client_mobile_menu_button(); ?>
                <?php $variant = 'client'; include __DIR__ . '/../includes/theme-toggle.php'; ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="export"/>
                    <button type="submit" class="conversation-header__icon-btn" title="Export">
                        <span class="material-symbols-outlined text-outline">download</span>
                    </button>
                </form>
            </div>
            <?php if ($aiCopilotOn): ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="intervene"/>
                <button type="submit" class="conversation-header__intervene conversation-header__intervene--pause" title="Intervene">
                    <span class="material-symbols-outlined">pause</span>
                    <span class="conversation-header__intervene-label">Intervene</span>
                </button>
            </form>
            <?php else: ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="resume_ai"/>
                <button type="submit" class="conversation-header__intervene conversation-header__intervene--resume" title="Resume auto-replies">
                    <span class="material-symbols-outlined">smart_toy</span>
                    <span class="conversation-header__intervene-label">Resume</span>
                </button>
            </form>
            <?php endif; ?>
            <button type="button" onclick="App.openBottomSheet('lead-intel')" class="conversation-header__icon-btn md:hidden" aria-label="Lead info">
                <span class="material-symbols-outlined">info</span>
            </button>
            <button type="button" class="conversation-header__icon-btn conversation-header__more md:hidden" data-mobile-menu-open aria-label="More options" aria-controls="client-mobile-menu" aria-expanded="false">
                <span class="material-symbols-outlined">more_vert</span>
            </button>
        </div>
    </header>

    <div class="client-chat-body">
        <!-- Conversation thread -->
        <div id="conversation-thread" class="conversation-thread conversation-scroll">
            <?php
            $lastDate = '';
            foreach ($messages as $msg):
                $msgDate = format_day_label($msg['created_at']);
                if ($msgDate !== $lastDate):
                    $lastDate = $msgDate;
            ?>
            <div class="flex justify-center my-md">
                <span class="bg-surface-variant text-on-surface-variant px-md py-xs rounded-full text-label-sm font-label js-local-day" data-iso="<?= sanitize(datetime_to_iso($msg['created_at'])) ?>"><?= sanitize($msgDate) ?></span>
            </div>
            <?php endif; ?>

            <?php
            if ($lead['status'] === 'qualified' && str_contains($msg['message'], 'calendar') && ($msg['role'] ?? '') === 'assistant'): ?>
            <div class="flex justify-center my-md">
                <div class="bg-primary-container/20 border border-primary text-on-primary-container px-md py-sm rounded-xl flex items-center gap-sm max-w-[90%]">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings:'FILL' 1">verified</span>
                    <div>
                        <p class="text-label-sm font-bold uppercase tracking-widest text-primary">Lead Qualified</p>
                        <p class="text-body-md">Presenting Calendly link now.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?= conversation_render_message_html($msg, $lead, [
                'rep_name'  => $repName,
                'lead_name' => (string) ($lead['name'] ?? 'Lead'),
            ]) ?>
            <?php
            $lastMessageId = max($lastMessageId, (int) ($msg['id'] ?? 0));
            endforeach; ?>
        </div>

        <!-- Desktop lead intel sidebar -->
        <aside class="client-lead-intel hidden md:flex md:flex-col w-72 shrink-0 border-l border-outline-variant bg-surface-container-lowest overflow-y-auto">
            <div class="p-md">
            <h3 class="font-title text-title-md mb-md">Lead Intel</h3>
            <div class="space-y-md text-body-md">
                <div>
                    <p class="text-label-sm text-outline uppercase font-label mb-xs">Budget Profile</p>
                    <p class="text-on-surface"><?= sanitize((string) $budget) ?></p>
                </div>
                <div>
                    <p class="text-label-sm text-outline uppercase font-label mb-xs">Primary Pain</p>
                    <p class="text-on-surface"><?= sanitize((string) $pain) ?></p>
                </div>
                <div>
                    <p class="text-label-sm text-outline uppercase font-label mb-xs">Engagement Score</p>
                    <div class="h-2 bg-surface-container rounded-full overflow-hidden mb-xs">
                        <div class="h-full bg-primary rounded-full" style="width: <?= min(100, max(0, $score)) ?>%"></div>
                    </div>
                    <p class="text-label-sm font-label"><?= $score ?>%</p>
                </div>
                <div>
                    <p class="text-label-sm text-outline uppercase font-label mb-xs">Platform</p>
                    <p class="capitalize"><?= sanitize($lead['platform']) ?></p>
                </div>
                <div>
                    <p class="text-label-sm text-outline uppercase font-label mb-xs">Assignment</p>
                    <form method="POST" class="space-y-xs">
                        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                        <input type="hidden" name="action" value="assign"/>
                        <select name="assigned_member_id" class="w-full h-10 px-sm rounded-lg bg-surface-container border border-outline-variant text-body-md">
                            <option value="">Unassigned</option>
                            <?php foreach ($teamMembers as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"<?= (int) ($lead['assigned_member_id'] ?? 0) === (int) $m['id'] ? ' selected' : '' ?>><?= sanitize($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="inbox_priority" class="w-full h-10 px-sm rounded-lg bg-surface-container border border-outline-variant text-body-md">
                            <?php foreach (['normal', 'high', 'urgent'] as $p): ?>
                            <option value="<?= sanitize($p) ?>"<?= ($lead['inbox_priority'] ?? 'normal') === $p ? ' selected' : '' ?>><?= sanitize(team_priority_label($p)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full h-10 rounded-lg bg-secondary text-on-secondary text-label-sm font-label">Update assignment</button>
                    </form>
                </div>
                <div>
                    <p class="text-label-sm text-outline uppercase font-label mb-xs">Internal notes</p>
                    <div class="space-y-xs mb-sm max-h-40 overflow-y-auto">
                        <?php if ($internalNotes === []): ?>
                        <p class="text-body-md text-on-surface-variant italic">No notes yet.</p>
                        <?php else: ?>
                        <?php foreach ($internalNotes as $note): ?>
                        <div class="p-sm rounded-lg bg-surface-container text-body-md">
                            <p><?= sanitize($note['note']) ?></p>
                            <p class="text-label-sm text-outline mt-xs"><?= sanitize($note['author_name']) ?> · <time class="js-local-time" datetime="<?= sanitize(datetime_to_iso($note['created_at'])) ?>" data-iso="<?= sanitize(datetime_to_iso($note['created_at'])) ?>"><?= sanitize(format_time($note['created_at'])) ?></time></p>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="flex gap-xs">
                        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                        <input type="hidden" name="action" value="add_note"/>
                        <input name="note" placeholder="Add internal note…" class="flex-1 h-10 px-sm rounded-lg bg-surface-container border border-outline-variant text-body-md"/>
                        <button type="submit" class="h-10 px-sm rounded-lg bg-primary text-on-primary text-label-sm font-label">Add</button>
                    </form>
                </div>
                <?php if (!empty($lead['calendly_link'])): ?>
                <a href="<?= sanitize($lead['calendly_link']) ?>" target="_blank" rel="noopener"
                   class="block w-full h-12 rounded-xl bg-primary text-on-primary text-center leading-[3rem] font-title text-title-md active:scale-95">
                    View CRM Profile
                </a>
                <?php endif; ?>
            </div>
            </div>
        </aside>
    </div>

    <!-- Message input -->
    <div class="conversation-composer safe-bottom">
        <?php if ($quickReplies !== []): ?>
        <div class="flex gap-xs overflow-x-auto pb-sm mb-sm">
            <?php foreach ($quickReplies as $qr): ?>
            <form method="POST" class="shrink-0">
                <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="quick_send"/>
                <input type="hidden" name="reply_id" value="<?= (int) $qr['id'] ?>"/>
                <button type="submit" class="h-9 px-md rounded-full bg-surface-container border border-outline-variant text-label-sm font-label whitespace-nowrap active:scale-95">
                    <?= sanitize($qr['title']) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="POST" class="space-y-sm">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
            <input type="hidden" name="action" value="send"/>
            <div class="flex items-center gap-sm bg-surface-container rounded-2xl p-xs focus-within:ring-2 focus-within:ring-secondary/50">
                <input type="file" id="conv-media-input" accept="image/jpeg,image/png,image/webp" class="hidden"/>
                <button type="button" id="conv-media-btn" class="p-base text-outline active:scale-95" title="Send image">
                    <span class="material-symbols-outlined">add_circle</span>
                </button>
                <input name="message" class="flex-1 bg-transparent border-none focus:ring-0 text-body-md py-xs placeholder:text-outline" placeholder="Type your reply…"/>
                <button type="submit" class="bg-secondary text-white w-10 h-10 rounded-xl flex items-center justify-center active:scale-95">
                    <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1">send</span>
                </button>
            </div>
            <div class="conversation-composer__status">
                <div class="conversation-composer__pill">
                    <div id="conv-status-dot" class="w-2 h-2 rounded-full ai-pulse <?= $aiCopilotOn ? 'bg-primary' : 'bg-error' ?>"></div>
                    <span id="conv-status-label" class="conversation-composer__pill-label">
                        <?= $aiCopilotOn ? sanitize($repName) . ' · Auto-replies on' : 'Human takeover' ?>
                    </span>
                </div>
                <p class="conversation-composer__hint">Intervening pauses auto-replies for 60 min.</p>
            </div>
        </form>
    </div>
</div>
<?php client_layout_end(); ?>
<?php client_shell_end(); ?>

<!-- Mobile bottom sheet: Lead Intel -->
<div id="lead-intel-overlay" class="bottom-sheet-overlay md:hidden" onclick="App.closeBottomSheet('lead-intel')"></div>
<div id="lead-intel" class="bottom-sheet md:hidden">
    <div class="bottom-sheet-handle"></div>
    <div class="px-edge-margin pb-lg">
        <h3 class="font-headline text-xl mb-md">Lead Intel</h3>
        <div class="space-y-md text-body-md">
            <div>
                <p class="text-label-sm text-outline uppercase font-label mb-xs">Budget Profile</p>
                <p><?= sanitize((string) $budget) ?></p>
            </div>
            <div>
                <p class="text-label-sm text-outline uppercase font-label mb-xs">Primary Pain</p>
                <p><?= sanitize((string) $pain) ?></p>
            </div>
            <div>
                <p class="text-label-sm text-outline uppercase font-label mb-xs">Engagement Score</p>
                <div class="h-2 bg-surface-container rounded-full overflow-hidden mb-xs">
                    <div class="h-full bg-primary rounded-full" style="width: <?= min(100, max(0, $score)) ?>%"></div>
                </div>
                <p class="text-label-sm font-label"><?= $score ?>%</p>
            </div>
            <?php if (!empty($lead['calendly_link'])): ?>
            <a href="<?= sanitize($lead['calendly_link']) ?>" target="_blank" rel="noopener"
               class="block w-full h-14 rounded-xl bg-primary text-on-primary text-center leading-[3.5rem] font-title text-title-md active:scale-95">
                View CRM Profile
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.IQPigeonConversation = {
    leadId: <?= (int) $leadId ?>,
    lastId: <?= (int) $lastMessageId ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    repName: <?= json_encode($repName) ?>,
    leadName: <?= json_encode((string) ($lead['name'] ?? 'Lead')) ?>,
    leadInitial: <?= json_encode(get_lead_initial($lead['name'] ?? 'L')) ?>
};
document.addEventListener('DOMContentLoaded', () => {
    const thread = document.getElementById('conversation-thread');
    if (thread) thread.scrollTop = thread.scrollHeight;
});
</script>
<script src="/assets/js/conversation.js?v=<?= @filemtime(__DIR__ . '/../assets/js/conversation.js') ?: time() ?>"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
