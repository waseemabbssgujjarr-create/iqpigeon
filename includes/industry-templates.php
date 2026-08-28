<?php
/**
 * Industry training templates — starter content for every business type.
 */
declare(strict_types=1);

/**
 * @return array<string, array{
 *   label: string,
 *   icon: string,
 *   business_mode: string,
 *   conversion_goal: string,
 *   offer: string,
 *   knowledge: string,
 *   questions: list<string>
 * }>
 */
function industry_training_templates(): array
{
    return [
        'ecommerce' => [
            'label'           => 'E-commerce / Retail',
            'icon'            => 'shopping_bag',
            'business_mode'   => 'ecommerce',
            'conversion_goal' => 'order_placed',
            'offer'           => 'We sell [products] online with delivery across [regions]. Payment: COD / card / bank transfer.',
            'knowledge'       => "Products & pricing:\n- \n\nDelivery:\n- Areas:\n- Time:\n- Cost:\n\nReturns:\n- Policy:\n\nPayment methods:\n- ",
            'questions'       => ['What are you looking to buy?', 'Which city for delivery?', 'Cash on delivery or prepaid?'],
        ],
        'services' => [
            'label'           => 'Services / Agency',
            'icon'            => 'handshake',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We provide [service] for [target clients]. Packages from [price range].',
            'knowledge'       => "Services:\n- \n\nPricing / packages:\n- \n\nProcess:\n- How it works:\n- Timeline:\n\nBooking:\n- Calendly / call link:\n",
            'questions'       => ['What service do you need?', 'What is your timeline?', 'What budget range works for you?'],
        ],
        'saas' => [
            'label'           => 'SaaS / Software',
            'icon'            => 'cloud',
            'business_mode'   => 'saas',
            'conversion_goal' => 'trial_started',
            'offer'           => 'We offer [product name] — [one-line value prop]. Plans: Starter / Pro / Enterprise.',
            'knowledge'       => "Product:\n- Core features:\n\nPlans & pricing:\n- \n\nTrial:\n- Days / card required:\n\nSupport:\n- ",
            'questions'       => ['How many users or seats?', 'What problem are you solving?', 'When do you want to start?'],
        ],
        'local' => [
            'label'           => 'Local Business',
            'icon'            => 'storefront',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We are a local [business type] serving [area]. Walk-in and appointments welcome.',
            'knowledge'       => "Location:\n- Address:\n\nHours:\n- \n\nServices / menu:\n- \n\nPricing:\n- ",
            'questions'       => ['When would you like to visit or book?', 'Which service do you need?'],
        ],
        'health' => [
            'label'           => 'Clinic / Wellness',
            'icon'            => 'medical_services',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We provide [treatments/consultations]. Licensed practitioners. Appointments required.',
            'knowledge'       => "Treatments:\n- \n\nConsultation fee:\n- \n\nHours & location:\n- \n\nImportant: We do not diagnose emergencies in chat — direct to emergency services.",
            'questions'       => ['Which treatment are you interested in?', 'Have you visited us before?', 'Preferred appointment day?'],
        ],
        'education' => [
            'label'           => 'Education / Coaching',
            'icon'            => 'school',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We help students/clients with [programs/courses/coaching]. Intakes: [dates].',
            'knowledge'       => "Programs:\n- \n\nEligibility:\n- \n\nFees:\n- \n\nIntake dates:\n- ",
            'questions'       => ['Which program or country are you targeting?', 'What is your current qualification?'],
        ],
        'realestate' => [
            'label'           => 'Real Estate',
            'icon'            => 'home_work',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We list and sell/rent [property types] in [areas]. Site visits by appointment.',
            'knowledge'       => "Listings:\n- \n\nAreas:\n- \n\nCommission / fees:\n- \n\nVisit booking:\n- ",
            'questions'       => ['Buy or rent?', 'Which area and budget?', 'When can you visit a property?'],
        ],
        'restaurant' => [
            'label'           => 'Restaurant / Food',
            'icon'            => 'restaurant',
            'business_mode'   => 'ecommerce',
            'conversion_goal' => 'order_placed',
            'offer'           => 'We serve [cuisine]. Dine-in, takeaway, and delivery available.',
            'knowledge'       => "Menu highlights:\n- \n\nDelivery areas:\n- \n\nHours:\n- \n\nMinimum order:\n- ",
            'questions'       => ['Delivery or pickup?', 'What would you like to order?', 'Your area / address?'],
        ],
        'automotive' => [
            'label'           => 'Automotive',
            'icon'            => 'directions_car',
            'business_mode'   => 'mixed',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We sell/service [vehicles/parts]. Test drives and service bookings available.',
            'knowledge'       => "Inventory / services:\n- \n\nFinancing:\n- \n\nService hours:\n- ",
            'questions'       => ['Which model or service do you need?', 'New or used?', 'When can you visit?'],
        ],
        'travel' => [
            'label'           => 'Travel / Tourism',
            'icon'            => 'flight',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We arrange [tours/visas/packages] for [destinations].',
            'knowledge'       => "Packages:\n- \n\nVisa support:\n- \n\nPricing:\n- \n\nBooking process:\n- ",
            'questions'       => ['Which destination and dates?', 'How many travelers?', 'Budget range?'],
        ],
        'b2b' => [
            'label'           => 'B2B / Wholesale',
            'icon'            => 'corporate_fare',
            'business_mode'   => 'mixed',
            'conversion_goal' => 'call_booked',
            'offer'           => 'We supply [products/services] to businesses. MOQ and bulk pricing available.',
            'knowledge'       => "Catalog:\n- \n\nMOQ / bulk pricing:\n- \n\nLead time:\n- \n\nPayment terms:\n- ",
            'questions'       => ['Company name and order volume?', 'Which products?', 'Delivery location?'],
        ],
        'freelancer' => [
            'label'           => 'Freelancer / Creator',
            'icon'            => 'person',
            'business_mode'   => 'services',
            'conversion_goal' => 'call_booked',
            'offer'           => 'I offer [skills/services] for [clients]. Project-based or retainer.',
            'knowledge'       => "Services:\n- \n\nRates:\n- \n\nPortfolio:\n- \n\nAvailability:\n- ",
            'questions'       => ['What project do you have in mind?', 'Timeline and budget?', 'Have you seen our portfolio?'],
        ],
    ];
}

/**
 * Admin-defined overrides/custom templates layered on top of the code
 * defaults above — lets Admin → AI Model & Guardrails → Industry Templates
 * edit existing templates or add brand-new ones without a code deploy.
 *
 * @return array<string, array{label?: string, icon?: string, business_mode?: string, conversion_goal?: string, offer?: string, knowledge?: string, questions?: list<string>, custom?: bool}>
 */
function industry_template_overrides(): array
{
    require_once __DIR__ . '/helpers.php';
    $raw = get_setting('industry_template_overrides', '');
    if ($raw === '' || $raw === null) {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, array<string, mixed>> $overrides */
function save_industry_template_overrides(array $overrides): void
{
    require_once __DIR__ . '/platform-settings.php';
    set_setting('industry_template_overrides', json_encode($overrides, JSON_UNESCAPED_UNICODE));
}

/** Save/update a single template (existing key = override, new key = custom template). */
function save_industry_template(string $key, array $data): void
{
    $overrides = industry_template_overrides();
    $overrides[$key] = [
        'label'           => trim((string) ($data['label'] ?? $key)),
        'icon'            => trim((string) ($data['icon'] ?? 'building')),
        'business_mode'   => trim((string) ($data['business_mode'] ?? 'mixed')),
        'conversion_goal' => trim((string) ($data['conversion_goal'] ?? 'call_booked')),
        'offer'           => (string) ($data['offer'] ?? ''),
        'knowledge'       => (string) ($data['knowledge'] ?? ''),
        'questions'       => array_values(array_filter(array_map('trim', (array) ($data['questions'] ?? [])))),
        'custom'          => !array_key_exists($key, industry_training_templates()),
    ];
    save_industry_template_overrides($overrides);
}

/** Delete a custom (non-built-in) template. Built-in templates can only be overridden, not removed. */
function delete_industry_template(string $key): void
{
    $overrides = industry_template_overrides();
    unset($overrides[$key]);
    save_industry_template_overrides($overrides);
}

function industry_template(string $key): ?array
{
    $all = industry_training_templates();
    $overrides = industry_template_overrides();
    if (isset($overrides[$key])) {
        $base = $all[$key] ?? ['label' => $key, 'icon' => 'building', 'business_mode' => 'mixed', 'conversion_goal' => 'call_booked', 'questions' => []];
        $all[$key] = array_merge($base, $overrides[$key]);
    }

    return $all[$key] ?? null;
}

/** All templates (built-in + custom), with admin overrides applied. */
function industry_templates_all(): array
{
    $keys = array_unique(array_merge(array_keys(industry_training_templates()), array_keys(industry_template_overrides())));
    $out = [];
    foreach ($keys as $key) {
        $tpl = industry_template($key);
        if ($tpl !== null) {
            $out[$key] = $tpl;
        }
    }

    return $out;
}

/**
 * Customer-facing offer from the applied industry + live catalog — never leftover [brackets].
 *
 * @param array<string, mixed> $bot
 */
function industry_resolved_offer_text(array $bot): string
{
    require_once __DIR__ . '/bot-knowledge.php';

    $offer = trim((string) ($bot['business_model'] ?? ''));
    $key = trim((string) ($bot['industry_key'] ?? ''));
    if ($offer === '' && $key !== '') {
        $tpl = industry_template($key);
        if (is_array($tpl)) {
            $offer = trim((string) ($tpl['offer'] ?? ''));
        }
    }

    $offer = knowledge_resolve_placeholders($offer, $bot);
    if ($offer === '' || knowledge_text_has_unresolved_placeholders($offer)) {
        $names = knowledge_real_offer_names($bot, 4);
        if ($names !== []) {
            return implode(', ', $names);
        }
        $offer = knowledge_strip_unresolved_placeholders($offer);
    }

    return $offer;
}

/**
 * Injected only on commercial turns so small talk does not recite a shop template.
 *
 * @param array<string, mixed> $bot
 */
function industry_live_context_block(array $bot): string
{
    require_once __DIR__ . '/bot-knowledge.php';

    $key = trim((string) ($bot['industry_key'] ?? ''));
    $tpl = $key !== '' ? industry_template($key) : null;
    $label = is_array($tpl) ? trim((string) ($tpl['label'] ?? '')) : '';
    $offer = industry_resolved_offer_text($bot);
    $names = knowledge_real_offer_names($bot, 5);

    if ($offer === '' && $label === '' && $names === []) {
        return '';
    }

    $lines = [
        '',
        '───── THIS BUSINESS (use ONLY if they asked about products, prices, packages, or what you offer) ─────',
    ];
    if ($label !== '') {
        $lines[] = 'Industry: ' . $label . '. Stay in this industry — do not switch to restaurant/food unless this industry is Restaurant / Food.';
    }
    if ($offer !== '') {
        $lines[] = 'What they actually offer: ' . $offer;
    }
    if ($names !== []) {
        $lines[] = 'Live catalog: ' . implode(', ', $names) . '. Prefer these names over generic template wording.';
    }
    $lines[] = 'If this turn is social (hi, how are you, thanks) — ignore this block completely.';

    return implode("\n", $lines);
}

/**
 * Training-tab sample thread for the selected industry (not always burgers).
 *
 * @param array<string, mixed> $bot
 * @return list<array{role: string, text: string}>
 */
function industry_sample_turns(array $bot, string $industryKey = ''): array
{
    require_once __DIR__ . '/bot-knowledge.php';
    require_once __DIR__ . '/catalog.php';

    $key = $industryKey !== '' ? $industryKey : trim((string) ($bot['industry_key'] ?? ''));
    $tpl = $key !== '' ? industry_template($key) : null;
    $label = is_array($tpl) ? (string) ($tpl['label'] ?? 'this business') : 'this business';
    $names = knowledge_real_offer_names($bot, 3);
    $botId = (int) ($bot['id'] ?? 0);
    $priced = [];
    if ($botId > 0 && $names !== []) {
        foreach (catalog_products_for_bot($botId) as $product) {
            $name = trim((string) ($product['name'] ?? ''));
            if ($name === '' || !in_array($name, $names, true)) {
                continue;
            }
            $priced[] = $name . ' – ' . catalog_format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'PKR'));
            if (count($priced) >= 3) {
                break;
            }
        }
    }

    if ($priced !== []) {
        $first = $names[0];
        $ask = $key === 'restaurant' ? 'What are your most popular items?' : 'What do you offer?';

        return [
            ['role' => 'user', 'text' => $ask],
            ['role' => 'bot', 'text' => "Here's what we have right now:\n• " . implode("\n• ", $priced) . "\n\nWant me to start with {$first}?"],
            ['role' => 'user', 'text' => "Yes — I'll take the {$first}."],
            ['role' => 'bot', 'text' => "Great — {$first} is noted. Anything else you'd like to add?"],
        ];
    }

    $offer = industry_resolved_offer_text($bot);
    if ($offer === '') {
        $offer = 'Tell me what you need and I\'ll walk you through it.';
    }
    $q = is_array($tpl) && !empty($tpl['questions'][0]) ? (string) $tpl['questions'][0] : 'What do you offer?';

    return [
        ['role' => 'user', 'text' => 'What do you offer?'],
        ['role' => 'bot', 'text' => $offer . "\n\n" . $q],
        ['role' => 'user', 'text' => 'Sounds good — how do we start?'],
        ['role' => 'bot', 'text' => 'Easy — tell me a bit about what you need for ' . $label . ' and I\'ll point you to the right option.'],
    ];
}

function industry_template_keys(): array
{
    return array_keys(industry_templates_all());
}

/**
 * Map a posted dropdown value (template key or legacy label) to an industry_key.
 */
function industry_key_from_posted(string $posted): string
{
    $posted = trim($posted);
    if ($posted === '') {
        return '';
    }
    $slug = preg_replace('/[^a-z0-9_]/', '', mb_strtolower($posted)) ?? '';
    $all = industry_templates_all();
    if ($slug !== '' && isset($all[$slug])) {
        return $slug;
    }
    foreach ($all as $key => $tpl) {
        $label = trim((string) ($tpl['label'] ?? ''));
        if ($label !== '' && strcasecmp($label, $posted) === 0) {
            return $key;
        }
    }
    $aliases = [
        'restaurant / food & beverage' => 'restaurant',
        'restaurant / food'            => 'restaurant',
        'food & beverage'              => 'restaurant',
        'retail / e-commerce'          => 'ecommerce',
        'e-commerce / retail'          => 'ecommerce',
        'healthcare / medical'         => 'health',
        'clinic / wellness'            => 'health',
        'education / training'         => 'education',
        'education / coaching'         => 'education',
        'coaching / training'          => 'education',
        'coaching'                     => 'education',
        'training'                     => 'education',
        'real estate'                  => 'realestate',
        'finance / banking'            => 'b2b',
        'technology / software'        => 'saas',
        'beauty / salon'               => 'local',
        'other'                        => 'local',
        'services / agency'            => 'services',
        'saas / software'              => 'saas',
        'local business'               => 'local',
    ];
    $norm = mb_strtolower($posted);
    if (isset($aliases[$norm])) {
        return $aliases[$norm];
    }
    foreach ($all as $key => $tpl) {
        $label = mb_strtolower(trim((string) ($tpl['label'] ?? '')));
        if ($label !== '' && (str_contains($label, $norm) || str_contains($norm, $label))) {
            return $key;
        }
    }

    return '';
}

/**
 * Quick-reply chips for Train → Test Your Assistant (industry-specific, not always food).
 *
 * @return list<string>
 */
function industry_test_chips(string $industryKey): array
{
    $map = [
        'restaurant'  => ['What are your opening hours?', 'Do you offer home delivery?', "What's your best selling item?"],
        'education'   => ['Which programs do you offer?', 'When is the next intake?', 'How do I enrol?'],
        'ecommerce'   => ['What do you sell?', 'Do you deliver to my city?', 'What are the prices?'],
        'services'    => ['What services do you offer?', 'How do we get started?', 'What is the typical timeline?'],
        'saas'        => ['What does the product do?', 'Is there a free trial?', 'How is pricing structured?'],
        'local'       => ['Where are you located?', 'What are your hours?', 'Do I need an appointment?'],
        'health'      => ['Which treatments do you offer?', 'How do I book an appointment?', 'Where is the clinic?'],
        'realestate'  => ['What properties are available?', 'Which areas do you cover?', 'Can I book a visit?'],
        'automotive'  => ['Which models do you have?', 'Do you offer servicing?', 'Can I book a test drive?'],
        'travel'      => ['Which destinations do you cover?', 'Do you help with visas?', 'How do we book a package?'],
        'b2b'         => ['What do you supply?', 'What is the MOQ?', 'Can you deliver to our warehouse?'],
        'freelancer'  => ['What services do you offer?', 'How do you price projects?', 'When are you available?'],
    ];
    $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower($industryKey)) ?? '';

    return $map[$key] ?? ['What do you offer?', 'Where are you located?', 'How do we get started?'];
}

/**
 * Facts the live AI must not mix up: industry, venue address vs the rep's home city.
 *
 * @param array<string, mixed> $bot
 */
function industry_runtime_facts_block(array $bot): string
{
    require_once __DIR__ . '/bot-knowledge.php';

    $profile = function_exists('bot_owner_profile_fields') ? bot_owner_profile_fields($bot) : [];
    $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($bot['industry_key'] ?? '')))) ?? '';
    $tpl = $key !== '' ? industry_template($key) : null;
    $label = is_array($tpl) ? trim((string) ($tpl['label'] ?? '')) : trim((string) ($profile['industry'] ?? ''));
    $address = trim((string) ($profile['address'] ?? ''));
    $bizCity = function_exists('bot_extract_city') ? bot_extract_city($address) : '';
    $persona = trim((string) ($bot['rep_persona'] ?? ''));
    $homeCity = '';
    if (preg_match('/\b(?:live[s]? in|based in|from)\s+([A-Za-z][A-Za-z]+(?:\s+[A-Za-z][A-Za-z]+)?)/u', $persona, $m)) {
        $homeCity = trim($m[1]);
    } elseif (preg_match('/\b(Lahore|Karachi|Islamabad|Rawalpindi|Multan|Faisalabad|Peshawar|Quetta|London|Dubai|Riyadh)\b/u', $persona, $m)) {
        $homeCity = (string) $m[1];
    }

    $lines = ['───── BUSINESS FACTS (venue vs personal life — never mix these) ─────'];
    if ($label !== '') {
        $lines[] = 'Industry: ' . $label . '. Stay in this industry. Do not talk as a restaurant, clinic, or shop unless that is this industry.';
    }
    if ($address !== '') {
        $lines[] = 'Business address (shop / venue / office): ' . $address;
    }
    if ($bizCity !== '') {
        $lines[] = 'Business city: ' . $bizCity . '. If they ask where the business / restaurant / shop / clinic / office is, use THIS city and the address above.';
    }
    if ($homeCity !== '' && strcasecmp($homeCity, $bizCity) !== 0) {
        $lines[] = 'You (the person) may live in ' . $homeCity . '. That is YOUR home city only — never say the business is in ' . $homeCity . '.';
    }
    $lines[] = 'If they ask where you live / are from personally, you may mention your home city and still give the business address if they mean the venue.';
    if (count($lines) <= 2) {
        return '';
    }

    return implode("\n", $lines);
}

/**
 * When Business Info industry changes: fill empty knowledge, replace stock templates, keep custom text.
 *
 * @param array<string, mixed> $bot
 * @return array{success: bool, business_model: string, bot_knowledge: string, business_mode: string, conversion_goal: string, industry_key: string}
 */
function industry_apply_key_change(array $bot, string $newKey, string $oldKey): array
{
    $tpl = industry_template($newKey);
    if ($tpl === null) {
        return [
            'success'         => false,
            'business_model'  => (string) ($bot['business_model'] ?? ''),
            'bot_knowledge'   => (string) ($bot['bot_knowledge'] ?? ''),
            'business_mode'   => (string) ($bot['business_mode'] ?? ''),
            'conversion_goal' => (string) ($bot['conversion_goal'] ?? ''),
            'industry_key'    => $oldKey,
        ];
    }

    $knowledge = trim((string) ($bot['bot_knowledge'] ?? ''));
    $force = $knowledge === '';
    if (!$force && $oldKey !== '' && $oldKey !== $newKey) {
        $oldTpl = industry_template($oldKey);
        if (is_array($oldTpl)) {
            $stock = trim('Industry: ' . $oldTpl['label'] . "\n\n" . $oldTpl['knowledge']);
            if ($knowledge === $stock) {
                $force = true;
            }
        }
    }

    $applied = industry_apply_to_bot($bot, $newKey, $force);
    if (!$force && $knowledge !== '') {
        $rewritten = preg_replace('/^Industry:\s*.+$/mu', 'Industry: ' . $tpl['label'], $knowledge, 1);
        $applied['bot_knowledge'] = is_string($rewritten) && $rewritten !== '' ? $rewritten : $knowledge;
    }
    $applied['industry_key'] = $newKey;

    return $applied;
}

/**
 * Apply template starter text to bot fields (does not overwrite non-empty unless $force).
 *
 * @return array{success: bool, business_model: string, bot_knowledge: string, business_mode: string, conversion_goal: string}
 */
function industry_apply_to_bot(array $bot, string $industryKey, bool $force = false): array
{
    $tpl = industry_template($industryKey);
    if ($tpl === null) {
        return ['success' => false, 'business_model' => '', 'bot_knowledge' => '', 'business_mode' => '', 'conversion_goal' => ''];
    }

    $offer = trim((string) ($bot['business_model'] ?? ''));
    $knowledge = trim((string) ($bot['bot_knowledge'] ?? ''));

    if ($force || $offer === '') {
        $offer = $tpl['offer'];
    }
    if ($force || $knowledge === '') {
        $knowledge = "Industry: {$tpl['label']}\n\n" . $tpl['knowledge'];
    }

    return [
        'success'         => true,
        'business_model'  => $offer,
        'bot_knowledge'   => $knowledge,
        'business_mode'   => $tpl['business_mode'],
        'conversion_goal' => $tpl['conversion_goal'],
        'industry_key'    => $industryKey,
    ];
}
