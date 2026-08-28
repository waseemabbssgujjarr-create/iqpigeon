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
    // ignore; empty state handles missing schema
}
try {
    ensure_conversations_schema();
} catch (Throwable $e) {
    // ignore
}

/* -------------------------------------------------------------------------
 * Inputs — status tab (deep-linkable, synced to $_GET['tab']; the legacy
 * ?status= param is still honored), free-text search (q) and channel filter
 * (platform). These preserve the original page's param keys.
 * ---------------------------------------------------------------------- */
$validTabs = ['all', 'new', 'in_progress', 'qualified', 'booked', 'disqualified'];
$tab = preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? $_GET['status'] ?? 'all'));
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$validPlatforms = ['whatsapp', 'instagram', 'widget'];
$platform = trim((string) ($_GET['platform'] ?? ''));
if (!in_array($platform, $validPlatforms, true)) {
    $platform = '';
}

/* Guarded optional columns. Phone data lives in external_id on this schema;
 * fall back to it when a dedicated phone column is absent. */
$hasPlatformCol = false;
try {
    $hasPlatformCol = db_column_exists('leads', 'platform');
} catch (Throwable $e) {
    $hasPlatformCol = false;
}
$hasPhoneCol = false;
try {
    $hasPhoneCol = db_column_exists('leads', 'phone');
} catch (Throwable $e) {
    $hasPhoneCol = false;
}

// SQL fragments (never contain user input).
$phoneExpr      = $hasPhoneCol ? "COALESCE(NULLIF(l.phone, ''), l.external_id)" : 'l.external_id';
$platformSelect = $hasPlatformCol ? 'l.platform AS platform,' : "'' AS platform,";
$activityExpr   = lead_last_activity_sql('l');

/* -------------------------------------------------------------------------
 * Data — one row per lead across every client business. Everything degrades
 * gracefully: guarded columns, try/catch + empty states.
 * ---------------------------------------------------------------------- */
$loadError = '';
$rows = [];
$agg = [];

try {
    // Headline stats (uncapped, across all client leads).
    $agg = db_fetch(
        "SELECT COUNT(*)                                                AS total,
                COUNT(DISTINCT u.id)                                    AS businesses,
                SUM(CASE WHEN l.status = 'qualified' THEN 1 ELSE 0 END) AS qualified,
                SUM(CASE WHEN l.status = 'booked'    THEN 1 ELSE 0 END) AS booked
         FROM leads l
         JOIN bots b  ON b.id = l.bot_id
         JOIN users u ON u.id = b.user_id
         WHERE u.role = 'client'"
    ) ?: [];

    $sql = "SELECT l.id, l.name AS lead_name, {$phoneExpr} AS lead_phone, l.status, l.score,
                   {$platformSelect}
                   b.name AS bot_name, b.user_id AS client_id,
                   u.company_name, u.name AS client_name,
                   {$activityExpr} AS last_activity_at
            FROM leads l
            JOIN bots b  ON b.id = l.bot_id
            JOIN users u ON u.id = b.user_id
            WHERE u.role = 'client'";
    $types = '';
    $params = [];
    if ($q !== '') {
        $sql .= " AND (l.name LIKE ? OR {$phoneExpr} LIKE ? OR u.company_name LIKE ? OR u.name LIKE ? OR b.name LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($platform !== '' && $hasPlatformCol) {
        $sql .= ' AND l.platform = ?';
        $types .= 's';
        $params[] = $platform;
    }
    $sql .= ' ORDER BY last_activity_at DESC LIMIT 300';

    $rows = db_fetch_all($sql, $types, $params);
} catch (Throwable $e) {
    $loadError = 'Some lead data could not be loaded.';
    $rows = [];
    $agg = [];
}

/* -------------------------------------------------------------------------
 * Derived view data.
 * ---------------------------------------------------------------------- */
$total      = (int) ($agg['total'] ?? 0);
$businesses = (int) ($agg['businesses'] ?? 0);
$qualified  = (int) ($agg['qualified'] ?? 0);
$booked     = (int) ($agg['booked'] ?? 0);

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
 * Returns an HTML string so each server-rendered tab panel stays in sync.
 */
$renderBody = static function (string $filter) use ($rows, $statusMeta): string {
    $html = '';
    foreach ($rows as $r) {
        $st = (string) ($r['status'] ?? '');
        if ($filter !== 'all' && $st !== $filter) {
            continue;
        }

        $leadId   = (int) ($r['id'] ?? 0);
        $clientId = (int) ($r['client_id'] ?? 0);
        $name     = trim((string) ($r['lead_name'] ?? ''));
        $phone    = trim((string) ($r['lead_phone'] ?? ''));
        $display  = $name !== '' ? $name : ($phone !== '' ? $phone : 'Unknown');

        $business = trim((string) ($r['company_name'] ?? ''));
        if ($business === '') {
            $business = trim((string) ($r['client_name'] ?? ''));
        }

        $bot   = trim((string) ($r['bot_name'] ?? ''));
        $plat  = trim((string) ($r['platform'] ?? ''));
        $score = (int) ($r['score'] ?? 0);
        $meta  = $statusMeta[$st] ?? ['label' => (ucwords(str_replace('_', ' ', $st)) ?: 'Unknown'), 'badge' => 'badge--gray'];

        $updated = iqp_rel_time((string) ($r['last_activity_at'] ?? ''));
        $href    = '/admin/conversation?lead_id=' . $leadId . ($clientId > 0 ? '&client_id=' . $clientId : '');

        // Secondary line under the lead name: bot · channel.
        $sub = $bot;
        if ($plat !== '') {
            $sub = ($sub !== '' ? $sub . ' · ' : '') . ucfirst($plat);
        }

        $leadCell = '<a class="strong" href="' . sanitize($href) . '">' . sanitize($display) . '</a>';
        if ($sub !== '') {
            $leadCell .= '<div class="muted small">' . sanitize($sub) . '</div>';
        }

        $html .= '<tr>'
            . '<td>' . $leadCell . '</td>'
            . '<td class="muted">' . sanitize($business !== '' ? $business : '—') . '</td>'
            . '<td class="muted nowrap">' . sanitize($phone !== '' ? $phone : '—') . '</td>'
            . '<td><span class="badge ' . $meta['badge'] . '"><span class="dot"></span>' . sanitize($meta['label']) . '</span></td>'
            . '<td class="right">' . ($score > 0 ? '<span class="strong">' . $score . '</span>' : '<span class="muted">0</span>') . '</td>'
            . '<td class="muted nowrap">' . sanitize($updated !== '' ? $updated : '—') . '</td>'
            . '<td><div class="row-actions"><a class="ic" href="' . sanitize($href) . '" title="Open conversation" data-ic="eye"></a></div></td>'
            . '</tr>';
    }

    if ($html === '') {
        $html = '<tr><td colspan="7" class="muted" style="text-align:center;padding:28px">'
            . ($filter === 'all' ? 'No leads yet.' : 'No leads in this status.')
            . '</td></tr>';
    }

    return $html;
};

/* -------------------------------------------------------------------------
 * Shell — page-head (title/subtitle) is rendered by iqp_admin_begin.
 * ---------------------------------------------------------------------- */
iqp_admin_begin($user, 'conversations', [
    'title'    => 'Leads Oversight',
    'subtitle' => 'Monitor every lead captured across all client businesses',
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
    <div class="stat-card__foot"><span class="muted">across all businesses</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile purple" data-ic="building"></span>
      <div class="grow"><div class="stat-card__label">Businesses</div><div class="stat-card__value"><?= number_format($businesses) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">with captured leads</span></div>
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
    <form method="GET" action="/admin/leads" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;flex:1 1 auto">
      <span class="input-icon grow" style="max-width:320px;min-width:190px">
        <span class="ic" data-ic="search"></span>
        <input type="search" id="lead-q" name="q" class="input" placeholder="Search lead, phone or business…" value="<?= sanitize($q) ?>">
      </span>
      <?php if ($hasPlatformCol): ?>
      <select name="platform" class="select" style="max-width:170px">
        <option value="">All channels</option>
        <?php foreach ($validPlatforms as $p): ?>
        <option value="<?= sanitize($p) ?>"<?= $platform === $p ? ' selected' : '' ?>><?= sanitize(ucfirst($p)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
      <button type="submit" class="btn btn--primary btn--sm">Filter</button>
    </form>
    <span class="spacer"></span>
    <span class="muted small"><?= number_format(count($rows)) ?> shown<?= ($q !== '' || $platform !== '') ? ' · filtered' : '' ?></span>
  </div>
  <div style="padding:0 16px"><nav class="iqp-tab-nav iqp-tab-nav--admin" aria-label="Lead filters"><div class="tabs iqp-tab-nav__grid" data-tabset="leads">
    <?php foreach ($tabsCfg as $id => $label): ?>
    <button type="button" class="tab<?= $tab === $id ? ' is-active' : '' ?>" data-tab="<?= sanitize($id) ?>"><?= sanitize($label) ?> <span class="cnt"><?= (int) ($counts[$id] ?? 0) ?></span></button>
    <?php endforeach; ?>
  </div></nav></div>
  <div data-panelset="leads">
    <?php foreach ($tabsCfg as $id => $label): ?>
    <div class="tab-panel<?= $tab === $id ? ' is-active' : '' ?>" data-panel="<?= sanitize($id) ?>">
      <div class="card__body" style="padding:0">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Lead</th><th>Business</th><th>Phone</th><th>Status</th><th class="right">Score</th><th>Last Activity</th><th></th></tr></thead>
          <tbody><?= $renderBody($id) ?></tbody>
        </table></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php iqp_admin_end();
