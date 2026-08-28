/**
 * Cross-page sync — Training, Orders, Catalog, Leads, Dashboard stay aligned.
 */
const BotSync = {
    channel: null,
    pollMs: 45000,
    pollTimer: null,
    lastVersion: '',
    debounceReload: null,

    init() {
        if (!document.body.classList.contains('client-app')) {
            return;
        }

        try {
            this.channel = new BroadcastChannel('iqpigeon-client-sync');
            this.channel.onmessage = (e) => this.handleMessage(e.data);
        } catch (_) {
            this.channel = null;
        }

        const cfg = window.__BOT_SYNC__ || {};
        const urlBot = new URLSearchParams(window.location.search).get('bot_id');
        const stored = sessionStorage.getItem('iqpigeon_active_bot_id');
        const botId = parseInt(cfg.botId || urlBot || stored || '0', 10);
        if (botId > 0) {
            this.setActiveBotId(botId, false);
        }

        document.addEventListener('bot:updated', (e) => {
            if (e.detail && e.detail.source === 'poll') {
                this.scheduleReload('bot:updated', e.detail);
            }
        });
        document.addEventListener('orders:changed', () => {});
        document.addEventListener('catalog:changed', () => {});

        this.bindBotSwitchers();
        this.startPoll();
    },

    pageKey() {
        return document.body.dataset.syncPage || '';
    },

    activeBotId() {
        return parseInt(sessionStorage.getItem('iqpigeon_active_bot_id') || '0', 10);
    },

    setActiveBotId(botId, persistUrl = true) {
        if (botId <= 0) return;
        sessionStorage.setItem('iqpigeon_active_bot_id', String(botId));
        document.body.dataset.syncBotId = String(botId);
        if (persistUrl) {
            const params = new URLSearchParams(window.location.search);
            if (params.get('bot_id') !== String(botId)) {
                params.set('bot_id', String(botId));
                const next = `${window.location.pathname}?${params.toString()}`;
                window.history.replaceState({}, '', next);
            }
        }
    },

    bindBotSwitchers() {
        document.querySelectorAll('[data-bot-switch]').forEach((sel) => {
            sel.addEventListener('change', () => {
                const id = parseInt(sel.value, 10);
                if (id > 0) {
                    this.setActiveBotId(id, false);
                } else if (sel.value === '0') {
                    sessionStorage.removeItem('iqpigeon_active_bot_id');
                }
                if (sel.getAttribute('onchange')) {
                    return;
                }
                if (id > 0) {
                    const params = new URLSearchParams(window.location.search);
                    params.set('bot_id', String(id));
                    window.location.search = params.toString();
                } else if (sel.value === '0') {
                    const params = new URLSearchParams(window.location.search);
                    params.delete('bot_id');
                    window.location.search = params.toString();
                }
            });
        });
    },

    publish(type, detail = {}) {
        const payload = { type, ts: Date.now(), source: 'local', ...detail };
        if (this.channel) {
            this.channel.postMessage(payload);
        }
        if (detail.context && detail.context.version) {
            this.lastVersion = detail.context.version;
        }
    },

    async notify(type, botId) {
        const id = botId || this.activeBotId();
        if (id <= 0) return;
        const data = await this.fetchContext(id);
        if (!data || !data.success) return;
        this.publish(type, { bot_id: id, context: data });
    },

    handleMessage(data) {
        if (!data || !data.type) return;
        this.scheduleReload(data.type, data);
    },

    scheduleReload(type, detail) {
        const page = this.pageKey();
        const myBot = this.activeBotId();
        const evtBot = parseInt(detail.bot_id || detail.context?.bot_id || '0', 10);
        if (myBot > 0 && evtBot > 0 && myBot !== evtBot) {
            return;
        }
        const reloadMap = {
            'bot:updated': ['orders', 'training', 'dashboard', 'analytics', 'catalog', 'connect', 'leads'],
            'orders:changed': ['orders', 'dashboard', 'analytics', 'leads'],
            'catalog:changed': ['catalog', 'analytics', 'dashboard', 'training'],
        };
        const targets = reloadMap[type] || [];
        if (!targets.includes(page)) {
            return;
        }
        if (document.hidden) {
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    window.location.reload();
                }
            }, { once: true });
            return;
        }
        clearTimeout(this.debounceReload);
        this.debounceReload = setTimeout(() => {
            if (typeof App !== 'undefined' && App.toast) {
                App.toast('Updating page with latest changes…', 'info');
            }
            setTimeout(() => window.location.reload(), 400);
        }, 800);
    },

    async fetchContext(botId) {
        const id = botId || this.activeBotId();
        if (id <= 0) return null;
        try {
            const res = await fetch(`/api/bot-context.php?bot_id=${id}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            return await res.json();
        } catch (_) {
            return null;
        }
    },

    startPoll() {
        const page = this.pageKey();
        if (!['orders', 'training', 'dashboard', 'analytics', 'catalog', 'leads', 'connect'].includes(page)) {
            return;
        }

        this.pollTimer = setInterval(async () => {
            if (document.hidden) return;
            const botId = this.activeBotId();
            if (botId <= 0) return;
            const data = await this.fetchContext(botId);
            if (!data || !data.success || !data.version) return;
            if (this.lastVersion === '') {
                this.lastVersion = data.version;
                return;
            }
            if (data.version !== this.lastVersion) {
                this.lastVersion = data.version;
                this.scheduleReload('bot:updated', { bot_id: botId, context: data, source: 'poll' });
            }
        }, this.pollMs);
    },
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => BotSync.init());
} else {
    BotSync.init();
}

window.BotSync = BotSync;
