<?php
declare(strict_types=1);

const FORM_REDIRECT = 'index.html#contacto';
const SMTP_TIMEOUT = 20;

redirect_if_not_post();
ignore_honeypot();

$payload = get_form_payload();
$smtp = get_smtp_config();

try {
    smtp_send_mail(
        $smtp,
        'rtalavera@axiomasoluciones.tech',
        $payload['email'],
        'Nueva consulta desde la web de Axioma',
        [
            'Nueva consulta recibida desde el formulario web.',
            '',
            'Nombre: ' . $payload['nombre'],
            'Empresa: ' . ($payload['empresa'] !== '' ? $payload['empresa'] : 'No informado'),
            'Email: ' . $payload['email'],
            'Telefono: ' . ($payload['telefono'] !== '' ? $payload['telefono'] : 'No informado'),
            '',
            'Necesidad principal:',
            $payload['mensaje'],
        ]
    );

    redirect_with_status('success');
} catch (Throwable $exception) {
    error_log('[Axioma SMTP] ' . $exception->getMessage());
    redirect_with_status('error');
}

function redirect_if_not_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . FORM_REDIRECT, true, 303);
        exit;
    }
}

function ignore_honeypot(): void
{
    if (!empty($_POST['website'] ?? '')) {
        redirect_with_status('success');
    }
}

function get_form_payload(): array
{
    $nombre = normalize_line((string) ($_POST['nombre'] ?? ''));
    $empresa = normalize_line((string) ($_POST['empresa'] ?? ''));
    $email = normalize_line((string) ($_POST['email'] ?? ''));
    $telefono = normalize_line((string) ($_POST['telefono'] ?? ''));
    $mensaje = trim((string) ($_POST['mensaje'] ?? ''));
    $mensaje = trim((string) preg_replace("/\r\n|\r|\n/", PHP_EOL, $mensaje));

    if ($nombre === '' || $mensaje === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect_with_status('invalid');
    }

    return [
        'nombre' => $nombre,
        'empresa' => $empresa,
        'email' => $email,
        'telefono' => $telefono,
        'mensaje' => $mensaje,
    ];
}

function get_smtp_config(): array
{
    $smtp = [
        'host' => 'smtp.hostinger.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'rtalavera@axiomasoluciones.tech',
        'password' => 'Axioma2025*',
        'from_email' => 'rtalavera@axiomasoluciones.tech',
        'from_name' => 'Axioma Web',
    ];

    $envConfig = [
        'host' => getenv('AXIOMA_SMTP_HOST') ?: null,
        'port' => getenv('AXIOMA_SMTP_PORT') ?: null,
        'encryption' => getenv('AXIOMA_SMTP_ENCRYPTION') ?: null,
        'username' => getenv('AXIOMA_SMTP_USERNAME') ?: null,
        'password' => getenv('AXIOMA_SMTP_PASSWORD') ?: null,
        'from_email' => getenv('AXIOMA_SMTP_FROM_EMAIL') ?: null,
        'from_name' => getenv('AXIOMA_SMTP_FROM_NAME') ?: null,
    ];

    $smtp = array_merge($smtp, array_filter($envConfig, static fn ($value) => $value !== null));
    $smtp['port'] = (int) $smtp['port'];
    $smtp['encryption'] = strtolower(trim((string) $smtp['encryption']));

    if (
        $smtp['host'] === '' ||
        $smtp['port'] <= 0 ||
        $smtp['username'] === '' ||
        $smtp['password'] === '' ||
        !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException('SMTP configuration is incomplete.');
    }

    if (!in_array($smtp['encryption'], ['ssl', 'tls', 'none'], true)) {
        throw new RuntimeException('SMTP encryption must be ssl, tls, or none.');
    }

    return $smtp;
}

function normalize_line(string $value): string
{
    $value = preg_replace("/[\r\n]+/", ' ', $value) ?? '';
    return trim($value);
}

function redirect_with_status(string $status): void
{
    header('Location: index.html?form_status=' . rawurlencode($status) . '#contacto', true, 303);
    exit;
}

function smtp_send_mail(array $smtp, string $recipient, string $replyTo, string $subject, array $bodyLines): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient email is invalid.');
    }

    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Reply-to email is invalid.');
    }

    $remoteHost = $smtp['encryption'] === 'ssl'
        ? 'ssl://' . $smtp['host']
        : $smtp['host'];

    $socket = @stream_socket_client(
        $remoteHost . ':' . $smtp['port'],
        $errorCode,
        $errorMessage,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException('SMTP connection failed: ' . $errorCode . ' ' . $errorMessage);
    }

    stream_set_timeout($socket, SMTP_TIMEOUT);

    try {
        smtp_expect($socket, [220]);

        $helloHost = get_hello_host($smtp['from_email']);
        smtp_command($socket, 'EHLO ' . $helloHost, [250]);

        if ($smtp['encryption'] === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);

            $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Unable to enable STARTTLS for SMTP.');
            }

            smtp_command($socket, 'EHLO ' . $helloHost, [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode((string) $smtp['username']), [334]);
        smtp_command($socket, base64_encode((string) $smtp['password']), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $smtp['from_email'] . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . format_mailbox((string) $smtp['from_name'], (string) $smtp['from_email']),
            'To: <' . $recipient . '>',
            'Reply-To: <' . $replyTo . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: ' . build_message_id($smtp['from_email']),
        ];

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . smtp_escape_body(implode(PHP_EOL, $bodyLines))
            . "\r\n.\r\n";

        smtp_write($socket, $message);
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    smtp_write($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_write($socket, string $payload): void
{
    $remaining = $payload;

    while ($remaining !== '') {
        $written = fwrite($socket, $remaining);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Unable to write to SMTP socket.');
        }

        $remaining = substr($remaining, $written);
    }
}

function smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (preg_match('/^\d{3} /', $line) === 1) {
            break;
        }
    }

    if ($response === '') {
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('SMTP server timed out.');
        }

        throw new RuntimeException('SMTP server returned an empty response.');
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response ' . $code . ': ' . trim($response));
    }

    return $response;
}

function smtp_escape_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }

    return implode("\r\n", $lines);
}

function get_hello_host(string $email): string
{
    $parts = explode('@', $email);
    return $parts[1] ?? 'localhost';
}

function format_mailbox(string $name, string $email): string
{
    $cleanName = trim(preg_replace('/["\r\n]+/', ' ', $name) ?? '');
    return $cleanName !== '' ? $cleanName . ' <' . $email . '>' : '<' . $email . '>';
}

function build_message_id(string $email): string
{
    $domain = get_hello_host($email);
    return '<' . str_replace('.', '', uniqid('axioma', true)) . '@' . $domain . '>';
}
