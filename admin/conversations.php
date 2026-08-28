<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

/* -------------------------------------------------------------------------
 * Inputs — status tab (deep-linkable) + free-text search.
 * ---------------------------------------------------------------------- */
$validTabs = ['all', 'new', 'in_progress', 'qualified', 'booked', 'disqualified'];
$tab = preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? 'all'));
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}
$q          = trim((string) ($_GET['q'] ?? ''));
$clientFilter = (int) ($_GET['client'] ?? 0); // set by client-detail "View Conversations" link

/* -------------------------------------------------------------------------
 * Data — one row per lead/conversation thread. Everything degrades
 * gracefully: optional schema, guarded columns, try/catch + empty states.
 * ---------------------------------------------------------------------- */
$loadError = '';
$rows = [];
$agg = [];

try {
    ensure_conversations_schema();
} catch (Throwable $e) {
    // best-effort; the query below still degrades to an empty state.
}

$hasPausedCol = false;
try {
    $hasPausedCol = db_column_exists('leads', 'bot_paused_until');
} catch (Throwable $e) {
    $hasPausedCol = false;
}

// The canonical leads schema stores the contact identifier in external_id;
// some deployments also add a dedicated `phone` column. Prefer phone when present.
$hasPhoneCol = false;
try {
    $hasPhoneCol = db_column_exists('leads', 'phone');
} catch (Throwable $e) {
    $hasPhoneCol = false;
}
$phoneExpr = $hasPhoneCol
    ? "COALESCE(NULLIF(l.phone, ''), l.external_id)"
    : 'l.external_id';

// SQL fragments (never contain user input).
$humanExpr = $hasPausedCol
    ? '(l.bot_paused_until IS NOT NULL AND l.bot_paused_until > NOW())'
    : '0';
$activityExpr = lead_last_activity_sql('l');
$pausedSelect = $hasPausedCol ? 'l.bot_paused_until AS bot_paused_until,' : 'NULL AS bot_paused_until,';

try {
    $aggWhere  = "u.role = 'client' AND EXISTS (SELECT 1 FROM conversations c WHERE c.lead_id = l.id)";
    $aggTypes  = '';
    $aggParams = [];
    if ($clientFilter > 0) {
        $aggWhere  .= ' AND u.id = ?';
        $aggTypes   = 'i';
        $aggParams  = [$clientFilter];
    }
    $agg = db_fetch(
        "SELECT
            COUNT(*)                                                          AS total,
            COUNT(DISTINCT u.id)                                              AS businesses,
            SUM(CASE WHEN {$humanExpr} THEN 1 ELSE 0 END)                     AS human_handled,
            SUM(CASE WHEN DATE({$activityExpr}) = CURDATE() THEN 1 ELSE 0 END) AS active_today
         FROM leads l
         JOIN bots b  ON b.id = l.bot_id
         JOIN users u ON u.id = b.user_id
         WHERE {$aggWhere}",
        $aggTypes,
        $aggParams
    ) ?: [];

    $sql = "SELECT l.id, l.name AS lead_name, {$phoneExpr} AS lead_phone, l.status,
                   b.user_id AS client_id, u.company_name, u.name AS client_name,
                   {$pausedSelect}
                   {$activityExpr} AS last_activity_at,
                   (SELECT c.message FROM conversations c
                     WHERE c.lead_id = l.id
                     ORDER BY c.created_at DESC, c.id DESC LIMIT 1) AS last_message
            FROM leads l
            JOIN bots b  ON b.id = l.bot_id
            JOIN users u ON u.id = b.user_id
            WHERE u.role = 'client'
              AND EXISTS (SELECT 1 FROM conversations c WHERE c.lead_id = l.id)";
    $types  = '';
    $params = [];
    if ($clientFilter > 0) {
        $sql   .= ' AND u.id = ?';
        $types  = 'i';
        $params = [$clientFilter];
    }
    if ($q !== '') {
        $sql   .= ' AND (l.name LIKE ? OR ' . $phoneExpr . ' LIKE ? OR u.company_name LIKE ? OR u.name LIKE ?)';
        $like   = '%' . $q . '%';
        $types .= 'ssss';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    $sql .= ' ORDER BY last_activity_at DESC LIMIT 300';

    $rows = db_fetch_all($sql, $types, $params);
} catch (Throwable $e) {
    $loadError = 'Some conversation data could not be loaded.';
    $rows = [];
    $agg  = [];
}

/* -------------------------------------------------------------------------
 * CSV export (real "Export" action). Runs BEFORE the HTML shell so headers
 * are clean. Reuses the already-fetched rows, respecting the active view.
 * ---------------------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = $tab === 'all'
        ? $rows
        : array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === $tab));

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="conversations-' . date('Ymd-His') . '.csv"');
    }
    // Guard against CSV formula injection in untrusted customer data.
    $csvSafe = static function ($v): string {
        $v = (string) $v;
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }
        return $v;
    };
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Customer', 'Phone', 'Business', 'Last message', 'Handled by', 'Status', 'Updated']);
    foreach ($csvRows as $r) {
        fputcsv($fh, [
            $csvSafe($r['lead_name'] ?? ''),
            $csvSafe($r['lead_phone'] ?? ''),
            $csvSafe(($r['company_name'] ?? '') !== '' ? $r['company_name'] : ($r['client_name'] ?? '')),
            $csvSafe($r['last_message'] ?? ''),
            is_lead_bot_paused($r) ? 'Human' : 'AI',
            $csvSafe(ucwords(str_replace('_', ' ', (string) ($r['status'] ?? '')))),
            $csvSafe(iqp_rel_time((string) ($r['last_activity_at'] ?? ''))),
        ]);
    }
    fclose($fh);
    exit;
}

/* -------------------------------------------------------------------------
 * Derived view data.
 * ---------------------------------------------------------------------- */
$total       = (int) ($agg['total'] ?? 0);
$human       = (int) ($agg['human_handled'] ?? 0);
$aiHandled   = max(0, $total - $human);
$activeToday = (int) ($agg['active_today'] ?? 0);
$businesses  = (int) ($agg['businesses'] ?? 0);
$aiPct       = $total > 0 ? round($aiHandled / $total * 100, 1) : 0;
$humanPct    = $total > 0 ? round($human / $total * 100, 1) : 0;

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

        $human = is_lead_bot_paused($r);
        $meta  = $statusMeta[$st] ?? ['label' => (ucwords(str_replace('_', ' ', $st)) ?: 'Unknown'), 'badge' => 'badge--gray'];

        // Latest message snippet — collapse whitespace, truncate, always sanitize (untrusted).
        $snippet = trim((string) preg_replace('/\s+/', ' ', (string) ($r['last_message'] ?? '')));
        if ($snippet === '') {
            $snippet = '—';
        } elseif (function_exists('mb_strimwidth')) {
            $snippet = mb_strimwidth($snippet, 0, 72, '…', 'UTF-8');
        } elseif (strlen($snippet) > 72) {
            $snippet = substr($snippet, 0, 71) . '…';
        }

        $updated = iqp_rel_time((string) ($r['last_activity_at'] ?? ''));
        $href    = '/admin/conversation?lead_id=' . $leadId . ($clientId > 0 ? '&client_id=' . $clientId : '');

        $custCell = '<div class="strong">' . sanitize($display) . '</div>';
        if ($name !== '' && $phone !== '') {
            $custCell .= '<div class="muted small">' . sanitize($phone) . '</div>';
        }

        $html .= '<tr>'
            . '<td>' . $custCell . '</td>'
            . '<td class="muted">' . sanitize($business !== '' ? $business : '—') . '</td>'
            . '<td class="muted">' . sanitize($snippet) . '</td>'
            . '<td>' . ($human
                ? '<span class="badge badge--blue">Human</span>'
                : '<span class="badge badge--green">AI</span>') . '</td>'
            . '<td><span class="badge ' . $meta['badge'] . '"><span class="dot"></span>' . sanitize($meta['label']) . '</span></td>'
            . '<td class="muted nowrap">' . sanitize($updated !== '' ? $updated : '—') . '</td>'
            . '<td><div class="row-actions"><a class="ic" href="' . sanitize($href) . '" title="Open conversation" data-ic="eye"></a></div></td>'
            . '</tr>';
    }

    if ($html === '') {
        $html = '<tr><td colspan="7" class="muted" style="text-align:center;padding:28px">'
            . ($filter === 'all' ? 'No conversations yet.' : 'No conversations in this status.')
            . '</td></tr>';
    }

    return $html;
};

/* -------------------------------------------------------------------------
 * Shell — page-head (title/subtitle/actions) is rendered by iqp_admin_begin.
 * ---------------------------------------------------------------------- */
$exportHref = '/admin/conversations?export=csv&tab=' . urlencode($tab)
    . ($q !== '' ? '&q=' . urlencode($q) : '')
    . ($clientFilter > 0 ? '&client=' . $clientFilter : '');

// Resolve client name for the filter label
$clientFilterName = '';
if ($clientFilter > 0) {
    $cf = db_fetch("SELECT name, company_name FROM users WHERE id = ? AND role='client'", 'i', [$clientFilter]);
    if ($cf) {
        $clientFilterName = trim((string)($cf['company_name'] ?: $cf['name']));
    }
}

$headActions =
    '<div class="iqp-page-actions-row"><a class="btn btn--ghost btn--sm" href="#conv-q"><span class="ic" data-ic="filter"></span> Filters</a>'
    . '<a class="btn btn--ghost btn--sm" href="' . sanitize($exportHref) . '"><span class="ic" data-ic="download"></span> Export</a></div>';

iqp_admin_begin($user, 'conversations', [
    'title'    => 'Conversations Oversight',
    'subtitle' => $clientFilterName !== ''
        ? 'Showing conversations for: ' . htmlspecialchars($clientFilterName, ENT_QUOTES, 'UTF-8')
        : 'Monitor and audit conversations across all businesses',
    'actions'  => $headActions,
]);
if ($loadError !== '') {
    iqp_flash($loadError, 'err');
}
?>
<div class="stat-grid cols-4">
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile blue" data-ic="chats"></span>
      <div class="grow"><div class="stat-card__label">Conversations</div><div class="stat-card__value"><?= number_format($total) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= number_format($businesses) ?> businesses</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile green" data-ic="sparkles"></span>
      <div class="grow"><div class="stat-card__label">AI Handled</div><div class="stat-card__value"><?= $aiPct ?>%</div></div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= number_format($aiHandled) ?> conv.</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile amber" data-ic="user"></span>
      <div class="grow"><div class="stat-card__label">Human Handled</div><div class="stat-card__value"><?= $humanPct ?>%</div></div>
    </div>
    <div class="stat-card__foot"><span class="muted"><?= number_format($human) ?> conv.</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile cyan" data-ic="clock"></span>
      <div class="grow"><div class="stat-card__label">Active Today</div><div class="stat-card__value"><?= number_format($activeToday) ?></div></div>
    </div>
    <div class="stat-card__foot"><span class="muted">since midnight</span></div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card__head iqp-toolbar">
    <form method="GET" action="/admin/conversations" class="input-icon grow" style="max-width:340px">
      <span class="ic" data-ic="search"></span>
      <input type="search" id="conv-q" name="q" class="input" placeholder="Search customer or business…" value="<?= sanitize($q) ?>">
      <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
      <?php if ($clientFilter > 0): ?>
      <input type="hidden" name="client" value="<?= $clientFilter ?>">
      <?php endif; ?>
    </form>
    <span class="spacer"></span>
    <?php if ($clientFilter > 0): ?>
    <a class="btn btn--ghost btn--sm" href="/admin/conversations" style="display:flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
      Clear filter
    </a>
    <a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=<?= $clientFilter ?>" style="display:flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Back to client
    </a>
    <?php endif; ?>
    <span class="muted small"><?= number_format(count($rows)) ?> shown<?= $q !== '' ? ' · filtered' : '' ?></span>
  </div>
  <div style="padding:12px 16px 8px"><nav class="iqp-tab-nav iqp-tab-nav--admin iqp-tab-nav--pills" aria-label="Conversation filters"><div class="pill-tabs iqp-tab-nav__grid" data-tabset="conv">
    <?php foreach ($tabsCfg as $id => $label): ?>
    <button type="button" class="pill-tab<?= $tab === $id ? ' is-active' : '' ?>" data-tab="<?= sanitize($id) ?>"><?= sanitize($label) ?><span class="cnt"><?= (int)($counts[$id] ?? 0) ?></span></button>
    <?php endforeach; ?>
  </div></nav></div>
  <div data-panelset="conv">
    <?php foreach ($tabsCfg as $id => $label): ?>
    <div class="tab-panel<?= $tab === $id ? ' is-active' : '' ?>" data-panel="<?= sanitize($id) ?>">
      <div class="card__body" style="padding:0">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Customer</th><th>Business</th><th>Last Message</th><th>Handled By</th><th>Status</th><th>Updated</th><th></th></tr></thead>
          <tbody><?= $renderBody($id) ?></tbody>
        </table></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php iqp_admin_end();
