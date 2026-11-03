<?php

declare(strict_types=1);

/**
 * Benchmark concurent pentru variantele ifx-compat.
 *
 * Fiecare worker este un proces PHP separat, cu o singură conexiune Informix
 * deschisă. Orchestratorul pornește toți workerii, așteaptă un barrier comun,
 * apoi aceștia rulează același workload timp de N secunde. Rezultatul este un
 * singur JSON: throughput, erori, p50/p95/p99 și conexiuni reușite.
 *
 * Exemple:
 *   php -n -d extension=E:\php\ext\php_pdo_informix.dll `
 *     php_informix/tools/parallel-benchmark.php --mode=pdo --concurrency=50
 *
 *   php -n -d extension=E:\php\ext\ifx.dll `
 *     php_informix/tools/parallel-benchmark.php --mode=direct --concurrency=100
 *
 * `--mode=native` înseamnă varianta `ifx_pdo` (`ifx_pdo.dll` +
 * `pdo_informix`); `--mode=direct` înseamnă varianta `ifx` (`ifx.dll`, fără
 * PDO).
 *
 * Variabile de mediu (nicio credențială în fișier):
 *   IFX_BENCH_DSN, IFX_BENCH_PDO_DSN, IFX_BENCH_USER, IFX_BENCH_PASSWORD
 *   USER și PASSWORD pot fi goale pentru autentificare OS locală.
 *
 * Extensii:
 *   --pdo-ext=<cale sau nume>       implicit php_pdo_informix
 *   --ifx-pdo-ext=<cale sau nume>   implicit ifx_pdo (alias: --ifx-ext)
 *   --direct-ext=<cale sau nume>    implicit ifx
 *   --extension-dir=<dir>           pentru nume fără cale
 *
 * Alte opțiuni:
 *   --concurrency=N                 număr de procese / conexiuni simultane
 *   --duration=SECUNDE              durata după barrier (implicit 5)
 *   --php=<cale php.exe>            binarul PHP folosit de workeri
 *   --keep                          păstrează fișierele temporare
 *
 * Workerii creează fiecare un tabel temporar propriu și fac un mix de
 * SELECT punctual, COUNT(*), scanare, INSERT și UPDATE. Tabelele temporare
 * dispar la închiderea sesiunii; datele aplicației nu sunt atinse.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

const PARALLEL_WORKLOAD = ['point', 'count', 'point', 'scan', 'insert', 'update'];
const PARALLEL_MAX_SAMPLES = 1000;

$options = parallel_parse_args($argv);
if (isset($options['worker'])) {
    parallel_worker_main($options);
    exit(0);
}

parallel_orchestrator_main($options);

function parallel_parse_args(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '--') !== 0) {
            continue;
        }
        $argument = substr($argument, 2);
        if ($argument === '') {
            continue;
        }
        $separator = strpos($argument, '=');
        if ($separator === false) {
            $options[$argument] = true;
            continue;
        }
        $options[substr($argument, 0, $separator)] = substr($argument, $separator + 1);
    }

    return $options;
}

function parallel_required_env(string $name, bool $allowEmpty = false): string
{
    $value = getenv($name);
    if ($value === false || (!$allowEmpty && $value === '')) {
        fwrite(STDERR, "Lipsește variabila de mediu {$name}\n");
        exit(2);
    }

    return $value;
}

function parallel_mode(array $options): string
{
    $mode = isset($options['mode']) ? (string) $options['mode'] : '';
    if (!in_array($mode, ['pdo', 'native', 'library', 'direct'], true)) {
        fwrite(STDERR, "Usage: --mode=pdo|native|library|direct\n");
        exit(2);
    }

    return $mode;
}

function parallel_is_absolute(string $path): bool
{
    return (bool) preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path);
}

function parallel_extension_args(array $options, string $mode): array
{
    $args = [];
    $extensionDir = isset($options['extension-dir'])
        ? (string) $options['extension-dir']
        : (string) ini_get('extension_dir');

    $extensions = [];
    if ($mode !== 'direct') {
        $extensions[] = isset($options['pdo-ext'])
            ? (string) $options['pdo-ext']
            : 'php_pdo_informix';
    }
    if ($mode === 'native') {
        $extensions[] = isset($options['ifx-pdo-ext'])
            ? (string) $options['ifx-pdo-ext']
            : (isset($options['ifx-ext']) ? (string) $options['ifx-ext'] : 'ifx_pdo');
    }
    if ($mode === 'direct') {
        $extensions[] = isset($options['direct-ext'])
            ? (string) $options['direct-ext']
            : (isset($options['ifx-ext']) ? (string) $options['ifx-ext'] : 'ifx');
    }

    $needsExtensionDir = false;
    foreach ($extensions as $extension) {
        if (!parallel_is_absolute($extension)) {
            $needsExtensionDir = true;
            break;
        }
    }
    if ($needsExtensionDir && $extensionDir !== '') {
        $args[] = '-d';
        $args[] = 'extension_dir=' . $extensionDir;
    }
    foreach ($extensions as $extension) {
        $args[] = '-d';
        $args[] = 'extension=' . $extension;
    }

    return $args;
}

function parallel_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            parallel_remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

function parallel_percentile(array $sorted, float $percentile): float
{
    $count = count($sorted);
    if ($count === 0) {
        return 0.0;
    }
    $index = (int) floor(($count - 1) * $percentile);
    $index = max(0, min($count - 1, $index));

    return (float) $sorted[$index];
}

function parallel_orchestrator_main(array $options): void
{
    $mode = parallel_mode($options);
    $concurrency = isset($options['concurrency']) ? (int) $options['concurrency'] : 1;
    $duration = isset($options['duration']) ? (float) $options['duration'] : 5.0;
    $startTimeout = isset($options['start-timeout']) ? (float) $options['start-timeout'] : 120.0;
    $php = isset($options['php']) ? (string) $options['php'] : PHP_BINARY;

    if ($concurrency < 1 || $concurrency > 1000) {
        fwrite(STDERR, "--concurrency trebuie să fie între 1 și 1000\n");
        exit(2);
    }
    if ($duration < 1.0 || $duration > 120.0) {
        fwrite(STDERR, "--duration trebuie să fie între 1 și 120 secunde\n");
        exit(2);
    }
    if (!is_file($php)) {
        fwrite(STDERR, "Binarul PHP nu există: {$php}\n");
        exit(2);
    }

    $dsn = parallel_required_env('IFX_BENCH_DSN');
    $user = parallel_required_env('IFX_BENCH_USER', true);
    $password = parallel_required_env('IFX_BENCH_PASSWORD', true);
    $pdoDsn = getenv('IFX_BENCH_PDO_DSN');
    if ($mode !== 'direct' && ($pdoDsn === false || $pdoDsn === '')) {
        fwrite(STDERR, "Lipsește IFX_BENCH_PDO_DSN pentru modul {$mode}\n");
        exit(2);
    }

    $extensionArgs = parallel_extension_args($options, $mode);
    $baseDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'ifx-par-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
        fwrite(STDERR, "Nu pot crea directorul temporar {$baseDir}\n");
        exit(2);
    }

    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $startFile = $baseDir . DIRECTORY_SEPARATOR . 'start';
    $workers = [];
    $spawnErrors = [];

    for ($index = 1; $index <= $concurrency; $index++) {
        $stdout = $baseDir . DIRECTORY_SEPARATOR . "out-{$index}.json";
        $stderr = $baseDir . DIRECTORY_SEPARATOR . "err-{$index}.log";
        $ready = $baseDir . DIRECTORY_SEPARATOR . "ready-{$index}";
        $command = array_merge(
            [$php, '-n', '-d', 'memory_limit=256M', '-d', 'display_errors=0'],
            $extensionArgs,
            [
                __FILE__,
                '--worker',
                '--mode=' . $mode,
                '--index=' . $index,
                '--dir=' . $baseDir,
                '--duration=' . $duration,
                '--start-timeout=' . $startTimeout,
            ]
        );
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            $spawnErrors[] = "worker {$index}: proc_open a eșuat";
            continue;
        }
        $workers[$index] = [
            'process' => $process,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'ready' => $ready,
            'closed' => false,
        ];
    }

    $readyDeadline = microtime(true) + max(30.0, $startTimeout);
    while (true) {
        $readyCount = 0;
        $runningCount = 0;
        foreach ($workers as $worker) {
            if (is_file($worker['ready'])) {
                $readyCount++;
            }
            if (!$worker['closed']) {
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    $runningCount++;
                }
            }
        }
        if ($readyCount >= count($workers) || microtime(true) >= $readyDeadline) {
            break;
        }
        if ($runningCount === 0) {
            break;
        }
        usleep(50000);
    }

    $startAt = microtime(true) + 1.0;
    file_put_contents($startFile, sprintf('%.6F', $startAt));

    $finishDeadline = $startAt + $duration + max(30.0, $startTimeout);
    while (true) {
        $runningCount = 0;
        foreach ($workers as $index => $worker) {
            if ($worker['closed']) {
                continue;
            }
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                $runningCount++;
                continue;
            }
            proc_close($worker['process']);
            $workers[$index]['closed'] = true;
        }
        if ($runningCount === 0 || microtime(true) >= $finishDeadline) {
            break;
        }
        usleep(50000);
    }

    $timedOut = false;
    foreach ($workers as $index => $worker) {
        if (!$worker['closed']) {
            $timedOut = true;
            @proc_terminate($worker['process']);
            @proc_close($worker['process']);
            $workers[$index]['closed'] = true;
        }
    }

    $results = [];
    $workersOk = 0;
    $failedWorkers = 0;
    $connectionFailures = 0;
    $totalOps = 0;
    $totalErrors = 0;
    $byType = [];
    $latencies = [];
    $connectTimes = [];
    $lastFinished = $startAt;
    $workerErrors = [];

    foreach ($workers as $index => $worker) {
        $raw = @file_get_contents($worker['stdout']);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $failedWorkers++;
            $stderr = @file_get_contents($worker['stderr']);
            $workerErrors[] = "worker {$index}: output JSON invalid"
                . (is_string($stderr) && trim($stderr) !== '' ? ' / ' . trim($stderr) : '');
            continue;
        }
        $results[] = $decoded;
        if (empty($decoded['ok'])) {
            $failedWorkers++;
            if (($decoded['phase'] ?? '') === 'connect') {
                $connectionFailures++;
            }
            if (!empty($decoded['error'])) {
                $workerErrors[] = "worker {$index}: {$decoded['error']}";
            }
            continue;
        }
        $workersOk++;
        $totalOps += (int) ($decoded['ops'] ?? 0);
        $totalErrors += (int) ($decoded['errors'] ?? 0);
        foreach (($decoded['by_type'] ?? []) as $type => $count) {
            $byType[$type] = ($byType[$type] ?? 0) + (int) $count;
        }
        if (isset($decoded['connect_ms'])) {
            $connectTimes[] = (float) $decoded['connect_ms'];
        }
        foreach (($decoded['latencies_us'] ?? []) as $value) {
            $latencies[] = (float) $value;
        }
        if (isset($decoded['finished_at'])) {
            $lastFinished = max($lastFinished, (float) $decoded['finished_at']);
        }
    }

    sort($latencies, SORT_NUMERIC);
    $wall = max(0.001, $lastFinished - $startAt);
    $connectAverage = $connectTimes === [] ? 0.0 : array_sum($connectTimes) / count($connectTimes);

    $summary = [
        'mode' => $mode,
        'concurrency' => $concurrency,
        'duration_s' => $duration,
        'workers_started' => count($workers),
        'workers_ok' => $workersOk,
        'workers_failed' => $failedWorkers,
        'connection_failures' => $connectionFailures,
        'total_ops' => $totalOps,
        'errors' => $totalErrors,
        'error_rate' => $totalOps + $totalErrors > 0
            ? round($totalErrors / ($totalOps + $totalErrors), 6)
            : 0.0,
        'ops_per_sec' => round($totalOps / $wall, 3),
        'wall_s' => round($wall, 3),
        'by_type' => $byType,
        'latency_ms' => [
            'p50' => round(parallel_percentile($latencies, 0.50) / 1000.0, 4),
            'p95' => round(parallel_percentile($latencies, 0.95) / 1000.0, 4),
            'p99' => round(parallel_percentile($latencies, 0.99) / 1000.0, 4),
            'max' => $latencies === [] ? 0.0 : round(max($latencies) / 1000.0, 4),
        ],
        'connect_ms' => [
            'avg' => round($connectAverage, 3),
            'max' => $connectTimes === [] ? 0.0 : round(max($connectTimes), 3),
        ],
        'latency_samples' => count($latencies),
        'timed_out' => $timedOut,
        'spawn_errors' => $spawnErrors,
        'worker_errors' => array_slice(array_values(array_unique($workerErrors)), 0, 20),
        'temp_dir' => $baseDir,
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if (!isset($options['keep'])) {
        parallel_remove_tree($baseDir);
    }
}

function parallel_worker_main(array $options): void
{
    $mode = parallel_mode($options);
    $index = isset($options['index']) ? (int) $options['index'] : 1;
    $directory = isset($options['dir']) ? (string) $options['dir'] : '';
    $duration = isset($options['duration']) ? (float) $options['duration'] : 5.0;
    $startTimeout = isset($options['start-timeout']) ? (float) $options['start-timeout'] : 120.0;

    if ($directory === '' || !is_dir($directory)) {
        fwrite(STDERR, "Directorul workerului nu există\n");
        exit(2);
    }

    $dsn = parallel_required_env('IFX_BENCH_DSN');
    $user = parallel_required_env('IFX_BENCH_USER', true);
    $password = parallel_required_env('IFX_BENCH_PASSWORD', true);
    $pdoDsn = getenv('IFX_BENCH_PDO_DSN');
    if ($mode !== 'direct' && ($pdoDsn === false || $pdoDsn === '')) {
        fwrite(STDERR, "Lipsește IFX_BENCH_PDO_DSN\n");
        exit(2);
    }

    $readyFile = $directory . DIRECTORY_SEPARATOR . "ready-{$index}";
    $startFile = $directory . DIRECTORY_SEPARATOR . 'start';
    $connection = null;
    $table = 'bench_par_' . getmypid() . '_' . $index;
    $result = [
        'index' => $index,
        'pid' => getmypid(),
        'ok' => false,
        'phase' => 'connect',
        'connect_ms' => 0.0,
        'ops' => 0,
        'errors' => 0,
        'by_type' => [],
        'latencies_us' => [],
        'error' => null,
        'started_at' => null,
        'finished_at' => microtime(true),
    ];

    try {
        $connectStart = microtime(true);
        $connection = parallel_connect($mode, $dsn, (string) $pdoDsn, $user, $password);
        $result['connect_ms'] = round((microtime(true) - $connectStart) * 1000.0, 3);
        $result['phase'] = 'setup';

        parallel_exec_sql($mode, $connection, "CREATE TEMP TABLE {$table} (id INTEGER, payload CHAR(64))");
        parallel_exec_sql($mode, $connection, "INSERT INTO {$table} (id, payload) VALUES (0, 'seed')");
        $result['ok'] = true;
        $result['phase'] = 'barrier';
        file_put_contents($readyFile, '1');
    } catch (Throwable $error) {
        $result['error'] = $error->getMessage();
        file_put_contents($readyFile, '0');
        echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    try {
        $waitUntil = microtime(true) + $startTimeout;
        while (!is_file($startFile)) {
            if (microtime(true) >= $waitUntil) {
                throw new RuntimeException('timeout așteptând barrier-ul de start');
            }
            usleep(10000);
        }
        $startAt = (float) trim((string) file_get_contents($startFile));
        while (microtime(true) < $startAt) {
            usleep(10000);
        }
        $result['started_at'] = microtime(true);
        $result['phase'] = 'workload';
        $endAt = $startAt + $duration;
        $insertId = 0;
        $sampleCount = 0;

        while (microtime(true) < $endAt && $result['errors'] < 50) {
            foreach (PARALLEL_WORKLOAD as $type) {
                if (microtime(true) >= $endAt || $result['errors'] >= 50) {
                    break;
                }
                $started = microtime(true);
                try {
                    if ($type === 'point') {
                        parallel_query_rows(
                            $mode,
                            $connection,
                            'SELECT tabid, tabname FROM systables WHERE tabid = 1'
                        );
                    } elseif ($type === 'count') {
                        parallel_query_rows(
                            $mode,
                            $connection,
                            'SELECT COUNT(*) AS n FROM systables'
                        );
                    } elseif ($type === 'scan') {
                        parallel_query_rows(
                            $mode,
                            $connection,
                            'SELECT tabid, tabname, owner FROM systables WHERE tabid <= 110'
                        );
                    } elseif ($type === 'insert') {
                        $insertId++;
                        parallel_exec_sql(
                            $mode,
                            $connection,
                            "INSERT INTO {$table} (id, payload) VALUES ({$insertId}, 'payload')"
                        );
                    } elseif ($type === 'update') {
                        parallel_exec_sql(
                            $mode,
                            $connection,
                            "UPDATE {$table} SET payload = 'updated' WHERE id = 0"
                        );
                    }
                    $latency = (microtime(true) - $started) * 1000000.0;
                    $result['ops']++;
                    $result['by_type'][$type] = ($result['by_type'][$type] ?? 0) + 1;
                    $sampleCount++;
                    if (count($result['latencies_us']) < PARALLEL_MAX_SAMPLES) {
                        $result['latencies_us'][] = $latency;
                    } else {
                        $slot = random_int(0, $sampleCount - 1);
                        if ($slot < PARALLEL_MAX_SAMPLES) {
                            $result['latencies_us'][$slot] = $latency;
                        }
                    }
                } catch (Throwable $error) {
                    $result['errors']++;
                    $result['error'] = $error->getMessage();
                    usleep(10000);
                }
            }
        }

        try {
            parallel_exec_sql($mode, $connection, "DROP TABLE {$table}");
        } catch (Throwable $ignored) {
            // Tabela temporară dispare oricum la închiderea sesiunii.
        }
        $result['finished_at'] = microtime(true);
    } catch (Throwable $error) {
        $result['ok'] = false;
        $result['error'] = $error->getMessage();
        $result['finished_at'] = microtime(true);
    }

    if ($connection !== null) {
        try {
            parallel_close($mode, $connection);
        } catch (Throwable $ignored) {
            // Închiderea nu trebuie să ascundă rezultatul.
        }
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function parallel_connect(string $mode, string $dsn, string $pdoDsn, string $user, string $password)
{
    if ($mode === 'pdo') {
        $pdo = new PDO($pdoDsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return $pdo;
    }

    if ($mode === 'library') {
        require_once dirname(__DIR__) . '/src/autoload.php';
    }

    $connection = ifx_connect($dsn, $user, $password);
    if ($connection === false) {
        throw new RuntimeException('ifx_connect a eșuat: ' . ifx_errormsg());
    }

    return $connection;
}

function parallel_close(string $mode, $connection): void
{
    if ($mode === 'pdo') {
        $connection = null;
        return;
    }
    ifx_close($connection);
}

function parallel_query_rows(string $mode, $connection, string $sql): array
{
    if ($mode === 'pdo') {
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new RuntimeException('PDO query a eșuat');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    $result = ifx_query($sql, $connection);
    if ($result === false) {
        throw new RuntimeException('ifx_query a eșuat: ' . ifx_errormsg());
    }
    $rows = [];
    while (($row = ifx_fetch_row($result)) !== false) {
        $rows[] = $row;
    }
    ifx_free_result($result);

    return $rows;
}

function parallel_exec_sql(string $mode, $connection, string $sql): int
{
    if ($mode === 'pdo') {
        $affected = $connection->exec($sql);
        if ($affected === false) {
            throw new RuntimeException('PDO exec a eșuat');
        }

        return (int) $affected;
    }

    $result = ifx_query($sql, $connection);
    if ($result === false) {
        throw new RuntimeException('ifx_query a eșuat: ' . ifx_errormsg());
    }
    $affected = ifx_affected_rows($result);
    ifx_free_result($result);

    return (int) $affected;
}
