/**
 * Bot setup — company info, website fetch, channel verification.
 */

const BotSetup = {
    botId: null,
    csrfToken: '',

    init(config) {
        this.botId = config.botId;
        this.csrfToken = config.csrfToken;
        this.bindTabs();
        this.bindKnowledgeUpload();
        this.bindFetchEverything();
        this.bindChannelVerify();
        this.bindWidgetPreview();
        this.bindWhatsAppTokenToggle();
    },

    bindTabs() {
        const root = document.getElementById('bot-setup-root');
        if (!root) return;

        const activate = (tab) => {
            root.querySelectorAll('[data-tab]').forEach(b => {
                b.classList.toggle('is-active', b.dataset.tab === tab);
            });
            root.querySelectorAll('[data-panel]').forEach(p => {
                p.classList.toggle('hidden', p.dataset.panel !== tab);
            });
            const params = new URLSearchParams(window.location.search);
            params.set('tab', tab);
            window.history.replaceState(null, '', '?' + params.toString());
        };

        root.querySelectorAll('[data-tab]').forEach(btn => {
            btn.addEventListener('click', () => activate(btn.dataset.tab));
        });

        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        if (tab && root.querySelector(`[data-tab="${tab}"]`)) {
            activate(tab);
        }
    },

    bindKnowledgeUpload() {
        const fileInput = document.getElementById('knowledge-file');
        const fileLabel = document.getElementById('knowledge-file-label');
        const textarea = document.getElementById('bot-knowledge');

        if (!fileInput || !textarea) return;

        fileInput.addEventListener('change', async () => {
            const file = fileInput.files?.[0];
            if (fileLabel) {
                fileLabel.textContent = file ? file.name : 'PDF, Word (.docx), or TXT — max 10 MB';
            }
            if (!file) return;

            try {
                const form = new FormData();
                form.append('bot_id', String(this.botId));
                form.append('document', file);
                form.append('csrf_token', this.csrfToken);
                const res = await fetch('/api/bot-knowledge-upload.php', { method: 'POST', body: form });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Could not read file.');
                textarea.value = data.text || '';
                App.toast('Document loaded into knowledge — click Save Changes.', 'success');
                fileInput.value = '';
                if (fileLabel) fileLabel.textContent = 'PDF, Word (.docx), or TXT — max 10 MB';
            } catch (err) {
                App.toast(err?.message || 'Upload failed.', 'error');
            }
        });
    },

    bindFetchEverything() {
        const btn = document.getElementById('fetch-everything');
        const urlInput = document.getElementById('website-url');
        const status = document.getElementById('fetch-status');
        const textarea = document.getElementById('bot-knowledge');

        if (!btn || !urlInput) return;

        btn.addEventListener('click', async () => {
            const url = urlInput.value.trim();
            if (!url) {
                App.toast('Enter your website URL first.', 'error');
                urlInput.focus();
                return;
            }

            btn.disabled = true;
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Fetching...';
            this.setStatus(status, 'Reading website and importing catalog…', false);

            try {
                const res = await fetch('/api/fetch-business.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    body: JSON.stringify({ bot_id: this.botId, url, csrf_token: this.csrfToken }),
                });
                let data;
                const raw = await res.text();
                try {
                    data = JSON.parse(raw);
                } catch {
                    throw new Error(raw && raw.length < 200 ? raw : `Server error (HTTP ${res.status}). Re-upload includes/catalog.php.`);
                }

                if (data.success) {
                    let msg = data.message || 'Website fetched!';
                    if (data.import_note) msg += ' ' + data.import_note;
                    if (typeof BotSync !== 'undefined') {
                        BotSync.publish('bot:updated', {
                            bot_id: this.botId,
                            context: data.context || null,
                        });
                        if (data.context && data.context.version) {
                            BotSync.lastVersion = data.context.version;
                        }
                    }
                    App.toast(msg, 'success');
                    window.location.href = `/client/bot-setup.php?id=${this.botId}&tab=company&fetched=1`;
                } else {
                    const errMsg = data.error || 'Fetch failed.';
                    App.toast(errMsg, 'error');
                    this.setStatus(status, errMsg, true);
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            } catch (err) {
                const errMsg = err?.message || 'Network error — try again.';
                App.toast(errMsg, 'error');
                this.setStatus(status, errMsg, true);
                btn.disabled = false;
                btn.innerHTML = original;
            }
        });
    },

    setStatus(status, message, isError) {
        if (!status) return;
        status.classList.remove('hidden', 'bg-error-container/20', 'border-error', 'text-on-error-container', 'bg-surface-container', 'text-on-surface-variant');
        if (isError) {
            status.classList.add('bg-error-container/20', 'border', 'border-error', 'text-on-error-container');
        } else {
            status.classList.add('bg-surface-container', 'text-on-surface-variant');
        }
        status.textContent = message;
    },

    bindChannelVerify() {
        const hasSavedToken = document.getElementById('bot-setup-root')?.dataset.waHasToken === '1';

        document.querySelectorAll('[data-verify]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const channel = btn.dataset.verify;
                let payload = { channel, bot_id: this.botId, csrf_token: this.csrfToken };

                if (channel === 'whatsapp') {
                    const phoneInput = document.getElementById('whatsapp_phone_id');
                    const tokenInput = document.getElementById('whatsapp_token');
                    const editWrap = document.getElementById('wa-token-edit-wrap');
                    payload.phone_id = phoneInput?.value.trim() || '';
                    payload.token = tokenInput?.value.trim() || '';

                    if (!payload.phone_id) {
                        App.toast('Enter your Phone Number ID from Meta (field 1).', 'error');
                        phoneInput?.focus();
                        return;
                    }
                    if (!payload.token) {
                        if (hasSavedToken && editWrap && editWrap.classList.contains('hidden')) {
                            App.toast('Click Replace token and paste the new access token from Meta before Verify & Connect.', 'error');
                            document.getElementById('wa-token-change-btn')?.focus();
                            return;
                        }
                        if (!hasSavedToken) {
                            App.toast('Access token is required.', 'error');
                            tokenInput?.focus();
                            return;
                        }
                    }
                } else if (channel === 'instagram') {
                    payload.page_id = document.getElementById('instagram_page_id')?.value || '';
                    payload.token = document.getElementById('instagram_token')?.value || '';
                }

                btn.disabled = true;
                btn.textContent = 'Verifying...';

                try {
                    const res = await fetch('/api/verify-channel.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                        body: JSON.stringify(payload),
                    });
                    const data = await res.json();

                    if (data.success) {
                        App.toast(data.message, 'success');
                        if (channel === 'whatsapp') {
                            window.location.reload();
                            return;
                        }
                        const badge = document.getElementById(`${channel}-status`);
                        if (badge) {
                            badge.className = 'bg-primary-container text-on-primary-container px-sm py-0.5 rounded-full text-label-sm font-label';
                            badge.textContent = 'Connected ✓';
                        }
                    } else {
                        App.toast(data.message || 'Verification failed', 'error');
                    }
                } catch {
                    App.toast('Network error', 'error');
                }

                btn.disabled = false;
                btn.textContent = 'Verify & Connect';
            });
        });
    },

    bindWidgetPreview() {
        const colorInput = document.getElementById('widget_color');
        const colorPicker = document.getElementById('widget_color_picker');
        const colorHex = document.getElementById('widget_color_hex');
        const preview = document.getElementById('widget-preview-bubble');
        const embedPre = document.getElementById('embed-code');

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
            if (preview) {
                const fabBg = preview.querySelector('[data-widget-fab-bg]');
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
                colorHex.classList.remove('border-error');
            }
        });

        colorHex?.addEventListener('blur', () => {
            const hex = normalizeHex(colorHex.value);
            if (hex) {
                applyColor(hex);
                colorHex.classList.remove('border-error');
            } else if (colorHex.value.trim() !== '') {
                colorHex.classList.add('border-error');
                App.toast('Use a valid hex color (e.g. 4aad36 or FFFFFF)', 'error');
            }
        });

        const copyBtn = document.getElementById('copy-embed');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => {
                const code = document.getElementById('embed-code')?.textContent || '';
                navigator.clipboard.writeText(code).then(() => App.toast('Embed code copied!', 'success'));
            });
        }
    },

    bindWhatsAppTokenToggle() {
        const savedWrap = document.getElementById('wa-token-saved-wrap');
        const editWrap = document.getElementById('wa-token-edit-wrap');
        const changeBtn = document.getElementById('wa-token-change-btn');
        const cancelBtn = document.getElementById('wa-token-cancel-btn');
        const tokenInput = document.getElementById('whatsapp_token');

        if (!savedWrap || !editWrap || !changeBtn) return;

        changeBtn.addEventListener('click', () => {
            savedWrap.classList.add('hidden');
            editWrap.classList.remove('hidden');
            tokenInput?.focus();
        });

        cancelBtn?.addEventListener('click', () => {
            editWrap.classList.add('hidden');
            savedWrap.classList.remove('hidden');
            if (tokenInput) tokenInput.value = '';
        });
    },
};

function bootBotSetup() {
    const el = document.getElementById('bot-setup-root');
    if (!el) return;
    BotSetup.init({
        botId: parseInt(el.dataset.botId, 10),
        csrfToken: el.dataset.csrf || '',
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootBotSetup);
} else {
    bootBotSetup();
}
