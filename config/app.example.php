<?php
declare(strict_types=1);

// Copy to config/app.php and set the canonical HTTPS URL.
// Do not derive security-sensitive email links from the incoming Host header.
return [
    'base_url' => 'https://accounts.example.com',
];
