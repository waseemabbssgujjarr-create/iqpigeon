<?php
/**
 * Per-bot qualification flow.
 *
 * Admin industry templates are global starters. Each client can replace the
 * questions, qualify trigger, messages, business mode, and conversion goal
 * from Training → Qualify. That override lives only on their bot.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lead-lifecycle.php';
require_once __DIR__ . '/industry-templates.php';

function ensure_qualification_flow_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    require_once __DIR__ . '/commerce-schema.php';
    commerce_ensure_column(db_connect(), 'bots', 'qualification_custom', 'TINYINT(1) NOT NULL DEFAULT 0');
    $done = true;
}

function qualification_is_custom(array $bot): bool
{
    return (int) ($bot['qualification_custom'] ?? 0) === 1;
}

/**
 * @return array<string, string>
 */
function qualification_question_types(): array
{
    return [
        'Product'      => 'Product / service',
        'Location'     => 'Location / area',
        'Budget'       => 'Budget',
        'Timeline'     => 'Timeline',
        'Intent'       => 'Intent',
        'Pain Point'   => 'Need / pain point',
        'Availability' => 'Availability',
        'Custom'       => 'Custom',
    ];
}

function qualification_infer_type(string $text): string
{
    $t = mb_strtolower($text);
    if (preg_match('/budget|price|afford|cost|spend|how much/u', $t)) {
        return 'Budget';
    }
    if (preg_match('/when|timeline|deadline|how soon|start date|intake/u', $t)) {
        return 'Timeline';
    }
    if (preg_match('/city|area|address|delivery|pickup|location|where|region/u', $t)) {
        return 'Location';
    }
    if (preg_match('/problem|pain|challenge|trying to solve/u', $t)) {
        return 'Pain Point';
    }
    if (preg_match('/visit|appointment|available|preferred day|slot|book/u', $t)) {
        return 'Availability';
    }
    if (preg_match('/buy or rent|new or used|delivery or pickup|intent|interested in/u', $t)) {
        return 'Intent';
    }
    if (preg_match('/what (are you|would you|do you)|looking to|which (product|service|model|program|treatment)|order/u', $t)) {
        return 'Product';
    }

    return 'Custom';
}

/**
 * @param list<mixed> $raw
 * @return list<array{text: string, type: string, required: bool}>
 */
function qualification_normalize_questions(array $raw, bool $defaultRequired = true): array
{
    $types = qualification_question_types();
    $out = [];

    foreach ($raw as $q) {
        if (is_string($q)) {
            $text = trim($q);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'text'     => mb_substr($text, 0, 240),
                'type'     => qualification_infer_type($text),
                'required' => true,
            ];
            continue;
        }
        if (!is_array($q)) {
            continue;
        }
        $text = trim((string) ($q['text'] ?? $q['question'] ?? ''));
        if ($text === '') {
            continue;
        }
        $type = trim((string) ($q['type'] ?? ''));
        if (!array_key_exists($type, $types)) {
            $type = qualification_infer_type($text);
        }
        $required = array_key_exists('required', $q)
            ? !empty($q['required']) && $q['required'] !== '0' && $q['required'] !== 'false'
            : $defaultRequired;
        $out[] = [
            'text'     => mb_substr($text, 0, 240),
            'type'     => $type,
            'required' => $required,
        ];
    }

    return array_slice($out, 0, 12);
}

/**
 * @param mixed $raw
 * @return list<array{text: string, type: string, required: bool}>
 */
function qualification_decode_questions(mixed $raw): array
{
    if (is_array($raw)) {
        return qualification_normalize_questions($raw);
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? qualification_normalize_questions($decoded) : [];
}

/**
 * @param array<string, mixed>|null $tpl
 * @return list<array{text: string, type: string, required: bool}>
 */
function qualification_questions_from_industry(?array $tpl): array
{
    if (!is_array($tpl)) {
        return [];
    }

    return qualification_normalize_questions((array) ($tpl['questions'] ?? []));
}

function qualification_default_trigger(string $goal, array $questions): string
{
    $required = [];
    foreach ($questions as $q) {
        if (!empty($q['required'])) {
            $required[] = $q;
        }
    }
    $use = $required !== [] ? $required : $questions;
    $bits = [];
    foreach ($use as $q) {
        $bits[] = rtrim((string) ($q['text'] ?? ''), '?');
    }

    if ($goal === 'order_placed') {
        return 'Customer confirms what they want, delivery or pickup details, and payment (COD or prepaid)';
    }
    if ($goal === 'trial_started') {
        return 'Clear need, and they agree to start a trial or book a demo';
    }
    if ($bits !== []) {
        return 'Lead has answered: ' . implode('; ', $bits);
    }

    return 'Clear fit per the owner qualification script';
}

/**
 * @return array{qualify_message: string, disqualify_message: string}
 */
function qualification_default_messages(string $goal): array
{
    return match ($goal) {
        'order_placed' => [
            'qualify_message'    => 'Perfect — I have everything I need to place this.',
            'disqualify_message' => 'No problem — if you want to order later, just message us.',
        ],
        'trial_started' => [
            'qualify_message'    => 'You look like a strong fit. Let me get you started with a trial or demo.',
            'disqualify_message' => 'Thanks for chatting — reach out if you want to try us later.',
        ],
        default => [
            'qualify_message'    => 'Great — you look like a strong fit. Here is how to book:',
            'disqualify_message' => 'Thanks for chatting — feel free to reach out if things change.',
        ],
    };
}

/**
 * Starter flow from the currently applied industry template (admin global).
 *
 * @param array<string, mixed> $bot
 * @return array{
 *   questions: list<array{text: string, type: string, required: bool}>,
 *   qualify_trigger: string,
 *   qualify_message: string,
 *   disqualify_message: string,
 *   business_mode: string,
 *   conversion_goal: string,
 *   industry_label: string
 * }
 */
function qualification_defaults_for_bot(array $bot): array
{
    ensure_lead_lifecycle_schema();
    $key = trim((string) ($bot['industry_key'] ?? ''));
    $tpl = $key !== '' ? industry_template($key) : null;
    $mode = is_array($tpl)
        ? strtolower(trim((string) ($tpl['business_mode'] ?? '')))
        : bot_business_mode($bot);
    if (!array_key_exists($mode, bot_business_modes())) {
        $mode = bot_business_mode($bot);
    }
    $goal = is_array($tpl)
        ? strtolower(trim((string) ($tpl['conversion_goal'] ?? '')))
        : bot_conversion_goal($bot);
    if (!array_key_exists($goal, bot_conversion_goals())) {
        $goal = lifecycle_conversion_goal_for_mode($mode);
    }
    $questions = qualification_questions_from_industry(is_array($tpl) ? $tpl : null);
    $msgs = qualification_default_messages($goal);

    return [
        'questions'          => $questions,
        'qualify_trigger'    => qualification_default_trigger($goal, $questions),
        'qualify_message'    => $msgs['qualify_message'],
        'disqualify_message' => $msgs['disqualify_message'],
        'business_mode'      => $mode,
        'conversion_goal'    => $goal,
        'industry_label'     => is_array($tpl) ? (string) ($tpl['label'] ?? '') : '',
    ];
}

/**
 * Editor state: stored bot values with industry defaults filling empty fields.
 *
 * @param array<string, mixed> $bot
 * @return array<string, mixed>
 */
function qualification_load_for_bot(array $bot): array
{
    $defaults = qualification_defaults_for_bot($bot);
    $stored = qualification_decode_questions($bot['qualifying_questions'] ?? '[]');
    $trigger = trim((string) ($bot['qualify_trigger'] ?? ''));
    $qMsg = trim((string) ($bot['qualify_message'] ?? ''));
    $dMsg = trim((string) ($bot['disqualify_message'] ?? ''));

    return [
        'questions'          => $stored !== [] ? $stored : $defaults['questions'],
        'stored_empty'       => $stored === [],
        'qualify_trigger'    => $trigger !== '' ? $trigger : $defaults['qualify_trigger'],
        'qualify_message'    => $qMsg !== '' ? $qMsg : $defaults['qualify_message'],
        'disqualify_message' => $dMsg !== '' ? $dMsg : $defaults['disqualify_message'],
        'business_mode'      => bot_business_mode($bot),
        'conversion_goal'    => bot_conversion_goal($bot),
        'custom'             => qualification_is_custom($bot),
        'industry_label'     => $defaults['industry_label'],
        'defaults'           => $defaults,
    ];
}

/**
 * Questions the live AI should actually ask (stored custom, else industry starter).
 *
 * @param array<string, mixed> $bot
 * @return list<array{text: string, type: string, required: bool}>
 */
function qualification_effective_questions(array $bot): array
{
    $stored = qualification_decode_questions($bot['qualifying_questions'] ?? '[]');
    if ($stored !== []) {
        return $stored;
    }

    return qualification_defaults_for_bot($bot)['questions'];
}

/**
 * @param array<string, mixed> $data
 */
function qualification_save_for_bot(int $botId, int $userId, array $data, bool $custom = true): bool
{
    if ($botId <= 0 || $userId <= 0) {
        return false;
    }

    ensure_qualification_flow_schema();
    ensure_lead_lifecycle_schema();

    $mode = strtolower(trim((string) ($data['business_mode'] ?? 'mixed')));
    if (!array_key_exists($mode, bot_business_modes())) {
        $mode = 'mixed';
    }
    $goal = strtolower(trim((string) ($data['conversion_goal'] ?? '')));
    if (!array_key_exists($goal, bot_conversion_goals())) {
        $goal = lifecycle_conversion_goal_for_mode($mode);
    }
    $questions = qualification_normalize_questions((array) ($data['questions'] ?? []), false);
    $trigger = mb_substr(trim((string) ($data['qualify_trigger'] ?? '')), 0, 500);
    if ($trigger === '') {
        $trigger = qualification_default_trigger($goal, $questions);
    }
    $msgs = qualification_default_messages($goal);
    $qMsg = mb_substr(trim((string) ($data['qualify_message'] ?? '')), 0, 500);
    $dMsg = mb_substr(trim((string) ($data['disqualify_message'] ?? '')), 0, 500);
    if ($qMsg === '') {
        $qMsg = $msgs['qualify_message'];
    }
    if ($dMsg === '') {
        $dMsg = $msgs['disqualify_message'];
    }

    db_execute(
        'UPDATE bots SET qualifying_questions = ?, qualify_trigger = ?, qualify_message = ?, disqualify_message = ?,
         business_mode = ?, conversion_goal = ?, qualification_custom = ?, knowledge_updated_at = NOW()
         WHERE id = ? AND user_id = ?',
        'ssssssiii',
        [
            json_encode($questions, JSON_UNESCAPED_UNICODE),
            $trigger,
            $qMsg,
            $dMsg,
            $mode,
            $goal,
            $custom ? 1 : 0,
            $botId,
            $userId,
        ]
    );

    return true;
}

/**
 * Seed this bot from the applied industry template and clear the custom flag.
 *
 * @param array<string, mixed> $bot
 */
function qualification_reset_from_industry(array $bot, int $userId): bool
{
    $defaults = qualification_defaults_for_bot($bot);

    return qualification_save_for_bot((int) ($bot['id'] ?? 0), $userId, $defaults, false);
}

/**
 * Injected on commercial turns so the bot follows this client's Qualify tab.
 *
 * @param array<string, mixed> $bot
 */
function qualification_prompt_block(array $bot): string
{
    $flow = qualification_load_for_bot($bot);
    $goals = bot_conversion_goals();
    $goalLabel = $goals[$flow['conversion_goal']] ?? $flow['conversion_goal'];
    $source = !empty($flow['custom'])
        ? 'your Sales & Leads settings'
        : 'industry starter — you can change this in Training → Sales & Leads';

    $lines = [
        '',
        '───── YOUR QUALIFICATION FLOW (' . $source . ') ─────',
        'Conversion goal: ' . $goalLabel . '.',
        'Qualify when: ' . $flow['qualify_trigger'],
        'Discover this information through natural conversation. Do not interrogate. Do not list several questions in one message.',
        'If the customer already provided an answer in this chat, treat it as collected and do not ask it again.',
        'Ask at most one unanswered required question per reply.',
    ];

    $questions = $flow['questions'];
    if ($questions !== []) {
        $lines[] = 'Collect these (one at a time, skip any already answered). Never invent extra qualification questions from another industry:';
        foreach ($questions as $i => $q) {
            $req = !empty($q['required']) ? 'required' : 'optional';
            $lines[] = ($i + 1) . '. [' . ($q['type'] ?? 'Custom') . ', ' . $req . '] ' . ($q['text'] ?? '');
        }
    } else {
        $lines[] = 'No extra qualifying questions — convert using the conversion goal above.';
    }

    $lines[] = 'When they qualify, use this line (adapt naturally): ' . $flow['qualify_message'];
    $lines[] = 'If they are clearly not a fit after several turns: ' . $flow['disqualify_message'];

    return implode("\n", $lines);
}
