<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/assert.php';
require_once __DIR__ . '/../../support/bootstrap_unit.php';

return [
    'requirePositiveInt parses positive integers and rejects invalid values' => static function (): void {
        assertSameValue(requirePositiveInt(['id' => '42'], 'id'), 42);
        assertSameValue(requirePositiveInt(['id' => 7], 'id'), 7);
        assertSameValue(requirePositiveInt(['id' => '-1'], 'id'), 0);
        assertSameValue(requirePositiveInt(['id' => 'abc'], 'id'), 0);
        assertSameValue(requirePositiveInt([], 'id'), 0);
    },

    'storage path validator accepts only allowed relative prefixes' => static function (): void {
        assertTrue(isSafeStorageRelativePath('storage/uploads/file.txt'));
        assertTrue(isSafeStorageRelativePath('storage/tmp/photo.jpg'));
        assertFalse(isSafeStorageRelativePath('storage/other/file.txt'));
        assertFalse(isSafeStorageRelativePath('../storage/uploads/file.txt'));
        assertFalse(isSafeStorageRelativePath("storage/uploads/evil\0name"));
    },

    'deleteStorageFileIfExists deletes safe files and ignores unsafe paths' => static function (): void {
        $safeRelative = 'storage/uploads/test-delete-' . bin2hex(random_bytes(4)) . '.txt';
        $safeAbsolute = BASE_PATH . '/' . $safeRelative;
        @mkdir(dirname($safeAbsolute), 0777, true);
        file_put_contents($safeAbsolute, 'temporary data');
        assertTrue(is_file($safeAbsolute));

        deleteStorageFileIfExists($safeRelative);
        assertFalse(is_file($safeAbsolute));

        $outsideFile = sys_get_temp_dir() . '/local-chat-outside-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outsideFile, 'outside');
        deleteStorageFileIfExists('../../../../' . basename($outsideFile));
        assertTrue(is_file($outsideFile));
        unlink($outsideFile);
    },

    'json encoding uses hardened escaping flags' => static function (): void {
        $payload = ['tag' => "<script>alert('x')</script>"];
        $encoded = encodeJson($payload);

        assertStringContains($encoded, '\\u003Cscript\\u003E');
        assertStringContains($encoded, '\\u0027x\\u0027');
    },

    'dbConfig defaults to sqlite' => static function (): void {
        putenv('CHAT_DB_DRIVER');
        assertSameValue(dbConfig()['driver'], 'sqlite');
    },

    'dbConfig returns mysql details when configured' => static function (): void {
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
    },

    'base64url helpers round-trip values' => static function (): void {
        $original = random_bytes(32);
        $encoded = base64UrlEncode($original);

        assertFalse(str_contains($encoded, '+'));
        assertFalse(str_contains($encoded, '/'));
        assertFalse(str_contains($encoded, '='));
        assertSameValue(base64UrlDecode($encoded), $original);
    },

    'normalizePushSubscription validates required shape' => static function (): void {
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
    },

    'defaultWebPushSubject uses host fallback' => static function (): void {
        $_SERVER['HTTP_HOST'] = 'chat.local.test';
        putenv('CHAT_WEB_PUSH_SUBJECT');

        assertSameValue(defaultWebPushSubject(), 'https://chat.local.test');

        putenv('CHAT_WEB_PUSH_SUBJECT=mailto:admin@example.com');
        assertSameValue(defaultWebPushSubject(), 'mailto:admin@example.com');

        putenv('CHAT_WEB_PUSH_SUBJECT');
    },

    'detectUploadedImageExtension supports common jpg/png/webp mime aliases' => static function (): void {
        assertSameValue(detectUploadedImageExtension(['name' => 'photo.jpg', 'type' => 'image/jpeg'], 'image/jpeg'), 'jpg');
        assertSameValue(detectUploadedImageExtension(['name' => 'photo', 'type' => 'image/jpg'], 'image/jpg'), 'jpg');
        assertSameValue(detectUploadedImageExtension(['name' => 'photo', 'type' => 'image/pjpeg'], 'image/pjpeg'), 'jpg');
        assertSameValue(detectUploadedImageExtension(['name' => 'photo', 'type' => 'image/x-png'], 'image/x-png'), 'png');
        assertSameValue(detectUploadedImageExtension(['name' => 'photo', 'type' => 'image/x-webp'], 'image/x-webp'), 'webp');
    },

    'message encryption round-trips plaintext and leaves empty untouched' => static function (): void {
        putenv('CHAT_MESSAGE_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        assertSameValue(encryptStoredMessageText(''), '');

        $plain = 'secret message ' . bin2hex(random_bytes(4));
        $encrypted = encryptStoredMessageText($plain);
        assertTrue($encrypted !== $plain, 'Encrypted payload should differ from plaintext');
        assertTrue(str_starts_with($encrypted, 'enc::'), 'Encrypted payload should include envelope marker');

        assertSameValue(decryptStoredMessageText($encrypted), $plain);
        assertSameValue(decryptStoredMessageText('plain text'), 'plain text');

        putenv('CHAT_MESSAGE_ENCRYPTION_KEY');
    },

    'decryptStoredMessageText handles malformed encrypted payloads' => static function (): void {
        assertSameValue(decryptStoredMessageText('enc::oops'), '[encrypted message]');
        assertSameValue(decryptStoredMessageText('enc::xchacha20:not-base64'), '[encrypted message]');
    },
];
