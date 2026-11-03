<?php

declare(strict_types=1);

test('ifx_htmltbl_result tipărește tabelul legacy și întoarce numărul de rânduri', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');
    ifx_query("insert into ifx_t_dml (name, n) values ('html-a', 1)", $conn);
    ifx_query("insert into ifx_t_dml (name, n) values ('html-b', 2)", $conn);

    $result = ifx_query('select name, n from ifx_t_dml order by id', $conn);
    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });

    assert_same(2, $count);
    assert_same(2, ifx_num_rows($result));
    assert_contains('<table><tr>', $output);
    assert_contains('<th>Name</th>', $output);
    assert_contains('<th>N</th>', $output);
    assert_contains('<td>html-a</td>', $output);
    assert_contains('<td>1</td>', $output);
    assert_contains('</table>', $output);

    drop_rows($conn, 'ifx_t_dml');
});

test('opțiunile HTML sunt inserate în tag-ul table', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables where tabid = 1', $conn);
    list($count, $output) = capture_output(static function () use ($result) {
        return ifx_htmltbl_result($result, 'border="1" class="grid"');
    });

    assert_same(1, $count);
    assert_contains('<table border="1" class="grid"><tr>', $output);
});

test('celulele BYTE/BLOB sunt (BYTE), TEXT/CLOB sunt conținutul', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");

    $text = ifx_create_blob(1, 0, 'text cell');
    $byte = ifx_create_blob(0, 0, 'byte cell');
    ifx_query(
        'insert into ifx_t_lob (txt, byt, blb, clb, label) values (?, ?, ?, ?, ?)',
        $conn,
        [$text, $byte, $byte, $text, 'lob-html']
    );

    $result = ifx_query("select txt, byt, blb, clb from ifx_t_lob where label = 'lob-html'", $conn);
    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });
    assert_same(1, $count);
    assert_contains('<td>text cell</td>', $output);
    assert_not_contains('byte cell', $output);
    assert_contains('<td>(BYTE)</td>', $output);

    drop_rows($conn, 'ifx_t_lob', "label like 'lob-%'");
});

test('fără rânduri se tipărește h2 No rows found', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select tabid from systables where 1 = 0', $conn);
    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });

    assert_same(0, $count);
    assert_same("<h2>No rows found</h2>\n", $output);
    assert_same(0, ifx_num_rows($result));
});

test('htmltbl_result continuă de la poziția curentă', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');
    ifx_query("insert into ifx_t_dml (name, n) values ('html-c', 3)", $conn);
    ifx_query("insert into ifx_t_dml (name, n) values ('html-d', 4)", $conn);

    $result = ifx_query('select name from ifx_t_dml order by id', $conn);
    assert_same('html-c', ifx_fetch_row($result)['name']);

    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });
    assert_same(1, $count);
    assert_not_contains('html-c', $output);
    assert_contains('html-d', $output);
    assert_same(2, ifx_num_rows($result));

    drop_rows($conn, 'ifx_t_dml');
});

test('NULL în HTML respectă ifx_nullformat', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('select null::integer as n from systables where tabid = 1', $conn);
    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });
    assert_same(1, $count);
    assert_contains('<td></td>', $output);

    ifx_nullformat(1);
    list($count, $output) = capture_output(static function () use ($conn) {
        return ifx_htmltbl_result(ifx_query('select null::integer as n from systables where tabid = 1', $conn));
    });
    assert_same(1, $count);
    assert_contains('<td>NULL</td>', $output);
    ifx_nullformat(0);
});

test('htmltbl_escape escapează conținutul când este activat', static function () {
    $conn = ifx_test_connect();
    drop_rows($conn, 'ifx_t_dml');
    ifx_query("insert into ifx_t_dml (name, n) values ('<script>alert(\"x\")</script>', 1)", $conn);

    $result = ifx_query('select name from ifx_t_dml', $conn);
    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });
    assert_same(1, $count);
    assert_contains('<script>alert("x")</script>', $output);

    \Informix\Config::set('htmltbl_escape', true);
    $result = ifx_query('select name from ifx_t_dml', $conn);
    list($count, $output) = capture_output(static function () use ($result) { return ifx_htmltbl_result($result); });
    assert_same(1, $count);
    assert_contains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $output);
    \Informix\Config::set('htmltbl_escape', false);

    drop_rows($conn, 'ifx_t_dml');
});

test('ifx_htmltbl_result pe non-SELECT eșuează', static function () {
    $conn = ifx_test_connect();
    $result = ifx_query('delete from ifx_t_dml where 1 = 0', $conn);
    assert_false(ifx_htmltbl_result($result));
    assert_contains('Not a select cursor', warnings()[0]);
}, true);
