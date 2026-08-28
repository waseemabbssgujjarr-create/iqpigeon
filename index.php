<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/db.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/mailer.php';



$activePage = 'home';

$demoBot = null;
try {
    $demoBot = get_demo_bot();
} catch (Throwable $e) {
    error_log('landing get_demo_bot: ' . $e->getMessage());
}

try {
    $plans = localized_plans();
} catch (Throwable $e) {
    error_log('landing localized_plans: ' . $e->getMessage());
    require_once __DIR__ . '/includes/platform-settings.php';
    $plans = plan_defaults();
}

$landingCurrency = visitor_currency();

$starterFromPrice = format_plan_price(plan_price_amount($plans['starter'], $landingCurrency), $landingCurrency);

$landingPayLabel = $landingCurrency === 'PKR' ? 'PayPak (JazzCash · Easypaisa · Banks)' : 'Stripe (USD)';

$landingPricingNote = $landingCurrency === 'PKR'
    ? 'After trial, flat PKR 1,440/month · Debit/credit card or PayPak required'
    : 'After trial, from ' . $starterFromPrice . '/mo · Debit/credit card required (Stripe)';

$waLabel = defined('WHATSAPP_DEMO_LABEL') && WHATSAPP_DEMO_LABEL !== '' ? WHATSAPP_DEMO_LABEL : 'Sareen';

$contactSuccess = false;

$contactError = '';



if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'contact') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {

        $contactError = 'Invalid request. Please try again.';

    } else {

        $name = trim($_POST['name'] ?? '');

        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

        $subject = trim($_POST['subject'] ?? 'General Inquiry');

        $message = trim($_POST['message'] ?? '');



        if ($name === '' || !$email || $message === '') {

            $contactError = 'Please fill in all required fields.';

        } else {

            $body = '<h2>Contact Form</h2>'

                . '<p><strong>Name:</strong> ' . htmlspecialchars($name) . '</p>'

                . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>'

                . '<p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>'

                . '<p>' . nl2br(htmlspecialchars($message)) . '</p>';

            send_email(ADMIN_EMAIL, 'Contact: ' . $subject, $body);

            $contactSuccess = true;

        }

    }

}



$features = [

    ['face_3', 'Human-Like AI Rep', 'WhatsApp-style tone. Never says "I\'m a bot."', true],

    ['menu_book', 'Train Your Knowledge Base', 'Paste text, link a site, or add a PDF.', false],

    ['forum', 'WhatsApp, Web & Instagram', 'Connect channels in one dashboard.', false],

    ['psychology', 'Smart Lead Qualification', 'Custom questions and 0–100 scoring.', false],

    ['event', 'Auto Booking Links', 'Send your calendar link when leads qualify.', false],

    ['notifications', 'Live Notifications', 'Bell alerts on every new lead.', false],

    ['supervisor_account', 'Human Takeover', 'Pause the bot on high-value deals.', false],

    ['phone_android', 'Mobile-First Dashboard', 'Manage everything from your phone.', false],

    ['shield', 'Agency-Ready Admin', 'Clients, bots, leads — one panel.', false],

];



$chatPreviews = [

    ['Greeting', [

        ['in', 'Hi, I saw your ad — what packages do you offer?'],

        ['out', 'Hey! Great to hear from you 👋 We help teams qualify leads on WhatsApp 24/7. What industry are you in?'],

    ]],

    ['Qualification', [

        ['in', 'We run a coaching business, budget around 50k PKR'],

        ['out', 'Perfect — that helps a lot. Are you looking to automate WhatsApp only, or Instagram + website too?'],

    ]],

    ['Booking', [

        ['in', 'Yes WhatsApp is priority, we need this week'],

        ['out brand', 'You\'re a great fit! Here\'s my calendar to book a quick call — pick any slot that works 🗓️'],

    ]],

];



$faqs = [

    ['What counts as a chat?', 'Each AI reply your bot sends counts as one chat. Incoming customer texts are free — limits apply to bot replies only.'],

    ['How is this different from ManyChat or Chatfuel?', 'Those are flow builders — rigid menus and buttons. We use conversational AI that builds rapport and adapts to how your lead actually talks.'],

    ['Can I train the bot on my business?', 'Yes. Paste text, add your website URL, or link a PDF/doc. The live demo lets you try this before signing up.'],

    ['Does it sound like a robot?', 'No. Short WhatsApp-style messages, matches your customer\'s language, and never says "I\'m an AI."'],

    ['What channels are supported?', 'WhatsApp Business, website widget, and Instagram DMs (via Meta) — managed from one dashboard.'],

    ['Can I try before signing up?', 'Yes — go to /demo.php, train the bot with your business info, and chat instantly. ' . TRIAL_DAYS . '-day free trial; debit/credit card or PayPak required when you subscribe after trial.'],

    ['What happens after the free trial?', 'Your account continues on the plan you chose. ' . sanitize($landingPricingNote) . ' We email you before trial ends. Cancel anytime from billing.'],

];

?>

<!DOCTYPE html>

<html lang="en">

<head>

<?= page_head('IQ Pigeon') ?>

<?= marketing_assets() ?>

</head>

<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">

<?php include __DIR__ . '/includes/marketing-header.php'; ?>



<!-- Hero — V2 layered -->

<section id="hero" class="v2-hero hip-hero scroll-mt-24">

    <div class="v2-hero__layers" aria-hidden="true">
        <div class="v2-hero__gradient"></div>
        <div class="v2-hero__mesh"></div>
        <div class="v2-float v2-float--1 v2-stamp-frame v2-stamp-frame--lg">
            <span class="v2-stamp-frame__inner"><span class="material-symbols-outlined">chat</span></span>
        </div>
        <div class="v2-float v2-float--2 v2-stamp-frame">
            <span class="v2-stamp-frame__inner"><span class="material-symbols-outlined">forum</span></span>
        </div>
        <div class="v2-float v2-float--3 v2-stamp-frame">
            <span class="v2-stamp-frame__inner"><?= sanitize(substr($waLabel, 0, 1)) ?></span>
        </div>
        <div class="v2-float v2-float--4 v2-stamp-frame">
            <span class="v2-stamp-frame__inner"><span class="material-symbols-outlined">event</span></span>
        </div>
        <div class="v2-float v2-float--5 v2-stamp-frame">
            <span class="v2-stamp-frame__inner"><span class="material-symbols-outlined">smart_toy</span></span>
        </div>
    </div>

    <div class="hip-wrap v2-hero__content">

        <div class="v2-hero__grid fade-up">

            <div class="v2-hero__content-col">

                <span class="v2-hero__eyebrow">WhatsApp · Web · Booking</span>

                <h1 class="v2-hero__title">Your AI rep.<br><span class="v2-hero__title-accent">Every channel.</span></h1>

                <p class="v2-hero__lead">Train in minutes. Qualify leads naturally. Book calls while you sleep.</p>

                <div class="v2-hero__actions">

                    <a href="/register" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary hover-lift btn-shine">Start <?= TRIAL_DAYS ?>-Day Trial</a>

                    <?php if (whatsapp_demo_available()): ?>

                        <?php $variant = 'hippo-hero'; include __DIR__ . '/includes/marketing-whatsapp-demo.php'; ?>

                    <?php else: ?>

                        <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--outline">Live Demo</a>

                    <?php endif; ?>

                </div>

                <p class="v2-hero__note"><?= TRIAL_DAYS ?>-day trial · <?= sanitize($landingPricingNote) ?></p>

            </div>

            <div class="v2-hero__visual">

                <div class="v2-glass v2-hero__phone-wrap fade-up" id="hero-wa-demo">

                    <div class="hip-iphone" aria-hidden="true">
                <div class="hip-iphone__shell">
                    <div class="hip-iphone__btn hip-iphone__btn--silent"></div>
                    <div class="hip-iphone__btn hip-iphone__btn--vol-up"></div>
                    <div class="hip-iphone__btn hip-iphone__btn--vol-down"></div>
                    <div class="hip-iphone__btn hip-iphone__btn--power"></div>
                    <div class="hip-iphone__screen">
                        <div class="hip-iphone__island"></div>
                        <div class="wa-statusbar">
                            <span class="wa-statusbar__time">9:41</span>
                            <span class="wa-statusbar__icons">
                                <svg width="16" height="12" viewBox="0 0 16 12" fill="currentColor"><rect x="0" y="8" width="3" height="4" rx="0.5"/><rect x="4" y="5" width="3" height="7" rx="0.5"/><rect x="8" y="2" width="3" height="10" rx="0.5"/><rect x="12" y="0" width="3" height="12" rx="0.5"/></svg>
                                <svg width="15" height="12" viewBox="0 0 15 12" fill="currentColor"><path d="M7.5 2.5C10 2.5 12.2 3.6 13.7 5.3L15 3.9C13.1 1.8 10.4 0.5 7.5 0.5S1.9 1.8 0 3.9L1.3 5.3C2.8 3.6 5 2.5 7.5 2.5Z"/><path d="M7.5 5.5C9.2 5.5 10.7 6.2 11.7 7.4L13 6.1C11.6 4.5 9.6 3.5 7.5 3.5S3.4 4.5 2 6.1L3.3 7.4C4.3 6.2 5.8 5.5 7.5 5.5Z"/><circle cx="7.5" cy="10.5" r="1.5"/></svg>
                                <svg width="22" height="11" viewBox="0 0 22 11" fill="currentColor"><rect x="0" y="1" width="18" height="9" rx="2" stroke="currentColor" stroke-width="1" fill="none"/><rect x="19" y="3.5" width="2" height="4" rx="0.5"/><rect x="1.5" y="2.5" width="13" height="6" rx="1" fill="currentColor"/></svg>
                            </span>
                        </div>
                        <header class="wa-header">
                            <button type="button" class="wa-header__back" aria-hidden="true">
                                <svg width="10" height="16" viewBox="0 0 10 16" fill="none"><path d="M8 2L2 8L8 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div class="wa-header__avatar"><?= sanitize(substr($waLabel, 0, 1)) ?></div>
                            <div class="wa-header__info">
                                <div class="wa-header__name"><?= sanitize($waLabel) ?></div>
                                <div class="wa-header__status">online</div>
                            </div>
                            <div class="wa-header__actions">
                                <svg width="20" height="14" viewBox="0 0 20 14" fill="currentColor"><path d="M0 10.5V12.5C0 13.3 0.7 14 1.5 14H18.5C19.3 14 20 13.3 20 12.5V10.5H0ZM13 0H7C5.9 0 5 0.9 5 2V8C5 9.1 5.9 10 7 10H13C14.1 10 15 9.1 15 8V2C15 0.9 14.1 0 13 0Z"/></svg>
                                <svg width="18" height="18" viewBox="0 0 18 18" fill="currentColor"><path d="M3.6 7.2C5.1 5.1 7.4 3.6 10 3.1V1C5.6 1.6 2 5.3 2 10H4C4 8.8 4.2 7.5 3.6 7.2ZM16 10C16 6.1 13 2.8 9 2.1V0.1C14.1 0.8 18 4.9 18 10H16ZM10 18C4.5 18 0 13.5 0 8H2C2 12.4 5.6 16 10 16V18Z"/></svg>
                                <svg width="4" height="16" viewBox="0 0 4 16" fill="currentColor"><circle cx="2" cy="2" r="2"/><circle cx="2" cy="8" r="2"/><circle cx="2" cy="14" r="2"/></svg>
                            </div>
                        </header>
                        <div class="wa-chat" id="hero-wa-chat"></div>
                        <footer class="wa-footer">
                            <span class="wa-footer__plus" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </span>
                            <div class="wa-footer__field">
                                <span class="wa-footer__placeholder">Message</span>
                                <span class="wa-footer__field-icons" aria-hidden="true">
                                    <span class="wa-footer__icon wa-footer__icon--emoji">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><circle cx="9" cy="10" r="1.1" fill="currentColor"/><circle cx="15" cy="10" r="1.1" fill="currentColor"/><path d="M8.5 14.2C9.6 15.4 10.7 16 12 16C13.3 16 14.4 15.4 15.5 14.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    </span>
                                    <span class="wa-footer__icon wa-footer__icon--attach">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M16.5 6.5L8.8 14.2C7.6 15.4 7.6 17.4 8.8 18.6C10 19.8 12 19.8 13.2 18.6L20.2 11.6C22.1 9.7 22.1 6.7 20.2 4.8C18.3 2.9 15.3 2.9 13.4 4.8L5.4 12.8C2.9 15.3 2.9 19.3 5.4 21.8C7.9 24.3 11.9 24.3 14.4 21.8L21 15.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="wa-footer__icon wa-footer__icon--camera">
                                        <svg width="20" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7.5H7.2L8.8 5.5H15.2L16.8 7.5H20C21.1 7.5 22 8.4 22 9.5V18.5C22 19.6 21.1 20.5 20 20.5H4C2.9 20.5 2 19.6 2 18.5V9.5C2 8.4 2.9 7.5 4 7.5Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="14" r="3.5" stroke="currentColor" stroke-width="1.6"/></svg>
                                    </span>
                                </span>
                            </div>
                            <span class="wa-footer__mic" aria-hidden="true">
                                <svg width="18" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14C13.66 14 15 12.66 15 11V5C15 3.34 13.66 2 12 2C10.34 2 9 3.34 9 5V11C9 12.66 10.34 14 12 14ZM17.3 11C17.3 14.05 14.76 16.5 11.75 16.93V19H14V21H10V19H12.25V16.93C9.24 16.5 6.7 14.05 6.7 11H8.7C8.7 13.21 10.54 15 12.75 15H11.25C13.46 15 15.3 13.21 15.3 11H17.3Z"/></svg>
                            </span>
                        </footer>
                        <div class="hip-iphone__home-indicator"></div>
                    </div>
                </div>
                    </div>

                </div>

            </div>

        </div>

    </div>

</section>



<!-- Trust -->

<section class="v2-trust hip-trust fade-up">

    <div class="hip-wrap">

        <p class="v2-trust__label">Built for agencies &amp; B2B</p>

        <div class="v2-trust__strip v2-glass hip-trust__items">

            <?php foreach (['WhatsApp Sales', 'Web Widget', 'Lead Scoring', 'Booking Links', 'Shop Catalog', 'Mobile Dashboard'] as $brand): ?>

            <span class="v2-trust__chip"><?= sanitize($brand) ?></span>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<!-- Three pillars -->

<section class="v2-section hip-section hip-section--muted">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <span class="v2-eyebrow hip-eyebrow">Why switch</span>

            <h2 class="v2-section-title hip-title">You&apos;re hiring an <em>AI rep</em></h2>

            <p class="v2-section-lead hip-lead hip-lead--center">Rapport first. Qualify second. Every channel.</p>

        </div>

        <div class="v2-pillars hip-pillars fade-up">

            <article class="v2-card v2-card--hover hip-pillar">

                <div class="hip-pillar__icon"><span class="material-symbols-outlined">psychology</span></div>

                <h3>Qualify Naturally</h3>

                <p>One question at a time — like a real rep.</p>

            </article>

            <article class="v2-card v2-card--hover hip-pillar">

                <div class="hip-pillar__icon"><span class="material-symbols-outlined">event</span></div>

                <h3>Book Automatically</h3>

                <p>Booking link sent when they&apos;re ready.</p>

            </article>

            <article class="v2-card v2-card--hover hip-pillar">

                <div class="hip-pillar__icon"><span class="material-symbols-outlined">notifications_active</span></div>

                <h3>Alert Instantly</h3>

                <p>Bell + email when a hot lead qualifies.</p>

            </article>

        </div>

    </div>

</section>



<!-- Chat previews -->

<section class="v2-section v2-section--alt hip-section hip-section--alt">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <span class="v2-eyebrow hip-eyebrow">Real chats</span>

            <h2 class="v2-section-title hip-title">DMs handled <em>24/7</em></h2>

            <p class="v2-section-lead hip-lead hip-lead--center">Greet, qualify, and book — exactly on WhatsApp.</p>

        </div>

        <div class="v2-chat-grid hip-chat-cards fade-up">

            <?php foreach ($chatPreviews as [$label, $messages]): ?>

            <article class="v2-card v2-chat-preview hip-chat-card">

                <div class="v2-chat-preview__head">

                    <span class="v2-stamp-frame v2-stamp-frame--sm" aria-hidden="true">

                        <span class="v2-stamp-frame__inner"><span class="material-symbols-outlined">chat</span></span>

                    </span>

                    <p class="v2-chat-preview__label hip-chat-card__label"><?= sanitize($label) ?></p>

                </div>

                <div class="v2-chat-preview__mini hip-chat-card__mini">

                    <?php foreach ($messages as [$type, $text]):

                        $cls = $type === 'out brand' ? 'v2-chat-bubble--out-brand hip-wa-bubble--out brand' : ($type === 'out' ? 'v2-chat-bubble--out hip-wa-bubble--out' : 'v2-chat-bubble--in hip-wa-bubble--in');

                    ?>

                    <div class="v2-chat-bubble hip-wa-bubble <?= $cls ?>"><?= sanitize($text) ?></div>

                    <?php endforeach; ?>

                </div>

            </article>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<!-- Dashboard -->

<section id="how-it-works" class="v2-section hip-section hip-section--muted scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <span class="v2-eyebrow hip-eyebrow">How it works</span>

            <h2 class="v2-section-title hip-title">One inbox. <em>Always learning.</em></h2>

            <p class="v2-section-lead hip-lead hip-lead--center">Track leads, scores, and bookings from your phone.</p>

        </div>

        <div class="hip-split fade-up">

            <div class="v2-how-copy">

                <ul class="v2-checklist hip-checklist">

                    <li><span class="material-symbols-outlined">check_circle</span> WhatsApp, web widget, Instagram (Meta)</li>

                    <li><span class="material-symbols-outlined">check_circle</span> Lead scoring 0–100</li>

                    <li><span class="material-symbols-outlined">check_circle</span> Human takeover anytime</li>

                    <li><span class="material-symbols-outlined">check_circle</span> Agency admin panel</li>

                </ul>

            </div>

            <div class="v2-card v2-dashboard hip-dashboard">

                <div class="v2-section-header">

                    <span class="v2-section-header__title">Live dashboard</span>

                    <span class="v2-dashboard__status">● Online</span>

                </div>

                <div class="v2-stat-grid mb-md">

                    <?php foreach ([['248', 'Leads'], ['67', 'Qualified'], ['34', 'Booked'], ['27%', 'Conv.']] as [$v, $l]): ?>

                    <div class="v2-stat-card">

                        <p class="v2-stat-card__label"><?= $l ?></p>

                        <p class="v2-stat-card__value"><?= $v ?></p>

                    </div>

                    <?php endforeach; ?>

                </div>

                <div class="hip-dashboard__bar">

                    <?php foreach ([35, 55, 40, 70, 50, 85, 65, 90, 75, 95, 80] as $h): ?>

                    <span style="height:<?= $h ?>%"></span>

                    <?php endforeach; ?>

                </div>

                <?php foreach ([['Jordan W.', 'qualified'], ['Sarah K.', 'in_progress']] as [$n, $st]): ?>

                <div class="v2-dashboard__lead">

                    <div class="v2-dashboard__avatar"><?= sanitize(substr($n, 0, 1)) ?></div>

                    <span class="v2-dashboard__name"><?= sanitize($n) ?></span>

                    <?= status_badge($st) ?>

                </div>

                <?php endforeach; ?>

            </div>

        </div>



        <div class="v2-steps hip-steps mt-xl fade-up">

            <?php

            $steps = [

                ['1', 'Create Account', TRIAL_DAYS . '-day free trial.'],

                ['2', 'Train Your Bot', 'Paste services, URL, or PDF.'],

                ['3', 'Connect Channels', 'WhatsApp, web widget, Instagram (Meta).'],

                ['4', 'Qualify & Book', 'AI chats, scores, sends booking link.'],

            ];

            foreach ($steps as [$num, $title, $desc]):

            ?>

            <article class="v2-card v2-step-card hip-step">

                <div class="hip-step__num"><?= $num ?></div>

                <h3><?= sanitize($title) ?></h3>

                <p><?= sanitize($desc) ?></p>

            </article>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<!-- Features grid -->

<section id="features" class="v2-section v2-section--alt hip-section hip-section--alt scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <span class="v2-eyebrow hip-eyebrow">Platform</span>

            <h2 class="v2-section-title hip-title">One AI. <em>All channels.</em></h2>

            <p class="v2-section-lead hip-lead hip-lead--center">Replace slow replies with a trained sales rep.</p>

        </div>

        <div class="v2-features hip-features fade-up">

            <?php foreach ($features as [$icon, $title, $desc, $highlight]): ?>

            <article class="v2-card v2-feature-card hip-feature<?= $highlight ? ' v2-feature-card--highlight hip-feature--highlight' : ' v2-card--hover' ?>">

                <div class="hip-feature__icon"><span class="material-symbols-outlined"><?= $icon ?></span></div>

                <h3><?= sanitize($title) ?></h3>

                <p><?= sanitize($desc) ?></p>

            </article>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<!-- Compare / Integrations -->

<section id="integrations" class="v2-section hip-section hip-section--muted scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap">

        <div class="hip-split fade-up">

            <div>

                <span class="v2-eyebrow hip-eyebrow">Integrations</span>

                <h2 class="v2-section-title hip-title">Menus vs. <em>conversations</em></h2>

                <p class="v2-section-lead hip-lead">Conversational AI — warm rep tone, short WhatsApp-style replies.</p>

            </div>

            <div class="v2-glass v2-integrations-panel">

                <ul class="v2-checklist hip-checklist">

                    <li><span class="material-symbols-outlined">check_circle</span> Natural replies — not button menus</li>

                    <li><span class="material-symbols-outlined">check_circle</span> Train on site, PDF, or pasted text</li>

                    <li><span class="material-symbols-outlined">check_circle</span> WhatsApp, web widget, Instagram (Meta)</li>

                    <li><span class="material-symbols-outlined">check_circle</span> Booking link auto-send on qualify</li>

                    <li><span class="material-symbols-outlined">check_circle</span> <?= $landingCurrency === 'PKR' ? 'Flat PKR 1,440/mo after trial' : 'From ' . sanitize($starterFromPrice) . '/mo after trial' ?> · PayPak &amp; card</li>

                    <li><span class="material-symbols-outlined">check_circle</span> Live demo before signup</li>

                </ul>

                <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary mt-lg">Open Live Demo</a>

            </div>

        </div>

        <div class="v2-logo-strip fade-up" aria-label="Supported channels">

            <?php foreach (['WhatsApp', 'Web Widget', 'Booking', 'Stripe', 'PayPak', 'Shopify'] as $logo): ?>

            <span class="v2-logo-strip__item"><?= sanitize($logo) ?></span>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<!-- Testimonials -->

<section class="v2-section v2-section--alt hip-section hip-section--alt">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <h2 class="v2-section-title hip-title">Real results</h2>

        </div>

        <div class="v2-testimonials hip-testimonials fade-up">

            <?php foreach ([

                ['"Feels like a person — not a bot menu. Reply rate doubled."', 'Marcus T.', 'Agency Owner'],

                ['"Pasted our site into the demo. Sold me in 5 minutes."', 'Priya S.', 'Sales Director'],

                ['"Live alerts when leads qualify — game changer."', 'James R.', 'B2B Consultant'],

            ] as [$quote, $name, $role]): ?>

            <article class="v2-card v2-testimonial hip-testimonial">

                <blockquote><?= sanitize($quote) ?></blockquote>

                <cite><?= sanitize($name) ?></cite>

                <span><?= sanitize($role) ?></span>

            </article>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<!-- ROI -->

<section class="v2-section hip-section hip-section--muted">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <span class="v2-eyebrow hip-eyebrow">ROI</span>

            <h2 class="v2-section-title hip-title">Your <em>potential savings</em></h2>

        </div>

        <div class="v2-roi hip-roi fade-up" id="hip-roi" data-currency="<?= sanitize($landingCurrency) ?>">

            <div class="v2-card v2-roi__inputs hip-roi__inputs">

                <div class="hip-roi__field">

                    <label>Leads / mo <span data-roi-leads-val>100</span></label>

                    <input type="range" min="10" max="500" value="100" data-roi-leads>

                </div>

                <div class="hip-roi__field">

                    <label>Deal value (<?= $landingCurrency === 'PKR' ? 'PKR' : 'USD' ?>) <span data-roi-deal-val><?= $landingCurrency === 'PKR' ? '25000' : '500' ?></span></label>

                    <input type="range" min="<?= $landingCurrency === 'PKR' ? '5000' : '100' ?>" max="<?= $landingCurrency === 'PKR' ? '200000' : '5000' ?>" value="<?= $landingCurrency === 'PKR' ? '25000' : '500' ?>" step="<?= $landingCurrency === 'PKR' ? '5000' : '50' ?>" data-roi-deal>

                </div>

                <div class="hip-roi__field">

                    <label>AI lift (%) <span data-roi-lift-val>15</span></label>

                    <input type="range" min="5" max="40" value="15" data-roi-lift>

                </div>

            </div>

            <div class="v2-glass v2-roi__result hip-roi__result">

                <p class="v2-roi__label">Extra revenue / month</p>

                <p class="hip-roi__value" data-roi-output><?= $landingCurrency === 'PKR' ? 'PKR 375,000' : '$7,500' ?></p>

                <a href="/register" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--white">Get Started Free</a>

            </div>

        </div>

    </div>

</section>



<!-- Pricing + FAQ -->

<section id="pricing" class="v2-section v2-section--alt hip-section hip-section--alt scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap">

        <div class="v2-section-head hip-section-head fade-up">

            <span class="v2-eyebrow hip-eyebrow">Pricing</span>

            <h2 class="v2-section-title hip-title">Simple plans</h2>

            <p class="v2-section-lead hip-lead hip-lead--center"><?= sanitize($landingPricingNote) ?> · <?= TRIAL_DAYS ?>-day trial</p>

        </div>

        <div class="v2-pricing-wrap mb-xl fade-up">

            <?php include __DIR__ . '/includes/marketing-pricing-cards.php'; ?>

        </div>

        <div class="max-w-3xl mx-auto v2-faq hip-faq fade-up">

            <div class="v2-section-head hip-faq__head text-center mb-xl">

                <span class="v2-eyebrow hip-eyebrow">FAQ</span>

                <h3 class="v2-section-title hip-title">Quick answers</h3>

            </div>

            <div class="hip-faq__list">

            <?php foreach ($faqs as [$q, $a]): ?>

            <details class="v2-card v2-faq__item hip-faq__item">

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

    </div>

</section>



<!-- Demo -->

<section id="demo" class="v2-section hip-section hip-section--muted scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap">

        <div class="v2-glass v2-demo-block fade-up">

            <div class="v2-section-head hip-section-head">

                <span class="v2-eyebrow hip-eyebrow">Try it</span>

                <h2 class="v2-section-title hip-title">Train it. <em>Chat.</em> Sign up.</h2>

                <p class="v2-section-lead hip-lead hip-lead--center">

                    <?php if (whatsapp_demo_available()): ?>

                    WhatsApp live demo — or train the website with your info.

                    <?php else: ?>

                    Paste your offer — watch it qualify like your best rep.

                    <?php endif; ?>

                </p>

            </div>

            <div class="v2-demo-block__actions">

                <?php if (whatsapp_demo_available()): ?>

                    <?php $variant = 'hippo'; include __DIR__ . '/includes/marketing-whatsapp-demo.php'; ?>

                <?php endif; ?>

                <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary">Website Demo</a>

                <?php if (android_apk_available()): ?>

                <a href="<?= sanitize(android_apk_url()) ?>" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--outline">

                    <span class="material-symbols-outlined">android</span> Android App

                </a>

                <?php endif; ?>

            </div>

        </div>

    </div>

</section>



<!-- About + security -->

<section id="about" class="v2-section v2-section--alt hip-section hip-section--alt scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap">

        <div class="hip-split v2-about-grid fade-up">

            <div class="v2-glass v2-about-copy">

                <span class="v2-eyebrow hip-eyebrow">About</span>

                <h2 class="v2-section-title hip-title">AI that sells <em>human</em></h2>

                <p class="v2-section-lead hip-lead">Trained on your business — qualify, book, and get alerts from your phone.</p>

            </div>

            <div class="v2-about-cards grid grid-cols-2 gap-md">

                <?php foreach ([

                    ['lock', 'Encrypted Tokens', 'AES-256 at rest.'],

                    ['group', 'Tenant Isolation', 'Scoped by user ID.'],

                    ['verified_user', 'Access Control', 'Roles + CSRF.'],

                ] as [$icon, $title, $desc]): ?>

                <div class="v2-card v2-card--flat v2-about-card hip-feature">

                    <div class="hip-feature__icon"><span class="material-symbols-outlined"><?= $icon ?></span></div>

                    <h3><?= sanitize($title) ?></h3>

                    <p><?= sanitize($desc) ?></p>

                </div>

                <?php endforeach; ?>

                <div class="v2-card v2-card--flat v2-about-card hip-feature">

                    <div class="hip-feature__icon"><span class="material-symbols-outlined">speed</span></div>

                    <h3>&lt;3s Replies</h3>

                    <p>Fast AI with human-like delays.</p>

                </div>

            </div>

        </div>

    </div>

</section>



<!-- Contact -->

<section id="contact" class="v2-section hip-section hip-section--muted scroll-mt-24">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap max-w-5xl">

        <div class="v2-section-head hip-section-head fade-up">

            <h2 class="v2-section-title hip-title">Get in <em>touch</em></h2>

            <p class="v2-section-lead hip-lead hip-lead--center">Setup, pricing, or enterprise plans.</p>

        </div>

        <div class="v2-contact-grid grid md:grid-cols-5 gap-xl fade-up">

            <div class="md:col-span-2">

                <div class="v2-glass v2-contact-info">

                    <span class="material-symbols-outlined v2-contact-info__icon">mail</span>

                    <h3>Email</h3>

                    <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>" class="v2-contact-info__link"><?= sanitize(ADMIN_EMAIL) ?></a>

                    <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary w-full justify-center mt-md">Try Live Demo</a>

                </div>

            </div>

            <div class="md:col-span-3">

                <?php if ($contactSuccess): ?>

                    <div class="v2-card v2-contact-success text-center p-xl">

                        <span class="material-symbols-outlined text-4xl text-primary mb-md">check_circle</span>

                        <h3 class="v2-section-title hip-title">Message sent!</h3>

                        <p class="v2-section-lead hip-lead hip-lead--center">We&apos;ll reply within 24 hours.</p>

                    </div>

                <?php else: ?>

                    <?php if ($contactError): ?>

                        <div class="v2-contact-error bg-error-container text-on-error-container rounded-xl p-md mb-md"><?= sanitize($contactError) ?></div>

                    <?php endif; ?>

                    <form method="POST" action="/index#contact" class="v2-card v2-contact-form mkt-form">

                        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

                        <input type="hidden" name="form_type" value="contact"/>

                        <div class="v2-form-group mkt-form__group">

                            <label class="v2-form-label mkt-form__label" for="contact-name">Name *</label>

                            <input type="text" id="contact-name" name="name" required class="mkt-input"/>

                        </div>

                        <div class="v2-form-group mkt-form__group">

                            <label class="v2-form-label mkt-form__label" for="contact-email">Email *</label>

                            <input type="email" id="contact-email" name="email" required class="mkt-input"/>

                        </div>

                        <div class="v2-form-group mkt-form__group">

                            <label class="v2-form-label mkt-form__label" for="contact-subject">Subject</label>

                            <select id="contact-subject" name="subject" class="mkt-select">

                                <option>General Inquiry</option>

                                <option>Sales / Demo Request</option>

                                <option>Technical Support</option>

                                <option>Enterprise Plan</option>

                            </select>

                        </div>

                        <div class="v2-form-group mkt-form__group">

                            <label class="v2-form-label mkt-form__label" for="contact-message">Message *</label>

                            <textarea id="contact-message" name="message" required rows="5" class="mkt-textarea"></textarea>

                        </div>

                        <button type="submit" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary hip-btn--block">Send Message</button>

                    </form>

                <?php endif; ?>

            </div>

        </div>

    </div>

</section>



<!-- Final CTA -->

<section class="v2-cta-band hip-cta-band fade-up">

    <h2>Your AI rep goes live tonight.</h2>

    <p>Train in minutes. WhatsApp, web widget, and booking — live today.</p>

    <a href="/register" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--white hover-lift">Start <?= TRIAL_DAYS ?>-Day Free Trial</a>

</section>



<?php include __DIR__ . '/includes/marketing-footer.php'; ?>

<script src="/assets/js/app.js"></script>

<script src="/assets/js/hero-wa-demo.js?v=<?= @filemtime(__DIR__ . '/assets/js/hero-wa-demo.js') ?: time() ?>"></script>

</body>

</html>


