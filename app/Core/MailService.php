<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MailService
{
    public static function send(string $to, string $subject, string $text, string $html = ''): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/mail.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('Mail service is not configured.');
        }
        $config = require $configPath;
        $mode = (string)($config['mode'] ?? 'disabled');
        $provider = match ($mode) {
            'development_log' => new DevelopmentLogMailer(),
            'smtp' => new SmtpMailer($config),
            default => throw new RuntimeException('Mail delivery is disabled.'),
        };
        $provider->send($to, $subject, $text, $html);
    }
}
