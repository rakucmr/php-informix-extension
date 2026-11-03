# Testare și verificare

Acest document descrie cum se verifică proiectul fără presupuneri ascunse:
ce este necesar, ce comandă se rulează, ce rezultat este așteptat și ce
efecte secundare apar în baza de date.

Testele de integrare sunt **reale**: creează tabele temporare, rulează DML,
LOB-uri, cursoare și tranzacții împotriva unui server Informix. Nu există
mock-uri și nu există credențiale implicite în repository.

## 1. Ce se poate verifica fără Informix

Aceste verificări nu necesită CSDK sau server și pot rula în CI:

```bash
# Sintaxa PHP (toate fișierele)
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# Generatorul de documentație și linkurile/ancorele
python docs/build.py
python tools/check-docs.py
```

`docs/build.py` este determinist: dacă paginile generate sunt deja în
repository, `git diff --exit-code -- docs` trebuie să treacă după regenerare.

Sursa PHP încorporată în `ifx_pdo` se verifică astfel:

```bash
php ifx_pdo_informix/generate-embedded.php
git diff --exit-code -- ifx_pdo_informix/ifx_php.c
```

Dacă ultima comandă raportează diferențe, `ifx_php.c` este învechit și trebuie
reconstruit `ifx_pdo.dll`/`ifx_pdo.so` după regenerare.

## 2. Cerințe pentru testele de integrare

| Componentă | Cerință |
|---|---|
| PHP | 7.0–8.5, x64; NTS/ZTS conform binarului |
| Extensii | `pdo`, `pdo_informix`; `ifx` sau `ifx_pdo` după suită |
| Informix | IDS 14.10 (sau compatibil) + Informix Client SDK 4.50 (sau compatibil) |
| Compilator | Visual Studio 2019/2022 pe Windows; `phpize`/GCC pe Linux |
| Bază de date | o bază de test dedicată, cu drepturi de CREATE/DROP/INSERT/UPDATE |
| Rețea | alias `sqlhosts` valid; nu se folosesc credentiale din repository |

Nu rulați testele într-o bază de producție: unele suite creează și șterg
obiecte, iar testele PDO pot păstra intenționat tabele pentru diagnostice.

## 3. Variabile de mediu

### Suitele `ifx` și biblioteca PHP

```powershell
$env:INFORMIXDIR = '<CSDK>'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = '<sqlhosts>'
$env:IFX_TEST_DSN = '<database>@<server>'
$env:IFX_TEST_USER = '<user>'
$env:IFX_TEST_PASSWORD = '<password>'
$env:PATH = "$env:INFORMIXDIR\bin;$env:PATH"
```

`IFX_TEST_USER` și `IFX_TEST_PASSWORD` pot fi goale numai dacă serverul este
configurat pentru autentificare OS locală. Nu presupuneți acest lucru pe alt
server.

### Driverul PDO și `ifx_pdo`

```powershell
$env:PDO_INFORMIX_DSN = 'informix:server=<server>;database=<database>;host=<host>;service=<port>;protocol=olsoctcp;'
$env:PDO_INFORMIX_USER = '<user>'
$env:PDO_INFORMIX_PASS = '<password>'
```

Configurația testelor PDO cere user și parolă **neempty**; pentru un server cu
autentificare locală folosiți un cont de test existent, nu contul de producție.

## 4. Comenzi Windows

Înlocuiți `C:\php\ext` cu directorul în care se află DLL-urile build-uite.
Pentru `ifx_pdo`, atât `php_pdo_informix.dll` cât și `ifx_pdo.dll` trebuie să
fie în același `extension_dir` sau în directoare indicate explicit.

### 4.1 `ifx` direct (102 verificări)

```powershell
php -n -d extension_dir="C:\php\ext" -d extension=ifx `
  ifx_informix/tests/native-suite.php
```

sau runner-ul complet, care setează și `IFX_DEFAULT_*`:

```powershell
.\ifx_informix\tests\run-native.ps1 `
  -Php "C:\php\php.exe" `
  -Dll "C:\php\ext\ifx.dll"
```

Rezultat așteptat: `Teste: 102 trecute, 0 eșuate` și `OK`.

### 4.2 `ifx_pdo` (92 verificări)

```powershell
php -n -d extension_dir="C:\php\ext" `
  -d extension=pdo_informix `
  -d extension=ifx_pdo `
  ifx_pdo_informix/tests/native-suite.php
```

Ordinea este importantă: driverul PDO se încarcă înaintea `ifx_pdo`.
Rezultat așteptat: `Teste: 92 trecute, 0 eșuate` și `OK`.

### 4.3 Biblioteca PHP (93 trecute + 1 sărit)

```powershell
php -n -d extension_dir="C:\php\ext" -d extension=pdo_informix `
  php_informix/tests/run-tests.php
```

Rezultat așteptat: `Teste: 93 trecute, 0 eșuate, 1 sărite` și `OK`.
Testul sărit este cel UTF-8 atunci când baza are `DB_LOCALE=en_US.819`; nu este
un eșec.

### 4.4 Driverul PDO (9 fișiere, 198 aserțiuni)

```powershell
php -n -d extension_dir="C:\php\ext" -d extension=pdo_informix `
  pdo_informix/test/run-tests.php
```

Rezultat așteptat: `ALL PASSED (9 test file(s), ...)`.

### 4.5 Smoke test `ifx`

```powershell
php -n -d extension_dir="C:\php\ext" -d extension=ifx `
  ifx_informix/tests/smoke-test.php
```

## 5. Comenzi Linux

Presupunem că `phpize`, `php-config`, GCC și CSDK sunt instalate, iar
`INFORMIXDIR` este setat.

```bash
export LD_LIBRARY_PATH="$INFORMIXDIR/lib:$INFORMIXDIR/lib/cli:$LD_LIBRARY_PATH"

# ifx direct
php -n -d extension=ifx ifx_informix/tests/native-suite.php

# ifx_pdo (driverul primul)
php -n -d extension=pdo_informix -d extension=ifx_pdo \
  ifx_pdo_informix/tests/native-suite.php

# biblioteca
php -n -d extension=pdo_informix php_informix/tests/run-tests.php

# driverul PDO
php -n -d extension=pdo_informix pdo_informix/test/run-tests.php
```

Dacă extensiile nu sunt în directorul implicit, adăugați
`-d extension_dir=/cale/catre/extensii`.

## 6. Matricea de compatibilitate

Matricea completă, cu observații despre Windows/Linux și NTS/ZTS, este în
[`docs/reference/COMPATIBILITY.md`](docs/reference/COMPATIBILITY.md).
Rezultatele măsurate pe o singură configurație nu garantează aceleași rezultate
pe alt server, alt CSDK sau alt workload.

## 7. Efecte secundare și curățare

- `ifx` direct și biblioteca creează tabele temporare și le șterg la final.
- `ifx_pdo` folosește aceleași tabele de test ca biblioteca.
- Testele PDO `t07_lob_trigger` și `t08_merge` păstrează intenționat tabele și
  trigger-e pentru diagnostic; curățarea se face cu
  `PDO_INFORMIX_KEEP_LOB=0` sau manual, în baza de test.
- Nu rulați suitele în paralel pe aceeași bază: numele obiectelor și datele de
  test se pot suprapune.
- Ștergeți DLL-urile și fișierele `.so` din working tree înainte de a publica
  un archive; `.gitignore` le exclude, dar un zip manual le poate include.

## 8. Benchmark

Benchmark-ul nu este un test de corectitudine. Rezultatele și metodologia sunt
în [`docs/reference/BENCHMARK.md`](docs/reference/BENCHMARK.md). Rulați-l pe
aceeași bază și cu aceeași versiune PHP pentru comparații corecte; nu comparați
numere din medii diferite.

## 9. Ce trimiteți într-un raport

Includeți:

- versiunea PHP, NTS/ZTS, x64/x86, OS și compilatorul;
- modulul (`ifx`, `ifx_pdo`, `pdo_informix`, bibliotecă) și binarul folosit;
- versiunea CSDK/IDS;
- comanda minimă care reproduce problema;
- mesajul de eroare complet, cu DSN-ul și credențialele **redactate**;
- rezultatul suitei afectate, dacă este disponibil.

Nu publicați parole, chei, DSN-uri reale sau dump-uri de date de producție.
