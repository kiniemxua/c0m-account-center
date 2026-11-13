<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$configPath = $root . '/config/database.php';
$lockPath = $root . '/storage/install.lock';
$error = null;
$done = is_file($lockPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
    $token = (string)($_POST['_csrf'] ?? '');
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['install_csrf']) || !hash_equals($_SESSION['install_csrf'], $token)) {
        $error = 'Invalid or expired form token.';
    } else {
        $host = trim((string)($_POST['host'] ?? 'localhost'));
        $port = (int)($_POST['port'] ?? 3306);
        $name = trim((string)($_POST['name'] ?? ''));
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $email = filter_var(trim((string)($_POST['admin_email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $display = trim((string)($_POST['admin_name'] ?? 'Administrator'));
        $adminPass = (string)($_POST['admin_password'] ?? '');
        if (!$email || strlen($adminPass) < 12 || $name === '' || $user === '' || $port < 1 || $port > 65535) {
            $error = 'Enter valid database details, an email and an admin password of at least 12 characters.';
        } elseif (file_exists($configPath) || !is_writable(dirname($configPath))) {
            $error = 'Remove the existing config or make the config directory writable before installation.';
        } else {
            try {
                $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $sql = file_get_contents($root . '/database/migrations/001_core.sql');
                if ($sql === false) throw new RuntimeException('Core migration file is missing.');
                $pdo->exec($sql);
                $stmt = $pdo->prepare("INSERT INTO users (email,password_hash,display_name,status,email_verified_at) VALUES (:email,:hash,:display,'active',NOW())");
                $stmt->execute(['email' => $email, 'hash' => password_hash($adminPass, PASSWORD_DEFAULT), 'display' => $display]);
                $id = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (?)')->execute([$id]);

                $config = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
                    'host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'password'=>$pass,
                    'app_key'=>bin2hex(random_bytes(32))
                ], true) . ";\n";
                if (file_put_contents($configPath, $config, LOCK_EX) === false) throw new RuntimeException('Could not write database config.');
                if (!is_dir($root . '/storage')) mkdir($root . '/storage', 0750, true);
                file_put_contents($lockPath, gmdate('c'), LOCK_EX);
                $done = true;
            } catch (Throwable $e) {
                error_log('c0m installer: ' . $e->getMessage());
                $error = 'Installation failed. Check database permissions and server error log. No credentials are displayed.';
            }
        }
    }
}
if (!$done && session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!$done && empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>c0m.me Installer</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="auth-wrap"><a class="brand" href="/">c0m.me</a><section class="auth-card"><span class="eyebrow">SETUP WIZARD</span><h1><?= $done ? 'Installation complete' : 'Set up your Account Center' ?></h1>
<?php if ($done): ?><p>Setup is locked. Remove public access to <code>/installer/</code> now. You can sign in using the administrator account you created.</p><a class="button full" href="/login">Continue to sign in</a>
<?php else: ?> <p>PHP 8.2+, PDO MySQL and a newly created database are required.</p><?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>"><label>Database host</label><input name="host" value="localhost" required><label>Port</label><input name="port" type="number" value="3306" required><label>Database name</label><input name="name" required><label>Database username</label><input name="user" required><label>Database password</label><input name="password" type="password"><hr><label>Administrator display name</label><input name="admin_name" value="Administrator" required><label>Administrator email</label><input name="admin_email" type="email" required><label>Administrator password (12+ characters)</label><input name="admin_password" type="password" minlength="12" required><button class="button full" type="submit">Install Account Center</button></form><?php endif; ?></section></main></body></html>
