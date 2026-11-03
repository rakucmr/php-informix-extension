# extension - build php_pdo_informix.dll (PHP 8.5 NTS x64)

## Conținut

Acest folder este publicat ca **surse și scripturi de build**. Binarele
`php_pdo_informix*.dll` și `pdo_informix.lib` rezultă local și nu sunt incluse
în repository.

| Fișier | Rol |
|---|---|
| `build.bat` | scriptul de build (reproductibil) |
| `fix-csdk-odbc-headers.bat` | copiază header-ele ODBC din Windows SDK în CSDK |
| `php.ini.example` | liniile de adăugat în `php.ini` |

## Cerințe

- **Visual Studio 2022** cu workload "Desktop development with C++" (vcvars64)
- **PHP devel pack** potrivit cu PHP țintă:
  `php-devel-pack-8.5.11-nts-Win32-vs17-x64.zip` (conține `include\`, `lib\php8.lib`)
- **Informix Client SDK 4.50.FC12W12** (`incl\cli`, `incl\esql`, `lib\iclit09b.lib`)

## Compilare

```bat
build.bat
:: sau explicit
build.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64" "C:\IBM\Informix_Client_SDK"

:: pentru alt PHP: pachetul de dezvoltare + numele DLL-ului rezultat
build.bat "C:\php-devel\phpother\x\php-8.3.36-devel-vs16-x64" ^
          "C:\IBM\Informix_Client_SDK" php_pdo_informix_83.dll

:: varianta cu Driver Manager-ul ODBC al Windows-ului (odbc32.lib, nu iclit09b.lib)
build.bat "" "" php_pdo_informix_dm.dll dm
```

Al patrulea argument `dm` schimbă biblioteca de legare: în loc de `iclit09b.lib`
(CLI direct) se leagă `odbc32.lib` + `odbccp32.lib`, iar conectarea trece prin
driverul înregistrat în `ODBCINST.INI` (`IBM INFORMIX ODBC DRIVER (64-bit)`),
adăugat automat în șirul de conectare dacă lipsește `DRIVER=`/`DSN=`. Varianta
trece aceleași teste, dar are două particularități: atributele proprii CLI
(`SQL_INFX_ATTR_*` peste 2000) nu pot fi setate — `SQLSetConnectAttr` iese cu
`0xC0000005`, deci sunt compilate doar în varianta directă — și pool-ul Driver
Manager-ului nu refolosește sesiunea Informix (detalii în `ANALIZA.md` §3.22).

Scriptul copiază sursele din `..\src` într-un director de lucru fără spații
(`%TEMP%\pdo_informix_build`), generează un `config.h` minimal
(`HAVE_CONFIG_H`, `COMPILE_DL_PDO_INFORMIX`) și compilează cu:

```
cl /LD /MD /O2 /DHAVE_CONFIG_H /DCOMPILE_DL_PDO_INFORMIX=1 /DZEND_DEBUG=0 ...
   /link /DLL /OUT:php_pdo_informix.dll php8.lib iclit09b.lib
```

### Versiuni de PHP

Aceleași surse compilează pentru PHP 8.1, 8.3 și 8.5 NTS x64; se schimbă doar
pachetul de dezvoltare (și, opțional, numele DLL-ului, ca să nu se
suprascrie). Verificat pe 8.1.12, 8.3.36 și 8.5.11: suita completă trece
(198 de aserțiuni) și build-ul iese fără warning-uri în codul propriu.

### De ce `cl` direct și nu `phpize` + `nmake`

`phpize.bat` din devel pack apelează `configure`, care cere uneltele din
php-sdk-binary-tools aflate în PATH (`bison`, `re2c`, `sed`, `zip`, `mc`, `7za`).
Pentru o extensie cu 3 fișiere C acestea nu sunt folosite la compilare, așa că
build-ul direct cu `cl` produce exact același DLL, fără să instaleze SDK-ul PHP.

### De ce header-e ODBC din Windows SDK

`infxcli.h` face, pe Windows:

```c
#ifdef _WINDOWS_
#include "sql.h"
#include "sqlext.h"
#else
#include "infxsql.h"
#endif
```

adică se bazează pe header-ele ODBC ale **Windows SDK**, nu pe cele din CSDK.
CSDK 4.50.FC12W12 pentru Windows livrează în `incl\cli` doar `infxcli.h` și
`xa.h`, iar `config.w32` din PECL verifică existența `sql.h`. De aceea
`fix-csdk-odbc-headers.bat` copiază `sql.h`, `sqlext.h`, `sqltypes.h`,
`sqlucode.h`, `odbcinst.h` din `Windows Kits\10\Include\<versiune>\um` în
`<CSDK>\incl\cli`. (Pe Linux, echivalentul este `infxsql.h`, livrat de CSDK.)

## Instalare

1. După build, copiază `php_pdo_informix.dll` în `ext\` al PHP-ului țintă
   (aici: `C:\php\ext`).
2. Setează variabilele de mediu pentru procesul PHP:

```bat
set INFORMIXDIR=C:\IBM\Informix_Client_SDK
set INFORMIXSERVER=informix_server
set INFORMIXSQLHOSTS=C:\IBM\Informix\etc\sqlhosts.informix_server
set PATH=%INFORMIXDIR%\bin;%PATH%
```

3. În `php.ini`:

```ini
extension=pdo_informix
```

Verificare:

```bat
php -m | findstr /i informix
php -r "var_dump(PDO::getAvailableDrivers());"
```

## DSN

Driverul alege între `SQLDriverConnect` și `SQLConnect` după cum DSN-ul conține
sau nu `=`:

- **cu `=`** → `SQLDriverConnect` (nu are nevoie de DSN în `odbc.ini`):

```
informix:server=informix_server;database=testdb;host=127.0.0.1;service=9088;protocol=olsoctcp;
```

- **fără `=`** → `SQLConnect`, deci numele trebuie să fie un **DSN** definit în
  `odbc.ini`; `informix:testdb` dă `SQLSTATE=IM002` dacă DSN-ul nu există.

## Rezultat build (verificat)

- `php_pdo_informix.dll` (aproximativ 39 KB), export `get_module`
- încărcat de PHP 8.5.11 NTS: `PDO::getAvailableDrivers()` → `["informix"]`
- `phpversion('pdo_informix')` → `1.3.7`
- suita PDO: **9/9 fișiere, 198 aserțiuni** pe 8.5.11

## Troubleshooting

| Simptom | Cauză / soluție |
|---|---|
| `Invalid library (maybe not a PHP library)` | lipsește `get_module` → compilat fără `HAVE_CONFIG_H` / `COMPILE_DL_PDO_INFORMIX` |
| `fatal error C1001` la `pdo_informix.c` | inițializator vid `{ }` → folosește `{ ZEND_FE_END }` (deja aplicat în `..\src`) |
| `SQLSTATE=IM002 ... Data source name not found` | DSN fără `=` → folosește forma cu `server=...;database=...` sau definește DSN-ul |
| `The specified module could not be found` la load | `iclit09b.dll` nu e în `PATH` (pune `%INFORMIXDIR%\bin`) |





