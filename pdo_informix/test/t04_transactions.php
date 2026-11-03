<?php
/**
 * t04 - transaction handling: commit persists, rollback discards.
 */

require __DIR__ . '/bootstrap.php';

$table = 'pdo_t_tx';
$pdo = connect();

try {
    drop_table($pdo, $table);
    $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, label VARCHAR(20))");

    $pdo->beginTransaction();
    check('inTransaction() after beginTransaction', $pdo->inTransaction());
    $pdo->exec("INSERT INTO {$table} VALUES (1, 'committed')");
    $pdo->commit();
    check('inTransaction() false after commit', !$pdo->inTransaction());

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO {$table} VALUES (2, 'rolled back')");
    $pdo->rollBack();
    check('inTransaction() false after rollBack', !$pdo->inTransaction());

    check_equals('only the committed row exists', 1, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
    check_equals(
        'committed value is there',
        'committed',
        $pdo->query("SELECT label FROM {$table} WHERE id = 1")->fetchColumn()
    );
} catch (Throwable $e) {
    check('transactions without exception', false, $e->getMessage());
} finally {
    drop_table($pdo, $table);
}

finish();
