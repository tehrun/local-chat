<?php

declare(strict_types=1);

session_start();

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('DB_PATH', STORAGE_PATH . '/db/chat.sqlite');
define('MESSAGE_TTL_SECONDS', 24 * 60 * 60);

if (!is_dir(dirname(DB_PATH))) {
    mkdir(dirname(DB_PATH), 0777, true);
}

if (!is_dir(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0777, true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initializeDatabase($pdo);

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
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
            created_at TEXT NOT NULL,
            FOREIGN KEY(sender_id) REFERENCES users(id),
            FOREIGN KEY(recipient_id) REFERENCES users(id)
        )'
    );
}

function purgeExpiredMessages(): void
{
    $cutoff = gmdate('c', time() - MESSAGE_TTL_SECONDS);
    $pdo = db();

    $stmt = $pdo->prepare('SELECT audio_path FROM messages WHERE created_at < :cutoff AND audio_path IS NOT NULL');
    $stmt->execute(['cutoff' => $cutoff]);

    foreach ($stmt->fetchAll() as $row) {
        $path = BASE_PATH . '/' . $row['audio_path'];
        if (is_file($path)) {
            unlink($path);
        }
    }

    $delete = $pdo->prepare('DELETE FROM messages WHERE created_at < :cutoff');
    $delete->execute(['cutoff' => $cutoff]);
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, username, created_at FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);

    return $stmt->fetch() ?: null;
}

function requireAuth(): array
{
    $user = currentUser();

    if ($user === null) {
        header('Location: /?login=required');
        exit;
    }

    return $user;
}

function allOtherUsers(int $currentUserId): array
{
    $stmt = db()->prepare('SELECT id, username, created_at FROM users WHERE id != :id ORDER BY username ASC');
    $stmt->execute(['id' => $currentUserId]);

    return $stmt->fetchAll();
}

function findUserByUsername(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => trim($username)]);

    return $stmt->fetch() ?: null;
}

function findUserById(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, username, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);

    return $stmt->fetch() ?: null;
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

    $_SESSION['user_id'] = $user['id'];

    return null;
}

function conversationMessages(int $userId, int $otherUserId): array
{
    purgeExpiredMessages();

    $stmt = db()->prepare(
        'SELECT m.*, u.username AS sender_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE ((sender_id = :user_id AND recipient_id = :other_id)
            OR (sender_id = :other_id AND recipient_id = :user_id))
         ORDER BY m.created_at ASC'
    );

    $stmt->execute([
        'user_id' => $userId,
        'other_id' => $otherUserId,
    ]);

    return $stmt->fetchAll();
}

function sendTextMessage(int $senderId, int $recipientId, string $body): ?string
{
    purgeExpiredMessages();

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

    return null;
}

function sendVoiceMessage(int $senderId, int $recipientId, array $file): ?string
{
    purgeExpiredMessages();

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Voice upload failed. Please attach an audio file.';
    }

    $allowedMimeTypes = [
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm',
        'audio/mp4' => 'm4a',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimeTypes[$mime])) {
        return 'Unsupported audio type. Use mp3, wav, ogg, webm, or m4a.';
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return 'Voice note must be 10MB or smaller.';
    }

    $extension = $allowedMimeTypes[$mime];
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

    return null;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
