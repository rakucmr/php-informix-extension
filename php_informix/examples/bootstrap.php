<?php

declare(strict_types=1);

/**
 * Conexiune comună pentru exemple.
 *
 *   IFX_EXAMPLE_DSN=database@server
 *   IFX_EXAMPLE_USER=<user>
 *   IFX_EXAMPLE_PASSWORD=<password>
 */

require_once __DIR__ . '/../src/autoload.php';

function example_connect(): \Informix\Connection
{
    $dsn = getenv('IFX_EXAMPLE_DSN') ?: getenv('IFX_TEST_DSN');
    $user = getenv('IFX_EXAMPLE_USER') ?: getenv('IFX_TEST_USER');
    $password = getenv('IFX_EXAMPLE_PASSWORD') ?: getenv('IFX_TEST_PASSWORD');
    if ($dsn === false || $dsn === '' || $user === false || $password === false) {
        fwrite(STDERR, 'Setați IFX_EXAMPLE_DSN / IFX_EXAMPLE_USER / IFX_EXAMPLE_PASSWORD.' . PHP_EOL);
        exit(2);
    }

    $connection = ifx_connect($dsn, $user, $password);
    if (!$connection instanceof \Informix\Connection) {
        fwrite(STDERR, 'Conectare eșuată: ' . ifx_error() . PHP_EOL);
        exit(1);
    }

    return $connection;
}
