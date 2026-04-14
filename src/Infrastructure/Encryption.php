<?php

declare(strict_types=1);

namespace LocalChat\Infrastructure;

final class Encryption
{
    public static function messageEncryptionKey(): string
    {
        static $key = null;

        if (is_string($key)) {
            return $key;
        }

        $configured = trim(Environment::value('CHAT_MESSAGE_ENCRYPTION_KEY', ''));
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

    public static function encryptStoredMessageText(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::messageEncryptionKey();

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

    public static function decryptStoredMessageText(?string $ciphertext): ?string
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

        $key = self::messageEncryptionKey();

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
}
