<?php

declare(strict_types=1);

test('INSERT ... SELECT raportează numărul real de rânduri', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');

    $insert = ifx_query(
        "insert into ifx_t_dml (name, n) select 'multi-' || tabid, tabid from systables where tabid <= 5",
        $conn
    );
    assert_not_false($insert);
    assert_same(5, ifx_affected_rows($insert));
    assert_same(5, ifx_getsqlca($insert)['sqlerrd2']);

    $row = ifx_fetch_row(ifx_query("select count(*) from ifx_t_dml where name like 'multi-%'", $conn));
    assert_same('5', $row['[Expr_1]']);
    drop_rows($conn, 'ifx_t_dml');
});

test('UPDATE care nu afectează niciun rând raportează 0', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');

    $update = ifx_query("update ifx_t_dml set n = 1 where name = 'nu-exista'", $conn);
    assert_same(0, ifx_affected_rows($update));
    assert_same(0, ifx_getsqlca($update)['sqlerrd2']);
});

test('un text Latin-1 (é) face round-trip prin CHAR', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_types', "v like 'caf%'");

    $text = 'caf' . chr(0xE9);
    $char = ifx_create_char($text);
    $result = ifx_query('insert into ifx_t_types (v) values (?)', $conn, [$char]);
    assert_not_false($result);

    $row = ifx_fetch_row(ifx_query("select v from ifx_t_types where v like 'caf%'", $conn));
    assert_same($text, $row['v']);

    drop_rows($conn, 'ifx_t_types', "v like 'caf%'");
    ifx_free_char($char);
});

test('text UTF-8 este acceptat doar într-o bază UTF-8', static function () {
    $conn = ifx_test_connect();
    $row = ifx_fetch_row(ifx_query(
        "select dbs_collate from sysmaster:sysdbslocale
          where trim(dbs_dbsname) = trim(dbinfo('dbname'))",
        $conn
    ));
    $collate = trim($row['dbs_collate']);
    if (stripos($collate, 'utf8') === false) {
        skip_test('DB_LOCALE este ' . $collate . ', nu UTF-8');
    }

    drop_rows($conn, 'ifx_t_types', "v like 'ă%'");
    $text = 'ăâîșțĂÂÎȘȚ';
    $char = ifx_create_char($text);
    ifx_query('insert into ifx_t_types (v) values (?)', $conn, [$char]);
    $row = ifx_fetch_row(ifx_query("select v from ifx_t_types where v like 'ă%'", $conn));
    assert_same($text, $row['v']);

    drop_rows($conn, 'ifx_t_types', "v like 'ă%'");
    ifx_free_char($char);
});

test('ifx_update_blob modifică doar copia locală, nu rândul din baza', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $blob = ifx_create_blob(1, 0, 'db-value');
    ifx_query('insert into ifx_t_lob (txt, label) values (?, ?)', $conn, [$blob, 'lob-local']);

    $row = ifx_fetch_row(ifx_query("select txt from ifx_t_lob where label = 'lob-local'", $conn));
    assert_same('db-value', ifx_get_blob($row['txt']));
    assert_true(ifx_update_blob($row['txt'], 'changed'));
    assert_same('changed', ifx_get_blob($row['txt']));

    ifx_textasvarchar(1);
    $again = ifx_fetch_row(ifx_query("select txt from ifx_t_lob where label = 'lob-local'", $conn));
    ifx_textasvarchar(0);
    assert_same('db-value', $again['txt'], 'baza păstrează valoarea originală');

    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");
});

test('ifx_prepare + ifx_do cu parametru LOB către o coloană TEXT', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $blob = ifx_create_blob(1, 0, 'prepared text');
    $result = ifx_prepare(
        'insert into ifx_t_lob (txt, label) values (?, ?)',
        $conn,
        [$blob, 'lob-prep']
    );
    assert_true(ifx_do($result));
    assert_same(1, ifx_affected_rows($result));

    ifx_textasvarchar(1);
    $row = ifx_fetch_row(ifx_query("select txt from ifx_t_lob where label = 'lob-prep'", $conn));
    ifx_textasvarchar(0);
    assert_same('prepared text', $row['txt']);

    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");
});

test('100 de rânduri pot fi citite și numărate corect', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');

    ifx_query(
        "insert into ifx_t_dml (name, n) select 'gen-' || tabid, tabid from systables",
        $conn
    );
    $result = ifx_query('select id, name, n from ifx_t_dml order by id', $conn);
    $count = 0;
    $last = null;
    while (($row = ifx_fetch_row($result)) !== false) {
        $count++;
        $last = $row;
    }
    assert_same($count, ifx_num_rows($result));
    assert_same('gen-' . $last['n'], $last['name']);
    $countRow = ifx_fetch_row(ifx_query('select count(*) from ifx_t_dml', $conn));
    assert_same((string) $count, trim($countRow['[Expr_1]']));

    drop_rows($conn, 'ifx_t_dml');
});
