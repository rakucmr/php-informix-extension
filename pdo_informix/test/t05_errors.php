<?php
/**
 * t05 - errors are surfaced as PDOException with a SQLSTATE code.
 */

require __DIR__ . '/bootstrap.php';

$table = 'pdo_t_err';
$pdo = connect();

try {
    drop_table($pdo, $table);
    $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, label VARCHAR(20))");
    $pdo->exec("INSERT INTO {$table} VALUES (1, 'first')");

    $caught = null;
    try {
        $pdo->exec("INSERT INTO {$table} VALUES (1, 'duplicate')");
    } catch (PDOException $e) {
        $caught = $e;
    }

    check('duplicate key throws PDOException', $caught instanceof PDOException);
    if ($caught instanceof PDOException) {
        check('SQLSTATE class is 23 (integrity constraint)', str_starts_with((string) $caught->getCode(), '23'), (string) $caught->getCode());
        check('error message is not empty', $caught->getMessage() !== '');
        echo "        SQLSTATE: {$caught->getCode()} - {$caught->getMessage()}\n";
    }

    $caught = null;
    try {
        $pdo->query('SELECT * FROM this_table_does_not_exist');
    } catch (PDOException $e) {
        $caught = $e;
    }

    check('unknown table throws PDOException', $caught instanceof PDOException);
    if ($caught instanceof PDOException) {
        check('SQLSTATE class is 42 (syntax/table not found)', str_starts_with((string) $caught->getCode(), '42'), (string) $caught->getCode());
        echo "        SQLSTATE: {$caught->getCode()} - {$caught->getMessage()}\n";
    }
} catch (Throwable $e) {
    check('error handling without unexpected exception', false, $e->getMessage());
} finally {
    drop_table($pdo, $table);
}

finish();
