<?php
/**
 * Admin — System Settings (redesigned to match screenshot)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/google-oauth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

$panels = ['gen', 'flags', 'sec', 'wf', 'comp', 'notif', 'ban', 'idef'];

$boolGet = static function (string $key, bool $default = false): bool {
    return get_setting($key, $default ? '1' : '0') === '1';
};

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $back   = 'gen';
    try {
        switch ($action) {
            case 'features':
                save_client_feature_flags([
                    'broadcasts'    => !empty($_POST['feature_broadcasts']),
                    'cart_recovery' => !empty($_POST['feature_cart_recovery']),
                    'courier'       => !empty($_POST['feature_courier']),
                    'team'          => !empty($_POST['feature_team']),
                ]);
                $back = 'flags';
                break;
            case 'general':
                set_setting('platform_name',        trim((string)($_POST['platform_name']        ?? '')));
                set_setting('support_email',        trim((string)($_POST['support_email']        ?? '')));
                set_setting('platform_timezone',    trim((string)($_POST['platform_timezone']    ?? '')));
                set_setting('platform_language',    trim((string)($_POST['platform_language']    ?? '')));
                set_setting('platform_currency',    trim((string)($_POST['platform_currency']    ?? '')));
                set_setting('platform_date_format', trim((string)($_POST['platform_date_format'] ?? '')));
                foreach (['maintenance_mode', 'allow_signups', 'debug_logging'] as $k) {
                    set_setting($k, !empty($_POST[$k]) ? '1' : '0');
                }
                $back = 'gen';
                break;
            case 'security':
                foreach (['require_email_verification', 'enforce_2fa'] as $k) {
                    set_setting($k, !empty($_POST[$k]) ? '1' : '0');
                }
                set_setting('session_timeout_minutes', (string)max(5,  min(1440, (int)($_POST['session_timeout_minutes'] ?? 60))));
                set_setting('min_password_length',     (string)max(6,  min(64,   (int)($_POST['min_password_length']     ?? 8))));
                $back = 'sec';
                break;
            case 'workflows':
                foreach (['wf_auto_assign', 'wf_auto_close_idle'] as $k) {
                    set_setting($k, !empty($_POST[$k]) ? '1' : '0');
                }
                set_setting('wf_sla_first_response_min', (string)max(1, min(1440, (int)($_POST['wf_sla_first_response_min'] ?? 5))));
                $back = 'wf';
                break;
            case 'compliance':
                foreach (['comp_gdpr_export', 'comp_right_to_forget'] as $k) {
                    set_setting($k, !empty($_POST[$k]) ? '1' : '0');
                }
                set_setting('comp_data_retention_days', (string)max(30, min(3650, (int)($_POST['comp_data_retention_days'] ?? 365))));
                $back = 'comp';
                break;
            case 'notifications':
                foreach (['notif_email', 'notif_slack'] as $k) {
                    set_setting($k, !empty($_POST[$k]) ? '1' : '0');
                }
                set_setting('notif_alert_recipients', trim((string)($_POST['notif_alert_recipients'] ?? '')));
                $back = 'notif';
                break;
            case 'banned_add':
                $word     = trim((string)($_POST['word'] ?? ''));
                $severity = in_array($_POST['severity'] ?? '', ['low','medium','high'], true) ? $_POST['severity'] : 'medium';
                $act      = in_array($_POST['ban_action'] ?? '', ['block','flag'], true) ? $_POST['ban_action'] : 'flag';
                if ($word !== '') {
                    $list = json_decode((string)get_setting('banned_words', '[]'), true);
                    if (!is_array($list)) $list = [];
                    $list[] = ['word' => mb_substr($word,0,100), 'severity' => $severity, 'action' => $act, 'added' => date('d M, Y')];
                    set_setting('banned_words', json_encode(array_values($list)));
                }
                $back = 'ban';
                break;
            case 'banned_del':
                $idx  = (int)($_POST['idx'] ?? -1);
                $list = json_decode((string)get_setting('banned_words', '[]'), true);
                if (is_array($list) && isset($list[$idx])) {
                    array_splice($list, $idx, 1);
                    set_setting('banned_words', json_encode(array_values($list)));
                }
                $back = 'ban';
                break;
        }
        redirect('/admin/settings?tab=' . $back . '&saved=1');
    } catch (Throwable $e) {
        $error = 'Could not save settings. Please try again.';
    }
}

$activePanel = (isset($_GET['tab']) && in_array($_GET['tab'], $panels, true)) ? $_GET['tab'] : 'gen';
if (!empty($_GET['saved'])) $message = 'Settings saved.';

/* ---- reads ---- */
$platformName    = get_setting('platform_name',        defined('APP_NAME') ? APP_NAME : 'IQPigeon');
$supportEmail    = get_setting('support_email',        defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '');
$timezone        = get_setting('platform_timezone',    'Asia/Karachi');
$language        = get_setting('platform_language',    'English');
$currency        = get_setting('platform_currency',    'PKR');
$dateFormat      = get_setting('platform_date_format', 'DD MMM, YYYY');
$featureFlags    = get_client_feature_flags();
$mailReady       = mail_transport_ready();
$mailDomain      = function_exists('mail_domain_from_address') && defined('SMTP_FROM') ? mail_domain_from_address(SMTP_FROM) : '';
$googleReady     = function_exists('google_oauth_configured') && google_oauth_configured();
$googleRedirect  = function_exists('google_oauth_redirect_uri') ? google_oauth_redirect_uri()
    : rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/auth/google-callback.php';
$sessionTimeout  = get_setting('session_timeout_minutes', '60');
$minPassLen      = get_setting('min_password_length',     '8');
$slaFirst        = get_setting('wf_sla_first_response_min', '5');
$dataRetention   = get_setting('comp_data_retention_days',  '365');
$alertRecipients = get_setting('notif_alert_recipients',    $supportEmail);
$aiModel         = get_setting('ai_model', defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini');
$trialDays       = get_setting('trial_days', defined('TRIAL_DAYS') ? (string)TRIAL_DAYS : '7');
$bannedWords     = json_decode((string)get_setting('banned_words', '[]'), true);
if (!is_array($bannedWords)) $bannedWords = [];
$banMeta = ['high' => 'badge--red', 'medium' => 'badge--amber', 'low' => 'badge--gray'];

$csrf = csrf_token();
$sw   = static fn(bool $on): string => $on ? ' checked' : '';

iqp_admin_begin($user, 'settings', [
    'title'    => 'System Settings',
    'subtitle' => 'Configure global platform settings, features and operational rules.',
    'actions'  => '<div style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--muted)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Last updated: ' . date('M j, Y') . '</div>',
]);
iqp_flash($message);
if ($error !== '') iqp_flash($error, 'err');
?>
<!-- Tab navigation -->
<?php
$settingsTabs = [];
foreach (['gen'=>'General Settings','flags'=>'Feature Flags','sec'=>'Verification &amp; Security','wf'=>'Workflows','comp'=>'Compliance','notif'=>'Notifications','ban'=>'Banned Words','idef'=>'Integrations Defaults'] as $tid => $tlabel) {
    $settingsTabs[] = ['id' => $tid, 'label' => $tlabel, 'href' => '/admin/settings?tab=' . $tid];
}
iqp_tab_nav($activePanel, $settingsTabs, ['variant' => 'admin', 'select_label' => 'Settings section']);
?>

<!-- 2-col: main + right rail -->
<div class="grid" style="grid-template-columns:minmax(0,1fr) 280px;gap:18px;align-items:start">
<div class="stack">

<?php if ($activePanel === 'gen'): ?>
<form method="POST" class="iqp-form-stack">
  <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
  <input type="hidden" name="action" value="general"/>
  <!-- Row 1: General + Trial & Onboarding + Default Plan -->
  <div class="grid grid-3">
    <div class="card">
      <div class="card__head"><span class="card__title" style="font-size:14px">General Settings</span></div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Configure basic platform-wide settings.</div>
        <div class="field"><label class="form-label">Platform Name</label><input class="input" name="platform_name" value="<?= sanitize($platformName) ?>"/></div>
        <div class="field"><label class="form-label">Support Email</label><input class="input" type="email" name="support_email" value="<?= sanitize($supportEmail) ?>"/></div>
        <div class="field"><label class="form-label">Default Language</label>
          <select class="select" name="platform_language">
            <?php foreach (['English','Urdu','Arabic','French'] as $l): ?>
            <option<?= $language===$l?' selected':'' ?>><?= sanitize($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin:0"><label class="form-label">Default Timezone</label>
          <select class="select" name="platform_timezone">
            <option value="Asia/Karachi"<?= $timezone==='Asia/Karachi'?' selected':'' ?>>(GMT+05:00) Asia/Karachi</option>
            <option value="UTC"<?= $timezone==='UTC'?' selected':'' ?>>(GMT+00:00) UTC</option>
            <option value="America/New_York"<?= $timezone==='America/New_York'?' selected':'' ?>>(GMT-05:00) Eastern</option>
          </select>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card__head"><span class="card__title" style="font-size:14px">Trial &amp; Onboarding</span></div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Control trial experience and onboarding defaults.</div>
        <div class="field"><label class="form-label">Trial Duration (Days)</label>
          <input class="input" type="number" name="trial_duration" min="0" max="90" value="<?= sanitize($trialDays) ?>" style="max-width:80px"/>
        </div>
        <div class="field"><label class="form-label">Trial Plan</label>
          <select class="select" name="trial_plan"><option>Starter Plan</option><option>Pro Plan</option></select>
        </div>
        <div class="between" style="padding:10px 0;border-top:1px solid var(--line-2)">
          <div><div class="strong" style="font-size:13px">Enable Trial</div><div class="muted small">Allow new users to start with a trial</div></div>
          <label class="switch"><input type="checkbox" name="enable_trial" value="1" checked><span class="track"></span></label>
        </div>
        <div class="between" style="padding:10px 0;border-top:1px solid var(--line-2)">
          <div><div class="strong" style="font-size:13px">Require Email Verification</div><div class="muted small">Users must verify email before login</div></div>
          <label class="switch"><input type="checkbox" name="require_email_verification" value="1"<?= $sw($boolGet('require_email_verification', true)) ?>><span class="track"></span></label>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card__head"><span class="card__title" style="font-size:14px">Default Plan Settings</span></div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Set default plan for new sign-ups.</div>
        <div class="field"><label class="form-label">Default Plan</label>
          <select class="select" name="default_plan"><option>Starter Plan</option><option>Pro Plan</option><option>Trial</option></select>
        </div>
        <div class="between" style="padding:10px 0;border-top:1px solid var(--line-2)">
          <div><div class="strong" style="font-size:13px">Auto Assign Plan After Trial</div><div class="muted small">Automatically assign this plan when trial ends</div></div>
          <label class="switch"><input type="checkbox" name="auto_assign_plan" value="1" checked><span class="track"></span></label>
        </div>
        <div class="field" style="margin-top:10px"><label class="form-label">Grace Period After Trial (Days)</label>
          <input class="input" type="number" name="grace_period" min="0" max="30" value="3" style="max-width:80px"/>
          <div class="hint" style="font-size:11.5px;color:var(--muted-2);margin-top:4px">Extra days before restricting access</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Row 2 -->
  <div class="grid grid-3">
    <div class="card">
      <div class="card__head"><span class="card__title" style="font-size:14px">Payment &amp; Suspension</span></div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Configure payment reminders and auto-suspension.</div>
        <div class="field"><label class="form-label">Payment Reminder</label>
          <select class="select" name="payment_reminder"><option>3 days before due date</option><option>7 days before due date</option></select>
        </div>
        <div class="field"><label class="form-label">Auto Suspend Delay</label>
          <select class="select" name="auto_suspend_delay"><option>7 days after due date</option><option>3 days after due date</option></select>
        </div>
        <div class="between" style="padding:10px 0;border-top:1px solid var(--line-2)">
          <div><div class="strong" style="font-size:13px">Suspend on Failed Payment</div><div class="muted small">Automatically suspend on payment failure</div></div>
          <label class="switch"><input type="checkbox" name="suspend_failed" value="1" checked><span class="track"></span></label>
        </div>
        <div class="between" style="padding:10px 0;border-top:1px solid var(--line-2)">
          <div><div class="strong" style="font-size:13px">Send Suspension Email</div><div class="muted small">Notify users when account is suspended</div></div>
          <label class="switch"><input type="checkbox" name="suspend_email" value="1" checked><span class="track"></span></label>
        </div>
        <div class="between" style="padding:10px 0;border-top:1px solid var(--line-2)">
          <div><div class="strong" style="font-size:13px">Allow Access After Suspension</div><div class="muted small">Allow read-only access to data</div></div>
          <label class="switch"><input type="checkbox" name="access_after_suspend" value="1"><span class="track"></span></label>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card__head"><span class="card__title" style="font-size:14px">User &amp; Business Defaults</span></div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Set defaults for new Businesses and users.</div>
        <div class="field"><label class="form-label">Default Industry</label>
          <select class="select" name="default_industry">
            <option value="">Select industry</option>
            <?php foreach (['Restaurant / Food & Beverage','Retail / E-Commerce','Healthcare','Education','Real Estate'] as $ind): ?>
            <option><?= sanitize($ind) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label class="form-label">Default Timezone for Businesses</label>
          <select class="select" name="biz_timezone"><option value="Asia/Karachi">(GMT+05:00) Asia/Karachi</option><option value="UTC">(GMT+00:00) UTC</option></select>
        </div>
        <div class="field"><label class="form-label">Default Currency</label>
          <select class="select" name="platform_currency">
            <?php foreach (['PKR'=>'PKR (Pakistani Rupee)','USD'=>'USD (US Dollar)','EUR'=>'EUR (Euro)','AED'=>'AED (UAE Dirham)'] as $val=>$lbl): ?>
            <option value="<?= sanitize($val) ?>"<?= $currency===$val?' selected':'' ?>><?= sanitize($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin:0"><label class="form-label">Default Business Locale</label>
          <select class="select" name="biz_locale"><option>English (Pakistan)</option><option>English (US)</option><option>Urdu (Pakistan)</option></select>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card__head"><span class="card__title" style="font-size:14px">Email &amp; Communication</span></div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Configure global email and communication settings.</div>
        <div class="field"><label class="form-label">Email Provider</label>
          <select class="select" name="email_provider"><option>SMTP (Custom)</option><option>SendGrid</option><option>Mailgun</option></select>
        </div>
        <div class="field"><label class="form-label">From Email</label><input class="input" type="email" name="from_email" value="no-reply@iqpigeon.com"/></div>
        <div class="field"><label class="form-label">From Name</label><input class="input" name="from_name" value="<?= sanitize($platformName) ?>"/></div>
        <div class="field" style="margin-bottom:12px"><label class="form-label">Reply To Email</label><input class="input" type="email" name="reply_to" value="<?= sanitize($supportEmail) ?>"/></div>
        <button type="button" class="btn btn--ghost btn--sm btn--block" style="justify-content:center;gap:7px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.56 1.2h3a2 2 0 0 1 2 1.72c.12.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          Test Email Configuration
        </button>
      </div>
    </div>
  </div>

  <div class="iqp-form-actions">
    <button type="submit" class="btn btn--primary">Save Settings</button>
  </div>
</form>

<!-- Banned Words (shown on gen tab) -->
<div class="card">
  <div class="card__head iqp-toolbar iqp-toolbar--stack" style="gap:10px">
    <div><span class="card__title">Banned Words &amp; Content</span><div class="muted small" style="margin-top:2px">Manage words and phrases not allowed in conversations.</div></div>
    <span class="spacer"></span>
    <form method="POST" class="iqp-toolbar iqp-toolbar--stack" style="gap:8px">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="banned_add"/>
      <div class="input-icon" style="position:relative">
        <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--muted-2)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input class="input" placeholder="Search banned words..." style="padding-left:32px;font-size:13px;width:180px"/>
      </div>
      <input class="input" name="word" required maxlength="100" placeholder="Add word..." style="width:160px;font-size:13px"/>
      <select class="select" name="severity" style="width:auto;padding:9px 28px 9px 10px;font-size:13px"><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select>
      <button class="btn btn--primary btn--sm" type="submit" style="display:flex;align-items:center;gap:5px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>+ Add Word
      </button>
    </form>
  </div>
  <!-- category chips -->
  <div style="padding:10px 20px;border-bottom:1px solid var(--line-2);display:flex;flex-wrap:wrap;gap:6px">
    <?php $cats=['All (245)'=>true,'Profanity (100)'=>false,'Violence (45)'=>false,'Hate Speech (22)'=>false,'Spam (28)'=>false,'Drugs (19)'=>false,'Other (18)'=>false]; ?>
    <?php foreach ($cats as $clabel=>$active): ?>
    <button type="button" style="padding:4px 12px;border-radius:20px;border:1px solid var(--line);background:<?= $active?'var(--green-600)':'#fff' ?>;color:<?= $active?'#fff':'var(--muted)' ?>;font-size:12.5px;font-weight:500;cursor:pointer"><?= sanitize($clabel) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th style="width:36px"><input type="checkbox" style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer"/></th>
        <th>Word / Phrase</th><th>Category</th><th>Added By</th><th>Date Added</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if ($bannedWords === []): ?>
        <tr><td colspan="6" style="padding:24px;text-align:center;color:var(--muted)">No banned words yet. Add one above.</td></tr>
      <?php else: foreach ($bannedWords as $i => $bw):
          $sevKey = (string)($bw['severity'] ?? 'medium');
          $badge  = $banMeta[$sevKey] ?? 'badge--gray';
      ?>
        <tr>
          <td><input type="checkbox" style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer"/></td>
          <td class="strong"><?= sanitize((string)($bw['word'] ?? '')) ?></td>
          <td><span class="badge <?= $badge ?>"><?= sanitize(ucfirst($sevKey)) ?></span></td>
          <td class="muted">Admin User</td>
          <td class="muted"><?= sanitize((string)($bw['added'] ?? date('d M, Y'))) ?></td>
          <td><div class="row-actions">
            <a class="ic" href="#" title="Edit"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
            <form method="POST" onsubmit="return confirm('Remove?')" style="display:inline;margin:0">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="banned_del"/>
              <input type="hidden" name="idx" value="<?= (int)$i ?>"/>
              <button class="ic danger" type="submit" title="Delete"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- System Maintenance card -->
<div class="card">
  <div class="card__head"><span class="card__title">System Maintenance</span></div>
  <div class="card__body">
    <div class="muted small" style="margin-bottom:14px">Advanced system control options.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="general"/>
      <div class="grid" style="grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
        <div class="between" style="padding:12px 14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong" style="font-size:13px">Maintenance Mode</div><div class="muted small">Temporarily disable platform for maintenance</div></div>
          <label class="switch"><input type="checkbox" name="maintenance_mode" value="1"<?= $sw($boolGet('maintenance_mode', false)) ?>><span class="track"></span></label>
        </div>
        <div class="between" style="padding:12px 14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong" style="font-size:13px">Allow Admin Access</div><div class="muted small">Allow only admins while in maintenance mode</div></div>
          <label class="switch"><input type="checkbox" name="allow_admin_access" value="1" checked><span class="track"></span></label>
        </div>
      </div>
      <div style="background:var(--amber-50);border:1px solid var(--amber-100);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--amber-700);display:flex;align-items:center;gap:8px;margin-bottom:12px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Enabling maintenance mode will log out all users except administrators.
      </div>
      <button type="submit" class="btn btn--primary btn--sm">Save Maintenance Mode</button>
    </form>
  </div>
</div>

<?php elseif ($activePanel === 'flags'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Feature Flags</span></div>
  <div class="card__body">
    <p class="muted small" style="margin-bottom:16px">Toggle which modules appear in every business's portal.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="features"/>
      <div class="stack" style="gap:10px;max-width:680px">
        <?php foreach ([
            ['feature_broadcasts',   'Broadcasts',    'Bulk WhatsApp campaigns',           !empty($featureFlags['broadcasts'])],
            ['feature_cart_recovery','Cart Recovery', 'Abandoned-cart follow-ups',         !empty($featureFlags['cart_recovery'])],
            ['feature_courier',      'Courier',       'Shipping & courier integrations',   !empty($featureFlags['courier'])],
            ['feature_team',         'Team',          'Multi-seat team members',           !empty($featureFlags['team'])],
        ] as [$fname,$ftitle,$fsub,$fon]): ?>
        <div class="between" style="padding:14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong"><?= sanitize($ftitle) ?></div><div class="muted small"><?= sanitize($fsub) ?></div></div>
          <label class="switch"><input type="checkbox" name="<?= $fname ?>" value="1"<?= $sw($fon) ?>><span class="track"></span></label>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn--primary" style="margin-top:16px" type="submit">Save Feature Visibility</button>
    </form>
  </div>
</div>

<?php elseif ($activePanel === 'sec'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Verification &amp; Security</span></div>
  <div class="card__body">
    <div class="grid grid-2" style="margin-bottom:14px">
      <div style="padding:12px;border:1px solid var(--line-2);border-radius:10px">
        <div class="eyebrow" style="margin-bottom:4px">Google Sign-In</div>
        <span class="badge <?= $googleReady?'badge--green':'badge--gray' ?>"><span class="dot"></span><?= $googleReady?'Connected':'Not set' ?></span>
        <?php if ($googleRedirect !== ''): ?><div class="muted small" style="margin-top:6px;word-break:break-all;font-size:11.5px"><?= sanitize($googleRedirect) ?></div><?php endif; ?>
      </div>
      <div style="padding:12px;border:1px solid var(--line-2);border-radius:10px">
        <div class="eyebrow" style="margin-bottom:4px">Mail Transport</div>
        <span class="badge <?= $mailReady?'badge--green':'badge--amber' ?>"><span class="dot"></span><?= $mailReady?'Ready':'Setup needed' ?></span>
        <?php if ($mailDomain !== ''): ?><div class="muted small" style="margin-top:6px">Domain: <?= sanitize($mailDomain) ?></div><?php endif; ?>
      </div>
    </div>
    <a class="btn btn--ghost btn--sm" href="/admin/integrations" style="margin-bottom:16px">Open Integrations</a>
    <div class="divider"></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="security"/>
      <div class="stack" style="gap:10px;max-width:680px">
        <?php foreach ([
            ['require_email_verification','Require Email Verification','Users must verify email before access',true],
            ['enforce_2fa','Enforce 2FA for Admins','All admin accounts require 2FA',false],
        ] as [$sname,$stitle,$ssub,$sdef]): ?>
        <div class="between" style="padding:14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong"><?= sanitize($stitle) ?></div><div class="muted small"><?= sanitize($ssub) ?></div></div>
          <label class="switch"><input type="checkbox" name="<?= $sname ?>" value="1"<?= $sw($boolGet($sname,$sdef)) ?>><span class="track"></span></label>
        </div>
        <?php endforeach; ?>
        <div class="grid grid-2">
          <div class="field"><label class="form-label">Session Timeout (min)</label><input class="input" type="number" name="session_timeout_minutes" min="5" max="1440" value="<?= sanitize($sessionTimeout) ?>"/></div>
          <div class="field"><label class="form-label">Min Password Length</label><input class="input" type="number" name="min_password_length" min="6" max="64" value="<?= sanitize($minPassLen) ?>"/></div>
        </div>
      </div>
      <button class="btn btn--primary" style="margin-top:16px" type="submit">Save Security</button>
    </form>
  </div>
</div>

<?php elseif ($activePanel === 'wf'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Workflows</span></div>
  <div class="card__body">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="workflows"/>
      <div class="stack" style="gap:10px;max-width:680px">
        <?php foreach ([['wf_auto_assign','Auto-assign New Leads','Round-robin across team members',true],['wf_auto_close_idle','Auto-close Idle Conversations','Close after 48h of inactivity',false]] as [$wn,$wt,$ws,$wd]): ?>
        <div class="between" style="padding:14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong"><?= sanitize($wt) ?></div><div class="muted small"><?= sanitize($ws) ?></div></div>
          <label class="switch"><input type="checkbox" name="<?= $wn ?>" value="1"<?= $sw($boolGet($wn,$wd)) ?>><span class="track"></span></label>
        </div>
        <?php endforeach; ?>
        <div class="field"><label class="form-label">SLA First-response Target (minutes)</label><input class="input" type="number" name="wf_sla_first_response_min" min="1" max="1440" value="<?= sanitize($slaFirst) ?>" style="max-width:160px"/></div>
      </div>
      <button class="btn btn--primary" style="margin-top:16px" type="submit">Save Workflows</button>
    </form>
  </div>
</div>

<?php elseif ($activePanel === 'comp'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Compliance</span></div>
  <div class="card__body">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="compliance"/>
      <div class="stack" style="gap:10px;max-width:680px">
        <?php foreach ([['comp_gdpr_export','GDPR Data Export','Allow users to export their data',true],['comp_right_to_forget','Right to be Forgotten','Enable account & data deletion requests',true]] as [$cn,$ct,$cs,$cd]): ?>
        <div class="between" style="padding:14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong"><?= sanitize($ct) ?></div><div class="muted small"><?= sanitize($cs) ?></div></div>
          <label class="switch"><input type="checkbox" name="<?= $cn ?>" value="1"<?= $sw($boolGet($cn,$cd)) ?>><span class="track"></span></label>
        </div>
        <?php endforeach; ?>
        <div class="field"><label class="form-label">Data Retention (days)</label><input class="input" type="number" name="comp_data_retention_days" min="30" max="3650" value="<?= sanitize($dataRetention) ?>" style="max-width:160px"/></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button class="btn btn--primary" type="submit">Save Compliance</button>
        <a class="btn btn--ghost" href="/admin/data-deletion-requests">Review Deletion Requests</a>
      </div>
    </form>
  </div>
</div>

<?php elseif ($activePanel === 'notif'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Notifications</span></div>
  <div class="card__body">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="notifications"/>
      <div class="stack" style="gap:10px;max-width:680px">
        <?php foreach ([['notif_email','Email Notifications','System alerts via email',true],['notif_slack','Slack Alerts','Post critical alerts to Slack',false]] as [$nn,$nt,$ns,$nd]): ?>
        <div class="between" style="padding:14px;border:1px solid var(--line-2);border-radius:10px">
          <div><div class="strong"><?= sanitize($nt) ?></div><div class="muted small"><?= sanitize($ns) ?></div></div>
          <label class="switch"><input type="checkbox" name="<?= $nn ?>" value="1"<?= $sw($boolGet($nn,$nd)) ?>><span class="track"></span></label>
        </div>
        <?php endforeach; ?>
        <div class="field"><label class="form-label">Alert Recipients</label><input class="input" name="notif_alert_recipients" value="<?= sanitize($alertRecipients) ?>" placeholder="ops@example.com"/></div>
      </div>
      <button class="btn btn--primary" style="margin-top:16px" type="submit">Save Notifications</button>
    </form>
  </div>
</div>

<?php elseif ($activePanel === 'ban'): ?>
<div class="card">
  <div class="card__head iqp-toolbar iqp-toolbar--stack" style="gap:10px">
    <div><span class="card__title">Banned Words &amp; Content</span></div>
    <span class="spacer"></span>
    <form method="POST" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="banned_add"/>
      <input class="input" name="word" required maxlength="100" placeholder="Add word or phrase..." style="width:200px"/>
      <select class="select" name="severity" style="width:auto;padding:9px 28px 9px 10px"><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select>
      <button class="btn btn--primary btn--sm" type="submit">+ Add Word</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Word / Phrase</th><th>Category</th><th>Added By</th><th>Date Added</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if ($bannedWords === []): ?>
        <tr><td colspan="5" style="padding:24px;text-align:center;color:var(--muted)">No banned words yet.</td></tr>
      <?php else: foreach ($bannedWords as $i => $bw):
          $badge = $banMeta[$bw['severity']??'medium'] ?? 'badge--gray';
      ?>
        <tr>
          <td class="strong"><?= sanitize((string)($bw['word']??'')) ?></td>
          <td><span class="badge <?= $badge ?>"><?= sanitize(ucfirst($bw['severity']??'medium')) ?></span></td>
          <td class="muted">Admin User</td>
          <td class="muted"><?= sanitize((string)($bw['added']??'')) ?></td>
          <td><div class="row-actions">
            <form method="POST" onsubmit="return confirm('Remove?')" style="display:inline;margin:0">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="banned_del"/>
              <input type="hidden" name="idx" value="<?= (int)$i ?>"/>
              <button class="ic danger" type="submit" title="Delete"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($activePanel === 'idef'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Integrations Defaults</span></div>
  <div class="card__body">
    <p class="muted small" style="margin-bottom:14px">These defaults are managed on their dedicated pages and shown here for reference.</p>
    <div class="grid grid-2" style="margin-bottom:14px">
      <div class="field"><label class="form-label">Default AI Model</label><input class="input" value="<?= sanitize($aiModel) ?>" disabled/></div>
      <div class="field"><label class="form-label">Default Trial Length (days)</label><input class="input" value="<?= sanitize($trialDays) ?>" disabled/></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn--ghost btn--sm" href="/admin/integrations">Manage AI &amp; Gateways</a>
      <a class="btn btn--ghost btn--sm" href="/admin/subscriptions">Manage Plans &amp; Trial</a>
      <a class="btn btn--ghost btn--sm" href="/admin/ai">Edit AI Base Prompt</a>
    </div>
  </div>
</div>
<?php endif; ?>
</div><!-- /stack -->

<!-- RIGHT RAIL -->
<div class="stack">
  <div class="card" style="position:sticky;top:16px">
    <div class="card__head"><span class="card__title">System Overview</span></div>
    <div class="card__body" style="display:flex;flex-direction:column;gap:8px">
      <div class="muted small" style="margin-bottom:4px">Key system configuration summary.</div>
      <?php foreach ([
        ['Trial Duration',    $trialDays . ' days'],
        ['Default Plan',      'Starter Plan'],
        ['Auto Suspend Delay','7 days'],
        ['Payment Reminder',  '3 days before'],
        ['Email Provider',    'SMTP (Custom)'],
        ['Banned Words',      count($bannedWords) . ' words'],
        ['Last Updated',      date('d M, Y g:i A')],
        ['Updated By',        (string)($user['name'] ?? 'Admin User')],
      ] as [$ok,$ov]): ?>
      <div class="between" style="padding:5px 0;border-bottom:1px solid var(--line-2)">
        <span class="muted small"><?= sanitize($ok) ?></span>
        <span class="strong small"><?= sanitize($ov) ?></span>
      </div>
      <?php endforeach; ?>
      <a href="/admin/audit" style="font-size:12.5px;color:var(--green-700);font-weight:600;margin-top:4px;display:flex;align-items:center;gap:5px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        View Change History
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Quick Actions</span></div>
    <div class="card__body iqp-action-grid">
      <?php foreach ([
        ['Clear System Cache',    'var(--green-600)', '<path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>'],
        ['Rebuild Search Index',  'var(--blue-600)',  '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'],
        ['Regenerate API Keys',   'var(--purple-600)','<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
        ['Backup Database',       'var(--amber-600)', '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'],
        ['System Maintenance Mode','var(--red-600)',  '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/>'],
      ] as [$qlabel,$qcolor,$qicon]): ?>
      <button type="button" class="btn btn--ghost btn--sm btn--block" style="justify-content:flex-start;gap:8px;font-size:12.5px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="<?= $qcolor ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $qicon ?></svg>
        <?= sanitize($qlabel) ?>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__body" style="text-align:center;padding:18px 16px">
      <div class="strong" style="font-size:13.5px;margin-bottom:4px">Need Help?</div>
      <div class="muted small" style="margin-bottom:12px">Read our system settings guide for detailed information.</div>
      <a href="/admin/settings?doc=1" class="btn btn--ghost btn--sm btn--block" style="justify-content:center;gap:6px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        View Settings Guide
      </a>
    </div>
  </div>
</div><!-- /right rail -->

</div><!-- /2-col grid -->
<?php iqp_admin_end();
