<?php

declare(strict_types=1);

test('SELECT întoarce toate valorile ca string-uri, cu numele coloanelor normalizate', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select * from ifx_t_types', $conn);
    assert_instance(\Informix\Result::class, $result);
    assert_same(13, ifx_num_fields($result));

    $row = ifx_fetch_row($result);
    assert_is_array($row);
    assert_count(13, $row);

    foreach (['id', 'si', 'i', 'bi', 'd', 'm', 'f', 'c', 'v', 'lv', 'dt', 'dtt', 'b'] as $key) {
        assert_key($key, $row);
        assert_true(is_string($row[$key]), 'coloana ' . $key . ' trebuie să fie string');
    }

    assert_same('1', $row['id']);
    assert_same('-7', $row['si']);
    assert_same('42', $row['i']);
    assert_same('9007199254740993', $row['bi']);
    assert_same('12345.67', $row['d']);
    assert_same('123.45', $row['m']);
    assert_same('4.5', $row['f']);
    assert_same('abc       ', $row['c'], 'CHAR păstrează padding-ul');
    assert_same('hello', $row['v']);
    assert_same('long text', $row['lv']);
    assert_matches('/^\d{4}-\d{2}-\d{2}$/', $row['dt']);
    assert_matches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['dtt']);
    assert_same('1', $row['b'], 'BOOLEAN vine ca 1/0 de la driver');

    assert_same(1, ifx_num_rows($result));
    assert_same(0, ifx_affected_rows($result));
    assert_same(0, ifx_num_rows($result) - 1);
});

test('ifx_fieldtypes raportează tipurile SQL legacy', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select * from ifx_t_types', $conn);
    $types = ifx_fieldtypes($result);
    assert_is_array($types);
    assert_count(13, $types);

    assert_same('SQLSERIAL', $types['id']);
    assert_same('SQLSMINT', $types['si']);
    assert_same('SQLINT', $types['i']);
    assert_same('SQLINT8', $types['bi']);
    assert_same('SQLDECIMAL', $types['d']);
    assert_same('SQLMONEY', $types['m']);
    assert_same('SQLFLOAT', $types['f']);
    assert_same('SQLCHAR', $types['c']);
    assert_same('SQLVCHAR', $types['v']);
    assert_same('SQLLVARCHAR', $types['lv']);
    assert_same('SQLDATE', $types['dt']);
    assert_same('SQLDTIME', $types['dtt']);
    assert_same('SQLBOOL', $types['b']);
});

test('ifx_fieldproperties are formatul TIP;len;prec;scale;nullable', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select * from ifx_t_types', $conn);
    $properties = ifx_fieldproperties($result);
    assert_is_array($properties);
    assert_count(13, $properties);

    foreach ($properties as $name => $property) {
        assert_matches('/^[A-Z][A-Z0-9 ]*;\d+;\d+;\d+;[YN]$/', $property, 'proprietatea ' . $name);
    }

    assert_same('SQLSERIAL;11;0;0;N', $properties['id']);
    assert_same('SQLDECIMAL;14;2;2;Y', $properties['d']);
    assert_same('SQLCHAR;10;0;0;Y', $properties['c']);
    assert_same('SQLDTIME;19;0;0;Y', $properties['dtt']);
});

test('expresiile primesc cheia [Expr_n]', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select count(*) from systables', $conn);
    assert_same(1, ifx_num_fields($result));
    $row = ifx_fetch_row($result);
    assert_key('[Expr_1]', $row);
    assert_matches('/^\d+$/', $row['[Expr_1]']);
});

test('la sfârșitul datelor ifx_fetch_row întoarce false și SQLCODE=100', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables where tabid = 1', $conn);
    assert_not_false(ifx_fetch_row($result));
    assert_false(ifx_fetch_row($result));
    assert_same('N [SQLSTATE=02 000  SQLCODE=100]', ifx_error());
    assert_same(1, ifx_num_rows($result));
    assert_same('No rows found', ifx_errormsg(100));
});

test('două rezultate pot fi citite intercalat pe aceeași conexiune', static function () {
    $conn = ifx_test_connect();
    $first = ifx_query('select tabid from systables order by tabid', $conn);
    $second = ifx_query('select tabname from systables order by tabid', $conn);

    $rowA = ifx_fetch_row($first);
    $rowB = ifx_fetch_row($second);
    $rowA2 = ifx_fetch_row($first);
    $rowB2 = ifx_fetch_row($second);

    assert_same('1', $rowA['tabid']);
    assert_same('systables', $rowB['tabname']);
    assert_same('2', $rowA2['tabid']);
    assert_same('syscolumns', $rowB2['tabname']);
});

test('numele duplicate de coloane nu strică maparea pe index', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid, tabid as tabid2 from systables where tabid = 1', $conn);
    $row = ifx_fetch_row($result);
    assert_same('1', $row['tabid']);
    assert_same('1', $row['tabid2']);
});

test('ifx_free_result invalidează rezultatul', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables where tabid = 1', $conn);
    assert_true(ifx_free_result($result));
    assert_false(ifx_fetch_row($result));
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('not a valid Informix result', $warnings[0]);
}, true);

test('ifx_fetch_row pe un statement non-SELECT emite Not a select cursor', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('delete from ifx_t_dml where 1 = 0', $conn);
    assert_same(0, ifx_num_fields($result));
    assert_same(0, ifx_affected_rows($result));
    assert_false(ifx_fetch_row($result));
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('Not a select cursor', $warnings[0]);
}, true);

test('ifx_num_rows numără fiecare fetch, inclusiv re-fetch-urile', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables order by tabid', $conn, IFX_SCROLL);
    ifx_fetch_row($result, 'FIRST');
    ifx_fetch_row($result, 'NEXT');
    ifx_fetch_row($result, 'FIRST');
    assert_same(3, ifx_num_rows($result));
});
