<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function assertSameValue(mixed $actual, mixed $expected, string $message = ''): void
{
    if ($actual !== $expected) {
        $prefix = $message !== '' ? $message . ' - ' : '';
        throw new RuntimeException($prefix . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = 'Expected condition to be true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertFalse(bool $condition, string $message = 'Expected condition to be false'): void
{
    if ($condition) {
        throw new RuntimeException($message);
    }
}

function assertStringContains(string $haystack, string $needle, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $prefix = $message !== '' ? $message . ' - ' : '';
        throw new RuntimeException($prefix . 'Failed asserting that "' . $haystack . '" contains "' . $needle . '"');
    }
}

$tests = [];

$tests['requirePositiveInt parses positive integers and rejects invalid values'] = static function (): void {
    assertSameValue(requirePositiveInt(['id' => '42'], 'id'), 42);
    assertSameValue(requirePositiveInt(['id' => 7], 'id'), 7);
    assertSameValue(requirePositiveInt(['id' => '-1'], 'id'), 0);
    assertSameValue(requirePositiveInt(['id' => 'abc'], 'id'), 0);
    assertSameValue(requirePositiveInt([], 'id'), 0);
};

$tests['storage path validator accepts only allowed relative prefixes'] = static function (): void {
    assertTrue(isSafeStorageRelativePath('storage/uploads/file.txt'));
    assertTrue(isSafeStorageRelativePath('storage/tmp/photo.jpg'));
    assertFalse(isSafeStorageRelativePath('storage/other/file.txt'));
    assertFalse(isSafeStorageRelativePath('../storage/uploads/file.txt'));
    assertFalse(isSafeStorageRelativePath("storage/uploads/evil\0name"));
};

$tests['deleteStorageFileIfExists deletes safe files and ignores unsafe paths'] = static function (): void {
    $safeRelative = 'storage/uploads/test-delete-' . bin2hex(random_bytes(4)) . '.txt';
    $safeAbsolute = BASE_PATH . '/' . $safeRelative;
    file_put_contents($safeAbsolute, 'temporary data');
    assertTrue(is_file($safeAbsolute));

    deleteStorageFileIfExists($safeRelative);
    assertFalse(is_file($safeAbsolute));

    $outsideFile = sys_get_temp_dir() . '/local-chat-outside-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($outsideFile, 'outside');
    deleteStorageFileIfExists('../../../../' . basename($outsideFile));
    assertTrue(is_file($outsideFile));
    unlink($outsideFile);
};

$tests['csrf token generation and verification works'] = static function (): void {
    unset($_SESSION['csrf_token']);

    $token = csrfToken();
    assertTrue(is_string($token) && strlen($token) === 64, 'CSRF token should be a 64-char hex string');
    assertTrue(verifyCsrfToken($token));
    assertFalse(verifyCsrfToken('not-the-token'));
    assertFalse(verifyCsrfToken(null));
};

$tests['json encoding uses hardened escaping flags'] = static function (): void {
    $payload = ['tag' => "<script>alert('x')</script>"];
    $encoded = encodeJson($payload);

    assertStringContains($encoded, '\\u003Cscript\\u003E');
    assertStringContains($encoded, '\\u0027x\\u0027');
};

$tests['dbConfig defaults to sqlite'] = static function (): void {
    putenv('CHAT_DB_DRIVER');
    assertSameValue(dbConfig()['driver'], 'sqlite');
};

$tests['dbConfig returns mysql details when configured'] = static function (): void {
    putenv('CHAT_DB_DRIVER=mysql');
    putenv('CHAT_DB_HOST=db.internal');
    putenv('CHAT_DB_PORT=3307');
    putenv('CHAT_DB_NAME=chat_db');
    putenv('CHAT_DB_USER=chat_user');
    putenv('CHAT_DB_PASS=secret');

    $config = dbConfig();
    assertSameValue($config['driver'], 'mysql');
    assertSameValue($config['host'], 'db.internal');
    assertSameValue($config['port'], '3307');
    assertSameValue($config['name'], 'chat_db');
    assertSameValue($config['username'], 'chat_user');
    assertSameValue($config['password'], 'secret');

    putenv('CHAT_DB_DRIVER');
    putenv('CHAT_DB_HOST');
    putenv('CHAT_DB_PORT');
    putenv('CHAT_DB_NAME');
    putenv('CHAT_DB_USER');
    putenv('CHAT_DB_PASS');
};

$tests['base64url helpers round-trip values'] = static function (): void {
    $original = random_bytes(32);
    $encoded = base64UrlEncode($original);

    assertFalse(str_contains($encoded, '+'));
    assertFalse(str_contains($encoded, '/'));
    assertFalse(str_contains($encoded, '='));
    assertSameValue(base64UrlDecode($encoded), $original);
};

$tests['normalizePushSubscription validates required shape'] = static function (): void {
    $normalized = normalizePushSubscription([
        'endpoint' => 'https://push.example.com/abc',
        'keys' => [
            'p256dh' => 'key-a',
            'auth' => 'key-b',
        ],
        'contentEncoding' => 'aesgcm',
    ]);

    assertSameValue($normalized, [
        'endpoint' => 'https://push.example.com/abc',
        'p256dh' => 'key-a',
        'auth' => 'key-b',
        'content_encoding' => 'aesgcm',
    ]);

    assertSameValue(normalizePushSubscription(['endpoint' => 'not-a-url']), null);
    assertSameValue(normalizePushSubscription(['endpoint' => 'https://push.example.com']), null);
};

$tests['defaultWebPushSubject uses host fallback'] = static function (): void {
    $_SERVER['HTTP_HOST'] = 'chat.local.test';
    putenv('CHAT_WEB_PUSH_SUBJECT');

    assertSameValue(defaultWebPushSubject(), 'https://chat.local.test');

    putenv('CHAT_WEB_PUSH_SUBJECT=mailto:admin@example.com');
    assertSameValue(defaultWebPushSubject(), 'mailto:admin@example.com');

    putenv('CHAT_WEB_PUSH_SUBJECT');
};

$tests['normalizeMessageSearchQuery trims spaces and enforces limits'] = static function (): void {
    assertSameValue(normalizeMessageSearchQuery('   hello   world   '), 'hello world');
    assertSameValue(normalizeMessageSearchQuery(' '), null);
    assertSameValue(normalizeMessageSearchQuery('a'), null);

    $long = str_repeat('x', 120);
    $normalized = normalizeMessageSearchQuery($long);
    assertTrue(is_string($normalized));
    assertSameValue(strlen($normalized), 80);
};

$tests['messageSearchLimit clamps values into safe bounds'] = static function (): void {
    assertSameValue(messageSearchLimit(0), 20);
    assertSameValue(messageSearchLimit(-5), 20);
    assertSameValue(messageSearchLimit(1), 1);
    assertSameValue(messageSearchLimit(999), 50);
};

$tests['escapeLikePattern escapes wildcard characters'] = static function (): void {
    $escaped = escapeLikePattern('10%_\\done');
    assertSameValue($escaped, '10\\%\\_\\\\done');
};

$tests['normalizeReactionEmoji trims and limits grapheme payload length'] = static function (): void {
    assertSameValue(normalizeReactionEmoji('   😀  '), '😀');
    assertSameValue(normalizeReactionEmoji(''), '');
    assertSameValue(mb_strlen(normalizeReactionEmoji(str_repeat('😀', 25))), 16);
};

$tests['presenceLabel reports online, offline and formatted offline time states'] = static function (): void {
    assertSameValue(presenceLabel(null), 'Offline');
    assertSameValue(presenceLabel('not-a-date'), 'Offline');
    assertSameValue(presenceLabel(gmdate('c')), 'Online');

    $oldTimestamp = time() - (PRESENCE_TTL_SECONDS + 3600);
    $label = presenceLabel(gmdate('c', $oldTimestamp));
    assertStringContains($label, ' at ');
};

$tests['formatChatListTime handles invalid and valid timestamps'] = static function (): void {
    assertSameValue(formatChatListTime(null), '');
    assertSameValue(formatChatListTime(''), '');
    assertSameValue(formatChatListTime('invalid'), '');
    assertSameValue(formatChatListTime('2025-01-01T15:06:07+00:00'), '15:06');
};

$tests['chatListPreview prioritizes body then attachment indicators'] = static function (): void {
    assertSameValue(chatListPreview(['last_message_body' => 'hello']), 'hello');
    assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_image_path' => 'storage/tmp/1.jpg']), '📷 Photo');
    assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_audio_path' => 'storage/uploads/1.m4a']), '🎤 Voice message');
    assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_file_path' => 'storage/uploads/1.pdf']), '📎 File');
    assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_attachment_expired' => 1]), 'Attachment expired');
    assertSameValue(chatListPreview(['last_message_body' => '']), 'Start chatting');
};

$tests['groupChatListPreview prioritizes body then attachment indicators'] = static function (): void {
    assertSameValue(groupChatListPreview(['last_message_body' => 'hello group']), 'hello group');
    assertSameValue(groupChatListPreview(['last_message_body' => '', 'last_message_image_path' => 'storage/tmp/1.jpg']), '📷 Photo');
    assertSameValue(groupChatListPreview(['last_message_body' => '', 'last_message_audio_path' => 'storage/uploads/1.m4a']), '🎤 Voice message');
    assertSameValue(groupChatListPreview(['last_message_body' => '', 'last_message_file_path' => 'storage/uploads/1.pdf']), '📎 File');
    assertSameValue(groupChatListPreview(['last_message_body' => '']), 'Group created');
};

$tests['message encryption round-trips plaintext and leaves empty untouched'] = static function (): void {
    assertSameValue(encryptStoredMessageText(''), '');

    $plain = 'secret message ' . bin2hex(random_bytes(4));
    $encrypted = encryptStoredMessageText($plain);
    assertTrue($encrypted !== $plain, 'Encrypted payload should differ from plaintext');
    assertTrue(str_starts_with($encrypted, 'enc::'), 'Encrypted payload should include envelope marker');

    assertSameValue(decryptStoredMessageText($encrypted), $plain);
    assertSameValue(decryptStoredMessageText('plain text'), 'plain text');
};

$tests['decryptStoredMessageText handles malformed encrypted payloads'] = static function (): void {
    assertSameValue(decryptStoredMessageText('enc::oops'), '[encrypted message]');
    assertSameValue(decryptStoredMessageText('enc::xchacha20:not-base64'), '[encrypted message]');
};

$tests['detectUploadedAudioExtension accepts uppercase file extensions'] = static function (): void {
    $file = [
        'name' => 'VOICE-NOTE.M4A',
        'type' => '',
    ];

    assertSameValue(detectUploadedAudioExtension($file, 'application/octet-stream'), 'm4a');
};

$passed = 0;
$failed = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "[FAIL] {$name}\n  " . $error->getMessage() . "\n");
    }
}

fwrite(STDOUT, "\n{$passed} passed, {$failed} failed\n");

exit($failed > 0 ? 1 : 0);
