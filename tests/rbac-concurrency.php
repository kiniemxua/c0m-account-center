<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'c0m_test';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET time_zone = '+00:00'");
$ids = [];
$marker = sys_get_temp_dir() . '/c0m-rbac-' . bin2hex(random_bytes(6));
$process1 = null;
$process2 = null;
$pipes1 = [];
$pipes2 = [];
try {
    $emails = ['rbac-actor-' . bin2hex(random_bytes(4)) . '@example.test', 'rbac-target-' . bin2hex(random_bytes(4)) . '@example.test'];
    foreach ($emails as $email) {
        $pdo->prepare("INSERT INTO users (email, password_hash, display_name, status, email_verified_at) VALUES (:email, :hash, 'RBAC concurrency test', 'active', NOW())")
            ->execute(['email' => $email, 'hash' => password_hash('RbacTestPassword-123', PASSWORD_DEFAULT)]);
        $ids[] = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'super_admin'")
            ->execute(['id' => $ids[array_key_last($ids)]]);
    }
    if (count($ids) !== 2) {
        throw new RuntimeException('Concurrency fixture must contain two test accounts.');
    }
    $roleCount = $pdo->prepare(
        "SELECT COUNT(*) FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id
         WHERE u.id IN (?, ?) AND u.status = 'active' AND r.role_key = 'super_admin'"
    );
    $roleCount->execute($ids);
    if ((int)$roleCount->fetchColumn() !== 2) {
        throw new RuntimeException('Concurrency fixture must contain two active Super Admin accounts.');
    }
    $command1 = [PHP_BINARY, BASE_PATH . '/tests/rbac-lock-worker.php', (string)$ids[1], (string)$ids[0], 'hold', $marker];
    $process1 = proc_open($command1, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
    if (!is_resource($process1)) {
        throw new RuntimeException('Could not start first RBAC worker.');
    }
    fclose($pipes1[0]);

    $deadline = microtime(true) + 5;
    while (!is_file($marker) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (!is_file($marker)) {
        proc_terminate($process1);
        $workerOut = stream_get_contents($pipes1[1]);
        $workerErr = stream_get_contents($pipes1[2]);
        throw new RuntimeException(
            'First RBAC worker did not acquire the lock in time. ' .
            'stdout=' . substr((string)$workerOut, 0, 500) .
            ' stderr=' . substr((string)$workerErr, 0, 500)
        );
    }

    // The second operation races to demote the actor while the first operation
    // removes the other administrator. The shared lock must serialize both.
    $command2 = [PHP_BINARY, BASE_PATH . '/tests/rbac-lock-worker.php', (string)$ids[0], (string)$ids[0], 'no-hold', $marker];
    $process2 = proc_open($command2, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);
    if (!is_resource($process2)) {
        throw new RuntimeException('Could not start second RBAC worker.');
    }
    fclose($pipes2[0]);

    $out1 = stream_get_contents($pipes1[1]);
    $err1 = stream_get_contents($pipes1[2]);
    fclose($pipes1[1]);
    fclose($pipes1[2]);
    $exit1 = proc_close($process1);
    $out2 = stream_get_contents($pipes2[1]);
    $err2 = stream_get_contents($pipes2[2]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    $exit2 = proc_close($process2);
    $process1 = $process2 = null;

    if ($exit1 !== 0 || trim($out1) !== 'removed' || $err1 !== '') {
        throw new RuntimeException('First RBAC transaction did not complete as expected.');
    }
    if ($exit2 !== 0 || trim($out2) !== 'last_super_admin_blocked' || $err2 !== '') {
        throw new RuntimeException('Concurrent final-Super-Admin removal was not blocked.');
    }

    $remaining = $pdo->query(
        "SELECT COUNT(*) FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id
         WHERE r.role_key = 'super_admin' AND u.status = 'active'"
    )->fetchColumn();
    if ((int)$remaining !== 1) {
        throw new RuntimeException('Concurrent RBAC operations did not preserve exactly one active Super Admin.');
    }
    echo "Super Admin concurrency integration test passed.\n";
} finally {
    foreach ([[$process1, $pipes1], [$process2, $pipes2]] as [$process, $pipes]) {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
            proc_close($process);
        }
    }
    @unlink($marker);
    if ($ids !== []) {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $delete = $pdo->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
        $delete->execute($ids);
    }
}
