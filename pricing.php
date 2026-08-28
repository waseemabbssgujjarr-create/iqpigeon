<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/auth.php';



$activePage = 'pricing';

$plans = localized_plans();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Pricing') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">Pricing</span>
        <h1 class="v2-mkt-hero__title">Simple, <em>transparent</em> plans.</h1>
        <p class="v2-mkt-hero__lead">Pay for AI chats — not leads. <?= TRIAL_DAYS ?>-day free trial, then flat monthly pricing.</p>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-pricing-wrap fade-up">
            <?php include __DIR__ . '/includes/marketing-pricing-cards.php'; ?>
        </div>
    </div>
</section>

<section class="v2-section hip-section hip-section--muted">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap">
        <div class="v2-section-head hip-section-head fade-up">
            <h2 class="v2-section-title hip-title">Quick answers</h2>
        </div>
        <div class="max-w-3xl mx-auto hip-faq fade-up">
            <div class="hip-faq__list">
            <?php
            $faqs = [
                ['What counts as a chat?', 'Each AI reply your bot sends (WhatsApp, widget, or Instagram) counts as one chat. Customer texts are free — you only pay for bot replies from your monthly allowance.'],
                ['How is this different from ManyChat or Chatfuel?', 'Those are flow builders — rigid menus and buttons. We use conversational AI that builds rapport, asks qualifying questions naturally, and adapts to how your lead actually talks.'],
                ['Can I train the bot on my business?', 'Yes. Upload PDF/Word/TXT or paste text on the Connect page. The live demo at /demo lets you try this before signing up.'],
                ['Need multiple bots or higher volume?', 'Choose Enterprise on the pricing page and talk to our team — we set custom chat limits and onboard multiple brands or clients.'],
                ['Can I try before signing up?', 'Yes — go to /demo, train the bot with your business info, and chat instantly. ' . TRIAL_DAYS . '-day free trial; debit/credit card or PayPak required when you subscribe after trial.'],
                ['What happens after the free trial?', 'Your account continues on the plan you chose at PKR 1,440/month (Starter) or your selected plan. Pay with debit/credit card (Stripe) or PayPak. We email you before trial ends. Cancel anytime from billing.'],
            ];
            foreach ($faqs as [$q, $a]):
            ?>
            <details class="v2-faq__item hip-faq__item">
                <summary class="hip-faq__summary">
                    <span class="hip-faq__question"><?= sanitize($q) ?></span>
                    <span class="hip-faq__toggle" aria-hidden="true">
                        <span class="hip-faq__toggle-plus">+</span>
                        <span class="hip-faq__toggle-minus">−</span>
                    </span>
                </summary>
                <div class="hip-faq__panel">
                    <div class="hip-faq__body"><?= sanitize($a) ?></div>
                </div>
            </details>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="text-center mt-xl fade-up">
            <p class="v2-section-lead hip-lead hip-lead--center mb-md">Want to see it live first?</p>
            <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">
                <span class="material-symbols-outlined">forum</span> Try Live Demo
            </a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
