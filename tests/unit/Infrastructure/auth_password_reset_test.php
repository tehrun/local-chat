<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/assert.php';
require_once __DIR__ . '/../../support/bootstrap_unit.php';

function makeAuthChallenge(int $answer = 7): void
{
    $_SESSION['auth_challenge'] = [
        'left' => $answer,
        'right' => 0,
        'operator' => '+',
        'answer' => $answer,
    ];
}

function createAuthTestUser(string $username, string $password): int
{
    $stmt = db()->prepare(
        'INSERT INTO users (username, password_hash, created_at)
         VALUES (:username, :password_hash, :created_at)'
    );
    $stmt->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => gmdate('c'),
    ]);

    return (int) db()->lastInsertId();
}

return [
    'password reset token validation rejects expired tokens' => static function (): void {
        $username = 'expired_reset_' . bin2hex(random_bytes(4));
        $userId = createAuthTestUser($username, 'ValidPassword123');
        $issued = issuePasswordResetToken($username);

        assertTrue(is_array($issued) && isset($issued['token']));

        $expireStmt = db()->prepare(
            'UPDATE password_reset_tokens
             SET expires_at = :expired_at
             WHERE user_id = :user_id'
        );
        $expireStmt->execute([
            'expired_at' => gmdate('c', time() - 3600),
            'user_id' => $userId,
        ]);

        assertSameValue(validatePasswordResetToken((string) $issued['token']), null);
    },

    'password reset confirm rejects invalid token' => static function (): void {
        $error = confirmPasswordReset('invalid-token-value', 'AnotherValid123', 'AnotherValid123');

        assertSameValue($error, 'Invalid or expired password reset token.');
    },

    'password reset confirm rotates hash and allows login with new password' => static function (): void {
        $username = 'success_reset_' . bin2hex(random_bytes(4));
        $originalPassword = 'OriginalPassword123';
        $newPassword = 'UpdatedPassword456';
        createAuthTestUser($username, $originalPassword);

        $issued = issuePasswordResetToken($username);
        assertTrue(is_array($issued) && isset($issued['token']));

        $token = (string) $issued['token'];
        $error = confirmPasswordReset($token, $newPassword, $newPassword);
        assertSameValue($error, null);

        assertSameValue(validatePasswordResetToken($token), null);

        makeAuthChallenge();
        $oldLoginError = loginUser($username, $originalPassword, '7');
        assertSameValue($oldLoginError, 'Invalid username or password.');

        makeAuthChallenge();
        $newLoginError = loginUser($username, $newPassword, '7');
        assertSameValue($newLoginError, null);
    },
];
