<?php
/**
 * WhatsApp OAuth debug — where the connect flow is stuck (client + admin).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/integration-settings.php';
require_once __DIR__ . '/../includes/whatsapp-oauth.php';
require_once __DIR__ . '/../includes/whatsapp-oauth-debug.php';
require_once __DIR__ . '/../includes/domain.php';

$user = require_login();
$clientId = (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$viewClientId = $isAdmin ? (int) ($_GET['client_id'] ?? $clientId) : $clientId;

$connected = whatsapp_client_embedded_connected($viewClientId);
$diag = whatsapp_oauth_diagnose_stuck($viewClientId);
$logs = whatsapp_oauth_debug_read(30, $viewClientId);
$metaVerify = whatsapp_meta_verify_app_credentials();
$redirectUri = whatsapp_oauth_redirect_uri();
$redirectUriPhp = rtrim(app_canonical_url(), '/') . '/client/whatsapp-oauth-callback.php';
$state = whatsapp_oauth_build_state($viewClientId, '/client/whatsapp-settings', false);
$launchUrlPopup = whatsapp_oauth_launch_url($state, true);
$launchUrlFull = whatsapp_oauth_launch_url($state, false);
$oauthStartUrl = whatsapp_oauth_start_url($viewClientId, '/client/whatsapp-settings', false);
$account = db_fetch(
    'SELECT waba_id, phone_number_id, phone_display_number, connection_status, connected_at
     FROM client_whatsapp_accounts WHERE client_id = ? ORDER BY id DESC LIMIT 1',
    'i',
    [$viewClientId]
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp OAuth Debug</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:20px;line-height:1.5}
.wrap{max-width:920px;margin:0 auto}
h1{font-size:1.35rem;margin:0 0 8px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px;margin:16px 0}
.ok{color:#4ade80}.warn{color:#fbbf24}.err{color:#f87171}
label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8}
pre,code{font-family:ui-monospace,monospace;font-size:12px;word-break:break-all;white-space:pre-wrap}
pre{background:#0b1220;padding:12px;border-radius:8px;border:1px solid #334155}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{border-bottom:1px solid #334155;padding:8px 6px;text-align:left;vertical-align:top}
.btn{display:inline-block;background:#1FA855;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600;margin:6px 6px 0 0;border:0;cursor:pointer}
.btn2{background:#334155}
.sev-ok{border-left:4px solid #4ade80}.sev-warn{border-left:4px solid #fbbf24}.sev-error{border-left:4px solid #f87171}
</style>
<?php if (!$connected && integration_meta_configured()): ?>
<?php $meta_fb_sdk_skip_root = true; include __DIR__ . '/../includes/meta-fb-sdk.php'; ?>
<?php endif; ?>
</head>
<body>
<div class="wrap">
<h1>WhatsApp OAuth Debug</h1>
<p style="color:#94a3b8;margin:0 0 16px">Client #<?= (int) $viewClientId ?> · <?= sanitize((string) ($user['email'] ?? '')) ?></p>

<div class="card sev-<?= sanitize($diag['severity']) ?>">
  <label>Stuck position</label>
  <p style="font-size:1.1rem;font-weight:700;margin:8px 0" class="<?= $diag['severity'] === 'ok' ? 'ok' : ($diag['severity'] === 'error' ? 'err' : 'warn') ?>">
    <?= sanitize($diag['position']) ?>
  </p>
  <p><?= sanitize($diag['detail']) ?></p>
  <p><strong>Fix:</strong> <?= sanitize($diag['fix']) ?></p>
</div>

<div class="card">
  <label>Database</label>
  <p>Embedded connected: <strong class="<?= $connected ? 'ok' : 'err' ?>"><?= $connected ? 'YES' : 'NO' ?></strong></p>
  <?php if ($account): ?>
  <pre><?= sanitize(json_encode($account, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
  <?php else: ?>
  <p>No row in <code>client_whatsapp_accounts</code>.</p>
  <?php endif; ?>
</div>

<div class="card sev-error" style="border-left-width:4px">
  <label>Meta Developer — required (one-time)</label>
  <p>In <a href="https://developers.facebook.com/apps/<?= sanitize(whatsapp_meta_app_id()) ?>/fb-login/settings/" target="_blank" rel="noopener" style="color:#4ade80">Meta → Facebook Login → Settings</a>, add <strong>both</strong> Valid OAuth Redirect URIs:</p>
  <pre><?= sanitize($redirectUri) ?>

<?= sanitize($redirectUriPhp) ?></pre>
  <p class="warn">Without these, Meta shows “shared” but never saves the connection (<code>stuck_on_meta</code>).</p>
</div>

<div class="card">
  <label>Meta config</label>
  <p>App ID: <code><?= sanitize(whatsapp_meta_app_id()) ?></code></p>
  <p>Config ID: <code><?= sanitize((string) integration_config('META_CONFIG_ID')) ?></code></p>
  <p>Graph API version: <code><?= sanitize(integration_meta_graph_api_version()) ?></code>
    <?php if (integration_meta_graph_api_version() !== trim(integration_config('META_GRAPH_API_VERSION'))): ?>
    <span class="warn"> (stored value invalid — using fallback)</span>
    <?php endif; ?>
  </p>
  <p>App Secret: <strong class="<?= !empty($metaVerify['success']) ? 'ok' : 'err' ?>"><?= !empty($metaVerify['success']) ? 'Valid' : sanitize((string) ($metaVerify['error'] ?? 'Invalid/missing')) ?></strong></p>
  <p>Redirect URI (sent to Meta):</p>
  <pre><?= sanitize($redirectUri) ?></pre>
  <p>Also register in Meta (with .php):</p>
  <pre><?= sanitize($redirectUriPhp) ?></pre>
  <?php
  $storedGraphVer = trim(integration_config('META_GRAPH_API_VERSION'));
  if ($storedGraphVer !== '' && $storedGraphVer !== integration_meta_graph_api_version()):
  ?>
  <div class="sev-error" style="margin-top:12px;padding:12px;border-radius:8px;background:#450a0a">
    <p class="err" style="margin:0 0 8px"><strong>Invalid Graph API version in database</strong></p>
    <p style="margin:0 0 8px">Stored: <code><?= sanitize($storedGraphVer) ?></code> — this breaks reading WhatsApp assets after token exchange.</p>
    <p style="margin:0">Fix: <strong>Admin → Integrations</strong> → Graph API version → set <code>v25.0</code> → Save, then Connect again.</p>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <label>Launch URL — popup (desktop Connect button)</label>
  <pre><?= sanitize($launchUrlPopup) ?></pre>
  <p>Has redirect_uri: <strong class="<?= str_contains($launchUrlPopup, 'redirect_uri=') ? 'ok' : 'err' ?>"><?= str_contains($launchUrlPopup, 'redirect_uri=') ? 'YES' : 'NO' ?></strong></p>
  <label style="display:block;margin-top:12px">Launch URL — full page (mobile)</label>
  <pre><?= sanitize($launchUrlFull) ?></pre>
</div>

<div class="card">
  <a class="btn" href="<?= sanitize($oauthStartUrl) ?>">1 · Start Meta OAuth (full page)</a>
  <button type="button" class="btn btn2" id="btn-sdk-complete">2 · Complete via FB SDK (after Meta “shared” toast)</button>
  <a class="btn btn2" href="/client/whatsapp-settings">WhatsApp Settings</a>
  <a class="btn btn2" href="?client_id=<?= (int) $viewClientId ?>">Refresh debug</a>
  <p id="sdk-status" style="margin-top:12px;font-size:13px;color:#94a3b8"></p>
</div>

<div class="card">
  <label>Event log (newest first)</label>
  <?php if ($logs === []): ?>
  <p>No events yet — click “Start Meta OAuth” above.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Time</th><th>Step</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $row): ?>
      <tr>
        <td><?= sanitize((string) ($row['ts'] ?? '')) ?></td>
        <td><code><?= sanitize((string) ($row['step'] ?? '')) ?></code></td>
        <td><pre style="margin:0;background:transparent;border:0;padding:0"><?= sanitize(json_encode($row['context'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</div>
<div id="fb-root"></div>
<script src="/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<script>
(function () {
  const clientId = <?= (int) $viewClientId ?>;
  const statusEl = document.getElementById('sdk-status');
  const btn = document.getElementById('btn-sdk-complete');
  if (!btn) return;

  btn.addEventListener('click', function () {
    statusEl.textContent = 'Requesting Meta authorization code…';
    btn.disabled = true;
    fetch('/api/whatsapp/oauth-debug-log.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ step: 'debug_sdk_complete_click', client_id: clientId }),
    }).catch(function () {});

    if (!window.metaWaSignup || typeof App === 'undefined') {
      statusEl.textContent = 'Meta SDK not loaded — allow connect.facebook.net and refresh.';
      btn.disabled = false;
      return;
    }

    App.finishWaEmbeddedSignup(clientId, {}, {
      startUrl: '/client/whatsapp-oauth-start?client_id=' + clientId + '&return=' + encodeURIComponent('/client/whatsapp-settings'),
      onSuccess: function () {
        statusEl.textContent = 'Saved! Redirecting…';
        window.location.href = '/client/whatsapp-settings?connected=1';
      },
      onError: function (msg) {
        statusEl.textContent = msg || 'Failed — try “Start Meta OAuth” instead.';
        btn.disabled = false;
      },
    });
  });
})();
</script>
</body>
</html>
