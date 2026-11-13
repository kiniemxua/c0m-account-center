<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

function httpCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dbConfigPath = BASE_PATH . '/config/database.php';
if (is_file($dbConfigPath)) {
    throw new RuntimeException('HTTP test refuses to overwrite an existing database config.');
}
$dbConfig = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'c0m_test',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];
if (!is_dir(BASE_PATH . '/config')) {
    throw new RuntimeException('Config directory is missing.');
}
$configSource = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($dbConfig, true) . ";\n";
if (file_put_contents($dbConfigPath, $configSource, LOCK_EX) === false) {
    throw new RuntimeException('Could not create temporary HTTP test configuration.');
}

$portSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$portSocket) {
    @unlink($dbConfigPath);
    throw new RuntimeException('Could not reserve a local HTTP test port.');
}
$address = stream_socket_get_name($portSocket, false);
$port = (int)substr(strrchr($address, ':'), 1);
fclose($portSocket);

$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', BASE_PATH . '/public', BASE_PATH . '/public/index.php'];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    @unlink($dbConfigPath);
    throw new RuntimeException('Could not start the PHP HTTP test server.');
}
fclose($pipes[0]);

try {
    $base = 'http://127.0.0.1:' . $port;
    $request = static function (string $method, string $path, string $body = '', string $cookie = '') use ($base): array {
        $headers = ["Connection: close"];
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

    $deadline = microtime(true) + 8;
    do {
        $login = $request('GET', '/login');
        if ($login['status'] === 200) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    httpCheck($login['status'] === 200, 'The actual HTTP router must render the login page.');
    httpCheck(str_contains(implode("\n", $login['headers']), 'X-Content-Type-Options: nosniff'), 'HTTP security headers must be emitted by bootstrap.');

    $cookie = '';
    foreach ($login['headers'] as $header) {
        if (stripos($header, 'Set-Cookie: c0m_session=') === 0) {
            $cookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
            httpCheck(stripos($header, 'httponly') !== false, 'Session cookies must be HttpOnly.');
            httpCheck(stripos($header, 'samesite=Lax') !== false, 'Session cookies must use SameSite=Lax.');
        }
    }
    httpCheck($cookie !== '', 'The login response must issue a session cookie.');

    $csrf = '';
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $login['body'], $match)) {
        $csrf = $match[1];
    }
    httpCheck($csrf !== '', 'The login form must expose its session-bound CSRF token.');

    $badCsrf = $request('POST', '/login', 'email=invalid%40example.test&password=invalid', $cookie);
    httpCheck($badCsrf['status'] === 419, 'The HTTP login endpoint must reject a missing CSRF token.');

    $badAdminCsrf = $request('POST', '/admin/users/status', 'user_id=1&status=banned', $cookie);
    httpCheck($badAdminCsrf['status'] === 419, 'The HTTP admin mutation endpoint must reject a missing CSRF token.');

    $admin = $request('GET', '/admin/users', '', $cookie);
    httpCheck($admin['status'] === 401, 'An unauthenticated HTTP request must not access admin member management.');

    $settings = $request('GET', '/admin/settings', '', $cookie);
    httpCheck($settings['status'] === 401, 'An unauthenticated HTTP request must not access admin settings.');

    $missing = $request('GET', '/route-that-does-not-exist');
    httpCheck($missing['status'] === 404, 'Unknown HTTP routes must return 404.');

    echo "HTTP router integration tests passed.\n";
} finally {
    proc_terminate($process);
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            fclose($pipes[$index]);
        }
    }
    proc_close($process);
    @unlink($dbConfigPath);
}
