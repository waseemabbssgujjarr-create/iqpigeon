<?php
/** @var array $user */
/** @var array $bots */
/** @var int $botId */
/** @var string $csrf */
/** @var string $intgSubTab */
/** @var array|null $shopify */
/** @var array|null $woo */
/** @var int $syncedCount */
/** @var array $websiteImport */
/** @var int $totalProducts */
/** @var string $settingsBaseUrl */

$settingsBaseUrl = $settingsBaseUrl ?? '/client/settings?tab=integrations';
?>
<div class="bg-white rounded-xl border border-slate-200 p-6 mb-5">
  <div class="text-[15px] font-bold text-slate-800 mb-0.5">Integrations</div>
  <div class="text-[12.5px] text-slate-400 mb-4">Connect your store and sync products. Website import also lives under <a href="/client/catalog" class="text-[#1FA855] font-medium hover:underline">Shop</a>.</div>
  <?php if ($bots !== []): ?>
  <form method="get" class="flex items-center gap-2 mb-4">
    <input type="hidden" name="tab" value="integrations"/>
    <input type="hidden" name="intg" value="<?= sanitize($intgSubTab) ?>"/>
    <label class="text-[11.5px] text-slate-500 font-medium">Assistant</label>
    <select name="bot_id" onchange="this.form.submit()"
      class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-600 bg-white focus:outline-none focus:border-[#1FA855]">
      <?php foreach ($bots as $b): ?>
      <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id']===$botId?' selected':'' ?>><?= sanitize($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<?php if ($bots === []): ?>
<div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
  <p class="text-[14px] text-slate-500 mb-4">Create your assistant in Training first, then connect integrations here.</p>
  <a href="/client/training" class="inline-block bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Open Training</a>
</div>
<?php else: ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
  <?php
  foreach ([
    ['Total Products', (string)$totalProducts],
    ['Synced via API', (string)$syncedCount],
    ['Website Import', (string)(int)($websiteImport['count']??0)],
    ['Connected Stores', (string)(($shopify?1:0)+($woo?1:0))],
  ] as [$slabel,$sval]): ?>
  <div class="bg-white rounded-xl border border-slate-200 p-4">
    <div class="text-[11.5px] text-slate-500 font-medium mb-1"><?= sanitize($slabel) ?></div>
    <div class="text-[22px] font-bold text-slate-800"><?= sanitize($sval) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$intgNav = [];
foreach ([
    ['website', 'Website Import'],
    ['shopify', 'Shopify' . ($shopify ? ' · Connected' : '')],
    ['woocommerce', 'WooCommerce' . ($woo ? ' · Connected' : '')],
] as [$tid, $tlabel]) {
    $intgNav[] = [$tid, $tlabel, '', $settingsBaseUrl . '&bot_id=' . $botId . '&intg=' . $tid];
}
iqp_tab_nav($intgSubTab, $intgNav, ['variant' => 'user', 'class' => 'mb-5']);
?>

<?php if ($intgSubTab === 'website'): ?>
<div class="bg-white rounded-xl border border-slate-200 p-6">
  <h2 class="text-[15px] font-bold text-slate-800 mb-1">Import from Website URL</h2>
  <p class="text-[12.5px] text-slate-500 mb-4">Paste your store URL — no API keys required.</p>
  <?php if ($websiteImport['source_url'] !== ''): ?>
  <p class="text-[12px] text-slate-500 mb-3"><?= (int)($websiteImport['count']??0) ?> products from <?= sanitize($websiteImport['source_url']) ?></p>
  <?php endif; ?>
  <form id="website-import-form" method="POST" onsubmit="return false;">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
    <div class="flex flex-col sm:flex-row gap-2">
      <input type="url" name="website_url" required value="<?= sanitize($websiteImport['source_url']) ?>" placeholder="https://yourstore.com"
        class="flex-1 border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
      <button type="button" data-website-preview class="border border-slate-200 rounded-lg px-4 py-2.5 text-[12.5px] font-semibold text-slate-600 hover:bg-slate-50"><span data-btn-label>Preview</span></button>
      <button type="button" data-website-import class="bg-[#1FA855] text-white rounded-lg px-4 py-2.5 text-[12.5px] font-semibold hover:bg-[#18934a]"><span data-btn-label>Import Products</span></button>
    </div>
    <p id="website-import-status" class="text-[12px] text-slate-500 mt-2"></p>
    <div id="website-import-preview" class="grid sm:grid-cols-2 gap-3 mt-4 hidden"></div>
  </form>
  <p class="text-[12px] text-slate-400 mt-4">For full catalog management, use <a href="/client/catalog?bot_id=<?= $botId ?>" class="text-[#1FA855] font-medium">Shop</a>.</p>
</div>

<?php elseif ($intgSubTab === 'shopify'): ?>
<div class="bg-white rounded-xl border border-slate-200 p-6">
  <h2 class="text-[15px] font-bold text-slate-800 mb-1">Shopify</h2>
  <p class="text-[12.5px] text-slate-500 mb-4">Sync products via Admin API.</p>
  <form method="POST" action="/client/settings?tab=integrations&bot_id=<?= $botId ?>&intg=shopify" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_integration"/>
    <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
    <input type="hidden" name="platform" value="shopify"/>
    <input type="text" name="store_url" placeholder="myshop.myshopify.com" value="<?= $shopify ? sanitize((string)$shopify['store_url']) : '' ?>"
      class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
    <input type="password" name="access_token" placeholder="shpat_..." autocomplete="off"
      class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="sync_enabled" value="1" <?= !$shopify || !empty($shopify['sync_enabled']) ? 'checked' : '' ?> class="accent-[#1FA855]"/> Enable automatic sync</label>
    <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Save Shopify Settings</button>
  </form>
  <?php if ($shopify): ?>
  <form method="POST" action="/client/settings?tab=integrations&bot_id=<?= $botId ?>&intg=shopify" class="mt-3 inline-block mr-2">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="sync_integration"/><input type="hidden" name="bot_id" value="<?= $botId ?>"/><input type="hidden" name="platform" value="shopify"/>
    <button type="submit" class="border border-slate-200 rounded-lg px-4 py-2 text-[12px] font-medium text-slate-600 hover:bg-slate-50">Sync now</button>
  </form>
  <?php endif; ?>
</div>

<?php elseif ($intgSubTab === 'woocommerce'): ?>
<div class="bg-white rounded-xl border border-slate-200 p-6">
  <h2 class="text-[15px] font-bold text-slate-800 mb-1">WooCommerce</h2>
  <p class="text-[12.5px] text-slate-500 mb-4">Sync products from your WordPress store.</p>
  <form method="POST" action="/client/settings?tab=integrations&bot_id=<?= $botId ?>&intg=woocommerce" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_integration"/>
    <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
    <input type="hidden" name="platform" value="woocommerce"/>
    <input type="text" name="store_url" placeholder="shop.example.com" value="<?= $woo ? sanitize((string)$woo['store_url']) : '' ?>"
      class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
    <input type="text" name="api_key" placeholder="ck_..." class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
    <input type="password" name="api_secret" placeholder="cs_..." autocomplete="off" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="sync_enabled" value="1" <?= !$woo || !empty($woo['sync_enabled']) ? 'checked' : '' ?> class="accent-[#1FA855]"/> Enable automatic sync</label>
    <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Save WooCommerce Settings</button>
  </form>
  <?php if ($woo): ?>
  <form method="POST" action="/client/settings?tab=integrations&bot_id=<?= $botId ?>&intg=woocommerce" class="mt-3 inline-block">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/><input type="hidden" name="action" value="sync_integration"/><input type="hidden" name="bot_id" value="<?= $botId ?>"/><input type="hidden" name="platform" value="woocommerce"/>
    <button type="submit" class="border border-slate-200 rounded-lg px-4 py-2 text-[12px] font-medium text-slate-600 hover:bg-slate-50">Sync now</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<script src="/assets/js/website-import.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/website-import.js') ?: time() ?>"></script>
<?php endif; ?>
