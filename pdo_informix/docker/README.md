# Test pe Linux (Docker)

Imagine `php:8.5-cli` (Debian) + **Informix Client SDK 4.50.FC12W12** (Linux x86_64)
+ extensia compilată din `..\src` cu `phpize` / `configure --with-pdo-informix`.
Testele rulează **în container** și se conectează la serverul Informix de pe
mașina Windows prin `host.docker.internal:9088`.

## Fișiere

| Fișier | Rol |
|---|---|
| `Dockerfile` | imaginea de test (contextul de build = rădăcina proiectului) |
| `csdk/clientsdk.tar` | Informix Client SDK 4.50.FC12W12 Linux x86_64 — **nu este inclus**; copiați arhiva aici |
| `csdk/installer.properties` | răspuns local pentru instalare silențioasă; **nu este publicat** |
| `csdk/installer.properties.example` | șablon; copiați-l în `installer.properties` după ce acceptați licența IBM |
| `etc/sqlhosts` | intrarea `informix_server olsoctcp host.docker.internal informix_server` |
| `test-on-linux.ps1` | build + `docker run` cu suita de teste montată din `..\test`; `-Report` rulează apoi și `report.php` |

`installer.properties` conține acceptarea licenței IBM, de aceea rămâne local și
este exclus prin `.gitignore`. Nu publicați fișierul fără să fi acceptat personal
licența pentru CSDK-ul folosit.

## Rulare

Înainte de primul build, pregătiți local fișierele care nu se publică:

```powershell
Copy-Item csdk\installer.properties.example csdk\installer.properties
# Copiați clientsdk.tar în csdk\ și, după acceptarea licenței IBM,
# setați LICENSE_ACCEPTED=TRUE în csdk\installer.properties.
```

```powershell
# din rădăcina proiectului
pwsh -File docker\test-on-linux.ps1

# refolosind imaginea deja construită
pwsh -File docker\test-on-linux.ps1 -SkipBuild

# suită + scrierea rezultatului în tabela `test_results`
pwsh -File docker\test-on-linux.ps1 -SkipBuild -Report
```

echivalent manual:

```powershell
docker build -f docker/Dockerfile -t pdo-informix-linux .
docker run --rm --add-host host.docker.internal:host-gateway `
  -e "PDO_INFORMIX_DSN=informix:server=informix_server;database=testdb;host=host.docker.internal;service=9088;protocol=olsoctcp;" `
  -e "PDO_INFORMIX_REPORT_DSN=informix:server=informix_server;database=testdb;host=host.docker.internal;service=9088;protocol=olsoctcp;" `
  -e PDO_INFORMIX_USER=<user> -e PDO_INFORMIX_PASS=<password> `
  -v "${PWD}\test:/tests" pdo-informix-linux php run-tests.php
```

## Detalii

- Pe Linux, `infxcli.h` include `infxsql.h` (livrat de CSDK), deci **nu** sunt
  necesare header-ele ODBC din Windows SDK — build-ul folosește `config.m4`.
- Serviciul `informix_server` este adăugat în `/etc/services` (`informix_server 9088/tcp`), iar
  `INFORMIXSQLHOSTS` arată către `etc/sqlhosts` din CSDK.
- Instalarea CSDK se face cu `./installclientsdk -i silent -l en`; ultimul pas
  post-instalare poate întoarce cod diferit de zero, de aceea `|| true`.
- `PDO_INFORMIX_*` din `..\test\config.php` sunt suprascrise prin `-e`, ca
  testele să folosească `host.docker.internal` în loc de `127.0.0.1`.
- `--add-host host.docker.internal:host-gateway` este obligatoriu pe Docker
  Desktop: fără el DNS-ul poate întoarce o adresă **IPv6**, iar CSDK se
  conectează doar IPv4, deci apare `-908 Attempt to connect to database server
  (informix_server) failed`.
- Pentru scrierea rezultatelor în tabela `teste`, `report.php` folosește
  `PDO_INFORMIX_REPORT_DSN` (nu `PDO_INFORMIX_DSN`):

  ```powershell
  docker run --rm --add-host host.docker.internal:host-gateway `
    -e "PDO_INFORMIX_DSN=$dsn" -e "PDO_INFORMIX_REPORT_DSN=$dsn" `
    -e PDO_INFORMIX_USER=<user> -e PDO_INFORMIX_PASS=<password> `
    -v "C:\path\to\pdo_informix\test:/tests" pdo-informix-linux php report.php
  ```

## Cerințe de sistem în imagine

Instalarea CSDK face un check de prerechizite și are nevoie de:

- un **JRE** (instalatorul este o aplicație InstallAnywhere) — `openjdk-21-jre-headless`
- `libncurses.so.6` / `libtinfo.so.6` — pachetele `libncurses6`, `libtinfo6`
- `libelf.so.1` — `libelf1t64` pe Debian 13, `libelf1` pe Debian 12

Fără ele, instalatorul iese cu cod 45 și `Prerequisite check ERROR: Missing
shared library files ...`, iar `NativeAPI` nu se poate încărca.

## Rezultate

```
== t01_extension ==   PASS   5 assertions
== t02_connect ==     PASS   5 assertions   (IDS 14.10.FC12W12DE, testdb)
== t03_crud ==        PASS  15 assertions
== t04_transactions == PASS  5 assertions
== t05_errors ==      PASS   5 assertions   (SQLSTATE 23000 / 42S02)
== t06_types ==       PASS  12 assertions
== t07_lob_trigger == PASS  31 assertions   (SERIAL8 + JSON + BSON + BLOB + trigger-e)

ALL PASSED (9 test file(s), 198 assertions, 2.16s)
```

Rularea din container scrie și ea în tabela `teste` (dacă pornești `php report.php`
în loc de `run-tests.php`), cu `os_name = 'Linux'` — vezi secțiunea „Rezultate”
din [`../README.md`](../README.md).




















