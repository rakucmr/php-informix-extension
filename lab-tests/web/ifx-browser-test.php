<?php
/**
 * Browser smoke test for pdo_informix + direct ifx.
 *
 * The page reads test settings from the service environment:
 *   INFORMIXDIR, INFORMIXSERVER, INFORMIXSQLHOSTS
 *   IFX_TEST_HOST, IFX_TEST_PORT, IFX_TEST_SERVER,
 *   IFX_TEST_DATABASE, IFX_TEST_USER, IFX_TEST_PASSWORD
 *
 * Do not put the password in the URL or in the document root.
 */

header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/ifx-all-types-lib.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Example defaults only. The real values come from the service environment.
$host = getenv('IFX_TEST_HOST') ?: 'db.example.invalid';
$port = getenv('IFX_TEST_PORT') ?: '9088';
$server = getenv('IFX_TEST_SERVER') ?: 'example_server';
$database = getenv('IFX_TEST_DATABASE') ?: 'example_db';
$user = getenv('IFX_TEST_USER') ?: 'example_user';
$password = getenv('IFX_TEST_PASSWORD');

$checks = [];
$pdoCount = null;
$ifxCount = null;

$checks[] = [
    'name' => 'PHP version',
    'ok' => version_compare(PHP_VERSION, '8.1', '>='),
    'detail' => PHP_VERSION,
];

$checks[] = [
    'name' => 'pdo_informix loaded',
    'ok' => extension_loaded('pdo_informix'),
    'detail' => extension_loaded('pdo_informix') ? 'yes' : 'no',
];

$checks[] = [
    'name' => 'ifx loaded',
    'ok' => extension_loaded('ifx'),
    'detail' => extension_loaded('ifx') ? 'yes' : 'no',
];

$checks[] = [
    'name' => 'ifx_pdo not loaded',
    'ok' => !extension_loaded('ifx_pdo'),
    'detail' => extension_loaded('ifx_pdo') ? 'loaded (conflict)' : 'no',
];

$ifxFunctions = get_extension_funcs('ifx') ?: [];
$checks[] = [
    'name' => '39 ifx functions',
    'ok' => count($ifxFunctions) === 39,
    'detail' => count($ifxFunctions) . ' functions',
];

$pdoFunctions = get_extension_funcs('pdo_informix') ?: [];
$checks[] = [
    'name' => 'pdo_informix exposes no ifx_* functions',
    'ok' => count($pdoFunctions) === 0,
    'detail' => count($pdoFunctions) . ' functions',
];

$pdoError = null;
$ifxError = null;

if ($password === false) {
    $pdoError = 'IFX_TEST_PASSWORD is not set in the service environment';
    $ifxError = $pdoError;
} else {
    $dsn = sprintf(
        'informix:server=%s;database=%s;host=%s;service=%s;protocol=olsoctcp;',
        $server,
        $database,
        $host,
        $port
    );

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdoCount = (int) $pdo->query('SELECT COUNT(*) FROM systables')->fetchColumn();
    } catch (Throwable $e) {
        $pdoError = $e->getMessage();
    }

    $ifxDsn = getenv('IFX_TEST_IFX_DSN') ?: $database;
    $conn = @ifx_connect($ifxDsn, $user, $password);
    if (!$conn) {
        $ifxError = trim(ifx_error() . ' ' . ifx_errormsg());
    } else {
        $result = @ifx_query('SELECT COUNT(*) AS n FROM systables', $conn);
        if (!$result) {
            $ifxError = trim(ifx_error() . ' ' . ifx_errormsg());
        } else {
            $row = ifx_fetch_row($result);
            $ifxCount = isset($row['n']) ? (int) $row['n'] : null;
            ifx_free_result($result);
        }
    }
}

$checks[] = [
    'name' => 'PDO connection to the configured database',
    'ok' => $pdoError === null,
    'detail' => $pdoError ?? ('systables=' . $pdoCount),
];

$checks[] = [
    'name' => 'direct ifx connection to the configured database',
    'ok' => $ifxError === null,
    'detail' => $ifxError ?? ('systables=' . $ifxCount),
];

if (isset($pdo, $conn) && $pdo instanceof PDO && is_resource($conn) && $ifxError === null) {
    $allTypes = ifx_run_all_types($pdo, $conn);
    $checks = array_merge($checks, $allTypes['checks']);
    if (is_resource($conn)) {
        ifx_close($conn);
    }
} else {
    $checks[] = [
        'name' => 'all-types table test',
        'ok' => false,
        'detail' => 'skipped because the PDO or ifx connection failed',
    ];
}

$failed = count(array_filter($checks, static fn(array $check): bool => !$check['ok']));
$overall = $failed === 0;

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Informix PHP smoke test</title>
<style>
    :root { color-scheme: dark; }
    body {
        margin: 0;
        font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        background: #111827;
        color: #e5e7eb;
    }
    main { max-width: 900px; margin: 40px auto; padding: 0 20px; }
    h1 { margin-bottom: 4px; }
    .meta { color: #9ca3af; margin-bottom: 24px; }
    .badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 999px;
        font-weight: 700;
        background: <?= $overall ? '#14532d' : '#7f1d1d' ?>;
        color: <?= $overall ? '#86efac' : '#fecaca' ?>;
    }
    table { width: 100%; border-collapse: collapse; margin-top: 24px; }
    th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #374151; }
    th { color: #9ca3af; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; }
    tr.ok td:first-child { color: #86efac; }
    tr.fail td:first-child { color: #fca5a5; }
    code { background: #1f2937; padding: 2px 6px; border-radius: 4px; }
    .note { margin-top: 24px; padding: 14px 16px; background: #1f2937; border-left: 3px solid #60a5fa; }
</style>
</head>
<body>
<main>
    <h1>Informix PHP smoke test</h1>
    <div class="meta">
        <?= h(php_uname('s') . ' ' . php_uname('r')) ?> ·
        PHP <code><?= h(PHP_VERSION) ?></code> ·
        <?= $overall ? '<span class="badge">PASS</span>' : '<span class="badge">FAIL</span>' ?>
    </div>

    <table>
        <thead>
            <tr><th>Check</th><th>Result</th></tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr class="<?= $check['ok'] ? 'ok' : 'fail' ?>">
                <td><?= $check['ok'] ? 'OK' : 'FAIL' ?> — <?= h($check['name']) ?></td>
                <td><code><?= h($check['detail']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="note">
        <strong>Configuration:</strong> supplied by the service environment
        and intentionally not displayed.
        <br>
        <strong>PDO drivers:</strong>
        <code><?= h(implode(', ', PDO::getAvailableDrivers())) ?></code>.
    </div>
</main>
</body>
</html>
