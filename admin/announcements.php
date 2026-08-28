<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();
iqp_ensure_ops_schema();

$message = '';
$error   = '';

// The canonical iqp_announcements table (created by the frozen iqp_ensure_ops_schema)
// only has id/title/body/status/scheduled_at/created_at. Extend it in-place with two
// nullable columns for audience + channels so the composer can persist them, without
// touching the frozen include. Degrades gracefully if the ALTER cannot run.
$hasExtras = false;
try {
    $conn = db_connect();
    if (!db_column_exists('iqp_announcements', 'audience')) {
        $conn->query('ALTER TABLE iqp_announcements ADD COLUMN audience VARCHAR(190) NULL');
    }
    if (!db_column_exists('iqp_announcements', 'channels')) {
        $conn->query('ALTER TABLE iqp_announcements ADD COLUMN channels VARCHAR(190) NULL');
    }
    $hasExtras = db_column_exists('iqp_announcements', 'audience', true)
        && db_column_exists('iqp_announcements', 'channels', true);
} catch (Throwable $e) {
    $hasExtras = false;
}

$channelLabels = ['inapp' => 'In-App', 'email' => 'Email', 'whatsapp' => 'WhatsApp'];

// ---- Audience options (stored as plain text) ----
$plans = function_exists('get_plans_merged')
    ? get_plans_merged()
    : (function_exists('get_plans') ? get_plans() : []);
$audienceOptions = ['All businesses', 'Trial users'];
if (is_array($plans)) {
    foreach ($plans as $slug => $plan) {
        if (!is_array($plan)) {
            continue;
        }
        $name = trim((string) ($plan['name'] ?? $slug));
        if ($name !== '') {
            $audienceOptions[] = $name . ' plan';
        }
    }
}
$audienceOptions = array_values(array_unique($audienceOptions));

// ---- POST: create announcement (draft / scheduled / published) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $title    = trim((string) ($_POST['title'] ?? ''));
    $body     = trim((string) ($_POST['body'] ?? ''));
    $audience = trim((string) ($_POST['audience'] ?? 'All businesses'));
    $action   = ($_POST['action'] ?? 'publish') === 'draft' ? 'draft' : 'publish';
    $mode     = ($_POST['schedule_mode'] ?? 'now') === 'schedule' ? 'schedule' : 'now';

    $postedChannels = $_POST['channels'] ?? [];
    if (!is_array($postedChannels)) {
        $postedChannels = [];
    }
    $channels = array_values(array_intersect($postedChannels, array_keys($channelLabels)));
    $channelsText = implode(',', $channels);

    $status      = 'published';
    $scheduledAt = null;
    if ($action === 'draft') {
        $status = 'draft';
    } elseif ($mode === 'schedule') {
        $status  = 'scheduled';
        $rawWhen = trim((string) ($_POST['scheduled_at'] ?? ''));
        $ts      = $rawWhen !== '' ? strtotime($rawWhen) : false;
        if ($ts === false) {
            $error = 'Please choose a valid date and time to schedule this announcement.';
        } else {
            $scheduledAt = date('Y-m-d H:i:s', $ts);
        }
    }

    if ($error === '') {
        if ($title === '' || $body === '') {
            $error = 'Title and message are both required.';
        } else {
            try {
                if ($hasExtras) {
                    db_insert(
                        'INSERT INTO iqp_announcements (title, body, status, scheduled_at, audience, channels)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        'ssssss',
                        [$title, $body, $status, $scheduledAt, $audience, $channelsText]
                    );
                } else {
                    db_insert(
                        'INSERT INTO iqp_announcements (title, body, status, scheduled_at)
                         VALUES (?, ?, ?, ?)',
                        'ssss',
                        [$title, $body, $status, $scheduledAt]
                    );
                }

                if ($status === 'published') {
                    // Actually broadcast (in-app notifications + email to subscribers).
                    if (function_exists('publish_system_update')) {
                        $res = publish_system_update((int) $user['id'], $title, $body);
                        if (!empty($res['success'])) {
                            $message = 'Announcement published. ' . (string) ($res['message'] ?? '');
                        } else {
                            $message = 'Announcement saved and marked published. Broadcast note: '
                                . (string) ($res['message'] ?? 'notifications unavailable.');
                        }
                    } else {
                        $message = 'Announcement published.';
                    }
                } elseif ($status === 'scheduled') {
                    $message = 'Announcement scheduled for '
                        . date('d M, Y g:i A', strtotime((string) $scheduledAt)) . '.';
                } else {
                    $message = 'Draft saved.';
                }
            } catch (Throwable $e) {
                $error = 'Could not save the announcement. ' . $e->getMessage();
            }
        }
    }
}

// ---- Tab (server-synced with $_GET['tab'] for deep-links) ----
$validTabs = ['all', 'published', 'scheduled', 'draft'];
$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'all')) ?: 'all';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

// ---- Stats (accurate totals, independent of the list limit) ----
$stats = ['total' => 0, 'published' => 0, 'scheduled' => 0, 'draft' => 0];
try {
    $rows = db_fetch_all('SELECT status, COUNT(*) AS cnt FROM iqp_announcements GROUP BY status', '', []);
    foreach ($rows as $r) {
        $st = (string) ($r['status'] ?? '');
        $c  = (int) ($r['cnt'] ?? 0);
        $stats['total'] += $c;
        if (isset($stats[$st])) {
            $stats[$st] += $c;
        }
    }
} catch (Throwable $e) {
    // leave zeros
}

// ---- List (fetch once, partition per-status so client-side tab switching stays correct) ----
$selectCols = $hasExtras
    ? 'id, title, body, status, scheduled_at, created_at, audience, channels'
    : 'id, title, body, status, scheduled_at, created_at';
$all = [];
try {
    $all = db_fetch_all("SELECT $selectCols FROM iqp_announcements ORDER BY created_at DESC LIMIT 100", '', []);
} catch (Throwable $e) {
    $all = [];
}
$groups = [
    'all'       => $all,
    'published' => array_values(array_filter($all, static fn ($a) => ($a['status'] ?? '') === 'published')),
    'scheduled' => array_values(array_filter($all, static fn ($a) => ($a['status'] ?? '') === 'scheduled')),
    'draft'     => array_values(array_filter($all, static fn ($a) => ($a['status'] ?? '') === 'draft')),
];

// ---- Composer form state (repopulate on validation error, reset after success) ----
$fTitle    = '';
$fBody     = '';
$fAudience = 'All businesses';
$fChannels = ['inapp', 'email'];
$fMode     = 'now';
$fWhen     = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fTitle    = (string) ($_POST['title'] ?? '');
    $fBody     = (string) ($_POST['body'] ?? '');
    $fAudience = (string) ($_POST['audience'] ?? $fAudience);
    $fChannels = is_array($_POST['channels'] ?? null) ? $_POST['channels'] : [];
    $fMode     = ($_POST['schedule_mode'] ?? 'now') === 'schedule' ? 'schedule' : 'now';
    $fWhen     = (string) ($_POST['scheduled_at'] ?? '');
}
if ($message !== '') {
    // clean slate after a successful save
    $fTitle    = '';
    $fBody     = '';
    $fAudience = 'All businesses';
    $fChannels = ['inapp', 'email'];
    $fMode     = 'now';
    $fWhen     = '';
}

$tabLabels = [
    'all'       => 'All',
    'published' => 'Published',
    'scheduled' => 'Scheduled',
    'draft'     => 'Draft',
];

$renderList = static function (array $items) use ($hasExtras, $channelLabels) {
    $cols = $hasExtras ? 5 : 3;
    ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Announcement</th>
        <?php if ($hasExtras): ?><th>Audience</th><th>Channels</th><?php endif; ?>
        <th>Status</th>
        <th class="nowrap">Time</th>
      </tr></thead>
      <tbody>
      <?php if ($items === []): ?>
        <tr><td colspan="<?= $cols ?>" class="muted">No announcements here yet.</td></tr>
      <?php else: foreach ($items as $a):
          $st    = (string) ($a['status'] ?? 'draft');
          $badge = $st === 'published' ? 'badge--green' : ($st === 'scheduled' ? 'badge--amber' : 'badge--gray');
          $bodyRaw = trim((string) ($a['body'] ?? ''));
          $snippet = trim(preg_replace('/\s+/', ' ', $bodyRaw));
          $snippet = function_exists('mb_strimwidth')
              ? mb_strimwidth($snippet, 0, 90, '…')
              : (strlen($snippet) > 88 ? substr($snippet, 0, 88) . '…' : $snippet);
          $whenRaw = ($st === 'scheduled' && !empty($a['scheduled_at']))
              ? (string) $a['scheduled_at']
              : (string) ($a['created_at'] ?? '');
          $when = iqp_rel_time($whenRaw);
          $chParts   = array_filter(array_map('trim', explode(',', (string) ($a['channels'] ?? ''))));
          $chDisplay = implode(', ', array_map(static fn ($c) => $channelLabels[$c] ?? $c, $chParts));
      ?>
        <tr>
          <td>
            <div class="strong"><?= sanitize((string) ($a['title'] ?? 'Untitled')) ?></div>
            <?php if ($snippet !== ''): ?><div class="muted small"><?= sanitize($snippet) ?></div><?php endif; ?>
          </td>
          <?php if ($hasExtras): ?>
            <td class="muted"><?= sanitize((string) ($a['audience'] ?? '') ?: '—') ?></td>
            <td class="muted"><?= sanitize($chDisplay ?: '—') ?></td>
          <?php endif; ?>
          <td><span class="badge <?= $badge ?>"><span class="dot"></span><?= sanitize(ucfirst($st)) ?></span></td>
          <td class="muted nowrap"><?= sanitize($when ?: '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <?php
};

iqp_admin_begin($user, 'announcements', [
    'title'    => 'Announcements',
    'subtitle' => 'Broadcast system updates and platform announcements to businesses',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/subscribers">Manage subscribers</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="megaphone"></span></span><div class="grow"><div class="stat-card__label">Total</div><div class="stat-card__value"><?= (int) ($stats['total'] ?? 0) ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span><div class="grow"><div class="stat-card__label">Published</div><div class="stat-card__value"><?= (int) ($stats['published'] ?? 0) ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="clock"></span></span><div class="grow"><div class="stat-card__label">Scheduled</div><div class="stat-card__value"><?= (int) ($stats['scheduled'] ?? 0) ?></div></div></div></div>
  <div class="stat-card"><div class="stat-card__top"><span class="stat-tile purple"><span class="ic" data-ic="filetext"></span></span><div class="grow"><div class="stat-card__label">Drafts</div><div class="stat-card__value"><?= (int) ($stats['draft'] ?? 0) ?></div></div></div></div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 380px;align-items:start;gap:16px">
  <!-- Left: tabbed list -->
  <div class="card">
    <div style="padding:12px 16px 8px"><nav class="iqp-tab-nav iqp-tab-nav--admin iqp-tab-nav--pills" aria-label="Announcement filters"><div class="pill-tabs iqp-tab-nav__grid" data-tabset="ann">
      <?php foreach ($tabLabels as $id => $label): ?>
      <button type="button" class="pill-tab<?= $tab === $id ? ' is-active' : '' ?>" data-tab="<?= sanitize($id) ?>"><?= sanitize($label) ?></button>
      <?php endforeach; ?>
    </div></nav></div>
    <div class="card__body" style="padding:0" data-panelset="ann">
      <?php foreach ($tabLabels as $id => $label): ?>
      <div class="tab-panel<?= $tab === $id ? ' is-active' : '' ?>" data-panel="<?= sanitize($id) ?>">
        <?php $renderList($groups[$id] ?? []); ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Right: composer -->
  <div class="card" style="align-self:start">
    <div class="card__head"><span class="card__title">Create announcement</span></div>
    <form method="post" class="card__body">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

      <div class="eyebrow">1 · Content</div>
      <div class="field"><label>Title</label>
        <input class="input" name="title" required maxlength="255" placeholder="e.g. New feature: AI voice notes" value="<?= sanitize($fTitle) ?>">
      </div>
      <div class="field"><label>Message</label>
        <textarea class="textarea" name="body" required rows="5" placeholder="Write your announcement…"><?= sanitize($fBody) ?></textarea>
      </div>

      <div class="divider"></div>
      <div class="eyebrow">2 · Audience</div>
      <div class="field"><label>Send to</label>
        <select class="select" name="audience">
          <?php foreach ($audienceOptions as $opt): ?>
          <option<?= $fAudience === $opt ? ' selected' : '' ?>><?= sanitize($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$hasExtras): ?><div class="muted small" style="margin-top:6px">Audience is recorded per broadcast; storage column unavailable on this database.</div><?php endif; ?>
      </div>

      <div class="divider"></div>
      <div class="eyebrow">3 · Channels</div>
      <div class="field">
        <label class="checkbox" style="margin-bottom:6px"><input type="checkbox" name="channels[]" value="inapp" <?= in_array('inapp', $fChannels, true) ? 'checked' : '' ?>> <span class="ic" data-ic="bell"></span> In-App notification</label>
        <label class="checkbox" style="margin-bottom:6px"><input type="checkbox" name="channels[]" value="email" <?= in_array('email', $fChannels, true) ? 'checked' : '' ?>> <span class="ic" data-ic="mail"></span> Email to subscribers</label>
        <label class="checkbox"><input type="checkbox" name="channels[]" value="whatsapp" <?= in_array('whatsapp', $fChannels, true) ? 'checked' : '' ?>> <span class="ic" data-ic="whatsapp"></span> WhatsApp</label>
      </div>

      <div class="divider"></div>
      <div class="eyebrow">4 · Schedule</div>
      <div class="field">
        <label class="checkbox" style="margin-bottom:6px"><input type="radio" name="schedule_mode" value="now" <?= $fMode !== 'schedule' ? 'checked' : '' ?>> <span>Send now — publish immediately</span></label>
        <label class="checkbox"><input type="radio" name="schedule_mode" value="schedule" <?= $fMode === 'schedule' ? 'checked' : '' ?>> <span>Schedule for later</span></label>
        <div id="schWhen" style="margin-top:8px<?= $fMode === 'schedule' ? '' : ';display:none' ?>">
          <input class="input" type="datetime-local" name="scheduled_at" value="<?= sanitize($fWhen) ?>">
        </div>
      </div>

      <div class="row" style="gap:10px;margin-top:6px">
        <button class="btn btn--ghost grow" type="submit" name="action" value="draft">Save draft</button>
        <button class="btn btn--primary grow" type="submit" name="action" value="publish"><span class="ic" data-ic="send"></span> Publish</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var box = document.getElementById('schWhen');
  if (!box) return;
  var radios = document.querySelectorAll('input[name="schedule_mode"]');
  function sync() {
    var sel = document.querySelector('input[name="schedule_mode"]:checked');
    box.style.display = (sel && sel.value === 'schedule') ? '' : 'none';
  }
  radios.forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php iqp_admin_end();
