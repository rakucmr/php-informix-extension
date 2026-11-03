<?php

declare(strict_types=1);

/*
 * Test de integrare pentru extensia C ifx_pdo.dll.
 * Rulează cu:
 *   php -n -d extension=pdo_informix -d extension=ifx_pdo native-suite.php
 */

$failures = [];
$count = 0;

function check(string $name, bool $condition, string $detail = '') {
    global $failures, $count;
    $count++;
    if ($condition) {
        echo "  PASS  {$name}\n";
        return;
    }
    $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    echo "  FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function eq(string $name, $expected, $actual) {
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function contains(string $name, string $needle, $haystack) {
    check($name, is_string($haystack) && str_contains($haystack, $needle), 'got ' . var_export($haystack, true));
}

$dsn = getenv('IFX_TEST_PDO_DSN');
$legacyDsn = getenv('IFX_TEST_DSN');
$user = getenv('IFX_TEST_USER');
$password = getenv('IFX_TEST_PASSWORD');
if ($dsn === false || $dsn === '' || $legacyDsn === false || $legacyDsn === '' || $user === false || $password === false) {
    fwrite(STDERR, 'Setați IFX_TEST_PDO_DSN și IFX_TEST_DSN; IFX_TEST_USER și IFX_TEST_PASSWORD pot fi goale.' . PHP_EOL);
    exit(2);
}
putenv('IFX_DEFAULT_DATABASE=' . $legacyDsn);
putenv('IFX_DEFAULT_USER=' . $user);
putenv('IFX_DEFAULT_PASSWORD=' . $password);
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_STRINGIFY_FETCHES => true,
]);

foreach (['ifx_n_types', 'ifx_n_lob', 'ifx_n_dml'] as $table) {
    try {
        $pdo->exec('drop table ' . $table);
    } catch (PDOException $e) {
    }
}

$pdo->exec('create table ifx_n_types (
    id serial, si smallint, i integer, bi bigint, d decimal(12,2),
    c char(10), v varchar(40), dt date, dtt datetime year to second, b boolean
)');
$pdo->exec("insert into ifx_n_types (si, i, bi, d, c, v, dt, dtt, b)
    values (-7, 42, 9007199254740993, 12345.67, 'abc', 'hello', today, current, 't')");
$pdo->exec('create table ifx_n_lob (id serial, txt text, byt byte, label varchar(40))');
$pdo->exec('create table ifx_n_dml (id serial, name varchar(30), n integer)');
$pdo->exec('create unique index ifx_n_dml_name on ifx_n_dml (name)');

// ---------------------------------------------------------------- connect

$link = ifx_connect($dsn, $user, $password);
check('ifx_connect întoarce o resursă', is_resource($link));
contains('ifx_error după connect', 'SQLCODE=0', ifx_error());
check('ifx_close(resursă)', ifx_close($link) === true);

$link = ifx_connect($legacyDsn, $user, $password);
check('ifx_connect(db@server)', is_resource($link));

// ---------------------------------------------------------------- select

$result = ifx_query('select id, si, i, v, c, dt, dtt, b from ifx_n_types', $link);
check('ifx_query întoarce o resursă', is_resource($result));
eq('ifx_num_fields', 8, ifx_num_fields($result));

$types = ifx_fieldtypes($result);
check('ifx_fieldtypes este array', is_array($types));
eq('tip id', 'SQLSERIAL', $types['id'] ?? null);
eq('tip v', 'SQLVCHAR', $types['v'] ?? null);
eq('tip c', 'SQLCHAR', $types['c'] ?? null);
eq('tip dtt', 'SQLDTIME', $types['dtt'] ?? null);
eq('tip b', 'SQLBOOL', $types['b'] ?? null);

$props = ifx_fieldproperties($result);
check('ifx_fieldproperties este array', is_array($props));
contains('proprietăți id', 'SQLSERIAL;', $props['id'] ?? null);

$row = ifx_fetch_row($result);
check('ifx_fetch_row este array', is_array($row));
eq('id este string', '1', $row['id'] ?? null);
eq('si este string', '-7', $row['si'] ?? null);
eq('i este string', '42', $row['i'] ?? null);
eq('v', 'hello', $row['v'] ?? null);
eq('c păstrează padding-ul', 'abc       ', $row['c'] ?? null);
eq('boolean devine 1', '1', $row['b'] ?? null);
eq('ifx_num_rows după un rând', 1, ifx_num_rows($result));
check('ifx_fetch_row la final este false', ifx_fetch_row($result) === false);
contains('ifx_error la final', 'SQLCODE=100', ifx_error());
eq('ifx_num_rows rămâne 1', 1, ifx_num_rows($result));

check('ifx_free_result', ifx_free_result($result) === true);
check('ifx_free_result a doua oară e false', ifx_free_result($result) === false);

// ---------------------------------------------------------------- prepare/scroll

$prepared = ifx_prepare('select id, v from ifx_n_types order by id', $link, IFX_SCROLL);
check('ifx_prepare întoarce o resursă', is_resource($prepared));
check('ifx_do', ifx_do($prepared) === true);
$first = ifx_fetch_row($prepared);
eq('primul rând', '1', $first['id'] ?? null);
$again = ifx_fetch_row($prepared, 'FIRST');
eq('poziția FIRST', '1', $again['id'] ?? null);
$last = ifx_fetch_row($prepared, 'LAST');
eq('LAST', '1', $last['id'] ?? null);
ifx_free_result($prepared);

// ---------------------------------------------------------------- default link

$defaultResult = ifx_query('select tabid from systables where tabid = 1');
check('ifx_query fără link folosește conexiunea implicită', is_resource($defaultResult));
$defaultRow = ifx_fetch_row($defaultResult);
eq('rând din conexiunea implicită', '1', $defaultRow['tabid'] ?? null);
ifx_free_result($defaultResult);

// ---------------------------------------------------------------- DML

$insert = ifx_query("insert into ifx_n_dml (name, n) values ('unul', 10)", $link);
check('insert întoarce resursă', is_resource($insert));
eq('ifx_affected_rows insert', 1, ifx_affected_rows($insert));
$sqlca = ifx_getsqlca($insert);
check('ifx_getsqlca este array', is_array($sqlca));
eq('sqlerrd2 = rânduri afectate', 1, $sqlca['sqlerrd2'] ?? null);
check('serial în sqlerrd1', ($sqlca['sqlerrd1'] ?? 0) > 0);
ifx_free_result($insert);

$update = ifx_query("update ifx_n_dml set n = 20 where name = 'unul'", $link);
eq('ifx_affected_rows update', 1, ifx_affected_rows($update));
ifx_free_result($update);

$selectDml = ifx_query("select n from ifx_n_dml where name = 'unul'", $link);
$rowDml = ifx_fetch_row($selectDml);
eq('update vizibil', '20', $rowDml['n'] ?? null);
ifx_free_result($selectDml);

$delete = ifx_query("delete from ifx_n_dml where name = 'unul'", $link);
eq('ifx_affected_rows delete', 1, ifx_affected_rows($delete));
ifx_free_result($delete);

// ---------------------------------------------------------------- LOBS

ifx_blobinfile_mode(0);
ifx_textasvarchar(0);
ifx_byteasvarchar(0);

$text = ifx_create_blob(1, 0, 'hello text');
$byte = ifx_create_blob(0, 0, "bin\0data");
check('ifx_create_blob TEXT', is_int($text));
check('ifx_create_blob BYTE', is_int($byte));
eq('ifx_get_blob TEXT', 'hello text', ifx_get_blob($text));
eq('ifx_get_blob BYTE', "bin\0data", ifx_get_blob($byte));

$lobInsert = ifx_query('insert into ifx_n_lob (txt, byt, label) values (?, ?, ?)', $link, 0, [$text, $byte, 'native']);
check('insert LOB', is_resource($lobInsert));
eq('ifx_affected_rows insert LOB', 1, ifx_affected_rows($lobInsert));
ifx_free_result($lobInsert);

$lobSelect = ifx_query("select txt, byt from ifx_n_lob where label = 'native'", $link);
$lobRow = ifx_fetch_row($lobSelect);
check('TEXT este blob id', is_int($lobRow['txt'] ?? null));
check('BYTE este blob id', is_int($lobRow['byt'] ?? null));
eq('conținut TEXT', 'hello text', ifx_get_blob($lobRow['txt']));
eq('conținut BYTE', "bin\0data", ifx_get_blob($lobRow['byt']));
ifx_free_result($lobSelect);

ifx_textasvarchar(1);
ifx_byteasvarchar(1);
$lobSelect = ifx_query("select txt, byt from ifx_n_lob where label = 'native'", $link);
$lobRow = ifx_fetch_row($lobSelect);
eq('ifx_textasvarchar', 'hello text', $lobRow['txt'] ?? null);
eq('ifx_byteasvarchar', "bin\0data", $lobRow['byt'] ?? null);
ifx_free_result($lobSelect);
ifx_textasvarchar(0);
ifx_byteasvarchar(0);

ifx_blobinfile_mode(1);
$lobSelect = ifx_query("select txt from ifx_n_lob where label = 'native'", $link);
$lobRow = ifx_fetch_row($lobSelect);
$path = ifx_get_blob($lobRow['txt']);
check('mod fișier întoarce cale', is_string($path) && is_file($path));
eq('conținut fișier', 'hello text', file_get_contents($path));
ifx_free_blob($lobRow['txt']);
check('fișierul temporar e șters', !is_file($path));
ifx_free_result($lobSelect);
ifx_blobinfile_mode(0);

// ---------------------------------------------------------------- CHAR/SLOB

$char = ifx_create_char('char value');
check('ifx_create_char', is_int($char));
eq('ifx_get_char', 'char value', ifx_get_char($char));
check('ifx_update_char', ifx_update_char($char, 'updated'));
eq('ifx_get_char după update', 'updated', ifx_get_char($char));
check('ifx_free_char', ifx_free_char($char));

$slob = ifxus_create_slob(0);
check('ifxus_create_slob', is_int($slob));
eq('ifxus_tell_slob', 0, ifxus_tell_slob($slob));
eq('ifxus_write_slob', 5, ifxus_write_slob($slob, 'hello'));
eq('ifxus_tell_slob după write', 5, ifxus_tell_slob($slob));
ifxus_seek_slob($slob, 0, 0);
eq('ifxus_read_slob', 'hello', ifxus_read_slob($slob, 5));
check('ifxus_close_slob', ifxus_close_slob($slob));
check('ifxus_open_slob', ifxus_open_slob($slob, 0) === 0);
check('ifxus_free_slob', ifxus_free_slob($slob));

// ---------------------------------------------------------------- extra

$persistent = ifx_pconnect($legacyDsn, $user, $password);
check('ifx_pconnect întoarce resursă', is_resource($persistent));
ifx_close($persistent);

$persistent0 = ifx_pconnect();
check('ifx_pconnect fără argumente folosește IFX_DEFAULT_*', is_resource($persistent0));
ifx_close($persistent0);

$persistent1 = ifx_pconnect($legacyDsn);
check('ifx_pconnect cu un singur argument completează user/parola', is_resource($persistent1));
ifx_close($persistent1);

$char = ifx_create_char('7');
$paramResult = ifx_query('select tabid from systables where tabid = ?', $link, [$char]);
check('al treilea argument poate fi array de blob-uri', is_resource($paramResult));
$paramRow = ifx_fetch_row($paramResult);
eq('parametru CHAR folosit ca ?', '7', $paramRow['tabid'] ?? null);
ifx_free_result($paramResult);
ifx_free_char($char);

$original = ifx_create_blob(1, 0, 'original');
$copy = ifx_copy_blob($original);
check('ifx_copy_blob', is_int($copy));
check('ifx_update_blob', ifx_update_blob($copy, 'copie'));
eq('originalul rămâne neschimbat', 'original', ifx_get_blob($original));
eq('copia este actualizată', 'copie', ifx_get_blob($copy));
ifx_free_blob($copy);
ifx_free_blob($original);

ifx_charasvarchar(1);
$charResult = ifx_query("select c from ifx_n_types", $link);
$charRow = ifx_fetch_row($charResult);
eq('ifx_charasvarchar taie padding-ul', 'abc', $charRow['c'] ?? null);
ifx_free_result($charResult);
ifx_charasvarchar(0);

ifx_nullformat(1);
$nullResult = ifx_query('select null::integer as n from systables', $link);
$nullRow = ifx_fetch_row($nullResult);
eq('ifx_nullformat', 'NULL', $nullRow['n'] ?? null);
ifx_free_result($nullResult);
ifx_nullformat(0);

check('ifx_errormsg pentru cod cunoscut', is_string(ifx_errormsg(-206)));

// ---------------------------------------------------------------- HTML/errors

ob_start();
$htmlCount = ifx_htmltbl_result(ifx_query('select id, v from ifx_n_types', $link));
$html = ob_get_clean();
eq('ifx_htmltbl_result număr rânduri', 1, $htmlCount);
contains('HTML tabel', '<table><tr><th>Id</th><th>V</th></tr>', $html);
contains('HTML rând', '<td>1</td><td>hello</td>', $html);

$bad = ifx_query('select * from tabela_inexistenta', $link);
check('query invalid e false', $bad === false);
contains('ifx_error invalid', 'SQLSTATE=', ifx_error());
check('ifx_errormsg invalid nu e gol', ifx_errormsg() !== '');

// ---------------------------------------------------------------- close

check('ifx_close link', ifx_close($link) === true);

$badLink = @ifx_connect('baza_inexistenta@invalid', $user, $password);
check('ifx_connect eșuat întoarce false, nu null', $badLink === false);
contains('ifx_error după connect eșuat', 'SQLCODE=', ifx_error());

check('ifx_query după close e false', @ifx_query('select 1 from systables', $link) === false);

echo "\n----------------------------------------\n";
echo 'Teste: ' . ($count - count($failures)) . ' trecute, ' . count($failures) . " eșuate\n";
if ($failures !== []) {
    echo "Eșecuri:\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}
echo "OK\n";
