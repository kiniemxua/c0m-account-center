<?php
declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];
if (!isset($argv[1], $argv[2])) {
    exit(1);
}
$portFile = $argv[1];
$mailFile = $argv[2];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
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
fwrite($client, "220 test.local ESMTP\r\n");
$dataMode = false;
$message = '';
while (($line = fgets($client, 8192)) !== false) {
    if ($dataMode) {
        if ($line === ".\r\n" || $line === ".\n") {
            file_put_contents($mailFile, $message);
            fwrite($client, "250 2.0.0 queued\r\n");
            $dataMode = false;
            continue;
        }
        $message .= $line;
        continue;
    }
    $command = strtoupper(strtok($line, " \r\n"));
    if ($command === 'EHLO' || $command === 'HELO') {
        fwrite($client, "250-test.local\r\n250 SIZE 1000000\r\n");
    } elseif ($command === 'MAIL' || $command === 'RCPT') {
        fwrite($client, "250 2.1.0 OK\r\n");
    } elseif ($command === 'DATA') {
        fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
        $dataMode = true;
    } elseif ($command === 'QUIT') {
        fwrite($client, "221 2.0.0 Bye\r\n");
        break;
    } else {
        fwrite($client, "250 OK\r\n");
    }
}
fclose($client);
fclose($server);
