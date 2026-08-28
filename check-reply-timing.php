<?php
/**
 * Reply timing diagnostic — shows where WhatsApp response delay comes from.
 *
 * Usage (logged in):
 *   /check-reply-timing.php
 *   /check-reply-timing.php?bot_id=8&live=1&message=Hi
 *   /check-reply-timing.php?lead_id=123&live=1
 *
 * Delete or restrict this file on production if you prefer (admin-only below).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reply-timing.php';
require_once __DIR__ . '/includes/whatsapp-inbound.php';

$user = require_login();

$botId = (int) ($_GET['bot_id'] ?? 0);
$leadId = (int) ($_GET['lead_id'] ?? 0);
$live = !empty($_GET['live']);
$customIncoming = trim($_GET['incoming'] ?? '');
$customReply = trim($_GET['reply'] ?? '');

// Sample exchanges from real WhatsApp tests (Roman Urdu + English)
$sampleExchanges = [
    [
        'label'    => 'Short greeting',
        'incoming' => 'Hi',
        'reply'    => 'Hi! How can I help you with IQ Pigeon today?',
    ],
    [
        'label'    => 'Name + location question',
        'incoming' => 'What is your name aur Kahan se ho ap?',
        'reply'    => 'Mera naam Sareen hai aur main Pakistan se hoon. Aap kaise hain?',
    ],
    [
        'label'    => 'Service interest (long reply)',
        'incoming' => 'Mn thek Alhamdulillah',
        'reply'    => 'Alhamdulillah, bohat acha! Aapko IQ Pigeon ki kaunsi service mein interest hai — AI sales automation, lead qualification, ya dono?',
    ],
    [
        'label'    => 'New business (longest typical reply)',
        'incoming' => 'Abi to business start kr rha hun pata ni kia chahy hoga',
        'reply'    => 'Bilkul, samajh gayi. Jab business naya hota hai to options clear nahi hote. Aap batao, aapka business kis type ka hai? E-commerce, real estate, service-based, ya kuch aur? Us hisaab se main aapko best suggestion doongi.',
    ],
];

if ($customIncoming !== '' && $customReply !== '') {
    array_unshift($sampleExchanges, [
        'label'    => 'Custom (from URL)',
        'incoming' => $customIncoming,
        'reply'    => $customReply,
    ]);
}

$config = [
    'HUMAN_READ_WPM'              => defined('HUMAN_READ_WPM') ? (int) HUMAN_READ_WPM : 300,
    'HUMAN_REPLY_WPM'             => defined('HUMAN_REPLY_WPM') ? (int) HUMAN_REPLY_WPM : 100,
    'HUMAN_REPLY_DELAY_MIN_MS'    => defined('HUMAN_REPLY_DELAY_MIN_MS') ? (int) HUMAN_REPLY_DELAY_MIN_MS : 1500,
    'HUMAN_REPLY_DELAY_MAX_MS'    => defined('HUMAN_REPLY_DELAY_MAX_MS') ? (int) HUMAN_REPLY_DELAY_MAX_MS : 90000,
    'WHATSAPP_INBOUND_DEBOUNCE_MS'=> whatsapp_inbound_debounce_ms(),
    'WHATSAPP_TYPING_PULSE_MS'    => defined('WHATSAPP_TYPING_PULSE_MS') ? (int) WHATSAPP_TYPING_PULSE_MS : 18000,
    'OPENAI_MODEL'              => defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini',
];

$simulations = [];
$totalHumanMs = 0;
foreach ($sampleExchanges as $ex) {
    $bd = human_agent_delay_breakdown($ex['reply'], $ex['incoming']);
    $simulations[] = array_merge($ex, ['breakdown' => $bd]);
    $totalHumanMs += $bd['delay_ms'];
}

$avgHumanMs = count($simulations) > 0 ? (int) round($totalHumanMs / count($simulations)) : 0;

$liveResult = null;
if ($live && ($botId > 0 || $leadId > 0)) {
    require_once __DIR__ . '/api/ai-respond.php';

    if ($leadId <= 0 && $botId > 0) {
        $bot = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, (int) $user['id']]);
        if ($bot) {
            $leadId = (int) (db_fetch(
                'SELECT id FROM leads WHERE bot_id = ? ORDER BY id DESC LIMIT 1',
                'i',
                [$botId]
            )['id'] ?? 0);
        }
    }

    $lead = $leadId > 0
        ? db_fetch(
            'SELECT l.* FROM leads l JOIN bots b ON b.id = l.bot_id WHERE l.id = ? AND b.user_id = ?',
            'ii',
            [$leadId, (int) $user['id']]
        )
        : null;

    if ($lead) {
        $testMessage = trim($_GET['message'] ?? 'Hi');
        $t0 = microtime(true);
        $ai = get_ai_response((int) $lead['id'], (int) $lead['bot_id'], $testMessage);
        $aiMs = (int) round((microtime(true) - $t0) * 1000);

        $replyText = trim($ai['reply'] ?? '');
        $humanBd = $replyText !== ''
            ? human_agent_delay_breakdown($replyText, $testMessage)
            : null;

        $liveResult = [
            'success'      => !empty($ai['success']),
            'error'        => $ai['error'] ?? null,
            'test_message' => $testMessage,
            'reply_preview'=> mb_substr($replyText, 0, 200),
            'ai_ms'        => $aiMs,
            'human'        => $humanBd,
            'debounce_ms'  => $config['WHATSAPP_INBOUND_DEBOUNCE_MS'],
            'estimated_total_ms' => $config['WHATSAPP_INBOUND_DEBOUNCE_MS'] + $aiMs + (int) ($humanBd['delay_ms'] ?? 0),
            'lead_id'      => (int) $lead['id'],
            'bot_id'       => (int) $lead['bot_id'],
            'note'         => 'Live test inserts a user message + assistant reply into the conversation.',
        ];
    } else {
        $liveResult = ['success' => false, 'error' => 'Lead not found or access denied. Use ?bot_id=YOUR_BOT or ?lead_id=YOUR_LEAD'];
    }
}

$userBots = db_fetch_all('SELECT id, name FROM bots WHERE user_id = ? ORDER BY name', 'i', [(int) $user['id']]);

function fmt_ms(int $ms): string
{
    if ($ms >= 1000) {
        return number_format($ms / 1000, 1) . ' s (' . number_format($ms) . ' ms)';
    }

    return $ms . ' ms';
}

function fmt_sec(float $s): string
{
    return number_format($s, 1) . ' s';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Reply Timing Check — <?= sanitize(APP_NAME) ?></title>
    <style>
        :root { --bg: #f4f6f4; --card: #fff; --text: #191c1d; --muted: #5c6b5c; --accent: #006d2f; --warn: #b45309; --bar-read: #25d366; --bar-type: #006d2f; --bar-ai: #6366f1; --bar-debounce: #94a3b8; }
        * { box-sizing: border-box; }
        body { font-family: Inter, system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 24px 16px 48px; line-height: 1.5; }
        .wrap { max-width: 960px; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin: 0 0 8px; }
        h2 { font-size: 1.1rem; margin: 28px 0 12px; }
        p, li { color: var(--muted); }
        .card { background: var(--card); border-radius: 16px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e8ebe8; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .highlight { color: var(--accent); font-weight: 600; }
        .warn { color: var(--warn); font-weight: 600; }
        .pipeline { display: flex; height: 28px; border-radius: 8px; overflow: hidden; margin: 8px 0; }
        .pipeline span { display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; font-weight: 600; min-width: 2px; }
        .legend { display: flex; flex-wrap: wrap; gap: 12px; font-size: 12px; margin-top: 8px; }
        .legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
        code { background: #eef1ee; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
        .quote { font-size: 13px; color: var(--text); background: #f8faf8; padding: 8px 10px; border-radius: 8px; margin: 4px 0; }
        a { color: var(--accent); }
        form { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-top: 12px; }
        label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--muted); }
        input, select { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        button { padding: 10px 16px; background: var(--accent); color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .back { display: inline-block; margin-bottom: 16px; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
<div class="wrap">
    <a class="back" href="/client/dashboard">← Back to dashboard</a>
    <h1>WhatsApp reply timing check</h1>
    <p>This shows <strong>where time is spent</strong> between a customer message and your bot’s WhatsApp reply. Use it to decide if you want faster settings.</p>

    <div class="card">
        <h2 style="margin-top:0">How the pipeline works</h2>
        <ol>
            <li><strong>Debounce</strong> — wait <?= fmt_ms($config['WHATSAPP_INBOUND_DEBOUNCE_MS']) ?> so rapid messages merge (config: <code>WHATSAPP_INBOUND_DEBOUNCE_MS</code>)</li>
            <li><strong>AI (OpenAI)</strong> — generate reply (usually <strong>3–15 s</strong>; measured live below)</li>
            <li><strong>Human delay</strong> — simulated “reading” + “typing” before send (config: <code>HUMAN_READ_WPM</code>, <code>HUMAN_REPLY_WPM</code>)</li>
            <li><strong>Send</strong> — Meta API (usually &lt; 1 s)</li>
        </ol>
        <p><strong>Total ≈ debounce + AI + human delay</strong> (typing indicator runs during AI + human delay)</p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Current config (<code>config.php</code>)</h2>
        <table>
            <tr><th>Setting</th><th>Value</th><th>Meaning</th></tr>
            <tr>
                <td><code>HUMAN_READ_WPM</code></td>
                <td class="num"><?= (int) $config['HUMAN_READ_WPM'] ?> WPM</td>
                <td>Speed to “read” customer message (~<?= (int) round(60000 / $config['HUMAN_READ_WPM']) ?> ms per word, cap 0.5–3.5 s)</td>
            </tr>
            <tr>
                <td><code>HUMAN_REPLY_WPM</code></td>
                <td class="num"><?= (int) $config['HUMAN_REPLY_WPM'] ?> WPM</td>
                <td>Typing speed for reply (~<?= (int) round(60000 / $config['HUMAN_REPLY_WPM']) ?> ms per word)</td>
            </tr>
            <tr>
                <td><code>HUMAN_REPLY_DELAY_MIN_MS</code></td>
                <td class="num"><?= fmt_ms($config['HUMAN_REPLY_DELAY_MIN_MS']) ?></td>
                <td>Minimum wait before send</td>
            </tr>
            <tr>
                <td><code>HUMAN_REPLY_DELAY_MAX_MS</code></td>
                <td class="num"><?= fmt_ms($config['HUMAN_REPLY_DELAY_MAX_MS']) ?></td>
                <td>Maximum wait (long replies hit this cap)</td>
            </tr>
            <tr>
                <td><code>WHATSAPP_INBOUND_DEBOUNCE_MS</code></td>
                <td class="num"><?= fmt_ms($config['WHATSAPP_INBOUND_DEBOUNCE_MS']) ?></td>
                <td>Fixed wait at start of every reply</td>
            </tr>
        </table>
        <p style="margin-top:12px;font-size:13px">
            Formula: <code>human_delay = read_time(incoming) + type_time(reply)</code> then clamp to min/max ± 4% jitter.<br/>
            At <?= (int) $config['HUMAN_REPLY_WPM'] ?> WPM, a <strong>30-word reply ≈ <?= fmt_ms(30 * (int) round(60000 / $config['HUMAN_REPLY_WPM'])) ?></strong> typing time alone.
        </p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Simulated human delay (your recent chat examples)</h2>
        <p>Average human delay across samples: <span class="highlight"><?= fmt_ms($avgHumanMs) ?></span></p>
        <table>
            <tr>
                <th>Exchange</th>
                <th>Read</th>
                <th>Type</th>
                <th>Human total</th>
                <th>+ 2s debounce + ~8s AI ≈</th>
            </tr>
            <?php foreach ($simulations as $sim):
                $b = $sim['breakdown'];
                $estAi = 8000;
                $estTotal = $config['WHATSAPP_INBOUND_DEBOUNCE_MS'] + $estAi + $b['delay_ms'];
            ?>
            <tr>
                <td>
                    <strong><?= sanitize($sim['label']) ?></strong>
                    <div class="quote">In: <?= sanitize(mb_substr($sim['incoming'], 0, 80)) ?></div>
                    <div class="quote">Out: <?= sanitize(mb_substr($sim['reply'], 0, 120)) ?><?= mb_strlen($sim['reply']) > 120 ? '…' : '' ?></div>
                    <small><?= (int) $b['incoming_words'] ?> in words · <?= (int) $b['reply_words'] ?> out words</small>
                </td>
                <td class="num">
                    <?= fmt_ms($b['read_ms']) ?><br/>
                    <small>@ <?= (int) $b['read_wpm'] ?> WPM</small>
                </td>
                <td class="num">
                    <?= fmt_ms($b['type_ms']) ?><?= $b['capped_by_max'] ? ' <span class="warn">(capped)</span>' : '' ?><br/>
                    <small>@ <?= (int) $b['type_wpm'] ?> WPM · <?= (int) $b['ms_per_type_word'] ?> ms/word</small>
                </td>
                <td class="num highlight"><?= fmt_ms($b['delay_ms']) ?></td>
                <td class="num warn">~<?= fmt_sec($estTotal / 1000) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($liveResult): ?>
    <div class="card">
        <h2 style="margin-top:0">Live AI benchmark <?= !empty($liveResult['success']) ? '✓' : '✗' ?></h2>
        <?php if (!empty($liveResult['error']) && empty($liveResult['success'])): ?>
            <p class="warn"><?= sanitize((string) $liveResult['error']) ?></p>
        <?php else:
            $h = $liveResult['human'] ?? [];
            $deb = (int) $liveResult['debounce_ms'];
            $ai = (int) $liveResult['ai_ms'];
            $hum = (int) ($h['delay_ms'] ?? 0);
            $tot = (int) $liveResult['estimated_total_ms'];
            $pDeb = $tot > 0 ? round($deb / $tot * 100) : 0;
            $pAi = $tot > 0 ? round($ai / $tot * 100) : 0;
            $pHum = $tot > 0 ? max(0, 100 - $pDeb - $pAi) : 0;
        ?>
        <p>Test message: <code><?= sanitize($liveResult['test_message']) ?></code></p>
        <p>Reply preview: <em><?= sanitize($liveResult['reply_preview']) ?></em></p>
        <p class="highlight">Estimated WhatsApp total: ~<?= fmt_sec($tot / 1000) ?></p>
        <div class="pipeline">
            <span style="width:<?= $pDeb ?>%;background:var(--bar-debounce)"><?= $pDeb ?>%</span>
            <span style="width:<?= $pAi ?>%;background:var(--bar-ai)">AI</span>
            <span style="width:<?= $pHum ?>%;background:var(--bar-type)">Human</span>
        </div>
        <div class="legend">
            <span><i style="background:var(--bar-debounce)"></i>Debounce <?= fmt_ms($deb) ?></span>
            <span><i style="background:var(--bar-ai)"></i>AI <?= fmt_ms($ai) ?></span>
            <span><i style="background:var(--bar-read)"></i>Read <?= fmt_ms((int) ($h['read_ms'] ?? 0)) ?></span>
            <span><i style="background:var(--bar-type)"></i>Type <?= fmt_ms((int) ($h['type_ms'] ?? 0)) ?> → send after <?= fmt_ms($hum) ?></span>
        </div>
        <p style="font-size:13px"><?= sanitize($liveResult['note'] ?? '') ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0">Run live AI timing test</h2>
        <p>Calls OpenAI once and measures AI time + projected human delay. <strong>Inserts messages into the conversation.</strong></p>
        <form method="get">
            <label>Bot
                <select name="bot_id">
                    <option value="">— select —</option>
                    <?php foreach ($userBots as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $botId === (int) $b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?> (#<?= (int) $b['id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Or lead ID <input type="number" name="lead_id" value="<?= $leadId ?: '' ?>" placeholder="optional"/></label>
            <label>Test message <input type="text" name="message" value="<?= sanitize($_GET['message'] ?? 'Hi') ?>"/></label>
            <input type="hidden" name="live" value="1"/>
            <button type="submit">Run live test</button>
        </form>
        <p style="font-size:13px;margin-top:12px">Custom simulation (no AI): add <code>&amp;incoming=...&amp;reply=...</code> to the URL.</p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Why you saw ~30–50 seconds</h2>
        <ul>
            <li><strong>~2 s</strong> — inbound debounce on every message</li>
            <li><strong>~5–15 s</strong> — OpenAI API (network + model)</li>
            <li><strong>~2–22 s</strong> — human delay at <?= (int) $config['HUMAN_READ_WPM'] ?> read / <?= (int) $config['HUMAN_REPLY_WPM'] ?> type WPM (~<?= (int) round(60000 / $config['HUMAN_REPLY_WPM']) ?> ms per reply word; max <?= fmt_ms($config['HUMAN_REPLY_DELAY_MAX_MS']) ?>)</li>
        </ul>
        <p>Typical total with current settings: <strong>~10–20 s</strong> for short replies, <strong>~15–25 s</strong> for longer ones (plus AI time).</p>
    </div>
</div>
</body>
</html>
