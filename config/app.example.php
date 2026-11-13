<?php
declare(strict_types=1);

// Copy to config/app.php. Only list immediate reverse proxies that you control.
// Do not add end-user IP ranges or trust X-Forwarded-* from arbitrary clients.
return [
    'base_url' => 'https://accounts.example.com',
    'trusted_proxies' => [],
];
