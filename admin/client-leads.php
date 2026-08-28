<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

/* Best-effort schema guards — the queries below still degrade to empty states. */
try {
    ensure_leads_schema();
} catch (Throwable $e) {
    // ignore
}
try {
    ensure_conversations_schema();
} catch (Throwable $e) {
    // ignore
}

/* -------------------------------------------------------------------------
 * Scope to ONE business — SAME $_GET key as the original page: `id`
 * (= users.id where role = 'client'). Bad ids fall back to the list.
 * ---------------------------------------------------------------------- */
$clientId = (int) ($_GET['id'] ?? 0);
$client = null;
try {
    $client = db_fetch("SELECT * FROM users WHERE id = ? AND role = 'client'", 'i', [$clientId]);
} catch (Throwable $e) {
    $client = null;
}
if (!$client) {
    redirect('/admin/businesses');
}

/* -------------------------------------------------------------------------
 * Inputs — status tab synced to $_GET['tab'] (legacy ?status= honored),
 * free-text search (q). Param keys preserved from the original page.
 * ---------------------------------------------------------------------- */
$validTabs = ['all', 'new', 'in_progress', 'qualified', 'booked', 'disqualified'];
$tab = preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? $_GET['status'] ?? 'all'));
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

/* Guarded optional columns. Phone data lives in external_id on this schema. */
$hasPhoneCol = false;
try {
    $hasPhoneCol = db_column_exists('leads', 'phone');
} catch (Throwable $e) {
    $hasPhoneCol = false;
}
$hasPlatformCol = false;
try {
    $hasPlatformCol = db_column_exists('leads', 'platform');
} catch (Throwable $e) {
    $hasPlatformCol = false;
}

$phoneExpr      = $hasPhoneCol ? "COALESCE(NULLIF(l.phone, ''), l.external_id)" : 'l.external_id';
$platformSelect = $hasPlatformCol ? 'l.platform AS platform,' : "'' AS platform,";
$activityExpr   = lead_last_activity_sql('l');

/* -------------------------------------------------------------------------
 * Data — one row per lead for THIS business. Degrades gracefully.
 * ---------------------------------------------------------------------- */
$loadError = '';
$rows = [];
$agg = [];

try {
    $agg = db_fetch(
        "SELECT COUNT(*)                                                AS total,
                SUM(CASE WHEN l.status = 'new'       THEN 1 ELSE 0 END) AS new_cnt,
                SUM(CASE WHEN l.status = 'qualified' THEN 1 ELSE 0 END) AS qualified,
                SUM(CASE WHEN l.status = 'booked'    THEN 1 ELSE 0 END) AS booked
         FROM leads l
         JOIN bots b ON b.id = l.bot_id
         WHERE b.user_id = ?",
        'i',
        [$clientId]
    ) ?: [];

    $sql = "SELECT l.id, l.name AS lead_name, {$phoneExpr} AS lead_phone, l.status, l.score,
                   {$platformSelect}
                   b.name AS bot_name,
                   {$activityExpr} AS last_activity_at,
                   (SELECT c.message FROM conversations c
                     WHERE c.lead_id = l.id
                     ORDER BY c.created_at DESC, c.id DESC LIMIT 1) AS last_message
            FROM leads l
            JOIN bots b ON b.id = l.bot_id
            WHERE b.user_id = ?";
    $types = 'i';
    $params = [$clientId];
    if ($q !== '') {
        $sql .= " AND (l.name LIKE ? OR {$phoneExpr} LIKE ? OR b.name LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY last_activity_at DESC LIMIT 500';

    $rows = db_fetch_all($sql, $types, $params);
} catch (Throwable $e) {
    $loadError = 'Some lead data could not be loaded.';
    $rows = [];
    $agg = [];
}

/* -------------------------------------------------------------------------
 * Derived view data.
 * ---------------------------------------------------------------------- */
$total     = (int) ($agg['total'] ?? 0);
$newCount  = (int) ($agg['new_cnt'] ?? 0);
$qualified = (int) ($agg['qualified'] ?? 0);
$booked    = (int) ($agg['booked'] ?? 0);

$bizName  = trim((string) ($client['company_name'] ?? ''));
if ($bizName === '') {
    $bizName = trim((string) ($client['name'] ?? '')) ?: 'Business';
}
$bizEmail = trim((string) ($client['email'] ?? ''));

$tabsCfg = [
    'all'          => 'All',
    'new'          => 'New',
    'in_progress'  => 'In Progress',
    'qualified'    => 'Qualified',
    'booked'       => 'Booked',
    'disqualified' => 'Disqualified',
];
$statusMeta = [
    'new'          => ['label' => 'New',          'badge' => 'badge--blue'],
    'in_progress'  => ['label' => 'In Progress',  'badge' => 'badge--amber'],
    'qualified'    => ['label' => 'Qualified',    'badge' => 'badge--cyan'],
    'booked'       => ['label' => 'Booked',       'badge' => 'badge--green'],
    'disqualified' => ['label' => 'Disqualified', 'badge' => 'badge--gray'],
];

$counts = ['all' => count($rows), 'new' => 0, 'in_progress' => 0, 'qualified' => 0, 'booked' => 0, 'disqualified' => 0];
foreach ($rows as $r) {
    $st = (string) ($r['status'] ?? '');
    if ($st !== 'all' && isset($counts[$st])) {
        $counts[$st]++;
    }
}

/**
 * Render <tbody> rows for one status filter ('all' = every row).
 */
$renderBody = static function (string $filter) use ($rows, $statusMeta, $clientId): string {
    $html = '';
    foreach ($rows as $r) {
        $st = (string) ($r['status'] ?? '');
        if ($filter !== 'all' && $st !== $filter) {
            continue;
        }

        $leadId  = (int) ($r['id'] ?? 0);
        $name    = trim((string) ($r['lead_name'] ?? ''));
        $phone   = trim((string) ($r['lead_phone'] ?? ''));
        $display = $name !== '' ? $name : ($phone !== '' ? $phone : 'Unknown');

        $bot   = trim((string) ($r['bot_name'] ?? ''));
        $plat  = trim((string) ($r['platform'] ?? ''));
        $score = (int) ($r['score'] ?? 0);
        $meta  = $statusMeta[$st] ?? ['label' => (ucwords(str_replace('_', ' ', $st)) ?: 'Unknown'), 'badge' => 'badge--gray'];

        $updated = iqp_rel_time((string) ($r['last_activity_at'] ?? ''));
        $href    = '/admin/conversation?lead_id=' . $leadId . '&client_id=' . (int) $clientId;

        // Secondary line: bot · channel.
        $sub = $bot;
        if ($plat !== '') {
            $sub = ($sub !== '' ? $sub . ' · ' : '') . ucfirst($plat);
        }

        // Latest message snippet — collapse whitespace, truncate, always sanitize.
        $snippet = trim((string) preg_replace('/\s+/', ' ', (string) ($r['last_message'] ?? '')));
        if ($snippet !== '') {
            if (function_exists('mb_strimwidth')) {
                $snippet = mb_strimwidth($snippet, 0, 80, '…', 'UTF-8');
            } elseif (strlen($snippet) > 80) {
                $snippet = substr($snippet, 0, 79) . '…';
            }
        }

        $leadCell = '<a class="strong" href="' . sanitize($href) . '">' . sanitize($display) . '</a>';
        if ($sub !== '') {
            $leadCell .= '<div class="muted small">' . sanitize($sub) . '</div>';
        }
        if ($snippet !== '') {
            $leadCell .= '<div class="muted small" style="font-style:italic">“' . sanitize($snippet) . '”</div>';
        }

        $html .= '<tr>'
            . '<td>' . $leadCell . '</td>'
            . '<td class="muted nowrap">' . sanitize($phone !== '' ? $phone : '—') . '</td>'
            . '<td><span class="badge ' . $meta['badge'] . '"><span class="dot"></span>' . sanitize($meta['label']) . '</span></td>'
            . '<td class="right">' . ($score > 0 ? '<span class="strong">' . $score . '</span>' : '<span class="muted">0</span>') . '</td>'
            . '<td class="muted nowrap">' . sanitize($updated !== '' ? $updated : '—') . '</td>'
            . '<td><div class="row-actions"><a class="ic" href="' . sanitize($href) . '" title="Open conversation" data-ic="eye"></a></div></td>'
            . '</tr>';
    }

    if ($html === '') {
        $html = '<tr><td colspan="6" class="muted" style="text-align:center;padding:28px">'
            . ($filter === 'all' ? 'No leads yet for this business.' : 'No leads in this status.')
            . '</td></tr>';
    }

    return $html;
};

/* -------------------------------------------------------------------------
 * Shell — back-links live in the page-head actions.
 * ---------------------------------------------------------------------- */
$headActions =
    '<a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=' . (int) $clientId . '"><span class="ic" data-ic="building"></span> Business profile</a>'
    . '<a class="btn btn--ghost btn--sm" href="/admin/businesses">All businesses</a>';

iqp_admin_begin($user, 'businesses', [
    'title'    => 'Leads — ' . $bizName,
    'subtitle' => $bizEmail !== '' ? $bizEmail : 'Leads & chats for this business',
    'actions'  => $headActions,
]);
if ($loadError !== '') {
    iqp_flash($loadError, 'err');
}
?>
<div class="stat-grid cols-4">
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile blue" data-ic="users"></span>
      <div class="grow"><div class="stat-card__label">Total Leads</div><div class="stat-card__value"><?= number_format($total) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">for this business</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile amber" data-ic="sparkles"></span>
      <div class="grow"><div class="stat-card__label">New</div><div class="stat-card__value"><?= number_format($newCount) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">awaiting triage</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile cyan" data-ic="checkcircle"></span>
      <div class="grow"><div class="stat-card__label">Qualified</div><div class="stat-card__value"><?= number_format($qualified) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">ready to convert</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile green" data-ic="calendar"></span>
      <div class="grow"><div class="stat-card__label">Booked</div><div class="stat-card__value"><?= number_format($booked) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">appointments won</span></div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card__head">
    <form method="GET" action="/admin/client-leads" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;flex:1 1 auto">
      <input type="hidden" name="id" value="<?= (int) $clientId ?>">
      <span class="input-icon grow" style="max-width:340px;min-width:190px">
        <span class="ic" data-ic="search"></span>
        <input type="search" id="lead-q" name="q" class="input" placeholder="Search lead, phone or bot…" value="<?= sanitize($q) ?>">
      </span>
      <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
      <button type="submit" class="btn btn--primary btn--sm">Search</button>
    </form>
    <span class="spacer"></span>
    <span class="muted small"><?= number_format(count($rows)) ?> shown<?= $q !== '' ? ' · filtered' : '' ?></span>
  </div>
  <div style="padding:0 16px"><nav class="iqp-tab-nav iqp-tab-nav--admin" aria-label="Lead filters"><div class="tabs iqp-tab-nav__grid" data-tabset="clead">
    <?php foreach ($tabsCfg as $id => $label): ?>
    <button type="button" class="tab<?= $tab === $id ? ' is-active' : '' ?>" data-tab="<?= sanitize($id) ?>"><?= sanitize($label) ?> <span class="cnt"><?= (int) ($counts[$id] ?? 0) ?></span></button>
    <?php endforeach; ?>
  </div></nav></div>
  <div data-panelset="clead">
    <?php foreach ($tabsCfg as $id => $label): ?>
    <div class="tab-panel<?= $tab === $id ? ' is-active' : '' ?>" data-panel="<?= sanitize($id) ?>">
      <div class="card__body" style="padding:0">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Lead</th><th>Phone</th><th>Status</th><th class="right">Score</th><th>Last Activity</th><th></th></tr></thead>
          <tbody><?= $renderBody($id) ?></tbody>
        </table></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php iqp_admin_end();
