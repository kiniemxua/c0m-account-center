<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SmtpMailer implements MailProviderInterface
{
    public function __construct(private readonly array $config)
    {
    }

    public function send(string $to, string $subject, string $text, string $html = ''): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
            throw new RuntimeException('Invalid mail recipient or subject.');
        }
        self::deliver($this->config, $to, $subject, $text, $html);
    }

    private static function deliver(array $config, string $to, string $subject, string $text, string $html): void
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

        if (($username === '') !== ($password === '')) {
            throw new RuntimeException('SMTP username and password must both be configured.');
        }
        if ($encryption === 'none' && $username !== '') {
            throw new RuntimeException('SMTP authentication requires TLS or implicit SSL.');
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
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $helo . '>',
                'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
            ];
            if ($html !== '') {
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($text))
                    . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($html))
                    . "--{$boundary}--\r\n";
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $body = chunk_split(base64_encode($text));
            }
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $message = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $message));
            self::writeAll($socket, $message . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } catch (\Throwable $e) {
            error_log('SMTP delivery failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            throw new RuntimeException('Email delivery failed.');
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $command, array $expected): string
    {
        self::writeAll($socket, $command . "\r\n");
        return self::expect($socket, $expected);
    }

    private static function writeAll($socket, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP socket write failed.');
            }
            $offset += $written;
        }
    }

    private static function expect($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP server timed out.' : 'SMTP server closed the connection.');
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
