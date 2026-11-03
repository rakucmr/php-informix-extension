# Build Windows pentru PHP 7.0–8.5

Aceeași sursă `ifx_pdo_informix/ifx.c` + `ifx_pdo_informix/ifx_php.c` se compilează pentru fiecare
versiune PHP a variantei `ifx_pdo`. Diferențele sunt în pachetul de dezvoltare și în biblioteca PHP
(`php7ts.lib`, `php7.lib`, `php8ts.lib`, `php8.lib`).

## 1. Pregătiți pachetele

Pentru fiecare versiune aveți nevoie de:

- arhiva PHP de dezvoltare (`php-devel-pack-<versiune>-...zip`), pentru
  `phpize.bat`, header-e și `.lib`;
- arhiva runtime (`php-<versiune>-Win32-...zip`), pentru teste.

Pachetele vechi sunt în arhiva windows.php.net:

```text
https://windows.php.net/downloads/releases/archives/
```

## 2. Patch pentru `pdo_informix` pe PHP < 8.1

Sursa `pdo_informix` are un bloc JSON/BSON care folosește `result`/`type`,
parametri disponibili doar în API-ul PDO din 8.1. În `informix_statement.c`,
încadrați blocul:

```c
#if PHP_8_1_OR_HIGHER
	if (col_res->json_col || col_res->bson_col) {
		...
		return TRUE;
	}
#endif
```

Patch-ul complet este în [`pdo-informix-php7.patch`](pdo-informix-php7.patch).
Pe 7.0–8.0 coloanele JSON/BSON nu au conversia specială; restul funcționează.

## 3. Compilați `pdo_informix`

```bat
pdo_informix\tools\build-pdo-windows.bat ^
  C:\php-devel\devel\php-7.4.33-devel-vc15-x64 ^
  php_pdo_informix_74_ts.dll ^
  "pdo_informix\src" ^
  "C:\IBM\Informix_Client_SDK"
```

Scriptul alege automat `php7ts.lib`/`php8ts.lib`/`php8.lib` și setează
`/DZTS=1` când este nevoie.

Varianta directă `ifx` se construiește separat, cu
`ifx_informix\build-ifx.bat`; rezultatul standard este `ifx.dll` și nu are
nevoie de `pdo_informix`. Pentru PHP 7.x/TS nu există încă un binar validat al
variantei directe.

```bat
ifx_informix\build-ifx.bat ^
  C:\php-devel\devel\php-8.5.11-devel-vs17-x64 ^
  ifx.dll ^
  C:\IBM\Informix_Client_SDK
```

Verificare, fără `pdo_informix` încărcat:

```powershell
php -n -d extension_dir=ifx_informix -d extension=ifx ^
  ifx_informix\tests\native-suite.php
```

Rezultat așteptat: `Teste: 102 trecute, 0 eșuate`.

## 4. Compilați `ifx_pdo`

```bat
ifx_pdo_informix\build-ifx.bat ^
  C:\php-devel\devel\php-7.4.33-devel-vc15-x64 ^
  ifx_pdo_74.dll
```

Pentru 7.0–7.3, PHP a fost construit cu VS2015/VS2017, iar compilatorul
disponibil este VS2019/VS2022. PHP refuză modulul cu:

```text
Can't load module ... as it's linked with 14.29, but the core is linked with 14.16
```

Aplicați patch-ul de linker pe ambele DLL-uri:

```powershell
ifx_pdo_informix\tools\patch-pe-linker.ps1 -Path ifx_pdo_informix\ifx_pdo_73.dll -Major 14 -Minor 16
ifx_pdo_informix\tools\patch-pe-linker.ps1 -Path .\php_pdo_informix_73_ts.dll -Major 14 -Minor 16
```

Pentru 7.0/7.1 folosiți `-Major 14 -Minor 0`; pentru 7.2/7.3 și 8.x folosiți
`14 16` (sau versiunea core-ului respectiv). Patch-ul schimbă doar câmpul
`MajorLinkerVersion`/`MinorLinkerVersion` din header-ul PE, nu și CRT-ul.

## 5. Verificați

```powershell
$env:INFORMIXDIR = 'C:\IBM\Informix_Client_SDK'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = 'C:\path\to\sqlhosts'
$env:PATH = "$env:INFORMIXDIR\bin;$env:PATH"

C:\php73\php.exe -n `
  -d extension=php_pdo_informix_73_ts.dll `
  -d extension=ifx_pdo_73.dll `
  ifx_pdo_informix\tests\native-suite.php
```

Rezultat așteptat: `Teste: 92 trecute, 0 eșuate`.
