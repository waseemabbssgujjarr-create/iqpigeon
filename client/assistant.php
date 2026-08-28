<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';

$user = require_login();
$userId = (int) $user['id'];
ensure_bots_schema();
ensure_client_starter_bot($userId);

$bot = db_fetch('SELECT * FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
if (!$bot) {
    redirect('/client/dashboard');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '') && ($_POST['action'] ?? '') === 'save') {
    $rep = mb_substr(trim((string) ($_POST['rep_name'] ?? '')), 0, 30);
    $org = mb_substr(trim((string) ($_POST['company_name'] ?? '')), 0, 60);
    $knowledge = mb_substr(trim((string) ($_POST['knowledge'] ?? '')), 0, 4000);
    if ($rep === '') {
        $error = 'Give your assistant a name.';
    } else {
        db_execute('UPDATE bots SET rep_name = ?, name = ?, bot_knowledge = ?, knowledge_updated_at = NOW() WHERE id = ? AND user_id = ?', 'sssii', [$rep, $rep, $knowledge, (int) $bot['id'], $userId]);
        if ($org !== '') {
            db_execute('UPDATE users SET company_name = ? WHERE id = ?', 'si', [$org, $userId]);
            $user['company_name'] = $org;
        }
        $bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [(int) $bot['id'], $userId]);
        $message = 'Saved. The AI will use this on the next reply.';
    }
}

$rep = (string) ($bot['rep_name'] ?? $bot['name'] ?? 'Pigi');
$org = (string) ($user['company_name'] ?? '');
$knowledge = (string) ($bot['bot_knowledge'] ?? '');
$updated = (string) ($bot['knowledge_updated_at'] ?? '');
$csrf = csrf_token();

iqp_user_begin($user, 'assistant', ['title' => 'Assistant']);
if ($message !== '') {
    iqp_flash($message);
}
if ($error !== '') {
    iqp_flash($error, 'err');
}
?>
      <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-6">
        <div>
          <h1 class="text-[19px] lg:text-[22px] font-bold text-slate-800">Your AI Assistant</h1>
          <p class="text-[13px] lg:text-[13.5px] text-slate-500 mt-1">Give it a name and business notes. It chats like a normal AI model — no training step.</p>
        </div>
        <div class="flex items-center gap-1.5 text-[12px] text-slate-500 border border-slate-200 rounded-lg px-3 py-1.5 bg-white w-fit">
          Last updated: <?= $updated !== '' ? sanitize(date('M j, Y', strtotime($updated))) : 'Never' ?>
        </div>
      </div>

      <form method="post" class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-5 mb-5">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>"/>
        <input type="hidden" name="action" value="save"/>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="text-[13.5px] font-bold text-slate-800">Assistant name</div>
          <div class="text-[11.5px] text-slate-400 mt-1">Customers see this name in chat.</div>
          <input name="rep_name" maxlength="30" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 mt-3" value="<?= sanitize($rep) ?>"/>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="text-[13.5px] font-bold text-slate-800">Your organization</div>
          <div class="text-[11.5px] text-slate-400 mt-1">Business or brand name.</div>
          <input name="company_name" maxlength="60" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[13px] text-slate-700 mt-3" value="<?= sanitize($org) ?>"/>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5 lg:col-span-2">
          <div class="text-[14px] font-bold text-slate-800 mb-1">Business knowledge</div>
          <div class="text-[12px] text-slate-400 mb-3">Paste hours, delivery areas, policies, FAQs. The model reads this on every reply.</div>
          <textarea name="knowledge" maxlength="4000" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-[12.5px] text-slate-600 h-36"><?= sanitize($knowledge) ?></textarea>
          <div class="flex justify-end mt-3">
            <button class="bg-[#1FA855] text-white text-[12.5px] font-semibold rounded-lg px-4 py-2" type="submit">Save</button>
          </div>
        </div>
      </form>

      <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="text-[15px] font-bold text-slate-800 mb-1">Try the model</div>
          <div class="text-[12px] text-slate-400 mb-3">Chat here the same way customers will on WhatsApp.</div>
          <div id="iqpChat" class="border border-slate-100 rounded-lg p-3 h-64 overflow-y-auto space-y-2 bg-slate-50 text-[13px]"></div>
          <div class="flex gap-2 mt-3">
            <input id="iqpChatInput" class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-[13px]" placeholder="Ask anything..."/>
            <button type="button" id="iqpChatSend" class="bg-[#1FA855] text-white rounded-lg px-4 py-2 text-[13px] font-semibold">Send</button>
          </div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="text-[15px] font-bold text-slate-800 mb-2">How it works</div>
          <div class="text-[12.5px] text-slate-600 space-y-2">
            <p>1. Save name + knowledge.</p>
            <p>2. Keep your shop catalog up to date.</p>
            <p>3. Connect WhatsApp. The same model answers customers live.</p>
            <p class="text-slate-400">There is no separate training job. Knowledge is context, like ChatGPT instructions.</p>
          </div>
          <a href="/client/catalog" class="mt-4 w-full border border-[#1FA855] text-[#1FA855] rounded-lg py-2 text-[13px] font-semibold block text-center">Open Shop</a>
        </div>
      </div>
      <script>
      (function(){
        var hist = [];
        var box = document.getElementById('iqpChat');
        var input = document.getElementById('iqpChatInput');
        var btn = document.getElementById('iqpChatSend');
        function add(role, text) {
          var d = document.createElement('div');
          d.className = role === 'user' ? 'text-right' : '';
          d.innerHTML = '<span class="inline-block max-w-[85%] rounded-lg px-3 py-2 ' + (role === 'user' ? 'bg-[#1FA855] text-white' : 'bg-white border border-slate-200 text-slate-700') + '">' + text.replace(/</g,'&lt;') + '</span>';
          box.appendChild(d);
          box.scrollTop = box.scrollHeight;
        }
        function send() {
          var msg = (input.value || '').trim();
          if (!msg) return;
          input.value = '';
          add('user', msg);
          hist.push({role:'user', content:msg});
          var fd = new FormData();
          fd.append('csrf_token', <?= json_encode($csrf) ?>);
          fd.append('bot_id', <?= (int) $bot['id'] ?>);
          fd.append('message', msg);
          fd.append('history', JSON.stringify(hist));
          fetch('/api/assistant-chat.php', {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(j){
            var reply = j.ok ? j.reply : (j.error || 'Unavailable');
            add('assistant', reply);
            hist.push({role:'assistant', content:reply});
          }).catch(function(){ add('assistant', 'Network error'); });
        }
        btn.addEventListener('click', send);
        input.addEventListener('keydown', function(e){ if (e.key === 'Enter') send(); });
      })();
      </script>
<?php
iqp_user_end();
