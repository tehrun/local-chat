<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('TMP_UPLOAD_PATH', STORAGE_PATH . '/tmp');
define('DB_PATH', STORAGE_PATH . '/db/chat.sqlite');
define('DEFAULT_DB_HOST', '127.0.0.1');
define('DEFAULT_DB_PORT', '3306');
define('MESSAGE_TTL_SECONDS', 24 * 60 * 60);
define('TYPING_TTL_SECONDS', 8);
define('PRESENCE_TTL_SECONDS', 90);
define('PRESENCE_UPDATE_INTERVAL_SECONDS', 30);
define('PURGE_INTERVAL_SECONDS', 30);
define('SESSION_TTL_SECONDS', 30 * 24 * 60 * 60);

configureSession();
session_start();

applySecurityHeaders();

function configureSession(): void
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
    ini_set('session.gc_maxlifetime', (string) SESSION_TTL_SECONDS);
    ini_set('session.cookie_lifetime', (string) SESSION_TTL_SECONDS);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
}

function applySecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; manifest-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
}


function jsonEncodeFlags(): int
{
    return JSON_THROW_ON_ERROR
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;
}

function encodeJson(mixed $value): string
{
    return json_encode($value, jsonEncodeFlags());
}

function jsonScriptValue(mixed $value): string
{
    return encodeJson($value);
}

function requirePositiveInt(array $source, string $key): int
{
    $value = $source[$key] ?? null;

    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }

    if (!is_string($value) || $value === '' || !ctype_digit($value)) {
        return 0;
    }

    $parsed = (int) $value;

    return $parsed > 0 ? $parsed : 0;
}

function isSafeStorageRelativePath(?string $relativePath): bool
{
    if (!is_string($relativePath) || $relativePath === '') {
        return false;
    }

    if (str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
        return false;
    }

    return str_starts_with($relativePath, 'storage/uploads/') || str_starts_with($relativePath, 'storage/tmp/');
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    return is_string($sessionToken)
        && is_string($token)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $token);
}

function requireCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        jsonResponse(['error' => 'Invalid CSRF token.'], 419);
    }
}


if (!is_dir(dirname(DB_PATH))) {
    mkdir(dirname(DB_PATH), 0777, true);
}

if (!is_dir(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0777, true);
}

if (!is_dir(TMP_UPLOAD_PATH)) {
    mkdir(TMP_UPLOAD_PATH, 0777, true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = dbConfig();

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

function dbConfig(): array
{
    $driver = strtolower(trim((string) envValue('CHAT_DB_DRIVER', 'sqlite')));

    if ($driver === 'mysql') {
        return [
            'driver' => 'mysql',
            'host' => trim((string) envValue('CHAT_DB_HOST', DEFAULT_DB_HOST)),
            'port' => trim((string) envValue('CHAT_DB_PORT', DEFAULT_DB_PORT)),
            'name' => trim((string) envValue('CHAT_DB_NAME', '')),
            'username' => trim((string) envValue('CHAT_DB_USER', '')),
            'password' => (string) envValue('CHAT_DB_PASS', ''),
        ];
    }

    return ['driver' => 'sqlite'];
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);

    return $value === false ? $default : $value;
}

function dbDriver(): string
{
    static $driver = null;

    if (is_string($driver)) {
        return $driver;
    }

    $driver = dbConfig()['driver'];

    return $driver;
}

function initializeDatabase(PDO $pdo): void
{
    if (dbDriver() === 'mysql') {
        initializeMysqlDatabase($pdo);

        return;
    }

    initializeSqliteDatabase($pdo);
}

function initializeSqliteDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            body TEXT,
            audio_path TEXT,
            image_path TEXT,
            delivered_at TEXT,
            read_at TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(sender_id) REFERENCES users(id),
            FOREIGN KEY(recipient_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS typing_status (
            user_id INTEGER NOT NULL,
            conversation_user_id INTEGER NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, conversation_user_id),
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(conversation_user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_messages_conversation_time
         ON messages (sender_id, recipient_id, created_at, id)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_messages_pending_delivery
         ON messages (recipient_id, sender_id, delivered_at, read_at, created_at, id)'
    );

    $columns = array_map(
        static fn (array $column): string => (string) $column['name'],
        $pdo->query('PRAGMA table_info(messages)')->fetchAll()
    );

    if (!in_array('image_path', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN image_path TEXT');
    }

    if (!in_array('delivered_at', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN delivered_at TEXT');
    }

    if (!in_array('read_at', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN read_at TEXT');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_presence (
            user_id INTEGER PRIMARY KEY,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_typing_status_lookup
         ON typing_status (user_id, conversation_user_id, updated_at)'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_user_presence_updated
         ON user_presence (updated_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS friend_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            responded_at TEXT,
            UNIQUE(sender_id, recipient_id),
            FOREIGN KEY(sender_id) REFERENCES users(id),
            FOREIGN KEY(recipient_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_friend_requests_recipient_status
         ON friend_requests (recipient_id, status, created_at)'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_friend_requests_sender_status
         ON friend_requests (sender_id, status, created_at)'
    );
}

function initializeMysqlDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            body LONGTEXT NULL,
            audio_path VARCHAR(255) NULL,
            image_path VARCHAR(255) NULL,
            delivered_at DATETIME NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_messages_conversation_time (sender_id, recipient_id, created_at, id),
            INDEX idx_messages_pending_delivery (recipient_id, sender_id, delivered_at, read_at, created_at, id),
            CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS typing_status (
            user_id INT UNSIGNED NOT NULL,
            conversation_user_id INT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, conversation_user_id),
            INDEX idx_typing_status_lookup (user_id, conversation_user_id, updated_at),
            CONSTRAINT fk_typing_status_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_typing_status_conversation_user FOREIGN KEY (conversation_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_presence (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            updated_at DATETIME NOT NULL,
            INDEX idx_user_presence_updated (updated_at),
            CONSTRAINT fk_user_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS friend_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            responded_at DATETIME NULL,
            UNIQUE KEY uniq_friend_requests_pair (sender_id, recipient_id),
            INDEX idx_friend_requests_recipient_status (recipient_id, status, created_at),
            INDEX idx_friend_requests_sender_status (sender_id, status, created_at),
            CONSTRAINT fk_friend_requests_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_friend_requests_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function purgeExpiredMessages(bool $force = false): void
{
    static $lastRunAt = null;

    if (!$force && $lastRunAt !== null && (time() - $lastRunAt) < PURGE_INTERVAL_SECONDS) {
        return;
    }

    $cutoff = gmdate('c', time() - MESSAGE_TTL_SECONDS);
    $typingCutoff = gmdate('c', time() - TYPING_TTL_SECONDS);
    $pdo = db();

    $stmt = $pdo->prepare('SELECT audio_path, image_path FROM messages WHERE created_at < :cutoff AND (audio_path IS NOT NULL OR image_path IS NOT NULL)');
    $stmt->execute(['cutoff' => $cutoff]);

    foreach ($stmt->fetchAll() as $row) {
        foreach (['audio_path', 'image_path'] as $column) {
            $relativePath = $row[$column] ?? null;
            if (!is_string($relativePath) || $relativePath === '') {
                continue;
            }

            if (!isSafeStorageRelativePath($relativePath)) {
                continue;
            }

            $path = BASE_PATH . '/' . $relativePath;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    $delete = $pdo->prepare('DELETE FROM messages WHERE created_at < :cutoff');
    $delete->execute(['cutoff' => $cutoff]);

    $deleteTyping = $pdo->prepare('DELETE FROM typing_status WHERE updated_at < :cutoff');
    $deleteTyping->execute(['cutoff' => $typingCutoff]);

    $lastRunAt = time();
}

function touchUserPresence(int $userId): void
{
    static $presenceWriteCache = [];

    $now = time();
    $lastWrittenAt = $presenceWriteCache[$userId] ?? 0;

    if (($now - $lastWrittenAt) < PRESENCE_UPDATE_INTERVAL_SECONDS) {
        return;
    }

    $params = [
        'user_id' => $userId,
        'updated_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO user_presence (user_id, updated_at)
             VALUES (:user_id, :updated_at)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO user_presence (user_id, updated_at)
             VALUES (:user_id, :updated_at)
             ON CONFLICT(user_id)
             DO UPDATE SET updated_at = excluded.updated_at'
        );
    }

    $stmt->execute($params);
    $presenceWriteCache[$userId] = $now;
}

function isUserOnline(int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM user_presence
         WHERE user_id = :user_id
           AND updated_at >= :cutoff
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'cutoff' => gmdate('c', time() - PRESENCE_TTL_SECONDS),
    ]);

    return (bool) $stmt->fetchColumn();
}

function formatPresenceTimestamp(int $timestamp): string
{
    $timeLabel = date('H:i', $timestamp);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $dateLabel = date('Y-m-d', $timestamp);

    if ($dateLabel === $today) {
        return 'today at ' . $timeLabel;
    }

    if ($dateLabel === $yesterday) {
        return 'yesterday at ' . $timeLabel;
    }

    $dateFormat = date('Y', $timestamp) === date('Y') ? 'M j' : 'M j, Y';

    return date($dateFormat, $timestamp) . ' at ' . $timeLabel;
}

function presenceLabel(?string $updatedAt): string
{
    if ($updatedAt === null) {
        return 'Offline';
    }

    $timestamp = strtotime($updatedAt);
    if ($timestamp === false) {
        return 'Offline';
    }

    if ($timestamp >= (time() - PRESENCE_TTL_SECONDS)) {
        return 'Online';
    }

    return 'Last seen ' . formatPresenceTimestamp($timestamp);
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, username, created_at FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);

    $user = $stmt->fetch() ?: null;

    if ($user !== null) {
        touchUserPresence((int) $user['id']);
    }

    return $user;
}

function requireAuth(): array
{
    $user = currentUser();

    if ($user === null) {
        header('Location: index.php?login=required');
        exit;
    }

    return $user;
}

function mapUsersWithPresence(array $users): array
{
    return array_map(static function (array $user): array {
        $user['is_online'] = isset($user['presence_updated_at'])
            && strtotime((string) $user['presence_updated_at']) !== false
            && strtotime((string) $user['presence_updated_at']) >= (time() - PRESENCE_TTL_SECONDS);
        $user['presence_label'] = presenceLabel($user['presence_updated_at'] ?? null);
        $user['unseen_count'] = (int) ($user['unseen_count'] ?? 0);

        return $user;
    }, $users);
}

function compareFriendshipPriority(array $candidate, array $current): int
{
    $priorityMap = [
        'accepted' => 4,
        'pending' => 3,
        'revoked' => 2,
        'rejected' => 1,
    ];

    $candidatePriority = $priorityMap[(string) ($candidate['status'] ?? '')] ?? 0;
    $currentPriority = $priorityMap[(string) ($current['status'] ?? '')] ?? 0;

    if ($candidatePriority !== $currentPriority) {
        return $candidatePriority <=> $currentPriority;
    }

    $candidateTimestamp = strtotime((string) ($candidate['responded_at'] ?? $candidate['created_at'] ?? '')) ?: 0;
    $currentTimestamp = strtotime((string) ($current['responded_at'] ?? $current['created_at'] ?? '')) ?: 0;

    if ($candidateTimestamp !== $currentTimestamp) {
        return $candidateTimestamp <=> $currentTimestamp;
    }

    return ((int) ($candidate['id'] ?? 0)) <=> ((int) ($current['id'] ?? 0));
}

function friendshipStatuses(int $currentUserId): array
{
    $stmt = db()->prepare(
        'SELECT id, sender_id, recipient_id, status, created_at, responded_at
         FROM friend_requests
         WHERE sender_id = :id OR recipient_id = :id'
    );
    $stmt->execute(['id' => $currentUserId]);

    $statuses = [];

    foreach ($stmt->fetchAll() as $request) {
        $senderId = (int) $request['sender_id'];
        $recipientId = (int) $request['recipient_id'];
        $otherUserId = $senderId === $currentUserId ? $recipientId : $senderId;
        $existing = $statuses[$otherUserId] ?? null;

        if ($existing !== null && compareFriendshipPriority($request, $existing) <= 0) {
            continue;
        }

        $status = (string) $request['status'];
        $statuses[$otherUserId] = [
            'friendship_id' => (int) $request['id'],
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'status' => $status,
            'created_at' => (string) $request['created_at'],
            'responded_at' => $request['responded_at'],
            'friendship_status' => $status,
            'can_chat' => $status === 'accepted',
            'request_direction' => $senderId === $currentUserId ? 'outgoing' : 'incoming',
        ];
    }

    return $statuses;
}

function decorateUsersWithFriendship(array $users, int $currentUserId): array
{
    $statuses = friendshipStatuses($currentUserId);

    return array_map(static function (array $user) use ($statuses): array {
        $friendship = $statuses[(int) $user['id']] ?? [
            'friendship_id' => null,
            'friendship_status' => 'none',
            'can_chat' => false,
            'request_direction' => null,
        ];

        return array_merge($user, $friendship);
    }, $users);
}

function friendshipRecord(int $userId, int $otherUserId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM friend_requests
         WHERE (sender_id = :user_id AND recipient_id = :other_user_id)
            OR (sender_id = :other_user_id AND recipient_id = :user_id)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'other_user_id' => $otherUserId,
    ]);

    $request = null;

    foreach ($stmt->fetchAll() as $candidate) {
        if ($request !== null && compareFriendshipPriority($candidate, $request) <= 0) {
            continue;
        }

        $request = $candidate;
    }

    if ($request === null) {
        return null;
    }

    $senderId = (int) $request['sender_id'];

    return [
        'id' => (int) $request['id'],
        'sender_id' => $senderId,
        'recipient_id' => (int) $request['recipient_id'],
        'status' => (string) $request['status'],
        'created_at' => (string) $request['created_at'],
        'responded_at' => $request['responded_at'],
        'can_chat' => (string) $request['status'] === 'accepted',
        'request_direction' => $senderId === $userId ? 'outgoing' : 'incoming',
    ];
}

function canUsersChat(int $userId, int $otherUserId): bool
{
    $friendship = friendshipRecord($userId, $otherUserId);

    return $friendship !== null && $friendship['status'] === 'accepted';
}

function sendFriendRequest(int $senderId, int $recipientId): ?string
{
    if ($senderId === $recipientId) {
        return 'You cannot add yourself.';
    }

    $friendship = friendshipRecord($senderId, $recipientId);

    if ($friendship !== null) {
        if ($friendship['status'] === 'accepted') {
            return 'You are already friends and can start chatting.';
        }

        if ($friendship['status'] === 'pending') {
            if ($friendship['request_direction'] === 'outgoing') {
                return 'Friend request already sent.';
            }

            return 'This user already asked to add you. Accept the request from your notifications.';
        }
    }

    $params = [
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'status' => 'pending',
        'created_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO friend_requests (sender_id, recipient_id, status, created_at, responded_at)
             VALUES (:sender_id, :recipient_id, :status, :created_at, NULL)
             ON DUPLICATE KEY UPDATE status = VALUES(status),
                                     created_at = VALUES(created_at),
                                     responded_at = NULL'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO friend_requests (sender_id, recipient_id, status, created_at, responded_at)
             VALUES (:sender_id, :recipient_id, :status, :created_at, NULL)
             ON CONFLICT(sender_id, recipient_id)
             DO UPDATE SET status = excluded.status,
                           created_at = excluded.created_at,
                           responded_at = NULL'
        );
    }

    $stmt->execute($params);

    return null;
}

function respondToFriendRequest(int $currentUserId, int $otherUserId, string $response): ?string
{
    if (!in_array($response, ['accepted', 'rejected'], true)) {
        return 'Unsupported response.';
    }

    $friendship = friendshipRecord($currentUserId, $otherUserId);

    if ($friendship === null || $friendship['status'] !== 'pending' || $friendship['request_direction'] !== 'incoming') {
        return 'Friend request not found.';
    }

    $stmt = db()->prepare(
        'UPDATE friend_requests
         SET status = :status,
             responded_at = :responded_at
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $response,
        'responded_at' => gmdate('c'),
        'id' => $friendship['id'],
    ]);

    return null;
}

function revokeFriendship(int $currentUserId, int $otherUserId): ?string
{
    $friendship = friendshipRecord($currentUserId, $otherUserId);

    if ($friendship === null || $friendship['status'] !== 'accepted') {
        return 'Friendship not found.';
    }

    $stmt = db()->prepare(
        'UPDATE friend_requests
         SET status = :status,
             responded_at = :responded_at
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => 'revoked',
        'responded_at' => gmdate('c'),
        'id' => $friendship['id'],
    ]);

    clearTypingStatus($currentUserId, $otherUserId);
    clearTypingStatus($otherUserId, $currentUserId);

    return null;
}

function incomingFriendRequests(int $currentUserId): array
{
    $stmt = db()->prepare(
        'SELECT fr.id,
                fr.sender_id,
                fr.recipient_id,
                fr.status,
                fr.created_at,
                fr.responded_at,
                u.username AS sender_name,
                up.updated_at AS presence_updated_at
         FROM friend_requests fr
         JOIN users u ON u.id = fr.sender_id
         LEFT JOIN user_presence up ON up.user_id = u.id
         WHERE fr.recipient_id = :id
            OR fr.sender_id = :id'
    );
    $stmt->execute([
        'id' => $currentUserId,
    ]);

    $requestsBySender = [];

    foreach ($stmt->fetchAll() as $request) {
        $senderId = (int) $request['sender_id'];
        $recipientId = (int) $request['recipient_id'];
        $otherUserId = $senderId === $currentUserId ? $recipientId : $senderId;
        $existing = $requestsBySender[$otherUserId] ?? null;

        if ($existing !== null && compareFriendshipPriority($request, $existing) <= 0) {
            continue;
        }

        $requestsBySender[$otherUserId] = $request;
    }

    $requests = array_filter($requestsBySender, static function (array $request) use ($currentUserId): bool {
        return (int) $request['recipient_id'] === $currentUserId && (string) $request['status'] === 'pending';
    });

    usort($requests, static function (array $left, array $right): int {
        $createdAtComparison = strtotime((string) $right['created_at']) <=> strtotime((string) $left['created_at']);

        if ($createdAtComparison !== 0) {
            return $createdAtComparison;
        }

        return ((int) $right['id']) <=> ((int) $left['id']);
    });

    return array_map(static function (array $request): array {
        $request['id'] = (int) $request['id'];
        $request['sender_id'] = (int) $request['sender_id'];
        $request['recipient_id'] = (int) $request['recipient_id'];
        $request['is_online'] = isset($request['presence_updated_at'])
            && strtotime((string) $request['presence_updated_at']) !== false
            && strtotime((string) $request['presence_updated_at']) >= (time() - PRESENCE_TTL_SECONDS);
        $request['presence_label'] = presenceLabel($request['presence_updated_at'] ?? null);
        $request['created_at_label'] = gmdate('Y-m-d H:i:s', strtotime((string) $request['created_at'])) . ' UTC';

        return $request;
    }, $requests);
}

function friendRequestsPayload(int $currentUserId): array
{
    return [
        'incoming_requests' => incomingFriendRequests($currentUserId),
    ];
}

function chattedUsers(int $currentUserId): array
{
    $users = allOtherUsers($currentUserId);

    $chatUsers = array_values(array_filter($users, static function (array $user): bool {
        return !empty($user['can_chat']) || !empty($user['last_message_at']);
    }));

    usort($chatUsers, static function (array $left, array $right): int {
        $leftHasMessages = !empty($left['last_message_at']);
        $rightHasMessages = !empty($right['last_message_at']);

        if ($leftHasMessages !== $rightHasMessages) {
            return $rightHasMessages <=> $leftHasMessages;
        }

        $leftTimestamp = $leftHasMessages ? (strtotime((string) $left['last_message_at']) ?: 0) : 0;
        $rightTimestamp = $rightHasMessages ? (strtotime((string) $right['last_message_at']) ?: 0) : 0;

        if ($leftTimestamp !== $rightTimestamp) {
            return $rightTimestamp <=> $leftTimestamp;
        }

        $leftMessageId = (int) ($left['last_message_id'] ?? 0);
        $rightMessageId = (int) ($right['last_message_id'] ?? 0);

        if ($leftMessageId !== $rightMessageId) {
            return $rightMessageId <=> $leftMessageId;
        }

        return strcasecmp((string) ($left['username'] ?? ''), (string) ($right['username'] ?? ''));
    });

    return $chatUsers;
}

function allOtherUsers(int $currentUserId): array
{
    $stmt = db()->prepare(
        'SELECT u.id,
                u.username,
                u.created_at,
                up.updated_at AS presence_updated_at,
                COUNT(DISTINCT m_unseen.id) AS unseen_count,
                MAX(m_latest.created_at) AS last_message_at,
                MAX(m_latest.id) AS last_message_id
         FROM users u
         LEFT JOIN user_presence up ON up.user_id = u.id
         LEFT JOIN messages m_latest
            ON ((m_latest.sender_id = :id AND m_latest.recipient_id = u.id)
             OR (m_latest.sender_id = u.id AND m_latest.recipient_id = :id))
         LEFT JOIN messages m_unseen ON m_unseen.sender_id = u.id
            AND m_unseen.recipient_id = :id
            AND m_unseen.read_at IS NULL
         WHERE u.id != :id
         GROUP BY u.id, u.username, u.created_at, up.updated_at
         ORDER BY CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END ASC,
                  last_message_at DESC,
                  last_message_id DESC,
                  username ASC'
    );
    $stmt->execute(['id' => $currentUserId]);

    return decorateUsersWithFriendship(mapUsersWithPresence($stmt->fetchAll()), $currentUserId);
}

function chatListPayload(int $currentUserId): array
{
    purgeExpiredMessages();
    touchUserPresence($currentUserId);

    return [
        'chat_users' => chattedUsers($currentUserId),
        'directory_users' => allOtherUsers($currentUserId),
        'incoming_requests' => incomingFriendRequests($currentUserId),
    ];
}

function chatListStateSignature(int $currentUserId): string
{
    purgeExpiredMessages();

    $usersStmt = db()->prepare(
        'SELECT COUNT(*) AS total_users,
                MAX(id) AS latest_user_id,
                MAX(created_at) AS latest_user_created_at
         FROM users
         WHERE id != :id'
    );
    $usersStmt->execute(['id' => $currentUserId]);
    $usersState = $usersStmt->fetch() ?: [];

    $messagesStmt = db()->prepare(
        'SELECT COUNT(*) AS total_messages,
                MAX(id) AS latest_message_id,
                MAX(created_at) AS latest_message_created_at,
                MAX(delivered_at) AS latest_message_delivered_at,
                MAX(read_at) AS latest_message_read_at
         FROM messages
         WHERE sender_id = :id OR recipient_id = :id'
    );
    $messagesStmt->execute(['id' => $currentUserId]);
    $messagesState = $messagesStmt->fetch() ?: [];

    $presenceStmt = db()->prepare(
        'SELECT COUNT(*) AS total_presence_rows,
                MAX(updated_at) AS latest_presence_updated_at,
                SUM(CASE WHEN updated_at >= :cutoff THEN 1 ELSE 0 END) AS online_user_count
         FROM user_presence
         WHERE user_id != :id'
    );
    $presenceStmt->execute([
        'id' => $currentUserId,
        'cutoff' => gmdate('c', time() - PRESENCE_TTL_SECONDS),
    ]);
    $presenceState = $presenceStmt->fetch() ?: [];

    $friendshipsStmt = db()->prepare(
        'SELECT COUNT(*) AS total_friend_requests,
                MAX(id) AS latest_friend_request_id,
                MAX(created_at) AS latest_friend_request_created_at,
                MAX(responded_at) AS latest_friend_request_responded_at,
                SUM(CASE WHEN status = \'accepted\' THEN 1 ELSE 0 END) AS accepted_count,
                SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = \'rejected\' THEN 1 ELSE 0 END) AS rejected_count,
                SUM(CASE WHEN status = \'revoked\' THEN 1 ELSE 0 END) AS revoked_count
         FROM friend_requests
         WHERE sender_id = :id OR recipient_id = :id'
    );
    $friendshipsStmt->execute(['id' => $currentUserId]);
    $friendshipsState = $friendshipsStmt->fetch() ?: [];

    return md5(encodeJson([
        'users' => [
            'total' => (int) ($usersState['total_users'] ?? 0),
            'latest_id' => (int) ($usersState['latest_user_id'] ?? 0),
            'latest_created_at' => $usersState['latest_user_created_at'] ?? null,
        ],
        'messages' => [
            'total' => (int) ($messagesState['total_messages'] ?? 0),
            'latest_id' => (int) ($messagesState['latest_message_id'] ?? 0),
            'latest_created_at' => $messagesState['latest_message_created_at'] ?? null,
            'latest_delivered_at' => $messagesState['latest_message_delivered_at'] ?? null,
            'latest_read_at' => $messagesState['latest_message_read_at'] ?? null,
        ],
        'presence' => [
            'total' => (int) ($presenceState['total_presence_rows'] ?? 0),
            'latest_updated_at' => $presenceState['latest_presence_updated_at'] ?? null,
            'online_user_count' => (int) ($presenceState['online_user_count'] ?? 0),
        ],
        'friend_requests' => [
            'total' => (int) ($friendshipsState['total_friend_requests'] ?? 0),
            'latest_id' => (int) ($friendshipsState['latest_friend_request_id'] ?? 0),
            'latest_created_at' => $friendshipsState['latest_friend_request_created_at'] ?? null,
            'latest_responded_at' => $friendshipsState['latest_friend_request_responded_at'] ?? null,
            'accepted_count' => (int) ($friendshipsState['accepted_count'] ?? 0),
            'pending_count' => (int) ($friendshipsState['pending_count'] ?? 0),
            'rejected_count' => (int) ($friendshipsState['rejected_count'] ?? 0),
            'revoked_count' => (int) ($friendshipsState['revoked_count'] ?? 0),
        ],
    ]));
}

function findUserByUsername(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => trim($username)]);

    return $stmt->fetch() ?: null;
}

function findUserById(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.created_at, up.updated_at AS presence_updated_at
         FROM users u
         LEFT JOIN user_presence up ON up.user_id = u.id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);

    $user = $stmt->fetch() ?: null;

    if ($user !== null) {
        $user['is_online'] = isset($user['presence_updated_at'])
            && strtotime((string) $user['presence_updated_at']) !== false
            && strtotime((string) $user['presence_updated_at']) >= (time() - PRESENCE_TTL_SECONDS);
        $user['presence_label'] = presenceLabel($user['presence_updated_at'] ?? null);
    }

    return $user;
}

function registerUser(string $username, string $password): ?string
{
    $username = trim($username);

    if ($username === '' || strlen($username) < 3) {
        return 'Username must be at least 3 characters.';
    }

    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }

    if (findUserByUsername($username) !== null) {
        return 'That username is already taken.';
    }

    $stmt = db()->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (:username, :password_hash, :created_at)');
    $stmt->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => gmdate('c'),
    ]);

    return null;
}

function loginUser(string $username, string $password): ?string
{
    $user = findUserByUsername($username);

    if ($user === null || !password_verify($password, $user['password_hash'])) {
        return 'Invalid username or password.';
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    csrfToken();

    return null;
}

function formatMessage(array $message): array
{
    return [
        'id' => (int) $message['id'],
        'sender_id' => (int) $message['sender_id'],
        'recipient_id' => (int) $message['recipient_id'],
        'sender_name' => $message['sender_name'],
        'body' => $message['body'],
        'audio_path' => $message['audio_path'],
        'image_path' => $message['image_path'] ?? null,
        'delivered_at' => $message['delivered_at'] ?? null,
        'read_at' => $message['read_at'] ?? null,
        'created_at' => $message['created_at'],
        'created_at_label' => gmdate('Y-m-d H:i:s', strtotime($message['created_at'])) . ' UTC',
    ];
}

function findMessageById(int $messageId): ?array
{
    $stmt = db()->prepare(
        'SELECT m.*, u.username AS sender_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $messageId]);

    $message = $stmt->fetch();

    return $message === false ? null : formatMessage($message);
}

function conversationMessages(int $userId, int $otherUserId): array
{
    purgeExpiredMessages();

    return conversationMessagesWithoutMaintenance($userId, $otherUserId);
}

function conversationExistsForUser(int $userId, int $otherUserId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM messages
         WHERE ((sender_id = :user_id AND recipient_id = :other_id)
            OR (sender_id = :other_id AND recipient_id = :user_id))
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
        'other_id' => $otherUserId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function canAccessConversation(int $userId, int $otherUserId): bool
{
    return canUsersChat($userId, $otherUserId)
        || friendshipRecord($userId, $otherUserId) !== null
        || conversationExistsForUser($userId, $otherUserId);
}

function conversationMessagesWithoutMaintenance(int $userId, int $otherUserId): array
{
    return conversationMessagesPageWithoutMaintenance($userId, $otherUserId);
}

function conversationMessagesPageWithoutMaintenance(int $userId, int $otherUserId, int $limit = 0, ?int $beforeMessageId = null): array
{
    $sql = 'SELECT m.*, u.username AS sender_name
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE ((sender_id = :user_id AND recipient_id = :other_id)
               OR (sender_id = :other_id AND recipient_id = :user_id))';

    $params = [
        'user_id' => $userId,
        'other_id' => $otherUserId,
    ];

    if ($beforeMessageId !== null && $beforeMessageId > 0) {
        $sql .= ' AND m.id < :before_message_id';
        $params['before_message_id'] = $beforeMessageId;
    }

    if ($limit > 0) {
        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT :limit';
    } else {
        $sql .= ' ORDER BY m.created_at ASC, m.id ASC';
    }

    $stmt = db()->prepare($sql);

    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }

    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }

    $stmt->execute();
    $messages = $stmt->fetchAll();

    if ($limit > 0) {
        $messages = array_reverse($messages);
    }

    return array_map(static fn (array $message): array => formatMessage($message), $messages);
}

function sendTextMessage(int $senderId, int $recipientId, string $body): array|string|null
{
    purgeExpiredMessages();

    if (!canUsersChat($senderId, $recipientId)) {
        return 'You can only message users after they accept your friend request.';
    }

    $body = trim($body);
    if ($body === '') {
        return 'Message cannot be empty.';
    }

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, created_at)
         VALUES (:sender_id, :recipient_id, :body, NULL, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'body' => $body,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);

    return findMessageById((int) db()->lastInsertId());
}

function detectUploadedAudioExtension(array $file, ?string $mime): ?string
{
    $allowedMimeTypes = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/vnd.wave' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'video/webm' => 'webm',
        'video/webm;codecs=opus' => 'webm',
        'video/ogg' => 'ogg',
        'video/ogg;codecs=opus' => 'ogg',
        'video/mp4' => 'm4a',
        'application/octet-stream' => null,
    ];

    if ($mime !== null && isset($allowedMimeTypes[$mime]) && $allowedMimeTypes[$mime] !== null) {
        return $allowedMimeTypes[$mime];
    }

    $clientName = strtolower((string) ($file['name'] ?? ''));
    $clientType = strtolower((string) ($file['type'] ?? ''));

    foreach ([$clientType, $mime] as $type) {
        if ($type !== null && isset($allowedMimeTypes[$type]) && $allowedMimeTypes[$type] !== null) {
            return $allowedMimeTypes[$type];
        }
    }

    $extension = pathinfo($clientName, PATHINFO_EXTENSION);
    $allowedExtensions = ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'mp4'];
    if (in_array($extension, $allowedExtensions, true)) {
        return $extension === 'mp4' ? 'm4a' : $extension;
    }

    return null;
}

function detectUploadedImageExtension(array $file, ?string $mime): ?string
{
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/octet-stream' => null,
    ];

    if ($mime !== null && isset($allowedMimeTypes[$mime]) && $allowedMimeTypes[$mime] !== null) {
        return $allowedMimeTypes[$mime];
    }

    $clientName = strtolower((string) ($file['name'] ?? ''));
    $clientType = strtolower((string) ($file['type'] ?? ''));

    foreach ([$clientType, $mime] as $type) {
        if ($type !== null && isset($allowedMimeTypes[$type]) && $allowedMimeTypes[$type] !== null) {
            return $allowedMimeTypes[$type];
        }
    }

    $extension = strtolower((string) pathinfo($clientName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];
    if (in_array($extension, $allowedExtensions, true)) {
        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    return null;
}

function sendImageMessage(int $senderId, int $recipientId, array $file): array|string|null
{
    purgeExpiredMessages();

    if (!canUsersChat($senderId, $recipientId)) {
        return 'You can only message users after they accept your friend request.';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Image upload failed. Please attach a photo or image file.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: null;
    $extension = detectUploadedImageExtension($file, $mime);

    if ($extension === null) {
        return 'Unsupported image type. Use jpg, png, gif, webp, heic, or heif.';
    }

    if (($file['size'] ?? 0) > 12 * 1024 * 1024) {
        return 'Image must be 12MB or smaller.';
    }

    $filename = sprintf('img_%s_%s.%s', $senderId, bin2hex(random_bytes(8)), $extension);
    $target = TMP_UPLOAD_PATH . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Could not save the image.';
    }

    $relativePath = 'storage/tmp/' . $filename;

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, image_path, created_at)
         VALUES (:sender_id, :recipient_id, NULL, NULL, :image_path, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'image_path' => $relativePath,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);

    return findMessageById((int) db()->lastInsertId());
}

function sendVoiceMessage(int $senderId, int $recipientId, array $file): array|string|null
{
    purgeExpiredMessages();

    if (!canUsersChat($senderId, $recipientId)) {
        return 'You can only message users after they accept your friend request.';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Voice upload failed. Please attach an audio file.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: null;
    $extension = detectUploadedAudioExtension($file, $mime);

    if ($extension === null) {
        return 'Unsupported audio type. Use mp3, wav, ogg, webm, or m4a (including browser-recorded webm/ogg notes).';
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return 'Voice note must be 10MB or smaller.';
    }

    $filename = sprintf('%s_%s.%s', $senderId, bin2hex(random_bytes(8)), $extension);
    $target = UPLOAD_PATH . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Could not save the voice note.';
    }

    $relativePath = 'storage/uploads/' . $filename;

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, created_at)
         VALUES (:sender_id, :recipient_id, NULL, :audio_path, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'audio_path' => $relativePath,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);

    return findMessageById((int) db()->lastInsertId());
}

function updateTypingStatus(int $userId, int $otherUserId): void
{
    purgeExpiredMessages();

    if (!canUsersChat($userId, $otherUserId)) {
        return;
    }

    $params = [
        'user_id' => $userId,
        'conversation_user_id' => $otherUserId,
        'updated_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO typing_status (user_id, conversation_user_id, updated_at)
             VALUES (:user_id, :conversation_user_id, :updated_at)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO typing_status (user_id, conversation_user_id, updated_at)
             VALUES (:user_id, :conversation_user_id, :updated_at)
             ON CONFLICT(user_id, conversation_user_id)
             DO UPDATE SET updated_at = excluded.updated_at'
        );
    }

    $stmt->execute($params);
}

function clearTypingStatus(int $userId, int $otherUserId): void
{
    $stmt = db()->prepare(
        'DELETE FROM typing_status WHERE user_id = :user_id AND conversation_user_id = :conversation_user_id'
    );

    $stmt->execute([
        'user_id' => $userId,
        'conversation_user_id' => $otherUserId,
    ]);
}

function isUserTyping(int $userId, int $otherUserId): bool
{
    purgeExpiredMessages();

    return isUserTypingWithoutMaintenance($userId, $otherUserId);
}

function isUserTypingWithoutMaintenance(int $userId, int $otherUserId): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM typing_status
         WHERE user_id = :user_id
           AND conversation_user_id = :conversation_user_id
           AND updated_at >= :cutoff
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $otherUserId,
        'conversation_user_id' => $userId,
        'cutoff' => gmdate('c', time() - TYPING_TTL_SECONDS),
    ]);

    return (bool) $stmt->fetchColumn();
}

function conversationPayload(int $userId, int $otherUserId, int $limit = 0, ?int $beforeMessageId = null): array
{
    purgeExpiredMessages();

    touchUserPresence($userId);
    $allowed = canUsersChat($userId, $otherUserId);

    if ($allowed) {
        markMessagesDelivered($userId, $otherUserId);
    }

    $otherUser = findUserById($otherUserId);
    $messages = conversationMessagesPageWithoutMaintenance($userId, $otherUserId, $limit, $beforeMessageId);
    $oldestLoadedId = $messages === [] ? null : (int) ($messages[0]['id'] ?? 0);

    return [
        'messages' => $messages,
        'has_more_messages' => $oldestLoadedId !== null && $oldestLoadedId > 0
            ? conversationHasOlderMessagesWithoutMaintenance($userId, $otherUserId, $oldestLoadedId)
            : false,
        'typing' => $allowed ? isUserTypingWithoutMaintenance($userId, $otherUserId) : false,
        'can_chat' => $allowed,
        'friendship' => friendshipRecord($userId, $otherUserId),
        'presence' => [
            'is_online' => (bool) ($otherUser['is_online'] ?? false),
            'label' => $otherUser['presence_label'] ?? 'Offline',
        ],
    ];
}

function conversationHasOlderMessagesWithoutMaintenance(int $userId, int $otherUserId, int $beforeMessageId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM messages
         WHERE (((sender_id = :user_id AND recipient_id = :other_id)
            OR (sender_id = :other_id AND recipient_id = :user_id)))
           AND id < :before_message_id
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
        'other_id' => $otherUserId,
        'before_message_id' => $beforeMessageId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function conversationStateSignature(int $userId, int $otherUserId): string
{
    purgeExpiredMessages();

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS total_messages,
                MAX(id) AS latest_message_id,
                MAX(created_at) AS latest_message_created_at,
                MAX(delivered_at) AS latest_message_delivered_at,
                MAX(read_at) AS latest_message_read_at
         FROM messages
         WHERE (sender_id = :user_id AND recipient_id = :other_user_id)
            OR (sender_id = :other_user_id AND recipient_id = :user_id)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'other_user_id' => $otherUserId,
    ]);
    $messageState = $stmt->fetch() ?: [];

    $friendship = friendshipRecord($userId, $otherUserId);
    $otherUser = findUserById($otherUserId);

    return md5(encodeJson([
        'messages' => [
            'total' => (int) ($messageState['total_messages'] ?? 0),
            'latest_id' => (int) ($messageState['latest_message_id'] ?? 0),
            'latest_created_at' => $messageState['latest_message_created_at'] ?? null,
            'latest_delivered_at' => $messageState['latest_message_delivered_at'] ?? null,
            'latest_read_at' => $messageState['latest_message_read_at'] ?? null,
        ],
        'typing' => isUserTypingWithoutMaintenance($userId, $otherUserId),
        'friendship' => $friendship === null ? null : [
            'id' => (int) $friendship['id'],
            'status' => (string) $friendship['status'],
            'responded_at' => $friendship['responded_at'],
            'created_at' => (string) $friendship['created_at'],
        ],
        'presence' => [
            'is_online' => (bool) ($otherUser['is_online'] ?? false),
            'label' => $otherUser['presence_label'] ?? 'Offline',
        ],
    ]));
}

function markMessagesDelivered(int $userId, int $otherUserId): void
{
    $stmt = db()->prepare(
        'UPDATE messages
         SET delivered_at = COALESCE(delivered_at, :delivered_at)
         WHERE sender_id = :other_user_id
           AND recipient_id = :user_id
           AND delivered_at IS NULL'
    );
    $stmt->execute([
        'delivered_at' => gmdate('c'),
        'other_user_id' => $otherUserId,
        'user_id' => $userId,
    ]);
}

function markMessagesRead(int $userId, int $otherUserId): void
{
    $stmt = db()->prepare(
        'UPDATE messages
         SET delivered_at = COALESCE(delivered_at, :read_at),
             read_at = COALESCE(read_at, :read_at)
         WHERE sender_id = :other_user_id
           AND recipient_id = :user_id
           AND read_at IS NULL'
    );
    $stmt->execute([
        'read_at' => gmdate('c'),
        'other_user_id' => $otherUserId,
        'user_id' => $userId,
    ]);
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo encodeJson($payload);
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
