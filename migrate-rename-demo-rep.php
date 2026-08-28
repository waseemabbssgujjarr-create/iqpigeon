<?php
/**
 * One-time: rename demo sales rep Nosheen → Sareen in bot prompts.
 * Run once: https://yoursite.com/migrate-rename-demo-rep.php
 * Delete this file after success.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/bot-knowledge.php';

header('Content-Type: text/plain; charset=UTF-8');

$oldName = 'Nosheen';
$newName = defined('WHATSAPP_DEMO_LABEL') && WHATSAPP_DEMO_LABEL !== ''
    ? WHATSAPP_DEMO_LABEL
    : 'Sareen';

$messages = [];

try {
    ensure_bots_schema();

    $bots = db_fetch_all('SELECT id, name, persona_description, openai_system_prompt, bot_knowledge FROM bots');
    $updated = 0;

    foreach ($bots as $bot) {
        $fields = [
            'persona_description' => (string) ($bot['persona_description'] ?? ''),
            'openai_system_prompt' => (string) ($bot['openai_system_prompt'] ?? ''),
            'bot_knowledge' => (string) ($bot['bot_knowledge'] ?? ''),
        ];

        $changed = false;
        $next = $fields;

        foreach ($fields as $key => $value) {
            if ($value === '' || stripos($value, $oldName) === false) {
                continue;
            }
            $next[$key] = preg_replace('/\b' . preg_quote($oldName, '/') . '\b/i', $newName, $value);
            if ($next[$key] !== $value) {
                $changed = true;
            }
        }

        if (!$changed) {
            continue;
        }

        db_execute(
            'UPDATE bots SET persona_description = ?, openai_system_prompt = ?, bot_knowledge = ? WHERE id = ?',
            'sssi',
            [$next['persona_description'], $next['openai_system_prompt'], $next['bot_knowledge'], (int) $bot['id']]
        );
        $updated++;
        $messages[] = 'Updated bot #' . (int) $bot['id'] . ' (' . ($bot['name'] ?? '') . ')';
    }

    if ($updated === 0) {
        $messages[] = 'No bots contained "' . $oldName . '" — nothing to change (or already renamed).';
    } else {
        $messages[] = 'Renamed "' . $oldName . '" → "' . $newName . '" in ' . $updated . ' bot(s).';
    }

    echo implode("\n", $messages) . "\n\nDone. Delete migrate-rename-demo-rep.php from the server.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
}
