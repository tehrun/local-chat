<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('TMP_UPLOAD_PATH', STORAGE_PATH . '/tmp');
define('DB_PATH', STORAGE_PATH . '/db/chat.sqlite');
define('DEFAULT_DB_HOST', '127.0.0.1');
define('DEFAULT_DB_PORT', '3306');
define('MESSAGE_RETENTION_TTL_SECONDS', 7 * 24 * 60 * 60);
define('MEDIA_RETENTION_TTL_SECONDS', 24 * 60 * 60);
define('TYPING_TTL_SECONDS', 8);
define('PRESENCE_TTL_SECONDS', 90);
define('PRESENCE_UPDATE_INTERVAL_SECONDS', 30);
define('PURGE_INTERVAL_SECONDS', 30);
define('SESSION_TTL_SECONDS', 30 * 24 * 60 * 60);
define('WEB_PUSH_KEY_PATH', STORAGE_PATH . '/webpush');
define('MESSAGE_ENCRYPTION_KEY_PATH', STORAGE_PATH . '/message-encryption.key');
define('WEB_PUSH_AUDIENCE_TTL_SECONDS', 12 * 60 * 60);
define('SESSION_BACKEND_NAME', 'database');

ensureStorageDirectories();
configureSession();
installSessionHandler();
session_start();

applySecurityHeaders();


function ensureStorageDirectories(): void
{
    if (!is_dir(dirname(DB_PATH))) {
        mkdir(dirname(DB_PATH), 0777, true);
    }

    if (!is_dir(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0777, true);
    }

    if (!is_dir(TMP_UPLOAD_PATH)) {
        mkdir(TMP_UPLOAD_PATH, 0777, true);
    }

    if (!is_dir(WEB_PUSH_KEY_PATH)) {
        mkdir(WEB_PUSH_KEY_PATH, 0777, true);
    }
}

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


function installSessionHandler(): void
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

function sessionDiagnostics(): array
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

function applySecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), camera=(), payment=(), usb=(), autoplay=(self)');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('X-Download-Options: noopen');
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
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

function deleteStorageFileIfExists(?string $relativePath): void
{
    if (!isSafeStorageRelativePath($relativePath)) {
        return;
    }

    $absolutePath = BASE_PATH . '/' . ltrim((string) $relativePath, '/');
    if (!is_file($absolutePath)) {
        return;
    }

    @unlink($absolutePath);
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

function messageEncryptionKey(): string
{
    static $key = null;

    if (is_string($key)) {
        return $key;
    }

    $configured = trim((string) envValue('CHAT_MESSAGE_ENCRYPTION_KEY', ''));
    if ($configured !== '') {
        $decoded = base64_decode($configured, true);
        if (is_string($decoded) && strlen($decoded) >= 32) {
            $key = substr($decoded, 0, 32);
            return $key;
        }

        if (strlen($configured) >= 32) {
            $key = substr($configured, 0, 32);
            return $key;
        }
    }

    if (is_file(MESSAGE_ENCRYPTION_KEY_PATH)) {
        $stored = trim((string) file_get_contents(MESSAGE_ENCRYPTION_KEY_PATH));
        $decoded = base64_decode($stored, true);
        if (is_string($decoded) && strlen($decoded) >= 32) {
            $key = substr($decoded, 0, 32);
            return $key;
        }
    }

    $generated = random_bytes(32);
    file_put_contents(MESSAGE_ENCRYPTION_KEY_PATH, base64_encode($generated), LOCK_EX);
    @chmod(MESSAGE_ENCRYPTION_KEY_PATH, 0600);
    $key = $generated;

    return $key;
}

function encryptStoredMessageText(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    $key = messageEncryptionKey();

    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);

        return 'enc::xchacha20:' . base64_encode($nonce . $ciphertext);
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        return $plaintext;
    }

    return 'enc::aes256gcm:' . base64_encode($nonce . $tag . $ciphertext);
}

function decryptStoredMessageText(?string $ciphertext): ?string
{
    if (!is_string($ciphertext) || $ciphertext === '') {
        return $ciphertext;
    }

    if (!str_starts_with($ciphertext, 'enc::')) {
        return $ciphertext;
    }

    $parts = explode(':', $ciphertext, 4);
    if (count($parts) !== 4) {
        return '[encrypted message]';
    }

    $algorithm = $parts[2];
    $payload = base64_decode($parts[3], true);
    if (!is_string($payload)) {
        return '[encrypted message]';
    }

    $key = messageEncryptionKey();

    if ($algorithm === 'xchacha20' && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($payload) <= $nonceLength) {
            return '[encrypted message]';
        }
        $nonce = substr($payload, 0, $nonceLength);
        $encrypted = substr($payload, $nonceLength);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($encrypted, '', $nonce, $key);

        return is_string($plaintext) ? $plaintext : '[encrypted message]';
    }

    if ($algorithm === 'aes256gcm') {
        if (strlen($payload) <= 28) {
            return '[encrypted message]';
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $encrypted = substr($payload, 28);
        $plaintext = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return is_string($plaintext) ? $plaintext : '[encrypted message]';
    }

    return '[encrypted message]';
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
            file_path TEXT,
            file_name TEXT,
            attachment_expired INTEGER NOT NULL DEFAULT 0,
            reply_to_message_id INTEGER,
            delivered_at TEXT,
            read_at TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(sender_id) REFERENCES users(id),
            FOREIGN KEY(recipient_id) REFERENCES users(id)
        )'
    );

    $columns = array_map(
        static fn (array $column): string => (string) $column['name'],
        $pdo->query('PRAGMA table_info(messages)')->fetchAll()
    );

    if (!in_array('image_path', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN image_path TEXT');
    }

    if (!in_array('file_path', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN file_path TEXT');
    }

    if (!in_array('file_name', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN file_name TEXT');
    }

    if (!in_array('attachment_expired', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN attachment_expired INTEGER NOT NULL DEFAULT 0');
    }

    if (!in_array('reply_to_message_id', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN reply_to_message_id INTEGER');
    }

    if (!in_array('delivered_at', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN delivered_at TEXT');
    }

    if (!in_array('read_at', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN read_at TEXT');
    }

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
        'CREATE TABLE IF NOT EXISTS conversation_clears (
            user_id INTEGER NOT NULL,
            conversation_user_id INTEGER NOT NULL,
            cleared_at TEXT NOT NULL,
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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_reactions (
            message_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            emoji TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (message_id, user_id),
            FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_message_reactions_updated
         ON message_reactions (message_id, updated_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_deletions (
            message_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            deleted_at TEXT NOT NULL,
            PRIMARY KEY (message_id, user_id),
            FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_message_deletions_user
         ON message_deletions (user_id, deleted_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_deletions (
            message_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            deleted_at TEXT NOT NULL,
            PRIMARY KEY (message_id, user_id),
            FOREIGN KEY(message_id) REFERENCES group_messages(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_group_message_deletions_user
         ON group_message_deletions (user_id, deleted_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            expires_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_sessions_expires_at
         ON sessions (expires_at)'
    );

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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            content_encoding TEXT NOT NULL DEFAULT \'aes128gcm\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user
         ON push_subscriptions (user_id, updated_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            creator_user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            deleted_at TEXT,
            FOREIGN KEY(creator_user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_groups_creator_created
         ON groups (creator_user_id, created_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_members (
            group_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            invited_by_user_id INTEGER,
            role TEXT NOT NULL DEFAULT \'member\',
            status TEXT NOT NULL DEFAULT \'active\',
            created_at TEXT NOT NULL,
            joined_at TEXT,
            left_at TEXT,
            PRIMARY KEY (group_id, user_id),
            FOREIGN KEY(group_id) REFERENCES groups(id),
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(invited_by_user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_group_members_user_status
         ON group_members (user_id, status, group_id)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            body TEXT,
            audio_path TEXT,
            image_path TEXT,
            file_path TEXT,
            file_name TEXT,
            reply_to_message_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY(group_id) REFERENCES groups(id),
            FOREIGN KEY(sender_id) REFERENCES users(id)
        )'
    );

    $groupMessageColumns = array_map(
        static fn (array $column): string => (string) $column['name'],
        $pdo->query('PRAGMA table_info(group_messages)')->fetchAll()
    );
    if (!in_array('reply_to_message_id', $groupMessageColumns, true)) {
        $pdo->exec('ALTER TABLE group_messages ADD COLUMN reply_to_message_id INTEGER');
    }
    if (!in_array('file_path', $groupMessageColumns, true)) {
        $pdo->exec('ALTER TABLE group_messages ADD COLUMN file_path TEXT');
    }
    if (!in_array('file_name', $groupMessageColumns, true)) {
        $pdo->exec('ALTER TABLE group_messages ADD COLUMN file_name TEXT');
    }

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_group_messages_timeline
         ON group_messages (group_id, created_at, id)'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_reactions (
            message_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            emoji TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (message_id, user_id),
            FOREIGN KEY(message_id) REFERENCES group_messages(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_group_message_reactions_updated
         ON group_message_reactions (message_id, updated_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_reads (
            group_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            last_read_message_id INTEGER,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (group_id, user_id),
            FOREIGN KEY(group_id) REFERENCES groups(id),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_typing_status (
            group_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (group_id, user_id),
            FOREIGN KEY(group_id) REFERENCES groups(id),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_group_typing_status_lookup
         ON group_typing_status (group_id, updated_at)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_conversation_clears (
            group_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            cleared_at TEXT NOT NULL,
            PRIMARY KEY (group_id, user_id),
            FOREIGN KEY(group_id) REFERENCES groups(id),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )'
    );
}

function mysqlTableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', str_replace('`', '``', $table)));

    return array_map(
        static fn (array $column): string => (string) ($column['Field'] ?? ''),
        $stmt ? $stmt->fetchAll() : []
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
            file_path VARCHAR(255) NULL,
            file_name VARCHAR(255) NULL,
            attachment_expired TINYINT(1) NOT NULL DEFAULT 0,
            reply_to_message_id BIGINT UNSIGNED NULL,
            delivered_at DATETIME NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_messages_conversation_time (sender_id, recipient_id, created_at, id),
            INDEX idx_messages_pending_delivery (recipient_id, sender_id, delivered_at, read_at, created_at, id),
            CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = mysqlTableColumns($pdo, 'messages');

    if (!in_array('image_path', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN image_path VARCHAR(255) NULL AFTER audio_path');
    }

    if (!in_array('file_path', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN file_path VARCHAR(255) NULL AFTER image_path');
    }

    if (!in_array('file_name', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN file_name VARCHAR(255) NULL AFTER file_path');
    }

    if (!in_array('attachment_expired', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN attachment_expired TINYINT(1) NOT NULL DEFAULT 0 AFTER file_name');
    }

    if (!in_array('reply_to_message_id', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN reply_to_message_id BIGINT UNSIGNED NULL AFTER attachment_expired');
    }

    if (!in_array('delivered_at', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN delivered_at DATETIME NULL AFTER reply_to_message_id');
    }

    if (!in_array('read_at', $columns, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN read_at DATETIME NULL AFTER delivered_at');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_reactions (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id),
            INDEX idx_message_reactions_updated (message_id, updated_at),
            CONSTRAINT fk_message_reactions_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_message_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_deletions (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            deleted_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id),
            INDEX idx_message_deletions_user (user_id, deleted_at),
            CONSTRAINT fk_message_deletions_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_message_deletions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
        'CREATE TABLE IF NOT EXISTS conversation_clears (
            user_id INT UNSIGNED NOT NULL,
            conversation_user_id INT UNSIGNED NOT NULL,
            cleared_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, conversation_user_id),
            CONSTRAINT fk_conversation_clears_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_conversation_clears_conversation_user FOREIGN KEY (conversation_user_id) REFERENCES users(id) ON DELETE CASCADE
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
        'CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_sessions_expires_at (expires_at)
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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS push_subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            endpoint VARCHAR(1024) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            content_encoding VARCHAR(32) NOT NULL DEFAULT \'aes128gcm\',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_push_subscriptions_endpoint (endpoint(255)),
            INDEX idx_push_subscriptions_user (user_id, updated_at),
            CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            creator_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            INDEX idx_groups_creator_created (creator_user_id, created_at),
            CONSTRAINT fk_groups_creator FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_members (
            group_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            invited_by_user_id INT UNSIGNED NULL,
            role VARCHAR(32) NOT NULL DEFAULT \'member\',
            status VARCHAR(32) NOT NULL DEFAULT \'active\',
            created_at DATETIME NOT NULL,
            joined_at DATETIME NULL,
            left_at DATETIME NULL,
            PRIMARY KEY (group_id, user_id),
            INDEX idx_group_members_user_status (user_id, status, group_id),
            CONSTRAINT fk_group_members_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_members_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            body LONGTEXT NULL,
            audio_path VARCHAR(255) NULL,
            image_path VARCHAR(255) NULL,
            file_path VARCHAR(255) NULL,
            file_name VARCHAR(255) NULL,
            reply_to_message_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_group_messages_timeline (group_id, created_at, id),
            CONSTRAINT fk_group_messages_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $groupColumns = mysqlTableColumns($pdo, 'group_messages');
    if (!in_array('reply_to_message_id', $groupColumns, true)) {
        $pdo->exec('ALTER TABLE group_messages ADD COLUMN reply_to_message_id BIGINT UNSIGNED NULL AFTER image_path');
    }
    if (!in_array('file_path', $groupColumns, true)) {
        $pdo->exec('ALTER TABLE group_messages ADD COLUMN file_path VARCHAR(255) NULL AFTER image_path');
    }
    if (!in_array('file_name', $groupColumns, true)) {
        $pdo->exec('ALTER TABLE group_messages ADD COLUMN file_name VARCHAR(255) NULL AFTER file_path');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_reactions (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id),
            INDEX idx_group_message_reactions_updated (message_id, updated_at),
            CONSTRAINT fk_group_message_reactions_message FOREIGN KEY (message_id) REFERENCES group_messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_message_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_deletions (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            deleted_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id),
            INDEX idx_group_message_deletions_user (user_id, deleted_at),
            CONSTRAINT fk_group_message_deletions_message FOREIGN KEY (message_id) REFERENCES group_messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_message_deletions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_reads (
            group_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (group_id, user_id),
            CONSTRAINT fk_group_reads_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_typing_status (
            group_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (group_id, user_id),
            INDEX idx_group_typing_status_lookup (group_id, updated_at),
            CONSTRAINT fk_group_typing_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_typing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_conversation_clears (
            group_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            cleared_at DATETIME NOT NULL,
            PRIMARY KEY (group_id, user_id),
            CONSTRAINT fk_group_clears_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_group_clears_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

function webPushPrivateKeyPath(): string
{
    return WEB_PUSH_KEY_PATH . '/vapid_private.pem';
}

function ensureWebPushKeyPair(): ?array
{
    static $cached = false;

    if (is_array($cached)) {
        return $cached;
    }

    $privatePem = trim((string) envValue('CHAT_WEB_PUSH_VAPID_PRIVATE_KEY_PEM', ''));
    if ($privatePem !== '') {
        $privateKey = openssl_pkey_get_private($privatePem);
        if ($privateKey === false) {
            return null;
        }
    } else {
        $path = webPushPrivateKeyPath();
        if (!is_file($path)) {
            $generated = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]);

            if ($generated === false) {
                return null;
            }

            $exported = openssl_pkey_export($generated, $privatePemOut);
            if (!$exported || $privatePemOut === '') {
                return null;
            }

            file_put_contents($path, $privatePemOut, LOCK_EX);
            @chmod($path, 0600);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($path) ?: '');
        if ($privateKey === false) {
            return null;
        }
    }

    $details = openssl_pkey_get_details($privateKey);
    if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
        return null;
    }

    $rawPublicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    $cached = [
        'private_key' => $privateKey,
        'public_key' => base64UrlEncode($rawPublicKey),
    ];

    return $cached;
}

function webPushPublicKey(): ?string
{
    $pair = ensureWebPushKeyPair();

    return is_array($pair) ? $pair['public_key'] : null;
}

function webPushEnabled(): bool
{
    return extension_loaded('openssl') && webPushPublicKey() !== null && function_exists('curl_init');
}

function defaultWebPushSubject(): string
{
    $configured = trim((string) envValue('CHAT_WEB_PUSH_SUBJECT', ''));
    if ($configured !== '') {
        return $configured;
    }

    $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));

    if ($host !== '') {
        return 'https://' . $host;
    }

    return 'https://example.com';
}

function normalizePushSubscription(array $subscription): ?array
{
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));
    $contentEncoding = trim((string) ($subscription['contentEncoding'] ?? 'aes128gcm'));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return null;
    }

    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        return null;
    }

    return [
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'content_encoding' => $contentEncoding !== '' ? $contentEncoding : 'aes128gcm',
    ];
}

function savePushSubscription(int $userId, array $subscription): bool
{
    $normalized = normalizePushSubscription($subscription);
    if ($normalized === null) {
        return false;
    }

    $params = [
        'user_id' => $userId,
        'endpoint' => $normalized['endpoint'],
        'p256dh' => $normalized['p256dh'],
        'auth' => $normalized['auth'],
        'content_encoding' => $normalized['content_encoding'],
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, content_encoding, created_at, updated_at)
             VALUES (:user_id, :endpoint, :p256dh, :auth, :content_encoding, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                content_encoding = VALUES(content_encoding),
                updated_at = VALUES(updated_at)'
        );

        return $stmt->execute($params);
    }

    $stmt = db()->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, content_encoding, created_at, updated_at)
         VALUES (:user_id, :endpoint, :p256dh, :auth, :content_encoding, :created_at, :updated_at)
         ON CONFLICT(endpoint)
         DO UPDATE SET
            user_id = excluded.user_id,
            p256dh = excluded.p256dh,
            auth = excluded.auth,
            content_encoding = excluded.content_encoding,
            updated_at = excluded.updated_at'
    );

    return $stmt->execute($params);
}

function deletePushSubscription(int $userId, string $endpoint): void
{
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
        return;
    }

    $stmt = db()->prepare('DELETE FROM push_subscriptions WHERE user_id = :user_id AND endpoint = :endpoint');
    $stmt->execute([
        'user_id' => $userId,
        'endpoint' => $endpoint,
    ]);
}

function deletePushSubscriptionByEndpoint(string $endpoint): void
{
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
        return;
    }

    $stmt = db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint');
    $stmt->execute(['endpoint' => $endpoint]);
}

function pushSubscriptionsForUser(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT endpoint, p256dh, auth, content_encoding
         FROM push_subscriptions
         WHERE user_id = :user_id
         ORDER BY updated_at DESC, id DESC'
    );
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll();
}

function createWebPushJwt(string $audience, int $expiresAt): ?array
{
    $pair = ensureWebPushKeyPair();
    if ($pair === null) {
        return null;
    }

    $header = base64UrlEncode(encodeJson([
        'typ' => 'JWT',
        'alg' => 'ES256',
    ]));
    $claims = base64UrlEncode(encodeJson([
        'aud' => $audience,
        'exp' => $expiresAt,
        'sub' => defaultWebPushSubject(),
    ]));
    $signingInput = $header . '.' . $claims;

    $signature = '';
    if (!openssl_sign($signingInput, $signature, $pair['private_key'], OPENSSL_ALGO_SHA256)) {
        return null;
    }

    $joseSignature = ecdsaDerSignatureToJose($signature, 64);
    if ($joseSignature === null) {
        return null;
    }

    return [
        'jwt' => $signingInput . '.' . base64UrlEncode($joseSignature),
        'public_key' => $pair['public_key'],
    ];
}

function ecdsaDerSignatureToJose(string $der, int $partLength): ?string
{
    $offset = 0;

    $readLength = static function (string $input, int &$cursor): ?int {
        if (!isset($input[$cursor])) {
            return null;
        }

        $length = ord($input[$cursor]);
        $cursor++;

        if (($length & 0x80) === 0) {
            return $length;
        }

        $byteCount = $length & 0x7f;
        if ($byteCount < 1 || $byteCount > 2 || strlen($input) < ($cursor + $byteCount)) {
            return null;
        }

        $length = 0;
        for ($index = 0; $index < $byteCount; $index++) {
            $length = ($length << 8) | ord($input[$cursor + $index]);
        }
        $cursor += $byteCount;

        return $length;
    };

    if (!isset($der[$offset]) || ord($der[$offset]) !== 0x30) {
        return null;
    }
    $offset++;
    $sequenceLength = $readLength($der, $offset);
    if ($sequenceLength === null || strlen($der) < ($offset + $sequenceLength)) {
        return null;
    }

    $readInteger = static function (string $input, int &$cursor) use ($readLength, $partLength): ?string {
        if (!isset($input[$cursor]) || ord($input[$cursor]) !== 0x02) {
            return null;
        }

        $cursor++;
        $length = $readLength($input, $cursor);
        if ($length === null || strlen($input) < ($cursor + $length)) {
            return null;
        }

        $value = substr($input, $cursor, $length);
        $cursor += $length;
        $value = ltrim($value, "\x00");
        $value = str_pad($value, $partLength / 2, "\x00", STR_PAD_LEFT);

        return strlen($value) === ($partLength / 2) ? $value : null;
    };

    $r = $readInteger($der, $offset);
    $s = $readInteger($der, $offset);

    return ($r !== null && $s !== null) ? ($r . $s) : null;
}

function sendWebPushHttpRequest(string $endpoint, array $headers): array
{
    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['status' => false, 'body' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $responseBody = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    return [
        'status' => $error === 0 ? $status : false,
        'body' => is_string($responseBody) ? $responseBody : null,
    ];
}

function sendWebPushRequest(string $endpoint): int|false
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $audience = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
    if (isset($parts['port'])) {
        $audience .= ':' . (int) $parts['port'];
    }

    $jwtPayload = createWebPushJwt($audience, time() + WEB_PUSH_AUDIENCE_TTL_SECONDS);
    if ($jwtPayload === null) {
        return false;
    }

    $commonHeaders = [
        'TTL: 60',
        'Urgency: high',
        'Content-Length: 0',
    ];

    $attempts = [
        array_merge($commonHeaders, [
            'Authorization: vapid t=' . $jwtPayload['jwt'] . ', k=' . $jwtPayload['public_key'],
        ]),
        array_merge($commonHeaders, [
            'Authorization: WebPush ' . $jwtPayload['jwt'],
            'Crypto-Key: p256ecdsa=' . $jwtPayload['public_key'],
        ]),
    ];

    $lastResult = ['status' => false, 'body' => null];

    foreach ($attempts as $headers) {
        $lastResult = sendWebPushHttpRequest($endpoint, $headers);
        $status = $lastResult['status'];

        if (is_int($status) && $status >= 200 && $status < 300) {
            return $status;
        }

        if ($status === 404 || $status === 410) {
            return $status;
        }
    }

    if (is_int($lastResult['status']) && $lastResult['status'] >= 400) {
        error_log(sprintf('Web Push delivery failed for %s with status %d%s', $endpoint, $lastResult['status'], $lastResult['body'] ? ': ' . trim($lastResult['body']) : ''));
    }

    return $lastResult['status'];
}

function triggerPushNotificationsForUser(int $userId): void
{
    if (!webPushEnabled()) {
        return;
    }

    foreach (pushSubscriptionsForUser($userId) as $subscription) {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        if ($endpoint === '') {
            continue;
        }

        $status = sendWebPushRequest($endpoint);
        if ($status === 404 || $status === 410) {
            deletePushSubscriptionByEndpoint($endpoint);
        }
    }
}

function triggerPushNotificationsForMessage(int $recipientId): void
{
    triggerPushNotificationsForUser($recipientId);
}

function outgoingFriendRequestUpdates(int $currentUserId): array
{
    $stmt = db()->prepare(
        'SELECT fr.id, fr.recipient_id, fr.status, fr.responded_at, u.username AS recipient_name
         FROM friend_requests fr
         INNER JOIN users u ON u.id = fr.recipient_id
         WHERE fr.sender_id = :user_id
           AND fr.status IN (\'accepted\', \'rejected\')
         ORDER BY fr.responded_at DESC, fr.id DESC
         LIMIT 20'
    );
    $stmt->execute(['user_id' => $currentUserId]);

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'recipient_id' => (int) $row['recipient_id'],
            'recipient_name' => (string) ($row['recipient_name'] ?? 'Unknown'),
            'status' => (string) $row['status'],
            'responded_at' => (string) ($row['responded_at'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function pushNotificationPayload(int $currentUserId): array
{
    $chatUsers = array_values(array_filter(
        chattedUsers($currentUserId),
        static fn (array $chatUser): bool => (int) ($chatUser['unseen_count'] ?? 0) > 0
    ));

    return [
        'chat_users' => $chatUsers,
        'incoming_requests' => incomingFriendRequests($currentUserId),
        'outgoing_request_updates' => outgoingFriendRequestUpdates($currentUserId),
        'generated_at' => gmdate('c'),
    ];
}

function purgeExpiredMessages(bool $force = false): void
{
    static $lastRunAt = null;

    if (!$force && $lastRunAt !== null && (time() - $lastRunAt) < PURGE_INTERVAL_SECONDS) {
        return;
    }

    $messageCutoff = gmdate('c', time() - MESSAGE_RETENTION_TTL_SECONDS);
    $mediaCutoff = gmdate('c', time() - MEDIA_RETENTION_TTL_SECONDS);
    $typingCutoff = gmdate('c', time() - TYPING_TTL_SECONDS);
    $pdo = db();

    $stmt = $pdo->prepare('SELECT audio_path, image_path, file_path FROM messages WHERE created_at < :cutoff AND (audio_path IS NOT NULL OR image_path IS NOT NULL OR file_path IS NOT NULL)');
    $stmt->execute(['cutoff' => $mediaCutoff]);

    foreach ($stmt->fetchAll() as $row) {
        foreach (['audio_path', 'image_path', 'file_path'] as $column) {
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

    $clearMedia = $pdo->prepare(
        'UPDATE messages
         SET audio_path = NULL,
             image_path = NULL,
             file_path = NULL,
             file_name = NULL,
             attachment_expired = 1
         WHERE created_at < :cutoff
           AND (audio_path IS NOT NULL OR image_path IS NOT NULL OR file_path IS NOT NULL OR file_name IS NOT NULL)'
    );
    $clearMedia->execute(['cutoff' => $mediaCutoff]);

    $delete = $pdo->prepare('DELETE FROM messages WHERE created_at < :cutoff');
    $delete->execute(['cutoff' => $messageCutoff]);

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
    triggerPushNotificationsForUser($recipientId);

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

    triggerPushNotificationsForUser($otherUserId);

    return null;
}

function cancelFriendRequest(int $currentUserId, int $otherUserId): ?string
{
    $friendship = friendshipRecord($currentUserId, $otherUserId);

    if ($friendship === null || $friendship['status'] !== 'pending' || $friendship['request_direction'] !== 'outgoing') {
        return 'Friend request not found.';
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

    triggerPushNotificationsForUser($otherUserId);

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
                m_last.created_at AS last_message_at,
                m_last.id AS last_message_id,
                m_last.body AS last_message_body,
                m_last.audio_path AS last_message_audio_path,
                m_last.image_path AS last_message_image_path,
                m_last.file_path AS last_message_file_path,
                m_last.attachment_expired AS last_message_attachment_expired
         FROM users u
         LEFT JOIN user_presence up ON up.user_id = u.id
         LEFT JOIN conversation_clears cc
            ON cc.user_id = :id
           AND cc.conversation_user_id = u.id
         LEFT JOIN messages m_last
            ON m_last.id = (
                SELECT m2.id
                FROM messages m2
                WHERE ((m2.sender_id = :id AND m2.recipient_id = u.id)
                   OR (m2.sender_id = u.id AND m2.recipient_id = :id))
                  AND (cc.cleared_at IS NULL OR m2.created_at > cc.cleared_at)
                ORDER BY m2.created_at DESC, m2.id DESC
                LIMIT 1
            )
         LEFT JOIN messages m_unseen ON m_unseen.sender_id = u.id
            AND m_unseen.recipient_id = :id
            AND m_unseen.read_at IS NULL
            AND (cc.cleared_at IS NULL OR m_unseen.created_at > cc.cleared_at)
         WHERE u.id != :id
         GROUP BY u.id, u.username, u.created_at, up.updated_at, cc.cleared_at,
                  m_last.created_at, m_last.id, m_last.body, m_last.audio_path, m_last.image_path
         ORDER BY CASE WHEN m_last.created_at IS NULL THEN 1 ELSE 0 END ASC,
                  m_last.created_at DESC,
                  m_last.id DESC,
                  username ASC'
    );
    $stmt->execute(['id' => $currentUserId]);

    $users = decorateUsersWithFriendship(mapUsersWithPresence($stmt->fetchAll()), $currentUserId);

    foreach ($users as &$user) {
        $user['chat_list_time'] = formatChatListTime($user['last_message_at'] ?? null);
        $user['chat_list_preview'] = chatListPreview($user);
    }
    unset($user);

    return $users;
}

function formatChatListTime(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '';
    }

    $time = strtotime($timestamp);

    if ($time === false) {
        return '';
    }

    return gmdate('H:i', $time);
}

function chatListPreview(array $user): string
{
    $body = trim((string) decryptStoredMessageText($user['last_message_body'] ?? ''));

    if ($body !== '') {
        return $body;
    }

    if (!empty($user['last_message_image_path'])) {
        return '📷 Photo';
    }

    if (!empty($user['last_message_audio_path'])) {
        return '🎤 Voice message';
    }

    if (!empty($user['last_message_file_path'])) {
        return '📎 File';
    }

    if (!empty($user['last_message_attachment_expired'])) {
        return 'Attachment expired';
    }

    return 'Start chatting';
}

function chatListPayload(int $currentUserId): array
{
    purgeExpiredMessages();
    touchUserPresence($currentUserId);
    markPendingMessagesDeliveredForUser($currentUserId);

    return [
        'chat_users' => combinedChatList($currentUserId),
        'directory_users' => allOtherUsers($currentUserId),
        'incoming_requests' => incomingFriendRequests($currentUserId),
    ];
}

function chatListStateSignature(int $currentUserId): string
{
    purgeExpiredMessages();
    touchUserPresence($currentUserId);
    markPendingMessagesDeliveredForUser($currentUserId);

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

    $groupMembershipStmt = db()->prepare(
        'SELECT COUNT(*) AS total_group_memberships,
                MAX(group_id) AS latest_group_id,
                MAX(created_at) AS latest_group_membership_created_at,
                MAX(joined_at) AS latest_group_membership_joined_at,
                SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active_group_memberships
         FROM group_members
         WHERE user_id = :id'
    );
    $groupMembershipStmt->execute(['id' => $currentUserId]);
    $groupMembershipState = $groupMembershipStmt->fetch() ?: [];

    $groupMessagesStmt = db()->prepare(
        'SELECT COUNT(*) AS total_group_messages,
                MAX(gm.id) AS latest_group_message_id,
                MAX(gm.created_at) AS latest_group_message_created_at
         FROM group_messages gm
         JOIN group_members membership
           ON membership.group_id = gm.group_id
          AND membership.user_id = :id
          AND membership.status = :status'
    );
    $groupMessagesStmt->execute([
        'id' => $currentUserId,
        'status' => 'active',
    ]);
    $groupMessagesState = $groupMessagesStmt->fetch() ?: [];

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
        'groups' => [
            'membership_total' => (int) ($groupMembershipState['total_group_memberships'] ?? 0),
            'latest_group_id' => (int) ($groupMembershipState['latest_group_id'] ?? 0),
            'latest_membership_created_at' => $groupMembershipState['latest_group_membership_created_at'] ?? null,
            'latest_membership_joined_at' => $groupMembershipState['latest_group_membership_joined_at'] ?? null,
            'active_memberships' => (int) ($groupMembershipState['active_group_memberships'] ?? 0),
            'message_total' => (int) ($groupMessagesState['total_group_messages'] ?? 0),
            'latest_message_id' => (int) ($groupMessagesState['latest_group_message_id'] ?? 0),
            'latest_message_created_at' => $groupMessagesState['latest_group_message_created_at'] ?? null,
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

function ensureAuthChallenge(): array
{
    $challenge = $_SESSION['auth_challenge'] ?? null;

    if (
        !is_array($challenge)
        || !isset($challenge['left'], $challenge['right'], $challenge['operator'], $challenge['answer'])
        || !is_int($challenge['left'])
        || !is_int($challenge['right'])
        || !is_string($challenge['operator'])
        || !is_int($challenge['answer'])
        || !in_array($challenge['operator'], ['+', '-'], true)
    ) {
        $challenge = refreshAuthChallenge();
    }

    return $challenge;
}

function refreshAuthChallenge(): array
{
    $left = random_int(2, 12);
    $right = random_int(2, 12);
    $operator = random_int(0, 1) === 0 ? '+' : '-';

    if ($operator === '-' && $right > $left) {
        [$left, $right] = [$right, $left];
    }

    $answer = $operator === '+' ? $left + $right : $left - $right;

    $_SESSION['auth_challenge'] = [
        'left' => $left,
        'right' => $right,
        'operator' => $operator,
        'answer' => $answer,
    ];

    return $_SESSION['auth_challenge'];
}

function authChallengePrompt(): string
{
    $challenge = ensureAuthChallenge();

    return sprintf('%d %s %d', $challenge['left'], $challenge['operator'], $challenge['right']);
}

function authRateLimitState(string $scope): array
{
    $state = $_SESSION['auth_rate_limits'][$scope] ?? null;
    if (!is_array($state)) {
        $state = [
            'attempts' => 0,
            'blocked_until' => 0,
        ];
    }

    return [
        'attempts' => max(0, (int) ($state['attempts'] ?? 0)),
        'blocked_until' => max(0, (int) ($state['blocked_until'] ?? 0)),
    ];
}

function enforceAuthRateLimit(string $scope): ?string
{
    $state = authRateLimitState($scope);
    $now = time();
    if ($state['blocked_until'] > $now) {
        $seconds = $state['blocked_until'] - $now;

        return 'Too many attempts. Please wait ' . $seconds . ' seconds and try again.';
    }

    return null;
}

function recordAuthAttempt(string $scope, bool $success): void
{
    $state = authRateLimitState($scope);

    if ($success) {
        $_SESSION['auth_rate_limits'][$scope] = [
            'attempts' => 0,
            'blocked_until' => 0,
        ];
        return;
    }

    $attempts = $state['attempts'] + 1;
    $blockedUntil = 0;
    if ($attempts >= 5) {
        $cooldown = min(300, (int) pow(2, min(8, $attempts - 5)));
        $blockedUntil = time() + $cooldown;
    }

    $_SESSION['auth_rate_limits'][$scope] = [
        'attempts' => $attempts,
        'blocked_until' => $blockedUntil,
    ];
}

function registerUser(
    string $username,
    string $password,
    string $confirmPassword,
    string $challengeAnswer
): ?string
{
    $rateLimitError = enforceAuthRateLimit('register');
    if ($rateLimitError !== null) {
        return $rateLimitError;
    }

    $username = trim($username);

    if ($username === '' || strlen($username) < 3) {
        return 'Username must be at least 3 characters.';
    }

    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }

    if (
        !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
    ) {
        return 'Password must include upper-case, lower-case, and a number.';
    }

    if (!hash_equals($password, $confirmPassword)) {
        recordAuthAttempt('register', false);
        refreshAuthChallenge();

        return 'Passwords do not match.';
    }

    $challenge = ensureAuthChallenge();
    $normalizedAnswer = trim($challengeAnswer);

    if ($normalizedAnswer === '' || !preg_match('/^-?\d+$/', $normalizedAnswer) || (int) $normalizedAnswer !== $challenge['answer']) {
        recordAuthAttempt('register', false);
        refreshAuthChallenge();

        return 'Incorrect verification answer. Please solve the new math question.';
    }

    if (findUserByUsername($username) !== null) {
        recordAuthAttempt('register', false);
        refreshAuthChallenge();

        return 'Registration is not authorized.';
    }

    $stmt = db()->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (:username, :password_hash, :created_at)');
    $stmt->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => gmdate('c'),
    ]);

    recordAuthAttempt('register', true);
    refreshAuthChallenge();

    return null;
}

function loginUser(string $username, string $password, string $challengeAnswer): ?string
{
    $rateLimitError = enforceAuthRateLimit('login');
    if ($rateLimitError !== null) {
        return $rateLimitError;
    }

    $challenge = ensureAuthChallenge();
    $normalizedAnswer = trim($challengeAnswer);

    if ($normalizedAnswer === '' || !preg_match('/^-?\d+$/', $normalizedAnswer) || (int) $normalizedAnswer !== $challenge['answer']) {
        recordAuthAttempt('login', false);
        refreshAuthChallenge();

        return 'Incorrect verification answer. Please solve the new math question.';
    }

    $user = findUserByUsername($username);

    if ($user === null || !password_verify($password, $user['password_hash'])) {
        recordAuthAttempt('login', false);
        refreshAuthChallenge();

        return 'Invalid username or password.';
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehashStmt = db()->prepare(
            'UPDATE users
             SET password_hash = :password_hash
             WHERE id = :id'
        );
        $rehashStmt->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => (int) $user['id'],
        ]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    csrfToken();
    recordAuthAttempt('login', true);
    refreshAuthChallenge();

    return null;
}


function conversationClearedAt(int $userId, int $otherUserId): ?string
{
    $stmt = db()->prepare(
        'SELECT cleared_at
         FROM conversation_clears
         WHERE user_id = :user_id
           AND conversation_user_id = :other_user_id
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'other_user_id' => $otherUserId,
    ]);

    $clearedAt = $stmt->fetchColumn();

    return is_string($clearedAt) && $clearedAt !== '' ? $clearedAt : null;
}

function clearConversationForUser(int $userId, int $otherUserId): void
{
    purgeExpiredMessages();

    $params = [
        'user_id' => $userId,
        'other_user_id' => $otherUserId,
        'cleared_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO conversation_clears (user_id, conversation_user_id, cleared_at)
             VALUES (:user_id, :other_user_id, :cleared_at)
             ON DUPLICATE KEY UPDATE cleared_at = VALUES(cleared_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO conversation_clears (user_id, conversation_user_id, cleared_at)
             VALUES (:user_id, :other_user_id, :cleared_at)
             ON CONFLICT(user_id, conversation_user_id)
             DO UPDATE SET cleared_at = excluded.cleared_at'
        );
    }

    $stmt->execute($params);
    clearTypingStatus($userId, $otherUserId);
    clearTypingStatus($otherUserId, $userId);
}

function messageHasExpiredAttachment(array $message): bool
{
    return !empty($message['attachment_expired']) || !empty($message['last_message_attachment_expired']);
}

function normalizeReactionEmoji(string $emoji): string
{
    $emoji = trim($emoji);

    if ($emoji === '') {
        return '';
    }

    return mb_substr($emoji, 0, 16, 'UTF-8');
}

function messageReactionsForMessageIds(array $messageIds): array
{
    $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn (int $id): bool => $id > 0)));
    if ($messageIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($messageIds as $index => $messageId) {
        $key = ':message_id_' . $index;
        $params[$key] = $messageId;
        $placeholders[] = $key;
    }

    $stmt = db()->prepare(
        'SELECT message_id, user_id, emoji
         FROM message_reactions
         WHERE message_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY updated_at ASC, user_id ASC'
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    $reactionMap = [];
    foreach ($rows as $row) {
        $messageId = (int) ($row['message_id'] ?? 0);
        if ($messageId <= 0) {
            continue;
        }
        $reactionMap[$messageId] ??= [];
        $reactionMap[$messageId][] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'emoji' => (string) ($row['emoji'] ?? ''),
        ];
    }

    return $reactionMap;
}

function attachReactionsToMessages(array $messages): array
{
    if ($messages === []) {
        return [];
    }

    $reactionMap = messageReactionsForMessageIds(array_map(
        static fn (array $message): int => (int) ($message['id'] ?? 0),
        $messages
    ));

    return array_map(static function (array $message) use ($reactionMap): array {
        $messageId = (int) ($message['id'] ?? 0);
        $message['reactions'] = $reactionMap[$messageId] ?? [];

        return $message;
    }, $messages);
}

function groupMessageReactionsForMessageIds(array $messageIds): array
{
    $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn (int $id): bool => $id > 0)));
    if ($messageIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($messageIds as $index => $messageId) {
        $key = ':group_message_id_' . $index;
        $params[$key] = $messageId;
        $placeholders[] = $key;
    }

    $stmt = db()->prepare(
        'SELECT message_id, user_id, emoji
         FROM group_message_reactions
         WHERE message_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY updated_at ASC, user_id ASC'
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    $reactionMap = [];
    foreach ($rows as $row) {
        $messageId = (int) ($row['message_id'] ?? 0);
        if ($messageId <= 0) {
            continue;
        }
        $reactionMap[$messageId] ??= [];
        $reactionMap[$messageId][] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'emoji' => (string) ($row['emoji'] ?? ''),
        ];
    }

    return $reactionMap;
}

function attachReactionsToGroupMessages(array $messages): array
{
    if ($messages === []) {
        return [];
    }

    $reactionMap = groupMessageReactionsForMessageIds(array_map(
        static fn (array $message): int => (int) ($message['id'] ?? 0),
        $messages
    ));

    return array_map(static function (array $message) use ($reactionMap): array {
        $messageId = (int) ($message['id'] ?? 0);
        $message['reactions'] = $reactionMap[$messageId] ?? [];

        return $message;
    }, $messages);
}

function formatMessage(array $message): array
{
    $replyMessageId = (int) ($message['reply_message_id'] ?? $message['reply_to_message_id'] ?? 0);
    $replySenderId = (int) ($message['reply_sender_id'] ?? 0);
    $replyReference = null;
    if ($replyMessageId > 0) {
        $replyReference = [
            'id' => $replyMessageId,
            'sender_id' => $replySenderId,
            'sender_name' => (string) ($message['reply_sender_name'] ?? ''),
            'body' => decryptStoredMessageText($message['reply_body'] ?? null),
            'audio_path' => $message['reply_audio_path'] ?? null,
            'image_path' => $message['reply_image_path'] ?? null,
            'file_path' => $message['reply_file_path'] ?? null,
            'file_name' => $message['reply_file_name'] ?? null,
            'created_at' => $message['reply_created_at'] ?? null,
        ];
    }

    return [
        'id' => (int) $message['id'],
        'sender_id' => (int) $message['sender_id'],
        'recipient_id' => (int) $message['recipient_id'],
        'sender_name' => $message['sender_name'],
        'body' => decryptStoredMessageText($message['body'] ?? null),
        'audio_path' => $message['audio_path'],
        'image_path' => $message['image_path'] ?? null,
        'file_path' => $message['file_path'] ?? null,
        'file_name' => $message['file_name'] ?? null,
        'attachment_expired' => messageHasExpiredAttachment($message),
        'reply_to_message_id' => $replyMessageId > 0 ? $replyMessageId : null,
        'reply_reference' => $replyReference,
        'delivered_at' => $message['delivered_at'] ?? null,
        'read_at' => $message['read_at'] ?? null,
        'reactions' => is_array($message['reactions'] ?? null) ? $message['reactions'] : [],
        'created_at' => $message['created_at'],
        'created_at_label' => gmdate('Y-m-d H:i:s', strtotime($message['created_at'])) . ' UTC',
    ];
}

function findMessageById(int $messageId): ?array
{
    $stmt = db()->prepare(
        'SELECT m.*, u.username AS sender_name,
                rm.id AS reply_message_id,
                rm.sender_id AS reply_sender_id,
                ru.username AS reply_sender_name,
                rm.body AS reply_body,
                rm.audio_path AS reply_audio_path,
                rm.image_path AS reply_image_path,
                rm.file_path AS reply_file_path,
                rm.file_name AS reply_file_name,
                rm.created_at AS reply_created_at
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
         LEFT JOIN users ru ON ru.id = rm.sender_id
         WHERE m.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $messageId]);

    $message = $stmt->fetch();

    if ($message === false) {
        return null;
    }

    $formatted = formatMessage($message);
    $withReactions = attachReactionsToMessages([$formatted]);

    return $withReactions[0] ?? $formatted;
}

function conversationMessages(int $userId, int $otherUserId): array
{
    purgeExpiredMessages();

    return conversationMessagesWithoutMaintenance($userId, $otherUserId);
}

function conversationExistsForUser(int $userId, int $otherUserId): bool
{
    $clearedAt = conversationClearedAt($userId, $otherUserId);
    $sql = 'SELECT 1
         FROM messages
         WHERE ((sender_id = :user_id AND recipient_id = :other_id)
            OR (sender_id = :other_id AND recipient_id = :user_id))';
    $params = [
        'user_id' => $userId,
        'other_id' => $otherUserId,
    ];

    if ($clearedAt !== null) {
        $sql .= ' AND created_at > :cleared_at';
        $params['cleared_at'] = $clearedAt;
    }

    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

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
    $clearedAt = conversationClearedAt($userId, $otherUserId);
    $sql = 'SELECT m.*, u.username AS sender_name,
                   rm.id AS reply_message_id,
                   rm.sender_id AS reply_sender_id,
                   ru.username AS reply_sender_name,
                   rm.body AS reply_body,
                   rm.audio_path AS reply_audio_path,
                   rm.image_path AS reply_image_path,
                   rm.file_path AS reply_file_path,
                   rm.file_name AS reply_file_name,
                   rm.created_at AS reply_created_at
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
            LEFT JOIN users ru ON ru.id = rm.sender_id
            LEFT JOIN message_deletions md ON md.message_id = m.id AND md.user_id = :user_id
            WHERE ((m.sender_id = :user_id AND m.recipient_id = :other_id)
               OR (m.sender_id = :other_id AND m.recipient_id = :user_id))
              AND md.message_id IS NULL';

    $params = [
        'user_id' => $userId,
        'other_id' => $otherUserId,
    ];

    if ($clearedAt !== null) {
        $sql .= ' AND m.created_at > :cleared_at';
        $params['cleared_at'] = $clearedAt;
    }

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
        $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }

    $stmt->execute();
    $messages = $stmt->fetchAll();

    if ($limit > 0) {
        $messages = array_reverse($messages);
    }

    return attachReactionsToMessages(array_map(static fn (array $message): array => formatMessage($message), $messages));
}

function normalizePrivateReplyTargetId(int $senderId, int $recipientId, ?int $replyToMessageId): ?int
{
    $messageId = (int) ($replyToMessageId ?? 0);
    if ($messageId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id
         FROM messages
         WHERE id = :message_id
           AND ((sender_id = :sender_id AND recipient_id = :recipient_id)
             OR (sender_id = :recipient_id AND recipient_id = :sender_id))
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
    ]);

    return $stmt->fetchColumn() ? $messageId : null;
}

function sendTextMessage(int $senderId, int $recipientId, string $body, ?int $replyToMessageId = null): array|string|null
{
    purgeExpiredMessages();

    if (!canUsersChat($senderId, $recipientId)) {
        return 'You can only message users after they accept your friend request.';
    }

    $body = trim($body);
    if ($body === '') {
        return 'Message cannot be empty.';
    }

    $replyToMessageId = normalizePrivateReplyTargetId($senderId, $recipientId, $replyToMessageId);

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, attachment_expired, reply_to_message_id, created_at)
         VALUES (:sender_id, :recipient_id, :body, NULL, 0, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'body' => encryptStoredMessageText($body),
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);
    triggerPushNotificationsForMessage($recipientId);

    return findMessageById((int) db()->lastInsertId());
}

function reactToPrivateMessage(int $currentUserId, int $otherUserId, int $messageId, string $emoji): array|string
{
    $messageId = max(0, $messageId);
    if ($messageId <= 0) {
        return 'Message not found.';
    }

    $stmt = db()->prepare(
        'SELECT id, sender_id
         FROM messages
         WHERE id = :message_id
           AND ((sender_id = :current_user_id AND recipient_id = :other_user_id)
             OR (sender_id = :other_user_id AND recipient_id = :current_user_id))
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'current_user_id' => $currentUserId,
        'other_user_id' => $otherUserId,
    ]);

    $messageRow = $stmt->fetch();
    if (!$messageRow) {
        return 'Message not found.';
    }
    if ((int) ($messageRow['sender_id'] ?? 0) === $currentUserId) {
        return 'You can only react to messages from other people.';
    }

    $emoji = normalizeReactionEmoji($emoji);
    if ($emoji === '') {
        $deleteStmt = db()->prepare(
            'DELETE FROM message_reactions
             WHERE message_id = :message_id
               AND user_id = :user_id'
        );
        $deleteStmt->execute([
            'message_id' => $messageId,
            'user_id' => $currentUserId,
        ]);

        return ['message_id' => $messageId];
    }

    $params = [
        'message_id' => $messageId,
        'user_id' => $currentUserId,
        'emoji' => $emoji,
        'updated_at' => gmdate('c'),
    ];
    if (dbDriver() === 'mysql') {
        $upsertStmt = db()->prepare(
            'INSERT INTO message_reactions (message_id, user_id, emoji, updated_at)
             VALUES (:message_id, :user_id, :emoji, :updated_at)
             ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), updated_at = VALUES(updated_at)'
        );
    } else {
        $upsertStmt = db()->prepare(
            'INSERT INTO message_reactions (message_id, user_id, emoji, updated_at)
             VALUES (:message_id, :user_id, :emoji, :updated_at)
             ON CONFLICT(message_id, user_id)
             DO UPDATE SET emoji = excluded.emoji, updated_at = excluded.updated_at'
        );
    }
    $upsertStmt->execute($params);

    return ['message_id' => $messageId];
}

function reactToGroupMessage(int $groupId, int $currentUserId, int $messageId, string $emoji): array|string
{
    if (!canAccessGroupConversation($groupId, $currentUserId)) {
        return 'Group not found.';
    }

    $stmt = db()->prepare(
        'SELECT id, sender_id
         FROM group_messages
         WHERE id = :message_id
           AND group_id = :group_id
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'group_id' => $groupId,
    ]);
    $messageRow = $stmt->fetch();
    if (!$messageRow) {
        return 'Message not found.';
    }
    if ((int) ($messageRow['sender_id'] ?? 0) === $currentUserId) {
        return 'You can only react to messages from other people.';
    }

    $emoji = normalizeReactionEmoji($emoji);
    if ($emoji === '') {
        $deleteStmt = db()->prepare(
            'DELETE FROM group_message_reactions
             WHERE message_id = :message_id
               AND user_id = :user_id'
        );
        $deleteStmt->execute([
            'message_id' => $messageId,
            'user_id' => $currentUserId,
        ]);

        return ['message_id' => $messageId];
    }

    $params = [
        'message_id' => $messageId,
        'user_id' => $currentUserId,
        'emoji' => $emoji,
        'updated_at' => gmdate('c'),
    ];
    if (dbDriver() === 'mysql') {
        $upsertStmt = db()->prepare(
            'INSERT INTO group_message_reactions (message_id, user_id, emoji, updated_at)
             VALUES (:message_id, :user_id, :emoji, :updated_at)
             ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), updated_at = VALUES(updated_at)'
        );
    } else {
        $upsertStmt = db()->prepare(
            'INSERT INTO group_message_reactions (message_id, user_id, emoji, updated_at)
             VALUES (:message_id, :user_id, :emoji, :updated_at)
             ON CONFLICT(message_id, user_id)
             DO UPDATE SET emoji = excluded.emoji, updated_at = excluded.updated_at'
        );
    }
    $upsertStmt->execute($params);

    return ['message_id' => $messageId];
}

function deletePrivateMessage(int $currentUserId, int $otherUserId, int $messageId): ?string
{
    $messageId = max(0, $messageId);
    if ($messageId <= 0) {
        return 'Message not found.';
    }

    $stmt = db()->prepare(
        'SELECT id, sender_id, audio_path, image_path, file_path
         FROM messages
         WHERE id = :message_id
           AND ((sender_id = :current_user_id AND recipient_id = :other_user_id)
             OR (sender_id = :other_user_id AND recipient_id = :current_user_id))
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'current_user_id' => $currentUserId,
        'other_user_id' => $otherUserId,
    ]);
    $messageRow = $stmt->fetch();
    if (!$messageRow) {
        return 'Message not found.';
    }

    if ((int) ($messageRow['sender_id'] ?? 0) === $currentUserId) {
        deleteStorageFileIfExists($messageRow['audio_path'] ?? null);
        deleteStorageFileIfExists($messageRow['image_path'] ?? null);
        deleteStorageFileIfExists($messageRow['file_path'] ?? null);

        $reactionStmt = db()->prepare('DELETE FROM message_reactions WHERE message_id = :message_id');
        $reactionStmt->execute(['message_id' => $messageId]);

        $deleteStmt = db()->prepare('DELETE FROM messages WHERE id = :message_id');
        $deleteStmt->execute(['message_id' => $messageId]);

        return null;
    }

    $params = [
        'message_id' => $messageId,
        'user_id' => $currentUserId,
        'deleted_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $hideStmt = db()->prepare(
            'INSERT INTO message_deletions (message_id, user_id, deleted_at)
             VALUES (:message_id, :user_id, :deleted_at)
             ON DUPLICATE KEY UPDATE deleted_at = VALUES(deleted_at)'
        );
    } else {
        $hideStmt = db()->prepare(
            'INSERT INTO message_deletions (message_id, user_id, deleted_at)
             VALUES (:message_id, :user_id, :deleted_at)
             ON CONFLICT(message_id, user_id)
             DO UPDATE SET deleted_at = excluded.deleted_at'
        );
    }
    $hideStmt->execute($params);

    return null;
}

function editPrivateMessage(int $currentUserId, int $otherUserId, int $messageId, string $body): ?string
{
    $messageId = max(0, $messageId);
    if ($messageId <= 0) {
        return 'Message not found.';
    }

    $trimmedBody = trim($body);
    if ($trimmedBody === '') {
        return 'Message cannot be empty.';
    }

    $stmt = db()->prepare(
        'UPDATE messages
         SET body = :body
         WHERE id = :message_id
           AND sender_id = :current_user_id
           AND recipient_id = :other_user_id'
    );
    $stmt->execute([
        'body' => encryptStoredMessageText($trimmedBody),
        'message_id' => $messageId,
        'current_user_id' => $currentUserId,
        'other_user_id' => $otherUserId,
    ]);

    if ($stmt->rowCount() > 0) {
        return null;
    }

    $checkStmt = db()->prepare(
        'SELECT sender_id
         FROM messages
         WHERE id = :message_id
           AND ((sender_id = :current_user_id AND recipient_id = :other_user_id)
             OR (sender_id = :other_user_id AND recipient_id = :current_user_id))
         LIMIT 1'
    );
    $checkStmt->execute([
        'message_id' => $messageId,
        'current_user_id' => $currentUserId,
        'other_user_id' => $otherUserId,
    ]);
    $messageRow = $checkStmt->fetch();
    if (!$messageRow) {
        return 'Message not found.';
    }

    return 'You can only edit your own messages.';
}

function deleteGroupMessage(int $groupId, int $currentUserId, int $messageId): ?string
{
    if (!canAccessGroupConversation($groupId, $currentUserId)) {
        return 'Group not found.';
    }

    $messageId = max(0, $messageId);
    if ($messageId <= 0) {
        return 'Message not found.';
    }

    $stmt = db()->prepare(
        'SELECT id, sender_id, audio_path, image_path, file_path
         FROM group_messages
         WHERE id = :message_id
           AND group_id = :group_id
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'group_id' => $groupId,
    ]);
    $messageRow = $stmt->fetch();
    if (!$messageRow) {
        return 'Message not found.';
    }

    if ((int) ($messageRow['sender_id'] ?? 0) === $currentUserId) {
        deleteStorageFileIfExists($messageRow['audio_path'] ?? null);
        deleteStorageFileIfExists($messageRow['image_path'] ?? null);
        deleteStorageFileIfExists($messageRow['file_path'] ?? null);

        $reactionStmt = db()->prepare('DELETE FROM group_message_reactions WHERE message_id = :message_id');
        $reactionStmt->execute(['message_id' => $messageId]);

        $deleteStmt = db()->prepare(
            'DELETE FROM group_messages
             WHERE id = :message_id
               AND group_id = :group_id'
        );
        $deleteStmt->execute([
            'message_id' => $messageId,
            'group_id' => $groupId,
        ]);

        return null;
    }

    $params = [
        'message_id' => $messageId,
        'user_id' => $currentUserId,
        'deleted_at' => gmdate('c'),
    ];
    if (dbDriver() === 'mysql') {
        $hideStmt = db()->prepare(
            'INSERT INTO group_message_deletions (message_id, user_id, deleted_at)
             VALUES (:message_id, :user_id, :deleted_at)
             ON DUPLICATE KEY UPDATE deleted_at = VALUES(deleted_at)'
        );
    } else {
        $hideStmt = db()->prepare(
            'INSERT INTO group_message_deletions (message_id, user_id, deleted_at)
             VALUES (:message_id, :user_id, :deleted_at)
             ON CONFLICT(message_id, user_id)
             DO UPDATE SET deleted_at = excluded.deleted_at'
        );
    }
    $hideStmt->execute($params);

    return null;
}

function editGroupMessage(int $groupId, int $currentUserId, int $messageId, string $body): ?string
{
    if (!canAccessGroupConversation($groupId, $currentUserId)) {
        return 'Group not found.';
    }

    $messageId = max(0, $messageId);
    if ($messageId <= 0) {
        return 'Message not found.';
    }

    $trimmedBody = trim($body);
    if ($trimmedBody === '') {
        return 'Message cannot be empty.';
    }

    $stmt = db()->prepare(
        'UPDATE group_messages
         SET body = :body
         WHERE id = :message_id
           AND group_id = :group_id
           AND sender_id = :current_user_id'
    );
    $stmt->execute([
        'body' => encryptStoredMessageText($trimmedBody),
        'message_id' => $messageId,
        'group_id' => $groupId,
        'current_user_id' => $currentUserId,
    ]);

    if ($stmt->rowCount() > 0) {
        return null;
    }

    $checkStmt = db()->prepare(
        'SELECT sender_id
         FROM group_messages
         WHERE id = :message_id
           AND group_id = :group_id
         LIMIT 1'
    );
    $checkStmt->execute([
        'message_id' => $messageId,
        'group_id' => $groupId,
    ]);
    $messageRow = $checkStmt->fetch();
    if (!$messageRow) {
        return 'Message not found.';
    }

    return 'You can only edit your own messages.';
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

function sendImageMessage(int $senderId, int $recipientId, array $file, ?int $replyToMessageId = null): array|string|null
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

    $replyToMessageId = normalizePrivateReplyTargetId($senderId, $recipientId, $replyToMessageId);

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, image_path, attachment_expired, reply_to_message_id, created_at)
         VALUES (:sender_id, :recipient_id, NULL, NULL, :image_path, 0, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'image_path' => $relativePath,
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);
    triggerPushNotificationsForMessage($recipientId);

    return findMessageById((int) db()->lastInsertId());
}

function sendVoiceMessage(int $senderId, int $recipientId, array $file, ?int $replyToMessageId = null): array|string|null
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

    $replyToMessageId = normalizePrivateReplyTargetId($senderId, $recipientId, $replyToMessageId);

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, attachment_expired, reply_to_message_id, created_at)
         VALUES (:sender_id, :recipient_id, NULL, :audio_path, 0, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'audio_path' => $relativePath,
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);
    triggerPushNotificationsForMessage($recipientId);

    return findMessageById((int) db()->lastInsertId());
}

function sendFileMessage(int $senderId, int $recipientId, array $file, ?int $replyToMessageId = null): array|string|null
{
    purgeExpiredMessages();

    if (!canUsersChat($senderId, $recipientId)) {
        return 'You can only message users after they accept your friend request.';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'File upload failed. Please choose a file to share.';
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return 'File must be 10MB or smaller.';
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    if ($originalName === '') {
        $originalName = 'shared-file';
    }

    $sanitizedName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName);
    $sanitizedName = trim((string) $sanitizedName, '-.');
    if ($sanitizedName === '') {
        $sanitizedName = 'shared-file';
    }

    $extension = strtolower((string) pathinfo($sanitizedName, PATHINFO_EXTENSION));
    $storedExtension = $extension !== '' ? '.' . substr($extension, 0, 20) : '';
    $filename = sprintf('file_%s_%s%s', $senderId, bin2hex(random_bytes(8)), $storedExtension);
    $target = UPLOAD_PATH . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Could not save the file.';
    }

    $relativePath = 'storage/uploads/' . $filename;

    $replyToMessageId = normalizePrivateReplyTargetId($senderId, $recipientId, $replyToMessageId);

    $stmt = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, body, audio_path, image_path, file_path, file_name, attachment_expired, reply_to_message_id, created_at)
         VALUES (:sender_id, :recipient_id, NULL, NULL, NULL, :file_path, :file_name, 0, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'file_path' => $relativePath,
        'file_name' => mb_substr($sanitizedName, 0, 255),
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearTypingStatus($senderId, $recipientId);
    triggerPushNotificationsForMessage($recipientId);

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
            'updated_at' => $otherUser['presence_updated_at'] ?? null,
        ],
    ];
}

function conversationHasOlderMessagesWithoutMaintenance(int $userId, int $otherUserId, int $beforeMessageId): bool
{
    $clearedAt = conversationClearedAt($userId, $otherUserId);
    $sql = 'SELECT 1
         FROM messages
         WHERE (((sender_id = :user_id AND recipient_id = :other_id)
            OR (sender_id = :other_id AND recipient_id = :user_id)))
           AND id < :before_message_id';
    $params = [
        'user_id' => $userId,
        'other_id' => $otherUserId,
        'before_message_id' => $beforeMessageId,
    ];

    if ($clearedAt !== null) {
        $sql .= ' AND created_at > :cleared_at';
        $params['cleared_at'] = $clearedAt;
    }

    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function conversationStateSignature(int $userId, int $otherUserId): string
{
    purgeExpiredMessages();

    $clearedAt = conversationClearedAt($userId, $otherUserId);
    $sql = 'SELECT COUNT(*) AS total_messages,
                MAX(id) AS latest_message_id,
                MAX(created_at) AS latest_message_created_at,
                MAX(delivered_at) AS latest_message_delivered_at,
                MAX(read_at) AS latest_message_read_at
         FROM messages
         WHERE ((sender_id = :user_id AND recipient_id = :other_user_id)
            OR (sender_id = :other_user_id AND recipient_id = :user_id))';
    $params = [
        'user_id' => $userId,
        'other_user_id' => $otherUserId,
    ];

    if ($clearedAt !== null) {
        $sql .= ' AND created_at > :cleared_at';
        $params['cleared_at'] = $clearedAt;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $messageState = $stmt->fetch() ?: [];
    $reactionSql = 'SELECT COUNT(*) AS total_reactions,
                           MAX(mr.updated_at) AS latest_reaction_updated_at
                    FROM message_reactions mr
                    JOIN messages m ON m.id = mr.message_id
                    WHERE ((m.sender_id = :user_id AND m.recipient_id = :other_user_id)
                       OR (m.sender_id = :other_user_id AND m.recipient_id = :user_id))';
    if ($clearedAt !== null) {
        $reactionSql .= ' AND m.created_at > :cleared_at';
    }
    $reactionStmt = db()->prepare($reactionSql);
    $reactionStmt->execute($params);
    $reactionState = $reactionStmt->fetch() ?: [];

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
        'reactions' => [
            'total' => (int) ($reactionState['total_reactions'] ?? 0),
            'latest_updated_at' => $reactionState['latest_reaction_updated_at'] ?? null,
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
            'updated_at' => $otherUser['presence_updated_at'] ?? null,
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

function markPendingMessagesDeliveredForUser(int $userId): void
{
    $stmt = db()->prepare(
        'UPDATE messages
         SET delivered_at = COALESCE(delivered_at, :delivered_at)
         WHERE recipient_id = :user_id
           AND delivered_at IS NULL'
    );
    $stmt->execute([
        'delivered_at' => gmdate('c'),
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

function activeGroupMembership(int $groupId, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT gm.*, g.name, g.creator_user_id, g.deleted_at
         FROM group_members gm
         JOIN groups g ON g.id = gm.group_id
         WHERE gm.group_id = :group_id
           AND gm.user_id = :user_id
           AND gm.status = :status
         LIMIT 1'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'user_id' => $userId,
        'status' => 'active',
    ]);

    $row = $stmt->fetch();

    if ($row === false || $row['deleted_at'] !== null) {
        return null;
    }

    return $row;
}

function findGroupById(int $groupId): ?array
{
    $stmt = db()->prepare(
        'SELECT g.*,
                creator.username AS creator_name
         FROM groups g
         JOIN users creator ON creator.id = g.creator_user_id
         WHERE g.id = :group_id
           AND g.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute(['group_id' => $groupId]);
    $group = $stmt->fetch();

    if ($group === false) {
        return null;
    }

    $group['id'] = (int) $group['id'];
    $group['creator_user_id'] = (int) $group['creator_user_id'];

    return $group;
}

function canAccessGroupConversation(int $groupId, int $userId): bool
{
    return activeGroupMembership($groupId, $userId) !== null;
}

function groupMembers(int $groupId): array
{
    $stmt = db()->prepare(
        'SELECT gm.group_id,
                gm.user_id,
                gm.invited_by_user_id,
                gm.role,
                gm.status,
                gm.created_at,
                gm.joined_at,
                gm.left_at,
                u.username,
                up.updated_at AS presence_updated_at
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN user_presence up ON up.user_id = gm.user_id
         WHERE gm.group_id = :group_id
           AND gm.status = :status
         ORDER BY CASE WHEN gm.role = \'creator\' THEN 0 ELSE 1 END ASC,
                  u.username ASC'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'status' => 'active',
    ]);

    return array_map(static function (array $member): array {
        $member['group_id'] = (int) $member['group_id'];
        $member['user_id'] = (int) $member['user_id'];
        $member['invited_by_user_id'] = $member['invited_by_user_id'] !== null ? (int) $member['invited_by_user_id'] : null;
        $member['is_online'] = isset($member['presence_updated_at'])
            && strtotime((string) $member['presence_updated_at']) !== false
            && strtotime((string) $member['presence_updated_at']) >= (time() - PRESENCE_TTL_SECONDS);
        $member['presence_label'] = presenceLabel($member['presence_updated_at'] ?? null);

        return $member;
    }, $stmt->fetchAll());
}

function createGroup(int $creatorUserId, string $name): ?string
{
    $trimmedName = trim($name);

    if ($trimmedName === '') {
        return 'Group name is required.';
    }

    if (mb_strlen($trimmedName) > 80) {
        return 'Group name must be 80 characters or fewer.';
    }

    $createdAt = gmdate('c');
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO groups (name, creator_user_id, created_at, deleted_at)
             VALUES (:name, :creator_user_id, :created_at, NULL)'
        );
        $stmt->execute([
            'name' => $trimmedName,
            'creator_user_id' => $creatorUserId,
            'created_at' => $createdAt,
        ]);

        $groupId = (int) $pdo->lastInsertId();
        $memberStmt = $pdo->prepare(
            'INSERT INTO group_members (group_id, user_id, invited_by_user_id, role, status, created_at, joined_at, left_at)
             VALUES (:group_id, :user_id, :invited_by_user_id, :role, :status, :created_at, :joined_at, NULL)'
        );
        $memberStmt->execute([
            'group_id' => $groupId,
            'user_id' => $creatorUserId,
            'invited_by_user_id' => $creatorUserId,
            'role' => 'creator',
            'status' => 'active',
            'created_at' => $createdAt,
            'joined_at' => $createdAt,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Could not create the group right now.';
    }

    return null;
}

function inviteUserToGroup(int $groupId, int $actorUserId, int $invitedUserId): ?string
{
    if ($actorUserId === $invitedUserId) {
        return 'You are already in the group.';
    }

    $group = findGroupById($groupId);
    if ($group === null || !canAccessGroupConversation($groupId, $actorUserId)) {
        return 'Group not found.';
    }

    $user = findUserById($invitedUserId);
    if ($user === null) {
        return 'User not found.';
    }

    $existing = activeGroupMembership($groupId, $invitedUserId);
    if ($existing !== null) {
        return 'That user is already in the group.';
    }

    $params = [
        'group_id' => $groupId,
        'user_id' => $invitedUserId,
        'invited_by_user_id' => $actorUserId,
        'role' => 'member',
        'status' => 'active',
        'created_at' => gmdate('c'),
        'joined_at' => gmdate('c'),
        'left_at' => null,
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO group_members (group_id, user_id, invited_by_user_id, role, status, created_at, joined_at, left_at)
             VALUES (:group_id, :user_id, :invited_by_user_id, :role, :status, :created_at, :joined_at, :left_at)
             ON DUPLICATE KEY UPDATE invited_by_user_id = VALUES(invited_by_user_id),
                                     role = VALUES(role),
                                     status = VALUES(status),
                                     created_at = VALUES(created_at),
                                     joined_at = VALUES(joined_at),
                                     left_at = VALUES(left_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO group_members (group_id, user_id, invited_by_user_id, role, status, created_at, joined_at, left_at)
             VALUES (:group_id, :user_id, :invited_by_user_id, :role, :status, :created_at, :joined_at, :left_at)
             ON CONFLICT(group_id, user_id)
             DO UPDATE SET invited_by_user_id = excluded.invited_by_user_id,
                           role = excluded.role,
                           status = excluded.status,
                           created_at = excluded.created_at,
                           joined_at = excluded.joined_at,
                           left_at = excluded.left_at'
        );
    }

    $stmt->execute($params);

    return null;
}

function leaveGroup(int $groupId, int $userId): ?string
{
    $membership = activeGroupMembership($groupId, $userId);
    if ($membership === null) {
        return 'Group not found.';
    }

    if ((string) $membership['role'] === 'creator') {
        return 'The group creator cannot leave without deleting the group.';
    }

    $stmt = db()->prepare(
        'UPDATE group_members
         SET status = :status,
             left_at = :left_at
         WHERE group_id = :group_id
           AND user_id = :user_id'
    );
    $stmt->execute([
        'status' => 'left',
        'left_at' => gmdate('c'),
        'group_id' => $groupId,
        'user_id' => $userId,
    ]);

    return null;
}

function removeGroupMember(int $groupId, int $actorUserId, int $targetUserId): ?string
{
    if ($targetUserId <= 0) {
        return 'Member not found.';
    }

    $group = findGroupById($groupId);
    if ($group === null) {
        return 'Group not found.';
    }

    if ((int) $group['creator_user_id'] !== $actorUserId) {
        return 'Only the group creator can remove members.';
    }

    if ($actorUserId === $targetUserId) {
        return 'The group creator cannot remove themselves.';
    }

    $membership = activeGroupMembership($groupId, $targetUserId);
    if ($membership === null) {
        return 'Member not found.';
    }

    if ((string) ($membership['role'] ?? '') === 'creator') {
        return 'The group creator cannot be removed.';
    }

    $stmt = db()->prepare(
        'UPDATE group_members
         SET status = :status,
             left_at = :left_at
         WHERE group_id = :group_id
           AND user_id = :user_id
           AND status = :current_status'
    );
    $stmt->execute([
        'status' => 'left',
        'left_at' => gmdate('c'),
        'group_id' => $groupId,
        'user_id' => $targetUserId,
        'current_status' => 'active',
    ]);

    return null;
}

function deleteGroup(int $groupId, int $actorUserId): ?string
{
    $group = findGroupById($groupId);
    if ($group === null) {
        return 'Group not found.';
    }

    if ((int) $group['creator_user_id'] !== $actorUserId) {
        return 'Only the group creator can delete this group.';
    }

    $stmt = db()->prepare(
        'UPDATE groups
         SET deleted_at = :deleted_at
         WHERE id = :group_id'
    );
    $stmt->execute([
        'deleted_at' => gmdate('c'),
        'group_id' => $groupId,
    ]);

    return null;
}

function renameGroup(int $groupId, int $actorUserId, string $name): ?string
{
    $group = findGroupById($groupId);
    if ($group === null) {
        return 'Group not found.';
    }

    if ((int) $group['creator_user_id'] !== $actorUserId) {
        return 'Only the group creator can rename this group.';
    }

    $trimmedName = trim($name);
    if ($trimmedName === '') {
        return 'Group name is required.';
    }

    if (mb_strlen($trimmedName) > 80) {
        return 'Group name must be 80 characters or fewer.';
    }

    $stmt = db()->prepare(
        'UPDATE groups
         SET name = :name
         WHERE id = :group_id'
    );
    $stmt->execute([
        'name' => $trimmedName,
        'group_id' => $groupId,
    ]);

    return null;
}

function groupConversationClearedAt(int $groupId, int $userId): ?string
{
    $stmt = db()->prepare(
        'SELECT cleared_at
         FROM group_conversation_clears
         WHERE group_id = :group_id
           AND user_id = :user_id'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'user_id' => $userId,
    ]);

    $clearedAt = $stmt->fetchColumn();

    return is_string($clearedAt) && $clearedAt !== '' ? $clearedAt : null;
}

function clearGroupConversationForUser(int $groupId, int $userId): void
{
    $params = [
        'group_id' => $groupId,
        'user_id' => $userId,
        'cleared_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO group_conversation_clears (group_id, user_id, cleared_at)
             VALUES (:group_id, :user_id, :cleared_at)
             ON DUPLICATE KEY UPDATE cleared_at = VALUES(cleared_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO group_conversation_clears (group_id, user_id, cleared_at)
             VALUES (:group_id, :user_id, :cleared_at)
             ON CONFLICT(group_id, user_id)
             DO UPDATE SET cleared_at = excluded.cleared_at'
        );
    }

    $stmt->execute($params);
}

function formatGroupMessage(array $message): array
{
    $replyMessageId = (int) ($message['reply_message_id'] ?? $message['reply_to_message_id'] ?? 0);
    $replySenderId = (int) ($message['reply_sender_id'] ?? 0);
    $replyReference = null;
    if ($replyMessageId > 0) {
        $replyReference = [
            'id' => $replyMessageId,
            'sender_id' => $replySenderId,
            'sender_name' => (string) ($message['reply_sender_name'] ?? ''),
            'body' => decryptStoredMessageText($message['reply_body'] ?? null),
            'audio_path' => $message['reply_audio_path'] ?? null,
            'image_path' => $message['reply_image_path'] ?? null,
            'file_path' => $message['reply_file_path'] ?? null,
            'file_name' => $message['reply_file_name'] ?? null,
            'created_at' => $message['reply_created_at'] ?? null,
        ];
    }

    return [
        'id' => (int) $message['id'],
        'group_id' => (int) $message['group_id'],
        'sender_id' => (int) $message['sender_id'],
        'sender_name' => (string) $message['sender_name'],
        'body' => decryptStoredMessageText($message['body'] ?? null),
        'audio_path' => $message['audio_path'],
        'image_path' => $message['image_path'],
        'file_path' => $message['file_path'] ?? null,
        'file_name' => $message['file_name'] ?? null,
        'reply_to_message_id' => $replyMessageId > 0 ? $replyMessageId : null,
        'reply_reference' => $replyReference,
        'reactions' => is_array($message['reactions'] ?? null) ? $message['reactions'] : [],
        'created_at' => $message['created_at'],
        'created_at_label' => gmdate('Y-m-d H:i:s', strtotime((string) $message['created_at'])) . ' UTC',
    ];
}

function groupMessagesPageWithoutMaintenance(int $groupId, int $userId, int $limit = 0, ?int $beforeMessageId = null): array
{
    $clearedAt = groupConversationClearedAt($groupId, $userId);
    $params = [
        'group_id' => $groupId,
        'user_id' => $userId,
    ];
    $sql = 'SELECT gm.*, u.username AS sender_name,
                   rgm.id AS reply_message_id,
                   rgm.sender_id AS reply_sender_id,
                   ru.username AS reply_sender_name,
                   rgm.body AS reply_body,
                   rgm.audio_path AS reply_audio_path,
                   rgm.image_path AS reply_image_path,
                   rgm.file_path AS reply_file_path,
                   rgm.file_name AS reply_file_name,
                   rgm.created_at AS reply_created_at
            FROM group_messages gm
            JOIN users u ON u.id = gm.sender_id
            LEFT JOIN group_messages rgm ON rgm.id = gm.reply_to_message_id
            LEFT JOIN users ru ON ru.id = rgm.sender_id
            LEFT JOIN group_message_deletions gmd ON gmd.message_id = gm.id AND gmd.user_id = :user_id
            WHERE gm.group_id = :group_id
              AND gmd.message_id IS NULL';

    if ($clearedAt !== null) {
        $sql .= ' AND gm.created_at > :cleared_at';
        $params['cleared_at'] = $clearedAt;
    }

    if ($beforeMessageId !== null && $beforeMessageId > 0) {
        $sql .= ' AND gm.id < :before_message_id';
        $params['before_message_id'] = $beforeMessageId;
    }

    if ($limit > 0) {
        $sql .= ' ORDER BY gm.created_at DESC, gm.id DESC LIMIT :limit';
    } else {
        $sql .= ' ORDER BY gm.created_at ASC, gm.id ASC';
    }

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $messages = $stmt->fetchAll();

    if ($limit > 0) {
        $messages = array_reverse($messages);
    }

    return attachReactionsToGroupMessages(array_map('formatGroupMessage', $messages));
}

function groupConversationHasOlderMessagesWithoutMaintenance(int $groupId, int $userId, int $beforeMessageId): bool
{
    $clearedAt = groupConversationClearedAt($groupId, $userId);
    $sql = 'SELECT 1
            FROM group_messages
            WHERE group_id = :group_id
              AND id < :before_message_id';
    $params = [
        'group_id' => $groupId,
        'before_message_id' => $beforeMessageId,
    ];

    if ($clearedAt !== null) {
        $sql .= ' AND created_at > :cleared_at';
        $params['cleared_at'] = $clearedAt;
    }

    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function groupTypingMembersWithoutMaintenance(int $groupId, int $currentUserId): array
{
    $stmt = db()->prepare(
        'SELECT gts.user_id, u.username
         FROM group_typing_status gts
         JOIN users u ON u.id = gts.user_id
         WHERE gts.group_id = :group_id
           AND gts.user_id != :user_id
           AND gts.updated_at >= :cutoff
         ORDER BY u.username ASC'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'user_id' => $currentUserId,
        'cutoff' => gmdate('c', time() - TYPING_TTL_SECONDS),
    ]);

    return array_map(static function (array $row): array {
        return [
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
        ];
    }, $stmt->fetchAll());
}

function updateGroupTypingStatus(int $groupId, int $userId): void
{
    $params = [
        'group_id' => $groupId,
        'user_id' => $userId,
        'updated_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO group_typing_status (group_id, user_id, updated_at)
             VALUES (:group_id, :user_id, :updated_at)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO group_typing_status (group_id, user_id, updated_at)
             VALUES (:group_id, :user_id, :updated_at)
             ON CONFLICT(group_id, user_id)
             DO UPDATE SET updated_at = excluded.updated_at'
        );
    }

    $stmt->execute($params);
}

function clearGroupTypingStatus(int $groupId, int $userId): void
{
    $stmt = db()->prepare(
        'DELETE FROM group_typing_status
         WHERE group_id = :group_id
           AND user_id = :user_id'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'user_id' => $userId,
    ]);
}

function normalizeGroupReplyTargetId(int $groupId, ?int $replyToMessageId): ?int
{
    $messageId = (int) ($replyToMessageId ?? 0);
    if ($messageId <= 0) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id
         FROM group_messages
         WHERE id = :message_id
           AND group_id = :group_id
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'group_id' => $groupId,
    ]);

    return $stmt->fetchColumn() ? $messageId : null;
}

function sendGroupTextMessage(int $groupId, int $userId, string $body, ?int $replyToMessageId = null): array|string
{
    if (!canAccessGroupConversation($groupId, $userId)) {
        return 'Group not found.';
    }

    $trimmedBody = trim($body);
    if ($trimmedBody === '') {
        return 'Message cannot be empty.';
    }

    $replyToMessageId = normalizeGroupReplyTargetId($groupId, $replyToMessageId);
    $stmt = db()->prepare(
        'INSERT INTO group_messages (group_id, sender_id, body, audio_path, image_path, file_path, file_name, reply_to_message_id, created_at)
         VALUES (:group_id, :sender_id, :body, NULL, NULL, NULL, NULL, :reply_to_message_id, :created_at)'
    );
    $createdAt = gmdate('c');
    $stmt->execute([
        'group_id' => $groupId,
        'sender_id' => $userId,
        'body' => encryptStoredMessageText($trimmedBody),
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => $createdAt,
    ]);

    clearGroupTypingStatus($groupId, $userId);

    $messageId = (int) db()->lastInsertId();
    $message = db()->prepare(
        'SELECT gm.*, u.username AS sender_name,
                rgm.id AS reply_message_id,
                rgm.sender_id AS reply_sender_id,
                ru.username AS reply_sender_name,
                rgm.body AS reply_body,
                rgm.audio_path AS reply_audio_path,
                rgm.image_path AS reply_image_path,
                rgm.file_path AS reply_file_path,
                rgm.file_name AS reply_file_name,
                rgm.created_at AS reply_created_at
         FROM group_messages gm
         JOIN users u ON u.id = gm.sender_id
         LEFT JOIN group_messages rgm ON rgm.id = gm.reply_to_message_id
         LEFT JOIN users ru ON ru.id = rgm.sender_id
         WHERE gm.id = :id'
    );
    $message->execute(['id' => $messageId]);
    $row = $message->fetch();

    if (!is_array($row)) {
        return 'Could not send message right now.';
    }

    $formatted = formatGroupMessage($row);
    $withReactions = attachReactionsToGroupMessages([$formatted]);

    return $withReactions[0] ?? $formatted;
}

function sendGroupVoiceMessage(int $groupId, int $userId, array $file, ?int $replyToMessageId = null): array|string
{
    if (!canAccessGroupConversation($groupId, $userId)) {
        return 'Group not found.';
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

    $filename = sprintf('%s_%s.%s', $userId, bin2hex(random_bytes(8)), $extension);
    $target = UPLOAD_PATH . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Could not save the voice note.';
    }

    $replyToMessageId = normalizeGroupReplyTargetId($groupId, $replyToMessageId);
    $stmt = db()->prepare(
        'INSERT INTO group_messages (group_id, sender_id, body, audio_path, image_path, file_path, file_name, reply_to_message_id, created_at)
         VALUES (:group_id, :sender_id, NULL, :audio_path, NULL, NULL, NULL, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'sender_id' => $userId,
        'audio_path' => 'storage/uploads/' . $filename,
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearGroupTypingStatus($groupId, $userId);

    $payload = groupConversationPayload($groupId, $userId);
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

    return $messages !== [] ? $messages[count($messages) - 1] : 'Could not send message right now.';
}

function sendGroupImageMessage(int $groupId, int $userId, array $file, ?int $replyToMessageId = null): array|string
{
    if (!canAccessGroupConversation($groupId, $userId)) {
        return 'Group not found.';
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

    $filename = sprintf('img_%s_%s.%s', $userId, bin2hex(random_bytes(8)), $extension);
    $target = TMP_UPLOAD_PATH . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Could not save the image.';
    }

    $replyToMessageId = normalizeGroupReplyTargetId($groupId, $replyToMessageId);
    $stmt = db()->prepare(
        'INSERT INTO group_messages (group_id, sender_id, body, audio_path, image_path, file_path, file_name, reply_to_message_id, created_at)
         VALUES (:group_id, :sender_id, NULL, NULL, :image_path, NULL, NULL, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'sender_id' => $userId,
        'image_path' => 'storage/tmp/' . $filename,
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearGroupTypingStatus($groupId, $userId);

    $payload = groupConversationPayload($groupId, $userId);
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

    return $messages !== [] ? $messages[count($messages) - 1] : 'Could not send message right now.';
}

function sendGroupFileMessage(int $groupId, int $userId, array $file, ?int $replyToMessageId = null): array|string
{
    if (!canAccessGroupConversation($groupId, $userId)) {
        return 'Group not found.';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'File upload failed. Please choose a file to share.';
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return 'File must be 10MB or smaller.';
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    if ($originalName === '') {
        $originalName = 'shared-file';
    }
    $sanitizedName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName);
    $sanitizedName = trim((string) $sanitizedName, '-.');
    if ($sanitizedName === '') {
        $sanitizedName = 'shared-file';
    }
    $extension = strtolower((string) pathinfo($sanitizedName, PATHINFO_EXTENSION));
    $storedExtension = $extension !== '' ? '.' . substr($extension, 0, 20) : '';
    $filename = sprintf('file_%s_%s%s', $userId, bin2hex(random_bytes(8)), $storedExtension);
    $target = UPLOAD_PATH . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Could not save the file.';
    }

    $replyToMessageId = normalizeGroupReplyTargetId($groupId, $replyToMessageId);
    $stmt = db()->prepare(
        'INSERT INTO group_messages (group_id, sender_id, body, audio_path, image_path, file_path, file_name, reply_to_message_id, created_at)
         VALUES (:group_id, :sender_id, NULL, NULL, NULL, :file_path, :file_name, :reply_to_message_id, :created_at)'
    );
    $stmt->execute([
        'group_id' => $groupId,
        'sender_id' => $userId,
        'file_path' => 'storage/uploads/' . $filename,
        'file_name' => mb_substr($sanitizedName, 0, 255),
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => gmdate('c'),
    ]);

    clearGroupTypingStatus($groupId, $userId);

    $payload = groupConversationPayload($groupId, $userId);
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

    return $messages !== [] ? $messages[count($messages) - 1] : 'Could not send message right now.';
}

function markGroupMessagesRead(int $groupId, int $userId): void
{
    $stmt = db()->prepare(
        'SELECT MAX(id)
         FROM group_messages
         WHERE group_id = :group_id'
    );
    $stmt->execute(['group_id' => $groupId]);
    $lastMessageId = (int) $stmt->fetchColumn();

    $params = [
        'group_id' => $groupId,
        'user_id' => $userId,
        'last_read_message_id' => $lastMessageId > 0 ? $lastMessageId : null,
        'updated_at' => gmdate('c'),
    ];

    if (dbDriver() === 'mysql') {
        $stmt = db()->prepare(
            'INSERT INTO group_message_reads (group_id, user_id, last_read_message_id, updated_at)
             VALUES (:group_id, :user_id, :last_read_message_id, :updated_at)
             ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id),
                                     updated_at = VALUES(updated_at)'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO group_message_reads (group_id, user_id, last_read_message_id, updated_at)
             VALUES (:group_id, :user_id, :last_read_message_id, :updated_at)
             ON CONFLICT(group_id, user_id)
             DO UPDATE SET last_read_message_id = excluded.last_read_message_id,
                           updated_at = excluded.updated_at'
        );
    }

    $stmt->execute($params);
}

function groupConversationPayload(int $groupId, int $userId, int $limit = 0, ?int $beforeMessageId = null): array
{
    $group = findGroupById($groupId);
    if ($group === null || !canAccessGroupConversation($groupId, $userId)) {
        return ['error' => 'Group not found.'];
    }

    touchUserPresence($userId);

    $messages = groupMessagesPageWithoutMaintenance($groupId, $userId, $limit, $beforeMessageId);
    $oldestLoadedId = $messages === [] ? null : (int) ($messages[0]['id'] ?? 0);
    $members = groupMembers($groupId);

    return [
        'group' => [
            'id' => (int) $group['id'],
            'name' => (string) $group['name'],
            'creator_user_id' => (int) $group['creator_user_id'],
            'creator_name' => (string) $group['creator_name'],
            'member_count' => count($members),
            'members' => $members,
            'can_delete' => (int) $group['creator_user_id'] === $userId,
            'can_rename' => (int) $group['creator_user_id'] === $userId,
        ],
        'messages' => $messages,
        'has_more_messages' => $oldestLoadedId !== null && $oldestLoadedId > 0
            ? groupConversationHasOlderMessagesWithoutMaintenance($groupId, $userId, $oldestLoadedId)
            : false,
        'typing_members' => groupTypingMembersWithoutMaintenance($groupId, $userId),
    ];
}

function groupConversationStateSignature(int $groupId, int $userId): string
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS total_messages,
                MAX(id) AS latest_message_id,
                MAX(created_at) AS latest_message_created_at
         FROM group_messages
         WHERE group_id = :group_id'
    );
    $stmt->execute(['group_id' => $groupId]);
    $messageState = $stmt->fetch() ?: [];
    $reactionStmt = db()->prepare(
        'SELECT COUNT(*) AS total_reactions,
                MAX(gmr.updated_at) AS latest_reaction_updated_at
         FROM group_message_reactions gmr
         JOIN group_messages gm ON gm.id = gmr.message_id
         WHERE gm.group_id = :group_id'
    );
    $reactionStmt->execute(['group_id' => $groupId]);
    $reactionState = $reactionStmt->fetch() ?: [];

    $group = findGroupById($groupId);

    return md5(encodeJson([
        'messages' => [
            'total' => (int) ($messageState['total_messages'] ?? 0),
            'latest_id' => (int) ($messageState['latest_message_id'] ?? 0),
            'latest_created_at' => $messageState['latest_message_created_at'] ?? null,
        ],
        'reactions' => [
            'total' => (int) ($reactionState['total_reactions'] ?? 0),
            'latest_updated_at' => $reactionState['latest_reaction_updated_at'] ?? null,
        ],
        'group' => $group === null ? null : [
            'id' => (int) $group['id'],
            'name' => (string) $group['name'],
            'creator_user_id' => (int) $group['creator_user_id'],
        ],
        'members' => array_map(static fn (array $member): array => [
            'user_id' => (int) $member['user_id'],
            'role' => (string) $member['role'],
        ], groupMembers($groupId)),
        'typing_members' => groupTypingMembersWithoutMaintenance($groupId, $userId),
    ]));
}

function groupChats(int $currentUserId): array
{
    $stmt = db()->prepare(
        'SELECT g.id,
                g.name,
                g.creator_user_id,
                MAX(gm.created_at) AS last_message_at,
                MAX(gm.id) AS last_message_id,
                (
                    SELECT gm2.body
                    FROM group_messages gm2
                    WHERE gm2.group_id = g.id
                    ORDER BY gm2.created_at DESC, gm2.id DESC
                    LIMIT 1
                ) AS last_message_body,
                (
                    SELECT COUNT(*)
                    FROM group_messages gm3
                    LEFT JOIN group_message_reads gmr
                      ON gmr.group_id = g.id
                     AND gmr.user_id = :user_id
                    WHERE gm3.group_id = g.id
                      AND gm3.sender_id != :user_id
                      AND gm3.id > COALESCE(gmr.last_read_message_id, 0)
                ) AS unseen_count
         FROM groups g
         JOIN group_members membership
           ON membership.group_id = g.id
          AND membership.user_id = :user_id
          AND membership.status = :status
         LEFT JOIN group_messages gm ON gm.group_id = g.id
         WHERE g.deleted_at IS NULL
         GROUP BY g.id, g.name, g.creator_user_id
         ORDER BY CASE WHEN MAX(gm.created_at) IS NULL THEN 1 ELSE 0 END ASC,
                  MAX(gm.created_at) DESC,
                  MAX(gm.id) DESC,
                  g.name ASC'
    );
    $stmt->execute([
        'user_id' => $currentUserId,
        'status' => 'active',
    ]);

    return array_map(static function (array $group): array {
        $groupId = (int) $group['id'];
        return [
            'id' => $groupId,
            'type' => 'group',
            'is_group' => true,
            'group_id' => $groupId,
            'name' => (string) $group['name'],
            'creator_user_id' => (int) $group['creator_user_id'],
            'username' => (string) $group['name'],
            'last_message_at' => $group['last_message_at'],
            'last_message_id' => (int) ($group['last_message_id'] ?? 0),
            'last_message_body' => $group['last_message_body'],
            'unseen_count' => (int) ($group['unseen_count'] ?? 0),
            'chat_list_time' => formatChatListTime($group['last_message_at'] ?? null),
            'chat_list_preview' => trim((string) decryptStoredMessageText($group['last_message_body'] ?? '')) !== ''
                ? (string) decryptStoredMessageText($group['last_message_body'] ?? '')
                : 'Group created',
            'member_count' => count(groupMembers($groupId)),
            'url' => 'chat.php?group=' . $groupId,
        ];
    }, $stmt->fetchAll());
}

function combinedChatList(int $currentUserId): array
{
    $directChats = array_map(static function (array $chat): array {
        $chat['type'] = 'direct';
        $chat['is_group'] = false;
        $chat['url'] = 'chat.php?user=' . (int) $chat['id'];
        $chat['name'] = (string) $chat['username'];

        return $chat;
    }, chattedUsers($currentUserId));
    $groupChats = groupChats($currentUserId);
    $combined = array_merge($directChats, $groupChats);

    usort($combined, static function (array $left, array $right): int {
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

        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    return $combined;
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
