<?php

declare(strict_types=1);

namespace LocalChat\Security;

use SessionHandlerInterface;

final class Session
{
    public static function configure(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => SESSION_TTL_SECONDS,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isHttps,
            'path' => '/',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_probability', '0');
        ini_set('session.gc_divisor', '1000');
        ini_set('session.gc_maxlifetime', (string) SESSION_TTL_SECONDS);
        ini_set('session.cookie_lifetime', (string) SESSION_TTL_SECONDS);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if ($isHttps) {
            ini_set('session.cookie_secure', '1');
        }
    }

    public static function installHandler(): void
    {
        static $installed = false;

        if ($installed) {
            return;
        }

        $handler = new class () implements SessionHandlerInterface {
            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                $this->gc(SESSION_TTL_SECONDS);

                return true;
            }

            public function read(string $id): string|false
            {
                $this->deleteExpiredSession($id);

                $stmt = db()->prepare(
                    'SELECT payload, expires_at
                     FROM sessions
                     WHERE id = :id
                     LIMIT 1'
                );
                $stmt->execute(['id' => $id]);
                $session = $stmt->fetch();

                if (!is_array($session)) {
                    return '';
                }

                $expiresAt = strtotime((string) ($session['expires_at'] ?? ''));
                if ($expiresAt === false || $expiresAt <= time()) {
                    $this->destroy($id);

                    return '';
                }

                return is_string($session['payload'] ?? null) ? $session['payload'] : '';
            }

            public function write(string $id, string $data): bool
            {
                $now = gmdate('c');
                $expiresAt = gmdate('c', time() + SESSION_TTL_SECONDS);

                if (dbDriver() === 'mysql') {
                    $stmt = db()->prepare(
                        'INSERT INTO sessions (id, payload, created_at, last_seen_at, expires_at)
                         VALUES (:id, :payload, :created_at, :last_seen_at, :expires_at)
                         ON DUPLICATE KEY UPDATE
                            payload = VALUES(payload),
                            last_seen_at = VALUES(last_seen_at),
                            expires_at = VALUES(expires_at)'
                    );
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO sessions (id, payload, created_at, last_seen_at, expires_at)
                         VALUES (:id, :payload, :created_at, :last_seen_at, :expires_at)
                         ON CONFLICT(id) DO UPDATE SET
                            payload = excluded.payload,
                            last_seen_at = excluded.last_seen_at,
                            expires_at = excluded.expires_at'
                    );
                }

                $stmt->execute([
                    'id' => $id,
                    'payload' => $data,
                    'created_at' => $now,
                    'last_seen_at' => $now,
                    'expires_at' => $expiresAt,
                ]);

                return true;
            }

            public function destroy(string $id): bool
            {
                $stmt = db()->prepare('DELETE FROM sessions WHERE id = :id');
                $stmt->execute(['id' => $id]);

                return true;
            }

            public function gc(int $max_lifetime): int|false
            {
                $stmt = db()->prepare('DELETE FROM sessions WHERE expires_at <= :now');
                $stmt->execute(['now' => gmdate('c')]);

                return $stmt->rowCount();
            }

            private function deleteExpiredSession(string $id): void
            {
                $stmt = db()->prepare('DELETE FROM sessions WHERE id = :id AND expires_at <= :now');
                $stmt->execute([
                    'id' => $id,
                    'now' => gmdate('c'),
                ]);
            }
        };

        session_set_save_handler($handler, true);
        $installed = true;
    }

    public static function diagnostics(): array
    {
        $sessionId = session_id();
        $sessionRecord = null;

        if ($sessionId !== '') {
            $stmt = db()->prepare(
                'SELECT id, created_at, last_seen_at, expires_at
                 FROM sessions
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $sessionId]);
            $row = $stmt->fetch();
            $sessionRecord = is_array($row) ? $row : null;
        }

        return [
            'backend' => SESSION_BACKEND_NAME,
            'ttl_seconds' => SESSION_TTL_SECONDS,
            'php_session_name' => session_name(),
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'session_status' => session_status(),
            'effective_expires_at' => $sessionRecord['expires_at'] ?? ($sessionId !== '' ? gmdate('c', time() + SESSION_TTL_SECONDS) : null),
            'created_at' => $sessionRecord['created_at'] ?? null,
            'last_seen_at' => $sessionRecord['last_seen_at'] ?? null,
        ];
    }
}
