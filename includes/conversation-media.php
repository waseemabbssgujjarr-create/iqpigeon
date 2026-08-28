<?php
/**
 * Conversation media — uploads, inbound persistence, message helpers.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/domain.php';

function conversation_media_uploads_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/conversation-media';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function conversation_media_public_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return app_url('/uploads/conversation-media/' . $relativePath);
}

/**
 * Copy downloaded WhatsApp media to a public uploads folder for dashboard display.
 *
 * @return array{success: bool, url?: string, error?: string}
 */
function conversation_persist_whatsapp_media(string $localPath, string $mediaId, string $mime): array
{
    if (!is_readable($localPath) || $mediaId === '') {
        return ['success' => false, 'error' => 'Missing media file'];
    }

    $ext = match (true) {
        str_contains($mime, 'ogg') => 'ogg',
        str_contains($mime, 'mpeg') => 'mp3',
        str_contains($mime, 'mp4') && str_contains($mime, 'audio') => 'm4a',
        str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
        str_contains($mime, 'png') => 'png',
        str_contains($mime, 'webp') => 'webp',
        default => 'bin',
    };

    $subdir = 'inbound';
    $destDir = conversation_media_uploads_dir() . '/' . $subdir;
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }

    $safeId = preg_replace('/[^a-zA-Z0-9._-]/', '', $mediaId) ?: bin2hex(random_bytes(8));
    $filename = $safeId . '.' . $ext;
    $dest = $destDir . '/' . $filename;

    if (!@copy($localPath, $dest)) {
        return ['success' => false, 'error' => 'Could not persist media'];
    }

    return ['success' => true, 'url' => conversation_media_public_url($subdir . '/' . $filename)];
}

/**
 * @return array{success: bool, url?: string, path?: string, error?: string}
 */
function conversation_save_outbound_image(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'No image selected.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed.'];
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }

    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'error' => 'Image must be under 5 MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return ['success' => false, 'error' => 'Use JPG, PNG, or WebP.'];
    }

    $userDir = conversation_media_uploads_dir() . '/' . $userId;
    if (!is_dir($userDir)) {
        @mkdir($userDir, 0755, true);
    }

    $name = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $userDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Could not save image.'];
    }

    return [
        'success' => true,
        'path'    => $dest,
        'url'     => conversation_media_public_url($userId . '/' . $name),
    ];
}

/**
 * Insert a conversation row (supports optional media columns).
 */
function conversation_insert(int $leadId, string $role, string $message, ?string $mediaType = null, ?string $mediaUrl = null): int
{
    ensure_conversations_schema();

    $mediaType = $mediaType !== null && $mediaType !== '' ? $mediaType : null;
    $mediaUrl = $mediaUrl !== null && $mediaUrl !== '' ? $mediaUrl : null;

    if ($mediaType !== null || $mediaUrl !== null) {
        return db_insert(
            'INSERT INTO conversations (lead_id, role, message, media_type, media_url) VALUES (?, ?, ?, ?, ?)',
            'issss',
            [$leadId, $role, $message, $mediaType ?? '', $mediaUrl ?? '']
        );
    }

    return db_insert(
        'INSERT INTO conversations (lead_id, role, message) VALUES (?, ?, ?)',
        'iss',
        [$leadId, $role, $message]
    );
}

/**
 * Detect display kind for a conversation row (supports legacy text markers).
 *
 * @param array<string, mixed> $msg
 * @return array{kind: string, text: string, media_url: string|null, transcript: string|null}
 */
function conversation_message_display(array $msg): array
{
    $text = (string) ($msg['message'] ?? '');
    $mediaType = trim((string) ($msg['media_type'] ?? ''));
    $mediaUrl = trim((string) ($msg['media_url'] ?? ''));

    if ($mediaType === '' && $mediaUrl === '') {
        if (preg_match('/^\[Voice message from customer\]:\s*(.*)$/us', trim($text), $m)) {
            return [
                'kind'        => 'voice',
                'text'        => $text,
                'media_url'   => null,
                'transcript'  => trim($m[1]),
            ];
        }
        if (preg_match('/^\[Customer sent an image\]\s*(.*)$/us', trim($text), $m)) {
            return [
                'kind'        => 'image',
                'text'        => $text,
                'media_url'   => null,
                'transcript'  => trim($m[1]) !== '' ? trim($m[1]) : null,
            ];
        }

        return ['kind' => 'text', 'text' => $text, 'media_url' => null, 'transcript' => null];
    }

    $kind = in_array($mediaType, ['voice', 'audio', 'image'], true) ? $mediaType : 'text';
    if ($kind === 'audio') {
        $kind = 'voice';
    }

    $transcript = null;
    if ($kind === 'voice' && preg_match('/^\[Voice message from customer\]:\s*(.*)$/us', trim($text), $m)) {
        $transcript = trim($m[1]);
    } elseif ($kind === 'image' && preg_match('/^\[Customer sent an image\]\s*(.*)$/us', trim($text), $m)) {
        $transcript = trim($m[1]) !== '' ? trim($m[1]) : null;
    }

    return [
        'kind'       => $kind,
        'text'       => $text,
        'media_url'  => $mediaUrl !== '' ? $mediaUrl : null,
        'transcript' => $transcript,
    ];
}

/**
 * Render one conversation bubble HTML (for PHP page + poll API consumers mirror in JS).
 *
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $lead
 * @param array<string, mixed> $opts  rep_name, lead_name
 */
function conversation_render_message_html(array $msg, array $lead, array $opts = []): string
{
    $role = (string) ($msg['role'] ?? '');
    if ($role === 'system') {
        return '';
    }

    $display = conversation_message_display($msg);
    $isAssistant = $role === 'assistant';
    $repName = (string) ($opts['rep_name'] ?? 'Team');
    $leadName = (string) ($opts['lead_name'] ?? ($lead['name'] ?? 'Lead'));
    $createdAt = (string) ($msg['created_at'] ?? '');
    $iso = datetime_to_iso($createdAt);
    $timeLabel = format_time($createdAt);
    $message = (string) ($msg['message'] ?? '');

    $isDecision = str_contains($message, '[DECISION:') || str_contains($message, 'AI Decision');
    if ($isAssistant && (str_starts_with($message, '⚡') || $isDecision)) {
        preg_match('/Trigger: (.+)/', $message, $triggerMatch);
        $triggerText = $triggerMatch[1] ?? 'Qualification criteria met';
        return '<div class="flex justify-center my-md conv-system-msg">'
            . '<div class="bg-tertiary-container/20 border border-tertiary text-on-tertiary-container px-md py-sm rounded-xl flex items-center gap-sm max-w-[90%]">'
            . '<span class="material-symbols-outlined text-tertiary ai-pulse" style="font-variation-settings:\'FILL\' 1">psychology</span>'
            . '<div><p class="text-label-sm font-bold uppercase tracking-widest">Decision Point</p>'
            . '<p class="text-body-md">Trigger: <span class="font-bold">' . sanitize($triggerText) . '</span></p></div></div></div>';
    }

    $bodyHtml = conversation_render_message_body_html($display);

    if ($isAssistant) {
        return '<div class="flex gap-sm mb-md max-w-[85%] conv-msg conv-msg--assistant" data-msg-id="' . (int) ($msg['id'] ?? 0) . '">'
            . '<span class="w-8 h-8 shrink-0 rounded-full bg-primary text-on-primary flex items-center justify-center">'
            . '<span class="material-symbols-outlined text-sm">person</span></span>'
            . '<div><div class="v2-chat-bubble v2-chat-bubble--in">' . $bodyHtml . '</div>'
            . '<p class="text-label-sm text-outline mt-xs font-label">' . sanitize($repName) . ' · '
            . '<time class="js-local-time" datetime="' . sanitize($iso) . '" data-iso="' . sanitize($iso) . '">' . sanitize($timeLabel) . '</time></p></div></div>';
    }

    return '<div class="flex gap-sm mb-md max-w-[85%] ml-auto justify-end conv-msg conv-msg--user" data-msg-id="' . (int) ($msg['id'] ?? 0) . '">'
        . '<div class="text-right"><div class="v2-chat-bubble v2-chat-bubble--out">' . $bodyHtml . '</div>'
        . '<p class="text-label-sm text-outline mt-xs font-label">' . sanitize($leadName) . ' · '
        . '<time class="js-local-time" datetime="' . sanitize($iso) . '" data-iso="' . sanitize($iso) . '">' . sanitize($timeLabel) . '</time></p></div>'
        . '<span class="w-8 h-8 shrink-0 rounded-full bg-secondary-fixed-dim text-on-secondary-container flex items-center justify-center font-label text-label-sm">'
        . sanitize(get_lead_initial($leadName)) . '</span></div>';
}

/**
 * @param array{kind: string, text: string, media_url: string|null, transcript: string|null} $display
 */
function conversation_render_message_body_html(array $display): string
{
    $kind = $display['kind'] ?? 'text';

    if ($kind === 'image') {
        $html = '<div class="conv-media conv-media--image">';
        if (!empty($display['media_url'])) {
            $html .= '<a href="' . sanitize($display['media_url']) . '" target="_blank" rel="noopener">'
                . '<img src="' . sanitize($display['media_url']) . '" alt="Image" class="conv-media-img rounded-lg max-w-full max-h-64 object-cover" loading="lazy"/></a>';
        } else {
            $html .= '<div class="conv-media-placeholder flex items-center gap-sm text-body-md">'
                . '<span class="material-symbols-outlined">image</span><span>Photo</span></div>';
        }
        if (!empty($display['transcript'])) {
            $html .= '<p class="text-body-md mt-sm whitespace-pre-wrap">' . sanitize($display['transcript']) . '</p>';
        }
        return $html . '</div>';
    }

    if ($kind === 'voice') {
        $html = '<div class="conv-media conv-media--voice flex items-start gap-sm">';
        $html .= '<span class="material-symbols-outlined text-primary shrink-0" style="font-variation-settings:\'FILL\' 1">mic</span>';
        $html .= '<div class="min-w-0">';
        $html .= '<p class="text-label-sm font-label uppercase tracking-wide text-on-surface-variant mb-xs">Voice message</p>';
        if (!empty($display['media_url'])) {
            $html .= '<audio controls preload="none" class="conv-voice-player w-full max-w-xs mb-xs" src="' . sanitize($display['media_url']) . '"></audio>';
        }
        if (!empty($display['transcript'])) {
            $html .= '<p class="text-body-md whitespace-pre-wrap italic">“' . sanitize($display['transcript']) . '”</p>';
        }
        return $html . '</div></div>';
    }

    return '<p class="text-body-md whitespace-pre-wrap">' . sanitize($display['text']) . '</p>';
}

/**
 * Send image from dashboard to lead via WhatsApp.
 */
function conversation_send_image_to_lead(int $leadId, int $userId, string $imageUrl, string $caption = ''): bool
{
    require_once __DIR__ . '/whatsapp.php';

    $lead = db_fetch(
        'SELECT l.*, b.user_id, b.whatsapp_phone_id, b.whatsapp_token, b.whatsapp_verified
         FROM leads l JOIN bots b ON b.id = l.bot_id
         WHERE l.id = ? AND b.user_id = ?',
        'ii',
        [$leadId, $userId]
    );
    if (!$lead) {
        return false;
    }

    pause_lead_bot($leadId, 60);

    $storedMessage = $caption !== '' ? $caption : '[Image]';
    conversation_insert($leadId, 'assistant', $storedMessage, 'image', $imageUrl);
    db_execute('UPDATE leads SET updated_at = NOW() WHERE id = ?', 'i', [$leadId]);

    if (($lead['platform'] ?? '') === 'whatsapp' && !empty($lead['whatsapp_verified'])) {
        $phone = preg_replace('/\D/', '', (string) ($lead['external_id'] ?? ''));
        $token = decrypt_token($lead['whatsapp_token'] ?? '');
        if ($phone !== '' && $token !== false && $token !== '' && !empty($lead['whatsapp_phone_id'])) {
            send_whatsapp_image((string) $lead['whatsapp_phone_id'], (string) $token, $phone, $imageUrl, $caption);
        }
    }

    return true;
}
