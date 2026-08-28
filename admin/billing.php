<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/billing-settings.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/paypak.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';

$user = require_admin();

$message = '';
$error = '';
$billing = get_billing_settings();
$trialDays = get_setting('trial_days', (string) TRIAL_DAYS);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'billing';

    if ($action === 'billing') {
        save_billing_settings([
            'stripe_enabled'             => !empty($_POST['stripe_enabled']),
            'paypak_enabled'             => !empty($_POST['paypak_enabled']),
            'default_currency'           => $_POST['default_currency'] ?? 'auto',
            'client_billing_note'        => $_POST['client_billing_note'] ?? '',
            'whatsapp_billing_note'      => $_POST['whatsapp_billing_note'] ?? '',
            'block_whatsapp_when_unpaid' => !empty($_POST['block_whatsapp_when_unpaid']),
        ]);

        $trial = max(1, min(90, (int) ($_POST['trial_days'] ?? TRIAL_DAYS)));
        set_setting('trial_days', (string) $trial);
        $trialDays = (string) $trial;

        $billing = get_billing_settings();
        $message = 'Billing settings saved.';
    }
}

try {
    $stats = billing_admin_stats();
} catch (Throwable $e) {
    $stats = [
        'mrr_usd' => 0.0, 'active_paid' => 0, 'trialing' => 0, 'past_due' => 0,
        'payments_month' => 0, 'revenue_month' => 0.0, 'whatsapp_connected' => 0,
        'recent_payments' => [], 'by_plan' => [],
    ];
}

$stripeOk = stripe_configured();
$stripeWebhookOk = stripe_webhook_configured();
$paypakOk = paypak_configured();
$paypakSandbox = defined('PAYPAK_SANDBOX') && PAYPAK_SANDBOX;

iqp_admin_begin($user, 'billing', [
    'title' => 'Billing',
    'subtitle' => 'Payment gateways, trial policy and revenue at a glance',
    'actions' => '<a class="btn btn--ghost btn--sm" href="/admin/subscriptions">Subscriptions</a><a class="btn btn--ghost btn--sm" href="/admin/plans">Plans &amp; pricing</a><a class="btn btn--ghost btn--sm" href="/admin/integrations">Integrations</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:20px">
  <div class="stat-card"><div class="stat-card__label">Est. MRR (USD)</div><div class="stat-card__value">$<?= number_format((float) $stats['mrr_usd'], 0) ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Active paid</div><div class="stat-card__value"><?= (int) $stats['active_paid'] ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Trialing</div><div class="stat-card__value"><?= (int) $stats['trialing'] ?></div></div>
  <div class="stat-card"><div class="stat-card__label">WhatsApp connected</div><div class="stat-card__value"><?= (int) $stats['whatsapp_connected'] ?></div></div>
</div>

<div class="grid grid-2" style="margin-bottom:20px">
  <div class="card">
    <div class="card__head"><span class="card__title">Payment gateways</span></div>
    <div class="card__body">
      <div class="between" style="padding:10px 0;border-bottom:1px solid var(--line-2)">
        <span>Stripe <span class="muted small">USD</span></span>
        <span class="badge <?= $stripeOk ? 'badge--green' : 'badge--red' ?>"><span class="dot"></span><?= $stripeOk ? 'Configured' : 'Missing keys' ?></span>
      </div>
      <div class="between" style="padding:10px 0;border-bottom:1px solid var(--line-2)">
        <span>Stripe webhook</span>
        <span class="badge <?= $stripeWebhookOk ? 'badge--green' : 'badge--gray' ?>"><?= $stripeWebhookOk ? 'Set' : 'Not set' ?></span>
      </div>
      <div class="between" style="padding:10px 0">
        <span>PayPak <span class="muted small">PKR</span></span>
        <span class="badge <?= $paypakOk ? ($paypakSandbox ? 'badge--amber' : 'badge--green') : 'badge--red' ?>"><span class="dot"></span><?= $paypakOk ? ($paypakSandbox ? 'Sandbox' : 'Live') : 'Missing keys' ?></span>
      </div>
      <p class="small" style="margin-bottom:0"><a class="view-all" href="/admin/integrations">Configure gateways &rarr;</a></p>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">This month</span></div>
    <div class="card__body">
      <p class="small" style="margin-top:0"><span class="strong"><?= (int) $stats['payments_month'] ?></span> successful payments</p>
      <p class="small">Recorded revenue: <span class="strong"><?= number_format((float) $stats['revenue_month'], 2) ?></span> <span class="muted">(mixed PKR / USD)</span></p>
      <?php if (!empty($stats['by_plan'])): ?>
      <div class="divider"></div>
      <div class="list">
        <?php foreach ($stats['by_plan'] as $row): ?>
        <div class="between" style="padding:6px 0">
          <span><?= sanitize(ucfirst((string) ($row['subscription_plan'] ?? ''))) ?></span>
          <span class="muted small"><?= (int) ($row['cnt'] ?? 0) ?> clients</span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ((int) $stats['past_due'] > 0): ?>
      <p class="small" style="margin-bottom:0;color:var(--red-600)"><span class="ic" data-ic="alert"></span> <?= (int) $stats['past_due'] ?> client(s) past due</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post">
  <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
  <input type="hidden" name="action" value="billing"/>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head"><span class="card__title">Gateway visibility</span></div>
    <div class="card__body">
      <div class="between" style="padding:10px 0;border-bottom:1px solid var(--line-2)">
        <span>Enable Stripe <span class="muted small">international / USD</span></span>
        <label class="switch"><input type="checkbox" name="stripe_enabled" value="1" <?= !empty($billing['stripe_enabled']) ? 'checked' : '' ?>/><span class="track"></span></label>
      </div>
      <div class="between" style="padding:10px 0;border-bottom:1px solid var(--line-2)">
        <span>Enable PayPak <span class="muted small">Pakistan / PKR</span></span>
        <label class="switch"><input type="checkbox" name="paypak_enabled" value="1" <?= !empty($billing['paypak_enabled']) ? 'checked' : '' ?>/><span class="track"></span></label>
      </div>
      <div class="field" style="margin-top:16px;margin-bottom:0;max-width:420px">
        <label>Default currency mode</label>
        <select name="default_currency" class="select">
          <option value="auto" <?= ($billing['default_currency'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto (PKR for Pakistan IP, USD elsewhere)</option>
          <option value="USD" <?= ($billing['default_currency'] ?? '') === 'USD' ? 'selected' : '' ?>>Force USD / Stripe</option>
          <option value="PKR" <?= ($billing['default_currency'] ?? '') === 'PKR' ? 'selected' : '' ?>>Force PKR / PayPak</option>
        </select>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head"><span class="card__title">Trial &amp; enforcement</span></div>
    <div class="card__body">
      <div class="field" style="max-width:220px">
        <label>Trial days <span class="hint">new clients</span></label>
        <input class="input" type="number" name="trial_days" min="1" max="90" value="<?= sanitize($trialDays) ?>"/>
      </div>
      <div class="between" style="padding:10px 0">
        <span class="grow">Block WhatsApp outbound when subscription is inactive or the trial has expired <span class="muted small">protects your Meta bill</span></span>
        <label class="switch"><input type="checkbox" name="block_whatsapp_when_unpaid" value="1" <?= !empty($billing['block_whatsapp_when_unpaid']) ? 'checked' : '' ?>/><span class="track"></span></label>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head"><span class="card__title">Client-facing copy</span></div>
    <div class="card__body">
      <div class="field">
        <label>Billing page note</label>
        <textarea class="textarea" name="client_billing_note" rows="3"><?= sanitize($billing['client_billing_note'] ?? '') ?></textarea>
        <span class="hint">Shown on the client billing page.</span>
      </div>
      <div class="field" style="margin-bottom:0">
        <label>WhatsApp billing note</label>
        <textarea class="textarea" name="whatsapp_billing_note" rows="3"><?= sanitize($billing['whatsapp_billing_note'] ?? '') ?></textarea>
        <span class="hint">Shown on WhatsApp settings as a subscription reminder.</span>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn--primary"><span class="ic" data-ic="check"></span>Save billing settings</button>
</form>

<div class="card" style="margin-top:24px">
  <div class="card__head"><span class="card__title">Recent payments</span></div>
  <div class="table-wrap"><table class="tbl">
    <thead>
      <tr><th>Client</th><th>Plan</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Date</th></tr>
    </thead>
    <tbody>
      <?php if (empty($stats['recent_payments'])): ?>
      <tr><td colspan="6" class="muted">No payments recorded yet.</td></tr>
      <?php else: foreach ($stats['recent_payments'] as $p):
          $ok = ($p['status'] ?? '') === 'paid';
      ?>
      <tr>
        <td>
          <div class="strong"><?= sanitize(($p['company_name'] ?? '') ?: ($p['name'] ?? '')) ?></div>
          <div class="muted small"><?= sanitize((string) ($p['email'] ?? '')) ?></div>
        </td>
        <td><?= sanitize(ucfirst((string) ($p['plan'] ?? ''))) ?></td>
        <td class="nowrap"><?= sanitize(strtoupper((string) ($p['gateway'] ?? '—'))) ?></td>
        <td class="strong nowrap"><?= sanitize((string) ($p['currency'] ?? '')) ?> <?= number_format((float) ($p['amount'] ?? 0), 2) ?></td>
        <td><span class="badge <?= $ok ? 'badge--green' : 'badge--gray' ?>"><?= sanitize(ucfirst((string) ($p['status'] ?? ''))) ?></span></td>
        <td class="muted nowrap"><?= sanitize(format_date(($p['paid_at'] ?? null) ?: ($p['created_at'] ?? null))) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php iqp_admin_end();
