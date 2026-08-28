<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/auth.php';



$activePage = 'terms';

$siteUrl = rtrim(app_canonical_url(), '/');

$effectiveDate = 'July 15, 2026';

$brandName = 'IQ Pigeon';

?>

<!DOCTYPE html>

<html lang="en">

<head>

<?= page_head('Terms of Service') ?>

<?= marketing_assets() ?>

</head>

<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">

<?php include __DIR__ . '/includes/marketing-header.php'; ?>



<section class="v2-mkt-hero scroll-mt-24">

    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap v2-mkt-hero__content fade-up">

        <span class="v2-eyebrow">Legal</span>

        <h1 class="v2-mkt-hero__title">Terms of <em>service.</em></h1>

        <p class="v2-mkt-hero__lead">Rules for using <?= sanitize($brandName) ?> at <a href="<?= sanitize($siteUrl) ?>" class="text-primary">iqpigeon.com</a>.</p>

        <p class="v2-mkt-hero__meta">Effective date: <?= sanitize($effectiveDate) ?></p>

    </div>

</section>



<section class="v2-section v2-section--alt hip-section hip-section--alt">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap v2-mkt-content">

        <article class="v2-card v2-mkt-prose fade-up">

            <section>

                <h2>1. Agreement</h2>

                <p>By creating an account, connecting WhatsApp or other channels, or using <?= sanitize($brandName) ?>, you agree to these Terms and our <a href="/privacy">Privacy Policy</a>. If you do not agree, do not use the service.</p>

            </section>



            <section>

                <h2>2. Service description</h2>

                <p><?= sanitize($brandName) ?> is a software-as-a-service platform that helps businesses automate sales conversations on WhatsApp, website chat, and related channels using AI. We provide tools for bot setup, lead management, catalog integration, and messaging — you remain responsible for your business content, compliance, and customer relationships.</p>

            </section>



            <section>

                <h2>3. Accounts &amp; eligibility</h2>

                <ul>

                    <li>You must provide accurate registration information and keep your login secure.</li>

                    <li>You must be at least 18 years old and authorized to bind your business.</li>

                    <li>One person or business may not share accounts in a way that violates plan limits or fair use.</li>

                    <li>We may suspend accounts for abuse, fraud, or violation of these Terms or applicable law.</li>

                </ul>

            </section>



            <section>

                <h2>4. Acceptable use</h2>

                <p>You agree not to use <?= sanitize($brandName) ?> to:</p>

                <ul>

                    <li>Send spam, unsolicited bulk messages, or content that violates WhatsApp/Meta policies</li>

                    <li>Harass, defraud, or impersonate others</li>

                    <li>Process illegal goods/services or content prohibited by Meta, Stripe, or local law</li>

                    <li>Attempt to bypass security, scrape other tenants' data, or reverse-engineer the platform</li>

                    <li>Misrepresent AI-generated replies as human when required by law or platform rules</li>

                </ul>

                <p>You are solely responsible for messages sent through your connected channels and for obtaining consent where required.</p>

            </section>



            <section>

                <h2>5. WhatsApp &amp; third-party platforms</h2>

                <p>WhatsApp, Instagram, Google, payment providers, and AI APIs have their own terms. Connecting those services means you also comply with their policies. We are not responsible for outages, account bans, or policy changes by third parties.</p>

            </section>



            <section>

                <h2>6. Subscriptions &amp; billing</h2>

                <p>Paid plans renew according to the billing cycle shown at checkout unless cancelled. Trials convert to paid plans when the trial ends unless you cancel before that date. Fees are non-refundable except where required by law or explicitly stated in writing.</p>

            </section>



            <section>

                <h2>7. Your content</h2>

                <p>You retain ownership of business knowledge, catalogs, and customer data you upload. You grant us a limited license to host, process, and transmit that content solely to operate the service (including AI replies and backups).</p>

            </section>



            <section>

                <h2>8. Disclaimer &amp; limitation of liability</h2>

                <p>The service is provided "as is." AI replies may be inaccurate; you must review critical business rules and monitor conversations. To the maximum extent permitted by law, <?= sanitize($brandName) ?> is not liable for indirect, incidental, or consequential damages, lost profits, or data loss arising from use of the service.</p>

            </section>



            <section>

                <h2>9. Termination</h2>

                <p>You may stop using the service at any time. We may terminate or suspend access for breach of these Terms. Upon termination, your right to use the platform ends; see our <a href="/data-deletion">Data Deletion</a> page to request removal of your account data.</p>

            </section>



            <section>

                <h2>10. Changes</h2>

                <p>We may update these Terms. Material changes will be posted on this page with an updated effective date. Continued use after changes constitutes acceptance.</p>

            </section>



            <section>

                <h2>11. Contact</h2>

                <p><strong><?= sanitize($brandName) ?></strong><br/>

                Email: <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>"><?= sanitize(ADMIN_EMAIL) ?></a><br/>

                Web: <a href="<?= sanitize($siteUrl) ?>"><?= sanitize($siteUrl) ?></a></p>

            </section>

        </article>

    </div>

</section>



<?php include __DIR__ . '/includes/marketing-footer.php'; ?>

<script src="/assets/js/app.js"></script>

</body>

</html>

