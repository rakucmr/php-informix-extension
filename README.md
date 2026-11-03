# ifx-compat

Proiect independent, neafiliat IBM. Nu este un driver oficial și nu redistribuie
Informix Client SDK sau serverul IDS. Pentru producție aveți nevoie de o licență
IBM corespunzătoare pentru CSDK/IDS.

English overview: [README.en.md](README.en.md) · [docs/en/index.html](docs/en/index.html).

Proiectul are patru foldere de extensie, fiecare cu un rol clar:

| Folder | Modul PHP | Ce conține | Tehnologie |
|---|---|---|---|
| [`ifx_informix/`](ifx_informix/) | `ifx` | API-ul procedural complet `ifx_*` / `ifxus_*` direct peste CSDK; **nu necesită `pdo_informix`** | C + Informix CSDK |
| [`ifx_pdo_informix/`](ifx_pdo_informix/) | `ifx_pdo` | același API procedural, implementat peste PDO; **necesită `pdo_informix`** | C + PHP încorporat + PDO (CSDK prin driver) |
| [`pdo_informix/`](pdo_informix/) | `pdo_informix` | driverul PDO pentru Informix, cu patch-urile PHP 7.0–8.5 | C + CSDK |
| [`php_informix/`](php_informix/) | — | biblioteca PHP pură cu aceeași semantică | PHP + `pdo_informix` |
| [`docs/`](docs/index.html) | — | site-ul și documentația completă, RO + EN | HTML/CSS/JS |

`ifx`, `ifx_pdo` și biblioteca PHP expun aceleași **39** de funcții PHP.
Diferența este backend-ul: `ifx` vorbește direct cu Informix Client SDK CLI,
iar `ifx_pdo` rulează aceeași semantică prin PDO. Biblioteca PHP folosește PDO
la fel ca `ifx_pdo`, dar fără extensie C.

**Care are nevoie de `pdo_informix`:** `ifx_pdo` și biblioteca PHP. Extensia
directă `ifx` **nu** are nevoie de el. Toate cele patru variante ajung la server
prin Informix Client SDK: `ifx` direct, iar celelalte prin `pdo_informix`.

Încărcare:

- `ifx` → `extension=ifx` (binar `ifx.dll`), fără `pdo_informix`;
- `ifx_pdo` → `extension=pdo_informix` + `extension=ifx_pdo` (binar
  `ifx_pdo.dll`).

Nu încărcați `ifx` și `ifx_pdo` în același proces: exportă aceleași nume de
funcții PHP, iar PHP ar raporta duplicate.

În comenzile de benchmark, `--mode=direct` este `ifx`, iar
`--mode=native` este `ifx_pdo`.

## Compatibilitate

- **PHP:** 7.0, 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5;
- **Windows:** NTS/TS, în funcție de build;
- **Linux:** NTS, compilat cu `phpize`;
- **Informix:** IDS 14.10 și CSDK 4.50 (sau versiuni compatibile);
- **Driver:** `pdo_informix` trebuie construit pentru versiunea PHP folosită.

Extensia directă `ifx` a fost construită și testată pe PHP 7.0–8.5 x64
(7.x–8.4 ZTS, 8.5 NTS), cu **102/102** verificări pe fiecare versiune.
`ifx_pdo`, driverul și biblioteca sunt acoperite de matricea de mai sus.
Matricea detaliată este în
[`docs/reference/COMPATIBILITY.md`](docs/reference/COMPATIBILITY.md) și pe site.

## Pornire rapidă

Exemplele citesc din mediu; nu hard-codați credențiale:

```bash
export IFX_DSN='<database>@<server>'
export IFX_USER='<user>'
export IFX_PASSWORD='<password>'
```

```powershell
$env:IFX_DSN = '<database>@<server>'
$env:IFX_USER = '<user>'
$env:IFX_PASSWORD = '<password>'
```

### 1. Bibliotecă PHP

```php
<?php

require __DIR__ . '/php_informix/src/autoload.php';

$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query('select tabid, tabname from systables', $conn, IFX_SCROLL);

while (($row = ifx_fetch_row($res)) !== false) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}
```

### 2. `ifx` — extensia directă peste CSDK

Binarul este `ifx.dll` și se încarcă prin `extension=ifx`. Nu are nevoie de
`pdo_informix`; folosește direct `SQLDriverConnect`, `SQLPrepare`,
`SQLGetData`, `SQLFetchScroll` și restul API-ului CLI.

```ini
; php.ini — ifx direct, fără pdo_informix
extension=ifx
```

```php
$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query('select id, v from ifx_n_types', $conn, IFX_SCROLL);
```

### 3. `ifx_pdo` — extensia C peste PDO

Necesită driverul `pdo_informix`; cele două DLL-uri se încarcă împreună.

```ini
; php.ini
extension=pdo_informix
extension=ifx_pdo
```

```php
$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);
```

### 4. Driverul PDO direct

```php
$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'),
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS')
);
```

DSN-ul și credențialele se păstrează în variabile de mediu; nu există valori
implicite în repository.

## Compilare

### pdo_informix

```bat
cd pdo_informix\extension
build.bat
```

Rezultă `php_pdo_informix.dll` (Windows). Pe Linux, driverul se compilează cu
`phpize` și `configure --with-pdo-informix`; în folderul `pdo_informix/docker/`
există un Dockerfile pentru testare.

### ifx (direct)

```bat
cd ifx_informix
build-ifx.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
```

Rezultă `ifx_informix\ifx.dll`. Necesită Informix Client SDK la compilare și la
runtime; nu are nevoie de `pdo_informix`. Pentru Linux:

```bash
cd ifx_informix
IFX_CSDK=/opt/ibm/informix ./build-ifx-linux.sh
```

Binarele vechi `ifx_direct*.dll` nu fac parte din arhiva publică; rămân doar
local, în `ifx_informix/legacy/`, pentru comparații istorice.

### ifx_pdo

```bat
cd ifx_pdo_informix
build-ifx.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
```

Rezultă `ifx_pdo_informix\ifx_pdo.dll` și are nevoie de
`php_pdo_informix.dll` la runtime. Pentru Linux:

```bash
cd ifx_pdo_informix
sh build-ifx-linux.sh
```

### php_informix

Nu necesită compilare. Se încarcă prin `require` sau Composer:

```bash
cd php_informix
composer install
```

## Testare

Testele de integrare au nevoie de Informix Client SDK, un server IDS și de
variabile de mediu. Nu există credențiale implicite în repository și testele nu
folosesc mock-uri.

```powershell
$env:INFORMIXDIR = '<CSDK>'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = '<sqlhosts>'
$env:IFX_TEST_DSN = '<database>@<server>'
$env:IFX_TEST_USER = '<user>'
$env:IFX_TEST_PASSWORD = '<password>'
```

Pentru driverul PDO, testele folosesc variabilele proprii:

```powershell
$env:PDO_INFORMIX_DSN = 'informix:server=<server>;database=<database>;host=<host>;service=<port>;protocol=olsoctcp;'
$env:PDO_INFORMIX_USER = '<user>'
$env:PDO_INFORMIX_PASS = '<password>'
```

| Suită | Comandă (Windows, cu extensia încărcată explicit) | Rezultat |
|---|---|---|
| Biblioteca PHP | `php -n -d extension_dir="C:\php\ext" -d extension=pdo_informix php_informix/tests/run-tests.php` | 93 trecute, 1 sărit |
| `ifx_pdo` | `php -n -d extension_dir="C:\php\ext" -d extension=pdo_informix -d extension=ifx_pdo ifx_pdo_informix/tests/native-suite.php` | 92 trecute |
| Driverul PDO | `php -n -d extension_dir="C:\php\ext" -d extension=pdo_informix pdo_informix/test/run-tests.php` | 9/9 trecute, 198 aserțiuni |
| `ifx` (direct) | `php -n -d extension_dir="C:\php\ext" -d extension=ifx ifx_informix/tests/native-suite.php` | 102 trecute |
| `ifx` (smoke) | `php -n -d extension_dir="C:\php\ext" -d extension=ifx ifx_informix/tests/smoke-test.php` | verificare rapidă |

Pentru `ifx_pdo`, `php_pdo_informix.dll` și `ifx_pdo.dll` trebuie să fie în
același `extension_dir` (sau indicați căi explicite); driverul se încarcă primul.

Runner-ele Windows sunt
[`ifx_informix/tests/run-native.ps1`](ifx_informix/tests/run-native.ps1) și
[`ifx_pdo_informix/tests/run-native.ps1`](ifx_pdo_informix/tests/run-native.ps1);
acestea setează și `IFX_DEFAULT_*` pentru testele fără argumente.
Testul UTF-8 este sărit pe o bază cu `DB_LOCALE=en_US.819`; nu este o eroare.
Detalii despre curățarea tabelelor și rezultatele măsurate:
[`TESTING.md`](TESTING.md).

### Verificare fără bază de date

Se pot verifica sintaxa, generarea documentației și sursa încorporată fără un
server Informix:

```bash
# PHP: toate fișierele trebuie să treacă lint
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# Documentația: generator deterministic + linkuri/ancore valide
python docs/build.py
python tools/check-docs.py --pages

# Sursa încorporată în ifx_pdo trebuie să fie la zi
php ifx_pdo_informix/generate-embedded.php
git diff --exit-code -- ifx_pdo_informix/ifx_php.c
```

### Benchmark

[`php_informix/tools/benchmark.php`](php_informix/tools/benchmark.php) compară
cele patru variante pe aceleași interogări și aceeași bază: PDO direct,
`ifx_pdo` peste PDO, biblioteca PHP peste PDO și `ifx` direct peste CSDK.
Rezultatele măsurate pe PHP 8.5.11 NTS și Informix 14.10 sunt în
[`docs/reference/BENCHMARK.md`](docs/reference/BENCHMARK.md) și în
[documentația pentru ifx direct](docs/ifx.html#performance).

Testul concurent este în
[`php_informix/tools/parallel-benchmark.php`](php_informix/tools/parallel-benchmark.php):
pornește procese PHP separate, fiecare cu o conexiune, și raportează
throughput + p50/p95/p99. Pe serverul local de test (IDS Developer Edition)
licența limitează conexiunile simultane la **25**, deci nu se pot rula 500;
pentru niveluri mai mari este nevoie de licență Workgroup/Enterprise și de
mărirea `NETTYPE`.

```powershell
# ifx (direct, fără pdo_informix)
php -n -d extension=ifx `
  php_informix/tools/parallel-benchmark.php --mode=direct --concurrency=25 --duration=10
```

## Documentație

Site-ul este în [`docs/`](docs/index.html):

- [română](docs/index.html)
- [English](docs/en/index.html)
- [documentația pe module RO](docs/documentation.html)
- [documentation by module EN](docs/en/documentation.html)
- [pdo_informix](docs/pdo_informix.html) · [ifx_pdo](docs/ifx_pdo.html) · [ifx direct](docs/ifx.html) · [php_informix](docs/php_informix.html)

Documentația Markdown de referință a rămas în
[`docs/reference/`](docs/reference/).

Documente de proiect: [`TESTING.md`](TESTING.md) ·
[`CONTRIBUTING.md`](CONTRIBUTING.md) · [`SECURITY.md`](SECURITY.md) ·
[`CHANGELOG.md`](CHANGELOG.md) · [`LICENSING.md`](LICENSING.md).

## Publicare pe GitHub

Repository-ul este pregătit pentru publicare ca sursă, fără binarare. Verificați
înainte de primul push:

```bash
git status --short
git check-ignore -v ifx_informix/ifx.dll
python docs/build.py
python tools/check-docs.py --pages
```

Inițializare și push (înlocuiți `<owner>` și `<repo>`):

```bash
git init
git add .
git commit -m "Initial public release"
git branch -M main
git remote add origin https://github.com/<owner>/<repo>.git
git push -u origin main
```

Apoi, în **Settings → Pages**, alegeți **GitHub Actions** ca sursă. Workflow-ul
[`Deploy documentation`](.github/workflows/pages.yml) publică `docs/` după push
pe `main`. Workflow-ul [`CI`](.github/workflows/ci.yml) rulează lint PHP,
regenerarea documentației, verificarea linkurilor și `composer validate`.

Nu urcați DLL-uri, `.so`, arhive CSDK, `linux-build/`, `vendor/` sau fișiere de
credentiale. `.gitignore` le exclude; un upload prin interfața web poate ocoli
`.gitignore`, deci verificați lista de fișiere înainte de publicare.

## Credite

Proiectul pornește de la semantica extensiei `ext/informix` din PHP 5.1 și de
la driverul `pdo_informix`. Implementarea PHP și extensiile C au fost realizate
cu **DeepSeek (AI)**, sub îndrumarea și validarea autorului proiectului.
Detalii despre autori și licențe: [`CREDITS.md`](CREDITS.md).

Contribuțiile sunt binevenite: raportați erori, propuneți modificări sau
testați pe alte platforme. Nu publicați credențiale, DSN-uri reale sau date
de acces în issue-uri.

## Licență

Licența aleasă pentru codul original este **MIT**, deliberat, pentru adopție
ușoară și compatibilitate cu proiecte legacy. Proiectul are însă licențe
separate pe componente:

- codul original ifx-compat (`ifx_informix`, adaptoarele `ifx_pdo_informix`,
  `php_informix`, documentația și scripturile) — **MIT**;
- `pdo_informix` — **Apache License 2.0**, cod IBM/PDO_INFORMIX; nu poate fi
  relicențiat de acest proiect;
- `ext/informix` original — PHP License 3.01, dar sursa lui nu este
  redistribuită aici;
- Informix CSDK/IDS — licență comercială IBM, nu este inclusă.

Textul MIT este în [`LICENSE`](LICENSE), textul Apache în
[`LICENSES/Apache-2.0.txt`](LICENSES/Apache-2.0.txt), iar atribuirile în
[`NOTICE`](NOTICE) și [`CREDITS.md`](CREDITS.md). Matricea completă și
explicația restricțiilor comerciale sunt în [`LICENSING.md`](LICENSING.md).

> **Important:** MIT și Apache-2.0 permit utilizarea comercială și revânzarea.
> Dacă doriți să interziceți vânzarea codului original, licența trebuie
> schimbată (de exemplu AGPL-3.0 sau PolyForm Noncommercial + licență
> comercială). `pdo_informix` rămâne Apache-2.0 indiferent de licența aleasă
> pentru codul original.

## Securitate și contribuții

- raportați vulnerabilitățile conform [`SECURITY.md`](SECURITY.md);
- consultați [`CONTRIBUTING.md`](CONTRIBUTING.md) înainte de un pull request;
- nu publicați credențiale, DSN-uri reale sau date de acces în issue-uri.
