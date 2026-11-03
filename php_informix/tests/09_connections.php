<?php

declare(strict_types=1);

function ifx_test_session_id(\Informix\Connection $connection): string
{
    $row = ifx_fetch_row(ifx_query("select dbinfo('sessionid') from systables where tabid = 1", $connection));

    return (string) $row['[Expr_1]'];
}

test('ifx_pconnect refolosește obiectul pentru aceleași argumente', static function () {
    reset_state();
    \Informix\Config::set('allow_persistent', true);

    $first = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    $second = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_true($first->persistent);
    assert_same($first, $second);
});

test('conexiunea persistentă refolosește sesiunea serverului după close', static function () {
    reset_state();
    \Informix\Config::set('allow_persistent', true);

    $first = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    $session = ifx_test_session_id($first);
    assert_true(ifx_close($first));

    $second = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_true($second !== $first);
    assert_same($session, ifx_test_session_id($second));
    ifx_close($second);
});

test('allow_persistent=0 transformă pconnect într-o conexiune normală', static function () {
    reset_state();
    \Informix\Config::set('allow_persistent', false);

    $first = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_false($first->persistent);
    $session = ifx_test_session_id($first);
    ifx_close($first);

    $second = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_false($second->persistent);
    assert_true($session !== ifx_test_session_id($second), 'fără persistent se deschide o sesiune nouă');
    ifx_close($second);
    \Informix\Config::set('allow_persistent', true);
});

test('max_links limitează numărul de conexiuni normale', static function () {
    reset_state();
    \Informix\Config::set('max_links', 1);

    $first = ifx_connect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_instance(\Informix\Connection::class, $first);

    $second = ifx_connect(ifx_test_dsn(), ifx_test_user(), ifx_test_password() . '-wrong');
    assert_false($second);
    $warnings = warnings();
    assert_count(1, $warnings);
    assert_contains('Prea multe conexiuni normale', $warnings[0]);
    assert_contains('SQLSTATE=08 001', ifx_error());

    ifx_close($first);
}, true);

test('max_persistent limitează conexiunile persistente', static function () {
    reset_state();
    \Informix\Config::set('max_persistent', 1);

    $first = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    assert_instance(\Informix\Connection::class, $first);

    $second = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password() . '-wrong');
    assert_false($second);
    assert_contains('Prea multe conexiuni persistente', warnings()[0]);
    ifx_close($first);
}, true);

test('max_links=0 refuză orice conexiune', static function () {
    reset_state();
    \Informix\Config::set('max_links', 0);

    assert_false(ifx_connect(ifx_test_dsn(), ifx_test_user(), ifx_test_password()));
    assert_contains('Prea multe conexiuni normale', warnings()[0]);
}, true);

test('Connection::closeAll închide toate conexiunile din cache', static function () {
    reset_state();
    $first = ifx_connect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    $second = ifx_connect(
        'informix:server=' . ifx_test_server() . ';database=' . ifx_test_database() . ';host=127.0.0.1;service=9088;',
        ifx_test_user(),
        ifx_test_password()
    );

    assert_greater(\Informix\Connection::cachedCount(false), 1);
    \Informix\Connection::closeAll();
    assert_true($first->isClosed());
    assert_true($second->isClosed());
    assert_same(0, \Informix\Connection::cachedCount(false));
});

test('ifx_query fără link folosește ultima conexiune pconnect', static function () {
    reset_state();
    $conn = ifx_pconnect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    $row = ifx_fetch_row(ifx_query('select tabid from systables where tabid = 1'));
    assert_same('1', $row['tabid']);
    ifx_close($conn);
});
