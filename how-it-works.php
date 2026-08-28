<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

$activePage = 'how-it-works';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('How It Works') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">How it works</span>
        <h1 class="v2-mkt-hero__title">Live in <em>4 steps.</em></h1>
        <p class="v2-mkt-hero__lead">Train your AI rep and go live on WhatsApp, Instagram, or web in under 15 minutes.</p>
    </div>
</section>

<section id="setup" class="v2-section v2-section--alt hip-section hip-section--alt scroll-mt-24">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-section-head hip-section-head fade-up">
            <h2 class="v2-section-title hip-title">Setup walkthrough</h2>
        </div>
        <div class="v2-steps hip-steps fade-up">
            <?php
            $steps = [
                ['1', 'Create Account', TRIAL_DAYS . '-day free trial. Card or PayPak after.'],
                ['2', 'Train Your Bot', 'Paste services, URL, or PDF. Set qualifying questions.'],
                ['3', 'Connect Channels', 'WhatsApp, Instagram, and web widget.'],
                ['4', 'Qualify & Book', 'AI chats, scores leads, sends Calendly.'],
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

<section id="training" class="v2-section hip-section hip-section--muted scroll-mt-24">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap">
        <div class="v2-mkt-split fade-up">
            <div>
                <span class="v2-eyebrow">Training</span>
                <h2 class="v2-section-title hip-title">Train before you go live</h2>
                <p class="v2-section-lead hip-lead">Your bot learns from your content — not generic templates.</p>
                <ul class="v2-checklist hip-checklist">
                    <li><span class="material-symbols-outlined">article</span> <strong>Text</strong> — services, pricing, FAQs</li>
                    <li><span class="material-symbols-outlined">language</span> <strong>Website</strong> — scrape key pages</li>
                    <li><span class="material-symbols-outlined">picture_as_pdf</span> <strong>PDF / Doc</strong> — brochure or Drive link</li>
                </ul>
                <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary mt-lg">Try training on demo</a>
            </div>
            <div class="v2-card v2-mkt-card text-center">
                <span class="material-symbols-outlined v2-mkt-card__icon" style="width:4rem;height:4rem;font-size:2.5rem;">menu_book</span>
                <p>Same training flow on the demo page — see results before signup.</p>
            </div>
        </div>
    </div>
</section>

<section id="scoring" class="v2-section v2-section--alt hip-section hip-section--alt scroll-mt-24">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap">
        <div class="v2-mkt-split fade-up">
            <div>
                <span class="v2-eyebrow">Qualification</span>
                <h2 class="v2-section-title hip-title">Conversations that convert</h2>
                <p class="v2-section-lead hip-lead">Budget signals, timeline urgency, and 0–100 scoring — one question at a time.</p>
                <ul class="v2-checklist hip-checklist">
                    <li><span class="material-symbols-outlined">check_circle</span> Natural WhatsApp-style replies</li>
                    <li><span class="material-symbols-outlined">check_circle</span> One qualifying question at a time</li>
                    <li><span class="material-symbols-outlined">check_circle</span> Qualified → Calendly link sent</li>
                </ul>
            </div>
            <div class="flex justify-center">
                <svg class="w-48 h-48" viewBox="0 0 100 100" aria-hidden="true">
                    <circle cx="50" cy="50" r="45" fill="none" stroke="#edeeef" stroke-width="8"/>
                    <circle class="metric-ring animated" cx="50" cy="50" r="45" fill="none" stroke="#4aad36" stroke-width="8" stroke-linecap="round" transform="rotate(-90 50 50)"/>
                    <text x="50" y="55" text-anchor="middle" class="text-2xl font-bold fill-primary" font-size="20">87</text>
                </svg>
            </div>
        </div>
    </div>
</section>

<section id="booking" class="v2-section hip-section hip-section--muted scroll-mt-24">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap">
        <div class="v2-section-head hip-section-head fade-up">
            <h2 class="v2-section-title hip-title">A real conversation</h2>
            <p class="v2-section-lead hip-lead hip-lead--center">Warm tone, one question at a time — exactly how leads experience it.</p>
        </div>
        <div class="v2-mkt-chat fade-up">
            <div class="v2-chat-bubble v2-chat-bubble--in">Hey! Thanks for reaching out 😊 What kind of project are you looking at?</div>
            <div class="v2-chat-bubble v2-chat-bubble--out">Need a website redesign, budget around $15k, want to start this month.</div>
            <div class="v2-chat-bubble v2-chat-bubble--in">That's great — we can definitely help. Quick one: is this for your own business or a client project?</div>
            <div class="v2-chat-bubble v2-chat-bubble--out">My own business, we're a coaching agency.</div>
            <div class="v2-mkt-chat__badge">
                <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1">verified</span>
                Lead qualified — booking offered
            </div>
            <div class="v2-chat-bubble v2-chat-bubble--in">Perfect! Let's grab 15 min — here's my calendar: calendly.com/you 📅</div>
        </div>
    </div>
</section>

<section class="v2-cta-band hip-cta-band fade-up">
    <h2>Train it. Chat with it. Sign up.</h2>
    <p>Experience the full flow on our interactive demo.</p>
    <a href="/demo" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--white">
        <span class="material-symbols-outlined">forum</span> Start Interactive Demo
    </a>
</section>

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
