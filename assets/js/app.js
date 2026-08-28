/**
 * Shared app utilities — toast, modal, bottom sheet, nav helpers.
 */

const App = {
    /**
     * Show a toast notification.
     * @param {string} message
     * @param {'success'|'error'|'info'} type
     * @param {number} duration ms
     */
    toast(message, type = 'info', duration = 3500) {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        container.appendChild(el);

        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s';
            setTimeout(() => el.remove(), 300);
        }, duration);
    },

    /**
     * Open a bottom sheet by ID.
     * @param {string} sheetId
     */
    openBottomSheet(sheetId) {
        const overlay = document.getElementById(`${sheetId}-overlay`);
        const sheet = document.getElementById(sheetId);
        if (overlay) overlay.classList.add('open');
        if (sheet) sheet.classList.add('open');
        document.body.style.overflow = 'hidden';
    },

    /**
     * Close a bottom sheet by ID.
     * @param {string} sheetId
     */
    closeBottomSheet(sheetId) {
        const overlay = document.getElementById(`${sheetId}-overlay`);
        const sheet = document.getElementById(sheetId);
        if (overlay) overlay.classList.remove('open');
        if (sheet) sheet.classList.remove('open');
        document.body.style.overflow = '';
    },

    openMobileMenu() {
        const overlay = document.getElementById('client-mobile-menu-overlay');
        const drawer = document.getElementById('client-mobile-menu');
        const trigger = document.querySelector('.client-mobile-menu-btn[aria-controls="client-mobile-menu"]');
        if (overlay) {
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        if (drawer) {
            drawer.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');
        }
        document.body.style.overflow = 'hidden';
    },

    closeMobileMenu() {
        const overlay = document.getElementById('client-mobile-menu-overlay');
        const drawer = document.getElementById('client-mobile-menu');
        const trigger = document.querySelector('.client-mobile-menu-btn[aria-controls="client-mobile-menu"]');
        if (overlay) {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        if (drawer) {
            drawer.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
        const modalOpen = document.getElementById('app-confirm-modal')
            || document.querySelector('.bottom-sheet.open');
        if (!modalOpen) {
            document.body.style.overflow = '';
        }
    },

    openAdminSidebar() {
        const sidebar = document.getElementById('admin-sidebar');
        const overlay = document.getElementById('admin-sidebar-overlay');
        const toggle = document.getElementById('admin-sidebar-toggle');
        document.body.classList.add('admin-sidebar-open');
        if (sidebar) {
            sidebar.classList.add('is-open');
        }
        if (overlay) {
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-label', 'Close admin menu');
        }
        document.body.style.overflow = 'hidden';
    },

    closeAdminSidebar() {
        const sidebar = document.getElementById('admin-sidebar');
        const overlay = document.getElementById('admin-sidebar-overlay');
        const toggle = document.getElementById('admin-sidebar-toggle');
        document.body.classList.remove('admin-sidebar-open');
        if (sidebar) {
            sidebar.classList.remove('is-open');
        }
        if (overlay) {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Open admin menu');
        }
        const modalOpen = document.getElementById('app-confirm-modal')
            || document.querySelector('.bottom-sheet.open');
        if (!modalOpen) {
            document.body.style.overflow = '';
        }
    },

    toggleAdminSidebar() {
        const sidebar = document.getElementById('admin-sidebar');
        if (sidebar?.classList.contains('is-open')) {
            this.closeAdminSidebar();
        } else {
            this.openAdminSidebar();
        }
    },

    /**
     * Confirm dialog — centered card, independent of Tailwind purge.
     * @param {string} message
     * @param {Function} onConfirm
     * @param {{title?: string, confirmLabel?: string, danger?: boolean}} [opts]
     */
    confirm(message, onConfirm, opts) {
        const options = opts && typeof opts === 'object' ? opts : {};
        const title = String(options.title || 'Please confirm');
        const confirmLabel = String(options.confirmLabel || 'Confirm');
        const danger = !!options.danger;
        const esc = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        const existing = document.getElementById('app-confirm-modal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = 'app-confirm-modal';
        modal.className = 'iqp-confirm';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'iqp-confirm-title');
        modal.innerHTML = `
            <div class="iqp-confirm__backdrop" data-dismiss></div>
            <div class="iqp-confirm__card">
                <h2 id="iqp-confirm-title" class="iqp-confirm__title">${esc(title)}</h2>
                <p class="iqp-confirm__body">${esc(message)}</p>
                <div class="iqp-confirm__actions">
                    <button type="button" class="iqp-confirm__btn iqp-confirm__btn--cancel" data-dismiss>Cancel</button>
                    <button type="button" class="iqp-confirm__btn ${danger ? 'iqp-confirm__btn--danger' : 'iqp-confirm__btn--ok'}" data-confirm>${esc(confirmLabel)}</button>
                </div>
            </div>`;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        const onKey = (event) => {
            if (event.key === 'Escape') {
                close();
            }
        };
        const close = () => {
            document.removeEventListener('keydown', onKey);
            modal.remove();
            document.body.style.overflow = '';
        };

        document.addEventListener('keydown', onKey);
        modal.querySelectorAll('[data-dismiss]').forEach((el) => {
            el.addEventListener('click', close);
        });
        modal.querySelector('[data-confirm]').addEventListener('click', () => {
            close();
            onConfirm();
        });
        modal.querySelector('[data-confirm]').focus();
    },

    /**
     * Highlight active bottom nav tab.
     * @param {string} activeTab home|connect|monitor|settings|leads|clients|bots
     */
    setActiveNav(activeTab) {
        document.querySelectorAll('[data-nav]').forEach(el => {
            const isActive = el.dataset.nav === activeTab;
            el.classList.toggle('active', isActive);
            if (isActive) {
                el.setAttribute('aria-current', 'page');
            } else {
                el.removeAttribute('aria-current');
            }
        });
    },

    /** @returns {Promise<void>} */
    ensureFbSdkReady(timeoutMs) {
        if (typeof FB !== 'undefined' && window.fbSdkReady) {
            return Promise.resolve();
        }
        if (window.fbSdkFailed) {
            return Promise.reject(new Error('Facebook SDK blocked or failed to load'));
        }
        const waitMs = typeof timeoutMs === 'number' && timeoutMs > 0 ? timeoutMs : 15000;
        return new Promise((resolve, reject) => {
            let poll = null;
            const cleanup = () => {
                clearTimeout(timeout);
                if (poll) {
                    clearInterval(poll);
                }
                document.removeEventListener('fb-sdk-ready', onReady);
                document.removeEventListener('fb-sdk-error', onError);
            };
            const onReady = () => {
                cleanup();
                if (typeof FB !== 'undefined') {
                    resolve();
                } else {
                    reject(new Error('Facebook SDK not available'));
                }
            };
            const onError = () => {
                cleanup();
                reject(new Error('Facebook SDK blocked or failed to load'));
            };
            const timeout = setTimeout(() => {
                cleanup();
                reject(new Error('Facebook SDK load timeout'));
            }, waitMs);
            if (window.fbSdkReady && typeof FB !== 'undefined') {
                cleanup();
                resolve();
                return;
            }
            document.addEventListener('fb-sdk-ready', onReady, { once: true });
            document.addEventListener('fb-sdk-error', onError, { once: true });
            poll = setInterval(() => {
                if (window.fbSdkReady && typeof FB !== 'undefined') {
                    onReady();
                }
            }, 150);
        });
    },

    /** @returns {number} */
    getWaConnectClientId() {
        const btn = document.getElementById('connect-wa-primary')
            || document.querySelector('[data-wa-oauth-connect]');
        return parseInt(btn?.getAttribute('data-wa-client-id') || '0', 10) || 0;
    },

    /**
     * Request OAuth code after Meta FINISH (second FB.login — user usually already authorized).
     * @returns {Promise<string>}
     */
    requestWaOAuthCode(cfg) {
        const signupCfg = cfg || window.metaWaSignup || {};
        if (!signupCfg.configId) {
            return Promise.reject(new Error('WhatsApp signup is not configured.'));
        }
        return App.ensureFbSdkReady(8000).then(() => new Promise((resolve, reject) => {
            FB.login((response) => {
                if (response.authResponse && response.authResponse.code) {
                    resolve(response.authResponse.code);
                    return;
                }
                reject(new Error('Meta did not return an authorization code.'));
            }, App.waFbLoginOptions(signupCfg.configId));
        }));
    },

    waFbLoginOptions(configId) {
        return {
            config_id: configId,
            auth_type: 'rerequest',
            response_type: 'code',
            override_default_response_type: true,
            extras: {
                setup: {},
                featureType: 'whatsapp_business_app_onboarding',
                sessionInfoVersion: '3',
                version: 'v4',
            },
        };
    },

    /** @returns {boolean} */
    isWaEmbeddedSignupFinish(event) {
        const e = String(event || '').toUpperCase();
        return e === 'FINISH'
            || e === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'
            || e === 'FINISH_ONLY_WABA'
            || e === 'FINISH_OBO_MIGRATION'
            || e === 'FINISH_GRANT_ONLY_API_ACCESS'
            || (e.includes('FINISH') && !e.includes('CANCEL'));
    },

    isWaEmbeddedSignupReady(event, payload) {
        if (App.isWaEmbeddedSignupFinish(event)) {
            return true;
        }
        const session = App.parseWaEmbeddedSignupSession(payload);
        return !!(session.waba_id || session.phone_number_id);
    },

    /**
     * Pull a catalog ID out of Meta Embedded Signup session fields.
     * @param {unknown} value
     * @returns {string}
     */
    firstWaCatalogId(value) {
        if (value == null || value === '') {
            return '';
        }
        if (typeof value === 'number' && Number.isFinite(value)) {
            return String(value);
        }
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (!trimmed) {
                return '';
            }
            if (trimmed.startsWith('[')) {
                try {
                    return App.firstWaCatalogId(JSON.parse(trimmed));
                } catch (e) {
                    return '';
                }
            }
            if (trimmed.includes(',')) {
                return trimmed.split(',')[0].trim();
            }
            return trimmed;
        }
        if (Array.isArray(value)) {
            return App.firstWaCatalogId(value[0]);
        }
        if (typeof value === 'object') {
            return App.firstWaCatalogId(value.catalog_id || value.id || value.catalog_ids || '');
        }
        return '';
    },

    /** @param {Record<string, unknown>|null|undefined} payload */
    parseWaEmbeddedSignupSession(payload) {
        const data = payload && typeof payload === 'object' ? payload : {};
        const nested = data.data && typeof data.data === 'object' && !Array.isArray(data.data) ? data.data : {};
        const inner = nested.data && typeof nested.data === 'object' && !Array.isArray(nested.data) ? nested.data : {};
        const bags = [data, nested, inner];
        let wabaId = '';
        let phoneNumberId = '';
        let displayPhone = '';
        let catalogId = '';
        let businessId = '';
        bags.forEach((bag) => {
            if (!bag || typeof bag !== 'object') {
                return;
            }
            if (!wabaId) {
                wabaId = String(bag.waba_id || (Array.isArray(bag.waba_ids) ? bag.waba_ids[0] : '') || '');
            }
            if (!phoneNumberId) {
                phoneNumberId = String(bag.phone_number_id || '');
            }
            if (!displayPhone) {
                displayPhone = String(bag.display_phone_number || bag.phone_number || '');
            }
            if (!catalogId) {
                catalogId = App.firstWaCatalogId(
                    bag.catalog_id || bag.catalog_ids || bag.selected_catalog_id || bag.whatsapp_catalog_id
                );
            }
            if (!businessId) {
                businessId = String(bag.business_id || bag.business_portfolio_id || '');
            }
        });
        return {
            waba_id: wabaId,
            phone_number_id: phoneNumberId,
            display_phone_number: displayPhone,
            catalog_id: catalogId,
            business_id: businessId,
        };
    },

    _waEsBridgeBound: false,
    _waSignupInFlight: false,
    _waExchanging: false,
    _waConnectedSaved: false,
    _waSavingCatalog: false,

    clearWaSignupState() {
        try {
            sessionStorage.removeItem('wa_signup_pending_code');
            sessionStorage.removeItem('wa_signup_session');
            sessionStorage.removeItem('wa_signup_meta_finish');
        } catch (e) { /* ignore */ }
        App.clearWaOAuthPending();
    },

    readWaSignupSession() {
        try {
            const raw = sessionStorage.getItem('wa_signup_session');
            return raw ? (JSON.parse(raw) || {}) : {};
        } catch (e) {
            return {};
        }
    },

    persistWaSignupSession(sessionData, code) {
        try {
            if (code) {
                sessionStorage.setItem('wa_signup_pending_code', code);
            }
            if (sessionData && (sessionData.waba_id || sessionData.phone_number_id || sessionData.catalog_id || sessionData.business_id)) {
                sessionStorage.setItem('wa_signup_session', JSON.stringify(sessionData));
            }
        } catch (e) { /* ignore */ }
    },

    abortWaConnectWait(msg) {
        App._waSignupInFlight = false;
        App._waExchanging = false;
        App._waSharedSaveInFlight = false;
        App.clearWaSignupState();
        App.resetWaConnectButtons();
        if (msg) {
            App.toast(msg, 'error');
        }
    },

    _waSharedSaveInFlight: false,
    _waAutoSharedTried: false,

    waConnectStartUrl() {
        const btn = document.getElementById('connect-wa-primary')
            || document.querySelector('[data-wa-oauth-connect]');
        const clientId = App.getWaConnectClientId();
        return btn?.getAttribute('data-wa-oauth-url')
            || (clientId > 0
                ? `/client/whatsapp-oauth-start?client_id=${clientId}&return=${encodeURIComponent('/client/whatsapp-settings')}`
                : '');
    },

    /**
     * Meta’s green “account shared” toast is not Finish. Grab an OAuth code anyway
     * (same path as WhatsApp OAuth debug → Complete via FB SDK).
     * @returns {Promise<boolean|null>}
     */
    completeWaAfterMetaShared(clientId) {
        if (App._waConnectedSaved) {
            return Promise.resolve(true);
        }
        if (!clientId || App._waSharedSaveInFlight) {
            return Promise.resolve(null);
        }
        App._waSharedSaveInFlight = true;
        App.setWaConnectUiPhase('saving');
        fetch('/api/whatsapp/oauth-debug-log.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ step: 'save_after_shared', client_id: clientId }),
        }).catch(() => {});

        return App.finishWaEmbeddedSignup(clientId, App.readWaSignupSession(), {
            startUrl: App.waConnectStartUrl(),
            preferPopup: true,
            onSuccess: () => {
                App._waConnectedSaved = true;
                App._waSignupInFlight = false;
                App._waSharedSaveInFlight = false;
                App.redirectWaConnected();
            },
            onError: (msg) => {
                App._waSharedSaveInFlight = false;
                App.setWaConnectUiPhase('meta');
                if (msg) {
                    App.toast(msg, 'error');
                }
            },
        }).catch(() => {
            App._waSharedSaveInFlight = false;
            App.setWaConnectUiPhase('meta');
            return false;
        });
    },

    waConnectReturnUrl() {
        const btn = document.getElementById('connect-wa-primary')
            || document.querySelector('[data-wa-oauth-connect]');
        const dest = btn?.getAttribute('data-wa-return')
            || '/client/whatsapp-settings';
        return dest.indexOf('?') >= 0 ? dest + '&connected=1' : dest + '?connected=1';
    },

    /**
     * @returns {Promise<boolean|null>} true saved, false failed, null already in flight
     */
    exchangeWaOAuthCode(clientId, code, sessionData) {
        if (App._waConnectedSaved) {
            App.saveEmbeddedCatalog(clientId, sessionData);
            return Promise.resolve(true);
        }
        if (!clientId || !code) {
            return Promise.resolve(false);
        }
        if (App._waExchanging) {
            return Promise.resolve(null);
        }
        App._waExchanging = true;
        App.setWaConnectUiPhase('saving');
        const stored = App.readWaSignupSession();
        const session = Object.assign({}, stored, sessionData && typeof sessionData === 'object' ? sessionData : {});
        if (!session.catalog_id && stored.catalog_id) {
            session.catalog_id = stored.catalog_id;
        }
        if (!session.business_id && stored.business_id) {
            session.business_id = stored.business_id;
        }
        return fetch('/api/whatsapp/exchange-token.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                code,
                client_id: clientId,
                waba_id: session.waba_id || '',
                phone_number_id: session.phone_number_id || '',
                display_phone_number: session.display_phone_number || '',
                catalog_id: session.catalog_id || '',
                business_id: session.business_id || '',
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                App._waExchanging = false;
                if (data && data.success) {
                    App._waConnectedSaved = true;
                    App._waSignupInFlight = false;
                    App.clearWaSignupState();
                    return true;
                }
                throw new Error((data && data.error) || 'Connection failed');
            })
            .catch((err) => {
                App._waExchanging = false;
                return Promise.reject(err);
            });
    },

    saveEmbeddedCatalog(clientId, sessionData) {
        const stored = App.readWaSignupSession();
        const session = Object.assign({}, stored, sessionData && typeof sessionData === 'object' ? sessionData : {});
        const catalogId = session.catalog_id || '';
        const businessId = session.business_id || '';
        if (!clientId || (!catalogId && !businessId)) {
            return Promise.resolve(false);
        }
        if (App._waSavingCatalog) {
            return Promise.resolve(null);
        }
        App._waSavingCatalog = true;
        return fetch('/api/whatsapp/save-catalog-id.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                client_id: clientId,
                catalog_id: catalogId,
                business_id: businessId,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                App._waSavingCatalog = false;
                return !!(data && data.success);
            })
            .catch(() => {
                App._waSavingCatalog = false;
                return false;
            });
    },

    redirectWaConnected() {
        if (window.WaConnect && typeof window.WaConnect.redirectConnected === 'function') {
            window.WaConnect.redirectConnected();
            return;
        }
        App.toast('WhatsApp connected!', 'success');
        window.location.replace(App.waConnectReturnUrl());
    },

    saveWaSignupFromFinish(clientId, sessionData, existingCode) {
        const session = sessionData || App.readWaSignupSession();
        App.persistWaSignupSession(session, existingCode || '');
        App.markWaOAuthPending();
        App.setWaConnectUiPhase('saving');
        const afterCode = (code) => App.exchangeWaOAuthCode(clientId, code, session)
            .then((ok) => {
                if (ok) {
                    App.redirectWaConnected();
                    return true;
                }
                return false;
            })
            .catch((err) => {
                App.clearWaSignupState();
                App.resetWaConnectButtons();
                App.toast((err && err.message) || 'Could not save WhatsApp. Click Connect again and Finish in Meta.', 'error');
                return false;
            });
        if (existingCode) {
            return afterCode(existingCode);
        }
        let stored = '';
        try {
            stored = sessionStorage.getItem('wa_signup_pending_code') || '';
        } catch (e) { /* ignore */ }
        if (stored) {
            return afterCode(stored);
        }
        return App.requestWaOAuthCode()
            .then((code) => {
                App.persistWaSignupSession(session, code);
                return afterCode(code);
            })
            .catch(() => {
                App.resetWaConnectButtons();
                App.toast('Click Finish in the Meta window, keep this tab open, then wait for Connected.', 'error');
                return false;
            });
    },

    initWaEmbeddedSignupBridge() {
        if (App._waEsBridgeBound) {
            return;
        }
        App._waEsBridgeBound = true;
        const metaOrigins = [
            'https://www.facebook.com',
            'https://web.facebook.com',
            'https://business.facebook.com',
        ];
        window.addEventListener('message', (event) => {
            if (!metaOrigins.includes(event.origin)) {
                return;
            }
            let data = event.data;
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch (e) {
                    return;
                }
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }
            const clientId = App.getWaConnectClientId();
            fetch('/api/whatsapp/oauth-debug-log.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    step: 'meta_es_event',
                    client_id: clientId,
                    event: data.event || '',
                }),
            }).catch(() => {});
            if (App.isWaEmbeddedSignupReady(data.event, data.data)) {
                try {
                    sessionStorage.setItem('wa_signup_meta_finish', '1');
                } catch (e) { /* ignore */ }
                if (window.WaConnect && typeof window.WaConnect.onMetaFinish === 'function') {
                    window.WaConnect.onMetaFinish();
                }
                const sessionData = App.parseWaEmbeddedSignupSession(data);
                App.persistWaSignupSession(sessionData);
                fetch('/api/whatsapp/oauth-debug-log.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        step: 'meta_finish_bridge',
                        client_id: clientId,
                        event: data.event,
                        data: sessionData,
                        data_keys: data.data && typeof data.data === 'object' ? Object.keys(data.data) : [],
                    }),
                }).catch(() => {});
                if (clientId > 0 && (sessionData.catalog_id || sessionData.business_id)) {
                    App.saveEmbeddedCatalog(clientId, sessionData);
                }
                if (clientId > 0 && !App._waConnectedSaved && !App._waSignupInFlight) {
                    let code = '';
                    try {
                        code = sessionStorage.getItem('wa_signup_pending_code') || '';
                    } catch (e) { /* ignore */ }
                    App.saveWaSignupFromFinish(clientId, sessionData, code);
                }
            }
            if (String(data.event || '').toUpperCase() === 'CANCEL' && !App._waExchanging && !App._waConnectedSaved) {
                App._waSignupInFlight = false;
                App.resetWaConnectButtons();
            }
        });
        const stopBtn = document.getElementById('wa-stop-wait');
        if (stopBtn && stopBtn.dataset.bound !== '1') {
            stopBtn.dataset.bound = '1';
            stopBtn.addEventListener('click', (e) => {
                e.preventDefault();
                App.abortWaConnectWait();
            });
        }
        const saveBtn = document.getElementById('wa-save-connection');
        if (saveBtn && saveBtn.dataset.bound !== '1') {
            saveBtn.dataset.bound = '1';
            saveBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const clientId = App.getWaConnectClientId();
                if (clientId > 0) {
                    App.completeWaAfterMetaShared(clientId);
                }
            });
        }
    },

    /**
     * Connect page button + status line during OAuth.
     * @param {'connecting'|'meta'|'saving'|'idle'} phase
     */
    setWaConnectUiPhase(phase) {
        if (window.WaConnect && typeof window.WaConnect.setPhase === 'function') {
            window.WaConnect.setPhase(phase);
            return;
        }
        const btn = document.getElementById('connect-wa-primary')
            || document.querySelector('[data-wa-oauth-connect]');
        const statusEl = document.getElementById('wa-connect-status');
        const spin = '<span class="wa-connect-spin" aria-hidden="true"></span>';

        const stopEl = document.getElementById('wa-stop-wait');
        const saveEl = document.getElementById('wa-save-connection');
        if (phase === 'idle') {
            if (btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                btn.classList.remove('opacity-70', 'pointer-events-none', 'wa-connect-busy');
                if (btn.dataset.waOauthOriginalHtml) {
                    btn.innerHTML = btn.dataset.waOauthOriginalHtml;
                }
            }
            if (statusEl) {
                statusEl.classList.add('hidden');
            }
            if (stopEl) {
                stopEl.classList.add('hidden');
            }
            if (saveEl) {
                saveEl.classList.remove('hidden');
            }
            return;
        }
        if (stopEl) {
            stopEl.classList.remove('hidden');
        }
        if (saveEl && phase !== 'connecting') {
            saveEl.classList.remove('hidden');
        }

        const labels = {
            connecting: ['Connecting…', 'Opening Meta signup…'],
            meta: ['Waiting for Meta…', 'If Meta says the account was shared, click Save connection — that green toast is not Finish.'],
            saving: ['Saving connection…', 'Saving your WhatsApp connection…'],
        };
        const pair = labels[phase] || labels.meta;

        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.classList.add('opacity-70', 'pointer-events-none', 'wa-connect-busy');
            btn.innerHTML = `${spin}<span>${pair[0]}</span>`;
        }
        if (statusEl) {
            statusEl.classList.remove('hidden');
            statusEl.textContent = pair[1];
        }
    },

    /** Warm Meta SDK in background — never disable the Connect button. */
    initWaConnectPreload() {
        const btn = document.getElementById('connect-wa-primary')
            || document.querySelector('[data-wa-oauth-connect]');
        if (!btn || !window.metaWaSignup) {
            return;
        }

        const markReady = () => {
            btn.dataset.waSdkReady = '1';
            delete btn.dataset.waSdkFailed;
        };
        const markBlocked = () => {
            window.fbSdkFailed = true;
            btn.dataset.waSdkFailed = '1';
            delete btn.dataset.waSdkReady;
        };

        if (window.fbSdkReady && typeof FB !== 'undefined') {
            markReady();
            return;
        }
        if (window.fbSdkFailed) {
            markBlocked();
            return;
        }

        document.addEventListener('fb-sdk-ready', markReady, { once: true });
        document.addEventListener('fb-sdk-error', markBlocked, { once: true });
        App.ensureFbSdkReady(5000).then(markReady).catch(markBlocked);
    },

    /**
     * Popup window features for WhatsApp OAuth.
     * @returns {string}
     */
    waOAuthPopupFeatures() {
        const w = Math.min(720, Math.max(480, screen.width - 48));
        const h = Math.min(820, Math.max(560, screen.height - 48));
        const left = Math.max(0, Math.round((screen.width - w) / 2));
        const top = Math.max(0, Math.round((screen.height - h) / 2));
        return `width=${w},height=${h},left=${left},top=${top},scrollbars=yes,resizable=yes`;
    },

    /**
     * Pre-open OAuth popup on user click (avoids blocker after async SDK wait).
     * @returns {Window|null}
     */
    prepareWaOAuthPopup() {
        try {
            return window.open('about:blank', 'iqpigeon-wa-oauth', App.waOAuthPopupFeatures());
        } catch (e) {
            return null;
        }
    },

    /** @returns {Promise<boolean>} */
    fetchWaConnectionStatus() {
        return fetch('/api/whatsapp/connection-status.php', { credentials: 'same-origin', cache: 'no-store' })
            .then((r) => r.json())
            .then((data) => !!(data && data.connected))
            .catch(() => false);
    },

    /** Accept postMessage from callback popup (same host, www or not). */
    isWaOAuthMessageOrigin(origin) {
        if (origin === window.location.origin) {
            return true;
        }
        try {
            return new URL(origin).host === window.location.host;
        } catch (e) {
            return false;
        }
    },

    /**
     * @param {Window} popup
     * @param {{ onSuccess?: () => void, onError?: (msg: string) => void, onClose?: () => void, onMetaFinish?: () => void }} opts
     */
    attachWaOAuthPopupListeners(popup, opts = {}) {
        const messageType = 'iqpigeon-whatsapp-oauth';
        const onSuccess = opts.onSuccess || (() => window.location.reload());
        const onError = opts.onError || ((msg) => { if (msg) App.toast(msg, 'error'); });
        const onClose = opts.onClose || (() => {});
        const onMetaFinish = opts.onMetaFinish || (() => {});
        const metaOrigins = [
            'https://www.facebook.com',
            'https://web.facebook.com',
            'https://business.facebook.com',
        ];

        let done = false;
        let popupClosedHandled = false;
        let pollTimer = null;
        let connectionPoll = null;
        let popupUrlPoll = null;

        const cleanup = () => {
            window.removeEventListener('message', onMessage);
            window.removeEventListener('message', onMetaMessage);
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (connectionPoll) {
                clearInterval(connectionPoll);
                connectionPoll = null;
            }
            if (popupUrlPoll) {
                clearInterval(popupUrlPoll);
                popupUrlPoll = null;
            }
        };

        const finishSuccess = () => {
            if (done) {
                return;
            }
            done = true;
            cleanup();
            try {
                if (!popup.closed) {
                    popup.close();
                }
            } catch (e) { /* ignore */ }
            onSuccess();
        };

        const checkConnection = () => App.fetchWaConnectionStatus().then((connected) => {
            if (connected) {
                finishSuccess();
            }
            return connected;
        });

        const scheduleConnectionChecks = () => {
            checkConnection();
            [1000, 2500, 5000, 8000].forEach((ms) => {
                setTimeout(() => {
                    if (!done) {
                        checkConnection();
                    }
                }, ms);
            });
        };

        const handlePopupClosed = () => {
            if (popupClosedHandled || done) {
                return;
            }
            popupClosedHandled = true;
            scheduleConnectionChecks();
            const clientId = parseInt(String(opts.clientId || '0'), 10);
            const tryRecover = clientId > 0
                ? App.recoverWaSignupIfPending(clientId)
                : Promise.resolve(false);

            tryRecover.then((recovered) => {
                if (recovered || done) {
                    return;
                }
                checkConnection().then((connected) => {
                    if (done || connected) {
                        return;
                    }
                    [2000, 5000, 10000, 20000].forEach((ms) => {
                        setTimeout(() => {
                            if (!done) {
                                checkConnection().then((ok) => {
                                    if (ok || done) {
                                        return;
                                    }
                                    if (ms === 20000) {
                                        cleanup();
                                        onClose();
                                    }
                                });
                            }
                        }, ms);
                    });
                });
            });
        };

        const onMessage = (event) => {
            if (!App.isWaOAuthMessageOrigin(event.origin)) {
                return;
            }
            const data = event.data;
            if (!data || data.type !== messageType || done) {
                return;
            }
            done = true;
            cleanup();
            try {
                if (!popup.closed) {
                    popup.close();
                }
            } catch (e) { /* ignore */ }
            if (data.success) {
                onSuccess();
            } else {
                onError(data.error || 'Connection failed');
            }
        };

        const onMetaMessage = (event) => {
            if (!metaOrigins.includes(event.origin) || done) {
                return;
            }
            let data = event.data;
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch (e) {
                    return;
                }
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }
            if (App.isWaEmbeddedSignupFinish(data.event)) {
                if (window.WaConnect && typeof window.WaConnect.onMetaFinish === 'function') {
                    window.WaConnect.onMetaFinish();
                }
                onMetaFinish();
                fetch('/api/whatsapp/oauth-debug-log.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        step: 'meta_finish_popup',
                        client_id: opts.clientId || 0,
                        event: data.event,
                        data: data.data || {},
                    }),
                }).catch(() => {});
                const sessionData = App.parseWaEmbeddedSignupSession(data);
                if (clientId > 0) {
                    App.saveEmbeddedCatalog(clientId, sessionData);
                }
                scheduleConnectionChecks();
                const clientId = parseInt(String(opts.clientId || '0'), 10);
                const sdkOk = !window.fbSdkFailed && typeof FB !== 'undefined' && window.metaWaSignup;
                if (clientId > 0 && sdkOk) {
                    App.finishWaEmbeddedSignup(clientId, sessionData, {
                        startUrl: opts.startUrl || '',
                        onSuccess: finishSuccess,
                        onError: () => {
                            scheduleConnectionChecks();
                        },
                    });
                }
            }
        };

        window.addEventListener('message', onMessage);
        window.addEventListener('message', onMetaMessage);

        connectionPoll = setInterval(() => {
            if (!done) {
                checkConnection();
            }
        }, 2500);

        popupUrlPoll = setInterval(() => {
            if (done || popup.closed) {
                return;
            }
            try {
                const href = popup.location.href;
                if (href.indexOf('/client/whatsapp-oauth-callback') >= 0) {
                    scheduleConnectionChecks();
                }
            } catch (e) { /* cross-origin */ }
        }, 800);

        pollTimer = setInterval(() => {
            if (done) {
                return;
            }
            if (popup.closed) {
                handlePopupClosed();
            }
        }, 400);

        scheduleConnectionChecks();
    },

    /**
     * Open OAuth in a popup — main tab stays on IQ Pigeon.
     * @param {string} startUrl
     * @param {{ onSuccess?: () => void, onError?: (msg: string) => void, onClose?: () => void }} [opts]
     * @returns {boolean} false if popup was blocked
     */
    openWhatsAppOAuthPopup(startUrl, opts = {}) {
        const onError = opts.onError || ((msg) => {
            if (msg) {
                App.toast(msg, 'error');
            }
        });

        if (!startUrl) {
            onError('WhatsApp signup URL is missing.');
            return false;
        }

        let popup = window.open(startUrl, 'iqpigeon-wa-oauth', App.waOAuthPopupFeatures());

        if (!popup) {
            return false;
        }

        const navigatePopup = () => {
            try {
                if (popup.closed) {
                    return;
                }
                const href = popup.location.href || '';
                if (href === 'about:blank' || href === '' || href === 'about:blank#') {
                    popup.location.replace(startUrl);
                }
            } catch (e) { /* cross-origin once Meta loads */ }
        };

        try {
            popup.focus();
        } catch (e) { /* ignore */ }

        navigatePopup();
        setTimeout(navigatePopup, 600);
        setTimeout(navigatePopup, 2000);

        App.attachWaOAuthPopupListeners(popup, opts);
        return true;
    },

    /**
     * Server OAuth — popup on desktop (IQ Pigeon tab unchanged), redirect on mobile.
     * @param {string} startUrl
     * @param {{ isNative?: boolean, isMobile?: boolean, preferRedirect?: boolean, onSuccess?: () => void, onError?: (msg: string) => void, onClose?: () => void, existingPopup?: Window|null }} [opts]
     */
    startWaOAuthRedirect(startUrl, opts = {}) {
        if (!startUrl) {
            if (opts.onError) {
                opts.onError('WhatsApp signup URL is missing.');
            }
            return;
        }

        if (opts.isNative || opts.isMobile || opts.preferRedirect) {
            window.location.href = startUrl;
            return;
        }

        const popupUrl = startUrl + (startUrl.includes('?') ? '&' : '?') + 'popup=1';
        App.openWhatsAppOAuthPopup(popupUrl, opts);
    },

    isWaSdkFailureMessage(msg) {
        return /sdk|timeout|blocked|not loaded|not available/i.test(String(msg || ''));
    },

    /**
     * After Meta FINISH (share complete) — exchange OAuth code and save on our server.
     * @param {number} clientId
     * @param {{ waba_id?: string, phone_number_id?: string, display_phone_number?: string }} sessionData
     * @param {{ onSuccess?: () => void, onError?: (msg: string) => void, startUrl?: string }} [opts]
     * @returns {Promise<void>}
     */
    finishWaEmbeddedSignup(clientId, sessionData, opts = {}) {
        const onSuccess = opts.onSuccess || (() => window.location.reload());
        const onError = opts.onError || ((msg) => { if (msg) App.toast(msg, 'error'); });
        const startUrl = opts.startUrl || '';
        const cfg = window.metaWaSignup || {};

        const stored = App.readWaSignupSession();
        const session = Object.assign({}, stored, sessionData && typeof sessionData === 'object' ? sessionData : {});
        const exchangeCode = (code) => fetch('/api/whatsapp/exchange-token.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                code,
                client_id: clientId,
                waba_id: session.waba_id || '',
                phone_number_id: session.phone_number_id || '',
                display_phone_number: session.display_phone_number || '',
                catalog_id: session.catalog_id || '',
                business_id: session.business_id || '',
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.success) {
                    onSuccess();
                } else {
                    throw new Error(data.error || 'Connection failed');
                }
            });

        const redirectToOAuth = () => {
            if (startUrl && !opts.preferPopup) {
                window.location.href = startUrl;
                return true;
            }
            return false;
        };

        return App.fetchWaConnectionStatus().then((connected) => {
            if (connected) {
                onSuccess();
                return;
            }
            if (!cfg.appId) {
                if (!redirectToOAuth()) {
                    onError('WhatsApp signup is not configured.');
                }
                return;
            }
            return App.ensureFbSdkReady(8000).then(() => new Promise((resolve, reject) => {
                FB.login((response) => {
                    if (response.authResponse && response.authResponse.code) {
                        resolve(response.authResponse.code);
                        return;
                    }
                    reject(new Error('Meta did not return an authorization code.'));
                }, App.waFbLoginOptions(cfg.configId));
            }))
                .then(exchangeCode)
                .catch((err) => {
                    if (redirectToOAuth()) {
                        return;
                    }
                    onError(err.message || 'Could not save WhatsApp connection.');
                });
        });
    },

    /**
     * Meta Embedded Signup via FB.login — dialog overlay on this page (recommended).
     * @param {number} clientId
     * @param {{ onSuccess?: () => void, onError?: (msg: string) => void, onSdkReady?: () => void, sdkReady?: boolean }} [opts]
     */
    launchWhatsAppFbSignup(clientId, opts = {}) {
        const cfg = window.metaWaSignup || {};
        const onSuccess = opts.onSuccess || (() => window.location.reload());
        const onError = opts.onError || ((msg) => { if (msg) App.toast(msg, 'error'); });
        const onSdkReady = opts.onSdkReady || (() => {});
        const onMetaFinish = opts.onMetaFinish || (() => {});
        const startUrl = opts.startUrl || '';
        const skipEnsure = opts.sdkReady === true;

        if (App._waSignupInFlight && !App._waConnectedSaved) {
            App.setWaConnectUiPhase('meta');
            return;
        }
        App._waSignupInFlight = true;

        let sessionData = {};
        let finished = false;
        let pendingCode = null;
        let codeReceivedAt = 0;
        let metaFinishReceived = false;
        let connectionPoll = null;
        let cancelTimer = null;
        let focusRetryTimer = null;
        let codeRequestInFlight = false;
        let watchdogTimer = null;

        const showSavingStatus = () => {
            App.setWaConnectUiPhase('saving');
        };

        const retryAfterMetaReturn = () => {
            if (finished) {
                return;
            }
            if (focusRetryTimer) {
                clearTimeout(focusRetryTimer);
            }
            focusRetryTimer = setTimeout(() => {
                if (finished) {
                    return;
                }
                App.fetchWaConnectionStatus().then((connected) => {
                    if (connected) {
                        finished = true;
                        App._waSignupInFlight = false;
                        cleanup();
                        App.clearWaSignupState();
                        onSuccess();
                        return;
                    }
                    if (pendingCode) {
                        showSavingStatus();
                        maybeExchange();
                    } else if (metaFinishReceived || sessionData.waba_id || sessionData.phone_number_id) {
                        showSavingStatus();
                        ensureOAuthCodeAndExchange();
                    } else {
                        App.completeWaAfterMetaShared(clientId);
                    }
                });
            }, 350);
        };

        const onWindowFocus = () => retryAfterMetaReturn();

        const onVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                retryAfterMetaReturn();
            }
        };

        const persistSignupState = () => {
            App.persistWaSignupSession(sessionData, pendingCode);
            App.markWaOAuthPending();
        };

        const clearSignupState = () => {
            App.clearWaSignupState();
        };

        const cleanup = () => {
            window.removeEventListener('message', onMetaMessage);
            window.removeEventListener('focus', onWindowFocus);
            document.removeEventListener('visibilitychange', onVisibilityChange);
            if (connectionPoll) {
                clearInterval(connectionPoll);
                connectionPoll = null;
            }
            if (cancelTimer) {
                clearTimeout(cancelTimer);
                cancelTimer = null;
            }
            if (focusRetryTimer) {
                clearTimeout(focusRetryTimer);
                focusRetryTimer = null;
            }
            if (watchdogTimer) {
                clearTimeout(watchdogTimer);
                watchdogTimer = null;
            }
        };

        const markSaved = () => {
            finished = true;
            App._waSignupInFlight = false;
            cleanup();
            clearSignupState();
            onSuccess();
        };

        const ensureOAuthCodeAndExchange = () => {
            if (finished || codeRequestInFlight) {
                return;
            }
            if (pendingCode) {
                maybeExchange();
                return;
            }
            codeRequestInFlight = true;
            showSavingStatus();
            App.requestWaOAuthCode(cfg)
                .then((code) => {
                    codeRequestInFlight = false;
                    if (finished) {
                        return;
                    }
                    pendingCode = code;
                    codeReceivedAt = Date.now();
                    persistSignupState();
                    fetch('/api/whatsapp/oauth-debug-log.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ step: 'fb_login_code_retry', client_id: clientId }),
                    }).catch(() => {});
                    exchangeCode(code);
                })
                .catch(() => {
                    codeRequestInFlight = false;
                    if (finished) {
                        return;
                    }
                    App.finishWaEmbeddedSignup(clientId, sessionData, {
                        startUrl,
                        onSuccess: markSaved,
                        onError: () => {
                            App.recoverWaSignupIfPending(clientId);
                        },
                    });
                });
        };

        const exchangeCode = (code) => {
            if (finished || !code) {
                return;
            }
            showSavingStatus();
            App.exchangeWaOAuthCode(clientId, code, sessionData)
                .then((ok) => {
                    if (ok) {
                        markSaved();
                    }
                })
                .catch((err) => {
                    App._waSignupInFlight = false;
                    App.resetWaConnectButtons();
                    onError((err && err.message) || 'Connection failed');
                });
        };

        const maybeExchange = () => {
            if (!pendingCode || finished) {
                return;
            }
            const stored = App.readWaSignupSession();
            if (stored.catalog_id || stored.business_id) {
                sessionData = Object.assign({}, sessionData, stored);
            }
            const hasAssets = sessionData.waba_id || sessionData.phone_number_id;
            const waitedMs = Date.now() - codeReceivedAt;
            const waitEnough = waitedMs >= 400;
            const waitedForCatalog = waitedMs >= 2000;
            if (!hasAssets && !waitEnough) {
                return;
            }
            if (!sessionData.catalog_id && !metaFinishReceived && !waitedForCatalog) {
                return;
            }
            exchangeCode(pendingCode);
        };

        const onMetaMessage = (event) => {
            if (!['https://www.facebook.com', 'https://web.facebook.com', 'https://business.facebook.com'].includes(event.origin)) {
                return;
            }
            let data = event.data;
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch (e) {
                    return;
                }
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }
            if (App.isWaEmbeddedSignupReady(data.event, data.data)) {
                metaFinishReceived = true;
                try {
                    sessionStorage.setItem('wa_signup_meta_finish', '1');
                } catch (e) { /* ignore */ }
                if (window.WaConnect && typeof window.WaConnect.onMetaFinish === 'function') {
                    window.WaConnect.onMetaFinish();
                }
                onMetaFinish();
                sessionData = App.parseWaEmbeddedSignupSession(data);
                fetch('/api/whatsapp/oauth-debug-log.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        step: 'meta_finish_sdk',
                        client_id: clientId,
                        event: data.event,
                        data: sessionData,
                        data_keys: data.data && typeof data.data === 'object' ? Object.keys(data.data) : [],
                    }),
                }).catch(() => {});
                persistSignupState();
                if (sessionData.catalog_id || sessionData.business_id) {
                    App.saveEmbeddedCatalog(clientId, sessionData);
                }
                persistSignupState();
                showSavingStatus();
                maybeExchange();
                if (!pendingCode) {
                    ensureOAuthCodeAndExchange();
                }
            }
        };

        window.addEventListener('message', onMetaMessage);
        window.addEventListener('focus', onWindowFocus);
        document.addEventListener('visibilitychange', onVisibilityChange);

        connectionPoll = setInterval(() => {
            if (finished) {
                clearInterval(connectionPoll);
                connectionPoll = null;
                return;
            }
            App.fetchWaConnectionStatus().then((connected) => {
                if (connected) {
                    markSaved();
                }
            });
        }, 1500);

        watchdogTimer = setTimeout(() => {
            if (finished || App._waConnectedSaved || App._waSharedSaveInFlight) {
                return;
            }
            App.completeWaAfterMetaShared(clientId);
        }, 20000);

        const startLogin = () => {
            if (window.WaConnect && typeof window.WaConnect.onMetaOpen === 'function') {
                window.WaConnect.onMetaOpen();
            } else {
                App.setWaConnectUiPhase('meta');
            }
            onSdkReady();
            setTimeout(() => {
                if (finished || App._waConnectedSaved) {
                    return;
                }
                App.completeWaAfterMetaShared(clientId);
            }, 7000);
            FB.login((response) => {
                if (finished) {
                    return;
                }

                if (response.authResponse && response.authResponse.code) {
                    pendingCode = response.authResponse.code;
                    codeReceivedAt = Date.now();
                    persistSignupState();
                    fetch('/api/whatsapp/oauth-debug-log.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ step: 'fb_login_code', client_id: clientId }),
                    }).catch(() => {});
                    setTimeout(maybeExchange, 400);
                    setTimeout(maybeExchange, 2000);
                    setTimeout(maybeExchange, 5000);
                    return;
                }

                // FB.login often fires once with unknown/connected before the popup finishes — do NOT abort.
            }, App.waFbLoginOptions(cfg.configId));
        };

        if (skipEnsure && typeof FB !== 'undefined' && window.fbSdkReady) {
            startLogin();
            return;
        }

        App.ensureFbSdkReady()
            .then(startLogin)
            .catch((err) => {
                App._waSignupInFlight = false;
                cleanup();
                onError(err.message || 'Facebook SDK not loaded. Refresh the page and try again.');
            });
    },

    bindWhatsAppOAuthConnectButtons() {
        document.querySelectorAll('[data-wa-oauth-connect]').forEach((btn) => {
            if (btn.dataset.waOauthBound === '1') {
                return;
            }
            btn.dataset.waOauthBound = '1';

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (App._waSignupInFlight && !App._waConnectedSaved) {
                    App.setWaConnectUiPhase('meta');
                    return;
                }
                const clientId = parseInt(btn.getAttribute('data-wa-client-id') || '0', 10);
                let startUrl = btn.getAttribute('data-wa-oauth-url') || '';
                const returnPath = btn.getAttribute('data-wa-return') || (window.location.pathname + window.location.search);
                const useFbSdk = clientId > 0 && window.metaWaSignup && window.metaWaSignup.appId;
                const isNative = App.isNativeApp();
                const isMobile = App.isMobileClient() || btn.getAttribute('data-wa-mobile') === '1';

                if (!startUrl && clientId > 0) {
                    startUrl = `/client/whatsapp-oauth-start?client_id=${clientId}&return=${encodeURIComponent(returnPath)}`;
                }

                if (!useFbSdk && !startUrl) {
                    return;
                }

                const sdkBlocked = window.fbSdkFailed || btn.dataset.waSdkFailed === '1';
                const useSdkPath = !isNative && !isMobile && useFbSdk && !sdkBlocked
                    && btn.dataset.waSdkReady === '1'
                    && window.fbSdkReady === true
                    && typeof FB !== 'undefined';

                const originalHtml = btn.innerHTML;
                btn.dataset.waOauthOriginalHtml = originalHtml;
                const statusEl = document.getElementById('wa-connect-status');

                App.setWaConnectUiPhase('connecting');
                App.markWaOAuthPending();

                fetch('/api/whatsapp/oauth-debug-log.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        step: 'connect_click',
                        client_id: clientId,
                        fbSdkReady: !!window.fbSdkReady,
                        fbSdkFailed: !!window.fbSdkFailed,
                        sdkBlocked,
                        useSdkPath,
                        startUrl,
                        flow: useSdkPath ? 'sdk' : 'popup',
                    }),
                }).catch(() => {});

                const resetButton = () => {
                    App.clearWaOAuthPending();
                    App.setWaConnectUiPhase('idle');
                };

                const onSuccess = () => {
                    App.clearWaOAuthPending();
                    if (window.WaConnect && typeof window.WaConnect.redirectConnected === 'function') {
                        window.WaConnect.redirectConnected();
                        return;
                    }
                    App.toast('WhatsApp connected!', 'success');
                    const dest = returnPath || '/client/dashboard?welcome=1';
                    const url = dest.indexOf('?') >= 0
                        ? dest + '&connected=1'
                        : dest + '?connected=1';
                    window.location.replace(url);
                };

                const oauthOpts = {
                    clientId,
                    startUrl,
                    onSuccess,
                    onMetaFinish: () => {
                        if (window.WaConnect && typeof window.WaConnect.onMetaFinish === 'function') {
                            window.WaConnect.onMetaFinish();
                        }
                        App.setWaConnectUiPhase('saving');
                    },
                    onError: (msg) => {
                        App._waSignupInFlight = false;
                        resetButton();
                        if (msg) {
                            App.toast(msg, 'error');
                        }
                    },
                    onClose: () => {
                        App.fetchWaConnectionStatus().then((connected) => {
                            if (connected) {
                                onSuccess();
                                return;
                            }
                            App.setWaConnectUiPhase('saving');
                            App.recoverWaSignupIfPending(clientId).then((recovered) => {
                                if (recovered) {
                                    return;
                                }
                                App.setWaConnectUiPhase('meta');
                            });
                        });
                    },
                };

                const openMetaPopup = () => {
                    App.setWaConnectUiPhase('meta');
                    const popupUrl = startUrl + (startUrl.includes('?') ? '&' : '?') + 'popup=1';
                    const ok = App.openWhatsAppOAuthPopup(popupUrl, oauthOpts);
                    if (!ok) {
                        resetButton();
                        App.toast('Popup blocked. Allow popups for iqpigeon.com and click Connect again.', 'error');
                    }
                };

                const runFbLogin = () => {
                    if (window.WaConnect && typeof window.WaConnect.onMetaOpen === 'function') {
                        window.WaConnect.onMetaOpen();
                    } else {
                        App.setWaConnectUiPhase('meta');
                    }
                    App.launchWhatsAppFbSignup(clientId, {
                        sdkReady: true,
                        startUrl,
                        onMetaFinish: oauthOpts.onMetaFinish,
                        onSuccess,
                        onError: (msg) => {
                            App._waSignupInFlight = false;
                            resetButton();
                            if (msg) {
                                App.toast(msg, 'error');
                            }
                        },
                    });
                };

                const redirectToMeta = () => {
                    if (startUrl) {
                        window.location.href = startUrl;
                        return;
                    }
                    resetButton();
                    App.toast('WhatsApp signup URL is missing.', 'error');
                };

                if (isNative || isMobile) {
                    redirectToMeta();
                    return;
                }

                if (useSdkPath) {
                    runFbLogin();
                    return;
                }

                openMetaPopup();
            });
        });
    },

    isMobileClient() {
        const ua = navigator.userAgent || '';
        const uaMatch = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(ua);
        const narrow = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        return uaMatch || narrow;
    },

    isNativeApp() {
        try {
            return typeof window.AndroidBridge !== 'undefined'
                && typeof window.AndroidBridge.isNativeApp === 'function'
                && window.AndroidBridge.isNativeApp();
        } catch (e) {
            return false;
        }
    },

    markWaOAuthPending() {
        try {
            sessionStorage.setItem('wa_oauth_pending', String(Date.now()));
        } catch (e) { /* ignore */ }
    },

    clearWaOAuthPending() {
        try {
            sessionStorage.removeItem('wa_oauth_pending');
        } catch (e) { /* ignore */ }
    },

    resetWaConnectButtons() {
        App.setWaConnectUiPhase('idle');
    },

    _waConnectionPollId: null,

    startWaConnectionPoll(onConnected) {
        if (App._waConnectionPollId) {
            return;
        }
        App._waConnectionPollId = setInterval(() => {
            let pending = null;
            try {
                pending = sessionStorage.getItem('wa_oauth_pending');
            } catch (e) {
                App.stopWaConnectionPoll();
                return;
            }
            if (!pending) {
                App.stopWaConnectionPoll();
                return;
            }
            const age = Date.now() - parseInt(pending, 10);
            if (Number.isNaN(age) || age > 30 * 60 * 1000) {
                App.clearWaOAuthPending();
                App.stopWaConnectionPoll();
                App.resetWaConnectButtons();
                return;
            }
            App.fetchWaConnectionStatus().then((connected) => {
                if (connected && onConnected) {
                    App.stopWaConnectionPoll();
                    onConnected();
                }
            });
        }, 2500);
    },

    stopWaConnectionPoll() {
        if (App._waConnectionPollId) {
            clearInterval(App._waConnectionPollId);
            App._waConnectionPollId = null;
        }
    },

    /** Retry saving WhatsApp after Meta popup closes (code/session in sessionStorage). */
    recoverWaSignupIfPending(clientId) {
        if (!clientId) {
            return Promise.resolve(false);
        }
        let code = null;
        let sessionData = {};
        try {
            code = sessionStorage.getItem('wa_signup_pending_code');
            const raw = sessionStorage.getItem('wa_signup_session');
            if (raw) {
                sessionData = JSON.parse(raw) || {};
            }
        } catch (e) {
            return Promise.resolve(false);
        }

        if (code) {
            return App.exchangeWaOAuthCode(clientId, code, sessionData)
                .then((ok) => {
                    if (ok) {
                        App.redirectWaConnected();
                        return true;
                    }
                    return false;
                })
                .catch(() => {
                    App.clearWaSignupState();
                    App.resetWaConnectButtons();
                    return false;
                });
        }

        const hasSession = !!(sessionData.waba_id || sessionData.phone_number_id);
        if (hasSession) {
            return App.requestWaOAuthCode()
                .then((freshCode) => App.exchangeWaOAuthCode(clientId, freshCode, sessionData).then((ok) => {
                    if (ok) {
                        App.redirectWaConnected();
                        return true;
                    }
                    return false;
                }))
                .catch(() => App.fetchWaConnectionStatus().then((connected) => {
                    if (connected) {
                        App.clearWaSignupState();
                        App.redirectWaConnected();
                        return true;
                    }
                    return false;
                }));
        }

        return App.fetchWaConnectionStatus().then((connected) => {
            if (connected) {
                App.clearWaSignupState();
                App.redirectWaConnected();
                return true;
            }
            return false;
        });
    },

    checkWaOAuthPending() {
        let pending = null;
        try {
            pending = sessionStorage.getItem('wa_oauth_pending');
        } catch (e) {
            return;
        }
        if (!pending) {
            App.stopWaConnectionPoll();
            return;
        }

        const age = Date.now() - parseInt(pending, 10);
        if (Number.isNaN(age) || age > 30 * 60 * 1000) {
            App.clearWaSignupState();
            App.resetWaConnectButtons();
            App.stopWaConnectionPoll();
            return;
        }

        const clientId = App.getWaConnectClientId();
        let hasSignupState = false;
        try {
            hasSignupState = !!(sessionStorage.getItem('wa_signup_pending_code')
                || sessionStorage.getItem('wa_signup_session')
                || sessionStorage.getItem('wa_signup_meta_finish'));
        } catch (e) { /* ignore */ }

        if (!hasSignupState) {
            if (!App._waSignupInFlight) {
                App.clearWaOAuthPending();
            }
            App.resetWaConnectButtons();
            App.stopWaConnectionPoll();
            return;
        }

        App.setWaConnectUiPhase('saving');

        const redirectIfConnected = () => {
            App.fetchWaConnectionStatus().then((connected) => {
                if (connected) {
                    App.clearWaSignupState();
                    App.stopWaConnectionPoll();
                    App.redirectWaConnected();
                }
            });
        };

        if (clientId > 0) {
            App.recoverWaSignupIfPending(clientId).then((recovered) => {
                if (!recovered && !App._waSignupInFlight) {
                    App.resetWaConnectButtons();
                }
            });
        } else {
            redirectIfConnected();
        }
    },
};

function formatRelativeTime(iso) {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const diff = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (diff < 60) return 'Just now';
    const mins = Math.floor(diff / 60);
    if (diff < 3600) return mins === 1 ? '1 minute ago' : `${mins} minutes ago`;
    const hours = Math.floor(diff / 3600);
    if (diff < 86400) return hours === 1 ? '1 hour ago' : `${hours} hours ago`;
    const days = Math.floor(diff / 86400);
    if (diff < 604800) return days === 1 ? '1 day ago' : `${days} days ago`;
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatLocalTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function formatLocalDayLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const now = new Date();
    const startOf = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const dDay = startOf(d).getTime();
    const today = startOf(now).getTime();
    const oneDay = 86400000;
    if (dDay === today) return 'Today';
    if (dDay === today - oneDay) return 'Yesterday';
    const opts = { month: 'short', day: 'numeric' };
    if (d.getFullYear() !== now.getFullYear()) {
        opts.year = 'numeric';
    }
    return d.toLocaleDateString(undefined, opts);
}

function refreshRelativeTimes() {
    document.querySelectorAll('[data-relative-time]').forEach((el) => {
        const iso = el.getAttribute('data-relative-time');
        if (!iso) return;
        el.textContent = formatRelativeTime(iso);
    });
}

function applyLocalTimes() {
    document.querySelectorAll('.js-local-time').forEach((el) => {
        const iso = el.getAttribute('data-iso') || el.getAttribute('datetime');
        if (!iso) return;
        const formatted = formatLocalTime(iso);
        if (formatted) el.textContent = formatted;
    });
    document.querySelectorAll('.js-local-day').forEach((el) => {
        const iso = el.getAttribute('data-iso');
        if (!iso) return;
        const formatted = formatLocalDayLabel(iso);
        if (formatted) el.textContent = formatted;
    });
}

document.addEventListener('click', (e) => {
    if (e.target.closest('#admin-sidebar-toggle')) {
        e.preventDefault();
        App.toggleAdminSidebar();
        return;
    }
    if (e.target.closest('#admin-sidebar-overlay')) {
        App.closeAdminSidebar();
        return;
    }
    if (e.target.closest('#admin-sidebar a')) {
        App.closeAdminSidebar();
        return;
    }
    if (e.target.closest('[data-mobile-menu-open]')) {
        e.preventDefault();
        App.openMobileMenu();
        return;
    }
    if (e.target.closest('[data-mobile-menu-close]')) {
        App.closeMobileMenu();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('success') === '1') {
        App.toast('Success!', 'success');
    }
    if (params.get('error')) {
        App.toast(decodeURIComponent(params.get('error')), 'error');
    }

    // Clear stuck modal scroll lock from a previous interaction
    const modalOpen = document.getElementById('app-confirm-modal')
        || document.querySelector('.bottom-sheet.open');
    if (!modalOpen && document.body.style.overflow === 'hidden') {
        document.body.style.overflow = '';
    }

    // Mobile bottom nav + menu drawer: move to body root (fixes overflow clipping on iOS)
    if (document.body.classList.contains('client-app')) {
        const mobileNav = document.getElementById('client-mobile-nav');
        if (mobileNav && mobileNav.parentElement !== document.body) {
            document.body.appendChild(mobileNav);
        }
        const menuOverlay = document.getElementById('client-mobile-menu-overlay');
        const menuDrawer = document.getElementById('client-mobile-menu');
        if (menuOverlay && menuOverlay.parentElement !== document.body) {
            document.body.appendChild(menuOverlay);
        }
        if (menuDrawer && menuDrawer.parentElement !== document.body) {
            document.body.appendChild(menuDrawer);
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.getElementById('client-mobile-menu')?.classList.contains('open')) {
            App.closeMobileMenu();
        }
        if (e.key === 'Escape' && document.getElementById('admin-sidebar')?.classList.contains('is-open')) {
            App.closeAdminSidebar();
        }
    });

    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const wrap = btn.closest('.password-field, .auth-input-group--password, .auth-input-group');
            const input = wrap?.querySelector('[data-password-input]');
            const icon = btn.querySelector('[data-password-icon]');
            if (!input || !icon) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.textContent = show ? 'visibility_off' : 'visibility';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    refreshRelativeTimes();
    applyLocalTimes();
    setInterval(refreshRelativeTimes, 30000);

    App.bindWhatsAppOAuthConnectButtons();
    App.initWaEmbeddedSignupBridge();
    App.checkWaOAuthPending();
    App.initWaConnectPreload();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            App.checkWaOAuthPending();
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            App.resetWaConnectButtons();
        }
        App.checkWaOAuthPending();
    });

    /* Leads live refresh handled by bot-sync.js version polling */

    if (document.body.classList.contains('admin-app')) {
        // Remove accidental plain-text key lines pasted into old PHP partials (OPcache stale copies).
        [...document.body.childNodes].forEach((node) => {
            if (node.nodeType !== Node.TEXT_NODE) {
                return;
            }
            const text = node.textContent.trim();
            if (text === '' || (text.length >= 32 && /^[A-Za-z0-9]+$/.test(text))) {
                node.remove();
            }
        });

        const adminOverlay = document.getElementById('admin-sidebar-overlay');
        const adminSidebar = document.getElementById('admin-sidebar');
        const adminToolbar = document.querySelector('.admin-toolbar');
        if (adminOverlay && adminOverlay.parentElement !== document.body) {
            document.body.appendChild(adminOverlay);
        }
        if (adminSidebar && adminSidebar.parentElement !== document.body) {
            document.body.appendChild(adminSidebar);
        }
        if (adminToolbar && adminToolbar.parentElement !== document.body) {
            document.body.appendChild(adminToolbar);
        }
    }
});
