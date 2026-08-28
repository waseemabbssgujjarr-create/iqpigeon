/**
 * Admin WhatsApp clients — log modal and revoke.
 */
const AdminWhatsApp = {
    init() {
        const overlay = document.getElementById('wa-log-overlay');
        const closeBtn = document.getElementById('wa-log-close-btn');
        const modal = document.getElementById('wa-log-modal');

        overlay?.addEventListener('click', () => this.closeModal());
        closeBtn?.addEventListener('click', () => this.closeModal());

        modal?.addEventListener('click', (e) => e.stopPropagation());

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.closeModal();
        });
    },

    openModal() {
        const overlay = document.getElementById('wa-log-overlay');
        const modal = document.getElementById('wa-log-modal');
        overlay?.classList.remove('hidden');
        modal?.classList.remove('hidden');
        overlay?.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    },

    async viewLog(clientId, clientName) {
        const title = document.getElementById('wa-log-modal-title');
        const body = document.getElementById('wa-log-modal-body');

        if (title) title.textContent = 'Log: ' + clientName;
        if (body) body.innerHTML = '<p class="text-body-md text-on-surface-variant">Loading…</p>';

        this.openModal();

        try {
            const res = await fetch('/api/admin/whatsapp-log.php?client_id=' + clientId);
            const data = await res.json();

            if (!data.success || !body) return;

            if (!data.messages.length) {
                body.innerHTML = '<p class="text-body-md text-on-surface-variant">No messages.</p>';
                return;
            }

            body.innerHTML = `<table class="w-full text-body-md">
                <thead><tr class="text-label-sm font-label uppercase text-outline border-b">
                    <th class="py-sm text-left">Dir</th><th class="py-sm text-left">From/To</th>
                    <th class="py-sm text-left">Message</th><th class="py-sm text-left">Status</th><th class="py-sm text-left">Time</th>
                </tr></thead>
                <tbody>${data.messages.map(m => `
                    <tr class="border-b border-outline-variant">
                        <td class="py-sm">${m.direction === 'inbound' ? '↓' : '↑'}</td>
                        <td class="py-sm font-label text-label-sm">${m.direction === 'inbound' ? (m.from || '') : (m.to || '')}</td>
                        <td class="py-sm text-on-surface-variant">${this.esc(m.preview)}</td>
                        <td class="py-sm">${(m.status || '').toUpperCase()}</td>
                        <td class="py-sm text-outline text-label-sm">${this.esc(m.created_at)}</td>
                    </tr>`).join('')}</tbody></table>`;
        } catch (e) {
            if (body) body.innerHTML = '<p class="text-error">Failed to load log.</p>';
        }
    },

    closeModal() {
        const overlay = document.getElementById('wa-log-overlay');
        const modal = document.getElementById('wa-log-modal');
        overlay?.classList.add('hidden');
        modal?.classList.add('hidden');
        overlay?.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    },

    revoke(clientId, name) {
        const csrf = document.body.dataset.csrf || '';
        App.confirm('Revoke WhatsApp for ' + name + '? This only disconnects from the app.', async () => {
            const res = await fetch('/api/admin/whatsapp-revoke.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ client_id: clientId, csrf_token: csrf }),
            });
            const data = await res.json();
            if (data.success) {
                App.toast('Connection revoked', 'success');
                setTimeout(() => location.reload(), 600);
            } else {
                App.toast(data.error || 'Revoke failed', 'error');
            }
        });
    },

    esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    },
};

document.addEventListener('DOMContentLoaded', () => AdminWhatsApp.init());
