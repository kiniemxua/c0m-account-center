<?php
declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];
$portFile = $argv[1];
$certFile = $argv[2];
$keyFile = $argv[3];
$mode = $argv[4] ?? 'success';
$delay = (int)($argv[5] ?? 7);
$context = stream_context_create(['ssl' => [
    'local_cert' => $certFile,
    'local_pk' => $keyFile,
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    exit(2);
}
$address = stream_socket_get_name($server, false);
$port = (int)substr(strrchr($address, ':'), 1);
file_put_contents($portFile, (string)$port);
$client = stream_socket_accept($server, 15);
if (!$client) {
    exit(3);
}
stream_set_timeout($client, 12);
if ($mode === 'timeout') {
    sleep($delay);
    fclose($client);
    fclose($server);
    exit(0);
}

fwrite($client, "220 tls.test ESMTP\r\n");
$ehlo = fgets($client, 2048);
if ($ehlo === false || strtoupper(strtok($ehlo, " \r\n")) !== 'EHLO') {
    exit(4);
}
fwrite($client, "250-tls.test\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n");
$startTls = fgets($client, 2048);
if ($startTls === false || strtoupper(trim($startTls)) !== 'STARTTLS') {
    exit(5);
}
fwrite($client, "220 Ready to start TLS\r\n");
if (!stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
    fclose($client);
    fclose($server);
    exit(0);
}
$ehlo = fgets($client, 2048);
if ($ehlo === false || strtoupper(strtok($ehlo, " \r\n")) !== 'EHLO') {
    exit(6);
}
fwrite($client, "250-tls.test\r\n250 AUTH LOGIN\r\n");
$auth = fgets($client, 2048);
if ($auth === false || strtoupper(trim($auth)) !== 'AUTH LOGIN') {
    exit(7);
}
fwrite($client, "334 VXNlcm5hbWU6\r\n");
$username = fgets($client, 2048);
fwrite($client, "334 UGFzc3dvcmQ6\r\n");
$password = fgets($client, 2048);
if ($mode === 'authfail') {
    fwrite($client, "535 5.7.8 Authentication credentials invalid\r\n");
    fclose($client);
    fclose($server);
    exit(0);
}
if (base64_decode(trim((string)$username), true) !== 'ci-smtp-user'
    || base64_decode(trim((string)$password), true) !== 'ci-smtp-pass') {
    fwrite($client, "535 5.7.8 Authentication credentials invalid\r\n");
    fclose($client);
    fclose($server);
    exit(0);
}
fwrite($client, "235 2.7.0 Authentication successful\r\n");

foreach (['MAIL FROM' => 250, 'RCPT TO' => 250, 'DATA' => 354] as $expected => $code) {
    $line = fgets($client, 2048);
    if ($line === false || stripos($line, $expected) !== 0) {
        exit(8);
    }
    fwrite($client, $code === 354 ? "354 End data\r\n" : "250 2.1.0 OK\r\n");
}
$message = '';
while (($line = fgets($client, 8192)) !== false) {
    if ($line === ".\r\n" || $line === ".\n") {
        file_put_contents($portFile . '.mail', $message);
        fwrite($client, "250 2.0.0 queued\r\n");
        break;
    }
    $message .= $line;
}
$quit = fgets($client, 2048);
if ($quit !== false && strtoupper(trim($quit)) === 'QUIT') {
    fwrite($client, "221 2.0.0 Bye\r\n");
}
fclose($client);
fclose($server);
