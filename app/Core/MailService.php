<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MailService
{
    public static function send(string $to, string $subject, string $text, string $html = ''): void
    {
        $configPath = BASE_PATH . '/config/mail.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('Mail service is not configured.');
        }
        $config = require $configPath;
        $mode = (string)($config['mode'] ?? 'disabled');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
            throw new RuntimeException('Invalid mail recipient or subject.');
        }
        if ($mode === 'development_log') {
            self::writeDevelopmentLog($to, $subject, $text);
            return;
        }
        if ($mode !== 'smtp') {
            throw new RuntimeException('Mail delivery is disabled.');
        }
        self::sendSmtp($config, $to, $subject, $text, $html);
    }

    private static function writeDevelopmentLog(string $to, string $subject, string $text): void
    {
        $storage = BASE_PATH . '/storage';
        if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
            throw new RuntimeException('Development mail log is unavailable.');
        }
        $entry = sprintf(
            "[%s] TO: %s\nSUBJECT: %s\n%s\n---\n",
            gmdate('c'),
            $to,
            $subject,
            $text
        );
        if (file_put_contents($storage . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Development mail log could not be written.');
        }
        @chmod($storage . '/mail.log', 0600);
    }

    private static function sendSmtp(array $config, string $to, string $subject, string $text, string $html): void
    {
        $host = (string)($config['host'] ?? '');
        $port = (int)($config['port'] ?? 587);
        $encryption = (string)($config['encryption'] ?? 'tls');
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        $from = (string)($config['from_email'] ?? '');
        $fromName = (string)($config['from_name'] ?? 'c0m.me Account Center');
        $timeout = max(5, min(30, (int)($config['timeout'] ?? 15)));

        if ($host === '' || $port < 1 || $port > 65535 || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $host . $username . $fromName)
            || !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('SMTP configuration is invalid.');
        }

        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP server connection failed.');
        }
        stream_set_timeout($socket, $timeout);

        try {
            self::expect($socket, [220]);
            $helo = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($config['helo'] ?? 'localhost')) ?: 'localhost';
            self::command($socket, 'EHLO ' . $helo, [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                self::command($socket, 'EHLO ' . $helo, [250]);
            }

            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode($password), [235]);
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);

            $boundary = 'c0m_' . bin2hex(random_bytes(16));
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $helo . '>',
                'From: ' . $encodedName . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
            ];
            if ($html !== '') {
                $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($text))
                    . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($html))
                    . "--{$boundary}--\r\n";
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $body = chunk_split(base64_encode($text));
            }
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $message = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $message));
            fwrite($socket, $message . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } catch (\Throwable $e) {
            error_log('SMTP delivery failed: ' . $e->getMessage());
            throw new RuntimeException('Email delivery failed.');
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $command, array $expected): string
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('SMTP command write failed.');
        }
        return self::expect($socket, $expected);
    }

    private static function expect($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 2048);
            if ($line === false) {
                throw new RuntimeException('SMTP server closed the connection.');
            }
            $response .= $line;
            $code = (int)substr($line, 0, 3);
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP server rejected a command (code ' . $code . ').');
        }
        return $response;
    }
}
