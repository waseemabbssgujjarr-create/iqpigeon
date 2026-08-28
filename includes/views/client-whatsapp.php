<?php
/** @var array $user */
/** @var array|null $account */
/** @var bool $isConnected */
/** @var bool $manualMode */
/** @var array|null $manualBot */
/** @var bool $manualConnected */
/** @var int $primaryBotId */
/** @var bool $flashSuccess */
/** @var string $flashError */
/** @var bool $waIsTestNumber */
/** @var array $waUsage */
/** @var array $billingSettings */
/** @var bool $waBillingBlocked */
/** @var array|null $inboundReady */
/** @var bool $tokenReadable */
/** @var array $messages */
/** @var int $clientId */
/** @var string $csrf */
/** @var string $flashMessage */
/** @var string $flashErr */

$bot = $primaryBotId > 0 ? db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$primaryBotId, $clientId]) : null;
$widgetEnabled = $bot ? (int) ($bot['widget_enabled'] ?? 0) === 1 : false;
$waAutoReply = $bot ? (int) ($bot['whatsapp_auto_reply'] ?? 1) === 1 : true;
$widgetColor = function_exists('normalize_widget_color') ? normalize_widget_color((string) ($bot['widget_color'] ?? ''), '#1FA855') : '#1FA855';
$widgetName = $bot && function_exists('get_widget_bot_name') ? get_widget_bot_name($bot) : APP_NAME;
$embed = '<script>window.SalesBotConfig = { botId: \'' . (int) $primaryBotId
    . '\', color: \'' . sanitize($widgetColor)
    . '\', apiBase: \'' . rtrim(APP_URL, '/')
    . '\', botName: \'' . sanitize((string) $widgetName)
    . '\', bottomOffset: 20 };</script>' . "\n"
    . '<script src="' . APP_URL . '/assets/js/chat-widget.js" async></script>';

$company = (string) ($user['company_name'] ?? $account['business_name'] ?? APP_NAME);
$phone = (string) ($account['phone_display_number'] ?? ($manualBot['whatsapp_phone_id'] ?? ''));

iqp_user_begin($user, 'whatsapp', ['title' => 'WhatsApp']);
if (!empty($waShowMetaSdk)):
?>
<div id="fb-root"></div>
<?php
    $meta_fb_sdk_skip_root = true;
    include __DIR__ . '/../includes/meta-fb-sdk.php';
endif;
if ($flashSuccess) {
    iqp_flash('WhatsApp connected successfully.');
}
if ($flashError !== '') {
    iqp_flash($flashError, 'err');
}
if ($flashMessage !== '') {
    iqp_flash($flashMessage);
}
if ($flashErr !== '') {
    iqp_flash($flashErr, 'err');
}
if ($waBillingBlocked) {
    iqp_flash('WhatsApp messaging paused until you renew. See Billing.', 'err');
}
?>
<div id="whatsapp-settings-root" data-client-id="<?= (int) $clientId ?>" data-bot-id="<?= (int) $primaryBotId ?>" data-csrf="<?= sanitize($csrf) ?>" hidden></div>
<div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-6">
  <div>
    <h1 class="text-[19px] lg:text-[22px] font-bold text-slate-800">WhatsApp & Website Widget</h1>
    <p class="text-[13px] lg:text-[13.5px] text-slate-500 mt-1">Connect WhatsApp Business, send test messages and add IQPigeon widget to your website.</p>
  </div>
  <a href="/client/notifications" class="border border-slate-200 bg-white rounded-lg px-3 py-2 text-[12px] font-medium text-slate-600 w-fit">Need Help?</a>
</div>

<div class="wa-page-grid mb-5">

  <!-- 1 · WhatsApp connection -->
  <section class="wa-panel bg-white rounded-xl border border-slate-200 p-4 sm:p-5 flex flex-col">
    <div class="flex items-start justify-between gap-3 mb-0.5">
      <div class="text-[14px] sm:text-[15px] font-bold text-slate-800">WhatsApp</div>
      <?php if ($isConnected || $manualConnected): ?>
      <div class="flex flex-col items-end gap-1 shrink-0">
        <span class="text-[10px] font-medium text-slate-500 uppercase tracking-wide">Auto-reply</span>
        <?= iqp_switch_control('whatsapp_auto_reply', $waAutoReply, 'WhatsApp auto-reply') ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-[11.5px] sm:text-[12px] text-slate-400 mb-3">Business connection<?= ($isConnected || $manualConnected) ? ' — turn auto-reply off to pause AI responses' : '' ?>.</div>
    <?php if ($isConnected || $manualConnected): ?>
    <div class="flex items-center gap-3 mb-3 p-3 rounded-xl wa-connected-banner">
      <?= iqp_whatsapp_logo_tile(32, 'wa-dash-logo wa-dash-logo--compact') ?>
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-1.5 mb-0.5">
          <span class="text-[13px] font-bold text-slate-800">Connected</span>
          <span class="wa-status-connected text-[10px]"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Active</span>
        </div>
        <div class="text-[12.5px] font-semibold text-slate-700 truncate"><?= sanitize($company) ?></div>
        <div class="text-[11.5px] text-slate-500 truncate"><?= sanitize($phone) ?></div>
      </div>
    </div>
    <dl class="wa-meta-list flex-1">
      <?php foreach ([
        ['Business', $company],
        ['Phone', $phone ?: '—'],
        ['Connected', !empty($account['connected_at']) ? date('d M, Y', strtotime((string)$account['connected_at'])) : '—'],
        ['Status', $waIsTestNumber ? 'Test number' : 'Verified'],
        ['WABA ID', (string)($account['waba_id'] ?? '—')],
        ['This month', ((int)($waUsage['outbound']??0)).' sent · '.((int)($waUsage['inbound']??0)).' in'],
      ] as [$dlabel, $dval]): ?>
      <div class="wa-meta-row">
        <dt><?= sanitize($dlabel) ?></dt>
        <dd><?= sanitize($dval) ?></dd>
      </div>
      <?php endforeach; ?>
    </dl>
    <?php if ($isConnected): ?>
    <button type="button" onclick="WhatsAppSettings.disconnect()"
      class="mt-3 w-full flex items-center justify-center gap-2 border border-red-200 text-red-500 rounded-lg px-3 py-2 text-[12px] font-semibold hover:bg-red-50 transition-all min-h-[40px]">
      Disconnect
    </button>
    <?php elseif ($manualMode): ?>
    <a href="/client/bot-setup?id=<?= (int)$primaryBotId ?>&tab=channels"
       class="mt-3 w-full flex items-center justify-center gap-2 border border-slate-200 rounded-lg px-3 py-2 text-[12px] font-semibold hover:bg-slate-50 min-h-[40px]">
      Bot Setup → Channels
    </a>
    <?php endif; ?>
    <?php else: ?>
    <p class="text-[12px] text-slate-500 mb-3 flex-1">Connect WhatsApp Business to automate conversations.</p>
    <?php if (!$manualMode): ?>
    <button type="button" id="connect-wa-primary" data-wa-oauth-connect="1" data-wa-client-id="<?= (int) $clientId ?>"
      data-wa-oauth-url="<?= sanitize($oauthStartUrl ?? '') ?>"
      data-wa-return="/client/whatsapp-settings"
      class="w-full bg-[#1FA855] text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold min-h-[44px]">Connect with WhatsApp</button>
    <div id="wa-connect-status" class="hidden mt-2 text-[11px] text-slate-500 text-center">Loading Meta signup…</div>
    <button type="button" id="wa-save-connection" class="mt-2 w-full bg-[#15803d] text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold min-h-[44px]">Save connection</button>
    <button type="button" id="wa-stop-wait" class="hidden mt-1 w-full text-[11px] text-slate-500 underline underline-offset-2">Stop waiting</button>
    <p class="mt-2 text-[11px] text-slate-400 text-center">
      Meta’s green “account shared” bar is <strong>not</strong> Finish. Leave that Meta window, then click <strong>Save connection</strong> on this page.
    </p>
    <p class="mt-1 text-[11px] text-slate-400 text-center">
      Stuck? <a href="/client/whatsapp-oauth-debug" class="text-[#1FA855] underline">OAuth debug</a>
      · No reply? <a href="/client/whatsapp-reply-debug" class="text-[#1FA855] underline">Reply debug</a>
    </p>
    <?php else: ?>
    <a href="/client/bot-setup?id=<?= (int) $primaryBotId ?>&tab=channels"
      class="w-full bg-[#1FA855] text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold text-center min-h-[44px] flex items-center justify-center">Connect in Bot Setup</a>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- 2 · Website widget (color + embed together) -->
  <section class="wa-panel bg-white rounded-xl border border-slate-200 p-4 sm:p-5 flex flex-col">
    <div class="flex items-start justify-between gap-3 mb-0.5">
      <div class="text-[14px] sm:text-[15px] font-bold text-slate-800">Website Widget</div>
      <?php if ($primaryBotId > 0): ?>
      <div class="flex flex-col items-end gap-1 shrink-0">
        <span class="text-[10px] font-medium text-slate-500 uppercase tracking-wide">Widget</span>
        <?= iqp_switch_control('widget_enabled', $widgetEnabled, 'Website widget') ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-[11.5px] sm:text-[12px] text-slate-400 mb-3">Set brand color, then copy the embed code<?= $primaryBotId > 0 ? '' : ' (set up a bot first)' ?>.</div>

    <?php if ($primaryBotId > 0): ?>
    <form method="POST" id="widgetColorForm" class="wa-widget-toolbar mb-2">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="save_widget_color"/>
      <input type="hidden" name="widget_color" id="widget_color" value="<?= sanitize($widgetColor) ?>"/>
      <label class="wa-color-swatch cursor-pointer shrink-0" title="Pick color">
        <input type="color" id="widget_color_picker" value="<?= sanitize($widgetColor) ?>" class="wa-color-input"/>
      </label>
      <div class="wa-hex-wrap">
        <span class="text-slate-400 text-[13px]">#</span>
        <input type="text" id="widget_color_hex" inputmode="text" autocomplete="off" spellcheck="false"
          maxlength="6" placeholder="1FA855" value="<?= sanitize(ltrim($widgetColor, '#')) ?>"
          class="wa-hex-input"/>
      </div>
      <button type="submit" class="wa-save-color-btn">Save</button>
    </form>

    <div class="wa-embed-block flex-1 flex flex-col min-h-0">
      <div class="text-[10.5px] text-slate-500 mb-1.5 font-medium uppercase tracking-wide">Embed code</div>
      <pre id="iqpWidgetCode" class="wa-embed-pre flex-1"><?= sanitize($embed) ?></pre>
      <button type="button" id="btn-copy-widget"
        class="mt-2 w-full border border-slate-200 rounded-lg px-3 py-2 text-[12px] font-semibold text-slate-600 hover:bg-slate-50 min-h-[40px]">
        Copy Code
      </button>
    </div>

    <div class="mt-3 pt-3 border-t border-slate-100">
      <div class="text-[10.5px] text-slate-500 mb-1.5">Preview</div>
      <div class="iqp-widget-preview wa-widget-preview-mini rounded-lg relative border border-slate-100 bg-gradient-to-b from-white to-slate-50">
        <div class="absolute inset-x-3 top-2 h-1.5 rounded bg-slate-100"></div>
        <div class="absolute inset-x-3 top-5 h-10 rounded bg-slate-50 border border-slate-100"></div>
        <div id="widget-preview-fab" class="iqp-widget-preview-fab absolute bottom-2 right-3" aria-hidden="true"><?= iqp_widget_fab_icon_svg($widgetColor, 44) ?></div>
      </div>
    </div>
    <?php else: ?>
    <p class="text-[12px] text-slate-400 flex-1">Set up your bot first to get the widget embed code.</p>
    <?php endif; ?>
  </section>

  <!-- 3 · Send test message -->
  <section class="wa-panel bg-white rounded-xl border border-slate-200 p-4 sm:p-5 flex flex-col">
    <div class="text-[14px] sm:text-[15px] font-bold text-slate-800 mb-0.5">Send Test Message</div>
    <div class="text-[11.5px] sm:text-[12px] text-slate-400 mb-3">To a number that messaged you in the last 24 hours.</div>
    <?php if ($isConnected): ?>
    <label class="block text-[11px] text-slate-500 font-medium mb-1" for="test-to">Phone Number</label>
    <input type="tel" id="test-to" inputmode="tel"
      class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] mb-3 min-h-[44px]"
      placeholder="+92 300 1234567"/>
    <label class="block text-[11px] text-slate-500 font-medium mb-1" for="test-body">Message</label>
    <textarea id="test-body"
      class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] min-h-[88px] resize-y mb-3 flex-1"
      placeholder="Hello! This is a test message from IQPigeon.">Hello! This is a test message from IQPigeon.</textarea>
    <button type="button" id="btn-send-test" data-client-id="<?= (int) $clientId ?>"
      onclick="WhatsAppSettings.sendTest()"
      class="w-full bg-[#1FA855] text-white rounded-lg py-2.5 text-[13px] font-semibold min-h-[44px] hover:bg-[#18934a]">
      Send Test Message
    </button>
    <p id="test-result" class="hidden mt-2 text-[12px]"></p>
    <?php else: ?>
    <div class="flex-1 flex items-center justify-center text-center px-2">
      <p class="text-[12px] text-slate-400">Connect WhatsApp first to send a test message.</p>
    </div>
    <?php endif; ?>
  </section>

</div>

<p id="wa-toggle-status" class="hidden text-[12px] mb-4" role="status"></p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-5">
  <div class="bg-white rounded-xl border border-slate-200 p-4 flex items-start gap-2.5"><span class="text-emerald-500 shrink-0">✓</span><div><div class="text-[12.5px] font-semibold text-slate-700">24/7 AI Assistant</div><div class="text-[11px] text-slate-400">Replies 24/7 to customer queries, even outside business hours.</div></div></div>
  <div class="bg-white rounded-xl border border-slate-200 p-4 flex items-start gap-2.5"><span class="text-blue-500 shrink-0">🕐</span><div><div class="text-[12.5px] font-semibold text-slate-700">Business Hours</div><div class="text-[11px] text-slate-400">Set hours in Settings — orders follow your schedule.</div></div></div>
  <div class="bg-white rounded-xl border border-slate-200 p-4 flex items-start gap-2.5"><span class="text-amber-500 shrink-0">🔔</span><div><div class="text-[12.5px] font-semibold text-slate-700">WhatsApp Policies</div><div class="text-[11px] text-slate-400">Avoid sending promotional or spam messages.</div></div></div>
</div>

<?php if ($messages !== []): ?>
<div class="bg-white rounded-xl border border-slate-200 p-5">
  <div class="text-[15px] font-bold text-slate-800 mb-3">Recent messages</div>
  <?php foreach (array_slice($messages, 0, 8) as $m):
      $preview = mb_substr((string) ($m['message_body'] ?? ''), 0, 80);
      $number = ($m['direction'] ?? '') === 'inbound' ? ($m['from_number'] ?? '') : ($m['to_number'] ?? '');
  ?>
  <div class="flex justify-between gap-3 py-2 border-b border-slate-50 text-[12px]">
    <span class="font-medium truncate"><?= sanitize((string) $number) ?></span>
    <span class="text-slate-400 truncate flex-1"><?= sanitize($preview) ?></span>
    <span class="text-slate-400 shrink-0"><?= sanitize(iqp_rel_time((string) ($m['created_at'] ?? ''))) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script src="/assets/js/app.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/app.js') ?: time() ?>"></script>
<script src="/assets/js/whatsapp-settings.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/whatsapp-settings.js') ?: time() ?>"></script>
<?php
iqp_user_end();
