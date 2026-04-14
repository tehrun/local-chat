<?php

declare(strict_types=1);

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
