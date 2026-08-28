<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/auth.php';



$activePage = 'privacy';

$siteUrl = rtrim(app_canonical_url(), '/');

$effectiveDate = 'July 15, 2026';

$brandName = 'IQ Pigeon';

?>

<!DOCTYPE html>

<html lang="en">

<head>

<?= page_head('Privacy Policy') ?>

<?= marketing_assets() ?>

</head>

<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">

<?php include __DIR__ . '/includes/marketing-header.php'; ?>



<section class="v2-mkt-hero scroll-mt-24">

    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap v2-mkt-hero__content fade-up">

        <span class="v2-eyebrow">Legal</span>

        <h1 class="v2-mkt-hero__title">Privacy <em>policy.</em></h1>

        <p class="v2-mkt-hero__lead">How <?= sanitize($brandName) ?> collects, uses, and protects your information.</p>

        <p class="v2-mkt-hero__meta">Effective date: <?= sanitize($effectiveDate) ?></p>

    </div>

</section>



<section class="v2-section v2-section--alt hip-section hip-section--alt">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap v2-mkt-content">

        <article class="v2-card v2-mkt-prose fade-up">

            <section>

                <h2>1. Who we are</h2>

                <p><?= sanitize($brandName) ?> provides a software platform for AI-assisted sales conversations on WhatsApp and other channels.</p>

                <p>Contact: <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>"><?= sanitize(ADMIN_EMAIL) ?></a></p>

            </section>



            <section>

                <h2>2. Information we collect</h2>

                <ul>

                    <li>Account details: name, email, company, hashed password</li>

                    <li>Bot settings, training content, catalog, and business data you enter</li>

                    <li>WhatsApp/message content, phone numbers, and lead history</li>

                    <li>Billing status via payment partners (we do not store full card numbers)</li>

                    <li>Technical logs: IP, browser, timestamps for security</li>

                </ul>

            </section>



            <section>

                <h2>3. How we use information</h2>

                <p>To operate the service, send AI replies, deliver notifications, process billing, prevent abuse, and comply with law. We do not sell personal data.</p>

            </section>



            <section>

                <h2>4. Third parties</h2>

                <p>Meta (WhatsApp), Google Sign-In, AI providers (e.g. DeepSeek), Stripe/PayPak, and email/hosting providers. WhatsApp use is subject to Meta and WhatsApp Business terms.</p>

            </section>



            <section>

                <h2>5. Your customers</h2>

                <p>If you message a business using <?= sanitize($brandName) ?>, that business controls your data. Contact them first; we process messages on their behalf.</p>

            </section>



            <section>

                <h2>6. Security &amp; retention</h2>

                <p>We use HTTPS, encrypted channel tokens, and tenant isolation. Data is kept while your subscription is active and as required for legal/billing purposes.</p>

            </section>



            <section>

                <h2>7. Your rights</h2>

                <p>You may request access, correction, or deletion via our <a href="/data-deletion">Data Deletion</a> page or by emailing <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>"><?= sanitize(ADMIN_EMAIL) ?></a>.</p>

            </section>



            <section>

                <h2>8. Contact</h2>

                <p><strong><?= sanitize($brandName) ?></strong><br/>

                <a href="<?= sanitize($siteUrl) ?>"><?= sanitize($siteUrl) ?></a></p>

            </section>

        </article>

    </div>

</section>



<?php include __DIR__ . '/includes/marketing-footer.php'; ?>

<script src="/assets/js/app.js"></script>

</body>

</html>

