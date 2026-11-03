<?php
/**
 * Test runner.
 *
 * Runs every t*.php file in this directory with the same PHP binary and the same
 * pdo_informix extension settings that were used to start the runner.
 *
 * Usage:
 *   php -d extension_dir=<dir> -d extension=pdo_informix run-tests.php
 *   php run-tests.php --filter=t03
 */

require __DIR__ . '/bootstrap.php';

$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    }
}

if (!extension_loaded('pdo_informix')) {
    fwrite(STDERR, "pdo_informix is not loaded - start the runner with -d extension=pdo_informix\n");
    exit(2);
}

$args = pdo_informix_child_args();

$files = glob(__DIR__ . '/t*.php') ?: [];
sort($files);

$failed = [];
$started = microtime(true);

foreach ($files as $file) {
    $name = basename($file, '.php');

    if ($filter !== null && !str_contains($name, $filter)) {
        continue;
    }

    echo "== {$name} ==\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' ' . escapeshellarg($file);
    $rc = 0;
    passthru($cmd, $rc);

    if ($rc !== 0) {
        $failed[] = $name;
    }

    echo "\n";
}

$elapsed = number_format(microtime(true) - $started, 2);
$total = count($files);

if ($failed === []) {
    echo "ALL PASSED ({$total} test file(s), {$elapsed}s)\n";
    exit(0);
}

echo 'FAILED: ' . implode(', ', $failed) . " ({$elapsed}s)\n";
exit(1);
