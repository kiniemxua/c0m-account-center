<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

function installerCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'c0m_test';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD') ?: '';
$root = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
    $dbUser,
    $dbPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$configPath = BASE_PATH . '/config/database.php';
$appConfigPath = BASE_PATH . '/config/app.php';
$lockPath = BASE_PATH . '/storage/install.lock';
installerCheck(!is_file($configPath) && !is_file($appConfigPath) && !is_file($lockPath), 'Installer test requires a clean configuration and install lock.');
$limitedUser = 'c0m_installer_limited';
$limitedPassword = 'InstallerLimitedPassword-OnlyForCI';
$process = null;
$pipes = [];

try {
    installerCheck(file_put_contents($appConfigPath, "<?php return ['trusted_proxies' => ['127.0.0.1']];", LOCK_EX) !== false, 'Could not create trusted-proxy test configuration.');
    $root->exec("CREATE USER IF NOT EXISTS '{$limitedUser}'@'%' IDENTIFIED BY '{$limitedPassword}'");
    $root->exec("GRANT SELECT, INSERT, UPDATE, CREATE, INDEX, REFERENCES ON `{$dbName}`.* TO '{$limitedUser}'@'%'");

    $portSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    installerCheck(is_resource($portSocket), 'Could not reserve an installer HTTP port.');
    $address = stream_socket_get_name($portSocket, false);
    fclose($portSocket);
    installerCheck(is_string($address) && str_contains($address, ':'), 'Could not determine installer HTTP port.');
    $port = (int)substr(strrchr($address, ':'), 1);

    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', BASE_PATH . '/public'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    installerCheck(is_resource($process), 'Could not start installer HTTP server.');
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $base = 'http://127.0.0.1:' . $port;
    $request = static function (string $method, string $body = '', string $cookie = '', bool $trustedHttps = true, string $path = '/installer/') use ($base): array {
        $headers = ['Connection: close'];
        if ($trustedHttps) {
            $headers[] = 'X-Forwarded-Proto: https';
        }
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 8,
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
    $cookieFrom = static function (array $response): string {
        foreach ($response['headers'] as $header) {
            if (stripos($header, 'Set-Cookie: c0m_install=') === 0) {
                return explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
            }
        }
        return '';
    };
    $csrfFrom = static function (array $response): string {
        return preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $response['body'], $m) ? $m[1] : '';
    };
    $post = static function (string $csrf, array $fields, string $cookie) use ($request): array {
        return $request('POST', http_build_query(['_csrf' => $csrf] + $fields), $cookie);
    };

    $englishInstaller = $request('GET', '', '', true, '/installer/?lang=en');
    installerCheck($englishInstaller['status'] === 200 && str_contains($englishInstaller['body'], '<html lang="en">'), 'Installer must render in English when the locale is explicitly selected.');
    installerCheck(str_contains($englishInstaller['body'], 'Set up your Account Center') && str_contains($englishInstaller['body'], 'Install Account Center'), 'English installer labels must use the English catalog.');
    $englishLocaleCookie = '';
    foreach ($englishInstaller['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_lang=en') === 0) {
            $englishLocaleCookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
        }
    }
    installerCheck($englishLocaleCookie === 'c0m_lang=en', 'Installer language selection must persist in the locale cookie.');

    $invalidInstallerLocale = $request('GET', '', '', true, '/installer/?lang=../config/database');
    installerCheck($invalidInstallerLocale['status'] === 200 && str_contains($invalidInstallerLocale['body'], '<html lang="vi">'), 'An unsupported installer locale must fall back to Vietnamese before any catalog file is loaded.');
    $invalidInstallerCookie = $request('GET', '', 'c0m_lang=../config/database');
    installerCheck($invalidInstallerCookie['status'] === 200 && str_contains($invalidInstallerCookie['body'], '<html lang="vi">'), 'An unsupported locale cookie must fall back to Vietnamese safely.');

    $insecureRequest = $request('GET', '', '', false);
    installerCheck($insecureRequest['status'] === 400 && str_contains($insecureRequest['body'], 'HTTPS is required'), 'Installer must reject HTTP when HTTPS is not proven.');

    $deadline = microtime(true) + 8;
    do {
        $first = $request('GET');
        if ($first['status'] === 200) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    installerCheck($first['status'] === 200, 'Installer must render over real HTTP (status ' . $first['status'] . ').');
    $cookie = $cookieFrom($first);
    $csrf = $csrfFrom($first);
    installerCheck($cookie !== '' && $csrf !== '', 'Installer must issue a session cookie and CSRF token.');
    $installCookieHeader = '';
    foreach ($first['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_install=') === 0) {
            $installCookieHeader = $header;
            break;
        }
    }
    installerCheck(stripos($installCookieHeader, 'httponly') !== false, 'Installer session cookies must be HttpOnly.');
    installerCheck(stripos($installCookieHeader, 'samesite=Strict') !== false, 'Installer session cookies must use SameSite=Strict.');
    installerCheck(stripos($installCookieHeader, 'secure') !== false, 'Installer session cookies must use Secure behind the configured test proxy.');

    $badConnection = $post($csrf, [
        'host' => '127.0.0.1', 'port' => '1', 'name' => $dbName, 'user' => $dbUser,
        'password' => 'DO_NOT_LEAK_DATABASE_PASSWORD', 'admin_name' => 'CI Admin',
        'admin_email' => 'installer-ci@example.test', 'admin_password' => 'InstallerPassword-123',
    ], $cookie);
    installerCheck($badConnection['status'] === 200 && str_contains($badConnection['body'], 'Cài đặt thất bại.'), 'Database connection failure must return a generic recoverable error.');
    installerCheck(!str_contains($badConnection['body'], 'DO_NOT_LEAK_DATABASE_PASSWORD'), 'Database credentials must not appear in installer output.');
    installerCheck(!is_file($configPath) && !is_file($lockPath), 'A failed database connection must not leave config or install lock behind.');

    $refreshed = $request('GET', '', $cookie);
    installerCheck($refreshed['status'] === 200 && str_contains($refreshed['body'], 'Cài đặt Trung tâm tài khoản'), 'Refreshing after a failed setup must leave the installer retryable.');
    $csrf = $csrfFrom($refreshed) ?: $csrf;
    $cookie = $cookieFrom($refreshed) ?: $cookie;

    $partial = $post($csrf, [
        'host' => $dbHost, 'port' => (string)$dbPort, 'name' => $dbName, 'user' => $limitedUser,
        'password' => $limitedPassword, 'admin_name' => 'CI Admin',
        'admin_email' => 'installer-ci@example.test', 'admin_password' => 'InstallerPassword-123',
    ], $cookie);
    installerCheck($partial['status'] === 200 && str_contains($partial['body'], 'Cài đặt thất bại.'), 'A migration permission failure must be reported as recoverable.');
    installerCheck(!is_file($configPath) && !is_file($lockPath), 'A partial migration must not mark the application installed.');

    $tables = $root->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $root->quote($dbName))->fetchAll(PDO::FETCH_COLUMN);
    installerCheck(in_array('users', $tables, true) && in_array('auth_tokens', $tables, true), 'The failure fixture must stop after core/auth migrations have created schema objects.');
    $default = $root->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $root->quote($dbName) . " AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'language'")->fetchColumn();
    installerCheck($default !== false && trim((string)$default, "'") !== 'vi', 'The restricted installer account must fail before the final preferences migration.');

    $recovered = $post($csrf, [
        'host' => $dbHost, 'port' => (string)$dbPort, 'name' => $dbName, 'user' => $dbUser,
        'password' => $dbPassword, 'admin_name' => 'CI Admin',
        'admin_email' => 'installer-ci@example.test', 'admin_password' => 'InstallerPassword-123',
    ], $cookie);
    installerCheck($recovered['status'] === 200 && str_contains($recovered['body'], 'Đã khóa cài đặt'), 'A retry with corrected DB permissions must complete installation.');
    installerCheck(is_file($configPath) && is_file($lockPath), 'Successful recovery must atomically persist config and install lock.');

    // Simulate a crash after config persistence but before install-lock creation.
    $recoveredCookie = $cookieFrom($recovered) ?: $cookie;
    @unlink($lockPath);
    $lockRecovery = $request('GET', '', $recoveredCookie);
    installerCheck($lockRecovery['status'] === 200 && str_contains($lockRecovery['body'], 'Đã khóa cài đặt'), 'A saved database config without its lock must recover safely.');
    installerCheck(is_file($lockPath), 'Installer must recreate the missing install lock from the saved DB config.');

    $adminId = $root->query("SELECT id FROM users WHERE email = 'installer-ci@example.test'")->fetchColumn();
    installerCheck($adminId !== false, 'Successful install must create the initial administrator.');
    $adminRole = $root->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.role_key = 'super_admin'");
    $adminRole->execute(['id' => (int)$adminId]);
    installerCheck((int)$adminRole->fetchColumn() === 1, 'Initial administrator must receive the Super Admin role.');

    @unlink($configPath);
    @unlink($appConfigPath);
    @unlink($lockPath);
    installerCheck(file_put_contents($appConfigPath, "<?php return ['trusted_proxies' => ['127.0.0.1']];", LOCK_EX) !== false, 'Could not restore trusted-proxy configuration for the existing-account recovery test.');

    // Existing accounts may only be promoted after proving possession of their password.
    $memberPassword = 'ExistingMemberPassword-456';
    $root->prepare("INSERT INTO users (email, password_hash, display_name, status, email_verified_at) VALUES ('existing-member@example.test', :hash, 'Existing member', 'active', NOW())")
        ->execute(['hash' => password_hash($memberPassword, PASSWORD_DEFAULT)]);
    $memberId = (int)$root->lastInsertId();
    $root->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'member'")->execute(['id' => $memberId]);

    $again = $request('GET');
    $cookie = $cookieFrom($again);
    $csrf = $csrfFrom($again);
    $wrongExistingPassword = $post($csrf, [
        'host' => $dbHost, 'port' => (string)$dbPort, 'name' => $dbName, 'user' => $dbUser,
        'password' => $dbPassword, 'admin_name' => 'Existing member',
        'admin_email' => 'existing-member@example.test', 'admin_password' => 'WrongExistingPassword-999',
    ], $cookie);
    installerCheck(str_contains($wrongExistingPassword['body'], 'Cài đặt thất bại.'), 'Installer must reject an existing account when its password is wrong.');
    installerCheck(!is_file($configPath) && !is_file($lockPath), 'Failed existing-account verification must not complete installation.');
    $memberRole = $root->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.role_key = 'super_admin'");
    $memberRole->execute(['id' => $memberId]);
    installerCheck((int)$memberRole->fetchColumn() === 0, 'A wrong password must never promote an existing member.');

    $rightExistingPassword = $post($csrf, [
        'host' => $dbHost, 'port' => (string)$dbPort, 'name' => $dbName, 'user' => $dbUser,
        'password' => $dbPassword, 'admin_name' => 'Existing member',
        'admin_email' => 'existing-member@example.test', 'admin_password' => $memberPassword,
    ], $cookie);
    installerCheck(str_contains($rightExistingPassword['body'], 'Đã khóa cài đặt'), 'A verified existing account may complete installer recovery.');
    $memberRole->execute(['id' => $memberId]);
    installerCheck((int)$memberRole->fetchColumn() === 1, 'Only a password-verified existing account may be promoted by the installer.');

    echo "Installer recovery integration tests passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
    }
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            fclose($pipes[$index]);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    @unlink($configPath);
    @unlink($appConfigPath);
    @unlink($lockPath);
    try {
        $root->exec("DROP USER IF EXISTS '{$limitedUser}'@'%'");
        $root->exec("DELETE FROM users WHERE email IN ('installer-ci@example.test', 'existing-member@example.test')");
    } catch (Throwable) {
        // Preserve the original test failure; CI database is disposable.
    }
}
