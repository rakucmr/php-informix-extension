<?php

declare(strict_types=1);

/**
 * Schema de test, recreată la fiecare rulare a suitei.
 * Folosește PDO direct: aici nu testăm stratul de compatibilitate.
 */
function ensure_test_schema(\Informix\Connection $connection) {
    $pdo = $connection->pdo();

    foreach (['ifx_t_types', 'ifx_t_lob', 'ifx_t_dml'] as $table) {
        try {
            $pdo->exec('drop table ' . $table);
        } catch (\PDOException $e) {
            // tabela nu există
        }
    }

    $pdo->exec('create table ifx_t_types (
        id    serial,
        si    smallint,
        i     integer,
        bi    bigint,
        d     decimal(12,2),
        m     money,
        f     float,
        c     char(10),
        v     varchar(40),
        lv    lvarchar(100),
        dt    date,
        dtt   datetime year to second,
        b     boolean
    )');

    $pdo->exec("insert into ifx_t_types (si, i, bi, d, m, f, c, v, lv, dt, dtt, b)
        values (-7, 42, 9007199254740993, 12345.67, 123.45, 4.5,
                'abc', 'hello', 'long text', today, current, 't')");

    $pdo->exec('create table ifx_t_lob (
        id    serial,
        txt   text,
        byt   byte,
        blb   blob,
        clb   clob,
        label varchar(40)
    )');

    $pdo->exec('create table ifx_t_dml (
        id   serial,
        name varchar(30),
        n    integer
    )');
    $pdo->exec('create unique index ifx_t_dml_name on ifx_t_dml (name)');
}

function drop_rows(\Informix\Connection $connection, string $table, string $where = '1=1') {
    try {
        $connection->pdo()->exec('delete from ' . $table . ' where ' . $where);
    } catch (\PDOException $e) {
        // ignorăm: curățenie best-effort
    }
}
