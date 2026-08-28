<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/platform-training.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$botId = (int) ($_GET['id'] ?? 0);
$bot = db_fetch(
    'SELECT b.*, u.company_name, u.email AS client_email, u.name AS client_name
     FROM bots b
     JOIN users u ON u.id = b.user_id
     WHERE b.id = ?',
    'i',
    [$botId]
);

if (!$bot) {
    redirect('/admin/bots');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        db_execute(
            'UPDATE bots SET name = ?, is_active = ?, widget_enabled = ? WHERE id = ?',
            'siii',
            [
                trim($_POST['name'] ?? $bot['name']),
                !empty($_POST['is_active']) ? 1 : 0,
                !empty($_POST['widget_enabled']) ? 1 : 0,
                $botId,
            ]
        );
        $message = 'Bot updated.';
        $bot = db_fetch(
            'SELECT b.*, u.company_name, u.email AS client_email, u.name AS client_name
             FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
            'i',
            [$botId]
        );
    } elseif ($action === 'set_demo') {
        db_execute(
            'INSERT INTO settings (key_name, value) VALUES (\'demo_bot_id\', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            's',
            [(string) $botId]
        );
        db_execute('UPDATE bots SET widget_enabled = 1, is_active = 1 WHERE id = ?', 'i', [$botId]);
        $message = 'This bot is now the public live demo.';
    } elseif ($action === 'disconnect_whatsapp') {
        db_execute(
            'UPDATE bots SET whatsapp_phone_id = NULL, whatsapp_token = NULL, whatsapp_verified = 0 WHERE id = ?',
            'i',
            [$botId]
        );
        $message = 'WhatsApp disconnected from this bot.';
        $bot = db_fetch(
            'SELECT b.*, u.company_name, u.email AS client_email, u.name AS client_name
             FROM bots b JOIN users u ON u.id = b.user_id WHERE b.id = ?',
            'i',
            [$botId]
        );
    } elseif ($action === 'delete') {
        $demoId = get_setting('demo_bot_id', '');
        if ($demoId === (string) $botId) {
            db_execute('DELETE FROM settings WHERE key_name = \'demo_bot_id\'', '', []);
        }
        db_execute('DELETE FROM bots WHERE id = ?', 'i', [$botId]);
        redirect('/admin/bots?deleted=1');
    }
}

$leadStats = db_fetch(
    'SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = \'qualified\' THEN 1 ELSE 0 END) AS qualified
     FROM leads WHERE bot_id = ?',
    'i',
    [$botId]
);

$demoBotId = get_setting('demo_bot_id', defined('DEMO_BOT_ID') && DEMO_BOT_ID > 0 ? (string) DEMO_BOT_ID : '');
$isDemoBot = (string) $botId === (string) $demoBotId;
$hasKnowledge = trim($bot['bot_knowledge'] ?? '') !== '';
$questions = json_decode($bot['qualifying_questions'] ?? '[]', true) ?: [];

$activeTab = 'bots';

$planPill = match(true) {
    str_contains(strtolower((string)($bot['subscription_plan'] ?? '')), 'pro') => 'badge--green',
    str_contains(strtolower((string)($bot['subscription_plan'] ?? '')), 'business') => 'badge--purple',
    default => 'badge--gray',
};

iqp_admin_begin($user, 'businesses', [
    'title'    => sanitize((string)($bot['name'] ?? 'Bot')),
    'subtitle' => sanitize(($bot['company_name'] ?? '') . ' Â· ' . ($bot['client_email'] ?? '')),
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=' . (int)$bot['user_id'] . '">â† Back to Client</a>',
]);
iqp_flash($message);
if ($error !== '') iqp_flash($error, 'err');
?>

<!-- Stat tiles -->
<div class="stat-grid cols-3" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="user"></span></span>
      <div class="grow"><div class="stat-card__label">Total Leads</div><div class="stat-card__value"><?= (int)($leadStats['total'] ?? 0) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= (int)($leadStats['qualified'] ?? 0) ?> qualified</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-tile <?= $bot['whatsapp_verified'] ? 'green' : 'red' ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
      </span>
      <div class="grow"><div class="stat-card__label">WhatsApp</div><div class="stat-card__value" style="font-size:18px"><?= $bot['whatsapp_verified'] ? 'Connected' : 'Not set' ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= $bot['widget_enabled'] ? 'Widget on' : 'Widget off' ?></span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile <?= $bot['is_active'] ? 'green' : 'amber' ?>"><span class="ic" data-ic="checkcircle"></span></span>
      <div class="grow"><div class="stat-card__label">Status</div><div class="stat-card__value" style="font-size:18px"><?= $bot['is_active'] ? 'Active' : 'Inactive' ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= $isDemoBot ? 'â˜… Public demo' : 'Standard bot' ?></span></div>
  </div>
</div>

<!-- Main grid -->
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;gap:18px;align-items:start">

  <!-- Left: Bot settings + knowledge -->
  <div class="stack">

    <!-- Bot Settings -->
    <div class="card">
      <div class="card__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green-600)" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 11V9M16 11V9"/></svg>
        <span class="card__title">Bot Settings</span>
      </div>
      <div class="card__body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="update"/>
          <div class="field"><label class="form-label">Bot Name</label>
            <input type="text" name="name" class="input" value="<?= sanitize((string)($bot['name'] ?? '')) ?>"/>
          </div>
          <div class="field">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink)">
              <input type="checkbox" name="is_active" value="1" <?= $bot['is_active'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--green-600)"/>
              Bot active (responds to messages)
            </label>
          </div>
          <div class="field" style="margin-bottom:0">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink)">
              <input type="checkbox" name="widget_enabled" value="1" <?= $bot['widget_enabled'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--green-600)"/>
              Website widget enabled
            </label>
          </div>
          <div style="margin-top:16px">
            <button type="submit" class="btn btn--primary">Save Bot</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Training / Knowledge (read-only) -->
    <div class="card">
      <div class="card__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue-600)" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        <span class="card__title">Training / Knowledge</span>
        <span class="spacer"></span>
        <a class="view-all" href="/admin/client-detail?id=<?= (int)$bot['user_id'] ?>">View client â†’</a>
      </div>
      <div class="card__body">
        <div class="grid grid-2" style="margin-bottom:14px">
          <div><div class="eyebrow" style="margin-bottom:3px">Rep Name</div><div class="strong"><?= sanitize((string)($bot['rep_name'] ?? 'â€”')) ?></div></div>
          <div><div class="eyebrow" style="margin-bottom:3px">Website</div><div><?= !empty($bot['website_url']) ? '<a href="' . sanitize((string)$bot['website_url']) . '" target="_blank" style="color:var(--green-700)">' . sanitize((string)$bot['website_url']) . '</a>' : '<span class="muted">â€”</span>' ?></div></div>
          <div><div class="eyebrow" style="margin-bottom:3px">Knowledge</div><div class="strong"><?= $hasKnowledge ? number_format(strlen((string)$bot['bot_knowledge'])) . ' characters' : '<span class="muted">Not configured</span>' ?></div></div>
          <div><div class="eyebrow" style="margin-bottom:3px">Business Model</div><div class="strong"><?= !empty($bot['business_model']) ? mb_strimwidth((string)$bot['business_model'], 0, 60, 'â€¦') : '<span class="muted">â€”</span>' ?></div></div>
        </div>
        <?php if ($hasKnowledge): ?>
        <details style="border:1px solid var(--line-2);border-radius:10px">
          <summary style="padding:12px 16px;cursor:pointer;font-weight:600;font-size:13.5px">Full knowledge document</summary>
          <pre style="padding:14px 16px;font-size:12.5px;white-space:pre-wrap;word-break:break-word;background:var(--field);border-radius:0 0 10px 10px;max-height:300px;overflow-y:auto;font-family:inherit"><?= sanitize((string)$bot['bot_knowledge']) ?></pre>
        </details>
        <?php endif; ?>
      </div>
    </div>

    <!-- Admin actions -->
    <div class="card">
      <div class="card__head"><span class="card__title">Admin Actions</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="set_demo"/>
          <button type="submit" class="btn btn--soft btn--block" style="justify-content:flex-start;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <?= $isDemoBot ? 'Already the public demo' : 'Set as Public Demo Bot' ?>
          </button>
        </form>
        <?php if ($bot['whatsapp_verified']): ?>
        <form method="POST" onsubmit="return confirm('Disconnect WhatsApp from this bot?')">
          <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="disconnect_whatsapp"/>
          <button type="submit" class="btn btn--ghost btn--block" style="justify-content:flex-start;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
            Disconnect WhatsApp
          </button>
        </form>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Delete this bot and all its leads? This cannot be undone.')">
          <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="delete"/>
          <button type="submit" class="btn btn--danger btn--block" style="justify-content:flex-start;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            Delete Bot
          </button>
        </form>
      </div>
    </div>

  </div><!-- /left -->

  <!-- Right: summary -->
  <div class="stack" style="position:sticky;top:16px">

    <div class="card">
      <div class="card__head"><span class="card__title">Bot Info</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
        <div class="between"><span class="muted small">Bot ID</span><span class="strong small">#<?= (int)$bot['id'] ?></span></div>
        <div class="between"><span class="muted small">Owner</span><a href="/admin/client-detail?id=<?= (int)$bot['user_id'] ?>" style="color:var(--green-700);font-size:13px"><?= sanitize((string)($bot['company_name'] ?: $bot['client_name'] ?? 'â€”')) ?></a></div>
        <div class="between"><span class="muted small">Email</span><span class="strong small"><?= sanitize((string)($bot['client_email'] ?? 'â€”')) ?></span></div>
        <div class="between"><span class="muted small">WhatsApp</span><span class="badge <?= $bot['whatsapp_verified'] ? 'badge--green' : 'badge--gray' ?>" style="font-size:11px"><span class="dot"></span><?= $bot['whatsapp_verified'] ? 'Connected' : 'Not set' ?></span></div>
        <div class="between"><span class="muted small">Widget</span><span class="badge <?= $bot['widget_enabled'] ? 'badge--blue' : 'badge--gray' ?>" style="font-size:11px"><?= $bot['widget_enabled'] ? 'Enabled' : 'Disabled' ?></span></div>
        <div class="divider"></div>
        <a class="btn btn--ghost btn--sm btn--block" href="/admin/conversations?client=<?= (int)$bot['user_id'] ?>" style="justify-content:flex-start;gap:8px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          View Conversations
        </a>
        <a class="btn btn--ghost btn--sm btn--block" href="/admin/client-detail?id=<?= (int)$bot['user_id'] ?>" style="justify-content:flex-start;gap:8px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          View Client Profile
        </a>
        <a class="btn btn--ghost btn--sm btn--block" href="/demo" target="_blank" rel="noopener" style="justify-content:flex-start;gap:8px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Preview Live Demo
        </a>
      </div>
    </div>

  </div><!-- /right -->
</div><!-- /grid -->
<?php iqp_admin_end();

