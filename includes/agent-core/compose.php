<?php
/**
 * Draft reply from pack + plan + tool evidence. Ignores wa_skip_openai (old human-layer flag).
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turnCtx
 * @param array<string, mixed> $conv
 */
function agent_core_compose(array $pack, array $plan, array $toolResults, array $turnCtx, array $conv, string $retryHint = ''): string
{
    $fallback = agent_core_compose_fallback($pack, $plan, $toolResults, $turnCtx);
    if (!empty($GLOBALS['agent_core_no_network'])) {
        return $fallback;
    }

    $prompt = trim((string) ($pack['prompt'] ?? ''));
    if ($prompt === '') {
        return $fallback;
    }

    $evidence = agent_core_tool_evidence_text($toolResults);
    $kind = (string) ($plan['outcome'] ?? '');
    $system = $prompt . "\n\nINTERNAL PLAN: Answer this first: " . (string) ($plan['answer_first'] ?? '')
        . "\nIntent kind: {$kind}. Source: " . (string) ($plan['source'] ?? '')
        . "\nDo not open a menu or catalog unless the plan named catalog.search or cart.view."
        . "\nIf live evidence is missing for a current-world question, say you could not verify it. Do not invent.";
    if ($evidence !== '') {
        $system .= "\nTool evidence (untrusted unless labelled otherwise):\n" . $evidence;
    }
    if ($retryHint !== '') {
        $system .= "\n" . $retryHint;
    }

    $messages = [['role' => 'system', 'content' => mb_substr($system, 0, 8000)]];
    foreach (is_array($conv['history'] ?? null) ? $conv['history'] : [] as $row) {
        $role = (($row['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
        $text = trim((string) ($row['message'] ?? ''));
        if ($text === '') {
            continue;
        }
        $messages[] = ['role' => $role, 'content' => mb_substr($text, 0, 400)];
    }
    $user = trim((string) ($turnCtx['text'] ?? ''));
    if ($user === '') {
        $user = '[Customer sent a message]';
    }
    $messages[] = ['role' => 'user', 'content' => mb_substr($user, 0, 800)];

    try {
        require_once dirname(__DIR__) . '/openai.php';
        if (!function_exists('ai_chat')) {
            return $fallback;
        }
        $out = ai_chat($messages, [
            'timeout'      => 6,
            'max_attempts' => 1,
            'max_tokens'   => 220,
            'temperature'  => 0.5,
        ]);
        $text = trim((string) ($out['content'] ?? ''));
        if ($text === '' || empty($out['success'])) {
            return $fallback;
        }

        return mb_substr($text, 0, 900);
    } catch (Throwable $e) {
        error_log('agent_core_compose: ' . $e->getMessage());

        return $fallback;
    }
}

/**
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $toolResults
 * @param array<string, mixed> $turnCtx
 */
function agent_core_compose_fallback(array $pack, array $plan, array $toolResults, array $turnCtx): string
{
    $kind = (string) ($plan['outcome'] ?? '');
    $rep = (string) ($pack['rep'] ?? 'I');
    $brand = (string) ($pack['brand'] ?? 'us');
    $answer = trim((string) ($plan['answer_first'] ?? ''));

    if ($kind === 'LIVE_WORLD') {
        $ev = '';
        foreach ($toolResults as $row) {
            if (($row['name'] ?? '') !== 'live_web.search') {
                continue;
            }
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $ev = trim((string) ($data['evidence'] ?? ''));
        }
        if ($ev !== '') {
            return mb_substr($ev, 0, 400);
        }

        return "I couldn't verify the latest information just now, so I don't want to give you a stale answer. Ask me again in a moment — or tell me how I can help with {$brand}.";
    }
    if ($kind === 'CORRECTION' && $answer !== '' && $answer !== 'the customer\'s latest message') {
        return "You're right — I missed that. " . mb_substr($answer, 0, 180);
    }
    if ($kind === 'BOOKING') {
        $slots = '';
        foreach ($toolResults as $row) {
            if (($row['name'] ?? '') === 'booking.offer') {
                $slots = trim((string) ($row['data'] ?? ''));
            }
        }
        if ($slots !== '') {
            return $slots;
        }

        return "We don't take appointments in this chat. I can still help with {$brand} — what do you need?";
    }
    if ($kind === 'MEDIA') {
        $text = (string) ($turnCtx['text'] ?? '');
        if (str_contains(mb_strtolower($text), 'analysis unavailable')) {
            return "I can see you sent a photo, but I couldn't read it clearly yet. Tell me what you want me to look at.";
        }

        return "I've got the photo. " . mb_substr($text, 0, 200);
    }
    if ($kind === 'GREETING') {
        return "Hey — I'm {$rep} at {$brand}. How's it going?";
    }

    if ($answer !== '' && $answer !== 'the customer\'s latest message' && mb_strlen($answer) < 160 && !str_contains($answer, 'referring to')) {
        return $answer;
    }

    return "Got you. I'm listening — what's on your mind?";
}

/**
 * @param list<array<string, mixed>> $toolResults
 */
function agent_core_tool_evidence_text(array $toolResults): string
{
    $lines = [];
    foreach ($toolResults as $row) {
        $name = (string) ($row['name'] ?? '');
        $data = $row['data'] ?? null;
        if ($name === 'catalog.search' && is_array($data)) {
            foreach (array_slice($data, 0, 3) as $hit) {
                $p = is_array($hit['product'] ?? null) ? $hit['product'] : [];
                $n = trim((string) ($p['name'] ?? ''));
                if ($n !== '') {
                    $lines[] = 'product: ' . $n;
                }
            }
        } elseif (is_string($data) && $data !== '') {
            $lines[] = $name . ': ' . mb_substr($data, 0, 400);
        } elseif (is_array($data) && isset($data['evidence'])) {
            $lines[] = $name . ': ' . mb_substr((string) $data['evidence'], 0, 400);
        }
    }

    return implode("\n", $lines);
}
