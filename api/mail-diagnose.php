<?php
/**
 * Mail diagnostics — visit /api/mail-diagnose.php?to=you@gmail.com
 * DELETE after debugging.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$to = filter_var(trim($_GET['to'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$to) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Add ?to=your@gmail.com to the URL',
    ], JSON_PRETTY_PRINT);
    exit;
}

$transports = function_exists('mail_transport_order')
    ? mail_transport_order()
    : ['exim', 'smtp'];

$results = [];

foreach ($transports as $transport) {
    mail_set_last_error('');
    $subject = 'Mail diagnose (' . $transport . ') — ' . APP_NAME;
    $body = email_template(
        'Transport test: ' . $transport,
        '<p>If you received this, the <strong>' . htmlspecialchars($transport, ENT_QUOTES, 'UTF-8') . '</strong> transport works.</p>',
        APP_URL,
        'Open site'
    );

    $ok = false;
    if ($transport === 'exim' && function_exists('send_email_via_exim')) {
        $ok = send_email_via_exim($to, $subject, $body);
    } elseif (function_exists('send_email_via_smtp')) {
        $ok = send_email_via_smtp($to, $subject, $body);
    }

    $results[] = [
        'transport' => $transport,
        'ok'        => $ok,
        'error'     => $ok ? '' : mail_last_error(),
    ];
}

$anyOk = false;
foreach ($results as $row) {
    if ($row['ok']) {
        $anyOk = true;
        break;
    }
}

echo json_encode([
    'ok'              => $anyOk,
    'to'              => $to,
    'mail_transport'  => defined('MAIL_TRANSPORT') ? MAIL_TRANSPORT : 'auto',
    'smtp_host'       => defined('SMTP_HOST') ? SMTP_HOST : '',
    'transport_order' => $transports,
    'results'         => $results,
    'recommendation'  => $anyOk
        ? 'Use MAIL_TRANSPORT matching the transport that delivered to Gmail.'
        : 'Set MAIL_TRANSPORT to exim and SMTP_HOST to localhost in config.php, then re-upload includes/mailer.php.',
], JSON_PRETTY_PRINT);
