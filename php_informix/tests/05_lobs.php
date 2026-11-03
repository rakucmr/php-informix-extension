<?php

declare(strict_types=1);

test('TEXT/BYTE/BLOB/CLOB sunt returnate ca blob id-uri implicite', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $text = ifx_create_blob(1, 0, 'hello text');
    $byte = ifx_create_blob(0, 0, "bin\0data");
    $blob = ifx_create_blob(0, 0, 'blob content');
    $clob = ifx_create_blob(1, 0, 'clob content');

    $insert = ifx_query(
        'insert into ifx_t_lob (txt, byt, blb, clb, label) values (?, ?, ?, ?, ?)',
        $conn,
        [$text, $byte, $blob, $clob, 'lob-basic']
    );
    assert_not_false($insert);
    assert_same(1, ifx_affected_rows($insert));

    $result = ifx_query("select txt, byt, blb, clb from ifx_t_lob where label = 'lob-basic'", $conn);
    $types = ifx_fieldtypes($result);
    assert_same('SQLTEXT', $types['txt']);
    assert_same('SQLBYTES', $types['byt']);
    assert_same('SQLUDTFIXED', $types['blb']);
    assert_same('SQLUDTFIXED', $types['clb']);

    $row = ifx_fetch_row($result);
    foreach (['txt', 'byt', 'blb', 'clb'] as $column) {
        assert_true(is_int($row[$column]), $column . ' trebuie să fie blob id');
        assert_greater($row[$column], 0);
    }

    assert_same('hello text', ifx_get_blob($row['txt']));
    assert_same("bin\0data", ifx_get_blob($row['byt']));
    assert_same('blob content', ifx_get_blob($row['blb']));
    assert_same('clob content', ifx_get_blob($row['clb']));

    assert_same('hello text', ifx_get_blob((string) $row['txt']), 'id numeric ca string');
});

test('ifx_textasvarchar/ifx_byteasvarchar întorc conținutul direct', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $text = ifx_create_blob(1, 0, 'hello text');
    $byte = ifx_create_blob(0, 0, "bin\0data");
    ifx_query(
        'insert into ifx_t_lob (txt, byt, label) values (?, ?, ?)',
        $conn,
        [$text, $byte, 'lob-varchar']
    );

    ifx_textasvarchar(1);
    ifx_byteasvarchar(1);
    $row = ifx_fetch_row(ifx_query("select txt, byt, clb from ifx_t_lob where label = 'lob-varchar'", $conn));
    assert_same('hello text', $row['txt']);
    assert_same("bin\0data", $row['byt']);
    assert_same('', $row['clb'], 'CLOB NULL devine string gol când textasvarchar=1');

    ifx_textasvarchar(0);
    ifx_byteasvarchar(0);
});

test('modul fișier: ifx_get_blob întoarce un fișier temporar, șters la free', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $text = ifx_create_blob(1, 0, 'file mode text');
    $byte = ifx_create_blob(0, 0, "file\0mode");
    ifx_query(
        'insert into ifx_t_lob (txt, byt, label) values (?, ?, ?)',
        $conn,
        [$text, $byte, 'lob-file']
    );

    ifx_blobinfile_mode(1);
    $row = ifx_fetch_row(ifx_query("select txt, byt from ifx_t_lob where label = 'lob-file'", $conn));
    $textPath = ifx_get_blob($row['txt']);
    $bytePath = ifx_get_blob($row['byt']);
    assert_true(is_file($textPath));
    assert_true(is_file($bytePath));
    assert_same('file mode text', file_get_contents($textPath));
    assert_same("file\0mode", file_get_contents($bytePath));

    assert_true(ifx_free_blob($row['txt']));
    assert_false(is_file($textPath), 'fișierul temporar este șters la free');
    assert_true(is_file($bytePath));

    ifx_free_blob($row['byt']);
    assert_false(is_file($bytePath));
    ifx_blobinfile_mode(0);
});

test('ifx_create_blob în mod fișier copiază sursa și nu o modifică', static function () {
    $source = tempnam(sys_get_temp_dir(), 'ifxsrc');
    file_put_contents($source, 'from file');

    $id = ifx_create_blob(1, 1, $source);
    assert_true(is_int($id));
    $payload = ifx_get_blob($id);
    assert_true($payload !== $source);
    assert_true(is_file($payload));
    assert_same('from file', file_get_contents($payload));

    assert_true(ifx_update_blob($id, 'updated file'));
    assert_same('updated file', file_get_contents($payload));
    assert_same('from file', file_get_contents($source), 'sursa rămâne neatinsă');

    assert_true(ifx_free_blob($id));
    assert_false(is_file($payload));
    assert_true(is_file($source));
    @unlink($source);
});

test('ifx_copy_blob creează o copie independentă', static function () {
    $original = ifx_create_blob(1, 0, 'original');
    $copy = ifx_copy_blob($original);
    assert_true(is_int($copy));
    assert_true($copy !== $original);

    assert_same('original', ifx_get_blob($copy));
    ifx_update_blob($copy, 'copy');
    assert_same('original', ifx_get_blob($original));
    assert_same('copy', ifx_get_blob($copy));

    ifx_free_blob($copy);
    ifx_free_blob($original);
});

test('NULL pe coloane LOB respectă ifx_nullformat', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");
    ifx_query("insert into ifx_t_lob (label) values ('lob-null')", $conn);

    $row = ifx_fetch_row(ifx_query("select txt, byt from ifx_t_lob where label = 'lob-null'", $conn));
    assert_true(is_int($row['txt']));
    assert_same('', ifx_get_blob($row['txt']));
    assert_same('', ifx_get_blob($row['byt']));

    ifx_nullformat(1);
    $row = ifx_fetch_row(ifx_query("select txt from ifx_t_lob where label = 'lob-null'", $conn));
    assert_same('NULL', ifx_get_blob($row['txt']));
    ifx_nullformat(0);
});

test('LOB mari (256 KB text și 128 KB binar) fac round-trip', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $largeText = str_repeat('A', 256 * 1024);
    $pattern = "\x00\xFF\x10\x7F\x80\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B";
    $largeByte = str_repeat($pattern, 128 * 1024 / strlen($pattern));

    $text = ifx_create_blob(1, 0, $largeText);
    $byte = ifx_create_blob(0, 0, $largeByte);
    ifx_query(
        'insert into ifx_t_lob (txt, byt, label) values (?, ?, ?)',
        $conn,
        [$text, $byte, 'lob-large']
    );

    ifx_textasvarchar(1);
    ifx_byteasvarchar(1);
    $row = ifx_fetch_row(ifx_query("select txt, byt from ifx_t_lob where label = 'lob-large'", $conn));
    ifx_textasvarchar(0);
    ifx_byteasvarchar(0);

    assert_same(strlen($largeText), strlen($row['txt']));
    assert_same(strlen($largeByte), strlen($row['byt']));
    assert_same($largeText, $row['txt']);
    assert_true($largeByte === $row['byt'], 'conținutul binar trebuie identic');
});

test('UPDATE cu parametru LOB raportează rândurile afectate', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");
    ifx_query("insert into ifx_t_lob (label) values ('lob-update')", $conn);

    $text = ifx_create_blob(1, 0, 'updated text');
    $result = ifx_query(
        "update ifx_t_lob set txt = ? where label = 'lob-update'",
        $conn,
        [$text]
    );
    assert_not_false($result);
    assert_same(1, ifx_affected_rows($result));

    ifx_textasvarchar(1);
    $row = ifx_fetch_row(ifx_query("select txt from ifx_t_lob where label = 'lob-update'", $conn));
    ifx_textasvarchar(0);
    assert_same('updated text', $row['txt']);
});

test('un blob eliberat nu mai poate fi folosit', static function () {
    $id = ifx_create_blob(1, 0, 'x');
    assert_true(ifx_free_blob($id));
    assert_false(ifx_get_blob($id));
    assert_contains('not an Informix blob-result index', warnings()[0]);

    clear_warnings();
    assert_false(ifx_free_blob($id));
    assert_contains('not an Informix blob-result index', warnings()[0]);
}, true);

test('ifx_create_blob cu fișier inexistent eșuează', static function () {
    assert_false(ifx_create_blob(1, 1, sys_get_temp_dir() . '/ifx-file-that-does-not-exist'));
    assert_contains('File copy failed', warnings()[0]);
}, true);
