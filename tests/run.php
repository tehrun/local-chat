<?php

declare(strict_types=1);

$testFiles = [
    ...glob(__DIR__ . '/unit/Security/*.php') ?: [],
    ...glob(__DIR__ . '/unit/Infrastructure/*.php') ?: [],
    ...glob(__DIR__ . '/unit/Domain/*.php') ?: [],
    ...glob(__DIR__ . '/integration/*.php') ?: [],
];

sort($testFiles);

$tests = [];

foreach ($testFiles as $testFile) {
    $fileTests = require $testFile;

    if (!is_array($fileTests)) {
        throw new RuntimeException(sprintf('Test file %s must return an array of tests.', $testFile));
    }

    foreach ($fileTests as $name => $test) {
        if (!is_callable($test)) {
            throw new RuntimeException(sprintf('Test "%s" in %s is not callable.', (string) $name, $testFile));
        }

        $tests[(string) $name] = $test;
    }
}

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
