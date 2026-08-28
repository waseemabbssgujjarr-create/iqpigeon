<?php
/**
 * Platform-wide AI training assembly.
 * Admin controls global tone/behavior; clients control rep identity, knowledge, and "What we offer".
 * Runtime prompts never inject hidden PHP defaults — only admin-saved settings + owner content.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai-personality.php';

/**
 * Admin-editable global personality, tone, and behavior rules.
 */
function get_global_personality_rules(): string
{
    return get_human_personality_core();
}

/**
 * Admin-editable rep persona template — only when explicitly saved (not PHP defaults at runtime).
 * Placeholders: {rep_name}, {brand_name}, {company_name}.
 */
function get_global_rep_persona_template(): string
{
    return trim(get_setting('global_rep_persona_template', '') ?? '');
}

/**
 * Admin-editable sales approach — only when explicitly saved.
 */
function get_global_sales_script_rules(): string
{
    return trim(get_setting('global_sales_script_rules', '') ?? '');
}

/**
 * Admin-editable platform knowledge (product FAQs, policies, general accurate facts).
 */
function get_global_platform_knowledge(): string
{
    return trim(get_setting('global_platform_knowledge', '') ?? '');
}

/**
 * Placeholder hint for client knowledge textarea (admin-editable).
 */
function get_knowledge_doc_placeholder(): string
{
    $custom = trim(get_setting('knowledge_doc_placeholder', '') ?? '');
    if ($custom !== '') {
        return $custom;
    }
    return knowledge_doc_placeholder_default();
}

function knowledge_doc_placeholder_default(): string
{
    return <<<'TEXT'
Tell your sales rep about your business:

• What you sell or offer — products, services, packages
• Pricing — starting prices, payment options (COD, card, etc.)
• Who your ideal customer is
• FAQs customers often ask
• Booking or order process

Optional: enter your website below and click Fetch Everything to auto-import products and site info.
TEXT;
}

/** Defaults shown in admin UI only — not injected at runtime until admin saves. */
function get_global_rep_persona_template_default(): string
{
    return <<<'TEXT'
You are {rep_name}, a real person on WhatsApp for {brand_name} ({company_name}).

You are NOT an AI, NOT a chatbot, and NOT a generic assistant.
You work on the {brand_name} team — warm, precise, respectful, and never robotic.

If asked whether you are AI or a bot, respond naturally:
"I'm {rep_name} from the {brand_name} team — happy to help you personally."

Listen first. Reply to what they just said. Never call yourself the business name — you are a person representing the company.
TEXT;
}

/** Defaults shown in admin UI only — not injected at runtime until admin saves. */
function get_global_sales_script_rules_default(): string
{
    return <<<'TEXT'
HOW TO REPLY:
1. Listen to the customer's latest message. Answer that first.
2. One follow-up question only — never stack questions.
3. 1–3 short WhatsApp lines for a single ask; if they raised several points (long text or voice note), cover each point briefly in order, then one follow-up.

SALES SIGNALS (hidden — never explain to customer):
- [BOOK_CALL] — once when qualified; include booking link in the SAME message.
- [CREATE_ORDER] — when product, address, and payment method are confirmed.
- [DISQUALIFY] — only after 4+ exchanges when they firmly closed the door.

FORBIDDEN: Markdown, ignoring the customer, inventing prices/policies not in the knowledge base.
TEXT;
}

/**
 * Mandatory knowledge boundary — always injected at runtime.
 */
function build_knowledge_boundary_rules(): string
{
    return <<<'TEXT'
───── KNOWLEDGE BOUNDARY (mandatory) ─────
You represent ONLY the business in the owner-provided blocks above.

1. SOURCE OF TRUTH: Products, services, prices, and policies must come ONLY from owner content (and catalog search if provided). Never guess.
2. UNKNOWN: If a specific company fact is NOT in the knowledge base, say you will double-check and reply here — do not invent.
3. NO INVENTION: Never agree to deliver services, prices, or policies that are not documented.
4. STALE CHAT: Ignore earlier messages about old/wrong products — owner content is authoritative.
5. OTHER BRANDS: If the customer names another company, answer only if it matches YOUR business; otherwise say you represent {brand_name}.
TEXT;
}

/**
 * Warn the model when chat history predates a knowledge update.
 *
 * @param array<string, mixed> $bot
 * @param array<int, array{role: string, message: string, created_at?: string}> $history
 */
function build_knowledge_freshness_prompt(array $bot, array $history): string
{
    $updatedAt = trim((string) ($bot['knowledge_updated_at'] ?? ''));
    if ($updatedAt === '' || $history === []) {
        return '';
    }

    $updatedTs = strtotime($updatedAt);
    if ($updatedTs === false) {
        return '';
    }

    foreach ($history as $row) {
        if (($row['role'] ?? '') === 'system') {
            continue;
        }
        $created = trim((string) ($row['created_at'] ?? ''));
        if ($created === '') {
            return "\n\nIMPORTANT: Business knowledge was recently updated. Ignore any earlier messages about different products, services, brands, or industries. Use ONLY the owner-provided content for facts.\n";
        }
        $msgTs = strtotime($created);
        if ($msgTs !== false && $msgTs < $updatedTs) {
            return "\n\nIMPORTANT: Business knowledge was recently updated. Ignore any earlier messages about different products, services, brands, or industries. Use ONLY the owner-provided content for facts.\n";
        }
    }

    return '';
}

/**
 * Stable rep identity block — always injected so every reply uses the same persona.
 *
 * @param array<string, mixed> $bot
 */
function build_consistent_rep_persona_block(array $bot, string $companyName): string
{
    require_once __DIR__ . '/bot-knowledge.php';

    $repName = get_bot_rep_name($bot);
    $brandName = trim((string) ($bot['name'] ?? '')) ?: $companyName;
    $tone = bot_rep_tone($bot);
    $persona = bot_rep_persona_plain($bot);

    $lines = [
        '───── YOUR IDENTITY (same person every message) ─────',
        "You are {$repName}, a real person on WhatsApp for {$brandName} ({$companyName}).",
        "Tone: {$tone}. Warm, precise, respectful — never robotic.",
        'You are NOT an AI, NOT a chatbot, and NOT a generic assistant.',
        "If asked whether you are AI: \"I'm {$repName} from {$brandName} — happy to help you personally.\"",
        'Listen first. Reply to what they just said. Do not skip to a pitch.',
        'Never use words: AI, bot, chatbot, automated, virtual assistant, or language model.',
        'Never call yourself the business name — you represent the company as a team member.',
    ];

    if ($persona !== '') {
        $lines[] = 'Personality notes: ' . $persona;
    }

    return implode("\n", $lines);
}

/**
 * Replace persona template placeholders with bot + company values.
 *
 * @param array<string, mixed> $bot
 */
function build_admin_rep_persona_block(array $bot, string $companyName): string
{
    $template = get_global_rep_persona_template();
    if ($template === '') {
        return '';
    }

    $repName = get_bot_rep_name($bot);
    $brandName = trim((string) ($bot['name'] ?? $companyName)) ?: $companyName;

    return str_replace(
        ['{rep_name}', '{brand_name}', '{company_name}'],
        [$repName, $brandName, $companyName],
        $template
    );
}

/**
 * User-provided company block — rep name, what we offer, knowledge base.
 *
 * @param array<string, mixed> $bot
 */
function build_user_company_prompt(array $bot, string $companyName): string
{
    $repName = get_bot_rep_name($bot);
    $brandName = trim((string) ($bot['name'] ?? ''));
    $businessModel = trim((string) ($bot['business_model'] ?? ''));
    $knowledge = trim((string) ($bot['bot_knowledge'] ?? ''));
    $website = trim((string) ($bot['website_url'] ?? ''));

    $parts = [];
    $parts[] = '───── THIS BUSINESS (owner-provided — final authority for facts) ─────';
    $parts[] = 'Rep name: ' . $repName;
    $parts[] = 'Brand / business name: ' . ($brandName !== '' ? $brandName : $companyName);
    $parts[] = 'Company: ' . $companyName;

    if ($businessModel !== '') {
        $parts[] = "\nWhat they offer:\n" . $businessModel;
    }
    if ($website !== '') {
        $parts[] = 'Website: ' . $website;
    }
    if ($knowledge !== '') {
        $parts[] = "\nCompany knowledge base:\n" . $knowledge;
    } else {
        $parts[] = "\n(No detailed knowledge document yet — use brand name and what they offer above only. Do NOT invent services or products.)";
    }

    $parts[] = "\nUse ONLY the facts above for pricing, policies, and services. If something is not listed, say you do not have that information — never invent or assume.";

    return implode("\n", $parts);
}

/**
 * Append "What we offer" when a knowledge document exists but business_model is separate.
 *
 * @param array<string, mixed> $bot
 */
function build_what_we_offer_supplement(array $bot): string
{
    $model = trim((string) ($bot['business_model'] ?? ''));
    if ($model === '') {
        return '';
    }

    return "───── WHAT WE OFFER (owner-provided) ─────\n" . $model;
}

/**
 * Assemble the base system prompt for a live conversation (before runtime catalog/language blocks).
 *
 * @param array<string, mixed> $bot
 * @param array<string, mixed>|null $lead Optional — demo visitor training per lead
 */
function build_runtime_bot_prompt(array $bot, string $companyName, ?array $lead = null): string
{
    require_once __DIR__ . '/bot-knowledge.php';
    require_once __DIR__ . '/demo-training.php';
    require_once __DIR__ . '/human-agent-prompt.php';

    $repName = get_bot_rep_name($bot);
    $brandName = trim((string) ($bot['name'] ?? '')) ?: $companyName;

    require_once __DIR__ . '/ai-instruction-layers.php';

    $parts = [
        human_agent_live_protocol(),
        human_agent_live_doctrine_block(),
        "You are {$repName}, a live human on WhatsApp for {$brandName} ({$companyName}). "
        . "Listen first, then reply to what they just said — the way a real staff member would. "
        . "Do not recite scripts, and never say you are being trained. "
        . "If you do not know something, say you will confirm — never invent prices, policies, or facts. "
        . "Reply in the customer's own language. Stay short unless they asked for detail. Don't repeat yourself or re-introduce yourself once the chat is underway.",
        ai_admin_priority_block(),
        ai_customer_conversation_prefs_block($bot),
        build_consistent_rep_persona_block($bot, $companyName),
    ];

    $demoTraining = null;
    if ($lead !== null && is_demo_bot((int) ($bot['id'] ?? 0)) && lead_has_demo_training($lead)) {
        $demoTraining = get_lead_training_data($lead);
    }

    if ($demoTraining !== null) {
        $demoKb = build_business_knowledge_block($demoTraining);
        if ($demoKb !== '') {
            $parts[] = $demoKb;
        }
        $parts[] = 'You are in demo mode for a visitor who trained you on THEIR business above. '
            . 'Represent that business using ONLY the demo knowledge — same professional tone every reply.';
    } elseif (bot_has_knowledge_document($bot)) {
        $parsed = [
            'knowledge_summary'    => mb_substr((string) ($bot['bot_knowledge'] ?? ''), 0, 8000),
            'qualify_trigger'      => $bot['qualify_trigger'] ?? '',
            'qualify_message'      => $bot['qualify_message'] ?? '',
            'disqualify_message'   => $bot['disqualify_message'] ?? '',
            'calendly_link'        => get_bot_calendly_link($bot),
            'qualifying_questions' => json_decode($bot['qualifying_questions'] ?? '[]', true) ?: [],
            'business_mode'        => $bot['business_mode'] ?? 'mixed',
            'conversion_goal'      => $bot['conversion_goal'] ?? '',
        ];
        $body = build_bot_knowledge_prompt_body(
            (string) $bot['bot_knowledge'],
            $parsed,
            $bot,
            $companyName,
            true
        );
        $offer = build_what_we_offer_supplement($bot);
        if ($offer !== '') {
            $body .= "\n\n" . $offer;
        }
        $parts[] = $body;
    } elseif (trim($bot['business_model'] ?? '') !== '' || trim($bot['bot_knowledge'] ?? '') !== '') {
        $parts[] = build_user_company_prompt($bot, $companyName);
    } elseif (trim((string) ($bot['openai_system_prompt'] ?? '')) !== '') {
        $parts[] = trim((string) $bot['openai_system_prompt']);
    } else {
        $parts[] = build_system_prompt($bot, $companyName, bot_rep_tone($bot));
    }

    require_once __DIR__ . '/industry-templates.php';
    $factsBlock = industry_runtime_facts_block($bot);
    if ($factsBlock !== '') {
        $parts[] = $factsBlock;
    }

    $parts[] = "───── ACTION SIGNALS (hidden — never explain or show these to the customer) ─────\n"
        . "• [BOOK_CALL] — include once when the customer is qualified and ready to book; put the booking details in the same message.\n"
        . "• [CREATE_ORDER] — include once the product, delivery address, and payment method are all confirmed.\n"
        . "• [DISQUALIFY] — only after several exchanges when the customer has clearly and firmly declined.";

    $parts[] = str_replace(
        '{brand_name}',
        trim((string) ($bot['name'] ?? $companyName)) ?: $companyName,
        build_knowledge_boundary_rules()
    );

    $global = get_global_platform_knowledge();
    if ($global !== '') {
        $parts[] = '───── PLATFORM KNOWLEDGE (general platform FAQs only — not client sales facts) ─────'
            . "\nUse ONLY for questions about the IQPigeon platform itself."
            . "\nFor this business's pricing, services, and qualifying, use the owner content above — never mix the two.\n\n"
            . $global;
    }

    require_once __DIR__ . '/bot-knowledge.php';
    $meta = bot_training_meta($bot);
    $hoursBlock = bot_operating_hours_prompt_block($meta);
    if ($hoursBlock !== '') {
        $parts[] = $hoursBlock;
    }
    $triggerBlock = bot_trigger_words_prompt_block($meta);
    if ($triggerBlock !== '') {
        $parts[] = $triggerBlock;
    }

    $parts[] = human_agent_identity_lock($bot, $companyName);

    return implode("\n\n", array_filter($parts, static fn ($p) => trim($p) !== ''));
}

/**
 * Admin-editable master behavior — Master Behavior tab, Global Guardrails tab,
 * and per-section notes in Admin → AI Model & Guardrails. This is the single
 * function that makes "Save Changes" on that page actually change every
 * business's live AI replies (previously the saved settings were never read
 * anywhere outside the admin's own test chat).
 */
function build_admin_master_prompt_block(): string
{
    $parts = [];

    $base = trim((string) get_setting('ai_base_prompt', ''));
    if ($base !== '') {
        $parts[] = $base;
    }

    $behavior = trim((string) get_setting('ai_behavior_prompt', ''));
    $tone = trim((string) get_setting('ai_tone', ''));
    $formality = trim((string) get_setting('ai_formality', ''));
    $langStyle = trim((string) get_setting('ai_language_style', ''));
    $styleBits = array_filter([$tone, $formality, $langStyle]);
    if ($behavior !== '' || $styleBits !== []) {
        $lines = ['───── PLATFORM MASTER BEHAVIOR (admin-controlled, applies to every business) ─────'];
        if ($behavior !== '') {
            $lines[] = $behavior;
        }
        if ($styleBits !== []) {
            $lines[] = 'Tone: ' . implode(' · ', $styleBits) . '.';
        }
        $parts[] = implode("\n", $lines);
    }

    $principles = get_ai_core_principles();
    if ($principles !== []) {
        $parts[] = "───── CORE PRINCIPLES (admin-defined, always apply) ─────\n"
            . implode("\n", array_map(static fn ($p) => '• ' . $p, $principles));
    }

    foreach (ai_master_section_ids() as $sid => $label) {
        $text = trim((string) get_setting('ai_section_' . $sid, ''));
        if ($text !== '') {
            $parts[] = '───── ' . mb_strtoupper($label) . ' (admin-defined) ─────' . "\n" . $text;
        }
    }

    $activeRules = array_values(array_filter(array_map(
        static fn ($rule) => !empty($rule['enabled']) ? $rule['label'] : null,
        get_ai_guardrails()
    )));
    if ($activeRules !== []) {
        $parts[] = "───── GLOBAL GUARDRAILS (admin-enforced, never break these) ─────\n"
            . implode("\n", array_map(static fn ($r) => '• ' . $r, $activeRules));
    }

    return implode("\n\n", array_filter($parts, static fn ($p) => trim($p) !== ''));
}

/** Section ids/labels for the Master Behavior left-nav (used for both UI and prompt assembly). */
function ai_master_section_ids(): array
{
    return [
        'tone'       => 'Tone & Language',
        'data'       => 'Data Handling',
        'fallback'   => 'Fallback & Escalation',
        'knowledge'  => 'Knowledge & Context',
        'rules'      => 'Business Rules',
        'disallowed' => 'Disallowed Actions',
    ];
}

/** @return list<string> */
function get_ai_core_principles(): array
{
    $raw = get_setting('ai_core_principles', '');
    if ($raw !== '' && $raw !== null) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('trim', $decoded), static fn ($p) => $p !== ''));
        }
    }

    return [
        'Be helpful, accurate and solution-focused',
        'Protect customer privacy and never share sensitive information',
        'Do not make up information. If unsure, ask for clarification',
        'Guide users step-by-step to complete their goals',
        'Be concise. Avoid long unnecessary explanations',
    ];
}

/** @param list<string> $principles */
function save_ai_core_principles(array $principles): void
{
    set_setting('ai_core_principles', json_encode(array_values($principles), JSON_UNESCAPED_UNICODE));
}

/**
 * @return array<string, array{label: string, enabled: bool}> keyed by a stable slug
 *         (not the label text) so toggle state survives future label edits.
 */
function get_ai_guardrails(): array
{
    $labels = [
        'no_pii'            => 'Never share personal data of other users',
        'no_impersonation'  => 'Never impersonate real people',
        'no_competitor_pricing' => 'Never discuss competitor pricing in detail',
        'no_medical_legal'  => 'Never make medical or legal claims',
        'no_payment_in_chat'=> 'Never accept payment details via chat',
    ];

    $enabledMap = [];
    $raw = get_setting('ai_guardrails', '');
    if ($raw !== '' && $raw !== null) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $enabledMap = $decoded;
        }
    }

    $out = [];
    foreach ($labels as $slug => $label) {
        $out[$slug] = [
            'label'   => $label,
            'enabled' => array_key_exists($slug, $enabledMap) ? (bool) $enabledMap[$slug] : true,
        ];
    }

    return $out;
}

/** @param array<string, bool> $enabledBySlug */
function save_ai_guardrails(array $enabledBySlug): void
{
    set_setting('ai_guardrails', json_encode($enabledBySlug, JSON_UNESCAPED_UNICODE));
}

/** Full snapshot of everything the Master Behavior page controls — used by Export/Duplicate/Reset. */
function ai_master_behavior_snapshot(): array
{
    $sections = [];
    foreach (ai_master_section_ids() as $sid => $label) {
        $sections[$sid] = trim((string) get_setting('ai_section_' . $sid, ''));
    }

    return [
        'ai_base_prompt'     => trim((string) get_setting('ai_base_prompt', '')),
        'ai_behavior_prompt' => trim((string) get_setting('ai_behavior_prompt', '')),
        'ai_tone'            => trim((string) get_setting('ai_tone', 'Friendly & Professional')),
        'ai_formality'       => trim((string) get_setting('ai_formality', 'Professional')),
        'ai_language_style'  => trim((string) get_setting('ai_language_style', 'Simple & Clear')),
        'principles'         => get_ai_core_principles(),
        'sections'           => $sections,
        'guardrails'         => array_map(static fn ($g) => $g['enabled'], get_ai_guardrails()),
    ];
}

/** Reset every Master Behavior setting back to platform defaults. */
function reset_ai_master_behavior(): void
{
    set_setting('ai_base_prompt', '');
    set_setting('ai_behavior_prompt', "You are a live WhatsApp sales representative for this business. Help customers with their questions, stay human and concise, and never invent prices or stock.\nAnswer what they just said first. Stay short unless they asked for detail.");
    set_setting('ai_tone', 'Friendly & Professional');
    set_setting('ai_formality', 'Professional');
    set_setting('ai_language_style', 'Simple & Clear');
    set_setting('ai_core_principles', '');
    foreach (ai_master_section_ids() as $sid => $label) {
        set_setting('ai_section_' . $sid, '');
    }
    set_setting('ai_guardrails', '');
}

/** Save a timestamped copy of the current behavior snapshot (capped history). */
function duplicate_ai_master_behavior_snapshot(string $adminName): array
{
    $raw = get_setting('ai_behavior_snapshots', '');
    $list = $raw !== '' && $raw !== null ? json_decode($raw, true) : [];
    if (!is_array($list)) {
        $list = [];
    }

    $entry = [
        'id'         => uniqid('snap_', true),
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => $adminName,
        'data'       => ai_master_behavior_snapshot(),
    ];
    array_unshift($list, $entry);
    $list = array_slice($list, 0, 20);

    set_setting('ai_behavior_snapshots', json_encode($list, JSON_UNESCAPED_UNICODE));

    return $entry;
}

/** @return list<array{id: string, created_at: string, created_by: string, data: array}> */
function list_ai_master_behavior_snapshots(): array
{
    $raw = get_setting('ai_behavior_snapshots', '');
    $list = $raw !== '' && $raw !== null ? json_decode($raw, true) : [];

    return is_array($list) ? $list : [];
}

/** Restore a previously saved snapshot by id. */
function restore_ai_master_behavior_snapshot(string $snapshotId): bool
{
    foreach (list_ai_master_behavior_snapshots() as $entry) {
        if (($entry['id'] ?? '') !== $snapshotId) {
            continue;
        }
        $data = $entry['data'] ?? [];
        set_setting('ai_base_prompt', (string) ($data['ai_base_prompt'] ?? ''));
        set_setting('ai_behavior_prompt', (string) ($data['ai_behavior_prompt'] ?? ''));
        set_setting('ai_tone', (string) ($data['ai_tone'] ?? 'Friendly & Professional'));
        set_setting('ai_formality', (string) ($data['ai_formality'] ?? 'Professional'));
        set_setting('ai_language_style', (string) ($data['ai_language_style'] ?? 'Simple & Clear'));
        save_ai_core_principles((array) ($data['principles'] ?? []));
        foreach ((array) ($data['sections'] ?? []) as $sid => $text) {
            set_setting('ai_section_' . $sid, (string) $text);
        }
        save_ai_guardrails((array) ($data['guardrails'] ?? []));

        return true;
    }

    return false;
}

/**
 * Single orchestrator — assembles the full system prompt in fixed priority order.
 *
 * Platform rules → persona → business knowledge → buyer context → runtime modules → language lock.
 *
 * @param array<string, mixed> $bot
 * @param array<string, mixed> $lead
 * @param array<int, array{role: string, message: string, created_at?: string}> $history
 * @param array<string, mixed> $visitorContext
 */
function build_full_ai_system_prompt(
    array $bot,
    array $lead,
    string $companyName,
    string $userMessage,
    array $history,
    array $visitorContext,
    int $leadId,
    int $botId
): string {
    require_once __DIR__ . '/visitor-context.php';
    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/cart.php';
    require_once __DIR__ . '/shipment.php';
    require_once __DIR__ . '/lead-lifecycle.php';
    require_once __DIR__ . '/booking.php';

    $prompt = build_runtime_bot_prompt($bot, $companyName, $lead);

    $prompt .= build_knowledge_freshness_prompt($bot, $history);
    $prompt .= build_visitor_language_prompt($visitorContext, $userMessage, $history);
    $prompt .= build_reply_discipline_prompt($history, $userMessage);
    $prompt .= build_language_lock_footer($userMessage, $history);

    require_once __DIR__ . '/conversation-intent.php';
    require_once __DIR__ . '/human-agent-prompt.php';
    $checkout = cart_checkout_in_progress($leadId);
    $conversion = conversation_active_conversion($leadId, $botId);
    $aside = conversation_message_is_aside_for_type($userMessage, (string) ($conversion['type'] ?? 'none'), $leadId, $botId);
    $commercial = !$aside && ($checkout || conversation_wants_commercial_context($userMessage));

    if ($aside) {
        $prompt .= human_agent_social_turn_lock();
        $prompt .= conversation_conversion_aside_prompt_block($conversion);
    } elseif ($commercial) {
        $prompt .= catalog_ai_prompt_block($botId);
        require_once __DIR__ . '/industry-templates.php';
        $prompt .= industry_live_context_block($bot);
        if (!$checkout) {
            $prompt .= catalog_runtime_search_block($botId, $userMessage);
        }
        $prompt .= lifecycle_conversion_prompt_block($bot);
        $prompt .= booking_ai_prompt_block($botId);
    } else {
        $prompt .= human_agent_social_turn_lock();
    }

    $iqp2Runtime = '';
    foreach ([
        dirname(__DIR__) . '/includes/runtime.php',
        dirname(__DIR__) . '/iqpigeon2/includes/runtime.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            $iqp2Runtime = $candidate;
            break;
        }
    }
    if ($iqp2Runtime !== '') {
        require_once $iqp2Runtime;
        if (function_exists('iqp2_runtime_prompt_suffix')) {
            $prompt .= iqp2_runtime_prompt_suffix($botId);
        }
    }

    $prompt .= cart_ai_context_block($leadId, $botId);
    $prompt .= shipment_ai_context_block($leadId, $botId);

    if (!empty($GLOBALS['turn_engine_state_prompt_block'])) {
        $prompt .= "\n\n" . trim((string) $GLOBALS['turn_engine_state_prompt_block']) . "\n";
    }
    if (!empty($GLOBALS['turn_engine_catalog_prompt_block'])) {
        $prompt .= trim((string) $GLOBALS['turn_engine_catalog_prompt_block']);
    }
    if (!empty($GLOBALS['turn_engine_intelligence_block'])) {
        $prompt .= "\n\n" . trim((string) $GLOBALS['turn_engine_intelligence_block']) . "\n";
    }
    if (!empty($GLOBALS['turn_engine_live_agent_block'])) {
        $prompt .= trim((string) $GLOBALS['turn_engine_live_agent_block']);
    }

    $longHint = ai_long_message_reply_hint($userMessage);
    if ($longHint !== '') {
        $prompt .= "\n\n───── " . $longHint . "\n";
    }

    require_once __DIR__ . '/human-agent-prompt.php';
    $prompt .= "\n\n" . human_agent_identity_lock($bot, $companyName) . "\n";

    return $prompt;
}

/**
 * Deterministic DeepSeek options for sales replies — one path for live bots, slightly warmer for demo.
 * Longer customer messages (especially voice transcripts) get a higher reply budget so multi-part asks are covered.
 *
 * @return array{temperature: float, max_tokens: int, top_p: float, frequency_penalty: float, presence_penalty: float}
 */
function get_ai_sales_chat_options(int $botId, bool $isRetry = false, string $userMessage = ''): array
{
    $isDemo = function_exists('is_demo_bot') && is_demo_bot($botId);
    $msgLen = mb_strlen(trim($userMessage));
    $isVoice = str_starts_with(trim($userMessage), '[Voice message from customer]');
    $isLong = $isVoice || $msgLen >= 350;

    $maxTokens = $isDemo ? 220 : 280;
    if ($isLong) {
        // ~30–60s voice / multi-question text needs room to answer each point briefly
        $maxTokens = $isDemo ? 480 : 560;
    } elseif ($msgLen >= 180) {
        $maxTokens = $isDemo ? 320 : 380;
    } elseif ($msgLen >= 80 || (function_exists('knowledge_user_wants_detailed_answer') && knowledge_user_wants_detailed_answer($userMessage))) {
        $maxTokens = $isDemo ? 300 : 360;
    }

    $base = [
        'temperature'       => $isDemo ? 0.32 : 0.28,
        'max_tokens'        => $maxTokens,
        'top_p'             => 0.88,
        'frequency_penalty' => 0.6,
        'presence_penalty'  => 0.35,
    ];

    if ($isRetry) {
        $base['temperature'] = min($base['temperature'] + 0.05, 0.28);
        $base['frequency_penalty'] = 0.9;
        $base['max_tokens'] = max($base['max_tokens'], $isLong ? 480 : 280);
    }

    return $base;
}

/**
 * Extra system note when the customer sent a long voice note or multi-part message.
 */
function ai_long_message_reply_hint(string $userMessage): string
{
    $trimmed = trim($userMessage);
    $isVoice = str_starts_with($trimmed, '[Voice message from customer]');
    $len = mb_strlen($trimmed);
    if (!$isVoice && $len < 350) {
        return '';
    }

    return "MULTI-POINT CUSTOMER MESSAGE:\n"
        . ($isVoice
            ? "This message is a voice-note transcript (full text below). "
            : "This customer message is long and may contain several asks. ")
        . "Cover EACH distinct question or request they raised — briefly, in order — before asking one follow-up. "
        . "Do not stop after the first point only.";
}

/**
 * Save admin global training settings.
 *
 * @param array{personality?: string, persona_template?: string, sales_rules?: string, platform_knowledge?: string, placeholder?: string} $data
 */
function save_global_training_settings(array $data): void
{
    require_once __DIR__ . '/platform-settings.php';

    if (array_key_exists('personality', $data)) {
        set_setting('global_personality_rules', trim((string) $data['personality']));
    }
    if (array_key_exists('persona_template', $data)) {
        set_setting('global_rep_persona_template', trim((string) $data['persona_template']));
    }
    if (array_key_exists('sales_rules', $data)) {
        set_setting('global_sales_script_rules', trim((string) $data['sales_rules']));
    }
    if (array_key_exists('platform_knowledge', $data)) {
        set_setting('global_platform_knowledge', trim((string) $data['platform_knowledge']));
    }
    if (array_key_exists('placeholder', $data)) {
        set_setting('knowledge_doc_placeholder', trim((string) $data['placeholder']));
    }
}
