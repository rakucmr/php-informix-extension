# pdo_informix pentru PHP 8.5 (Windows + Linux)

Extensia PDO pentru IBM Informix, plecând de la PECL 1.3.7 + modificările de
compatibilitate din fork-ul `nahuelon/pdo_informix`, compilată pe Windows și pe
Linux (Docker) și testată real împotriva serverului IDS 14.10.

**Licență:** Apache License 2.0 (cod IBM/PDO_INFORMIX). Vezi
[`src/LICENSE`](src/LICENSE) și [`../LICENSING.md`](../LICENSING.md).

```
C:\path\to\pdo_informix
├─ src\         sursa extensiei (PHP 8.1 - 8.5)
├─ test\        teste funcționale PHP, runner + raportare în baza de date
├─ extension\   scripturi de build/instalare (Windows); DLL-ul rezultă local
├─ docker\      imagine Linux (php:8.5-cli + CSDK) pentru rularea testelor
└─ README.md
```

Binarele (`php_pdo_informix*.dll`, `pdo_informix.so`, `.lib`) sunt rezultate de
build și **nu sunt publicate** în repository.

## Stare

| Element | Windows | Linux (Docker) |
|---|---|---|
| Compilare | `cl /LD` (MSVC 14.41), `php8.lib` + `iclit09b.lib` | `phpize` + `configure --with-pdo-informix`, GCC |
| Rezultat | `extension\php_pdo_informix.dll` (39 KB) | `pdo_informix.so` în imagine |
| Încărcare | `PDO::getAvailableDrivers()` → `["informix"]` | idem |
| Teste | 9/9 pass (198 assertions), ~2,7 s | 9/9 pass (198 assertions), ~2,2 s |
| Versiuni PHP | **8.1.12 NTS, 8.3.36 NTS, 8.5.11 NTS** — aceeași sursă, fără patch-uri | **8.5.12 NTS** |
| Server | IDS 14.10.FC12W12DE, baza `testdb`, user `<user>` | idem, prin `host.docker.internal:9088` |
| Rezultate în DB | tabela `test_results` (`os_name = 'Windows'`) | tabela `test_results` (`os_name = 'Linux'`) |

## Start rapid (Windows)

```bat
rem 1. compilare (dacă modifici sursa)
cd "C:\path\to\pdo_informix\extension"
build.bat

rem 2. mediu + testare
set INFORMIXDIR=C:\IBM\Informix_Client_SDK
set INFORMIXSERVER=informix_server
set INFORMIXSQLHOSTS=C:\IBM\Informix\etc\sqlhosts.informix_server
set PATH=%INFORMIXDIR%\bin;%PATH%

php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix ^
    "C:\path\to\pdo_informix\test\run-tests.php"
```

Pentru o altă versiune de PHP, primul argument al lui `build.bat` este pachetul
de dezvoltare, iar al treilea numele DLL-ului rezultat:

```bat
build.bat "C:\php-devel\phpother\x\php-8.3.36-devel-vs16-x64" ^
          "C:\IBM\Informix_Client_SDK" php_pdo_informix_83.dll
```

## Start rapid (Linux, Docker)

```powershell
pwsh -File docker\test-on-linux.ps1
```

Imaginea are nevoie de arhiva IBM Informix Client SDK pentru Linux, care nu
este distribuită în repository. Copiați-o ca `docker/csdk/clientsdk.tar`
înainte de primul build; restul fișierelor Docker sunt pregătite.

## Rezultate în baza de date

`test/report.php` rulează toate testele și scrie câte o linie pentru fiecare în
tabela `test_results` (creată automat, în dbspace `datadbs`):

| OS | PHP | run_id | teste | aserțiuni | eșecuri | durată |
|---|---|---|---|---|---|---|
| Windows | 8.5.11 | `6ae46d70-…` | 9 | 198 | 0 | 2763 ms |
| Windows | 8.3.36 | `6ae46d6d-…` | 9 | 198 | 0 | 2778 ms |
| Windows | 8.1.12 | `6ae46d6a-…` | 9 | 198 | 0 | 2704 ms |
| Linux | 8.5.12 | `6ae46da7-…` | 9 | 198 | 0 | 2158 ms |

Toate cele trei versiuni de PHP folosesc **aceeași sursă**, fără patch-uri: doar
pachetul de dezvoltare diferă între compilări.

```sql
SELECT os_name, run_id, MIN(executed_at) AS started, COUNT(*) AS teste,
       SUM(failures) AS esecuri, SUM(duration_ms) AS ms
FROM test_results GROUP BY os_name, run_id ORDER BY started DESC;
```

Testul `t07_lob_trigger` **nu își șterge obiectele**: în `testdb` rămân
`pdo_t_lob`, `pdo_t_lob_log` și trigger-ele `pdo_t_lob_ins/_upd/_del`, iar `t08`
lasă `pdo_t_merge_target`, `pdo_t_merge_source`, `pdo_t_merge_log` cu trigger-ele
aferente. Rulările repetate adaugă rânduri noi (etichete unice per rulare);
pentru curățare, rulează testul cu `PDO_INFORMIX_KEEP_LOB=0`.

## Utilizare din PHP

```php
$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'),
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS')
);
```

DSN-ul care conține `=` este trimis prin `SQLDriverConnect`; forma scurtă
(`informix:testdb`) trece prin `SQLConnect` și are nevoie de un DSN în `odbc.ini`.

Coloanele `JSON`/`BSON` se întorc ca `string` (nu ca stream), deci
`json_decode($row['doc'])` funcționează direct; stream-urile LOB rămân pentru
`TEXT`/`BYTE`/`CLOB`/`BLOB`.

Documentație detaliată: [`DOCUMENTATIE.md`](DOCUMENTATIE.md) (ghid complet:
sursă, build Windows/Linux, teste, capcane Informix, anexă de comenzi), plus
[`src/README.md`](src/README.md), [`test/README.md`](test/README.md),
[`extension/README.md`](extension/README.md), [`docker/README.md`](docker/README.md).






















