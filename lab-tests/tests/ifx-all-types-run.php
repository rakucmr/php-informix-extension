<?php
require __DIR__ . '/ifx-all-types-lib.php';

// Example defaults only. Set the IFX_TEST_* variables for the real test host.
$host = getenv('IFX_TEST_HOST') ?: 'db.example.invalid';
$port = getenv('IFX_TEST_PORT') ?: '9088';
$server = getenv('IFX_TEST_SERVER') ?: 'example_server';
$db = getenv('IFX_TEST_DATABASE') ?: 'example_db';
$user = getenv('IFX_TEST_USER') ?: 'example_user';
$pass = getenv('IFX_TEST_PASSWORD');
if ($pass === false) {
    fwrite(STDERR, "IFX_TEST_PASSWORD missing\n");
    exit(2);
}

$pdo = new PDO(
    "informix:server={$server};database={$db};host={$host};service={$port};protocol=olsoctcp;",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$ifxDsn = getenv('IFX_TEST_IFX_DSN') ?: $db;
$ifx = ifx_connect($ifxDsn, $user, $pass);
if (!$ifx) {
    fwrite(STDERR, 'ifx connect: ' . ifx_error() . ' ' . ifx_errormsg() . "\n");
    exit(2);
}

$result = ifx_run_all_types($pdo, $ifx);
$failed = 0;
foreach ($result['checks'] as $check) {
    if (!$check['ok']) {
        $failed++;
    }
    echo ($check['ok'] ? 'OK   ' : 'FAIL ') . $check['name'];
    if ($check['detail'] !== '') {
        echo ' [' . $check['detail'] . ']';
    }
    echo "\n";
}
echo $failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL ({$failed})\n";
if (is_resource($ifx)) {
    ifx_close($ifx);
}
exit($failed === 0 ? 0 : 1);
