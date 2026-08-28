<?php
/**
 * Marketing pricing cards — Starter, Pro, Enterprise (contact).
 * @var array<string, array<string, mixed>> $plans from get_plans()
 */
require_once __DIR__ . '/paypak.php';
$plans = $plans ?? localized_plans();
$displayCurrency = visitor_currency();
?>
<div class="grid md:grid-cols-3 gap-lg items-stretch">
    <?php foreach ($plans as $slug => $plan): ?>
    <?php
        $contactOnly = !empty($plan['contact_only']);
        $popular = !empty($plan['popular']);
        $chatLabel = is_numeric($plan['chats'] ?? null)
            ? number_format((int) $plan['chats']) . ' client chats/mo'
            : (string) ($plan['chats'] ?? 'Custom volume');
        $displayPrice = $plan['display_price'] ?? plan_price_amount($plan, $displayCurrency);
    ?>
    <div class="fade-up pricing-card v2-plan-card rounded-2xl p-lg border-2 relative <?= $popular ? 'featured is-popular border-primary shadow-xl bg-primary-container/25' : 'bg-surface-container-lowest border-outline-variant' ?>">
        <?php if ($popular): ?>
            <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-primary text-on-primary text-label-sm font-label px-md py-xs rounded-full">MOST POPULAR</span>
        <?php endif; ?>
        <p class="font-headline text-xl font-bold mb-sm"><?= sanitize($plan['name']) ?></p>
        <?php if ($contactOnly): ?>
            <p class="text-4xl font-bold mb-xs">Custom</p>
            <p class="text-body-md text-on-surface-variant mb-lg">Multiple bots · <?= sanitize($chatLabel) ?></p>
        <?php else: ?>
            <p class="text-5xl font-bold mb-xs"><?= sanitize(format_plan_price($displayPrice, $displayCurrency)) ?><span class="text-body-lg font-normal text-on-surface-variant">/mo</span></p>
            <p class="text-body-md text-on-surface-variant mb-xs"><?= sanitize((string) $plan['bots']) ?> bot<?= $plan['bots'] === 1 ? '' : 's' ?> · <?= sanitize($chatLabel) ?></p>
            <p class="text-label-sm text-on-surface-variant mb-lg"><?= $displayCurrency === 'PKR' ? sanitize(paypak_supported_methods_label()) : 'Stripe · USD' ?></p>
        <?php endif; ?>
        <ul class="space-y-sm text-body-md mb-xl">
            <?php foreach ($plan['features'] as $f): ?>
            <li class="flex items-center gap-sm">
                <span class="material-symbols-outlined text-primary text-sm">check</span>
                <?= sanitize($f) ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($contactOnly): ?>
        <a href="/contact"
           class="v2-plan-cta v2-plan-cta--primary block w-full text-center">
            Talk to sales
        </a>
        <?php else: ?>
        <a href="/register?plan=<?= sanitize($slug) ?>"
           class="v2-plan-cta block w-full text-center <?= $popular ? 'v2-plan-cta--primary' : 'v2-plan-cta--ghost' ?>">
            Start free trial
        </a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
