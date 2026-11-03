<?php

declare(strict_types=1);

/**
 * Runner minimal pentru testele de integrare.
 *
 *   php -d extension=pdo_informix tests/run-tests.php [--filter=substring]
 *
 * Nu depinde de PHPUnit; rulează cu `php -n` + extensia pdo_informix.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

$filter = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--filter=')) {
        $filter = substr($argument, 9);
    }
}

try {
    reset_state();
    $setup = ifx_test_connect();
    ensure_test_schema($setup);
    ifx_close($setup);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: baza de test nu este disponibilă: {$e->getMessage()}\n");
    exit(1);
}
reset_state();

$files = glob(__DIR__ . '/[0-9][0-9]_*.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter !== null && !str_contains(basename($file), $filter)) {
        continue;
    }
    echo "\n== " . basename($file) . " ==\n";
    reset_state();
    try {
        require $file;
    } catch (\Throwable $e) {
        fail_test('(fișier ' . basename($file) . ')', get_class($e) . ': ' . $e->getMessage(), $e);
    }
}

$stats = $GLOBALS['__ifx_stats'];
echo "\n----------------------------------------\n";
printf("Teste: %d trecute, %d eșuate, %d sărite\n", $stats['pass'], $stats['fail'], $stats['skip']);
if ($stats['fail'] > 0) {
    echo "\nEșecuri:\n";
    foreach ($GLOBALS['__ifx_failures'] as $failure) {
        echo ' - ' . $failure['name'] . ': ' . $failure['message'] . "\n";
    }
    exit(1);
}
echo "OK\n";
exit(0);
