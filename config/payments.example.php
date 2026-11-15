<?php
declare(strict_types=1);

/*
 * Copy this file to config/payments.php and replace the example secret.
 * Keep config/payments.php out of version control.
 *
 * Webhook signature format:
 * X-C0M-Provider: generic
 * X-C0M-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256>
 * HMAC input: <timestamp>.<raw request body>
 */
return [
    'providers' => [
        'generic' => [
            'webhook_secret' => 'REPLACE_WITH_AT_LEAST_32_RANDOM_BYTES',
        ],
    ],
];
