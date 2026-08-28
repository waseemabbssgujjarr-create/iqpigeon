/**
 * Connect WhatsApp page — immediate UI feedback, polling, auto-redirect.
 * Loaded only on /client/connect-whatsapp (works even if app.js is cached).
 */
(function () {
    'use strict';

    const POLL_MS = 1500;
    const STUCK_MS = 120000;
    const DASHBOARD = '/client/dashboard?welcome=1&connected=1';
    const DEBUG_URL = '/client/whatsapp-oauth-debug';

    let pollTimer = null;
    let stuckTimer = null;
    let recoverAttempted = false;

    function btn() {
        return document.getElementById('connect-wa-primary')
            || document.querySelector('[data-wa-oauth-connect]');
    }

    function statusEl() {
        return document.getElementById('wa-connect-status');
    }

    function spinHtml(label) {
        return '<span class="wa-connect-spin" aria-hidden="true"></span><span>' + label + '</span>';
    }

    function clientId() {
        const b = btn();
        return parseInt(b && b.getAttribute('data-wa-client-id') || '0', 10) || 0;
    }

    function setPhase(phase) {
        const b = btn();
        const s = statusEl();
        if (b && !b.dataset.waOauthOriginalHtml) {
            b.dataset.waOauthOriginalHtml = b.innerHTML;
        }
        if (phase === 'idle') {
            if (b && b.dataset.waOauthOriginalHtml) {
                b.disabled = false;
                b.removeAttribute('aria-busy');
                b.classList.remove('opacity-70', 'pointer-events-none', 'wa-connect-busy');
                b.innerHTML = b.dataset.waOauthOriginalHtml;
            }
            if (s) {
                s.classList.add('hidden');
            }
            stopPoll();
            clearStuckTimer();
            return;
        }
        if (b) {
            b.disabled = true;
            b.setAttribute('aria-busy', 'true');
            b.classList.add('opacity-70', 'pointer-events-none', 'wa-connect-busy');
        }
        if (s) {
            s.classList.remove('hidden');
        }
        const labels = {
            connecting: ['Connecting…', 'Opening Meta signup…'],
            meta: ['Waiting for Meta…', 'Complete signup in the Meta window — this page stays open.'],
            saving: ['Saving connection…', 'Saving your WhatsApp connection…'],
            stuck: ['Still saving…', 'Taking longer than usual. Open Connection debug below if this continues.'],
        };
        const pair = labels[phase] || labels.meta;
        if (b) {
            b.innerHTML = spinHtml(pair[0]);
        }
        if (s) {
            s.textContent = pair[1];
        }
    }

    function markPending() {
        try {
            sessionStorage.setItem('wa_oauth_pending', String(Date.now()));
        } catch (e) { /* ignore */ }
    }

    function clearPending() {
        try {
            sessionStorage.removeItem('wa_oauth_pending');
            sessionStorage.removeItem('wa_signup_pending_code');
            sessionStorage.removeItem('wa_signup_session');
        } catch (e) { /* ignore */ }
    }

    function isPending() {
        try {
            return !!sessionStorage.getItem('wa_oauth_pending');
        } catch (e) {
            return false;
        }
    }

    function clearStuckTimer() {
        if (stuckTimer) {
            clearTimeout(stuckTimer);
            stuckTimer = null;
        }
    }

    function armStuckTimer() {
        clearStuckTimer();
        stuckTimer = setTimeout(function () {
            if (!isPending()) {
                return;
            }
            setPhase('stuck');
            const s = statusEl();
            if (s) {
                s.innerHTML = 'Still not connected. <a href="' + DEBUG_URL + '" class="underline underline-offset-2">Open connection debug</a> — use step 2 “Complete via FB SDK” after Meta shows shared.';
            }
        }, STUCK_MS);
    }

    function redirectConnected() {
        clearPending();
        stopPoll();
        clearStuckTimer();
        if (window.App && typeof App.toast === 'function') {
            App.toast('WhatsApp connected!', 'success');
        }
        window.location.replace(DASHBOARD);
    }

    function tryRecoverSignup() {
        const id = clientId();
        if (!id || recoverAttempted) {
            return Promise.resolve(false);
        }
        if (!window.App || typeof App.recoverWaSignupIfPending !== 'function') {
            return Promise.resolve(false);
        }
        recoverAttempted = true;
        setPhase('saving');
        return App.recoverWaSignupIfPending(id).then(function (ok) {
            if (ok) {
                redirectConnected();
            }
            return !!ok;
        }).catch(function () {
            recoverAttempted = false;
            return false;
        });
    }

    function checkConnected() {
        return fetch('/api/whatsapp/connection-status.php', {
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.connected) {
                    redirectConnected();
                    return true;
                }
                return false;
            })
            .catch(function () { return false; });
    }

    function onPendingActivity() {
        if (!isPending()) {
            return;
        }
        setPhase('saving');
        checkConnected().then(function (connected) {
            if (!connected) {
                return tryRecoverSignup();
            }
            return connected;
        });
    }

    function startPoll(options) {
        const opts = options || {};
        if (pollTimer) {
            if (opts.forceSaving) {
                setPhase('saving');
            }
            return;
        }
        if (opts.forceSaving) {
            setPhase('saving');
        }
        armStuckTimer();
        onPendingActivity();
        pollTimer = setInterval(function () {
            if (!isPending()) {
                stopPoll();
                return;
            }
            onPendingActivity();
        }, POLL_MS);
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function arm() {
        recoverAttempted = false;
        markPending();
        setPhase('connecting');
        startPoll({});
    }

    function onMetaOpen() {
        markPending();
        setPhase('meta');
        startPoll({});
    }

    function onMetaFinish() {
        recoverAttempted = false;
        markPending();
        setPhase('saving');
        startPoll({ forceSaving: true });
        onPendingActivity();
    }

    document.addEventListener('click', function (e) {
        const target = e.target && e.target.closest
            ? e.target.closest('#connect-wa-primary,[data-wa-oauth-connect]')
            : null;
        if (!target || target.disabled) {
            return;
        }
        arm();
    }, true);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            onPendingActivity();
        }
    });

    window.addEventListener('focus', function () {
        onPendingActivity();
    });

    window.WaConnect = {
        setPhase: setPhase,
        arm: arm,
        onMetaOpen: onMetaOpen,
        onMetaFinish: onMetaFinish,
        startPoll: startPoll,
        checkConnected: checkConnected,
        redirectConnected: redirectConnected,
        tryRecoverSignup: tryRecoverSignup,
    };

    if (isPending()) {
        let hasSignup = false;
        try {
            hasSignup = !!(sessionStorage.getItem('wa_signup_pending_code')
                || sessionStorage.getItem('wa_signup_session')
                || sessionStorage.getItem('wa_signup_meta_finish'));
        } catch (e) { /* ignore */ }
        if (hasSignup) {
            setPhase('saving');
            startPoll({ forceSaving: true });
        } else {
            try {
                sessionStorage.removeItem('wa_oauth_pending');
            } catch (e) { /* ignore */ }
        }
    }
})();
