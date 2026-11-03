<?php

declare(strict_types=1);

/**
 * Benchmark reproductibil pentru variantele ifx-compat:
 *
 *   php benchmark.php --mode=pdo --rounds=3
 *   php benchmark.php --mode=native --rounds=3
 *   php benchmark.php --mode=library --rounds=3
 *   php benchmark.php --mode=direct --rounds=3
 *
 * Mediul (nu se scrie nicio credențială în fișier):
 *
 *   IFX_BENCH_DSN       DSN pentru ifx_connect(), de ex. "baza@server"
 *   IFX_BENCH_PDO_DSN   DSN complet pentru PDO, de ex.
 *                       "informix:server=server;database=baza;host=127.0.0.1;service=9088;protocol=olsoctcp;"
 *   IFX_BENCH_USER
 *   IFX_BENCH_PASSWORD
 *
 * USER și PASSWORD pot fi goale pentru autentificare OS locală.
 *
 * Modul `library` încarcă php_informix/src/autoload.php. Modul `native`
 * înseamnă `ifx_pdo` și presupune că `ifx_pdo.dll` este deja încărcat
 * împreună cu `php_pdo_informix.dll`. Modul `direct` înseamnă `ifx` și
 * presupune că este încărcat `ifx.dll` (CSDK CLI, fără PDO). Modul `pdo`
 * folosește numai driverul PDO.
 *
 * Rezultatul este JSON pe stdout; erorile merg pe stderr.
 */

$mode = '';
$rounds = 1;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--mode=') === 0) {
        $mode = substr($arg, 7);
    } elseif (strpos($arg, '--rounds=') === 0) {
        $rounds = max(1, (int) substr($arg, 9));
    }
}

if (!in_array($mode, ['pdo', 'native', 'library', 'direct'], true)) {
    fwrite(STDERR, "Usage: php benchmark.php --mode=pdo|native|library|direct [--rounds=N]\n");
    exit(2);
}

$dsn = getenv('IFX_BENCH_DSN');
$pdoDsn = getenv('IFX_BENCH_PDO_DSN');
$user = getenv('IFX_BENCH_USER');
$password = getenv('IFX_BENCH_PASSWORD');

if ($dsn === false || $dsn === '') {
    fwrite(STDERR, "Lipsește IFX_BENCH_DSN\n");
    exit(2);
}
if ($mode !== 'direct' && ($pdoDsn === false || $pdoDsn === '')) {
    fwrite(STDERR, "Lipsește IFX_BENCH_PDO_DSN\n");
    exit(2);
}
if ($user === false || $password === false) {
    fwrite(STDERR, "Lipsesc IFX_BENCH_USER / IFX_BENCH_PASSWORD\n");
    exit(2);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

if ($mode === 'library') {
    require dirname(__DIR__) . '/src/autoload.php';
}

if ($mode === 'pdo') {
    if (!in_array('informix', PDO::getAvailableDrivers(), true)) {
        fwrite(STDERR, "Driverul PDO informix nu este disponibil\n");
        exit(2);
    }
} elseif ($mode === 'direct') {
    if (!extension_loaded('ifx') || !function_exists('ifx_connect')) {
        fwrite(STDERR, "Extensia ifx nu este încărcată\n");
        exit(2);
    }
} elseif ($mode === 'native') {
    if (!extension_loaded('ifx_pdo') || !function_exists('ifx_connect')) {
        fwrite(STDERR, "Extensia ifx_pdo nu este încărcată\n");
        exit(2);
    }
} elseif (!function_exists('ifx_connect')) {
    fwrite(STDERR, "Funcțiile ifx_* nu sunt disponibile pentru modul {$mode}\n");
    exit(2);
}

/**
 * @param callable():mixed $operation
 * @return array{ms:float,ops:int,ms_per_op:float}
 */
function timed($operation, int $operations): array
{
    $start = microtime(true);
    for ($i = 0; $i < $operations; $i++) {
        $operation($i);
    }
    $ms = (microtime(true) - $start) * 1000.0;

    return [
        'ms' => round($ms, 3),
        'ops' => $operations,
        'ms_per_op' => round($ms / $operations, 6),
    ];
}

function failBench(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * @return array{row:array<string,mixed>|false}
 */
function pdoRow(PDO $pdo, string $sql): array
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        failBench('PDO query failed: ' . $sql);
    }
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $statement->closeCursor();

    return is_array($row) ? $row : [];
}

/**
 * @return array<string,mixed>|false
 */
function ifxRow($connection, string $sql, string $function = 'ifx_query')
{
    $result = \ifx_query($sql, $connection);
    if ($result === false) {
        failBench('ifx_query failed: ' . $sql . ' / ' . \ifx_errormsg());
    }
    $row = \ifx_fetch_row($result);
    \ifx_free_result($result);

    return $row;
}

function pdoExec(PDO $pdo, string $sql): void
{
    if ($pdo->exec($sql) === false) {
        failBench('PDO exec failed: ' . $sql);
    }
}

function ifxExec($connection, string $sql): void
{
    $result = \ifx_query($sql, $connection);
    if ($result === false) {
        failBench('ifx_query failed: ' . $sql . ' / ' . \ifx_errormsg());
    }
    \ifx_free_result($result);
}

$metrics = [];
$checks = [];
$scriptStart = microtime(true);
$pointSql = 'SELECT tabname FROM systables WHERE tabid = 1';
$countSql = 'SELECT COUNT(*) AS n FROM systables';
$scanSql = 'SELECT tabid, tabname, owner FROM systables';

for ($round = 1; $round <= $rounds; $round++) {
    $roundMetrics = [];

    // 1. Deschidere + închidere de conexiune.
    $connectOps = 40;
    for ($i = 0; $i < 2; $i++) {
        if ($mode === 'pdo') {
            $warm = new PDO($pdoDsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $warm = null;
        } else {
            $warm = \ifx_connect($dsn, $user, $password);
            if ($warm === false) {
                failBench('ifx_connect failed: ' . \ifx_errormsg());
            }
            \ifx_close($warm);
        }
    }
    $roundMetrics['connect_close'] = timed(function () use ($mode, $pdoDsn, $dsn, $user, $password): void {
        if ($mode === 'pdo') {
            $pdo = new PDO($pdoDsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo = null;
        } else {
            $connection = \ifx_connect($dsn, $user, $password);
            if ($connection === false) {
                failBench('ifx_connect failed: ' . \ifx_errormsg());
            }
            \ifx_close($connection);
        }
    }, $connectOps);

    // O conexiune comună pentru restul operațiilor.
    if ($mode === 'pdo') {
        $connection = new PDO($pdoDsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $connection = \ifx_connect($dsn, $user, $password);
        if ($connection === false) {
            failBench('ifx_connect failed: ' . \ifx_errormsg());
        }
    }

    // 2. SELECT punctual, un rând, de N ori.
    $pointOps = 1500;
    for ($i = 0; $i < 10; $i++) {
        if ($mode === 'pdo') {
            pdoRow($connection, $pointSql);
        } else {
            ifxRow($connection, $pointSql);
        }
    }
    $roundMetrics['point_select'] = timed(function () use ($mode, $connection, $pointSql): void {
        $row = $mode === 'pdo' ? pdoRow($connection, $pointSql) : ifxRow($connection, $pointSql);
        if ($row === [] || $row === false) {
            failBench('point select returned no row');
        }
    }, $pointOps);

    // 3. Agregare COUNT(*), de N ori.
    $countOps = 400;
    for ($i = 0; $i < 5; $i++) {
        if ($mode === 'pdo') {
            pdoRow($connection, $countSql);
        } else {
            ifxRow($connection, $countSql);
        }
    }
    $roundMetrics['count_aggregate'] = timed(function () use ($mode, $connection, $countSql): void {
        $row = $mode === 'pdo' ? pdoRow($connection, $countSql) : ifxRow($connection, $countSql);
        if ($row === [] || $row === false) {
            failBench('count aggregate returned no value');
        }
        $value = array_values($row);
        if (!isset($value[0]) || !is_numeric($value[0])) {
            failBench('count aggregate returned no numeric value');
        }
    }, $countOps);

    // 4. Scanare completă a catalogului și numărare rânduri.
    $scanOps = 150;
    $scanRows = 0;
    $roundMetrics['scan_catalog'] = timed(function () use ($mode, $connection, $scanSql, &$scanRows): void {
        $rows = 0;
        if ($mode === 'pdo') {
            $statement = $connection->query($scanSql);
            if ($statement === false) {
                failBench('PDO scan query failed');
            }
            while ($statement->fetch(PDO::FETCH_ASSOC)) {
                $rows++;
            }
            $statement->closeCursor();
        } else {
            $result = \ifx_query($scanSql, $connection);
            if ($result === false) {
                failBench('ifx scan query failed: ' . \ifx_errormsg());
            }
            while (\ifx_fetch_row($result) !== false) {
                $rows++;
            }
            \ifx_free_result($result);
        }
        $scanRows = max($scanRows, $rows);
    }, $scanOps);

    // 5. Scrieri pe o tabelă temporară, ca să nu atingem datele aplicației.
    $writeOps = 250;
    $table = 'bench_stress_' . getmypid();
    $payload = str_repeat('x', 40);
    $ddl = "CREATE TEMP TABLE {$table} (id INTEGER, payload CHAR(64))";
    if ($mode === 'pdo') {
        pdoExec($connection, $ddl);
    } else {
        ifxExec($connection, $ddl);
    }

    for ($i = 0; $i < 5; $i++) {
        $sql = "INSERT INTO {$table} (id, payload) VALUES ({$i}, '{$payload}')";
        if ($mode === 'pdo') {
            pdoExec($connection, $sql);
        } else {
            ifxExec($connection, $sql);
        }
    }

    $insertId = 5;
    $roundMetrics['temp_insert'] = timed(function () use ($mode, $connection, $table, $payload, &$insertId): void {
        $insertId++;
        $sql = "INSERT INTO {$table} (id, payload) VALUES ({$insertId}, '{$payload}')";
        if ($mode === 'pdo') {
            pdoExec($connection, $sql);
        } else {
            ifxExec($connection, $sql);
        }
    }, $writeOps);

    $updateId = -1;
    $roundMetrics['temp_update'] = timed(function () use ($mode, $connection, $table, &$updateId): void {
        $updateId++;
        $sql = "UPDATE {$table} SET payload = 'updated' WHERE id = {$updateId}";
        if ($mode === 'pdo') {
            pdoExec($connection, $sql);
        } else {
            ifxExec($connection, $sql);
        }
    }, $writeOps);

    // Curățare + verificare finală.
    if ($mode === 'pdo') {
        $row = pdoRow($connection, "SELECT COUNT(*) AS n FROM {$table}");
        pdoExec($connection, "DROP TABLE {$table}");
    } else {
        $row = ifxRow($connection, "SELECT COUNT(*) AS n FROM {$table}");
        ifxExec($connection, "DROP TABLE {$table}");
        \ifx_close($connection);
    }
    $rowValues = $row === false ? [] : array_values($row);
    if (!isset($rowValues[0]) || (int) $rowValues[0] !== $writeOps + 5) {
        failBench('temp table verification failed');
    }

    $checks['point_rows'] = $pointOps;
    $checks['count_rows'] = $countOps;
    $checks['scan_rows'] = $scanOps;
    $checks['scan_verified'] = $scanRows;
    $checks['temp_rows'] = (int) $rowValues[0];

    foreach ($roundMetrics as $name => $metric) {
        $metrics[$name][] = $metric;
    }
}

$output = [
    'mode' => $mode,
    'php' => PHP_VERSION,
    'zts' => defined('PHP_ZTS') && PHP_ZTS ? 'ZTS' : 'NTS',
    'os' => PHP_OS_FAMILY,
    'rounds' => $rounds,
    'metrics' => $metrics,
    'checks' => $checks,
    'duration_ms' => round((microtime(true) - $scriptStart) * 1000.0, 1),
    'memory_peak_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
