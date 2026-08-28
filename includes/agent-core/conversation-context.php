<?php
/**
 * Thread, referents, missed thought. Reads conversations; does not write.
 *
 * @return array<string, mixed>
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $turnCtx
 * @return array<string, mixed>
 */
function agent_core_conversation_context(array $turnCtx): array
{
    $leadId = (int) ($turnCtx['lead_id'] ?? 0);
    $history = [];
    if ($leadId > 0 && function_exists('wa_webhook_recent_chat')) {
        try {
            $history = wa_webhook_recent_chat($leadId, 10);
        } catch (Throwable $e) {
            $history = [];
        }
    }
    if (!is_array($history)) {
        $history = [];
    }

    $lastAssistant = '';
    $lastUser = '';
    $current = mb_strtolower(trim((string) ($turnCtx['text'] ?? '')));
    foreach (array_reverse($history) as $row) {
        $role = (string) ($row['role'] ?? '');
        $text = trim((string) ($row['message'] ?? ''));
        if ($text === '') {
            continue;
        }
        if ($role === 'assistant' && $lastAssistant === '') {
            $lastAssistant = $text;
        }
        if ($role === 'user' && $lastUser === '' && mb_strtolower($text) !== $current) {
            $lastUser = $text;
        }
        if ($lastAssistant !== '' && $lastUser !== '') {
            break;
        }
    }

    $referent = agent_core_infer_referent((string) ($turnCtx['text'] ?? ''), $lastAssistant, $lastUser);
    $missed = agent_core_infer_missed_thought((string) ($turnCtx['text'] ?? ''), $lastUser, $lastAssistant);

    $facts = [];
    $botId = (int) ($turnCtx['bot_id'] ?? 0);
    if ($botId > 0 && $leadId > 0 && function_exists('agent_core_memory_read')) {
        try {
            $facts = agent_core_memory_read($botId, $leadId, (string) ($turnCtx['text'] ?? ''));
        } catch (Throwable $e) {
            $facts = [];
        }
    }

    return [
        'history'        => $history,
        'last_assistant' => $lastAssistant,
        'last_user'      => $lastUser,
        'referents'      => ['product' => $referent, 'topic' => $referent],
        'open_goal'      => null,
        'missed_thought' => $missed,
        'runtime_facts'  => is_array($facts) ? $facts : [],
    ];
}

function agent_core_infer_referent(string $current, string $lastAssistant, string $lastUser): string
{
    $cur = mb_strtolower($current);
    if (!preg_match('/\b(the |that |this )?(black|white|red|blue|other|same) ones?\b/u', $cur)
        && !preg_match('/\b(that one|this one|the one|it|those)\b/u', $cur)
    ) {
        return '';
    }
    $blob = $lastAssistant . ' ' . $lastUser;
    if (preg_match('/\*([^*]{3,80})\*/u', $lastAssistant, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\b([A-Z][A-Za-z0-9][A-Za-z0-9 \-]{2,40})\b/u', $blob, $m)) {
        return trim($m[1]);
    }

    return trim(mb_substr($lastAssistant, 0, 80));
}

function agent_core_infer_missed_thought(string $current, string $lastUser, string $lastAssistant): string
{
    $cur = mb_strtolower($current);
    if (!preg_match('/\b(why (didn\'?t|did not) you (answer|reply|respond)|you didn\'?t (answer|understand)|that\'?s not what i asked|you (didn\'?t|did not) (get|listen))\b/u', $cur)) {
        return '';
    }
    $prior = trim($lastUser);
    if ($prior !== '' && (str_contains($prior, '?') || mb_strlen($prior) > 8)) {
        return $prior;
    }

    return $lastUser;
}
