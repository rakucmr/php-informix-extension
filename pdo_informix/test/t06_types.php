<?php
/**
 * t06 - NULL handling, numeric strings and column metadata.
 */

require __DIR__ . '/bootstrap.php';

$table = 'pdo_t_types';
$pdo = connect();

try {
    drop_table($pdo, $table);
    $pdo->exec("CREATE TABLE {$table} (
        id INTEGER,
        n DECIMAL(10,2),
        txt VARCHAR(30),
        d DATE
    )");

    $stmt = $pdo->prepare("INSERT INTO {$table} (id, n, txt, d) VALUES (?, ?, ?, ?)");
    $stmt->execute([1, null, null, null]);
    check('INSERT with NULLs', true);

    $row = row_lower($pdo->query("SELECT id, n, txt, d FROM {$table} WHERE id = 1")->fetch());
    check('fetched row is not empty', $row !== []);
    check_equals('NULL decimal stays NULL', null, $row['n'] ?? null);
    check_equals('NULL varchar stays NULL', null, $row['txt'] ?? null);
    check_equals('NULL date stays NULL', null, $row['d'] ?? null);

    // numeric string into a DECIMAL column
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, n, txt, d) VALUES (?, ?, ?, ?)");
    $stmt->execute([2, '1234.56', 'hello', '2026-10-28']);
    $stmt->closeCursor();
    $row = row_lower($pdo->query("SELECT n, txt, d FROM {$table} WHERE id = 2")->fetch());
    check_equals('DECIMAL value', '1234.56', (string) ($row['n'] ?? ''));
    check_equals('VARCHAR value', 'hello', $row['txt'] ?? null);
    check_equals('DATE value', '2026-10-28', (string) ($row['d'] ?? ''));

    // column metadata
    $stmt = $pdo->query("SELECT id, n, txt FROM {$table} WHERE id = 2");
    check_equals('columnCount', 3, $stmt->columnCount());
    $meta = $stmt->getColumnMeta(0);
    check('getColumnMeta(0) returns an array', is_array($meta));
    check_equals('first column name', 'ID', strtoupper((string) ($meta['name'] ?? '')));

    // rowCount on SELECT is driver defined, just make sure it does not explode
    check('rowCount() on SELECT returns int', is_int($stmt->rowCount()));
    $stmt->closeCursor();
} catch (Throwable $e) {
    check('types without exception', false, $e->getMessage());
} finally {
    drop_table($pdo, $table);
}

finish();
