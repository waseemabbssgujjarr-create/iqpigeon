<?php
/**
 * Minimal SMTP client for shared hosting (no PHPMailer / mail() required).
 */

/**
 * @return array{success: bool, error?: string}
 */
function smtp_send_mail(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $secure,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $htmlContentType = 'text/html; charset=UTF-8',
    bool $bodyIsComplete = false,
    array $extraHeaders = []
): array {
    $secure = strtolower(trim($secure));
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => defined('SMTP_SSL_VERIFY') ? (bool) SMTP_SSL_VERIFY : true,
            'verify_peer_name'  => defined('SMTP_SSL_VERIFY') ? (bool) SMTP_SSL_VERIFY : true,
            'allow_self_signed' => !(defined('SMTP_SSL_VERIFY') ? (bool) SMTP_SSL_VERIFY : true),
        ],
    ]);

    $remote = $host;
    if ($secure === 'ssl') {
        $remote = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        $remote . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return ['success' => false, 'error' => "Could not connect to {$host}:{$port} — {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, [220]);

        $ehloHost = smtp_ehlo_host($fromEmail);
        if (!smtp_cmd($socket, "EHLO {$ehloHost}", [250])) {
            smtp_cmd($socket, "HELO {$ehloHost}", [250]);
        }

        if ($secure === 'tls') {
            if (!smtp_cmd($socket, 'STARTTLS', [220])) {
                throw new RuntimeException('STARTTLS not supported by server.');
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
            }
            if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                throw new RuntimeException('Could not enable TLS encryption.');
            }
            smtp_cmd($socket, "EHLO {$ehloHost}", [250]);
        }

        if ($user !== '' && $pass !== '') {
            if (!smtp_cmd($socket, 'AUTH LOGIN', [334])) {
                throw new RuntimeException('SMTP AUTH LOGIN not accepted.');
            }
            if (!smtp_cmd($socket, base64_encode($user), [334])) {
                throw new RuntimeException('SMTP username rejected.');
            }
            if (!smtp_cmd($socket, base64_encode($pass), [235])) {
                throw new RuntimeException('SMTP password rejected. Check SMTP_PASS in config.php.');
            }
        }

        if (!smtp_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
            throw new RuntimeException('MAIL FROM rejected.');
        }
        if (!smtp_cmd($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251])) {
            throw new RuntimeException('RCPT TO rejected for ' . $toEmail);
        }
        if (!smtp_cmd($socket, 'DATA', [354])) {
            throw new RuntimeException('DATA command rejected.');
        }

        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . smtp_mail_domain($fromEmail) . '>';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_encode_address($fromName, $fromEmail),
            'Reply-To: ' . smtp_encode_address($fromName, $fromEmail),
            'Return-Path: <' . $fromEmail . '>',
            'Message-ID: ' . $messageId,
            'To: <' . $toEmail . '>',
            'Subject: ' . smtp_encode_header($subject),
            'MIME-Version: 1.0',
            'X-Mailer: ' . (defined('APP_NAME') ? APP_NAME : 'App') . ' SMTP',
        ];
        foreach ($extraHeaders as $headerLine) {
            if (is_string($headerLine) && $headerLine !== '') {
                $headers[] = $headerLine;
            }
        }

        if ($bodyIsComplete) {
            $headers[] = 'Content-Type: ' . $htmlContentType;
            $body = implode("\r\n", $headers) . "\r\n\r\n";
            $body .= $htmlBody;
        } else {
            $boundary = 'b_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = implode("\r\n", $headers) . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $plain . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Type: ' . $htmlContentType . "\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n\r\n";
            $body .= '--' . $boundary . "--\r\n";
        }

        $body = preg_replace("/\r\n\./", "\r\n..", $body);

        fwrite($socket, $body . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_cmd($socket, 'QUIT', [221]);

        return ['success' => true];
    } catch (Throwable $e) {
        error_log('SMTP error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        fclose($socket);
    }
}

/**
 * @param resource $socket
 * @param int[] $okCodes
 */
function smtp_cmd($socket, string $cmd, array $okCodes): bool
{
    fwrite($socket, $cmd . "\r\n");
    return smtp_expect($socket, $okCodes);
}

/**
 * @param resource $socket
 * @param int[] $okCodes
 */
function smtp_expect($socket, array $okCodes): bool
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('Empty SMTP response.');
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException(trim($response));
    }

    return true;
}

function smtp_encode_header(string $text): string
{
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

function smtp_encode_address(string $name, string $email): string
{
    $name = trim($name);
    if ($name === '') {
        return '<' . $email . '>';
    }
    return smtp_encode_header($name) . ' <' . $email . '>';
}

/**
 * Domain used in EHLO / Message-ID (must match From address domain).
 */
function smtp_mail_domain(string $fromEmail): string
{
    $parts = explode('@', strtolower(trim($fromEmail)));
    if (count($parts) === 2 && $parts[1] !== '') {
        return $parts[1];
    }

    if (defined('APP_URL')) {
        $host = parse_url(APP_URL, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
    }

    return 'localhost.localdomain';
}

/**
 * EHLO hostname sent to the mail server.
 */
function smtp_ehlo_host(string $fromEmail): string
{
    return smtp_mail_domain($fromEmail);
}

/**
 * Resolve SMTP secure mode from config.
 */
function smtp_secure_mode(): string
{
    if (defined('SMTP_SECURE') && SMTP_SECURE !== '') {
        return strtolower(SMTP_SECURE);
    }
    $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
    return $port === 465 ? 'ssl' : 'tls';
}

/**
 * Check whether any mail transport is available.
 */
function mail_transport_ready(): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }
    if (defined('SMTP_HOST') && SMTP_HOST !== '' && defined('SMTP_USER') && SMTP_USER !== '') {
        return true;
    }
    return function_exists('mail');
}

/**
 * Last send error message (for diagnostics).
 */
function mail_last_error(): string
{
    return $GLOBALS['_mail_last_error'] ?? '';
}

function mail_set_last_error(string $error): void
{
    $GLOBALS['_mail_last_error'] = $error;
}

/**
 * Last transport that successfully sent mail (exim|smtp).
 */
function mail_last_transport(): string
{
    return $GLOBALS['_mail_last_transport'] ?? '';
}

function mail_set_last_transport(string $transport): void
{
    $GLOBALS['_mail_last_transport'] = $transport;
}

/**
 * Hosts to try for SMTP — do not mix remote host with localhost fallbacks.
 *
 * @return string[]
 */
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

/**
 * Transport order for send attempts.
 *
 * @return string[]
 */
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
