<?php

declare(strict_types=1);

session_start();

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('TMP_UPLOAD_PATH', STORAGE_PATH . '/tmp');
define('DB_PATH', STORAGE_PATH . '/db/chat.sqlite');
define('MESSAGE_TTL_SECONDS', 24 * 60 * 60);
define('TYPING_TTL_SECONDS', 8);
define('PRESENCE_TTL_SECONDS', 90);
define('PURGE_INTERVAL_SECONDS', 30);

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
    $stmt = db()->prepare(
        'INSERT INTO user_presence (user_id, updated_at)
         VALUES (:user_id, :updated_at)
         ON CONFLICT(user_id)
         DO UPDATE SET updated_at = excluded.updated_at'
    );
    $stmt->execute([
        'user_id' => $userId,
        'updated_at' => gmdate('c'),
    ]);
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

    $stmt = db()->prepare(
        'INSERT INTO friend_requests (sender_id, recipient_id, status, created_at, responded_at)
         VALUES (:sender_id, :recipient_id, :status, :created_at, NULL)
         ON CONFLICT(sender_id, recipient_id)
         DO UPDATE SET status = excluded.status,
                       created_at = excluded.created_at,
                       responded_at = NULL'
    );
    $stmt->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'status' => 'pending',
        'created_at' => gmdate('c'),
    ]);

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

    $_SESSION['user_id'] = $user['id'];

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

function conversationMessagesWithoutMaintenance(int $userId, int $otherUserId): array
{
    $stmt = db()->prepare(
        'SELECT m.*, u.username AS sender_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE ((sender_id = :user_id AND recipient_id = :other_id)
            OR (sender_id = :other_id AND recipient_id = :user_id))
         ORDER BY m.created_at ASC, m.id ASC'
    );

    $stmt->execute([
        'user_id' => $userId,
        'other_id' => $otherUserId,
    ]);

    return array_map(static fn (array $message): array => formatMessage($message), $stmt->fetchAll());
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

    $stmt = db()->prepare(
        'INSERT INTO typing_status (user_id, conversation_user_id, updated_at)
         VALUES (:user_id, :conversation_user_id, :updated_at)
         ON CONFLICT(user_id, conversation_user_id)
         DO UPDATE SET updated_at = excluded.updated_at'
    );
    $stmt->execute([
        'user_id' => $userId,
        'conversation_user_id' => $otherUserId,
        'updated_at' => gmdate('c'),
    ]);
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

function conversationPayload(int $userId, int $otherUserId): array
{
    purgeExpiredMessages();

    touchUserPresence($userId);
    $allowed = canUsersChat($userId, $otherUserId);

    if ($allowed) {
        markMessagesDelivered($userId, $otherUserId);
    }

    $otherUser = findUserById($otherUserId);

    return [
        'messages' => conversationMessagesWithoutMaintenance($userId, $otherUserId),
        'typing' => $allowed ? isUserTypingWithoutMaintenance($userId, $otherUserId) : false,
        'can_chat' => $allowed,
        'friendship' => friendshipRecord($userId, $otherUserId),
        'presence' => [
            'is_online' => (bool) ($otherUser['is_online'] ?? false),
            'label' => $otherUser['presence_label'] ?? 'Offline',
        ],
    ];
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
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
