/**
 * Client WhatsApp settings — message log refresh, send test, disconnect.
 */
const WhatsAppSettings = {
    clientId: null,
    botId: null,
    csrf: '',

    init(clientId) {
        this.clientId = clientId || this.resolveClientId();
        this.botId = this.resolveBotId();
        this.csrf = this.resolveCsrf();
        this.refreshLog();
        setInterval(() => this.refreshLog(), 30000);
        this.bindWidgetColor();
        this.bindChannelToggles();
    },

    resolveBotId() {
        const root = document.getElementById('whatsapp-settings-root');
        return parseInt(root?.dataset.botId || '0', 10) || 0;
    },

    resolveCsrf() {
        const root = document.getElementById('whatsapp-settings-root');
        return root?.dataset.csrf || document.querySelector('input[name="csrf_token"]')?.value || '';
    },

    resolveClientId() {
        const root = document.getElementById('whatsapp-settings-root');
        if (root && root.dataset.clientId) {
            return parseInt(root.dataset.clientId, 10) || 0;
        }
        const btn = document.getElementById('btn-send-test');
        if (btn && btn.dataset.clientId) {
            return parseInt(btn.dataset.clientId, 10) || 0;
        }
        return 0;
    },

    async refreshLog() {
        const tbody = document.getElementById('wa-log-body');
        const cards = document.getElementById('wa-log-cards');
        if (!tbody && !cards) return;

        try {
            const res = await fetch('/api/whatsapp/message-log.php?client_id=' + this.clientId);
            const data = await res.json();
            if (!data.success) return;

            if (data.messages.length === 0) {
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="5" class="px-md py-lg text-center text-body-md text-on-surface-variant">No messages yet.</td></tr>';
                }
                if (cards) {
                    cards.innerHTML = '<p class="px-md py-lg text-center text-body-md text-on-surface-variant">No messages yet.</p>';
                }
                return;
            }

            if (tbody) {
                tbody.innerHTML = data.messages.map(m => this.renderRow(m)).join('');
            }
            if (cards) {
                cards.innerHTML = data.messages.map(m => this.renderCard(m)).join('');
            }
        } catch (e) {
            console.error('Log refresh failed', e);
        }
    },

    renderRow(m) {
        const dirIcon = m.direction === 'inbound'
            ? '<span class="material-symbols-outlined text-primary text-lg" title="Inbound">south</span>'
            : '<span class="material-symbols-outlined text-secondary text-lg" title="Outbound">north</span>';
        const number = m.direction === 'inbound' ? (m.from || '—') : (m.to || '—');
        return `<tr class="border-b border-outline-variant hover:bg-surface-container-low/50 transition-colors">
            <td class="px-md py-sm">${dirIcon}</td>
            <td class="px-md py-sm text-body-md font-label break-words">${this.esc(number)}</td>
            <td class="px-md py-sm text-body-md text-on-surface-variant break-words">${this.esc(m.preview)}</td>
            <td class="px-md py-sm">${this.statusClass(m.status)}</td>
            <td class="px-md py-sm text-label-sm font-label text-outline whitespace-nowrap">${this.esc(m.time_ago)}</td>
        </tr>`;
    },

    renderCard(m) {
        const dirIcon = m.direction === 'inbound'
            ? '<span class="material-symbols-outlined text-primary" title="Inbound">south</span>'
            : '<span class="material-symbols-outlined text-secondary" title="Outbound">north</span>';
        const number = m.direction === 'inbound' ? (m.from || '—') : (m.to || '—');
        return `<div class="px-md py-sm">
            <div class="flex items-start gap-sm">
                <div class="shrink-0 pt-0.5">${dirIcon}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-sm">
                        <span class="text-body-md font-label truncate">${this.esc(number)}</span>
                        <span class="text-label-sm font-label text-outline shrink-0">${this.esc(m.time_ago)}</span>
                    </div>
                    <p class="text-body-md text-on-surface-variant break-words mt-xs">${this.esc(m.preview)}</p>
                    <div class="mt-xs">${this.statusClass(m.status)}</div>
                </div>
            </div>
        </div>`;
    },

    statusClass(status) {
        const map = {
            sent: 'bg-secondary-container text-on-secondary-container',
            delivered: 'bg-primary-container/30 text-on-primary-container',
            read: 'bg-primary-container text-on-primary-container',
            failed: 'bg-error-container text-on-error-container',
            received: 'bg-tertiary-container text-on-tertiary-container',
        };
        const cls = map[status] || 'bg-surface-container text-on-surface-variant';
        const label = (status || 'unknown').toUpperCase();
        return `<span class="${cls} px-sm py-0.5 rounded text-[10px] font-bold uppercase tracking-wider font-label whitespace-nowrap shrink-0 inline-block">${label}</span>`;
    },

    async disconnect() {
        App.confirm(
            'Your WhatsApp Business account stays on Meta. Only this app connection is removed.',
            async () => {
                const res = await fetch('/api/whatsapp/disconnect.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ client_id: this.clientId }),
                });
                const data = await res.json();
                if (data.success) {
                    App.toast('WhatsApp disconnected', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    App.toast(data.error || 'Disconnect failed', 'error');
                }
            },
            { title: 'Disconnect WhatsApp?', confirmLabel: 'Disconnect', danger: true }
        );
    },

    async sendTest() {
        const to = document.getElementById('test-to')?.value?.trim();
        const body = document.getElementById('test-body')?.value?.trim();
        const resultEl = document.getElementById('test-result');
        const clientId = this.clientId || this.resolveClientId();

        if (!to || !body) {
            if (resultEl) {
                resultEl.classList.remove('hidden');
                resultEl.className = 'mt-2 text-[12px] text-red-500';
                resultEl.textContent = 'Enter a phone number and message.';
            }
            return;
        }

        const btn = document.getElementById('btn-send-test');
        if (btn) btn.disabled = true;
        if (resultEl) {
            resultEl.classList.add('hidden');
            resultEl.textContent = '';
        }

        try {
            const res = await fetch('/api/whatsapp/send-test.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ client_id: clientId, to_number: to, message_body: body }),
            });
            const data = await res.json();

            if (resultEl) {
                resultEl.classList.remove('hidden');
                if (data.success) {
                    resultEl.className = 'mt-2 text-[12px] text-emerald-600';
                    resultEl.textContent = 'Message sent to ' + to
                        + (data.warning ? '. ' + data.warning : '.');
                } else {
                    resultEl.className = 'mt-2 text-[12px] text-red-500';
                    resultEl.textContent = data.error || 'Send failed. Try again.';
                }
            }
        } catch (e) {
            if (resultEl) {
                resultEl.classList.remove('hidden');
                resultEl.className = 'mt-2 text-[12px] text-red-500';
                resultEl.textContent = 'Network error. Please try again.';
            }
        } finally {
            if (btn) btn.disabled = false;
        }
    },

    esc(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    },

    bindWidgetColor() {
        const colorInput = document.getElementById('widget_color');
        const colorPicker = document.getElementById('widget_color_picker');
        const colorHex = document.getElementById('widget_color_hex');
        const previewFab = document.getElementById('widget-preview-fab');
        const embedPre = document.getElementById('iqpWidgetCode');
        const copyBtn = document.getElementById('btn-copy-widget');

        if (!colorPicker && !colorHex) return;

        const normalizeHex = (raw) => {
            let s = String(raw || '').trim().replace(/^#/, '').toLowerCase();
            if (!/^[0-9a-f]{3}$/.test(s) && !/^[0-9a-f]{6}$/.test(s)) {
                return null;
            }
            if (s.length === 3) {
                s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
            }
            return '#' + s;
        };

        const applyColor = (hex) => {
            if (!hex) return;
            if (colorInput) colorInput.value = hex;
            if (colorPicker) colorPicker.value = hex;
            if (colorHex) colorHex.value = hex.slice(1);
            if (previewFab) {
                const fabBg = previewFab.querySelector('[data-widget-fab-bg]');
                if (fabBg) fabBg.setAttribute('fill', hex);
            }
            if (embedPre) {
                embedPre.textContent = embedPre.textContent.replace(
                    /color:\s*'[^']*'/,
                    "color: '" + hex + "'"
                );
            }
        };

        colorPicker?.addEventListener('input', () => {
            applyColor(colorPicker.value);
        });

        colorHex?.addEventListener('input', () => {
            const hex = normalizeHex(colorHex.value);
            if (hex) {
                applyColor(hex);
                colorHex.classList.remove('border-red-400');
            }
        });

        colorHex?.addEventListener('blur', () => {
            const hex = normalizeHex(colorHex.value);
            if (hex) {
                applyColor(hex);
                colorHex.classList.remove('border-red-400');
            } else if (colorHex.value.trim() !== '') {
                colorHex.classList.add('border-red-400');
            }
        });

        copyBtn?.addEventListener('click', () => {
            const code = embedPre?.textContent || '';
            navigator.clipboard.writeText(code).then(() => {
                copyBtn.textContent = 'Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy Code'; }, 2000);
            });
        });
    },

    setSwitchState(btn, enabled) {
        if (!btn) return;
        btn.dataset.enabled = enabled ? '1' : '0';
        btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        btn.classList.toggle('iqp-switch--on', enabled);
        const text = btn.querySelector('.iqp-switch__text');
        if (text) text.textContent = enabled ? 'On' : 'Off';
    },

    bindChannelToggles() {
        document.querySelectorAll('[data-wa-toggle]').forEach((btn) => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => this.toggleChannel(btn));
        });
    },

    async toggleChannel(btn) {
        if (!btn || btn.disabled) return;

        const field = btn.dataset.waToggle || '';
        const botId = this.botId || this.resolveBotId();
        const csrf = this.csrf || this.resolveCsrf();
        const nextEnabled = btn.dataset.enabled !== '1';

        if (!botId) {
            this.toast('Set up your bot first.', 'error');
            return;
        }
        if (!csrf) {
            this.toast('Session expired — refresh the page.', 'error');
            return;
        }

        btn.disabled = true;
        const prevEnabled = btn.dataset.enabled === '1';
        this.setSwitchState(btn, nextEnabled);

        try {
            const body = new FormData();
            body.set('csrf_token', csrf);
            body.set('bot_id', String(botId));
            body.set('field', field);
            body.set('enabled', nextEnabled ? '1' : '0');

            const res = await fetch('/api/whatsapp/channel-toggles.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                throw new Error(data.error || 'Could not save setting.');
            }

            document.querySelectorAll('[data-wa-toggle="widget_enabled"]').forEach((el) => {
                this.setSwitchState(el, Number(data.widget_enabled) === 1);
            });
            document.querySelectorAll('[data-wa-toggle="whatsapp_auto_reply"]').forEach((el) => {
                this.setSwitchState(el, Number(data.whatsapp_auto_reply) === 1);
            });

            this.toast(data.message || 'Saved.', 'success');
        } catch (e) {
            this.setSwitchState(btn, prevEnabled);
            this.toast(e.message || 'Save failed.', 'error');
        } finally {
            btn.disabled = false;
        }
    },

    toast(message, type) {
        if (typeof App !== 'undefined' && App.toast) {
            App.toast(message, type === 'error' ? 'error' : 'success');
            return;
        }
        const el = document.getElementById('wa-toggle-status');
        if (el) {
            el.textContent = message;
            el.className = 'text-[12px] mt-2 ' + (type === 'error' ? 'text-red-500' : 'text-emerald-600');
            el.classList.remove('hidden');
            window.setTimeout(() => el.classList.add('hidden'), 4000);
        }
    },

    /**
     * @deprecated Use data-wa-oauth-connect + data-wa-client-id buttons.
     */
    connectPhase1(url) {
        const clientId = parseInt(document.getElementById('whatsapp-settings-root')?.dataset.clientId || '0', 10);
        if (clientId > 0 && window.metaWaSignup && window.metaWaSignup.appId) {
            App.launchWhatsAppFbSignup(clientId, {
                onSuccess: () => {
                    App.toast('WhatsApp connected!', 'success');
                    location.reload();
                },
            });
            return;
        }
        App.openWhatsAppOAuthPopup(url);
    },

    /**
     * Phase 2: FB SDK Embedded Signup (uncomment in PHP when Live).
     */
    launchWhatsAppSignup(appId, configId, apiVersion, clientId) {
        if (typeof FB === 'undefined') {
            App.toast('Facebook SDK not loaded', 'error');
            return;
        }
        FB.login(function (response) {
            if (response.authResponse && response.authResponse.code) {
                fetch('/api/whatsapp/exchange-token.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: response.authResponse.code, client_id: clientId }),
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            App.toast('WhatsApp connected!', 'success');
                            location.reload();
                        } else {
                            App.toast('Connection failed: ' + (data.error || ''), 'error');
                        }
                    });
            }
        }, (window.App && typeof App.waFbLoginOptions === 'function')
            ? App.waFbLoginOptions(configId)
            : {
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
            });
    },
};

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('whatsapp-settings-root');
    if (el && el.dataset.clientId) {
        WhatsAppSettings.init(parseInt(el.dataset.clientId, 10));
    }
});
