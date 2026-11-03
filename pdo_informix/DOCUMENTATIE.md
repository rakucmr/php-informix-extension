# PDO_INFORMIX pentru PHP 8.5 — documentație tehnică

Documentul descrie ce s-a construit, de ce, cum se folosește și ce limitări are
extensia PDO pentru IBM Informix, pe Windows și pe Linux, împreună cu suita de
teste și cu rezultatele obținute pe serverul real.

> Fișierele `.dll`, `.so` și `.lib` menționate în acest document sunt rezultate
> de build locale; nu sunt incluse în repository-ul public.

---

## 1. Rezumat

| Obiectiv | Rezultat |
|---|---|
| Sursă PECL 1.3.7 compatibilă cu PHP 8.1 → 8.5 | `src\` (4 modificări din fork + 1 corecție MSVC) |
| Extensie compilată pentru PHP 8.5.11 NTS x64 (Windows) | `extension\php_pdo_informix.dll` (32.256 B, export `get_module`) |
| Suită de teste funcționale | `test\` — 9 fișiere, **198 de aserțiuni**, 0 eșecuri |
| Documentație de analiză (MERGE, limitări, oportunități) | `ANALIZA.md` |
| Rezultatele testelor salvate în baza de date | tabela `test_results` — 52 de rânduri, 8 rulări |
| Tabele demo cu SERIAL8 / JSON / BSON / BLOB + trigger-e | `pdo_t_lob`, `pdo_t_lob_log`, 3 trigger-e (păstrate intenționat) |
| Testare pe Linux | imagine Docker `pdo-informix-linux` (PHP 8.5.12 NTS, Debian 13.7), 9/9 pass |
| Server folosit | IBM Informix Dynamic Server **14.10.FC12W12DE**, instanța `informix_server`, port 9088, baza `testdb` |

---

## 2. Context

`pdo_informix` este driverul PDO pentru IBM Informix, publicat pe PECL ultima
dată în versiunea **1.3.7**, care nu mai compilează pe PHP ≥ 8.1 din cauza
schimbărilor din API-ul Zend/PDO. Fork-ul [`nahuelon/pdo_informix`](https://github.com/nahuelon/pdo_informix)
aduce exact modificările necesare, dar este distribuit cu un `Dockerfile` și cu
arhiva Client SDK-ului IBM în repo.

Obiectivele au fost:

1. o sursă proprie, plecând **strict** de la PECL 1.3.7 + modificările
   funcționale din fork (fără restul ambalajului);
2. compilarea efectivă a extensiei pe Windows, pentru PHP 8.5;
3. teste reale împotriva serverului Informix, nu doar „se încarcă modulul”;
4. verificarea aceleiași surse pe Linux.

---

## 3. Sursa (`src\`)

### 3.1 Proveniență

- bază: `PDO_INFORMIX-1.3.7.tgz` de pe pecl.php.net (`package.xml` declară 1.3.7);
- modificări: cele patru corecții de compatibilitate din fork (`main`), aplicate
  prin `php85-compat.patch`; fișierele C rezultate sunt **identice** cu cele din
  fork (verificat prin comparație de conținut, ignorând CRLF);
- restul fișierelor (`config.m4`, `config.w32`, `Makefile.frag`, `package.xml`,
  `tests/*.phpt`, `LICENSE`, `CREDITS`) sunt neatinse față de PECL.

### 3.2 Modificările

| # | Fișier | Modificare | Motiv |
|---|---|---|---|
| 1 | `informix_driver.c`, `informix_statement.c` | `pdo_parse_params(stmt, stmt_string, …)` fără cast `(char *)` | semnătura s-a schimbat în PHP 8.1 |
| 2 | `informix_statement.c` | apelul `stmt_cleanup(stmt)` în `#if !PHP_8_1_OR_HIGHER` | membrul a fost eliminat din `pdo_stmt_driver_t` în 8.1 |
| 3 | `informix_statement.c` | `lob_stream_read/write` cu `ssize_t` în loc de `size_t` | `php_stream_ops` folosește `ssize_t` din PHP 8.4 |
| 4 | `informix_statement.c` | `strcmp(Z_STRVAL_P(...), "") == 0` în loc de `Z_STRVAL_P(...) == '\0'` | din 8.1 un string gol legat ca parametru trebuie să ajungă `NULL` |
| 5 | `pdo_informix.c` | `zend_function_entry … = { };` → `{ ZEND_FE_END }` | inițializatorul vid provoacă **ICE** (`fatal error C1001`) în MSVC 14.41; corecție locală, nu vine din fork |

Modificarea 5 este singura care nu provine din fork. Ea nu schimbă comportamentul
la runtime (lista de funcții PHP rămâne vidă), dar face fișierul C valid și
compilabil cu MSVC.

### 3.3 Versiune

`php_pdo_informix.h` păstrează `PDO_INFORMIX_VERSION "1.3.7"`, deci
`phpversion('pdo_informix')` întoarce `1.3.7`.

---

## 4. Compilare pe Windows

### 4.1 Mediu

| Componentă | Versiune / locație |
|---|---|
| PHP țintă | 8.5.11 NTS, x64, VS17 (`C:\php`) |
| PHP API | 20250925 |
| Compilator | MSVC 14.41 (Visual Studio 2022), `vcvars64.bat` |
| Header-e + lib PHP | `php-devel-pack-8.5.11-nts-Win32-vs17-x64.zip` (`include\`, `lib\php8.lib`) |
| Client SDK | Informix 4.50.FC12W12 (`C:\IBM\Informix_Client_SDK`) |
| Flags | `/LD /MD /O2`, link `php8.lib` + `iclit09b.lib` |

### 4.2 De ce `cl` direct, nu `phpize` + `nmake`

`phpize.bat` din devel pack generează un `configure.bat` care cere uneltele din
`php-sdk-binary-tools` aflate în `PATH`: `bison`, `re2c`, `sed`, `zip`, `mc`,
`7za`. Pentru o extensie cu trei fișiere C acestea nu sunt folosite la compilare,
iar instalarea SDK-ului PHP doar pentru atât ar fi fost nejustificată. Build-ul
direct cu `cl` produce același DLL.

### 4.3 Header-ele ODBC — capcană specifică Windows

`infxcli.h` (singurul header din `CSDK\incl\cli`) conține:

```c
#ifdef _WINDOWS_
#include "sql.h"
#include "sqlext.h"
#else
#include "infxsql.h"
#endif
```

Adică pe Windows se bazează pe header-ele ODBC din **Windows SDK**, nu pe cele
din CSDK; `infxsql.h` există doar în pachetul de Linux. CSDK 4.50.FC12W12 pentru
Windows livrează în `incl\cli` doar `infxcli.h` și `xa.h`, iar `config.w32` din
PECL verifică existența `sql.h`.

Soluția aplicată: copierea header-elor din
`C:\Program Files (x86)\Windows Kits\10\Include\10.0.22621.0\um` în
`<CSDK>\incl\cli` — `sql.h`, `sqlext.h`, `sqltypes.h`, `sqlucode.h`,
`odbcinst.h`. Pasul este automatizat de `extension\fix-csdk-odbc-headers.bat`.
Nu este necesară reinstalarea CSDK cu feature-ul ODBC.

### 4.4 Scriptul de build

`extension\build.bat [devel-pack] [csdk] [output-dll] [dm]`:

1. verifică existența devel pack-ului, a `iclit09b.lib` și a `infxcli.h`;
2. găsește Visual Studio prin `vswhere` (fallback pe VS 2022 Preview);
3. copiază sursele într-un director de lucru **fără spații**
   (`%TEMP%\pdo_informix_build`) — evită problemele de quoting la `cl`/`link`;
4. generează un `config.h` minimal:

   ```c
   #include "main/config.w32.h"
   #define COMPILE_DL_PDO_INFORMIX 1
   #define HAVE_PDO_INFORMIX 1
   ```

5. compilează și linkează în `extension\php_pdo_informix.dll`.

Cu al patrulea argument `dm` se construiește varianta care leagă **Driver Manager-ul
ODBC al Windows-ului** (`odbc32.lib` / `odbccp32.lib`) în loc de `iclit09b.lib`:
DLL-ul se numește atunci de obicei `php_pdo_informix_dm.dll` și conectarea trece
prin driverul înregistrat în `ODBCINST.INI`
(`IBM INFORMIX ODBC DRIVER (64-bit)`), pe care extensia îl adaugă singură în
șirul de conectare dacă lipsește `DRIVER=`/`DSN=`. Varianta trece aceleași 198 de
aserțiuni, dar **nu refolosește sesiuni** nici cu pooling pornit (vezi
`ANALIZA.md` §3.22), iar atributele proprii CLI (peste 2000) nu pot fi setate
prin Driver Manager — `SQLSetConnectAttr` iese cu `0xC0000005`, deci sunt
compilate doar în varianta directă.


Două detalii de care depinde DLL-ul:

- **`/DHAVE_CONFIG_H`** — fără el `config.h` nu este inclus, `COMPILE_DL_PDO_INFORMIX`
  rămâne nedefinit și `ZEND_GET_MODULE` nu generează `get_module`; PHP răspunde
  atunci cu `Invalid library (maybe not a PHP library)`.
- **`/DZEND_DEBUG=0`** — altfel `ZEND_DEBUG` este nedefinit și `zend_module_entry`
  nu se mai inițializează constant (`C2099`).

### 4.5 Rezultat

```
php_pdo_informix.dll   32.256 bytes
export:                get_module
dependențe:            php8.dll, ICLIT09B.dll, VCRUNTIME140.dll, api-ms-win-crt-*
```

Verificare:

```bat
> php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix -m | findstr /i informix
pdo_informix
> php -r "var_dump(PDO::getAvailableDrivers());"
array(1) { [0]=> string(8) "informix" }
```

### 4.6 Instalare

1. copiază `php_pdo_informix.dll` în `ext\` al PHP-ului țintă;
2. în `php.ini`: `extension=pdo_informix`;
3. variabile de mediu pentru procesul PHP:

```bat
set INFORMIXDIR=C:\IBM\Informix_Client_SDK
set INFORMIXSERVER=informix_server
set INFORMIXSQLHOSTS=C:\IBM\Informix\etc\sqlhosts.informix_server
set PATH=%INFORMIXDIR%\bin;%PATH%
```

`ICLIT09B.dll` trebuie să fie găsit la runtime, deci `%INFORMIXDIR%\bin` în `PATH`
este obligatoriu.

---

## 5. Utilizare

### 5.1 DSN

Driverul alege funcția ODBC după cum DSN-ul conține sau nu `=`:

| Formă | Funcție | Cerințe |
|---|---|---|
| `informix:server=informix_server;database=testdb;host=127.0.0.1;service=9088;protocol=olsoctcp;` | `SQLDriverConnect` | nu are nevoie de DSN în `odbc.ini` |
| `informix:testdb` | `SQLConnect` | numele trebuie să fie un DSN definit în `odbc.ini`; altfel `SQLSTATE=IM002` |

În forma cu `=` se pot adăuga opțiuni de client CSDK, de exemplu
`...;OPTOFC=1;` sau `...;OPTMSG=0;` — acestea se aplică la conectare și nu pot
fi schimbate ulterior prin `setAttribute()`.

### 5.2 Exemplu

```php
$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'),
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

foreach ($pdo->query('SELECT FIRST 5 tabname FROM systables WHERE tabid > 99') as $row) {
    echo $row['TABNAME'], PHP_EOL;   // atenție: cheile sunt cu MAJUSCULE
}

// atribute PDO
echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);    // …Version 14.10.FC12W12DE
echo $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);    // 4.50.FC12W12 (CSDK)
echo $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS); // Connected
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);            // secunde (SEND/RECVTIMEOUT)

// atribute specifice Informix (constante PDO_INFORMIX_*)
$pdo->setAttribute(PDO_INFORMIX_FETCH_BUFFER_SIZE, 32768);
```

### 5.3 Autentificare

Testele folosesc un cont dedicat, configurat prin variabilele de mediu
`PDO_INFORMIX_USER` și `PDO_INFORMIX_PASS`. În producție nu folosi contul de
administrator: creează un utilizator dedicat aplicației și acordă-i doar
drepturile necesare.

---

## 6. Teste funcționale (`test\`)

### 6.1 Structură

| Fișier | Rol |
|---|---|
| `bootstrap.php` | `connect()`, `check()`, `check_equals()`, `drop_table()`, `row_lower()`, `pdo_informix_child_args()` |
| `config.php` | DSN + credențiale pentru teste și pentru raportare (suprascrise prin `PDO_INFORMIX_*`) |
| `run-tests.php` | rulează toate fișierele `t*.php`, afișează PASS/FAIL |
| `report.php` | rulează testele, cronometrează fiecare fișier și scrie rezultatele în tabela `test_results` |
| `schema.sql` | DDL-ul tabelei `test_results` (referință) |
| `t01…t07*.php` | testele propriu-zise |

Fiecare test creează ce are nevoie, verifică, apoi iese cu `0` (pass) sau `1`
(fail). `t07` face excepție: își păstrează intenționat obiectele.

### 6.2 Ce acoperă

| Test | Aserțiuni | Verifică |
|---|---|---|
| `t01_extension` | 5 | extensia încărcată, driverul `informix` înregistrat, versiunea 1.3.x |
| `t02_connect` | 5 | conexiune, `PDO::ATTR_DRIVER_NAME`, `DBINFO('version')`, `DBINFO('dbname')`, `SELECT 1` |
| `t03_crud` | 15 | CREATE/INSERT/SELECT/UPDATE/DELETE, placeholdere poziționale și numite, `bindValue`, `fetchAll`, `rowCount` |
| `t04_transactions` | 5 | `beginTransaction`, `commit`, `rollBack`, `inTransaction`, persistență vs anulare |
| `t05_errors` | 5 | `PDOException` + SQLSTATE pentru duplicat (`23xxx`) și tabel inexistent (`42xxx`) |
| `t06_types` | 12 | NULL, DECIMAL, VARCHAR, DATE, `columnCount`, `getColumnMeta`, `rowCount` pe SELECT |
| `t07_lob_trigger` | 31 | `SERIAL8`, `JSON`/`BSON` citite direct ca string (inclusiv valori > 8 KB și NUL-uri), `BLOB`, trigger-e INSERT/UPDATE/DELETE, jurnal |
| `t08_merge` | 22 | `MERGE`: matched/not matched, sursă subquery, parametri, tranzacții, trigger-e |
| `t09_pdo_features` | 98 | atribute PDO și Informix, `ATTR_CASE`, `quote()`, `lastInsertId` (`SERIAL`/`SERIAL8`/`BIGSERIAL`), tipuri, LOB-uri (`TEXT`/`BYTE` direct, `CLOB`/`TEXT` peste 8 KB, `fstat`, `fseek` înainte/înapoi/`SEEK_END`), `bindParam` cu lungime, refuzul `INOUT`, proceduri stocate, cursoare, `check_liveness` |
| **total** | **198** | |

### 6.3 Particularități Informix descoperite

Acestea sunt comportamente ale serverului/driverului, nu defecte ale extensiei,
și sunt tratate explicit în teste:

1. **Numele coloanelor vin cu majuscule.** `fetch()` întoarce `['ID' => …,
   'TEST_NAME' => …]`; `PDO::ATTR_DEFAULT_FETCH_MODE` nu schimbă asta. Testele
   normalizează cheile cu `row_lower()`.
2. **BLOB are nevoie de un sbspace**: `... PUT data IN (sbspace)`. Dacă instanța
   are alt nume, testul recreează tabela fără clauză (se folosește
   `SBSPACENAME` din `onconfig`).
3. **Parametrii legați nu se convertesc direct în JSON/BSON**: `?::json` dă
   `-9634 No cast from byte to json`. Funcționează varianta cu cast prin
   LVARCHAR:

   ```sql
   INSERT INTO pdo_t_lob (doc, payload, data, label)
   VALUES (CAST(? AS LVARCHAR(2000))::json,
           json_to_bson(CAST(? AS LVARCHAR(2000))::json),
           ?, ?)
   ```

   `?` simplu merge direct doar pentru coloana `BLOB`.
4. **Trigger-ele cer `REFERENCING`**: `NEW.id` fără clauză dă
   `-8336 Undefined symbol (new)`. Forma corectă:

   ```sql
   CREATE TRIGGER pdo_t_lob_upd UPDATE ON pdo_t_lob
     REFERENCING OLD AS o NEW AS n FOR EACH ROW
     (INSERT INTO pdo_t_lob_log (op, row_id, at_time, db_user, note)
      VALUES ('UPDATE', n.id, CURRENT, USER, n.label));
   ```

5. **BSON nu se poate citi direct**: `bson_export_txt()` întoarce tipul `impexp`
   și PDO eșuează cu `-9634 No cast from impexp to sendrecv`. Verificarea se face
   cu `bson_valid(payload)` și cu `payload::lvarchar(n)` (octeții BSON bruti).
6. **Funcții utile**: `json_to_bson()`, `bson_valid()`, `bson_export_txt()`,
   `bson_import_txt()`, `bson_extract()`.

### 6.4 Reluare

`t07` este reluabil: dacă tabelele și trigger-ele există, le refolosește și
adaugă rânduri noi cu etichete unice (`row-1-HHMMSS-xxxx`); aserțiunile sunt
relative la starea de dinainte. Curățare completă doar la cerere:

```bat
set PDO_INFORMIX_KEEP_LOB=0
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\t07_lob_trigger.php
```

### 6.5 MERGE

Testul `t08` acoperă `MERGE` (IDS 12.10+, deci și 14.10): actualizare + inserare
într-un singur statement, doar `WHEN MATCHED`, doar `WHEN NOT MATCHED`, sursă
subquery, parametru legat în `SET`, tranzacție cu `rollBack()` și trigger-e
declanșate de MERGE. `PDO::exec()` întoarce numărul total de rânduri afectate
(actualizări + inserări). Detalii și limitările serverului în
[`ANALIZA.md`](ANALIZA.md) §1.

### 6.6 Corecții aduse driverului

Optsprezece defecte reale, găsite prin teste, au fost corectate în `src\`, plus o lărgire de tip și patru limitări eliminate (detalii în
[`ANALIZA.md`](ANALIZA.md) §3). Numerotarea continuă lista din
[`src/README.md`](src/README.md): 1-4 sunt hunk-urile preluate din fork, 5 este
corecția `ZEND_FE_END`.

| # | Fișier | Corecție | Efect |
|---|---|---|---|
| 6 | `informix_statement.c` | flag `eof` în `lob_stream_read` | citirea CLOB/JSON printr-un stream nu se mai repetă la infinit |
| 7 | `informix_statement.c` | `stmt_parameter_post_execute` nu mai atinge string-ul pentru `PDO_PARAM_LOB` | `bindValue($s, PDO::PARAM_LOB)` nu mai golește `$s` |
| 8 | `informix_driver.c` | `SQL_INFX_ATTR_ENABLE_SCROLL_CURSORS` pe conexiune | `PDO::CURSOR_SCROLL` funcționează (`FETCH_ORI_ABS/FIRST/LAST/REL`) |
| 9 | `php_pdo_informix_int.h` | `last_insert_id` → `zend_long` | fără trunchiere la 32 de biți |
| 10 | `informix_statement.c` | primul chunk LOB citit în `create_lob_stream()` (`lob_fill`), reluat din `pending` | `TEXT`/`BYTE` citite direct întorc conținutul; `NULL` rămâne `NULL` |
| 11 | `informix_statement.c` + `.h` | token `lob_stream_token` (`alive`/`refs`) | un stream nu mai atinge un statement eliberat (fără use-after-free) |
| 12 | `informix_statement.c` | `row_count` normalizat la `0` | `rowCount()` pe `SELECT` nu mai întoarce `-1` |
| 13 | `informix_driver.c` | `ATTR_SERVER_VERSION` prin `DBINFO('version','full')`, `ATTR_CLIENT_VERSION` prin `SQL_DRIVER_VER`, `ATTR_TIMEOUT`, `ATTR_CONNECTION_STATUS`, `ATTR_CASE` corect | atributele PDO raportează valori reale |
| 14 | `informix_driver.c` + `pdo_informix.c` | `PDO_INFORMIX_*` (9 constante) + `check_liveness` (round trip `SELECT 1 FROM systables`) | tuning Informix din PHP; conexiunile persistente moarte sunt detectate și refăcute |
| 15 | `informix_statement.c` + `.h` | `smart_lob` detectat cu `SQLColAttribute(SQL_COLUMN_TYPE_NAME)`; `CLOB`/`BLOB` citite cu `SQL_C_BINARY` (indicatorul = octeți rămași) | LOB-urile peste 8 KB se citesc integral; înainte `CLOB` repeta primul chunk la infinit |
| 16 | `informix_driver.c` | al doilea `sprintf` din `informix_handle_fetch_error` avea 6 placeholdere și 5 argumente; trecut pe `snprintf` cu formatul corect | mesaje de eroare corecte (fără valoare „ISAM” inventată), fără depășire de buffer |
| 17 | `informix_statement.c` | `lastInsertId` pentru `SERIAL8`/`BIGSERIAL`: după `INSERT`, dacă `SQL_GET_SERIAL_VALUE` dă 0, se citesc `DBINFO('sqlca.sqlerrd1')`/`('serial8')`/`('bigserial')` (prima instrucțiune din sesiune după insert) | id-uri pe 64 de biți corecte; `INSERT`-urile fără rânduri păstrează valoarea anterioară |
| 18 | `informix_statement.c` | precedența în `param_type & PDO_PARAM_INPUT_OUTPUT > 0` (se citea `param_type & 1`); paranteze explicite | `INPUT_OUTPUT` este detectat pentru orice tip de parametru |
| 19 | toate cele trei fișiere | 24 de warning-uri MSVC: semnături `long` în loc de `zend_long` (`attr`, `offset`, `colno`) și `cast`-uri lipsă pentru `SQLUSMALLINT`/`SQLINTEGER`/`SQLSMALLINT`/`SQLLEN` | build fără warning-uri în codul propriu; fără trunchieri pe Win64 |
| 20 | `informix_driver.c` + `pdo_informix.c` | mesajele de eroare nu mai conțin `at fisier:linie`; detaliile de driver sunt în spatele opțiunii `pdo_informix.error_detail` (implicit 0) | erori lizibile în producție, detalii la nevoie |
| 21 | `informix_statement.c` | `php_stream_ops.Seek` implementat (poziție + citire/aruncare pentru înainte; înapoi, inițial refuzat cu `-1` și `E_WARNING`, acum suportat — vezi #25) | `fseek()` înainte pe stream-urile LOB |
| 22 | `informix_statement.c` | al patrulea argument al lui `bindParam()` (lungimea) era tratat ca `SQL_PARAM_OUTPUT` → `-254`/`-11012`; acum `INPUT` implicit. Bufferul de ieșire e al driverului, nu al lui `zend_string` (heap corupt înainte) | `bindParam(..., )` funcționează; fără scrieri în capul lui `zend_string` |
| 23 | `informix_statement.c` | parametrii `INOUT`/`OUT` sunt refuzați la `bindParam()` cu `IM001`: CSDK dă `-9752` sau blochează `SQLExecute()` pentru totdeauna | aplicația nu mai poate atârna; mesajul trimite la `EXECUTE FUNCTION … RETURNING` |
| 24 | `informix_statement.c` | `php_stream_ops.Stat` implementat (lungimea din primul `SQLGetData` pentru smart LOB-uri, altfel după EOF) + `SEEK_END` când lungimea e cunoscută; `php_stream_ops` completat în ordinea corectă (`cast`, `stat`, `set_option`) | `fstat()` dă dimensiunea unui `CLOB`/`BLOB` fără citire; `fseek(..., SEEK_END)` funcționează |
| 25 | `informix_statement.c` | `fseek()` înapoi: octeții consumați sunt ținuți în `lob_stream_data.history` și re-serviți din memorie | `fseek` înapoi și `SEEK_CUR` negativ funcționează pe stream-urile LOB |
| 26 | `informix_statement.c` | coloanele `JSON`/`BSON` erau legate ca `PDO_PARAM_LOB`, deci `fetch()` întorcea un resource; acum se recunoaște numele tipului și valoarea se întoarce ca string (text JSON, respectiv octeți BSON cu NUL-uri) | `json_decode($row['doc'])` merge; BSON se compară byte cu byte |
| 27 | `informix_statement.c` | `next_rowset()` întorcea `FALSE` și pentru `SQL_ERROR`, adică o eroare reală era raportată ca „nu mai sunt seturi” | `IM001` cu mesaj explicit la eroare; `SQL_NO_DATA` rămâne `FALSE` |
| 28 | `informix_driver.c` | environment partajat (refcount) când `pdo_informix.connection_pooling=1`, plus `SQL_ATTR_CONNECTION_POOLING`/`SQL_INFX_ATTR_CONNECTION_POOLING`; implicit oprit | pool-ul CSDK are un singur environment, deci măcar are șanse să refolosească (măsurat: CLI 4.50 nu refolosește, vezi `ANALIZA.md` §3.22) |

Atributele noi se folosesc așa:

```php
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);              // SENDTIMEOUT/RECVTIMEOUT
$pdo->setAttribute(PDO_INFORMIX_FETCH_BUFFER_SIZE, 32768);
$pdo->setAttribute(PDO_INFORMIX_DEFERRED_PREPARE, 1);

$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);   // IBM Informix Dynamic Server Version 14.10.FC12W12DE
$pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);   // 4.50.FC12W12 (CSDK)
$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);// Connected

// OPTOFC/OPTMSG/DELIMIDENT se dau doar în DSN:
$pdo = new PDO('informix:server=informix_server;database=testdb;host=…;OPTOFC=1;', $u, $p);
$pdo->getAttribute(PDO_INFORMIX_OPTOFC);        // 1
```

### 6.7 `lastInsertId()` pe `SERIAL`, `SERIAL8` și `BIGSERIAL`

```php
$pdo->exec('CREATE TABLE t (id SERIAL8 NOT NULL PRIMARY KEY, n VARCHAR(10))');
$pdo->exec("INSERT INTO t (n) VALUES ('a')");
echo $pdo->lastInsertId();          // 1

$pdo->prepare('INSERT INTO t (n) VALUES (?)')->execute(['b']);
echo $pdo->lastInsertId();          // 2
```

Merge pentru toate cele trei tipuri de „serial”. Valoarea este citită de driver
din `DBINFO('serial8')` / `DBINFO('bigserial')` / `DBINFO('sqlca.sqlerrd1')`
imediat după `INSERT` (serverul o raportează doar pentru primul statement de
după insert), deci nu rulați voi înșiși un `SELECT dbinfo(...)` între `INSERT`
și `lastInsertId()` — veți primi `0`. Argumentul `$name` este ignorat
(Informix nu are secvențe pe care să le poți numi aici).

### 6.8 Mesaje de eroare și stream-uri LOB

Implicit, mesajul unei erori este doar textul serverului (plus codul ISAM, util
la diagnosticare):

```
SQLSTATE[42S02]: Base table or view not found: -206 [Informix][Informix ODBC
Driver][Informix]The specified table (x) is not in the database. (ISAM: -111)
```

Cu `pdo_informix.error_detail = 1` (în `php.ini` sau prin `ini_set()`) se adaugă
și funcția din driver plus fișierul:linia — utile doar la depanarea driverului.

Directiva `pdo_informix.connection_pooling` (implicit `0`, `PHP_INI_SYSTEM`)
pornește pool-ul de conexiuni al CSDK: driverul ține un singur environment,
partajat de toate conexiunile, și îl eliberează la ultima închidere. Se setează
doar din `php.ini` sau din linia de comandă, pentru că trebuie aplicată înainte
de conectare:

```ini
pdo_informix.connection_pooling = 1
```

Măsurat cu CSDK 4.50.FC12W12: CLI-ul nu refolosește sesiunea nici așa
(`DBINFO('sessionid')` diferă la fiecare conectare), deci pentru performanță se
folosește `PDO::ATTR_PERSISTENT`. Detalii în `ANALIZA.md` §3.22.

Stream-urile LOB (`$pdo->query('SELECT c_clob …')->fetchColumn()`) sunt
secvențiale la nivel de `SQLGetData`, dar driverul ține minte octeții consumați,
așa că `fseek()` merge înainte (citește și aruncă diferența) **și înapoi** (se
re-servesc din memorie). `SEEK_END` folosește lungimea raportată de primul
`SQLGetData` pe smart LOB-uri.

```php
$lob = $pdo->query('SELECT c_clob FROM t')->fetchColumn();
fstat($lob)['size'];      // 40000: lungimea, fără să citească nimic (CLOB/BLOB)
fread($lob, 16);
fseek($lob, 1000);       // sare la offsetul 1000
fread($lob, 16);
fseek($lob, 0);          // 0: se întoarce, octeții sunt încă în memorie
fseek($lob, -100);       // -1: înainte de începutul valorii
fseek($lob, 0, SEEK_END); // merge, pentru că lungimea e cunoscută
```

---

## 7. Rezultatele în baza de date

### 7.1 Tabela `test_results`

Este creată automat de `report.php` în `testdb`, în dbspace-ul `datadbs`:

```sql
CREATE TABLE teste (
    id            SERIAL            NOT NULL PRIMARY KEY,
    run_id        CHAR(36)          NOT NULL,
    executed_at   DATETIME YEAR TO SECOND NOT NULL,
    test_name     VARCHAR(64)       NOT NULL,
    result        CHAR(6),
    assertions    SMALLINT,
    failures      SMALLINT,
    exit_code     SMALLINT,
    duration_ms   INTEGER,
    os_name       VARCHAR(32),
    php_version   VARCHAR(32),
    ext_version   VARCHAR(16),
    server_name   VARCHAR(96),
    db_name       VARCHAR(32),
    os_user       VARCHAR(32),
    message       LVARCHAR(1024)
) IN datadbs;
```

La data redactării: **394 de rânduri, 46 rulări** (ultimele două — Windows și
Linux — câte 9 teste și **198 de aserțiuni** fiecare, `run_id`
`6ae46d70-3dae-abf3-3bc0-642a36b7370e` respectiv
`6ae46da7-1d09-240b-cf11-aa9fbc6ee1dd`).

Interogare de sinteză:

```sql
SELECT os_name, run_id, MIN(executed_at) AS started, COUNT(*) AS teste,
       SUM(assertions) AS aserțiuni, SUM(failures) AS eșecuri, SUM(duration_ms) AS ms
FROM test_results GROUP BY os_name, run_id ORDER BY started DESC;
```

### 7.2 Obiectele demo păstrate

Testul `t07` lasă intenționat în urmă:

| Obiect | Conținut |
|---|---|
| `pdo_t_lob` | 23 de rânduri (la data redactării; crește la fiecare rulare `t07`): `id SERIAL8`, `doc JSON`, `payload BSON`, `data BLOB`, `label` |
| `pdo_t_lob_log` | 92 de rânduri: `op`, `row_id`, `at_time`, `db_user`, `note` |
| `pdo_t_lob_ins`, `pdo_t_lob_upd`, `pdo_t_lob_del` | trigger-ele care alimentează jurnalul |

Testul `t08` lasă, de asemenea:

| Obiect | Conținut |
|---|---|
| `pdo_t_merge_target` | 4 rânduri: `id`, `name`, `qty` |
| `pdo_t_merge_source` | 3 rânduri (sursa MERGE) |
| `pdo_t_merge_log` | 10 rânduri: `op`, `row_id`, `at_time` |
| `pdo_t_merge_target_ins`, `pdo_t_merge_target_upd` | trigger-ele pe tabela țintă |

Exemplu de conținut al jurnalului:

```
25  INSERT row=13  informix  row-1-234748-81ed
26  INSERT row=14  informix  row-2-234748-81ed
27  UPDATE row=13  informix  row-1-modificat-234748-81ed
28  DELETE row=14  informix  row-2-234748-81ed
```

---

## 8. Testare pe Linux (Docker)

### 8.1 Imaginea

`docker\Dockerfile`, context = rădăcina proiectului:

- bază `php:8.5-cli` → Debian 13.7, PHP 8.5.12 NTS, API 20250925;
- pachete: `build-essential autoconf pkg-config file libncurses6 libtinfo6
  libssl3 openjdk-21-jre-headless` + `libelf1t64`;
- Informix Client SDK 4.50.12.12 (Linux x86_64) instalat silențios cu
  `./installclientsdk -i silent -f installer.properties -l en`;
- extensia compilată din `src\` cu `phpize && ./configure --with-pdo-informix
  && make && make install && docker-php-ext-enable pdo_informix`.

Imagine rezultată: **1,65 GB**.

### 8.2 Conectarea la serverul de pe Windows

- `/etc/services`: `informix_server 9088/tcp`;
- `INFORMIXSQLHOSTS=/opt/IBM/informix/etc/sqlhosts` cu
  `informix_server olsoctcp host.docker.internal informix_server`;
- testele primesc DSN-ul prin `-e PDO_INFORMIX_DSN=…host.docker.internal…`
  (și `PDO_INFORMIX_REPORT_DSN`, pentru scrierea în `test_results`);
- `--add-host host.docker.internal:host-gateway` fixează numele pe IPv4: fără el
  DNS-ul Docker poate întoarce o adresă IPv6, iar CSDK se conectează doar IPv4
  (simptom: `-908 Attempt to connect to database server failed`).

### 8.3 Probleme întâlnite la instalarea CSDK pe Linux

| Simptom | Cauză | Soluție |
|---|---|---|
| installer exit 45, `Prerequisite check ERROR: Missing shared library files libncurses.so.6, libelf.so.1` | lipsesc bibliotecile cerute de check-ul Informix | `libncurses6`, `libtinfo6`, `libelf1t64` |
| `NativeAPI failed to load` / `UnsatisfiedLinkError` | instalatorul e InstallAnywhere și are nevoie de JRE | `openjdk-21-jre-headless` |
| ultimul pas post-instalare întoarce cod ≠ 0 | comportament cunoscut al instalatorului în mod silențios | `|| true` + verificare că `incl/cli` există |

### 8.4 Rulare

```powershell
pwsh -File docker\test-on-linux.ps1          # build + run
pwsh -File docker\test-on-linux.ps1 -SkipBuild
```

Rezultat: **9/9 teste, 198 de aserțiuni, 0 eșecuri, ~2,2 s (include 1 s de sleep la testul de liveness)**.

Raportul se scrie în `test_results` dacă e dat `PDO_INFORMIX_REPORT_DSN` (și el,
în container, trebuie să folosească `host.docker.internal`):

```powershell
docker run --rm --add-host host.docker.internal:host-gateway `
    -e "PDO_INFORMIX_DSN=$dsn" -e "PDO_INFORMIX_REPORT_DSN=$dsn" `
    -e PDO_INFORMIX_USER=<user> -e PDO_INFORMIX_PASS=<password> `
    -v "${PWD}\test:/tests" pdo-informix-linux php report.php
```

---

## 9. Limitări cunoscute

| Limitare | Detaliu |
|---|---|
| PHP 8.6+ încă netestat | sursa folosește API-ul 8.5; de reverificat la 8.6 |
| `lastInsertId()` și `SERIAL8` | funcționează (valoarea e citită din `DBINFO` imediat după insert, vezi `ANALIZA.md` §3.4); dacă aplicația rulează ea însăși `SELECT dbinfo('serial8')` după insert, va primi `0` — folosiți `lastInsertId()` |
| stream-uri LOB după distrugerea statementului | doar primul chunk (8 KB) rămâne citibil, apoi `E_WARNING`; ține statementul viu cât citești |
| `fseek()` pe stream-urile LOB | înainte și înapoi (înapoi serveste din bufferul de octeți deja citiți, deci consumă memorie proporțional cu partea parcursă); `SEEK_END` și `fstat()` merg pentru `CLOB`/`BLOB`, iar pentru `TEXT`/`BYTE` numai după ce valoarea a fost parcursă o dată |
| parametri OUT/INOUT din proceduri | CSDK nu-i poate folosi (`-9752`, sau blocaj total în `SQLExecute()`); driverul îi refuză la `bindParam()` cu `IM001`, iar valorile se întorc cu `EXECUTE FUNCTION … RETURNING` |
| `CLOB` citit în mod binar | `CLOB`/`BLOB` sunt citite cu `SQL_C_BINARY` (singurul mod în care `SQLGetData` avansează), deci conversia de caractere a driverului nu se aplică |
| JSON/BSON ca parametri | necesită cast explicit prin `LVARCHAR` în SQL (`CAST(? AS LVARCHAR(n))::json`, `json_to_bson(...)`); la citire se întorc ca string, nu ca stream |
| BSON doar ca octeți | nu există mapare PDO pentru BSON; citirea se face cu `bson_valid()` / `::lvarchar` |
| `CHAR` | păstrează spațiile de umplere (semantică Informix) |
| Diacritice / UTF-8 | `21005 Inexact character conversion` dacă `DB_LOCALE`/`CLIENT_LOCALE` nu acoperă caracterele |
| `OPTOFC`, `OPTMSG`, `DELIMIDENT` | nu se pot seta după conectare (doar în DSN) |
| `tests/*.phpt` din PECL | nu rulează cu runner-ul din `test\`; necesită `run-tests.php` din php-src |
| Client SDK în repo | arhiva CSDK este licențiată IBM; folosită doar local, pentru imaginea de test |
| Contul folosit la teste | `<user>` / `<password>` (administrator) — inacceptabil în producție |

Lista completă, cu cauze și propuneri de remediere, este în
[`ANALIZA.md`](ANALIZA.md).

### 9.1 Versiuni de PHP verificate

Aceeași sursă din `src\` a fost compilată și testată pe trei versiuni minore de
PHP pe Windows, fără niciun patch suplimentar — diferă doar pachetul de dezvoltare
folosit de `extension\build.bat`:

| PHP | Pachet de dezvoltare | Suită | `run_id` |
|---|---|---|---|
| 8.1.12 NTS x64 | `php-devel-pack-8.1.12-nts-Win32-vs16-x64` | 198/198, 0 eșecuri, 2,70 s | `6ae46d6a-…` |
| 8.3.36 NTS x64 | `php-devel-pack-8.3.36-nts-Win32-vs16-x64` | 198/198, 0 eșecuri, 2,78 s | `6ae46d6d-…` |
| 8.5.11 NTS x64 | `php-8.5.11-devel-vs17-x64` | 198/198, 0 eșecuri, 2,76 s | `6ae46d70-…` |
| 8.5.12 NTS x64 (Linux, Docker) | `phpize` + GCC | 198/198, 0 eșecuri, 2,16 s | `6ae46da7-…` |

```bat
rem aceeași sursă, alt PHP: primul argument e pachetul de dezvoltare,
rem al treilea numele DLL-ului rezultat
build.bat "C:\php-devel\phpother\x\php-8.1.12-devel-vs16-x64" ^
          "C:\IBM\Informix_Client_SDK" php_pdo_informix_81.dll
```

Compilarea pentru 8.1 și 8.3 iese fără warning-uri în codul propriu, la fel ca
pentru 8.5.

---

## 10. Harta fișierelor

```
C:\path\to\pdo_informix
├─ DOCUMENTATIE.md            acest document
├─ ANALIZA.md                 MERGE, matrice de funcționalități, limitări, oportunități
├─ README.md                  prezentare scurtă + stare
├─ .dockerignore
├─ src\                       sursa extensiei
│  ├─ pdo_informix.c          intrarea modulului (+ corecția ZEND_FE_END)
│  ├─ informix_driver.c       driver PDO (conectare, execuție, atribute)
│  ├─ informix_statement.c    statement PDO (bind, fetch, LOB)
│  ├─ php_pdo_informix.h      versiunea, declarații
│  ├─ php_pdo_informix_int.h  structuri interne + include infxcli.h
│  ├─ config.m4 / config.w32  build Unix / Windows (PECL, neatinse)
│  ├─ package.xml / LICENSE / CREDITS / Makefile.frag
│  ├─ tests\*.phpt            testele oficiale PECL
│  ├─ php85-compat.patch      patch-ul de referință (4 hunk-uri)
│  └─ README.md
├─ extension\
│  ├─ php_pdo_informix.dll    DLL-ul pentru PHP 8.5.11 NTS x64
│  ├─ php_pdo_informix_83.dll idem, pentru PHP 8.3.36 NTS x64
│  ├─ php_pdo_informix_81.dll idem, pentru PHP 8.1.12 NTS x64
│  ├─ pdo_informix.lib
│  ├─ build.bat               build reproductibil
│  ├─ build.log               log-ul ultimei compilări
│  ├─ fix-csdk-odbc-headers.bat
│  ├─ php.ini.example
│  └─ README.md
├─ test\
│  ├─ bootstrap.php / config.php / run-tests.php / report.php
│  ├─ schema.sql
│  ├─ t01_extension.php … t07_lob_trigger.php
│  ├─ t08_merge.php           MERGE
│  ├─ t09_pdo_features.php    atribute, tipuri, LOB, cursoare
│  └─ README.md
└─ docker\
   ├─ Dockerfile
   ├─ test-on-linux.ps1
   ├─ csdk\clientsdk.tar      CSDK 4.50.12.12 Linux x86_64
   ├─ csdk\installer.properties
   ├─ etc\sqlhosts
   ├─ build-linux.log
   └─ README.md
```

Directorul de lucru pentru build (`C:\php-devel`) conține devel pack-ul
PHP și arhivele descărcate; nu face parte din livrabil.

---

## 11. Anexă — comenzi

```bat
:: compilare
cd "C:\path\to\pdo_informix\extension"
build.bat

:: mediu
set INFORMIXDIR=C:\IBM\Informix_Client_SDK
set INFORMIXSERVER=informix_server
set INFORMIXSQLHOSTS=C:\IBM\Informix\etc\sqlhosts.informix_server
set PATH=%INFORMIXDIR%\bin;%PATH%

:: teste
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\run-tests.php

:: teste + scriere în tabela teste
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\report.php

:: un singur test
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\t07_lob_trigger.php
```

```powershell
# Linux
pwsh -File docker\test-on-linux.ps1
```

```sql
-- rezultate
SELECT os_name, run_id, MIN(executed_at), COUNT(*), SUM(failures)
FROM test_results GROUP BY os_name, run_id ORDER BY 3 DESC;

-- obiectele demo
SELECT id, label, doc::lvarchar(200), bson_valid(payload) FROM pdo_t_lob ORDER BY id;
SELECT log_id, op, row_id, db_user, note, at_time FROM pdo_t_lob_log ORDER BY log_id;
```

---

## 12. Referințe

- PECL PDO_INFORMIX 1.3.7 — <https://pecl.php.net/package/PDO_INFORMIX>
- fork cu modificările de compatibilitate — <https://github.com/nahuelon/pdo_informix>
- PHP pentru Windows (binare, devel pack-uri, arhive) — <https://windows.php.net/downloads/>
- IBM Informix Client SDK — documentația IBM (CLI/ODBC, tipuri JSON/BSON)
















































