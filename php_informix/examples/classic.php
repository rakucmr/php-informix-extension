<?php

declare(strict_types=1);

/**
 * Exemplu clasic: connect, query, fetch, metadate, erori.
 *
 *   php examples/classic.php
 */

require __DIR__ . '/bootstrap.php';

$conn = example_connect();

echo 'Conectat la ', $conn->database, '@', $conn->server, PHP_EOL;
echo 'DSN: ', $conn->dsn, PHP_EOL;

$result = ifx_query(
    'select tabid, tabname, owner from systables where tabid <= 5 order by tabid',
    $conn
);

if ($result === false) {
    fwrite(STDERR, 'Query eșuat: ' . ifx_error() . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'Coloane: ', ifx_num_fields($result), PHP_EOL;
echo 'Tipuri:', PHP_EOL;
foreach (ifx_fieldtypes($result) as $name => $type) {
    printf("  %-10s %s\n", $name, $type);
}

echo PHP_EOL;
while (($row = ifx_fetch_row($result)) !== false) {
    printf("%4s  %-20s %s\n", $row['tabid'], $row['tabname'], $row['owner']);
}

echo PHP_EOL, 'Rânduri citite: ', ifx_num_rows($result), PHP_EOL;
ifx_free_result($result);

// O eroare controlată: tabelă inexistentă.
$missing = @ifx_query('select * from tabela_inexistenta', $conn);
if ($missing === false) {
    echo PHP_EOL, 'Eroare așteptată: ', ifx_error(), PHP_EOL;
    echo 'Mesaj: ', ifx_errormsg(), PHP_EOL;
}

ifx_close($conn);
