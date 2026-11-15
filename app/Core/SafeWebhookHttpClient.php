<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SafeWebhookHttpClient
{
    /**
     * Validate the URL and resolve every address before a connection is made.
     * The returned address is pinned with CURLOPT_RESOLVE to prevent a second
     * DNS lookup from redirecting the request to a private network.
     *
     * @return array{url:string,host:string,ip:string}
     */
    public static function resolvePublicTarget(string $url): array
    {
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('invalid_endpoint');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
            throw new RuntimeException('invalid_endpoint');
        }

        $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
        if ($host === '' || preg_match('/[\x00-\x20\x7f]/', $host)) {
            throw new RuntimeException('invalid_endpoint');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // IPv6 literal authorities require a different CURLOPT_RESOLVE
            // host syntax. DNS names with public AAAA records remain supported.
            throw new RuntimeException('invalid_endpoint');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $addresses = [$host];
        } else {
            // Reject non-canonical numeric host forms (127.1, octal and hex
            // IPv4 spellings) rather than letting a client reinterpret them.
            if (preg_match('/\\A[0-9.x]+\\z/i', $host)
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new RuntimeException('invalid_endpoint');
            }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip'])) $addresses[] = $record['ip'];
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) $addresses[] = $record['ipv6'];
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) throw new RuntimeException('endpoint_dns_failed');
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('endpoint_address_not_public');
            }
        }

        return ['url' => $url, 'host' => $host, 'ip' => $addresses[0]];
    }

    /**
     * Send one HTTPS POST without redirects or environment proxy routing.
     * TLS certificate and hostname verification remain enabled.
     */
    public static function post(string $url, array $headers, string $body): int
    {
        $target = self::resolvePublicTarget($url);
        if (!extension_loaded('curl')) throw new RuntimeException('curl_extension_required');

        $resolveAddress = str_contains($target['ip'], ':')
            ? '[' . $target['ip'] . ']'
            : $target['ip'];
        $handle = curl_init($target['url']);
        if ($handle === false) throw new RuntimeException('curl_initialization_failed');

        try {
            $responseBytes = 0;
            $configured = curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBytes): int {
                    $responseBytes += strlen($chunk);
                    return $responseBytes > 65536 ? 0 : strlen($chunk);
                },
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_RESOLVE => [$target['host'] . ':443:' . $resolveAddress],
            ]);
            if (!$configured) throw new RuntimeException('curl_configuration_failed');
            $response = curl_exec($handle);
            if ($response === false) throw new RuntimeException('transport_error');
            return (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
    }
}
