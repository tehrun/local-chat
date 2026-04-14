<?php

declare(strict_types=1);

namespace LocalChat\Infrastructure;

use PDO;

final class Database
{
    public static function connection(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $config = self::config();

        if ($config['driver'] === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['name']
            );
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('SET NAMES utf8mb4');
        } else {
            $pdo = new PDO('sqlite:' . DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        initializeDatabase($pdo);

        return $pdo;
    }

    public static function config(): array
    {
        $driver = strtolower(trim(Environment::value('CHAT_DB_DRIVER', 'sqlite')));

        if ($driver === 'mysql') {
            return [
                'driver' => 'mysql',
                'host' => trim(Environment::value('CHAT_DB_HOST', DEFAULT_DB_HOST)),
                'port' => trim(Environment::value('CHAT_DB_PORT', DEFAULT_DB_PORT)),
                'name' => trim(Environment::value('CHAT_DB_NAME', '')),
                'username' => trim(Environment::value('CHAT_DB_USER', '')),
                'password' => Environment::value('CHAT_DB_PASS', ''),
            ];
        }

        return ['driver' => 'sqlite'];
    }

    public static function driver(): string
    {
        static $driver = null;

        if (is_string($driver)) {
            return $driver;
        }

        $driver = self::config()['driver'];

        return $driver;
    }
}
