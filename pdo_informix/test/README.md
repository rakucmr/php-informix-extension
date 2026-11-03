# Teste pdo_informix

Teste funcționale care rulează împotriva unei baze Informix reale. Fiecare fișier
`t*.php` își creează propriile tabele (`pdo_t_*`), le șterge la final și iese cu
`0` (pass) sau `1` (fail).

## Configurare

Valorile implicite sunt în `config.php`:

```
dsn  = informix:server=informix_server;database=testdb;host=127.0.0.1;service=9088;protocol=olsoctcp;
user = (gol -> autentificare Windows)
pass = (gol)
```

Se pot suprascrie prin variabile de mediu:

```bat
set PDO_INFORMIX_DSN=informix:server=informix_server;database=testdb;host=127.0.0.1;service=9088;protocol=olsoctcp;
set PDO_INFORMIX_USER=<user>
set PDO_INFORMIX_PASS=<password>
```

## Rulare

Cu extensia încărcată din `php.ini`:

```bat
php run-tests.php
```

sau explicit, fără php.ini:

```bat
set PATH=C:\IBM\Informix_Client_SDK\bin;%PATH%
set INFORMIXDIR=C:\IBM\Informix_Client_SDK
set INFORMIXSERVER=informix_server
set INFORMIXSQLHOSTS=C:\IBM\Informix\etc\sqlhosts.informix_server

php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix run-tests.php
```

Un singur test:

```bat
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\t03_crud.php
```

Filtru pe nume:

```bat
php run-tests.php --filter=t03
```

## Ce acoperă

| Fișier | Verifică |
|---|---|
| `t01_extension.php` | extensia e încărcată, driverul `informix` e înregistrat, versiunea |
| `t02_connect.php` | conexiune, `PDO::ATTR_DRIVER_NAME`, `DBINFO()`, `SELECT` simplu |
| `t03_crud.php` | CREATE/INSERT/SELECT/UPDATE/DELETE, placeholdere poziționale și numite, `bindValue`, `fetchAll` |
| `t04_transactions.php` | `beginTransaction` / `commit` / `rollBack`, `inTransaction()` |
| `t05_errors.php` | `PDOException` + SQLSTATE pentru duplicat (23xxx) și tabel inexistent (42xxx) |
| `t06_types.php` | NULL, DECIMAL, VARCHAR, DATE, `columnCount`, `getColumnMeta` |
| `t07_lob_trigger.php` | `SERIAL8` + `JSON` + `BSON` + `BLOB`, trigger-e care jurnalizează INSERT/UPDATE/DELETE |
| `t08_merge.php` | `MERGE`: matched/not matched, sursă subquery, parametri, tranzacție, trigger-e |
| `t09_pdo_features.php` | atribute PDO și Informix (`PDO_INFORMIX_*`), `ATTR_CASE`, `quote()`, `lastInsertId`, tipuri native, LOB-uri (`TEXT`/`BYTE` citite direct, `CLOB`/`TEXT` peste 8 KB), cursoare (inclusiv `CURSOR_SCROLL`), `check_liveness`, erori |

## `t07_lob_trigger.php` - particularități Informix

Testul creează două tabele și trei trigger-e:

```
pdo_t_lob     (id SERIAL8, doc JSON, payload BSON, data BLOB, label VARCHAR(40))
pdo_t_lob_log (log_id SERIAL, op CHAR(6), row_id BIGINT, at_time DATETIME YEAR TO FRACTION(3),
               db_user VARCHAR(32), note LVARCHAR(120))
```

**Obiectele rămân în baza de date** după rulare (ca să poți inspecta jurnalul) și
testul este reluabil: dacă tabelele/trigger-ele există deja, le refolosește și
adaugă doar rânduri noi, cu etichete unice per rulare (`row-1-HHMMSS-xxxx`).
Curățare completă:

```bat
set PDO_INFORMIX_KEEP_LOB=0
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\t07_lob_trigger.php
```

Interogări utile după rulare:

```sql
SELECT id, label, doc::lvarchar(200), bson_valid(payload),
       CASE WHEN data IS NOT NULL THEN 'da' ELSE 'nu' END AS are_blob
FROM pdo_t_lob ORDER BY id;

SELECT log_id, op, row_id, db_user, note, at_time
FROM pdo_t_lob_log ORDER BY log_id;
```

Trei lucruri nu funcționează „din prima” și sunt tratate explicit în test:

1. **BLOB are nevoie de un sbspace** — `... PUT data IN (sbspace)`. Dacă instanța
   are alt nume de sbspace, testul recreează tabela fără clauză (se folosește
   `SBSPACENAME` din `onconfig`).
2. **Parametrii legați nu se convertesc direct în JSON/BSON**:
   `INSERT ... VALUES (?::json, ...)` dă `-9634 No cast from byte to json`.
   Soluția care merge:

   ```sql
   INSERT INTO pdo_t_lob (doc, payload, data, label)
   VALUES (CAST(? AS LVARCHAR(2000))::json,
           json_to_bson(CAST(? AS LVARCHAR(2000))::json),
           ?, ?)
   ```

   `?` merge direct doar pentru coloana `BLOB`.
3. **Trigger-ele au nevoie de `REFERENCING`** — `NEW.id` fără el dă
   `-8336 Undefined symbol (new)`:

   ```sql
   CREATE TRIGGER pdo_t_lob_upd UPDATE ON pdo_t_lob
     REFERENCING OLD AS o NEW AS n FOR EACH ROW
     (INSERT INTO pdo_t_lob_log (op, row_id, at_time, db_user, note)
      VALUES ('UPDATE', n.id, CURRENT, USER, n.label));
   ```

Verificări: id-uri SERIAL8 pozitive și distincte, JSON citit înapoi cu
`doc::lvarchar(200)`, BSON validat cu `bson_valid()` și comparat octet cu octet
(`payload::lvarchar(100)`, codificarea BSON pentru `{"b":2}`), BLOB prezent
(`data IS NOT NULL`), apoi jurnalul: 4 intrări în ordinea
INSERT, INSERT, UPDATE, DELETE, cu `row_id` corect, `note` = label-ul nou la
UPDATE și cel vechi la DELETE, `db_user` = utilizatorul conexiunii.

În plus, citirea **directă** a coloanelor `JSON`/`BSON` (`SELECT doc`, `SELECT
payload`): driverul le întoarce ca `string`, nu ca stream — `json_decode()`
funcționează direct, octeții BSON (inclusiv `0x00`) se păstrează identici, iar
un document JSON mai mare de 8 KB se întoarce identic. Rândul acela de 14 KB se
inserează într-o tranzacție anulată, ca jurnalul trigger-elor să rămână cu
exact 4 intrări.


## `t08_merge.php` - MERGE

Acoperă `MERGE` (IDS 12.10+): `WHEN MATCHED THEN UPDATE` + `WHEN NOT MATCHED THEN
INSERT`, doar una dintre ramuri, sursă subquery (`USING (SELECT ... FROM
systables ...)`), parametru legat în `SET`, `MERGE` într-o tranzacție urmată de
`rollBack()` și trigger-e declanșate de MERGE. `exec()` întoarce numărul total de
rânduri (actualizări + inserări). Tabelele `pdo_t_merge_target`,
`pdo_t_merge_source` și `pdo_t_merge_log` (plus două trigger-e) **rămân** în
bază; conținutul lor este resetat la fiecare rulare.

Limitări ale serverului (nu ale driverului): parametrul în lista de selecție a
sursei (`USING (SELECT ? ...)`) dă `-201`, iar parametrul în `FIRST`/`LIMIT` dă
`-11064`.


## `t09_pdo_features.php` - atribute și tipuri

Verifică `ATTR_*`, `ATTR_CASE` (`CASE_LOWER`/`CASE_UPPER`), `quote()`,
`lastInsertId()` (inclusiv `SERIAL8`/`BIGSERIAL`), tipurile native (DECIMAL,
MONEY, DATETIME, INTERVAL, BOOLEAN, BIGINT, LVARCHAR, CHAR), `getColumnMeta`,
LOB-urile (`BLOB`/`CLOB`/`TEXT`/`BYTE`, atât prin stream cât și citite direct,
inclusiv valori peste 8 KB), modurile de fetch, `SAVEPOINT`, cursoarele
(`CURSOR_FWDONLY` și `CURSOR_SCROLL`), `fseek()` pe stream-urile LOB,
`bindParam()` cu lungime maximă (înainte tratat ca parametru de ieșire), refuzul
explicit al parametrilor `INOUT`/`OUT`, procedurile stocate
(`EXECUTE FUNCTION … RETURNING`, `EXECUTE PROCEDURE`), `check_liveness` și
conexiunile persistente.

Secțiunea A2 acoperă atributele specifice Informix: constantele
`PDO_INFORMIX_*`, `setAttribute()`/`getAttribute()` pentru `FETCH_BUFFER_SIZE`
și `DEFERRED_PREPARE` (pe o conexiune separată, pentru că `DEFERRED_PREPARE`
schimbă momentul în care `SQLNumResultCols` are sens), plus `OPTOFC=1` dat din
DSN.

Note:

- `lastInsertId()` funcționează și pentru `SERIAL8`/`BIGSERIAL` (valoarea e
  citită din `DBINFO` imediat după `INSERT`; vezi `ANALIZA.md` §3.4);
- pentru un string legat la o coloană `CLOB` se folosește `PDO::PARAM_STR`
  (`PDO::PARAM_LOB` este pentru stream-uri; funcționează și cu string, dar nu
  mai modifică variabila apelantă după corecția din `src\`);
- `TEXT`/`BYTE` citite direct întorc un stream cu conținut (nu `NULL`), iar un
  `NULL` în bază rămâne `null` în PHP;
- stream-urile LOB trebuie închise (`fclose`) înainte de `closeCursor()`; dacă
  statementul este distrus primul, doar primul chunk (8 KB) mai poate fi citit;
- `CURSOR_SCROLL` funcționează pentru că driverul setează
  `SQL_INFX_ATTR_ENABLE_SCROLL_CURSORS` pe conexiune;
- `PDO_INFORMIX_OPTOFC`/`OPTMSG`/`DELIMIDENT` se pot doar citi; ele se
  stabilesc în DSN (`...;OPTOFC=1;`).

## Rezultate în baza de date (`report.php`)

`report.php` rulează toate testele, cronometrează fiecare fișier și scrie
rezultatul în tabela `test_results` din `testdb` (creată automat, DDL-ul de referință
este în `schema.sql`):

```bat
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix report.php
```

```
run_id: 6ae46d70-3dae-abf3-3bc0-642a36b7370e
server: IBM Informix Dynamic Server Version 14.10.FC12W12DE

TEST             REZ    ASSERTIONS   FAILED         MS
------------------------------------------------------
t01_extension    PASS            5        0        121
t02_connect      PASS            5        0        165
t03_crud         PASS           15        0        195
t04_transactions PASS            5        0       1845
t05_errors       PASS            5        0        164
t06_types        PASS           12        0        167
t07_lob_trigger  PASS           31        0        194
t08_merge        PASS           22        0        196
t09_pdo_features PASS           98        0       1428
```

Total: **9 teste, 198 de aserțiuni**, 0 eșecuri.

Suita trece identic pe PHP 8.1.12, 8.3.36 și 8.5.11 (Windows) și pe 8.5.12 în
containerul Linux; `report.php` scrie versiunea PHP în coloana `php_version`,
deci rulările se pot filtra:

```sql
SELECT php_version, os_name, SUM(assertions), SUM(failures)
FROM test_results GROUP BY php_version, os_name ORDER BY php_version;
```

Coloane: `id`, `run_id`, `executed_at`, `test_name`, `result`, `assertions`,
`failures`, `exit_code`, `duration_ms`, `os_name`, `php_version`, `ext_version`,
`server_name`, `db_name`, `os_user`, `message`.

Interogare rapidă a ultimelor rulări:

```sql
SELECT run_id, MIN(executed_at) AS started, COUNT(*) AS teste,
       SUM(failures) AS esecuri, SUM(duration_ms) AS ms
FROM test_results GROUP BY run_id ORDER BY started DESC;
```

Conexiunea de raportare se ia din `config.php` secțiunea `report`
(implicit `<user>` / `<password>`) și poate fi schimbată cu
`PDO_INFORMIX_REPORT_DSN`, `PDO_INFORMIX_REPORT_USER`,
`PDO_INFORMIX_REPORT_PASS`, `PDO_INFORMIX_REPORT_TABLE`.

## Rulare pe Linux

Aceleași fișiere rulează în container prin `..\docker\test-on-linux.ps1`
(vezi [`../docker/README.md`](../docker/README.md)).




























