# Windows test — PHP 8.5.11 NTS

Test rulat pe Windows x64 cu:

- PHP 8.5.11 NTS x64;
- `php_pdo_informix.dll` construit pentru PHP 8.5;
- `ifx.dll` construit pentru PHP 8.5;
- Informix Client SDK 4.50.FC12W12;
- Informix IDS 14.10, baza `<database>`;
- PDO și `ifx` încărcate în același proces (fără `ifx_pdo`).

## Comenzi

```powershell
$php  = 'C:\path\to\php.exe'
$pdo  = 'C:\path\to\php\ext'
$ifxDll = 'C:\path\to\php\ext\ifx.dll'

$env:INFORMIXDIR = 'C:\path\to\csdk'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = 'C:\path\to\sqlhosts'
$env:PATH = "$env:INFORMIXDIR\bin;$env:PATH"

$env:IFX_TEST_HOST = '<informix-host>'
$env:IFX_TEST_PORT = '9088'
$env:IFX_TEST_SERVER = '<server>'
$env:IFX_TEST_DATABASE = '<database>'
$env:IFX_TEST_USER = '<db-user>'
$env:IFX_TEST_PASSWORD = '<password>'

# DSN explicit pentru ifx direct. În acest mediu, rezolvarea doar prin
# sqlhosts a returnat -908; DSN-ul explicit a funcționat.
$env:IFX_TEST_IFX_DSN = 'informix:server=<server>;database=<database>;host=<informix-host>;service=9088;protocol=olsoctcp;'

php -n `
  -d "extension_dir=$pdo" `
  -d extension=php_pdo_informix.dll `
  -d "extension=$ifxDll" `
  tests\ifx-all-types-run.php
```

## Rezultat

**63 verificări, 0 eșecuri.**

```text
OK   table created (first run) / existing table reused
OK   PDO wrote row 1 (scalars, temporal, LOB, collections)
OK   PDO serial / CHAR / VARCHAR / ... / CLOB
OK   ifx wrote row 2 (scalars, temporal, LOB, collections)
OK   ifx serial / CHAR / VARCHAR / ... / BYTE
OK   ifx SET / MULTISET / LIST / ROW
OK   ifx CLOB write verified via PDO
OK   ifx BLOB write verified via PDO
RESULT: PASS
```

## Output complet

Rezultatul integral este salvat în
[`WINDOWS-TEST-OUTPUT.txt`](WINDOWS-TEST-OUTPUT.txt).

## Observații

- `pdo_informix` și `ifx` direct pot fi încărcate împreună; nu se încarcă
  `ifx_pdo`.
- `ifx` direct poate scrie `CLOB`, dar driverul nu poate face `SELECT` pe el.
  Valoarea este verificată prin PDO.
- `BLOB` scris de `ifx` este verificat prin PDO.
- Testul creează tabela `php_ifx_all_types` doar dacă nu există; nu o șterge
  la final. Înlocuiește doar rândurile de test `id=1` și `id=2`, ca tabela să
  poată fi inspectată după rulare.

