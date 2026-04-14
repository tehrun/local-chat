<?php

declare(strict_types=1);

namespace LocalChat\Infrastructure;

final class Paths
{
    public static function ensureStorageDirectories(): void
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

        if (!is_dir(AVATAR_UPLOAD_PATH)) {
            mkdir(AVATAR_UPLOAD_PATH, 0777, true);
        }

        if (!is_dir(WEB_PUSH_KEY_PATH)) {
            mkdir(WEB_PUSH_KEY_PATH, 0777, true);
        }
    }

    public static function isSafeStorageRelativePath(?string $relativePath): bool
    {
        if (!is_string($relativePath) || $relativePath === '') {
            return false;
        }

        if (str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
            return false;
        }

        return str_starts_with($relativePath, 'storage/uploads/') || str_starts_with($relativePath, 'storage/tmp/');
    }

    public static function deleteStorageFileIfExists(?string $relativePath): void
    {
        if (!self::isSafeStorageRelativePath($relativePath)) {
            return;
        }

        $absolutePath = BASE_PATH . '/' . ltrim((string) $relativePath, '/');
        if (!is_file($absolutePath)) {
            return;
        }

        @unlink($absolutePath);
    }
}
