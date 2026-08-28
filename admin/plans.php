<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/platform-settings.php';

$user = require_admin();

$message = '';
$error = '';
$defaults = plan_defaults();
$stored = get_admin_plans_raw();
$plans = $stored !== [] ? $stored : [];

// Seed any default plan that has never been customised so it shows up as editable.
foreach ($defaults as $slug => $plan) {
    if (!isset($plans[$slug])) {
        $plans[$slug] = array_merge($plan, ['enabled' => true]);
        $plans[$slug]['features_text'] = implode("\n", $plan['features'] ?? []);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'add') {
        $slug = strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim($_POST['new_slug'] ?? '')) ?? '');
        $slug = trim($slug, '_');
        if ($slug === '' || isset($plans[$slug])) {
            $error = 'Enter a unique plan slug (letters, numbers, underscores).';
        } else {
            $plans[$slug] = [
                'name'          => trim($_POST['new_name'] ?? ucfirst($slug)),
                'price_usd'     => (int) ($_POST['new_price_usd'] ?? 0),
                'price_pkr'     => (int) ($_POST['new_price_pkr'] ?? 0),
                'bots'          => trim($_POST['new_bots'] ?? '1'),
                'chats'         => trim($_POST['new_chats'] ?? '100'),
                'leads'         => trim($_POST['new_leads'] ?? ''),
                'popular'       => !empty($_POST['new_popular']),
                'contact_only'  => !empty($_POST['new_contact_only']),
                'enabled'       => true,
                'features_text' => trim($_POST['new_features'] ?? ''),
            ];
            save_admin_plans($plans);
            $message = 'Plan added.';
        }
    } elseif ($action === 'delete') {
        $slug = trim($_POST['plan_slug'] ?? '');
        if ($slug !== '' && isset($plans[$slug])) {
            $plans[$slug]['deleted'] = true;
            $plans[$slug]['enabled'] = false;
            save_admin_plans($plans);
            unset($plans[$slug]);
            $message = 'Plan removed.';
        }
    } else {
        $submitted = $_POST['plans'] ?? [];
        if (!is_array($submitted)) {
            $error = 'Invalid plan data.';
        } else {
            foreach ($submitted as $slug => $data) {
                if (!is_string($slug) || !isset($plans[$slug])) {
                    continue;
                }
                $plans[$slug]['name'] = trim($data['name'] ?? $plans[$slug]['name'] ?? $slug);
                $plans[$slug]['price_usd'] = (int) ($data['price_usd'] ?? 0);
                $plans[$slug]['price_pkr'] = (int) ($data['price_pkr'] ?? 0);
                $plans[$slug]['price'] = $plans[$slug]['price_usd'];
                $plans[$slug]['bots'] = trim($data['bots'] ?? '1');
                $chatsRaw = trim($data['chats'] ?? '100');
                $plans[$slug]['chats'] = is_numeric($chatsRaw) ? (int) $chatsRaw : $chatsRaw;
                $plans[$slug]['leads'] = trim($data['leads'] ?? '');
                $plans[$slug]['popular'] = !empty($data['popular']);
                $plans[$slug]['contact_only'] = !empty($data['contact_only']);
                $plans[$slug]['enabled'] = !empty($data['enabled']);
                $plans[$slug]['features_text'] = trim($data['features_text'] ?? '');
                unset($plans[$slug]['deleted']);
            }
            save_admin_plans($plans);
            $message = 'Pricing plans saved.';
        }
    }
}

// Normalise features into arrays + build the comparison-matrix union.
$planFeatures = [];
$featureUnion = [];
foreach ($plans as $slug => $p) {
    $ft = $p['features_text'] ?? null;
    if ($ft !== null && $ft !== '') {
        $lines = preg_split('/\r\n|\r|\n/', (string) $ft) ?: [];
    } else {
        $lines = (array) ($p['features'] ?? []);
    }
    $arr = array_values(array_filter(array_map('trim', $lines)));
    $planFeatures[$slug] = $arr;
    foreach ($arr as $f) {
        $featureUnion[$f] = true;
    }
}
$featureUnion = array_keys($featureUnion);

// Header overview (real, derived from the plan set).
$totalPlans = count($plans);
$visiblePlans = 0;
$popularName = '—';
foreach ($plans as $slug => $p) {
    if (!empty($p['enabled'])) {
        $visiblePlans++;
    }
    if (!empty($p['popular'])) {
        $popularName = (string) ($p['name'] ?? $slug);
    }
}
$hiddenPlans = $totalPlans - $visiblePlans;

$customSlugs = array_values(array_filter(array_keys($plans), static fn($s) => !isset($defaults[$s])));

iqp_admin_begin($user, 'billing', [
    'title' => 'Plans & Pricing',
    'subtitle' => 'Names, prices, limits and features for every subscription tier',
    'actions' => '<a class="btn btn--ghost btn--sm" href="/admin/subscriptions">Subscriptions &amp; Billing</a><a class="btn btn--primary btn--sm" href="#add-plan"><span class="ic" data-ic="plus"></span>Add plan</a>',
]);
iqp_flash($message);
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
<div class="stat-grid cols-4" style="margin-bottom:20px">
  <div class="stat-card"><div class="stat-card__label">Plans</div><div class="stat-card__value"><?= (int) $totalPlans ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Visible on pricing</div><div class="stat-card__value"><?= (int) $visiblePlans ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Hidden</div><div class="stat-card__value"><?= (int) $hiddenPlans ?></div></div>
  <div class="stat-card"><div class="stat-card__label">Most popular</div><div class="stat-card__value" style="font-size:20px"><?= sanitize($popularName) ?></div></div>
</div>

<form method="post">
  <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
  <input type="hidden" name="action" value="save"/>

  <?php foreach ($plans as $slug => $plan):
      $name = (string) ($plan['name'] ?? $slug);
      $pkr = (int) ($plan['price_pkr'] ?? 0);
      $usd = (int) ($plan['price_usd'] ?? $plan['price'] ?? 0);
      $botsVal = trim((string) ($plan['bots'] ?? '1'));
      $chatsVal = trim((string) ($plan['chats'] ?? ''));
      $leadsVal = trim((string) ($plan['leads'] ?? ''));
      $popular = !empty($plan['popular']);
      $contactOnly = !empty($plan['contact_only']);
      $enabled = !empty($plan['enabled']);
      $ftVal = $plan['features_text'] ?? implode("\n", $planFeatures[$slug]);

      $metaParts = [];
      $metaParts[] = ($botsVal === '' ? '0' : $botsVal) . ' ' . ($botsVal === '1' ? 'bot' : 'bots');
      if ($chatsVal !== '') {
          $metaParts[] = $chatsVal . ' chats';
      }
      $metaParts[] = ($leadsVal === '' ? 'Unlimited' : $leadsVal) . ' leads';
      $metaStr = implode(' · ', $metaParts);
  ?>
  <div class="card" data-plan-card="<?= sanitize($slug) ?>" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title" data-pv-head-name><?= sanitize($name) ?></span>
      <span class="badge badge--gray"><?= sanitize($slug) ?></span>
      <?php if (!isset($defaults[$slug])): ?><span class="badge badge--blue">Custom</span><?php endif; ?>
      <div class="spacer"></div>
      <span class="small muted">Visible</span>
      <label class="switch" title="Show on the public pricing page">
        <input type="checkbox" name="plans[<?= sanitize($slug) ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?>/>
        <span class="track"></span>
      </label>
    </div>
    <div class="card__body">
      <div class="grid grid-2">
        <!-- Editor -->
        <div>
          <div class="field">
            <label>Display name</label>
            <input class="input" type="text" name="plans[<?= sanitize($slug) ?>][name]" value="<?= sanitize($name) ?>" data-field="name"/>
          </div>
          <div class="grid grid-2">
            <div class="field">
              <label>Price (PKR / month)</label>
              <input class="input" type="number" min="0" name="plans[<?= sanitize($slug) ?>][price_pkr]" value="<?= sanitize((string) $pkr) ?>" data-field="price_pkr"/>
            </div>
            <div class="field">
              <label>Price (USD / month)</label>
              <input class="input" type="number" min="0" name="plans[<?= sanitize($slug) ?>][price_usd]" value="<?= sanitize((string) $usd) ?>"/>
            </div>
          </div>
          <div class="grid grid-3">
            <div class="field">
              <label>Bots</label>
              <input class="input" type="text" name="plans[<?= sanitize($slug) ?>][bots]" value="<?= sanitize($botsVal) ?>" data-field="bots"/>
            </div>
            <div class="field">
              <label>Chats / mo</label>
              <input class="input" type="text" name="plans[<?= sanitize($slug) ?>][chats]" value="<?= sanitize($chatsVal) ?>" data-field="chats"/>
            </div>
            <div class="field">
              <label>Leads</label>
              <input class="input" type="text" name="plans[<?= sanitize($slug) ?>][leads]" value="<?= sanitize($leadsVal) ?>" placeholder="Unlimited" data-field="leads"/>
            </div>
          </div>
          <div class="field">
            <label>Options</label>
            <div class="row wrap" style="gap:22px;align-items:center">
              <span class="center" style="gap:8px">
                <label class="switch">
                  <input type="checkbox" name="plans[<?= sanitize($slug) ?>][popular]" value="1" <?= $popular ? 'checked' : '' ?> data-field="popular"/>
                  <span class="track"></span>
                </label>
                <span class="small">Most popular</span>
              </span>
              <span class="center" style="gap:8px">
                <label class="switch">
                  <input type="checkbox" name="plans[<?= sanitize($slug) ?>][contact_only]" value="1" <?= $contactOnly ? 'checked' : '' ?> data-field="contact_only"/>
                  <span class="track"></span>
                </label>
                <span class="small">Contact sales only</span>
              </span>
            </div>
          </div>
          <div class="field" style="margin-bottom:0">
            <label>Features <span class="hint">one per line</span></label>
            <textarea class="textarea" rows="6" name="plans[<?= sanitize($slug) ?>][features_text]" data-field="features"><?= sanitize($ftVal) ?></textarea>
          </div>
        </div>

        <!-- Live preview -->
        <div>
          <div class="eyebrow" style="margin-bottom:10px">Customer preview</div>
          <div class="card" data-preview="<?= sanitize($slug) ?>" style="box-shadow:none;border:1px solid var(--line)">
            <div class="card__body">
              <div class="between">
                <span class="strong" data-pv="name" style="font-size:16px"><?= sanitize($name) ?></span>
                <span class="badge badge--green" data-pv="popular" style="<?= $popular ? '' : 'display:none' ?>"><span class="ic" data-ic="crown"></span>Popular</span>
              </div>
              <div data-pv="price" style="margin:12px 0 4px;font-size:26px;font-weight:800;letter-spacing:-.02em">
                <?php if ($contactOnly): ?>Custom<?php else: ?>PKR <?= number_format($pkr, 0) ?><span class="muted" style="font-size:13px;font-weight:600">/mo</span><?php endif; ?>
              </div>
              <div class="muted small" data-pv="meta"><?= sanitize($metaStr) ?></div>
              <div class="divider"></div>
              <div class="list" data-pv="features" style="gap:8px">
                <?php if ($planFeatures[$slug] === []): ?>
                  <div class="muted small">No features listed.</div>
                <?php else: foreach ($planFeatures[$slug] as $f): ?>
                  <div class="center" style="gap:8px"><span style="color:#1FA855;font-weight:700">&#10003;</span><span class="small"><?= sanitize($f) ?></span></div>
                <?php endforeach; endif; ?>
              </div>
              <button type="button" class="btn btn--primary btn--block" style="margin-top:16px" disabled><?= $contactOnly ? 'Contact Sales' : 'Get Started' ?></button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="row" style="gap:12px">
    <button type="submit" class="btn btn--primary"><span class="ic" data-ic="check"></span>Save all plans</button>
    <a class="btn btn--ghost" href="/admin/subscriptions">Cancel</a>
  </div>
</form>

<!-- Feature comparison matrix (real features across every plan) -->
<div class="card" style="margin-top:24px">
  <div class="card__head"><span class="card__title">Feature comparison</span></div>
  <div class="table-wrap"><table class="tbl">
    <thead>
      <tr>
        <th>Feature</th>
        <?php foreach ($plans as $slug => $plan): ?>
        <th class="right"><?= sanitize((string) ($plan['name'] ?? $slug)) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if ($featureUnion === []): ?>
      <tr><td colspan="<?= (int) ($totalPlans + 1) ?>" class="muted">No features defined yet. Add feature lines to a plan above.</td></tr>
      <?php else: foreach ($featureUnion as $feature): ?>
      <tr>
        <td class="strong"><?= sanitize($feature) ?></td>
        <?php foreach ($plans as $slug => $plan): ?>
        <td class="right">
          <?php if (in_array($feature, $planFeatures[$slug], true)): ?>
            <span style="color:#1FA855"><span class="ic" data-ic="check"></span></span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($customSlugs !== []): ?>
<div class="card" style="margin-top:24px">
  <div class="card__head"><span class="card__title">Custom plans</span></div>
  <div class="card__body">
    <p class="muted small" style="margin-top:0">Default tiers (Starter, Pro, Enterprise) cannot be removed. Custom plans can be deleted below.</p>
    <?php foreach ($customSlugs as $slug): ?>
    <div class="between" style="padding:10px 0;border-bottom:1px solid var(--line-2)">
      <span class="strong"><?= sanitize((string) ($plans[$slug]['name'] ?? $slug)) ?> <span class="muted small">(<?= sanitize($slug) ?>)</span></span>
      <form method="post" onsubmit="return confirm('Remove this plan?');" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
        <input type="hidden" name="action" value="delete"/>
        <input type="hidden" name="plan_slug" value="<?= sanitize($slug) ?>"/>
        <button type="submit" class="btn btn--danger btn--sm"><span class="ic" data-ic="trash"></span>Remove</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Add a new plan -->
<div class="card" id="add-plan" style="margin-top:24px">
  <div class="card__head"><span class="card__title">Add a new plan</span></div>
  <div class="card__body">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="add"/>
      <div class="grid grid-2">
        <div class="field">
          <label>Slug <span class="hint">lowercase, e.g. agency</span></label>
          <input class="input" type="text" name="new_slug" required pattern="[a-z0-9_]+"/>
        </div>
        <div class="field">
          <label>Display name</label>
          <input class="input" type="text" name="new_name" required/>
        </div>
      </div>
      <div class="grid grid-2">
        <div class="field">
          <label>Price (PKR / month)</label>
          <input class="input" type="number" min="0" name="new_price_pkr" value="0"/>
        </div>
        <div class="field">
          <label>Price (USD / month)</label>
          <input class="input" type="number" min="0" name="new_price_usd" value="0"/>
        </div>
      </div>
      <div class="grid grid-3">
        <div class="field">
          <label>Bots</label>
          <input class="input" type="text" name="new_bots" value="1"/>
        </div>
        <div class="field">
          <label>Chats / mo</label>
          <input class="input" type="text" name="new_chats" value="100"/>
        </div>
        <div class="field">
          <label>Leads</label>
          <input class="input" type="text" name="new_leads" placeholder="Unlimited"/>
        </div>
      </div>
      <div class="field">
        <label>Options</label>
        <div class="row wrap" style="gap:22px;align-items:center">
          <span class="center" style="gap:8px">
            <label class="switch"><input type="checkbox" name="new_popular" value="1"/><span class="track"></span></label>
            <span class="small">Most popular</span>
          </span>
          <span class="center" style="gap:8px">
            <label class="switch"><input type="checkbox" name="new_contact_only" value="1"/><span class="track"></span></label>
            <span class="small">Contact sales only</span>
          </span>
        </div>
      </div>
      <div class="field">
        <label>Features <span class="hint">one per line</span></label>
        <textarea class="textarea" rows="4" name="new_features" placeholder="1 AI sales bot&#10;500 client chats/month&#10;WhatsApp + website widget"></textarea>
      </div>
      <button type="submit" class="btn btn--primary"><span class="ic" data-ic="plus"></span>Add plan</button>
    </form>
  </div>
</div>

<script>
(function () {
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function fmtPKR(v) {
    var n = parseInt(String(v).replace(/[^0-9]/g, ''), 10);
    if (isNaN(n)) { n = 0; }
    return 'PKR ' + n.toLocaleString('en-US');
  }
  document.querySelectorAll('[data-plan-card]').forEach(function (card) {
    var slug = card.getAttribute('data-plan-card');
    var pv = document.querySelector('[data-preview="' + slug + '"]');
    if (!pv) { return; }
    var f = function (name) { return card.querySelector('[data-field="' + name + '"]'); };
    var pvq = function (name) { return pv.querySelector('[data-pv="' + name + '"]'); };
    var isContact = function () { var c = f('contact_only'); return !!(c && c.checked); };

    function update() {
      var nameVal = f('name') ? (f('name').value.trim() || slug) : slug;
      var nameEl = pvq('name'); if (nameEl) { nameEl.textContent = nameVal; }
      var headEl = card.querySelector('[data-pv-head-name]'); if (headEl) { headEl.textContent = nameVal; }

      var priceEl = pvq('price');
      if (priceEl) {
        if (isContact()) {
          priceEl.textContent = 'Custom';
        } else {
          priceEl.innerHTML = esc(fmtPKR(f('price_pkr') ? f('price_pkr').value : 0))
            + '<span class="muted" style="font-size:13px;font-weight:600">/mo</span>';
        }
      }

      var metaEl = pvq('meta');
      if (metaEl) {
        var bots = f('bots') ? f('bots').value.trim() : '';
        var chats = f('chats') ? f('chats').value.trim() : '';
        var leads = f('leads') ? f('leads').value.trim() : '';
        var parts = [];
        parts.push((bots === '' ? '0' : bots) + ' ' + (bots === '1' ? 'bot' : 'bots'));
        if (chats !== '') { parts.push(chats + ' chats'); }
        parts.push((leads === '' ? 'Unlimited' : leads) + ' leads');
        metaEl.textContent = parts.join(' · ');
      }

      var popEl = pvq('popular'); var pc = f('popular');
      if (popEl) { popEl.style.display = (pc && pc.checked) ? '' : 'none'; }

      var btn = pv.querySelector('.btn--block');
      if (btn) { btn.textContent = isContact() ? 'Contact Sales' : 'Get Started'; }

      var featEl = pvq('features'); var ft = f('features');
      if (featEl && ft) {
        var lines = ft.value.split(/\r\n|\r|\n/).map(function (x) { return x.trim(); }).filter(Boolean);
        featEl.innerHTML = lines.length
          ? lines.map(function (l) {
              return '<div class="center" style="gap:8px"><span style="color:#1FA855;font-weight:700">✓</span><span class="small">' + esc(l) + '</span></div>';
            }).join('')
          : '<div class="muted small">No features listed.</div>';
      }
    }

    card.querySelectorAll('[data-field]').forEach(function (inp) {
      inp.addEventListener('input', update);
      inp.addEventListener('change', update);
    });
    update();
  });
})();
</script>
<?php iqp_admin_end();
