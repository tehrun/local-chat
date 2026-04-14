<?php

declare(strict_types=1);

namespace LocalChat\Infrastructure;

final class Environment
{
    public static function value(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}
