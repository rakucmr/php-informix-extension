# ifx_pdo — extensia C `ifx_pdo.dll`

Numele PHP al acestei variante este **`ifx_pdo`**. Are nevoie de
**`pdo_informix`** (`php_pdo_informix.dll` sau `pdo_informix.so`). Extensia
produce `ifx_pdo.dll` pe Windows sau `ifx_pdo.so` pe Linux/Unix, se încarcă prin
`extension=ifx_pdo` și expune toate cele 39 de funcții `ifx_*` / `ifxus_*`.
Folderul acestei variante este `ifx_pdo_informix/`.

**Licență:** MIT pentru codul original al adaptorului; `pdo_informix` rămâne
Apache-2.0. Vezi [`../LICENSE`](../LICENSE) și
[`../LICENSING.md`](../LICENSING.md).

Nu confundați această variantă cu `ifx` (direct) din `../ifx_informix/`, care
folosește `ifx.dll` și **nu** are nevoie de `pdo_informix`. Și `ifx` implementează
toate cele 39 de funcții, dar direct peste CSDK, nu prin PDO.

## Ce este, de fapt

Este o extensie C reală din punctul de vedere al PHP:

- are `get_module`, funcții C în `zend_function_entry`, resurse Zend pentru
  link-uri și rezultate, directive INI `ifx.*`;
- se încarcă prin `extension=ifx_pdo` și nu are nevoie de `require`;
- link-urile și rezultatele sunt `resource`, deci `is_resource()` funcționează
  ca în extensia veche.

Motorul care vorbește cu PDO și implementează semantica legacy este însă
**încorporat în DLL** ca sursă PHP generată (`ifx_php.c`) și compilat o dată
pe request, la primul apel `ifx_*`. Funcțiile C sunt adaptoare subțiri către
acel motor. Nu este o rescriere integrală a `ifx.ec` în C.

Avantajul acestei abordări este că cele două variante (DLL și biblioteca PHP)
folosesc exact același cod pentru PDO, LOB-uri, cursoare și erori. Dezavantajul
este că logica rulează în motorul PHP încorporat, nu direct în C, iar prima
utilizare dintr-un request are costul compilării sursei încorporate.

## Cerințe de build

- **PHP 7.0–8.5** cu `ext-pdo`; pentru Windows, build-ul PHP trebuie să
  corespundă (NTS/TS, x64).
- **pachetul PHP de dezvoltare** potrivit (`php-devel-pack-8.5.x-...`), care
  conține `include\` și `lib\php7.lib`/`php7ts.lib` sau `php8.lib`/`php8ts.lib`;
- **Visual Studio 2019/2022** cu workload „Desktop development with C++”;
- pe Linux: `phpize`, `gcc`, `make`, `autoconf`, plus pachetul PHP de dezvoltare;
- **pdo_informix** instalat la runtime (DLL-ul sau `.so`-ul driverului).

Nu este nevoie de Informix Client SDK la compilarea acestei extensii: ea nu
leagă `iclit09b.lib`; driverul PDO face acest lucru.

## Compilare separată

```bat
cd ifx_pdo_informix
build-ifx.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
```

Al doilea argument este numele DLL-ului de ieșire:

```bat
build-ifx.bat "C:\php-devel\devel\php-8.1.12-devel-vs16-x64" ifx_pdo_81.dll
```

Scriptul alege automat `php7.lib`/`php8.lib` (NTS) sau `php7ts.lib`/`php8ts.lib` (TS) și adaugă
definiția `ZTS` când este nevoie. Pentru PHP 8.5 NTS rezultatul este
`ext\ifx_pdo.dll`.

### Linux / Unix

În directorul `ext/`, cu PHP și instrumentele de build instalate
(`phpize`, `gcc`, `make`, `autoconf`):

```bash
cd ifx_pdo_informix
sh build-ifx-linux.sh
```

Rezultă `ifx_pdo_informix/ifx_pdo.so`. Se încarcă la fel:

```ini
extension=pdo_informix
extension=ifx_pdo
```

Pentru testarea în Docker există [`tools/test-linux.ps1`](tools/test-linux.ps1),
care compilează `.so`-ul într-un container și rulează ambele suite:

```powershell
.\tools\test-linux.ps1 -Build
```

Containerul trebuie să aibă `pdo_informix` și Informix Client SDK, iar
`INFORMIXSQLHOSTS` trebuie să indice un server accesibil din container.
În Docker Desktop pe Windows, serverul Informix care ascultă doar pe
`127.0.0.1` nu este vizibil direct; folosiți un proxy TCP pe interfața hostului
și o intrare `sqlhosts` cu IP-ul gateway-ului Docker (de exemplu
`192.168.65.254`).

### Compilare împreună cu PHP

Sunt incluse `config.w32` și `config.m4`:

```bat
:: Windows, din arborele php-src
buildconf
configure --enable-ifx-pdo
nmake
```

```bash
# Linux/Unix
./buildconf --force
./configure --enable-ifx-pdo
make
```

Această variantă (`ifx_pdo`) depinde de `pdo_informix`; driverul PDO se încarcă
separat, ca orice driver PDO.

### Varianta directă `ifx` (fără PDO)

Folderul [`../ifx_informix/`](../ifx_informix/) conține extensia separată `ifx`,
care implementează toate cele 39 de funcții direct peste Informix Client SDK
CLI, fără PDO. Binarul este `ifx.dll` (sau `ifx.so`). Este independentă de
`pdo_informix` și de `ifx_pdo`.

```bat
cd ifx_informix
build-ifx.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
```

`ifx.dll` (`ifx`) și `ifx_pdo.dll` (`ifx_pdo`) exportă aceleași funcții PHP;
încărcați doar una dintre ele. Detalii:
[`../ifx_informix/README.md`](../ifx_informix/README.md).
### Regenerarea motorului încorporat

După modificarea fișierelor din `php_informix/src/Informix/`, regenerați `ifx_php.c`:

```bat
php ifx_pdo_informix\generate-embedded.php
```

Generatorul lipește clasele într-un singur fișier, le redenumește cu prefixul
`IfxCompat` (ca să nu intre în conflict cu biblioteca PHP) și scrie un array de
octeți C.

## Instalare

1. Copiați `ifx_pdo.dll` în directorul `ext` al PHP-ului. Numele fișierului poate
   fi și `php_ifx_pdo.dll`; modulul încărcat rămâne `ifx_pdo`.
2. Copiați/instalați `php_pdo_informix.dll` în același director.
3. În `php.ini`:

```ini
extension=pdo_informix
extension=ifx_pdo
```

4. Verificați:

```bat
php -m | findstr /i ifx
php -r "var_dump(function_exists('ifx_connect'), extension_loaded('ifx_pdo'));"
```

Variabilele de mediu rămân cele Informix:

```bat
set INFORMIXDIR=C:\IBM\Informix_Client_SDK
set INFORMIXSERVER=<server>
set INFORMIXSQLHOSTS=C:\path\to\sqlhosts
set PATH=%INFORMIXDIR%\bin;%PATH%
```

## Directive `php.ini`

| Directivă | Implicit | Efect |
|---|---|---|
| `ifx.blobinfile` | `1` | ca extensia veche: `ifx_get_blob()` întoarce cale de fișier |
| `ifx.textasvarchar` | `0` | TEXT/CLOB ca string |
| `ifx.byteasvarchar` | `0` | BYTE/BLOB ca string |
| `ifx.charasvarchar` | `0` | taie padding-ul CHAR |
| `ifx.nullformat` | `0` | `NULL` devine `""` sau `"NULL"` |
| `ifx.default_host` | `""` | server implicit |
| `ifx.default_user` | `""` | utilizator implicit |
| `ifx.default_password` | `""` | parolă implicită |
| `ifx.default_database` | `""` | bază implicită |
| `ifx.allow_persistent` | `1` | permite `ifx_pconnect` |
| `ifx.max_links` | `-1` | limită conexiuni |
| `ifx.max_persistent` | `-1` | limită conexiuni persistente |
| `ifx.htmltbl_escape` | `0` | escape HTML în `ifx_htmltbl_result` |
| `ifx.connect_timeout` | `-1` | `PDO::ATTR_TIMEOUT` când este `> 0` |

Valorile din `php.ini` sunt aplicate la începutul requestului, înainte de
prima funcție `ifx_*`. Funcțiile `ifx_blobinfile_mode()`, `ifx_textasvarchar()`
etc. le pot schimba apoi pentru requestul curent.

## Testare

```powershell
.\tests\run-native.ps1
```

sau manual:

```powershell
php -n `
  -d extension_dir="C:\path\to\pdo_informix\extension" `
  -d extension=php_pdo_informix `
  -d extension="C:\path\to\ifx-compat\ifx_pdo_informix\ifx_pdo.dll" `
  "C:\path\to\ifx-compat\ifx_pdo_informix\tests\native-suite.php"
```

Suita creează tabelele `ifx_n_types`, `ifx_n_lob` și `ifx_n_dml` în baza de test și verifică resursele, metadatele, scroll-ul, DML-ul, LOB-urile,
CHAR/SLOB, HTML și erorile.

Pe Linux, în container:

```bash
php -d extension=pdo_informix -d extension=/path/to/ifx_pdo.so \
    ifx_pdo_informix/tests/native-suite.php
```

sau, pentru build și ambele suite:

```powershell
.\tools\test-linux.ps1 -Build
```

## Limitări

- Motorul este PHP încorporat, nu C pur; performanța este apropiată de
  biblioteca PHP, cu un cost suplimentar la primul apel dintr-un request.
- `IFX_HOLD` este acceptat, dar cursorul nu supraviețuiește unui `COMMIT`.
- `ifx_getsqlca()` este aproximat (`sqlerrd1`/`sqlerrd2`).
- SLOB-urile sunt bufferi în memorie, nu obiecte `ifx_lo_*` pe server.
- Mesajele de eroare vin de la driverul ODBC, în engleză.
- Pentru PHP 7.0–8.5 este nevoie de un `pdo_informix` construit pentru exact
  acel build (NTS/TS); altfel driverul nu se încarcă.
