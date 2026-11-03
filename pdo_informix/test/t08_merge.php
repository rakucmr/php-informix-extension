<?php
/**
 * t08 - MERGE statement (Informix 14.10+).
 *
 * Verifică:
 *  - MERGE cu WHEN MATCHED THEN UPDATE + WHEN NOT MATCHED THEN INSERT pe o
 *    tabelă sursă (rowCount = rânduri actualizate + rânduri inserate);
 *  - MERGE doar cu WHEN MATCHED (inclusiv expresii în SET);
 *  - MERGE doar cu WHEN NOT MATCHED;
 *  - MERGE cu sursă subquery;
 *  - MERGE cu parametru legat în SET (prepare + execute);
 *  - MERGE în tranzacție + rollback;
 *  - MERGE care declanșează trigger-ele de INSERT/UPDATE (jurnal);
 *  - cazurile de eroare: MERGE fără WHEN, tabelă țintă inexistentă;
 *  - sursa rămâne nemodificată.
 *
 * Tabelele rămân în baza de date (ca la t07), dar conținutul lor este resetat
 * la fiecare rulare, ca aserțiunile să fie deterministe.
 */

require __DIR__ . '/bootstrap.php';

$target = 'pdo_t_merge_target';
$source = 'pdo_t_merge_source';
$log = 'pdo_t_merge_log';
$pdo = connect();

$triggerExists = static function (PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM systriggers WHERE trigname = ?');
    $stmt->execute([strtolower($name)]);

    return (int) $stmt->fetchColumn() > 0;
};

try {
    // ---------------------------------------------------------------- DDL
    if (!table_exists($pdo, $target)) {
        $pdo->exec("CREATE TABLE {$target} (
            id         INT PRIMARY KEY,
            name       VARCHAR(30),
            qty        INT,
            updated_at DATETIME YEAR TO FRACTION(3)
        )");
    }
    if (!table_exists($pdo, $source)) {
        $pdo->exec("CREATE TABLE {$source} (
            id   INT PRIMARY KEY,
            name VARCHAR(30),
            qty  INT
        )");
    }
    if (!table_exists($pdo, $log)) {
        $pdo->exec("CREATE TABLE {$log} (
            log_id  SERIAL,
            op      CHAR(6),
            row_id  INT,
            at_time DATETIME YEAR TO FRACTION(3),
            note    LVARCHAR(120)
        )");
    }
    check(
        'tabelele pentru MERGE există',
        table_exists($pdo, $target) && table_exists($pdo, $source) && table_exists($pdo, $log)
    );

    if (!$triggerExists($pdo, "{$target}_ins")) {
        $pdo->exec("CREATE TRIGGER {$target}_ins INSERT ON {$target}
            REFERENCING NEW AS n FOR EACH ROW
            (INSERT INTO {$log} (op, row_id, at_time, note)
             VALUES ('INSERT', n.id, CURRENT, n.name))");
    }
    if (!$triggerExists($pdo, "{$target}_upd")) {
        $pdo->exec("CREATE TRIGGER {$target}_upd UPDATE ON {$target}
            REFERENCING OLD AS o NEW AS n FOR EACH ROW
            (INSERT INTO {$log} (op, row_id, at_time, note)
             VALUES ('UPDATE', n.id, CURRENT, n.name))");
    }
    check(
        'trigger-ele de jurnal există',
        $triggerExists($pdo, "{$target}_ins") && $triggerExists($pdo, "{$target}_upd")
    );

    // ------------------------------------------------------------- date
    $pdo->exec("DELETE FROM {$log}");
    $pdo->exec("DELETE FROM {$target}");
    $pdo->exec("DELETE FROM {$source}");

    $pdo->exec("INSERT INTO {$target} (id, name, qty) VALUES (1, 'vechi-1', 1)");
    $pdo->exec("INSERT INTO {$target} (id, name, qty) VALUES (2, 'vechi-2', 2)");
    $pdo->exec("INSERT INTO {$source} (id, name, qty) VALUES (1, 'nou-1', 10)");
    $pdo->exec("INSERT INTO {$source} (id, name, qty) VALUES (2, 'nou-2', 20)");
    $pdo->exec("INSERT INTO {$source} (id, name, qty) VALUES (3, 'nou-3', 30)");
    $pdo->exec("DELETE FROM {$log}");
    $pdo->exec("UPDATE {$target} SET updated_at = CURRENT WHERE id IN (1, 2)");
    $pdo->exec("DELETE FROM {$log}");

    check_equals('starea inițială a țintei', 2, (int) $pdo->query("SELECT COUNT(*) FROM {$target}")->fetchColumn());

    // -------------------------------------------------------------- MERGE 1
    $merge = "MERGE INTO {$target} t USING {$source} s ON t.id = s.id
        WHEN MATCHED THEN UPDATE SET t.name = s.name, t.qty = s.qty, t.updated_at = CURRENT
        WHEN NOT MATCHED THEN INSERT (id, name, qty, updated_at) VALUES (s.id, s.name, s.qty, CURRENT)";
    $rc = $pdo->exec($merge);
    check_equals('MERGE matched+notmatched: rowCount', 3, $rc);

    $rows = $pdo->query("SELECT id, name, qty FROM {$target} ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    check_equals('MERGE: 3 rânduri în țintă', 3, count($rows));
    check_equals('MERGE: rând actualizat (1)', ['1', 'nou-1', '10'], $rows[0]);
    check_equals('MERGE: rând actualizat (2)', ['2', 'nou-2', '20'], $rows[1]);
    check_equals('MERGE: rând inserat (3)', ['3', 'nou-3', '30'], $rows[2]);

    $logRows = $pdo->query("SELECT op, row_id FROM {$log} ORDER BY log_id")->fetchAll(PDO::FETCH_NUM);
    check_equals(
        'MERGE a declanșat 2 UPDATE + 1 INSERT',
        [['UPDATE', '1'], ['UPDATE', '2'], ['INSERT', '3']],
        array_map(static fn(array $r): array => [trim((string) $r[0]), (string) $r[1]], $logRows)
    );

    // -------------------------------------------------------------- MERGE 2
    $pdo->exec("DELETE FROM {$log}");
    $rc = $pdo->exec("MERGE INTO {$target} t USING {$source} s ON t.id = s.id
        WHEN MATCHED THEN UPDATE SET t.qty = s.qty * 100");
    check_equals('MERGE doar WHEN MATCHED: rowCount', 3, $rc);
    check_equals(
        'MERGE doar WHEN MATCHED: valorile',
        ['1000', '2000', '3000'],
        $pdo->query("SELECT qty FROM {$target} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
    );

    // -------------------------------------------------------------- MERGE 3
    $rc = $pdo->exec("MERGE INTO {$target} t USING {$source} s ON t.id = s.id
        WHEN NOT MATCHED THEN INSERT (id, name, qty) VALUES (s.id, s.name, s.qty)");
    check_equals('MERGE doar WHEN NOT MATCHED (toate se potrivesc): rowCount', 0, $rc);

    $rc = $pdo->exec("MERGE INTO {$target} t USING (SELECT 4 AS id, 'nou-4' AS name, 40 AS qty FROM systables WHERE tabid = 1) s
        ON t.id = s.id
        WHEN NOT MATCHED THEN INSERT (id, name, qty) VALUES (s.id, s.name, s.qty)");
    check_equals('MERGE cu sursă subquery: rowCount', 1, $rc);
    check_equals(
        'MERGE cu sursă subquery: rândul 4',
        '{"id":"4","name":"nou-4","qty":"40"}',
        json_encode(array_change_key_case($pdo->query("SELECT id, name, qty FROM {$target} WHERE id = 4")->fetch(PDO::FETCH_ASSOC)))
    );

    // -------------------------------------------------------------- MERGE 4
    $rc = $pdo->exec("MERGE INTO {$target} t USING {$source} s ON t.id = s.id
        WHEN MATCHED THEN UPDATE SET t.name = s.name, t.qty = s.qty");
    check_equals('MERGE de refacere: rowCount', 3, $rc);

    // ------------------------------------------------- MERGE cu parametru
    $stmt = $pdo->prepare("MERGE INTO {$target} t USING {$source} s ON t.id = s.id
        WHEN MATCHED THEN UPDATE SET t.qty = ?");
    $stmt->execute([777]);
    check_equals('MERGE cu parametru în SET: rowCount', 3, $stmt->rowCount());
    check_equals(
        'MERGE cu parametru în SET: valorile',
        ['777', '777', '777'],
        $pdo->query("SELECT qty FROM {$target} WHERE id IN (1, 2, 3) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
    );
    $stmt->closeCursor();

    // ------------------------------------------------- MERGE în tranzacție
    $before = $pdo->query("SELECT id, qty FROM {$target} ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    $pdo->beginTransaction();
    $rc = $pdo->exec("MERGE INTO {$target} t USING {$source} s ON t.id = s.id
        WHEN MATCHED THEN UPDATE SET t.qty = 999");
    $pdo->rollBack();
    check_equals('MERGE în tranzacție: rowCount', 3, $rc);
    check_equals(
        'MERGE în tranzacție: rollback anulează modificările',
        $before,
        $pdo->query("SELECT id, qty FROM {$target} ORDER BY id")->fetchAll(PDO::FETCH_NUM)
    );

    // ---------------------------------------------------------- erori
    $errorState = static function (PDO $pdo, string $sql): string {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            return (string) $e->getCode();
        }

        return '';
    };
    check_equals(
        'MERGE fără WHEN este respins',
        '42000',
        $errorState($pdo, "MERGE INTO {$target} t USING {$source} s ON t.id = s.id")
    );
    check_equals(
        'MERGE către o tabelă inexistentă',
        '42S02',
        $errorState($pdo, 'MERGE INTO pdo_t_merge_missing t USING pdo_t_merge_source s ON t.id = s.id WHEN MATCHED THEN UPDATE SET t.qty = 1')
    );

    // ------------------------------------------------- sursa nemodificată
    check_equals(
        'MERGE nu modifică sursa',
        [['1', 'nou-1', '10'], ['2', 'nou-2', '20'], ['3', 'nou-3', '30']],
        $pdo->query("SELECT id, name, qty FROM {$source} ORDER BY id")->fetchAll(PDO::FETCH_NUM)
    );

    echo "        țintă: ";
    foreach ($pdo->query("SELECT id, name, qty FROM {$target} ORDER BY id") as $r) {
        echo "[{$r['ID']} {$r['NAME']} {$r['QTY']}] ";
    }
    echo "\n        jurnal MERGE: ";
    foreach ($pdo->query("SELECT op, row_id FROM {$log} ORDER BY log_id") as $r) {
        echo "{$r['OP']}/{$r['ROW_ID']} ";
    }
    echo "\n        (tabelele au fost păstrate: {$target}, {$source}, {$log})\n";
} catch (Throwable $e) {
    check('test fără excepție neașteptată', false, $e->getMessage());
}

finish();
