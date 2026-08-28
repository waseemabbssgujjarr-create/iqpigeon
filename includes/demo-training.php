<?php
/**
 * Demo bot training — visitors provide KB before chatting.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai-personality.php';

/**
 * @return bool
 */
function is_demo_bot(int $botId): bool
{
    $demo = get_demo_bot();
    return $demo !== null && (int) $demo['id'] === $botId;
}

/**
 * @return array<string, mixed>|null
 */
function get_lead_training_data(array $lead): ?array
{
    $raw = $lead['qualification_data'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }

    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($data) || empty($data['demo_training'])) {
        return null;
    }

    return $data['demo_training'];
}

/**
 * @return bool
 */
function lead_has_demo_training(array $lead): bool
{
    $training = get_lead_training_data($lead);
    return $training !== null && ($training['status'] ?? '') === 'ready';
}

/**
 * Fetch plain text from a public website (best-effort).
 */
function fetch_website_snippet(string $url, int $maxLen = 6000): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    require_once __DIR__ . '/website-import.php';

    $response = website_import_http_get($url);
    $html = ($response['code'] >= 200 && $response['code'] < 400) ? ($response['body'] ?? '') : '';
    if ($html === '') {
        return '';
    }

    $text = website_import_extract_business_text($html, $url);
    if ($text === '') {
        return '';
    }

    return mb_substr($text, 0, $maxLen);
}

/**
 * Save demo training on a lead.
 *
 * @param array{text?: string, website?: string, pdf_url?: string, business_name?: string} $input
 * @return array{success: bool, message: string, training?: array<string, mixed>}
 */
function save_demo_training(int $leadId, array $input): array
{
    $text = trim($input['text'] ?? '');
    $website = trim($input['website'] ?? '');
    $pdfUrl = trim($input['pdf_url'] ?? '');
    $businessName = trim($input['business_name'] ?? '');

    if ($text === '' && $website === '' && $pdfUrl === '') {
        return ['success' => false, 'message' => 'Please provide business info as text, a website URL, or a document link.'];
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'Please enter a valid website URL (include https://).'];
    }

    if ($pdfUrl !== '' && !filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'Please enter a valid document link (Google Drive, PDF URL, etc.).'];
    }

    $websiteContent = '';
    if ($website !== '') {
        $websiteContent = fetch_website_snippet($website);
    }

    $training = [
        'status'           => 'ready',
        'business_name'    => $businessName,
        'text'             => mb_substr($text, 0, 12000),
        'website'          => $website,
        'website_content'  => $websiteContent,
        'pdf_url'          => $pdfUrl,
        'completed_at'     => date('c'),
    ];

    $payloadData = ['demo_training' => $training];
    $existing = db_fetch('SELECT qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
    if (!empty($existing['qualification_data'])) {
        $prev = json_decode($existing['qualification_data'], true);
        if (is_array($prev)) {
            $payloadData = array_merge($prev, $payloadData);
        }
    }

    $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

    db_execute(
        'UPDATE leads SET qualification_data = ?, notes = ? WHERE id = ?',
        'ssi',
        [
            $payload,
            'Demo training: ' . ($businessName ?: 'Visitor business'),
            $leadId,
        ]
    );

    return ['success' => true, 'message' => 'Training saved!', 'training' => $training];
}

/**
 * System prompt for demo bot with visitor-provided knowledge.
 *
 * @param array<string, mixed> $bot
 * @param array<string, mixed> $training
 */
function build_demo_system_prompt(array $bot, array $training, string $companyName): string
{
    $botName = $bot['name'] ?? 'Your Assistant';
    $kb = build_business_knowledge_block($training);
    $businessLabel = $training['business_name'] ?? $companyName;

    $system = "You are {$botName}, the AI assistant for {$businessLabel}. "
        . "Talk naturally, like ChatGPT — answer the visitor's message directly using the business knowledge below, do not recite scripts, and never say you are being trained or in demo mode. "
        . "If you do not know something, say so and ask a short clarifying question — never invent prices, policies, or facts. "
        . "Reply in the visitor's own language and keep it concise.";

    return $system . $kb . "\n\nYour display name: {$botName}\n";
}
