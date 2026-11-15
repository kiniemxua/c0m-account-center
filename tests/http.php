<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('UTC');

function httpCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dbConfigPath = BASE_PATH . '/config/database.php';
$appConfigPath = BASE_PATH . '/config/app.php';
$mailConfigPath = BASE_PATH . '/config/mail.php';
$paymentConfigPath = BASE_PATH . '/config/payments.php';
$mailLogPath = BASE_PATH . '/storage/mail.log';
if (is_file($dbConfigPath) || is_file($appConfigPath) || is_file($mailConfigPath) || is_file($paymentConfigPath) || is_file($mailLogPath)) {
    throw new RuntimeException('HTTP test refuses to overwrite an existing database config.');
}
$dbConfig = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'c0m_test',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'app_key' => bin2hex(random_bytes(32)),
];
if (!is_dir(BASE_PATH . '/config')) {
    throw new RuntimeException('Config directory is missing.');
}
$configSource = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($dbConfig, true) . ";\n";
if (file_put_contents($dbConfigPath, $configSource, LOCK_EX) === false) {
    throw new RuntimeException('Could not create temporary HTTP test configuration.');
}
require_once BASE_PATH . '/app/Core/Crypto.php';
$cryptoSecret = bin2hex(random_bytes(32));
$encryptedSecret = App\Core\Crypto::encrypt($cryptoSecret);
httpCheck(!str_contains($encryptedSecret, $cryptoSecret), 'Webhook secrets must be encrypted at rest.');
httpCheck(App\Core\Crypto::decrypt($encryptedSecret) === $cryptoSecret, 'Webhook secret encryption must round-trip.');
$encryptedPayload = base64_decode($encryptedSecret, true);
$encryptedPayload[strlen($encryptedPayload) - 1] = chr(ord($encryptedPayload[strlen($encryptedPayload) - 1]) ^ 1);
try {
    App\Core\Crypto::decrypt(base64_encode($encryptedPayload));
    throw new RuntimeException('Tampered encrypted webhook secrets must be rejected.');
} catch (RuntimeException $e) {
    httpCheck($e->getMessage() !== 'Tampered encrypted webhook secrets must be rejected.', 'Authenticated encryption must reject modified ciphertext.');
}
if (file_put_contents($appConfigPath, "<?php return ['base_url' => 'https://accounts.example.test', 'trusted_proxies' => ['127.0.0.1']];", LOCK_EX) === false) {
    @unlink($dbConfigPath);
    throw new RuntimeException('Could not create temporary trusted-proxy configuration.');
}
if (file_put_contents($mailConfigPath, "<?php return ['mode' => 'development_log'];", LOCK_EX) === false) {
    @unlink($dbConfigPath);
    @unlink($appConfigPath);
    throw new RuntimeException('Could not create temporary development mail configuration.');
}
$paymentTestSecret = bin2hex(random_bytes(32));
$paymentConfigSource = "<?php return " . var_export(['providers' => ['generic' => ['webhook_secret' => $paymentTestSecret]]], true) . ";";
if (file_put_contents($paymentConfigPath, $paymentConfigSource, LOCK_EX) === false) {
    @unlink($dbConfigPath);
    @unlink($appConfigPath);
    @unlink($mailConfigPath);
    throw new RuntimeException('Could not create temporary payment webhook configuration.');
}

$pdo = null;
$testUserId = null;
$registryAppId = null;
$registryGroupId = null;
$flowEmail = null;
$planKey = null;
$paymentEventId = null;
$paymentEventIds = [];
$process = null;
$pipes = [];
$serverStdoutPath = '';
$serverStderrPath = '';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbConfig['host'], $dbConfig['port'], $dbConfig['name']),
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $testEmail = 'http-' . bin2hex(random_bytes(5)) . '@example.test';
    $testPassword = 'HttpTestPassword-456';
    $pdo->prepare("INSERT INTO users (email, password_hash, display_name, status, email_verified_at) VALUES (:email, :hash, 'HTTP test member', 'active', NOW())")
        ->execute(['email' => $testEmail, 'hash' => password_hash($testPassword, PASSWORD_DEFAULT)]);
    $testUserId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (:id)')->execute(['id' => $testUserId]);
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'member'")->execute(['id' => $testUserId]);

    $portSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$portSocket) {
        throw new RuntimeException('Could not reserve a local HTTP test port.');
    }
    $address = stream_socket_get_name($portSocket, false);
    fclose($portSocket);
    if (!is_string($address) || !str_contains($address, ':')) {
        throw new RuntimeException('Could not determine the local HTTP test port.');
    }
    $port = (int)substr(strrchr($address, ':'), 1);

    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', BASE_PATH . '/public', BASE_PATH . '/public/index.php'];
    $serverStdoutPath = sys_get_temp_dir() . '/c0m-http-test-server-stdout.log';
    $serverStderrPath = sys_get_temp_dir() . '/c0m-http-test-server-stderr.log';
    // The built-in server logs every accepted connection to stderr. The test
    // never reads the child's pipes while it runs, and on Windows proc_open
    // pipe buffers are small (~4 KB), so the server would block forever once
    // the buffer fills. Writing both streams to files keeps the server
    // unblocked on every platform for the whole test run.
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $serverStdoutPath, 'w'],
        2 => ['file', $serverStderrPath, 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the PHP HTTP test server.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $base = 'http://127.0.0.1:' . $port;
    $request = static function (string $method, string $path, string $body = '', string $cookie = '', bool $trustedHttps = true, array $extraHeaders = []) use ($base): array {
        $headers = ["Connection: close"];
        if ($trustedHttps) {
            $headers[] = "X-Forwarded-Proto: https";
        }
        foreach ($extraHeaders as $extraHeader) {
            if (is_string($extraHeader) && !preg_match('/[\\r\\n]/', $extraHeader)) {
                $headers[] = $extraHeader;
            }
        }
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if ($method === 'POST' && !array_filter($headers, static fn($header): bool => stripos($header, 'Content-Type:') === 0)) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 3,
        ]]);
        $response = @file_get_contents($base . $path, false, $context);
        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($rawHeaders as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $status = (int)$match[1];
            }
        }
        return ['status' => $status, 'headers' => $rawHeaders, 'body' => $response === false ? '' : $response];
    };

    // proc_open() returns before the PHP development server is accepting
    // connections. Establish readiness using a trusted HTTPS-proxy request
    // before asserting the deliberately insecure request; otherwise CI can
    // observe connection status 0 and report a false HTTPS regression.
    $startupDeadline = microtime(true) + 8;
    do {
        $startup = $request('GET', '/login');
        if ($startup['status'] === 200) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $startupDeadline);
    httpCheck($startup['status'] === 200, 'The HTTP test server must become ready before security assertions (status ' . $startup['status'] . ').');

    $insecureRequest = $request('GET', '/login', '', '', false);
    httpCheck($insecureRequest['status'] === 400 && str_contains($insecureRequest['body'], 'Yêu cầu HTTPS'), 'The application must reject HTTP when HTTPS is not proven.');

    $login = $startup;

    httpCheck($login['status'] === 200, 'The actual HTTP router must render the login page (status ' . $login['status'] . ', response ' . substr(strip_tags(preg_replace('/value="[a-f0-9]{64}"/', 'value="[redacted]"', $login['body'])), 0, 180) . ').');
    httpCheck(str_contains(implode("\n", $login['headers']), 'X-Content-Type-Options: nosniff'), 'HTTP security headers must be emitted by bootstrap.');

    $cookie = '';
    foreach ($login['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_session=') === 0) {
            $cookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
            httpCheck(stripos($header, 'httponly') !== false, 'Session cookies must be HttpOnly.');
            httpCheck(stripos($header, 'samesite=Lax') !== false, 'Session cookies must use SameSite=Lax.');
            httpCheck(stripos($header, 'secure') !== false, 'Session cookies must use Secure when HTTPS is asserted by the configured test proxy.');
        }
    }
    httpCheck($cookie !== '', 'The login response must issue a session cookie.');

    $csrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $login['body'], $match)) {
        $csrf = $match[1];
    }
    httpCheck($csrf !== '', 'The login form must expose its session-bound CSRF token.');
    httpCheck(str_contains($login['body'], '<html lang="vi">'), 'Guest requests must default to Vietnamese.');

    $guestLanguage = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf,
        'language' => 'en',
        'return_to' => '/login',
    ]), $cookie);
    httpCheck($guestLanguage['status'] === 303, 'Guest language switching must redirect safely.');
    $missingLanguageCsrf = $request('POST', '/language', http_build_query(['language' => 'en']), $cookie);
    httpCheck($missingLanguageCsrf['status'] === 419, 'Language changes must reject missing CSRF tokens.');
    $invalidLanguage = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf, 'language' => '../en.php', 'return_to' => '/login',
    ]), $cookie);
    httpCheck($invalidLanguage['status'] === 422, 'Unsupported locale values must be rejected.');
    $unsafeRedirect = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf, 'language' => 'en', 'return_to' => '//evil.example/path',
    ]), $cookie);
    httpCheck($unsafeRedirect['status'] === 303 && in_array('Location: /', $unsafeRedirect['headers'], true), 'Protocol-relative return targets must fall back to the local home page.');
    $encodedUnsafeRedirect = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf, 'language' => 'en', 'return_to' => '/%2f%2fevil.example/path',
    ]), $cookie);
    httpCheck($encodedUnsafeRedirect['status'] === 303 && in_array('Location: /', $encodedUnsafeRedirect['headers'], true), 'Encoded protocol-relative return targets must not be used.');
    $malformedRedirect = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf, 'language' => 'en', 'return_to' => 'http://[::1',
    ]), $cookie);
    httpCheck($malformedRedirect['status'] === 303 && in_array('Location: /', $malformedRedirect['headers'], true), 'Malformed redirect targets must safely fall back without a server error.');
    $controlByteRedirect = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf, 'language' => 'en', 'return_to' => "/safe\r\nLocation: https://evil.example",
    ]), $cookie);
    httpCheck($controlByteRedirect['status'] === 303 && in_array('Location: /', $controlByteRedirect['headers'], true), 'Raw control bytes in redirect targets must be rejected before URL parsing.');
    $encodedControlRedirect = $request('POST', '/language', http_build_query([
        '_csrf' => $csrf, 'language' => 'en', 'return_to' => '/%0d%0aLocation:%20https://evil.example',
    ]), $cookie);
    httpCheck($encodedControlRedirect['status'] === 303 && in_array('Location: /', $encodedControlRedirect['headers'], true), 'Percent-encoded control bytes in redirect paths must be rejected.');
    $localeCookie = '';
    foreach ($guestLanguage['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_lang=en') === 0) {
            $localeCookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
        }
    }
    httpCheck($localeCookie === 'c0m_lang=en', 'Guest language selection must persist in the locale cookie.');
    $localeCookieHeader = '';
    foreach ($guestLanguage['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_lang=en') === 0) $localeCookieHeader = $header;
    }
    httpCheck(stripos($localeCookieHeader, 'httponly') !== false && stripos($localeCookieHeader, 'samesite=Lax') !== false && stripos($localeCookieHeader, 'secure') !== false, 'Locale cookies must be HttpOnly, SameSite=Lax and Secure behind the trusted HTTPS proxy.');
    $cookie .= '; ' . $localeCookie;
    $englishLogin = $request('GET', '/login', '', $cookie);
    httpCheck($englishLogin['status'] === 200 && str_contains($englishLogin['body'], '<html lang="en">'), 'The guest language must persist across application pages.');
    if (preg_match('/name="_csrf"\\s+value="([a-f0-9]{64})"/', $englishLogin['body'], $match)) {
        $csrf = $match[1];
    }

    // Exercise registration, email verification, password reset and replay through the real router.
    $flowEmail = 'http-flow-' . bin2hex(random_bytes(5)) . '@example.test';
    $flowPassword = 'HttpFlowInitialPass42';
    $registerPage = $request('GET', '/register', '', $cookie);
    httpCheck($registerPage['status'] === 200, 'The registration form must render through HTTP.');
    $registerCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $registerPage['body'], $match)) {
        $registerCsrf = $match[1];
    }
    httpCheck($registerCsrf !== '', 'Registration form must include CSRF protection.');
    $registerResponse = $request('POST', '/register', http_build_query([
        '_csrf' => $registerCsrf,
        'display_name' => 'HTTP Flow Member',
        'email' => $flowEmail,
        'password' => $flowPassword,
    ]), $cookie);
    httpCheck($registerResponse['status'] === 200 && str_contains($registerResponse['body'], 'verification email'), 'Registration must complete through the HTTP router.');

    $mailLog = is_file($mailLogPath) ? (string)file_get_contents($mailLogPath) : '';
    httpCheck(str_contains($mailLog, 'Hello HTTP Flow Member'), 'Verification email must use the guest-selected English language.');
    httpCheck(preg_match('/verify-email\?token=([a-f0-9]{64})/', $mailLog, $verifyMatch) === 1, 'Registration must send a one-time verification link through the mail adapter.');
    $verifyToken = $verifyMatch[1];
    $verifyPage = $request('GET', '/verify-email?token=' . rawurlencode($verifyToken), '', $cookie);
    httpCheck($verifyPage['status'] === 200 && str_contains($verifyPage['body'], 'Confirm your email'), 'The verification token must render a confirmation page.');
    $verifyCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $verifyPage['body'], $match)) {
        $verifyCsrf = $match[1];
    }
    $verified = $request('POST', '/verify-email', http_build_query(['_csrf' => $verifyCsrf, 'token' => $verifyToken]), $cookie);
    httpCheck($verified['status'] === 200 && str_contains($verified['body'], 'Email verified'), 'Email verification must activate the account through HTTP.');
    $verifiedRevision = $pdo->prepare('SELECT revision FROM user_authorization_revisions WHERE user_id = (SELECT id FROM users WHERE email = :email)');
    $verifiedRevision->execute(['email' => $flowEmail]);
    httpCheck((int)$verifiedRevision->fetchColumn() === 2, 'Account activation must advance the authorization revision.');
    $verifyReplay = $request('POST', '/verify-email', http_build_query(['_csrf' => $verifyCsrf, 'token' => $verifyToken]), $cookie);
    httpCheck(str_contains($verifyReplay['body'], 'Link unavailable'), 'Email verification token replay must be rejected over HTTP.');

    // Recipient preference must control system email independently of browser locale.
    $pdo->prepare("UPDATE user_profiles SET language = 'vi' WHERE user_id = (SELECT id FROM users WHERE email = :email)")
        ->execute(['email' => $flowEmail]);
    $forgotPage = $request('GET', '/forgot-password', '', $cookie);
    $forgotCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $forgotPage['body'], $match)) {
        $forgotCsrf = $match[1];
    }
    $forgotResponse = $request('POST', '/forgot-password', http_build_query(['_csrf' => $forgotCsrf, 'email' => $flowEmail]), $cookie);
    httpCheck($forgotResponse['status'] === 200 && str_contains($forgotResponse['body'], 'If an active account matches'), 'Password recovery must return the generic HTTP response.');
    $mailLog = (string)file_get_contents($mailLogPath);
    httpCheck(str_contains($mailLog, 'Sử dụng liên kết một lần sau đây để đặt lại mật khẩu'), 'Password reset email must use the recipient saved Vietnamese preference even when the browser is English.');
    httpCheck(preg_match('/reset-password\?token=([a-f0-9]{64})/', $mailLog, $resetMatch) === 1, 'Password recovery must send a one-time reset link through the mail adapter.');
    $resetToken = $resetMatch[1];
    $resetPage = $request('GET', '/reset-password?token=' . rawurlencode($resetToken), '', $cookie);
    httpCheck($resetPage['status'] === 200 && str_contains($resetPage['body'], 'Choose a new password'), 'The reset token must render a password form.');
    $resetCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $resetPage['body'], $match)) {
        $resetCsrf = $match[1];
    }
    $newFlowPassword = 'HttpFlowUpdatedPass84';
    $resetResponse = $request('POST', '/reset-password', http_build_query([
        '_csrf' => $resetCsrf,
        'token' => $resetToken,
        'password' => $newFlowPassword,
    ]), $cookie);
    httpCheck($resetResponse['status'] === 200, 'Password reset must complete through the HTTP router.');
    $flowUser = $pdo->prepare('SELECT id, status, password_hash FROM users WHERE email = :email');
    $flowUser->execute(['email' => $flowEmail]);
    $flowUserRow = $flowUser->fetch();
    httpCheck($flowUserRow && $flowUserRow['status'] === 'active' && password_verify($newFlowPassword, $flowUserRow['password_hash']), 'HTTP password reset must persist the new password for the verified account.');
    $pdo->prepare("UPDATE user_profiles SET language = 'en' WHERE user_id = :id")->execute(['id' => (int)$flowUserRow['id']]);
    $flowRole = $pdo->prepare(
        "SELECT r.role_key FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id"
    );
    $flowRole->execute(['id' => (int)$flowUserRow['id']]);
    httpCheck($flowRole->fetchColumn() === 'member', 'New registrations must receive the default Member role.');
    $resetReplay = $request('POST', '/reset-password', http_build_query([
        '_csrf' => $resetCsrf,
        'token' => $resetToken,
        'password' => 'AnotherUpdatedPass85',
    ]), $cookie);
    httpCheck($resetReplay['status'] === 200, 'Password reset replay must return a safe result page.');
    $flowUser->execute(['email' => $flowEmail]);
    httpCheck(password_verify($newFlowPassword, $flowUser->fetch()['password_hash']), 'A consumed reset token must not change the password again.');

    $flowLoginPage = $request('GET', '/login', '', $cookie);
    $flowLoginCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $flowLoginPage['body'], $match)) {
        $flowLoginCsrf = $match[1];
    }
    $flowLogin = $request('POST', '/login', http_build_query([
        '_csrf' => $flowLoginCsrf,
        'email' => $flowEmail,
        'password' => $newFlowPassword,
    ]), $cookie);
    httpCheck($flowLogin['status'] === 303, 'The reset password must authenticate successfully through HTTP.');
    $flowAuthCookie = '';
    foreach ($flowLogin['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_session=') === 0) {
            $flowAuthCookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
        }
    }
    httpCheck($flowAuthCookie !== '', 'HTTP login after password reset must issue a rotated session cookie.');
    $flowAuthCookie .= '; ' . $localeCookie;
    $flowDashboard = $request('GET', '/dashboard', '', $flowAuthCookie);
    httpCheck($flowDashboard['status'] === 200 && str_contains($flowDashboard['body'], '<html lang="en">') && str_contains($flowDashboard['body'], 'HTTP Flow Member'), 'The authenticated account must keep its saved English language after login.');
    $flowLogoutCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $flowDashboard['body'], $match)) {
        $flowLogoutCsrf = $match[1];
    }
    $flowLogout = $request('POST', '/logout', http_build_query(['_csrf' => $flowLogoutCsrf]), $flowAuthCookie);
    httpCheck($flowLogout['status'] === 303, 'The HTTP flow account must be able to log out.');
    $afterLocaleLogout = $request('GET', '/login', '', $flowAuthCookie);
    httpCheck($afterLocaleLogout['status'] === 200 && str_contains($afterLocaleLogout['body'], '<html lang="en">'), 'The guest language must remain selected after logout.');

    // Login regenerated and logout destroyed the prior session; start a fresh CSRF session for the member fixture.
    $login = $request('GET', '/login');
    $cookie = '';
    foreach ($login['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_session=') === 0) {
            $cookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
        }
    }
    $csrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $login['body'], $match)) {
        $csrf = $match[1];
    }
    httpCheck($cookie !== '' && $csrf !== '', 'A fresh login form must issue a new session and CSRF token after logout.');

    $badCsrf = $request('POST', '/login', 'email=invalid%40example.test&password=invalid', $cookie);
    httpCheck($badCsrf['status'] === 419, 'The HTTP login endpoint must reject a missing CSRF token.');

    $badAdminCsrf = $request('POST', '/admin/users/status', 'user_id=1&status=banned', $cookie);
    httpCheck($badAdminCsrf['status'] === 419, 'The HTTP admin mutation endpoint must reject a missing CSRF token.');

    $admin = $request('GET', '/admin/users', '', $cookie);
    httpCheck($admin['status'] === 401, 'An unauthenticated HTTP request must not access admin member management.');

    $settings = $request('GET', '/admin/settings', '', $cookie);
    httpCheck($settings['status'] === 401, 'An unauthenticated HTTP request must not access admin settings.');
    $applications = $request('GET', '/admin/applications', '', $cookie);
    httpCheck($applications['status'] === 401, 'An unauthenticated HTTP request must not access the application registry.');
    $applicationsCsrf = $request('POST', '/admin/applications/save', 'app_key=missing-csrf', $cookie);
    httpCheck($applicationsCsrf['status'] === 419, 'Application registry writes must reject missing CSRF tokens.');
    $groupsAnonymous = $request('GET', '/admin/groups', '', $cookie);
    httpCheck($groupsAnonymous['status'] === 401, 'An unauthenticated HTTP request must not access member groups.');
    $groupsCsrf = $request('POST', '/admin/groups', 'action=create', $cookie);
    httpCheck($groupsCsrf['status'] === 419, 'Member group writes must reject missing CSRF tokens.');

    $loginSuccess = $request('POST', '/login', http_build_query([
        '_csrf' => $csrf,
        'email' => $testEmail,
        'password' => $testPassword,
    ]), $cookie);
    httpCheck($loginSuccess['status'] === 303, 'A valid member login must complete through the HTTP router.');
    $authCookie = '';
    foreach ($loginSuccess['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_session=') === 0) {
            $authCookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
        }
    }
    httpCheck($authCookie !== '', 'Successful HTTP login must rotate and issue an authenticated session cookie.');

    $dashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck($dashboard['status'] === 200 && str_contains($dashboard['body'], 'HTTP test member'), 'The authenticated member must reach the dashboard through HTTP.');
    $memberAdmin = $request('GET', '/admin/users', '', $authCookie);
    httpCheck($memberAdmin['status'] === 403, 'A member must be denied admin routes even with a valid session.');
    $memberApplications = $request('GET', '/admin/applications', '', $authCookie);
    httpCheck($memberApplications['status'] === 403, 'A member must not manage the application registry.');
    $memberGroups = $request('GET', '/admin/groups', '', $authCookie);
    httpCheck($memberGroups['status'] === 403, 'A member must not manage groups.');

    $dashboardCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $dashboard['body'], $match)) {
        $dashboardCsrf = $match[1];
    }
    httpCheck($dashboardCsrf !== '', 'The authenticated dashboard must issue a logout CSRF token.');
    $memberLanguage = $request('POST', '/language', http_build_query([
        '_csrf' => $dashboardCsrf, 'language' => 'en', 'return_to' => '/dashboard',
    ]), $authCookie);
    httpCheck($memberLanguage['status'] === 303 && in_array('Location: /dashboard', $memberLanguage['headers'], true), 'Authenticated language switching must redirect to the local dashboard.');
    $memberPreference = $pdo->prepare('SELECT language FROM user_profiles WHERE user_id = :id');
    $memberPreference->execute(['id' => $testUserId]);
    httpCheck($memberPreference->fetchColumn() === 'en', 'Authenticated language switching must persist the member profile preference.');
    $memberLocaleCookie = '';
    foreach ($memberLanguage['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_lang=en') === 0) {
            $memberLocaleCookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
        }
    }
    httpCheck($memberLocaleCookie === 'c0m_lang=en', 'Authenticated language changes must update the guest fallback cookie.');
    $authCookie .= '; ' . $memberLocaleCookie;
    $englishDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck($englishDashboard['status'] === 200 && str_contains($englishDashboard['body'], '<html lang="en">'), 'The saved member language must override prior session and cookie state.');
    $memberSwitchBackCsrf = '';
    if (preg_match('/name="_csrf"\\s+value="([a-f0-9]{64})"/', $englishDashboard['body'], $match)) {
        $memberSwitchBackCsrf = $match[1];
    }
    $memberSwitchBack = $request('POST', '/language', http_build_query([
        '_csrf' => $memberSwitchBackCsrf, 'language' => 'vi', 'return_to' => '/dashboard',
    ]), $authCookie);
    httpCheck($memberSwitchBack['status'] === 303, 'A member must be able to switch the saved preference back to Vietnamese.');
    $memberVietnameseDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck($memberVietnameseDashboard['status'] === 200 && str_contains($memberVietnameseDashboard['body'], '<html lang="vi">'), 'The Vietnamese account preference must override a stale English cookie/session.');
    $memberPreference->execute(['id' => $testUserId]);
    httpCheck($memberPreference->fetchColumn() === 'vi', 'Switching back to Vietnamese must persist the account preference.');
    $memberLocaleCookieVi = '';
    foreach ($memberSwitchBack['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_lang=vi') === 0) $memberLocaleCookieVi = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
    }
    httpCheck($memberLocaleCookieVi === 'c0m_lang=vi', 'Switching back to Vietnamese must synchronize the guest fallback cookie.');
    $authCookie .= '; ' . $memberLocaleCookieVi;
    // Promote this isolated fixture to Admin and exercise the real admin locale flow.
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'admin'")
        ->execute(['id' => $testUserId]);
    $adminApplications = $request('GET', '/admin/applications', '', $authCookie);
    httpCheck($adminApplications['status'] === 403, 'An Admin without apps.manage must not manage the application registry.');
    $adminGroups = $request('GET', '/admin/groups', '', $authCookie);
    httpCheck($adminGroups['status'] === 403, 'An Admin without groups.manage must not manage groups.');
    $adminPage = $request('GET', '/admin/users', '', $authCookie);
    httpCheck($adminPage['status'] === 200 && str_contains($adminPage['body'], '<html lang="vi">'), 'An authenticated administrator must reach the admin interface in the saved Vietnamese account language.');
    $adminCsrf = '';
    if (preg_match('/name="_csrf"\\s+value="([a-f0-9]{64})"/', $adminPage['body'], $match)) {
        $adminCsrf = $match[1];
    }
    httpCheck($adminCsrf !== '', 'The administrator interface must expose the normal CSRF token.');
    $adminLanguage = $request('POST', '/language', http_build_query([
        '_csrf' => $adminCsrf, 'language' => 'en', 'return_to' => '/admin/users',
    ]), $authCookie);
    httpCheck($adminLanguage['status'] === 303 && in_array('Location: /admin/users', $adminLanguage['headers'], true), 'An administrator must be able to switch language from an admin route.');
    $adminPreference = $pdo->prepare('SELECT language FROM user_profiles WHERE user_id = :id');
    $adminPreference->execute(['id' => $testUserId]);
    httpCheck($adminPreference->fetchColumn() === 'en', 'Administrator language switching must persist the account preference.');
    $adminPageEn = $request('GET', '/admin/users', '', $authCookie);
    httpCheck($adminPageEn['status'] === 200 && str_contains($adminPageEn['body'], '<html lang="en">'), 'The administrator interface must render in the newly saved English language.');
    $adminCsrfEn = '';
    if (preg_match('/name="_csrf"\\s+value="([a-f0-9]{64})"/', $adminPageEn['body'], $match)) {
        $adminCsrfEn = $match[1];
    }
    httpCheck($adminCsrfEn !== '', 'The English admin page must retain a valid CSRF token.');
    $adminLanguageBack = $request('POST', '/language', http_build_query([
        '_csrf' => $adminCsrfEn, 'language' => 'vi', 'return_to' => '/admin/users',
    ]), $authCookie);
    httpCheck($adminLanguageBack['status'] === 303, 'An administrator must be able to switch back to Vietnamese.');
    $adminPageVi = $request('GET', '/admin/users', '', $authCookie);
    httpCheck($adminPageVi['status'] === 200 && str_contains($adminPageVi['body'], '<html lang="vi">'), 'The administrator Vietnamese preference must persist across requests.');

    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'super_admin'")
        ->execute(['id' => $testUserId]);
    $registryPage = $request('GET', '/admin/applications', '', $authCookie);
    httpCheck($registryPage['status'] === 200 && str_contains($registryPage['body'], '<html lang="vi">'), 'A Super Admin must open the localized application registry.');
    $registryCsrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $registryPage['body'], $match)) {
        $registryCsrf = $match[1];
    }
    httpCheck($registryCsrf !== '', 'The application registry form must include CSRF protection.');

    $groupsPage = $request('GET', '/admin/groups', '', $authCookie);
    httpCheck($groupsPage['status'] === 200 && str_contains($groupsPage['body'], '<html lang="vi">'), 'A Super Admin must open the localized member groups page.');
    $groupKey = 'http-group-' . bin2hex(random_bytes(5));
    $groupCreate = $request('POST', '/admin/groups', http_build_query([
        '_csrf' => $registryCsrf,
        'action' => 'create',
        'group_key' => $groupKey,
        'name' => 'HTTP Restricted Group',
        'description' => 'Isolated HTTP group fixture.',
    ]), $authCookie);
    httpCheck($groupCreate['status'] === 303, 'A Super Admin must create a member group through HTTP.');
    $groupLookup = $pdo->prepare('SELECT id FROM member_groups WHERE group_key = :group_key');
    $groupLookup->execute(['group_key' => $groupKey]);
    $registryGroupId = (int)$groupLookup->fetchColumn();
    httpCheck($registryGroupId > 0, 'The member group must persist in the database.');

    $userRevision = $pdo->prepare('SELECT revision FROM user_authorization_revisions WHERE user_id = :id');
    $userRevision->execute(['id' => $testUserId]);
    $revisionBeforeGroup = (int)$userRevision->fetchColumn();
    if ($revisionBeforeGroup < 1) $revisionBeforeGroup = 1;
    $assignGroup = $request('POST', '/admin/groups', http_build_query([
        '_csrf' => $registryCsrf,
        'action' => 'members',
        'group_id' => $registryGroupId,
        'user_ids' => [(string)$testUserId],
    ]), $authCookie);
    httpCheck($assignGroup['status'] === 303, 'A Super Admin must assign members to a group through HTTP.');
    $groupMembership = $pdo->prepare('SELECT COUNT(*) FROM user_groups WHERE user_id = :user_id AND group_id = :group_id');
    $groupMembership->execute(['user_id' => $testUserId, 'group_id' => $registryGroupId]);
    httpCheck((int)$groupMembership->fetchColumn() === 1, 'Group assignment must persist.');
    $userRevision->execute(['id' => $testUserId]);
    httpCheck((int)$userRevision->fetchColumn() === $revisionBeforeGroup + 1, 'Group membership changes must advance the authorization revision.');

    $statusRevision = $pdo->prepare('SELECT revision FROM user_authorization_revisions WHERE user_id = :id');
    $statusRevision->execute(['id' => (int)$flowUserRow['id']]);
    $flowRevision = (int)$statusRevision->fetchColumn();
    $suspendMember = $request('POST', '/admin/users/status', http_build_query([
        '_csrf' => $registryCsrf,
        'user_id' => (int)$flowUserRow['id'],
        'status' => 'suspended',
    ]), $authCookie);
    httpCheck($suspendMember['status'] === 303, 'A Super Admin must be able to suspend a member through the admin route.');
    $statusRevision->execute(['id' => (int)$flowUserRow['id']]);
    httpCheck((int)$statusRevision->fetchColumn() === $flowRevision + 1, 'Changing account status must advance the authorization revision.');
    $repeatSuspend = $request('POST', '/admin/users/status', http_build_query([
        '_csrf' => $registryCsrf,
        'user_id' => (int)$flowUserRow['id'],
        'status' => 'suspended',
    ]), $authCookie);
    httpCheck($repeatSuspend['status'] === 303, 'An idempotent status submission must remain successful.');
    $statusRevision->execute(['id' => (int)$flowUserRow['id']]);
    httpCheck((int)$statusRevision->fetchColumn() === $flowRevision + 1, 'Submitting an unchanged account status must not advance the authorization revision.');

    $registryKey = 'http-registry-' . bin2hex(random_bytes(5));
    $registryName = 'HTTP Registry App';
    $registryFields = [
        'app_key' => $registryKey,
        'name' => $registryName,
        'description' => 'Created by the isolated HTTP registry test.',
        'url' => 'https://registry.example.test/app',
        'icon_path' => '/assets/registry-test.png',
        'category' => 'Testing',
        'version' => '1.0',
        'status' => 'active',
        'access_level' => 'members',
        'featured' => '1',
    ];
    $registryCreate = $request('POST', '/admin/applications/save', http_build_query(['_csrf' => $registryCsrf] + $registryFields), $authCookie);
    httpCheck($registryCreate['status'] === 303 && in_array('Location: /admin/applications', $registryCreate['headers'], true), 'A Super Admin must create an application through the real HTTP route.');
    $registryLookup = $pdo->prepare('SELECT id, name, url, status, access_level, featured FROM applications WHERE app_key = :app_key');
    $registryLookup->execute(['app_key' => $registryKey]);
    $registryRow = $registryLookup->fetch();
    httpCheck($registryRow && $registryRow['name'] === $registryName && $registryRow['status'] === 'active' && $registryRow['access_level'] === 'members' && (int)$registryRow['featured'] === 1, 'Application creation must persist the submitted registry fields.');
    $registryAppId = (int)$registryRow['id'];

    // Create the OAuth client through the Super Admin interface, then
    // exercise the complete Authorization Code + PKCE and OIDC flow.
    $oauthAdminPage = $request('GET', '/admin/oauth-clients', '', $authCookie);
    httpCheck($oauthAdminPage['status'] === 200 && str_contains($oauthAdminPage['body'], 'OAuth client'), 'A Super Admin must access OAuth client management.');
    $oauthAdminCsrf = '';
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $oauthAdminPage['body'], $match)) {
        $oauthAdminCsrf = $match[1];
    }
    httpCheck($oauthAdminCsrf !== '', 'OAuth client management must expose CSRF protection.');
    $oauthRedirect = 'https://oauth.example.test/callback';
    $oauthScopes = ['openid', 'profile', 'email', 'groups', 'permissions', 'entitlements'];
    $oauthClientCreate = $request('POST', '/admin/oauth-clients/create', http_build_query([
        '_csrf' => $oauthAdminCsrf,
        'application_id' => (string)$registryAppId,
        'client_type' => 'public',
        'redirect_uris' => $oauthRedirect,
        'scopes' => $oauthScopes,
    ]), $authCookie);
    httpCheck($oauthClientCreate['status'] === 303, 'A Super Admin must provision an OAuth client through HTTP.');
    $oauthClientLookup = $pdo->prepare('SELECT client_id FROM oauth_clients WHERE application_id = :application_id');
    $oauthClientLookup->execute(['application_id' => $registryAppId]);
    $oauthClientId = (string)$oauthClientLookup->fetchColumn();
    httpCheck(preg_match('/\\A[a-f0-9]{64}\\z/D', $oauthClientId) === 1, 'The OAuth client ID must be generated and stored.');
    $discovery = $request('GET', '/.well-known/openid-configuration');
    $discoveryData = json_decode($discovery['body'], true);
    httpCheck($discovery['status'] === 200 && str_contains($discovery['body'], '/oauth/token') && str_contains($discovery['body'], 'RS256')
        && is_array($discoveryData) && in_array('groups', $discoveryData['claims_supported'] ?? [], true)
        && in_array('permissions', $discoveryData['claims_supported'] ?? [], true)
        && in_array('authorization_revision', $discoveryData['claims_supported'] ?? [], true),
        'OpenID discovery must publish supported endpoints and every available member claim.');
    $jwksResponse = $request('GET', '/oauth/jwks');
    httpCheck($jwksResponse['status'] === 200 && str_contains($jwksResponse['body'], '"kty":"RSA"'), 'The JWKS endpoint must publish an RSA public key.');

    $oauthPreflight = $request('OPTIONS', '/oauth/token', '', '', true, [
        'Origin: https://oauth.example.test',
        'Access-Control-Request-Method: POST',
        'Access-Control-Request-Headers: authorization,content-type',
    ]);
    httpCheck($oauthPreflight['status'] === 204
        && in_array('Access-Control-Allow-Origin: https://oauth.example.test', $oauthPreflight['headers'], true),
        'OAuth CORS preflight must allow only the registered application origin.');
    $untrustedPreflight = $request('OPTIONS', '/oauth/token', '', '', true, [
        'Origin: https://attacker.example.test',
        'Access-Control-Request-Method: POST',
        'Access-Control-Request-Headers: authorization,content-type',
    ]);
    httpCheck($untrustedPreflight['status'] === 403, 'OAuth CORS preflight must reject unregistered origins.');

    $oauthVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=') . 'A';
    $oauthChallenge = rtrim(strtr(base64_encode(hash('sha256', $oauthVerifier, true)), '+/', '-_'), '=');
    $oauthState = bin2hex(random_bytes(16));
    $oauthNonce = bin2hex(random_bytes(16));
    $authorizeQuery = http_build_query([
        'response_type' => 'code',
        'client_id' => $oauthClientId,
        'redirect_uri' => $oauthRedirect,
        'scope' => implode(' ', $oauthScopes),
        'state' => $oauthState,
        'nonce' => $oauthNonce,
        'code_challenge' => $oauthChallenge,
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
    $authorizePage = $request('GET', '/oauth/authorize?' . $authorizeQuery, '', $authCookie);
    httpCheck($authorizePage['status'] === 200
        && (str_contains($authorizePage['body'], 'Authorize application') || str_contains($authorizePage['body'], 'Cấp quyền cho ứng dụng')),
        'A signed-in member must see an OAuth consent screen (status ' . $authorizePage['status'] . ').');
    $oauthCsrf = '';
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $authorizePage['body'], $match)) {
        $oauthCsrf = $match[1];
    }
    httpCheck($oauthCsrf !== '', 'OAuth consent must be protected by CSRF.');
    $oauthConsent = $request('POST', '/oauth/authorize', http_build_query([
        '_csrf' => $oauthCsrf,
        'decision' => 'approve',
    ]), $authCookie);
    httpCheck($oauthConsent['status'] === 303, 'A member must be able to approve OAuth consent.');
    $authorizationLocation = '';
    foreach ($oauthConsent['headers'] as $header) {
        if (stripos($header, 'Location: ' . $oauthRedirect . '?') === 0) {
            $authorizationLocation = substr($header, strlen('Location: '));
        }
    }
    $authorizationQuery = [];
    parse_str((string)parse_url($authorizationLocation, PHP_URL_QUERY), $authorizationQuery);
    $authorizationCode = (string)($authorizationQuery['code'] ?? '');
    httpCheck($authorizationCode !== '' && ($authorizationQuery['state'] ?? '') === $oauthState, 'The authorization response must return a one-time code and the exact state.');

    $badPkce = $request('POST', '/oauth/token', http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $oauthClientId,
        'code' => $authorizationCode,
        'redirect_uri' => $oauthRedirect,
        'code_verifier' => str_repeat('B', 43),
    ]));
    httpCheck($badPkce['status'] === 400 && str_contains($badPkce['body'], 'invalid_grant'), 'The token endpoint must reject an incorrect PKCE verifier without consuming the code.');
    $tokenResponse = $request('POST', '/oauth/token', http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $oauthClientId,
        'code' => $authorizationCode,
        'redirect_uri' => $oauthRedirect,
        'code_verifier' => $oauthVerifier,
    ]));
    $oauthTokens = json_decode($tokenResponse['body'], true);
    httpCheck($tokenResponse['status'] === 200 && is_array($oauthTokens)
        && !empty($oauthTokens['access_token']) && !empty($oauthTokens['refresh_token']) && !empty($oauthTokens['id_token']),
        'A valid PKCE authorization code must exchange for access, refresh and OIDC ID tokens.');
    $idTokenParts = explode('.', (string)$oauthTokens['id_token']);
    $idTokenClaims = count($idTokenParts) === 3
        ? json_decode(base64_decode(strtr($idTokenParts[1], '-_', '+/')), true)
        : null;
    httpCheck(is_array($idTokenClaims) && ($idTokenClaims['sub'] ?? '') === (string)$testUserId
        && ($idTokenClaims['aud'] ?? '') === $oauthClientId && ($idTokenClaims['nonce'] ?? '') === $oauthNonce
        && (int)($idTokenClaims['auth_time'] ?? 0) > 0,
        'The OIDC ID token must bind the subject, client, nonce and original authentication time.');
    $userinfo = $request('GET', '/oauth/userinfo', '', '', true, ['Authorization: Bearer ' . $oauthTokens['access_token']]);
    $userinfoData = json_decode($userinfo['body'], true);
    httpCheck($userinfo['status'] === 200 && is_array($userinfoData)
        && ($userinfoData['sub'] ?? '') === (string)$testUserId
        && ($userinfoData['email'] ?? '') === $testEmail
        && in_array($groupKey, $userinfoData['groups'] ?? [], true)
        && array_key_exists('authorization_revision', $userinfoData),
        'OIDC userinfo must return only scoped member claims and the authorization revision.');

    $apiUnauthenticated = $request('GET', '/api/v1/me');
    httpCheck($apiUnauthenticated['status'] === 401, 'The central profile API must reject requests without a bearer token.');
    $apiMe = $request('GET', '/api/v1/me', '', '', true, ['Authorization: Bearer ' . $oauthTokens['access_token']]);
    $apiMeData = json_decode($apiMe['body'], true);
    httpCheck($apiMe['status'] === 200 && is_array($apiMeData)
        && ($apiMeData['ok'] ?? false) === true
        && ($apiMeData['data']['id'] ?? '') === (string)$testUserId
        && ($apiMeData['data']['email'] ?? '') === $testEmail
        && isset($apiMeData['data']['authorization_revision'], $apiMeData['meta']['request_id']),
        'The versioned profile API must return scoped identity data and a revision envelope.');
    $apiGroups = $request('GET', '/api/v1/me/groups', '', '', true, ['Authorization: Bearer ' . $oauthTokens['access_token']]);
    $apiGroupsData = json_decode($apiGroups['body'], true);
    httpCheck($apiGroups['status'] === 200 && is_array($apiGroupsData)
        && in_array($groupKey, array_column($apiGroupsData['data']['groups'] ?? [], 'group_key'), true),
        'The central groups API must return active member group keys.');
    $apiPermissions = $request('GET', '/api/v1/me/permissions', '', '', true, ['Authorization: Bearer ' . $oauthTokens['access_token']]);
    $apiPermissionsData = json_decode($apiPermissions['body'], true);
    httpCheck($apiPermissions['status'] === 200 && is_array($apiPermissionsData)
        && is_array($apiPermissionsData['data']['roles'] ?? null)
        && is_array($apiPermissionsData['data']['permissions'] ?? null)
        && isset($apiPermissionsData['data']['authorization_revision']),
        'The central permissions API must return role and effective permission keys.');
    $apiMemberships = $request('GET', '/api/v1/me/memberships', '', '', true, ['Authorization: Bearer ' . $oauthTokens['access_token']]);
    $apiMembershipsData = json_decode($apiMemberships['body'], true);
    httpCheck($apiMemberships['status'] === 200 && is_array($apiMembershipsData)
        && in_array($groupKey, array_column($apiMembershipsData['data']['memberships'] ?? [], 'group_key'), true),
        'The central memberships API must return the member group assignments.');

    $plansPage = $request('GET', '/admin/plans', '', $authCookie);
    httpCheck($plansPage['status'] === 200, 'A Super Admin must access subscription plan administration.');
    $plansCsrf = '';
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $plansPage['body'], $match)) {
        $plansCsrf = $match[1];
    }
    httpCheck($plansCsrf !== '', 'Subscription plan administration must expose CSRF protection.');
    $planKey = 'http-pro-' . bin2hex(random_bytes(4));
    $planCreate = $request('POST', '/admin/plans/save', http_build_query([
        '_csrf' => $plansCsrf,
        'id' => '0',
        'plan_key' => $planKey,
        'name' => 'HTTP Pro',
        'description' => 'HTTP plan administration test.',
        'price_minor' => '1200',
        'currency' => 'USD',
        'billing_interval' => 'month',
        'features_json' => '{"forum_access":true,"custom_domain":true}',
        'quotas_json' => '{"subdomains":10,"storage_mb":1024}',
        'status' => 'active',
        'sort_order' => '10',
    ]), $authCookie);
    httpCheck($planCreate['status'] === 303, 'A Super Admin must create a subscription plan through the browser UI.');
    $planLookup = $pdo->prepare('SELECT id, features, quotas FROM subscription_plans WHERE plan_key = :key');
    $planLookup->execute(['key' => $planKey]);
    $planRow = $planLookup->fetch();
    httpCheck($planRow && json_decode($planRow['features'], true)['forum_access'] === true
        && json_decode($planRow['quotas'], true)['subdomains'] === 10,
        'Subscription plan feature flags and quotas must persist as JSON objects.');
    $planId = (int)$planRow['id'];
    $paymentEventId = 'evt-' . bin2hex(random_bytes(12));
    $paymentEventIds[] = $paymentEventId;
    $providerSubscriptionId = 'sub-' . bin2hex(random_bytes(8));
    $paymentEventBody = json_encode([
        'id' => $paymentEventId,
        'type' => 'subscription.activated',
        'data' => [
            'user_id' => $testUserId,
            'plan_key' => $planKey,
            'provider_subscription_id' => $providerSubscriptionId,
            'provider_customer_id' => 'cus-http-test',
            'status' => 'active',
            'current_period_start' => gmdate('c'),
            'current_period_end' => gmdate('c', time() + 30 * 86400),
            'cancel_at_period_end' => false,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $paymentTimestamp = (string)time();
    $paymentSignature = hash_hmac('sha256', $paymentTimestamp . '.' . $paymentEventBody, $paymentTestSecret);
    $paymentHeaders = [
        'Content-Type: application/json',
        'X-C0M-Provider: generic',
        'X-C0M-Signature: t=' . $paymentTimestamp . ',v1=' . $paymentSignature,
    ];
    $paymentWebhook = $request('POST', '/api/v1/webhooks/payments', $paymentEventBody, '', true, $paymentHeaders);
    httpCheck($paymentWebhook['status'] === 200 && str_contains($paymentWebhook['body'], '"ok":true'), 'A correctly signed subscription webhook must be accepted.');
    $subscriptionLookup = $pdo->prepare('SELECT id, status FROM subscriptions WHERE user_id = :user AND provider = :provider');
    $subscriptionLookup->execute(['user' => $testUserId, 'provider' => 'generic']);
    $subscriptionRow = $subscriptionLookup->fetch();
    httpCheck($subscriptionRow && $subscriptionRow['status'] === 'active', 'A verified subscription webhook must activate the member subscription.');
    $entitlementRows = $pdo->prepare("SELECT entitlement_key, entitlement_value FROM user_entitlements WHERE user_id = :user AND status = 'active'");
    $entitlementRows->execute(['user' => $testUserId]);
    $entitlementMap = [];
    foreach ($entitlementRows->fetchAll() as $entitlementRow) {
        $entitlementMap[$entitlementRow['entitlement_key']] = json_decode($entitlementRow['entitlement_value'], true);
    }
    httpCheck(($entitlementMap['feature.forum_access'] ?? false) === true
        && ($entitlementMap['quota.subdomains'] ?? null) === 10
        && ($entitlementMap['quota.storage_mb'] ?? null) === 1024,
        'Subscription plan features and quotas must become member entitlements.');
    $entitlementApi = $request('GET', '/api/v1/me/entitlements', '', '', true, ['Authorization: Bearer ' . $oauthTokens['access_token']]);
    $entitlementApiData = json_decode($entitlementApi['body'], true);
    httpCheck($entitlementApi['status'] === 200 && is_array($entitlementApiData)
        && ($entitlementApiData['data']['entitlements']['feature.forum_access']['value'] ?? false) === true
        && ($entitlementApiData['data']['entitlements']['quota.subdomains']['value'] ?? null) === 10,
        'The scoped entitlement API must return the current member features and quotas.');

    $revisionBeforePlanEdit = (int)$pdo->query('SELECT revision FROM user_authorization_revisions WHERE user_id = ' . (int)$testUserId)->fetchColumn();
    $editPlanPage = $request('GET', '/admin/plans?edit=' . $planId, '', $authCookie);
    $editPlanCsrf = '';
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $editPlanPage['body'], $match)) {
        $editPlanCsrf = $match[1];
    }
    httpCheck($editPlanPage['status'] === 200 && $editPlanCsrf !== '', 'A Super Admin must be able to edit an existing plan.');
    $planUpdate = $request('POST', '/admin/plans/save', http_build_query([
        '_csrf' => $editPlanCsrf,
        'id' => (string)$planId,
        'plan_key' => $planKey,
        'name' => 'HTTP Pro Updated',
        'description' => 'Updated HTTP plan test.',
        'price_minor' => '1500',
        'currency' => 'USD',
        'billing_interval' => 'month',
        'features_json' => '{"forum_access":true,"custom_domain":true,"ai_member":true}',
        'quotas_json' => '{"subdomains":15,"storage_mb":2048}',
        'status' => 'active',
        'sort_order' => '10',
    ]), $authCookie);
    httpCheck($planUpdate['status'] === 303, 'A Super Admin must update a subscription plan.');
    $entitlementRows->execute(['user' => $testUserId]);
    $updatedEntitlementMap = [];
    foreach ($entitlementRows->fetchAll() as $entitlementRow) {
        $updatedEntitlementMap[$entitlementRow['entitlement_key']] = json_decode($entitlementRow['entitlement_value'], true);
    }
    $revisionAfterPlanEdit = (int)$pdo->query('SELECT revision FROM user_authorization_revisions WHERE user_id = ' . (int)$testUserId)->fetchColumn();
    httpCheck(($updatedEntitlementMap['feature.ai_member'] ?? false) === true
        && ($updatedEntitlementMap['quota.subdomains'] ?? null) === 15
        && ($updatedEntitlementMap['quota.storage_mb'] ?? null) === 2048
        && $revisionAfterPlanEdit > $revisionBeforePlanEdit,
        'Editing a plan must synchronize active member entitlements and advance authorization revision.');
    $archiveAttempt = $request('POST', '/admin/plans/save', http_build_query([
        '_csrf' => $editPlanCsrf,
        'id' => (string)$planId,
        'plan_key' => $planKey,
        'name' => 'HTTP Pro Updated',
        'description' => 'Updated HTTP plan test.',
        'price_minor' => '1500',
        'currency' => 'USD',
        'billing_interval' => 'month',
        'features_json' => '{"forum_access":true,"custom_domain":true,"ai_member":true}',
        'quotas_json' => '{"subdomains":15,"storage_mb":2048}',
        'status' => 'archived',
        'sort_order' => '10',
    ]), $authCookie);
    httpCheck($archiveAttempt['status'] === 422, 'A plan with current subscriptions must not be archived.');
    $planStatusCheck = $pdo->prepare('SELECT status FROM subscription_plans WHERE id = :id');
    $planStatusCheck->execute(['id' => $planId]);
    httpCheck($planStatusCheck->fetchColumn() === 'active', 'A rejected plan archive must leave the active plan unchanged.');

    $duplicatePaymentWebhook = $request('POST', '/api/v1/webhooks/payments', $paymentEventBody, '', true, $paymentHeaders);
    httpCheck($duplicatePaymentWebhook['status'] === 200 && str_contains($duplicatePaymentWebhook['body'], '"duplicate":true'),
        'A repeated provider event must be idempotently acknowledged without reapplying entitlements.');
    $outboxCount = $pdo->prepare('SELECT COUNT(*) FROM event_outbox WHERE event_key = :key');
    $outboxCount->execute(['key' => hash('sha256', 'generic:' . $paymentEventId)]);
    httpCheck((int)$outboxCount->fetchColumn() === 1, 'A processed payment webhook must create exactly one transactional outbox event.');

    $scheduledEventId = 'evt-' . bin2hex(random_bytes(12));
    $paymentEventIds[] = $scheduledEventId;
    $scheduledBody = json_encode([
        'id' => $scheduledEventId,
        'type' => 'subscription.canceled',
        'data' => [
            'user_id' => $testUserId,
            'plan_key' => $planKey,
            'provider_subscription_id' => $providerSubscriptionId,
            'status' => 'canceled',
            'current_period_start' => gmdate('c', time() - 86400),
            'current_period_end' => gmdate('c', time() + 20 * 86400),
            'cancel_at_period_end' => true,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $scheduledTimestamp = (string)time();
    $scheduledSignature = hash_hmac('sha256', $scheduledTimestamp . '.' . $scheduledBody, $paymentTestSecret);
    $scheduledHeaders = [
        'Content-Type: application/json',
        'X-C0M-Provider: generic',
        'X-C0M-Signature: t=' . $scheduledTimestamp . ',v1=' . $scheduledSignature,
    ];
    $scheduledCancellation = $request('POST', '/api/v1/webhooks/payments', $scheduledBody, '', true, $scheduledHeaders);
    httpCheck($scheduledCancellation['status'] === 200, 'A scheduled subscription cancellation must be accepted.');
    $activeEntitlementCount = $pdo->prepare("SELECT COUNT(*) FROM user_entitlements WHERE user_id = :user AND status = 'active' AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())");
    $activeEntitlementCount->execute(['user' => $testUserId]);
    httpCheck((int)$activeEntitlementCount->fetchColumn() >= 4, 'Entitlements must remain valid until the scheduled period end.');

    $immediateEventId = 'evt-' . bin2hex(random_bytes(12));
    $paymentEventIds[] = $immediateEventId;
    $immediateBody = json_encode([
        'id' => $immediateEventId,
        'type' => 'subscription.canceled',
        'data' => [
            'user_id' => $testUserId,
            'plan_key' => $planKey,
            'provider_subscription_id' => $providerSubscriptionId,
            'status' => 'canceled',
            'current_period_start' => gmdate('c', time() - 86400),
            'current_period_end' => gmdate('c', time() - 1),
            'cancel_at_period_end' => false,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $immediateTimestamp = (string)time();
    $immediateSignature = hash_hmac('sha256', $immediateTimestamp . '.' . $immediateBody, $paymentTestSecret);
    $immediateCancellation = $request('POST', '/api/v1/webhooks/payments', $immediateBody, '', true, [
        'Content-Type: application/json',
        'X-C0M-Provider: generic',
        'X-C0M-Signature: t=' . $immediateTimestamp . ',v1=' . $immediateSignature,
    ]);
    httpCheck($immediateCancellation['status'] === 200, 'An immediate subscription cancellation must be accepted.');
    $activeEntitlementCount->execute(['user' => $testUserId]);
    httpCheck((int)$activeEntitlementCount->fetchColumn() === 0, 'Immediate cancellation must revoke the member entitlements.');
    $badPaymentWebhook = $request('POST', '/api/v1/webhooks/payments', $paymentEventBody, '', true, [
        'Content-Type: application/json',
        'X-C0M-Provider: generic',
        'X-C0M-Signature: t=' . $paymentTimestamp . ',v1=' . str_repeat('0', 64),
    ]);
    httpCheck($badPaymentWebhook['status'] === 401, 'An invalid payment webhook signature must be rejected.');

    $refreshResponse = $request('POST', '/oauth/token', http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $oauthClientId,
        'refresh_token' => $oauthTokens['refresh_token'],
    ]));
    $rotatedTokens = json_decode($refreshResponse['body'], true);
    httpCheck($refreshResponse['status'] === 200 && is_array($rotatedTokens)
        && !empty($rotatedTokens['access_token']) && !empty($rotatedTokens['refresh_token']) && !empty($rotatedTokens['id_token']),
        'Refresh-token rotation must issue a new token pair and OIDC ID token.');
    $refreshReplay = $request('POST', '/oauth/token', http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $oauthClientId,
        'refresh_token' => $oauthTokens['refresh_token'],
    ]));
    httpCheck($refreshReplay['status'] === 400, 'Reusing a rotated refresh token must be rejected.');
    $revokedUserinfo = $request('GET', '/oauth/userinfo', '', '', true, ['Authorization: Bearer ' . $rotatedTokens['access_token']]);
    httpCheck($revokedUserinfo['status'] === 401, 'Refresh-token reuse must revoke the compromised token family.');
    $unknownRevoke = $request('POST', '/oauth/revoke', http_build_query([
        'client_id' => $oauthClientId,
        'token' => 'unknown-token',
        'token_type_hint' => 'access_token',
    ]));
    httpCheck($unknownRevoke['status'] === 200, 'RFC 7009 revocation must return success for unknown tokens.');

    // Verify confidential-client secret disclosure, one-time rotation and lifecycle controls.
    $pdo->prepare('DELETE FROM oauth_clients WHERE application_id = :application_id')
        ->execute(['application_id' => $registryAppId]);
    $oauthAdminPage = $request('GET', '/admin/oauth-clients', '', $authCookie);
    $oauthAdminCsrf = '';
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $oauthAdminPage['body'], $match)) {
        $oauthAdminCsrf = $match[1];
    }
    $confidentialCreate = $request('POST', '/admin/oauth-clients/create', http_build_query([
        '_csrf' => $oauthAdminCsrf,
        'application_id' => (string)$registryAppId,
        'client_type' => 'confidential',
        'redirect_uris' => $oauthRedirect,
        'scopes' => ['openid', 'email'],
    ]), $authCookie);
    httpCheck($confidentialCreate['status'] === 303, 'A Super Admin must create a confidential OAuth client.');
    $confidentialRowStmt = $pdo->prepare('SELECT id, client_id, client_secret_hash, status FROM oauth_clients WHERE application_id = :application_id');
    $confidentialRowStmt->execute(['application_id' => $registryAppId]);
    $confidentialRow = $confidentialRowStmt->fetch();
    $initialSecretHash = (string)$confidentialRow['client_secret_hash'];
    $secretFlash = $request('GET', '/admin/oauth-clients', '', $authCookie);
    $initialSecret = '';
    if (preg_match('/<label>Client secret<\\/label>\\s*<div class="code-value">([a-f0-9]{96})<\\/div>/', $secretFlash['body'], $match)) {
        $initialSecret = $match[1];
    }
    httpCheck($initialSecret !== '' && hash('sha256', $initialSecret) === $initialSecretHash, 'A confidential client secret must be shown once and stored only as a hash.');
    $rotateSecret = $request('POST', '/admin/oauth-clients/rotate', http_build_query([
        '_csrf' => $oauthAdminCsrf,
        'client_record_id' => (string)$confidentialRow['id'],
    ]), $authCookie);
    httpCheck($rotateSecret['status'] === 303, 'A Super Admin must be able to rotate a confidential client secret.');
    $confidentialRowStmt->execute(['application_id' => $registryAppId]);
    $rotatedClientRow = $confidentialRowStmt->fetch();
    $rotatedSecretFlash = $request('GET', '/admin/oauth-clients', '', $authCookie);
    $rotatedSecret = '';
    if (preg_match('/<label>Client secret<\\/label>\\s*<div class="code-value">([a-f0-9]{96})<\\/div>/', $rotatedSecretFlash['body'], $match)) {
        $rotatedSecret = $match[1];
    }
    httpCheck($rotatedSecret !== '' && $rotatedSecret !== $initialSecret
        && hash('sha256', $rotatedSecret) === $rotatedClientRow['client_secret_hash'],
        'Secret rotation must replace the hash and disclose the new secret only once.');
    $afterSecretFlash = $request('GET', '/admin/oauth-clients', '', $authCookie);
    httpCheck(!str_contains($afterSecretFlash['body'], $rotatedSecret), 'A client secret must not be shown again after its one-time flash.');
    $disableOauthClient = $request('POST', '/admin/oauth-clients/status', http_build_query([
        '_csrf' => $oauthAdminCsrf,
        'client_record_id' => (string)$confidentialRow['id'],
        'status' => 'disabled',
    ]), $authCookie);
    httpCheck($disableOauthClient['status'] === 303, 'A Super Admin must be able to disable an OAuth client.');
    $confidentialRowStmt->execute(['application_id' => $registryAppId]);
    httpCheck($confidentialRowStmt->fetch()['status'] === 'disabled', 'Disabling an OAuth client must persist its inactive state.');
    $enableOauthClient = $request('POST', '/admin/oauth-clients/status', http_build_query([
        '_csrf' => $oauthAdminCsrf,
        'client_record_id' => (string)$confidentialRow['id'],
        'status' => 'active',
    ]), $authCookie);
    httpCheck($enableOauthClient['status'] === 303, 'A Super Admin must be able to re-enable an OAuth client.');

    $memberDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck($memberDashboard['status'] === 200 && str_contains($memberDashboard['body'], $registryName) && str_contains($memberDashboard['body'], 'https://registry.example.test/app'), 'Active member applications must appear as safe links on the dashboard.');
    $invalidRegistryUrl = $request('POST', '/admin/applications/save', http_build_query([
        '_csrf' => $registryCsrf,
        'id' => (string)$registryAppId,
    ] + array_merge($registryFields, ['url' => 'javascript:alert(1)'])), $authCookie);
    httpCheck($invalidRegistryUrl['status'] === 422, 'Non-HTTPS application URLs must be rejected.');

    $duplicateRegistryKey = $request('POST', '/admin/applications/save', http_build_query([
        '_csrf' => $registryCsrf,
    ] + array_merge($registryFields, ['name' => 'Duplicate application'])), $authCookie);
    httpCheck($duplicateRegistryKey['status'] === 409, 'Duplicate application keys must return a conflict without replacing the existing entry.');

    $registryFields['id'] = (string)$registryAppId;
    $registryFields['name'] = 'Updated Registry App';
    $registryFields['description'] = 'Updated through the HTTP registry test.';
    $registryFields['access_level'] = 'restricted';
    $registryFields['group_ids'] = [(string)$registryGroupId];
    unset($registryFields['featured']);
    $userRevision->execute(['id' => $testUserId]);
    $revisionBeforeGrant = (int)$userRevision->fetchColumn();
    $registryUpdate = $request('POST', '/admin/applications/save', http_build_query(['_csrf' => $registryCsrf] + $registryFields), $authCookie);
    httpCheck($registryUpdate['status'] === 303, 'A Super Admin must update an existing application.');
    $registryLookup->execute(['app_key' => $registryKey]);
    $registryRow = $registryLookup->fetch();
    httpCheck($registryRow && $registryRow['name'] === 'Updated Registry App' && $registryRow['access_level'] === 'restricted' && (int)$registryRow['featured'] === 0, 'Application updates must persist and omitted featured state must clear.');
    $restrictedDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck($registryUpdate['status'] === 303 && str_contains($restrictedDashboard['body'], 'Updated Registry App'), 'A restricted application must appear for a member with an explicit matching group grant.');
    $userRevision->execute(['id' => $testUserId]);
    $revisionAfterGrant = (int)$userRevision->fetchColumn();
    httpCheck($revisionAfterGrant === $revisionBeforeGrant + 1, 'Changing application group grants must advance affected member revisions.');
    $disableGroup = $request('POST', '/admin/groups', http_build_query([
        '_csrf' => $registryCsrf,
        'action' => 'update',
        'group_id' => $registryGroupId,
        'name' => 'HTTP Restricted Group',
        'description' => 'Isolated HTTP group fixture.',
        'status' => 'disabled',
    ]), $authCookie);
    httpCheck($disableGroup['status'] === 303, 'A Super Admin must be able to disable a member group.');
    $userRevision->execute(['id' => $testUserId]);
    $revisionAfterDisable = (int)$userRevision->fetchColumn();
    httpCheck($revisionAfterDisable === $revisionAfterGrant + 1, 'Disabling a group must advance member authorization revisions.');
    $disabledGroupDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck(!str_contains($disabledGroupDashboard['body'], 'Updated Registry App'), 'A disabled group must no longer grant restricted application visibility.');
    $enableGroup = $request('POST', '/admin/groups', http_build_query([
        '_csrf' => $registryCsrf,
        'action' => 'update',
        'group_id' => $registryGroupId,
        'name' => 'HTTP Restricted Group',
        'description' => 'Isolated HTTP group fixture.',
        'status' => 'active',
    ]), $authCookie);
    httpCheck($enableGroup['status'] === 303, 'A Super Admin must be able to re-enable a member group.');
    $userRevision->execute(['id' => $testUserId]);
    $revisionAfterEnable = (int)$userRevision->fetchColumn();
    httpCheck($revisionAfterEnable === $revisionAfterDisable + 1, 'Re-enabling a group must advance member authorization revisions.');
    $reenabledGroupDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck(str_contains($reenabledGroupDashboard['body'], 'Updated Registry App'), 'Re-enabling a granted group must restore application visibility.');
    $revisionBeforeGroupRemoval = $revisionAfterEnable;
    $removeGroup = $request('POST', '/admin/groups', http_build_query([
        '_csrf' => $registryCsrf,
        'action' => 'members',
        'group_id' => $registryGroupId,
    ]), $authCookie);
    httpCheck($removeGroup['status'] === 303, 'A Super Admin must be able to revoke group membership.');
    $userRevision->execute(['id' => $testUserId]);
    httpCheck((int)$userRevision->fetchColumn() === $revisionBeforeGroupRemoval + 1, 'Group membership revocation must advance the authorization revision.');
    $restrictedDashboard = $request('GET', '/dashboard', '', $authCookie);
    httpCheck(!str_contains($restrictedDashboard['body'], 'Updated Registry App'), 'A restricted application must disappear after its group grant is revoked.');
    $registryAudits = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE subject_type = 'application' AND subject_id = :id AND action IN ('application.created', 'application.updated')");
    $registryAudits->execute(['id' => (string)$registryAppId]);
    httpCheck((int)$registryAudits->fetchColumn() === 2, 'Registry create and update must each produce an audit event.');

    $logoutCsrf = '';
    if (preg_match('/name="_csrf"\\s+value="([a-f0-9]{64})"/', $englishDashboard['body'], $match)) {
        $logoutCsrf = $match[1];
    }
    httpCheck($logoutCsrf !== '', 'The localized dashboard must retain its CSRF token.');
    $logout = $request('POST', '/logout', http_build_query(['_csrf' => $logoutCsrf]), $authCookie);
    httpCheck($logout['status'] === 303, 'Logout must complete through the HTTP router.');
    $session = $pdo->prepare('SELECT revoked_at FROM sessions WHERE user_id = :id ORDER BY last_activity DESC LIMIT 1');
    $session->execute(['id' => $testUserId]);
    httpCheck($session->fetchColumn() !== null, 'HTTP logout must revoke the persisted server-side session.');

    $afterLogout = $request('GET', '/dashboard', '', $authCookie);
    httpCheck($afterLogout['status'] === 303, 'A logged-out session must no longer access the dashboard.');

    $missing = $request('GET', '/route-that-does-not-exist');
    httpCheck($missing['status'] === 404, 'Unknown HTTP routes must return 404.');

    echo "HTTP router integration tests passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    if ($serverStdoutPath !== '') {
        @unlink($serverStdoutPath);
    }
    if ($serverStderrPath !== '') {
        @unlink($serverStderrPath);
    }
    @unlink($dbConfigPath);
    @unlink($appConfigPath);
    @unlink($mailConfigPath);
    @unlink($paymentConfigPath);
    @unlink($mailLogPath);
    if ($pdo instanceof PDO) {
        if (is_string($flowEmail)) {
            $pdo->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $flowEmail]);
        }
        if (is_int($testUserId)) {
            if (is_int($registryAppId)) {
                $pdo->prepare("DELETE FROM audit_logs WHERE subject_type = 'application' AND subject_id = :id")
                    ->execute(['id' => (string)$registryAppId]);
                $pdo->prepare('DELETE FROM applications WHERE id = :id')->execute(['id' => $registryAppId]);
            }
            if (is_int($registryGroupId)) {
                $pdo->prepare("DELETE FROM audit_logs WHERE subject_type = 'group' AND subject_id = :id")
                    ->execute(['id' => (string)$registryGroupId]);
                $pdo->prepare('DELETE FROM member_groups WHERE id = :id')->execute(['id' => $registryGroupId]);
            }
            $pdo->prepare('DELETE FROM subscriptions WHERE user_id = :id')->execute(['id' => $testUserId]);
            $pdo->prepare('DELETE FROM payment_transactions WHERE user_id = :id')->execute(['id' => $testUserId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $testUserId]);
        }
        foreach ($paymentEventIds as $eventId) {
            $pdo->prepare('DELETE FROM payment_webhook_events WHERE provider = :provider AND provider_event_id = :event')
                ->execute(['provider' => 'generic', 'event' => $eventId]);
            $pdo->prepare('DELETE FROM event_outbox WHERE event_key = :key')
                ->execute(['key' => hash('sha256', 'generic:' . $eventId)]);
        }
        if (is_string($planKey)) {
            $pdo->prepare('DELETE FROM subscription_plans WHERE plan_key = :key')->execute(['key' => $planKey]);
        }
    }
}
