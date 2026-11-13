<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// SMTP credentials must never be sent over an unencrypted connection.
$plaintextAuthRejected = false;
try {
    $unsafeMailer = new App\Core\SmtpMailer([
        'host' => '127.0.0.1',
        'port' => 1,
        'encryption' => 'none',
        'username' => 'smtp-user',
        'password' => 'smtp-secret',
        'from_email' => 'noreply@example.test',
    ]);
    $unsafeMailer->send('member@example.test', 'Unsafe test', 'This must not be sent.');
} catch (RuntimeException $e) {
    $plaintextAuthRejected = $e->getMessage() === 'SMTP authentication requires TLS or implicit SSL.';
}
if (!$plaintextAuthRejected) {
    throw new RuntimeException('SMTP authentication over plaintext must be rejected before opening a socket.');
}

$partialCredentialsRejected = false;
try {
    $partialMailer = new App\Core\SmtpMailer([
        'host' => '127.0.0.1',
        'port' => 1,
        'encryption' => 'tls',
        'username' => 'smtp-user',
        'password' => '',
        'from_email' => 'noreply@example.test',
    ]);
    $partialMailer->send('member@example.test', 'Invalid test', 'This must not be sent.');
} catch (RuntimeException $e) {
    $partialCredentialsRejected = $e->getMessage() === 'SMTP username and password must both be configured.';
}
if (!$partialCredentialsRejected) {
    throw new RuntimeException('SMTP username/password must be configured as a pair.');
}

$base = sys_get_temp_dir() . '/c0m-smtp-' . bin2hex(random_bytes(5));
$portFile = $base . '.port';
$mailFile = $base . '.mail';
$command = [PHP_BINARY, BASE_PATH . '/tests/fake-smtp-server.php', $portFile, $mailFile];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start SMTP test server.');
}
fclose($pipes[0]);
$deadline = microtime(true) + 5;
while (!is_file($portFile) && microtime(true) < $deadline) {
    usleep(20000);
}
if (!is_file($portFile)) {
    proc_terminate($process);
    throw new RuntimeException('SMTP test server did not start.');
}
$port = (int)file_get_contents($portFile);
$mailer = new App\Core\SmtpMailer([
    'host' => '127.0.0.1',
    'port' => $port,
    'encryption' => 'none',
    'from_email' => 'noreply@example.test',
    'from_name' => 'c0m.me Test',
    'helo' => 'accounts.example.test',
]);
$mailer->send('member@example.test', 'Verify your email', "Verification URL: https://accounts.example.test/verify", '<p>Verify your email</p>');
$exit = proc_close($process);
$message = is_file($mailFile) ? file_get_contents($mailFile) : '';
@unlink($portFile);
@unlink($mailFile);
if ($exit !== 0 || !str_contains($message, 'To: <member@example.test>') || !str_contains($message, 'Subject: =?UTF-8?B?')) {
    throw new RuntimeException('SMTP provider did not complete a valid message transaction.');
}

function runSmtpFixtureCommand(array $command): void
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not create SMTP TLS certificate fixture.');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('SMTP TLS certificate fixture generation failed.');
    }
}

function startSmtpTlsFixture(string $mode, string $certFile, string $keyFile, string $directory): array
{
    $portFile = $directory . '/' . $mode . '-' . bin2hex(random_bytes(3)) . '.port';
    $command = [PHP_BINARY, BASE_PATH . '/tests/fake-smtp-tls-server.php', $portFile, $certFile, $keyFile, $mode, '7'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start SMTP TLS fixture.');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    while (!is_file($portFile) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (!is_file($portFile)) {
        proc_terminate($process);
        throw new RuntimeException('SMTP TLS fixture did not start.');
    }
    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => (int)file_get_contents($portFile),
        'mail_file' => $portFile . '.mail',
    ];
}

function finishSmtpTlsFixture(array &$fixture, bool $terminate = false): int
{
    if ($terminate && is_resource($fixture['process'])) {
        proc_terminate($fixture['process']);
    }
    foreach ([1, 2] as $index) {
        if (isset($fixture['pipes'][$index]) && is_resource($fixture['pipes'][$index])) {
            stream_get_contents($fixture['pipes'][$index]);
            fclose($fixture['pipes'][$index]);
        }
    }
    $exit = proc_close($fixture['process']);
    $fixture['process'] = null;
    return $exit;
}

$tlsDirectory = sys_get_temp_dir() . '/c0m-smtp-tls-' . bin2hex(random_bytes(5));
if (!mkdir($tlsDirectory, 0700, true)) {
    throw new RuntimeException('Could not create SMTP TLS fixture directory.');
}
$caKey = $tlsDirectory . '/ca.key';
$caFile = $tlsDirectory . '/ca.crt';
$serverKey = $tlsDirectory . '/server.key';
$serverCsr = $tlsDirectory . '/server.csr';
$serverCert = $tlsDirectory . '/server.crt';
$fixture = null;
try {
    runSmtpFixtureCommand([
        'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
        '-keyout', $caKey, '-out', $caFile, '-subj', '/CN=c0m-test-ca', '-days', '1',
        '-addext', 'basicConstraints=critical,CA:TRUE',
        '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
    ]);
    runSmtpFixtureCommand([
        'openssl', 'req', '-newkey', 'rsa:2048', '-nodes',
        '-keyout', $serverKey, '-out', $serverCsr, '-subj', '/CN=localhost',
        '-addext', 'subjectAltName=DNS:localhost',
        '-addext', 'basicConstraints=critical,CA:FALSE',
        '-addext', 'keyUsage=critical,digitalSignature,keyEncipherment',
        '-addext', 'extendedKeyUsage=serverAuth',
    ]);
    runSmtpFixtureCommand([
        'openssl', 'x509', '-req', '-in', $serverCsr, '-CA', $caFile, '-CAkey', $caKey,
        '-CAcreateserial', '-out', $serverCert, '-days', '1', '-copy_extensions', 'copy',
    ]);

    $fixture = startSmtpTlsFixture('success', $serverCert, $serverKey, $tlsDirectory);
    (new App\Core\SmtpMailer([
        'host' => '127.0.0.1',
        'port' => $fixture['port'],
        'encryption' => 'tls',
        'username' => 'ci-smtp-user',
        'password' => 'ci-smtp-pass',
        'tls_peer_name' => 'localhost',
        'ca_file' => $caFile,
        'from_email' => 'noreply@example.test',
        'helo' => 'accounts.example.test',
        'timeout' => 5,
    ]))->send('member@example.test', 'TLS verified message', 'TLS fixture body');
    if (finishSmtpTlsFixture($fixture) !== 0) {
        throw new RuntimeException('SMTP STARTTLS fixture did not finish successfully.');
    }
    $tlsMessage = file_get_contents($fixture['mail_file']);
    if (!is_string($tlsMessage) || !str_contains($tlsMessage, 'To: <member@example.test>')) {
        throw new RuntimeException('SMTP STARTTLS fixture did not receive the message.');
    }

    $fixture = startSmtpTlsFixture('success', $serverCert, $serverKey, $tlsDirectory);
    $hostnameMismatchRejected = false;
    try {
        (new App\Core\SmtpMailer([
            'host' => '127.0.0.1',
            'port' => $fixture['port'],
            'encryption' => 'tls',
            'username' => 'ci-smtp-user',
            'password' => 'ci-smtp-pass',
            'tls_peer_name' => 'wrong.example.test',
            'ca_file' => $caFile,
            'from_email' => 'noreply@example.test',
            'timeout' => 5,
        ]))->send('member@example.test', 'Hostname mismatch', 'Must be rejected.');
    } catch (RuntimeException $e) {
        $hostnameMismatchRejected = $e->getMessage() === 'Email delivery failed.';
    }
    finishSmtpTlsFixture($fixture, true);
    if (!$hostnameMismatchRejected) {
        throw new RuntimeException('SMTP TLS hostname mismatch must be rejected.');
    }

    $fixture = startSmtpTlsFixture('authfail', $serverCert, $serverKey, $tlsDirectory);
    $authFailureRejected = false;
    try {
        (new App\Core\SmtpMailer([
            'host' => '127.0.0.1',
            'port' => $fixture['port'],
            'encryption' => 'tls',
            'username' => 'ci-smtp-user',
            'password' => 'wrong-test-password',
            'tls_peer_name' => 'localhost',
            'ca_file' => $caFile,
            'from_email' => 'noreply@example.test',
            'timeout' => 5,
        ]))->send('member@example.test', 'Auth failure', 'Must be rejected.');
    } catch (RuntimeException $e) {
        $authFailureRejected = $e->getMessage() === 'Email delivery failed.';
    }
    finishSmtpTlsFixture($fixture);
    if (!$authFailureRejected) {
        throw new RuntimeException('SMTP authentication failure must be rejected.');
    }

    $fixture = startSmtpTlsFixture('timeout', $serverCert, $serverKey, $tlsDirectory);
    $timeoutRejected = false;
    try {
        (new App\Core\SmtpMailer([
            'host' => '127.0.0.1',
            'port' => $fixture['port'],
            'encryption' => 'tls',
            'from_email' => 'noreply@example.test',
            'tls_peer_name' => 'localhost',
            'ca_file' => $caFile,
            'timeout' => 5,
        ]))->send('member@example.test', 'Timeout', 'Must time out.');
    } catch (RuntimeException $e) {
        $timeoutRejected = $e->getMessage() === 'Email delivery failed.';
    }
    finishSmtpTlsFixture($fixture, true);
    if (!$timeoutRejected) {
        throw new RuntimeException('SMTP greeting timeout must fail safely.');
    }

    $reserved = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$reserved) {
        throw new RuntimeException('Could not reserve an SMTP unavailable-server test port.');
    }
    $reservedAddress = stream_socket_get_name($reserved, false);
    $closedPort = (int)substr(strrchr($reservedAddress, ':'), 1);
    fclose($reserved);
    $unavailableRejected = false;
    try {
        (new App\Core\SmtpMailer([
            'host' => '127.0.0.1',
            'port' => $closedPort,
            'encryption' => 'tls',
            'from_email' => 'noreply@example.test',
            'timeout' => 5,
        ]))->send('member@example.test', 'Unavailable', 'Must fail.');
    } catch (RuntimeException $e) {
        $unavailableRejected = $e->getMessage() === 'SMTP server connection failed.';
    }
    if (!$unavailableRejected) {
        throw new RuntimeException('Unavailable SMTP server must fail safely.');
    }
} finally {
    if (is_array($fixture) && is_resource($fixture['process'])) {
        finishSmtpTlsFixture($fixture, true);
    }
    foreach (glob($tlsDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tlsDirectory);
}

echo "SMTP protocol, STARTTLS, hostname verification, authentication, timeout and unavailable-server tests passed.\n";
