<?php
/**
 * WhatsApp reply debug — why customers get no response.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp-reply-debug.php';

$user = require_login();
$userId = (int) $user['id'];

$bot = db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
$botId = (int) ($bot['id'] ?? 0);

$message = '';
$error = '';
if (isset($_GET['done']) && $_GET['done'] === '1') {
    $message = 'Recovered hung turns: ' . (int) ($_GET['hung'] ?? 0)
        . '. Fallback sent for ' . (int) ($_GET['ensured'] ?? 0) . ' lead(s). Send ONE test WhatsApp now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = trim($_POST['action'] ?? '');
    if ($action === 'force_recover' && $botId > 0) {
        try {
            require_once __DIR__ . '/../includes/wa-recover-lite.php';
            @set_time_limit(120);
            $result = wa_recover_run($botId, true, 1);
            whatsapp_reply_debug_log('force_recover_lite', [
                'bot_id' => $botId,
                'hung'   => $result['hung'] ?? 0,
                'sent'   => $result['sent'] ?? 0,
                'ok'     => !empty($result['ok']),
            ]);
            header('Location: /client/whatsapp-reply-debug?done=1&hung=' . (int) ($result['hung'] ?? 0)
                . '&ensured=' . (int) ($result['sent'] ?? 0));
            exit;
        } catch (Throwable $e) {
            $error = 'Recover failed: ' . $e->getMessage();
            whatsapp_reply_debug_log('force_recover_error', ['error' => $e->getMessage()]);
        }
    }
    if ($action === 'repair_routing' && $botId > 0) {
        require_once __DIR__ . '/../includes/whatsapp.php';
        $botRow = db_fetch('SELECT whatsapp_phone_id FROM bots WHERE id = ?', 'i', [$botId]);
        $phoneId = trim((string) ($botRow['whatsapp_phone_id'] ?? ''));
        if ($phoneId !== '') {
            $released = bot_whatsapp_release_phone_id($botId, $phoneId);
            $message = $released > 0
                ? 'Released phone_id from ' . $released . ' other bot(s).'
                : 'Routing already exclusive to this bot.';
        }
    }
}

$snap = null;
$snapError = '';
if ($botId > 0) {
    try {
        $snap = whatsapp_reply_debug_snapshot($botId, $userId);
    } catch (Throwable $e) {
        $snapError = $e->getMessage();
        whatsapp_reply_debug_log('snapshot_error', ['error' => $e->getMessage()]);
    }
}
$csrf = csrf_token();

if ($botId > 0 && isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="wa-reply-debug-' . $botId . '.json"');
    echo json_encode($snap ?? ['error' => 'no bot'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('WhatsApp Reply Debug') ?>
<style>
.wa-dbg { max-width: 920px; margin: 0 auto; padding: 24px 16px 48px; font-family: system-ui, sans-serif; }
.wa-dbg h1 { font-size: 1.35rem; margin: 0 0 4px; }
.wa-dbg .sub { color: #64748b; font-size: 13px; margin-bottom: 20px; }
.wa-dbg .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.wa-dbg .stuck { background: #fef2f2; border-color: #fecaca; }
.wa-dbg .ok { color: #15803d; font-weight: 600; }
.wa-dbg .bad { color: #dc2626; font-weight: 600; }
.wa-dbg pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; font-size: 11px; overflow-x: auto; max-height: 280px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; }
.wa-dbg table { width: 100%; border-collapse: collapse; font-size: 12px; }
.wa-dbg th, .wa-dbg td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.wa-dbg .btn { display: inline-block; background: #1FA855; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; margin-right: 8px; margin-bottom: 8px; }
.wa-dbg .btn2 { background: #334155; }
.wa-dbg .flash-ok { background: #ecfdf5; color: #065f46; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 13px; }
.wa-dbg .flash-err { background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 13px; }
</style>
</head>
<body class="bg-slate-50">
<div class="wa-dbg">
  <p><a href="/client/dashboard" class="text-sm text-slate-500">← Dashboard</a></p>
  <h1>WhatsApp Reply Debug</h1>
  <p class="sub">Client #<?= $userId ?> · Bot #<?= $botId ?> — use this when customers message you and get <strong>no reply</strong>.</p>

  <?php if ($message !== ''): ?><div class="flash-ok"><?= sanitize($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash-err"><?= sanitize($error) ?></div><?php endif; ?>
  <?php if ($snapError !== ''): ?><div class="flash-err">Debug snapshot error: <?= sanitize($snapError) ?></div><?php endif; ?>

  <?php if ($snap === null): ?>
  <div class="card stuck"><strong>No bot found.</strong> Complete onboarding first.</div>
  <?php else: ?>
  <div class="card stuck">
    <strong>STUCK POSITION: <?= sanitize((string) ($snap['stuck']['position'] ?? 'unknown')) ?></strong>
    <p style="margin:8px 0 4px;font-size:13px"><?= sanitize((string) ($snap['stuck']['summary'] ?? '')) ?></p>
    <p style="margin:0;font-size:12px;color:#64748b"><strong>Fix:</strong> <?= sanitize((string) ($snap['stuck']['fix'] ?? '')) ?></p>
  </div>

  <div class="card">
    <strong>Checks</strong>
    <table class="mt-2">
      <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
      <?php foreach ($snap['checks'] as $c): ?>
      <tr>
        <td><?= sanitize((string) ($c['name'] ?? '')) ?></td>
        <td class="<?= !empty($c['ok']) ? 'ok' : 'bad' ?>"><?= !empty($c['ok']) ? 'OK' : 'FAIL' ?></td>
        <td><?= sanitize((string) ($c['detail'] ?? '')) ?><?php if (empty($c['ok']) && !empty($c['fix'])): ?><br><span style="color:#64748b">→ <?= sanitize((string) $c['fix']) ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($snap['php_lint'] !== []): ?>
  <div class="card stuck">
    <strong>PHP syntax errors (will break replies)</strong>
    <pre><?= sanitize(implode("\n", $snap['php_lint'])) ?></pre>
  </div>
  <?php endif; ?>

  <div class="card" style="border-color:#86efac;background:#f0fdf4">
    <strong>Recover stuck replies (no 503)</strong>
    <p style="margin:8px 0 12px;font-size:13px;color:#166534">
      Use this instead of the old Force process. It does <em>not</em> load the heavy turn engine.
    </p>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="force_recover"/>
      <button type="submit" class="btn">Recover now (OpenAI + fallback)</button>
    </form>
    <?php if (defined('CRON_SECRET') && CRON_SECRET !== ''): ?>
    <?php $base = rtrim(app_url(), '/'); $k = urlencode((string) CRON_SECRET); ?>
    <p style="margin:12px 0 0;font-size:12px;color:#64748b">
      <strong>Fast fallback (~5s):</strong><br>
      <code style="word-break:break-all"><?= sanitize($base . '/api/wa-fallback.php?key=' . $k . '&bot_id=' . $botId) ?></code>
    </p>
    <p style="margin:8px 0 0;font-size:12px;color:#64748b">
      <strong>One OpenAI reply (~15s):</strong><br>
      <code style="word-break:break-all"><?= sanitize($base . '/api/wa-recover.php?key=' . $k . '&bot_id=' . $botId . '&ai=1&limit=1') ?></code>
    </p>
    <p style="margin:8px 0 0;font-size:12px;color:#64748b">
      <strong>Health check (instant):</strong><br>
      <code style="word-break:break-all"><?= sanitize($base . '/api/wa-recover.php?key=' . $k . '&ping=1') ?></code>
    </p>
    <p style="margin:8px 0 0;font-size:12px;color:#64748b">
      cPanel Terminal (from <code>public_html</code>): <code>php scripts/wa-recover.php --key=CRON_SECRET --bot=<?= (int) $botId ?></code> (fallback) or add <code>--ai</code>
    </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="repair_routing"/>
      <button type="submit" class="btn btn2">Repair phone routing</button>
    </form>
    <a class="btn btn2" href="?refresh=1">Refresh</a>
    <a class="btn btn2" href="?download=1">Download JSON</a>
    <a class="btn btn2" href="/client/whatsapp-oauth-debug">OAuth debug</a>
    <a class="btn btn2" href="/api/whatsapp-diagnose.php" target="_blank" rel="noopener">Full diagnose JSON</a>
  </div>

  <div class="card">
    <strong>Recent turn events</strong>
    <table class="mt-2">
      <tr><th>Time</th><th>Turn</th><th>Event</th><th>Detail</th></tr>
      <?php foreach ($snap['turn_events'] as $e): ?>
      <tr>
        <td><?= sanitize((string) ($e['created_at'] ?? '')) ?></td>
        <td>#<?= (int) ($e['turn_id'] ?? 0) ?></td>
        <td><?= sanitize((string) ($e['event_type'] ?? '')) ?></td>
        <td><code style="font-size:10px"><?= sanitize(mb_substr((string) ($e['detail_json'] ?? ''), 0, 120)) ?></code></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($snap['turn_events'] === []): ?><tr><td colspan="4" style="color:#94a3b8">No turn events yet — send a WhatsApp test message.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card">
    <strong>Recent turns</strong>
    <table class="mt-2">
      <tr><th>ID</th><th>Status</th><th>Msgs</th><th>Suppressed</th><th>Last msg</th></tr>
      <?php foreach ($snap['recent_turns'] as $t): ?>
      <tr>
        <td>#<?= (int) ($t['id'] ?? 0) ?></td>
        <td><?= sanitize((string) ($t['status'] ?? '')) ?></td>
        <td><?= (int) ($t['message_count'] ?? 0) ?></td>
        <td><?= sanitize((string) ($t['suppression_reason'] ?? '')) ?></td>
        <td><?= sanitize((string) ($t['last_message_at'] ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <strong>Reply debug log</strong>
    <p style="margin:4px 0 8px;font-size:12px;color:#64748b">Written to <code>storage/logs/wa-reply-debug.jsonl</code> on every webhook step and send failure.</p>
    <pre><?php
      $dbgLines = $snap['debug_log'] ?? [];
      if ($dbgLines === []) {
          echo '(empty — upload includes/whatsapp-reply-debug.php + api/whatsapp-webhook.php, then send a test WhatsApp)';
      } else {
          foreach ($dbgLines as $row) {
              echo sanitize((string) ($row['ts'] ?? '')) . '  '
                  . sanitize((string) ($row['step'] ?? '')) . '  '
                  . sanitize(json_encode($row['context'] ?? [], JSON_UNESCAPED_UNICODE)) . "\n";
          }
      }
    ?></pre>
  </div>

  <div class="card">
    <strong>Webhook log (last 40 lines)</strong>
    <pre><?= sanitize($snap['webhook_log'] === [] ? '(empty — Meta may not be calling your webhook)' : implode("\n", $snap['webhook_log'])) ?></pre>
  </div>

  <div class="card">
    <strong>Recent conversations</strong>
    <table class="mt-2">
      <tr><th>Time</th><th>Role</th><th>Message</th></tr>
      <?php foreach ($snap['conversations'] as $c): ?>
      <tr>
        <td><?= sanitize((string) ($c['created_at'] ?? '')) ?></td>
        <td><?= sanitize((string) ($c['role'] ?? '')) ?></td>
        <td><?= sanitize((string) ($c['message'] ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <strong>Deployed file timestamps</strong>
    <pre><?php foreach ($snap['file_mtiles'] as $f => $mtime): ?><?= sanitize($f) ?> — <?= sanitize((string) $mtime) ?>

<?php endforeach; ?></pre>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
