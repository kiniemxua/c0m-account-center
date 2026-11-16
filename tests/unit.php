<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

session_name('c0m_test_' . bin2hex(random_bytes(3)));
session_start();
$token = App\Core\Csrf::token();
check(strlen($token) === 64, 'CSRF token must contain 32 random bytes encoded as hex.');
check(App\Core\Csrf::verify($token), 'The issued CSRF token should validate.');
check(!App\Core\Csrf::verify(str_repeat('0', 64)), 'A different CSRF token must be rejected.');
check(!App\Core\Csrf::verify(null), 'A missing CSRF token must be rejected.');
check(!App\Core\Csrf::verify([]), 'An array CSRF token must be rejected without a type error.');
check(!App\Core\Csrf::verify(new stdClass()), 'An object CSRF token must be rejected without a type error.');
$_SESSION['_csrf'] = ['malformed'];
$recoveredToken = App\Core\Csrf::token();
check(is_string($recoveredToken) && strlen($recoveredToken) === 64, 'A malformed stored CSRF value must be replaced with a valid token.');
check(App\Core\Csrf::verify($recoveredToken), 'The regenerated CSRF token must validate.');
session_destroy();

// Forwarded headers from arbitrary clients must never enable Secure-cookie trust.
$_SERVER['HTTPS'] = 'off';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
check(!App\Core\SessionManager::requestIsSecure(), 'Untrusted forwarded HTTPS headers must be ignored.');
$_SERVER['HTTPS'] = 'on';
check(App\Core\SessionManager::requestIsSecure(), 'Direct HTTPS must be recognized.');

// A configured proxy may assert HTTPS only when its address matches REMOTE_ADDR.
$configPath = BASE_PATH . '/config/app.php';
$configExisted = is_file($configPath);
$oldConfig = $configExisted ? file_get_contents($configPath) : null;
try {
    file_put_contents($configPath, "<?php return ['trusted_proxies' => ['127.0.0.1']];");
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    check(App\Core\SessionManager::requestIsSecure(), 'A configured trusted proxy may assert HTTPS.');

    session_name('c0m_proxy_cookie_test');
    App\Core\SessionManager::start();
    $proxyCookie = session_get_cookie_params();
    check(($proxyCookie['secure'] ?? false) === true, 'A trusted HTTPS proxy must set the actual session cookie Secure flag.');
    session_destroy();

    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    check(!App\Core\SessionManager::requestIsSecure(), 'A non-trusted client must not assert HTTPS through a forwarded header.');
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    check(!App\Core\SessionManager::requestIsSecure(), 'Ambiguous forwarded protocol chains must be rejected.');
} finally {
    if ($configExisted && $oldConfig !== false) {
        file_put_contents($configPath, $oldConfig);
    } else {
        @unlink($configPath);
    }
}

// Router dispatch must instantiate controller handlers whose action methods are
// non-static, instead of failing with a TypeError on every route.
final class RouterFixtureController
{
    public function ping(): void { echo 'pong'; }
}
App\Core\Localization::set('vi');
$router = new App\Core\Router();
$router->get('/router-fixture', [RouterFixtureController::class, 'ping']);
$router->get('/router-missing-method', [RouterFixtureController::class, 'nonexistent']);
ob_start();
$router->dispatch('GET', '/router-fixture');
$dispatched = ob_get_clean();
check($dispatched === 'pong', 'The router must instantiate non-static controller handlers and dispatch them.');
http_response_code(200);
ob_start();
$router->dispatch('GET', '/no-such-route');
$notFound = ob_get_clean();
check(http_response_code() === 404 && trim($notFound) !== '', 'Unknown routes must render the localized 404 page.');
http_response_code(200);

// OAuth 2.0 helpers must enforce exact redirect URI matching and S256 PKCE.
$verifier = str_repeat('A', 43);
$challenge = App\Core\OAuthService::base64UrlEncode(hash('sha256', $verifier, true));
check(App\Core\OAuthService::validVerifier($verifier), 'A valid RFC 7636 verifier must be accepted.');
check(!App\Core\OAuthService::validVerifier(str_repeat('a', 42)), 'A short PKCE verifier must be rejected.');
check(!App\Core\OAuthService::validVerifier(str_repeat('a', 129)), 'An overlong PKCE verifier must be rejected.');
check(App\Core\OAuthService::base64UrlEncode(hash('sha256', $verifier, true)) === $challenge, 'PKCE S256 challenge must use unpadded base64url encoding.');
check(App\Core\OAuthService::validRedirectUri(['https://app.example/callback'], 'https://app.example/callback'), 'A registered redirect URI must match exactly.');
check(!App\Core\OAuthService::validRedirectUri(['https://app.example/callback'], 'https://app.example/callback/'), 'A redirect URI with a different trailing slash must be rejected.');
check(!App\Core\OAuthService::validRedirectUri(['https://app.example/callback'], 'https://APP.example/callback'), 'Redirect URI matching must not normalize host casing.');
$confidentialClient = ['client_type' => 'confidential', 'client_secret_hash' => hash('sha256', 'high-entropy-secret')];
check(App\Core\OAuthService::verifyClientSecret($confidentialClient, 'high-entropy-secret'), 'A valid confidential client secret must verify.');
check(!App\Core\OAuthService::verifyClientSecret($confidentialClient, 'wrong-secret'), 'An invalid confidential client secret must fail.');
check(!App\Core\OAuthService::verifyClientSecret(['client_type' => 'public', 'client_secret_hash' => null], 'secret'), 'Public clients must never authenticate with a client secret.');

// OIDC signing key must be RSA-2048+ and expose a matching RS256 JWKS key.
$jwt = App\Core\OidcSigner::sign(['iss' => 'https://accounts.example.test', 'sub' => '42', 'iat' => time()]);
$jwtParts = explode('.', $jwt);
check(count($jwtParts) === 3, 'An OIDC signed token must have three compact JWT segments.');
$jwtHeader = json_decode(base64_decode(strtr($jwtParts[0], '-_', '+/')), true);
check(is_array($jwtHeader) && ($jwtHeader['alg'] ?? null) === 'RS256' && ($jwtHeader['kid'] ?? '') !== '', 'OIDC tokens must use RS256 and identify the signing key.');
$jwks = App\Core\OidcSigner::jwks();
check(($jwks['keys'][0]['kty'] ?? null) === 'RSA' && ($jwks['keys'][0]['alg'] ?? null) === 'RS256'
    && ($jwks['keys'][0]['kid'] ?? null) === $jwtHeader['kid']
    && isset($jwks['keys'][0]['n'], $jwks['keys'][0]['e']), 'The public JWKS must publish the active RSA signing key.');

// JWKS must retain a previous public key during the rotation overlap window.
$previousDirectory = BASE_PATH . '/storage/oauth-signing-previous';
$createdPreviousDirectory = !is_dir($previousDirectory);
if ($createdPreviousDirectory) {
    mkdir($previousDirectory, 0750, true);
}
$previousKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$previousDetails = $previousKey ? openssl_pkey_get_details($previousKey) : false;
check(is_array($previousDetails) && isset($previousDetails['key']), 'A test previous RSA key must be generated.');
$previousKid = substr(hash('sha256', $previousDetails['key']), 0, 24);
$previousPath = $previousDirectory . '/' . $previousKid . '.pem';
check(file_put_contents($previousPath, $previousDetails['key'], LOCK_EX) !== false, 'A test previous public key must be persisted.');
try {
    $overlapJwks = App\Core\OidcSigner::jwks();
    check(count(array_filter($overlapJwks['keys'], static fn(array $key): bool => ($key['kid'] ?? '') === $previousKid)) === 1,
        'JWKS must publish a previous public key during the overlap period.');
    touch($previousPath, time() - 86401);
    $expiredJwks = App\Core\OidcSigner::jwks();
    check(count(array_filter($expiredJwks['keys'], static fn(array $key): bool => ($key['kid'] ?? '') === $previousKid)) === 0,
        'JWKS must stop publishing previous keys after the 24-hour overlap.');
} finally {
    @unlink($previousPath);
    if ($createdPreviousDirectory) @rmdir($previousDirectory);
}

$webhookSecret = bin2hex(random_bytes(32));
$webhookBody = '{"id":"evt_test","type":"payment.succeeded"}';
$webhookTimestamp = (string)time();
$webhookSignature = hash_hmac('sha256', $webhookTimestamp . '.' . $webhookBody, $webhookSecret);
check(App\Core\PaymentWebhookVerifier::verify($webhookSecret, $webhookBody, 't=' . $webhookTimestamp . ',v1=' . $webhookSignature), 'A valid timestamped payment webhook signature must verify.');
check(!App\Core\PaymentWebhookVerifier::verify($webhookSecret, $webhookBody . ' ', 't=' . $webhookTimestamp . ',v1=' . $webhookSignature), 'A modified webhook body must fail signature verification.');
check(!App\Core\PaymentWebhookVerifier::verify($webhookSecret, $webhookBody, 't=' . ($webhookTimestamp - 900) . ',v1=' . $webhookSignature), 'An expired webhook timestamp must be rejected.');
check(!App\Core\PaymentWebhookVerifier::verify('short', $webhookBody, 't=' . $webhookTimestamp . ',v1=' . $webhookSignature), 'A weak webhook secret must be rejected.');

echo "Unit tests passed.\n";
