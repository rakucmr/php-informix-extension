<?php

declare(strict_types=1);

/**
 * Exemplu LOB: creare, inserare, citire în memorie și în mod fișier.
 *
 * Necesită drepturi de CREATE/DROP TABLE. Tabela este ștearsă la final.
 *
 *   php examples/lobs.php
 */

require __DIR__ . '/bootstrap.php';

$conn = example_connect();

// Curățenie best-effort (Informix nu are DROP TABLE IF EXISTS).
@ifx_query('drop table ifx_example_lob', $conn);

$create = ifx_query(
    'create table ifx_example_lob (
        id    serial,
        txt   text,
        byt   byte,
        blb   blob,
        clb   clob,
        label varchar(40)
    )',
    $conn
);

if ($create === false) {
    fwrite(STDERR, 'Nu pot crea tabela: ' . ifx_error() . PHP_EOL);
    exit(1);
}

$text = ifx_create_blob(1, 0, 'Salut din ifx-compat!');
$bytes = ifx_create_blob(0, 0, "binary\0data\xFF");

$insert = ifx_query(
    'insert into ifx_example_lob (txt, byt, blb, clb, label) values (?, ?, ?, ?, ?)',
    $conn,
    [$text, $bytes, $bytes, $text, 'exemplu']
);

if ($insert === false) {
    fwrite(STDERR, 'Insert eșuat: ' . ifx_error() . PHP_EOL);
    @ifx_query('drop table ifx_example_lob', $conn);
    exit(1);
}

echo 'Insert: ', ifx_affected_rows($insert), ' rând(uri)', PHP_EOL;

// 1. Citire implicită: LOB-urile sunt blob id-uri.
$result = ifx_query("select txt, byt, blb, clb from ifx_example_lob where label = 'exemplu'", $conn);
$row = ifx_fetch_row($result);

echo PHP_EOL, 'Blob id-uri: txt=', $row['txt'], ' byt=', $row['byt'], PHP_EOL;
echo 'TEXT: ', ifx_get_blob($row['txt']), PHP_EOL;
echo 'BYTE (hex): ', bin2hex(ifx_get_blob($row['byt'])), PHP_EOL;

// 2. Citire directă ca string.
ifx_textasvarchar(1);
ifx_byteasvarchar(1);
$row = ifx_fetch_row(ifx_query("select txt, byt from ifx_example_lob where label = 'exemplu'", $conn));
echo PHP_EOL, 'Ca string: ', $row['txt'], ' / ', bin2hex($row['byt']), PHP_EOL;
ifx_textasvarchar(0);
ifx_byteasvarchar(0);

// 3. Mod fișier: ifx_get_blob() întoarce calea temporară.
ifx_blobinfile_mode(1);
$row = ifx_fetch_row(ifx_query("select txt from ifx_example_lob where label = 'exemplu'", $conn));
$path = ifx_get_blob($row['txt']);
echo PHP_EOL, 'Fișier temporar: ', $path, PHP_EOL;
echo 'Conținut fișier: ', file_get_contents($path), PHP_EOL;
ifx_free_blob($row['txt']);
ifx_blobinfile_mode(0);

@ifx_query('drop table ifx_example_lob', $conn);
ifx_close($conn);
