<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
$configPath = BASE_PATH . '/config/database.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Database configuration is missing. Run the installer first.\n");
    exit(1);
}
$config = require $configPath;
if (!is_array($config) || !isset($config['host'], $config['name'], $config['user'])) {
    fwrite(STDERR, "Database configuration is invalid.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int)($config['port'] ?? 3306), $config['name']),
        (string)$config['user'],
        (string)($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $lock = $pdo->query("SELECT GET_LOCK('c0m-account-center-migrations', 30)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('Could not acquire the migration lock.');
    }
    try {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        if ($files === []) throw new RuntimeException('No migration files were found.');
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Migration file is empty or unreadable: ' . basename($file));
            $pdo->exec($sql);
            fwrite(STDOUT, 'Applied ' . basename($file) . PHP_EOL);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('c0m-account-center-migrations')");
    }
    fwrite(STDOUT, "Database migrations completed.\n");
} catch (Throwable $e) {
    error_log('Migration runner failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
    fwrite(STDERR, "Migration failed. Check the server error log.\n");
    exit(1);
}
