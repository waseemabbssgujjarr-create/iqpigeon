<?php
/**
 * Bot knowledge document — one paste, AI extracts script settings.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/ai-personality.php';

/**
 * Ensure bots table has bot_knowledge column.
 */
function ensure_bots_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $row = db_fetch(
        'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'bots\' AND COLUMN_NAME = \'bot_knowledge\'',
        's',
        [DB_NAME]
    );
    if ((int) ($row['cnt'] ?? 0) === 0) {
        try {
            db_connect()->query('ALTER TABLE bots ADD COLUMN bot_knowledge LONGTEXT NULL AFTER openai_system_prompt');
        } catch (Throwable $e) {
            error_log('ensure_bots_schema bot_knowledge: ' . $e->getMessage());
        }
    }

    ensure_bot_training_schema();
    $done = true;
}

/**
 * Client training fields — rep name, business model, website (admin controls persona/tone).
 */
function ensure_bot_training_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'rep_name'              => 'VARCHAR(120) NULL AFTER persona_description',
        'business_model'        => 'TEXT NULL AFTER bot_knowledge',
        'website_url'           => 'VARCHAR(500) NULL AFTER business_model',
        'knowledge_updated_at'  => 'DATETIME NULL AFTER website_url',
        'industry_key'          => 'VARCHAR(64) NULL AFTER knowledge_updated_at',
        'training_meta'         => 'LONGTEXT NULL AFTER industry_key',
        'rep_persona'           => 'MEDIUMTEXT NULL AFTER rep_name',
    ];

    foreach ($columns as $col => $definition) {
        $row = db_fetch(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'bots\' AND COLUMN_NAME = ?',
            'ss',
            [DB_NAME, $col]
        );
        if ((int) ($row['cnt'] ?? 0) === 0) {
            try {
                db_connect()->query("ALTER TABLE bots ADD COLUMN {$col} {$definition}");
            } catch (Throwable $e) {
                error_log("ensure_bot_training_schema {$col}: " . $e->getMessage());
            }
        }
    }

    try {
        $personaCol = db_fetch(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'bots\' AND COLUMN_NAME = \'rep_persona\'',
            's',
            [DB_NAME]
        );
        $personaType = strtolower((string) ($personaCol['DATA_TYPE'] ?? ''));
        if ($personaType === 'text' || $personaType === 'tinytext' || $personaType === 'varchar') {
            db_connect()->query('ALTER TABLE bots MODIFY COLUMN rep_persona MEDIUMTEXT NULL');
        }
    } catch (Throwable $e) {
        error_log('ensure_bot_training_schema rep_persona size: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Structured per-bot settings (operating hours, closed-store message, trigger
 * words) stored as JSON in the existing `training_meta` column — avoids a
 * separate migration while giving each training-page tab real persistence.
 *
 * @return array{
 *   operating_hours: array{always_open: bool, days: array<string, array{open: string, close: string, enabled: bool}>},
 *   closed_behavior: string,
 *   trigger_words: list<array{word: string, intent: string, is_active: bool}>
 * }
 */
function bot_training_meta(array $bot): array
{
    $defaults = [
        'operating_hours' => ['always_open' => true, 'days' => []],
        'closed_behavior' => '',
        'trigger_words'   => [],
        'menu_cards'      => [],
        'conversation'    => [
            'tone'             => '',
            'formality'        => '',
            'language'         => '',
            'response_length'  => '',
            'emoji'            => '',
            'personal_touches' => false,
        ],
    ];
    $raw = trim((string) ($bot['training_meta'] ?? ''));
    if ($raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $merged = array_merge($defaults, array_intersect_key($decoded, $defaults));
    if (isset($decoded['conversation']) && is_array($decoded['conversation'])) {
        $merged['conversation'] = array_merge(
            $defaults['conversation'],
            array_intersect_key($decoded['conversation'], $defaults['conversation'])
        );
    }

    return $merged;
}

/**
 * Merge a partial patch into the bot's training_meta JSON and persist it.
 *
 * @param array<string, mixed> $patch
 */
function bot_training_meta_update(int $botId, int $userId, array $patch): bool
{
    $bot = db_fetch('SELECT training_meta FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$bot) {
        return false;
    }
    $current = bot_training_meta($bot);
    $merged  = array_merge($current, array_intersect_key($patch, $current));
    db_execute('UPDATE bots SET training_meta = ? WHERE id = ? AND user_id = ?', 'sii', [
        json_encode($merged, JSON_UNESCAPED_UNICODE),
        $botId,
        $userId,
    ]);

    return true;
}

/**
 * @param array<int, array<string, mixed>> $products
 * @return array<string, array<int, array<string, mixed>>>
 */
function training_products_by_category(array $products): array
{
    $groups = [];
    foreach ($products as $product) {
        $cat = trim((string) ($product['category'] ?? ''));
        if ($cat === '') {
            $cat = 'General';
        }
        $groups[$cat][] = $product;
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    return $groups;
}

/**
 * Pick top active products for a menu card (sort_order, then name).
 *
 * @param array<int, array<string, mixed>> $products
 * @return list<int>
 */
function training_suggest_menu_product_ids(array $products, int $max = 6): array
{
    $active = array_values(array_filter($products, static fn (array $p): bool => !empty($p['is_active'])));
    usort($active, static function (array $a, array $b): int {
        $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($order !== 0) {
            return $order;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return array_values(array_map(
        static fn (array $p): int => (int) $p['id'],
        array_slice($active, 0, max(1, min($max, 30)))
    ));
}

/**
 * Build default menu cards — one per category with suggested products.
 *
 * @param array<string, array<int, array<string, mixed>>> $byCategory
 * @return list<array{id: string, title: string, category: string, product_ids: list<int>}>
 */
function training_auto_menu_cards(array $byCategory, int $perCard = 6): array
{
    $cards = [];
    foreach ($byCategory as $category => $items) {
        $ids = training_suggest_menu_product_ids($items, $perCard);
        if ($ids === []) {
            continue;
        }
        $cards[] = [
            'id'          => 'auto-' . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($category)),
            'title'       => $category,
            'category'    => $category,
            'product_ids' => $ids,
        ];
    }

    return $cards;
}

/** Natural-language summary of operating hours, for AI prompt context. */
function bot_operating_hours_prompt_block(array $meta): string
{
    $hours = $meta['operating_hours'] ?? ['always_open' => true, 'days' => []];
    if (!empty($hours['always_open']) || empty($hours['days'])) {
        if (!empty($hours['always_open'])) {
            return "───── OPERATING HOURS ─────\nThis business is always open. Do not tell customers the business is currently closed.";
        }

        return '';
    }

    $lines = [];
    foreach ($hours['days'] as $day => $d) {
        if (empty($d['enabled'])) {
            continue;
        }
        $lines[] = (string) $day . ': ' . (string) ($d['open'] ?? '') . '–' . (string) ($d['close'] ?? '');
    }
    if ($lines === []) {
        return '';
    }

    $closedNote = trim((string) ($meta['closed_behavior'] ?? ''));
    $block = "───── OPERATING HOURS ─────\n" . implode("\n", $lines)
        . "\nIf the customer messages outside these hours, let them know naturally that the team is currently closed and will reply when open.";
    if ($closedNote !== '') {
        $block .= "\nWhen closed, use this note as guidance for the tone/details: " . $closedNote;
    }

    return $block;
}

/** Trigger-word hints for the AI prompt — nudges intent recognition, never a hard rule. */
function bot_trigger_words_prompt_block(array $meta): string
{
    $words = array_values(array_filter((array) ($meta['trigger_words'] ?? []), static fn ($w) => !empty($w['is_active'])));
    if ($words === []) {
        return '';
    }

    $lines = [];
    foreach (array_slice($words, 0, 20) as $w) {
        $word = trim((string) ($w['word'] ?? ''));
        $intent = trim((string) ($w['intent'] ?? ''));
        if ($word === '') {
            continue;
        }
        $lines[] = '"' . $word . '"' . ($intent !== '' ? ' → ' . $intent : '');
    }
    if ($lines === []) {
        return '';
    }

    return "───── TRIGGER WORD HINTS (owner-defined intents; use judgment, never quote this list) ─────\n" . implode("\n", $lines);
}

/** Normalize a website URL to lowercase host (empty if invalid). */
function bot_knowledge_host_from_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = parse_url($url, PHP_URL_HOST);

    return is_string($host) ? strtolower($host) : '';
}

/** Remove auto-imported website blocks from a knowledge document. */
function bot_knowledge_strip_website_blocks(string $knowledge): string
{
    $knowledge = trim($knowledge);
    if ($knowledge === '') {
        return '';
    }

    $clean = preg_replace('/\n\n── Website: .*?(?=\n\n── Website:|$)/s', '', $knowledge) ?? $knowledge;
    $clean = preg_replace('/^── Website: .*?(?=\n\n── Website:|$)/s', '', $clean) ?? $clean;

    return trim($clean);
}

/** Remove Human Trainer append-only sections (stored separately in training_meta). */
function bot_knowledge_strip_trainer_sections(string $knowledge): string
{
    $knowledge = trim($knowledge);
    if ($knowledge === '') {
        return '';
    }

    $patterns = [
        '/\n\n───── FAQ \(Human Trainer\) ─────.*?(?=\n\n─────|$)/s',
        '/\n\n───── OBJECTION HANDLING ─────.*?(?=\n\n─────|$)/s',
        '/\n\n───── NEVER SAY ─────.*$/s',
        '/^───── FAQ \(Human Trainer\) ─────.*?(?=\n\n─────|$)/s',
        '/^───── OBJECTION HANDLING ─────.*?(?=\n\n─────|$)/s',
        '/^───── NEVER SAY ─────.*$/s',
    ];

    foreach ($patterns as $pattern) {
        $knowledge = preg_replace($pattern, '', $knowledge) ?? $knowledge;
    }

    return trim($knowledge);
}

/** Remove nested section headers pasted into knowledge. */
function bot_knowledge_strip_nested_headers(string $knowledge): string
{
    $knowledge = preg_replace('/───── (?:WHAT WE OFFER|COMPANY KNOWLEDGE(?: BASE)?) ─────\s*/u', '', $knowledge) ?? $knowledge;
    $knowledge = preg_replace('/^Industry: .+\n/m', '', $knowledge) ?? $knowledge;
    $knowledge = preg_replace('/^Rep name: .+\n/m', '', $knowledge) ?? $knowledge;
    $knowledge = preg_replace('/^Brand: .+\n/m', '', $knowledge) ?? $knowledge;

    return trim($knowledge);
}

/** Remove repeated lines (duplicate prices, package names, etc.). */
function bot_knowledge_dedupe_lines(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $seen = [];
    $out = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $out[] = '';
            continue;
        }

        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
        if (mb_strlen($key) >= 10 && isset($seen[$key])) {
            continue;
        }
        if (mb_strlen($key) >= 10) {
            $seen[$key] = true;
        }
        $out[] = $trimmed;
    }

    return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $out)) ?? implode("\n", $out));
}

/** Drop paragraphs that largely duplicate business_model / offer text. */
function bot_knowledge_remove_duplicate_of(string $knowledge, string $reference): string
{
    $reference = trim($reference);
    $knowledge = trim($knowledge);
    if ($knowledge === '' || $reference === '' || mb_strlen($reference) < 120) {
        return $knowledge;
    }

    $refKey = mb_strtolower(preg_replace('/\s+/u', ' ', $reference) ?? $reference);
    $parts = preg_split('/\n{2,}/', $knowledge) ?: [];
    $kept = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $partKey = mb_strtolower(preg_replace('/\s+/u', ' ', $part) ?? $part);
        if ($partKey === $refKey) {
            continue;
        }

        if (mb_strlen($partKey) > 120 && str_contains($refKey, mb_substr($partKey, 0, min(400, mb_strlen($partKey))))) {
            continue;
        }

        if (mb_strlen($partKey) > 120 && str_contains($partKey, mb_substr($refKey, 0, min(400, mb_strlen($refKey))))) {
            continue;
        }

        $kept[] = $part;
    }

    return trim(implode("\n\n", $kept));
}

/** Normalize knowledge before save or compose — no trainer junk, dupes, or merged words. */
function bot_knowledge_normalize_for_storage(string $knowledge, string $businessModel = ''): string
{
    $knowledge = bot_knowledge_strip_trainer_sections($knowledge);
    $knowledge = bot_knowledge_strip_nested_headers($knowledge);
    if ($businessModel !== '') {
        $knowledge = bot_knowledge_remove_duplicate_of($knowledge, $businessModel);
    }
    $knowledge = bot_knowledge_dedupe_lines($knowledge);

    return trim(preg_replace('/\n{3,}/', "\n\n", $knowledge) ?? $knowledge);
}

/** Website import blocks only (for compose / storage). */
function bot_knowledge_extract_website_blocks(string $knowledge): string
{
    $knowledge = trim($knowledge);
    if ($knowledge === '') {
        return '';
    }

    if (!preg_match_all('/(?:^|\n\n)(── Website: .*?(?=\n\n── Website:|$))/s', $knowledge, $matches)) {
        return '';
    }

    $blocks = array_values(array_unique(array_map('trim', $matches[1] ?? [])));

    return trim(implode("\n\n", $blocks));
}

/** Manual notes excluding website blocks, trainer sections, and offer duplicates. */
function bot_knowledge_manual_notes(string $knowledge, string $businessModel = ''): string
{
    $notes = bot_knowledge_strip_website_blocks($knowledge);
    $notes = bot_knowledge_normalize_for_storage($notes, $businessModel);

    return $notes;
}

/** Extract FAQ / objection / never-say blocks for storage. */
function bot_knowledge_extract_trainer_sections(string $knowledge): string
{
    $parts = [];
    $patterns = [
        '/(?:^|\n\n)(───── FAQ \(Human Trainer\) ─────.*?)(?=\n\n─────|$)/s',
        '/(?:^|\n\n)(───── OBJECTION HANDLING ─────.*?)(?=\n\n─────|$)/s',
        '/(?:^|\n\n)(───── NEVER SAY ─────.*?)$/s',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $knowledge, $m)) {
            $parts[] = trim($m[1]);
        }
    }

    return trim(implode("\n\n", $parts));
}

/** Mark bot knowledge as updated (used to warn AI about stale chat history). */
function bot_touch_knowledge_updated(int $botId): void
{
    ensure_bot_training_schema();
    db_execute(
        'UPDATE bots SET knowledge_updated_at = NOW() WHERE id = ?',
        'i',
        [$botId]
    );
}

/**
 * After company knowledge changes — bump version. Do not wipe live chats.
 * Wiping history made every follow-up look like a first hello (canned intro loop).
 */
function bot_refresh_after_knowledge_change(int $botId): void
{
    if ($botId <= 0) {
        return;
    }
    bot_touch_knowledge_updated($botId);
}

/**
 * Kept for compatibility. Never delete WhatsApp/widget history on knowledge refresh.
 */
function bot_prune_lead_conversations_before_knowledge(int $leadId, array $bot): void
{
    unset($leadId, $bot);
}

/** Wipe stored chat history for every lead on a bot (after business/knowledge change). */
function bot_clear_conversations_for_bot(int $botId): void
{
    if ($botId <= 0) {
        return;
    }

    try {
        db_execute(
            'DELETE c FROM conversations c
             INNER JOIN leads l ON l.id = c.lead_id
             WHERE l.bot_id = ?',
            'i',
            [$botId]
        );
    } catch (Throwable $e) {
        error_log('bot_clear_conversations_for_bot: ' . $e->getMessage());
    }
}

/**
 * Parse JSON from AI output (handles markdown fences and extra prose).
 *
 * @return array<string, mixed>|null
 */
function bot_knowledge_extract_json(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $raw) ?? $raw;
    $stripped = trim($stripped);

    $decoded = json_decode($stripped, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($stripped, '{');
    $end = strrpos($stripped, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $candidate = substr($stripped, $start, $end - $start + 1);
        $candidate = preg_replace('/,\s*([}\]])/', '$1', $candidate) ?? $candidate;
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/**
 * Example document shown in the UI.
 */
function bot_knowledge_placeholder(): string
{
    if (function_exists('get_knowledge_doc_placeholder')) {
        require_once __DIR__ . '/platform-training.php';
        return get_knowledge_doc_placeholder();
    }
    $custom = trim(get_setting('knowledge_doc_placeholder', '') ?? '');
    if ($custom !== '') {
        return $custom;
    }
    return 'Paste your company knowledge — pricing, services, FAQs, who qualifies, booking link, and tone. Then click Build.';
}

/**
 * Use AI to extract script fields from a knowledge document.
 *
 * @return array{success: bool, data?: array<string, mixed>, error?: string}
 */
function parse_bot_knowledge_document(string $document, string $companyName, string $botName = 'Sales Bot'): array
{
    $document = trim($document);
    if ($document === '') {
        return ['success' => false, 'error' => 'Paste your business knowledge first.'];
    }

    if (mb_strlen($document) > 20000) {
        $document = mb_substr($document, 0, 20000);
    }

    $system = <<<'PROMPT'
You extract sales bot configuration from a business knowledge document. Return ONLY valid JSON, no markdown fences.

Schema:
{
  "qualifying_questions": [{"text": "question to ask lead", "type": "Budget|Timeline|Pain Point|Intent|Custom"}],
  "qualify_trigger": "when lead counts as qualified (one sentence)",
  "qualify_message": "message before sharing booking link",
  "disqualify_message": "polite message when not a fit",
  "calendly_link": "URL or empty string",
  "persona_summary": "1-2 sentences how the rep should sound",
  "knowledge_summary": "condensed facts the bot must know (services, pricing, policies) — max 800 words",
  "business_mode": "ecommerce|services|saas|mixed",
  "conversion_goal": "order_placed|call_booked|trial_started"
}

Rules:
- Infer 3–6 qualifying questions from the document if not listed explicitly.
- Extract calendly/booking URL if present; else empty string.
- If document lacks detail for a field, leave it empty or minimal — never invent services, prices, or policies.
- knowledge_summary must capture pricing, services, and FAQs from the document.
- business_mode: ecommerce if they sell physical/digital products with orders; services if consulting/agency; saas if subscription software; mixed if both.
- conversion_goal: order_placed for shops, call_booked for services, trial_started for SaaS.
PROMPT;

    $user = "Company: {$companyName}\nBot name: {$botName}\n\nKnowledge document:\n{$document}";

    $result = ai_chat(
        [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        ['temperature' => 0.3, 'max_tokens' => 2000]
    );

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'] ?? 'AI could not read the document.'];
    }

    $raw = trim($result['content'] ?? '');
    $data = bot_knowledge_extract_json($raw);

    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Could not parse AI response. Try again, shorten the document, or paste plain text without special formatting.'];
    }

    $questions = [];
    foreach ($data['qualifying_questions'] ?? [] as $q) {
        if (!is_array($q)) {
            continue;
        }
        $text = trim($q['text'] ?? '');
        if ($text !== '') {
            $type = $q['type'] ?? 'Custom';
            if (!in_array($type, ['Budget', 'Timeline', 'Pain Point', 'Intent', 'Custom'], true)) {
                $type = 'Custom';
            }
            $questions[] = ['text' => $text, 'type' => $type];
        }
    }

    if ($questions === []) {
        $questions = [
            ['text' => 'What budget range are you working with?', 'type' => 'Budget'],
            ['text' => 'What timeline are you looking at?', 'type' => 'Timeline'],
            ['text' => 'What problem are you trying to solve?', 'type' => 'Pain Point'],
        ];
    }

    require_once __DIR__ . '/lead-lifecycle.php';
    $inferredMode = lifecycle_infer_business_mode($document);
    $mode = strtolower(trim((string) ($data['business_mode'] ?? $inferredMode)));
    if (!array_key_exists($mode, bot_business_modes())) {
        $mode = $inferredMode;
    }
    $goal = strtolower(trim((string) ($data['conversion_goal'] ?? lifecycle_conversion_goal_for_mode($mode))));
    if (!array_key_exists($goal, bot_conversion_goals())) {
        $goal = lifecycle_conversion_goal_for_mode($mode);
    }

    $qualifyTrigger = trim($data['qualify_trigger'] ?? '');
    if ($qualifyTrigger === '') {
        $qualifyTrigger = match ($mode) {
            'ecommerce' => 'Customer confirms product choice, delivery address, and COD payment',
            'saas'      => 'Clear need, budget/timeline fit, and agrees to demo or trial',
            'services'  => 'Budget and timeline fit with clear service need',
            default     => 'Shows clear budget and timeline fit',
        };
    }

    return [
        'success' => true,
        'data'    => [
            'qualifying_questions' => $questions,
            'qualify_trigger'      => $qualifyTrigger,
            'qualify_message'      => trim($data['qualify_message'] ?? 'Great — you look like a strong fit! Here is how to book a call:'),
            'disqualify_message'   => trim($data['disqualify_message'] ?? 'Thanks for chatting — feel free to reach out if things change.'),
            'calendly_link'        => trim($data['calendly_link'] ?? ''),
            'persona_summary'      => trim($data['persona_summary'] ?? ''),
            'knowledge_summary'    => trim($data['knowledge_summary'] ?? $document),
            'business_mode'        => $mode,
            'conversion_goal'      => $goal,
        ],
    ];
}

/**
 * Apply parsed knowledge + document to a bot row.
 *
 * @param array<string, mixed> $parsed From parse_bot_knowledge_document data
 */
function apply_bot_knowledge(int $botId, int $userId, string $document, array $parsed, string $companyName, string $tone = 'Professional'): bool
{
    ensure_bots_schema();
    require_once __DIR__ . '/lead-lifecycle.php';
    ensure_lead_lifecycle_schema();

    $bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$bot) {
        return false;
    }

    $keepQualify = qualification_flow_load() && qualification_is_custom($bot);
    $questions = $parsed['qualifying_questions'] ?? [];
    $businessModel = trim((string) ($bot['business_model'] ?? ''));
    $document = bot_knowledge_normalize_for_storage($document, $businessModel);

    $systemPrompt = build_bot_knowledge_prompt_body($document, $parsed, $bot, $companyName);

    if ($keepQualify) {
        db_execute(
            'UPDATE bots SET calendly_link = ?, openai_system_prompt = ?, bot_knowledge = ?
             WHERE id = ? AND user_id = ?',
            'sssii',
            [
                $parsed['calendly_link'] ?? get_bot_calendly_link($bot),
                $systemPrompt,
                $document,
                $botId,
                $userId,
            ]
        );
    } else {
        db_execute(
            'UPDATE bots SET qualifying_questions = ?,
             qualify_trigger = ?, qualify_message = ?, disqualify_message = ?,
             calendly_link = ?, openai_system_prompt = ?, bot_knowledge = ?,
             business_mode = ?, conversion_goal = ?
             WHERE id = ? AND user_id = ?',
            'sssssssssii',
            [
                json_encode($questions),
                $parsed['qualify_trigger'] ?? '',
                $parsed['qualify_message'] ?? '',
                $parsed['disqualify_message'] ?? '',
                $parsed['calendly_link'] ?? get_bot_calendly_link($bot),
                $systemPrompt,
                $document,
                $parsed['business_mode'] ?? lifecycle_infer_business_mode($document),
                $parsed['conversion_goal'] ?? lifecycle_conversion_goal_for_mode($parsed['business_mode'] ?? 'mixed'),
                $botId,
                $userId,
            ]
        );
    }

    return true;
}

/**
 * Business-specific prompt body (stored on bot — no global personality; added once at runtime).
 *
 * @param array<string, mixed> $parsed
 * @param array<string, mixed> $bot
 */
function build_bot_knowledge_prompt_body(
    string $document,
    array $parsed,
    array $bot,
    string $companyName,
    bool $skipIdentityBlock = false
): string {
    $summary = trim($parsed['knowledge_summary'] ?? '');
    if ($summary === '') {
        $summary = mb_substr(trim($document), 0, 8000);
    }

    $questions = $parsed['qualifying_questions'] ?? [];
    if ($questions === [] && qualification_flow_load()) {
        $questions = qualification_effective_questions($bot);
    }
    $questionLines = '';
    foreach ($questions as $i => $q) {
        $text = is_array($q) ? ($q['text'] ?? '') : (string) $q;
        if ($text !== '') {
            $req = is_array($q) && !empty($q['required']) ? ' (required)' : '';
            $questionLines .= ($i + 1) . '. ' . $text . $req . "\n";
        }
    }

    $block = build_business_knowledge_block([
        'business_name' => $companyName,
        'text'          => $summary,
    ]);

    $rules = '';
    if (!$skipIdentityBlock) {
        $rules .= "\n\n───── YOUR IDENTITY ─────\n";
        $rules .= 'Your name is ' . get_bot_rep_name($bot) . '. You represent ' . trim($bot['name'] ?? $companyName) . ' (' . $companyName . "). ";
        $rules .= "Never call yourself the business name — you are a person on the team.\n";
        if (!empty($bot['persona_description'])) {
            $rules .= trim(preg_replace('/ Tone: .+$/', '', $bot['persona_description'])) . "\n";
        }
    }

    $rules .= "\n───── COMPANY KNOWLEDGE BASE (owner document — final authority) ─────\n";
    $rules .= "Full owner document is below. Use it for all business facts, pricing, and policies.\n\n";
    $rules .= trim($document) . "\n";
    $rules .= "\n───── SALES SCRIPT (extracted from document) ─────\n";
    $rules .= "Qualify when: " . ($parsed['qualify_trigger'] ?? $bot['qualify_trigger'] ?? '') . "\n";
    $rules .= "Ask these questions one at a time:\n" . ($questionLines !== '' ? $questionLines : "Convert using this business's conversion goal — do not invent budget/timeline questions.\n");
    $rules .= "When qualified, include [BOOK_CALL] in the same message and use: " . ($parsed['qualify_message'] ?? $bot['qualify_message'] ?? '') . "\n";
    $rules .= "Calendly/booking: " . get_bot_calendly_link(array_merge($bot, [
        'calendly_link' => $parsed['calendly_link'] ?? $bot['calendly_link'] ?? '',
    ])) . "\n";
    $rules .= "Use [DISQUALIFY] only after 4+ exchanges when they firmly are not a fit.\n";
    $rules .= "Disqualify message: " . ($parsed['disqualify_message'] ?? $bot['disqualify_message'] ?? '') . "\n";
    $rules .= "\nAnswer the customer's latest message first, then guide using this script.";

    return trim($block . $rules);
}

/** @deprecated Alias — use build_bot_knowledge_prompt_body() */
function build_bot_knowledge_system_prompt(string $document, array $parsed, array $bot, string $companyName): string
{
    return build_bot_knowledge_prompt_body($document, $parsed, $bot, $companyName);
}

/**
 * Whether bot uses knowledge-document mode.
 *
 * @param array<string, mixed> $bot
 */
function bot_has_knowledge_document(array $bot): bool
{
    return trim($bot['bot_knowledge'] ?? '') !== '';
}

/**
 * Use posted value when non-empty; otherwise keep existing DB value (never wipe on partial save).
 */
function bot_persist_field(string $posted, string $existing): string
{
    $posted = trim($posted);
    return $posted !== '' ? $posted : trim($existing);
}

/**
 * Owner profile fields used for venue address vs the rep's personal city.
 *
 * @param array<string, mixed> $bot
 * @return array{address: string, industry: string, bio: string, company_name: string}
 */
function bot_owner_profile_fields(array $bot): array
{
    $out = [
        'address'      => trim((string) ($bot['address'] ?? '')),
        'industry'     => trim((string) ($bot['owner_industry'] ?? '')),
        'bio'          => trim((string) ($bot['bio'] ?? '')),
        'company_name' => trim((string) ($bot['company_name'] ?? '')),
    ];
    if ($out['address'] !== '' && $out['company_name'] !== '') {
        return $out;
    }
    $uid = (int) ($bot['user_id'] ?? 0);
    if ($uid <= 0) {
        return $out;
    }
    try {
        $u = db_fetch('SELECT address, industry, bio, company_name FROM users WHERE id = ?', 'i', [$uid]);
    } catch (Throwable $e) {
        return $out;
    }
    if (!is_array($u)) {
        return $out;
    }
    if ($out['address'] === '') {
        $out['address'] = trim((string) ($u['address'] ?? ''));
    }
    if ($out['industry'] === '') {
        $out['industry'] = trim((string) ($u['industry'] ?? ''));
    }
    if ($out['bio'] === '') {
        $out['bio'] = trim((string) ($u['bio'] ?? ''));
    }
    if ($out['company_name'] === '') {
        $out['company_name'] = trim((string) ($u['company_name'] ?? ''));
    }

    return $out;
}

function bot_extract_city(string $address): string
{
    $address = trim($address);
    if ($address === '') {
        return '';
    }
    if (preg_match(
        '/\b(Lahore|Karachi|Islamabad|Rawalpindi|Multan|Faisalabad|Peshawar|Quetta|London|Dubai|Riyadh)\b/u',
        $address,
        $m
    )) {
        return (string) $m[1];
    }
    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn ($p) => $p !== ''));
    if ($parts === []) {
        return '';
    }
    $last = $parts[count($parts) - 1];
    if (preg_match('/\b(pakistan|india|uae|uk|usa|ksa)\b/iu', $last) && count($parts) >= 2) {
        $last = $parts[count($parts) - 2];
    }

    return mb_substr($last, 0, 40);
}

/**
 * True only when THIS bot actually sells catalog items (food, retail SKUs).
 * Coaching, freelance, education, and similar must never fall through to a restaurant menu.
 *
 * @param array<string, mixed> $bot
 */
function bot_uses_shop_catalog(array $bot): bool
{
    $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($bot['industry_key'] ?? '')))) ?: '';
    if (in_array($key, ['freelancer', 'education', 'services', 'saas', 'health', 'realestate', 'travel'], true)) {
        return false;
    }
    if (in_array($key, ['restaurant', 'ecommerce'], true)) {
        return true;
    }
    $mode = mb_strtolower(trim((string) ($bot['business_mode'] ?? '')));
    if (in_array($mode, ['restaurant', 'ecommerce'], true)) {
        return true;
    }
    if ($key === '' && function_exists('catalog_bot_is_restaurant')) {
        $botId = (int) ($bot['id'] ?? 0);
        if ($botId > 0 && catalog_bot_is_restaurant($botId)) {
            return true;
        }
    }

    return in_array($key, ['automotive', 'b2b', 'local'], true)
        && (int) ($bot['id'] ?? 0) > 0
        && function_exists('catalog_bot_has_products')
        && catalog_bot_has_products((int) $bot['id']);
}

/**
 * Trim text to a maximum number of whitespace-separated words.
 */
function bot_limit_words(string $text, int $maxWords): string
{
    $text = trim($text);
    if ($text === '' || $maxWords < 1) {
        return '';
    }
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words) || count($words) <= $maxWords) {
        return $text;
    }

    return implode(' ', array_slice($words, 0, $maxWords));
}

/**
 * Rep personality without trailing " Tone: …" suffix.
 */
function bot_rep_persona_plain(array $bot): string
{
    return trim(preg_replace('/ Tone: .+$/', '', (string) ($bot['persona_description'] ?? '')));
}

/**
 * Tone saved on persona_description (Professional, Friendly, Casual).
 */
function bot_rep_tone(array $bot): string
{
    if (preg_match('/ Tone: (Professional|Friendly|Casual)$/', (string) ($bot['persona_description'] ?? ''), $m)) {
        return $m[1];
    }
    return 'Professional';
}

function bot_format_persona_with_tone(string $persona, string $tone): string
{
    $persona = trim(preg_replace('/ Tone: .+$/', '', $persona));
    if ($persona === '') {
        return '';
    }
    $tone = in_array($tone, ['Professional', 'Friendly', 'Casual'], true) ? $tone : 'Professional';
    return $persona . ' Tone: ' . $tone;
}

/**
 * Build live chat system prompt for knowledge-based bots.
 *
 * @param array<string, mixed> $bot
 */
function build_live_bot_system_prompt(array $bot, string $companyName): string
{
    require_once __DIR__ . '/platform-training.php';
    return build_runtime_bot_prompt($bot, $companyName);
}

/**
 * Study-abroad consultancy — only from explicit business model.
 *
 * @param array<string, mixed> $bot
 */
function bot_business_is_study_abroad(array $bot): bool
{
    $model = mb_strtolower(trim((string) ($bot['business_model'] ?? '')));

    return preg_match(
        '/\b(study abroad|immigration consult|visa consult|university placement|education consult|study visa)\b/u',
        $model
    ) === 1;
}

/**
 * SaaS / human sales-agent platform (e.g. IQ Pigeon).
 *
 * @param array<string, mixed> $bot
 */
function bot_business_is_saas_or_agent(array $bot): bool
{
    $blob = mb_strtolower(trim((string) ($bot['business_model'] ?? '') . ' ' . (string) ($bot['name'] ?? '')));

    return preg_match(
        '/\b(iq pigeon|sales agent|sales rep|whatsapp widget|website widget|saas|messaging platform|lead setter)\b/u',
        $blob
    ) === 1;
}

/**
 * Broad match: customer wants to know what the business offers/sells/provides.
 */
function knowledge_message_is_offer_question(string $message): bool
{
    require_once __DIR__ . '/conversation-intent.php';
    $lower = mb_strtolower(conversation_normalize_intent_text($message));
    if ($lower === '') {
        return false;
    }

    if (preg_match(
        '/\b(?:what(?:\'?s| is| are)?(?: you)?(?:'
        . '\s+(?:do you|are you|you)?\s*(?:offer(?:ing)?|provide(?:ing)?|sell(?:ing)?|have\b)'
        . '|(?:\s+you)?\s+(?:offer(?:ing)?|services?|products?)))'
        . '|what can you help(?: with| me with)?'
        . '|what do you (?:have|sell|provide|offer)\b'
        . '|tell me what (?:are you |you )?(?:offer(?:ing)?|sell(?:ing)?|provide(?:ing)?|have)\b'
        . '|tell me (?:about )?(?:your )?(?:company|business|services?|products?|offerings?)'
        . '|what you (?:offer|offering|provide|providing|sell|have)\b'
        . '|what(?:\'?s| is) available'
        . '|what are you (?:offer(?:ing)?|provid(?:e|ing)?|sell(?:ing)?)'
        . '|(?:send|share) again.*(?:offer|offering|detail)'
        . '|(?:offer|offering|services?).*(?:in detail|in details)\b/u',
        $lower
    )) {
        return true;
    }

    if (knowledge_user_wants_detailed_answer($message)) {
        return true;
    }

    $words = array_values(array_filter(preg_split('/[\s!.?,]+/u', $lower) ?: []));
    if ($words !== [] && count($words) <= 14) {
        $hasWhat = in_array('what', $words, true);
        $hasTellMe = in_array('tell', $words, true) && in_array('me', $words, true);
        foreach (['offer', 'offering', 'providing', 'provide', 'services', 'service', 'products', 'product', 'sell', 'provide'] as $w) {
            if (in_array($w, $words, true) && ($hasWhat || in_array('you', $words, true) || $hasTellMe)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Customer-facing offer answer (raw text — caller runs conversation_finalize_reply).
 *
 * @param array<string, mixed> $bot
 */
function knowledge_offer_reply_text(array $bot, string $userMessage, int $leadId = 0): string
{
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/catalog.php';

    $botId = (int) ($bot['id'] ?? 0);
    $catalogLine = bot_uses_shop_catalog($bot) ? knowledge_catalog_offer_line($bot, $botId) : '';

    $listed = knowledge_offer_list_reply($bot);
    $offer = $listed !== '' ? $listed : knowledge_short_offer_line($bot, $userMessage);
    if ($offer !== '' && knowledge_text_has_unresolved_placeholders($offer)) {
        $offer = knowledge_resolve_placeholders($offer, $bot);
    }
    if ($offer === '' || knowledge_text_has_unresolved_placeholders($offer)) {
        $offer = $catalogLine !== '' ? $catalogLine : 'Tell me what you need — I\'ll help you right away.';
    } elseif ($catalogLine !== '' && mb_strlen($offer) < 40) {
        $offer = $catalogLine;
    }

    if ($leadId > 0) {
        require_once __DIR__ . '/whatsapp-inbound.php';
        if (whatsapp_lead_has_prior_reply($leadId)) {
            return $offer;
        }
    }

    $rep = get_bot_rep_name($bot);
    $brandLabel = get_bot_brand_label($bot);

    return "I'm {$rep} from {$brandLabel}. {$offer}";
}

/**
 * Direct answer when customer asks what the business offers — always substantive, never a nudge.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_build_offer_reply(array $bot, int $leadId, string $userMessage): string
{
    return conversation_finalize_reply(
        $bot,
        $leadId,
        knowledge_offer_reply_text($bot, $userMessage, $leadId),
        $userMessage
    );
}

/**
 * Hint text for clarifying questions.
 *
 * @param array<string, mixed> $bot
 */
function bot_fallback_help_topics(array $bot, string $userMessage = ''): string
{
    $userLower = mb_strtolower(trim($userMessage));

    if (bot_business_is_study_abroad($bot)
        && preg_match('/\b(study|visa|university|abroad|scholarship|intake)\b/u', $userLower)) {
        return 'your target country, qualification, or intake timing';
    }

    if (bot_business_is_saas_or_agent($bot)) {
        return 'a sales rep on WhatsApp and your website, pricing, or setup';
    }

    $model = mb_strtolower(trim((string) ($bot['business_model'] ?? '')));
    if (preg_match('/\b(subscription|saas|software|platform)\b/u', $model)) {
        return 'plans, pricing, or getting started';
    }

    if (preg_match('/\b(ecommerce|shop|store|product|delivery|order)\b/u', $model)) {
        return 'products, pricing, delivery, or your order';
    }

    return 'what you\'re looking for';
}

/**
 * True when the question can be answered from bot_knowledge / business_model.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_question_likely_answerable(array $bot, string $userMessage): bool
{
    $lower = mb_strtolower(trim($userMessage));
    if ($lower === '') {
        return false;
    }

    if (knowledge_message_is_offer_question($userMessage)) {
        return true;
    }

    if (preg_match('/\b(need this|want this|sign up|get started|how much|pricing|how it works|interested)\b/u', $lower)
        || preg_match('/\b(boat|bot)\b/u', $lower)) {
        if (bot_business_is_saas_or_agent($bot)) {
            return true;
        }
    }

    if (bot_business_is_study_abroad($bot)
        && preg_match('/\b(study|studying|student|university|college|visa|abroad|scholarship)\b/u', $lower)) {
        return true;
    }

    $corpus = trim((string) ($bot['business_model'] ?? '') . "\n\n" . (string) ($bot['bot_knowledge'] ?? ''));
    if ($corpus === '') {
        return false;
    }

    $terms = knowledge_query_terms($userMessage);
    if ($terms === []) {
        return false;
    }

    $snippet = knowledge_find_best_snippet($corpus, $userMessage);
    if ($snippet === null) {
        return false;
    }

    return knowledge_score_chunk($snippet, $terms) >= 2;
}

/**
 * Local reply from owner knowledge when AI is unavailable.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_try_local_reply(array $bot, string $userMessage, int $leadId = 0): ?string
{
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/conversation-intent.php';

    if (conversation_is_general_chat($userMessage)) {
        return null;
    }

    $rep = get_bot_rep_name($bot);
    $brand = trim((string) ($bot['name'] ?? ''));
    $brandLabel = $brand !== '' ? $brand : 'our team';
    $lower = mb_strtolower(trim($userMessage));
    if ($lower === '') {
        return null;
    }

    if (preg_match(
        '/\b(cost|price|how much|your name|who are you|where are you|where you|what do you offer|what you offer|'
        . 'why didn\'?t you|where you were|being silent)\b/u',
        $lower
    )) {
        return null;
    }

    if (preg_match('/\b(love you|i love|like you|marry|date me)\b/u', $lower)) {
        return conversation_finalize_reply(
            $bot,
            $leadId,
            conversation_casual_redirect_reply($bot, $userMessage)
        );
    }

    if (knowledge_message_is_offer_question($userMessage)) {
        return knowledge_build_offer_reply($bot, $leadId, $userMessage);
    }

    if (preg_match('/\b(need this|want this|sign up|get started|how much|pricing|how it works|interested)\b/u', $lower)
        || preg_match('/\b(boat|bot)\b/u', $lower)) {
        if (bot_business_is_saas_or_agent($bot)) {
            return conversation_finalize_reply(
                $bot,
                $leadId,
                "I'm {$rep} — we set up a dedicated sales rep on your WhatsApp and website. What kind of business are you running?"
            );
        }
    }

    if (bot_business_is_study_abroad($bot)
        && preg_match('/\b(study|studying|student|university|college|visa|abroad|scholarship)\b/u', $lower)) {
        $country = '';
        if (preg_match('/\b(uk|united kingdom|england|usa|america|canada|australia|germany|dubai|uae)\b/u', $lower, $m)) {
            $country = knowledge_normalize_country($m[1]);
        }
        if ($country !== '') {
            return conversation_finalize_reply(
                $bot,
                $leadId,
                "Yes — we help with {$country}. What's your current qualification and target intake?"
            );
        }
    }

    $corpus = trim((string) ($bot['business_model'] ?? '') . "\n\n" . (string) ($bot['bot_knowledge'] ?? ''));
    if ($corpus === '') {
        return null;
    }

    $terms = knowledge_query_terms($userMessage);
    $snippet = knowledge_find_best_snippet($corpus, $userMessage);
    if ($snippet !== null && knowledge_score_chunk($snippet, $terms) >= 2) {
        $limit = knowledge_user_wants_detailed_answer($userMessage) ? 420 : 280;
        $snippet = knowledge_sanitize_for_customer(knowledge_trim_snippet($snippet, $limit));
        if ($snippet !== '') {
            return conversation_finalize_reply($bot, $leadId, $snippet, $userMessage);
        }
    }

    if (conversation_should_verify_with_team($bot, $userMessage)) {
        return conversation_finalize_reply(
            $bot,
            $leadId,
            conversation_verify_with_team_reply($bot, $userMessage)
        );
    }

    return null;
}

/**
 * Owner-configured first greeting, if present in training.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_configured_greeting(array $bot): string
{
    $text = (string) ($bot['bot_knowledge'] ?? '');
    if ($text === '') {
        return '';
    }
    if (!preg_match('/greet customers?(?:[^\n:]{0,80})?:\s*(.+)/iu', $text, $m)) {
        return '';
    }
    $line = trim((string) $m[1]);
    $line = trim(explode("\n", $line)[0]);
    $line = trim($line, " \t\"'");
    if (mb_strlen($line) < 12 || mb_strlen($line) > 280) {
        return '';
    }

    return $line;
}

/**
 * @param array<string, mixed> $bot
 * @return list<string>
 */
function knowledge_extract_service_lines(array $bot): array
{
    $text = trim((string) ($bot['bot_knowledge'] ?? '') . "\n" . (string) ($bot['business_model'] ?? ''));
    if ($text === '') {
        return [];
    }
    $lines = [];
    if (preg_match(
        '/list of services offered:\s*(.+)$/is',
        $text,
        $m
    )) {
        foreach (preg_split('/\r?\n/', (string) $m[1]) ?: [] as $line) {
            $line = trim((string) $line, " \t-•*");
            if ($line === '' || knowledge_text_has_unresolved_placeholders($line)) {
                continue;
            }
            if (mb_strlen($line) >= 3 && mb_strlen($line) <= 90) {
                $lines[] = $line;
            }
        }
    }
    if ($lines === [] && preg_match_all('/^[\-\*•]\s+([A-Za-z][^\n]{2,80})$/m', $text, $m)) {
        foreach ($m[1] as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^\[|http/i', $line) || knowledge_text_has_unresolved_placeholders($line)) {
                continue;
            }
            $lines[] = $line;
        }
        $lines = array_slice(array_values(array_unique($lines)), 0, 8);
    }

    return array_values(array_unique($lines));
}

/**
 * @param array<string, mixed> $bot
 */
function knowledge_extract_hourly_rate(array $bot): string
{
    $text = (string) ($bot['bot_knowledge'] ?? '') . ' ' . (string) ($bot['business_model'] ?? '');
    if (preg_match('/\$\s*(\d+(?:\.\d+)?)\s*\/\s*(?:hour|hr)\b/iu', $text, $m)) {
        return '$' . $m[1] . '/hour';
    }
    if (preg_match('/rate\s*[:\-]?\s*\$?\s*(\d+(?:\.\d+)?)\s*(?:per\s+hour|\/\s*(?:hour|hr)|an hour)/iu', $text, $m)) {
        return '$' . $m[1] . '/hour';
    }

    return '';
}

/**
 * Customer-facing services answer from THIS business's training — never another industry's catalog.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_offer_list_reply(array $bot): string
{
    $services = knowledge_extract_service_lines($bot);
    $rate = knowledge_extract_hourly_rate($bot);
    if ($services !== []) {
        $clean = [];
        foreach ($services as $svc) {
            $svc = knowledge_resolve_placeholders($svc, $bot);
            $svc = knowledge_strip_unresolved_placeholders($svc);
            if ($svc !== '') {
                $clean[] = $svc;
            }
        }
        if ($clean === []) {
            $services = [];
        } else {
            $out = "We currently offer:\n• " . implode("\n• ", $clean);
            if ($rate !== '') {
                $out .= "\n\n1:1 sessions are {$rate}.";
            }

            return $out;
        }
    }
    if ($rate !== '') {
        return '1:1 sessions are ' . $rate . '. I can also walk you through what we cover.';
    }

    return '';
}

function knowledge_price_from_training(array $bot): string
{
    $rate = knowledge_extract_hourly_rate($bot);
    if ($rate !== '') {
        return 'The 1:1 rate is ' . $rate . '.';
    }

    return '';
}

function knowledge_first_greeting(array $bot): string
{
    $g = knowledge_configured_greeting($bot);
    if ($g !== '') {
        return $g;
    }
    $rep = function_exists('get_bot_rep_name') ? get_bot_rep_name($bot) : 'I';
    $brand = function_exists('get_bot_brand_label') ? get_bot_brand_label($bot) : trim((string) ($bot['company_name'] ?? $bot['name'] ?? 'us'));

    return "Hey — I'm {$rep} at {$brand}. How can I help you today?";
}

function knowledge_short_offer_line(array $bot, string $userMessage = ''): string
{
    $listed = knowledge_offer_list_reply($bot);
    if ($listed !== '' && !preg_match('/^i can walk you through/iu', $listed)) {
        return $listed;
    }

    $detailed = knowledge_user_wants_detailed_answer($userMessage);
    $shortLimit = $detailed ? 380 : 220;
    $lineLimit = $detailed ? 420 : 280;

    $model = trim((string) ($bot['business_model'] ?? ''));
    if ($model !== '') {
        $line = knowledge_sanitize_for_customer(knowledge_trim_snippet($model, $lineLimit));
        if ($line !== '') {
            if (!$detailed && preg_match('/^(.+?[.!?])(?:\s|$)/u', $line, $m)) {
                $line = trim($m[1]);
            }

            return knowledge_resolve_placeholders($detailed ? $line : knowledge_trim_snippet($line, $shortLimit), $bot);
        }
    }

    $corpus = trim((string) ($bot['bot_knowledge'] ?? ''));
    if ($corpus === '') {
        return '';
    }

    $lines = preg_split('/\r?\n/u', $corpus) ?: [];
    $paragraphs = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || mb_strlen($line) < 15) {
            continue;
        }
        if (preg_match('/^(we |our |at )/iu', $line)) {
            $paragraphs[] = $line;
        }
    }

    if ($detailed && $paragraphs !== []) {
        $joined = knowledge_sanitize_for_customer(knowledge_trim_snippet(implode(' ', array_slice($paragraphs, 0, 3)), 480));

        return knowledge_resolve_placeholders($joined, $bot);
    }

    foreach ($paragraphs as $line) {
        $out = knowledge_sanitize_for_customer(knowledge_trim_snippet($line, $lineLimit));
        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $out, $m)) {
            $out = trim($m[1]);
        }

        return knowledge_resolve_placeholders($out, $bot);
    }

    $fallback = knowledge_sanitize_for_customer(knowledge_trim_snippet($corpus, $lineLimit));
    if (!$detailed && preg_match('/^(.+?[.!?])(?:\s|$)/u', $fallback, $m)) {
        $fallback = trim($m[1]);
    }

    return knowledge_resolve_placeholders($fallback, $bot);
}

function knowledge_text_has_unresolved_placeholders(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    return (bool) preg_match('/\[[^\]\n]{1,64}\]/', $text)
        || (bool) preg_match('/\{\{[^}\n]{1,64}\}\}/', $text)
        || (bool) preg_match('/\$\{[^}\n]{1,64}\}/', $text);
}

function knowledge_strip_unresolved_placeholders(string $text): string
{
    if (function_exists('conversation_strip_unresolved_placeholders')) {
        return conversation_strip_unresolved_placeholders($text);
    }
    $text = preg_replace('/\[[^\]\n]{1,64}\]/u', '', $text) ?? $text;
    $text = preg_replace('/\{\{[^}\n]{1,64}\}\}/u', '', $text) ?? $text;
    $text = preg_replace('/\$\{[^}\n]{1,64}\}/u', '', $text) ?? $text;

    return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
}

/**
 * Real catalog / company names for filling industry template slots.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_real_offer_names(array $bot, int $limit = 3): array
{
    require_once __DIR__ . '/catalog.php';
    $botId = (int) ($bot['id'] ?? 0);
    if ($botId <= 0 || !catalog_bot_has_products($botId)) {
        return [];
    }

    $products = catalog_products_for_bot($botId);
    $names = [];
    foreach ($products as $product) {
        $name = trim((string) ($product['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $names[] = $name;
        if (count($names) >= $limit) {
            break;
        }
    }

    return $names;
}

/**
 * Replace template placeholders like [cuisine] with this business's real catalog/offer.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_resolve_placeholders(string $text, array $bot): string
{
    $text = trim($text);
    if ($text === '' || !knowledge_text_has_unresolved_placeholders($text)) {
        return $text;
    }

    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/restaurant-menu-card.php';
    require_once __DIR__ . '/industry-templates.php';

    $brand = get_bot_brand_label($bot);
    $botId = (int) ($bot['id'] ?? 0);
    $industryKey = mb_strtolower(trim((string) ($bot['industry_key'] ?? '')));
    $tpl = $industryKey !== '' ? industry_template($industryKey) : null;
    $industryLabel = is_array($tpl) ? trim((string) ($tpl['label'] ?? '')) : '';
    $isRestaurant = $industryKey === 'restaurant'
        || ($botId > 0 && catalog_bot_is_restaurant($botId));

    $names = knowledge_real_offer_names($bot, 4);
    $productHint = $names !== [] ? implode(', ', $names) : '';
    if ($productHint === '') {
        $model = trim(preg_replace('/\[[^\]]+\]/', '', (string) ($bot['business_model'] ?? '')) ?? '');
        $model = trim(preg_replace('/\s{2,}/u', ' ', $model) ?? $model);
        if ($model !== '') {
            $productHint = mb_substr($model, 0, 80);
        } elseif ($industryLabel !== '') {
            $productHint = $industryLabel;
        } else {
            $productHint = 'what we do';
        }
    }

    $priceHint = '';
    if ($botId > 0 && catalog_bot_has_products($botId)) {
        $prices = [];
        foreach (catalog_products_for_bot($botId) as $product) {
            $p = (float) ($product['price'] ?? 0);
            if ($p > 0) {
                $prices[] = $p;
            }
        }
        if ($prices !== []) {
            $cur = (string) ((catalog_products_for_bot($botId)[0]['currency'] ?? 'PKR') ?: 'PKR');
            $priceHint = $cur . ' ' . number_format((int) min($prices)) . '–' . number_format((int) max($prices));
        }
    }

    $serviceHint = $isRestaurant ? $productHint : ($productHint !== '' ? $productHint : 'our services');
    $profile = bot_owner_profile_fields($bot);
    $bizCity = bot_extract_city((string) ($profile['address'] ?? ''));
    $placeHint = $bizCity !== '' ? $bizCity : ($profile['address'] !== '' ? $profile['address'] : 'your area');
    $map = [
        'cuisine'                    => $isRestaurant ? $productHint : $productHint,
        'products'                   => $productHint,
        'product'                    => $names[0] ?? $productHint,
        'product name'               => $names[0] ?? $brand,
        'items'                      => $productHint,
        'menu items'                 => $productHint,
        'service'                    => $serviceHint,
        'services'                   => $serviceHint,
        'skills/services'            => $serviceHint,
        'treatments/consultations'   => $serviceHint,
        'treatments'                 => $serviceHint,
        'property types'             => $productHint,
        'vehicles/parts'             => $productHint,
        'tours/visas/packages'       => $productHint,
        'programs/courses/coaching'  => $productHint,
        'business'                   => $brand,
        'company'                    => $brand,
        'brand'                      => $brand,
        'business type'              => $industryLabel !== '' ? $industryLabel : $brand,
        'location'                   => $placeHint,
        'city'                       => $bizCity !== '' ? $bizCity : 'your city',
        'area'                       => $placeHint,
        'areas'                      => $placeHint,
        'regions'                    => $placeHint,
        'destinations'               => $productHint,
        'target clients'             => 'clients like you',
        'clients'                    => 'clients like you',
        'client'                     => 'clients like you',
        'skills'                     => $serviceHint,
        'price range'                => $priceHint !== '' ? $priceHint : 'on request',
        'price'                      => $priceHint !== '' ? $priceHint : 'on request',
        'one-line value prop'        => $productHint,
    ];

    $resolved = preg_replace_callback(
        '/\[([^\]]+)\]/',
        static function (array $m) use ($map, $brand, $productHint): string {
            $key = mb_strtolower(trim($m[1]));
            if (isset($map[$key])) {
                return $map[$key];
            }
            if ($productHint !== '' && $productHint !== $brand) {
                return $productHint;
            }

            return $brand;
        },
        $text
    );

    $out = trim(preg_replace('/[ \t]{2,}/u', ' ', $resolved ?? $text) ?? $text);
    $out = trim(preg_replace('/\s+[—–-]\s*$/u', '', $out) ?? $out);
    if (knowledge_text_has_unresolved_placeholders($out)) {
        $out = knowledge_strip_unresolved_placeholders($out);
    }

    return $out;
}

/**
 * Catalog-first offer line for shop / restaurant bots.
 *
 * @param array<string, mixed> $bot
 */
function knowledge_catalog_offer_line(array $bot, int $botId): string
{
    require_once __DIR__ . '/catalog.php';
    if ($botId <= 0 || !bot_uses_shop_catalog($bot) || !catalog_bot_has_products($botId)) {
        return '';
    }

    require_once __DIR__ . '/whatsapp-shop-ux.php';
    require_once __DIR__ . '/helpers.php';
    conversation_flag_shop_menu_send(true);
    if (function_exists('whatsapp_shop_copy_menu_with_items')) {
        $menu = whatsapp_shop_copy_menu_with_items($bot, $botId);
        if ($menu !== '') {
            return $menu;
        }
    }

    return whatsapp_shop_copy_offer($bot, $botId);
}

/** Remove technical / bot wording from customer-facing text. */
function knowledge_sanitize_for_customer(string $text): string
{
    if (function_exists('conversation_normalize_whatsapp_whitespace')) {
        $text = conversation_normalize_whatsapp_whitespace($text);
    } else {
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    }
    if ($text === '') {
        return '';
    }

    $replacements = [
        '/\bintelligent whatsapp bot chatbot\b/iu'     => 'sales rep on WhatsApp',
        '/\bwhatsapp bot chatbot\b/iu'                => 'sales rep on WhatsApp',
        '/\bwhatsapp bot\b/iu'                         => 'WhatsApp sales rep',
        '/\bchatbot\b/iu'                              => 'sales rep',
        '/\bmeta whatsapp business api\b/iu'          => 'WhatsApp for business',
        '/\btechnology solutions provider\b/iu'        => 'team',
        '/\bplatform integration\b/iu'                   => 'setup',
        '/\bautomated\b/iu'                            => '',
        '/\bas an AI\b/iu'                             => '',
        '/\bI am (an? )?(AI|language model)\b/iu'      => '',
    ];

    foreach ($replacements as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }

    $text = preg_replace('/^[a-z0-9.-]+\.(com|net|org|io)\s+is\s+/iu', 'We ', $text) ?? $text;
    $text = preg_replace('/\bis a team specializing in\b/iu', 'we help businesses with', $text) ?? $text;
    $text = knowledge_strip_unresolved_placeholders($text);

    return function_exists('conversation_normalize_whatsapp_whitespace')
        ? conversation_normalize_whatsapp_whitespace($text)
        : trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
}

function knowledge_normalize_country(string $token): string
{
    $map = [
        'uk' => 'the UK', 'united kingdom' => 'the UK', 'england' => 'the UK',
        'usa' => 'the USA', 'america' => 'the USA', 'uae' => 'the UAE', 'dubai' => 'the UAE',
    ];
    $key = mb_strtolower(trim($token));

    return $map[$key] ?? ucfirst($token);
}

function knowledge_extract_offer_summary(string $corpus): string
{
    return knowledge_trim_snippet(knowledge_sanitize_for_customer($corpus), 140);
}

/**
 * @param list<string> $boostWords
 */
function knowledge_find_best_snippet(string $corpus, string $userMessage, array $boostWords = []): ?string
{
    $chunks = knowledge_split_corpus($corpus);
    if ($chunks === []) {
        return null;
    }

    $terms = knowledge_query_terms($userMessage);
    if ($terms === [] && $boostWords === []) {
        return null;
    }

    $best = null;
    $bestScore = 0;
    foreach ($chunks as $chunk) {
        $score = knowledge_score_chunk($chunk, $terms, $boostWords);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $chunk;
        }
    }

    return $bestScore >= 3 ? $best : null;
}

/** @return list<string> */
function knowledge_split_corpus(string $corpus): array
{
    $parts = preg_split('/\n{2,}|\r\n{2,}/u', $corpus) ?: [];
    $chunks = [];
    foreach ($parts as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
        if (mb_strlen($part) >= 25) {
            $chunks[] = $part;
            continue;
        }
        foreach (preg_split('/(?<=[.!?])\s+/u', $part) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) >= 25) {
                $chunks[] = $sentence;
            }
        }
    }

    return $chunks;
}

/** @return list<string> */
function knowledge_query_terms(string $message): array
{
    $stop = [
        'i', 'me', 'my', 'you', 'your', 'we', 'our', 'the', 'a', 'an', 'is', 'are', 'am', 'was', 'be',
        'to', 'for', 'in', 'on', 'at', 'of', 'and', 'or', 'but', 'need', 'want', 'like', 'good', 'hi', 'hello',
        'hey', 'please', 'can', 'could', 'would', 'what', 'how', 'when', 'where', 'who', 'why', 'do', 'does',
        'did', 'have', 'has', 'had', 'will', 'with', 'about', 'this', 'that', 'it', 'its', 'just', 'really',
    ];
    $words = preg_split('/[\s,.!?;:()\-]+/u', mb_strtolower(trim($message))) ?: [];
    $terms = [];
    foreach ($words as $word) {
        $word = trim($word);
        if ($word === '' || mb_strlen($word) < 2 || in_array($word, $stop, true)) {
            continue;
        }
        $terms[] = $word;
    }

    return array_values(array_unique($terms));
}

/** @param list<string> $terms
 * @param list<string> $boostWords
 */
function knowledge_score_chunk(string $chunk, array $terms, array $boostWords = []): int
{
    $lower = mb_strtolower($chunk);
    $score = 0;
    foreach ($terms as $term) {
        if (str_contains($lower, $term)) {
            $score += mb_strlen($term) >= 4 ? 2 : 1;
        }
    }
    foreach ($boostWords as $boost) {
        if (str_contains($lower, mb_strtolower($boost))) {
            $score += 2;
        }
    }

    return $score;
}

function knowledge_format_local_reply(string $rep, string $brand, string $snippet, string $countryHint = ''): string
{
    return knowledge_sanitize_for_customer(knowledge_trim_snippet($snippet, 280));
}

function knowledge_trim_snippet(string $text, int $maxLen): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '' || $maxLen <= 0) {
        return '';
    }
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    $cut = mb_substr($text, 0, $maxLen);

    if (preg_match('/^(.+[.!?])\s/u', $cut, $m)) {
        return trim($m[1]);
    }

    if (preg_match('/^(.+)\s+\S*$/u', $cut, $m) && mb_strlen(trim($m[1])) >= (int) ($maxLen * 0.55)) {
        $wordCut = trim($m[1]);
        if (preg_match('/[.!?]$/u', $wordCut)) {
            return $wordCut;
        }

        return $wordCut . '.';
    }

    return trim($cut) . '.';
}

/** Customer explicitly wants a fuller explanation, not a one-liner. */
function knowledge_user_wants_detailed_answer(string $userMessage): bool
{
    require_once __DIR__ . '/conversation-intent.php';
    $lower = mb_strtolower(conversation_normalize_intent_text($userMessage));

    return (bool) preg_match(
        '/\b(in detail|in details|more detail|full detail|explain(?: more| fully)?|tell me more|'
        . 'send again|share again|describe|elaborate|break it down|what exactly|everything you)\b/u',
        $lower
    );
}
