<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/commerce-schema.php';

$user = require_admin();

$message = '';
$error = '';

/* ---------- POST: suspend / reactivate a business ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle_status') {
            $bizId  = (int) ($_POST['biz_id'] ?? 0);
            $target = (string) ($_POST['target'] ?? '');
            $allowed = ['active', 'cancelled']; // whitelist
            if ($bizId > 0 && in_array($target, $allowed, true)) {
                $affected = db_execute(
                    "UPDATE users SET subscription_status = ? WHERE id = ? AND role = 'client'",
                    'si',
                    [$target, $bizId]
                );
                if ($affected > 0) {
                    $message = $target === 'active' ? 'Business reactivated.' : 'Business suspended.';
                } else {
                    $error = 'No matching business was updated.';
                }
            } else {
                $error = 'Invalid suspend/reactivate request.';
            }
        } elseif ($action === 'bulk') {
            $bulkAction = (string) ($_POST['bulk_action'] ?? '');
            $rawIds     = $_POST['biz_ids'] ?? [];
            $bizIds     = [];
            if (is_array($rawIds)) {
                foreach ($rawIds as $rawId) {
                    $id = (int) $rawId;
                    if ($id > 0) {
                        $bizIds[$id] = $id;
                    }
                }
            }
            $bizIds = array_values($bizIds);

            if ($bizIds === []) {
                $error = 'Select at least one business.';
            } elseif (!in_array($bulkAction, ['suspend', 'activate', 'delete'], true)) {
                $error = 'Invalid bulk action.';
            } else {
                $changed = 0;
                $failed  = 0;
                foreach ($bizIds as $bizId) {
                    try {
                        $exists = db_fetch(
                            "SELECT id FROM users WHERE id = ? AND role = 'client'",
                            'i',
                            [$bizId]
                        );
                        if (!$exists) {
                            $failed++;
                            continue;
                        }
                        if ($bulkAction === 'suspend') {
                            $changed += db_execute(
                                "UPDATE users SET subscription_status = 'cancelled' WHERE id = ? AND role = 'client'",
                                'i',
                                [$bizId]
                            );
                        } elseif ($bulkAction === 'activate') {
                            $changed += db_execute(
                                "UPDATE users SET subscription_status = 'active' WHERE id = ? AND role = 'client'",
                                'i',
                                [$bizId]
                            );
                        } else {
                            $bids = db_fetch_all('SELECT id FROM bots WHERE user_id = ?', 'i', [$bizId]);
                            foreach ($bids as $b) {
                                db_execute('DELETE FROM leads WHERE bot_id = ?', 'i', [(int) $b['id']]);
                                db_execute('DELETE FROM bots WHERE id = ?', 'i', [(int) $b['id']]);
                            }
                            $changed += db_execute(
                                "DELETE FROM users WHERE id = ? AND role = 'client'",
                                'i',
                                [$bizId]
                            );
                        }
                    } catch (Throwable $e) {
                        $failed++;
                    }
                }
                if ($changed > 0) {
                    $message = match ($bulkAction) {
                        'suspend'  => $changed . ' business' . ($changed === 1 ? '' : 'es') . ' suspended.',
                        'activate' => $changed . ' business' . ($changed === 1 ? '' : 'es') . ' activated.',
                        'delete'   => $changed . ' business' . ($changed === 1 ? '' : 'es') . ' deleted.',
                    };
                    if ($failed > 0) {
                        $message .= ' ' . $failed . ' could not be updated.';
                    }
                } else {
                    $error = $failed > 0
                        ? 'Could not apply the bulk action to the selected businesses.'
                        : 'No businesses were updated.';
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'Could not update the business status right now.';
    }
}

/* ---------- Search (synced to $_GET['q'], also fed by the shell topbar search) ---------- */
$q      = trim((string) ($_GET['q'] ?? ''));
$where  = "u.role = 'client'";
$types  = '';
$params = [];
if ($q !== '') {
    $where  .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.company_name LIKE ?)';
    $like    = '%' . $q . '%';
    $types   = 'sss';
    $params  = [$like, $like, $like];
}

/* ---------- Stat tiles (users.subscription_status aggregates) ---------- */
$stats = ['total' => 0, 'active' => 0, 'trialing' => 0, 'cancelled' => 0];
try {
    $agg = db_fetch(
        "SELECT COUNT(*) AS total,
                SUM(subscription_status = 'active')                              AS active,
                SUM(subscription_status = 'trialing')                            AS trialing,
                SUM(subscription_status IN ('cancelled','canceled','past_due'))  AS cancelled
         FROM users WHERE role = 'client'"
    );
    if ($agg) {
        $stats['total']     = (int) ($agg['total'] ?? 0);
        $stats['active']    = (int) ($agg['active'] ?? 0);
        $stats['trialing']  = (int) ($agg['trialing'] ?? 0);
        $stats['cancelled'] = (int) ($agg['cancelled'] ?? 0);
    }
} catch (Throwable $e) {
    // keep zeros — degrade gracefully
}

/* ---------- Businesses list (bots is a core table → correlated subquery is safe) ---------- */
$businesses = [];
try {
    $businesses = db_fetch_all(
        "SELECT u.id, u.name, u.company_name, u.email, u.subscription_plan, u.subscription_status,
                u.created_at,
                (SELECT COUNT(*) FROM bots b WHERE b.user_id = u.id) AS bots_count
         FROM users u
         WHERE $where
         ORDER BY u.created_at DESC
         LIMIT 200",
        $types,
        $params
    );
} catch (Throwable $e) {
    $businesses = [];
}

/* ---------- Derived counts keyed by user id (each guarded so a missing table only zeroes itself) ---------- */
$leadCounts = [];
try {
    foreach (db_fetch_all(
        'SELECT b.user_id AS uid, COUNT(l.id) AS c
         FROM leads l JOIN bots b ON b.id = l.bot_id
         GROUP BY b.user_id'
    ) as $r) {
        $leadCounts[(int) $r['uid']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    $leadCounts = [];
}

$orderCounts = [];
try {
    ensure_commerce_schema(); // bot_orders may be absent on a fresh install
    foreach (db_fetch_all(
        'SELECT b.user_id AS uid, COUNT(o.id) AS c
         FROM bot_orders o JOIN bots b ON b.id = o.bot_id
         GROUP BY b.user_id'
    ) as $r) {
        $orderCounts[(int) $r['uid']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    $orderCounts = [];
}

/* ---------- Selected business → quick-preview detail panel ---------- */
$selId    = (int) ($_GET['id'] ?? 0);
$detail   = null;
$dBots    = 0;
$dLeads   = 0;
$dOrders  = 0;
$dRevenue = 0.0;
$dActivity = [];
$dLimits  = ['bots' => 0, 'leads' => 0, 'chats' => 0];
if ($selId > 0) {
    try {
        $detail = db_fetch("SELECT * FROM users WHERE id = ? AND role = 'client'", 'i', [$selId]);
    } catch (Throwable $e) {
        $detail = null;
    }
    if ($detail) {
        try {
            $dBots = (int) (db_fetch('SELECT COUNT(*) AS c FROM bots WHERE user_id = ?', 'i', [$selId])['c'] ?? 0);
        } catch (Throwable $e) {
            $dBots = 0;
        }
        $dLeads  = $leadCounts[$selId] ?? 0;
        $dOrders = $orderCounts[$selId] ?? 0;

        try {
            $dActivity = db_fetch_all(
                'SELECT l.id, l.name, l.status, l.created_at, b.name AS bot_name
                 FROM leads l JOIN bots b ON b.id = l.bot_id
                 WHERE b.user_id = ?
                 ORDER BY l.created_at DESC
                 LIMIT 6',
                'i',
                [$selId]
            );
        } catch (Throwable $e) {
            $dActivity = [];
        }

        try {
            ensure_commerce_schema();
            $dRevenue = (float) (db_fetch(
                'SELECT COALESCE(SUM(o.total_amount),0) AS s
                 FROM bot_orders o JOIN bots b ON b.id = o.bot_id
                 WHERE b.user_id = ?',
                'i',
                [$selId]
            )['s'] ?? 0);
        } catch (Throwable $e) {
            $dRevenue = 0.0;
        }

        if (function_exists('get_plan_limits')) {
            $lim = get_plan_limits((string) ($detail['subscription_plan'] ?? ''));
            if (is_array($lim)) {
                $dLimits['bots']  = (int) ($lim['bots'] ?? 0);
                $dLimits['leads'] = (int) ($lim['leads'] ?? 0);
                $dLimits['chats'] = (int) ($lim['chats'] ?? 0);
            }
        }
    }
}

/* ---------- Pagination ---------- */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 10;
$totalBiz   = count($businesses);
$totalPages = max(1, (int)ceil($totalBiz / $perPage));
$page       = min($page, $totalPages);
$pagedBiz   = array_slice($businesses, ($page - 1) * $perPage, $perPage);

/* ---------- Sub-tab (deep-linkable via $_GET['tab']) ---------- */
$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'ov')) ?: 'ov';
if (!in_array($tab, ['ov', 'us', 'sub', 'act'], true)) {
    $tab = 'ov';
}

/* ---------- Presentation helpers ---------- */
$statusClass = static function (string $st): string {
    return match (strtolower(trim($st))) {
        'active'                          => 'badge--green',
        'trialing'                        => 'badge--amber',
        'past_due', 'cancelled', 'canceled' => 'badge--red',
        default                           => 'badge--gray',
    };
};
$statusLabel = static function (string $st): string {
    $st = trim($st);
    return $st === '' ? 'Unknown' : ucfirst(str_replace('_', ' ', strtolower($st)));
};
$avatarColor = static function (int $id): string {
    return ['green', 'purple', 'orange', 'pink', ''][$id % 5];
};
$isActiveStatus = static function (string $st): bool {
    return in_array(strtolower(trim($st)), ['active', 'trialing', 'past_due'], true);
};
$planPillClass = static function (string $plan): string {
    $plan = strtolower(trim($plan));
    return match (true) {
        str_contains($plan, 'pro')        => 'badge--green',
        str_contains($plan, 'business')   => 'badge--purple',
        str_contains($plan, 'growth')     => 'badge--blue',
        str_contains($plan, 'agency')     => 'badge--cyan',
        str_contains($plan, 'enterprise') => 'badge--amber',
        str_contains($plan, 'starter')    => 'badge--gray',
        default                           => 'badge--gray',
    };
};
/* Industry key → display label */
$industryLabel = static function (string $k): string {
    return match (strtolower(trim($k))) {
        'restaurant','food' => 'Restaurant',
        'real_estate','realestate' => 'Real Estate',
        'healthcare','health' => 'Healthcare',
        'retail','ecommerce','e-commerce' => 'E-Commerce',
        'education' => 'Education',
        'beauty','salon' => 'Beauty / Salon',
        'hospitality' => 'Hospitality',
        'construction' => 'Construction',
        'petservices','pet_services' => 'Pet Services',
        default => $k !== '' ? ucwords(str_replace('_',' ',$k)) : '—',
    };
};
$qParam = $q !== '' ? '&q=' . urlencode($q) : '';

/* ---------- WA status per user (from bots) ---------- */
$waByUser = [];
try {
    foreach (db_fetch_all(
        "SELECT b.user_id AS uid, MAX(b.whatsapp_verified) AS wa_ok,
                (SELECT cwa.phone_display_number FROM client_whatsapp_accounts cwa
                 WHERE cwa.client_id=b.user_id AND cwa.connection_status='active' LIMIT 1) AS wa_phone
         FROM bots b GROUP BY b.user_id", '', []
    ) as $r) {
        $waByUser[(int)$r['uid']] = [(bool)$r['wa_ok'], (string)($r['wa_phone']??'')];
    }
} catch (Throwable $e) { $waByUser = []; }

/* ---------- Industry per user (from bots) ---------- */
$industryByUser = [];
try {
    foreach (db_fetch_all("SELECT user_id, industry_key FROM bots GROUP BY user_id", '', []) as $r) {
        $industryByUser[(int)$r['user_id']] = (string)($r['industry_key']??'');
    }
} catch (Throwable $e) { $industryByUser = []; }

/* ---------- Usage % for bar in list ---------- */
$usageByUser = [];
try {
    foreach (db_fetch_all(
        "SELECT b.user_id AS uid, COUNT(l.id) AS leads
         FROM leads l JOIN bots b ON b.id=l.bot_id GROUP BY b.user_id", '', []
    ) as $r) {
        $usageByUser[(int)$r['uid']] = (int)$r['leads'];
    }
} catch (Throwable $e) { $usageByUser = []; }

$plans = function_exists('get_plans_merged') ? get_plans_merged() : (function_exists('get_plans') ? get_plans() : []);

iqp_admin_begin($user, 'businesses', [
    'subtitle' => 'Every business (SaaS customer) registered on the platform',
    'actions'  => '<a class="btn btn--primary btn--sm" href="/admin/create-client"><span class="ic" data-ic="plus"></span> Add Business</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4">
  <div class="stat-card"><div class="stat-card__top">
    <span class="stat-tile blue"><span class="ic" data-ic="building"></span></span>
    <div class="grow">
      <div class="stat-card__label">Total Businesses</div>
      <div class="stat-card__value"><?= number_format($stats['total']) ?></div>
    </div>
  </div>
  <div class="stat-card__foot"><span class="trend up">↑ 12.5%</span><span class="muted">vs Apr 20 – May 19</span></div>
  </div>
  <div class="stat-card"><div class="stat-card__top">
    <span class="stat-tile green"><span class="ic" data-ic="checkcircle"></span></span>
    <div class="grow">
      <div class="stat-card__label">Active Businesses</div>
      <div class="stat-card__value"><?= number_format($stats['active']) ?></div>
    </div>
  </div>
  <div class="stat-card__foot"><span class="trend up">↑ 10.8%</span><span class="muted">vs Apr 20 – May 19</span></div>
  </div>
  <div class="stat-card"><div class="stat-card__top">
    <span class="stat-tile red"><span class="ic" data-ic="slash"></span></span>
    <div class="grow">
      <div class="stat-card__label">Suspended</div>
      <div class="stat-card__value"><?= number_format($stats['cancelled']) ?></div>
    </div>
  </div>
  <div class="stat-card__foot"><span class="trend down">↓ 3.4%</span><span class="muted">vs Apr 20 – May 19</span></div>
  </div>
  <div class="stat-card"><div class="stat-card__top">
    <span class="stat-tile purple"><span class="ic" data-ic="clock"></span></span>
    <div class="grow">
      <div class="stat-card__label">Trial Accounts</div>
      <div class="stat-card__value"><?= number_format($stats['trialing']) ?></div>
    </div>
  </div>
  <div class="stat-card__foot"><span class="trend down">↓ 7.6%</span><span class="muted">vs Apr 20 – May 19</span></div>
  </div>
</div>

<div class="grid g-main-side" style="margin-top:16px">
  <!-- ===== Businesses table ===== -->
  <div class="card">
    <div class="card__head iqp-toolbar" style="gap:10px">
      <form method="get" action="/admin/businesses" class="input-icon" style="flex:1 1 240px;max-width:360px">
        <svg style="position:absolute;left:13px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted-2)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input class="input" type="search" name="q" value="<?= sanitize($q) ?>" placeholder="Search businesses..." style="padding-left:38px">
      </form>
      <button type="button" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:6px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        Filters
      </button>
      <button type="button" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:6px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export
      </button>
      <span class="muted small nowrap">Showing 1 to <?= min($perPage, $totalBiz) ?> of <?= number_format($totalBiz) ?> results</span>
    </div>
    <div class="iqp-bulk-bar" id="bizBulkBar" hidden>
      <span class="iqp-bulk-bar__count"><strong id="bizBulkCount">0</strong> selected</span>
      <div class="iqp-bulk-bar__actions">
        <button type="button" class="btn btn--soft btn--sm" data-biz-bulk="activate"><span class="ic" data-ic="checkcircle"></span> Activate</button>
        <button type="button" class="btn btn--ghost btn--sm" data-biz-bulk="suspend"><span class="ic" data-ic="slash"></span> Suspend</button>
        <button type="button" class="btn btn--danger btn--sm" data-biz-bulk="delete"><span class="ic" data-ic="trash"></span> Delete</button>
        <button type="button" class="btn btn--ghost btn--sm" id="bizBulkClear">Clear</button>
      </div>
    </div>
    <form method="post" id="bizBulkForm" style="display:none" aria-hidden="true">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
      <input type="hidden" name="action" value="bulk">
      <input type="hidden" name="bulk_action" id="bizBulkAction" value="">
      <div id="bizBulkIds"></div>
    </form>
    <div class="card__body" style="padding:0">
      <div class="table-wrap" data-bulk-select="businesses">
        <table class="tbl">
          <thead><tr>
            <th style="width:36px"><input type="checkbox" id="checkAll" class="iqp-row-check-all" aria-label="Select all on this page" style="width:16px;height:16px;accent-color:var(--green-600);cursor:pointer"/></th>
            <th>Business / Owner</th>
            <th>Industry</th>
            <th>Plan</th>
            <th>Status</th>
            <th>WhatsApp</th>
            <th>Usage (This Month)</th>
            <th>Joined On</th>
            <th style="width:40px"></th>
          </tr></thead>
          <tbody>
          <?php if ($pagedBiz === []): ?>
            <tr><td colspan="9">
              <div class="iqp-empty" style="padding:34px 12px;text-align:center">
                <?= $q !== ''
                    ? 'No businesses match &ldquo;' . sanitize($q) . '&rdquo;.'
                    : 'No businesses yet. New sign-ups will appear here.' ?>
              </div>
            </td></tr>
          <?php else: foreach ($pagedBiz as $b):
              $id      = (int)$b['id'];
              $display = trim((string)($b['company_name'] ?? '')) !== ''
                  ? (string)$b['company_name'] : (string)($b['name'] ?? 'Unnamed');
              $st      = (string)($b['subscription_status'] ?? '');
              $active  = $isActiveStatus($st);
              $leads   = $leadCounts[$id] ?? 0;
              $joined  = (string)($b['created_at'] ?? '');
              $aColor  = $avatarColor($id);
              $planSlug = (string)($b['subscription_plan'] ?? '');
              $indKey  = $industryByUser[$id] ?? '';
              [$waOk, $waPhone] = $waByUser[$id] ?? [false, ''];
              $uLeads  = $usageByUser[$id] ?? 0;
              $uLimit  = 2000;
              $uPct    = min(100, (int)round($uLeads / max(1,$uLimit) * 100));
          ?>
            <tr class="<?= $id === $selId ? 'is-selected' : '' ?>">
              <td><input type="checkbox" class="row-cb iqp-row-check" style="width:16px;height:16px;accent-color:var(--green-600);cursor:pointer" value="<?= $id ?>" onclick="event.stopPropagation()"/></td>
              <td>
                <div class="cell-media">
                  <span class="avatar <?= $aColor ?>" style="border-radius:10px;font-size:12px;font-weight:700"><?= sanitize(iqp_initials($display)) ?></span>
                  <div>
                    <div class="strong"><a href="/admin/businesses?id=<?= $id . $qParam ?>"><?= sanitize($display) ?></a></div>
                    <div class="sub"><?= sanitize((string)($b['email'] ?? '')) ?></div>
                  </div>
                </div>
              </td>
              <td>
                <?php if ($indKey !== ''): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--text)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                  <?= sanitize($industryLabel($indKey)) ?>
                </span>
                <?php else: ?><span class="muted small">—</span><?php endif; ?>
              </td>
              <td><span class="badge <?= $planPillClass($planSlug) ?>"><?= sanitize(iqp_plan_label($planSlug)) ?></span></td>
              <td><span class="badge <?= $statusClass($st) ?>"><span class="dot"></span><?= sanitize($statusLabel($st)) ?></span></td>
              <td>
                <?php if ($waOk): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--green-700)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="#25D366"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                  Connected
                </span>
                <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--red-600)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                  Error
                </span>
                <?php endif; ?>
              </td>
              <td style="min-width:110px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px"><?= number_format($uLeads) ?> / <?= number_format($uLimit) ?></div>
                <div class="bar" style="height:5px"><span style="width:<?= $uPct ?>%"></span></div>
                <div style="font-size:11px;color:var(--muted-2);margin-top:2px"><?= $uPct ?>% of limit</div>
              </td>
              <td class="muted nowrap" style="font-size:12.5px"><?= $joined !== '' ? sanitize(date('d M, Y', strtotime($joined))) : '—' ?></td>
              <td>
                <div style="position:relative">
                  <button type="button" class="icon-btn" style="width:30px;height:30px;border-radius:8px" data-row-menu-toggle onclick="iqpToggleRowMenu(this)" title="Actions">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                  </button>
                  <div class="row-menu">
                    <a class="row-menu-item" href="/admin/client-detail?id=<?= $id ?>">View full profile</a>
                    <a class="row-menu-item" href="/admin/businesses?id=<?= $id ?>">Edit business</a>
                    <a class="row-menu-item" href="/admin/subscriptions?biz=<?= $id ?>">Change plan</a>
                    <div style="height:1px;background:var(--line-2);margin:4px 0"></div>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="biz_id" value="<?= $id ?>">
                      <input type="hidden" name="target" value="<?= $active ? 'cancelled' : 'active' ?>">
                      <button type="submit" class="row-menu-item" style="width:100%;color:<?= $active?'var(--red-600)':'var(--green-700)' ?>"><?= $active ? 'Suspend account' : 'Reactivate account' ?></button>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--line-2);flex-wrap:wrap;gap:10px">
        <span class="muted small">Showing <?= ($totalBiz>0?(($page-1)*$perPage+1):0) ?> to <?= min($page*$perPage,$totalBiz) ?> of <?= number_format($totalBiz) ?> results</span>
        <div style="display:flex;align-items:center;gap:6px">
          <?php
          $baseHref = '/admin/businesses?per='.$perPage.$qParam.($selId?'&id='.$selId:'');
          if ($page>1): ?><a href="<?= sanitize($baseHref.'&page='.($page-1)) ?>" class="btn btn--ghost btn--sm" style="padding:5px 10px">&lsaquo;</a><?php endif; ?>
          <?php for($pg=max(1,$page-2);$pg<=min($totalPages,$page+2);$pg++): ?>
          <a href="<?= sanitize($baseHref.'&page='.$pg) ?>" class="btn btn--sm <?= $pg===$page?'btn--primary':'btn--ghost' ?>" style="padding:5px 10px;min-width:32px"><?= $pg ?></a>
          <?php endfor; ?>
          <?php if($page<$totalPages): ?><a href="<?= sanitize($baseHref.'&page='.($page+1)) ?>" class="btn btn--ghost btn--sm" style="padding:5px 10px">&rsaquo;</a><?php endif; ?>
          <select onchange="location.href='<?= sanitize('/admin/businesses?page=1'.$qParam.($selId?'&id='.$selId:'')) ?>&per='+this.value" class="select" style="width:auto;padding:5px 28px 5px 10px;font-size:12.5px">
            <?php foreach([10,25,50,100] as $pp): ?>
            <option value="<?= $pp ?>"<?= $pp===$perPage?' selected':'' ?>><?= $pp ?> / page</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Quick-preview detail panel ===== -->
  <div class="card" style="align-self:start;position:sticky;top:16px">
  <?php if (!$detail): ?>
    <div class="card__body">
      <div class="iqp-empty" style="padding:44px 16px;text-align:center">
        <span class="stat-tile blue" style="margin:0 auto 12px"><span class="ic" data-ic="building"></span></span>
        <div class="strong">Select a business to preview</div>
        <div class="muted small" style="margin-top:6px">Pick a row to see contact, usage and subscription details here.</div>
      </div>
    </div>
  <?php else:
      $dId      = (int)$detail['id'];
      $dName    = trim((string)($detail['company_name'] ?? '')) !== ''
          ? (string)$detail['company_name'] : (string)($detail['name'] ?? 'Unnamed');
      $dStatus  = (string)($detail['subscription_status'] ?? '');
      $dActive  = $isActiveStatus($dStatus);
      $dPlanSlug = (string)($detail['subscription_plan'] ?? '');
      $dPlan    = is_array($plans[$dPlanSlug] ?? null) ? $plans[$dPlanSlug] : [];
      $dExpires = (string)($detail['subscription_expires_at'] ?? '');
      $dJoined  = (string)($detail['created_at'] ?? '');
      $dEmail   = (string)($detail['email'] ?? '');
      $dPhone   = (string)($detail['phone'] ?? '');
      $dIndKey  = $industryByUser[$dId] ?? '';
      [$dWaOk, $dWaPhone] = $waByUser[$dId] ?? [false, ''];
      $dAcolor  = $avatarColor($dId);
      $dWebsite = (string)($detail['website'] ?? '');
      $dBizId   = 'b_' . substr(md5((string)$dId), 0, 12);
  ?>
    <!-- Header -->
    <div class="card__head" style="gap:12px">
      <span class="avatar <?= $dAcolor ?>" style="width:48px;height:48px;border-radius:12px;font-size:14px;font-weight:800"><?= sanitize(iqp_initials($dName)) ?></span>
      <div class="grow" style="min-width:0">
        <div class="strong" style="font-size:15px"><?= sanitize($dName) ?></div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap">
          <?php if ($dIndKey !== ''): ?>
          <span style="font-size:12px;color:var(--muted)">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
            <?= sanitize($industryLabel($dIndKey)) ?>
          </span>
          <?php endif; ?>
          <span class="badge <?= $statusClass($dStatus) ?>" style="font-size:11px;padding:2px 8px"><span class="dot"></span><?= sanitize($statusLabel($dStatus)) ?></span>
        </div>
      </div>
      <a href="/admin/businesses<?= $q !== '' ? '?q='.urlencode($q) : '' ?>" title="Close" style="color:var(--muted-2);flex:0 0 auto">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </a>
    </div>

    <!-- Contact chips -->
    <div style="padding:10px 20px;border-bottom:1px solid var(--line-2);display:flex;flex-direction:column;gap:6px">
      <?php if ($dEmail !== ''): ?>
      <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
        <a href="mailto:<?= sanitize($dEmail) ?>" style="color:var(--text)"><?= sanitize($dEmail) ?></a>
      </div>
      <?php endif; ?>
      <?php if ($dPhone !== ''): ?>
      <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.56 1.2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92Z"/></svg>
        <?= sanitize($dPhone) ?>
      </div>
      <?php endif; ?>
      <?php if ($dWebsite !== ''): ?>
      <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        <a href="<?= sanitize($dWebsite) ?>" target="_blank" style="color:var(--text)"><?= sanitize($dWebsite) ?></a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Sub-tabs -->
    <div style="padding:8px 12px"><nav class="iqp-tab-nav iqp-tab-nav--admin iqp-tab-nav--pills" aria-label="Business detail"><div class="pill-tabs iqp-tab-nav__grid" data-tabset="biz">
      <button type="button" class="pill-tab<?= $tab==='ov' ?' is-active':'' ?>" data-tab="ov">Overview</button>
      <button type="button" class="pill-tab<?= $tab==='us' ?' is-active':'' ?>" data-tab="us">Usage</button>
      <button type="button" class="pill-tab<?= $tab==='sub'?' is-active':'' ?>" data-tab="sub">Subscription</button>
      <button type="button" class="pill-tab<?= $tab==='act'?' is-active':'' ?>" data-tab="act">Activity</button>
    </div></nav></div>

    <div class="card__body" data-panelset="biz">
      <!-- Overview -->
      <div class="tab-panel<?= $tab==='ov'?' is-active':'' ?>" data-panel="ov">
        <!-- Business Info grid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;margin-bottom:16px">
          <div>
            <div class="eyebrow" style="margin-bottom:3px">Owner Name</div>
            <div class="strong" style="font-size:13px"><?= sanitize((string)($detail['name'] ?? '—')) ?></div>
          </div>
          <div>
            <div class="eyebrow" style="margin-bottom:3px">Industry</div>
            <div class="strong" style="font-size:13px"><?= sanitize($dIndKey !== '' ? $industryLabel($dIndKey) : '—') ?></div>
          </div>
          <div>
            <div class="eyebrow" style="margin-bottom:3px">Business Email</div>
            <div style="font-size:12.5px;word-break:break-all"><?= sanitize($dEmail ?: '—') ?></div>
          </div>
          <div>
            <div class="eyebrow" style="margin-bottom:3px">Joined On</div>
            <div style="font-size:12.5px"><?= $dJoined !== '' ? sanitize(date('d M, Y', strtotime($dJoined))) : '—' ?></div>
          </div>
          <?php if ($dWebsite !== ''): ?>
          <div>
            <div class="eyebrow" style="margin-bottom:3px">Business Website</div>
            <div style="font-size:12.5px"><a href="<?= sanitize($dWebsite) ?>" target="_blank" style="color:var(--green-700)"><?= sanitize($dWebsite) ?></a></div>
          </div>
          <?php endif; ?>
          <div>
            <div class="eyebrow" style="margin-bottom:3px">Business ID</div>
            <div style="font-size:11.5px;color:var(--muted-2);font-family:monospace"><?= sanitize($dBizId) ?></div>
          </div>
        </div>
        <div class="divider"></div>
        <!-- WA + AI status mini-cards -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
          <div style="border:1px solid var(--line);border-radius:10px;padding:10px">
            <div class="eyebrow" style="margin-bottom:6px">WhatsApp Status</div>
            <div style="display:flex;align-items:center;gap:6px">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="<?= $dWaOk?'#25D366':'#94a3b8' ?>"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
              <span style="font-size:12.5px;font-weight:600;color:<?= $dWaOk?'var(--green-700)':'var(--muted)' ?>"><?= $dWaOk ? 'Connected' : 'Not Connected' ?></span>
            </div>
            <?php if ($dWaPhone !== ''): ?><div style="font-size:11px;color:var(--muted-2);margin-top:3px"><?= sanitize($dWaPhone) ?></div><?php endif; ?>
          </div>
          <div style="border:1px solid var(--line);border-radius:10px;padding:10px">
            <div class="eyebrow" style="margin-bottom:6px">AI Assistant</div>
            <div style="display:flex;align-items:center;gap:6px">
              <span style="width:8px;height:8px;border-radius:50%;background:var(--green-600);flex:0 0 auto"></span>
              <span style="font-size:12.5px;font-weight:600;color:var(--green-700)">Active</span>
            </div>
          </div>
        </div>
        <!-- Quick Actions qa-grid -->
        <div class="eyebrow" style="margin-bottom:10px">Quick Actions</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px">
          <a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=<?= $dId ?>" style="flex-direction:column;gap:4px;padding:10px 6px;font-size:12px;text-align:center">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            View as Business
          </a>
          <a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=<?= $dId ?>#edit" style="flex-direction:column;gap:4px;padding:10px 6px;font-size:12px;text-align:center">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
            Edit Business
          </a>
          <a class="btn btn--ghost btn--sm" href="/admin/subscriptions?biz=<?= $dId ?>" style="flex-direction:column;gap:4px;padding:10px 6px;font-size:12px;text-align:center">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/></svg>
            Change Plan
          </a>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="biz_id" value="<?= $dId ?>">
            <input type="hidden" name="target" value="cancelled">
            <button type="submit" class="btn btn--ghost btn--sm btn--block" style="flex-direction:column;gap:4px;padding:10px 6px;font-size:12px;color:var(--amber-700);border-color:var(--amber-100)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M10 15V9M14 15V9"/></svg>
              Pause Account
            </button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="biz_id" value="<?= $dId ?>">
            <input type="hidden" name="target" value="<?= $dActive ? 'cancelled' : 'active' ?>">
            <button type="submit" class="btn btn--sm btn--block <?= $dActive ? 'btn--danger' : 'btn--soft' ?>" style="flex-direction:column;gap:4px;padding:10px 6px;font-size:12px">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 14.14 14.14"/></svg>
              <?= $dActive ? 'Suspend' : 'Reactivate' ?>
            </button>
          </form>
          <a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=<?= $dId ?>&impersonate=1" style="flex-direction:column;gap:4px;padding:10px 6px;font-size:12px;color:var(--purple-700);border-color:var(--purple-100)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Impersonate
          </a>
        </div>
        <!-- Internal Notes -->
        <div class="divider"></div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <span class="eyebrow">Internal Notes</span>
          <button type="button" class="btn btn--ghost btn--sm" style="font-size:12px;padding:4px 10px">+ Add Note</button>
        </div>
        <div style="background:var(--field);border-radius:8px;padding:10px 12px;font-size:12.5px;color:var(--text);line-height:1.5;min-height:48px">
          <span class="muted">No internal notes yet. Add one to keep track of client-specific details.</span>
        </div>
      </div>

      <!-- Usage -->
      <div class="tab-panel<?= $tab==='us'?' is-active':'' ?>" data-panel="us">
        <div class="stack" style="gap:14px">
          <?php
          $unlimited   = 1000000;
          $botsLimit   = $dLimits['bots'];
          $leadsLimit  = $dLimits['leads'];
          $botsCapped  = $botsLimit > 0 && $botsLimit < $unlimited;
          $leadsCapped = $leadsLimit > 0 && $leadsLimit < $unlimited;
          $botsPct     = $botsCapped ? min(100,(int)round($dBots/$botsLimit*100)) : 0;
          $leadsPct    = $leadsCapped ? min(100,(int)round($dLeads/$leadsLimit*100)) : 0;
          ?>
          <div>
            <div class="between" style="margin-bottom:6px"><span class="muted">Conversations</span><span class="strong"><?= number_format($dLeads) ?><?= $leadsCapped ? ' / '.number_format($leadsLimit) : '' ?></span></div>
            <?php if ($leadsCapped): ?><div class="bar"><span style="width:<?= $leadsPct ?>%"></span></div><div style="font-size:11px;color:var(--muted-2);margin-top:3px"><?= $leadsPct ?>% of limit</div><?php endif; ?>
          </div>
          <div>
            <div class="between" style="margin-bottom:6px"><span class="muted">Bots</span><span class="strong"><?= number_format($dBots) ?><?= $botsCapped ? ' / '.number_format($botsLimit) : '' ?></span></div>
            <?php if ($botsCapped): ?><div class="bar"><span style="width:<?= $botsPct ?>%"></span></div><?php endif; ?>
          </div>
          <div class="between"><span class="muted">Orders</span><span class="strong"><?= number_format($dOrders) ?></span></div>
          <div class="between"><span class="muted">Order revenue</span><span class="strong"><?= sanitize(format_money($dRevenue,'PKR')) ?></span></div>
        </div>
      </div>

      <!-- Subscription -->
      <div class="tab-panel<?= $tab==='sub'?' is-active':'' ?>" data-panel="sub">
        <div style="background:var(--green-50);border:1px solid var(--green-100);border-radius:10px;padding:14px;margin-bottom:14px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green-600)" stroke-width="2"><path d="M2 20h20M5 20V8l7-6 7 6v12"/></svg>
            <span class="strong" style="color:var(--green-700)"><?= sanitize(iqp_plan_label($dPlanSlug)) ?></span>
          </div>
          <div style="font-size:22px;font-weight:800;color:var(--green-600)">
            <?php if (!empty($dPlan['contact_only'])) { echo 'Custom'; } else {
              $price = (int)($dPlan['price_pkr'] ?? $dPlan['price'] ?? 0);
              echo 'PKR '.number_format($price).'<span style="font-size:13px;font-weight:500;color:var(--muted)"> /month</span>';
            } ?>
          </div>
          <?php if ($dExpires !== '' && $dExpires !== '0000-00-00 00:00:00'): ?>
          <div style="font-size:12px;color:var(--muted);margin-top:4px">Renews on <?= sanitize(date('d M, Y', strtotime($dExpires))) ?></div>
          <?php endif; ?>
        </div>
        <div class="stack" style="gap:10px;margin-bottom:14px">
          <div class="between"><span class="muted">Status</span><span class="badge <?= $statusClass($dStatus) ?>"><span class="dot"></span><?= sanitize($statusLabel($dStatus)) ?></span></div>
          <?php if (isset($dPlan['bots'])): ?>
          <div class="between"><span class="muted">Plan limits</span><span class="strong"><?= (int)($dPlan['bots']??0) ?> bots · <?= sanitize((string)($dPlan['chats']??'—')) ?> chats</span></div>
          <?php endif; ?>
        </div>
        <a class="btn btn--ghost btn--block btn--sm" href="/admin/subscriptions"><span class="ic" data-ic="card"></span> Manage in Billing</a>
      </div>

      <!-- Activity -->
      <div class="tab-panel<?= $tab==='act'?' is-active':'' ?>" data-panel="act">
        <?php if ($dActivity === []): ?>
          <div class="iqp-empty" style="padding:20px 8px;text-align:center">No recent lead activity.</div>
        <?php else: ?>
          <div class="list">
            <?php foreach ($dActivity as $a): ?>
            <div class="list-row">
              <span class="mini-tile" style="background:var(--green-50);color:var(--green-600)"><span class="ic" data-ic="user"></span></span>
              <div class="list-row__main">
                <div class="list-row__title" style="font-size:13px"><?= sanitize((string)($a['name'] ?? 'Lead')) ?> · <span class="muted"><?= sanitize($statusLabel((string)($a['status'] ?? ''))) ?></span></div>
                <div class="list-row__sub"><?= sanitize((string)($a['bot_name'] ?? 'Bot')) ?> · <?= sanitize(iqp_rel_time((string)($a['created_at'] ?? '')) ?: '—') ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php iqp_admin_end();
