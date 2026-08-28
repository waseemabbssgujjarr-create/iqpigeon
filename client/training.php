<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iqp-ui.php';
require_once __DIR__ . '/../includes/bot-knowledge.php';
require_once __DIR__ . '/../includes/commerce-schema.php';
require_once __DIR__ . '/../includes/platform-schema.php';
require_once __DIR__ . '/../includes/industry-templates.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/lead-lifecycle.php';
require_once __DIR__ . '/../includes/qualification-flow.php';
platform_ensure_all_silent();

$user   = require_login();
$userId = (int)$user['id'];

if (isset($_GET['tab']) && (string) $_GET['tab'] === 'training') {
    redirect('/client/training?tab=knowledge');
}

$tabAliases = [
    'industry'  => 'business',
    'hours'     => 'business',
    'menu'      => 'knowledge',
    'triggers'  => 'knowledge',
    'qualify'   => 'sales',
    'behavior'  => 'conversation',
];
$requestedTab = preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? ''));
if ($requestedTab !== '' && isset($tabAliases[$requestedTab]) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $qs = $_GET;
    $qs['tab'] = $tabAliases[$requestedTab];
    redirect('/client/training?' . http_build_query($qs));
}

ensure_bots_schema();
ensure_lead_lifecycle_schema();
ensure_qualification_flow_schema();
ensure_client_starter_bot($userId);
ensure_commerce_schema(); // brings business_mode / conversion_goal columns online too
ensure_user_profile_schema(); // avatar_url / bio / address / phone / industry

$bot = db_fetch('SELECT * FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
if (!$bot) {
    redirect('/client/dashboard');
}

$botId = (int)$bot['id'];
$message = '';
$error   = '';

// Handle POST saves
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_rep_name') {
        $repName = mb_substr(trim((string) ($_POST['rep_name'] ?? '')), 0, 30);
        if ($repName === '') {
            $error = 'Enter a name for your assistant.';
        } else {
            db_execute(
                'UPDATE bots SET rep_name=?, knowledge_updated_at=NOW() WHERE id=? AND user_id=?',
                'sii',
                [$repName, $botId, $userId]
            );
            redirect('/client/training?tab=conversation&saved=name');
        }
        $bot = db_fetch('SELECT * FROM bots WHERE id=? AND user_id=?', 'ii', [$botId, $userId]);
    }

    if ($action === 'save_business_info') {
        $repName  = mb_substr(bot_persist_field((string) ($_POST['rep_name'] ?? ''), (string) ($bot['rep_name'] ?? '')), 0, 30);
        $bizName  = mb_substr(trim($_POST['company_name'] ?? ''), 0, 120);
        $postedIndustryKey = industry_key_from_posted((string) ($_POST['industry_key'] ?? $_POST['industry'] ?? ''));
        $oldIndustryKey = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($bot['industry_key'] ?? '')))) ?: '';
        $industryKeySave = $postedIndustryKey !== '' ? $postedIndustryKey : $oldIndustryKey;
        $industryTpl = $industryKeySave !== '' ? industry_template($industryKeySave) : null;
        $industry = is_array($industryTpl)
            ? (string) $industryTpl['label']
            : mb_substr(bot_persist_field((string) ($_POST['industry'] ?? ''), (string) ($user['industry'] ?? '')), 0, 80);
        $bizEmail = mb_substr(trim($_POST['business_email'] ?? ''), 0, 120);
        $bizPhone = mb_substr(trim($_POST['business_phone'] ?? ''), 0, 30);
        $website  = mb_substr(bot_persist_field((string) ($_POST['website_url'] ?? ''), (string) ($bot['website_url'] ?? '')), 0, 255);
        $desc     = mb_substr(trim($_POST['business_description'] ?? ''), 0, 500);
        $address  = mb_substr(trim($_POST['business_address'] ?? ''), 0, 255);
        $timezone = mb_substr(trim($_POST['timezone'] ?? ''), 0, 80);
        $currency = mb_substr(trim($_POST['currency'] ?? ''), 0, 20);

        // Business Logo upload — same validated pipeline as client/settings.php avatar upload.
        $logoUpdate = '';
        if (!empty($_FILES['logo_file']['tmp_name'])) {
            $file  = $_FILES['logo_file'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
            $ftype = @mime_content_type($file['tmp_name']);
            if (!in_array($ftype, $allowed, true)) {
                $error = 'Invalid logo type. Use JPG, PNG, SVG or WebP.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'Logo too large. Max 2MB.';
            } else {
                $ext = match ($ftype) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/svg+xml' => 'svg',
                    default => 'jpg',
                };
                $dir = dirname(__DIR__) . '/uploads/avatars';
                if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                $fname = 'logo_' . $userId . '_' . time() . '.' . $ext;
                $dest  = $dir . '/' . $fname;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $logoUpdate = '/uploads/avatars/' . $fname;
                } else {
                    $error = 'Could not save logo. Check server permissions.';
                }
            }
        }

        $postedPersona = trim((string) ($_POST['rep_persona'] ?? ''));
        $repPersona = $postedPersona !== ''
            ? bot_limit_words($postedPersona, 5000)
            : (string) ($bot['rep_persona'] ?? '');
        if (mb_strlen($repPersona) > 200000) {
            $repPersona = mb_substr($repPersona, 0, 200000);
        }

        $tzOptions = [
            'Asia/Karachi'     => 'Asia/Karachi',
            'UTC'              => 'UTC',
            'Asia/Kolkata'     => 'Asia/Kolkata',
            'America/New_York' => 'America/New_York',
            'Europe/London'    => 'Europe/London',
            'Asia/Dubai'       => 'Asia/Dubai',
        ];
        $timezoneIana = $timezone;
        if (!isset($tzOptions[$timezoneIana])) {
            $timezoneIana = (string) ($user['pref_timezone'] ?? 'Asia/Karachi');
        }
        $currencySave = strtoupper($currency);
        if (!in_array($currencySave, ['PKR', 'USD', 'EUR', 'GBP'], true)) {
            $currencySave = (string) ($user['pref_currency'] ?? 'PKR');
        }

        if ($error === '') {
            require_once dirname(__DIR__) . '/includes/bot-knowledge.php';
            ensure_bots_schema();
            db_execute(
                'UPDATE bots SET rep_name=?, website_url=?, rep_persona=?, knowledge_updated_at=NOW() WHERE id=? AND user_id=?',
                'sssii',
                [$repName, $website, $repPersona, $botId, $userId]
            );
            if ($industryKeySave !== '') {
                if ($industryKeySave !== $oldIndustryKey) {
                    $applied = industry_apply_key_change($bot, $industryKeySave, $oldIndustryKey);
                    $keepCustomQualify = function_exists('qualification_is_custom') && qualification_is_custom($bot);
                    $modeSave = $keepCustomQualify
                        ? (string) ($bot['business_mode'] ?? 'mixed')
                        : ($applied['business_mode'] !== '' ? $applied['business_mode'] : (string) ($bot['business_mode'] ?? 'mixed'));
                    $goalSave = $keepCustomQualify
                        ? (string) ($bot['conversion_goal'] ?? '')
                        : ($applied['conversion_goal'] !== '' ? $applied['conversion_goal'] : (string) ($bot['conversion_goal'] ?? ''));
                    db_execute(
                        'UPDATE bots SET industry_key=?, business_model=?, bot_knowledge=?, business_mode=?, conversion_goal=?, knowledge_updated_at=NOW() WHERE id=? AND user_id=?',
                        'sssssii',
                        [
                            $industryKeySave,
                            $applied['business_model'],
                            $applied['bot_knowledge'],
                            $modeSave,
                            $goalSave,
                            $botId,
                            $userId,
                        ]
                    );
                    if (!$keepCustomQualify && $oldIndustryKey === '') {
                        $seedBot = array_merge($bot, [
                            'industry_key'    => $industryKeySave,
                            'business_mode'   => $modeSave,
                            'conversion_goal' => $goalSave,
                        ]);
                        $existingQs = qualification_load_for_bot($seedBot);
                        $hasQ = false;
                        foreach ((array) ($existingQs['questions'] ?? []) as $q) {
                            if (trim((string) ($q['text'] ?? '')) !== '') {
                                $hasQ = true;
                                break;
                            }
                        }
                        if (!$hasQ) {
                            qualification_save_for_bot($botId, $userId, qualification_defaults_for_bot($seedBot), false);
                        }
                    }
                } else {
                    db_execute(
                        'UPDATE bots SET industry_key=? WHERE id=? AND user_id=?',
                        'sii',
                        [$industryKeySave, $botId, $userId]
                    );
                }
            }
            if ($bizName !== '') {
                db_execute('UPDATE bots SET name=? WHERE id=? AND user_id=?', 'sii', [$bizName, $botId, $userId]);
            }

            $companyForUser = $bizName !== '' ? $bizName : trim((string) ($user['company_name'] ?? ''));
            $sql = 'UPDATE users SET company_name=?, industry=?, bio=?, address=?, phone=?, pref_timezone=?, pref_currency=?';
            $types = 'sssssss';
            $params = [$companyForUser, $industry, $desc, $address, $bizPhone, $timezoneIana, $currencySave];
            if ($logoUpdate !== '') {
                $sql .= ', avatar_url=?';
                $types .= 's';
                $params[] = $logoUpdate;
            }
            if ($bizEmail !== '') {
                $sql .= ', business_email=?';
                $types .= 's';
                $params[] = $bizEmail;
            }
            $sql .= ' WHERE id=?';
            $types .= 'i';
            $params[] = $userId;
            db_execute($sql, $types, $params);

            $bot  = db_fetch('SELECT * FROM bots WHERE id=? AND user_id=?', 'ii', [$botId, $userId]);
            $user = db_fetch('SELECT * FROM users WHERE id=?', 'i', [$userId]);
            redirect('/client/training?tab=business&saved=1');
        }
        $bot  = db_fetch('SELECT * FROM bots WHERE id=? AND user_id=?', 'ii', [$botId, $userId]);
        $user = db_fetch('SELECT * FROM users WHERE id=?', 'i', [$userId]);
    }

    if ($action === 'save_knowledge') {
        $knowledge = mb_substr(trim($_POST['knowledge'] ?? ''), 0, 15000);
        db_execute('UPDATE bots SET bot_knowledge=?, knowledge_updated_at=NOW() WHERE id=? AND user_id=?',
            'sii', [$knowledge, $botId, $userId]);
        $warn = (function_exists('knowledge_text_has_unresolved_placeholders')
            && knowledge_text_has_unresolved_placeholders($knowledge))
            ? '&placeholders=1'
            : '';
        $bot = db_fetch('SELECT * FROM bots WHERE id=? AND user_id=?', 'ii', [$botId, $userId]);
        redirect('/client/training?tab=knowledge&saved=1' . $warn);
    }

    if ($action === 'save_menu_cards' || $action === 'auto_menu_cards') {
        $allProducts = catalog_products_for_bot($botId, false);
        $byCategory = training_products_by_category($allProducts);
        $cards = [];

        if ($action === 'auto_menu_cards') {
            $cards = training_auto_menu_cards($byCategory);
            $message = $cards === []
                ? 'Add products in Shop first, then auto-fill menu cards.'
                : 'Menu cards created from your Shop catalog.';
        } else {
            $submitted = $_POST['menu_cards'] ?? [];
            if (is_array($submitted)) {
                $validIds = [];
                foreach ($allProducts as $p) {
                    $validIds[(int) $p['id']] = true;
                }
                foreach ($submitted as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 80);
                    $category = mb_substr(trim((string) ($row['category'] ?? '')), 0, 80);
                    $rawIds = (array) ($row['product_ids'] ?? []);
                    $productIds = [];
                    foreach ($rawIds as $pid) {
                        $pid = (int) $pid;
                        if ($pid > 0 && isset($validIds[$pid])) {
                            $productIds[] = $pid;
                        }
                    }
                    $productIds = array_values(array_unique($productIds));
                    if ($title === '' || $productIds === []) {
                        continue;
                    }
                    $cards[] = [
                        'id'          => mb_substr(trim((string) ($row['id'] ?? '')), 0, 64)
                            ?: ('card-' . substr(md5($category . $title . implode(',', $productIds)), 0, 12)),
                        'title'       => $title,
                        'category'    => $category !== '' ? $category : 'General',
                        'product_ids' => array_slice($productIds, 0, 30),
                    ];
                }
            }
            $message = 'Menu cards saved.';
        }

        bot_training_meta_update($botId, $userId, ['menu_cards' => $cards]);
        $bot = db_fetch('SELECT * FROM bots WHERE id=? AND user_id=?', 'ii', [$botId, $userId]);
        redirect('/client/training?tab=knowledge&saved=menu');
    }

    if ($action === 'apply_industry') {
        ensure_lead_lifecycle_schema();
        require_once __DIR__ . '/../includes/qualification-flow.php';
        ensure_qualification_flow_schema();
        $key        = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim($_POST['industry_key'] ?? '')));
        $currentKey = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($bot['industry_key'] ?? ''))));
        $tpl        = industry_template($key);
        if ($tpl === null) {
            $error = 'Pick a valid industry.';
        } else {
            $applied = industry_apply_key_change($bot, $key, $currentKey);
            $keepCustomQualify = qualification_is_custom($bot);
            $modeSave = $keepCustomQualify
                ? (string) ($bot['business_mode'] ?? 'mixed')
                : $applied['business_mode'];
            $goalSave = $keepCustomQualify
                ? (string) ($bot['conversion_goal'] ?? '')
                : $applied['conversion_goal'];
            db_execute(
                'UPDATE bots SET industry_key=?, business_model=?, bot_knowledge=?, business_mode=?, conversion_goal=?, knowledge_updated_at=NOW() WHERE id=? AND user_id=?',
                'sssssii',
                [$key, $applied['business_model'], $applied['bot_knowledge'], $modeSave, $goalSave, $botId, $userId]
            );
            db_execute('UPDATE users SET industry = ? WHERE id = ?', 'si', [$tpl['label'], $userId]);
            redirect('/client/training?tab=business&applied=1');
        }
    }

    if ($action === 'save_qualification') {
        require_once __DIR__ . '/../includes/qualification-flow.php';
        $postedQuestions = $_POST['questions'] ?? [];
        if (!is_array($postedQuestions)) {
            $postedQuestions = [];
        }
        qualification_save_for_bot($botId, $userId, [
            'questions'          => $postedQuestions,
            'qualify_trigger'    => $_POST['qualify_trigger'] ?? '',
            'qualify_message'    => $_POST['qualify_message'] ?? '',
            'disqualify_message' => $_POST['disqualify_message'] ?? '',
            'business_mode'      => $_POST['business_mode'] ?? 'mixed',
            'conversion_goal'    => $_POST['conversion_goal'] ?? '',
        ], true);
        redirect('/client/training?tab=sales&saved=1');
    }

    if ($action === 'reset_qualification') {
        require_once __DIR__ . '/../includes/qualification-flow.php';
        if (qualification_reset_from_industry($bot, $userId)) {
            redirect('/client/training?tab=sales&reset=1');
        }
        $error = 'Apply an industry template first, then reset Qualify to its starter questions.';
    }

    if ($action === 'save_hours') {
        $alwaysOpen = !empty($_POST['always_open']);
        $days = [];
        foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day) {
            $slug = strtolower($day);
            $days[$day] = [
                'enabled' => isset($_POST['day_enabled'][$slug]),
                'open'    => mb_substr((string) ($_POST['day_open'][$slug] ?? '10:00'), 0, 5),
                'close'   => mb_substr((string) ($_POST['day_close'][$slug] ?? '22:00'), 0, 5),
            ];
        }
        bot_training_meta_update($botId, $userId, [
            'operating_hours' => ['always_open' => $alwaysOpen, 'days' => $days],
        ]);
        redirect('/client/training?tab=business&saved=hours');
    }

    if ($action === 'save_closed_behavior') {
        $text = mb_substr(trim($_POST['closed_behavior'] ?? ''), 0, 500);
        bot_training_meta_update($botId, $userId, ['closed_behavior' => $text]);
        redirect('/client/training?tab=business&saved=hours');
    }

    if ($action === 'save_conversation') {
        require_once __DIR__ . '/../includes/ai-instruction-layers.php';
        $repName = mb_substr(trim((string) ($_POST['rep_name'] ?? '')), 0, 30);
        if ($repName === '') {
            $repName = (string) ($bot['rep_name'] ?? '');
        }
        $postedPersona = trim((string) ($_POST['rep_persona'] ?? ''));
        $repPersona = $postedPersona !== ''
            ? bot_limit_words($postedPersona, 5000)
            : '';
        if (mb_strlen($repPersona) > 200000) {
            $repPersona = mb_substr($repPersona, 0, 200000);
        }
        db_execute(
            'UPDATE bots SET rep_name=?, rep_persona=?, knowledge_updated_at=NOW() WHERE id=? AND user_id=?',
            'ssii',
            [$repName, $repPersona, $botId, $userId]
        );
        $conv = [
            'tone'             => ai_normalize_allowed_value((string) ($_POST['tone'] ?? ''), ai_allowed_customer_tone_values()),
            'formality'        => ai_normalize_allowed_value((string) ($_POST['formality'] ?? ''), ai_allowed_customer_formality_values()),
            'language'         => ai_normalize_allowed_value((string) ($_POST['language'] ?? ''), ai_allowed_customer_language_values()),
            'response_length'  => ai_normalize_allowed_value((string) ($_POST['response_length'] ?? ''), ai_allowed_customer_length_values(), 'Concise'),
            'emoji'            => ai_normalize_allowed_value((string) ($_POST['emoji'] ?? ''), ai_allowed_customer_emoji_values(), 'Occasional'),
            'personal_touches' => !empty($_POST['personal_touches']),
        ];
        bot_training_meta_update($botId, $userId, ['conversation' => $conv]);
        redirect('/client/training?tab=conversation&saved=1');
    }

    if ($action === 'add_trigger_word') {
        $word   = mb_substr(trim($_POST['word'] ?? ''), 0, 60);
        $intent = mb_substr(trim($_POST['intent'] ?? ''), 0, 80);
        if ($word === '') {
            $error = 'Type a trigger word or phrase.';
        } else {
            $meta = bot_training_meta($bot);
            $meta['trigger_words'][] = ['word' => $word, 'intent' => $intent, 'is_active' => true];
            bot_training_meta_update($botId, $userId, ['trigger_words' => $meta['trigger_words']]);
            $message = 'Keyword hint added.';
            redirect('/client/training?tab=knowledge&saved=hints');
        }
    }

    if ($action === 'delete_trigger_word') {
        $idx  = (int) ($_POST['index'] ?? -1);
        $meta = bot_training_meta($bot);
        if (isset($meta['trigger_words'][$idx])) {
            unset($meta['trigger_words'][$idx]);
            bot_training_meta_update($botId, $userId, ['trigger_words' => array_values($meta['trigger_words'])]);
            redirect('/client/training?tab=knowledge&saved=hints');
        }
    }

    if ($action === 'toggle_trigger_word') {
        $idx  = (int) ($_POST['index'] ?? -1);
        $meta = bot_training_meta($bot);
        if (isset($meta['trigger_words'][$idx])) {
            $meta['trigger_words'][$idx]['is_active'] = empty($meta['trigger_words'][$idx]['is_active']);
            bot_training_meta_update($botId, $userId, ['trigger_words' => $meta['trigger_words']]);
            redirect('/client/training?tab=knowledge&saved=hints');
        }
    }
}

// Data for view
$repName         = trim((string) ($bot['rep_name'] ?? ''));
$repNamePreview  = $repName !== '' ? $repName : 'Assistant';
$repPersona      = trim((string) ($bot['rep_persona'] ?? ''));
$bizName     = (string)($user['company_name'] ?? '');
$industry    = (string)($user['industry'] ?? '');
$knowledge   = (string)($bot['bot_knowledge'] ?? '');
$websiteUrl  = (string)($bot['website_url'] ?? '');
$updated     = (string)($bot['knowledge_updated_at'] ?? '');
$industryKey = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($bot['industry_key'] ?? '')))) ?: '';
if ($industryKey === '' && $industry !== '') {
    $industryKey = industry_key_from_posted($industry);
}
$trainingMeta = bot_training_meta($bot);
$menuCards = (array) ($trainingMeta['menu_cards'] ?? []);
$conversationPrefs = is_array($trainingMeta['conversation'] ?? null) ? $trainingMeta['conversation'] : [];
require_once __DIR__ . '/../includes/ai-instruction-layers.php';
$trainingReady = ai_training_readiness($bot, $user);
$industryTemplates = industry_templates_all();
$qualifyFlow = qualification_load_for_bot($bot);
$qualifyTypes = qualification_question_types();
$qualifyModes = bot_business_modes();
$qualifyGoals = bot_conversion_goals();

// WhatsApp connection status
$wa = db_fetch('SELECT * FROM client_whatsapp_accounts WHERE client_id=? AND connection_status=\'active\' ORDER BY connected_at DESC LIMIT 1', 'i', [$userId]);
if ($wa && !empty($wa['phone_display_number'])) {
    whatsapp_sync_user_business_phone($userId, (string) $wa['phone_display_number']);
    $user = db_fetch('SELECT * FROM users WHERE id=?', 'i', [$userId]) ?: $user;
}
$waDisplayPhone = trim((string) ($wa['phone_display_number'] ?? ''));
$businessPhone = trim((string) ($user['phone'] ?? ''));
if ($businessPhone === '' || whatsapp_is_placeholder_business_phone($businessPhone)) {
    $businessPhone = $waDisplayPhone;
}
$businessPhoneFromWhatsApp = $waDisplayPhone !== ''
    && ($businessPhone === $waDisplayPhone || whatsapp_is_placeholder_business_phone((string) ($user['phone'] ?? '')));

// Products for Menu tab + Industry preview
$products = catalog_products_for_bot($botId, false);
$productsByCategory = training_products_by_category($products);
$productMap = [];
foreach ($products as $p) {
    $productMap[(int) $p['id']] = $p;
}
$prodLimit = array_slice($products, 0, 5);

$csrf = csrf_token();

require __DIR__ . '/../includes/views/client-training.php';
