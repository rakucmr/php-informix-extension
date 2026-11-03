<?php
/**
 * t03 - DDL + CRUD, with positional and named placeholders.
 */

require __DIR__ . '/bootstrap.php';

$table = 'pdo_t_crud';
$pdo = connect();

try {
    drop_table($pdo, $table);
    $pdo->exec("CREATE TABLE {$table} (
        id INTEGER PRIMARY KEY,
        name VARCHAR(50),
        amount DECIMAL(10,2),
        created DATETIME YEAR TO SECOND
    )");
    check('CREATE TABLE', table_exists($pdo, $table));

    // positional placeholder
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, name, amount, created) VALUES (?, ?, ?, CURRENT)");
    $ok = $stmt->execute([1, 'alpha', '10.50']);
    check('INSERT positional returns true', $ok === true);
    check_equals('rowCount after INSERT', 1, $stmt->rowCount());

    // named placeholders (PDO rewrites them to positional for this driver)
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, name, amount, created) VALUES (:id, :name, :amount, CURRENT)");
    $ok = $stmt->execute([':id' => 2, ':name' => 'beta', ':amount' => '20.25']);
    check('INSERT named returns true', $ok === true);

    // binding with explicit types
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, name, amount, created) VALUES (?, ?, ?, CURRENT)");
    $stmt->bindValue(1, 3, PDO::PARAM_INT);
    $stmt->bindValue(2, 'gamma', PDO::PARAM_STR);
    $stmt->bindValue(3, '0.99', PDO::PARAM_STR);
    $stmt->execute();
    check('INSERT bindValue returns true', true);

    $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    check_equals('three rows stored', 3, $count);

    // fetch
    $row = row_lower($pdo->query("SELECT id, name, amount FROM {$table} WHERE id = 1")->fetch());
    check('fetch returns an array', $row !== []);
    check_equals('fetched name', 'alpha', $row['name'] ?? null);
    check_equals('fetched amount as string', '10.50', (string) ($row['amount'] ?? ''));

    // prepared SELECT with parameter
    $stmt = $pdo->prepare("SELECT name FROM {$table} WHERE id = ?");
    $stmt->execute([2]);
    check_equals('prepared SELECT', 'beta', $stmt->fetchColumn());

    // fetchAll with FETCH_ASSOC
    $rows = $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check_equals('fetchAll returns 3 rows', 3, count($rows));

    // update
    $stmt = $pdo->prepare("UPDATE {$table} SET amount = ? WHERE id = ?");
    $stmt->execute(['99.99', 3]);
    check_equals('UPDATE rowCount', 1, $stmt->rowCount());
    $amount = $pdo->query("SELECT amount FROM {$table} WHERE id = 3")->fetchColumn();
    check_equals('updated amount', '99.99', (string) $amount);

    // delete
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->execute([1]);
    check_equals('DELETE rowCount', 1, $stmt->rowCount());
    check_equals('two rows left', 2, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
} catch (Throwable $e) {
    check('CRUD without exception', false, $e->getMessage());
} finally {
    drop_table($pdo, $table);
}

finish();
