<?php
/**
 * Rulează toate testele t*.php și salvează rezultatul fiecăruia în tabela
 * `test_results` din baza Informix, apoi afișează ce s-a scris.
 *
 * Usage:
 *   php -d extension_dir=<dir> -d extension=pdo_informix report.php
 *   php report.php --filter=t03
 *
 * Conexiunea de raportare se ia din config.php ('report') și poate fi
 * suprascrisă cu PDO_INFORMIX_REPORT_DSN/_USER/_PASS/_TABLE.
 */

require __DIR__ . '/bootstrap.php';

$cfg = pdo_informix_config();
$rep = $cfg['report'];
$table = $rep['table'];

$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    }
}

if (!extension_loaded('pdo_informix')) {
    fwrite(STDERR, "pdo_informix nu este încărcat (pornește cu -d extension=pdo_informix)\n");
    exit(2);
}

$pdo = new PDO($rep['dsn'], $rep['user'], $rep['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ddl = "CREATE TABLE {$table} (
    id            SERIAL            NOT NULL PRIMARY KEY,
    run_id        CHAR(36)          NOT NULL,
    executed_at   DATETIME YEAR TO SECOND NOT NULL,
    test_name     VARCHAR(64)       NOT NULL,
    result        CHAR(6),
    assertions    SMALLINT,
    failures      SMALLINT,
    exit_code     SMALLINT,
    duration_ms   INTEGER,
    os_name       VARCHAR(32),
    php_version   VARCHAR(32),
    ext_version   VARCHAR(16),
    server_name   VARCHAR(96),
    db_name       VARCHAR(32),
    os_user       VARCHAR(32),
    message       LVARCHAR(1024)
)";

if (!table_exists($pdo, $table)) {
    try {
        $pdo->exec($ddl . ' IN datadbs');
        echo "Tabela {$table} a fost creată (datadbs).\n";
    } catch (PDOException $e) {
        $pdo->exec($ddl);
        echo "Tabela {$table} a fost creată (dbspace implicit).\n";
    }
}

$serverName = trim((string) $pdo->query("SELECT DBINFO('version', 'full') FROM systables WHERE tabid = 1")->fetchColumn());
$dbName = trim((string) $pdo->query("SELECT DBINFO('dbname') FROM systables WHERE tabid = 1")->fetchColumn());
$osUser = trim((string) $pdo->query('SELECT USER FROM systables WHERE tabid = 1')->fetchColumn());
$extVersion = (string) (phpversion('pdo_informix') ?: 'unknown');

$runId = sprintf(
    '%08x-%04x-%04x-%04x-%012x',
    time(),
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0xffffffffffff)
);

$files = glob(__DIR__ . '/t*.php') ?: [];
sort($files);

$insert = $pdo->prepare(
    "INSERT INTO {$table}
        (run_id, executed_at, test_name, result, assertions, failures, exit_code,
         duration_ms, os_name, php_version, ext_version, server_name, db_name, os_user, message)
     VALUES (?, CURRENT, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$args = pdo_informix_child_args();

echo "run_id: {$runId}\n";
echo "server: {$serverName} / db; user {$osUser}\n\n";

$failedRuns = 0;
$totalAssertions = 0;

foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($filter !== null && !str_contains($name, $filter)) {
        continue;
    }

    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . implode(' ', array_map('escapeshellarg', $args))
        . ' ' . escapeshellarg($file) . ' 2>&1';

    $lines = [];
    $exit = 0;
    $started = microtime(true);
    exec($cmd, $lines, $exit);
    $duration = (int) round((microtime(true) - $started) * 1000);

    $assertions = 0;
    $failures = 0;
    $message = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, 'ok ')) {
            $assertions++;
        } elseif (str_starts_with($trimmed, 'FAIL ')) {
            $assertions++;
            $failures++;
            if ($message === '') {
                $message = $trimmed;
            }
        } elseif ($message === '' && str_starts_with($trimmed, 'FAILED:')) {
            $message = $trimmed;
        }
    }

    $totalAssertions += $assertions;
    $result = $exit === 0 ? 'PASS' : 'FAIL';
    if ($exit !== 0) {
        $failedRuns++;
    }

    $insert->execute([
        $runId,
        $name,
        $result,
        $assertions,
        $failures,
        $exit,
        $duration,
        PHP_OS_FAMILY,
        PHP_VERSION,
        $extVersion,
        $serverName,
        $dbName,
        $osUser,
        $message,
    ]);

    printf(
        "  %-14s %s  %2d assertions, %d failed, %5d ms%s\n",
        $name,
        $result,
        $assertions,
        $failures,
        $duration,
        $message !== '' ? "  <- {$message}" : ''
    );
}

echo "\nScris în tabela {$table} (run_id {$runId}).\n\n";

$rows = $pdo->prepare(
    "SELECT test_name, result, assertions, failures, duration_ms
       FROM {$table} WHERE run_id = ? ORDER BY id"
);
$rows->execute([$runId]);
$stored = array_map('row_lower', $rows->fetchAll());

printf("%-16s %-6s %10s %8s %10s\n", 'TEST', 'REZ', 'ASSERTIONS', 'FAILED', 'MS');
printf("%s\n", str_repeat('-', 54));
foreach ($stored as $row) {
    printf(
        "%-16s %-6s %10d %8d %10d\n",
        $row['test_name'],
        trim((string) $row['result']),
        (int) $row['assertions'],
        (int) $row['failures'],
        (int) $row['duration_ms']
    );
}

$total = count($stored);
exit($failedRuns === 0 ? 0 : 1);
