/**
 * Menu / catalog file upload (PDF, PNG, JPG) with deduplicated product import.
 */
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('menu-file-import-form');
    if (!form) return;

    const btn = document.getElementById('menu-file-import-btn');
    const statusEl = document.getElementById('menu-file-import-status');
    const fileInput = form.querySelector('[name="menu_file"]');
    const csrf = form.querySelector('[name="csrf_token"]')?.value || '';
    const botId = form.querySelector('[name="bot_id"]')?.value || '';

    let btnHtml = btn?.innerHTML || '';

    const setStatus = (text, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.classList.toggle('text-error', isError);
        statusEl.classList.toggle('text-primary', !isError && text !== '');
        statusEl.classList.toggle('text-on-surface-variant', text === '');
    };

    const setBusy = (busy) => {
        if (!btn) return;
        btn.disabled = busy;
        if (fileInput) fileInput.disabled = busy;
        if (busy) {
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="material-symbols-outlined text-lg animate-spin" aria-hidden="true">progress_activity</span><span data-btn-label>Importing…</span>';
        } else {
            btn.removeAttribute('aria-busy');
            btn.innerHTML = btnHtml;
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const file = fileInput?.files?.[0];
        if (!file) {
            setStatus('Choose a PDF or image file first.', true);
            return;
        }

        const allowed = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const extOk = ['pdf', 'png', 'jpg', 'jpeg', 'webp'].includes(ext);
        if (!extOk && !allowed.includes(file.type)) {
            setStatus('Use PDF, PNG, JPG, or WebP.', true);
            return;
        }

        if (file.size > 12 * 1024 * 1024) {
            setStatus('File must be under 12 MB.', true);
            return;
        }

        setBusy(true);
        setStatus('Reading menu and matching products…');

        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('bot_id', botId);
        body.append('menu_file', file);

        try {
            const response = await fetch('/api/catalog-file-import.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.success) {
                setStatus(data.error || 'Import failed. Try another file.', true);
                return;
            }

            setStatus(data.message || 'Menu imported.');
            if (fileInput) fileInput.value = '';

            window.setTimeout(() => {
                window.location.reload();
            }, 1200);
        } catch (err) {
            setStatus('Network error. Check your connection and try again.', true);
        } finally {
            setBusy(false);
        }
    });
});
