<?php
/**
 * Minimal real-database smoke test for pdo_informix + direct ifx.
 *
 * Required environment:
 *   IFX_TEST_PASSWORD  Informix password (not stored in this file)
 *
 * Optional (example defaults only; set the real values in the environment):
 *   IFX_TEST_HOST      default db.example.invalid
 *   IFX_TEST_PORT      default 9088
 *   IFX_TEST_SERVER    default example_server
 *   IFX_TEST_DATABASE  default example_db
 *   IFX_TEST_USER      default example_user
 */

$host = getenv('IFX_TEST_HOST') ?: 'db.example.invalid';
$port = getenv('IFX_TEST_PORT') ?: '9088';
$server = getenv('IFX_TEST_SERVER') ?: 'example_server';
$database = getenv('IFX_TEST_DATABASE') ?: 'example_db';
$user = getenv('IFX_TEST_USER') ?: 'example_user';
$password = getenv('IFX_TEST_PASSWORD');

$failures = 0;

function ok(string $message): void
{
    echo "OK   $message\n";
}

function fail(string $message): void
{
    global $failures;
    $failures++;
    echo "FAIL $message\n";
}

echo 'PHP ' . PHP_VERSION . ' on ' . (php_uname('s') . ' ' . php_uname('r')) . "\n";
echo 'pdo_informix=' . (extension_loaded('pdo_informix') ? 'yes' : 'no')
    . ' ifx=' . (extension_loaded('ifx') ? 'yes' : 'no')
    . ' ifx_pdo=' . (extension_loaded('ifx_pdo') ? 'yes' : 'no') . "\n";

if (!extension_loaded('pdo_informix')) {
    fail('pdo_informix is not loaded');
}
if (!extension_loaded('ifx')) {
    fail('ifx is not loaded');
}
if (count(get_extension_funcs('ifx') ?: []) !== 39) {
    fail('ifx does not expose exactly 39 functions');
} else {
    ok('ifx exposes 39 functions');
}

if ($password === false) {
    fail('IFX_TEST_PASSWORD is not set');
    exit(1);
}

// ---------------------------------------------------------------------------
// PDO path
// ---------------------------------------------------------------------------
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
    $count = (int) $pdo->query('SELECT COUNT(*) FROM systables')->fetchColumn();
    ok("PDO connected to the configured database; systables=$count");
} catch (Throwable $e) {
    fail('PDO: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Direct ifx path
// ---------------------------------------------------------------------------
if (function_exists('ifx_connect')) {
    $conn = @ifx_connect($database, $user, $password);
    if (!$conn) {
        fail('ifx_connect: ' . ifx_error() . ' / ' . ifx_errormsg());
    } else {
        $result = @ifx_query('SELECT COUNT(*) AS n FROM systables', $conn);
        if (!$result) {
            fail('ifx_query: ' . ifx_error() . ' / ' . ifx_errormsg());
        } else {
            $row = ifx_fetch_row($result);
            ok('ifx connected to the configured database; systables=' . ($row['n'] ?? '?'));
            ifx_free_result($result);
        }
        ifx_close($conn);
    }
}

echo $failures === 0 ? "RESULT: PASS\n" : "RESULT: FAIL ($failures)\n";
exit($failures === 0 ? 0 : 1);
