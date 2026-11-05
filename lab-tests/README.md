# ifx-lab-tests

Kit complet pentru laboratorul cu **pdo_informix + ifx direct** pe:

- **RHEL 10.2** — PHP nativ **8.3.36**
- **Fedora 45** — PHP nativ **8.5.12**

Nu se folosește Remi. Comenzile de instalare sunt în `install/`, testele în
`tests/`, pagina de browser în `web/`, iar configurația Windows pentru IDS în
`windows/`.

Rezultatele curente: **toate testele PASS pe ambele VM-uri**.

---

## Structură

```text
ifx-lab-tests/
├─ README.md
├─ install/
│  ├─ rhel10-php83.sh
│  └─ fedora45-php85.sh
├─ tests/
│  ├─ ifx-all-types-lib.php
│  ├─ ifx-all-types-run.php
│  └─ ifx-basic-smoke.php
├─ web/
│  ├─ ifx-browser-test.php
│  ├─ ifx-all-types-lib.php      (copie pentru pagina web)
│  ├─ setup-browser-test.sh
│  ├─ ifx-browser-test.service
│  └─ ifx-test.env.example
├─ windows/
│  └─ configure-informix-network.ps1
├─ tools/
│  ├─ vm_ssh.py
│  └─ vm_scp.py
└─ docs/
   └─ SETUP-LOG.md
```

> `web/ifx-all-types-lib.php` trebuie să fie lângă `index.php` pe VM. Scriptul
> `setup-browser-test.sh` îl copiază automat dacă este în `/home/<ssh-user>/`.

---

## 1. Informix Client SDK

Arhiva folosită este:

```text
ibm.csdk.4.50.12.12.Linux.64.x86_64.tar
```

Trebuie acceptată licența IBM înainte de instalare. Copiază arhiva pe fiecare VM:

```powershell
scp "<csdk-archive>" `
  <ssh-user>@<rhel-host>:/home/<ssh-user>/
```

```powershell
scp "<csdk-archive>" `
  <ssh-user>@<fedora-host>:/home/<ssh-user>/
```

---

## 2. Instalare RHEL 10.2

Copiază folderul pe VM:

```powershell
scp -r "<kit>" <ssh-user>@<rhel-host>:/home/<ssh-user>/
ssh <ssh-user>@<rhel-host>
```

Pe VM:

```bash
cd ~/ifx-lab-tests/install
chmod +x rhel10-php83.sh

export ACCEPT_IBM_LICENSE=yes
export CSDK_ARCHIVE="$HOME/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar"
./rhel10-php83.sh
```

Scriptul:

- instalează PHP 8.3.36 și uneltele de build;
- instalează CSDK în `/opt/ibm/informix`;
- compilează și instalează `pdo_informix.so`;
- compilează și instalează `ifx.so`;
- activează ambele în `/etc/php.d/`;
- afișează verificarea finală.

Verificare manuală:

```bash
php -v
php -m | grep -Ei 'ifx|informix|pdo'

php -r '
printf("PHP=%s\n", PHP_VERSION);
printf("pdo_informix=%s\n", extension_loaded("pdo_informix") ? "yes" : "no");
printf("ifx=%s\n", extension_loaded("ifx") ? "yes" : "no");
printf("ifx_pdo=%s\n", extension_loaded("ifx_pdo") ? "yes" : "no");
printf("ifx_funcs=%d\n", count(get_extension_funcs("ifx") ?: []));
printf("pdo_funcs=%d\n", count(get_extension_funcs("pdo_informix") ?: []));
printf("drivers=%s\n", implode(",", PDO::getAvailableDrivers()));
'
```

Rezultat așteptat:

```text
pdo_informix=yes
ifx=yes
ifx_pdo=no
ifx_funcs=39
pdo_funcs=0
drivers=sqlite,informix
```

---

## 3. Instalare Fedora 45

```powershell
scp -r "<kit>" <ssh-user>@<fedora-host>:/home/<ssh-user>/
ssh <ssh-user>@<fedora-host>
```

Pe VM:

```bash
cd ~/ifx-lab-tests/install
chmod +x fedora45-php85.sh

export ACCEPT_IBM_LICENSE=yes
export CSDK_ARCHIVE="$HOME/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar"
./fedora45-php85.sh
```

Scriptul folosește Java 21 pentru installerul CSDK:

```text
JAVA_HOME=/usr/lib/jvm/java-21-openjdk
JAVA_TOOL_OPTIONS=-Djdk.xml.enableExtensionFunctions=true -Djava.awt.headless=true
```

Fără aceste setări, installerul CSDK poate eșua pe Fedora cu Java 25 și pot
lipsi `libifcli.so` / fișiere din instalare.

---

## 4. Teste CLI

Pe fiecare VM, setează conexiunea:

```bash
printf '<server> olsoctcp <informix-host> 9088\n' > "$HOME/sqlhosts"

export INFORMIXDIR=/opt/ibm/informix
export INFORMIXSERVER='<server>'
export INFORMIXSQLHOSTS="$HOME/sqlhosts"
export IFX_TEST_HOST='<informix-host>'
export IFX_TEST_PORT=9088
export IFX_TEST_SERVER='<server>'
export IFX_TEST_DATABASE='<database>'
export IFX_TEST_USER='<db-user>'
export IFX_TEST_PASSWORD='<password>'
```

Test rapid (module + conexiune + `systables`):

```bash
cd ~/ifx-lab-tests/tests
php ifx-basic-smoke.php
```

Test complet cu toate tipurile:

```bash
cd ~/ifx-lab-tests/tests
php ifx-all-types-run.php
```

Rezultat așteptat:

```text
OK   table created (first run) / existing table reused
OK   PDO wrote row 1 ...
OK   PDO serial / CHAR / ... / CLOB
OK   ifx wrote row 2 ...
OK   ifx serial / CHAR / ... / BYTE
OK   ifx SET / MULTISET / LIST / ROW
OK   ifx CLOB write verified via PDO
OK   ifx BLOB write verified via PDO
OK   table preserved for inspection
RESULT: PASS
```

Tabela se creează **doar dacă nu există** și **nu se șterge la final**. La
rulări repetate sunt înlocuite doar rândurile de test `id=1` și `id=2`; tabela
rămâne pentru verificare.

---

## 5. Test pe Windows

Testul a rulat și pe Windows x64, cu **PHP 8.5.11 NTS**, `pdo_informix` și
`ifx` direct, fără `ifx_pdo`.

Rezultat: **PASS** — 63 verificări, 0 eșecuri.

Comenzile complete și rezultatul sunt în
[`docs/WINDOWS-TEST.md`](docs/WINDOWS-TEST.md), iar output-ul integral în
[`docs/WINDOWS-TEST-OUTPUT.txt`](docs/WINDOWS-TEST-OUTPUT.txt).

Pe scurt:

```powershell
$php = 'C:\path\to\php.exe'
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
$env:IFX_TEST_IFX_DSN = 'informix:server=<server>;database=<database>;host=<informix-host>;service=9088;protocol=olsoctcp;'

php -n `
  -d "extension_dir=C:\php\ext" `
  -d extension=php_pdo_informix.dll `
  -d "extension=C:\php\ext\ifx.dll" `
  tests\ifx-all-types-run.php
```

În acest mediu Windows, `ifx` direct a avut nevoie de DSN-ul explicit
`IFX_TEST_IFX_DSN`; rezolvarea doar prin `sqlhosts` a dat `-908`.

---

## 6. Test în browser

Din Windows copiază fișierele necesare pe VM:

```powershell
scp "<kit>\web\ifx-browser-test.php" `
  <ssh-user>@<rhel-host>:/home/<ssh-user>/
scp "<kit>\tests\ifx-all-types-lib.php" `
  <ssh-user>@<rhel-host>:/home/<ssh-user>/
scp "<kit>\web\setup-browser-test.sh" `
  <ssh-user>@<rhel-host>:/home/<ssh-user>/
```

Apoi pe VM:

```bash
export WEB_USER='<web-user>'
export INFORMIXSERVER='<server>'
export INFORMIXSQLHOSTS='<sqlhosts>'
export IFX_TEST_HOST='<informix-host>'
export IFX_TEST_PORT='9088'
export IFX_TEST_SERVER='<server>'
export IFX_TEST_DATABASE='<database>'
export IFX_TEST_USER='<db-user>'
export IFX_TEST_PASSWORD='<password>'
export IFX_TEST_IFX_DSN='informix:server=<server>;database=<database>;host=<informix-host>;service=9088;protocol=olsoctcp;'

sudo env \
  WEB_USER="$WEB_USER" \
  INFORMIXSERVER="$INFORMIXSERVER" \
  INFORMIXSQLHOSTS="$INFORMIXSQLHOSTS" \
  IFX_TEST_HOST="$IFX_TEST_HOST" \
  IFX_TEST_PORT="$IFX_TEST_PORT" \
  IFX_TEST_SERVER="$IFX_TEST_SERVER" \
  IFX_TEST_DATABASE="$IFX_TEST_DATABASE" \
  IFX_TEST_USER="$IFX_TEST_USER" \
  IFX_TEST_PASSWORD="$IFX_TEST_PASSWORD" \
  IFX_TEST_IFX_DSN="$IFX_TEST_IFX_DSN" \
  bash /home/<ssh-user>/setup-browser-test.sh
```

`WEB_USER` trebuie să fie un cont existent pe VM. Pentru Fedora, aceleași
comenzi cu `<ssh-user>@<fedora-host>`.

Pagina rulează pe portul **18080**:

- **RHEL:** <http://<rhel-host>:18080/>
- **Fedora:** <http://<fedora-host>:18080/>

Dacă browserul nu vede direct VM-urile:

```powershell
ssh -L 18081:<rhel-host>:18080 <ssh-user>@<rhel-host>
ssh -L 18082:<fedora-host>:18080 <ssh-user>@<fedora-host>
```

Apoi:

- RHEL: <http://127.0.0.1:18081/>
- Fedora: <http://127.0.0.1:18082/>

Serviciul este doar pentru test, nu pentru producție: `php -S` nu este un server
web concurent și pagina nu are autentificare.

---

## 7. Ce acoperă testul „all types”

Tabela `php_ifx_all_types` conține:

| Familie | Tipuri |
|---|---|
| Caractere | `CHAR`, `VARCHAR`, `LVARCHAR`, `NCHAR`, `NVARCHAR` |
| Numerice | `BOOLEAN`, `SMALLINT`, `INTEGER`, `BIGINT`, `INT8`, `DECIMAL`, `NUMERIC`, `MONEY`, `SMALLFLOAT`, `REAL`, `FLOAT`, `DOUBLE PRECISION` |
| Dată/timp | `DATE`, `DATETIME`, `INTERVAL` |
| LOB | `TEXT`, `BYTE`, `BLOB`, `CLOB` |
| Colecții | `SET`, `MULTISET`, `LIST`, `ROW` |
| Serial | `SERIAL` |

PDO scrie și citește rândul 1. `ifx` direct scrie și citește rândul 2.

Limitări documentate:

- `ifx` direct poate scrie `CLOB`, dar nu poate face `SELECT t_clob`
  (`SQLCODE -11013`); valoarea este verificată prin PDO.
- `BLOB` scris de `ifx` este verificat prin PDO.
- `SERIAL8` / `BIGSERIAL` nu pot coexista cu `SERIAL` în aceeași tabelă;
  Informix permite o singură coloană serial per tabelă.
- Tipurile definite de utilizator (`DISTINCT`, `OPAQUE`) și JSON/BSON nu fac
  parte din testul standard; necesită definiții suplimentare.

Tabela rămâne în baza de date. Pentru inspectarea rezultatelor:

```sql
SELECT id, serial_col, t_varchar, t_integer, t_date, t_datetime,
       t_interval, t_text, t_byte, t_blob, t_clob
FROM php_ifx_all_types
ORDER BY id;
```

Rândul `id=1` este scris de PDO, rândul `id=2` de `ifx` direct.

---

## 8. Configurare Windows IDS + firewall

Scriptul:

```text
windows/configure-informix-network.ps1
```

Se rulează **ca Administrator**:

```powershell
Start-Process powershell -Verb RunAs -ArgumentList `
  '-NoProfile -ExecutionPolicy Bypass -File "<kit>\windows\configure-informix-network.ps1"'
```

Face:

- backup la `sqlhosts`;
- schimbă listenerul pe `0.0.0.0`;
- repornește serviciul `<server>`;
- creează reguli firewall pentru:
  - `<vm-subnet>` — VM-uri Hyper-V;
  - `<lan-subnet>` — LAN;
- lasă restul surselor blocate implicit.

Internetul nu poate iniția conexiuni fără port forwarding pe router; chiar dacă
ar exista, regula firewall nu permite adrese publice.

---

## 9. Log complet

Comenzile și rezultatele sunt în:

[`docs/SETUP-LOG.md`](docs/SETUP-LOG.md)

Conține:

- identificarea VM-urilor și versiunile native PHP;
- instalarea CSDK pe RHEL și Fedora;
- problemele întâlnite (`libcrypt`, Java 25, `libifcli`);
- comenzile de build pentru ambele extensii;
- verificările și testele finale.

