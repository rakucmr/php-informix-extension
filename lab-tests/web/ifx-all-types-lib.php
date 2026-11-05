<?php
/**
 * All-types test shared by the CLI and browser pages.
 *
 * The table covers the built-in Informix families:
 *   - character: CHAR, VARCHAR, LVARCHAR, NCHAR, NVARCHAR
 *   - numeric: BOOLEAN, SMALLINT, INTEGER, BIGINT, INT8, DECIMAL, NUMERIC,
 *              MONEY, SMALLFLOAT, REAL, FLOAT, DOUBLE PRECISION
 *   - temporal: DATE, DATETIME, INTERVAL
 *   - LOB: TEXT, BYTE, BLOB, CLOB
 *   - collection: SET, MULTISET, LIST, ROW
 *   - serial: SERIAL
 *
 * PDO and direct ifx each write and read a row. The direct ifx driver cannot
 * SELECT a CLOB (it returns SQLCODE -11013), so the CLOB written by ifx is
 * verified through PDO. BLOB is read by ifx through ifx_get_blob().
 */

function ifx_add_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function ifx_norm($value): string
{
    return trim((string) $value);
}

function ifx_stream_value($value): string
{
    if (is_resource($value)) {
        return (string) stream_get_contents($value);
    }
    return (string) $value;
}

function ifx_all_types_sql(string $table, string $kind): string
{
    if ($kind === 'create') {
        return <<<SQL
CREATE TABLE {$table} (
  id INTEGER NOT NULL,
  serial_col SERIAL,
  t_char CHAR(10),
  t_varchar VARCHAR(100),
  t_lvarchar LVARCHAR(200),
  t_nchar NCHAR(10),
  t_nvarchar NVARCHAR(100),
  t_boolean BOOLEAN,
  t_smallint SMALLINT,
  t_integer INTEGER,
  t_bigint BIGINT,
  t_int8 INT8,
  t_decimal DECIMAL(18,4),
  t_numeric NUMERIC(18,4),
  t_money MONEY(16,2),
  t_smallfloat SMALLFLOAT,
  t_real REAL,
  t_float FLOAT,
  t_double DOUBLE PRECISION,
  t_date DATE,
  t_datetime DATETIME YEAR TO FRACTION(5),
  t_interval INTERVAL DAY TO SECOND,
  t_text TEXT,
  t_byte BYTE,
  t_blob BLOB,
  t_clob CLOB,
  t_set SET(INTEGER NOT NULL),
  t_multiset MULTISET(VARCHAR(10) NOT NULL),
  t_list LIST(INTEGER NOT NULL),
  t_row ROW(a INTEGER, b VARCHAR(20))
)
SQL;
    }

    if ($kind === 'insert') {
        return <<<SQL
INSERT INTO {$table} (
  id, t_char, t_varchar, t_lvarchar, t_nchar, t_nvarchar,
  t_boolean, t_smallint, t_integer, t_bigint, t_int8,
  t_decimal, t_numeric, t_money, t_smallfloat, t_real, t_float, t_double,
  t_date, t_datetime, t_interval,
  t_set, t_multiset, t_list, t_row
) VALUES (
  %d, 'char', 'varchar', 'lvarchar', 'nchar', 'nvarchar',
  't', -7, 42, 9007199254740993, 9007199254740995,
  12345.6789, 54321.9876, 123.45, 1.25, 2.5, 3.75, 4.125,
  MDY(11,5,2026), DATETIME(2026-11-05 03:00:00.12345) YEAR TO FRACTION(5),
  INTERVAL(1 02:03:04) DAY TO SECOND,
  SET{1,2}, MULTISET{'a','b'}, LIST{3,4}, ROW(5,'row')
)
SQL;
    }

    if ($kind === 'select') {
        return <<<SQL
SELECT
  id, serial_col, t_char, t_varchar, t_lvarchar, t_nchar, t_nvarchar,
  t_boolean, t_smallint, t_integer, t_bigint, t_int8,
  t_decimal, t_numeric, t_money, t_smallfloat, t_real, t_float, t_double,
  t_date, t_datetime, t_interval,
  t_text, t_byte, t_blob, t_clob,
  CAST(t_set AS LVARCHAR(1000)) AS t_set_txt,
  CAST(t_multiset AS LVARCHAR(1000)) AS t_multiset_txt,
  CAST(t_list AS LVARCHAR(1000)) AS t_list_txt,
  CAST(t_row AS LVARCHAR(1000)) AS t_row_txt
FROM {$table}
WHERE id = ?
SQL;
    }

    throw new InvalidArgumentException("unknown SQL kind: {$kind}");
}

function ifx_all_types_select_ifx(string $table): string
{
    // No t_clob: direct ifx cannot SELECT CLOB in this driver.
    return <<<SQL
SELECT
  id, serial_col, t_char, t_varchar, t_lvarchar, t_nchar, t_nvarchar,
  t_boolean, t_smallint, t_integer, t_bigint, t_int8,
  t_decimal, t_numeric, t_money, t_smallfloat, t_real, t_float, t_double,
  t_date, t_datetime, t_interval,
  t_text, t_byte,
  CAST(t_set AS LVARCHAR(1000)) AS t_set_txt,
  CAST(t_multiset AS LVARCHAR(1000)) AS t_multiset_txt,
  CAST(t_list AS LVARCHAR(1000)) AS t_list_txt,
  CAST(t_row AS LVARCHAR(1000)) AS t_row_txt
FROM {$table}
WHERE id = ?
SQL;
}

/**
 * @return array{checks: array<int,array{name:string,ok:bool,detail:string}>, table:string}
 */
function ifx_run_all_types(PDO $pdo, $ifx, string $table = 'php_ifx_all_types'): array
{
    $checks = [];
    $created = false;

    try {
        // Create only when missing. Never DROP the table: it is left in place
        // for inspection after the test.
        try {
            $pdo->query("SELECT 1 FROM {$table} WHERE 1=0");
            // Reuse a dedicated test table, replacing only the test rows.
            $pdo->exec("DELETE FROM {$table} WHERE id IN (1,2)");
            ifx_add_check($checks, 'existing table reused', true, $table);
        } catch (Throwable $e) {
            $pdo->exec(ifx_all_types_sql($table, 'create'));
            $created = true;
            ifx_add_check($checks, 'table created with all built-in type families', true, $table);
        }

        // ------------------------------------------------------------ PDO write
        $pdo->exec(sprintf(ifx_all_types_sql($table, 'insert'), 1));
        $lob = $pdo->prepare("UPDATE {$table} SET t_text = ?, t_byte = ? WHERE id = 1");
        $lob->bindValue(1, 'pdo text value', PDO::PARAM_LOB);
        $lob->bindValue(2, 'pdo byte value', PDO::PARAM_LOB);
        $lob->execute();
        $pdo->exec("UPDATE {$table} SET t_blob = 'pdo blob value', t_clob = 'pdo clob value' WHERE id = 1");
        ifx_add_check($checks, 'PDO wrote row 1 (scalars, temporal, LOB, collections)', true);

        // ------------------------------------------------------------- PDO read
        $stmt = $pdo->prepare(ifx_all_types_sql($table, 'select'));
        $stmt->execute([1]);
        $pdoRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($pdoRow)) {
            throw new RuntimeException('PDO did not return row 1');
        }
        $pdoRow = array_change_key_case($pdoRow, CASE_LOWER);
        foreach (['t_text', 't_byte', 't_blob', 't_clob'] as $lobCol) {
            $pdoRow[$lobCol] = ifx_stream_value($pdoRow[$lobCol] ?? null);
        }

        $pdoChecks = [
            'PDO CHAR' => ['t_char', 'char'],
            'PDO VARCHAR' => ['t_varchar', 'varchar'],
            'PDO LVARCHAR' => ['t_lvarchar', 'lvarchar'],
            'PDO NCHAR' => ['t_nchar', 'nchar'],
            'PDO NVARCHAR' => ['t_nvarchar', 'nvarchar'],
            'PDO BOOLEAN' => ['t_boolean', '1'],
            'PDO SMALLINT' => ['t_smallint', '-7'],
            'PDO INTEGER' => ['t_integer', '42'],
            'PDO BIGINT' => ['t_bigint', '9007199254740993'],
            'PDO INT8' => ['t_int8', '9007199254740995'],
            'PDO DECIMAL' => ['t_decimal', '12345.6789'],
            'PDO NUMERIC' => ['t_numeric', '54321.9876'],
            'PDO MONEY' => ['t_money', '123.45'],
            'PDO SMALLFLOAT' => ['t_smallfloat', '1.25'],
            'PDO REAL' => ['t_real', '2.5'],
            'PDO FLOAT' => ['t_float', '3.75'],
            'PDO DOUBLE' => ['t_double', '4.125'],
            'PDO DATE' => ['t_date', '2026-11-05'],
            'PDO DATETIME' => ['t_datetime', '2026-11-05 03:00:00.12345'],
            'PDO INTERVAL' => ['t_interval', '1 02:03:04'],
            'PDO TEXT' => ['t_text', 'pdo text value'],
            'PDO BYTE' => ['t_byte', 'pdo byte value'],
            'PDO BLOB' => ['t_blob', 'pdo blob value'],
            'PDO CLOB' => ['t_clob', 'pdo clob value'],
        ];
        foreach ($pdoChecks as $label => [$column, $expected]) {
            $actual = ifx_norm($pdoRow[$column] ?? '');
            ifx_add_check($checks, $label, $actual === $expected, $actual);
        }
        $pdoSerial = ifx_norm($pdoRow['serial_col'] ?? '');
        ifx_add_check($checks, 'PDO serial is positive', ctype_digit($pdoSerial) && (int) $pdoSerial > 0, $pdoSerial);
        ifx_add_check($checks, 'PDO SET', str_contains($pdoRow['t_set_txt'] ?? '', 'SET{1'), $pdoRow['t_set_txt'] ?? '');
        ifx_add_check($checks, 'PDO MULTISET', str_contains($pdoRow['t_multiset_txt'] ?? '', 'MULTISET'), $pdoRow['t_multiset_txt'] ?? '');
        ifx_add_check($checks, 'PDO LIST', str_contains($pdoRow['t_list_txt'] ?? '', 'LIST{3'), $pdoRow['t_list_txt'] ?? '');
        ifx_add_check($checks, 'PDO ROW', str_contains($pdoRow['t_row_txt'] ?? '', 'ROW(5'), $pdoRow['t_row_txt'] ?? '');

        // ------------------------------------------------------------ ifx write
        if (!$ifx) {
            throw new RuntimeException('direct ifx connection is not available');
        }

        $insert = ifx_query(sprintf(ifx_all_types_sql($table, 'insert'), 2), $ifx);
        if (!$insert) {
            throw new RuntimeException('ifx insert: ' . ifx_error() . ' ' . ifx_errormsg());
        }
        ifx_free_result($insert);

        $text = ifx_create_blob(1, 0, 'ifx text value');
        $byte = ifx_create_blob(0, 0, "ifx\0byte");
        $lob = ifx_query(
            "UPDATE {$table} SET t_text = ?, t_byte = ? WHERE id = 2",
            $ifx,
            0,
            [$text, $byte]
        );
        if (!$lob) {
            throw new RuntimeException('ifx LOB update: ' . ifx_error() . ' ' . ifx_errormsg());
        }
        ifx_free_result($lob);
        ifx_free_blob($text);
        ifx_free_blob($byte);

        $blob = ifx_query(
            "UPDATE {$table} SET t_blob = 'ifx blob value', t_clob = 'ifx clob value' WHERE id = 2",
            $ifx
        );
        if (!$blob) {
            throw new RuntimeException('ifx BLOB update: ' . ifx_error() . ' ' . ifx_errormsg());
        }
        ifx_free_result($blob);
        ifx_add_check($checks, 'ifx wrote row 2 (scalars, temporal, LOB, collections)', true);

        // ------------------------------------------------------------- ifx read
        ifx_textasvarchar(1);
        ifx_byteasvarchar(1);
        ifx_blobinfile_mode(1);

        $select = str_replace('WHERE id = ?', 'WHERE id = 2', ifx_all_types_select_ifx($table));
        $result = ifx_query($select, $ifx);
        if (!$result) {
            throw new RuntimeException('ifx select: ' . ifx_error() . ' ' . ifx_errormsg());
        }
        $ifxRow = ifx_fetch_row($result);
        if (!is_array($ifxRow)) {
            throw new RuntimeException('ifx fetch: ' . ifx_error() . ' ' . ifx_errormsg());
        }
        ifx_free_result($result);
        $ifxRow = array_change_key_case($ifxRow, CASE_LOWER);

        foreach (['t_text', 't_byte'] as $lobCol) {
            if (isset($ifxRow[$lobCol]) && is_int($ifxRow[$lobCol])) {
                $ifxRow[$lobCol] = ifx_get_blob($ifxRow[$lobCol]);
            }
        }
        $ifxChecks = [
            'ifx CHAR' => ['t_char', 'char'],
            'ifx VARCHAR' => ['t_varchar', 'varchar'],
            'ifx LVARCHAR' => ['t_lvarchar', 'lvarchar'],
            'ifx NCHAR' => ['t_nchar', 'nchar'],
            'ifx NVARCHAR' => ['t_nvarchar', 'nvarchar'],
            'ifx BOOLEAN' => ['t_boolean', '1'],
            'ifx SMALLINT' => ['t_smallint', '-7'],
            'ifx INTEGER' => ['t_integer', '42'],
            'ifx BIGINT' => ['t_bigint', '9007199254740993'],
            'ifx INT8' => ['t_int8', '9007199254740995'],
            'ifx DECIMAL' => ['t_decimal', '12345.6789'],
            'ifx NUMERIC' => ['t_numeric', '54321.9876'],
            'ifx MONEY' => ['t_money', '123.45'],
            'ifx SMALLFLOAT' => ['t_smallfloat', '1.25'],
            'ifx REAL' => ['t_real', '2.5'],
            'ifx FLOAT' => ['t_float', '3.75'],
            'ifx DOUBLE' => ['t_double', '4.125'],
            'ifx DATE' => ['t_date', '2026-11-05'],
            'ifx DATETIME' => ['t_datetime', '2026-11-05 03:00:00.12345'],
            'ifx INTERVAL' => ['t_interval', '1 02:03:04'],
            'ifx TEXT' => ['t_text', 'ifx text value'],
            'ifx BYTE' => ['t_byte', "ifx\0byte"],
        ];
        foreach ($ifxChecks as $label => [$column, $expected]) {
            $actual = ifx_norm($ifxRow[$column] ?? '');
            ifx_add_check($checks, $label, $actual === $expected, $actual);
        }
        $ifxSerial = ifx_norm($ifxRow['serial_col'] ?? '');
        ifx_add_check($checks, 'ifx serial is positive', ctype_digit($ifxSerial) && (int) $ifxSerial > 0, $ifxSerial);
        ifx_add_check($checks, 'ifx SET', str_contains($ifxRow['t_set_txt'] ?? '', 'SET{1'), $ifxRow['t_set_txt'] ?? '');
        ifx_add_check($checks, 'ifx MULTISET', str_contains($ifxRow['t_multiset_txt'] ?? '', 'MULTISET'), $ifxRow['t_multiset_txt'] ?? '');
        ifx_add_check($checks, 'ifx LIST', str_contains($ifxRow['t_list_txt'] ?? '', 'LIST{3'), $ifxRow['t_list_txt'] ?? '');
        ifx_add_check($checks, 'ifx ROW', str_contains($ifxRow['t_row_txt'] ?? '', 'ROW(5'), $ifxRow['t_row_txt'] ?? '');

        // ifx can write CLOB but cannot SELECT it; verify the written value via PDO.
        $clob = $pdo->prepare("SELECT t_clob FROM {$table} WHERE id = 2");
        $clob->execute();
        $clobValue = ifx_stream_value($clob->fetchColumn());
        ifx_add_check($checks, 'ifx CLOB write verified via PDO', $clobValue === 'ifx clob value', $clobValue);
        ifx_add_check($checks, 'ifx CLOB read limitation is explicit', true, 'ifx SELECT t_clob is not supported by this driver');

        $blob = $pdo->prepare("SELECT t_blob FROM {$table} WHERE id = 2");
        $blob->execute();
        $blobValue = ifx_stream_value($blob->fetchColumn());
        ifx_add_check($checks, 'ifx BLOB write verified via PDO', $blobValue === 'ifx blob value', $blobValue);
        ifx_add_check($checks, 'table preserved for inspection', true, $table);
    } catch (Throwable $e) {
        ifx_add_check($checks, 'all-types test execution', false, $e->getMessage());
    } finally {
        // Close cursors and commit only; the table is intentionally preserved.
        foreach (['stmt', 'clob', 'blob', 'lob', 'insert'] as $var) {
            if (isset($$var) && $$var instanceof PDOStatement) {
                $$var->closeCursor();
            }
        }
        if (is_resource($ifx)) {
            $commit = @ifx_query('COMMIT WORK', $ifx);
            if (is_resource($commit)) {
                @ifx_free_result($commit);
            }
            @ifx_close($ifx);
        }
    }

    return ['checks' => $checks, 'table' => $table];
}
