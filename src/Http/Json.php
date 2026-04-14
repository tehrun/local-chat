<?php

declare(strict_types=1);

namespace LocalChat\Http;

final class Json
{
    public static function encodeFlags(): int
    {
        return JSON_THROW_ON_ERROR
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::encodeFlags());
    }

    public static function scriptValue(mixed $value): string
    {
        return self::encode($value);
    }

    public static function response(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo self::encode($payload);
        exit;
    }
}
