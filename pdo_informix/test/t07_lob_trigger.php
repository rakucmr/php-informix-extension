<?php
/**
 * t07 - SERIAL8 + JSON + BSON + BLOB, cu trigger-e care jurnalizează
 * INSERT / UPDATE / DELETE într-o tabelă de log.
 *
 * Tabelele și trigger-ele rămân în baza de date după rulare (ca să poți
 * inspecta jurnalul). Testul este reluabil: dacă obiectele există deja, le
 * refolosește și doar adaugă rânduri noi, cu etichete unice per rulare.
 * Pentru curățare completă: PDO_INFORMIX_KEEP_LOB=0 php t07_lob_trigger.php
 *
 * Particularități Informix acoperite aici:
 *  - BLOB are nevoie de un sbspace ("PUT data IN (sbspace)"), cu fallback pe
 *    sbspace-ul implicit dacă numele diferă;
 *  - parametrii legați nu se pot converti direct în JSON/BSON ("No cast from
 *    byte to json"), deci se face CAST(? AS LVARCHAR(n))::json, respectiv
 *    json_to_bson(...);
 *  - trigger-ele au nevoie de clauza REFERENCING NEW AS n / OLD AS o pentru a
 *    putea citi coloanele rândului (altfel: "Undefined symbol (new)").
 */

require __DIR__ . '/bootstrap.php';

$main = 'pdo_t_lob';
$log  = 'pdo_t_lob_log';
$keep = getenv('PDO_INFORMIX_KEEP_LOB') !== '0';
$tag  = date('His') . '-' . substr(uniqid(), -4);
$pdo  = connect();

$dropAll = static function (PDO $pdo) use ($main, $log): void {
    foreach (['ins', 'upd', 'del'] as $suffix) {
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS {$main}_{$suffix}");
        } catch (PDOException $e) {
            // ignore
        }
    }
    drop_table($pdo, $main);
    drop_table($pdo, $log);
};

$triggerExists = static function (PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM systriggers WHERE trigname = ?');
    $stmt->execute([strtolower($name)]);

    return (int) $stmt->fetchColumn() > 0;
};

try {
    if (!$keep) {
        $dropAll($pdo);
    }

    // ---------------------------------------------------------------- DDL
    if (!table_exists($pdo, $main)) {
        try {
            $pdo->exec("CREATE TABLE {$main} (
                id      SERIAL8,
                doc     JSON,
                payload BSON,
                data    BLOB,
                label   VARCHAR(40)
            ) PUT data IN (sbspace)");
        } catch (PDOException $e) {
            // instanță fără sbspace numit "sbspace" - folosim sbspace-ul implicit
            $pdo->exec("CREATE TABLE {$main} (
                id      SERIAL8,
                doc     JSON,
                payload BSON,
                data    BLOB,
                label   VARCHAR(40)
            )");
        }
    }
    check('tabela principală există (SERIAL8 + JSON + BSON + BLOB)', table_exists($pdo, $main));

    if (!table_exists($pdo, $log)) {
        $pdo->exec("CREATE TABLE {$log} (
            log_id  SERIAL,
            op      CHAR(6),
            row_id  BIGINT,
            at_time DATETIME YEAR TO FRACTION(3),
            db_user VARCHAR(32),
            note    LVARCHAR(120)
        )");
    }
    check('tabela de jurnal există', table_exists($pdo, $log));

    if (!$triggerExists($pdo, "{$main}_ins")) {
        $pdo->exec("CREATE TRIGGER {$main}_ins INSERT ON {$main}
            REFERENCING NEW AS n FOR EACH ROW
            (INSERT INTO {$log} (op, row_id, at_time, db_user, note)
             VALUES ('INSERT', n.id, CURRENT, USER, n.label))");
    }
    if (!$triggerExists($pdo, "{$main}_upd")) {
        $pdo->exec("CREATE TRIGGER {$main}_upd UPDATE ON {$main}
            REFERENCING OLD AS o NEW AS n FOR EACH ROW
            (INSERT INTO {$log} (op, row_id, at_time, db_user, note)
             VALUES ('UPDATE', n.id, CURRENT, USER, n.label))");
    }
    if (!$triggerExists($pdo, "{$main}_del")) {
        $pdo->exec("CREATE TRIGGER {$main}_del DELETE ON {$main}
            REFERENCING OLD AS o FOR EACH ROW
            (INSERT INTO {$log} (op, row_id, at_time, db_user, note)
             VALUES ('DELETE', o.id, CURRENT, USER, o.label))");
    }
    check(
        'trigger-ele INSERT/UPDATE/DELETE există',
        $triggerExists($pdo, "{$main}_ins")
            && $triggerExists($pdo, "{$main}_upd")
            && $triggerExists($pdo, "{$main}_del")
    );

    $beforeMain = (int) $pdo->query("SELECT COUNT(*) FROM {$main}")->fetchColumn();
    $beforeLog = (int) $pdo->query("SELECT COUNT(*) FROM {$log}")->fetchColumn();

    // ------------------------------------------------------------- INSERT
    $insert = $pdo->prepare("INSERT INTO {$main} (doc, payload, data, label)
        VALUES (CAST(? AS LVARCHAR(2000))::json,
                json_to_bson(CAST(? AS LVARCHAR(2000))::json),
                ?,
                ?)");

    $insert->execute(['{"b":2}', '{"b":2}', 'continut BLOB 1', "row-1-{$tag}"]);
    check_equals('INSERT 1 rowCount', 1, $insert->rowCount());
    $insert->execute(['{"nume":"ana","varsta":30}', '{"nume":"ana","varsta":30}', 'continut BLOB 2', "row-2-{$tag}"]);
    check_equals('INSERT 2 rowCount', 1, $insert->rowCount());
    $insert->closeCursor();

    $find = $pdo->prepare("SELECT id FROM {$main} WHERE label = ?");
    $find->execute(["row-1-{$tag}"]);
    $id1 = (int) $find->fetchColumn();
    $find->execute(["row-2-{$tag}"]);
    $id2 = (int) $find->fetchColumn();

    check('SERIAL8 a generat id-uri pozitive', $id1 > 0 && $id2 > 0, "{$id1}, {$id2}");
    check('SERIAL8 a generat id-uri distincte', $id1 !== $id2);
    check_equals('tabela are două rânduri în plus', $beforeMain + 2, (int) $pdo->query("SELECT COUNT(*) FROM {$main}")->fetchColumn());

    // --------------------------------------------------------------- JSON
    $doc = $pdo->query("SELECT doc::lvarchar(200) FROM {$main} WHERE id = {$id1}")->fetchColumn();
    check_equals('JSON citit înapoi', '{"b":2}', (string) $doc);

    $doc2 = $pdo->query("SELECT doc::lvarchar(200) FROM {$main} WHERE id = {$id2}")->fetchColumn();
    check(
        'JSON cu mai multe chei',
        str_contains((string) $doc2, '"nume":"ana"') && str_contains((string) $doc2, '"varsta":30'),
        (string) $doc2
    );

    // --------------------------------------------------------------- BSON
    $ok = trim((string) $pdo->query("SELECT bson_valid(payload) FROM {$main} WHERE id = {$id1}")->fetchColumn());
    check_equals('bson_valid(payload)', 'ok', $ok);

    $bson = (string) $pdo->query("SELECT payload::lvarchar(100) FROM {$main} WHERE id = {$id1}")->fetchColumn();
    // BSON pentru {"b": 2}: 0C 00 00 00 | 10 'b' 00 | 02 00 00 00 | 00
    check_equals('BSON are codificarea așteptată', '0c0000001062000200000000', bin2hex($bson));

    /*
     * Citirea directă a coloanelor JSON/BSON: driverul le întoarce ca string
     * (JSON text, respectiv octeți BSON cu NUL-uri păstrate), nu ca stream.
     */
    $nativeDoc = $pdo->query("SELECT doc FROM {$main} WHERE id = {$id1}")->fetchColumn(0);
    check_equals('JSON citit direct e string', 'string', gettype($nativeDoc));
    check_equals('JSON direct are conținutul corect', '{"b":2}', $nativeDoc);
    $decoded = json_decode((string) $nativeDoc, true);
    check_equals('json_decode funcționează pe valoarea JSON', 2, $decoded['b'] ?? null);

    $nativeBson = $pdo->query("SELECT payload FROM {$main} WHERE id = {$id1}")->fetchColumn(0);
    check('BSON citit direct e string binar', is_string($nativeBson) && strlen($nativeBson) === 12,
        gettype($nativeBson));
    check_equals('BSON direct păstrează octeții (inclusiv NUL-uri)',
        '0c0000001062000200000000', bin2hex((string) $nativeBson));

    // JSON mai mare decât un chunk de LOB (8 KB), tot ca string. Rândul se
    // inserează într-o tranzacție anulată, ca jurnalul trigger-elor să rămână
    // cu exact intrările testului.
    $bigDoc = json_encode(['chei' => array_fill(0, 400, str_repeat('x', 20))]);
    check('JSON de test depășește 8 KB', strlen($bigDoc) > 8192, strlen($bigDoc) . ' octeți');
    $pdo->beginTransaction();
    $insertBig = $pdo->prepare("INSERT INTO {$main} (doc, label) VALUES (CAST(? AS LVARCHAR(20000))::json, ?)");
    $insertBig->execute([$bigDoc, "row-3-{$tag}"]);
    $insertBig->closeCursor();
    $backBig = $pdo->query("SELECT doc FROM {$main} WHERE label = " . $pdo->quote("row-3-{$tag}"))->fetchColumn(0);
    check_equals('JSON mare citit direct, identic', $bigDoc, $backBig);
    $pdo->rollBack();

    // ---------------------------------------------------------------- BLOB
    $withBlob = (int) $pdo->query("SELECT COUNT(*) FROM {$main} WHERE data IS NOT NULL AND id IN ({$id1}, {$id2})")->fetchColumn();
    check_equals('BLOB stocat pe ambele rânduri', 2, $withBlob);

    // -------------------------------------------------------------- UPDATE
    $update = $pdo->prepare("UPDATE {$main} SET label = ?, doc = CAST(? AS LVARCHAR(2000))::json WHERE id = ?");
    $update->execute(["row-1-modificat-{$tag}", '{"b":3}', $id1]);
    check_equals('UPDATE rowCount', 1, $update->rowCount());

    $label = $pdo->query("SELECT label FROM {$main} WHERE id = {$id1}")->fetchColumn();
    check_equals('UPDATE a schimbat label-ul', "row-1-modificat-{$tag}", $label);
    $doc = $pdo->query("SELECT doc::lvarchar(200) FROM {$main} WHERE id = {$id1}")->fetchColumn();
    check_equals('UPDATE a schimbat JSON-ul', '{"b":3}', (string) $doc);

    // -------------------------------------------------------------- DELETE
    $delete = $pdo->prepare("DELETE FROM {$main} WHERE id = ?");
    $delete->execute([$id2]);
    check_equals('DELETE rowCount', 1, $delete->rowCount());
    check_equals(
        'tabela are un rând în plus după DELETE',
        $beforeMain + 1,
        (int) $pdo->query("SELECT COUNT(*) FROM {$main}")->fetchColumn()
    );

    // ----------------------------------------------------------- JURNAL
    $totalLog = (int) $pdo->query("SELECT COUNT(*) FROM {$log}")->fetchColumn();
    check_equals('jurnal: 4 intrări noi', $beforeLog + 4, $totalLog);

    $entries = array_map(
        'row_lower',
        $pdo->query("SELECT FIRST 4 op, row_id, db_user, note FROM {$log}
                     WHERE row_id IN ({$id1}, {$id2}) ORDER BY log_id DESC")->fetchAll()
    );
    $entries = array_reverse($entries);

    check_equals('jurnal: ordinea operațiilor', ['INSERT', 'INSERT', 'UPDATE', 'DELETE'], array_map(
        static fn(array $e): string => trim((string) $e['op']),
        $entries
    ));
    check_equals('jurnal: id-uri din SERIAL8', [$id1, $id2, $id1, $id2], array_map(
        static fn(array $e): int => (int) $e['row_id'],
        $entries
    ));
    check_equals('jurnal: UPDATE are label-ul nou', "row-1-modificat-{$tag}", trim((string) $entries[2]['note']));
    check_equals('jurnal: DELETE are label-ul vechi', "row-2-{$tag}", trim((string) $entries[3]['note']));
    check_equals('jurnal: utilizatorul curent', ['informix'], array_values(array_unique(array_map(
        static fn(array $e): string => trim((string) $e['db_user']),
        $entries
    ))));

    foreach ($entries as $e) {
        echo "        {$e['op']} row={$e['row_id']} user={$e['db_user']} note={$e['note']}\n";
    }

    echo $keep
        ? "        (obiectele au fost păstrate: {$main}, {$log} + 3 trigger-e)\n"
        : "        (obiectele au fost șterse: PDO_INFORMIX_KEEP_LOB=0)\n";
} catch (Throwable $e) {
    check('test fără excepție neașteptată', false, $e->getMessage());
} finally {
    if (!$keep) {
        $dropAll($pdo);
    }
}

finish();

