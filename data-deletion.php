<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/mailer.php';



$activePage = 'data-deletion';

$siteUrl = rtrim(app_canonical_url(), '/');

$effectiveDate = 'July 15, 2026';

$brandName = 'IQ Pigeon';



$loggedInUser = get_user();

$success = false;

$error = '';

$requestId = 0;



$prefillName = $loggedInUser ? trim((string) ($loggedInUser['name'] ?? '')) : '';

$prefillEmail = $loggedInUser ? trim((string) ($loggedInUser['email'] ?? '')) : '';

$prefillAccountEmail = $prefillEmail;



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_once __DIR__ . '/includes/data-deletion-requests.php';

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {

        $error = 'Invalid request. Please try again.';

    } else {

        $result = data_deletion_submit([

            'name'           => $_POST['name'] ?? '',

            'email'          => $_POST['email'] ?? '',

            'account_email'  => $_POST['account_email'] ?? '',

            'request_type'   => $_POST['request_type'] ?? 'account',

            'reason'         => $_POST['reason'] ?? '',

            'user_id'        => $loggedInUser ? (int) $loggedInUser['id'] : null,

            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '',

        ]);



        if ($result['success']) {

            $requestId = (int) ($result['id'] ?? 0);

            data_deletion_notify_admin($requestId);

            $success = true;

        } else {

            $error = $result['error'] ?? 'Could not submit request.';

        }

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<?= page_head('Data Deletion') ?>

<?= marketing_assets() ?>

</head>

<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">

<?php include __DIR__ . '/includes/marketing-header.php'; ?>



<section class="v2-mkt-hero scroll-mt-24">

    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>

    <div class="hip-wrap v2-mkt-hero__content fade-up">

        <span class="v2-eyebrow">Privacy</span>

        <h1 class="v2-mkt-hero__title">Data <em>deletion.</em></h1>

        <p class="v2-mkt-hero__lead">Request removal of personal data from <?= sanitize($brandName) ?>.</p>

        <p class="v2-mkt-hero__meta">Effective date: <?= sanitize($effectiveDate) ?></p>

    </div>

</section>



<section class="v2-section v2-section--alt hip-section hip-section--alt">

    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>

    <div class="hip-wrap max-w-5xl">

        <div class="v2-mkt-split fade-up">

            <article class="v2-card v2-mkt-prose v2-mkt-card--left">

                <section>

                    <h2>Platform account holders</h2>

                    <p>If you have an <?= sanitize($brandName) ?> login (trial or paid), you may request deletion of your account and associated data:</p>

                    <ul>

                        <li>Profile, company info, and bot configuration</li>

                        <li>Leads, conversation history, and uploaded catalog/knowledge</li>

                        <li>Connected channel tokens (WhatsApp, etc.)</li>

                        <li>Billing records retained only as required by law</li>

                    </ul>

                    <p>Submit the form or email <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>"><?= sanitize(ADMIN_EMAIL) ?></a>. We typically complete requests within <strong>30 days</strong>.</p>

                </section>



                <section>

                    <h2>WhatsApp / chat customers</h2>

                    <p>If you messaged a <em>business</em> that uses <?= sanitize($brandName) ?> (not <?= sanitize($brandName) ?> itself), that business controls your chat data. Contact them first.</p>

                    <p>If they cannot help, use the form and select <strong>End customer</strong> — we will forward the request to the business owner.</p>

                </section>



                <section>

                    <h2>Facebook / Meta login</h2>

                    <p>If you signed in with Facebook or connected WhatsApp through Meta, you may also remove app access in Facebook Settings → Apps and Websites. Submitting here ensures we remove copies stored on <?= sanitize($siteUrl) ?>.</p>

                </section>



                <section>

                    <h2>What we keep</h2>

                    <p>We may retain minimal records (e.g. payment invoices, fraud-prevention logs) where law requires. Anonymized analytics may be kept without identifying you.</p>

                </section>

            </article>



            <div>

                <?php if ($success): ?>

                <div class="v2-card v2-mkt-success">

                    <span class="material-symbols-outlined text-4xl text-primary mb-md">check_circle</span>

                    <h2 class="v2-section-title hip-title">Request received</h2>

                    <p class="v2-section-lead hip-lead hip-lead--center mb-sm">Reference #<?= (int) $requestId ?>. We will email you when your request is processed (usually within 30 days).</p>

                    <p class="v2-mkt-hero__meta">Questions? <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>" class="text-primary"><?= sanitize(ADMIN_EMAIL) ?></a></p>

                </div>

                <?php else: ?>

                    <?php if ($error): ?>

                    <div class="v2-mkt-alert v2-mkt-alert--error"><?= sanitize($error) ?></div>

                    <?php endif; ?>



                    <?php if ($loggedInUser): ?>

                    <div class="v2-mkt-alert v2-mkt-alert--info mb-md">

                        Signed in as <strong><?= sanitize($prefillEmail) ?></strong>. Your request will be linked to your account for faster processing.

                    </div>

                    <?php endif; ?>



                    <form method="POST" class="v2-card v2-mkt-form mkt-form">

                        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>



                        <div class="mkt-form__group">

                            <label class="mkt-form__label" for="request-type">Request type *</label>

                            <select id="request-type" name="request_type" class="mkt-select">

                                <option value="account">Platform account — delete my IQ Pigeon account/data</option>

                                <option value="customer">End customer — I messaged a business using IQ Pigeon</option>

                            </select>

                        </div>



                        <div class="mkt-form__group">

                            <label class="mkt-form__label" for="deletion-name">Your name *</label>

                            <input type="text" id="deletion-name" name="name" required value="<?= sanitize($prefillName) ?>" class="mkt-input"/>

                        </div>



                        <div class="mkt-form__group">

                            <label class="mkt-form__label" for="deletion-email">Contact email *</label>

                            <input type="email" id="deletion-email" name="email" required value="<?= sanitize($prefillEmail) ?>" class="mkt-input"/>

                            <p class="v2-mkt-hero__meta mt-xs">We send status updates here.</p>

                        </div>



                        <div class="mkt-form__group">

                            <label class="mkt-form__label" for="deletion-account-email">Account email (if different)</label>

                            <input type="email" id="deletion-account-email" name="account_email" value="<?= sanitize($prefillAccountEmail) ?>"

                                   placeholder="Login email on iqpigeon.com" class="mkt-input"/>

                        </div>



                        <div class="mkt-form__group">

                            <label class="mkt-form__label" for="deletion-reason">Details (optional)</label>

                            <textarea id="deletion-reason" name="reason" rows="4" placeholder="Phone number used on WhatsApp, business name, or anything that helps us find your data..." class="mkt-textarea"></textarea>

                        </div>



                        <p class="v2-mkt-hero__meta mb-md">By submitting, you confirm you are the data subject or authorized to make this request.</p>



                        <button type="submit" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary hip-btn--block">Submit deletion request</button>

                    </form>

                <?php endif; ?>

            </div>

        </div>

    </div>

</section>



<?php include __DIR__ . '/includes/marketing-footer.php'; ?>

<script src="/assets/js/app.js"></script>

</body>

</html>

