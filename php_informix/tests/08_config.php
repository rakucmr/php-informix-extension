<?php

declare(strict_types=1);

test('Config citește și scrie nume cu prefix ifx.', static function () {
    \Informix\Config::reset();
    assert_same(0, \Informix\Config::get('ifx.nullformat'));
    \Informix\Config::set('ifx.nullformat', 1);
    assert_same(1, \Informix\Config::get('nullformat'));
    assert_same('NULL', \Informix\Config::nullValue());
    \Informix\Config::reset();
});

test('ifx_nullformat schimbă valoarea pentru NULL', static function () {
    $conn = ifx_test_connect();

    assert_true(ifx_nullformat(0));
    $row = ifx_fetch_row(ifx_query('select null::integer as n from systables where tabid = 1', $conn));
    assert_same('', $row['n']);

    assert_true(ifx_nullformat(1));
    $row = ifx_fetch_row(ifx_query('select null::integer as n from systables where tabid = 1', $conn));
    assert_same('NULL', $row['n']);

    ifx_nullformat(0);
});

test('ifx_charasvarchar taie padding-ul coloanelor CHAR', static function () {
    $conn = ifx_test_connect();

    $row = ifx_fetch_row(ifx_query('select c from ifx_t_types', $conn));
    assert_same('abc       ', $row['c']);

    assert_true(ifx_charasvarchar(1));
    $row = ifx_fetch_row(ifx_query('select c from ifx_t_types', $conn));
    assert_same('abc', $row['c']);

    ifx_charasvarchar(0);
    $row = ifx_fetch_row(ifx_query('select c from ifx_t_types', $conn));
    assert_same('abc       ', $row['c']);
});

test('modurile blob/text/byte se pot seta și prin Config', static function () {
    \Informix\Config::reset();
    assert_same(0, \Informix\Config::get('blobinfile'));
    assert_true(ifx_blobinfile_mode(1));
    assert_same(1, \Informix\Config::get('blobinfile'));

    assert_true(ifx_textasvarchar(1));
    assert_same(1, \Informix\Config::get('textasvarchar'));
    assert_true(ifx_byteasvarchar(1));
    assert_same(1, \Informix\Config::get('byteasvarchar'));

    ifx_blobinfile_mode(0);
    ifx_textasvarchar(0);
    ifx_byteasvarchar(0);
});

test('Config::all conține toate opțiunile, iar default_host vine din INFORMIXSERVER', static function () {
    \Informix\Config::reset();
    $all = \Informix\Config::all();
    foreach (['blobinfile', 'textasvarchar', 'byteasvarchar', 'charasvarchar', 'nullformat', 'allow_persistent', 'max_links'] as $key) {
        assert_key($key, $all);
    }
    $expected = getenv('INFORMIXSERVER');
    if ($expected === false || $expected === '') {
        skip_test('INFORMIXSERVER nu este setat');
    }
    assert_same($expected, \Informix\Config::get('default_host'));
});

test('variabilele de mediu sunt citite la Config::reset', static function () {
    putenv('IFX_CHARASVARCHAR=1');
    putenv('IFX_NULLFORMAT=1');
    \Informix\Config::reset();
    assert_same(1, \Informix\Config::get('charasvarchar'));
    assert_same(1, \Informix\Config::get('nullformat'));

    putenv('IFX_CHARASVARCHAR');
    putenv('IFX_NULLFORMAT');
    \Informix\Config::reset();
    assert_same(0, \Informix\Config::get('charasvarchar'));
    assert_same(0, \Informix\Config::get('nullformat'));
});

test('variabilele de mediu boolean acceptă și forma false/no/off', static function () {
    putenv('IFX_ALLOW_PERSISTENT=false');
    putenv('IFX_HTMLTBL_ESCAPE=no');
    \Informix\Config::reset();
    assert_false(\Informix\Config::get('allow_persistent'));
    assert_false(\Informix\Config::get('htmltbl_escape'));

    putenv('IFX_ALLOW_PERSISTENT');
    putenv('IFX_HTMLTBL_ESCAPE');
    \Informix\Config::reset();
    assert_true(\Informix\Config::get('allow_persistent'));
});

test('Config::set respinge opțiunile necunoscute', static function () {
    try {
        \Informix\Config::set('nu_exista', 1);
        throw new TestFailure('Config::set ar fi trebuit să arunce excepție');
    } catch (\InvalidArgumentException $e) {
        assert_contains('Opțiune ifx necunoscută', $e->getMessage());
    }
});

test('reset_state readuce configurația la valorile implicite', static function () {
    \Informix\Config::set('nullformat', 1);
    \Informix\Config::set('max_links', 5);
    reset_state();
    assert_same(0, \Informix\Config::get('nullformat'));
    assert_same(-1, \Informix\Config::get('max_links'));
});
