<?php
/** @var array $user */
/** @var string $message */
/** @var string $error */
/** @var array $hoursConfig */
/** @var string $hoursStatus */
/** @var array $dayLabels */
/** @var bool $subscribedToUpdates */
/** @var int $botId */

$tab  = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'profile')) ?: 'profile';
$csrf = csrf_token();
$name    = (string)($user['name'] ?? '');
$company = (string)($user['company_name'] ?? '');
$email   = (string)($user['email'] ?? '');
$phone   = (string)($user['phone'] ?? '');
$profileTitleKey = profile_title_normalize((string)($user['profile_title'] ?? 'owner'));
$profileTitleOpts = profile_title_options();
$teamDesignationKeys = team_member_designation_keys();
$teamDesignations = team_designations();

// Settings sidebar nav definition
$nav = [
    ['profile',      'Profile Settings',     'Your name, photo, and contact',
     '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
    ['notifications','Notifications',        'Manage email and alerts',
     '<path d="M12 4a5 5 0 0 0-5 5v3.5l-1.5 3h13L17 12.5V9a5 5 0 0 0-5-5Z"/><path d="M10 19a2 2 0 0 0 4 0"/>'],
    ['security',     'Security',             'Password, 2FA, and sign out',
     '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>'],
    ['team',         'Team Members',         'Assign agents to leads',
     '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
];

$allowedTabs = array_column($nav, 0);
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'profile';
}

iqp_user_begin($user, 'settings', ['title' => 'Settings']);
if ($message !== '') iqp_flash($message);
if ($error   !== '') iqp_flash($error, 'err');
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[20px] lg:text-[22px] font-bold text-slate-800">Settings</h1>
    <p class="text-[13px] text-slate-500 mt-0.5">Account, security, and team.</p>
  </div>
  <div class="text-[12px] text-slate-400 flex items-center gap-1.5">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Last updated: <?= date('M j, Y') ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-5">

  <!-- Sidebar nav -->
  <div class="bg-white rounded-xl border border-slate-200 p-2 h-fit">
    <?php foreach ($nav as [$id,$title,$sub,$iconPath]):
        $on   = $tab === $id;
        $href = '/client/settings?tab=' . $id;
    ?>
    <a href="<?= sanitize($href) ?>"
      class="flex items-center gap-3 px-3 py-2.5 rounded-lg mb-0.5 group transition-colors
      <?= $on ? 'bg-emerald-50' : 'hover:bg-slate-50' ?>">
      <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0
        <?= $on ? 'bg-[#1FA855] text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-slate-200' ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><?= $iconPath ?></svg>
      </div>
      <div class="min-w-0">
        <div class="text-[12.5px] font-semibold <?= $on ? 'text-emerald-700' : 'text-slate-700' ?> leading-tight">
          <?= sanitize($title) ?>
        </div>
        <div class="text-[10.5px] text-slate-400 mt-0.5 leading-tight"><?= sanitize($sub) ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Content panels -->
  <div class="space-y-5">

    <?php if ($tab === 'profile'): ?>
    <!-- Profile Information -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <div class="text-[15px] font-bold text-slate-800 mb-0.5">Profile Information</div>
      <div class="text-[12.5px] text-slate-400 mb-5">Update your personal information and profile details.</div>

      <form method="POST" id="profileForm" enctype="multipart/form-data" class="space-y-0">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="profile"/>
        <input type="hidden" name="return_tab" value="profile"/>

        <!-- Avatar row with real upload -->
        <div class="flex items-center gap-5 mb-6">
          <div class="relative group">
            <div id="avatarPreview"
              class="w-20 h-20 rounded-2xl text-white text-[22px] font-bold flex items-center justify-center select-none overflow-hidden transition-all"
              style="background:linear-gradient(135deg,#1FA855 0%,#16803d 100%);box-shadow:0 4px 14px rgba(31,168,85,.30)">
              <?php $av = (string)($user['avatar_url'] ?? ''); ?>
              <span id="avatarInitials" <?= $av !== '' ? 'style="display:none"' : '' ?>><?= sanitize(iqp_initials($name)) ?></span>
              <img id="avatarImg" src="<?= $av !== '' ? sanitize($av) : '' ?>" alt="Profile photo"
                class="w-full h-full object-cover <?= $av !== '' ? '' : 'hidden' ?>" style="position:absolute;inset:0;border-radius:inherit"/>
            </div>
            <!-- Upload button overlay -->
            <label for="avatarFileInput"
              class="absolute inset-0 flex items-center justify-center rounded-2xl cursor-pointer opacity-0 group-hover:opacity-100 transition-opacity"
              style="background:rgba(0,0,0,.45)">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3"/></svg>
            </label>
            <!-- Small camera badge -->
            <label for="avatarFileInput"
              class="absolute -bottom-1 -right-1 w-7 h-7 bg-white border-2 border-white rounded-full flex items-center justify-center cursor-pointer shadow-md hover:bg-slate-50 transition-colors"
              style="box-shadow:0 2px 8px rgba(0,0,0,.15)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3"/></svg>
            </label>
            <input id="avatarFileInput" type="file" name="avatar_file" accept="image/jpeg,image/png,image/webp" class="hidden"
              onchange="previewAvatar(this)"/>
          </div>
          <div>
            <div class="text-[15px] font-bold text-slate-800"><?= sanitize($name) ?></div>
            <div class="text-[13px] text-slate-500 mt-0.5"><?= sanitize($email) ?></div>
            <label for="avatarFileInput" class="mt-2 inline-flex items-center gap-1.5 text-[12.5px] text-[#1FA855] font-semibold cursor-pointer hover:underline">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Upload photo
            </label>
            <div class="text-[11px] text-slate-400 mt-0.5">JPG, PNG, WebP · max 2MB</div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Full Name</label>
            <input name="name" required value="<?= sanitize($name) ?>"
              class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
          </div>
          <div>
            <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Role / Designation</label>
            <select name="profile_title" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 bg-white focus:outline-none focus:border-[#1FA855]">
              <?php foreach ($profileTitleOpts as $key => $label): ?>
              <option value="<?= sanitize($key) ?>"<?= $profileTitleKey === $key ? ' selected' : '' ?>><?= sanitize($label) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-[10.5px] text-slate-400 mt-1"><?= sanitize(profile_title_summary($profileTitleKey)) ?></p>
          </div>
          <div>
            <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Email Address</label>
            <input disabled value="<?= sanitize($email) ?>"
              class="w-full border border-slate-100 rounded-lg px-3 py-2.5 text-[13px] text-slate-400 bg-slate-50 cursor-not-allowed"/>
          </div>
          <div>
            <label class="block text-[11.5px] text-slate-500 font-medium mb-1.5">Phone Number</label>
            <div class="flex gap-2">
              <div class="flex items-center gap-1.5 border border-slate-200 rounded-lg px-2.5 py-2.5 bg-white cursor-pointer hover:bg-slate-50 shrink-0">
                <span class="text-[14px]">🇵🇰</span>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
              </div>
              <input name="phone" value="<?= sanitize($phone) ?>" placeholder="+92 300 1234567"
                class="flex-1 border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 focus:outline-none focus:border-[#1FA855]"/>
            </div>
          </div>
        </div>
        <div class="flex justify-end">
          <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a]">Save Changes</button>
        </div>
      </form>
      <p class="text-[12px] text-slate-400 mt-4 pt-4 border-t border-slate-100">
        Business name, industry, hours, and AI training live under
        <a href="/client/training" class="text-[#1FA855] font-medium hover:underline">Train → Business Info</a>.
      </p>
    </div>

    <!-- Account Preferences -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="text-[15px] font-bold text-slate-800 mb-0.5">Account Preferences</div>
        <div class="text-[12.5px] text-slate-400 mb-5">Customize your account experience.</div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="save_preferences"/>
          <input type="hidden" name="return_tab" value="profile"/>
          <div class="space-y-3">
            <div class="flex items-center justify-between py-2.5 border-b border-slate-50">
              <div>
                <div class="text-[12.5px] font-medium text-slate-700">Language</div>
                <div class="text-[11px] text-slate-400">Choose your preferred language</div>
              </div>
              <select name="pref_language" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-[12px] text-slate-600 bg-white focus:outline-none focus:border-[#1FA855]">
                <?php foreach (['English','Urdu','Arabic','French'] as $lang): ?>
                <option<?= ($prefLanguage ?? 'English') === $lang ? ' selected' : '' ?>><?= sanitize($lang) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex items-center justify-between py-2.5 border-b border-slate-50">
              <div>
                <div class="text-[12.5px] font-medium text-slate-700">Time Zone</div>
                <div class="text-[11px] text-slate-400">Set your current time zone</div>
              </div>
              <select name="pref_timezone" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-[12px] text-slate-600 bg-white focus:outline-none focus:border-[#1FA855] max-w-[160px]">
                <?php foreach ([
                    'Asia/Karachi'     => '(GMT+05:00) Islamabad, Karachi',
                    'UTC'              => '(GMT+00:00) UTC',
                    'Europe/London'    => '(GMT+01:00) London',
                    'Asia/Kolkata'     => '(GMT+05:30) Mumbai, New Delhi',
                    'America/New_York' => '(GMT-05:00) Eastern Time',
                ] as $tzVal => $tzLabel): ?>
                <option value="<?= sanitize($tzVal) ?>"<?= ($prefTimezone ?? 'Asia/Karachi') === $tzVal ? ' selected' : '' ?>><?= sanitize($tzLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex items-center justify-between py-2.5 border-b border-slate-50">
              <div>
                <div class="text-[12.5px] font-medium text-slate-700">Date Format</div>
                <div class="text-[11px] text-slate-400">Choose your date format</div>
              </div>
              <select name="pref_date_format" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-[12px] text-slate-600 bg-white focus:outline-none focus:border-[#1FA855]">
                <?php foreach ([
                    'DD MMM, YYYY' => 'DD MMM, YYYY (20 May, 2025)',
                    'MM/DD/YYYY'   => 'MM/DD/YYYY',
                    'YYYY-MM-DD'   => 'YYYY-MM-DD',
                    'DD/MM/YYYY'   => 'DD/MM/YYYY',
                ] as $dfVal => $dfLabel): ?>
                <option value="<?= sanitize($dfVal) ?>"<?= ($prefDateFormat ?? 'DD MMM, YYYY') === $dfVal ? ' selected' : '' ?>><?= sanitize($dfLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex items-center justify-between py-2.5">
              <div>
                <div class="text-[12.5px] font-medium text-slate-700">Currency</div>
                <div class="text-[11px] text-slate-400">Choose your currency</div>
              </div>
              <select name="pref_currency" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-[12px] text-slate-600 bg-white focus:outline-none focus:border-[#1FA855]">
                <?php foreach (['PKR' => 'PKR (Rs.)', 'USD' => 'USD ($)', 'EUR' => 'EUR (€)', 'GBP' => 'GBP (£)', 'AED' => 'AED (د.إ)'] as $curVal => $curLabel): ?>
                <option value="<?= sanitize($curVal) ?>"<?= ($prefCurrency ?? 'PKR') === $curVal ? ' selected' : '' ?>><?= sanitize($curLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="flex justify-end mt-4">
            <button type="submit" class="bg-[#1FA855] text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:bg-[#18934a] active:scale-95 transition-all">Save Preferences</button>
          </div>
        </form>
      </div>

    <?php endif; // profile tab ?>

    <?php if ($tab === 'notifications'): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <div class="text-[15px] font-bold text-slate-800 mb-0.5">Notifications</div>
      <div class="text-[12.5px] text-slate-400 mb-5">Email alerts for product updates. In-app alerts always appear under Updates.</div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="updates"/>
        <input type="hidden" name="subscribe_updates" value="<?= $subscribedToUpdates?'0':'1' ?>"/>
        <div class="flex items-center justify-between py-3 border-b border-slate-100">
          <div>
            <div class="text-[12.5px] font-semibold text-slate-700">Email product updates</div>
            <div class="text-[11px] text-slate-400 mt-0.5"><?= $subscribedToUpdates?'Subscribed':'Not subscribed' ?></div>
          </div>
          <button type="submit"><?= iqp_toggle($subscribedToUpdates) ?></button>
        </div>
      </form>
      <a href="/client/notifications" class="inline-block mt-4 text-[12.5px] text-[#1FA855] font-medium hover:underline">View all notifications →</a>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'security'): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div class="bg-white rounded-xl border border-slate-200 p-6" id="password-section">
        <div class="text-[15px] font-bold text-slate-800 mb-0.5">Password & Security</div>
        <div class="text-[12.5px] text-slate-400 mb-5">Keep your account secure.</div>
        <div class="flex items-center justify-between mb-4 pb-4 border-b border-slate-100">
          <div>
            <div class="text-[12.5px] font-semibold text-slate-700">Password</div>
            <div class="text-[13px] text-slate-400 tracking-[0.2em] mt-1">••••••••••••••</div>
          </div>
          <button type="button" onclick="document.getElementById('iqpPwForm2').classList.toggle('hidden')"
            class="border border-slate-200 rounded-lg px-3 py-1.5 text-[12px] font-medium text-slate-600 hover:bg-slate-50">
            Change Password
          </button>
        </div>
        <div id="iqpPwForm2" class="space-y-3 mb-4">
          <div id="password-flash-success" class="hidden text-[12px] text-emerald-600 bg-emerald-50 rounded-lg px-3 py-2"></div>
          <div id="password-flash-error" class="hidden text-[12px] text-red-500 bg-red-50 rounded-lg px-3 py-2"></div>
          <form id="password-form" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
            <input type="password" name="current_password" required placeholder="Current password"
              class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
            <input type="password" name="new_password" required minlength="8" placeholder="New password"
              class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
            <input type="password" name="confirm_password" required minlength="8" placeholder="Confirm new password"
              class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] focus:outline-none focus:border-[#1FA855]"/>
            <button class="bg-[#1FA855] text-white rounded-lg px-4 py-2.5 text-[12.5px] font-semibold w-full hover:bg-[#18934a]">Update Password</button>
          </form>
        </div>
        <div class="flex items-center justify-between pt-3 border-t border-slate-100">
          <div>
            <div class="text-[12.5px] font-semibold text-slate-700">Two-Factor Authentication (2FA)</div>
            <div class="text-[11px] text-slate-400 mt-0.5">Add an extra layer of security to your account.</div>
          </div>
          <button type="button" id="iqp2faToggle" data-on="1"
            onclick="this.dataset.on=this.dataset.on==='1'?'0':'1';update2fa(this)"
            class="relative w-10 h-5 rounded-full bg-[#1FA855] shrink-0">
            <span class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow" id="iqp2faDot"></span>
          </button>
        </div>
      </div>
      <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="text-[15px] font-bold text-slate-800 mb-0.5">Account Preferences</div>
        <div class="text-[12.5px] text-slate-400 mb-5">Appearance for this device.</div>
        <div class="flex flex-wrap gap-2">
          <button type="button" data-theme-option="light" class="border border-slate-200 rounded-lg px-4 py-2 text-[12.5px] font-medium text-slate-600 hover:bg-slate-50">Light</button>
          <button type="button" data-theme-option="dark" class="border border-slate-200 rounded-lg px-4 py-2 text-[12.5px] font-medium text-slate-600 hover:bg-slate-50">Dark</button>
          <button type="button" data-theme-option="system" class="border border-slate-200 rounded-lg px-4 py-2 text-[12.5px] font-medium text-slate-600 hover:bg-slate-50">System</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'team'): ?>
    <div class="iqp-team-panel bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="iqp-team-panel__head px-4 sm:px-6 py-4 border-b border-slate-100">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <h2 class="text-[16px] sm:text-[17px] font-bold text-slate-800">Team Members</h2>
              <span class="text-[11px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-full px-2 py-0.5"><?= count($teamMembers ?? []) ?> active</span>
            </div>
            <p class="text-[12px] text-slate-500 mt-0.5">Add agents, set designations, and assign them from Leads.</p>
          </div>
          <a href="/client/leads" class="text-[12px] font-semibold text-[#1FA855] hover:underline shrink-0">Open Leads →</a>
        </div>
      </div>

      <details class="iqp-team-roles-details group border-b border-slate-100">
        <summary class="px-4 sm:px-6 py-3 cursor-pointer text-[12.5px] font-semibold text-slate-600 hover:bg-slate-50 flex items-center justify-between gap-2 list-none">
          <span>Role permissions</span>
          <svg class="w-4 h-4 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="px-4 sm:px-6 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
          <?php foreach ($teamDesignationKeys as $dKey):
              $dMeta = $teamDesignations[$dKey] ?? [];
          ?>
          <div class="iqp-team-role-chip">
            <span class="iqp-team-role-badge iqp-team-role-badge--<?= sanitize($dKey) ?>"><?= sanitize((string) ($dMeta['label'] ?? $dKey)) ?></span>
            <span class="text-[11px] text-slate-500 leading-snug"><?= sanitize((string) ($dMeta['summary'] ?? '')) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </details>

      <div class="px-4 sm:px-6 py-4 border-b border-slate-100 bg-slate-50/60">
        <div class="text-[12px] font-semibold text-slate-700 mb-3">Add member</div>
        <form method="POST" id="teamAddForm" class="iqp-team-add-form">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="team_add"/>
          <div class="iqp-team-add-field">
            <label class="iqp-team-label">Name</label>
            <input name="name" required placeholder="Agent name" class="iqp-team-input"/>
          </div>
          <div class="iqp-team-add-field">
            <label class="iqp-team-label">Email</label>
            <input name="email" type="email" placeholder="Optional" class="iqp-team-input"/>
          </div>
          <div class="iqp-team-add-field iqp-team-add-field--designation">
            <label class="iqp-team-label">Designation</label>
            <select name="designation" id="teamAddDesignation" class="iqp-team-input iqp-team-select">
              <?php foreach ($teamDesignationKeys as $dKey): ?>
              <option value="<?= sanitize($dKey) ?>"<?= $dKey === 'agent' ? ' selected' : '' ?>><?= sanitize(team_designation_label($dKey)) ?></option>
              <?php endforeach; ?>
            </select>
            <p id="teamAddDesignationHint" class="iqp-team-hint"><?= sanitize(team_designation_summary('agent')) ?></p>
          </div>
          <div class="iqp-team-add-field iqp-team-add-field--color">
            <label class="iqp-team-label">Color</label>
            <input name="color" type="color" value="#6366f1" class="iqp-team-color-input"/>
          </div>
          <div class="iqp-team-add-field iqp-team-add-field--submit">
            <label class="iqp-team-label iqp-team-label--hidden" aria-hidden="true">&nbsp;</label>
            <button type="submit" class="iqp-team-btn iqp-team-btn--primary w-full">Add member</button>
          </div>
        </form>
      </div>

      <div class="px-4 sm:px-6 py-4">
        <?php if (empty($teamMembers)): ?>
        <div class="text-center py-10 px-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/50">
          <p class="text-[13px] text-slate-500">No team members yet.</p>
          <p class="text-[12px] text-slate-400 mt-1">Add someone above — they appear when assigning leads.</p>
        </div>
        <?php else: ?>
        <div class="iqp-team-list">
          <?php foreach ($teamMembers as $m):
              $mDesignation = team_designation_normalize((string) ($m['designation'] ?? 'agent'));
              $mColor = sanitize($m['color'] ?? '#6366f1');
          ?>
          <form method="POST" class="iqp-team-member-row">
            <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
            <input type="hidden" name="action" value="team_update"/>
            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>"/>
            <div class="iqp-team-member-main">
              <input name="color" type="color" value="<?= $mColor ?>" class="iqp-team-color-input iqp-team-color-input--sm" title="Badge color"/>
              <div class="iqp-team-member-fields">
                <input name="name" value="<?= sanitize($m['name']) ?>" required class="iqp-team-input iqp-team-input--name" placeholder="Name"/>
                <input name="email" type="email" value="<?= sanitize($m['email'] ?? '') ?>" placeholder="Email" class="iqp-team-input"/>
                <div class="iqp-team-member-meta">
                  <select name="designation" class="team-designation-select iqp-team-select iqp-team-select--inline">
                    <?php foreach ($teamDesignationKeys as $dKey): ?>
                    <option value="<?= sanitize($dKey) ?>"<?= $mDesignation === $dKey ? ' selected' : '' ?> data-summary="<?= sanitize(team_designation_summary($dKey)) ?>"><?= sanitize(team_designation_label($dKey)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="iqp-team-active-toggle">
                    <input type="checkbox" name="is_active" value="1"<?= !empty($m['is_active']) ? ' checked' : '' ?>/>
                    <span>Active</span>
                  </label>
                </div>
                <p class="team-designation-hint iqp-team-hint"><?= sanitize(team_designation_summary($mDesignation)) ?></p>
              </div>
            </div>
            <div class="iqp-team-member-actions">
              <button type="submit" class="iqp-team-btn iqp-team-btn--save">Save</button>
              <button type="submit" name="action" value="team_delete" class="iqp-team-btn iqp-team-btn--danger" onclick="return confirm('Remove this team member?')">Remove</button>
            </div>
          </form>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /content panels -->
</div><!-- /grid -->

<script>
// Password form AJAX
document.getElementById('password-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.target;
  const errEl = document.getElementById('password-flash-error');
  const okEl  = document.getElementById('password-flash-success');
  if (errEl) errEl.classList.add('hidden');
  if (okEl)  okEl.classList.add('hidden');
  try {
    const res  = await fetch('/api/change-password.php', {method:'POST', body: new FormData(form)});
    const data = await res.json();
    if (data.success) {
      if (okEl) { okEl.textContent = data.message||'Password updated.'; okEl.classList.remove('hidden'); }
      form.reset();
    } else {
      if (errEl) { errEl.textContent = data.message||'Could not update password.'; errEl.classList.remove('hidden'); }
    }
  } catch { if (errEl) { errEl.textContent = 'Network error.'; errEl.classList.remove('hidden'); } }
});

// Eye toggle for password fields
function togglePw(btn){
  const inp = btn.closest('.relative')?.querySelector('input');
  if (!inp) return;
  inp.type = inp.type==='password' ? 'text' : 'password';
}

// 2FA toggle visual
function update2fa(btn){
  const dot = document.getElementById('iqp2faDot');
  if (!dot) return;
  if (btn.dataset.on==='1') {
    btn.classList.add('bg-[#1FA855]'); btn.classList.remove('bg-slate-200');
    dot.style.right='2px'; dot.style.left='';
  } else {
    btn.classList.remove('bg-[#1FA855]'); btn.classList.add('bg-slate-200');
    dot.style.left='2px'; dot.style.right='';
  }
}

// ── Avatar photo preview (live before upload) ──────────────────────────────
function previewAvatar(input) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > 2 * 1024 * 1024) {
    alert('Image too large. Please use a file under 2MB.');
    input.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = function(e) {
    const preview  = document.getElementById('avatarPreview');
    const initials = document.getElementById('avatarInitials');
    const img      = document.getElementById('avatarImg');
    if (img) {
      img.src = e.target.result;
      img.classList.remove('hidden');
      img.style.position = 'absolute';
      img.style.inset     = '0';
      if (initials) initials.style.display = 'none';
    }
  };
  reader.readAsDataURL(file);
}

// Team designation hints
(function(){
  const summaries = <?= json_encode(array_map(static fn($k) => team_designation_summary($k), array_combine($teamDesignationKeys, $teamDesignationKeys)), JSON_UNESCAPED_UNICODE) ?>;
  const addSel = document.getElementById('teamAddDesignation');
  const addHint = document.getElementById('teamAddDesignationHint');
  if (addSel && addHint) {
    addSel.addEventListener('change', () => {
      addHint.textContent = summaries[addSel.value] || '';
    });
  }
  document.querySelectorAll('.team-designation-select').forEach(sel => {
    sel.addEventListener('change', () => {
      const hint = sel.closest('div')?.querySelector('.team-designation-hint');
      const opt = sel.options[sel.selectedIndex];
      if (hint && opt) hint.textContent = opt.dataset.summary || summaries[sel.value] || '';
    });
  });
})();
</script>
<script src="/assets/js/app.js"></script>
<?php iqp_user_end();
