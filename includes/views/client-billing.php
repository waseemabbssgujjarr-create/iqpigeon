<?php
/** @var array $user */
/** @var array $plans */
/** @var array $limits */
/** @var array $botCount */
/** @var int $monthChats */
/** @var string $billingGateway */
/** @var string $billingCurrency */
/** @var array $subStatus */
/** @var string $errorMsg */
/** @var int $userId */

$csrf = csrf_token();
$planKey = (string) ($user['subscription_plan'] ?? 'starter');
$invoices = [];
try {
    $invoices = db_fetch_all(
        'SELECT * FROM subscription_payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 12',
        'i',
        [$userId]
    );
} catch (Throwable $e) {
    $invoices = [];
}

iqp_user_begin($user, 'billing', ['title' => 'Billing']);
if ($errorMsg !== '') {
    iqp_flash($errorMsg, 'err');
}
if (!empty($_GET['canceled'])) {
    iqp_flash('Payment was canceled.', 'err');
}
if (!empty($_GET['success'])) {
    iqp_flash('Subscription updated.');
}
?>
<div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[22px] font-bold text-slate-800">Billing &amp; Subscription</h1>
    <p class="text-[14px] text-slate-500 mt-1">Choose the perfect plan for your business. Upgrade or downgrade anytime.</p>
  </div>
  <a href="/client/notifications" class="flex items-center gap-1.5 border border-slate-200 bg-white rounded-xl px-4 py-2.5 text-[13px] font-medium text-slate-600 hover:bg-slate-50 transition-all">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
    Need Help?
  </a>
</div>

<!-- Current Plan Summary -->
<details class="iqp-mobile-drawer iqp-billing-current mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white" open style="box-shadow:0 4px 16px rgba(16,24,40,.07)">
  <summary class="iqp-mobile-drawer__summary lg:hidden">
    <span class="iqp-mobile-drawer__summary-icon" style="background:linear-gradient(135deg,#16a34a 0%,#1FA855 100%)">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M2 20h20M5 20V8l7-6 7 6v12"/></svg>
    </span>
    <span class="iqp-mobile-drawer__summary-text">
      <span class="iqp-mobile-drawer__summary-label">Your Current Plan</span>
      <span class="iqp-mobile-drawer__summary-value"><?= sanitize(iqp_plan_label($planKey)) ?> · <?= sanitize($subStatus[0]) ?></span>
    </span>
    <svg class="iqp-mobile-drawer__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
  </summary>
  <div class="iqp-mobile-drawer__content">
  <div class="iqp-billing-current__band px-4 py-4 sm:px-6 sm:py-5 border-b border-emerald-100" style="background:linear-gradient(135deg,#ecfdf5 0%,#d1fae5 45%,#f0fdf4 100%)">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-start gap-3.5 min-w-0 flex-1">
        <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex items-center justify-center shrink-0 shadow-sm" style="background:linear-gradient(135deg,#16a34a 0%,#1FA855 100%)">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h20M5 20V8l7-6 7 6v12"/><rect x="9" y="14" width="6" height="6"/></svg>
        </div>
        <div class="min-w-0 flex-1">
          <div class="text-[11px] sm:text-[12px] font-semibold uppercase tracking-wide text-emerald-700/70">Your Current Plan</div>
          <div class="text-[18px] sm:text-[20px] font-bold text-emerald-800 flex flex-wrap items-center gap-2 mt-1">
            <?= sanitize(iqp_plan_label($planKey)) ?>
            <span class="text-[11px] font-bold bg-white/80 text-emerald-700 px-2.5 py-0.5 rounded-full border border-emerald-200 shadow-sm"><?= sanitize($subStatus[0]) ?></span>
          </div>
          <div class="text-[12px] sm:text-[13px] text-emerald-800/60 mt-1.5 break-words"><?= sanitize(payment_gateway_label($billingGateway)) ?></div>
        </div>
      </div>
      <?php if (!empty($user['stripe_subscription_id']) && ($user['subscription_status'] ?? '') !== 'canceled'): ?>
      <form method="POST" onsubmit="return confirm('Cancel subscription at period end?')" class="shrink-0">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="cancel"/>
        <button class="flex items-center gap-1.5 bg-white/90 border border-emerald-200 rounded-xl px-3 py-2 text-[12px] font-semibold text-emerald-800 hover:bg-white transition-all shadow-sm">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9 9h6v6H9z"/></svg>
          Manage
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="iqp-billing-current__body p-4 sm:p-6">
    <div class="iqp-billing-current__stats grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div class="iqp-billing-stat rounded-xl border border-slate-100 bg-slate-50/90 p-3.5 sm:p-4">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#DBEAFE;color:#2563EB">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/></svg>
          </span>
          <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Bots</span>
        </div>
        <div class="text-[18px] sm:text-[20px] font-bold text-slate-800 leading-none">
          <?= (int)($botCount['cnt'] ?? 0) ?><span class="text-slate-400 font-medium text-[14px]"> / <?= $limits['bots'] === PHP_INT_MAX ? '∞' : (int)$limits['bots'] ?></span>
        </div>
      </div>
      <div class="iqp-billing-stat rounded-xl border border-slate-100 bg-slate-50/90 p-3.5 sm:p-4">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#DCFCE7;color:#16A34A">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </span>
          <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Chats</span>
        </div>
        <div class="text-[18px] sm:text-[20px] font-bold text-slate-800 leading-none">
          <?= number_format($monthChats) ?><span class="text-slate-400 font-medium text-[14px]"> / <?= $limits['chats'] === PHP_INT_MAX ? '∞' : number_format((int)$limits['chats']) ?></span>
        </div>
        <div class="text-[10px] text-slate-400 mt-1">this month</div>
      </div>
      <?php if (!empty($user['subscription_expires_at'])): ?>
      <div class="iqp-billing-stat rounded-xl border border-slate-100 bg-slate-50/90 p-3.5 sm:p-4 col-span-2 sm:col-span-1">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#F3E8FF;color:#7C3AED">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </span>
          <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Next billing</span>
        </div>
        <div class="text-[18px] sm:text-[20px] font-bold text-slate-800 leading-none"><?= sanitize(date('d M, Y', strtotime((string)$user['subscription_expires_at']))) ?></div>
      </div>
      <?php elseif (!empty($user['trial_ends_at'])): ?>
      <div class="iqp-billing-stat rounded-xl border border-slate-100 bg-slate-50/90 p-3.5 sm:p-4 col-span-2 sm:col-span-1">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#FFEDD5;color:#EA580C">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </span>
          <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Trial ends</span>
        </div>
        <div class="text-[18px] sm:text-[20px] font-bold text-slate-800 leading-none"><?= sanitize(date('d M, Y', strtotime((string)$user['trial_ends_at']))) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  </div>
</details>

<div class="mb-6">
  <div class="text-[18px] font-bold text-slate-800">Choose Your Plan</div>
  <div class="text-[13.5px] text-slate-400 mt-0.5">All plans include 24/7 AI Assistant, WhatsApp integration and smart automation.</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 lg:gap-5 mb-6">
<?php
$i = 0;
foreach ($plans as $key => $plan):
    $i++;
    $isCurrent = $planKey === $key || ($planKey === 'growth' && $key === 'pro');
    $popular = !empty($plan['popular']);
    $contact = !empty($plan['contact_only']);
    $priceLabel = $contact ? 'Custom' : sanitize(format_plan_price($plan['display_price'] ?? null, $billingCurrency));
    $border = $popular ? 'border-2 border-[#1FA855]' : 'border border-slate-200';
?>
  <div class="relative bg-white rounded-xl <?= $border ?> p-5" style="min-height:320px;display:flex;flex-direction:column">
    <?php if ($popular): ?>
    <span class="absolute -top-3.5 right-4 bg-[#1FA855] text-white text-[11px] font-bold px-3 py-1 rounded-full" style="box-shadow:0 3px 10px rgba(31,168,85,.35)">Most Popular</span>
    <?php endif; ?>
    <div class="text-[16px] font-bold text-slate-800"><?= sanitize((string) $plan['name']) ?></div>
    <div class="text-[12.5px] text-slate-400 mb-3"><?= $contact ? 'for larger teams' : 'for growing businesses' ?></div>
    <div class="text-[26px] font-extrabold <?= $contact ? '' : 'text-slate-800' ?> mb-4 <?= $contact ? 'text-violet-600' : '' ?>"><?= $priceLabel ?><?php if (!$contact): ?> <span class="text-[13px] font-normal text-slate-400">/ month</span><?php endif; ?></div>
    <?php if ($contact): ?>
      <a href="/contact"
        class="plan-card-btn"
        style="border:2px solid #7C3AED;color:#7C3AED;background:transparent;text-decoration:none">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12"/></svg>
        Contact Sales
      </a>
    <?php elseif ($isCurrent): ?>
      <button type="button" class="plan-card-btn" disabled
        style="background:#F1F5F9;color:#64748b;cursor:not-allowed;border:1.5px solid #E2E8F0">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Current Plan
      </button>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="checkout"/>
        <input type="hidden" name="plan" value="<?= sanitize($key) ?>"/>
        <button class="plan-card-btn" style="background:#1FA855;color:#fff;border:1.5px solid #1FA855;box-shadow:0 4px 14px rgba(31,168,85,.30)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="margin-right:6px"><path d="m5 12 5 5L20 7"/></svg>
          <?= $planKey === 'starter' ? 'Upgrade to ' : 'Switch to ' ?><?= sanitize((string) $plan['name']) ?>
        </button>
      </form>
    <?php endif; ?>
    <div class="mt-4 space-y-0.5">
      <?php
      $feats = [];
      if (!empty($plan['bots'])) {
          $feats[] = (string) $plan['bots'] . ' bot' . ((int) ($plan['bots'] ?? 1) === 1 ? '' : 's');
      }
      if (isset($plan['chats'])) {
          $chatLimit = is_numeric($plan['chats']) ? number_format((int) $plan['chats']) : (string) $plan['chats'];
          $feats[] = $chatLimit . ' conversations / month';
      }
      $feats[] = 'WhatsApp Integration';
      $feats[] = 'AI Assistant 24/7';
      foreach ($feats as $f):
      ?>
      <div class="flex items-center gap-2 text-[13px] text-slate-600 py-1.5">
        <span class="w-5 h-5 rounded-full flex items-center justify-center shrink-0" style="background:#DCFCE7">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </span>
        <?= sanitize($f) ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="bg-white rounded-2xl border border-slate-200 p-5" style="box-shadow:0 2px 8px rgba(16,24,40,.06)">
  <div class="flex items-center justify-between mb-4">
    <div>
      <div class="text-[16px] font-bold text-slate-800">Billing History</div>
      <div class="text-[13px] text-slate-400 mt-0.5">View your recent transactions and invoices.</div>
    </div>
  </div>
  <?php if ($invoices === []): ?>
    <div class="flex flex-col items-center py-8 text-center">
      <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mb-3">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 7h6M9 11h6M9 15h4"/></svg>
      </div>
      <div class="text-[14px] text-slate-500">No invoices yet.</div>
    </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
      <thead>
        <tr style="background:#F8FAFC">
          <th style="text-align:left;padding:10px 14px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #E4E7EC">Date</th>
          <th style="text-align:left;padding:10px 14px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #E4E7EC">Plan</th>
          <th style="text-align:left;padding:10px 14px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #E4E7EC">Amount</th>
          <th style="text-align:left;padding:10px 14px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #E4E7EC">Status</th>
          <th style="text-align:left;padding:10px 14px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #E4E7EC">Reference</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($invoices as $inv):
          $st = strtolower((string)($inv['status'] ?? 'pending'));
          $ok = $st === 'paid';
      ?>
      <tr style="border-bottom:1px solid #F1F5F9;transition:background .12s" onmouseover="this.style.background='#F8FAFC'" onmouseout="this.style.background=''">
        <td style="padding:13px 14px;color:#64748b;white-space:nowrap"><?= sanitize(date('d M, Y', strtotime((string)($inv['created_at'] ?? 'now')))) ?></td>
        <td style="padding:13px 14px;font-weight:600;color:#1e293b"><?= sanitize(ucfirst((string)($inv['plan'] ?? $inv['plan_slug'] ?? $planKey))) ?> Plan</td>
        <td style="padding:13px 14px;font-weight:700;color:#1e293b"><?= sanitize(isset($inv['amount']) ? format_money((float)$inv['amount'], $billingCurrency) : '—') ?></td>
        <td style="padding:13px 14px">
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;<?= $ok ? 'background:#DCFCE7;color:#15803D' : 'background:#F1F5F9;color:#64748b' ?>">
            <span style="width:6px;height:6px;border-radius:50%;background:currentColor"></span>
            <?= sanitize(ucfirst($st)) ?>
          </span>
        </td>
        <td style="padding:13px 14px;color:#2563EB;font-size:13px"><?= sanitize((string)($inv['order_ref'] ?? ('INV-'.$inv['id']))) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php
iqp_user_end();
