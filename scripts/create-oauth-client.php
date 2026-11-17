<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$argv = $_SERVER['argv'] ?? [];
define('BASE_PATH', dirname(__DIR__));
$configPath = BASE_PATH . '/config/database.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Database configuration is missing. Run the installer first.\n");
    exit(1);
}
$config = require $configPath;
$applicationId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = (string)($argv[2] ?? '');
$redirects = array_slice($argv, 3);
if ($applicationId === false || !in_array($type, ['public', 'confidential'], true) || $redirects === []) {
    fwrite(STDERR, "Usage: php scripts/create-oauth-client.php <application-id> <public|confidential> <https-redirect-uri> [additional-https-redirect-uri...]\n");
    exit(2);
}
foreach ($redirects as $uri) {
    $parts = parse_url($uri);
    if (!is_string($uri) || strlen($uri) > 2048 || filter_var($uri, FILTER_VALIDATE_URL) === false
        || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || preg_match('/[\x00-\x20\x7f]/', $uri)) {
        fwrite(STDERR, "Every redirect URI must be a valid HTTPS URL without userinfo or fragments.\n");
        exit(2);
    }
}
$redirects = array_values(array_unique($redirects));
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int)($config['port'] ?? 3306), $config['name']),
        (string)$config['user'],
        (string)($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $application = $pdo->prepare("SELECT id, name FROM applications WHERE id = :id AND status = 'active' LIMIT 1");
    $application->execute(['id' => $applicationId]);
    $app = $application->fetch(PDO::FETCH_ASSOC);
    if (!$app) throw new RuntimeException('Application does not exist or is not active.');
    $existing = $pdo->prepare('SELECT id FROM oauth_clients WHERE application_id = :id LIMIT 1');
    $existing->execute(['id' => $applicationId]);
    if ($existing->fetchColumn() !== false) throw new RuntimeException('This application already has an OAuth client. Rotate or update it through the admin console.');
    $clientId = bin2hex(random_bytes(32));
    $secret = $type === 'confidential' ? bin2hex(random_bytes(48)) : null;
    $scopes = ['openid', 'profile', 'email', 'groups', 'permissions', 'entitlements'];
    $insert = $pdo->prepare(
        'INSERT INTO oauth_clients (application_id, client_id, client_secret_hash, client_type, redirect_uris, allowed_scopes, status)
         VALUES (:app, :client, :secret_hash, :type, :redirects, :scopes, \'active\')'
    );
    $insert->execute([
        'app' => $applicationId,
        'client' => $clientId,
        'secret_hash' => $secret === null ? null : hash('sha256', $secret),
        'type' => $type,
        'redirects' => json_encode($redirects, JSON_THROW_ON_ERROR),
        'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
    ]);
    fwrite(STDOUT, json_encode([
        'application' => $app['name'],
        'client_id' => $clientId,
        'client_type' => $type,
        'client_secret' => $secret,
        'redirect_uris' => $redirects,
        'allowed_scopes' => $scopes,
        'warning' => 'Store this output securely. The client secret is shown only once.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $e) {
    error_log('OAuth client provisioning failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
    fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage() . PHP_EOL : "OAuth client provisioning failed. Check the server error log.\n");
    exit(1);
}
