<?php
/**
 * Admin — AI Model & Guardrails (Master Behavior + Industry Templates + Guardrails).
 * Every save on this page is read live by includes/platform-training.php and
 * actually changes what every business's AI says — there is no separate
 * "training job" to run.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/platform-settings.php';
require_once __DIR__ . '/../includes/platform-training.php';
require_once __DIR__ . '/../includes/industry-templates.php';

$user = require_admin();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_base_prompt') {
            set_setting('ai_base_prompt', trim((string) ($_POST['ai_base_prompt'] ?? '')));
            $message = 'Master behavior saved — now live for every business.';
        } elseif ($action === 'save_behavior') {
            set_setting('ai_behavior_prompt', trim((string) ($_POST['behavior_prompt'] ?? '')));
            set_setting('ai_tone', trim((string) ($_POST['ai_tone'] ?? 'Friendly & Professional')));
            set_setting('ai_formality', trim((string) ($_POST['ai_formality'] ?? 'Professional')));
            set_setting('ai_language_style', trim((string) ($_POST['ai_language_style'] ?? 'Simple & Clear')));
            $message = 'Behavior & Personality saved — now live for every business.';
        } elseif ($action === 'add_principle') {
            $text = trim((string) ($_POST['principle_text'] ?? ''));
            if ($text === '') {
                $error = 'Type a principle before adding it.';
            } else {
                $list = get_ai_core_principles();
                $list[] = $text;
                save_ai_core_principles($list);
                $message = 'Principle added.';
            }
        } elseif ($action === 'delete_principle') {
            $idx = (int) ($_POST['index'] ?? -1);
            $list = get_ai_core_principles();
            if (isset($list[$idx])) {
                unset($list[$idx]);
                save_ai_core_principles(array_values($list));
                $message = 'Principle removed.';
            }
        } elseif ($action === 'save_section') {
            $sid = preg_replace('/[^a-z_]/', '', (string) ($_POST['section_id'] ?? ''));
            $ids = ai_master_section_ids();
            if ($sid !== '' && isset($ids[$sid])) {
                set_setting('ai_section_' . $sid, trim((string) ($_POST['section_text'] ?? '')));
                $message = sanitize($ids[$sid]) . ' saved — now live for every business.';
            } else {
                $error = 'Unknown section.';
            }
        } elseif ($action === 'save_guardrails') {
            $slugs = array_keys(get_ai_guardrails());
            $enabled = [];
            foreach ($slugs as $slug) {
                $enabled[$slug] = isset($_POST['guardrail'][$slug]);
            }
            save_ai_guardrails($enabled);
            $message = 'Guardrails saved — now enforced for every business.';
        } elseif ($action === 'reset_master') {
            reset_ai_master_behavior();
            $message = 'Master behavior reset to platform defaults.';
        } elseif ($action === 'duplicate_snapshot') {
            duplicate_ai_master_behavior_snapshot(trim((string) ($user['name'] ?? 'Admin')));
            $message = 'Snapshot saved. Find it under Key Settings → Version History.';
        } elseif ($action === 'restore_snapshot') {
            $sid = trim((string) ($_POST['snapshot_id'] ?? ''));
            if ($sid !== '' && restore_ai_master_behavior_snapshot($sid)) {
                $message = 'Snapshot restored.';
            } else {
                $error = 'Snapshot not found.';
            }
        } elseif ($action === 'save_industry_template') {
            $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($_POST['template_key'] ?? ''))));
            if ($key === '') {
                $key = preg_replace('/[^a-z0-9_]/', '_', mb_strtolower(trim((string) ($_POST['label'] ?? 'custom'))));
            }
            if ($key === '') {
                $error = 'Give the template a name.';
            } else {
                $questions = preg_split('/\r\n|\r|\n/', (string) ($_POST['questions'] ?? '')) ?: [];
                save_industry_template($key, [
                    'label'           => $_POST['label'] ?? $key,
                    'business_mode'   => $_POST['business_mode'] ?? 'mixed',
                    'conversion_goal' => $_POST['conversion_goal'] ?? 'call_booked',
                    'offer'           => $_POST['offer'] ?? '',
                    'knowledge'       => $_POST['knowledge'] ?? '',
                    'questions'       => $questions,
                ]);
                $message = 'Industry template saved.';
                header('Location: /admin/ai?tab=templates&saved=' . $key);
                exit;
            }
        } elseif ($action === 'delete_industry_template') {
            $key = trim((string) ($_POST['template_key'] ?? ''));
            $builtIn = industry_training_templates();
            if ($key !== '' && !isset($builtIn[$key])) {
                delete_industry_template($key);
                $message = 'Custom template deleted.';
            } else {
                $error = 'Built-in templates cannot be deleted — edit or override them instead.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/integration-settings.php';

$openaiKey      = integration_openai_chat_key() !== '';
$anyKey         = $openaiKey;
$activeModel    = integration_openai_model();
$basePrompt     = (string) get_setting('ai_base_prompt', '');
$behaviorPrompt = (string) get_setting('ai_behavior_prompt',
    "You are a live WhatsApp sales representative for this business. Help customers with their questions, stay human and concise, and never invent prices or stock.\nAnswer what they just said first. Stay short unless they asked for detail.");
$aiTone         = (string) get_setting('ai_tone', 'Friendly & Professional');
$aiFormality    = (string) get_setting('ai_formality', 'Professional');
$aiLangStyle    = (string) get_setting('ai_language_style', 'Simple & Clear');
$principles     = get_ai_core_principles();
$guardrails     = get_ai_guardrails();
$snapshots      = list_ai_master_behavior_snapshots();

$mainTab = preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? 'templates')) ?: 'templates';
$adminTabAliases = [
    'industry' => 'templates',
    'master'   => 'behavior',
    'guardrails' => 'safety',
];
if (isset($adminTabAliases[$mainTab])) {
    $secQs = isset($_GET['sec']) ? '&sec=' . preg_replace('/[^a-z_]/', '', (string) $_GET['sec']) : '';
    header('Location: /admin/ai?tab=' . $adminTabAliases[$mainTab] . $secQs, true, 302);
    exit;
}
// Left inner-nav section for AI Behavior / Safety
$section = preg_replace('/[^a-z_]/', '', (string) ($_GET['sec'] ?? 'behavior')) ?: 'behavior';
if ($mainTab === 'safety' && !isset($_GET['sec'])) {
    $section = 'guardrails';
}
if ($mainTab === 'behavior' && in_array($section, ['data', 'fallback', 'knowledge', 'rules', 'disallowed', 'guardrails'], true)) {
    header('Location: /admin/ai?tab=safety&sec=' . $section, true, 302);
    exit;
}

$csrf = csrf_token();

$mainTabs = [
    ['templates', 'Templates', '<path d="M2 20h20M5 20V8l7-6 7 6v12"/>'],
    ['behavior', 'AI Behavior', '<path d="M12 3 3 7l9 4 9-4-9-4Z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>'],
    ['intelligence', 'Conversation Engine', '<path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z"/><circle cx="12" cy="9" r="2.2"/>'],
    ['safety', 'Safety & Control', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>'],
    ['versioning', 'Model Version Control', '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
];

$sectionIds = ai_master_section_ids();
$leftNav = [
    ['behavior', 'Personality & Tone'],
    ['flow', 'Conversation Rules'],
    ['response', 'Response Rules'],
    ['tone', 'Tone notes'],
];
$safetyNav = [
    ['guardrails', 'Global Guardrails'],
    ['data', 'Knowledge & Data'],
    ['knowledge', 'Missing information'],
    ['fallback', 'Fallback & Escalation'],
    ['rules', 'Business Rules'],
    ['disallowed', 'Disallowed Actions'],
];

$sectionCopy = [
    'flow'       => 'Behavior, tone and response rules below govern how a conversation flows for every business.',
    'response'   => 'Behavior, tone and response rules below govern the shape of every AI reply.',
    'tone'       => 'Free-text notes on tone/language nuances (e.g. use of emoji, formality per region) — appended to every conversation.',
    'data'       => 'Rules for how the AI should treat customer data during a conversation (e.g. what it may ask for or must never store).',
    'fallback'   => 'What the AI should do when it does not know an answer or needs to hand off to a human.',
    'knowledge'  => 'How the AI should treat missing or partial business knowledge (e.g. always say "let me check" instead of guessing).',
    'rules'      => 'General business rules that apply across all industries (e.g. no discounts without approval).',
    'disallowed' => 'Explicit list of things the AI must never do or say, platform-wide.',
];

iqp_admin_begin($user, 'ai', [
    'title'    => 'AI Training &amp; Templates',
    'subtitle' => 'Admin defines what IQPigeon can do. Businesses define what it knows. Saved settings are live on the next reply — there is no training job.',
    'actions'  => '<button type="button" id="iqpTestAssistantBtn" class="btn btn--primary btn--sm" style="display:flex;align-items:center;gap:7px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Test Assistant</button>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>

<!-- Main sections — mobile cards, desktop pills -->
<?php
$aiNavTabs = [];
foreach ($mainTabs as [$tid, $tlabel, $ticon]) {
    $aiNavTabs[] = [$tid, $tlabel, $ticon, '/admin/ai?tab=' . $tid];
}
iqp_tab_nav($mainTab, $aiNavTabs, ['variant' => 'admin', 'select_label' => 'Section']);
?>

<?php if ($mainTab === 'behavior'): ?>
<!-- ===================== MASTER BEHAVIOR (GLOBAL) ===================== -->
<div class="grid" style="grid-template-columns:220px minmax(0,1fr) 320px;gap:18px;align-items:start">

  <!-- Left inner-nav -->
  <div class="card" style="position:sticky;top:16px">
    <?php
    $secNav = [];
    foreach ($leftNav as [$sid, $slabel]) {
        $secNav[] = [$sid, $slabel, '/admin/ai?tab=behavior&sec=' . $sid];
    }
    iqp_section_nav($section, $secNav, ['class' => 'iqp-section-nav--pad']);
    ?>
    <div class="divider" style="margin:4px 0"></div>
    <div style="padding:10px 12px">
      <div style="font-size:12px;font-weight:600;color:var(--muted-2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Applies To</div>
      <div style="font-size:13px;color:var(--ink);margin-bottom:2px">All Businesses</div>
      <div style="font-size:13px;color:var(--ink)">All Industry Templates</div>
      <div class="muted small" style="margin-top:8px;line-height:1.5">This is a platform-wide layer. Per-business overrides live in each client's Assistant page.</div>
    </div>
  </div>

  <!-- Centre: section content -->
  <div>
    <div class="card">
      <!-- Card header with Active badge + Actions -->
      <div class="card__head" style="flex-wrap:wrap;gap:10px">
        <div class="grow">
          <div class="strong" style="font-size:16px">AI Behavior</div>
          <div class="muted small" style="margin-top:3px">Platform-wide personality, conversation rules, and response shape. Businesses can soften tone but cannot override safety.</div>
        </div>
        <span class="badge badge--green" style="font-size:12.5px">
          <span style="width:7px;height:7px;border-radius:50%;background:currentColor"></span> Active
        </span>
        <div style="position:relative">
          <button type="button" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:6px" onclick="this.nextElementSibling.classList.toggle('hidden')">
            Actions
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <div class="hidden" style="position:absolute;right:0;top:38px;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow-md);z-index:20;min-width:200px;padding:6px">
            <form method="POST" onsubmit="return confirm('Reset Master Behavior to platform defaults? This clears the base prompt, behavior text, tone, principles and section notes.');">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="reset_master"/>
              <button type="submit" class="row-menu-item btn--ghost" style="display:block;width:100%;text-align:left;padding:8px 12px;font-size:13px;background:none;border:none;cursor:pointer;border-radius:7px">Reset to Default</button>
            </form>
            <button type="button" onclick="iqpExportBehaviorJson()" class="row-menu-item btn--ghost" style="display:block;width:100%;text-align:left;padding:8px 12px;font-size:13px;background:none;border:none;cursor:pointer;border-radius:7px">Export JSON</button>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="duplicate_snapshot"/>
              <button type="submit" class="row-menu-item btn--ghost" style="display:block;width:100%;text-align:left;padding:8px 12px;font-size:13px;background:none;border:none;cursor:pointer;border-radius:7px">Duplicate Snapshot</button>
            </form>
          </div>
        </div>
      </div>

      <div class="card__body">

        <?php if ($section === 'behavior' || $section === 'flow' || $section === 'response'): ?>
        <!-- Core Personality & Behavior -->
        <?php if ($section !== 'behavior'): ?>
        <div class="muted small" style="margin-bottom:12px"><?= sanitize($sectionCopy[$section] ?? '') ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="save_behavior"/>

          <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px">Personality &amp; Behavior</div>
          <div class="muted small" style="margin-bottom:14px">Define how the AI should behave in all conversations.</div>

          <div class="field">
            <label class="form-label">How IQPigeon should behave</label>
            <div style="position:relative">
              <textarea class="textarea" name="behavior_prompt" id="behaviorPrompt" rows="5"
                oninput="document.getElementById('behaviorCount').textContent=this.value.length"
                style="font-size:13.5px;line-height:1.6"><?= sanitize($behaviorPrompt) ?></textarea>
              <div style="position:absolute;bottom:10px;right:12px;font-size:11.5px;color:var(--muted-2)">
                <span id="behaviorCount"><?= strlen($behaviorPrompt) ?></span>/2000
              </div>
            </div>
          </div>

          <!-- Tone / Formality / Language Style row -->
          <div class="grid grid-3" style="margin-bottom:16px">
            <div class="field" style="margin:0">
              <label class="form-label">Tone</label>
              <select class="select" name="ai_tone">
                <?php foreach (['Friendly & Professional', 'Formal', 'Casual', 'Empathetic', 'Direct'] as $t): ?>
                <option<?= $aiTone === $t ? ' selected' : '' ?>><?= sanitize($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="margin:0">
              <label class="form-label">Formality</label>
              <select class="select" name="ai_formality">
                <?php foreach (['Professional', 'Semi-formal', 'Informal'] as $f): ?>
                <option<?= $aiFormality === $f ? ' selected' : '' ?>><?= sanitize($f) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="margin:0">
              <label class="form-label">Language Style</label>
              <select class="select" name="ai_language_style">
                <?php foreach (['Simple & Clear', 'Technical', 'Storytelling', 'Bullet-point'] as $s): ?>
                <option<?= $aiLangStyle === $s ? ' selected' : '' ?>><?= sanitize($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Applies To info bar -->
          <div style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--blue-600)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <span style="font-size:13px;color:var(--blue-700)">
              <strong>Behavior Summary</strong> — This master behavior is inherited by every business's bot and injected into every live AI reply.
            </span>
          </div>

          <div style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn--primary" style="display:flex;align-items:center;gap:7px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Save Changes
            </button>
          </div>
        </form>

        <?php if ($section === 'behavior'): ?>
        <div class="divider" style="margin:22px 0"></div>
        <!-- Core Principles -->
        <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:4px">Core Principles</div>
        <div class="muted small" style="margin-bottom:12px">These principles are appended to every AI reply's instructions, platform-wide.</div>
        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px" id="principlesList">
          <?php foreach ($principles as $i => $pr): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:11px 14px;border:1px solid var(--line-2);border-radius:10px;background:#fff">
            <div style="width:18px;height:18px;border-radius:5px;background:var(--green-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="var(--green-700)" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <span style="font-size:13.5px;flex:1"><?= sanitize($pr) ?></span>
            <form method="POST" onsubmit="return confirm('Remove this principle?');">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="delete_principle"/>
              <input type="hidden" name="index" value="<?= (int) $i ?>"/>
              <button type="submit" title="Remove" style="color:var(--muted-2);background:none;border:none;cursor:pointer;padding:4px">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
              </button>
            </form>
          </div>
          <?php endforeach; ?>
          <?php if ($principles === []): ?>
          <div class="muted small" style="padding:10px 2px">No principles yet — add one below.</div>
          <?php endif; ?>
        </div>
        <form method="POST" style="display:flex;gap:8px;margin-bottom:16px">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="add_principle"/>
          <input class="input" name="principle_text" placeholder="e.g. Always confirm the order total before checkout" style="flex:1"/>
          <button type="submit" class="btn btn--ghost btn--sm" style="display:flex;align-items:center;gap:7px;white-space:nowrap">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Add Principle
          </button>
        </form>
        <?php endif; ?>

        <?php elseif (isset($sectionIds[$section])): ?>
        <!-- Generic per-section note, saved + actually injected into the live prompt -->
        <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px"><?= sanitize($sectionIds[$section]) ?></div>
        <div class="muted small" style="margin-bottom:14px"><?= sanitize($sectionCopy[$section] ?? '') ?></div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="save_section"/>
          <input type="hidden" name="section_id" value="<?= sanitize($section) ?>"/>
          <div class="field">
            <textarea class="textarea" name="section_text" rows="10" style="font-size:13.5px;line-height:1.6"
              placeholder="Write the rule(s) for this category — they'll be appended to every AI reply's instructions."
            ><?= sanitize((string) get_setting('ai_section_' . $section, '')) ?></textarea>
          </div>
          <div style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn--primary" style="display:flex;align-items:center;gap:7px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Save Changes
            </button>
          </div>
        </form>
        <?php endif; ?>

      </div><!-- /card__body -->
    </div><!-- /card -->

  </div><!-- /centre -->

  <!-- Right rail -->
  <div class="stack">

    <!-- Behavior Preview -->
    <div class="card" style="position:sticky;top:16px">
      <div class="card__head">
        <span class="card__title">Behavior Preview</span>
      </div>
      <div class="card__body">
        <div class="muted small" style="margin-bottom:10px">See how the AI responds using everything saved on this page (base prompt, behavior, tone, principles, sections, guardrails).</div>
        <div class="field">
          <label class="form-label">Test a message</label>
          <input class="input" id="aiPreviewInput" type="text" placeholder="How can you help me today?" value="How can you help me today?"/>
        </div>
        <button type="button" class="btn btn--primary btn--block btn--sm" id="aiPreviewBtn" style="margin-bottom:16px">
          <span style="display:flex;align-items:center;justify-content:center;gap:7px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Test Response
          </span>
        </button>

        <!-- Chat preview -->
        <div style="background:var(--field);border-radius:10px;overflow:hidden">
          <div style="background:var(--green-600);padding:10px 14px;display:flex;align-items:center;gap:8px">
            <div style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff">IQ</div>
            <div style="color:#fff;font-size:13px;font-weight:600">IQPigeon AI</div>
          </div>
          <div id="aiPreviewChat" style="padding:12px;display:flex;flex-direction:column;gap:10px;min-height:160px">
            <div style="background:#fff;border:1px solid var(--line);border-radius:10px;border-bottom-left-radius:2px;padding:10px 12px;font-size:13px;line-height:1.5;color:var(--ink);max-width:90%;align-self:flex-start" id="previewResponse">
              Click "Test Response" to see a live reply generated from the settings on this page.
              <div style="font-size:10px;color:var(--muted-2);margin-top:5px"><?= date('g:i A') ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Key Settings -->
    <div class="card">
      <div class="card__head"><span class="card__title">Key Settings</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
        <div class="between"><span class="muted small">Applies To</span><span class="strong small">All Businesses</span></div>
        <div class="between"><span class="muted small">Principles</span><span class="strong small"><?= count($principles) ?> active</span></div>
        <div class="between"><span class="muted small">Guardrails</span><span class="strong small"><?= count(array_filter($guardrails, static fn ($g) => $g['enabled'])) ?>/<?= count($guardrails) ?> enabled</span></div>
        <div class="between"><span class="muted small">Snapshots Saved</span><span class="strong small"><?= count($snapshots) ?></span></div>
        <div class="divider" style="margin:4px 0"></div>
        <a href="/admin/audit" style="font-size:13px;color:var(--green-700);font-weight:600;display:flex;align-items:center;gap:5px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          View Change History
        </a>
      </div>
    </div>

    <!-- Version History (snapshots) -->
    <?php if ($snapshots !== []): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Version History</span></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:8px;max-height:260px;overflow-y:auto">
        <?php foreach ($snapshots as $snap): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--line-2)">
          <div style="min-width:0">
            <div class="strong small"><?= sanitize(iqp_rel_time((string) ($snap['created_at'] ?? '')) ?: (string) ($snap['created_at'] ?? '')) ?></div>
            <div class="muted small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">by <?= sanitize((string) ($snap['created_by'] ?? 'Admin')) ?></div>
          </div>
          <form method="POST" onsubmit="return confirm('Restore this snapshot? It will replace current Master Behavior settings.');">
            <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
            <input type="hidden" name="action" value="restore_snapshot"/>
            <input type="hidden" name="snapshot_id" value="<?= sanitize((string) ($snap['id'] ?? '')) ?>"/>
            <button type="submit" class="btn btn--ghost btn--sm">Restore</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /right rail -->
</div><!-- /3-col grid -->

<?php elseif ($mainTab === 'templates'): ?>
<!-- ===================== INDUSTRY TEMPLATES ===================== -->
<?php
$editKey = isset($_GET['edit']) ? preg_replace('/[^a-z0-9_]/', '', (string) $_GET['edit']) : '';
$isNew   = isset($_GET['new']);
$allTemplates = industry_templates_all();
$builtInKeys  = array_keys(industry_training_templates());

$tileColors = ['green', 'blue', 'red', 'purple', 'amber', 'pink', 'cyan', 'orange'];
?>

<?php if ($isNew || $editKey !== ''): ?>
<?php $editTpl = $editKey !== '' ? ($allTemplates[$editKey] ?? null) : null; ?>
<div class="card">
  <div class="card__head">
    <span class="card__title"><?= $editTpl ? 'Edit Template — ' . sanitize($editTpl['label']) : 'Add Industry Template' ?></span>
    <span class="spacer"></span>
    <a href="/admin/ai?tab=templates" class="btn btn--ghost btn--sm">Cancel</a>
  </div>
  <div class="card__body">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="save_industry_template"/>
      <?php if ($editTpl): ?><input type="hidden" name="template_key" value="<?= sanitize($editKey) ?>"/><?php endif; ?>
      <div class="grid grid-2" style="margin-bottom:12px">
        <div class="field" style="margin:0">
          <label class="form-label">Template Name</label>
          <input class="input" name="label" required value="<?= sanitize((string) ($editTpl['label'] ?? '')) ?>" placeholder="e.g. Fitness / Gym"/>
        </div>
        <div class="field" style="margin:0">
          <label class="form-label">Conversion Goal</label>
          <select class="select" name="conversion_goal">
            <?php foreach (['order_placed' => 'Order placed', 'call_booked' => 'Call booked', 'trial_started' => 'Trial started'] as $gv => $gl): ?>
            <option value="<?= sanitize($gv) ?>"<?= ($editTpl['conversion_goal'] ?? '') === $gv ? ' selected' : '' ?>><?= sanitize($gl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label class="form-label">Business Mode</label>
        <select class="select" name="business_mode">
          <?php foreach (['ecommerce' => 'E-commerce', 'services' => 'Services', 'saas' => 'SaaS', 'mixed' => 'Mixed'] as $mv => $ml): ?>
          <option value="<?= sanitize($mv) ?>"<?= ($editTpl['business_mode'] ?? '') === $mv ? ' selected' : '' ?>><?= sanitize($ml) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="form-label">What This Business Offers (one-line pitch)</label>
        <textarea class="textarea" name="offer" rows="2"><?= sanitize((string) ($editTpl['offer'] ?? '')) ?></textarea>
      </div>
      <div class="field">
        <label class="form-label">Starter Knowledge Document</label>
        <textarea class="textarea" name="knowledge" rows="8" style="font-family:monospace;font-size:12.5px"><?= sanitize((string) ($editTpl['knowledge'] ?? '')) ?></textarea>
        <div class="muted small" style="margin-top:6px">This is pre-filled into a client's Knowledge tab when they apply this industry. Each client can replace the qualifying questions from Training → Qualify without changing this global template.</div>
      </div>
      <div class="field">
        <label class="form-label">Qualifying Questions (one per line)</label>
        <textarea class="textarea" name="questions" rows="4"><?= sanitize(implode("\n", (array) ($editTpl['questions'] ?? []))) ?></textarea>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px">
        <button type="submit" class="btn btn--primary">Save Template</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="card__head">
    <span class="card__title">Industry Templates</span>
    <span class="spacer"></span>
    <a class="btn btn--primary btn--sm" href="/admin/ai?tab=templates&new=1" style="display:flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
      Add Template
    </a>
  </div>
  <div class="card__body">
    <p class="muted small" style="margin-bottom:14px">These templates are global starters. Each business can replace the qualifying questions from their own Training → Qualify tab without changing this list.</p>
    <div class="grid grid-3">
      <?php $i = 0; foreach ($allTemplates as $tkey => $tpl):
          $tcolor = $tileColors[$i % count($tileColors)]; $i++;
          $isCustom = !in_array($tkey, $builtInKeys, true);
      ?>
      <div class="card" style="border:1px solid var(--line)">
        <div class="card__body">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div class="stat-tile <?= $tcolor ?>" style="width:38px;height:38px;border-radius:10px">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 20h20M5 20V8l7-6 7 6v12"/></svg>
            </div>
            <div class="strong" style="font-size:13.5px"><?= sanitize($tpl['label']) ?></div>
          </div>
          <div class="muted small" style="margin-bottom:12px;min-height:36px"><?= sanitize(mb_strimwidth((string) ($tpl['offer'] ?? ''), 0, 90, '…')) ?></div>
          <div style="display:flex;gap:6px">
            <a href="/admin/ai?tab=templates&edit=<?= sanitize($tkey) ?>" class="btn btn--ghost btn--sm" style="flex:1;justify-content:center">Edit</a>
            <?php if ($isCustom): ?>
            <form method="POST" onsubmit="return confirm('Delete this custom template?');">
              <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
              <input type="hidden" name="action" value="delete_industry_template"/>
              <input type="hidden" name="template_key" value="<?= sanitize($tkey) ?>"/>
              <button type="submit" class="btn btn--ghost btn--sm" title="Delete">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
              </button>
            </form>
            <?php endif; ?>
            <span class="badge <?= $isCustom ? 'badge--blue' : 'badge--green' ?>" style="font-size:11.5px;align-self:center"><?= $isCustom ? 'Custom' : 'Built-in' ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:14px">These templates are industry defaults. Businesses pick a type under Training → Business. Changing type never wipes their knowledge. Edits here affect new associations and empty fields only.</p>
  </div>
</div>
<?php endif; ?>

<?php elseif ($mainTab === 'intelligence'): ?>
<?php
require_once __DIR__ . '/../includes/conversation-turn-engine.php';
$engineStatus = conversation_engine_core_status();
?>
<!-- ===================== CONVERSATION INTELLIGENCE (read-only) ===================== -->
<div class="card">
  <div class="card__head" style="flex-wrap:wrap;gap:10px">
    <div class="grow">
      <div class="strong" style="font-size:16px">Conversation Engine</div>
      <div class="muted small" style="margin-top:3px">Intent, context, and turn handling. Always on. Customer keyword hints remain compatibility data — the engine should understand meaning, not only exact words.</div>
    </div>
    <span class="badge badge--green" style="font-size:12.5px">
      <span style="width:7px;height:7px;border-radius:50%;background:currentColor"></span> Active
    </span>
  </div>
  <div class="card__body">
    <p style="font-size:13.5px;line-height:1.6;color:var(--ink);margin:0 0 16px">
      IQ Pigeon automatically combines related customer messages, media and voice notes into conversational turns before generating a response.
      The representative reads, listens, looks at images, forms a verdict, then sends one WhatsApp reply.
    </p>
    <div class="stack" style="gap:8px">
      <?php foreach ($engineStatus as $row): ?>
      <div class="between" style="padding:10px 12px;border:1px solid var(--line-2);border-radius:10px;background:#fff">
        <span class="strong small"><?= sanitize($row['label']) ?></span>
        <span class="badge badge--green" style="font-size:11.5px"><?= sanitize($row['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php
      require_once __DIR__ . '/../includes/conversation-intelligence.php';
      $intentList = defined('CI_INTENTS') ? CI_INTENTS : [];
    ?>
    <?php if ($intentList !== []): ?>
    <div style="margin-top:18px">
      <div class="strong small" style="margin-bottom:8px">Recognized intents</div>
      <p class="muted small" style="margin-bottom:10px">Do not create a second intent catalog. Keyword hints from businesses remain compatibility examples for these intents.</p>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($intentList as $intentName): ?>
        <span class="badge" style="font-size:11px"><?= sanitize((string) $intentName) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($mainTab === 'safety'): ?>
<!-- ===================== SAFETY & CONTROL ===================== -->
<div class="grid" style="grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:start">
  <div class="card" style="position:sticky;top:16px">
    <?php
    $secNav = [];
    foreach ($safetyNav as [$sid, $slabel]) {
        $secNav[] = [$sid, $slabel, '/admin/ai?tab=safety&sec=' . $sid];
    }
    iqp_section_nav($section, $secNav, ['class' => 'iqp-section-nav--pad']);
    ?>
    <div class="muted small" style="padding:12px">These rules outrank business knowledge, industry templates, and customer tone.</div>
  </div>
  <div>
<?php if ($section === 'guardrails'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Global Guardrails</span></div>
  <div class="card__body">
    <p class="muted" style="margin-bottom:16px">Hard limits for every business. Enabled rules are injected above business knowledge on every reply.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
      <input type="hidden" name="action" value="save_guardrails"/>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($guardrails as $slug => $g): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid var(--line-2);border-radius:10px">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:18px;height:18px;border-radius:5px;background:var(--green-100);display:flex;align-items:center;justify-content:center">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="var(--green-700)" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <span style="font-size:13.5px"><?= sanitize($g['label']) ?></span>
          </div>
          <label class="switch"><input type="checkbox" name="guardrail[<?= sanitize($slug) ?>]" <?= $g['enabled'] ? 'checked' : '' ?>><span class="track"></span></label>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn--primary" style="margin-top:16px">Save Changes</button>
    </form>
  </div>
</div>
<?php elseif (isset($sectionIds[$section])): ?>
<div class="card">
  <div class="card__body">
        <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px"><?= sanitize($sectionIds[$section]) ?></div>
        <div class="muted small" style="margin-bottom:14px"><?= sanitize($sectionCopy[$section] ?? '') ?></div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
          <input type="hidden" name="action" value="save_section"/>
          <input type="hidden" name="section_id" value="<?= sanitize($section) ?>"/>
          <div class="field">
            <textarea class="textarea" name="section_text" rows="10" style="font-size:13.5px;line-height:1.6"
              placeholder="Write the rule(s) for this category — they apply to every business and cannot be overridden by customer settings."
            ><?= sanitize((string) get_setting('ai_section_' . $section, '')) ?></textarea>
          </div>
          <div style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn--primary">Save Changes</button>
          </div>
        </form>
  </div>
</div>
<?php endif; ?>
  </div>
</div>

<?php elseif ($mainTab === 'versioning'): ?>
<!-- ===================== MODEL VERSION CONTROL ===================== -->
<div class="card">
  <div class="card__head"><span class="card__title">Model Version Control</span></div>
  <div class="card__body">
    <div class="grid grid-2">
      <div class="card"><div class="card__body">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
          <div class="strong">Active Provider</div>
          <span class="badge badge--green"><span class="dot"></span>Live</span>
        </div>
        <div style="font-size:22px;font-weight:800;color:var(--ink)"><?= sanitize($openaiKey ? 'OpenAI' : 'Not configured') ?></div>
        <div class="muted small" style="margin-top:4px">Model: <?= sanitize($activeModel ?: '—') ?></div>
      </div></div>
      <div class="card"><div class="card__body">
        <div class="strong" style="margin-bottom:6px">Media (voice & vision)</div>
        <div style="font-size:18px;font-weight:700;color:var(--ink)"><?= sanitize(openai_media_configured() ? 'OpenAI' : 'Not configured') ?></div>
        <div class="muted small" style="margin-top:4px">Whisper + GPT-4o vision for WhatsApp media</div>
      </div></div>
    </div>
    <p class="muted small" style="margin-top:12px">API keys are managed in <a href="/admin/integrations" style="color:var(--green-700)">Integrations</a>. Keys are never displayed here.</p>
  </div>
</div>
<?php endif; ?>

<!-- Live test chat (always visible at bottom) -->
<div class="card" style="margin-top:18px" id="aiChatCard">
  <div class="card__head">
    <span class="card__title">Live AI Test Chat</span>
    <span class="spacer"></span>
    <span class="badge <?= $anyKey ? 'badge--green' : 'badge--red' ?>">
      <span class="dot"></span><?= $anyKey ? 'AI Connected' : 'No API Key' ?>
    </span>
  </div>
  <div class="card__body">
    <div id="aiChat" data-csrf="<?= sanitize($csrf) ?>">
      <div id="aiChatLog" style="min-height:160px;max-height:320px;overflow-y:auto;border:1px solid var(--line-2);border-radius:12px;padding:14px;background:var(--field);display:flex;flex-direction:column;gap:10px">
        <div class="muted small" id="aiChatEmpty">No messages yet — say hello to test the model with everything saved on this page.</div>
      </div>
      <div style="margin-top:10px;gap:8px;display:flex">
        <input class="input" id="aiChatInput" type="text" placeholder="Type a test message…" autocomplete="off" style="flex:1"<?= $anyKey ? '' : ' disabled' ?>>
        <button class="btn btn--primary" id="aiChatSend" type="button"<?= $anyKey ? '' : ' disabled' ?> style="display:flex;align-items:center;gap:7px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Send
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var behaviorSnapshot = <?= json_encode(ai_master_behavior_snapshot(), JSON_UNESCAPED_UNICODE) ?>;

  window.iqpExportBehaviorJson = function () {
    var blob = new Blob([JSON.stringify(behaviorSnapshot, null, 2)], {type: 'application/json'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'iqpigeon-master-behavior-' + new Date().toISOString().slice(0, 10) + '.json';
    document.body.appendChild(a); a.click(); a.remove();
  };

  var testBtn = document.getElementById('iqpTestAssistantBtn');
  if (testBtn) {
    testBtn.addEventListener('click', function () {
      var card = document.getElementById('aiChatCard');
      if (card) { card.scrollIntoView({behavior: 'smooth', block: 'start'}); }
      var input = document.getElementById('aiChatInput');
      if (input) { setTimeout(function () { input.focus(); }, 350); }
    });
  }

  // --- Live chat ---
  var root=document.getElementById('aiChat');
  if(!root) return;
  var log=document.getElementById('aiChatLog');
  var input=document.getElementById('aiChatInput');
  var sendBtn=document.getElementById('aiChatSend');
  var emptyMsg=document.getElementById('aiChatEmpty');
  var csrf=root.getAttribute('data-csrf')||'';
  var history=[];
  var busy=false;

  function esc(s){var d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML;}
  function bubble(role,text,kind){
    if(emptyMsg){emptyMsg.remove();emptyMsg=null;}
    var el=document.createElement('div');
    var mine=role==='user';
    el.style.cssText='max-width:80%;padding:9px 12px;border-radius:12px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;'+(
      mine?'align-self:flex-end;background:var(--green-600);color:#fff;':
      kind==='error'?'align-self:flex-start;background:#fde8e8;color:#b42318;border:1px solid #f5c2c2;':
      'align-self:flex-start;background:#fff;color:var(--ink);border:1px solid var(--line-2);');
    el.innerHTML=esc(text);log.appendChild(el);log.scrollTop=log.scrollHeight;return el;
  }
  function send(){
    if(busy)return;
    var text=(input.value||'').trim();
    if(!text)return;
    busy=true;sendBtn.disabled=true;input.value='';
    bubble('user',text);
    var prior=history.slice(-12);
    history.push({role:'user',content:text});
    var typing=bubble('assistant','…');
    var fd=new FormData();
    fd.append('csrf_token',csrf);fd.append('message',text);fd.append('history',JSON.stringify(prior));
    fetch('/api/admin-ai-test.php',{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>r.json().catch(()=>({ok:false,error:'Unexpected response.'})))
      .then(data=>{
        typing.remove();
        if(data&&data.ok){bubble('assistant',data.reply||'(empty)');history.push({role:'assistant',content:String(data.reply||'')});}
        else{bubble('assistant',(data&&data.error)||'AI unavailable.','error');}
      })
      .catch(()=>{typing.remove();bubble('assistant','Network error.','error');})
      .finally(()=>{busy=false;sendBtn.disabled=false;input.focus();});
  }
  sendBtn.addEventListener('click',send);
  input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});

  // --- Behavior preview test (same endpoint, dedicated bubble) ---
  var pvBtn=document.getElementById('aiPreviewBtn');
  var pvInput=document.getElementById('aiPreviewInput');
  var pvResp=document.getElementById('previewResponse');
  if(pvBtn&&pvInput&&pvResp){
    pvBtn.addEventListener('click',async function(){
      var msg=(pvInput.value||'').trim();if(!msg)return;
      pvResp.textContent='…';
      try{
        var fd=new FormData();fd.append('csrf_token',csrf);fd.append('message',msg);fd.append('history','[]');
        var r=await fetch('/api/admin-ai-test.php',{method:'POST',body:fd,credentials:'same-origin'});
        var d=await r.json().catch(()=>({ok:false}));
        pvResp.textContent=d.ok?d.reply:(d.error||'(AI unavailable)');
      }catch(e){pvResp.textContent='(Network error)';}
    });
  }
})();
</script>
<?php iqp_admin_end();
