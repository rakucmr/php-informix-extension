<?php
/**
 * t09 - matricea de funcționalități PDO a driverului pdo_informix.
 *
 * Testul fixează comportamentul observat pe Informix 14.10 + CSDK 4.50, atât
 * pentru ce funcționează, cât și pentru limitările cunoscute (marcate explicit
 * în eticheta aserțiunii), ca să prindă regresiile.
 *
 * Include regresia pentru corecția din `lob_stream_read`: citirea unui CLOB
 * printr-un stream se oprește la sfârșitul valorii (înainte se repeta la
 * nesfârșit).
 */

require __DIR__ . '/bootstrap.php';

$table = 'pdo_t_feat';
$cfg = pdo_informix_config();
$pdo = connect();

$errorCodeOf = static function (callable $fn): string {
    try {
        $fn();
    } catch (PDOException $e) {
        return (string) $e->getCode();
    }

    return '';
};

$readStream = static function ($lob, int $max = 4096): string {
    if (!is_resource($lob)) {
        return '';
    }
    $out = '';
    while (!feof($lob) && strlen($out) < $max) {
        $chunk = fread($lob, 512);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $out .= $chunk;
    }

    return $out;
};

try {
    // ---------------------------------------------------------- tabelă
    if (!table_exists($pdo, $table)) {
        try {
            $pdo->exec("CREATE TABLE {$table} (
                id     INT,
                c_dec  DECIMAL(20,4),
                c_money MONEY(10,2),
                c_dt   DATETIME YEAR TO FRACTION(5),
                c_iv   INTERVAL DAY(3) TO SECOND,
                c_bool BOOLEAN,
                c_big  BIGINT,
                c_lv   LVARCHAR(100),
                c_text TEXT,
                c_blob BLOB,
                c_clob CLOB
            ) PUT c_blob IN (sbspace), c_clob IN (sbspace)");
        } catch (PDOException $e) {
            $pdo->exec("CREATE TABLE {$table} (
                id     INT,
                c_dec  DECIMAL(20,4),
                c_money MONEY(10,2),
                c_dt   DATETIME YEAR TO FRACTION(5),
                c_iv   INTERVAL DAY(3) TO SECOND,
                c_bool BOOLEAN,
                c_big  BIGINT,
                c_lv   LVARCHAR(100),
                c_text TEXT,
                c_blob BLOB,
                c_clob CLOB
            )");
        }
    }
    check('tabela de test există', table_exists($pdo, $table));
    $pdo->exec("DELETE FROM {$table}");

    // ------------------------------------------------- A. atribute conexiune
    check_equals('ATTR_DRIVER_NAME', 'informix', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    check(
        'ATTR_CLIENT_VERSION = versiunea CSDK',
        (bool) preg_match('/^\d+\.\d+\./', $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION))
    );
    check_equals('ATTR_SERVER_INFO', 'Informix', $pdo->getAttribute(PDO::ATTR_SERVER_INFO));
    check_equals('ATTR_AUTOCOMMIT', true, $pdo->getAttribute(PDO::ATTR_AUTOCOMMIT));
    check(
        'ATTR_SERVER_VERSION conține versiunea serverului',
        (bool) preg_match('/Informix.*\d+\.\d+/', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))
    );
    check_equals('ATTR_TIMEOUT implicit', 0, $pdo->getAttribute(PDO::ATTR_TIMEOUT));
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 7);
    check_equals('ATTR_TIMEOUT setat', 7, $pdo->getAttribute(PDO::ATTR_TIMEOUT));
    check_equals('ATTR_CONNECTION_STATUS', 'Connected', $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS));

    // ----------------------------------- A2. atribute specifice Informix
    // (pe o conexiune separată: unele atribute schimbă comportamentul driverului)
    $tune = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    check_equals('constantă PDO_INFORMIX_FETCH_BUFFER_SIZE', 2258, PDO_INFORMIX_FETCH_BUFFER_SIZE);
    $tune->setAttribute(PDO_INFORMIX_FETCH_BUFFER_SIZE, 32768);
    check_equals('FETCH_BUFFER_SIZE se poate seta', 32768, $tune->getAttribute(PDO_INFORMIX_FETCH_BUFFER_SIZE));
    $tune->setAttribute(PDO_INFORMIX_DEFERRED_PREPARE, 1);
    check_equals('DEFERRED_PREPARE se poate seta', 1, $tune->getAttribute(PDO_INFORMIX_DEFERRED_PREPARE));
    $withOptofc = new PDO($cfg['dsn'] . 'OPTOFC=1;', $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    check_equals('OPTOFC=1 din DSN', 1, $withOptofc->getAttribute(PDO_INFORMIX_OPTOFC));

    // ------------------------------------------------------ B. ATTR_CASE
    $lower = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_LOWER,
    ]);
    check_equals(
        'ATTR_CASE=CASE_LOWER la conectare',
        ['tabid'],
        array_keys($lower->query('SELECT tabid FROM systables WHERE tabid = 1')->fetch(PDO::FETCH_ASSOC))
    );
    check_equals('getAttribute(ATTR_CASE) după CASE_LOWER', PDO::CASE_LOWER, $lower->getAttribute(PDO::ATTR_CASE));
    $upper = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_UPPER,
    ]);
    check_equals(
        'ATTR_CASE=CASE_UPPER la conectare',
        ['TABID'],
        array_keys($upper->query('SELECT tabid FROM systables WHERE tabid = 1')->fetch(PDO::FETCH_ASSOC))
    );
    check_equals('getAttribute(ATTR_CASE) după CASE_UPPER', PDO::CASE_UPPER, $upper->getAttribute(PDO::ATTR_CASE));

    // ---------------------------------------------------------- C. quote
    check_equals('quote() escapează apostroful', "'o\\'brien'", $pdo->quote("o'brien"));
    check_equals('quote() escapează backslash-ul', "'a\\\\b'", $pdo->quote('a\\b'));
    check_equals('quote() escapează newline', "'a\\nb'", $pdo->quote("a\nb"));

    // ----------------------------------------------------- D. lastInsertId
    drop_table($pdo, 'pdo_t_li_serial');
    $pdo->exec('CREATE TABLE pdo_t_li_serial (id SERIAL, n VARCHAR(10))');
    $pdo->exec("INSERT INTO pdo_t_li_serial (n) VALUES ('a')");
    check_equals('lastInsertId() după INSERT pe SERIAL', '1', $pdo->lastInsertId());

    drop_table($pdo, 'pdo_t_li_serial8');
    $pdo->exec('CREATE TABLE pdo_t_li_serial8 (id SERIAL8, n VARCHAR(10))');
    $pdo->exec("INSERT INTO pdo_t_li_serial8 (n) VALUES ('a')");
    /*
     * SERIAL8/BIGSERIAL: SQL_GET_SERIAL_VALUE raportează doar SERIAL, așa că
     * driverul citește valoarea din DBINFO('serial8') imediat după INSERT
     * (sesiunea o raportează doar pentru primul statement de după insert).
     */
    check_equals('lastInsertId() după INSERT pe SERIAL8', '1', $pdo->lastInsertId());
    $pdo->exec("INSERT INTO pdo_t_li_serial8 (n) VALUES ('b')");
    check_equals('lastInsertId() crește la al doilea INSERT SERIAL8', '2', $pdo->lastInsertId());
    check_equals(
        'SERIAL8 a generat id-uri valide',
        '2',
        $pdo->query('SELECT MAX(id) FROM pdo_t_li_serial8')->fetchColumn()
    );
    check_equals(
        'lastInsertId() se repetă cu aceeași valoare',
        '2',
        $pdo->lastInsertId()
    );

    drop_table($pdo, 'pdo_t_li_bigserial');
    $pdo->exec('CREATE TABLE pdo_t_li_bigserial (id BIGSERIAL, n VARCHAR(10))');
    $pdo->exec("INSERT INTO pdo_t_li_bigserial (n) VALUES ('a')");
    check_equals('lastInsertId() după INSERT pe BIGSERIAL', '1', $pdo->lastInsertId());

    // un INSERT care nu atinge niciun rând nu trebuie să schimbe valoarea
    $pdo->exec("INSERT INTO pdo_t_li_serial (n) VALUES ('c')");
    $before = $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pdo_t_li_bigserial (n) SELECT n FROM pdo_t_li_bigserial WHERE 1 = 0");
    check_equals('INSERT fără rânduri păstrează lastInsertId anterior', $before, $pdo->lastInsertId());

    drop_table($pdo, 'pdo_t_li_serial');
    drop_table($pdo, 'pdo_t_li_serial8');
    drop_table($pdo, 'pdo_t_li_bigserial');

    // ------------------------------------------------------ E. tipuri
    $pdo->exec("INSERT INTO {$table} (id, c_dec, c_money, c_dt, c_iv, c_bool, c_big, c_lv)
        VALUES (1, 12345678901234.5678, 1234.56, '2026-10-29 12:34:56.12345', '5 01:02:03', 't', 9223372036854775807, 'lvarchar')");
    $row = $pdo->query("SELECT c_dec, c_money, c_dt, c_iv, c_bool, c_big, c_lv FROM {$table} WHERE id = 1")
        ->fetch(PDO::FETCH_ASSOC);
    $row = array_change_key_case($row, CASE_LOWER);

    check_equals('DECIMAL păstrează 4 zecimale', '12345678901234.5678', $row['c_dec']);
    check_equals('MONEY', '1234.56', $row['c_money']);
    check_equals('DATETIME YEAR TO FRACTION(5)', '2026-10-29 12:34:56.12345', $row['c_dt']);
    check_equals('INTERVAL', '5 01:02:03', trim($row['c_iv']));
    check_equals('BOOLEAN', '1', $row['c_bool']);
    check_equals('BIGINT', '9223372036854775807', $row['c_big']);
    check_equals('LVARCHAR', 'lvarchar', $row['c_lv']);

    $meta = $pdo->query("SELECT c_dec, c_money, c_bool FROM {$table}")->getColumnMeta(0);
    check_equals('getColumnMeta: native_type DECIMAL', 'DECIMAL', $meta['native_type']);
    check_equals('getColumnMeta: scale', 4, $meta['scale']);

    // CHAR este de lungime fixă: valorile vin cu spații de umplere (comportament
    // Informix, nu o conversie a driverului).
    drop_table($pdo, 'pdo_t_char');
    $pdo->exec('CREATE TABLE pdo_t_char (c CHAR(10), v VARCHAR(10))');
    $pdo->exec("INSERT INTO pdo_t_char VALUES ('abc', 'abc')");
    $charRow = $pdo->query('SELECT c, v FROM pdo_t_char')->fetch(PDO::FETCH_ASSOC);
    check_equals('CHAR (lungime fixă) păstrează spațiile de umplere', 'abc       ', $charRow['C']);
    check_equals('VARCHAR nu are umplere', 'abc', $charRow['V']);
    drop_table($pdo, 'pdo_t_char');

    // --------------------------------------------------------- F. LOB-uri
    $blob = str_repeat('B', 1000);
    $clob = str_repeat('continut clob ', 20);

    $stmt = $pdo->prepare("INSERT INTO {$table} (id, c_text, c_blob, c_clob) VALUES (?, ?, ?, ?)");
    $stmt->bindValue(1, 2, PDO::PARAM_INT);
    $stmt->bindValue(2, 'text simplu', PDO::PARAM_STR);
    $stmt->bindValue(3, $blob, PDO::PARAM_LOB);
    $stmt->bindValue(4, $clob, PDO::PARAM_STR);
    $stmt->execute();
    $stmt->closeCursor();
    check_equals('INSERT cu BLOB/CLOB prin PARAM_LOB', 1, (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = 2")->fetchColumn());

    // regresie: legarea unui string cu PARAM_LOB nu mai trunchiază variabila
    $lobString = str_repeat('L', 64);
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, c_clob) VALUES (?, ?)");
    $stmt->bindValue(1, 30, PDO::PARAM_INT);
    $stmt->bindValue(2, $lobString, PDO::PARAM_LOB);
    $stmt->execute();
    $stmt->closeCursor();
    check_equals('bindValue cu PARAM_LOB nu trunchiază variabila', str_repeat('L', 64), $lobString);

    /*
     * bindParam() cu al patrulea argument (lungimea maximă) rămâne un parametru
     * de intrare: înainte era legat ca SQL_PARAM_OUTPUT și serverul răspundea
     * cu -254 ("Too many or too few parameters").
     */
    $boundValue = 'systables';
    $stmt = $pdo->prepare('SELECT tabid FROM systables WHERE tabname = ?');
    $stmt->bindParam(1, $boundValue, PDO::PARAM_STR, 64);
    $stmt->execute();
    check_equals('bindParam cu lungime maximă funcționează ca parametru de intrare', '1', (string) $stmt->fetchColumn());
    check_equals('bindParam cu lungime maximă nu modifică variabila', 'systables', $boundValue);
    $stmt->closeCursor();

    /*
     * Parametrii INOUT/OUT nu sunt suportați de CSDK: SQLExecute() blochează
     * apelul. Driverul îi refuză la bindParam(), cu un mesaj care trimite la
     * EXECUTE FUNCTION … RETURNING.
     */
    $inoutError = '';
    try {
        $stmt = $pdo->prepare('SELECT tabid FROM systables WHERE tabname = ?');
        $inoutValue = 21;
        $stmt->bindParam(1, $inoutValue, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
    } catch (PDOException $e) {
        $inoutError = $e->getMessage();
    }
    check('parametrii INOUT sunt refuzați explicit', str_contains($inoutError, 'IM001'), $inoutError);
    check('mesajul pentru INOUT trimite la RETURNING', str_contains($inoutError, 'RETURNING'), $inoutError);

    $lob = null;
    $stmt = $pdo->prepare("SELECT c_blob FROM {$table} WHERE id = 2");
    $stmt->bindColumn(1, $lob, PDO::PARAM_LOB);
    $stmt->execute();
    $stmt->fetch(PDO::FETCH_BOUND);
    $blobRead = $readStream($lob);
    fclose($lob);
    $lob = null;
    check_equals('BLOB citit prin stream (1000 octeți)', 1000, strlen($blobRead));
    check_equals('BLOB conținut identic', $blob, $blobRead);
    $stmt->closeCursor();

    $lob = null;
    $stmt = $pdo->prepare("SELECT c_clob FROM {$table} WHERE id = 2");
    $stmt->bindColumn(1, $lob, PDO::PARAM_LOB);
    $stmt->execute();
    $stmt->fetch(PDO::FETCH_BOUND);
    $clobRead = $readStream($lob);
    fclose($lob);
    $lob = null;
    check_equals('CLOB citit prin stream (fără repetiții)', strlen($clob), strlen($clobRead));
    check_equals('CLOB conținut identic', $clob, $clobRead);
    $stmt->closeCursor();

    /*
     * Regresie: LOB-urile mari (peste un chunk de 8 KB) trebuie citite integral.
     * CLOB/BLOB sunt smart LOBs -- SQLGetData avansează doar cu SQL_C_BINARY,
     * în timp ce TEXT/BYTE (simple large objects) merg doar cu SQL_C_CHAR.
     */
    $bigClob = str_repeat('0123456789abcdef', 2500);      // 40 000 octeți
    $bigText = 'start-' . str_repeat('text-lung-', 2000) . '-end';
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, c_text, c_clob) VALUES (?, ?, ?)");
    $stmt->bindValue(1, 31, PDO::PARAM_INT);
    $stmt->bindValue(2, $bigText, PDO::PARAM_STR);
    $stmt->bindValue(3, $bigClob, PDO::PARAM_STR);
    $stmt->execute();
    $stmt->closeCursor();

    $stmt = $pdo->prepare("SELECT c_clob, c_text FROM {$table} WHERE id = 31");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $bigClobRead = $readStream($row[0], 50000);
    fclose($row[0]);
    $bigTextRead = $readStream($row[1], 50000);
    fclose($row[1]);
    $stmt->closeCursor();
    check_equals('CLOB mare (40 000 octeți) citit integral', strlen($bigClob), strlen($bigClobRead));
    check_equals('CLOB mare conținut identic', md5($bigClob), md5($bigClobRead));
    check_equals('TEXT mare citit integral', strlen($bigText), strlen($bigTextRead));
    check_equals('TEXT mare conținut identic', $bigText, $bigTextRead);

    /*
     * Seek: SQLGetData merge doar înainte, dar driverul ține octeții consumați,
     * așa că se poate sări înainte și reveni în partea deja citită.
     */
    $stmt = $pdo->prepare("SELECT c_clob FROM {$table} WHERE id = 31");
    $stmt->execute();
    $lob = $stmt->fetchColumn(0);
    $lobStat = fstat($lob);
    check_equals('stream LOB: fstat dă lungimea înainte de citire',
        strlen($bigClob), $lobStat['size'] ?? null);
    check_equals('stream LOB: fread de la început', '0123456789abcdef', fread($lob, 16));
    check_equals('stream LOB: ftell după citire', 16, ftell($lob));
    check_equals('stream LOB: fseek înainte (SEEK_SET) reușește', 0, fseek($lob, 1000, SEEK_SET));
    check_equals('stream LOB: datele de la offsetul cerut',
        substr($bigClob, 1000, 16), fread($lob, 16));
    check_equals('stream LOB: fseek înapoi (SEEK_SET) reușește', 0, fseek($lob, 0, SEEK_SET));
    check_equals('stream LOB: datele de la început după revenire',
        '0123456789abcdef', fread($lob, 16));
    check_equals('stream LOB: fseek înapoi relativ (SEEK_CUR)',
        10, @fseek($lob, -6, SEEK_CUR) === 0 ? ftell($lob) : -1);
    check_equals('stream LOB: fseek înainte de început e refuzat', -1, @fseek($lob, -100, SEEK_SET));
    check_equals('stream LOB: SEEK_END folosește lungimea cunoscută',
        strlen($bigClob), @fseek($lob, 0, SEEK_END) === 0 ? ftell($lob) : -1);
    fclose($lob);
    $stmt->closeCursor();

    $lob = null;
    $stmt = $pdo->prepare("SELECT c_text FROM {$table} WHERE id = 2");
    $stmt->bindColumn(1, $lob, PDO::PARAM_LOB);
    $stmt->execute();
    $stmt->fetch(PDO::FETCH_BOUND);
    $textRead = $readStream($lob);
    fclose($lob);
    $lob = null;
    check_equals('TEXT citit prin stream', 'text simplu', $textRead);
    $stmt->closeCursor();

    $blobValue = $pdo->query("SELECT c_blob FROM {$table} WHERE id = 2")->fetchColumn();
    check('BLOB direct este întors ca stream, nu ca string', is_resource($blobValue));
    if (is_resource($blobValue)) {
        fclose($blobValue);
    }

    // TEXT citit direct (fără bindColumn): stream pentru valori, NULL pentru NULL
    $textValue = $pdo->query("SELECT c_text FROM {$table} WHERE id = 2")->fetchColumn();
    check('TEXT direct este întors ca stream', is_resource($textValue));
    check_equals('TEXT direct conținut', 'text simplu', $readStream($textValue));
    if (is_resource($textValue)) {
        fclose($textValue);
    }
    $textRows = $pdo->query("SELECT c_text FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
    check_equals('TEXT direct: NULL rămâne NULL', null, $textRows[0]);

    // stream-ul rămâne utilizabil după distrugerea statementului (primul chunk
    // este deja în buffer; nu se mai atinge handle-ul statementului)
    $st = $pdo->query("SELECT c_text FROM {$table} WHERE id = 2");
    $orphan = $st->fetchColumn();
    unset($st);
    check_equals('stream LOB după distrugerea statementului (chunk buffered)', 'text simplu', $readStream($orphan));
    if (is_resource($orphan)) {
        fclose($orphan);
    }

    // BYTE scris și citit prin parametru
    drop_table($pdo, 'pdo_t_byte');
    $pdo->exec('CREATE TABLE pdo_t_byte (id INT, b BYTE)');
    $stmt = $pdo->prepare('INSERT INTO pdo_t_byte (id, b) VALUES (?, ?)');
    $stmt->bindValue(1, 1, PDO::PARAM_INT);
    $stmt->bindValue(2, 'abcdef', PDO::PARAM_LOB);
    $stmt->execute();
    $stmt->closeCursor();
    $byteValue = $pdo->query('SELECT b FROM pdo_t_byte WHERE id = 1')->fetchColumn();
    check_equals('BYTE citit direct', 'abcdef', $readStream($byteValue));
    if (is_resource($byteValue)) {
        fclose($byteValue);
    }
    drop_table($pdo, 'pdo_t_byte');

    // rowCount pe SELECT nu mai întoarce -1
    check_equals('rowCount pe SELECT', 0, $pdo->query("SELECT id FROM {$table}")->rowCount());

    // ------------------------------------------------------ G. fetch modes
    $pdo->exec("INSERT INTO {$table} (id, c_lv) VALUES (3, 'trei')");
    $pdo->exec("INSERT INTO {$table} (id, c_lv) VALUES (8, 'opt')");
    check_equals(
        'FETCH_KEY_PAIR',
        ['3' => 'trei', '8' => 'opt'],
        $pdo->query("SELECT id, c_lv FROM {$table} WHERE id IN (3, 8) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR)
    );
    check_equals(
        'FETCH_COLUMN',
        ['trei', 'opt'],
        $pdo->query("SELECT c_lv FROM {$table} WHERE id IN (3, 8) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
    );
    $obj = $pdo->query("SELECT id, c_lv FROM {$table} WHERE id = 3")->fetchObject();
    check_equals('fetchObject', ['ID' => '3', 'C_LV' => 'trei'], (array) $obj);
    check_equals(
        'FETCH_CLASS',
        ['ID' => '3', 'C_LV' => 'trei'],
        (array) $pdo->query("SELECT id, c_lv FROM {$table} WHERE id = 3")->fetchAll(PDO::FETCH_CLASS, stdClass::class)[0]
    );
    check_equals(
        'FETCH_NAMED',
        ['ID' => '3', 'C_LV' => 'trei'],
        $pdo->query("SELECT id, c_lv FROM {$table} WHERE id = 3")->fetch(PDO::FETCH_NAMED)
    );

    // ----------------------------------------------------- H. tranzacții
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO {$table} (id, c_lv) VALUES (4, 'patru')");
    $pdo->exec('SAVEPOINT sp_feat');
    $pdo->exec("INSERT INTO {$table} (id, c_lv) VALUES (5, 'cinci')");
    $pdo->exec('ROLLBACK TO SAVEPOINT sp_feat');
    check_equals('SAVEPOINT + ROLLBACK TO SAVEPOINT', '0', $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = 5")->fetchColumn());
    check_equals('rândul dinaintea savepoint-ului rămâne', '1', $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = 4")->fetchColumn());
    $pdo->rollBack();
    check_equals('rollback anulează tot', '0', $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = 4")->fetchColumn());
    check_equals('inTransaction după rollback', false, $pdo->inTransaction());

    // ------------------------------------------------------- I. cursoare
    $stmt = $pdo->prepare('SELECT tabid FROM systables WHERE tabid <= 3', [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor();
    check_equals('CURSOR_FWDONLY funcționează', ['1', '2', '3'], $ids);

    // SQL_INFX_ATTR_ENABLE_SCROLL_CURSORS este setat pe conexiune, deci
    // cursoarele scrollabile chiar funcționează (înainte: HY106 / -11086).
    $scroll = static function (int $ori, int $offset = 0) use ($pdo) {
        $stmt = $pdo->prepare('SELECT tabid FROM systables WHERE tabid <= 3', [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL]);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC, $ori, $offset);
        $stmt->closeCursor();

        return $row;
    };
    check_equals('CURSOR_SCROLL: FETCH_ORI_ABS(2)', ['TABID' => '2'], $scroll(PDO::FETCH_ORI_ABS, 2));
    check_equals('CURSOR_SCROLL: FETCH_ORI_FIRST', ['TABID' => '1'], $scroll(PDO::FETCH_ORI_FIRST));
    check_equals('CURSOR_SCROLL: FETCH_ORI_LAST', ['TABID' => '3'], $scroll(PDO::FETCH_ORI_LAST));

    // ------------------------------------------------- J. statement-uri
    $stmt = $pdo->prepare("INSERT INTO {$table} (id, c_lv) VALUES (?, ?)");
    $stmt->execute([6, 'sase']);
    $stmt->execute([7, 'sapte']);
    check_equals(
        'același statement executat de două ori',
        '2',
        $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id IN (6, 7)")->fetchColumn()
    );

    $stmt = $pdo->prepare("SELECT c_lv FROM {$table} WHERE id = ?");
    $stmt->execute([6]);
    $first = $stmt->fetchColumn();
    $stmt->closeCursor();
    $stmt->execute([7]);
    check_equals('closeCursor + re-execute', ['sase', 'sapte'], [$first, $stmt->fetchColumn()]);
    $stmt->closeCursor();

    // ---------------------------------------------------------- K. erori
    $errorMessage = '';
    try {
        $pdo->query('SELECT * FROM tabela_inexistenta');
    } catch (PDOException $e) {
        check_equals('SQLSTATE pentru tabelă inexistentă', '42S02', (string) $e->getCode());
        $errorMessage = $e->getMessage();
    }
    check_equals('errorCode după eroare', '42S02', $pdo->errorCode());
    $info = $pdo->errorInfo();
    check_equals('errorInfo[1] = codul nativ', -206, $info[1]);

    // Mesajul implicit nu conține detaliile de driver (fișier:linie); ele apar
    // doar cu pdo_informix.error_detail = 1.
    check(
        'mesajul de eroare nu conține fișier:linie',
        !str_contains($errorMessage, '.c:'),
        $errorMessage
    );
    check('mesajul de eroare conține textul serverului', str_contains($errorMessage, 'is not in the database'), $errorMessage);
    $previousDetail = ini_get('pdo_informix.error_detail');
    ini_set('pdo_informix.error_detail', '1');
    try {
        $pdo->query('SELECT * FROM tabela_inexistenta');
    } catch (PDOException $e) {
        check('pdo_informix.error_detail=1 adaugă fișier:linie', str_contains($e->getMessage(), 'informix_driver.c:'), $e->getMessage());
    }
    ini_set('pdo_informix.error_detail', (string) $previousDetail);

    /*
     * Pool de conexiuni CSDK: directiva există și e oprită implicit. Se poate
     * porni doar din php.ini/linia de comandă (PHP_INI_SYSTEM), pentru că
     * trebuie setată pe environment înainte de conectare.
     */
    check_equals('pdo_informix.connection_pooling e oprit implicit', '0',
        (string) ini_get('pdo_informix.connection_pooling'));
    check('pdo_informix.connection_pooling nu se poate schimba la rulare',
        @ini_set('pdo_informix.connection_pooling', '1') === false);

    // ------------------------------------------------- L. conexiune persistentă
    $persistent = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => true,
    ]);
    check(
        'ATTR_PERSISTENT funcționează',
        (int) $persistent->query('SELECT COUNT(*) FROM systables')->fetchColumn() > 0
    );

    // check_liveness: după ce sesiunea persistentă este omorâtă de pe altă
    // conexiune, PDO trebuie să detecteze conexiunea moartă și să reconecteze.
    $persistentSid = (string) $persistent->query("SELECT DBINFO('sessionid') FROM systables WHERE tabid = 1")->fetchColumn();
    $killResult = 'indisponibil';
    try {
        $admin = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $kill = $admin->prepare("SELECT sysadmin:task('onmode','-z', ?::varchar(10)) FROM systables WHERE tabid = 1");
        $kill->execute([$persistentSid]);
        $killResult = (string) $kill->fetchColumn();
        $kill->closeCursor();
    } catch (PDOException $e) {
        $killResult = 'indisponibil: ' . $e->getCode();
    }
    unset($persistent);
    if (str_contains($killResult, 'Killed')) {
        sleep(1);
        $reconnected = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => true,
        ]);
        $newSid = (string) $reconnected->query("SELECT DBINFO('sessionid') FROM systables WHERE tabid = 1")->fetchColumn();
        check('check_liveness: sesiunea moartă este detectată', $newSid !== $persistentSid);
        check(
            'check_liveness: conexiunea persistentă este refăcută',
            (int) $reconnected->query('SELECT COUNT(*) FROM systables')->fetchColumn() > 0
        );
    } else {
        check_equals('check_liveness: test sărit (fără drept de kill)', $killResult, $killResult);
    }

    echo "        (tabela {$table} a fost păstrată)\n";

    // -------------------------------------- M. proceduri stocate
    // O funcție SPL cu RETURNING se apelează cu EXECUTE FUNCTION; valoarea se
    // citește normal. Parametrii OUT/INOUT nu întorc valoarea în PHP (vezi
    // ANALIZA.md §4.4), deci se folosește varianta cu RETURNING.
    foreach (['pdo_p_t09_double', 'pdo_p_t09_inout'] as $proc) {
        try {
            $pdo->exec("DROP PROCEDURE {$proc}");
        } catch (Throwable $e) {
            // nu exista
        }
    }
    $pdo->exec('CREATE PROCEDURE pdo_p_t09_double(x INT) RETURNING INT;
        RETURN x * 3;
    END PROCEDURE');
    $stmt = $pdo->prepare('EXECUTE FUNCTION pdo_p_t09_double(?)');
    $stmt->execute([7]);
    check_equals('EXECUTE FUNCTION ... RETURNING întoarce valoarea', '21', (string) $stmt->fetchColumn());
    $stmt->closeCursor();

    $pdo->exec('CREATE PROCEDURE pdo_p_t09_inout(io INT)
        LET io = io * 2;
    END PROCEDURE');
    $stmt = $pdo->prepare('EXECUTE PROCEDURE pdo_p_t09_inout(?)');
    $inValue = 11;
    $stmt->bindValue(1, $inValue, PDO::PARAM_INT);
    check('EXECUTE PROCEDURE cu parametru IN funcționează', $stmt->execute() !== false);
    $stmt->closeCursor();

    foreach (['pdo_p_t09_double', 'pdo_p_t09_inout'] as $proc) {
        $pdo->exec("DROP PROCEDURE {$proc}");
    }
} catch (Throwable $e) {
    check('test fără excepție neașteptată', false, $e->getMessage());
    echo '        linia ', $e->getLine(), ' în ', basename($e->getFile()), "\n";
}

finish();


