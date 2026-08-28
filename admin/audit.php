<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();
iqp_ensure_ops_schema(); // creates iqp_audit (id, actor, action, detail, created_at)

// ---------- Filters (GET, prepared statements only) ----------
$q            = trim((string) ($_GET['q'] ?? ''));
$actorFilter  = trim((string) ($_GET['actor'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 25;

// Build a WHERE clause from the active filters — values bound as params.
$conds  = [];
$types  = '';
$params = [];
if ($q !== '') {
    $conds[]  = '(actor LIKE ? OR action LIKE ? OR detail LIKE ?)';
    $like     = '%' . $q . '%';
    $types   .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($actorFilter !== '') {
    $conds[]  = 'actor = ?';
    $types   .= 's';
    $params[] = $actorFilter;
}
if ($actionFilter !== '') {
    $conds[]  = 'action = ?';
    $types   .= 's';
    $params[] = $actionFilter;
}
$where = $conds ? (' WHERE ' . implode(' AND ', $conds)) : '';

// Active filters, kept for building filter-aware pager / export links.
$filterParams = [];
if ($q !== '')            { $filterParams['q'] = $q; }
if ($actorFilter !== '')  { $filterParams['actor'] = $actorFilter; }
if ($actionFilter !== '') { $filterParams['action'] = $actionFilter; }
$hasFilters = $filterParams !== [];

// ---------- CSV export (read-only, self-contained, carries active filters) ----------
if (($_GET['export'] ?? '') === 'csv') {
    $exportRows = [];
    try {
        $exportRows = db_fetch_all(
            "SELECT actor, action, detail, created_at FROM iqp_audit{$where} ORDER BY created_at DESC LIMIT 5000",
            $types,
            $params
        );
    } catch (Throwable $e) {
        $exportRows = [];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="iqp-audit-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Actor', 'Action', 'Detail', 'When']);
    foreach ($exportRows as $r) {
        fputcsv($out, [
            (string) ($r['actor'] ?? ''),
            (string) ($r['action'] ?? ''),
            (string) ($r['detail'] ?? ''),
            (string) ($r['created_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// ---------- Data (everything guarded — table may be empty on a fresh install) ----------
$stats   = ['total' => 0, 'today' => 0, 'week' => 0, 'actors' => 0];
$actors  = [];
$actions = [];
$total   = 0;
$rows    = [];

try {
    $stats['total']  = (int) ((db_fetch('SELECT COUNT(*) AS c FROM iqp_audit') ?? [])['c'] ?? 0);
    $stats['today']  = (int) ((db_fetch('SELECT COUNT(*) AS c FROM iqp_audit WHERE created_at >= CURDATE()') ?? [])['c'] ?? 0);
    $stats['week']   = (int) ((db_fetch('SELECT COUNT(*) AS c FROM iqp_audit WHERE created_at >= (CURDATE() - INTERVAL 6 DAY)') ?? [])['c'] ?? 0);
    $stats['actors'] = (int) ((db_fetch("SELECT COUNT(DISTINCT actor) AS c FROM iqp_audit WHERE actor IS NOT NULL AND actor <> ''") ?? [])['c'] ?? 0);

    $actors  = db_fetch_all("SELECT DISTINCT actor FROM iqp_audit WHERE actor IS NOT NULL AND actor <> '' ORDER BY actor ASC LIMIT 200");
    $actions = db_fetch_all("SELECT DISTINCT action FROM iqp_audit WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC LIMIT 200");

    $total = (int) ((db_fetch("SELECT COUNT(*) AS c FROM iqp_audit{$where}", $types, $params) ?? [])['c'] ?? 0);
} catch (Throwable $e) {
    $stats   = ['total' => 0, 'today' => 0, 'week' => 0, 'actors' => 0];
    $actors  = [];
    $actions = [];
    $total   = 0;
}

// Pagination math — clamp the requested page into the valid range.
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

try {
    $rows = db_fetch_all(
        "SELECT actor, action, detail, created_at FROM iqp_audit{$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$perPage, $offset])
    );
} catch (Throwable $e) {
    $rows = [];
}

// Filter-aware page URL builder (page=1 omitted for a clean URL).
$pageUrl = static function (int $p) use ($filterParams): string {
    $qsParams = $filterParams;
    if ($p > 1) {
        $qsParams['page'] = $p;
    }
    $qs = http_build_query($qsParams);
    return '/admin/audit' . ($qs !== '' ? ('?' . $qs) : '');
};

$showingFrom = $total > 0 ? ($offset + 1) : 0;
$showingTo   = $total > 0 ? min($offset + $perPage, $total) : 0;
$exportQs    = http_build_query(array_merge($filterParams, ['export' => 'csv']));

iqp_admin_begin($user, 'audit', [
    'title'    => 'Audit Logs',
    'subtitle' => 'Immutable record of all administrative actions',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/audit?' . sanitize($exportQs) . '"><span class="ic" data-ic="download"></span> Export CSV</a>',
]);
?>
<div class="stat-grid cols-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-card__label">Total events</div><div class="stat-card__value"><?= number_format((int) ($stats['total'] ?? 0)) ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Today</div><div class="stat-card__value"><?= number_format((int) ($stats['today'] ?? 0)) ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Last 7 days</div><div class="stat-card__value"><?= number_format((int) ($stats['week'] ?? 0)) ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Distinct actors</div><div class="stat-card__value"><?= number_format((int) ($stats['actors'] ?? 0)) ?></div></div>
</div>

<div class="card">
  <form method="get" action="/admin/audit" class="card__head" style="flex-wrap:wrap">
    <div class="input-icon grow" style="max-width:340px">
      <span class="ic" data-ic="search"></span>
      <input class="input" type="text" name="q" value="<?= sanitize($q) ?>" placeholder="Search logs...">
    </div>
    <span class="spacer"></span>
    <select class="select" name="actor" style="width:auto;min-width:150px" aria-label="Filter by actor">
      <option value="">All actors</option>
      <?php foreach ($actors as $a): $av = (string) ($a['actor'] ?? ''); if ($av === '') { continue; } ?>
      <option value="<?= sanitize($av) ?>"<?= $actorFilter === $av ? ' selected' : '' ?>><?= sanitize($av) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="select" name="action" style="width:auto;min-width:160px" aria-label="Filter by action">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): $av = (string) ($a['action'] ?? ''); if ($av === '') { continue; } ?>
      <option value="<?= sanitize($av) ?>"<?= $actionFilter === $av ? ' selected' : '' ?>><?= sanitize($av) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn--primary btn--sm" type="submit"><span class="ic" data-ic="filter"></span> Filter</button>
    <?php if ($hasFilters): ?>
    <a class="btn btn--ghost btn--sm" href="/admin/audit">Clear</a>
    <?php endif; ?>
  </form>

  <div class="card__body" style="padding:0">
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Actor</th><th>Action</th><th>Detail</th><th>When</th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="4" class="muted"><?= $hasFilters ? 'No audit events match your filters.' : 'No audit events recorded yet.' ?></td></tr>
      <?php else: foreach ($rows as $r):
          $actor  = trim((string) ($r['actor'] ?? ''));
          $detail = trim((string) ($r['detail'] ?? ''));
          $ts     = strtotime((string) ($r['created_at'] ?? ''));
      ?>
        <tr>
          <td class="strong"><?= $actor !== '' ? sanitize($actor) : '<span class="muted">System</span>' ?></td>
          <td><?= sanitize((string) ($r['action'] ?? '')) ?></td>
          <td class="muted"><?= $detail !== '' ? sanitize($detail) : '—' ?></td>
          <td class="muted nowrap">
            <?php if ($ts): ?>
              <?= sanitize(iqp_rel_time((string) $r['created_at'])) ?>
              <div class="small" style="opacity:.7"><?= sanitize(date('d M Y, H:i', $ts)) ?></div>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>

    <div class="between" style="padding:14px 16px;border-top:1px solid var(--line-2)">
      <span class="muted small">
        <?php if ($total > 0): ?>
          Showing <?= number_format($showingFrom) ?>&ndash;<?= number_format($showingTo) ?> of <?= number_format($total) ?> entries
        <?php else: ?>
          Showing 0 of 0 entries
        <?php endif; ?>
      </span>
      <div class="row">
        <?php if ($page > 1): ?>
          <a class="btn btn--ghost btn--sm" href="<?= sanitize($pageUrl($page - 1)) ?>">Previous</a>
        <?php else: ?>
          <span class="btn btn--ghost btn--sm" style="opacity:.45;pointer-events:none">Previous</span>
        <?php endif; ?>
        <span class="btn btn--primary btn--sm" style="pointer-events:none"><?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn--ghost btn--sm" href="<?= sanitize($pageUrl($page + 1)) ?>">Next</a>
        <?php else: ?>
          <span class="btn btn--ghost btn--sm" style="opacity:.45;pointer-events:none">Next</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php iqp_admin_end();
