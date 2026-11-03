# Benchmark: PDO direct vs. ifx vs. ifx_pdo vs. biblioteca PHP

Rezultatele au fost obținute pe **31 octombrie 2026**, pe o singură mașină
Windows x64, cu:

- PHP **8.5.11 NTS** x64;
- IBM Informix IDS **14.10.FC12** și CSDK 4.50, server local (fără hop de
  rețea);
- driverul `pdo_informix`, varianta **`ifx`** (binar
  `ifx.dll`, C direct peste CSDK, fără PDO) și varianta
  **`ifx_pdo`** (binar `ifx_pdo.dll`, C peste PDO), compilate
  pentru același PHP;
- trei runde pentru fiecare variantă; valorile din tabel sunt mediana.

`ifx_pdo` are nevoie de `php_pdo_informix.dll`. Varianta
directă `ifx` **nu** are nevoie de `pdo_informix`.

În comenzile de mai jos, `--mode=direct` înseamnă `ifx`, iar
`--mode=native` înseamnă `ifx_pdo`.

## Rezultate

| Operație | PDO direct | ifx (CSDK direct) | ifx_pdo | Biblioteca PHP (peste PDO) |
|---|---:|---:|---:|---:|
| Conexiune + închidere, 40× | **27,42** | 29,23 (×1,07) | 27,31 (×1,00) | 29,89 (×1,09) |
| `SELECT` punctual, 1 rând, 1.500× | **0,7173** | 0,7256 (×1,01) | 0,7697 (×1,07) | 0,7635 (×1,06) |
| `COUNT(*)`, 400× | 0,6645 | **0,6586** (×0,99) | 0,7187 (×1,08) | 0,7120 (×1,07) |
| Scanare catalog, 110 rânduri, 150× | **1,5123** | 2,5207 (×1,67) | 2,0774 (×1,37) | 2,0179 (×1,33) |
| `INSERT` în tabelă temporară, 250× | **1,1075** | 1,1742 (×1,06) | 1,4796 (×1,34) | 1,4219 (×1,28) |
| `UPDATE` în tabelă temporară, 250× | **0,3222** | 0,5125 (×1,59) | 0,6664 (×2,07) | 0,6583 (×2,04) |

Valorile sunt **ms/op**; între paranteze este raportul față de PDO direct.
`ifx` a avut un vârf de aproximativ **2 MiB**, PDO direct **4 MiB**, iar
`ifx_pdo` și biblioteca aproximativ **6 MiB**. Tabela temporară este creată și
ștearsă în cadrul benchmark-ului; datele aplicației nu sunt modificate.

## Interpretare

- **PDO direct** este cea mai rapidă variantă la scanare, `INSERT` și `UPDATE`.
- **`ifx`** (direct peste CSDK) este practic egal cu PDO la `SELECT` punctual
  și `COUNT(*)`, dar este cu ~67% mai lent la scanare și cu ~59% mai lent la
  `UPDATE`; `INSERT` este aproape egal. Cauza este citirea fiecărei coloane cu
  `SQLGetData` și pregătirea/executarea prin CLI.
- **`ifx_pdo`** și **biblioteca PHP** sunt practic egale.
  Ambele trec prin PDO și prin obiecte PHP de rezultat; varianta pdo based
  apelează, prin `call_user_function`, exact codul PHP încorporat, deci nu are
  un avantaj de viteză față de bibliotecă. Avantajul ei este încărcarea ca
  extensie PHP, fără `require`.
- Față de PDO, `ifx_pdo` și biblioteca au 6–8% la interogările simple, 33–37%
  la scanare, 28–34% la `INSERT` și peste 2× la `UPDATE`.
- Timpul de conexiune are o variație naturală mare (27–30 ms); diferențele
  dintre variante nu sunt semnificative.
- Benchmark-ul principal este single-process, fără concurență. Pentru un
  server web, rezultatele depind de rețea, CSDK și de încărcarea serverului;
  testul concurent de mai jos arată plafonul observat pe serverul local.

## Test concurent

Testul concurent folosește procese PHP separate, fiecare cu o singură conexiune
Informix. Orchestratorul pornește toate procesele, așteaptă un barrier comun,
apoi fiecare worker creează un tabel temporar propriu și rulează timp de 5
secunde un ciclu de `SELECT` punctual, `COUNT(*)`, scanare, `INSERT` și `UPDATE`.
`--concurrency` este și numărul de conexiuni simultane. Toate valorile provin
dintr-o rundă de 5 secunde pentru fiecare nivel de concurență.

| Conexiuni simultane | PDO direct (ops/s) | ifx (ops/s) | ifx_pdo (ops/s) | Biblioteca PHP (ops/s) |
|---:|---:|---:|---:|---:|
| 1 | **1.079** | 976 | 859 | 874 |
| 10 | **2.977** | 2.628 | 2.594 | 2.614 |
| 20 | **2.996** | 2.674 | 2.611 | 2.667 |
| 25 | **3.005** | 2.691 | 2.621 | 2.717 |

| Conexiuni simultane | PDO direct (p95, ms) | ifx (p95, ms) | ifx_pdo (p95, ms) | Biblioteca PHP (p95, ms) |
|---:|---:|---:|---:|---:|
| 1 | **1,40** | 2,00 | 1,83 | 1,79 |
| 10 | **4,87** | 5,79 | 5,94 | 6,02 |
| 20 | **9,08** | 11,97 | 11,97 | 12,03 |
| 25 | **11,72** | 14,77 | 15,33 | 14,42 |

La o conexiune, PDO direct este cu circa 10% peste `ifx` în workload-ul mixt
(1.079 vs. 976 ops/s); `ifx_pdo` și biblioteca sunt apropiate (859–874 ops/s).
La 10–25 de conexiuni throughput-ul crește de 2,4–2,8×, dar toate variantele
se apropie de plafonul serverului: PDO ajunge la circa 3,0 mii de operații/s,
`ifx` la 2,6–2,7 mii, iar `ifx_pdo` și biblioteca la 2,6–2,7 mii. Avantajul
`ifx` la `INSERT` nu se mai vede când serverul devine blocant. Latența p95
crește de la aproximativ 1,4–2,0 ms la 11,7–15,3 ms, aproape 9×, în timp ce
throughput-ul crește doar de circa 2,5×.

### Capacitate: 25, nu 500

Serverul local este IDS 14.10.FC12 **Developer Edition**. Licența acestei ediții
limitează conexiunile simultane la **25**, indiferent de `NETTYPE`. `onconfig`
are `NETTYPE olsoctcp,1,150,NET`, dar limita efectivă este licența. Am încercat
nivele peste 25 și am măsurat câte conexiuni au fost acceptate:

| Conexiuni cerute | Acceptate | Respinse |
|---:|---:|---:|
| 25 | 25 | 0 |
| 50 | 25 | 25 |
| 100 | 25 | 75 |
| 140 | 25 | 115 |

Workerii respinși au primit `SQLDriverConnect` cu eroarea `-25571`; în
log-ul serverului apare explicit:

```text
Warning: The IBM IDS Developer Edition license restriction limits
the number of connections to this server to 25.
```

Prin urmare, **500 de conexiuni simultane nu sunt posibile pe acest server**
fără o licență Workgroup/Enterprise. Chiar și cu licența corespunzătoare,
`NETTYPE` trebuie mărit la 500 și serverul repornit; de verificat și `LOCKS`,
memoria partajată, numărul de CPU VP și limitele sistemului de operare.
Configurația serverului nu a fost modificată în timpul acestor teste.

Serverul are un singur CPU VP și `DS_MAX_QUERIES=5`; plafonul de throughput
aparține serverului, nu clientului PHP. Pentru un test real la 500 de conexiuni
trebuie folosit un server cu licență potrivită și o configurație dimensionată
pentru acel nivel.

### Reproducere test concurent

Scriptul este `php_informix/tools/parallel-benchmark.php`. Fiecare worker este
un proces PHP separat; JSON-ul conține throughput,
p50/p95/p99, erori și conexiuni reușite.

```powershell
$env:IFX_BENCH_DSN = '<database>@<server>'
$env:IFX_BENCH_PDO_DSN = 'informix:server=<server>;database=<database>;'
$env:IFX_BENCH_USER = '<user>'
$env:IFX_BENCH_PASSWORD = '<password>'

# PDO direct, 25 procese / conexiuni simultane
php -n -d extension_dir=C:\php\ext -d extension=pdo_informix `
  php_informix/tools/parallel-benchmark.php --mode=pdo --concurrency=25 --duration=10

# ifx (direct CSDK, fără PDO)
php -n -d extension=ifx `
  php_informix/tools/parallel-benchmark.php --mode=direct --concurrency=25 --duration=10
```

## Note despre varianta `ifx` (direct)

- Implementează toate cele 39 de funcții `ifx_*` / `ifxus_*`, inclusiv LOB-uri,
  CHAR, SLOB, cursoare scroll, metadate, SQLCA și HTML.
- A fost construită și testată pe **PHP 7.0–8.5 x64** (7.x–8.4 ZTS, 8.5 NTS),
  cu **102/102** verificări pe fiecare versiune.
- Nu poate fi încărcată în același proces cu `ifx_pdo`, pentru că ambele exportă
  aceleași nume de funcții.
- Fetch-ul citește fiecare coloană cu `SQLGetData`, fără buffere fixe, deci
  valorile mari nu sunt trunchiate.
- SLOB-urile sunt bufferi client-side, nu obiecte `ifx_lo_*` pe server.

## Reproducere

Scriptul este în `php_informix/tools/benchmark.php`.
Nu conține credențiale; acestea se transmit numai prin variabile de mediu.

```powershell
$env:IFX_BENCH_DSN = '<database>@<server>'
$env:IFX_BENCH_PDO_DSN = 'informix:server=<server>;database=<database>;host=<host>;service=<service>;protocol=olsoctcp;'
$env:IFX_BENCH_USER = '<user>'
$env:IFX_BENCH_PASSWORD = '<password>'

# PDO direct
php -n -d extension_dir=C:\php\ext -d extension=pdo_informix `
  php_informix/tools/benchmark.php --mode=pdo --rounds=5

# ifx (direct CSDK, fără PDO)
php -n -d extension=ifx `
  php_informix/tools/benchmark.php --mode=direct --rounds=5

# ifx_pdo (pdo based, necesită php_pdo_informix.dll)
php -n -d extension_dir=C:\php\ext -d extension=pdo_informix -d extension=ifx_pdo `
  php_informix/tools/benchmark.php --mode=native --rounds=5

# biblioteca PHP (peste PDO)
php -n -d extension_dir=C:\php\ext -d extension=pdo_informix `
  php_informix/tools/benchmark.php --mode=library --rounds=5
```

Extensia directă `ifx` se construiește separat:

```bat
cd ifx_informix
build-ifx.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
```

Fiecare proces afișează JSON pe stdout; scriptul nu scrie în baza de date decât
tabela temporară proprie, care este ștearsă la final. Suita directă este
`ifx_informix/tests/native-suite.php`.
