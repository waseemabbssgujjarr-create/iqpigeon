<?php
/** @var array $user */
/** @var array $bots */
/** @var int $botId */
/** @var array $products */
/** @var string $message */
/** @var string $error */
/** @var array $websiteImport */
/** @var array $menuFileImport */
/** @var string $menuKeywords */
/** @var array $metaCatalogStatus */
/** @var string $metaCatalogId */
/** @var bool $catalogMutated */
/** @var string $waDisplayPhone */
/** @var bool $waCatalogConnected */

$q          = trim((string)($_GET['q'] ?? ''));
$catFilter  = trim((string)($_GET['category'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$sortFilter = trim((string)($_GET['sort'] ?? 'newest'));

$categories = [];
$total = count($products);
$activeN = 0; $oosN = 0; $inactiveN = 0;
foreach ($products as $p) {
    $c = trim((string)($p['category'] ?? ''));
    if ($c !== '') $categories[$c] = true;
    $active = !empty($p['is_active']);
    $stock  = $p['stock'] ?? null;
    $oos    = $stock !== null && $stock !== '' && (int)$stock <= 0;
    if (!$active) $inactiveN++;
    elseif ($oos) $oosN++;
    else $activeN++;
}
$cats = array_keys($categories);
sort($cats);

$shown = [];
foreach ($products as $p) {
    if ($q !== '' && stripos((string)($p['name'].' '.($p['description']??'')), $q) === false) continue;
    if ($catFilter !== '' && strcasecmp((string)($p['category']??''), $catFilter) !== 0) continue;
    $active = !empty($p['is_active']);
    $stock  = $p['stock'] ?? null;
    $oos    = $stock !== null && $stock !== '' && (int)$stock <= 0;
    $st = !$active ? 'inactive' : ($oos ? 'oos' : 'active');
    if ($statusFilter === 'active'   && $st !== 'active') continue;
    if ($statusFilter === 'oos'      && $st !== 'oos') continue;
    if ($statusFilter === 'inactive' && $st !== 'inactive') continue;
    $shown[] = $p;
}

// Sort
if ($sortFilter === 'oldest') {
    usort($shown, fn($a,$b) => strtotime((string)($a['created_at']??'')) <=> strtotime((string)($b['created_at']??'')));
} elseif ($sortFilter === 'price_asc') {
    usort($shown, fn($a,$b) => (float)($a['price']??0) <=> (float)($b['price']??0));
} elseif ($sortFilter === 'price_desc') {
    usort($shown, fn($a,$b) => (float)($b['price']??0) <=> (float)($a['price']??0));
} else {
    // newest (default) — already ordered by DB, keep as-is
}

// Pagination
$perPage  = 16;
$page     = max(1, (int)($_GET['page'] ?? 1));
$totalShown = count($shown);
$totalPages = max(1, (int)ceil($totalShown / $perPage));
$page = min($page, $totalPages);
$paged = array_slice($shown, ($page - 1) * $perPage, $perPage);

$currency = function_exists('default_currency') ? default_currency() : 'PKR';
$csrf = csrf_token();
$waDisplayPhone = (string) ($waDisplayPhone ?? '');
$waCatalogConnected = !empty($waCatalogConnected);
$metaError = meta_catalog_status_is_problem((string) ($metaCatalogStatus['status'] ?? ''))
    || ($error !== '' && (stripos($error, 'Meta') !== false || stripos($error, 'catalog') !== false || stripos($error, 'Unsupported post') !== false));

// Edit product data
$editId = (int)($_GET['edit'] ?? 0);
$editProduct = null;
if ($editId > 0) {
    foreach ($products as $p) {
        if ((int)$p['id'] === $editId) { $editProduct = $p; break; }
    }
}

iqp_user_begin($user, 'shop', ['title' => 'Shop']);
if ($message !== '') iqp_flash($message);
if ($error !== '')   iqp_flash($error, 'err');
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[20px] lg:text-[22px] font-bold text-slate-800">Manage Products / Menu Items</h1>
    <p class="text-[13px] text-slate-500 mt-0.5">View, edit and manage all your products or menu items.</p>
  </div>
  <div class="flex flex-wrap gap-2 shrink-0">
    <button type="button" onclick="document.getElementById('iqpWebsiteFetch').scrollIntoView({behavior:'smooth',block:'center'})"
      class="flex items-center gap-1.5 bg-[#1FA855] text-white rounded-lg px-3 py-2 text-[12.5px] font-semibold hover:bg-[#18934a]">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
      Fetch from Website
    </button>
    <button type="button" onclick="document.getElementById('iqpImport').classList.toggle('hidden')"
      class="flex items-center gap-1.5 border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12.5px] font-medium text-slate-600 hover:bg-slate-50">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Import / Sync
    </button>
    <a href="#add-product"
      class="flex items-center gap-1.5 border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12.5px] font-medium text-slate-600 hover:bg-slate-50">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      Add Product
    </a>
    <?php if ($total > 0): ?>
    <form method="POST" class="inline" onsubmit="return confirm('Remove all <?= (int)$total ?> products from your catalog? This cannot be undone.')">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="delete_all_products"/>
      <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
      <button type="submit" class="flex items-center gap-1.5 border border-red-200 bg-red-50 rounded-lg px-3 py-2 text-[12.5px] font-medium text-red-600 hover:bg-red-100">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        Remove All
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($bots === []): ?>
  <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-[13px] text-slate-500">
    Create a bot first, then add products. <a class="text-[#1FA855] font-semibold" href="/client/onboarding">Set up bot</a>
  </div>
<?php else: ?>

<!-- Bot selector -->
<form method="get" class="mb-4">
  <select name="bot_id" onchange="this.form.submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] text-slate-600 bg-white">
    <?php foreach ($bots as $b): ?>
    <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === $botId ? ' selected' : '' ?>><?= sanitize($b['name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<!-- KPI Tiles -->
<?php
iqp_stat_cards([
    ['Total Products', (string) $total, 'All menu items', '#EDE9FE', '#7C3AED', '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
    ['Active', (string) $activeN, 'Currently available', '#DCFCE7', '#16A34A', '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'],
    ['Out of Stock', (string) $oosN, 'Not available', '#FFEDD5', '#EA580C', '<path d="M4 8h16l-1 12H5L4 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>'],
    ['Inactive', (string) $inactiveN, 'Deactivated items', '#F1F5F9', '#64748B', '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>'],
]);
?>

<!-- Website Fetch (always visible) -->
<div id="iqpWebsiteFetch" class="iqp-website-fetch rounded-xl p-4 lg:p-5 mb-5">
  <div class="iqp-website-fetch__head">
    <div class="text-[14px] font-bold text-slate-800">Fetch from Website</div>
    <p class="text-[12px] text-slate-500 mt-1 leading-relaxed max-w-2xl">Paste your store URL and we will auto-import products into this shop.</p>
  </div>
  <form id="website-import-form" method="POST" class="iqp-website-fetch__form mt-3" onsubmit="return false;">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
    <input type="url" name="website_url" value="<?= sanitize((string)($websiteImport['source_url']??'')) ?>" placeholder="https://yourstore.com" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[12.5px] bg-white focus:outline-none focus:border-[#1FA855] mb-2 sm:mb-0"/>
    <div class="iqp-action-btn-row">
      <button type="button" data-website-preview class="iqp-guide-btn iqp-guide-btn--secondary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <span class="iqp-guide-btn__text"><span class="iqp-guide-btn__label">Preview</span><span class="iqp-guide-btn__hint">Scan before import</span></span>
      </button>
      <button type="button" data-website-import class="iqp-guide-btn iqp-guide-btn--primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span class="iqp-guide-btn__text"><span class="iqp-guide-btn__label">Fetch &amp; Import</span><span class="iqp-guide-btn__hint">Replace catalog</span></span>
      </button>
    </div>
    <p id="website-import-status" class="text-[12px] text-slate-500 mt-2"></p>
    <div id="website-import-preview" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2"></div>
  </form>
  <p class="iqp-website-fetch__note">
    One business per account. Fetching a new website replaces your entire catalog.
  </p>
  <?php if (!empty($websiteImport['source_url'])): ?>
  <p class="text-[11px] text-slate-400 mt-2">Last synced: <?= sanitize((string)($websiteImport['source_url'] ?? '')) ?></p>
  <?php endif; ?>
</div>

<!-- Import Panel (CSV, menu, Meta) -->
<div id="iqpImport" class="<?= $metaError ? '' : 'hidden' ?> iqp-import-panel bg-white rounded-xl border border-slate-200 p-5 mb-5">
  <div class="text-[15px] font-bold text-slate-800">Import / Sync</div>
  <p class="text-[12px] text-slate-500 mt-1 mb-4">CSV, PDF menu, or the catalog on your connected WhatsApp number. Website fetch is above.</p>

  <div class="iqp-import-grid">
  <div class="iqp-import-block">
    <div class="iqp-import-block__title">Upload CSV</div>
    <form method="POST" enctype="multipart/form-data" class="iqp-import-block__form">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="import_csv"/>
      <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
      <input type="file" name="csv_file" accept=".csv,text/csv" required class="iqp-import-file"/>
      <button class="border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12px] font-medium">Import CSV</button>
    </form>
  </div>

  <div class="iqp-import-block">
    <div class="iqp-import-block__title">PDF / image menu</div>
    <form id="menu-file-import-form" class="iqp-import-block__form" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
      <input type="file" name="menu_file" accept=".pdf,application/pdf,image/png,image/jpeg,image/jpg,image/webp" required class="iqp-import-file"/>
      <button id="menu-file-import-btn" class="border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12px] font-medium">Import menu</button>
    </form>
    <p id="menu-file-import-status" class="text-[12px] text-slate-400"></p>
  </div>

  <div class="iqp-import-block iqp-import-block--whatsapp">
    <div class="iqp-import-block__title">WhatsApp catalog</div>
    <?php if ($waCatalogConnected): ?>
    <p class="iqp-import-block__note">
      Uses <?= $waDisplayPhone !== '' ? '<strong>' . sanitize($waDisplayPhone) . '</strong>' : 'your connected WhatsApp number' ?>. Syncs this shop to that number’s Meta catalog.
    </p>
    <?php else: ?>
    <p class="iqp-import-block__note">Connect WhatsApp in Settings first. The catalog on that number is linked automatically.</p>
    <?php endif; ?>
    <div class="iqp-import-actions">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="sync_meta_catalog"/>
        <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
        <button class="bg-[#1FA855] text-white rounded-lg px-3 py-2.5 text-[12px] font-semibold"<?= $waCatalogConnected ? '' : ' disabled' ?>>Sync now</button>
      </form>
      <form method="POST" onsubmit="return confirm('Clear the stored catalog ID and rediscover the catalog on this WhatsApp number? IQ Pigeon products stay.')">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="reset_meta_catalog"/>
        <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
        <input type="hidden" name="reject_catalog_id" value="<?= sanitize((string)($metaCatalogId ?? '')) ?>"/>
        <button class="border border-amber-200 bg-amber-50 rounded-lg px-3 py-2.5 text-[12px] font-medium text-amber-800">Reset catalog</button>
      </form>
    </div>
    <p class="iqp-import-status<?= meta_catalog_status_is_problem((string) ($metaCatalogStatus['status'] ?? '')) ? ' iqp-import-status--err' : '' ?>">
      <?= sanitize((string)($metaCatalogStatus['label'] ?? '')) ?>
      <?php if (!empty($metaCatalogId)): ?>
        · ID <?= sanitize((string)$metaCatalogId) ?>
      <?php endif; ?>
    </p>
    <?php if (!empty($metaCatalogStatus['detail']) && meta_catalog_status_is_problem((string) ($metaCatalogStatus['status'] ?? ''))): ?>
    <p class="iqp-import-block__note"><?= sanitize((string)$metaCatalogStatus['detail']) ?></p>
    <?php endif; ?>
  </div>
  </div>
</div>

<!-- Main Grid -->
<div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-5">

  <!-- Products Table -->
  <div class="bg-white rounded-xl border border-slate-200 p-5">
    <div class="text-[15px] font-bold text-slate-800 mb-0.5">All Products</div>
    <div class="text-[12px] text-slate-400 mb-4">Products fetched from your website and uploads.</div>

    <!-- Filters -->
    <details class="iqp-filter-drawer lg:open mb-4">
      <summary class="iqp-filter-drawer__toggle lg:hidden">
        <span class="iqp-filter-drawer__toggle-main">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
          Search &amp; filters
        </span>
        <span class="iqp-filter-drawer__hint">Category, status, sort</span>
      </summary>
    <form method="get" class="iqp-filter-drawer__body iqp-shop-filters">
      <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
      <div class="iqp-search-wrap relative flex-1 min-w-[160px]">
        <span class="iqp-search-icon" aria-hidden="true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </span>
        <input name="q" value="<?= sanitize($q) ?>" class="iqp-search-input w-full border border-slate-200 rounded-lg pr-3 py-2 text-[12.5px]" placeholder="Search products..."/>
      </div>
      <select name="category" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-500 bg-white" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($cats as $c): ?>
        <option<?= $catFilter === $c ? ' selected' : '' ?>><?= sanitize($c) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-500 bg-white" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="active"<?= $statusFilter==='active' ? ' selected' : '' ?>>Active</option>
        <option value="oos"<?= $statusFilter==='oos' ? ' selected' : '' ?>>Out of Stock</option>
        <option value="inactive"<?= $statusFilter==='inactive' ? ' selected' : '' ?>>Inactive</option>
      </select>
      <select name="sort" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-500 bg-white" onchange="this.form.submit()">
        <option value="newest"<?= $sortFilter==='newest' ? ' selected' : '' ?>>Sort: Newest</option>
        <option value="oldest"<?= $sortFilter==='oldest' ? ' selected' : '' ?>>Sort: Oldest</option>
        <option value="price_asc"<?= $sortFilter==='price_asc' ? ' selected' : '' ?>>Price: Low–High</option>
        <option value="price_desc"<?= $sortFilter==='price_desc' ? ' selected' : '' ?>>Price: High–Low</option>
      </select>
    </form>
    </details>

    <!-- Product Cards Grid — 2 cols mobile, 4 cols desktop -->
    <?php if ($paged === []): ?>
      <div class="text-[13px] text-slate-400 py-10 text-center">
        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
        </div>
        No products found.
        <div class="mt-3">
          <button type="button" onclick="document.getElementById('iqpWebsiteFetch').scrollIntoView({behavior:'smooth'})" class="text-[#1FA855] font-semibold text-[12.5px] hover:underline">Fetch from your website</button>
          or <a href="#add-product" class="text-[#1FA855] font-semibold text-[12.5px] hover:underline">add manually</a>
        </div>
      </div>
    <?php else: ?>
    <div class="iqp-product-grid">
      <?php foreach ($paged as $p):
          $active = !empty($p['is_active']);
          $stock  = $p['stock'] ?? null;
          $oos    = $stock !== null && $stock !== '' && (int)$stock <= 0;
          if (!$active)     { $pillBg = '#F1F5F9'; $pillCol = '#475569'; $pillLabel = 'Inactive'; }
          elseif ($oos)     { $pillBg = '#FFEDD5'; $pillCol = '#B45309'; $pillLabel = 'Out of Stock'; }
          else              { $pillBg = '#DCFCE7'; $pillCol = '#15803D'; $pillLabel = 'Active'; }
          $price = function_exists('catalog_format_price')
              ? catalog_format_price((float)($p['price']??0),(string)($p['currency']??$currency))
              : $currency.' '.number_format((float)($p['price']??0));
          $img      = trim((string)($p['image_url']??''));
          $name     = (string)($p['name']??'');
          $desc     = (string)($p['description']??'');
          $cat      = (string)($p['category']??'');
          $editHref = '/client/catalog?bot_id='.$botId.'&edit='.(int)$p['id'].'#add-product';
      ?>
      <div class="iqp-product-card group relative bg-white rounded-xl border border-slate-200 overflow-hidden transition-all duration-200 hover:shadow-md hover:-translate-y-0.5" data-product-id="<?= (int)$p['id'] ?>">

        <div class="iqp-product-card__media">
          <?php if ($img !== ''): ?>
            <img src="<?= sanitize($img) ?>" alt="<?= sanitize($name) ?>" loading="lazy"/>
          <?php else: ?>
            <div class="iqp-product-card__placeholder">
              <div class="iqp-product-card__placeholder-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
              </div>
              <span class="text-[10px]">No image</span>
            </div>
          <?php endif; ?>

          <span class="iqp-product-card__badge iqp-product-card__badge--status" style="background:<?= $pillBg ?>;color:<?= $pillCol ?>">
            <?= sanitize($pillLabel) ?>
          </span>

          <?php if ($cat !== ''): ?>
          <span class="iqp-product-card__badge iqp-product-card__badge--cat" title="<?= sanitize($cat) ?>">
            <?= sanitize($cat) ?>
          </span>
          <?php endif; ?>
        </div>

        <div class="iqp-product-card__body">
          <div class="iqp-product-card__title" title="<?= sanitize($name) ?>"><?= sanitize($name) ?></div>
          <?php if ($desc !== ''): ?>
          <div class="iqp-product-card__desc"><?= sanitize($desc) ?></div>
          <?php endif; ?>

          <div class="iqp-product-card__footer">
            <div class="iqp-product-card__price"><?= sanitize($price) ?></div>

            <div class="iqp-product-card__actions">
              <a href="<?= sanitize($editHref) ?>" class="iqp-product-action hover:text-[#1FA855] hover:border-[#1FA855] hover:bg-emerald-50" title="Edit product">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
              </a>
              <?php if ($img !== ''): ?>
              <a href="<?= sanitize($img) ?>" target="_blank" rel="noopener" class="iqp-product-action hover:text-blue-500 hover:border-blue-300 hover:bg-blue-50" title="View image">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>
              <?php endif; ?>
              <form method="POST" class="inline" onsubmit="return confirm('Remove this product?')">
                <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
                <input type="hidden" name="action" value="delete_product"/>
                <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"/>
                <button type="submit" class="iqp-product-action hover:text-red-500 hover:border-red-200 hover:bg-red-50" title="Delete product">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Pagination + count -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4 pt-3 border-t border-slate-100">
      <div class="text-[12px] text-slate-500">
        Showing <?= ($totalShown > 0) ? (($page-1)*$perPage+1) : 0 ?> to <?= min($page*$perPage,$totalShown) ?> of <?= $totalShown ?> products
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="flex items-center gap-1">
        <?php
        $baseHref = '/client/catalog?bot_id='.$botId
            .($q?'&q='.urlencode($q):'')
            .($catFilter?'&category='.urlencode($catFilter):'')
            .($statusFilter?'&status='.urlencode($statusFilter):'')
            .($sortFilter&&$sortFilter!=='newest'?'&sort='.urlencode($sortFilter):'');
        // Prev
        if ($page > 1): ?>
        <a href="<?= sanitize($baseHref.'&page='.($page-1)) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 text-[12px]">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <?php else: ?>
        <span class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-100 text-slate-300 text-[12px]">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
        </span>
        <?php endif;
        // Page numbers (show max 5 around current)
        $start = max(1, $page-2); $end = min($totalPages, $page+2);
        if ($start > 1): ?>
          <a href="<?= sanitize($baseHref.'&page=1') ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-[12px] text-slate-600 hover:bg-slate-50">1</a>
          <?php if ($start > 2): ?><span class="text-slate-400 text-[12px] px-1">…</span><?php endif; ?>
        <?php endif;
        for ($pg = $start; $pg <= $end; $pg++): ?>
          <a href="<?= sanitize($baseHref.'&page='.$pg) ?>"
            class="w-8 h-8 flex items-center justify-center rounded-lg border text-[12px] <?= $pg===$page ? 'bg-[#1FA855] border-[#1FA855] text-white font-semibold' : 'border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            <?= $pg ?>
          </a>
        <?php endfor;
        if ($end < $totalPages): ?>
          <?php if ($end < $totalPages-1): ?><span class="text-slate-400 text-[12px] px-1">…</span><?php endif; ?>
          <a href="<?= sanitize($baseHref.'&page='.$totalPages) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-[12px] text-slate-600 hover:bg-slate-50"><?= $totalPages ?></a>
        <?php endif;
        // Next
        if ($page < $totalPages): ?>
        <a href="<?= sanitize($baseHref.'&page='.($page+1)) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 text-[12px]">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
        </a>
        <?php else: ?>
        <span class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-100 text-slate-300 text-[12px]">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- How Products are Added -->
    <div class="border-t border-slate-100 mt-5 pt-4">
      <div class="text-[13px] font-bold text-slate-800 mb-3">How Products are Added</div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="flex items-start gap-2.5">
          <div class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1FA855" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          </div>
          <div>
            <div class="text-[12.5px] font-semibold text-slate-700">Auto Fetch</div>
            <div class="text-[11px] text-slate-400">Paste your store URL — we fetch products into this shop.</div>
          </div>
        </div>
        <div class="flex items-start gap-2.5">
          <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          </div>
          <div>
            <div class="text-[12.5px] font-semibold text-slate-700">Upload</div>
            <div class="text-[11px] text-slate-400">Upload your menu or catalog in bulk (CSV, PDF, or images).</div>
          </div>
        </div>
        <div class="flex items-start gap-2.5">
          <div class="w-9 h-9 rounded-lg bg-violet-50 flex items-center justify-center shrink-0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7C3AED" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </div>
          <div>
            <div class="text-[12.5px] font-semibold text-slate-700">Add Manually</div>
            <div class="text-[11px] text-slate-400">Add or create products manually anytime.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Add / Edit Product Form -->
  <div id="add-product" class="bg-white rounded-xl border border-slate-200 p-5 h-fit">
    <div class="text-[15px] font-bold text-slate-800"><?= $editProduct ? 'Edit Product' : 'Add Product / Menu Item' ?></div>
    <div class="text-[12px] text-slate-400 mb-4"><?= $editProduct ? 'Update the product details below.' : 'Add a new product manually.' ?></div>

    <form id="catalog-add-product-form" method="POST"
      enctype="multipart/form-data"
      action="/api/catalog-product.php"
      data-csrf="<?= sanitize($csrf) ?>">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="<?= $editProduct ? 'edit_product' : 'add_product' ?>"/>
      <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
      <?php if ($editProduct): ?>
      <input type="hidden" name="product_id" value="<?= (int)$editProduct['id'] ?>"/>
      <?php endif; ?>

      <div class="text-[11.5px] text-slate-500 mb-1 font-medium">Product Title</div>
      <input name="name" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] mb-3 focus:outline-none focus:border-[#1FA855]"
        placeholder="e.g. Chicken Burger" value="<?= sanitize((string)($editProduct['name']??'')) ?>"/>

      <div class="text-[11.5px] text-slate-500 mb-1 font-medium">Price (<?= sanitize($currency) ?>)</div>
      <input name="price" type="number" step="0.01" min="0" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] mb-3 focus:outline-none focus:border-[#1FA855]"
        placeholder="e.g. 850" value="<?= sanitize((string)($editProduct['price']??'')) ?>"/>
      <input type="hidden" name="currency" value="<?= sanitize($currency) ?>"/>

      <!-- Drag-drop image upload zone -->
      <div class="text-[11.5px] text-slate-500 mb-1 font-medium">Product Image</div>
      <div id="iqpImageDrop" class="border-2 border-dashed border-slate-200 rounded-lg p-5 mb-3 text-center cursor-pointer hover:border-[#1FA855] hover:bg-emerald-50/30 transition-colors"
           onclick="document.getElementById('iqpImageInput').click()">
        <div id="iqpImagePreview" class="hidden mb-2">
          <img id="iqpImageThumb" src="" alt="" class="w-20 h-20 object-cover rounded-lg mx-auto"/>
        </div>
        <div id="iqpImagePlaceholder">
          <svg class="mx-auto mb-2 text-slate-400" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <div class="text-[12px] text-slate-500 font-medium">Click to upload image</div>
          <div class="text-[11px] text-slate-400 mt-0.5">PNG, JPG up to 5MB</div>
        </div>
        <input id="iqpImageInput" type="file" name="product_image" accept="image/jpeg,image/png,image/webp" class="hidden"/>
      </div>
      <input name="image_url" type="url" id="iqpImageUrl"
        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] mb-3 focus:outline-none focus:border-[#1FA855]"
        placeholder="or paste image URL"
        value="<?= sanitize((string)($editProduct['image_url']??'')) ?>"/>

      <div class="text-[11.5px] text-slate-500 mb-1 font-medium">Product Link (Optional)</div>
      <input name="product_url" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] mb-3 focus:outline-none focus:border-[#1FA855]"
        placeholder="https://yourwebsite.com/product"
        value="<?= sanitize((string)($editProduct['product_url']??'')) ?>"/>

      <div class="text-[11.5px] text-slate-500 mb-1 font-medium">Description</div>
      <div class="relative mb-3">
        <textarea id="iqpProdDesc" name="description" maxlength="1000"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] h-20 resize-none focus:outline-none focus:border-[#1FA855]"
          placeholder="Describe your product..."
          oninput="document.getElementById('iqpDescCount').textContent=this.value.length"><?= sanitize((string)($editProduct['description']??'')) ?></textarea>
        <div class="absolute bottom-2 right-3 text-[10.5px] text-slate-400"><span id="iqpDescCount"><?= strlen((string)($editProduct['description']??'')) ?></span>/1000</div>
      </div>

      <label class="flex items-center gap-2 text-[12px] text-slate-600 mb-4 cursor-pointer">
        <input type="checkbox" name="is_active" value="1" <?= ($editProduct ? !empty($editProduct['is_active']) : true) ? 'checked' : '' ?> class="w-4 h-4 accent-[#1FA855]"/>
        Active in shop
      </label>

      <p id="catalog-add-product-status" class="text-[12px] hidden mb-2" role="status"></p>
      <button id="catalog-add-product-btn" data-default-label="<?= $editProduct ? 'Save Changes' : 'Add Product' ?>"
        class="w-full bg-[#1FA855] text-white rounded-lg py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">
        <?= $editProduct ? 'Save Changes' : 'Add Product' ?>
      </button>
      <?php if ($editProduct): ?>
      <a href="/client/catalog?bot_id=<?= (int)$botId ?>" class="mt-2.5 w-full border border-slate-200 rounded-lg py-2.5 text-[13px] font-medium text-slate-600 block text-center hover:bg-slate-50">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

</div>
<?php endif; ?>

<script>
// Drag-drop image preview
(function(){
  const drop = document.getElementById('iqpImageDrop');
  const inp  = document.getElementById('iqpImageInput');
  const prev = document.getElementById('iqpImagePreview');
  const thumb= document.getElementById('iqpImageThumb');
  const ph   = document.getElementById('iqpImagePlaceholder');
  const urlIn= document.getElementById('iqpImageUrl');
  if (!drop||!inp) return;

  function showPreview(src){
    thumb.src=src; prev.classList.remove('hidden'); ph.classList.add('hidden');
  }
  inp.addEventListener('change',()=>{
    const f=inp.files[0]; if(!f) return;
    showPreview(URL.createObjectURL(f)); urlIn.value='';
  });
  drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('border-[#1FA855]','bg-emerald-50/30');});
  drop.addEventListener('dragleave',()=>drop.classList.remove('border-[#1FA855]','bg-emerald-50/30'));
  drop.addEventListener('drop',e=>{
    e.preventDefault(); drop.classList.remove('border-[#1FA855]','bg-emerald-50/30');
    const f=e.dataTransfer.files[0]; if(!f||!f.type.startsWith('image/')) return;
    const dt=new DataTransfer(); dt.items.add(f); inp.files=dt.files;
    showPreview(URL.createObjectURL(f)); urlIn.value='';
  });
  // If edit mode with existing URL, show preview
  if(urlIn&&urlIn.value) showPreview(urlIn.value);
})();
</script>
<script src="/assets/js/website-import.js?v=<?= @filemtime(dirname(__DIR__,2).'/assets/js/website-import.js') ?: time() ?>"></script>
<script src="/assets/js/catalog-file-import.js?v=<?= @filemtime(dirname(__DIR__,2).'/assets/js/catalog-file-import.js') ?: time() ?>"></script>
<script src="/assets/js/catalog-add-product.js?v=<?= @filemtime(dirname(__DIR__,2).'/assets/js/catalog-add-product.js') ?: time() ?>"></script>
<?php iqp_user_end();
