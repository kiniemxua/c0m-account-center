<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

final class SessionManager
{
    private const ABSOLUTE_TIMEOUT = 43200;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('c0m_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::requestIsSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (!empty($_SESSION['user_id'])) {
            self::validateCurrent();
        }
    }

    /**
     * Only trust X-Forwarded-Proto when REMOTE_ADDR is explicitly configured
     * as a trusted proxy. Client-supplied forwarding headers are ignored.
     */
    public static function requestIsSecure(): bool
    {
        return RequestSecurity::isHttps(BASE_PATH);
    }

    public static function establish(int $userId): void
    {
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM sessions WHERE expires_at < NOW() OR revoked_at < (NOW() - INTERVAL 30 DAY)');
        self::revokeCurrent($pdo);
        session_regenerate_id(true);

        $now = new \DateTimeImmutable('now');
        $stmt = $pdo->prepare(
            'INSERT INTO sessions (id, user_id, ip_address, user_agent, last_activity, expires_at)
             VALUES (:id, :user_id, :ip, :agent, :last_activity, :expires_at)'
        );
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $packedIp = $ip !== false ? inet_pton($ip) : null;
        $stmt->bindValue(':id', hash('sha256', session_id()));
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':agent', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500));
        $stmt->bindValue(':last_activity', $now->format('Y-m-d H:i:s'));
        $stmt->bindValue(':expires_at', $now->modify('+' . self::ABSOLUTE_TIMEOUT . ' seconds')->format('Y-m-d H:i:s'));
        $stmt->execute();

        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = $now->getTimestamp();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function revokeCurrent(?PDO $pdo = null): void
    {
        if (empty($_SESSION['user_id']) || session_id() === '') {
            return;
        }
        try {
            $pdo ??= Database::connection();
            $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL');
            $stmt->execute(['id' => hash('sha256', session_id())]);
        } catch (Throwable $e) {
            error_log('Session revocation failed: ' . $e->getMessage());
        }
    }

    private static function validateCurrent(): void
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT s.user_id, u.status FROM sessions s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.id = :id AND s.user_id = :user_id
                   AND s.revoked_at IS NULL AND s.expires_at > NOW()
                   AND s.last_activity >= (NOW() - INTERVAL 30 MINUTE)
                 LIMIT 1'
            );
            $stmt->execute([
                'id' => hash('sha256', session_id()),
                'user_id' => (int)$_SESSION['user_id'],
            ]);
            $session = $stmt->fetch();
            if (!$session || $session['status'] !== 'active') {
                self::invalidate();
                return;
            }
            $update = $pdo->prepare('UPDATE sessions SET last_activity = NOW() WHERE id = :id');
            $update->execute(['id' => hash('sha256', session_id())]);
        } catch (Throwable $e) {
            error_log('Authenticated session validation failed: ' . $e->getMessage());
            self::invalidate();
        }
    }

    public static function invalidate(): void
    {
        self::revokeCurrent();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
