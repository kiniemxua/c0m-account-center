<?php
declare(strict_types=1);

namespace App\Core;

interface MailProviderInterface
{
    public function send(string $to, string $subject, string $text, string $html = ''): void;
}
