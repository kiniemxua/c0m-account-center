<?php
declare(strict_types=1);

// Copy to config/mail.php. Never commit real SMTP credentials.
return [
    // Use development_log only on a local/staging environment.
    'mode' => 'development_log',
    // Production SMTP example (CraneMail or another external provider):
    // 'mode' => 'smtp',
    // 'host' => 'smtp.example.com',
    // 'port' => 587,
    // 'encryption' => 'tls', // tls (STARTTLS), ssl, or none (not recommended)
    // 'username' => 'your-mailbox@example.com',
    // 'password' => 'CHANGE_ME',
    // 'from_email' => 'no-reply@example.com',
    // 'from_name' => 'c0m.me Account Center',
    // 'helo' => 'accounts.example.com',
    // 'timeout' => 15,
];
