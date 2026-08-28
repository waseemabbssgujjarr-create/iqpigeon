<?php
/**
 * Admin — System Health
 * Diagnostic checks for DB, AI, SMTP, and core services.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();
$run  = ($_GET['run'] ?? '') === '1';

function health_row(string $label, bool $ok, string $detail = ''): array
{
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

$checks = [];

try {
    $checks[] = health_row('PHP version ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='));
} catch (Throwable $e) {
    $checks[] = health_row('PHP version', false, $e->getMessage());
}

try {
    db_connect();
    $checks[] = health_row('Database connection', true, 'Connected to ' . (defined('DB_NAME') ? DB_NAME : 'database'));
} catch (Throwable $e) {
    $checks[] = health_row('Database connection', false, $e->getMessage());
}

try {
    $checks[] = health_row('OpenAI API key set', integration_openai_chat_key() !== '');
} catch (Throwable $e) {
    $checks[] = health_row('OpenAI API key', false, $e->getMessage());
}

try {
    $smtpOk = mail_transport_ready();
    $detail = '';
    if (defined('SMTP_HOST') && defined('SMTP_PORT')) {
        $detail = SMTP_HOST . ':' . SMTP_PORT;
    }
    $checks[] = health_row('SMTP configured', $smtpOk, $detail);
} catch (Throwable $e) {
    $checks[] = health_row('SMTP configured', false, $e->getMessage());
}

try {
    $checks[] = health_row('Notifications tables', notifications_tables_ready());
} catch (Throwable $e) {
    $checks[] = health_row('Notifications tables', false, $e->getMessage());
}

try {
    $demo = get_demo_bot();
    $checks[] = health_row(
        'Demo / widget bot',
        $demo !== null,
        $demo ? 'Bot #' . $demo['id'] . ' — ' . ($demo['name'] ?? '') : 'Enable widget on a bot in Bot Setup'
    );
} catch (Throwable $e) {
    $checks[] = health_row('Demo / widget bot', false, $e->getMessage());
}

// WhatsApp gateway
try {
    $waConnected = (int)(db_fetch(
        "SELECT COUNT(*) AS cnt FROM bots b JOIN users u ON u.id=b.user_id WHERE u.role='client' AND b.whatsapp_verified=1",
        '', []
    )['cnt'] ?? 0);
    $checks[] = health_row('WhatsApp gateway', $waConnected > 0, $waConnected > 0 ? $waConnected . ' bot(s) connected' : 'No bots have WhatsApp connected');
} catch (Throwable $e) {
    $checks[] = health_row('WhatsApp gateway', false, $e->getMessage());
}

// File upload directory
try {
    $uploadDir = dirname(__DIR__) . '/uploads';
    $uploadOk  = is_dir($uploadDir) && is_writable($uploadDir);
    $checks[]  = health_row('Upload directory writable', $uploadOk, $uploadOk ? $uploadDir : 'Directory missing or not writable');
} catch (Throwable $e) {
    $checks[] = health_row('Upload directory', false, $e->getMessage());
}

// Live tests (only when ?run=1)
if ($run) {
    try {
        $ai = ai_chat([['role' => 'user', 'content' => 'Say hi in 3 words']], ['max_tokens' => 20]);
        $checks[] = health_row(
            'OpenAI API live test',
            !empty($ai['success']),
            $ai['content'] ?? ($ai['error'] ?? 'Unknown error')
        );
    } catch (Throwable $e) {
        $checks[] = health_row('DeepSeek API live test', false, $e->getMessage());
    }

    try {
        $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';
        if ($adminEmail !== '') {
            $mail = send_test_email($adminEmail);
            $checks[] = health_row('SMTP live test', !empty($mail['success']), $mail['message'] ?? '');
        } else {
            $checks[] = health_row('SMTP live test', false, 'ADMIN_EMAIL not configured in config.php');
        }
    } catch (Throwable $e) {
        $checks[] = health_row('SMTP live test', false, $e->getMessage());
    }
}

$allOk     = array_reduce($checks, fn($c, $r) => $c && $r['ok'], true);
$failCount = count(array_filter($checks, fn($r) => !$r['ok']));

iqp_admin_begin($user, 'settings', [
    'title'    => 'System Health',
    'subtitle' => 'Diagnostics for database, AI, email and core services.',
    'actions'  => '<a class="btn btn--ghost btn--sm" href="/admin/settings">System Settings</a>'
        . '<a class="btn btn--primary btn--sm" href="/admin/health?run=1">Run Live Tests</a>',
]);
?>

<!-- Overall status banner -->
<div class="card" style="margin-bottom:20px;border-left:4px solid <?= $allOk ? 'var(--green-500)' : 'var(--amber-500)' ?>">
  <div class="card__body" style="display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:50%;background:<?= $allOk ? 'var(--green-100)' : 'var(--amber-100)' ?>;display:flex;align-items:center;justify-content:center;flex:0 0 auto">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="<?= $allOk ? 'var(--green-600)' : 'var(--amber-600)' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <?php if ($allOk): ?>
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        <?php else: ?>
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        <?php endif; ?>
      </svg>
    </div>
    <div>
      <div style="font-size:16px;font-weight:700;color:var(--ink)">
        <?= $allOk ? 'All systems operational' : $failCount . ' check' . ($failCount === 1 ? '' : 's') . ' need attention' ?>
      </div>
      <div class="muted small"><?= count($checks) ?> checks run<?= $run ? ' (including live tests)' : '' ?></div>
    </div>
    <?php if (!$run): ?>
    <a class="btn btn--soft btn--sm" href="/admin/health?run=1" style="margin-left:auto">Run live AI + email tests →</a>
    <?php endif; ?>
  </div>
</div>

<!-- Check rows -->
<div class="card">
  <div class="card__head"><span class="card__title">Health Checks</span></div>
  <div class="card__body" style="padding:0">
    <?php foreach ($checks as $i => $row): ?>
    <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 20px;border-bottom:1px solid var(--line-2);<?= $i === count($checks)-1 ? 'border-bottom:0' : '' ?>">
      <!-- Status icon -->
      <div style="width:32px;height:32px;border-radius:50%;background:<?= $row['ok'] ? 'var(--green-100)' : 'var(--red-100)' ?>;display:flex;align-items:center;justify-content:center;flex:0 0 auto;margin-top:2px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?= $row['ok'] ? 'var(--green-600)' : 'var(--red-600)' ?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($row['ok']): ?>
            <polyline points="20 6 9 17 4 12"/>
          <?php else: ?>
            <path d="M18 6 6 18M6 6l12 12"/>
          <?php endif; ?>
        </svg>
      </div>
      <!-- Label + detail -->
      <div class="grow">
        <div style="font-size:14px;font-weight:600;color:var(--ink)"><?= sanitize($row['label']) ?></div>
        <?php if ($row['detail'] !== ''): ?>
        <div class="muted small" style="margin-top:3px"><?= sanitize($row['detail']) ?></div>
        <?php endif; ?>
      </div>
      <!-- Badge -->
      <span class="badge <?= $row['ok'] ? 'badge--green' : 'badge--red' ?>" style="flex-shrink:0;align-self:center">
        <span style="width:6px;height:6px;border-radius:50%;background:currentColor"></span>
        <?= $row['ok'] ? 'OK' : 'Failed' ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Action buttons -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px">
  <?php if ($run): ?>
  <a class="btn btn--ghost" href="/admin/health">Basic checks only</a>
  <?php else: ?>
  <a class="btn btn--primary" href="/admin/health?run=1">Run live AI + email tests</a>
  <?php endif; ?>
  <a class="btn btn--ghost" href="/admin/settings">System Settings</a>
  <a class="btn btn--ghost" href="/admin/dashboard">Dashboard</a>
</div>
<?php iqp_admin_end();
