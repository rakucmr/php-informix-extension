<?php

declare(strict_types=1);

/**
 * Exemplu de cursor scroll.
 *
 *   php examples/scroll.php
 */

require __DIR__ . '/bootstrap.php';

$conn = example_connect();

$result = ifx_query(
    'select tabid, tabname from systables where tabid between 1 and 5 order by tabid',
    $conn,
    IFX_SCROLL
);

if ($result === false) {
    fwrite(STDERR, 'Query eșuat: ' . ifx_error() . PHP_EOL);
    exit(1);
}

$show = static function (mixed $row, string $label): void {
    if ($row === false || $row === null) {
        echo $label, ': (niciun rând)', PHP_EOL;

        return;
    }
    echo $label, ': ', $row['tabid'], ' ', $row['tabname'], PHP_EOL;
};

$show(ifx_fetch_row($result, 'FIRST'), 'FIRST');
$show(ifx_fetch_row($result, 'NEXT'), 'NEXT');
$show(ifx_fetch_row($result, 'LAST'), 'LAST');
$show(ifx_fetch_row($result, 3), 'ABS 3');
$show(ifx_fetch_row($result, -1), 'ABS -1');
$show(ifx_fetch_row($result, 'PRIOR'), 'PRIOR');
$show(ifx_fetch_row($result, 'CURRENT'), 'CURRENT');

echo PHP_EOL, 'Fetch-uri efectuate: ', ifx_num_rows($result), PHP_EOL;

ifx_free_result($result);

// IFX_HOLD este acceptat, dar nu păstrează cursorul după COMMIT.
echo PHP_EOL, 'Notă: IFX_HOLD nu supraviețuiește unui COMMIT; re-executați query-ul.', PHP_EOL;

ifx_close($conn);
