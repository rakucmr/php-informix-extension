<?php

declare(strict_types=1);

test('constantele legacy există și au valorile din ext/informix', static function () {
    assert_same(1, IFX_SCROLL);
    assert_same(2, IFX_HOLD);
    assert_same(0x4, IFX_LO_RDONLY);
    assert_same(0x2, IFX_LO_WRONLY);
    assert_same(0x1, IFX_LO_APPEND);
    assert_same(0x8, IFX_LO_RDWR);
    assert_same(0x200, IFX_LO_BUFFER);
    assert_same(0x400, IFX_LO_NOBUFFER);
});

test('ifx_connect întoarce o conexiune cu DSN-ul construit din baza@server', static function () {
    $conn = ifx_test_connect();
    assert_instance(\Informix\Connection::class, $conn);
    assert_same(ifx_test_database(), $conn->database);
    assert_same(ifx_test_server(), $conn->server);
    assert_contains('server=' . ifx_test_server(), $conn->dsn);
    assert_contains('database=' . ifx_test_database(), $conn->dsn);
    assert_false($conn->isClosed());
});

test('al doilea ifx_connect cu aceleași argumente refolosește link-ul', static function () {
    $first = ifx_test_connect();
    $second = ifx_connect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_same($first, $second, 'aceeași conexiune, ca în extensia veche');
});

test('ifx_query fără link folosește conexiunea implicită', static function () {
    ifx_test_connect();
    $result = ifx_query('select tabid from systables where tabid = 1');
    assert_instance(\Informix\Result::class, $result);
    $row = ifx_fetch_row($result);
    assert_same('1', $row['tabid']);
    ifx_free_result($result);
});

test('ifx_close fără argument închide link-ul implicit', static function () {
    $conn = ifx_test_connect();
    assert_true(ifx_close());
    assert_true($conn->isClosed());
    $result = ifx_query('select tabid from systables where tabid = 1');
    $warnings = warnings();
    assert_false($result);
    assert_count(1, $warnings);
    assert_contains('No Informix connection', $warnings[0]);
}, true);

test('ifx_close eliberează cache-ul; reconectarea creează un obiect nou', static function () {
    $first = ifx_test_connect();
    assert_true(ifx_close($first));
    $second = ifx_test_connect();
    assert_true($first !== $second);
});

test('formatul ifx_error() după succes este cel din extensia veche', static function () {
    ifx_test_connect();
    assert_same('  [SQLSTATE=00 000  SQLCODE=0]', ifx_error());
    assert_same('', ifx_errormsg());
});

test('eroarea de conectare setează SQLSTATE/SQLCODE și emite warning', static function () {
    $conn = ifx_connect('baza_inexistenta@' . ifx_test_server(), ifx_test_user(), ifx_test_password());
    assert_false($conn);
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('ifx_connect():', $warnings[0]);

    $error = ifx_error();
    assert_matches('/^E \[SQLSTATE=[0-9A-Z]{2} [0-9A-Z]{3}  SQLCODE=-\d+\]$/', $error);
    assert_true(ifx_errormsg() !== '');
}, true);

test('ifx_connect acceptă și un DSN complet informix:', static function () {
    $conn = ifx_connect('informix:server=' . ifx_test_server() . ';database=' . ifx_test_database() . ';', ifx_test_user(), ifx_test_password());
    assert_instance(\Informix\Connection::class, $conn);
    assert_same(ifx_test_database(), $conn->database);
    assert_same(ifx_test_server(), $conn->server);
});

test('un link închis este respins de ifx_query cu warning', static function () {
    $conn = ifx_test_connect();
    ifx_close($conn);
    $result = ifx_query('select tabid from systables where tabid = 1', $conn);
    assert_false($result);
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('not a valid Informix link', $warnings[0]);
}, true);
