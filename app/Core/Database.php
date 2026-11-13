<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    public static function configure(array $config): void
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'], (int)($config['port'] ?? 3306), $config['name']);
        try {
            self::$pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            throw new RuntimeException('Database connection failed.');
        }
    }
    public static function connection(): PDO
    {
        if (!self::$pdo) throw new RuntimeException('Database is not configured. Run the installer.');
        return self::$pdo;
    }
}
