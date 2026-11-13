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
echo "SMTP provider protocol test passed.\n";
