<?php

declare(strict_types=1);

test('ifx_prepare + ifx_do: metadatele apar doar după execuție', static function () {
    $conn = ifx_test_connect();
    $result = ifx_prepare('select tabid from systables where tabid between 1 and 5 order by tabid', $conn, IFX_SCROLL);
    assert_instance(\Informix\Result::class, $result);

    assert_false(ifx_fieldtypes($result));
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('available only after ifx_do()', $warnings[0]);
    assert_same(1, ifx_num_fields($result), 'PDO cunoaște numărul de coloane după prepare');

    assert_true(ifx_do($result));
    assert_same(1, ifx_num_fields($result));
    assert_same(['tabid' => 'SQLSERIAL'], ifx_fieldtypes($result));
}, true);

test('cursor scroll: FIRST, NEXT, PRIOR, CURRENT, ABS, REL, LAST', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query(
        'select tabid from systables where tabid between 1 and 5 order by tabid',
        $conn,
        IFX_SCROLL
    );

    assert_same('1', ifx_fetch_row($result, 'FIRST')['tabid']);
    assert_same('2', ifx_fetch_row($result, 'NEXT')['tabid']);
    assert_same('1', ifx_fetch_row($result, 'PRIOR')['tabid']);
    assert_same('1', ifx_fetch_row($result, 'CURRENT')['tabid']);
    assert_same('4', ifx_fetch_row($result, 4)['tabid']);
    assert_same('5', ifx_fetch_row($result, -1)['tabid'], 'offset negativ absolut = ultimul rând');
    assert_same('5', ifx_fetch_row($result, 'LAST')['tabid']);
    assert_same('4', ifx_fetch_row($result, 'PREVIOUS')['tabid']);

    assert_same(8, ifx_num_rows($result));
});

test('poziționare invalidă pe cursor scroll întoarce false și warning', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables order by tabid', $conn, IFX_SCROLL);
    assert_false(ifx_fetch_row($result, 'BOGUS'));
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('Invalid positioning arg', $warnings[0]);
    assert_contains('SQLCODE=-1', ifx_error());

    clear_warnings();
    assert_false(ifx_fetch_row($result, '3'));
    assert_contains('Invalid positioning arg', warnings()[0]);
}, true);

test('fără IFX_SCROLL poziția este ignorată și se citește secvențial', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables where tabid between 1 and 5 order by tabid', $conn, 0);
    assert_same('1', ifx_fetch_row($result, 'LAST')['tabid']);
    assert_same('2', ifx_fetch_row($result, 4)['tabid']);
});

test('IFX_HOLD singur nu activează scroll-ul', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables where tabid between 1 and 5 order by tabid', $conn, IFX_HOLD);
    assert_same('1', ifx_fetch_row($result, 'LAST')['tabid']);
    assert_same('2', ifx_fetch_row($result, 'NEXT')['tabid']);
});

test('ifx_do poate re-executa un cursor după epuizarea rândurilor', static function () {
    $conn = ifx_test_connect();
    $result = ifx_prepare('select tabid from systables where tabid between 1 and 5 order by tabid', $conn, IFX_SCROLL);
    assert_true(ifx_do($result));
    assert_same('1', ifx_fetch_row($result, 'FIRST')['tabid']);
    assert_same('5', ifx_fetch_row($result, 'LAST')['tabid']);
    assert_false(ifx_fetch_row($result, 'NEXT'));
    assert_true(ifx_do($result));
    assert_same('1', ifx_fetch_row($result, 'FIRST')['tabid']);
    assert_same(1, ifx_num_rows($result));
});

test('ifx_prepare non-SELECT cu array de parametri ca al treilea argument', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml', "name = 'prep-one'");
    $name = ifx_create_char('prep-one');

    $result = ifx_prepare('insert into ifx_t_dml (name, n) values (?, 11)', $conn, [$name]);
    assert_instance(\Informix\Result::class, $result);
    assert_true(ifx_do($result));
    assert_same(1, ifx_affected_rows($result));

    $row = ifx_fetch_row(ifx_query("select name, n from ifx_t_dml where name = 'prep-one'", $conn));
    assert_same('prep-one', $row['name']);
    assert_same('11', $row['n']);
    drop_rows($conn, 'ifx_t_dml', "name = 'prep-one'");
    ifx_free_char($name);
});

test('ifx_prepare non-SELECT cu al patrulea argument array', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml', "name = 'prep-two'");
    $name = ifx_create_char('prep-two');

    $result = ifx_prepare('insert into ifx_t_dml (name, n) values (?, 12)', $conn, 0, [$name]);
    assert_true(ifx_do($result));
    assert_same(1, ifx_affected_rows($result));
    drop_rows($conn, 'ifx_t_dml', "name = 'prep-two'");
    ifx_free_char($name);
});

test('IFX_SCROLL pe un statement non-SELECT este ignorat', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('delete from ifx_t_dml where 1 = 0', $conn, IFX_SCROLL);
    assert_instance(\Informix\Result::class, $result);
    assert_same(0, ifx_affected_rows($result));
    assert_same(0, ifx_num_fields($result));
});

test('un ? dintr-un literal SQL nu este numărat ca placeholder', static function () {
    $conn = ifx_test_connect();
    $char = ifx_create_char('1');
    $result = ifx_query(
        "select '?' as q from systables where tabid = ?",
        $conn,
        [$char]
    );
    assert_instance(\Informix\Result::class, $result);
    $row = ifx_fetch_row($result);
    assert_same('?', $row['q']);
});

test('ifx_prepare cu SQL invalid întoarce false și setează eroarea', static function () {
    $conn = ifx_test_connect();
    $result = ifx_prepare('select from where', $conn);
    assert_false($result);
    assert_matches('/^E \[SQLSTATE=[0-9A-Z]{2} [0-9A-Z]{3}  SQLCODE=-\d+\]$/', ifx_error());
    assert_contains('ifx_prepare():', warnings()[0]);
}, true);
