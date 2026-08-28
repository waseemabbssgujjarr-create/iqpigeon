<?php
/**
 * Branded HTML email templates for transactional mail.
 */

require_once __DIR__ . '/../config.php';

/** Absolute URL for email assets (logo must be HTTPS for clients). */
function email_absolute_url(string $path): string
{
    $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
    if ($base === '' && defined('SMTP_FROM') && SMTP_FROM !== '') {
        $at = strrchr(SMTP_FROM, '@');
        if ($at !== false) {
            $base = 'https://' . strtolower(substr($at, 1));
        }
    }
    if ($base === '') {
        return $path;
    }

    return $base . '/' . ltrim($path, '/');
}

/** Public logo URL used inside HTML emails. */
function email_brand_logo_url(): string
{
    $path = __DIR__ . '/../email-logo.php';
    $url = '/email-logo.php';
    if (is_file($path)) {
        $url .= '?v=' . filemtime($path);
    }

    return email_absolute_url($url);
}

/** CID reference after PHPMailer embeds the logo. */
function email_brand_logo_cid(): string
{
    return 'cid:iqpigeon-logo';
}

/** Square PNG bytes for BIMI / favicon fallbacks. */
function email_brand_logo_bytes(): ?string
{
    $candidates = [
        __DIR__ . '/../assets/img/Fav-Icon-on-white-bg.png',
        __DIR__ . '/../assets/img/site-logo-on-dark-bg.png',
        __DIR__ . '/../assets/img/site-logo-on-white-bg.png',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $bytes = @file_get_contents($path);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }
    }

    return null;
}

/** BIMI-compliant SVG (embedded PNG) for Gmail/Yahoo inbox avatar. */
function email_bimi_svg_content(): ?string
{
    $bytes = email_brand_logo_bytes();
    if ($bytes === null) {
        return null;
    }

    $name = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
    $b64 = base64_encode($bytes);

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg version="1.2" baseProfile="tiny-ps" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="' . $name . '">'
        . '<image width="512" height="512" href="data:image/png;base64,' . $b64 . '"/>'
        . '</svg>';
}

/** Public BIMI logo URL (Gmail round sender icon). */
function email_bimi_logo_url(): string
{
    return email_absolute_url('/.well-known/bimi/logo.svg');
}

/**
 * @return array{host: string, value: string, dmarc_host: string, dmarc_example: string, from_domain: string}
 */
function email_bimi_dns_records(): array
{
    $from = defined('SMTP_FROM') ? (string) SMTP_FROM : '';
    $domain = '';
    if ($from !== '' && str_contains($from, '@')) {
        $domain = strtolower(substr(strrchr($from, '@'), 1));
    }
    if ($domain === '' && defined('APP_URL') && APP_URL !== '') {
        $domain = strtolower((string) parse_url(APP_URL, PHP_URL_HOST));
    }
    if ($domain === '') {
        $domain = 'yourdomain.com';
    }

    return [
        'host'           => 'default._bimi.' . $domain,
        'value'          => 'v=BIMI1; l=' . email_bimi_logo_url() . ';',
        'dmarc_host'      => '_dmarc.' . $domain,
        'dmarc_example'  => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain,
        'from_domain'    => $domain,
    ];
}

/**
 * Wrap email body in a consistent HTML layout.
 *
 * @param string $title Heading shown in the email
 * @param string $bodyHtml Inner HTML (paragraphs, lists)
 * @param string|null $buttonUrl Optional CTA button URL
 * @param string|null $buttonLabel Optional CTA button label
 * @param string|null $footerNote Optional small print below button
 * @return string Full HTML document
 */
function email_template(
    string $title,
    string $bodyHtml,
    ?string $buttonUrl = null,
    ?string $buttonLabel = null,
    ?string $footerNote = null
): string {
    $appName = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
    $appUrl = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $logoAlt = $appName;
    $logoSrc = htmlspecialchars(email_brand_logo_url(), ENT_QUOTES, 'UTF-8');

    $buttonBlock = '';
    if ($buttonUrl && $buttonLabel) {
        $buttonBlock = '<p style="margin:28px 0;text-align:center;">'
            . '<a href="' . htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#4aad36;color:#ffffff;text-decoration:none;'
            . 'font-family:Inter,Arial,sans-serif;font-size:16px;font-weight:600;'
            . 'padding:14px 28px;border-radius:12px;">'
            . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') . '</a></p>';
    }

    $footerNoteBlock = $footerNote
        ? '<p style="margin:16px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">'
          . htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f5;font-family:Inter,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f5;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e1e3e4;">
<tr><td style="background:#4aad36;padding:28px 28px 24px;text-align:center;">
<img src="{$logoSrc}" alt="{$logoAlt}" width="168" style="display:block;margin:0 auto;max-width:168px;width:168px;height:auto;border:0;outline:none;text-decoration:none;"/>
</td></tr>
<tr><td style="padding:28px;">
<h1 style="margin:0 0 16px;font-size:22px;color:#191c1d;line-height:1.3;">{$title}</h1>
<div style="font-size:15px;color:#3f484a;line-height:1.6;">{$bodyHtml}</div>
{$buttonBlock}
{$footerNoteBlock}
</td></tr>
<tr><td style="padding:20px 28px;background:#f9fafb;border-top:1px solid #e1e3e4;">
<p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.5;">
&copy; {$year} {$appName} · <a href="{$appUrl}" style="color:#4aad36;">{$appUrl}</a>
</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Verification email — link + 6-digit code.
 */
function email_template_verify(string $name, string $verifyUrl, string $code, string $expiryLabel): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $body = "<p>Hi {$safeName},</p>"
        . '<p>Thanks for signing up. Confirm your email to activate your account and connect your business WhatsApp.</p>'
        . '<p style="text-align:center;margin:24px 0;">'
        . '<span style="display:inline-block;background:#edeeef;padding:12px 24px;border-radius:12px;'
        . 'font-size:28px;font-weight:700;letter-spacing:8px;color:#191c1d;">' . $safeCode . '</span></p>'
        . '<p>Enter this code on the verification page, or click the button below.</p>';

    return email_template(
        'Confirm your email',
        $body,
        $verifyUrl,
        'Verify Email Address',
        "This link and code expire in {$expiryLabel}. If you didn't create an account, ignore this email."
    );
}

/**
 * Password reset email.
 */
function email_template_password_reset(string $resetUrl, int $hours = 1): string
{
    $body = '<p>We received a request to reset your password.</p>'
        . '<p>Click the button below to choose a new password. If you didn\'t request this, you can safely ignore this email.</p>';

    return email_template(
        'Reset your password',
        $body,
        $resetUrl,
        'Reset Password',
        "This link expires in {$hours} hour(s)."
    );
}

/**
 * Welcome email after verification.
 */
function email_template_welcome(string $name): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $body = "<p>Hi {$safeName},</p>"
        . '<p>Your email is confirmed. Connect your business WhatsApp next — then train your AI sales rep in the dashboard.</p>';

    return email_template(
        'Welcome to ' . APP_NAME,
        $body,
        APP_URL . '/client/connect-whatsapp',
        'Connect WhatsApp'
    );
}

/**
 * New lead notification.
 */
function email_template_new_lead(string $leadName, string $platform): string
{
    $body = '<p>You have a new lead: <strong>' . htmlspecialchars($leadName, ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . '<p>Channel: <strong>' . htmlspecialchars(ucfirst($platform), ENT_QUOTES, 'UTF-8') . '</strong></p>';

    return email_template(
        'New lead received',
        $body,
        APP_URL . '/client/leads',
        'View Leads'
    );
}

/**
 * Qualified lead notification.
 */
function email_template_lead_qualified(string $leadName): string
{
    $body = '<p><strong>' . htmlspecialchars($leadName, ENT_QUOTES, 'UTF-8') . '</strong> has been qualified — your Calendly link was sent automatically.</p>';

    return email_template(
        'Qualified lead',
        $body,
        APP_URL . '/client/leads',
        'View Conversation'
    );
}

/**
 * New WhatsApp shop order (COD).
 */
function email_template_new_order(int $orderId, string $customerName): string
{
    $body = '<p>New COD order <strong>#' . (int) $orderId . '</strong> from '
        . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>Confirm and update status in your order pipeline.</p>';

    return email_template(
        'New order received',
        $body,
        APP_URL . '/client/orders',
        'View Orders'
    );
}

/**
 * Payment failed notification.
 */
function email_template_payment_failed(): string
{
    $body = '<p>Your subscription payment could not be processed. Update your billing details to keep your bots running.</p>';

    return email_template(
        'Payment failed — action required',
        $body,
        APP_URL . '/client/billing',
        'Update Billing'
    );
}

/**
 * Admin: new client signup.
 */
function email_template_admin_new_client(string $name, string $email, string $company): string
{
    $body = '<p><strong>Name:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Company:</strong> ' . htmlspecialchars($company, ENT_QUOTES, 'UTF-8') . '</p>';

    return email_template(
        'New client signup',
        $body,
        APP_URL . '/admin/businesses',
        'View Businesses'
    );
}

/**
 * Product / system update email for subscribers.
 */
function email_template_system_update(string $title, string $bodyHtml, string $unsubscribeUrl): string
{
    $body = '<p style="margin-bottom:16px;">' . nl2br(htmlspecialchars($bodyHtml, ENT_QUOTES, 'UTF-8')) . '</p>';

    return email_template(
        $title,
        $body,
        APP_URL . '/login.php',
        'Open Dashboard',
        'Unsubscribe: ' . $unsubscribeUrl
    );
}
