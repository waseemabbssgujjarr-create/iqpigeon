/**
 * Live conversation polling + image upload.
 */
(function () {
    'use strict';

    const cfg = window.IQPigeonConversation;
    if (!cfg || !cfg.leadId) {
        return;
    }

    const thread = document.getElementById('conversation-thread');
    const statusDot = document.getElementById('conv-status-dot');
    const statusLabel = document.getElementById('conv-status-label');
    let lastId = cfg.lastId || 0;
    let polling = true;

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderBody(msg) {
        const kind = msg.kind || 'text';
        if (kind === 'image') {
            let html = '<div class="conv-media conv-media--image">';
            if (msg.media_url) {
                html += '<a href="' + escapeHtml(msg.media_url) + '" target="_blank" rel="noopener">'
                    + '<img src="' + escapeHtml(msg.media_url) + '" alt="Image" class="conv-media-img rounded-lg max-w-full max-h-64 object-cover" loading="lazy"/></a>';
            } else {
                html += '<div class="conv-media-placeholder flex items-center gap-sm text-body-md">'
                    + '<span class="material-symbols-outlined">image</span><span>Photo</span></div>';
            }
            if (msg.transcript) {
                html += '<p class="text-body-md mt-sm whitespace-pre-wrap">' + escapeHtml(msg.transcript) + '</p>';
            }
            return html + '</div>';
        }
        if (kind === 'voice') {
            let html = '<div class="conv-media conv-media--voice flex items-start gap-sm">'
                + '<span class="material-symbols-outlined text-primary shrink-0" style="font-variation-settings:\'FILL\' 1">mic</span><div class="min-w-0">'
                + '<p class="text-label-sm font-label uppercase tracking-wide text-on-surface-variant mb-xs">Voice message</p>';
            if (msg.media_url) {
                html += '<audio controls preload="none" class="conv-voice-player w-full max-w-xs mb-xs" src="' + escapeHtml(msg.media_url) + '"></audio>';
            }
            if (msg.transcript) {
                html += '<p class="text-body-md whitespace-pre-wrap italic">“' + escapeHtml(msg.transcript) + '”</p>';
            }
            return html + '</div></div>';
        }
        return '<p class="text-body-md whitespace-pre-wrap">' + escapeHtml(msg.message || '') + '</p>';
    }

    function renderMessage(msg) {
        if (msg.role === 'system') {
            return '';
        }
        const body = renderBody(msg);
        const time = escapeHtml(msg.time_label || '');
        const iso = escapeHtml(msg.iso_time || '');

        if (msg.role === 'assistant') {
            const rep = escapeHtml(cfg.repName || 'Team');
            return '<div class="flex gap-sm mb-md max-w-[85%] conv-msg conv-msg--assistant" data-msg-id="' + msg.id + '">'
                + '<span class="w-8 h-8 shrink-0 rounded-full bg-primary text-on-primary flex items-center justify-center">'
                + '<span class="material-symbols-outlined text-sm">person</span></span>'
                + '<div><div class="bg-surface-container-lowest rounded-xl rounded-tl-none p-md shadow-sm">' + body + '</div>'
                + '<p class="text-label-sm text-outline mt-xs font-label">' + rep + ' · '
                + '<time class="js-local-time" datetime="' + iso + '" data-iso="' + iso + '">' + time + '</time></p></div></div>';
        }

        const lead = escapeHtml(cfg.leadName || 'Lead');
        const initial = escapeHtml(cfg.leadInitial || 'L');
        return '<div class="flex gap-sm mb-md max-w-[85%] ml-auto justify-end conv-msg conv-msg--user" data-msg-id="' + msg.id + '">'
            + '<div class="text-right"><div class="bg-secondary text-on-secondary rounded-xl rounded-tr-none p-md shadow-sm">' + body + '</div>'
            + '<p class="text-label-sm text-outline mt-xs font-label">' + lead + ' · '
            + '<time class="js-local-time" datetime="' + iso + '" data-iso="' + iso + '">' + time + '</time></p></div>'
            + '<span class="w-8 h-8 shrink-0 rounded-full bg-secondary-fixed-dim text-on-secondary-container flex items-center justify-center font-label text-label-sm">' + initial + '</span></div>';
    }

    function appendMessages(messages) {
        if (!thread || !messages.length) {
            return;
        }
        const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;
        let html = '';
        messages.forEach((msg) => {
            if (document.querySelector('[data-msg-id="' + msg.id + '"]')) {
                return;
            }
            html += renderMessage(msg);
        });
        if (html) {
            thread.insertAdjacentHTML('beforeend', html);
            if (window.App && typeof App.localizeTimes === 'function') {
                App.localizeTimes(thread);
            }
            if (atBottom) {
                thread.scrollTop = thread.scrollHeight;
            }
        }
    }

    function updateStatus(paused) {
        if (statusDot) {
            statusDot.classList.toggle('bg-primary', !paused);
            statusDot.classList.toggle('bg-error', paused);
        }
        if (statusLabel) {
            statusLabel.textContent = paused
                ? 'Human takeover'
                : (cfg.repName ? cfg.repName + ' · Auto-replies on' : 'Auto-replies on');
        }
    }

    async function poll() {
        if (!polling) {
            return;
        }
        try {
            const url = '/api/conversation-poll.php?lead_id=' + encodeURIComponent(cfg.leadId)
                + '&since_id=' + encodeURIComponent(lastId);
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) {
                return;
            }
            const data = await res.json();
            if (!data.success) {
                return;
            }
            if (data.messages && data.messages.length) {
                appendMessages(data.messages);
                lastId = data.last_id || lastId;
            }
            if (typeof data.bot_paused === 'boolean') {
                updateStatus(data.bot_paused);
            }
        } catch (e) {
            /* silent retry */
        }
    }

    setInterval(poll, 3500);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            poll();
        }
    });

    const mediaBtn = document.getElementById('conv-media-btn');
    const mediaInput = document.getElementById('conv-media-input');
    if (mediaBtn && mediaInput) {
        mediaBtn.addEventListener('click', () => mediaInput.click());
        mediaInput.addEventListener('change', async () => {
            const file = mediaInput.files && mediaInput.files[0];
            if (!file) {
                return;
            }
            mediaBtn.disabled = true;
            const form = new FormData();
            form.append('csrf_token', cfg.csrf);
            form.append('lead_id', String(cfg.leadId));
            form.append('image', file);
            try {
                const res = await fetch('/api/conversation-media.php', {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || 'Could not send image.');
                } else {
                    lastId = Math.max(lastId, data.last_id || 0);
                    await poll();
                }
            } catch (e) {
                alert('Could not send image.');
            } finally {
                mediaInput.value = '';
                mediaBtn.disabled = false;
            }
        });
    }
})();
