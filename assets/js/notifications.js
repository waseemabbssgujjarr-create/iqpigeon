/**
 * Live notification bell — polls /api/notifications.php every 30s.
 */
const LiveNotifications = {
    pollMs: 30000,
    timer: null,
    open: false,

    init() {
        const root = document.getElementById('notification-bell-root');
        if (!root) return;

        this.csrf = root.dataset.csrf || '';

        this.bellBtn = document.getElementById('notification-bell-btn');
        this.badge = document.getElementById('notification-badge');
        this.panel = document.getElementById('notification-panel');
        this.list = document.getElementById('notification-list');
        this.markAllBtn = document.getElementById('notification-mark-all');

        if (this.bellBtn) {
            this.bellBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.togglePanel();
            });
        }

        if (this.markAllBtn) {
            this.markAllBtn.addEventListener('click', () => this.markAllRead());
        }

        document.addEventListener('click', (e) => {
            if (this.open && this.panel && !this.panel.contains(e.target) && e.target !== this.bellBtn) {
                this.closePanel();
            }
        });

        this.fetchNotifications(true);
        this.timer = setInterval(() => this.fetchNotifications(false), this.pollMs);
    },

    async fetchNotifications(openPanelOnNew) {
        try {
            const res = await fetch('/api/notifications.php');
            const data = await res.json();
            if (!data.success) return;

            const prevUnread = parseInt(this.badge?.dataset.count || '0', 10);
            this.updateBadge(data.unread || 0);
            this.renderList(data.notifications || []);

            if (openPanelOnNew && data.unread > prevUnread && data.unread > 0 && typeof App !== 'undefined') {
                App.toast('You have ' + data.unread + ' new notification(s)', 'info', 4000);
            }
        } catch (e) {
            /* silent */
        }
    },

    updateBadge(count) {
        if (!this.badge) return;
        this.badge.dataset.count = String(count);
        if (count > 0) {
            this.badge.textContent = count > 99 ? '99+' : String(count);
            this.badge.classList.remove('hidden');
        } else {
            this.badge.classList.add('hidden');
        }
    },

    renderList(items) {
        if (!this.list) return;

        if (!items.length) {
            this.list.innerHTML = '<p class="p-md text-body-md text-on-surface-variant text-center">No notifications yet</p>';
            return;
        }

        this.list.innerHTML = items.map(n => {
            const unread = n.is_read ? '' : ' bg-primary-container/10';
            const link = n.link ? ` data-link="${this.esc(n.link)}"` : '';
            return `<button type="button" class="notification-item w-full text-left p-md border-b border-outline-variant hover:bg-surface-container-low transition-colors${unread}" data-id="${n.id}"${link}>
                <div class="flex gap-sm">
                    <span class="material-symbols-outlined text-primary shrink-0">${this.esc(n.icon)}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-title text-title-md truncate">${this.esc(n.title)}</p>
                        ${n.message ? `<p class="text-body-md text-on-surface-variant truncate">${this.esc(n.message)}</p>` : ''}
                        <p class="text-label-sm text-outline font-label mt-xs">${this.esc(n.time_ago)}</p>
                    </div>
                </div>
            </button>`;
        }).join('');

        this.list.querySelectorAll('.notification-item').forEach(el => {
            el.addEventListener('click', () => this.onItemClick(el));
        });
    },

    async onItemClick(el) {
        const id = parseInt(el.dataset.id, 10);
        const link = el.dataset.link;

        await fetch('/api/notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrf,
            },
            body: JSON.stringify({ action: 'mark_read', id, csrf_token: this.csrf }),
        });

        if (link) {
            window.location.href = link;
        } else {
            this.fetchNotifications(false);
        }
    },

    async markAllRead() {
        await fetch('/api/notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrf,
            },
            body: JSON.stringify({ action: 'mark_all_read', csrf_token: this.csrf }),
        });
        this.fetchNotifications(false);
    },

    togglePanel() {
        this.open = !this.open;
        this.panel?.classList.toggle('hidden', !this.open);
        if (this.open) this.fetchNotifications(false);
    },

    closePanel() {
        this.open = false;
        this.panel?.classList.add('hidden');
    },

    esc(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }
};

document.addEventListener('DOMContentLoaded', () => LiveNotifications.init());
