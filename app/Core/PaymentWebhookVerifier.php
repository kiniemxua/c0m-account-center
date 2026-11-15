<?php
declare(strict_types=1);

namespace App\Core;

final class PaymentWebhookVerifier
{
    public static function verify(string $secret, string $rawBody, string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        if (strlen($secret) < 32 || $rawBody === '' || strlen($rawBody) > 65536) return false;
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) continue;
            if ($pair[0] === 't' && preg_match('/\A[0-9]{1,12}\z/D', $pair[1])) {
                $timestamp = (int)$pair[1];
            } elseif ($pair[0] === 'v1' && preg_match('/\A[a-f0-9]{64}\z/D', $pair[1])) {
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $toleranceSeconds) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) return true;
        }
        return false;
    }
}
