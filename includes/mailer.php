<?php
/**
 * Email sending — PHPMailer, native SMTP, or mail() fallback.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/email-templates.php';
require_once __DIR__ . '/smtp-client.php';
require_once __DIR__ . '/integration-settings.php';

if (!function_exists('mail_cfg')) {
    function mail_cfg(string $constantName): string
    {
        return integration_config($constantName);
    }
}

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    try {
        require_once $vendorAutoload;
    } catch (Throwable $e) {
        error_log('PHPMailer autoload failed: ' . $e->getMessage());
    }
}

if (!function_exists('mail_last_transport')) {
    function mail_last_transport(): string
    {
        return $GLOBALS['_mail_last_transport'] ?? '';
    }
}

if (!function_exists('mail_set_last_transport')) {
    function mail_set_last_transport(string $transport): void
    {
        $GLOBALS['_mail_last_transport'] = $transport;
    }
}

/**
 * Which transports to try, in order (exim = PHP mail() / cPanel webmail path).
 *
 * @return string[]
 */
if (!function_exists('mail_transport_order')) {
    function mail_transport_order(): array
    {
        $mode = defined('MAIL_TRANSPORT') ? strtolower((string) MAIL_TRANSPORT) : 'auto';

        if ($mode === 'smtp') {
            return ['smtp', 'exim'];
        }
        if ($mode === 'exim' || $mode === 'mail') {
            return ['exim', 'smtp'];
        }

        $host = defined('SMTP_HOST') ? strtolower(trim(SMTP_HOST)) : '';
        if (in_array($host, ['localhost', '127.0.0.1', ''], true)) {
            return ['exim', 'smtp'];
        }

        return ['smtp', 'exim'];
    }
}

/**
 * SMTP hosts to attempt for the configured SMTP_HOST.
 *
 * @return string[]
 */
if (!function_exists('smtp_hosts_to_try')) {
    function smtp_hosts_to_try(): array
    {
        if (!defined('SMTP_HOST') || SMTP_HOST === '') {
            return [];
        }

        $host = strtolower(trim(SMTP_HOST));
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return array_values(array_unique([$host, 'localhost', '127.0.0.1']));
        }

        return [SMTP_HOST];
    }
}

/**
 * Whether to send via PHP mail() / Exim first (recommended on cPanel).
 */
function mail_use_exim_first(): bool
{
    if (defined('MAIL_TRANSPORT')) {
        $mode = strtolower((string) MAIL_TRANSPORT);
        if ($mode === 'exim' || $mode === 'mail') {
            return true;
        }
        if ($mode === 'smtp') {
            return false;
        }
    }

    if (defined('SMTP_HOST')) {
        $host = strtolower(trim(SMTP_HOST));
        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    return false;
}

/**
 * Local path to the brand logo file for email embedding.
 */
function email_brand_logo_path(): ?string
{
    $candidates = [
        __DIR__ . '/../assets/img/site-logo-on-dark-bg.png',
        __DIR__ . '/../assets/img/Fav-Icon-on-white-bg.png',
        __DIR__ . '/../assets/img/site-logo-on-white-bg.png',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/** Swap remote logo URL for CID before inline embedding. */
function email_html_with_inline_logo(string $htmlBody, string $cid = 'iqpigeon-logo'): string
{
    $cidRef = 'cid:' . $cid;
    $htmlBody = str_replace(email_brand_logo_url(), $cidRef, $htmlBody);
    $htmlBody = str_replace(htmlspecialchars(email_brand_logo_url(), ENT_QUOTES, 'UTF-8'), $cidRef, $htmlBody);
    $htmlBody = str_replace('{{IQPG_EMAIL_LOGO}}', $cidRef, $htmlBody);

    return $htmlBody;
}

/**
 * Build multipart/related body (HTML + inline PNG) for mail() / Exim.
 *
 * @return array{boundary: string, body: string}|null
 */
function email_multipart_related_pack(string $htmlBody, string $cid = 'iqpigeon-logo'): ?array
{
    $logoPath = email_brand_logo_path();
    if ($logoPath === null) {
        return null;
    }

    $htmlBody = email_html_with_inline_logo($htmlBody, $cid);
    $imageData = base64_encode((string) file_get_contents($logoPath));
    $boundary = 'iqpg_' . bin2hex(random_bytes(8));

    $parts = '--' . $boundary . "\r\n";
    $parts .= "Content-Type: text/html; charset=UTF-8\r\n";
    $parts .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $parts .= $htmlBody . "\r\n\r\n";
    $parts .= '--' . $boundary . "\r\n";
    $parts .= "Content-Type: image/png; name=\"logo.png\"\r\n";
    $parts .= "Content-Transfer-Encoding: base64\r\n";
    $parts .= 'Content-ID: <' . $cid . ">\r\n";
    $parts .= "Content-Disposition: inline; filename=\"logo.png\"\r\n\r\n";
    $parts .= chunk_split($imageData, 76, "\r\n");
    $parts .= '--' . $boundary . "--\r\n";

    return ['boundary' => $boundary, 'body' => $parts];
}

/**
 * Replace legacy logo placeholder (back-compat).
 */
function email_finalize_html_body(string $htmlBody, bool $embedInline = false): string
{
    if ($embedInline) {
        return email_html_with_inline_logo($htmlBody);
    }

    $htmlBody = str_replace('{{IQPG_EMAIL_LOGO}}', email_brand_logo_url(), $htmlBody);

    return $htmlBody;
}

/**
 * Prepare HTML for PHPMailer inline logo embedding.
 */
function email_prepare_html_body(string $htmlBody): string
{
    return email_brand_logo_path() !== null
        ? email_html_with_inline_logo($htmlBody)
        : email_finalize_html_body($htmlBody, false);
}

/**
 * Extra headers for inbox brand avatar (BIMI).
 *
 * @return string[]
 */
function email_brand_header_lines(): array
{
    if (email_bimi_svg_content() === null) {
        return [];
    }

    return ['BIMI-Selector: v=BIMI1; s=default;'];
}

/**
 * @param PHPMailer\PHPMailer\PHPMailer $mail
 */
function email_apply_brand_headers($mail): void
{
    foreach (email_brand_header_lines() as $line) {
        $parts = explode(': ', $line, 2);
        if (count($parts) === 2) {
            $mail->addCustomHeader($parts[0], $parts[1]);
        }
    }
}

/**
 * Send HTML email through PHP mail() — same Exim/DKIM path as cPanel webmail.
 */
function send_email_via_exim(string $to, string $subject, string $htmlBody): bool
{
    if (!function_exists('mail')) {
        mail_set_last_error('PHP mail() is not available on this server.');
        return false;
    }

    $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : '';
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'App');

    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        mail_set_last_error('Invalid SMTP_FROM address in config.php.');
        return false;
    }

    $domain = smtp_mail_domain($fromEmail);
    $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $encodedSubject = smtp_encode_header($subject);

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . smtp_encode_address($fromName, $fromEmail),
        'Reply-To: ' . $fromEmail,
        'Return-Path: <' . $fromEmail . '>',
        'Message-ID: ' . $messageId,
        'X-Mailer: ' . (defined('APP_NAME') ? APP_NAME : 'App') . ' (Exim)',
    ];
    $headers = array_merge($headers, email_brand_header_lines());

    $packed = email_multipart_related_pack($htmlBody);
    if ($packed !== null) {
        $headers[] = 'Content-Type: multipart/related; boundary="' . $packed['boundary'] . '"';
        $messageBody = $packed['body'];
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $messageBody = email_finalize_html_body($htmlBody, false);
    }

    $sent = @mail($to, $encodedSubject, $messageBody, implode("\r\n", $headers), '-f' . $fromEmail);
    if (!$sent) {
        mail_set_last_error('PHP mail() / Exim send failed.');
    }

    return $sent;
}

/**
 * Send via authenticated SMTP (PHPMailer or native client).
 */
function send_email_via_smtp(string $to, string $subject, string $body): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->Port       = (int) SMTP_PORT;
            $mail->CharSet    = 'UTF-8';

            $secure = smtp_secure_mode();
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
                $mail->SMTPSecure = false;
            }

            if (defined('APP_DEBUG') && APP_DEBUG) {
                $mail->SMTPDebug = 2;
            }

            if (defined('SMTP_SSL_VERIFY') && !SMTP_SSL_VERIFY) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->Sender = SMTP_FROM;
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;

            $htmlBody = email_prepare_html_body($body);
            $logoPath = email_brand_logo_path();
            if ($logoPath !== null) {
                $mail->addEmbeddedImage($logoPath, 'iqpigeon-logo', 'logo.png', 'base64', 'image/png');
            }

            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
            email_apply_brand_headers($mail);
            $mail->send();
            return true;
        } catch (Throwable $e) {
            mail_set_last_error($e->getMessage());
            error_log('PHPMailer error: ' . $e->getMessage());
        }
    }

    if (!defined('SMTP_HOST') || SMTP_HOST === '') {
        return false;
    }

    $errors = [];
    $packed = email_multipart_related_pack($body);
    if ($packed !== null) {
        $remoteBody = $packed['body'];
        $contentType = 'multipart/related; boundary="' . $packed['boundary'] . '"';
    } else {
        $remoteBody = email_finalize_html_body($body, false);
        $contentType = 'text/html; charset=UTF-8';
    }
    foreach (smtp_hosts_to_try() as $host) {
        $result = smtp_send_mail(
            $host,
            (int) SMTP_PORT,
            SMTP_USER,
            SMTP_PASS,
            smtp_secure_mode(),
            SMTP_FROM,
            SMTP_FROM_NAME,
            $to,
            $subject,
            $remoteBody,
            $contentType,
            $packed !== null,
            email_brand_header_lines()
        );

        if ($result['success']) {
            return true;
        }

        $errors[] = ($result['error'] ?? 'SMTP send failed.') . " ({$host})";
        mail_set_last_error($result['error'] ?? 'SMTP send failed.');
    }

    if ($errors !== []) {
        mail_set_last_error(implode(' | ', $errors));
        error_log('Native SMTP error: ' . mail_last_error());
    }

    return false;
}

/**
 * Send an email message.
 *
 * @return bool
 */
function send_email(string $to, string $subject, string $body): bool
{
    mail_set_last_error('');
    mail_set_last_transport('');
    $to = filter_var(trim($to), FILTER_VALIDATE_EMAIL);
    if (!$to) {
        mail_set_last_error('Invalid recipient email.');
        return false;
    }

    $errors = [];
    foreach (mail_transport_order() as $transport) {
        $ok = false;
        if ($transport === 'exim') {
            $ok = send_email_via_exim($to, $subject, $body);
        } elseif ($transport === 'smtp') {
            $ok = send_email_via_smtp($to, $subject, $body);
        }

        if ($ok) {
            mail_set_last_transport($transport);
            return true;
        }

        if (mail_last_error() !== '') {
            $errors[] = strtoupper($transport) . ': ' . mail_last_error();
        }
    }

    if ($errors !== []) {
        mail_set_last_error(implode(' | ', $errors));
    } elseif (!mail_last_error()) {
        mail_set_last_error('No mail transport available. Set MAIL_TRANSPORT and SMTP settings in config.php.');
    }

    error_log('Email send failed to ' . $to . ' — ' . mail_last_error());
    return false;
}

/**
 * Extract domain from an email address.
 */
function mail_domain_from_address(string $email): string
{
    $parts = explode('@', strtolower(trim($email)));
    return (count($parts) === 2 && $parts[1] !== '') ? $parts[1] : '';
}

/**
 * True when recipient is on the same domain as the sender (local delivery only).
 */
function mail_is_internal_recipient(string $to, string $from): bool
{
    $toDomain = mail_domain_from_address($to);
    $fromDomain = mail_domain_from_address($from);
    return $toDomain !== '' && $toDomain === $fromDomain;
}

/**
 * Short admin-facing steps to fix Gmail bounces (SPF/DKIM).
 *
 * @return string[]
 */
function mail_deliverability_steps(string $fromEmail): array
{
    $domain = mail_domain_from_address($fromEmail);
    if ($domain === '') {
        $domain = 'your-domain.com';
    }

    return [
        'Open cPanel → Email → Email Deliverability.',
        'Select "' . $domain . '" and click Repair (or Manage).',
        'Install the recommended SPF and DKIM DNS records (status must show Valid).',
        'Add DMARC (p=quarantine or reject) — required for Gmail inbox logo (BIMI).',
        'Optional inbox logo: DNS TXT at default._bimi.' . $domain . ' → v=BIMI1; l=' . email_bimi_logo_url() . ';',
        'If this is a subdomain, add records to the subdomain zone — not only the parent domain.',
        'Wait 15–60 minutes for DNS, then send a test to a Gmail address (not ' . $fromEmail . ').',
        'Check Gmail inbox and spam; bounces mean SPF/DKIM are still missing.',
    ];
}

/**
 * Whether the last test likely only proved local delivery.
 */
function mail_test_needs_external_check(string $testTo, string $fromEmail): bool
{
    return mail_is_internal_recipient($testTo, $fromEmail);
}

/**
 * Send a test email and return detailed result.
 *
 * @return array{success: bool, message: string}
 */
function send_test_email(string $to): array
{
    $subject = 'Test email — ' . APP_NAME;
    $body = email_template(
        'Email test successful',
        '<p>If you received this, SMTP is configured correctly for <strong>' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '</strong>.</p>',
        APP_URL,
        'Open dashboard'
    );

    if (send_email($to, $subject, $body)) {
        $message = 'Test email sent to ' . $to;
        $via = mail_last_transport();
        if ($via !== '') {
            $message .= ' (via ' . $via . ')';
        }
        if (mail_test_needs_external_check($to, SMTP_FROM)) {
            $message .= '. Note: same-domain test only proves the server accepts mail — send to Gmail to confirm external delivery.';
        }
        return ['success' => true, 'message' => $message];
    }

    $detail = mail_last_error();
    return [
        'success' => false,
        'message' => $detail !== ''
            ? 'Send failed: ' . $detail
            : 'Send failed. Check SMTP_HOST, SMTP_USER, SMTP_PASS, and SMTP_PORT in config.php.',
    ];
}

function email_admin_new_client(string $name, string $email, string $company): bool
{
    return send_email(
        ADMIN_EMAIL,
        'New client signup: ' . $company,
        email_template_admin_new_client($name, $email, $company)
    );
}

function email_new_lead(string $clientEmail, string $leadName, string $platform): bool
{
    return send_email(
        $clientEmail,
        'New lead: ' . $leadName,
        email_template_new_lead($leadName, $platform)
    );
}

function email_lead_qualified(string $clientEmail, string $leadName): bool
{
    return send_email(
        $clientEmail,
        'Qualified lead: ' . $leadName,
        email_template_lead_qualified($leadName)
    );
}

function email_new_order(string $clientEmail, int $orderId, string $customerName): bool
{
    return send_email(
        $clientEmail,
        'New order #' . $orderId . ' — ' . $customerName,
        email_template_new_order($orderId, $customerName)
    );
}

function email_payment_failed(string $clientEmail): bool
{
    return send_email(
        $clientEmail,
        'Payment failed — action required',
        email_template_payment_failed()
    );
}

function email_password_reset(string $email, string $resetLink): bool
{
    return send_email(
        $email,
        'Password reset — ' . APP_NAME,
        email_template_password_reset($resetLink, 1)
    );
}

function email_verify_address(string $email, string $name, string $verifyUrl, string $code): bool
{
    return send_email(
        $email,
        'Confirm your email — ' . APP_NAME,
        email_template_verify($name, $verifyUrl, $code, email_verify_expiry_label())
    );
}

function email_welcome(string $email, string $name): bool
{
    return send_email(
        $email,
        'Welcome to ' . APP_NAME,
        email_template_welcome($name)
    );
}

function email_system_update(string $email, string $name, string $title, string $body, string $unsubscribeToken): bool
{
    $unsubUrl = APP_URL . '/unsubscribe.php?token=' . urlencode($unsubscribeToken);

    return send_email(
        $email,
        $title . ' — ' . APP_NAME,
        email_template_system_update($title, $body, $unsubUrl)
    );
}
