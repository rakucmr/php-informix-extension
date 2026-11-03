# Compatibilitate PHP

Testele de mai jos au fost rulate real împotriva unui server Informix de test
(IDS 14.10.FC12), cu o bază dedicată, cu `pdo_informix` construit pentru
fiecare build PHP. „`pdo_informix`” este driverul folosit de `ifx_pdo` și de
bibliotecă; „`ifx_pdo`” înseamnă suita
`ifx_pdo_informix/tests/native-suite.php` prin `ifx_pdo.dll`; „bibliotecă” înseamnă
suita `php_informix/tests/run-tests.php` prin biblioteca pur PHP. Varianta
directă `ifx` (`ifx.dll`) a fost testată pe PHP 7.0–8.5 pe Windows x64, cu
**102/102** verificări pe fiecare versiune (7.x–8.4 ZTS, 8.5 NTS).

| PHP | Windows | Linux | pdo_informix | ifx_pdo | ifx direct | Bibliotecă |
|---|---|---|---|---|---|---|
| 7.0.33 | ZTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 7.1.33 | ZTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 7.2.34 | ZTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 7.3.33 | ZTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 7.4.32/7.4.33 | ZTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 8.0.30 | ZTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 8.1.12 | ZTS | — | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 8.2.35 | — | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 8.3.36 | ZTS | — | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 8.4.27 | — | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |
| 8.5.11/8.5.12 | NTS | NTS | ✓ | **92/92** | **102/102** | **93 + 1 sărit** |

`✓` la `pdo_informix` înseamnă că driverul a fost compilat și folosit de
suitele `ifx_pdo`/bibliotecă pe versiunea respectivă; suita proprie are
**9/9 teste și 198 de aserțiuni** pe 8.5.11. `ifx` direct are **102/102** pe
toate versiunile din tabel, rulate pe Windows x64.

Testul sărit este cel UTF-8: baza de test are `DB_LOCALE=en_US.819`, deci
textul UTF-8 nu poate fi stocat corect. Coloanele Windows/Linux se referă la
build-urile `ifx_pdo`/bibliotecă; `ifx` direct a fost rulat pe Windows x64
pentru toate versiunile. Windows și Linux folosesc același cod PHP/C; diferă
doar binarul compilat.

## Ce s-a schimbat pentru PHP 7.x

- `php_informix/src/Informix/*.php` nu mai folosește `match`, union types, constructor
  promotion, typed properties, arrow functions, `?->`, `??=` sau `mixed`.
- Tipurile de retur au fost eliminate; parametrii cu valoare implicită `null`
  nu mai au type hint, pentru ca PHP 7.0 să accepte `null`.
- `php_informix/src/polyfill.php` definește `str_contains`, `str_starts_with`,
  `str_ends_with` și `array_is_list` pentru versiunile care nu le au.
- `get_debug_type()` și `Stringable` au fost înlocuite cu variante portabile.
- `ifx_pdo` are ramuri `PHP_VERSION_ID` pentru `zend_result`,
  `zend_read_property` și `zend_stream_open`/`zend_stream_init_filename`.

## Driverul `pdo_informix` pentru PHP < 8.1

Driverul folosit de proiect are un bloc JSON/BSON scris pentru API-ul PDO din
PHP 8.1. Blocul este în `informix_stmt_get_col()` și a fost încadrat în
`#if PHP_8_1_OR_HIGHER`, astfel încât să compileze pe 7.0–8.0. Pe aceste
versiuni coloanele JSON/BSON nu au conversia specială (rămân LOB/string),
restul API-ului funcționează normal. Patch-ul este în
`docs/reference/pdo-informix-php7.patch`.

## Windows: CRT și DLL-urile precompilate

PHP 7.0–7.3 au fost compilate cu VS2015/VS2017 (linker 14.0/14.16), iar
compilatorul disponibil aici este VS2019/VS2022 (14.29/14.41). PHP refuză un
modul legat cu o versiune mai nouă de linker:

```text
PHP Warning: Can't load module ... as it's linked with 14.29,
but the core is linked with 14.16
```

DLL-urile livrate pentru 7.0–7.3 au câmpul `MajorLinkerVersion`/
`MinorLinkerVersion` din header-ul PE ajustat la versiunea core-ului
(14.0 pentru 7.0/7.1, 14.16 pentru 7.2/7.3). Runtime-ul CRT folosit este
compatibil. Dacă recompilați din sursă, fie folosiți toolset-ul VS2017
(`v141`), fie aplicați același patch asupra DLL-ului; scriptul este descris în
`docs/reference/BUILD-WINDOWS.md`.

## Fallback PDO ODBC (experimental)

Dacă `pdo_informix` lipsește, biblioteca poate folosi `pdo_odbc` **doar dacă**
setați `IFX_USE_ODBC=1` (sau `use_odbc` în configurare). Driverul ODBC al
Informix nu expune metadatele coloanelor prin `PDOStatement::getColumnMeta()`;
numele coloanelor devin `[Expr_N]`, iar `ifx_fieldtypes`/
`ifx_fieldproperties` nu pot fi completate. Fallback-ul este util pentru
interogări simple, nu pentru compatibilitate completă. Pentru suita de teste
folosiți `pdo_informix`.

Variabile pentru fallback:

```text
IFX_USE_ODBC=1
IFX_ODBC_DRIVER=IBM INFORMIX ODBC DRIVER (64-bit)
IFX_HOST=127.0.0.1
IFX_SERVICE=9088
DB_LOCALE=en_US.819
CLIENT_LOCALE=en_US.819
```

## Cum se testează o versiune anume

Windows, `ifx_pdo` (necesită `pdo_informix`):

```powershell
.\ifx_pdo_informix\tests\run-native.ps1 `
  -Php 'C:\php73\php.exe' `
  -PdoDll 'php_pdo_informix' `
  -Dll 'C:\path\to\ifx_pdo.dll'
```

Windows, biblioteca PHP:

```powershell
.\php_informix\tools\test.ps1 `
  -Php 'C:\php73\php.exe' `
  -ExtensionDir 'C:\path\to\ext' `
  -PdoDll 'php_pdo_informix'
```

Linux, în container PHP 7.0–8.5:

```bash
phpize
./configure --enable-ifx-pdo
make
php -d extension=/path/to/pdo_informix.so \
    -d extension=/path/to/ifx_pdo.so \
    ifx_pdo_informix/tests/native-suite.php
```

Pentru un alt build PHP, atât `ifx_pdo` cât și `pdo_informix` trebuie recompilate
cu header-ele acelei versiuni; un `.so`/`.dll` construit pentru 8.5 nu se
încarcă într-un alt ABI.

## Rezumat

- **Suportat și testat:** PHP 7.0, 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3,
  8.4, 8.5.
- **NTS/TS:** potrivirea este obligatorie; DLL-ul și `pdo_informix` trebuie
  să fie ambele NTS sau ambele TS.
- **PHP 7.0–7.3 sunt EOL:** compatibilitatea este oferită pentru aplicații
  legacy, fără garanții de securitate din partea PHP.
