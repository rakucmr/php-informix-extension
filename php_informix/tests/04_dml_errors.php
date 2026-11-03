<?php

declare(strict_types=1);

test('INSERT/UPDATE/DELETE raportează rândurile afectate și SQLCA', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');

    $insert = ifx_query("insert into ifx_t_dml (name, n) values ('dml-a', 1)", $conn);
    assert_instance(\Informix\Result::class, $insert);
    assert_same(1, ifx_affected_rows($insert));
    $sqlca = ifx_getsqlca($insert);
    assert_is_array($sqlca);
    foreach (['sqlerrd0', 'sqlerrd1', 'sqlerrd2', 'sqlerrd3', 'sqlerrd4', 'sqlerrd5'] as $key) {
        assert_key($key, $sqlca);
        assert_true(is_int($sqlca[$key]), $key . ' trebuie să fie int');
    }
    assert_greater($sqlca['sqlerrd1'], 0);
    assert_same(1, $sqlca['sqlerrd2']);

    assert_same(1, ifx_affected_rows(ifx_query("insert into ifx_t_dml (name, n) values ('dml-b', 1)", $conn)));

    $update = ifx_query("update ifx_t_dml set n = n + 1 where name like 'dml-%'", $conn);
    assert_same(2, ifx_affected_rows($update));
    assert_same(2, ifx_getsqlca($update)['sqlerrd2']);

    $delete = ifx_query("delete from ifx_t_dml where name like 'dml-%'", $conn);
    assert_same(2, ifx_affected_rows($delete));
    assert_same(2, ifx_getsqlca($delete)['sqlerrd2']);

    $count = ifx_fetch_row(ifx_query("select count(*) from ifx_t_dml where name like 'dml-%'", $conn));
    assert_same('0', $count['[Expr_1]']);
});

test('duplicatul pe index unic produce SQLSTATE 23000 / SQLCODE -239', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml', "name = 'dml-dup'");

    assert_not_false(ifx_query("insert into ifx_t_dml (name, n) values ('dml-dup', 1)", $conn));
    $duplicate = ifx_query("insert into ifx_t_dml (name, n) values ('dml-dup', 2)", $conn);

    assert_false($duplicate);
    assert_same('E [SQLSTATE=23 000  SQLCODE=-239]', ifx_error());
    assert_contains('duplicate value', ifx_errormsg());
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('ifx_query():', $warnings[0]);
    assert_contains('SQLCODE=-239', $warnings[0]);

    drop_rows($conn, 'ifx_t_dml', "name = 'dml-dup'");
}, true);

test('eroarea de sintaxă și tabela lipsă au SQLCODE-urile legacy', static function () {
    $conn = ifx_test_connect();

    assert_false(ifx_query('select from where', $conn));
    assert_same('E [SQLSTATE=42 000  SQLCODE=-201]', ifx_error());
    assert_contains('syntax error', ifx_errormsg());

    assert_false(ifx_query('select * from ifx_table_that_does_not_exist', $conn));
    assert_same('E [SQLSTATE=42 S02  SQLCODE=-206]', ifx_error());
    assert_contains('not in the database', ifx_errormsg());

    assert_count(2, warnings());
}, true);

test('ifx_errormsg întoarce mesajul curent sau un mesaj pentru cod', static function () {
    $conn = ifx_test_connect();
    ifx_query('select tabid from systables where tabid = 1', $conn);

    assert_same('', ifx_errormsg());
    assert_contains('not in the database', ifx_errormsg(-206));
    assert_same('Informix error 99999', ifx_errormsg(99999));
});

test('tranzacțiile SQL manuale: BEGIN WORK / ROLLBACK WORK / COMMIT WORK', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');

    assert_not_false(ifx_query('begin work', $conn));
    assert_not_false(ifx_query("insert into ifx_t_dml (name, n) values ('dml-tx', 1)", $conn));
    assert_not_false(ifx_query('rollback work', $conn));
    $row = ifx_fetch_row(ifx_query("select count(*) from ifx_t_dml where name = 'dml-tx'", $conn));
    assert_same('0', $row['[Expr_1]']);

    assert_not_false(ifx_query('begin work', $conn));
    assert_not_false(ifx_query("insert into ifx_t_dml (name, n) values ('dml-tx', 2)", $conn));
    assert_not_false(ifx_query('commit work', $conn));
    $row = ifx_fetch_row(ifx_query("select n from ifx_t_dml where name = 'dml-tx'", $conn));
    assert_same('2', $row['n']);

    drop_rows($conn, 'ifx_t_dml');
});

test('tranzacțiile prin obiectul Connection', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');

    assert_false($conn->inTransaction());
    $conn->beginTransaction();
    assert_true($conn->inTransaction());
    assert_not_false(ifx_query("insert into ifx_t_dml (name, n) values ('dml-oo', 1)", $conn));
    $conn->rollback();
    assert_false($conn->inTransaction());
    $row = ifx_fetch_row(ifx_query("select count(*) from ifx_t_dml where name = 'dml-oo'", $conn));
    assert_same('0', $row['[Expr_1]']);

    $conn->beginTransaction();
    assert_not_false(ifx_query("insert into ifx_t_dml (name, n) values ('dml-oo', 2)", $conn));
    $conn->commit();
    $row = ifx_fetch_row(ifx_query("select n from ifx_t_dml where name = 'dml-oo'", $conn));
    assert_same('2', $row['n']);
    drop_rows($conn, 'ifx_t_dml');
});

test('handle-uri invalide sunt respinse cu warning', static function () {
    $conn = ifx_test_connect();
    assert_false(ifx_query('select tabid from systables', new stdClass()));
    assert_contains('not a valid Informix link', warnings()[0]);

    clear_warnings();
    assert_false(ifx_do('not-a-result'));
    assert_contains('not a valid Informix result', warnings()[0]);

    clear_warnings();
    assert_false(ifx_fetch_row(12345));
    assert_contains('not a valid Informix result', warnings()[0]);

    clear_warnings();
    assert_false(ifx_num_rows(null));
    assert_contains('not a valid Informix result', warnings()[0]);
}, true);

test('array-ul de blob-uri trebuie să corespundă numărului de placeholdere', static function () {
    $conn = ifx_test_connect();
    $char = ifx_create_char('x');

    assert_false(ifx_query('insert into ifx_t_dml (name, n) values (?, ?)', $conn, [$char]));
    assert_contains("doesn't match placeholders", warnings()[0]);

    clear_warnings();
    assert_false(ifx_query('insert into ifx_t_dml (name, n) values (?, 1)', $conn, [$char, $char]));
    assert_contains("doesn't match placeholders", warnings()[0]);

    clear_warnings();
    assert_false(ifx_query('insert into ifx_t_dml (name, n) values (?, 1)', $conn, [999999]));
    assert_contains('is not an Informix blob-result index', warnings()[0]);

    ifx_free_char($char);
}, true);

test('un query care nu este string este respins', static function () {
    $conn = ifx_test_connect();
    assert_false(ifx_query(['select 1'], $conn));
    assert_contains('Query must be a string', warnings()[0]);
}, true);
