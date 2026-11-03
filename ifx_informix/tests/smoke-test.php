<?php

declare(strict_types=1);

/**
 * Smoke test pentru extensia ifx (CSDK CLI, fără PDO).
 *
 *   php -n -d extension=ifx smoke-test.php
 *
 * Mediul: IFX_TEST_DSN, IFX_TEST_USER, IFX_TEST_PASSWORD.
 * Nu scrie credențiale în fișier și nu atinge datele aplicației.
 */

$dsn = getenv('IFX_TEST_DSN');
$user = getenv('IFX_TEST_USER');
$password = getenv('IFX_TEST_PASSWORD');

if ($dsn === false || $user === false || $password === false) {
    fwrite(STDERR, "Lipsesc IFX_TEST_DSN / IFX_TEST_USER / IFX_TEST_PASSWORD\n");
    exit(2);
}
if (!extension_loaded('ifx')) {
    fwrite(STDERR, "Extensia ifx nu este încărcată\n");
    exit(2);
}

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

$expectedFunctions = [
    'ifx_connect', 'ifx_pconnect', 'ifx_close', 'ifx_query', 'ifx_prepare',
    'ifx_do', 'ifx_fetch_row', 'ifx_free_result', 'ifx_affected_rows',
    'ifx_num_rows', 'ifx_num_fields', 'ifx_fieldtypes', 'ifx_fieldproperties',
    'ifx_error', 'ifx_errormsg', 'ifx_getsqlca', 'ifx_htmltbl_result',
    'ifx_create_blob', 'ifx_copy_blob', 'ifx_free_blob', 'ifx_get_blob',
    'ifx_update_blob', 'ifx_blobinfile_mode', 'ifx_create_char',
    'ifx_free_char', 'ifx_get_char', 'ifx_update_char', 'ifxus_create_slob',
    'ifxus_open_slob', 'ifxus_close_slob', 'ifxus_free_slob',
    'ifxus_read_slob', 'ifxus_write_slob', 'ifxus_seek_slob',
    'ifxus_tell_slob', 'ifx_textasvarchar', 'ifx_byteasvarchar',
    'ifx_charasvarchar', 'ifx_nullformat',
];
$missingFunctions = array_values(array_diff($expectedFunctions, get_extension_funcs('ifx') ?: []));
check('API complet (39 funcții)', $missingFunctions === [], implode(', ', $missingFunctions));

function query($connection, string $sql)
{
    $result = ifx_query($sql, $connection);
    if ($result === false) {
        fwrite(STDERR, "SQL failed: {$sql} / " . ifx_errormsg() . "\n");
        exit(1);
    }

    return $result;
}

$connection = ifx_connect($dsn, $user, $password);
check('ifx_connect', is_resource($connection), ifx_errormsg());
if (!is_resource($connection)) {
    exit(1);
}

$result = query($connection, 'SELECT tabid, tabname FROM systables WHERE tabid = 1');
$row = ifx_fetch_row($result);
check('fetch primul rând', is_array($row) && $row !== []);
check('tabid = 1', isset($row['tabid']) && (string) $row['tabid'] === '1');
ifx_free_result($result);

$table = 'ifx_smoke_' . getmypid();
query($connection, "CREATE TEMP TABLE {$table} (id INTEGER, payload CHAR(32))");
for ($i = 0; $i < 3; $i++) {
    $result = query($connection, "INSERT INTO {$table} (id, payload) VALUES ({$i}, 'smoke')");
    ifx_free_result($result);
}
$result = query($connection, "SELECT COUNT(*) AS n FROM {$table}");
$row = ifx_fetch_row($result);
check('trei rânduri inserate', is_array($row) && (int) array_values($row)[0] === 3);
check('ifx_num_rows după un fetch', ifx_num_rows($result) === 1);
ifx_free_result($result);

$result = query($connection, "UPDATE {$table} SET payload = 'updated' WHERE id = 1");
check('update un rând', ifx_affected_rows($result) === 1);
ifx_free_result($result);

$result = query($connection, "UPDATE {$table} SET payload = 'nope' WHERE id = 99");
check('update fără rânduri nu este eroare', ifx_affected_rows($result) === 0);
ifx_free_result($result);

$result = query($connection, "DROP TABLE {$table}");
ifx_free_result($result);

check('ifx_close', ifx_close($connection) === true);

echo $failures === 0 ? "\nOK\n" : "\n{$failures} eșec(uri)\n";
exit($failures === 0 ? 0 : 1);
