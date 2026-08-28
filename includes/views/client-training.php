<?php
/** @var array $user */
/** @var array $bot */
/** @var int $botId */
/** @var string $repName */
/** @var string $repNamePreview */
/** @var string $bizName */
/** @var string $industry */
/** @var string $knowledge */
/** @var string $websiteUrl */
/** @var string $updated */
/** @var string $industryKey */
/** @var array $trainingMeta */
/** @var array $industryTemplates */
/** @var array|null $wa */
/** @var array $products */
/** @var array $prodLimit */
/** @var string $message */
/** @var string $error */
/** @var array $qualifyFlow */
/** @var array $qualifyTypes */
/** @var array $qualifyModes */
/** @var array $qualifyGoals */
/** @var string $csrf */

$tab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'business')) ?: 'business';
$tabs = [
    ['business',     'Business',        '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>'],
    ['knowledge',    'Knowledge',       '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'],
    ['sales',        'Sales & Leads',   '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'],
    ['conversation', 'Conversation',    '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
    ['test',         'Test & Publish',  '<path d="M22 2 11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>'],
];

$industries = [];
if (is_array($industryTemplates ?? null)) {
    foreach ($industryTemplates as $tkey => $tpl) {
        $industries[$tkey] = (string) ($tpl['label'] ?? $tkey);
    }
}
if ($industries === []) {
    $industries = [
        'restaurant' => 'Restaurant / Food',
        'ecommerce'  => 'E-commerce / Retail',
        'education'  => 'Education / Coaching',
    ];
}
$selectedIndustryKey = $industryKey;
if ($selectedIndustryKey === '' && $industry !== '') {
    $selectedIndustryKey = function_exists('industry_key_from_posted')
        ? industry_key_from_posted($industry)
        : '';
}

$currency = function_exists('default_currency') ? default_currency() : 'PKR';
$userCurrency = strtoupper(trim((string) ($user['pref_currency'] ?? $currency)));
if (in_array($userCurrency, ['PKR', 'USD', 'EUR', 'GBP'], true)) {
    $currency = $userCurrency;
}
$userTimezone = trim((string) ($user['pref_timezone'] ?? 'Asia/Karachi'));
$businessEmail = trim((string) ($user['business_email'] ?? ''));
if ($businessEmail === '') {
    $businessEmail = trim((string) ($user['email'] ?? ''));
}
$conversationPrefs = is_array($conversationPrefs ?? null) ? $conversationPrefs : [];
$trainingReady = is_array($trainingReady ?? null) ? $trainingReady : ['items' => [], 'ready' => false];

$updates = 0;
try { $updates = (int)(db_fetch('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=? AND is_read=0','i',[(int)$user['id']])['cnt']??0); } catch (Throwable $e) {}

iqp_user_begin($user, 'train', ['title' => 'Train', 'updates' => $updates]);
if ($message !== '') iqp_flash($message);
if ($error   !== '') iqp_flash($error, 'err');
if (isset($_GET['saved']) && (string) $_GET['saved'] === '1' && $tab === 'business') {
    iqp_flash('Business information saved.');
}
if (isset($_GET['saved']) && (string) $_GET['saved'] === 'name' && $tab === 'business') {
    iqp_flash('Assistant name saved.');
}
if (isset($_GET['applied']) && (string) $_GET['applied'] === '1') {
    iqp_flash('Recommended configuration applied. Your existing knowledge, products, and lead questions were kept.');
}
if ($tab === 'sales' && isset($_GET['saved']) && (string) $_GET['saved'] === '1') {
    iqp_flash('Sales & Leads saved. Live chats now use your questions and conversion goal.');
}
if ($tab === 'sales' && isset($_GET['reset']) && (string) $_GET['reset'] === '1') {
    iqp_flash('Lead questions reset to the industry starter. Save again after any edits to keep them as your own.');
}
if ($tab === 'business' && isset($_GET['saved']) && (string) $_GET['saved'] === 'hours') {
    iqp_flash('Business hours saved.');
}
if ($tab === 'knowledge' && isset($_GET['saved'])) {
    $sk = (string) $_GET['saved'];
    if ($sk === '1') iqp_flash('Knowledge saved.');
    if ($sk === 'menu') iqp_flash('Products &amp; menu cards saved.');
    if ($sk === 'hints') iqp_flash('Customer phrases saved.');
    if (!empty($_GET['placeholders'])) {
        iqp_flash('Saved, but unresolved placeholders like [skills] or {{variable}} were found. Customers will not see those brackets — fill them with real business details.');
    }
}
if ($tab === 'conversation' && isset($_GET['saved'])) {
    iqp_flash('How your assistant talks was saved. Changes apply on the next customer message.');
}
?>

<!-- Page header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
  <div>
    <h1 class="text-[20px] lg:text-[22px] font-bold text-slate-800">Set up your assistant</h1>
    <p class="text-[13px] text-slate-500 mt-0.5">Tell IQPigeon about your business, then save. Changes apply on the next customer message — there is no separate training job.</p>
  </div>
  <div class="flex items-center gap-3 flex-wrap justify-end">
    <?php if ($updated !== ''): ?>
    <div class="flex items-center gap-1.5 text-[12px] text-slate-400">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Last updated: <?= sanitize(date('M j, Y', strtotime($updated))) ?>
    </div>
    <?php endif; ?>
    <a href="/client/training?tab=test" id="iqpTrainBtn"
      class="flex items-center gap-2 bg-[#1FA855] text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Save &amp; Publish
    </a>
  </div>
</div>

<!-- Tab navigation — mobile card grid, desktop compact bar -->
<?php
$navTabs = [];
foreach ($tabs as [$tid, $tlabel, $ticon]) {
    $navTabs[] = [$tid, $tlabel, $ticon, '/client/training?tab=' . $tid];
}
iqp_tab_nav($tab, $navTabs, ['variant' => 'user', 'class' => 'mb-5', 'select_label' => 'Training section']);
?>

<!-- Two-column layout: left content + right rail (Test Assistant) -->
<div class="iqp-train-layout<?= $tab === 'test' ? ' iqp-train-layout--publish' : '' ?>">

<!-- ==================== LEFT COLUMN ==================== -->
<div class="iqp-train-main min-w-0">

<?php if ($tab === 'business'): ?>
<!-- ---- Business Info Tab ---- -->
<div class="bg-white rounded-xl border border-slate-200 p-6">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-5">
    <div class="min-w-0 flex-1">
      <div class="text-[15px] font-bold text-slate-800">Business Information</div>
      <div class="text-[12.5px] text-slate-400 mt-0.5">Provide key details about your business.</div>
    </div>
    <button form="bizInfoForm" type="submit"
      class="iqp-train-save-btn bg-[#1FA855] text-white rounded-lg px-4 py-2 text-[12.5px] font-semibold hover:bg-[#18934a] shrink-0 self-start sm:self-auto">
      Save Changes
    </button>
  </div>
  <form id="bizInfoForm" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_business_info"/>
    <input type="hidden" name="rep_name" id="bizRepNameHidden" value="<?= sanitize($repName) ?>"/>
    <div class="iqp-biz-info-grid grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business / Organization Name</label>
        <input name="company_name" value="<?= sanitize($bizName) ?>" placeholder="e.g. Waqar's Kitchen"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Industry</label>
        <div class="relative">
          <select name="industry_key" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855] appearance-none">
            <option value="">Select industry</option>
            <?php foreach ($industries as $indKey => $indLabel): ?>
            <option value="<?= sanitize((string) $indKey) ?>"<?= $selectedIndustryKey === (string) $indKey ? ' selected' : '' ?>><?= sanitize((string) $indLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <svg class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
        </div>
        <p class="text-[10.5px] text-slate-400 mt-1.5">Recommended configuration is applied automatically. Changing type never deletes your knowledge, products, or lead questions.</p>
        <?php if ($selectedIndustryKey !== ''): ?>
        <p class="text-[11px] text-emerald-700 mt-1.5 font-medium">Recommended configuration applied<?= isset($industries[$selectedIndustryKey]) ? ' · ' . sanitize((string) $industries[$selectedIndustryKey]) : '' ?>.</p>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business Email</label>
        <input name="business_email" value="<?= sanitize($businessEmail) ?>" placeholder="info@yourcompany.com"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business Phone</label>
        <div class="flex gap-2">
          <div class="flex items-center gap-1.5 border border-slate-200 rounded-lg px-2.5 bg-white shrink-0 cursor-pointer hover:bg-slate-50">
            <span class="text-[14px]">🇵🇰</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
          </div>
          <input name="business_phone" value="<?= sanitize((string)($businessPhone ?? '')) ?>" placeholder="+92 300 1234567"
            class="flex-1 border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
        </div>
        <?php if (!empty($businessPhoneFromWhatsApp)): ?>
        <p class="text-[10.5px] text-[#1FA855] mt-1.5">Filled from your connected WhatsApp number. Edit only if customers should see a different contact number.</p>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business Website</label>
        <input name="website_url" value="<?= sanitize($websiteUrl) ?>" placeholder="https://yourwebsite.com"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
      </div>
      <div class="iqp-biz-logo-field">
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business Logo</label>
        <div class="iqp-biz-logo-drop border border-dashed border-slate-200 rounded-lg px-3 py-2.5 flex items-center gap-3 cursor-pointer hover:border-[#1FA855] hover:bg-emerald-50/20 transition-colors"
          onclick="document.getElementById('bizLogoInput').click()">
          <?php $curLogo = (string) ($user['avatar_url'] ?? ''); ?>
          <?php if ($curLogo !== ''): ?>
          <img id="bizLogoPreview" src="<?= sanitize($curLogo) ?>" class="iqp-biz-logo-preview" alt="Logo"/>
          <?php else: ?>
          <svg id="bizLogoIcon" class="iqp-biz-logo-preview text-slate-300 shrink-0" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <?php endif; ?>
          <div class="min-w-0 flex-1">
            <div id="bizLogoFilename" class="text-[12px] text-slate-600 truncate">JPG, PNG, SVG · Max 2MB</div>
            <button type="button" onclick="event.stopPropagation();document.getElementById('bizLogoInput').click()"
              class="mt-1 text-[11.5px] font-semibold text-[#1FA855] hover:underline">Change</button>
          </div>
          <input id="bizLogoInput" name="logo_file" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" onchange="iqpPreviewLogo(this)"/>
        </div>
        <div class="text-[10.5px] text-slate-400 mt-1">Uploads when you click "Save Changes" above.</div>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business Address</label>
        <textarea name="business_address" rows="2" placeholder="123 Main St, City, Country"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-none"><?= sanitize((string)($user['address']??'')) ?></textarea>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Time Zone</label>
        <div class="relative">
          <select name="timezone" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855] appearance-none">
            <?php
            $tzChoices = [
                'Asia/Karachi'     => '(GMT+05:00) Islamabad, Karachi',
                'UTC'              => '(GMT+00:00) UTC',
                'Asia/Kolkata'     => '(GMT+05:30) Mumbai, New Delhi',
                'America/New_York' => '(GMT-05:00) Eastern Time',
                'Europe/London'    => '(GMT+00:00) London',
                'Asia/Dubai'       => '(GMT+04:00) Dubai',
            ];
            foreach ($tzChoices as $tzVal => $tzLabel):
            ?>
            <option value="<?= sanitize($tzVal) ?>"<?= $userTimezone === $tzVal ? ' selected' : '' ?>><?= sanitize($tzLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <svg class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
        </div>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business Description <span class="text-slate-300 font-normal" id="bizDescCount"><?= strlen((string)($user['bio']??'')) ?>/500</span></label>
        <textarea name="business_description" maxlength="500" rows="3"
          oninput="document.getElementById('bizDescCount').textContent=this.value.length+'/500'"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-none"
          placeholder="We are a fast casual restaurant offering delicious burgers, fries, beverages and more."><?= sanitize((string)($user['bio']??'')) ?></textarea>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Currency</label>
        <div class="relative">
          <select name="currency" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855] appearance-none">
            <option value="PKR"<?= $currency==='PKR'?' selected':'' ?>>PKR (Rs.)</option>
            <option value="USD"<?= $currency==='USD'?' selected':'' ?>>USD ($)</option>
            <option value="EUR"<?= $currency==='EUR'?' selected':'' ?>>EUR (€)</option>
            <option value="GBP"<?= $currency==='GBP'?' selected':'' ?>>GBP (£)</option>
          </select>
          <svg class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
        </div>
      </div>
      </div>
    </div>
  </form>
</div>

<!-- Connect Your Channels -->
<div class="bg-white rounded-xl border border-slate-200 p-5 mt-4">
  <div class="text-[14px] font-bold text-slate-800 mb-0.5">Connect Your Channels</div>
  <div class="text-[12px] text-slate-400 mb-4">Connect channels where your assistant will be available.</div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <!-- WhatsApp -->
    <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-[#25D366] flex items-center justify-center shrink-0">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
        </div>
        <div>
          <div class="text-[13px] font-semibold text-slate-800">WhatsApp</div>
          <div class="text-[11px] <?= $wa?'text-emerald-600':'text-slate-400' ?>"><?= $wa?'Your assistant is active on WhatsApp':'Not connected' ?></div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <?php if ($wa): ?>
        <span class="bg-emerald-50 text-emerald-600 text-[10.5px] font-bold px-2 py-0.5 rounded-full">Connected</span>
        <a href="/client/whatsapp-settings" class="text-[11.5px] text-[#1FA855] font-semibold hover:underline">Manage</a>
        <?php else: ?>
        <span class="bg-slate-100 text-slate-500 text-[10.5px] font-bold px-2 py-0.5 rounded-full">Not Connected</span>
        <a href="/client/connect-whatsapp" class="text-[11.5px] text-[#1FA855] font-semibold hover:underline">Connect</a>
        <?php endif; ?>
      </div>
    </div>
    <!-- Website Widget -->
    <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center shrink-0">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div>
          <div class="text-[13px] font-semibold text-slate-800">Website / Widget</div>
          <div class="text-[11px] text-orange-500">Connect to enable on your website.</div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <span class="bg-orange-50 text-orange-500 text-[10.5px] font-bold px-2 py-0.5 rounded-full">Not Connected</span>
        <a href="/client/integrations" class="text-[11.5px] text-[#1FA855] font-semibold hover:underline">Connect</a>
      </div>
    </div>
  </div>
  <div class="mt-4 flex items-center gap-2 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2.5 text-[12px] text-emerald-700">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
    Keep your business information updated to help your AI Assistant provide accurate and helpful responses.
  </div>
</div>
<?php endif; // business tab ?>

<?php if ($tab === 'knowledge'): ?>
<!-- ---- Knowledge Tab (single source — what your AI learns from) ---- -->
<div class="bg-white rounded-xl border border-slate-200 p-5 max-w-5xl">
  <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
    <div>
      <div class="text-[15px] font-bold text-slate-800">About your business</div>
      <div class="text-[12px] text-slate-400 mt-0.5">Who you are, who you serve, what makes you different, locations, policies, and important facts. Write in plain language — not technical prompts.</div>
    </div>
    <?php if ($updated !== ''): ?>
    <div class="text-[11px] text-slate-400 shrink-0">Last saved <?= sanitize((string) $updated) ?></div>
    <?php endif; ?>
  </div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_knowledge"/>
    <div class="border border-slate-200 rounded-lg overflow-hidden">
      <div class="flex flex-wrap gap-1 p-2 border-b border-slate-100 bg-slate-50">
        <button type="button" onclick="iqpWrapText('knowledgeText','**')" title="Bold" class="w-7 h-7 rounded border border-slate-200 bg-white text-[12px] font-bold text-slate-600 hover:bg-slate-100">B</button>
        <button type="button" onclick="iqpWrapText('knowledgeText','*')" title="Italic" class="w-7 h-7 rounded border border-slate-200 bg-white text-[12px] italic text-slate-600 hover:bg-slate-100">I</button>
        <button type="button" onclick="iqpWrapText('knowledgeText','__')" title="Underline" class="w-7 h-7 rounded border border-slate-200 bg-white text-[12px] underline text-slate-600 hover:bg-slate-100">U</button>
        <div class="w-px bg-slate-200 mx-1 self-stretch"></div>
        <button type="button" onclick="iqpPrefixLines('knowledgeText','• ')" title="Bullet list" class="w-7 h-7 rounded border border-slate-200 bg-white text-[11px] text-slate-600 hover:bg-slate-100">•≡</button>
        <button type="button" onclick="iqpNumberLines('knowledgeText')" title="Numbered list" class="w-7 h-7 rounded border border-slate-200 bg-white text-[11px] text-slate-600 hover:bg-slate-100">1≡</button>
        <button type="button" onclick="iqpInsertLink('knowledgeText')" title="Insert link" class="w-7 h-7 rounded border border-slate-200 bg-white text-[11px] text-slate-600 hover:bg-slate-100">🔗</button>
        <button type="button" onclick="iqpClearFormatting('knowledgeText')" class="ml-auto border border-slate-200 rounded-lg px-2.5 py-1 text-[11px] text-slate-500 hover:bg-slate-100">Clear Formatting</button>
      </div>
      <textarea id="knowledgeText" name="knowledge" maxlength="15000"
        oninput="document.getElementById('knowledgeCharCount').textContent=this.value.length.toLocaleString()"
        class="w-full min-h-[min(70vh,520px)] px-4 py-3 text-[13px] text-slate-700 outline-none resize-y leading-relaxed"
        placeholder="About your business, menu highlights, policies, FAQs, delivery areas…"><?= sanitize($knowledge) ?></textarea>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
      <div class="text-[11px] text-slate-400">Characters: <span id="knowledgeCharCount"><?= number_format(strlen($knowledge)) ?></span> / 15,000</div>
      <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2 text-[12.5px] font-semibold hover:bg-[#18934a]">Save Knowledge</button>
    </div>
    <p class="text-[11px] text-slate-400 mt-3">Products and files below are also trusted knowledge. Lead questions live in <a href="/client/training?tab=sales" class="text-[#1FA855] font-medium">Sales &amp; Leads</a>. Hours live in <a href="/client/training?tab=business" class="text-[#1FA855] font-medium">Business</a>.</p>
  </form>
</div>
<div class="bg-white rounded-xl border border-slate-200 p-5 mt-4">
  <div class="text-[15px] font-bold text-slate-800">Website</div>
  <div class="text-[12px] text-slate-400 mt-0.5 mb-3">Scan your site from Shop — IQPigeon already imports products from there. This is not a second crawler.</div>
  <a href="/client/catalog?bot_id=<?= (int)$botId ?>" class="inline-flex items-center gap-1.5 border border-slate-200 rounded-lg px-3 py-2 text-[12.5px] font-medium text-slate-600 hover:bg-slate-50">Scan website in Shop</a>
</div>
<?php endif; // knowledge tab ?>

<?php if ($tab === 'knowledge'): ?>
<!-- ---- Menu / Catalogue Items Tab ---- -->
<?php
$menuCards = $menuCards ?? [];
$productsByCategory = $productsByCategory ?? [];
$productMap = $productMap ?? [];
?>
<div class="bg-white rounded-xl border border-slate-200 p-5 mt-4">
  <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
    <div>
      <div class="text-[15px] font-bold text-slate-800">Products &amp; services</div>
      <div class="text-[12px] text-slate-400 mt-0.5">Items from Shop are the source of truth. You can also add products there if you do not use a WhatsApp catalog.</div>
    </div>
    <div class="flex flex-wrap gap-2 shrink-0">
      <a href="/client/catalog?bot_id=<?= (int)$botId ?>" class="flex items-center gap-1.5 border border-slate-200 rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600 hover:bg-slate-50">Manage in Shop</a>
      <?php if ($products !== []): ?>
      <form method="POST" class="inline">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="auto_menu_cards"/>
        <button type="submit" class="border border-[#1FA855] text-[#1FA855] rounded-lg px-3 py-2 text-[12px] font-semibold hover:bg-emerald-50">Auto-fill from Shop</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($products === []): ?>
  <div class="border border-dashed border-slate-200 rounded-xl p-10 text-center">
    <p class="text-[13px] text-slate-500 mb-3">No products in Shop yet.</p>
    <a href="/client/catalog?bot_id=<?= (int)$botId ?>" class="inline-flex items-center gap-1.5 bg-[#1FA855] text-white rounded-lg px-4 py-2 text-[12.5px] font-semibold hover:bg-[#18934a]">Import or add products</a>
  </div>
  <?php else: ?>
  <form method="POST" id="menuCardsForm">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_menu_cards"/>
    <div id="menuCardsList" class="space-y-4">
      <?php
      $cardIndex = 0;
      $cardsToRender = $menuCards !== [] ? $menuCards : training_auto_menu_cards($productsByCategory);
      foreach ($cardsToRender as $card):
          $cat = (string) ($card['category'] ?? 'General');
          $catProducts = $productsByCategory[$cat] ?? [];
          if ($catProducts === []) {
              continue;
          }
          $selected = array_map('intval', (array) ($card['product_ids'] ?? []));
      ?>
      <div class="menu-card-block border border-slate-200 rounded-xl p-4 bg-slate-50/50" data-category="<?= sanitize($cat) ?>">
        <div class="flex flex-wrap items-center gap-3 mb-3">
          <input type="hidden" name="menu_cards[<?= $cardIndex ?>][id]" value="<?= sanitize((string)($card['id'] ?? '')) ?>"/>
          <input type="hidden" name="menu_cards[<?= $cardIndex ?>][category]" value="<?= sanitize($cat) ?>"/>
          <div class="flex-1 min-w-[160px]">
            <label class="block text-[10px] uppercase tracking-wide text-slate-400 mb-1">Card title</label>
            <input name="menu_cards[<?= $cardIndex ?>][title]" value="<?= sanitize((string)($card['title'] ?? $cat)) ?>"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[13px] font-semibold text-slate-800 bg-white"/>
          </div>
          <span class="text-[11px] text-slate-400 px-2 py-1 bg-white border border-slate-200 rounded-lg"><?= sanitize($cat) ?></span>
          <button type="button" onclick="this.closest('.menu-card-block').remove()" class="text-[11px] text-red-500 hover:underline ml-auto">Remove card</button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
          <?php foreach ($catProducts as $p):
              $pid = (int) $p['id'];
              $checked = in_array($pid, $selected, true);
              $pPrice = catalog_format_price((float)($p['price']??0), (string)($p['currency']??$currency));
              $inactive = empty($p['is_active']);
          ?>
          <label class="flex items-start gap-2 p-2.5 rounded-lg border <?= $checked ? 'border-[#1FA855] bg-emerald-50/60' : 'border-slate-200 bg-white' ?> cursor-pointer hover:border-[#1FA855]/50">
            <input type="checkbox" name="menu_cards[<?= $cardIndex ?>][product_ids][]" value="<?= $pid ?>" <?= $checked ? 'checked' : '' ?> class="mt-0.5"/>
            <span class="min-w-0 flex-1">
              <span class="block text-[12.5px] font-medium text-slate-800 truncate"><?= sanitize((string)$p['name']) ?></span>
              <span class="block text-[11px] text-slate-500"><?= sanitize($pPrice) ?><?= $inactive ? ' · inactive' : '' ?></span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php $cardIndex++; endforeach; ?>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-4">
      <select id="addMenuCardCategory" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] text-slate-700">
        <?php foreach ($productsByCategory as $catName => $_): ?>
        <option value="<?= sanitize($catName) ?>"><?= sanitize($catName) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" onclick="iqpAddMenuCard()" class="border border-slate-200 rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600 hover:bg-slate-50">+ Add menu card</button>
      <button type="submit" class="ml-auto bg-[#1FA855] text-white rounded-lg px-5 py-2 text-[12.5px] font-semibold hover:bg-[#18934a]">Save Menu Cards</button>
    </div>
  </form>
  <template id="menuCardProductTemplate">
    <?php foreach ($productsByCategory as $catName => $catItems): ?>
    <div class="menu-card-products" data-category="<?= sanitize($catName) ?>" hidden>
      <?php foreach ($catItems as $p):
          $pPrice = catalog_format_price((float)($p['price']??0), (string)($p['currency']??$currency));
      ?>
      <label class="flex items-start gap-2 p-2.5 rounded-lg border border-slate-200 bg-white cursor-pointer hover:border-[#1FA855]/50 mb-2">
        <input type="checkbox" class="menu-card-product-cb mt-0.5" value="<?= (int)$p['id'] ?>"/>
        <span class="min-w-0 flex-1">
          <span class="block text-[12.5px] font-medium text-slate-800 truncate"><?= sanitize((string)$p['name']) ?></span>
          <span class="block text-[11px] text-slate-500"><?= sanitize($pPrice) ?></span>
        </span>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </template>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'industry'): ?>
<!-- ---- Industry Tab ---- -->
<?php $currentTpl = $industryKey !== '' ? ($industryTemplates[$industryKey] ?? null) : null; ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Left: Industry Template -->
  <div class="bg-white rounded-xl border border-slate-200 p-5">
    <div class="text-[15px] font-bold text-slate-800 mb-0.5">Industry Template</div>
    <div class="text-[12px] text-slate-400 mb-4">Pick the template that matches your business, then apply it to pre-fill Knowledge. Qualifying questions on that template are a global starter — customize them for this bot only under <a href="/client/training?tab=qualify" class="text-[#1FA855] font-medium">Qualify</a>.</div>

    <form method="POST" class="mb-4">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="apply_industry"/>
      <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Choose Industry Template</label>
      <div class="flex gap-2">
        <select name="industry_key" class="flex-1 border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]">
          <?php foreach ($industryTemplates as $tkey => $tpl): ?>
          <option value="<?= sanitize($tkey) ?>"<?= $industryKey === $tkey ? ' selected' : '' ?>><?= sanitize($tpl['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-4 py-2.5 text-[12.5px] font-semibold hover:bg-[#18934a] whitespace-nowrap">Apply Template</button>
      </div>
      <label class="flex items-center gap-2 mt-2 text-[11.5px] text-slate-500">
        <input type="checkbox" name="overwrite_existing" value="1" class="w-3.5 h-3.5 accent-[#1FA855]"/>
        Overwrite my existing knowledge text with the template (otherwise it only fills empty fields)
      </label>
      <?php if (!empty($qualifyFlow['custom'])): ?>
      <label class="flex items-center gap-2 mt-2 text-[11.5px] text-slate-500">
        <input type="checkbox" name="overwrite_qualification" value="1" class="w-3.5 h-3.5 accent-[#1FA855]"/>
        Also replace my custom Qualify flow with this template’s questions (leave off to keep yours)
      </label>
      <?php endif; ?>
    </form>

    <?php if ($currentTpl): ?>
    <div class="border border-slate-200 rounded-lg overflow-hidden">
      <div class="px-4 py-2.5 border-b border-slate-100 bg-slate-50 text-[12px] font-semibold text-slate-700">
        Currently applied: <?= sanitize($currentTpl['label']) ?>
      </div>
      <textarea id="industryTemplate" rows="10" readonly
        class="w-full px-4 py-3 text-[12.5px] text-slate-600 outline-none resize-none leading-relaxed bg-slate-50/50"
      ><?php
        $liveOffer = function_exists('industry_resolved_offer_text')
            ? industry_resolved_offer_text(is_array($bot ?? null) ? $bot : [])
            : (string) ($currentTpl['offer'] ?? '');
        echo sanitize("Offer: {$liveOffer}\n\n" . implode("\n", (array) ($currentTpl['questions'] ?? [])));
      ?></textarea>
    </div>
    <div class="text-[11px] text-slate-400 mt-2">To edit this text for your business, use the <a href="/client/training?tab=knowledge" class="text-[#1FA855] font-medium">Knowledge tab</a>. To change the qualifying questions and conversion goal for this bot only, use <a href="/client/training?tab=qualify" class="text-[#1FA855] font-medium">Qualify</a> — that does not change the admin industry template.</div>
    <?php else: ?>
    <div class="border border-dashed border-slate-200 rounded-lg p-6 text-center text-[12px] text-slate-400">No template applied yet. Choose one above and click "Apply Template".</div>
    <?php endif; ?>

    <?php
    $sampleTurns = function_exists('industry_sample_turns')
        ? industry_sample_turns(is_array($bot ?? null) ? $bot : [], $industryKey)
        : [];
    ?>
    <div class="text-[11.5px] text-slate-400 mt-4 mb-2 font-semibold">Sample Conversation</div>
    <div class="text-[11px] text-slate-400 mb-3">Product / offer questions use your industry template and catalog. Casual chat stays human — no template.</div>
    <div class="space-y-3">
      <?php if ($sampleTurns === []): ?>
      <div class="text-[12px] text-slate-400">Apply an industry template to see a sample.</div>
      <?php else: foreach ($sampleTurns as $turn):
          $isUser = ($turn['role'] ?? '') === 'user';
          $body = nl2br(sanitize((string) ($turn['text'] ?? '')));
      ?>
      <?php if ($isUser): ?>
      <div class="flex justify-end"><div class="bg-[#DCF8C6] rounded-xl rounded-tr-none px-3 py-2 text-[12px] text-slate-700 max-w-[240px]"><?= $body ?> <span class="text-[9.5px] text-slate-400 ml-1">10:30</span></div></div>
      <?php else: ?>
      <div class="flex gap-2">
        <div class="w-8 h-8 rounded-full bg-[#1FA855] flex items-center justify-center shrink-0 mt-1">
          <span class="text-white text-[11px] font-bold"><?= sanitize(iqp_initials($repNamePreview)) ?></span>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl rounded-tl-none px-3 py-2 text-[12px] text-slate-700 max-w-[240px]">
          <?= $body ?>
          <div class="text-[9.5px] text-slate-400 mt-1">10:30 AM</div>
        </div>
      </div>
      <?php endif; endforeach; endif; ?>
    </div>
  </div>

  <!-- Right: Menu items + Trigger words summary -->
  <div class="space-y-4">
    <!-- Menu / Catalogue Items preview -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="flex items-center justify-between mb-3">
        <div>
          <div class="text-[14px] font-bold text-slate-800">Menu / Catalogue Items</div>
          <div class="text-[11.5px] text-slate-400 mt-0.5">Add your menu, products or services so AI can respond accurately.</div>
        </div>
        <a href="/client/catalog?bot_id=<?= (int)$botId ?>" class="flex items-center gap-1 border border-slate-200 rounded-lg px-3 py-1.5 text-[12px] font-medium text-slate-600 hover:bg-slate-50">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
          Add Item
        </a>
      </div>
      <?php if ($products === []): ?>
      <div class="text-[12px] text-slate-400 py-4 text-center">No products yet. <a href="/client/catalog" class="text-[#1FA855] font-medium">Add from Shop</a></div>
      <?php else: ?>
      <div class="text-[11.5px] grid grid-cols-[2fr_1fr_70px_60px_60px] gap-2 text-slate-400 font-medium pb-1.5 border-b border-slate-100">
        <span>Item / Product / Service</span><span>Category</span><span>Price</span><span>Status</span><span>Actions</span>
      </div>
      <?php foreach ($prodLimit as $p):
          $active = !empty($p['is_active']);
          $pPrice = function_exists('catalog_format_price') ? catalog_format_price((float)($p['price']??0),(string)($p['currency']??$currency)) : $currency.' '.number_format((float)($p['price']??0));
      ?>
      <div class="grid grid-cols-[2fr_1fr_70px_60px_60px] gap-2 items-center py-2 border-b border-slate-50 last:border-0 text-[12px]">
        <span class="font-medium text-slate-700 truncate"><?= sanitize((string)$p['name']) ?></span>
        <span class="text-slate-500 truncate"><?= sanitize((string)($p['category']??'—')) ?></span>
        <span class="text-slate-700 font-semibold"><?= sanitize($pPrice) ?></span>
        <span class="<?= $active?'text-emerald-600':'text-slate-400' ?> font-medium"><?= $active?'Active':'Inactive' ?></span>
        <div class="flex gap-2">
          <a href="/client/catalog?bot_id=<?= (int)$botId ?>&edit=<?= (int)$p['id'] ?>" class="text-slate-400 hover:text-[#1FA855]">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
          </a>
          <form method="POST" action="/client/catalog" onsubmit="return confirm('Remove this item?');">
            <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
            <input type="hidden" name="action" value="delete_product"/>
            <input type="hidden" name="bot_id" value="<?= (int)$botId ?>"/>
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"/>
            <button type="submit" class="text-slate-300 hover:text-red-500">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (count($products) > 5): ?>
      <a href="/client/training?tab=menu" class="block mt-2 text-[12px] text-[#1FA855] font-medium hover:underline">Build menu cards (<?= count($products) ?> items in Shop) →</a>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Trigger Words summary -->
    <?php $triggerWords = (array) ($trainingMeta['trigger_words'] ?? []); ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="flex items-center justify-between mb-3">
        <div>
          <div class="text-[14px] font-bold text-slate-800">Trigger Words</div>
          <div class="text-[11.5px] text-slate-400 mt-0.5">Keywords that hint the AI toward a specific intent.</div>
        </div>
        <a href="/client/training?tab=triggers" class="flex items-center gap-1 border border-slate-200 rounded-lg px-3 py-1.5 text-[12px] font-medium text-slate-600 hover:bg-slate-50">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
          Manage
        </a>
      </div>
      <?php if ($triggerWords === []): ?>
      <div class="text-[12px] text-slate-400 py-4 text-center">No trigger words yet. <a href="/client/training?tab=triggers" class="text-[#1FA855] font-medium">Add one</a>.</div>
      <?php else: ?>
      <div class="text-[11.5px] grid grid-cols-[2fr_1fr_60px] gap-2 text-slate-400 font-medium pb-1.5 border-b border-slate-100">
        <span>Trigger Word / Keyword</span><span>Intent</span><span>Status</span>
      </div>
      <?php foreach (array_slice($triggerWords, 0, 5) as $tw): ?>
      <div class="grid grid-cols-[2fr_1fr_60px] gap-2 items-center py-2 border-b border-slate-50 last:border-0 text-[12px]">
        <span class="font-mono text-slate-700"><?= sanitize((string) ($tw['word'] ?? '')) ?></span>
        <span class="text-slate-500 truncate"><?= sanitize((string) ($tw['intent'] ?? '—')) ?></span>
        <span class="<?= !empty($tw['is_active']) ? 'text-emerald-600' : 'text-slate-400' ?> font-medium"><?= !empty($tw['is_active']) ? 'Active' : 'Off' ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (count($triggerWords) > 5): ?>
      <a href="/client/training?tab=triggers" class="block mt-2 text-[12px] text-[#1FA855] font-medium hover:underline">View all trigger words (<?= count($triggerWords) ?>) →</a>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; // industry tab ?>

<?php if ($tab === 'sales'): ?>
<!-- ---- Sales & Leads ---- -->
<?php
$qualifyFlow = is_array($qualifyFlow ?? null) ? $qualifyFlow : [];
$qualifyTypes = is_array($qualifyTypes ?? null) ? $qualifyTypes : [];
$qualifyModes = is_array($qualifyModes ?? null) ? $qualifyModes : [];
$qualifyGoals = is_array($qualifyGoals ?? null) ? $qualifyGoals : [];
$qQuestions = (array) ($qualifyFlow['questions'] ?? []);
if ($qQuestions === []) {
    $qQuestions = [['text' => '', 'type' => 'Custom', 'required' => true]];
}
$qCustom = !empty($qualifyFlow['custom']);
$qLabel = trim((string) ($qualifyFlow['industry_label'] ?? ''));
?>
<div class="bg-white rounded-xl border border-slate-200 p-5 max-w-5xl">
  <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
    <div>
      <div class="text-[15px] font-bold text-slate-800">What should the assistant help you achieve?</div>
      <div class="text-[12px] text-slate-400 mt-0.5">Choose a goal, then tell the assistant what information to collect from leads. It will ask naturally — not like a form.</div>
      <?php if ($qLabel === ''): ?>
      <div class="text-[12px] text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mt-2">No business type selected yet. You can still add questions here, or pick a type under <a href="/client/training?tab=business" class="font-semibold underline">Business</a>.</div>
      <?php endif; ?>
    </div>
    <div class="flex flex-wrap items-center gap-2 shrink-0">
      <?php if ($qCustom): ?>
      <span class="inline-flex items-center rounded-full bg-emerald-50 border border-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">Custom for this bot</span>
      <?php else: ?>
      <span class="inline-flex items-center rounded-full bg-slate-50 border border-slate-200 px-2.5 py-1 text-[11px] font-medium text-slate-500">Using industry starter<?= $qLabel !== '' ? ' · ' . sanitize($qLabel) : '' ?></span>
      <?php endif; ?>
    </div>
  </div>

  <form method="POST" id="qualifyForm">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_qualification"/>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Conversion goal</label>
        <select name="conversion_goal" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]">
          <?php foreach ($qualifyGoals as $gv => $gl): ?>
          <option value="<?= sanitize($gv) ?>"<?= ($qualifyFlow['conversion_goal'] ?? '') === $gv ? ' selected' : '' ?>><?= sanitize($gl) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-[11px] text-slate-400 mt-1.5">Shop / restaurant → order placed. Clinic, agency, real estate → call booked. SaaS → trial started.</p>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Business mode</label>
        <select name="business_mode" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]">
          <?php foreach ($qualifyModes as $mv => $ml): ?>
          <option value="<?= sanitize($mv) ?>"<?= ($qualifyFlow['business_mode'] ?? '') === $mv ? ' selected' : '' ?>><?= sanitize($ml) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mb-5">
      <div class="flex items-center justify-between gap-2 mb-2">
        <div>
          <div class="text-[13px] font-semibold text-slate-800">What information should the assistant collect from leads?</div>
          <div class="text-[11.5px] text-slate-400">Asked one at a time in conversation. If they already answered, it will not ask again. Required items must be collected before a lead counts as qualified.</div>
        </div>
        <button type="button" onclick="iqpAddQualifyQuestion()" class="border border-slate-200 rounded-lg px-3 py-1.5 text-[12px] font-medium text-slate-600 hover:bg-slate-50 whitespace-nowrap">+ Add question</button>
      </div>
      <div id="qualifyQuestions" class="space-y-2">
        <?php foreach ($qQuestions as $qi => $qq): ?>
        <div class="qualify-q-row flex flex-col sm:flex-row sm:items-center gap-2 border border-slate-200 rounded-lg p-2.5 bg-slate-50/50">
          <span class="hidden sm:flex w-6 h-6 items-center justify-center text-[11px] font-semibold text-slate-400 shrink-0 qualify-q-num"><?= (int) $qi + 1 ?></span>
          <input name="questions[<?= (int) $qi ?>][text]" value="<?= sanitize((string) ($qq['text'] ?? '')) ?>" maxlength="240" placeholder="e.g. Which city should we deliver to?"
            class="flex-1 min-w-0 border border-slate-200 rounded-lg px-3 py-2 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]"/>
          <select name="questions[<?= (int) $qi ?>][type]" class="sm:w-[150px] border border-slate-200 rounded-lg px-2 py-2 text-[12px] text-slate-700 bg-white">
            <?php foreach ($qualifyTypes as $tv => $tl): ?>
            <option value="<?= sanitize($tv) ?>"<?= (($qq['type'] ?? 'Custom') === $tv) ? ' selected' : '' ?>><?= sanitize($tl) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="flex items-center gap-1.5 text-[11.5px] text-slate-500 shrink-0">
            <input type="checkbox" name="questions[<?= (int) $qi ?>][required]" value="1" class="w-3.5 h-3.5 accent-[#1FA855]"<?= !empty($qq['required']) ? ' checked' : '' ?>/>
            Required
          </label>
          <div class="flex gap-1 shrink-0">
            <button type="button" onclick="iqpMoveQualifyQuestion(this,-1)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:bg-white" title="Move up">↑</button>
            <button type="button" onclick="iqpMoveQualifyQuestion(this,1)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:bg-white" title="Move down">↓</button>
            <button type="button" onclick="iqpRemoveQualifyQuestion(this)" class="w-8 h-8 rounded-lg border border-slate-200 text-red-400 hover:bg-red-50" title="Remove">×</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="mb-4">
      <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Qualify when (trigger)</label>
      <textarea name="qualify_trigger" rows="2" maxlength="500"
        class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-none"
        placeholder="When this is true, the lead is qualified"><?= sanitize((string) ($qualifyFlow['qualify_trigger'] ?? '')) ?></textarea>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Message when qualified</label>
        <textarea name="qualify_message" rows="3" maxlength="500"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-none"><?= sanitize((string) ($qualifyFlow['qualify_message'] ?? '')) ?></textarea>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Message when not a fit</label>
        <textarea name="disqualify_message" rows="3" maxlength="500"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-none"><?= sanitize((string) ($qualifyFlow['disqualify_message'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-[11px] text-slate-400 max-w-xl">Saving marks this as a custom flow. Applying a different industry later will not overwrite it unless you tick that box on the Industry tab.</p>
      <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Save Sales &amp; Leads</button>
    </div>
  </form>

  <?php if ($qLabel !== '' || $qCustom): ?>
  <form method="POST" class="mt-4 pt-4 border-t border-slate-100" onsubmit="return confirm('Replace this flow with the current industry starter questions?');">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="reset_qualification"/>
    <button type="submit" class="text-[12px] text-slate-500 hover:text-slate-700 underline">Reset to <?= $qLabel !== '' ? sanitize($qLabel) : 'industry' ?> starter</button>
  </form>
  <?php endif; ?>
</div>

<template id="qualifyQuestionTpl">
  <div class="qualify-q-row flex flex-col sm:flex-row sm:items-center gap-2 border border-slate-200 rounded-lg p-2.5 bg-slate-50/50">
    <span class="hidden sm:flex w-6 h-6 items-center justify-center text-[11px] font-semibold text-slate-400 shrink-0 qualify-q-num">1</span>
    <input name="questions[0][text]" value="" maxlength="240" placeholder="e.g. What do you need help with?"
      class="flex-1 min-w-0 border border-slate-200 rounded-lg px-3 py-2 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]"/>
    <select name="questions[0][type]" class="sm:w-[150px] border border-slate-200 rounded-lg px-2 py-2 text-[12px] text-slate-700 bg-white">
      <?php foreach ($qualifyTypes as $tv => $tl): ?>
      <option value="<?= sanitize($tv) ?>"><?= sanitize($tl) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="flex items-center gap-1.5 text-[11.5px] text-slate-500 shrink-0">
      <input type="checkbox" name="questions[0][required]" value="1" class="w-3.5 h-3.5 accent-[#1FA855]" checked/>
      Required
    </label>
    <div class="flex gap-1 shrink-0">
      <button type="button" onclick="iqpMoveQualifyQuestion(this,-1)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:bg-white" title="Move up">↑</button>
      <button type="button" onclick="iqpMoveQualifyQuestion(this,1)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:bg-white" title="Move down">↓</button>
      <button type="button" onclick="iqpRemoveQualifyQuestion(this)" class="w-8 h-8 rounded-lg border border-slate-200 text-red-400 hover:bg-red-50" title="Remove">×</button>
    </div>
  </div>
</template>
<?php endif; ?>

<?php if ($tab === 'knowledge'): ?>
<!-- ---- Customer phrases (optional intent hints) ---- -->
<?php $triggerWords = (array) ($trainingMeta['trigger_words'] ?? []); ?>
<div class="bg-white rounded-xl border border-slate-200 p-5 mt-4">
  <div class="flex items-center justify-between mb-4">
    <div>
      <div class="text-[15px] font-bold text-slate-800">Words customers often use</div>
      <div class="text-[12px] text-slate-400 mt-0.5">Optional phrases (for example “menu” or “prices”). The assistant uses them as hints, not a rigid script.</div>
    </div>
  </div>

  <form method="POST" class="flex flex-wrap items-end gap-2 mb-5 bg-slate-50 border border-slate-100 rounded-lg p-3">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="add_trigger_word"/>
    <div class="flex-1 min-w-[140px]">
      <label class="block text-[11px] text-slate-500 font-medium mb-1">Phrase</label>
      <input name="word" required maxlength="60" placeholder="e.g. menu"
        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
    </div>
    <div class="flex-1 min-w-[140px]">
      <label class="block text-[11px] text-slate-500 font-medium mb-1">Intent (optional)</label>
      <input name="intent" maxlength="80" placeholder="e.g. Show Menu"
        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
    </div>
    <button type="submit" class="flex items-center gap-1.5 bg-[#1FA855] text-white rounded-lg px-3 py-2 text-[12px] font-semibold hover:bg-[#18934a]">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
      Add phrase
    </button>
  </form>

  <?php if ($triggerWords === []): ?>
  <div class="text-[12px] text-slate-400 py-8 text-center">No trigger words yet. Add one above to get started.</div>
  <?php else: ?>
  <div class="text-[11.5px] grid grid-cols-[2fr_2fr_80px_90px] gap-2 text-slate-400 font-medium pb-1.5 border-b border-slate-100">
    <span>Trigger Word / Keyword</span><span>Intent</span><span>Status</span><span>Actions</span>
  </div>
  <?php foreach ($triggerWords as $i => $tw): ?>
  <div class="grid grid-cols-[2fr_2fr_80px_90px] gap-2 items-center py-2.5 border-b border-slate-50 last:border-0 text-[12px]">
    <span class="font-mono text-slate-700"><?= sanitize((string) ($tw['word'] ?? '')) ?></span>
    <span class="text-slate-500 truncate"><?= sanitize((string) ($tw['intent'] ?? '—')) ?></span>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="toggle_trigger_word"/>
      <input type="hidden" name="index" value="<?= (int) $i ?>"/>
      <button type="submit" class="<?= !empty($tw['is_active']) ? 'text-emerald-600' : 'text-slate-400' ?> font-medium hover:underline">
        <?= !empty($tw['is_active']) ? 'Active' : 'Off' ?>
      </button>
    </form>
    <form method="POST" onsubmit="return confirm('Remove this trigger word?');">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="delete_trigger_word"/>
      <input type="hidden" name="index" value="<?= (int) $i ?>"/>
      <button type="submit" class="text-slate-400 hover:text-red-500">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
      </button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'business'): ?>
<!-- ---- Operating Hours (inside Business) ---- -->
<?php
$hoursMeta = (array) ($trainingMeta['operating_hours'] ?? ['always_open' => true, 'days' => []]);
$hoursAlwaysOpen = !empty($hoursMeta['always_open']);
$hoursDays = (array) ($hoursMeta['days'] ?? []);
$weekdays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
?>
<div class="bg-white rounded-xl border border-slate-200 p-5">
  <div class="text-[15px] font-bold text-slate-800 mb-0.5">Operating Hours</div>
  <div class="text-[12px] text-slate-400 mb-4">Set when your business is open. Outside these hours, the assistant lets customers know the store is currently closed.</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_hours"/>
    <div class="space-y-3 mb-4">
      <label class="flex items-center justify-between text-[13px] font-medium text-slate-700 py-3 border-b border-slate-100">
        <div>
          <div>Always Open (24/7)</div>
          <div class="text-[11.5px] text-slate-400 font-normal mt-0.5">Assistant never mentions being closed</div>
        </div>
        <input type="checkbox" name="always_open" id="alwaysOpen" value="1" class="w-4 h-4 accent-[#1FA855]" <?= $hoursAlwaysOpen ? 'checked' : '' ?>
          onchange="document.getElementById('customHoursGrid').classList.toggle('hidden',this.checked)"/>
      </label>
      <div id="customHoursGrid" class="iqp-hours-grid <?= $hoursAlwaysOpen ? 'hidden' : '' ?>">
        <?php foreach ($weekdays as $day): $slug = strtolower($day); $d = $hoursDays[$day] ?? ['enabled' => true, 'open' => '10:00', 'close' => '22:00']; ?>
        <div class="iqp-hours-row">
          <label class="iqp-hours-day">
            <input type="checkbox" name="day_enabled[<?= sanitize($slug) ?>]" value="1" class="w-4 h-4 accent-[#1FA855] shrink-0" <?= !empty($d['enabled']) ? 'checked' : '' ?>/>
            <span><?= sanitize($day) ?></span>
          </label>
          <div class="iqp-hours-times">
            <input type="time" name="day_open[<?= sanitize($slug) ?>]" value="<?= sanitize((string) ($d['open'] ?? '10:00')) ?>" class="iqp-hours-time" aria-label="<?= sanitize($day) ?> open"/>
            <span class="iqp-hours-sep">to</span>
            <input type="time" name="day_close[<?= sanitize($slug) ?>]" value="<?= sanitize((string) ($d['close'] ?? '22:00')) ?>" class="iqp-hours-time" aria-label="<?= sanitize($day) ?> close"/>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="flex justify-end">
      <button class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Save Operating Hours</button>
    </div>
  </form>
</div>

<div class="bg-white rounded-xl border border-slate-200 p-5 mt-4">
  <div class="text-[15px] font-bold text-slate-800 mb-0.5">When customers message after hours</div>
  <div class="text-[12px] text-slate-400 mb-4">The assistant still replies. It must not say you are open if you are closed. Use the note below.</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_closed_behavior"/>
    <div class="flex flex-wrap gap-1.5 mb-2">
      <?php foreach (['{operating_hours}','{store_name}','{rep_name}'] as $v): ?>
      <button type="button" onclick="iqpInsertAtCursor('closedBehaviorInput','<?= sanitize($v) ?>')" class="border border-slate-200 bg-slate-50 rounded-full px-2.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 font-mono"><?= $v ?></button>
      <?php endforeach; ?>
    </div>
    <textarea id="closedBehaviorInput" name="closed_behavior" maxlength="500" rows="4"
      class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-none"
      placeholder="Sorry, we are currently closed. Our operating hours are {operating_hours}."><?= sanitize((string) ($trainingMeta['closed_behavior'] ?? '')) ?></textarea>
    <div class="flex justify-end mt-3">
      <button class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Save after-hours note</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'conversation'): ?>
<?php
  $conv = $conversationPrefs;
  $repPersonaWords = 0;
  $repPersonaTrim = trim((string) ($repPersona ?? ''));
  if ($repPersonaTrim !== '') {
      $repPersonaWords = count(preg_split('/\s+/u', $repPersonaTrim, -1, PREG_SPLIT_NO_EMPTY) ?: []);
  }
  $toneVal = (string) ($conv['tone'] ?? 'Friendly & Professional');
  $formalityVal = (string) ($conv['formality'] ?? 'Professional');
  $langVal = (string) ($conv['language'] ?? 'Simple & Clear');
  $lenVal = (string) ($conv['response_length'] ?? 'Concise');
  $emojiVal = (string) ($conv['emoji'] ?? 'Occasional');
?>
<div class="bg-white rounded-xl border border-slate-200 p-5">
  <div class="text-[15px] font-bold text-slate-800 mb-0.5">How your assistant talks</div>
  <div class="text-[12px] text-slate-400 mb-4">These are your preferences. Platform safety and Admin rules always come first.</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
    <input type="hidden" name="action" value="save_conversation"/>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Assistant name</label>
        <input name="rep_name" value="<?= sanitize($repName) ?>" maxlength="30" placeholder="e.g. Shiza"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Tone</label>
        <select name="tone" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]">
          <?php foreach (ai_allowed_customer_tone_values() as $opt): ?>
          <option<?= $toneVal === $opt ? ' selected' : '' ?>><?= sanitize($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Formality</label>
        <select name="formality" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white">
          <?php foreach (ai_allowed_customer_formality_values() as $opt): ?>
          <option<?= $formalityVal === $opt ? ' selected' : '' ?>><?= sanitize($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Language</label>
        <select name="language" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white">
          <?php foreach (ai_allowed_customer_language_values() as $opt): ?>
          <option<?= $langVal === $opt ? ' selected' : '' ?>><?= sanitize($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Response length</label>
        <select name="response_length" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white">
          <?php foreach (ai_allowed_customer_length_values() as $opt): ?>
          <option<?= $lenVal === $opt ? ' selected' : '' ?>><?= sanitize($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Emoji</label>
        <select name="emoji" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white">
          <?php foreach (ai_allowed_customer_emoji_values() as $opt): ?>
          <option<?= $emojiVal === $opt ? ' selected' : '' ?>><?= sanitize($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Assistant personality <span class="text-slate-300 font-normal" id="repPersonaCount"><?= (int) $repPersonaWords ?>/5000 words</span></label>
        <textarea name="rep_persona" rows="6"
          class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855] resize-y min-h-[100px]"
          placeholder="Shiza is warm, confident and helpful. She speaks naturally, keeps conversations conversational, and asks thoughtful questions before recommending the next step."><?= sanitize($repPersona ?? '') ?></textarea>
        <p class="text-[11px] text-slate-400 mt-1.5">Optional. Do not invent a fictional personal life unless you want that flavour. Never used to override safety rules.</p>
      </div>
      <div class="sm:col-span-2">
        <label class="flex items-center gap-2 text-[13px] text-slate-700">
          <input type="checkbox" name="personal_touches" value="1" class="w-4 h-4 accent-[#1FA855]"<?= !empty($conv['personal_touches']) ? ' checked' : '' ?>/>
          Allow light personal touches (hobbies, small talk) when the customer is not asking a business question
        </label>
      </div>
    </div>
    <div class="flex justify-end mt-4">
      <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Update Assistant</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'test'): ?>
<?php
  $readyItems = (array) ($trainingReady['items'] ?? []);
  $assistantReady = !empty($trainingReady['ready']);
  $testChips = function_exists('industry_test_chips')
      ? industry_test_chips((string) ($selectedIndustryKey ?? $industryKey ?? ''))
      : [];
  $scenarioChips = [
    'Ask about services' => 'What services do you offer?',
    'Ask about pricing' => 'What are your prices?',
    'Ask about availability' => 'Are you open right now?',
    'Become a lead' => 'I want to get started. My budget is around 50,000 and I need this next month.',
    'Ask an unknown question' => 'Do you offer a lifetime warranty on the moon?',
    'Request a human' => 'Can I talk to a real person please?',
  ];
?>
<div class="bg-white rounded-xl border border-slate-200 p-5 mb-4">
  <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
    <div>
      <div class="text-[15px] font-bold text-slate-800">Test &amp; Publish</div>
      <div class="text-[12px] text-slate-400 mt-0.5">This chat uses your live business setup, knowledge, products, lead questions, tone, and platform rules. Saving a section already updates the assistant — there is no training job.</div>
    </div>
    <div class="shrink-0">
      <span class="inline-flex items-center rounded-full px-3 py-1 text-[12px] font-semibold <?= $assistantReady ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-amber-50 text-amber-800 border border-amber-100' ?>">
        Assistant: <?= $assistantReady ? 'Ready' : 'Needs setup' ?>
      </span>
    </div>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4">
    <?php foreach ($readyItems as $item): ?>
    <div class="flex items-center gap-2 text-[13px] <?= !empty($item['ready']) ? 'text-emerald-700' : 'text-slate-500' ?>">
      <span><?= !empty($item['ready']) ? '✓' : '○' ?></span>
      <span><?= sanitize((string) ($item['label'] ?? '')) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col" style="min-height:640px;height:min(78vh,760px)">
  <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
    <div class="flex items-center gap-2.5">
      <div class="iqp-chat-avatar-lg w-8 h-8 rounded-full bg-[#1FA855] flex items-center justify-center shrink-0">
        <span class="text-white text-[11px] font-bold"><?= sanitize(iqp_initials($repNamePreview)) ?></span>
      </div>
      <div>
        <div class="text-[13px] font-semibold text-slate-800"><?= sanitize($repNamePreview) ?></div>
        <div class="flex items-center gap-1 text-[11px] text-emerald-600">
          <div class="w-1.5 h-1.5 rounded-full bg-emerald-500"></div>
          Same setup as live WhatsApp
        </div>
      </div>
    </div>
    <button type="button" onclick="resetChat()" class="text-slate-400 hover:text-slate-600">Reset</button>
  </div>
  <div id="chatMessages" class="flex-1 overflow-y-auto px-4 py-3 space-y-3 bg-slate-50/40">
    <div class="flex gap-2">
      <div class="iqp-chat-avatar w-7 h-7 rounded-full bg-[#1FA855] flex items-center justify-center shrink-0 mt-1">
        <span class="text-white text-[10px] font-bold"><?= sanitize(iqp_initials($repNamePreview)) ?></span>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl rounded-tl-none px-3 py-2 text-[12.5px] text-slate-700 max-w-[85%] shadow-sm">
        Hi! I'm <?= sanitize($repNamePreview) ?>. How can I help you today?
        <div class="text-[9.5px] text-slate-400 mt-1"><?= date('g:i A') ?></div>
      </div>
    </div>
  </div>
  <div id="chatChips" class="flex flex-wrap gap-1.5 px-4 py-2 border-t border-slate-100 bg-white">
    <?php foreach ($scenarioChips as $label => $msg): ?>
    <button type="button" onclick="sendChatChip(<?= htmlspecialchars(json_encode($msg), ENT_QUOTES) ?>)"
      class="border border-slate-200 rounded-full px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50 hover:border-[#1FA855] hover:text-[#1FA855]"><?= sanitize($label) ?></button>
    <?php endforeach; ?>
    <?php foreach (array_slice($testChips, 0, 3) as $chip): ?>
    <button type="button" onclick="sendChatChip(this.textContent)"
      class="border border-slate-200 rounded-full px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50 hover:border-[#1FA855] hover:text-[#1FA855]"><?= sanitize($chip) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="flex items-center gap-2 px-4 py-3 border-t border-slate-100 bg-white">
    <input id="chatInput" type="text" placeholder="Simulate a customer message..."
      class="flex-1 border border-slate-200 rounded-full px-4 py-2 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"
      onkeydown="if(event.key==='Enter'){sendChat()}"/>
    <button type="button" onclick="sendChat()"
      class="w-9 h-9 rounded-full bg-[#1FA855] flex items-center justify-center hover:bg-[#18934a] shrink-0">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
    </button>
  </div>
  <div class="px-4 py-1.5 text-center text-[10.5px] text-slate-400 border-t border-slate-50 bg-white">
    Replies use your current saved configuration. They go live as soon as you save a section.
  </div>
</div>
<?php endif; ?>

</div><!-- /left column -->

<!-- ==================== RIGHT RAIL ==================== -->
<?php if ($tab !== 'test'): ?>
<div class="iqp-train-rail space-y-4 min-w-0">
  <div class="bg-white rounded-xl border border-slate-200 p-4">
    <div class="text-[13px] font-semibold text-slate-800">Test your assistant</div>
    <div class="text-[12px] text-slate-400 mt-1">Simulate a customer chat with the same live setup used on WhatsApp.</div>
    <a href="/client/training?tab=test" class="mt-3 inline-flex items-center gap-1.5 bg-[#1FA855] text-white rounded-lg px-3 py-2 text-[12.5px] font-semibold hover:bg-[#18934a]">Open Test &amp; Publish</a>
  </div>
  <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4 text-[12px] text-emerald-800">
    Changes are live when you save a section. There is no separate training job to run.
  </div>
</div>
<?php endif; ?>

</div><!-- /grid -->

<script>
// ---- Test Assistant Chat ----
const botId   = <?= (int)$botId ?>;
const csrf    = <?= json_encode($csrf) ?>;
const repName = <?= json_encode($repNamePreview) ?>;
const repInit = <?= json_encode(iqp_initials($repNamePreview)) ?>;
let chatHistory = [];

document.getElementById('bizInfoForm')?.addEventListener('submit', function(){
  const nameInp = document.getElementById('iqpRepNameInput');
  const hidden = document.getElementById('bizRepNameHidden');
  if (nameInp && hidden) hidden.value = nameInp.value.trim();
});

function formatTime(){
  const d=new Date();
  return d.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});
}

function appendMsg(text, role){
  const wrap = document.getElementById('chatMessages');
  if (!wrap) return;
  const t = formatTime();
  if(role==='user'){
    wrap.innerHTML += `<div class="flex justify-end">
      <div class="bg-[#DCF8C6] rounded-xl rounded-tr-none px-3 py-2 text-[12.5px] text-slate-700 max-w-[85%]">
        ${escHtml(text)}<div class="text-[9.5px] text-slate-400 mt-1 text-right">${t} ✓✓</div>
      </div></div>`;
  } else {
    wrap.innerHTML += `<div class="flex gap-2">
      <div class="iqp-chat-avatar w-7 h-7 rounded-full bg-[#1FA855] flex items-center justify-center shrink-0 mt-1">
        <span class="text-white text-[10px] font-bold">${escHtml(repInit)}</span>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl rounded-tl-none px-3 py-2 text-[12.5px] text-slate-700 max-w-[85%] shadow-sm whitespace-pre-wrap leading-relaxed">
        ${escHtml(text)}<div class="text-[9.5px] text-slate-400 mt-1">${t}</div>
      </div></div>`;
  }
  wrap.scrollTop = wrap.scrollHeight;
}

function appendTyping(){
  const wrap = document.getElementById('chatMessages');
  if (!wrap) return;
  document.getElementById('typingIndicator')?.remove();
  wrap.innerHTML += `<div id="typingIndicator" class="flex gap-2 iqp-typing-row">
    <div class="iqp-chat-avatar w-7 h-7 rounded-full bg-[#1FA855] flex items-center justify-center shrink-0">
      <span class="text-white text-[10px] font-bold">${escHtml(repInit)}</span>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl rounded-tl-none px-4 py-3 shadow-sm flex gap-1 items-center iqp-typing-bubble">
      <div class="iqp-typing-dot"></div>
      <div class="iqp-typing-dot" style="animation-delay:.15s"></div>
      <div class="iqp-typing-dot" style="animation-delay:.3s"></div>
    </div></div>`;
  wrap.scrollTop = wrap.scrollHeight;
}

function removeTyping(){
  document.getElementById('typingIndicator')?.remove();
}

function escHtml(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function sendChat(){
  const inp = document.getElementById('chatInput');
  if (!inp) return;
  const msg = inp.value.trim();
  if(!msg) return;
  inp.value = '';
  appendMsg(msg,'user');
  appendTyping();
  try {
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('bot_id', botId);
    fd.append('message', msg);
    fd.append('action', 'chat');
    fd.append('history', JSON.stringify(chatHistory.slice(-12)));
    chatHistory.push({role:'user', content: msg});
    const res = await fetch('/api/bot-chat.php', {method:'POST', body:fd});
    const data = await res.json().catch(()=>({reply:'Sorry, I couldn\'t process that.'}));
    removeTyping();
    const reply = data.reply || data.message || 'Sorry, I couldn\'t process that.';
    chatHistory.push({role:'assistant', content: reply});
    appendMsg(reply, 'bot');
  } catch(e){
    removeTyping();
    appendMsg('Sorry, there was a connection error. Please try again.','bot');
  }
}

function sendChatChip(text){
  const inp = document.getElementById('chatInput');
  if (!inp) return;
  inp.value = text;
  sendChat();
}

function resetChat(){
  const box = document.getElementById('chatMessages');
  if (!box) return;
  chatHistory = [];
  box.innerHTML = `<div class="flex gap-2">
    <div class="iqp-chat-avatar w-7 h-7 rounded-full bg-[#1FA855] flex items-center justify-center shrink-0 mt-1">
      <span class="text-white text-[10px] font-bold">${escHtml(repInit)}</span>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl rounded-tl-none px-3 py-2 text-[12.5px] text-slate-700 max-w-[85%] shadow-sm">
      Hi! I'm ${escHtml(repName)}. How can I help you today?
      <div class="text-[9.5px] text-slate-400 mt-1">${formatTime()}</div>
    </div></div>`;
}

function emojiPicker(){
  // Placeholder: focus input
  document.getElementById('chatInput').focus();
}

// ---- Knowledge / Training Data toolbar — plain-text helpers (textarea has no
// rich rendering, so these annotate the raw text the AI reads rather than
// pretending to be a WYSIWYG editor). ----
function iqpWrapText(textareaId, marker){
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  const start = ta.selectionStart, end = ta.selectionEnd;
  const selected = ta.value.slice(start, end) || 'text';
  ta.value = ta.value.slice(0, start) + marker + selected + marker + ta.value.slice(end);
  ta.focus();
  ta.selectionStart = start + marker.length;
  ta.selectionEnd = start + marker.length + selected.length;
  ta.dispatchEvent(new Event('input'));
}

function iqpPrefixLines(textareaId, prefix){
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  const start = ta.selectionStart, end = ta.selectionEnd;
  const lineStart = ta.value.lastIndexOf('\n', start - 1) + 1;
  let lineEnd = ta.value.indexOf('\n', end);
  if (lineEnd === -1) lineEnd = ta.value.length;
  const block = ta.value.slice(lineStart, lineEnd);
  const cleaned = block.split('\n').map(l => l.replace(/^([•\d]+[.\)]?\s*)?/, prefix));
  ta.value = ta.value.slice(0, lineStart) + cleaned.join('\n') + ta.value.slice(lineEnd);
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

function iqpNumberLines(textareaId){
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  const start = ta.selectionStart, end = ta.selectionEnd;
  const lineStart = ta.value.lastIndexOf('\n', start - 1) + 1;
  let lineEnd = ta.value.indexOf('\n', end);
  if (lineEnd === -1) lineEnd = ta.value.length;
  const block = ta.value.slice(lineStart, lineEnd);
  const lines = block.split('\n').map(l => l.replace(/^(\d+[.\)]\s*|•\s*)/, ''));
  const numbered = lines.map((l, i) => (i + 1) + '. ' + l);
  ta.value = ta.value.slice(0, lineStart) + numbered.join('\n') + ta.value.slice(lineEnd);
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

function iqpInsertLink(textareaId){
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  const url = prompt('Link URL:', 'https://');
  if (!url) return;
  const start = ta.selectionStart, end = ta.selectionEnd;
  const label = ta.value.slice(start, end) || url;
  const link = '[' + label + '](' + url + ')';
  ta.value = ta.value.slice(0, start) + link + ta.value.slice(end);
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

function iqpClearFormatting(textareaId){
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  ta.value = ta.value
    .replace(/\*\*(.*?)\*\*/g, '$1')
    .replace(/__(.*?)__/g, '$1')
    .replace(/\*(.*?)\*/g, '$1')
    .replace(/\[(.*?)\]\((.*?)\)/g, '$1')
    .replace(/^([•]|\d+[.\)])\s*/gm, '');
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

function iqpInsertAtCursor(textareaId, text){
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  const start = ta.selectionStart ?? ta.value.length, end = ta.selectionEnd ?? ta.value.length;
  ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = start + text.length;
}

function iqpAddMenuCard(){
  const list = document.getElementById('menuCardsList');
  const sel = document.getElementById('addMenuCardCategory');
  const tpl = document.getElementById('menuCardProductTemplate');
  if (!list || !sel || !tpl) return;
  const category = sel.value || 'General';
  const idx = list.querySelectorAll('.menu-card-block').length;
  const src = tpl.content.querySelector('.menu-card-products[data-category="'+category.replace(/"/g,'')+'"]')
    || tpl.content.querySelector('.menu-card-products');
  const block = document.createElement('div');
  block.className = 'menu-card-block border border-slate-200 rounded-xl p-4 bg-slate-50/50';
  block.dataset.category = category;
  let productsHtml = '';
  if (src) {
    src.querySelectorAll('label').forEach(function (label) {
      const cb = label.querySelector('.menu-card-product-cb');
      if (!cb) return;
      const clone = label.cloneNode(true);
      const input = clone.querySelector('input');
      if (input) {
        input.name = 'menu_cards['+idx+'][product_ids][]';
        input.classList.remove('menu-card-product-cb');
      }
      productsHtml += clone.outerHTML;
    });
  }
  block.innerHTML =
    '<div class="flex flex-wrap items-center gap-3 mb-3">' +
    '<input type="hidden" name="menu_cards['+idx+'][id]" value=""/>' +
    '<input type="hidden" name="menu_cards['+idx+'][category]" value="'+category.replace(/"/g,'&quot;')+'"/>' +
    '<div class="flex-1 min-w-[160px]"><label class="block text-[10px] uppercase tracking-wide text-slate-400 mb-1">Card title</label>' +
    '<input name="menu_cards['+idx+'][title]" value="'+category.replace(/"/g,'&quot;')+'" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-[13px] font-semibold text-slate-800 bg-white"/></div>' +
    '<span class="text-[11px] text-slate-400 px-2 py-1 bg-white border border-slate-200 rounded-lg">'+category.replace(/</g,'&lt;')+'</span>' +
    '<button type="button" onclick="this.closest(\'.menu-card-block\').remove()" class="text-[11px] text-red-500 hover:underline ml-auto">Remove card</button></div>' +
    '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">'+productsHtml+'</div>';
  list.appendChild(block);
}

function iqpPreviewLogo(input){
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const box = input.closest('.iqp-biz-logo-drop') || input.closest('div');
  const filenameEl = document.getElementById('bizLogoFilename');
  if (filenameEl) filenameEl.textContent = file.name;
  const reader = new FileReader();
  reader.onload = function (e) {
    let img = document.getElementById('bizLogoPreview');
    const icon = document.getElementById('bizLogoIcon');
    if (icon) icon.remove();
    if (!img) {
      img = document.createElement('img');
      img.id = 'bizLogoPreview';
      img.className = 'iqp-biz-logo-preview';
      img.alt = 'Logo';
      box.insertBefore(img, box.firstChild);
    }
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

(function iqpRepPersonaWordLimit(){
  const ta = document.getElementById('repPersonaInput');
  const count = document.getElementById('repPersonaCount');
  if (!ta || !count) return;
  const MAX = 5000;
  function wordList(s){
    s = (s || '').trim();
    if (!s) return [];
    return s.split(/\s+/);
  }
  function update(){
    let words = wordList(ta.value);
    if (words.length > MAX) {
      ta.value = words.slice(0, MAX).join(' ');
      words = wordList(ta.value);
    }
    count.textContent = words.length + '/5000 words';
  }
  ta.addEventListener('input', update);
  ta.addEventListener('paste', function(){ setTimeout(update, 0); });
  update();
})();

function iqpQualifyReindex(){
  const rows = document.querySelectorAll('#qualifyQuestions .qualify-q-row');
  rows.forEach((row, i) => {
    const num = row.querySelector('.qualify-q-num');
    if (num) num.textContent = String(i + 1);
    row.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace(/questions\[\d+\]/, 'questions[' + i + ']');
    });
  });
}

function iqpAddQualifyQuestion(){
  const list = document.getElementById('qualifyQuestions');
  const tpl = document.getElementById('qualifyQuestionTpl');
  if (!list || !tpl || !tpl.content) return;
  list.appendChild(tpl.content.cloneNode(true));
  iqpQualifyReindex();
}

function iqpRemoveQualifyQuestion(btn){
  const list = document.getElementById('qualifyQuestions');
  const row = btn.closest('.qualify-q-row');
  if (!list || !row) return;
  if (list.querySelectorAll('.qualify-q-row').length <= 1) {
    const input = row.querySelector('input[name*="[text]"]');
    if (input) input.value = '';
    return;
  }
  row.remove();
  iqpQualifyReindex();
}

function iqpMoveQualifyQuestion(btn, dir){
  const row = btn.closest('.qualify-q-row');
  if (!row) return;
  const target = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
  if (!target || !target.classList.contains('qualify-q-row')) return;
  if (dir < 0) row.parentNode.insertBefore(row, target);
  else row.parentNode.insertBefore(target, row);
  iqpQualifyReindex();
}

// Saving a section already updates the live assistant. This page is Test & Publish.
function trainAssistant(){
  window.location.href = '/client/training?tab=test';
}
</script>
<?php iqp_user_end();
