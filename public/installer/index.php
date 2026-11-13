<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$configPath = $root . '/config/database.php';
$storagePath = $root . '/storage';
$lockPath = $storagePath . '/install.lock';
$error = null;
$done = is_file($lockPath);

// If the config was atomically written but the process stopped before writing
// the lock, verify the configured database and finish the lock operation.
if (!$done && is_file($configPath)) {
    try {
        $saved = require $configPath;
        $check = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $saved['host'], (int)($saved['port'] ?? 3306), $saved['name']),
            $saved['user'],
            $saved['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $check->query('SELECT id FROM users LIMIT 1');
        if (!is_dir($storagePath) && !mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
            throw new RuntimeException('Cannot create storage directory.');
        }
        $tmpLock = $lockPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmpLock, gmdate('c'), LOCK_EX) === false || !rename($tmpLock, $lockPath)) {
            @unlink($tmpLock);
            throw new RuntimeException('Cannot finalize installation lock.');
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('c0m installer recovery: ' . $e->getMessage());
        $error = 'An existing configuration was found, but its installation could not be verified. The installer will not overwrite it. Check the server error log.';
        $done = true;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('c0m_install');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
    $token = (string)($_POST['_csrf'] ?? '');
    if (empty($_SESSION['install_csrf']) || !hash_equals($_SESSION['install_csrf'], $token)) {
        $error = 'Invalid or expired form token.';
    } else {
        $host = trim((string)($_POST['host'] ?? 'localhost'));
        $port = (int)($_POST['port'] ?? 3306);
        $name = trim((string)($_POST['name'] ?? ''));
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $email = filter_var(strtolower(trim((string)($_POST['admin_email'] ?? ''))), FILTER_VALIDATE_EMAIL);
        $display = trim((string)($_POST['admin_name'] ?? 'Administrator'));
        $adminPass = (string)($_POST['admin_password'] ?? '');

        if (!$email || strlen($adminPass) < 12 || $name === '' || $user === '' || $port < 1 || $port > 65535
            || !preg_match('/^[a-zA-Z0-9._:-]{1,190}$/', $host)) {
            $error = 'Enter valid database details, an email and an administrator password of at least 12 characters.';
        } elseif (is_file($configPath) || !is_writable(dirname($configPath))) {
            $error = 'An existing configuration was found or the config directory is not writable. The installer will not overwrite it.';
            $done = is_file($configPath);
        } else {
            $tmpConfig = null;
            try {
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                    $user,
                    $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
                );

                $migrations = glob($root . '/database/migrations/*.sql') ?: [];
                sort($migrations, SORT_STRING);
                if ($migrations === []) {
                    throw new RuntimeException('No database migrations were found.');
                }
                // DDL is not transactional on MySQL. Each shipped migration is
                // designed to be repeatable so an interrupted setup can be retried.
                foreach ($migrations as $migrationFile) {
                    $sql = file_get_contents($migrationFile);
                    if ($sql === false) {
                        throw new RuntimeException('A database migration could not be read.');
                    }
                    $pdo->exec($sql);
                }

                $find = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $find->execute(['email' => $email]);
                $id = $find->fetchColumn();
                if ($id === false) {
                    $insert = $pdo->prepare(
                        "INSERT INTO users (email,password_hash,display_name,status,email_verified_at)
                         VALUES (:email,:hash,:display,'active',NOW())"
                    );
                    $insert->execute([
                        'email' => $email,
                        'hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                        'display' => $display,
                    ]);
                    $id = $pdo->lastInsertId();
                }
                $profile = $pdo->prepare('INSERT IGNORE INTO user_profiles (user_id) VALUES (:id)');
                $profile->execute(['id' => (int)$id]);
                $role = $pdo->prepare(
                    "INSERT IGNORE INTO user_roles (user_id, role_id)
                     SELECT :user_id, id FROM roles WHERE role_key = 'super_admin'"
                );
                $role->execute(['user_id' => (int)$id]);

                if (!is_dir($storagePath) && !mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
                    throw new RuntimeException('Cannot create storage directory.');
                }
                $config = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
                    'host' => $host,
                    'port' => $port,
                    'name' => $name,
                    'user' => $user,
                    'password' => $pass,
                    'app_key' => bin2hex(random_bytes(32)),
                ], true) . ";\n";
                $tmpConfig = $configPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
                if (file_put_contents($tmpConfig, $config, LOCK_EX) === false || !rename($tmpConfig, $configPath)) {
                    throw new RuntimeException('Could not atomically write database configuration.');
                }
                $tmpConfig = null;

                $tmpLock = $lockPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
                if (file_put_contents($tmpLock, gmdate('c'), LOCK_EX) === false || !rename($tmpLock, $lockPath)) {
                    @unlink($tmpLock);
                    throw new RuntimeException('Could not atomically write installation lock.');
                }
                $done = true;
                $_SESSION = [];
                session_regenerate_id(true);
            } catch (Throwable $e) {
                if ($tmpConfig !== null) {
                    @unlink($tmpConfig);
                }
                error_log('c0m installer: ' . $e->getMessage());
                $error = 'Installation failed. You may safely retry; existing schema objects are preserved. Check database permissions and server error log.';
            }
        }
    }
}

if (!$done && empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>c0m.me Installer</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="auth-wrap">
<a class="brand" href="/">c0m.me</a>
<section class="auth-card">
<span class="eyebrow">SETUP WIZARD</span>
<h1><?= $done ? 'Installation locked' : 'Set up your Account Center' ?></h1>
<?php if ($done): ?>
<p><?= $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : 'The installation is locked. Remove public access to /installer/ and sign in with the administrator account.' ?></p>
<a class="button full" href="/login">Continue to sign in</a>
<?php else: ?>
<p>PHP 8.2+, PDO MySQL and a newly created database are required.</p>
<?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label>Database host</label><input name="host" value="localhost" required>
<label>Port</label><input name="port" type="number" value="3306" min="1" max="65535" required>
<label>Database name</label><input name="name" required>
<label>Database username</label><input name="user" required>
<label>Database password</label><input name="password" type="password" autocomplete="new-password">
<hr>
<label>Administrator display name</label><input name="admin_name" value="Administrator" required>
<label>Administrator email</label><input name="admin_email" type="email" required>
<label>Administrator password (12+ characters)</label><input name="admin_password" type="password" minlength="12" autocomplete="new-password" required>
<button class="button full" type="submit">Install Account Center</button>
</form>
<?php endif; ?>
</section>
</main>
</body>
</html>
