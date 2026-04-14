<?php

declare(strict_types=1);

namespace LocalChat\Http;

final class Request
{
    public static function requirePositiveInt(array $source, string $key): int
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
}
