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

function assertIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'c0m_test'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
App\Core\Database::configure([
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'c0m_test',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
]);

$migration = file_get_contents(BASE_PATH . '/database/migrations/001_core.sql');
assertIntegration($migration !== false, 'Core migration must exist.');
$pdo->exec($migration);
$pdo->exec($migration); // MySQL DDL is not transactional; installer retries must be idempotent.
$authMigration = file_get_contents(BASE_PATH . '/database/migrations/002_auth_foundation.sql');
assertIntegration($authMigration !== false, 'Auth migration must exist.');
$pdo->exec($authMigration);
$pdo->exec($authMigration); // RBAC and auth token migration must also be repeatable.

$email = 'ci-' . bin2hex(random_bytes(6)) . '@example.test';
$insert = $pdo->prepare("INSERT INTO users (email,password_hash,status,email_verified_at) VALUES (:email,:hash,'active',NOW())");
$insert->execute(['email' => $email, 'hash' => password_hash('Correct-Horse-42!', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

try {
    session_name('c0m_session');
    session_start();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'c0m-account-center-ci';
    App\Core\SessionManager::establish($userId);
    $sessionId = hash('sha256', session_id());

    $find = $pdo->prepare('SELECT user_id, revoked_at, expires_at FROM sessions WHERE id = :id');
    $find->execute(['id' => $sessionId]);
    $row = $find->fetch();
    assertIntegration($row && (int)$row['user_id'] === $userId, 'Login must persist a server-side session.');
    assertIntegration($row['revoked_at'] === null, 'A new session must not be revoked.');
    assertIntegration(strtotime($row['expires_at']) > time(), 'A new session must have an absolute expiry.');

    App\Core\SessionManager::revokeCurrent();
    $find->execute(['id' => $sessionId]);
    $row = $find->fetch();
    assertIntegration($row && $row['revoked_at'] !== null, 'Logout must revoke the server-side session.');

    session_destroy();
} finally {
    $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $delete->execute(['id' => $userId]);
}


// Exercise registration, hashed verification token, activation and single-use semantics.
$pdo->prepare("UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'registration_enabled'")->execute();
file_put_contents(BASE_PATH . '/config/app.php', "<?php return ['base_url' => 'https://accounts.example.test'];");
file_put_contents(BASE_PATH . '/config/mail.php', "<?php return ['mode' => 'development_log'];");
$registrationEmail = 'signup-' . bin2hex(random_bytes(6)) . '@example.test';
$controller = new App\Controllers\AuthController();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_start();
$_SESSION = [];
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => $registrationEmail,
    'display_name' => 'CI Registration',
    'password' => 'StrongPassword42',
];
ob_start();
$controller->register();
ob_end_clean();

$lookup = $pdo->prepare('SELECT id, status, email_verified_at FROM users WHERE email = :email');
$lookup->execute(['email' => $registrationEmail]);
$registered = $lookup->fetch();
assertIntegration($registered && $registered['status'] === 'pending', 'Registration must create a pending account.');
$mailLog = file_get_contents(BASE_PATH . '/storage/mail.log') ?: '';
preg_match('/verify-email\?token=([a-f0-9]{64})/', $mailLog, $matches);
assertIntegration(isset($matches[1]), 'Registration must send a verification link through the configured mail provider.');
$rawToken = $matches[1];
$tokenQuery = $pdo->prepare('SELECT token_hash FROM auth_tokens WHERE user_id = :id AND token_type = "email_verification"');
$tokenQuery->execute(['id' => (int)$registered['id']]);
$storedToken = $tokenQuery->fetchColumn();
assertIntegration($storedToken === hash('sha256', $rawToken), 'Only a SHA-256 hash of the verification token may be stored.');

$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $rawToken];
ob_start();
$controller->verifyEmail();
ob_end_clean();
$lookup->execute(['email' => $registrationEmail]);
$activated = $lookup->fetch();
assertIntegration($activated && $activated['status'] === 'active' && $activated['email_verified_at'] !== null, 'A valid verification token must activate the account.');

$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $rawToken];
ob_start();
$controller->verifyEmail();
ob_end_clean();
$tokenQuery->execute(['id' => (int)$registered['id']]);
$tokenRow = $pdo->prepare('SELECT used_at FROM auth_tokens WHERE token_hash = :hash');
$tokenRow->execute(['hash' => hash('sha256', $rawToken)]);
assertIntegration($tokenRow->fetchColumn() !== null, 'A verification token must remain consumed after use.');

$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int)$registered['id']]);
@unlink(BASE_PATH . '/config/app.php');
@unlink(BASE_PATH . '/config/mail.php');
@unlink(BASE_PATH . '/storage/mail.log');

echo "MariaDB integration tests passed.\n";
