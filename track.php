<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/shipment.php';
require_once __DIR__ . '/includes/cart.php';

$token = trim($_GET['t'] ?? '');
$shipment = $token !== '' ? shipment_get_by_public_token($token) : null;
$order = null;
$events = [];
$notFound = !$shipment;

if ($shipment) {
    $order = db_fetch('SELECT id, status, total_amount, currency, customer_name FROM bot_orders WHERE id = ?', 'i', [(int) $shipment['order_id']]);
    $events = shipment_timeline((int) $shipment['id']);
}

$pageTitle = $notFound ? 'Tracking not found' : 'Track your order';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head($pageTitle) ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body v2-mkt-page">
<div class="v2-mkt-page__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
<main class="v2-mkt-page__main">
    <?php if ($notFound): ?>
    <div class="v2-mkt-success fade-up">
        <span class="material-symbols-outlined text-5xl text-outline mb-md">local_shipping</span>
        <h1 class="v2-mkt-hero__title">Tracking not found</h1>
        <p class="v2-mkt-hero__lead">This link may have expired or is incorrect. Message us on WhatsApp for help.</p>
    </div>
    <?php else: ?>
    <?php
        $status = shipment_status_label((string) ($shipment['current_status'] ?? ''));
        $total = $order && function_exists('catalog_format_price')
            ? catalog_format_price((float) $order['total_amount'], (string) ($order['currency'] ?? 'PKR'))
            : '';
    ?>
    <div class="text-center mb-lg fade-up">
        <span class="material-symbols-outlined text-5xl text-primary mb-sm">package_2</span>
        <h1 class="v2-mkt-hero__title">Order #<?= (int) $shipment['order_id'] ?></h1>
        <p class="v2-mkt-hero__lead"><?= sanitize($status) ?></p>
    </div>

    <div class="v2-card v2-mkt-card v2-mkt-card--left mb-lg fade-up">
        <div class="flex justify-between gap-md mb-sm">
            <span class="text-on-surface-variant">Courier</span>
            <strong><?= sanitize((string) ($shipment['courier_name'] ?? '')) ?></strong>
        </div>
        <div class="flex justify-between gap-md mb-sm">
            <span class="text-on-surface-variant">Tracking #</span>
            <strong class="font-mono text-right"><?= sanitize((string) ($shipment['tracking_number'] ?? '')) ?></strong>
        </div>
        <?php if ($total !== ''): ?>
        <div class="flex justify-between gap-md mb-sm">
            <span class="text-on-surface-variant">Order total</span>
            <strong><?= sanitize($total) ?></strong>
        </div>
        <?php endif; ?>
        <?php
        $eta = shipment_format_eta($shipment['estimated_delivery'] ?? null);
        if ($eta !== ''):
        ?>
        <div class="flex justify-between gap-md mb-sm">
            <span class="text-on-surface-variant">Estimated delivery</span>
            <strong><?= sanitize($eta) ?></strong>
        </div>
        <?php endif; ?>
        <?php if (!empty($shipment['tracking_url'])): ?>
        <a href="<?= sanitize((string) $shipment['tracking_url']) ?>" target="_blank" rel="noopener"
           class="inline-flex items-center gap-xs mt-sm text-primary font-medium">
            Open courier tracking
            <span class="material-symbols-outlined text-base">open_in_new</span>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($events !== []): ?>
    <div class="v2-card v2-mkt-card v2-mkt-card--left fade-up">
        <h2 class="v2-section-title hip-title mb-md">Shipment timeline</h2>
        <ol class="v2-mkt-timeline">
            <?php foreach ($events as $ev): ?>
            <li>
                <p class="font-medium"><?= sanitize((string) ($ev['title'] ?? shipment_status_label((string) ($ev['status'] ?? '')))) ?></p>
                <?php if (!empty($ev['event_at'])): ?>
                <p class="v2-mkt-hero__meta"><?= sanitize((string) $ev['event_at']) ?></p>
                <?php endif; ?>
                <?php if (!empty($ev['description'])): ?>
                <p class="v2-section-lead hip-lead mt-xs"><?= sanitize((string) $ev['description']) ?></p>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
