<?php
/**
 * One Knowledge Pack — build_runtime_bot_prompt() once. Do not re-append doctrine.
 *
 * @return array{prompt: string, capabilities: list<string>, brand: string, rep: string}
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $bot
 * @return array<string, mixed>
 */
function agent_core_knowledge_pack(array $bot): array
{
    require_once dirname(__DIR__) . '/platform-training.php';
    require_once dirname(__DIR__) . '/bot-knowledge.php';

    $brand = function_exists('get_bot_brand_label')
        ? get_bot_brand_label($bot)
        : trim((string) ($bot['company_name'] ?? $bot['name'] ?? ''));
    if ($brand === '') {
        $brand = 'this business';
    }
    $prompt = build_runtime_bot_prompt($bot, $brand);
    $profile = function_exists('agent_core_business_profile')
        ? agent_core_business_profile($bot)
        : ['capabilities' => [], 'rep' => '', 'brand' => $brand];

    $qual = '';
    try {
        require_once dirname(__DIR__) . '/qualification-flow.php';
        $loaded = qualification_load_for_bot($bot);
        $qs = $loaded['questions'] ?? [];
        if (is_array($qs) && $qs !== []) {
            $bits = [];
            foreach (array_slice($qs, 0, 8) as $q) {
                $t = is_array($q) ? (string) ($q['text'] ?? '') : (string) $q;
                if ($t !== '') {
                    $bits[] = $t;
                }
            }
            if ($bits !== []) {
                $qual = implode(' | ', $bits);
            }
        }
    } catch (Throwable $e) {
        $qual = '';
    }

    return [
        'prompt'         => $prompt,
        'capabilities'   => $profile['capabilities'] ?? [],
        'brand'          => $brand,
        'rep'            => (string) ($profile['rep'] ?? ''),
        'qualify_read'   => $qual,
    ];
}
