/**
 * Full-page live demo — chat immediately using bot knowledge from settings.
 */
(function () {
    const panel = document.getElementById('demo-chat-panel');
    if (!panel) return;

    const botId = panel.dataset.botId;
    const color = panel.dataset.color || '#4aad36';
    const greeting = panel.dataset.greeting || 'Hi! How can I help you today?';
    const messagesEl = document.getElementById('demo-chat-messages');
    const inputEl = document.getElementById('demo-chat-input');
    const sendBtn = document.getElementById('demo-chat-send');
    const headerEl = document.getElementById('demo-chat-header');

    if (headerEl) headerEl.style.background = color;

    const browserLocale = (navigator.language || navigator.userLanguage || '').trim();

    const sessionKey = 'demo_chat_session_' + botId;
    let sessionId = localStorage.getItem(sessionKey);
    if (!sessionId) {
        sessionId = 'demo_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        localStorage.setItem(sessionKey, sessionId);
    }

    function addMessage(text, role) {
        const div = document.createElement('div');
        div.className = 'demo-msg ' + role;
        if (role === 'user') div.style.background = color;
        div.textContent = text;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    addMessage(greeting, 'bot');

    async function sendMessage() {
        const text = inputEl.value.trim();
        if (!text) return;

        addMessage(text, 'user');
        inputEl.value = '';
        inputEl.disabled = true;
        sendBtn.disabled = true;

        try {
            const res = await fetch('/api/chat-widget.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_id: parseInt(botId, 10),
                    session_id: sessionId,
                    message: text,
                    name: 'Demo Visitor',
                    demo_mode: true,
                    locale: browserLocale,
                }),
            });
            const data = await res.json();

            if (data.success && data.reply) {
                addMessage(data.reply, 'bot');
            } else {
                addMessage(data.error || data.message || 'Sorry, something went wrong.', 'bot');
            }
        } catch {
            addMessage('Network error. Please try again.', 'bot');
        }

        inputEl.disabled = false;
        sendBtn.disabled = false;
        inputEl.focus();
    }

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') sendMessage();
    });
})();
