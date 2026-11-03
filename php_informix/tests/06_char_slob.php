<?php

declare(strict_types=1);

test('CHAR: create/get/update/free', static function () {
    $id = ifx_create_char('hello char');
    assert_true(is_int($id));
    assert_same('hello char', ifx_get_char($id));
    assert_true(ifx_update_char($id, 'updated'));
    assert_same('updated', ifx_get_char($id));
    assert_true(ifx_free_char($id));
    assert_false(ifx_get_char($id));
    assert_contains('not an Informix char-result index', warnings()[0]);
}, true);

test('CHAR poate fi folosit ca parametru pentru un VARCHAR', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml', "name = 'char-param'");

    $name = ifx_create_char('char-param');
    $result = ifx_query('insert into ifx_t_dml (name, n) values (?, 7)', $conn, [$name]);
    assert_not_false($result);
    $row = ifx_fetch_row(ifx_query("select name, n from ifx_t_dml where name = 'char-param'", $conn));
    assert_same('char-param', $row['name']);
    assert_same('7', $row['n']);

    drop_rows($conn, 'ifx_t_dml', "name = 'char-param'");
    ifx_free_char($name);
});

test('CHAR cu parametru invalid eșuează cu warning', static function () {
    assert_false(ifx_create_char(['nu', 'e', 'string']));
    assert_contains('Content must be a string', warnings()[0]);
}, true);

test('un handle eliberat nu poate fi trimis ca parametru', static function () {
    $conn = ifx_test_connect();
    $char = new \Informix\CharHandle('1');
    $char->free();

    $result = ifx_query('select tabid from systables where tabid = ?', $conn, [$char]);
    assert_false($result);
    assert_contains('freed Informix handle', warnings()[0]);
}, true);

test('SLOB: write/tell/seek/read de bază', static function () {
    $id = ifxus_create_slob(IFX_LO_RDWR);
    assert_true(is_int($id));

    assert_same(6, ifxus_write_slob($id, 'abcdef'));
    assert_same(6, ifxus_tell_slob($id));

    assert_same(0, ifxus_seek_slob($id, 0, 0));
    assert_same('abc', ifxus_read_slob($id, 3));
    assert_same(3, ifxus_tell_slob($id));

    assert_same(5, ifxus_seek_slob($id, 2, -1));
    assert_same('f', ifxus_read_slob($id, 10));

    assert_same(2, ifxus_seek_slob($id, 0, 2));
    assert_same(0, ifxus_seek_slob($id, 0, 0));
    assert_same(2, ifxus_write_slob($id, 'XY'));
    assert_same(0, ifxus_seek_slob($id, 0, 0));
    assert_same('XYcdef', ifxus_read_slob($id, 100));

    assert_true(ifxus_free_slob($id));
});

test('SLOB read-only refuză scrierea', static function () {
    $id = ifxus_create_slob(IFX_LO_RDONLY);
    assert_same('', ifxus_read_slob($id, 10));
    assert_false(ifxus_write_slob($id, 'x'));
    assert_contains('Error writing slob', warnings()[0]);
    ifxus_free_slob($id);
}, true);

test('SLOB close/open păstrează conținutul', static function () {
    $id = ifxus_create_slob(IFX_LO_RDWR);
    ifxus_write_slob($id, 'abc');
    assert_true(ifxus_close_slob($id));
    assert_false(ifxus_tell_slob($id));

    assert_same(0, ifxus_open_slob($id, IFX_LO_RDONLY));
    assert_same('abc', ifxus_read_slob($id, 10));
    assert_true(ifxus_close_slob($id));

    assert_same(0, ifxus_open_slob($id, IFX_LO_RDWR));
    assert_same(3, ifxus_write_slob($id, 'def'));
    assert_true(ifxus_close_slob($id));
    ifxus_free_slob($id);
}, true);

test('SLOB: open când e deja deschis și close de două ori eșuează', static function () {
    $id = ifxus_create_slob(IFX_LO_RDWR);
    assert_false(ifxus_open_slob($id, IFX_LO_RDWR));
    assert_contains('Can not open slob-resource', warnings()[0]);

    assert_true(ifxus_close_slob($id));
    clear_warnings();
    assert_false(ifxus_close_slob($id));
    assert_contains('already closed', warnings()[0]);
    ifxus_free_slob($id);
}, true);

test('SLOB APPEND scrie mereu la sfârșit', static function () {
    $id = ifxus_create_slob(IFX_LO_APPEND);
    assert_same(2, ifxus_write_slob($id, 'ab'));
    assert_same(2, ifxus_write_slob($id, 'cd'));
    assert_same(0, ifxus_seek_slob($id, 0, 0));
    assert_same(1, ifxus_write_slob($id, 'X'));
    assert_same(0, ifxus_seek_slob($id, 0, 0));
    assert_same('abcdX', ifxus_read_slob($id, 100));
    ifxus_free_slob($id);
});

test('SLOB acceptă biții BUFFER/NOBUFFER în mod', static function () {
    $id = ifxus_create_slob(IFX_LO_RDWR | IFX_LO_BUFFER);
    assert_same(1, ifxus_write_slob($id, 'x'));
    assert_true(ifxus_free_slob($id));
});

test('SLOB cu seek negativ sau citire negativă eșuează', static function () {
    $id = ifxus_create_slob(IFX_LO_RDWR);
    ifxus_write_slob($id, 'abc');

    assert_false(ifxus_seek_slob($id, 0, -1));
    assert_contains('seek-operation', warnings()[0]);

    clear_warnings();
    assert_false(ifxus_read_slob($id, -1));
    assert_contains('Error reading slob', warnings()[0]);
    ifxus_free_slob($id);
}, true);

test('SLOB eliberat nu mai poate fi folosit', static function () {
    $id = ifxus_create_slob(IFX_LO_RDWR);
    assert_true(ifxus_free_slob($id));
    assert_false(ifxus_tell_slob($id));
    assert_contains('not an Informix slob-result index', warnings()[0]);

    clear_warnings();
    assert_false(ifxus_free_slob($id));
    assert_contains('not an Informix slob-result index', warnings()[0]);
}, true);
