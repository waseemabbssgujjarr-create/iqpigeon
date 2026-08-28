<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/shop-integrations.php';
require_once __DIR__ . '/../includes/website-import.php';

$user   = require_login();
$userId = (int) $user['id'];
$message = '';
$error   = '';

$bots  = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? ($bots[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $botId   = (int) ($_POST['bot_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $platform = trim((string) ($_POST['platform'] ?? ''));

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        $error = 'Invalid bot.';
    } elseif ($action === 'save') {
        try {
            shop_integration_save($botId, $userId, $platform, $_POST);
            $message = (shop_platforms()[$platform] ?? $platform) . ' settings saved.';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    } elseif ($action === 'sync') {
        $result = shop_sync_products($botId, $userId, $platform);
        $message = $result['success'] ? ($result['message'] ?? 'Sync complete.') : ($result['message'] ?? 'Sync failed.');
        if (!$result['success']) { $error = $message; $message = ''; }
    } elseif ($action === 'disconnect') {
        shop_integration_delete((int) ($_POST['integration_id'] ?? 0), $userId);
        $message = 'Integration removed.';
    }
}

$shopify       = $botId ? shop_integration_for_bot($botId, 'shopify')       : null;
$woo           = $botId ? shop_integration_for_bot($botId, 'woocommerce')   : null;
$syncedCount   = 0;
$websiteImport = ['source_url' => '', 'count' => 0];
if ($botId) {
    $row = db_fetch('SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id = ? AND external_source IN (\'shopify\',\'woocommerce\')', 'i', [$botId]);
    $syncedCount = (int) ($row['cnt'] ?? 0);
    $websiteImport = website_import_bot_status($botId);
}

$activeTab = (string) ($_GET['tab'] ?? 'website');
if (!in_array($activeTab, ['website','shopify','woocommerce'], true)) $activeTab = 'website';

$csrf = csrf_token();

// Stats
$totalProducts = 0;
try {
    $totalProducts = (int)(db_fetch('SELECT COUNT(*) AS cnt FROM bot_products WHERE bot_id=?', 'i', [$botId])['cnt'] ?? 0);
} catch (Throwable $e) {}

iqp_user_begin($user, 'integrations', ['title' => 'Integrations']);
if ($message !== '') iqp_flash($message);
if ($error   !== '') iqp_flash($error, 'err');
?>

<!-- Page header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[22px] font-bold text-slate-800">Integrations</h1>
    <p class="text-[14px] text-slate-500 mt-1">Connect your store and sync products automatically.</p>
  </div>
  <?php if ($bots !== []): ?>
  <form method="get" class="flex items-center gap-2">
    <input type="hidden" name="tab" value="<?= sanitize($activeTab) ?>"/>
    <select name="bot_id" onchange="this.form.submit()"
      class="border border-slate-200 rounded-xl px-3 py-2.5 text-[14px] text-slate-600 bg-white focus:outline-none focus:border-[#1FA855] shadow-sm">
      <?php foreach ($bots as $b): ?>
      <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id']===$botId?' selected':'' ?>><?= sanitize($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<?php if ($bots === []): ?>
<div class="bg-white rounded-xl border border-slate-200 p-12 text-center">
  <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6M15 2v6"/><path d="M7 8h10v3a5 5 0 0 1-10 0Z"/><path d="M12 16v6"/></svg>
  </div>
  <h2 class="text-[18px] font-bold text-slate-800 mb-2">Create a bot first</h2>
  <p class="text-[14px] text-slate-500 mb-5">You need a bot to connect integrations.</p>
  <a href="/client/onboarding" class="inline-block bg-[#1FA855] text-white rounded-xl px-6 py-3 text-[14px] font-semibold">Set up bot</a>
</div>
<?php else: ?>

<!-- Stats row -->
<?php
iqp_stat_cards([
    ['Total Products', (string) $totalProducts, '#DCFCE7', '#16A34A', '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
    ['Synced via API', (string) $syncedCount, '#DBEAFE', '#2563EB', '<path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>'],
    ['Website Import', (string) (int) ($websiteImport['count'] ?? 0), '#FFEDD5', '#EA580C', '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],
    ['Connected Stores', (string) (($shopify ? 1 : 0) + ($woo ? 1 : 0)), '#F3E8FF', '#7C3AED', '<path d="M9 2v6M15 2v6"/><path d="M7 8h10v3a5 5 0 0 1-10 0Z"/><path d="M12 16v6"/>'],
], ['margin' => 'mb-6']);
?>

<!-- Tab navigation -->
<?php
$intgNav = [];
foreach ([
    ['website', 'Website Import', '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],
    ['shopify', 'Shopify' . ($shopify ? ' · Connected' : ''), '<path d="M15.5 2.1c-.3 0-1.4.4-2.8 1-1.3-1.5-3.1-2.4-4.4-2.4-.1 0-.1 0-.2 0-.4-.6-1-.9-1.5-.9-3.7 0-5.5 4.6-6 6.8-1.4.4-2.4.8-2.5.8-.8.2-.8.2-.9 1L.3 18.8l12.5 2.1 6.7-1.5L15.5 2.1z"/>'],
    ['woocommerce', 'WooCommerce' . ($woo ? ' · Connected' : ''), '<path d="M3 3h18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M8 21h8M12 17v4"/>'],
] as [$tid, $tlabel, $ticon]) {
    $intgNav[] = [$tid, $tlabel, $ticon, '?bot_id=' . $botId . '&tab=' . $tid];
}
iqp_tab_nav($activeTab, $intgNav, ['variant' => 'user', 'class' => 'mb-6 iqp-intg-tabs', 'mobile' => 'grid']);
?>

<!-- ===================== WEBSITE IMPORT TAB ===================== -->
<?php if ($activeTab === 'website'):
    $importUrl = trim((string)($websiteImport['source_url'] ?? ''));
    $importHost = $importUrl !== '' ? (parse_url($importUrl, PHP_URL_HOST) ?: $importUrl) : '';
    $importCount = (int)($websiteImport['count'] ?? 0);
?>
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
  <div class="iqp-intg-hero iqp-intg-hero--website">
    <div class="iqp-intg-hero__main">
      <div class="iqp-intg-hero__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
      </div>
      <div class="iqp-intg-hero__copy min-w-0">
        <h2 class="iqp-intg-hero__title">Import from Website URL</h2>
        <p class="iqp-intg-hero__desc">Paste your store URL — we fetch products automatically. No API keys.</p>
      </div>
    </div>
    <?php if ($importUrl !== ''): ?>
    <div class="iqp-intg-hero__meta">
      <span class="iqp-intg-hero__pill"><?= $importCount ?> synced</span>
      <a href="<?= sanitize($importUrl) ?>" target="_blank" rel="noopener" class="iqp-intg-hero__link" title="<?= sanitize($importUrl) ?>"><?= sanitize($importHost) ?></a>
      <?php if (!empty($websiteImport['synced_at'])): ?>
      <span class="iqp-intg-hero__time"><?= sanitize(date('M j, Y g:i A', strtotime((string)$websiteImport['synced_at']))) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="p-4 sm:p-6">
    <form id="website-import-form" method="POST" onsubmit="return false;">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
      <label class="block text-[14px] font-semibold text-slate-700 mb-2">Store Website URL</label>
      <div class="iqp-intg-import-row">
        <input type="url" name="website_url" required
          value="<?= sanitize($websiteImport['source_url']) ?>"
          placeholder="https://yourstore.com"
          class="iqp-intg-import-row__input border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
        <div class="iqp-intg-import-row__actions">
          <button type="button" data-website-preview
            class="iqp-guide-btn iqp-guide-btn--secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span class="iqp-guide-btn__text"><span class="iqp-guide-btn__label">Preview</span><span class="iqp-guide-btn__hint">Scan first</span></span>
          </button>
          <button type="button" data-website-import
            class="iqp-guide-btn iqp-guide-btn--primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="iqp-guide-btn__text"><span class="iqp-guide-btn__label">Import</span><span class="iqp-guide-btn__hint">Sync catalog</span></span>
          </button>
        </div>
      </div>
      <p id="website-import-status" class="text-[13px] text-slate-500 mt-2"></p>
      <div id="website-import-preview" class="grid sm:grid-cols-2 gap-3 mt-4 hidden"></div>
    </form>

    <!-- How it works -->
    <details class="iqp-intg-how mt-5 pt-5 border-t border-slate-100">
      <summary class="iqp-intg-how__summary">
        <span class="iqp-intg-how__summary-title">How it works</span>
        <svg class="iqp-intg-how__chevron lg:hidden" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
      </summary>
      <div class="iqp-intg-steps">
        <?php foreach ([
          ['1','Paste URL','Enter your Shopify, WooCommerce, or any product page URL','green'],
          ['2','Preview','We scan the page and show what we found before importing','blue'],
          ['3','Import','Products sync to Shop and AI learns them instantly','purple'],
        ] as [$step,$title,$desc,$tone]): ?>
        <div class="iqp-intg-step iqp-intg-step--<?= sanitize($tone) ?>">
          <span class="iqp-intg-step__num"><?= sanitize($step) ?></span>
          <div class="iqp-intg-step__body">
            <div class="iqp-intg-step__title"><?= sanitize($title) ?></div>
            <div class="iqp-intg-step__desc"><?= sanitize($desc) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </details>

    <p class="text-[13px] text-slate-400 mt-4">Works with Shopify, WooCommerce, and most product pages. Manual products in <a href="/client/catalog?bot_id=<?= $botId ?>" class="text-[#1FA855] font-medium">Shop catalog</a>.</p>
  </div>
</div>

<!-- ===================== SHOPIFY TAB ===================== -->
<?php elseif ($activeTab === 'shopify'): ?>
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
  <div class="p-6 border-b border-slate-100" style="background:linear-gradient(135deg,#f8fff0 0%,#edffd6 100%)">
    <div class="flex items-center gap-4">
      <div class="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0" style="background:#95BF47">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
      </div>
      <div>
        <h2 class="text-[18px] font-bold text-slate-800 mb-1">Shopify Integration</h2>
        <p class="text-[14px] text-slate-600">Sync your Shopify products directly via API.</p>
        <?php if ($shopify): ?>
        <div class="flex items-center gap-2 mt-2">
          <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
          <span class="text-[13px] text-emerald-700 font-semibold">Connected — <?= sanitize((string)($shopify['store_url']??'')) ?></span>
          <?php if (!empty($shopify['last_sync_at'])): ?>
          <span class="text-[12px] text-slate-400">· Last sync: <?= sanitize(date('M j, Y g:i A', strtotime($shopify['last_sync_at']))) ?></span>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <span class="inline-flex items-center gap-1.5 text-[13px] text-slate-500 mt-1"><span class="w-2 h-2 rounded-full bg-slate-300"></span>Not connected</span>
        <?php endif; ?>
      </div>
      <?php if ($shopify): ?>
      <div class="ml-auto flex gap-2">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="sync"/><input type="hidden" name="bot_id" value="<?= $botId ?>"/><input type="hidden" name="platform" value="shopify"/>
          <button type="submit" class="flex items-center gap-2 border border-slate-200 rounded-xl px-4 py-2.5 text-[13px] font-semibold text-slate-600 hover:bg-slate-50 transition-all">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>Sync Now
          </button>
        </form>
        <form method="POST" onsubmit="return confirm('Remove Shopify integration?')">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="disconnect"/><input type="hidden" name="integration_id" value="<?= (int)$shopify['id'] ?>"/><input type="hidden" name="bot_id" value="<?= $botId ?>"/>
          <button type="submit" class="flex items-center gap-2 border border-red-200 text-red-500 rounded-xl px-4 py-2.5 text-[13px] font-semibold hover:bg-red-50 transition-all">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>Disconnect
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="p-6">
    <form method="POST" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
      <input type="hidden" name="platform" value="shopify"/>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Store Domain</label>
          <input type="text" name="store_url" placeholder="myshop.myshopify.com"
            value="<?= $shopify ? sanitize((string)$shopify['store_url']) : '' ?>"
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
        </div>
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Admin API Access Token</label>
          <input type="password" name="access_token" placeholder="shpat_..." autocomplete="off"
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
          <?php if ($shopify): ?><p class="text-[12px] text-slate-400 mt-1">Leave blank to keep existing token.</p><?php endif; ?>
        </div>
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Webhook Secret <span class="text-slate-400 font-normal">(optional)</span></label>
          <input type="password" name="webhook_secret" placeholder="Shared secret for auto-sync" autocomplete="off"
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
        </div>
      </div>
      <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" name="sync_enabled" value="1" <?= !$shopify || !empty($shopify['sync_enabled']) ? 'checked' : '' ?> class="w-5 h-5 rounded accent-[#1FA855]"/>
        <span class="text-[14px] font-medium text-slate-700">Enable automatic product sync</span>
      </label>
      <button type="submit" class="flex items-center gap-2 bg-[#1FA855] text-white rounded-xl px-6 py-3 text-[14px] font-semibold hover:bg-[#18934a] transition-all" style="box-shadow:0 4px 14px rgba(31,168,85,.30)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
        Save Shopify Settings
      </button>
    </form>
    <?php if ($shopify): ?>
    <div class="mt-6 pt-5 border-t border-slate-100">
      <div class="text-[13px] font-semibold text-slate-600 mb-2">Webhook URL (optional auto-sync)</div>
      <code class="block text-[13px] break-all bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-600"><?= sanitize(shop_webhook_url($botId, 'shopify')) ?></code>
      <p class="text-[12px] text-slate-400 mt-2">Add this URL in Shopify → Settings → Notifications → Webhooks. Products trigger: products/create, products/update, products/delete.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===================== WOOCOMMERCE TAB ===================== -->
<?php elseif ($activeTab === 'woocommerce'): ?>
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
  <div class="p-6 border-b border-slate-100" style="background:linear-gradient(135deg,#faf5ff 0%,#f3e8ff 100%)">
    <div class="flex items-center gap-4">
      <div class="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0" style="background:#7F54B3">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M3 3h18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M8 21h8M12 17v4"/></svg>
      </div>
      <div>
        <h2 class="text-[18px] font-bold text-slate-800 mb-1">WooCommerce Integration</h2>
        <p class="text-[14px] text-slate-600">Sync products from your WordPress WooCommerce store.</p>
        <?php if ($woo): ?>
        <div class="flex items-center gap-2 mt-2">
          <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
          <span class="text-[13px] text-emerald-700 font-semibold">Connected — <?= sanitize((string)($woo['store_url']??'')) ?></span>
          <?php if (!empty($woo['last_sync_at'])): ?>
          <span class="text-[12px] text-slate-400">· Last sync: <?= sanitize(date('M j, Y g:i A', strtotime($woo['last_sync_at']))) ?></span>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <span class="inline-flex items-center gap-1.5 text-[13px] text-slate-500 mt-1"><span class="w-2 h-2 rounded-full bg-slate-300"></span>Not connected</span>
        <?php endif; ?>
      </div>
      <?php if ($woo): ?>
      <div class="ml-auto flex gap-2">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="sync"/><input type="hidden" name="bot_id" value="<?= $botId ?>"/><input type="hidden" name="platform" value="woocommerce"/>
          <button type="submit" class="flex items-center gap-2 border border-slate-200 rounded-xl px-4 py-2.5 text-[13px] font-semibold text-slate-600 hover:bg-slate-50 transition-all">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>Sync Now
          </button>
        </form>
        <form method="POST" onsubmit="return confirm('Remove WooCommerce integration?')">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="disconnect"/><input type="hidden" name="integration_id" value="<?= (int)$woo['id'] ?>"/><input type="hidden" name="bot_id" value="<?= $botId ?>"/>
          <button type="submit" class="flex items-center gap-2 border border-red-200 text-red-500 rounded-xl px-4 py-2.5 text-[13px] font-semibold hover:bg-red-50 transition-all">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>Disconnect
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="p-6">
    <form method="POST" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
      <input type="hidden" name="platform" value="woocommerce"/>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Site URL</label>
          <input type="text" name="store_url" placeholder="shop.example.com"
            value="<?= $woo ? sanitize((string)$woo['store_url']) : '' ?>"
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
        </div>
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Consumer Key</label>
          <input type="text" name="api_key" placeholder="ck_..."
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
          <?php if ($woo): ?><p class="text-[12px] text-slate-400 mt-1">Leave blank to keep existing key.</p><?php endif; ?>
        </div>
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Consumer Secret</label>
          <input type="password" name="api_secret" placeholder="cs_..." autocomplete="off"
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
          <?php if ($woo): ?><p class="text-[12px] text-slate-400 mt-1">Leave blank to keep existing secret.</p><?php endif; ?>
        </div>
        <div>
          <label class="block text-[14px] font-semibold text-slate-700 mb-2">Webhook Secret <span class="text-slate-400 font-normal">(optional)</span></label>
          <input type="password" name="webhook_secret" placeholder="Shared secret for auto-sync" autocomplete="off"
            class="w-full border border-slate-200 rounded-xl px-4 py-3 text-[14px] focus:outline-none focus:border-[#1FA855] focus:ring-2 focus:ring-emerald-100"/>
        </div>
      </div>
      <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" name="sync_enabled" value="1" <?= !$woo || !empty($woo['sync_enabled']) ? 'checked' : '' ?> class="w-5 h-5 rounded accent-[#1FA855]"/>
        <span class="text-[14px] font-medium text-slate-700">Enable automatic product sync</span>
      </label>
      <button type="submit" class="flex items-center gap-2 bg-[#1FA855] text-white rounded-xl px-6 py-3 text-[14px] font-semibold hover:bg-[#18934a] transition-all" style="box-shadow:0 4px 14px rgba(31,168,85,.30)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
        Save WooCommerce Settings
      </button>
    </form>
    <?php if ($woo): ?>
    <div class="mt-6 pt-5 border-t border-slate-100">
      <div class="text-[13px] font-semibold text-slate-600 mb-2">Webhook URL (optional auto-sync)</div>
      <code class="block text-[13px] break-all bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-600"><?= sanitize(shop_webhook_url($botId, 'woocommerce')) ?></code>
      <p class="text-[12px] text-slate-400 mt-2">Add this URL in WooCommerce → Settings → Advanced → Webhooks. Topic: Product Updated, Product Created.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; // end $bots check ?>
<script src="/assets/js/website-import.js?v=<?= @filemtime(dirname(__DIR__).'/assets/js/website-import.js') ?: time() ?>"></script>
<?php iqp_user_end();
