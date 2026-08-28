<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$activePage = 'contact';
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $subject = trim($_POST['subject'] ?? 'General Inquiry');
        $message = trim($_POST['message'] ?? '');

        if ($name === '' || !$email || $message === '') {
            $error = 'Please fill in all required fields.';
        } else {
            $body = '<h2>Contact Form</h2>'
                . '<p><strong>Name:</strong> ' . htmlspecialchars($name) . '</p>'
                . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>'
                . '<p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>'
                . '<p>' . nl2br(htmlspecialchars($message)) . '</p>';
            send_email(ADMIN_EMAIL, 'Contact: ' . $subject, $body);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Contact') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body min-h-[100dvh]">
<?php include __DIR__ . '/includes/marketing-header.php'; ?>

<section class="v2-mkt-hero scroll-mt-24">
    <div class="v2-mkt-hero__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
    <div class="hip-wrap v2-mkt-hero__content fade-up">
        <span class="v2-eyebrow">Contact</span>
        <h1 class="v2-mkt-hero__title">Get in <em>touch.</em></h1>
        <p class="v2-mkt-hero__lead">Setup, pricing, or enterprise plans — we reply within 24 hours.</p>
    </div>
</section>

<section class="v2-section v2-section--alt hip-section hip-section--alt">
    <div class="v2-section__layers" aria-hidden="true"><div class="v2-section__gradient v2-section__gradient--reverse"></div></div>
    <div class="hip-wrap max-w-5xl">
        <div class="v2-contact-grid grid md:grid-cols-5 gap-lg fade-up">
            <div class="md:col-span-2">
                <div class="v2-contact-sidebar v2-glass">
                    <div class="v2-contact-sidebar__block">
                        <span class="material-symbols-outlined v2-contact-sidebar__icon" aria-hidden="true">mail</span>
                        <div class="v2-contact-sidebar__body">
                            <h3>Email us</h3>
                            <a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>" class="v2-contact-sidebar__link"><?= sanitize(ADMIN_EMAIL) ?></a>
                        </div>
                    </div>
                    <div class="v2-contact-sidebar__block">
                        <span class="material-symbols-outlined v2-contact-sidebar__icon" aria-hidden="true">schedule</span>
                        <div class="v2-contact-sidebar__body">
                            <h3>Response time</h3>
                            <p>Within 24 hours on business days</p>
                        </div>
                    </div>
                    <div class="v2-contact-sidebar__actions">
                        <p class="v2-contact-sidebar__hint">Try the AI rep on WhatsApp or web before you write in.</p>
                        <a href="/demo" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary w-full justify-center">
                            <span class="material-symbols-outlined">forum</span> Try live demo
                        </a>
                        <?php if (whatsapp_demo_available()): ?>
                            <?php $variant = 'hippo'; include __DIR__ . '/includes/marketing-whatsapp-demo.php'; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="md:col-span-3">
                <?php if ($success): ?>
                <div class="v2-card v2-contact-success v2-mkt-success">
                    <span class="material-symbols-outlined text-4xl text-primary mb-md">check_circle</span>
                    <h2 class="v2-section-title hip-title">Message sent!</h2>
                    <p class="v2-section-lead hip-lead hip-lead--center">We'll get back to you within 24 hours.</p>
                </div>
                <?php else: ?>
                    <?php if ($error): ?>
                    <div class="v2-mkt-alert v2-mkt-alert--error"><?= sanitize($error) ?></div>
                    <?php endif; ?>
                    <form method="POST" class="v2-card v2-contact-form v2-mkt-form mkt-form">
                        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>
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

<?php include __DIR__ . '/includes/marketing-footer.php'; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
