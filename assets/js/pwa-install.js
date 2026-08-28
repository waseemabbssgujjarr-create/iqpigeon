/**
 * PWA install prompt — logged-in client portal, mobile only.
 */
(function () {
    'use strict';

    const script = document.currentScript;
    if (!script || script.getAttribute('data-client-pwa') !== '1') {
        return;
    }

    const APP_NAME = script.getAttribute('data-app-name') || 'IQ Pigeon';
    const DISMISS_KEY = 'iqpigeon_pwa_install_dismissed';
    const DISMISS_DAYS = 14;

    let deferredPrompt = null;

    function isMobileViewport() {
        return window.matchMedia('(max-width: 767px)').matches;
    }

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    function isIosSafari() {
        const ua = navigator.userAgent;
        const isIos = /iPhone|iPad|iPod/i.test(ua);
        const isSafari = /Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua);
        return isIos && isSafari;
    }

    function wasDismissedRecently() {
        try {
            const raw = localStorage.getItem(DISMISS_KEY);
            if (!raw) {
                return false;
            }
            const ts = parseInt(raw, 10);
            return !Number.isNaN(ts) && Date.now() - ts < DISMISS_DAYS * 86400000;
        } catch {
            return false;
        }
    }

    function canShowBanner() {
        return isMobileViewport()
            && !isStandalone()
            && !wasDismissedRecently();
    }

    function dismissBanner(bar) {
        try {
            localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            /* ignore */
        }
        bar?.remove();
    }

    function installMode() {
        if (deferredPrompt) {
            return 'native';
        }
        if (isIosSafari()) {
            return 'ios';
        }
        return null;
    }

    function createBanner(mode) {
        if (document.getElementById('pwa-install-banner') || !canShowBanner()) {
            return;
        }

        const bar = document.createElement('div');
        bar.id = 'pwa-install-banner';
        bar.className = 'pwa-install-banner';
        bar.setAttribute('role', 'dialog');
        bar.setAttribute('aria-label', 'Install app');

        const iosHint = mode === 'ios'
            ? '<p class="pwa-install-banner__ios-hint">Tap <strong>Share</strong>, then <strong>Add to Home Screen</strong>.</p>'
            : '';

        const buttonLabel = mode === 'ios' ? 'Got it' : 'Install app';

        bar.innerHTML = `
            <div class="pwa-install-banner__head">
                <span class="material-symbols-outlined pwa-install-banner__icon" aria-hidden="true">install_mobile</span>
                <div class="pwa-install-banner__copy">
                    <p class="pwa-install-banner__title">Install ${APP_NAME}</p>
                    <p class="pwa-install-banner__text">Open from your home screen like a native app.</p>
                    ${iosHint}
                </div>
                <button type="button" class="pwa-install-banner__close" aria-label="Dismiss">&times;</button>
            </div>
            <button type="button" class="pwa-install-banner__btn">${buttonLabel}</button>
        `;

        document.body.appendChild(bar);

        bar.querySelector('.pwa-install-banner__close')?.addEventListener('click', () => {
            dismissBanner(bar);
        });

        bar.querySelector('.pwa-install-banner__btn')?.addEventListener('click', async () => {
            if (mode === 'ios') {
                dismissBanner(bar);
                return;
            }

            if (!deferredPrompt) {
                return;
            }

            try {
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;
                if (choice.outcome === 'accepted') {
                    dismissBanner(bar);
                }
            } catch {
                /* prompt may fail if already used */
            }

            deferredPrompt = null;
        });
    }

    function maybeShowBanner() {
        const mode = installMode();
        if (mode) {
            createBanner(mode);
        }
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        maybeShowBanner();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        document.getElementById('pwa-install-banner')?.remove();
    });

    function init() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js').catch(() => {});
        }

        if (!canShowBanner()) {
            return;
        }

        // Show after the client dashboard loads (post-login), not immediately on auth pages.
        setTimeout(maybeShowBanner, 1200);
    }

    window.matchMedia('(max-width: 767px)').addEventListener('change', (event) => {
        if (!event.matches) {
            document.getElementById('pwa-install-banner')?.remove();
            return;
        }
        maybeShowBanner();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
