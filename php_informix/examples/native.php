<?php

declare(strict_types=1);

/**
 * Exemplu pentru extensia C ifx_pdo.dll (peste PDO).
 *
 *   php -d extension=pdo_informix -d extension=ifx_pdo examples/native.php
 *
 * Nu se face `require`: funcțiile și resursele vin din extensie.
 */

if (!extension_loaded('ifx_pdo')) {
    fwrite(STDERR, "Extensia ifx_pdo nu este încărcată." . PHP_EOL);
    fwrite(STDERR, "Adăugați extension=ifx_pdo în php.ini sau folosiți -d extension=ifx_pdo." . PHP_EOL);
    exit(2);
}

$dsn = getenv('IFX_EXAMPLE_DSN') ?: getenv('IFX_TEST_DSN');
$user = getenv('IFX_EXAMPLE_USER') ?: getenv('IFX_TEST_USER');
$password = getenv('IFX_EXAMPLE_PASSWORD') ?: getenv('IFX_TEST_PASSWORD');
if ($dsn === false || $dsn === '' || $user === false || $password === false) {
    fwrite(STDERR, 'Setați IFX_EXAMPLE_DSN / IFX_EXAMPLE_USER / IFX_EXAMPLE_PASSWORD.' . PHP_EOL);
    exit(2);
}

$conn = ifx_connect($dsn, $user, $password);
if (!is_resource($conn)) {
    fwrite(STDERR, 'Conectare eșuată: ' . ifx_error() . PHP_EOL);
    exit(1);
}

$res = ifx_query(
    'select tabid, tabname, owner from systables where tabid <= 5 order by tabid',
    $conn
);

if (!is_resource($res)) {
    fwrite(STDERR, 'Query eșuat: ' . ifx_error() . PHP_EOL);
    ifx_close($conn);
    exit(1);
}

echo 'Coloane: ', ifx_num_fields($res), PHP_EOL;
foreach (ifx_fieldtypes($res) as $name => $type) {
    printf("  %-10s %s\n", $name, $type);
}

echo PHP_EOL;
while (($row = ifx_fetch_row($res)) !== false) {
    printf("%4s  %-20s %s\n", $row['tabid'], $row['tabname'], $row['owner']);
}

echo PHP_EOL, 'Rânduri citite: ', ifx_num_rows($res), PHP_EOL;
ifx_free_result($res);
ifx_close($conn);
