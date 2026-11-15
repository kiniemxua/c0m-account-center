<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('UTC');
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$targetId = (int)($argv[1] ?? 0);
$actorId = (int)($argv[2] ?? 0);
$holdLock = ($argv[3] ?? '') === 'hold';
$marker = (string)($argv[4] ?? '');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'c0m_test'),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET time_zone = '+00:00'");

try {
    $pdo->beginTransaction();
    $active = App\Core\SuperAdminGuard::lockActive($pdo);
    $isSuperAdmin = static function (int $id) use ($pdo): bool {
        $check = $pdo->prepare(
            "SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :id AND r.role_key = 'super_admin' LIMIT 1"
        );
        $check->execute(['id' => $id]);
        return $check->fetchColumn() !== false;
    };
    $actor = $pdo->prepare('SELECT status FROM users WHERE id = :id FOR UPDATE');
    $actor->execute(['id' => $actorId]);
    if ($actor->fetchColumn() !== 'active' || !$isSuperAdmin($actorId)) {
        throw new RuntimeException('actor_no_longer_super_admin');
    }

    $target = $pdo->prepare('SELECT status FROM users WHERE id = :id FOR UPDATE');
    $target->execute(['id' => $targetId]);
    $status = $target->fetchColumn();
    $isSuper = $isSuperAdmin($targetId);
    App\Core\SuperAdminGuard::assertCanRemove($active, $targetId, $isSuper, $status === 'active', false);

    if ($holdLock) {
        file_put_contents($marker, 'locked');
        usleep(1500000);
    }
    $remove = $pdo->prepare(
        "DELETE ur FROM user_roles ur
         INNER JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = :id AND r.role_key = 'super_admin'"
    );
    $remove->execute(['id' => $targetId]);
    $pdo->commit();
    echo "removed\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getMessage() === 'last_super_admin') {
        echo "last_super_admin_blocked\n";
        exit(0);
    }
    fwrite(STDERR, "RBAC worker failed (" . get_class($e) . ", " . (in_array($e->getMessage(), ['actor_no_longer_super_admin', 'last_super_admin'], true) ? $e->getMessage() : 'unexpected') . ").\n");
    exit(1);
}
