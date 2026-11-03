<?php
/**
 * t02 - a connection can be opened and basic metadata queries work.
 */

require __DIR__ . '/bootstrap.php';

try {
    $pdo = connect();
} catch (Throwable $e) {
    check('connect', false, $e->getMessage());
    finish();
}

check('connect returns a PDO instance', $pdo instanceof PDO);
check_equals('driver name', 'informix', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

$version = $pdo->query("SELECT DBINFO('version', 'full') FROM systables WHERE tabid = 1")->fetchColumn();
check('DBINFO(version) returned a string', is_string($version) && $version !== '', var_export($version, true));
if (is_string($version)) {
    echo "        server: {$version}\n";
}

$server = $pdo->query("SELECT DBINFO('dbname') FROM systables WHERE tabid = 1")->fetchColumn();
check('DBINFO(dbname) matches configured database', is_string($server) && $server !== '', var_export($server, true));
echo "        database: {$server}\n";

$one = $pdo->query('SELECT 1 FROM systables WHERE tabid = 1')->fetchColumn();
check_equals('SELECT 1', '1', (string) $one);

finish();
