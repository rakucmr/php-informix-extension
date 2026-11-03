# ifx — extensia directă peste Informix CSDK

Acesta este folderul extensiei **`ifx`**. Binarul este `ifx.dll` (Windows) sau
`ifx.so` (Linux) și se încarcă prin `extension=ifx`. Extensia este scrisă în C
și implementează API-ul istoric `ifx_*` / `ifxus_*` direct peste **Informix
Client SDK CLI**, fără PDO și fără `pdo_informix`.

**Licență:** MIT pentru codul original al acestui folder; vezi
[`../LICENSE`](../LICENSE) și [`../LICENSING.md`](../LICENSING.md).

> `ifx.dll` și `ifx_pdo.dll` exportă aceleași nume de funcții PHP. Nu le
> încărcați în același proces; PHP ar raporta funcții duplicate. `ifx` este
> varianta directă, iar `ifx_pdo` este varianta construită peste PDO.

## Acoperire

Sunt implementate toate cele **39** de funcții ale API-ului legacy:

- conexiune: `ifx_connect`, `ifx_pconnect`, `ifx_close`;
- execuție: `ifx_query`, `ifx_prepare`, `ifx_do`;
- citire: `ifx_fetch_row`, `ifx_free_result`, `ifx_num_rows`,
  `ifx_num_fields`, `ifx_affected_rows`;
- metadate: `ifx_fieldtypes`, `ifx_fieldproperties`, `ifx_getsqlca`;
- erori: `ifx_error`, `ifx_errormsg`;
- HTML: `ifx_htmltbl_result`;
- LOB/CHAR: `ifx_create_blob`, `ifx_copy_blob`, `ifx_free_blob`,
  `ifx_get_blob`, `ifx_update_blob`, `ifx_create_char`, `ifx_free_char`,
  `ifx_get_char`, `ifx_update_char`;
- SLOB: `ifxus_create_slob`, `ifxus_open_slob`, `ifxus_close_slob`,
  `ifxus_free_slob`, `ifxus_read_slob`, `ifxus_write_slob`,
  `ifxus_seek_slob`, `ifxus_tell_slob`;
- moduri: `ifx_textasvarchar`, `ifx_byteasvarchar`, `ifx_charasvarchar`,
  `ifx_nullformat`, `ifx_blobinfile_mode`.

Sunt definite și constantele `IFX_SCROLL`, `IFX_HOLD`, `IFX_LO_RDONLY`,
`IFX_LO_WRONLY`, `IFX_LO_APPEND`, `IFX_LO_RDWR`, `IFX_LO_BUFFER` și
`IFX_LO_NOBUFFER`.

## Semantica implementării

- Conexiunea acceptă forma `database@server`, un DSN complet
  `server=...;database=...;UID=...;PWD=...;` sau `informix:` în față. Fără
  argumente se folosesc `IFX_DEFAULT_DATABASE`, `IFX_DEFAULT_USER` și
  `IFX_DEFAULT_PASSWORD` (apoi `INFORMIXDATABASE`).
- `ifx_query` pregătește statement-ul, leagă parametrii `?` și îl execută.
  Al treilea argument poate fi un cursor (`IFX_SCROLL`, `IFX_HOLD`) sau, în
  forma veche, direct array-ul de parametri. Al patrulea argument este
  array-ul de parametri.
- Parametrii pot fi scalari sau handle-uri de blob/CHAR/SLOB (întregi).
  Șirurile numerice rămân șiruri, ca să nu fie confundate cu un handle.
  TEXT este legat ca `SQL_LONGVARCHAR`, iar BYTE ca `SQL_LONGVARBINARY`.
- `ifx_pconnect` refolosește conexiunea deschisă cu aceleași argumente în
  cadrul requestului; `ifx_connect` deschide mereu o conexiune nouă.
- `ifx_prepare` creează cursorul fără execuție; `ifx_do` îl execută.
- `ifx_fetch_row` citește fiecare coloană cu `SQLGetData`, fără buffere fixe,
  deci valorile mari nu sunt trunchiate. Pe un cursor `IFX_SCROLL` acceptă
  `NEXT`, `FIRST`, `LAST`, `PRIOR`/`PREVIOUS`, `CURRENT`, un număr pozitiv
  (poziție absolută) sau negativ (de la final).
- TEXT/BYTE sunt întoarse ca handle numeric, ca în API-ul legacy. Cu
  `ifx_textasvarchar(1)` / `ifx_byteasvarchar(1)` sunt întoarse direct ca
  string. Cu `ifx_blobinfile_mode(1)` conținutul este scris într-un fișier
  temporar, iar `ifx_get_blob` întoarce calea fișierului.
- `ifx_charasvarchar(1)` elimină padding-ul coloanelor CHAR, iar
  `ifx_nullformat(1)` transformă NULL în string-ul `NULL`.
- `ifx_getsqlca` raportează `sqlerrd1` (SERIAL) și `sqlerrd2` (rânduri
  afectate). Pentru INSERT-urile cu parametri LOB, unde `SQLRowCount` poate
  întoarce 0, valorile sunt citite din `dbinfo('sqlca.sqlerrd1')` și
  `dbinfo('sqlca.sqlerrd2')`.
- Erorile sunt citite cu `SQLGetDiagRec`; `ifx_error` păstrează formatul
  legacy, iar `ifx_errormsg` oferă mesajul curent sau mesaje pentru codurile
  uzuale.

## Build pe Windows

Cerințe:

- PHP 7.0–8.5 x64 NTS/TS și pachetul de dezvoltare corespunzător;
- Visual Studio 2019/2022 cu C++;
- Informix Client SDK cu `incl\cli\infxcli.h` și `lib\iclit09b.lib`.

```bat
cd ifx_informix
build-ifx.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
```

Argumentele opționale sunt directorul pachetului de dezvoltare, numele DLL-ului
și directorul CSDK:

```bat
build-ifx.bat ^
  "C:\php-devel\devel\php-8.5.11-devel-vs17-x64" ^
  ifx.dll ^
  "C:\IBM\Informix_Client_SDK"
```

Rezultatul implicit este `ifx_informix\ifx.dll`.

Pentru PHP 7.0–7.4, DLL-ul trebuie marcat cu versiunea de linker a PHP-ului
(14.0 pentru 7.0/7.1, 14.16 pentru 7.2–7.4). `tests/run-native.ps1 -Build` face
asta automat; la build manual folosiți
`ifx_pdo_informix/tools/patch-pe-linker.ps1`.

## Build pe Linux

Este necesar un CSDK cu `incl/cli/infxcli.h` și biblioteca CLI:

```bash
cd ifx_informix
IFX_CSDK=/opt/ibm/informix ./build-ifx-linux.sh
```

Build-ul Linux folosește același `ifx.c`, dar matricea de test verificată în
acest proiect este Windows x64, pe PHP 7.0–8.5. `build-ifx-linux.sh` este
oferit pentru integrare și nu înlocuiește testele de pe Windows.

## Încărcare

```ini
extension=ifx
```

Exemplu complet: [`php.ini.example`](php.ini.example).

## Teste

Suita nativă are **102 verificări** și acoperă conexiunea, metadatele,
prepare/scroll, DML, SQLCA, LOB-uri, CHAR, SLOB, moduri, HTML și erorile.
A fost rulată pe PHP 7.0–8.5 x64, cu 102/102 pe fiecare versiune:

```powershell
$env:INFORMIXDIR = 'C:\IBM\Informix_Client_SDK'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = 'C:\path\to\sqlhosts'
$env:IFX_TEST_DSN = '<database>@<server>'
$env:IFX_TEST_USER = '<user>'
$env:IFX_TEST_PASSWORD = '<password>'

.\ifx_informix\tests\run-native.ps1 -Build `
  -Php 'C:\php\php.exe' `
  -Devel 'C:\php-devel\devel\php-8.5.11-devel-vs17-x64'
```

`IFX_TEST_PDO_DSN` este opțional și testează forma DSN completă; dacă
lipsește, se folosește `IFX_TEST_DSN`. Testul rapid este
[`tests/smoke-test.php`](tests/smoke-test.php).

Suita nu depinde de `pdo_informix` și creează doar cele trei tabele de test
(`ifx_n_types`, `ifx_n_lob`, `ifx_n_dml`), pe care le recreează la fiecare
rulare.

## Benchmark

```powershell
$env:IFX_BENCH_DSN = '<database>@<server>'
$env:IFX_BENCH_USER = '<user>'
$env:IFX_BENCH_PASSWORD = '<password>'

php -n -d extension=ifx `
  php_informix/tools/benchmark.php --mode=direct --rounds=5
```

Rezultatele sunt în
[`../docs/reference/BENCHMARK.md`](../docs/reference/BENCHMARK.md).

## Structura folderului

- `ifx.c` — implementarea C completă;
- `build-ifx.bat`, `build-ifx-linux.sh`, `config.w32`, `config.m4` — build;
- `ifx.dll` — binar Windows construit local, exclus din arhiva publică;
- `php.ini.example` — exemplu de încărcare;
- `tests/native-suite.php` — suita de 102 verificări;
- `tests/run-native.ps1` — runner Windows;
- `tests/smoke-test.php` — test rapid;
- `legacy/` — binare vechi `ifx_direct*.dll`, păstrate local, nepublicate.

Implementarea a fost realizată cu **DeepSeek (AI)**, sub îndrumarea autorului
proiectului.
