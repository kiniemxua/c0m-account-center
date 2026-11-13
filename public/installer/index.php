<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$installerLocale = (string)($_GET['lang'] ?? $_COOKIE['c0m_lang'] ?? 'vi');
if (!in_array($installerLocale, ['vi', 'en'], true)) $installerLocale = 'vi';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['vi', 'en'], true)) {
    setcookie('c0m_lang', $installerLocale, ['expires' => time() + 31536000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
$installerCatalog = require $root . '/lang/' . $installerLocale . '.php';
$installerT = static fn(string $key): string => (string)($installerCatalog[$key] ?? ($installerLocale === 'vi' ? 'Thông báo hệ thống' : 'System message'));
require_once $root . '/app/Core/RequestSecurity.php';
if (!\App\Core\RequestSecurity::isHttps($root)) {
    http_response_code(400);
    header('Cache-Control: no-store');
    exit($installerT('HTTPS is required. Configure direct HTTPS or trusted proxy addresses before installation.'));
}
$configPath = $root . '/config/database.php';
$storagePath = $root . '/storage';
$lockPath = $storagePath . '/install.lock';
$error = null;
$done = is_file($lockPath);
if ($done && !is_file($configPath)) {
    $error = 'An installation lock exists but the database configuration is missing. Restore config/database.php from a secure backup; do not rerun setup against this database.';
}

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
        error_log('c0m installer recovery failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
        $error = 'An existing configuration was found, but its installation could not be verified. The installer will not overwrite it. Check the server error log.';
        $done = true;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('c0m_install');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => \App\Core\RequestSecurity::isHttps($root),
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

        if (!$email || strlen($adminPass) < 12 || $display === '' || mb_strlen($display) > 100 || $name === '' || $user === '' || $port < 1 || $port > 65535
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

                $find = $pdo->prepare('SELECT id, password_hash, status, email_verified_at FROM users WHERE email = :email LIMIT 1');
                $find->execute(['email' => $email]);
                $existing = $find->fetch(PDO::FETCH_ASSOC);
                if ($existing === false) {
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
                } else {
                    if ($existing['status'] !== 'active' || $existing['email_verified_at'] === null
                        || !password_verify($adminPass, $existing['password_hash'])) {
                        throw new RuntimeException('Existing administrator credentials did not verify.');
                    }
                    $id = (int)$existing['id'];
                }
                $profile = $pdo->prepare('INSERT IGNORE INTO user_profiles (user_id, language) VALUES (:id, :language)');
                $profile->execute(['id' => (int)$id, 'language' => $installerLocale]);
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
                error_log('c0m installer failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
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
<html lang="<?= htmlspecialchars($installerLocale, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>c0m.me — <?= htmlspecialchars($installerT('Installer'), ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><form method="get" class="language-switcher" style="position:fixed;top:12px;right:12px;z-index:5"><label for="installer-language"><?= htmlspecialchars($installerT("Language"), ENT_QUOTES, 'UTF-8') ?></label><select id="installer-language" name="lang" onchange="this.form.submit()"><option value="vi" <?= $installerLocale === 'vi' ? 'selected' : '' ?>><?= htmlspecialchars($installerT('Vietnamese'), ENT_QUOTES, 'UTF-8') ?></option><option value="en" <?= $installerLocale === 'en' ? 'selected' : '' ?>><?= htmlspecialchars($installerT('English'), ENT_QUOTES, 'UTF-8') ?></option></select></form>
<main class="auth-wrap">
<a class="brand" href="/">c0m.me</a>
<section class="auth-card">
<span class="eyebrow"><?= htmlspecialchars($installerT("SETUP WIZARD"), ENT_QUOTES, 'UTF-8') ?></span>
<h1><?= htmlspecialchars($installerT($done ? 'Installation locked' : 'Set up your Account Center'), ENT_QUOTES, 'UTF-8') ?></h1>
<?php if ($done): ?>
<p><?= $error ? htmlspecialchars($installerT($error), ENT_QUOTES, 'UTF-8') : htmlspecialchars($installerT('The installation is locked. Remove public access to /installer/ and sign in with the administrator account.'), ENT_QUOTES, 'UTF-8') ?></p>
<a class="button full" href="/login"><?= htmlspecialchars($installerT("Continue to sign in"), ENT_QUOTES, 'UTF-8') ?></a>
<?php else: ?>
<p><?= htmlspecialchars($installerT("PHP 8.2+, PDO MySQL and a newly created database are required."), ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($error): ?><div class="alert"><?= htmlspecialchars($installerT($error), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label><?= htmlspecialchars($installerT("Database host"), ENT_QUOTES, 'UTF-8') ?></label><input name="host" value="localhost" required>
<label><?= htmlspecialchars($installerT("Database port"), ENT_QUOTES, 'UTF-8') ?></label><input name="port" type="number" value="3306" min="1" max="65535" required>
<label><?= htmlspecialchars($installerT("Database name"), ENT_QUOTES, 'UTF-8') ?></label><input name="name" required>
<label><?= htmlspecialchars($installerT("Database username"), ENT_QUOTES, 'UTF-8') ?></label><input name="user" required>
<label><?= htmlspecialchars($installerT("Database password"), ENT_QUOTES, 'UTF-8') ?></label><input name="password" type="password" autocomplete="new-password">
<hr>
<label><?= htmlspecialchars($installerT("Administrator display name"), ENT_QUOTES, 'UTF-8') ?></label><input name="admin_name" value="Administrator" required>
<label><?= htmlspecialchars($installerT("Administrator email"), ENT_QUOTES, 'UTF-8') ?></label><input name="admin_email" type="email" required>
<label><?= htmlspecialchars($installerT("Administrator password (12+ characters)"), ENT_QUOTES, 'UTF-8') ?></label><input name="admin_password" type="password" minlength="12" autocomplete="new-password" required>
<button class="button full" type="submit"><?= htmlspecialchars($installerT("Install Account Center"), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<?php endif; ?>
</section>
</main>
<script>
(() => {
 const key = 'c0m-installer-form';
 const selector = document.getElementById('installer-language');
 if (selector) selector.form.addEventListener('submit', () => {
   const values = [];
   Array.from(document.forms).forEach((form, formIndex) => {
     if (form === selector.form) return;
     Array.from(form.elements).forEach((el, elementIndex) => {
       if (!['INPUT','SELECT','TEXTAREA'].includes(el.tagName) || !el.name || ['password','hidden','file','submit','button','reset'].includes(el.type)) return;
       values.push({formIndex,elementIndex,type:el.type,value:el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value});
     });
   });
   try { sessionStorage.setItem(key, JSON.stringify(values)); } catch (_) {}
 });
 try {
   const values = JSON.parse(sessionStorage.getItem(key) || '[]');
   values.forEach(v => {
     const form = document.forms[v.formIndex];
     const el = form && form.elements[v.elementIndex];
     if (!el || el.type === 'password' || el.type === 'hidden' || el.type === 'file') return;
     if (el.type === 'checkbox' || el.type === 'radio') el.checked = !!v.value; else el.value = v.value;
   });
   if (values.length) sessionStorage.removeItem(key);
 } catch (_) {}
})();
</script>
</body>
</html>
