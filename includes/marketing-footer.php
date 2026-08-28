<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/auth.php';

?>

<footer class="marketing-footer">

    <div class="px-edge-margin py-xl max-w-7xl mx-auto">

        <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-xl mb-xl">

            <div class="lg:col-span-2">

                <div class="flex items-center mb-md">
                    <?= brand_logo_markup('brand-logo-img', 'dark') ?>
                </div>

                <p class="text-body-md marketing-footer__text mb-md max-w-sm">

                    Human-like AI sales reps for WhatsApp, Instagram, and your website. Train on your business, qualify leads naturally, and get live notifications when deals are hot.

                </p>

                <?php if (whatsapp_demo_available()): ?>

                <a href="<?= sanitize(whatsapp_demo_href()) ?>" target="_blank" rel="noopener noreferrer"

                   class="inline-flex items-center gap-sm px-md py-sm rounded-xl marketing-header-btn marketing-header-btn--whatsapp font-title text-title-md hover-lift transition-all duration-300">

                    <span class="material-symbols-outlined">chat</span>

                    Try Live on WhatsApp

                </a>

                <?php else: ?>

                <a href="/#demo"

                   class="inline-flex items-center gap-sm px-md py-sm rounded-xl bg-secondary text-on-secondary font-title text-title-md hover-lift transition-all duration-300">

                    <span class="material-symbols-outlined">forum</span>

                    Try Live Demo

                </a>

                <?php endif; ?>

                <div class="mt-lg">

                    <p class="font-title text-title-md mb-sm marketing-footer__heading">Product updates</p>

                    <form id="footer-subscribe-form" class="flex flex-col sm:flex-row gap-sm max-w-md">

                        <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>

                        <input type="email" name="email" required placeholder="Your email"

                               class="marketing-footer__input flex-1 h-12 px-md rounded-xl text-body-md"/>

                        <button type="submit" class="h-12 px-lg rounded-xl bg-secondary text-on-secondary font-title text-title-md shrink-0 active:scale-95">

                            Subscribe

                        </button>

                    </form>

                    <p id="footer-subscribe-msg" class="text-body-md mt-sm hidden"></p>

                </div>

            </div>

            <div>

                <p class="font-title text-title-md mb-md marketing-footer__heading">Product</p>

                <ul class="space-y-sm text-body-md marketing-footer__text">

                    <li><a href="/#features" class="footer-link nav-scroll">Features</a></li>

                    <li><a href="/#how-it-works" class="footer-link nav-scroll">How It Works</a></li>

                    <li><a href="/#integrations" class="footer-link nav-scroll">Integrations</a></li>

                    <li><a href="/#pricing" class="footer-link nav-scroll">Pricing</a></li>

                    <li><a href="/#demo" class="footer-link nav-scroll">Live Demo</a></li>

                    <?php if (function_exists('android_apk_available') && android_apk_available()): ?>

                    <li><a href="<?= sanitize(android_apk_url()) ?>" class="footer-link">Android App</a></li>

                    <?php endif; ?>

                </ul>

            </div>

            <div>

                <p class="font-title text-title-md mb-md marketing-footer__heading">Company</p>

                <ul class="space-y-sm text-body-md marketing-footer__text">

                    <li><a href="/about" class="footer-link">About Us</a></li>

                    <li><a href="/privacy" class="footer-link">Privacy Policy</a></li>

                    <li><a href="/terms" class="footer-link">Terms of Service</a></li>

                    <li><a href="/data-deletion" class="footer-link">Data Deletion</a></li>

                    <li><a href="/contact" class="footer-link">Contact</a></li>

                    <li><a href="/register" class="footer-link">Free Trial</a></li>

                    <li><a href="/login" class="footer-link">Login</a></li>

                </ul>

            </div>

            <div>

                <p class="font-title text-title-md mb-md marketing-footer__heading">Support</p>

                <ul class="space-y-sm text-body-md marketing-footer__text">

                    <li><a href="/#contact" class="footer-link nav-scroll">Help Center</a></li>

                    <li><a href="mailto:<?= sanitize(ADMIN_EMAIL) ?>" class="footer-link">Email Support</a></li>

                </ul>

            </div>

        </div>

        <div class="marketing-footer__divider border-t pt-lg flex flex-col sm:flex-row justify-between items-center gap-md">

            <p class="text-label-sm font-label marketing-footer__faint">© <?= date('Y') ?> <?= sanitize(APP_NAME) ?>. All rights reserved.</p>

            <div class="flex flex-wrap justify-center sm:justify-end gap-x-md gap-y-xs text-label-sm font-label marketing-footer__faint">

                <span>GDPR Ready</span>

                <span class="opacity-70" aria-hidden="true">|</span>

                <span>Encrypted Tokens</span>

                <span class="opacity-70" aria-hidden="true">|</span>

                <span>99.9% Uptime</span>

            </div>

        </div>

    </div>

</footer>



<?php include __DIR__ . '/marketing-mobile-nav.php'; ?>

<?php include __DIR__ . '/marketing-demo-widget.php'; ?>



<script src="/assets/js/landing.js?v=<?= @filemtime(__DIR__ . '/../assets/js/landing.js') ?: time() ?>"></script>

