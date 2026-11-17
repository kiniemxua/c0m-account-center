<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class DevelopmentLogMailer implements MailProviderInterface
{
    public function send(string $to, string $subject, string $text, string $html = ''): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $storage = BASE_PATH . '/storage';
        if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
            throw new RuntimeException('Development mail log is unavailable.');
        }
        $entry = sprintf("[%s] TO: %s\nSUBJECT: %s\n%s\n---\n", gmdate('c'), $to, $subject, $text);
        if (file_put_contents($storage . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Development mail log could not be written.');
        }
        @chmod($storage . '/mail.log', 0600);
    }
}
