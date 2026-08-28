<?php
$showGoogle = function_exists('google_oauth_configured') && google_oauth_configured();
$showFacebook = function_exists('facebook_oauth_configured') && facebook_oauth_configured();
$googleHref = $showGoogle ? '/api/auth/google-start.php?mode=' . rawurlencode($authMode ?? 'login') : '';
$facebookHref = $showFacebook ? '/api/auth/facebook-start.php?mode=' . rawurlencode($authMode ?? 'login') : '';
?>
<div class="iqp-auth-hero-side px-2">
  <div class="mb-6">
    <?= brand_logo_markup('brand-logo-img iqp-auth-brand-logo', 'light') ?>
  </div>
  <h1 class="text-[26px] sm:text-[30px] font-bold text-slate-800 leading-tight">Your Human Like AI Assistant for WhatsApp Business</h1>
  <p class="text-[14px] text-slate-500 mt-3 max-w-sm">Handle customer queries, automate conversations and grow your business 24/7.</p>
  <div class="mt-8 space-y-2.5 text-[13px] text-slate-600">
    <div class="flex items-center gap-2"><span class="text-emerald-500">✓</span>Reply Instantly 24/7</div>
    <div class="flex items-center gap-2"><span class="text-emerald-500">✓</span>Convert More Leads</div>
    <div class="flex items-center gap-2"><span class="text-emerald-500">✓</span>Scale Your Business</div>
  </div>
</div>
