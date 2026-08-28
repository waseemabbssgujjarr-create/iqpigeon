<?php
/**
 * Admin — Orders Oversight
 * Cross-business view of every order placed through any bot on the platform.
 * Read-only by design: order status mutations belong to the business's own
 * order flow (which writes bot_order_status_events + notifies the customer);
 * a bare admin UPDATE here would bypass that pipeline. Admin gets view + export.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/commerce-schema.php';

$user = require_admin();
try {
    ensure_commerce_schema();
} catch (Throwable $e) {
    // schema helpers degrade gracefully; queries below are all guarded too
}

$validStatuses = ['new', 'confirmed', 'shipped', 'delivered', 'cancelled'];
$statusTabs = array_merge(['all'], $validStatuses);
$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'all')) ?: 'all';
if (!in_array($tab, $statusTabs, true)) {
    $tab = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

/* ---- fetch orders (search applied; partitioned by status in PHP) ---- */
$orders = [];
$fetchError = false;
try {
    $sql = "SELECT o.id, o.status, o.total_amount, o.currency, o.cod,
                   o.customer_name, o.customer_phone, o.lead_id, o.created_at,
                   b.user_id AS biz_user_id,
                   u.name AS biz_name, u.company_name AS biz_company,
                   (SELECT COALESCE(SUM(oi.quantity), 0) FROM bot_order_items oi WHERE oi.order_id = o.id) AS item_qty
            FROM bot_orders o
            JOIN bots b  ON b.id = o.bot_id
            JOIN users u ON u.id = b.user_id
            WHERE u.role = 'client'";
    $types = '';
    $params = [];
    if ($q !== '') {
        $sql .= " AND (o.customer_name LIKE ? OR o.customer_phone LIKE ? OR u.company_name LIKE ? OR u.name LIKE ? OR o.id = ?)";
        $like = '%' . $q . '%';
        $types = 'ssssi';
        $params = [$like, $like, $like, $like, ctype_digit($q) ? (int) $q : 0];
    }
    $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT 500';
    $orders = db_fetch_all($sql, $types, $params) ?: [];
} catch (Throwable $e) {
    $orders = [];
    $fetchError = true;
}

/* ---- CSV export (stream before any shell output) ---- */
if (($_GET['export'] ?? '') === 'csv') {
    $csvCell = static function ($v): string {
        $s = (string) $v;
        if ($s !== '' && strpbrk($s[0], "=+-@") !== false) {
            $s = "'" . $s; // neutralize spreadsheet formula injection
        }
        return '"' . str_replace('"', '""', $s) . '"';
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['Order ID', 'Business', 'Customer', 'Phone', 'Items', 'Amount', 'Currency', 'Status', 'COD', 'Placed']);
    $count = 0;
    foreach ($orders as $o) {
        if ($tab !== 'all' && (string) ($o['status'] ?? '') !== $tab) {
            continue;
        }
        $line = [
            '#ORD-' . (int) $o['id'],
            (string) ($o['biz_company'] ?: $o['biz_name'] ?: ''),
            (string) ($o['customer_name'] ?? ''),
            (string) ($o['customer_phone'] ?? ''),
            (int) ($o['item_qty'] ?? 0),
            number_format((float) ($o['total_amount'] ?? 0), 2, '.', ''),
            (string) ($o['currency'] ?? 'PKR'),
            (string) ($o['status'] ?? ''),
            ((int) ($o['cod'] ?? 0) === 1) ? 'COD' : 'Prepaid',
            (string) ($o['created_at'] ?? ''),
        ];
        fwrite($out, implode(',', array_map($csvCell, $line)) . "\n");
        if (++$count >= 5000) {
            break;
        }
    }
    fclose($out);
    exit;
}

/* ---- stat tiles (single guarded aggregate over all businesses) ---- */
$stats = ['total' => 0, 'new' => 0, 'confirmed' => 0, 'shipped' => 0, 'delivered' => 0, 'revenue' => 0.0];
try {
    $row = db_fetch(
        "SELECT COUNT(*) AS total,
                SUM(o.status = 'new')       AS s_new,
                SUM(o.status = 'confirmed') AS s_confirmed,
                SUM(o.status = 'shipped')   AS s_shipped,
                SUM(o.status = 'delivered') AS s_delivered,
                SUM(CASE WHEN o.status <> 'cancelled' THEN o.total_amount ELSE 0 END) AS revenue
         FROM bot_orders o
         JOIN bots b  ON b.id = o.bot_id
         JOIN users u ON u.id = b.user_id
         WHERE u.role = 'client'"
    );
    if ($row) {
        $stats['total']     = (int) ($row['total'] ?? 0);
        $stats['new']       = (int) ($row['s_new'] ?? 0);
        $stats['confirmed'] = (int) ($row['s_confirmed'] ?? 0);
        $stats['shipped']   = (int) ($row['s_shipped'] ?? 0);
        $stats['delivered'] = (int) ($row['s_delivered'] ?? 0);
        $stats['revenue']   = (float) ($row['revenue'] ?? 0);
    }
} catch (Throwable $e) {
    // leave zeros
}

/* ---- partition fetched rows by status for the tab panels ---- */
$byStatus = ['all' => []];
foreach ($validStatuses as $s) {
    $byStatus[$s] = [];
}
foreach ($orders as $o) {
    $byStatus['all'][] = $o;
    $st = (string) ($o['status'] ?? '');
    if (isset($byStatus[$st])) {
        $byStatus[$st][] = $o;
    }
}

$statusMeta = [
    'new'       => ['badge' => 'badge--blue',  'label' => 'New'],
    'confirmed' => ['badge' => 'badge--cyan',  'label' => 'Confirmed'],
    'shipped'   => ['badge' => 'badge--amber', 'label' => 'Shipped'],
    'delivered' => ['badge' => 'badge--green', 'label' => 'Delivered'],
    'cancelled' => ['badge' => 'badge--gray',  'label' => 'Cancelled'],
];
$tabLabels = ['all' => 'All Orders'] + array_map(static fn($m) => $m['label'], $statusMeta);

$money = static function ($amount, $currency) {
    $currency = $currency ?: 'PKR';
    if (function_exists('format_money')) {
        return format_money((float) $amount, (string) $currency);
    }
    return $currency . ' ' . number_format((float) $amount, 0);
};

$exportQs = 'export=csv&tab=' . urlencode($tab) . ($q !== '' ? '&q=' . urlencode($q) : '');

iqp_admin_begin($user, 'orders', [
    'title' => 'Orders Oversight',
    'subtitle' => 'Track orders across all businesses on the platform',
    'actions' => '<div class="iqp-page-actions-row"><a class="btn btn--ghost btn--sm" href="/admin/orders?' . sanitize($exportQs) . '"><span class="ic" data-ic="download"></span> Export</a></div>',
]);
if ($fetchError) {
    iqp_flash('Orders data is not available yet.', 'err');
}
?>
<div class="stat-grid cols-5" style="margin-bottom:16px">
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile purple"><span class="ic" data-ic="bag"></span></span>
      <div class="grow"><div class="stat-card__label">Total Orders</div><div class="stat-card__value"><?= number_format($stats['total']) ?></div></div></div>
    <div class="stat-card__foot"><span class="muted"><?= sanitize($money($stats['revenue'], 'PKR')) ?> revenue</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile blue"><span class="ic" data-ic="sparkles"></span></span>
      <div class="grow"><div class="stat-card__label">New</div><div class="stat-card__value"><?= number_format($stats['new']) ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">awaiting</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile amber"><span class="ic" data-ic="clock"></span></span>
      <div class="grow"><div class="stat-card__label">Confirmed</div><div class="stat-card__value"><?= number_format($stats['confirmed']) ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">in progress</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile cyan"><span class="ic" data-ic="truck"></span></span>
      <div class="grow"><div class="stat-card__label">Shipped</div><div class="stat-card__value"><?= number_format($stats['shipped']) ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">en route</span></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__top"><span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span>
      <div class="grow"><div class="stat-card__label">Delivered</div><div class="stat-card__value"><?= number_format($stats['delivered']) ?></div></div></div>
    <div class="stat-card__foot"><span class="muted">completed</span></div>
  </div>
</div>

<div class="card">
  <div class="card__head iqp-toolbar">
    <form method="get" class="input-icon grow" style="max-width:340px">
      <span class="ic" data-ic="search"></span>
      <input class="input" type="text" name="q" value="<?= sanitize($q) ?>" placeholder="Search orders, customer, business...">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= sanitize($tab) ?>"><?php endif; ?>
    </form>
    <span class="spacer"></span>
    <?php if ($q !== ''): ?><a class="btn btn--ghost btn--sm" href="/admin/orders<?= $tab !== 'all' ? '?tab=' . sanitize($tab) : '' ?>">Clear</a><?php endif; ?>
  </div>
  <div style="padding:12px 16px 8px"><nav class="iqp-tab-nav iqp-tab-nav--admin iqp-tab-nav--pills" aria-label="Order filters"><div class="pill-tabs iqp-tab-nav__grid" data-tabset="ord">
    <?php foreach ($statusTabs as $id): ?>
      <button type="button" class="pill-tab<?= $tab === $id ? ' is-active' : '' ?>" data-tab="<?= sanitize($id) ?>">
        <?= sanitize($tabLabels[$id]) ?><span class="cnt"><?= count($byStatus[$id]) ?></span>
      </button>
    <?php endforeach; ?>
  </div></nav></div>
    <?php foreach ($statusTabs as $id): $rows = $byStatus[$id]; ?>
    <div class="tab-panel<?= $tab === $id ? ' is-active' : '' ?>" data-panel="<?= sanitize($id) ?>">
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Order</th><th>Business</th><th>Customer</th><th>Items</th><th>Amount</th><th>Status</th><th>Placed</th><th></th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="8" class="muted"><?= $q !== '' ? 'No orders match your search.' : 'No orders yet.' ?></td></tr>
        <?php else: foreach ($rows as $o):
            $st = (string) ($o['status'] ?? '');
            $meta = $statusMeta[$st] ?? ['badge' => 'badge--gray', 'label' => ucfirst($st ?: 'Unknown')];
            $bizId = (int) ($o['biz_user_id'] ?? 0);
            $leadId = (int) ($o['lead_id'] ?? 0);
            $viewHref = $leadId > 0
                ? '/admin/conversation?lead_id=' . $leadId . '&client_id=' . $bizId
                : '/admin/client-detail?id=' . $bizId;
        ?>
          <tr>
            <td class="strong">#ORD-<?= (int) $o['id'] ?></td>
            <td><a href="/admin/client-detail?id=<?= $bizId ?>"><?= sanitize($o['biz_company'] ?: $o['biz_name'] ?: '—') ?></a></td>
            <td>
              <?= sanitize($o['customer_name'] ?: '—') ?>
              <?php if (!empty($o['customer_phone'])): ?><div class="muted small"><?= sanitize($o['customer_phone']) ?></div><?php endif; ?>
            </td>
            <td class="muted"><?= (int) ($o['item_qty'] ?? 0) ?> items</td>
            <td class="strong"><?= sanitize($money($o['total_amount'] ?? 0, $o['currency'] ?? 'PKR')) ?><?php if ((int) ($o['cod'] ?? 0) === 1): ?> <span class="badge badge--gray">COD</span><?php endif; ?></td>
            <td><span class="badge <?= $meta['badge'] ?>"><span class="dot"></span><?= sanitize($meta['label']) ?></span></td>
            <td class="muted nowrap"><?= sanitize(iqp_rel_time((string) ($o['created_at'] ?? ''))) ?></td>
            <td><div class="row-actions"><a class="ic" href="<?= sanitize($viewHref) ?>" title="View"><span class="ic" data-ic="eye"></span></a></div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php endforeach; ?>
  </div></div>
</div>
<?php if (count($byStatus['all']) >= 500): ?>
<p class="muted small" style="margin-top:12px">Showing the 500 most recent orders. Use search or export for the full set.</p>
<?php endif; ?>
<?php iqp_admin_end();
