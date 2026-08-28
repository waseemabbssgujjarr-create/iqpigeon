<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';

$user = require_admin();

/* -------------------------------------------------------------------------
 * Inputs — preserved exactly (linked from Conversations Oversight & Business
 * profile as /admin/conversation?lead_id=X&client_id=Y).
 * ---------------------------------------------------------------------- */
$leadId   = (int) ($_GET['lead_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);

if (!$leadId) {
    redirect('/admin/leads');
}

$lead = db_fetch(
    'SELECT l.*, b.name AS bot_name, b.user_id AS client_id, u.company_name, u.email AS client_email, u.name AS client_name
     FROM leads l
     JOIN bots b ON b.id = l.bot_id
     JOIN users u ON u.id = b.user_id
     WHERE l.id = ? AND u.role = \'client\'',
    'i',
    [$leadId]
);

if (!$lead) {
    redirect('/admin/leads');
}

if ($clientId <= 0) {
    $clientId = (int) $lead['client_id'];
}

/* Full message thread — degrade gracefully if the table is missing. */
$messages = [];
try {
    ensure_conversations_schema();
    $messages = db_fetch_all(
        'SELECT * FROM conversations WHERE lead_id = ? ORDER BY created_at ASC',
        'i',
        [$leadId]
    );
} catch (Throwable $e) {
    $messages = [];
}

$qualData = json_decode($lead['qualification_data'] ?? '{}', true) ?: [];
$backHref = $clientId > 0
    ? '/admin/client-leads?id=' . $clientId
    : '/admin/leads';

/* -------------------------------------------------------------------------
 * Presentation helpers — lead status → admin .badge (mirrors oversight page).
 * ---------------------------------------------------------------------- */
$statusMeta = [
    'new'          => ['label' => 'New',          'badge' => 'badge--blue'],
    'in_progress'  => ['label' => 'In Progress',  'badge' => 'badge--amber'],
    'qualified'    => ['label' => 'Qualified',    'badge' => 'badge--cyan'],
    'booked'       => ['label' => 'Booked',       'badge' => 'badge--green'],
    'disqualified' => ['label' => 'Disqualified', 'badge' => 'badge--gray'],
];
$leadBadge = static function (string $status) use ($statusMeta): string {
    $meta = $statusMeta[$status] ?? [
        'label' => (ucwords(str_replace('_', ' ', $status)) ?: 'Unknown'),
        'badge' => 'badge--gray',
    ];
    return '<span class="badge ' . $meta['badge'] . '"><span class="dot"></span>' . sanitize($meta['label']) . '</span>';
};
$platformLabel = static function (string $platform): string {
    return match ($platform) {
        'whatsapp'  => 'WhatsApp',
        'instagram' => 'Instagram',
        'widget'    => 'Website widget',
        default     => $platform,
    };
};

$leadName = trim((string) ($lead['name'] ?? '')) !== '' ? (string) $lead['name'] : 'Unknown';
$business = trim((string) ($lead['company_name'] ?? '')) !== ''
    ? (string) $lead['company_name']
    : (string) ($lead['client_name'] ?? '');
$platform = (string) ($lead['platform'] ?? '');
$score    = (int) ($lead['score'] ?? 0);
$status   = (string) ($lead['status'] ?? '');
$human    = is_lead_bot_paused($lead);
$handledBadge = $human
    ? '<span class="badge badge--blue"><span class="dot"></span>Human</span>'
    : '<span class="badge badge--green"><span class="dot"></span>AI</span>';

/* Subtitle: bot · channel · business (skip empty segments). */
$subtitle = implode(' · ', array_filter([
    trim((string) ($lead['bot_name'] ?? '')),
    $platform !== '' ? $platformLabel($platform) : '',
    trim($business),
]));

/* Header actions: status + handled + score (all read-only). */
$headActions = $leadBadge($status) . ' ' . $handledBadge;
if ($score > 0) {
    $headActions .= ' <span class="badge badge--gray">' . $score . ' pts</span>';
}

iqp_admin_begin($user, 'conversations', [
    'title'    => $leadName,
    'subtitle' => $subtitle,
    'actions'  => $headActions,
]);
?>
<div class="between" style="margin-bottom:16px">
  <a class="btn btn--ghost btn--sm" href="<?= sanitize($backHref) ?>">Back to leads</a>
  <a class="btn btn--ghost btn--sm" href="/admin/client-detail?id=<?= (int) $clientId ?>"><span class="ic" data-ic="building"></span> View business</a>
</div>

<div class="grid g-main-side">
  <!-- ===== Chat thread ===== -->
  <div class="card">
    <div class="card__head">
      <span class="card__title">Conversation history</span>
      <span class="spacer"></span>
      <span class="muted small"><?= number_format(count($messages)) ?> message<?= count($messages) === 1 ? '' : 's' ?> · read-only</span>
    </div>
    <div class="card__body">
      <?php if ($messages === []): ?>
        <div class="iqp-empty" style="padding:32px 12px;text-align:center">No messages yet for this lead.</div>
      <?php else: ?>
        <div class="chat">
          <?php foreach ($messages as $msg):
              $role    = (string) ($msg['role'] ?? '');
              $created = (string) ($msg['created_at'] ?? '');
              $stamp   = trim(implode(' · ', array_filter([format_date($created), format_time($created)])));

              if ($role === 'system'):
          ?>
            <div class="small muted" style="align-self:center;text-align:center;max-width:80%;background:var(--field);padding:6px 12px;border-radius:10px">
              <?= sanitize((string) ($msg['message'] ?? '')) ?>
              <?php if ($stamp !== ''): ?><span style="opacity:.7"> · <?= sanitize($stamp) ?></span><?php endif; ?>
            </div>
          <?php else:
              $isAssistant = $role === 'assistant';
              $bubbleCls   = $isAssistant ? 'bubble out' : 'bubble in';
              $who         = $isAssistant ? 'Sales rep' : ($leadName !== 'Unknown' ? $leadName : 'Customer');
          ?>
            <div class="<?= $bubbleCls ?>">
              <div style="white-space:pre-wrap"><?= sanitize((string) ($msg['message'] ?? '')) ?></div>
              <div class="meta"><?= sanitize($who) ?><?= $stamp !== '' ? ' · ' . sanitize($stamp) : '' ?></div>
            </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== Lead details + qualification ===== -->
  <div class="stack" style="gap:16px">
    <div class="card">
      <div class="card__head"><span class="card__title">Lead details</span></div>
      <div class="card__body">
        <div class="stack" style="gap:12px">
          <div class="between"><span class="muted">Customer</span><span class="strong"><?= sanitize($leadName) ?></span></div>
          <?php if (trim((string) ($lead['phone'] ?? '')) !== ''): ?>
          <div class="between"><span class="muted">Phone</span><span class="strong"><?= sanitize((string) $lead['phone']) ?></span></div>
          <?php endif; ?>
          <div class="between"><span class="muted">Status</span><?= $leadBadge($status) ?></div>
          <div class="between"><span class="muted">Handled by</span><?= $handledBadge ?></div>
          <?php if ($score > 0): ?>
          <div class="between"><span class="muted">Lead score</span><span class="strong"><?= $score ?> pts</span></div>
          <?php endif; ?>
          <?php if ($platform !== ''): ?>
          <div class="between"><span class="muted">Channel</span><span class="strong"><?= sanitize($platformLabel($platform)) ?></span></div>
          <?php endif; ?>
          <div class="between"><span class="muted">Bot</span><span class="strong"><?= sanitize((string) ($lead['bot_name'] ?? '—')) ?></span></div>
          <div class="between"><span class="muted">Business</span><span class="strong" style="text-align:right"><?= sanitize($business !== '' ? $business : '—') ?></span></div>
          <?php if (trim((string) ($lead['client_email'] ?? '')) !== ''): ?>
          <div class="between"><span class="muted">Owner email</span><span class="strong" style="text-align:right;word-break:break-all"><?= sanitize((string) $lead['client_email']) ?></span></div>
          <?php endif; ?>
        </div>
        <div class="divider"></div>
        <a class="btn btn--ghost btn--block btn--sm" href="/admin/client-detail?id=<?= (int) $clientId ?>"><span class="ic" data-ic="building"></span> View business profile</a>
      </div>
    </div>

    <?php if ($qualData !== []): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Qualification</span></div>
      <div class="card__body">
        <div class="stack" style="gap:12px">
          <?php foreach ($qualData as $key => $value): ?>
            <?php if (is_scalar($value) && trim((string) $value) !== ''): ?>
            <div class="between">
              <span class="muted" style="text-transform:capitalize"><?= sanitize(str_replace('_', ' ', (string) $key)) ?></span>
              <span class="strong" style="text-align:right"><?= sanitize((string) $value) ?></span>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php
    $diagTurns = [];
    try {
        require_once __DIR__ . '/../includes/conversation-turn-engine.php';
        turn_engine_ensure_schema();
        $diagTurns = db_fetch_all(
            'SELECT id, status, conversation_state, message_count, media_count, started_at, last_message_at, finalized_at, suppression_reason
             FROM conversation_turns WHERE lead_id = ? ORDER BY id DESC LIMIT 8',
            'i',
            [$leadId]
        );
    } catch (Throwable $e) {
        $diagTurns = [];
    }
    ?>
    <?php if ($diagTurns !== []): ?>
    <div class="card">
      <div class="card__head">
        <span class="card__title">Conversation Intelligence</span>
        <span class="spacer"></span>
        <span class="badge badge--green" style="font-size:11px">Active</span>
      </div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:12px">Admin diagnostics only — customers never see this.</div>
        <div class="stack" style="gap:12px">
          <?php foreach ($diagTurns as $tRow):
              $tid = (int) ($tRow['id'] ?? 0);
              $diag = $tid > 0 ? turn_engine_get_turn_diagnostics($tid) : null;
              $msgs = is_array($diag['messages'] ?? null) ? $diag['messages'] : [];
              $state = (string) ($tRow['conversation_state'] ?? '');
              $sup = trim((string) ($tRow['suppression_reason'] ?? ''));
              $started = (string) ($tRow['started_at'] ?? '');
              $lastAt = (string) ($tRow['last_message_at'] ?? '');
              $agg = '';
              if ($started !== '' && $lastAt !== '') {
                  $a = strtotime($started);
                  $b = strtotime($lastAt);
                  if ($a && $b && $b >= $a) {
                      $agg = number_format(($b - $a) * 1000) . ' ms';
                  }
              }
          ?>
          <div style="border:1px solid var(--line-2);border-radius:10px;padding:12px">
            <div class="between" style="margin-bottom:8px">
              <span class="strong small">Turn #<?= $tid ?></span>
              <span class="badge badge--gray" style="font-size:11px"><?= sanitize((string) ($tRow['status'] ?? '')) ?></span>
            </div>
            <div class="stack" style="gap:6px">
              <div class="between"><span class="muted small">State</span><span class="small"><?= sanitize($state !== '' ? $state : '—') ?></span></div>
              <div class="between"><span class="muted small">Messages</span><span class="small"><?= (int) ($tRow['message_count'] ?? 0) ?></span></div>
              <div class="between"><span class="muted small">Media</span><span class="small"><?= (int) ($tRow['media_count'] ?? 0) ?></span></div>
              <?php if ($agg !== ''): ?>
              <div class="between"><span class="muted small">Aggregation</span><span class="small"><?= sanitize($agg) ?></span></div>
              <?php endif; ?>
              <?php if ($sup !== ''): ?>
              <div class="between"><span class="muted small">Suppression</span><span class="small" style="text-align:right"><?= sanitize($sup) ?></span></div>
              <?php endif; ?>
            </div>
            <?php if ($msgs !== []): ?>
            <div class="divider" style="margin:10px 0"></div>
            <div class="muted small" style="margin-bottom:6px">Included messages</div>
            <div class="stack" style="gap:6px">
              <?php foreach ($msgs as $m):
                  $mType = (string) ($m['message_type'] ?? 'text');
                  $mText = trim((string) (($m['raw_text'] ?? '') !== '' ? $m['raw_text'] : (($m['caption'] ?? '') !== '' ? $m['caption'] : ($m['transcription'] ?? ''))));
                  if (mb_strlen($mText) > 80) {
                      $mText = mb_substr($mText, 0, 80) . '…';
                  }
              ?>
              <div class="between">
                <span class="muted small"><?= sanitize($mType) ?></span>
                <span class="small" style="text-align:right;max-width:60%"><?= sanitize($mText !== '' ? $mText : (string) ($m['processing_status'] ?? '')) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php iqp_admin_end();
