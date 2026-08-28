<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/client-shell.php';
require_once __DIR__ . '/../includes/client-header.php';
require_once __DIR__ . '/../includes/shipment.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/platform-settings.php';

ensure_shipment_schema();

$user = require_login();
require_client_feature('courier');
$userId = (int) $user['id'];
$message = '';
$error = '';
$bulkResult = null;

$bots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name ASC', 'i', [$userId]);
$botId = (int) ($_GET['bot_id'] ?? ($bots[0]['id'] ?? 0));
$tab = in_array($_GET['tab'] ?? 'manual', ['manual', 'api', 'bulk'], true) ? ($_GET['tab'] ?? 'manual') : 'manual';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $postTab = $_POST['form_tab'] ?? 'api';
    $botId = (int) ($_POST['bot_id'] ?? 0);
    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);

    if (!$owned) {
        $error = 'Invalid bot.';
    } elseif ($postTab === 'api' && courier_settings_save($botId, $userId, $_POST)) {
        $message = 'Courier API settings saved.';
        $tab = 'api';
    } elseif ($postTab === 'bulk') {
        $csvRaw = trim((string) ($_POST['csv_text'] ?? ''));
        if ($csvRaw === '' && !empty($_FILES['csv_file']['tmp_name'])) {
            $csvRaw = (string) file_get_contents($_FILES['csv_file']['tmp_name']);
        }
        $rows = [];
        if ($csvRaw !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $csvRaw) ?: [];
            foreach ($lines as $i => $line) {
                $line = trim($line);
                if ($line === '' || ($i === 0 && stripos($line, 'order_id') !== false)) {
                    continue;
                }
                $cols = str_getcsv($line);
                if (count($cols) < 3) {
                    continue;
                }
                $rows[] = [
                    'order_id'           => $cols[0] ?? '',
                    'courier_name'       => $cols[1] ?? '',
                    'tracking_number'    => $cols[2] ?? '',
                    'dispatch_date'      => $cols[3] ?? '',
                    'estimated_delivery' => $cols[4] ?? '',
                    'tracking_url'       => $cols[5] ?? '',
                    'notes'              => $cols[6] ?? '',
                ];
            }
        }
        if ($rows === []) {
            $error = 'Paste or upload a CSV with order_id, courier, tracking columns.';
            $tab = 'bulk';
        } else {
            $bulkResult = shipment_bulk_import($userId, $rows);
            $message = 'Imported ' . (int) $bulkResult['imported'] . ' shipment(s). Skipped ' . (int) $bulkResult['skipped'] . '.';
            $tab = 'bulk';
        }
    }
}

$settings = $botId ? (courier_settings_for_bot($botId) ?: []) : [];
$providers = courier_provider_options();
$courierPresets = ['Leopards', 'TCS', 'BlueEx', 'Trax', 'M&P', 'PostEx', 'DHL', 'FedEx', 'UPS'];
$pendingOrders = $botId ? db_fetch_all(
    'SELECT o.id, o.customer_name, o.customer_phone, o.total_amount, o.currency, o.status, o.created_at
     FROM bot_orders o
     LEFT JOIN shipments s ON s.order_id = o.id
     WHERE o.bot_id = ? AND o.user_id = ? AND s.id IS NULL AND o.status IN (\'confirmed\', \'new\')
     ORDER BY o.created_at DESC LIMIT 100',
    'ii',
    [$botId, $userId]
) : [];
$activeTab = 'courier';
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Courier Settings') ?>
</head>
<body class="client-app v2-client bg-background font-body text-on-surface">
<?php client_shell_begin(); ?>
<?php include __DIR__ . '/../includes/client-nav.php'; ?>
<?php client_layout_start(); ?>
    <?php client_page_header('Courier', ['subtitle' => 'Tracking & dispatch']); ?>

    <?php if ($message): ?><p class="mb-md text-primary font-medium"><?= sanitize($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-md text-error font-medium"><?= sanitize($error) ?></p><?php endif; ?>
    <?php if ($bulkResult && !empty($bulkResult['errors'])): ?>
    <div class="mb-md p-md rounded-xl bg-error-container text-on-error-container text-body-sm">
        <?php foreach ($bulkResult['errors'] as $err): ?>
        <p><?= sanitize($err) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant mb-lg">
        <p class="text-body-md text-on-surface-variant">
            <strong>Manual mode (default):</strong> Enter courier + tracking when shipping. Customers get WhatsApp updates automatically.
            Upload the parcel receipt slip so your agent can send it when asked.
        </p>
        <p class="text-body-md text-on-surface-variant mt-sm">
            <strong>API mode (optional):</strong> Auto-refresh tracking every 30–60 minutes via cron.
        </p>
        <p class="text-body-sm text-outline mt-sm">
            Cron: <code>curl -s "<?= sanitize(rtrim(APP_URL, '/')) ?>/api/cron.php?key=YOUR_CRON_SECRET"</code>
        </p>
    </div>

    <?php if ($bots === []): ?>
    <p class="text-body-lg">Create a bot first.</p>
    <?php else: ?>
    <form method="get" class="mb-md flex flex-wrap items-end gap-md">
        <label>
            <span class="block text-label-sm text-on-surface-variant uppercase mb-xs font-label">Bot</span>
            <select name="bot_id" data-bot-switch onchange="this.form.submit()" class="h-12 px-md rounded-xl bg-surface-container border border-outline-variant min-w-[14rem]">
                <?php foreach ($bots as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === $botId ? ' selected' : '' ?>><?= sanitize($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="hidden" name="tab" value="<?= sanitize($tab) ?>"/>
        <button type="submit" class="h-12 px-lg rounded-xl bg-surface-container-high border border-outline-variant">Apply</button>
    </form>

    <nav class="flex gap-sm mb-lg border-b border-outline-variant pb-sm">
        <?php
        $tabs = [
            'manual' => 'Manual dispatch',
            'api'    => 'API settings',
            'bulk'   => 'Bulk CSV',
        ];
        foreach ($tabs as $key => $label):
        ?>
        <a href="?bot_id=<?= $botId ?>&tab=<?= $key ?>"
           class="px-md py-sm rounded-t-lg text-body-md font-medium <?= $tab === $key ? 'bg-primary text-on-primary' : 'text-on-surface-variant hover:bg-surface-container' ?>">
            <?= sanitize($label) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'manual'): ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-lg">
        <section class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant">
            <h2 class="font-title text-title-sm mb-md">Orders awaiting shipment</h2>
            <?php if ($pendingOrders === []): ?>
            <p class="text-body-md text-on-surface-variant">No confirmed orders without tracking. Check the Orders board.</p>
            <?php else: ?>
            <ul class="space-y-sm max-h-[28rem] overflow-y-auto">
                <?php foreach ($pendingOrders as $po):
                    $poTotal = catalog_format_price((float) $po['total_amount'], (string) ($po['currency'] ?? 'PKR'));
                ?>
                <li>
                    <button type="button" class="w-full text-left p-md rounded-xl border border-outline-variant hover:border-primary dispatch-order-pick"
                            data-order-id="<?= (int) $po['id'] ?>"
                            data-customer="<?= sanitize((string) ($po['customer_name'] ?? '')) ?>">
                        <strong>#<?= (int) $po['id'] ?></strong>
                        <?= sanitize((string) ($po['customer_name'] ?? 'Customer')) ?>
                        <span class="block text-label-sm text-outline"><?= sanitize($poTotal) ?> · <?= sanitize(ucfirst((string) $po['status'])) ?></span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant">
            <h2 class="font-title text-title-sm mb-md">Enter tracking details</h2>
            <form id="manual-dispatch-form" class="space-y-md" enctype="multipart/form-data">
                <input type="hidden" name="order_id" id="dispatch-order-id" value=""/>
                <p id="dispatch-order-label" class="text-body-sm text-on-surface-variant">Select an order from the list, or enter order ID below.</p>
                <label class="block">
                    <span class="text-label-sm font-label text-on-surface-variant">Order ID *</span>
                    <input type="number" name="order_id_visible" id="dispatch-order-id-visible" min="1"
                           class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
                </label>
                <label class="block">
                    <span class="text-label-sm font-label text-on-surface-variant">Courier company *</span>
                    <input type="text" name="courier_name" id="dispatch-courier" list="courier-presets" required
                           class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
                    <datalist id="courier-presets">
                        <?php foreach ($courierPresets as $preset): ?>
                        <option value="<?= sanitize($preset) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="block">
                    <span class="text-label-sm font-label text-on-surface-variant">Tracking / consignment number *</span>
                    <input type="text" name="tracking_number" id="dispatch-tracking" required
                           class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-sm">
                    <label class="block">
                        <span class="text-label-sm font-label text-on-surface-variant">Dispatch date</span>
                        <input type="date" name="dispatch_date" value="<?= date('Y-m-d') ?>"
                               class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
                    </label>
                    <label class="block">
                        <span class="text-label-sm font-label text-on-surface-variant">Estimated delivery</span>
                        <input type="date" name="estimated_delivery"
                               class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
                    </label>
                </div>
                <label class="block">
                    <span class="text-label-sm font-label text-on-surface-variant">Tracking URL (optional)</span>
                    <input type="url" name="tracking_url" id="dispatch-tracking-url" placeholder="Auto-filled for known couriers"
                           class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
                </label>
                <label class="block">
                    <span class="text-label-sm font-label text-on-surface-variant">Parcel receipt photo</span>
                    <p class="text-body-sm text-on-surface-variant mt-xs mb-xs">Photo of the consignment slip inside the parcel — sent when customer asks on WhatsApp.</p>
                    <input type="file" name="receipt_image" accept="image/jpeg,image/png,image/webp" class="w-full text-body-sm"/>
                </label>
                <label class="block">
                    <span class="text-label-sm font-label text-on-surface-variant">Notes (optional)</span>
                    <textarea name="notes" rows="2" class="w-full px-md py-sm rounded-xl bg-surface-container border border-outline-variant mt-xs"></textarea>
                </label>
                <button type="submit" class="h-12 px-lg rounded-xl bg-primary text-on-primary font-title w-full sm:w-auto">
                    Save & notify customer
                </button>
            </form>
        </section>
    </div>

    <?php elseif ($tab === 'api'): ?>
    <form method="POST" class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant space-y-md max-w-xl">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
        <input type="hidden" name="form_tab" value="api"/>

        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">Courier provider</span>
            <select name="provider" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs">
                <option value="manual">Manual only (no API)</option>
                <?php foreach ($providers as $slug => $label): ?>
                <option value="<?= sanitize($slug) ?>"<?= ($settings['provider'] ?? '') === $slug ? ' selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="flex items-center gap-sm">
            <input type="checkbox" name="api_enabled" value="1"<?= !empty($settings['api_enabled']) ? ' checked' : '' ?>/>
            <span class="text-body-md">Enable automatic tracking sync (requires valid API credentials)</span>
        </label>

        <label class="flex items-center gap-sm">
            <input type="checkbox" name="auto_tracking_urls" value="1"<?= !isset($settings['auto_tracking_urls']) || !empty($settings['auto_tracking_urls']) ? ' checked' : '' ?>/>
            <span class="text-body-md">Auto-build tracking URLs for Leopards, TCS, BlueEx, etc.</span>
        </label>

        <label class="flex items-center gap-sm">
            <input type="checkbox" name="send_receipt_on_ship" value="1"<?= !isset($settings['send_receipt_on_ship']) || !empty($settings['send_receipt_on_ship']) ? ' checked' : '' ?>/>
            <span class="text-body-md">Send parcel receipt photo on WhatsApp when order ships</span>
        </label>

        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">Environment</span>
            <select name="environment" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs">
                <option value="production"<?= ($settings['environment'] ?? '') !== 'sandbox' ? ' selected' : '' ?>>Production</option>
                <option value="sandbox"<?= ($settings['environment'] ?? '') === 'sandbox' ? ' selected' : '' ?>>Sandbox</option>
            </select>
        </label>

        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">API username</span>
            <input type="text" name="api_username" value="<?= sanitize($settings['api_username'] ?? '') ?>" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
        </label>
        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">API password</span>
            <input type="password" name="api_password" value="<?= sanitize($settings['api_password'] ?? '') ?>" autocomplete="off" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
        </label>
        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">API key</span>
            <input type="text" name="api_key" value="<?= sanitize($settings['api_key'] ?? '') ?>" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
        </label>
        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">API secret</span>
            <input type="password" name="api_secret" value="<?= sanitize($settings['api_secret'] ?? '') ?>" autocomplete="off" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
        </label>
        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">Account number</span>
            <input type="text" name="account_number" value="<?= sanitize($settings['account_number'] ?? '') ?>" class="w-full h-12 px-md rounded-xl bg-surface-container border border-outline-variant mt-xs"/>
        </label>

        <button type="submit" class="h-12 px-lg rounded-xl bg-primary text-on-primary font-title">Save API settings</button>
    </form>

    <?php else: ?>
    <form method="POST" enctype="multipart/form-data" class="bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant space-y-md max-w-2xl">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="bot_id" value="<?= $botId ?>"/>
        <input type="hidden" name="form_tab" value="bulk"/>

        <p class="text-body-md text-on-surface-variant">
            CSV columns: <code>order_id, courier_name, tracking_number, dispatch_date, estimated_delivery, tracking_url, notes</code>
        </p>
        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">Paste CSV</span>
            <textarea name="csv_text" rows="8" placeholder="order_id,courier_name,tracking_number&#10;42,Leopards,LP123456789"
                      class="w-full px-md py-sm rounded-xl bg-surface-container border border-outline-variant mt-xs font-mono text-body-sm"></textarea>
        </label>
        <label class="block">
            <span class="text-label-sm font-label text-on-surface-variant">Or upload CSV file</span>
            <input type="file" name="csv_file" accept=".csv,text/csv" class="mt-xs"/>
        </label>
        <button type="submit" class="h-12 px-lg rounded-xl bg-primary text-on-primary font-title">Import shipments</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

<?php client_layout_end(); ?>
<?php client_shell_end(); ?>
<script>window.__COURIER_DISPATCH__ = { csrf: <?= json_encode($csrf) ?> };</script>
<script src="/assets/js/courier-dispatch.js?v=<?= @filemtime(__DIR__ . '/../assets/js/courier-dispatch.js') ?: time() ?>"></script>
</body>
</html>
