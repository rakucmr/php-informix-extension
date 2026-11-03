# php_informix

Biblioteca PHP pură care reimplementează API-ul procedural `ifx_*` / `ifxus_*`
din extensia `ext/informix` (PHP 5.1) peste driverul PDO `pdo_informix`.

**Licență:** MIT pentru codul original; `pdo_informix` rămâne Apache-2.0.
Vezi [`../LICENSE`](../LICENSE) și [`../LICENSING.md`](../LICENSING.md).

- fără compilare și fără ESQL/C;
- un singur `require` sau autoload Composer;
- funcții globale, link-uri și rezultate ca obiecte PHP;
- suport PHP 7.0–8.5;
- teste de integrare reale împotriva serverului Informix.

## Instalare

```php
require __DIR__ . '/src/autoload.php';
```

sau, din Composer:

```bash
composer require informix/ifx-compat
```

## Exemplu

```php
<?php

require __DIR__ . '/src/autoload.php';

$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query('select tabid, tabname from systables', $conn, IFX_SCROLL);

while (($row = ifx_fetch_row($res)) !== false) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}

ifx_free_result($res);
ifx_close($conn);
```

## Testare

Setați `IFX_TEST_DSN`, `IFX_TEST_USER` și `IFX_TEST_PASSWORD`, apoi:

```bash
php tests/run-tests.php
```

Suita standard: **93 trecute, 1 sărit** (testul UTF-8 este sărit pe o bază
`en_US.819`).

## Benchmark

`tools/benchmark.php` compară PDO direct, `ifx` (direct peste CSDK),
`ifx_pdo` (peste PDO) și biblioteca PHP (peste PDO). Varianta directă nu are
nevoie de `pdo_informix`; `ifx_pdo` are nevoie de el.
Rezultatele măsurate pe PHP 8.5.11 NTS / Informix 14.10 sunt în
[`../docs/reference/BENCHMARK.md`](../docs/reference/BENCHMARK.md) și pe site,
în [documentația php_informix](../docs/php_informix.html#performance).

`tools/parallel-benchmark.php` rulează aceleași variante cu procese PHP separate
și conexiuni simultane; raportează throughput și p50/p95/p99. Serverul local de
test este IDS Developer Edition, cu licență pentru **25** de conexiuni
simultane, deci testele peste 25 sunt respinse de server. Nivelul se controlează
cu `--concurrency`.

```powershell
$env:IFX_BENCH_DSN = '<database>@<server>'
$env:IFX_BENCH_PDO_DSN = 'informix:server=<server>;database=<database>;host=<host>;service=<service>;protocol=olsoctcp;'
$env:IFX_BENCH_USER = '<user>'
$env:IFX_BENCH_PASSWORD = '<password>'

php -n -d extension_dir=C:\php\ext -d extension=pdo_informix `
  tools/benchmark.php --mode=library --rounds=5
```

## Structură

```text
php_informix/
├─ src/            biblioteca (Informix\Api, Connection, Result, Config, handles)
├─ tests/          suita de integrare
├─ examples/       exemple funcționale
├─ tools/          runner pentru Windows și benchmark
└─ composer.json
```

Documentația completă și prezentarea celor patru module sunt în
[`../docs/index.html`](../docs/index.html).
