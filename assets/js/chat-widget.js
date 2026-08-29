(function () {
    'use strict';

    const config = window.SalesBotConfig || {};
    const botId = config.botId;
    const isDemo = !!config.isDemo;
    const fallbackTitle = config.botName || 'Chat with us';
    const fallbackColor = config.color || '#4aad36';
    const zIndex = Number(config.zIndex) || 2147483000;
    const bottomOffset = Number(config.bottomOffset);
    const bubbleBottom = Number.isFinite(bottomOffset) ? bottomOffset : 20;
    const windowBottom = bubbleBottom + 72;

    function detectWidgetApiBase() {
        if (config.apiBase) {
            return String(config.apiBase).replace(/\/$/, '');
        }
        const current = document.currentScript;
        if (current && current.src && current.src.indexOf('/assets/js/chat-widget.js') !== -1) {
            try {
                return new URL(current.src).origin;
            } catch (e) { /* fall through */ }
        }
        const scripts = document.getElementsByTagName('script');
        for (let i = scripts.length - 1; i >= 0; i--) {
            const src = scripts[i].src || '';
            if (src.indexOf('/assets/js/chat-widget.js') !== -1) {
                try {
                    return new URL(src).origin;
                } catch (e) {
                    break;
                }
            }
        }
        return 'https://iqpigeon.com';
    }

    const apiBase = detectWidgetApiBase();

    if (!botId) return;

    const sessionKey = 'salesbot_session_' + botId;
    const sessionMetaKey = sessionKey + '_meta';
    const SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000;

    function loadOrCreateSession(storedKnowledgeVersion) {
        const now = Date.now();
        let sessionId = localStorage.getItem(sessionKey);
        let meta = null;
        try {
            meta = JSON.parse(localStorage.getItem(sessionMetaKey) || 'null');
        } catch (e) {
            meta = null;
        }

        const expired = !meta || !meta.created || (now - meta.created) > SESSION_TTL_MS;
        const knowledgeChanged = storedKnowledgeVersion
            && meta
            && meta.knowledge_version
            && meta.knowledge_version !== storedKnowledgeVersion;

        if (!sessionId || expired || knowledgeChanged) {
            sessionId = 'sess_' + now + '_' + Math.random().toString(36).slice(2);
            localStorage.setItem(sessionKey, sessionId);
            localStorage.setItem(sessionMetaKey, JSON.stringify({
                created: now,
                knowledge_version: storedKnowledgeVersion || '',
            }));
        } else if (storedKnowledgeVersion && meta.knowledge_version !== storedKnowledgeVersion) {
            meta.knowledge_version = storedKnowledgeVersion;
            localStorage.setItem(sessionMetaKey, JSON.stringify(meta));
        }

        return sessionId;
    }

    function touchSessionMeta(knowledgeVersion) {
        const now = Date.now();
        localStorage.setItem(sessionMetaKey, JSON.stringify({
            created: now,
            knowledge_version: knowledgeVersion || '',
        }));
    }

    async function fetchWidgetConfig(id, base, sessionId) {
        try {
            let url = base.replace(/\/$/, '') + '/api/chat-widget.php?bot_id=' + encodeURIComponent(id);
            if (sessionId) {
                url += '&session_id=' + encodeURIComponent(sessionId);
            }
            const res = await fetch(url, {
                method: 'GET',
                headers: { Accept: 'application/json' },
            });
            const raw = await res.text();
            let data;
            try {
                data = raw ? JSON.parse(raw) : {};
            } catch {
                return { error: 'Could not reach chat service (HTTP ' + res.status + ').' };
            }
            if (!data || !data.success) {
                return { error: data.error || 'Widget is not enabled for this bot.' };
            }
            return {
                botName: data.botName || data.bot_name || '',
                widget_color: data.widget_color || data.widgetColor || '',
                knowledge_version: data.knowledge_version || '',
                messages: Array.isArray(data.messages) ? data.messages : [],
                ready: true,
            };
        } catch (e) {
            return { error: 'Connection error loading widget settings.' };
        }
    }

    function titleInitials(title) {
        return String(title).split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase() || 'AI';
    }

    (async function initWidget() {
        const bootstrapCfg = await fetchWidgetConfig(botId, apiBase, '');
        let sessionId = loadOrCreateSession(bootstrapCfg && bootstrapCfg.knowledge_version);
        let serverCfg = await fetchWidgetConfig(botId, apiBase, sessionId);

        if (bootstrapCfg && bootstrapCfg.knowledge_version && serverCfg && serverCfg.ready) {
            touchSessionMeta(serverCfg.knowledge_version);
        }

        const widgetReady = !!(serverCfg && serverCfg.ready);
        const botTitle = (serverCfg && serverCfg.botName) || fallbackTitle;
        const color = (serverCfg && serverCfg.widget_color) || fallbackColor;

        const browserLocale = (navigator.language || navigator.userLanguage || '').trim();

        let hasConversation = false;
        let isSending = false;

        function hexToRgba(hex, alpha) {
            const h = hex.replace('#', '');
            const r = parseInt(h.substring(0, 2), 16) || 0;
            const g = parseInt(h.substring(2, 4), 16) || 0;
            const b = parseInt(h.substring(4, 6), 16) || 0;
            return `rgba(${r},${g},${b},${alpha})`;
        }

        function adjustColor(hex, amount) {
            const h = hex.replace('#', '');
            let r = Math.max(0, Math.min(255, (parseInt(h.substring(0, 2), 16) || 0) + amount));
            let g = Math.max(0, Math.min(255, (parseInt(h.substring(2, 4), 16) || 0) + amount));
            let b = Math.max(0, Math.min(255, (parseInt(h.substring(4, 6), 16) || 0) + amount));
            return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
        }

        function widgetFabIconSvg(bgColor, size) {
            const safe = /^#[0-9a-fA-F]{6}$/.test(bgColor) ? bgColor : '#1A66FF';
            return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
                + '<rect width="64" height="64" rx="18" fill="' + safe + '" data-widget-fab-bg/>'
                + '<path fill="#FFFFFF" d="M32 14.5c-9.9 0-18 8.1-18 18 0 4.8 1.9 9.1 5 12.3L14.5 50.5l8.2-6.5c3.1 2 6.7 3.2 10.8 3.2 9.9 0 18-8.1 18-18s-8.1-18-18-18z"/>'
                + '<rect x="23.5" y="26" width="17" height="3.5" rx="1.75" fill="#0D2355"/>'
                + '<rect x="23.5" y="32" width="12.5" height="3.5" rx="1.75" fill="#0D2355"/>'
                + '</svg>';
        }

        const colorDark = adjustColor(color, -20);
        const colorFocus = hexToRgba(color, 0.12);

        const styles = document.createElement('style');
        styles.textContent = `
        #salesbot-bubble {
            position: fixed;
            bottom: ${bubbleBottom}px;
            right: 24px;
            width: 60px;
            height: 60px;
            border-radius: 0;
            background: transparent;
            cursor: pointer;
            filter: drop-shadow(0 6px 28px rgba(0,0,0,0.18));
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: ${zIndex};
            transition: transform 0.2s ease, filter 0.2s ease, opacity 0.2s ease;
            border: none;
            padding: 0;
            line-height: 1;
            box-sizing: border-box;
        }
        #salesbot-bubble svg {
            display: block;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }
        #salesbot-bubble:hover { transform: scale(1.06); filter: drop-shadow(0 8px 32px rgba(0,0,0,0.22)); }
        #salesbot-bubble:active { transform: scale(0.96); }
        #salesbot-bubble.hidden { opacity: 0; pointer-events: none; transform: scale(0.8); }
        @media (max-width: 1023px) {
            body.marketing-page #salesbot-bubble {
                bottom: calc(${bubbleBottom + 48}px + env(safe-area-inset-bottom, 0px));
                right: 14px;
                width: 52px;
                height: 52px;
            }
            body.marketing-page #salesbot-window {
                bottom: calc(${windowBottom + 40}px + env(safe-area-inset-bottom, 0px));
                right: 10px;
                left: 10px;
                width: auto;
                max-width: none;
                max-height: calc(100dvh - 150px - env(safe-area-inset-bottom, 0px));
            }
        }
        #salesbot-window {
            position: fixed;
            bottom: ${windowBottom}px;
            right: 24px;
            width: 380px;
            max-width: calc(100vw - 24px);
            height: 520px;
            max-height: calc(100dvh - ${windowBottom + 24}px);
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 12px 48px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.04);
            z-index: ${zIndex + 1};
            display: none;
            flex-direction: column;
            overflow: hidden;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            opacity: 0;
            transform: translateY(16px) scale(0.96);
            transition: opacity 0.25s ease, transform 0.25s ease;
            box-sizing: border-box;
        }
        #salesbot-window *, #salesbot-bubble * { box-sizing: border-box; }
        #salesbot-window.open {
            display: flex;
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        #salesbot-header {
            background: linear-gradient(135deg, ${color} 0%, ${colorDark} 100%);
            color: #fff;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        #salesbot-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,0.35);
        }
        #salesbot-header-text { flex: 1; min-width: 0; }
        #salesbot-header-title {
            font-weight: 600;
            font-size: 15px;
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #salesbot-header-status {
            font-size: 12px;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }
        #salesbot-header-status::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #7dffb3;
            box-shadow: 0 0 0 2px rgba(125,255,179,0.35);
        }
        #salesbot-close {
            background: rgba(255,255,255,0.28);
            border: 2px solid rgba(255,255,255,0.65);
            color: #fff !important;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background 0.15s, border-color 0.15s;
            padding: 0;
            line-height: 1;
        }
        #salesbot-close:hover { background: rgba(255,255,255,0.42); border-color: #fff; }
        #salesbot-close svg {
            stroke: #ffffff !important;
            color: #ffffff !important;
            pointer-events: none;
        }
        #salesbot-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px 16px;
            background: #f8f9fa;
            scroll-behavior: smooth;
        }
        #salesbot-messages::-webkit-scrollbar { width: 5px; }
        #salesbot-messages::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        #salesbot-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            height: 100%;
            min-height: 180px;
            padding: 24px 20px;
            color: #6b7280;
        }
        #salesbot-empty.hidden { display: none; }
        .sb-empty-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #eef0f2;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
            color: ${color};
        }
        .sb-empty-title {
            font-size: 15px;
            font-weight: 600;
            color: #374151;
            margin: 0 0 6px;
        }
        .sb-empty-sub {
            font-size: 13px;
            line-height: 1.5;
            margin: 0;
            max-width: 240px;
        }
        .sb-msg {
            margin-bottom: 10px;
            max-width: 82%;
            padding: 10px 14px;
            border-radius: 16px;
            line-height: 1.5;
            font-size: 14px;
            word-wrap: break-word;
            animation: sb-fade-in 0.25s ease;
        }
        @keyframes sb-fade-in {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sb-msg.bot {
            background: #fff;
            color: #1f2937;
            border-top-left-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .sb-msg.user {
            background: ${color};
            color: #fff;
            margin-left: auto;
            border-top-right-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }
        .sb-typing {
            display: inline-flex;
            gap: 4px;
            padding: 12px 16px;
            background: #fff;
            border-radius: 16px;
            border-top-left-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            margin-bottom: 10px;
        }
        .sb-typing span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #9ca3af;
            animation: sb-bounce 1.2s infinite ease-in-out;
        }
        .sb-typing span:nth-child(2) { animation-delay: 0.15s; }
        .sb-typing span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes sb-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
            30% { transform: translateY(-5px); opacity: 1; }
        }
        #salesbot-input-area {
            display: flex;
            gap: 10px;
            padding: 14px 16px;
            border-top: 1px solid #e5e7eb;
            background: #fff;
            align-items: center;
        }
        #salesbot-input {
            flex: 1;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            border-radius: 24px;
            padding: 11px 18px;
            font-size: 14px;
            outline: none;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        #salesbot-input:focus {
            border-color: ${color};
            box-shadow: 0 0 0 3px ${colorFocus};
            background: #fff;
        }
        #salesbot-input:disabled { opacity: 0.6; }
        #salesbot-send {
            background: ${color};
            color: #fff !important;
            border: 2px solid rgba(255,255,255,0.55);
            border-radius: 50%;
            width: 42px;
            height: 42px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.15s, opacity 0.15s, box-shadow 0.15s;
            padding: 0;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
        }
        #salesbot-send svg {
            fill: #ffffff !important;
            color: #ffffff !important;
            pointer-events: none;
        }
        #salesbot-send:hover:not(:disabled) { transform: scale(1.05); box-shadow: 0 4px 12px rgba(0,0,0,0.22); }
        #salesbot-send:active:not(:disabled) { transform: scale(0.95); }
        #salesbot-send:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            background: #6b7280;
            border-color: rgba(255,255,255,0.35);
        }
        #salesbot-powered {
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
            padding: 0 16px 10px;
            background: #fff;
        }
        #salesbot-powered a {
            color: #9ca3af;
            text-decoration: none;
        }
        #salesbot-powered a:hover {
            text-decoration: underline;
        }
        @media (max-width: 480px) {
            #salesbot-window {
                bottom: 0;
                right: 0;
                left: 0;
                width: 100%;
                max-width: 100%;
                height: 100dvh;
                max-height: 100dvh;
                border-radius: 0;
            }
            #salesbot-bubble { bottom: 20px; right: 20px; }
            #salesbot-bubble.hidden { display: none; }
        }
    `;
        document.head.appendChild(styles);

        const initials = titleInitials(botTitle);

        const bubble = document.createElement('button');
        bubble.id = 'salesbot-bubble';
        bubble.type = 'button';
        bubble.setAttribute('aria-label', 'Open chat');
        bubble.innerHTML = widgetFabIconSvg(color, 60);

        const win = document.createElement('div');
        win.id = 'salesbot-window';
        win.setAttribute('role', 'dialog');
        win.setAttribute('aria-label', 'Chat');
        win.innerHTML = `
        <div id="salesbot-header">
            <div id="salesbot-avatar">${initials}</div>
            <div id="salesbot-header-text">
                <div id="salesbot-header-title">${escapeHtml(botTitle)}</div>
                <div id="salesbot-header-status">${widgetReady ? 'Online' : 'Setup required'}</div>
            </div>
            <button type="button" id="salesbot-close" aria-label="Close chat" title="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="salesbot-messages">
            <div id="salesbot-empty">
                <div class="sb-empty-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
                </div>
                <p class="sb-empty-title">Start a conversation</p>
                <p class="sb-empty-sub">Send a message and we'll reply right away.</p>
            </div>
        </div>
        <div id="salesbot-input-area">
            <input id="salesbot-input" type="text" placeholder="Type your message…" autocomplete="off" maxlength="2000"/>
            <button id="salesbot-send" type="button" aria-label="Send message" title="Send" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="#ffffff" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="#ffffff"/></svg>
            </button>
        </div>
        <div id="salesbot-powered"><a href="https://iqpigeon.com" target="_blank" rel="noopener noreferrer">iqpigeon.com</a></div>`;

        document.body.appendChild(bubble);
        document.body.appendChild(win);

        const messagesEl = document.getElementById('salesbot-messages');
        const emptyEl = document.getElementById('salesbot-empty');
        const inputEl = document.getElementById('salesbot-input');
        const sendBtn = document.getElementById('salesbot-send');
        let typingEl = null;

        function escapeHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function openChat() {
            win.classList.add('open');
            bubble.classList.add('hidden');
            setTimeout(() => inputEl.focus(), 280);
        }

        function closeChat() {
            win.classList.remove('open');
            bubble.classList.remove('hidden');
        }

        function hideEmpty() {
            if (emptyEl) emptyEl.classList.add('hidden');
            hasConversation = true;
        }

        function addMessage(text, role) {
            hideEmpty();
            const div = document.createElement('div');
            div.className = 'sb-msg ' + role;
            div.textContent = text;
            messagesEl.appendChild(div);
            scrollToBottom();
        }

        function scrollToBottom() {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function showTyping() {
            removeTyping();
            typingEl = document.createElement('div');
            typingEl.className = 'sb-typing';
            typingEl.innerHTML = '<span></span><span></span><span></span>';
            messagesEl.appendChild(typingEl);
            scrollToBottom();
        }

        function removeTyping() {
            if (typingEl) {
                typingEl.remove();
                typingEl = null;
            }
        }

        function updateSendState() {
            const hasText = inputEl.value.trim().length > 0;
            sendBtn.disabled = !hasText || isSending;
        }

        bubble.addEventListener('click', openChat);

        document.getElementById('salesbot-close')?.addEventListener('click', (e) => {
            e.stopPropagation();
            closeChat();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && win.classList.contains('open')) {
                closeChat();
            }
        });

        inputEl.addEventListener('input', updateSendState);

        function parseApiJson(raw) {
            const text = String(raw || '').replace(/^\uFEFF/, '').trim();
            if (!text) {
                return { ok: false, data: null, reason: 'empty' };
            }
            try {
                return { ok: true, data: JSON.parse(text), reason: null };
            } catch (e) {
                const start = text.indexOf('{');
                const end = text.lastIndexOf('}');
                if (start >= 0 && end > start) {
                    try {
                        return { ok: true, data: JSON.parse(text.slice(start, end + 1)), reason: null };
                    } catch (e2) { /* fall through */ }
                }
                return { ok: false, data: null, reason: 'invalid_json' };
            }
        }

    async function sendMessage() {
        const text = inputEl.value.trim();
        if (!text || isSending) return;

        if (!widgetReady) {
            addMessage(
                (serverCfg && serverCfg.error)
                    || 'This chat widget is not active yet. Enable it in IQ Pigeon → Bot Setup → Website Widget, then save.',
                'bot'
            );
            return;
        }

        isSending = true;
        updateSendState();
        addMessage(text, 'user');
        inputEl.value = '';
        inputEl.disabled = true;
        showTyping();

        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutMs = 75000;
        const timeoutId = controller
            ? setTimeout(function () { try { controller.abort(); } catch (e) { /* ignore */ } }, timeoutMs)
            : null;

        try {
            const endpoint = apiBase.replace(/\/$/, '') + '/api/chat-widget.php';
            const fetchOpts = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    bot_id: botId,
                    session_id: sessionId,
                    message: text,
                    demo_mode: isDemo,
                    locale: browserLocale,
                }),
            };
            if (controller) {
                fetchOpts.signal = controller.signal;
            }
            const res = await fetch(endpoint, fetchOpts);

            const raw = await res.text();
            if (timeoutId) clearTimeout(timeoutId);
            const parsed = parseApiJson(raw);
            if (!parsed.ok) {
                removeTyping();
                const apiHint = config.apiBase
                    ? ''
                    : ' Re-copy the embed code from IQ Pigeon and include apiBase: \'https://iqpigeon.com\'.';
                addMessage(
                    'Chat service returned an invalid response (HTTP ' + res.status + ').' + apiHint,
                    'bot'
                );
                finishSend();
                return;
            }

            const data = parsed.data || {};
            removeTyping();

            if (data.success) {
                if (data.paused) {
                    addMessage('A team member will reply shortly.', 'bot');
                } else if (data.reply && String(data.reply).trim()) {
                    addMessage(String(data.reply).trim(), 'bot');
                } else {
                    addMessage('I am here — could you say that one more time?', 'bot');
                }
                finishSend();
                return;
            }

            addMessage(data.error || data.message || 'Sorry, something went wrong. Please try again.', 'bot');
        } catch (err) {
            if (timeoutId) clearTimeout(timeoutId);
            removeTyping();
            const aborted = err && (err.name === 'AbortError' || err.code === 20);
            addMessage(
                aborted
                    ? 'That took too long on our side. Please try again in a moment.'
                    : 'Connection error. Please check your network and try again.',
                'bot'
            );
        }

        finishSend();
    }

    function finishSend() {
        isSending = false;
        inputEl.disabled = false;
        updateSendState();
        inputEl.focus();
    }

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    updateSendState();

    if (serverCfg && serverCfg.messages && serverCfg.messages.length) {
        serverCfg.messages.forEach(function (msg) {
            const role = msg && msg.role === 'user' ? 'user' : 'bot';
            const text = msg && msg.text ? String(msg.text).trim() : '';
            if (text) {
                addMessage(text, role);
            }
        });
    }

    if (config.autoOpen) {
        requestAnimationFrame(() => openChat());
    }
    })();
})();
