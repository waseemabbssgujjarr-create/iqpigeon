<?php
/**
 * Quick check that mailer.php on the server is up to date.
 * Visit /api/mail-version.php — DELETE after debugging.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$checks = [
    'mail_transport_order'   => function_exists('mail_transport_order'),
    'mail_set_last_transport'=> function_exists('mail_set_last_transport'),
    'send_email_via_exim'    => function_exists('send_email_via_exim'),
    'send_email_via_smtp'    => function_exists('send_email_via_smtp'),
    'send_email'             => function_exists('send_email'),
];

echo json_encode([
    'ok'             => !in_array(false, $checks, true),
    'mailer_version' => '2026-06-20-v2',
    'mail_transport' => defined('MAIL_TRANSPORT') ? MAIL_TRANSPORT : null,
    'smtp_host'      => defined('SMTP_HOST') ? SMTP_HOST : null,
    'functions'        => $checks,
    'fix'            => in_array(false, $checks, true)
        ? 'Re-upload includes/mailer.php from your computer (overwrite the file on cPanel).'
        : 'mailer.php looks correct. Test with /api/mail-diagnose.php?to=your@gmail.com',
], JSON_PRETTY_PRINT);
