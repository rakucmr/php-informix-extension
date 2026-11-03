# Analiză: MERGE și acoperirea PDO pe Informix 14.10

Documentul răspunde la trei întrebări:

1. funcționează `MERGE` prin `pdo_informix`?
2. ce alte lucruri nu funcționează (sau funcționează parțial)?
3. ce se poate îmbunătăți în extensie, pe baza a ce oferă Client SDK-ul?

Rezultatele se bazează pe teste rulate efectiv pe `IDS 14.10.FC12W12DE`
(instanța `informix_server`), cu extensia construită din `src\` și CSDK 4.50.FC12W12.
Testele care fixează comportamentul sunt [`test/t08_merge.php`](test/t08_merge.php)
și [`test/t09_pdo_features.php`](test/t09_pdo_features.php).

---

## 1. MERGE

### 1.1 Ce am testat

| Caz | Rezultat |
|---|---|
| `MERGE ... USING tabelă_sursă ... WHEN MATCHED THEN UPDATE + WHEN NOT MATCHED THEN INSERT` | ✔ `exec()` întoarce 3 (2 actualizări + 1 inserare) |
| Doar `WHEN MATCHED THEN UPDATE SET` (inclusiv expresii: `qty * 100`) | ✔ |
| Doar `WHEN NOT MATCHED THEN INSERT` | ✔ (0 rânduri dacă toate se potrivesc) |
| Sursă subquery: `USING (SELECT ... FROM systables WHERE ...)` | ✔ |
| Parametru legat în `SET t.qty = ?` (prepare + execute) | ✔ |
| `MERGE` în tranzacție + `rollBack()` | ✔ modificările sunt anulate |
| Trigger-e `INSERT`/`UPDATE` pe tabela țintă | ✔ declanșate de MERGE |
| `MERGE` fără niciun `WHEN` | ✖ `SQLSTATE 42000` / `-201` (corect) |
| `MERGE` către tabelă inexistentă | ✖ `SQLSTATE 42S02` / `-206` (corect) |
| Sursa după MERGE | neschimbată |

Exemplu minim care funcționează:

```sql
MERGE INTO pdo_t_merge_target t
USING pdo_t_merge_source s ON t.id = s.id
WHEN MATCHED THEN UPDATE SET t.name = s.name, t.qty = s.qty
WHEN NOT MATCHED THEN INSERT (id, name, qty) VALUES (s.id, s.name, s.qty);
```

cu parametru:

```php
$stmt = $pdo->prepare(
    'MERGE INTO pdo_t_merge_target t USING pdo_t_merge_source s ON t.id = s.id
     WHEN MATCHED THEN UPDATE SET t.qty = ?'
);
$stmt->execute([777]);   // rowCount() == 3
```

### 1.2 Ce nu merge la MERGE

| Caz | Eroare | Cauză |
|---|---|---|
| parametru în lista de selecție a sursei: `USING (SELECT ? AS id, ...)` | `-201` la `SQLPrepare` | Informix nu acceptă parametri în lista de selecție (limitare de server, nu de driver) |
| parametru în `FIRST`/`LIMIT` | `-11064` | la fel, limitare de server |

### 1.3 De ce „în extensia veche nu funcționa”

Extensia PECL 1.3.7 **știe** de MERGE: `informix_handle_doer` are comentariul
explicit „…was not an UPDATE, INSERT, DELETE, or MERGE statement” și tratează
`SQLRowCount == -1` ca 0 (`informix_driver.c:336-343`). Deci, pe un server care
suportă MERGE, `exec()` funcționează.

Explicațiile plauzibile pentru eșecurile raportate anterior:

1. **server vechi** — MERGE a apărut în IDS 12.10; pe 11.x sau mai vechi
   statementul nu există;
2. **rowCount** — dacă driverul ODBC vechi întorcea `-1` pentru MERGE,
   `PDO::exec()` întorcea `0` și părea că „nu s-a întâmplat nimic”, deși
   operațiile se aplicaseră;
3. **driver vechi (PHP 5.x / CSDK 3.x)** — binarul vechi nu poate fi încărcat în
   PHP 8.5, deci nu se poate compara direct; codul sursă actual tratează corect
   MERGE.

---

## 2. Matricea de funcționalități

Legendă: ✔ funcționează, ⚠ funcționează cu rezerve, ✖ nu funcționează.

| Funcționalitate | Stare | Observații |
|---|---|---|
| `MERGE` (toate formele de mai sus) | ✔ | inclusiv trigger-e și tranzacții |
| proceduri/funcții stocate (EXECUTE PROCEDURE/EXECUTE FUNCTION … RETURNING) | ✔ | parametri IN și valori RETURNING; OUT/INOUT nu întorc valoarea în PHP (vezi §4.4) |
| `INSERT`/`UPDATE`/`DELETE`, `rowCount()` pe DML | ✔ | |
| `rowCount()` pe `SELECT` | ✔ | **reparat** — valorile negative se normalizează la `0` |
| `lastInsertId()` pe `SERIAL` | ✔ | |
| `lastInsertId()` pe `SERIAL8`/`BIGSERIAL` | ✔ | **reparat** prin `DBINFO` citit imediat după insert — vezi §3.4 și §4.1 |
| `ATTR_CASE` (CASE_LOWER / CASE_UPPER/NATURAL), la conectare și la runtime | ✔ | **reparat** — `getAttribute()` întoarce acum constanta cerută |
| `ATTR_SERVER_VERSION` | ✔ | **implementat** — `DBINFO('version','full')` (ex. „IBM Informix Dynamic Server Version 14.10.FC12W12DE”) |
| `ATTR_CLIENT_VERSION` | ✔ | **reparat** — versiunea CSDK (`4.50.FC12W12`); versiunea extensiei: `phpversion('pdo_informix')` |
| `ATTR_TIMEOUT` | ✔ | **implementat** — `SENDTIMEOUT`/`RECVTIMEOUT`/`LOGIN_TIMEOUT` (set/get) |
| `ATTR_CONNECTION_STATUS` | ✔ | **implementat** — „Connected”/„Disconnected” |
| `ATTR_PERSISTENT` (+ `check_liveness`) | ✔ | **implementat** — o conexiune persistentă moartă este detectată printr-un `SELECT 1 FROM systables` (round trip), apoi PDO reconectează |
| atribute de tuning `PDO_INFORMIX_*` (`FETCH_BUFFER_SIZE`, `DEFERRED_PREPARE`, `LEAVE_TRAILING_SPACES`, `ENABLE_INSERT_CURSORS`, `SENDTIMEOUT`, `RECVTIMEOUT`) | ✔ | **implementat** — `setAttribute()`/`getAttribute()` |
| `PDO_INFORMIX_OPTOFC` / `OPTMSG` / `DELIMIDENT` | ✔ (citire) | se stabilesc doar din DSN (`...;OPTOFC=1;`); vezi §4.3 |
| `quote()` (apostrof, backslash, newline) | ✔ | |
| placeholdere poziționale `?` | ✔ | |
| placeholdere numite `:nume` | ✔ | |
| același placeholder numit de două ori | ✖ | `-11012` — limitare PDO cu prepare nativ |
| parametru în lista de selecție (`SELECT ?`) | ✖ | `-201` — limitare de server |
| `FETCH_ASSOC/NUM/OBJ/BOTH/NAMED/KEY_PAIR/COLUMN/CLASS`, `fetchObject`, `fetchColumn` | ✔ | |
| `ATTR_DEFAULT_FETCH_MODE` | ✔ | |
| `CURSOR_FWDONLY` | ✔ | |
| `CURSOR_SCROLL` (`FETCH_ORI_ABS/FIRST/LAST/REL`) | ✔ | **reparat** — vezi §3.3 |
| `beginTransaction`/`commit`/`rollBack`, `inTransaction` | ✔ | |
| `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` | ✔ | doar în tranzacție (altfel `-255`) |
| `EXECUTE PROCEDURE` cu `RETURN ... WITH RESUME` | ✔ | un singur set de rezultate |
| `nextRowset()` | ⚠ | întoarce `false` (Informix nu are mai multe seturi) |
| `TEXT`/`BYTE` — insert prin parametru | ✔ | |
| `TEXT` — citire directă (`SELECT c_text`) | ✔ | **reparat** — stream cu conținut; `NULL` rămâne `NULL` |
| `BLOB`/`CLOB` — insert prin `PARAM_LOB` (stream sau string) | ✔ | |
| `BLOB`/`CLOB` — citire prin stream (`bindColumn(..., PARAM_LOB)`) | ✔ | **reparat** — vezi §3.1 și §3.6 (inclusiv valori peste 8 KB) |
| `BLOB`/`CLOB`/`BYTE` — citire directă | ✔ | întoarce un stream (resource) cu conținutul corect |
| `CLOB`/`TEXT` mari (> 8 KB), conținut identic | ✔ | **reparat** — vezi §3.6 |
| `json` / `bson` ca parametri | ✖ | necesită `CAST(? AS LVARCHAR(n))::json`; `?::json` dă `-9634` |
| `bson` citit direct | ✖ | `bson_export_txt()` → tip `impexp` → `-9634`; se folosește `::lvarchar` / `bson_valid()` |
| `DECIMAL`, `MONEY`, `DATETIME YEAR TO FRACTION(5)`, `INTERVAL`, `BOOLEAN`, `BIGINT`, `LVARCHAR` | ✔ | ca string în PHP |
| `CHAR` | ⚠ | păstrează spațiile de umplere (semantică Informix) |
| diacritice / UTF-8 | ⚠ | `21005 Inexact character conversion` dacă `DB_LOCALE`/`CLIENT_LOCALE` nu acoperă caracterele |
| `SELECT` pe `systables`/`sysmaster` | ✔ | |

---

## 3. Ce am reparat

Optsprezece defecte reale, găsite prin teste, corectate în `src\` și acoperite de teste, plus o lărgire de tip (§3.4) și patru limitări eliminate (§3.19–§3.22).

### 3.1 Citirea CLOB printr-un stream se repeta la infinit

`lob_stream_read()` (`informix_statement.c:44`) nu detecta sfârșitul valorii:
`SQLGetData` pe o coloană `CLOB`/`LONGVARCHAR` întorcea aceleași octeți la
fiecare apel, iar `php_stream` nu ajungea niciodată la EOF. `stream_get_contents()`
intra într-o buclă infinită (observat: 13 caractere repetate de sute de ori).

Corecție: un flag `eof` în `lob_stream_data`, setat când `SQLGetData` întoarce
`SQL_NO_DATA` sau când citirea a fost parțială (semn că valoarea s-a terminat).
Test: `t09` — „CLOB citit prin stream (fără repetiții)” și „CLOB conținut identic”.

### 3.2 `bindValue($s, PDO::PARAM_LOB)` golea variabila

`stmt_parameter_post_execute()` ajusta lungimea string-ului legat pe baza lui
`transfer_length`. Pentru LOB-uri (`SQL_DATA_AT_EXEC`, valoare negativă) intra pe
ramura „transfer_length < 0” și scria `Z_STRLEN = 0` **în chiar string-ul
apelantului** (zend_string partajat), deci `$s` devenea `''` după `execute()`.

Corecție: blocul se aplică doar pentru parametri care nu sunt `PDO_PARAM_LOB`.
Test: `t09` — „bindValue cu PARAM_LOB nu trunchiază variabila”.

### 3.3 Cursoare scrollabile (`PDO::CURSOR_SCROLL`)

Driverul seta `SQL_ATTR_CURSOR_TYPE = SQL_CURSOR_DYNAMIC`, dar CSDK-ul cere și
`SQL_INFX_ATTR_ENABLE_SCROLL_CURSORS` (definit în `infxcli.h` ca 2254), altfel
`SQLFetchScroll(SQL_FETCH_ABSOLUTE)` eșuează cu `-11086 Fetch type out of range`.

Corecție: atributul este setat pe **conexiune** în `dbh_connect()` (pe statement
dă `-11073 Option type out of range`). Acum `FETCH_ORI_ABS/FIRST/LAST/REL`
funcționează. Test: `t09` — cele trei aserțiuni `CURSOR_SCROLL`.

### 3.4 `last_insert_id` pe 64 de biți și `SERIAL8`/`BIGSERIAL`

Două probleme separate:

1. Câmpul `conn_handle.last_insert_id` era `int` (32 de biți); a fost lărgit la
   `zend_long` și citit prin `SQLINTEGER`, ca să nu trunchieze valorile mari.
2. Pentru `SERIAL8`/`BIGSERIAL`, `SQL_GET_SERIAL_VALUE` întoarce **totdeauna 0**
   (vezi tabelul de probe din §4.1), deci `lastInsertId()` dădea `0`.

Soluția este cea folosită de driverul CodeIgniter pentru Informix (mulțumiri
utilizatorului): serverul raportează ultima valoare generată în sesiune prin
`DBINFO('sqlca.sqlerrd1')` (SERIAL), `DBINFO('serial8')` (SERIAL8) și
`DBINFO('bigserial')` (BIGSERIAL) — exact una dintre ele este non-zero, după
tipul ultimului `INSERT`.

Detaliu important, măsurat aici: valoarea este vizibilă **doar în primul
statement de după `INSERT`**; următorul `SELECT` (chiar identic) întoarce `0`.
De aceea driverul o citește imediat după execuția `INSERT`-ului
(`record_last_insert_id()` → `informix_session_serial_id()`), nu la apelul
`lastInsertId()`, și o ține în `conn_handle.last_insert_id`:

```
INSERT            -> SQL_GET_SERIAL_VALUE  (SERIAL: valoarea reală)
                  -> dacă e 0: SELECT dbinfo('sqlca.sqlerrd1') sc,
                                    dbinfo('serial8') s,
                                    dbinfo('bigserial') bs
                               FROM systables WHERE tabid = 1
                  -> max(sc, s, bs)
lastInsertId()    -> valoarea memorată
```

Un `INSERT` care nu atinge niciun rând nu schimbă valoarea anterioară (nu s-a
generat niciun id), iar interogarea nu se execută deloc în acest caz.

Test: `t09` — „lastInsertId() după INSERT pe SERIAL8”, „… crește la al doilea
INSERT SERIAL8”, „… după INSERT pe BIGSERIAL”, „INSERT fără rânduri păstrează
lastInsertId anterior”, „lastInsertId() se repetă cu aceeași valoare”.

### 3.5 `TEXT`/`BYTE` citite direct întorceau `NULL` sau `-11066`

`stmt_get_col()` deschidea un stream LOB, dar driverul nu avea cum să afle dacă
valoarea e `NULL` fără să consume date: proba cu `SQLGetData(buf=NULL, count=0)`
dă `-11066`, iar un `SQLGetData` cu 8 octeți urmat de o citire mare repornea
valoarea de la început pentru `CLOB`/`BYTE`.

Corecție: primul chunk se citește **în `create_lob_stream()`** cu bufferul
complet (`LOB_BUFFER_SIZE`, 8 KB) și se reia din `pending` la citire. Așa:
`SQL_NULL_DATA` este detectat exact (→ `null` în PHP), iar secvența de apeluri
`SQLGetData` rămâne identică cu cea care funcționa deja pentru `BLOB`/`CLOB`.
Test: `t09` — „TEXT direct …”, „BYTE citit direct”, „TEXT direct: NULL rămâne NULL”.

### 3.6 CLOB-urile mai mari de 8 KB se citeau greșit (se repetă primul chunk)

Simptom: `stream_get_contents()` pe un `CLOB` de peste 8 KB nu se termina
(pentru `$pdo->query(...)` se oprea la 8 KB, cu un `E_WARNING`), iar un `CLOB`
de exact 8191 de octeți intra în buclă infinită. `BLOB` și `TEXT` funcționau.

Cauza, stabilită cu un program C (`clobprobe.c`): pentru **smart LOBs**
(`CLOB`/`BLOB`) `SQLGetData` cu `SQL_C_CHAR` **nu avansează** — întoarce mereu
primii octeți, iar indicatorul este *lungimea totală*. Cu `SQL_C_BINARY`
avansează corect, iar indicatorul devine *lungimea rămasă*. Pentru
`TEXT`/`BYTE` (simple large objects) este exact invers: `SQL_C_BINARY` întoarce
`SQL_NULL_DATA`.

Corecție: `SQLColAttribute(SQL_COLUMN_TYPE_NAME)` este citit la descrierea
coloanei; dacă numele real este `CLOB` sau `BLOB`, coloana este marcată
`smart_lob` și citită cu `SQL_C_BINARY` (indicator = octeți rămași), altfel se
păstrează logica pe `SQL_C_CHAR` cu „citire scurtă = sfârșit de valoare”.
Test: `t09` — „CLOB mare (40 000 octeți) citit integral”, „TEXT mare …”.

### 3.7 Ciclul de viață al stream-urilor LOB (use-after-free)

Stream-ul păstra un pointer la `stmt_handle`; dacă statementul era distrus primul
(`$pdo->query(...)->fetchColumn()`), următoarea citire apela `SQLGetData` pe un
handle eliberat → acces la memorie liberă.

Corecție: un token partajat (`lob_stream_token`: `alive` + `refs`) ținut de
statement și de fiecare stream. La distrugerea statementului tokenul este marcat
`alive = 0`, iar stream-urile nu mai ating handle-ul; chunk-ul deja buffered
rămâne citibil, iar dacă valoarea nu era complet citită se emite un `E_WARNING`
(„keep the PDOStatement alive…”), nu o trunchiere silențioasă.
Test: `t09` — „stream LOB după distrugerea statementului (chunk buffered)”.

### 3.8 `rowCount()` pe `SELECT` întorcea `-1`

`SQLRowCount` raportează `-1` pentru un cursor deschis; PDO nu se aștepta la
valori negative. Corecție: `stmt->row_count` se normalizează la `0` pentru orice
valoare negativă. Test: `t09` — „rowCount pe SELECT”.

### 3.9 `getAttribute(PDO::ATTR_CASE)` raporta mereu `CASE_UPPER`

Driverul stoca un `enum pdo_case_conversion` (`NATURAL = 0`, `UPPER = 1`,
`LOWER = 2`), dar îl returna ca `zend_long` fără nicio conversie, iar
`dbh_connect()` nu inițializa deloc valoarea implicită.

Corecție: valoarea implicită este setată explicit la conectare (`PDO_CASE_UPPER`,
comportamentul Informix), iar `getAttribute()` întoarce exact constanta cerută.
Test: `t09` — „getAttribute(ATTR_CASE) după CASE_LOWER/CASE_UPPER”.

### 3.10 Atribute PDO lipsă (`SERVER_VERSION`, `CLIENT_VERSION`, `TIMEOUT`, `CONNECTION_STATUS`)

- `ATTR_SERVER_VERSION` → `SQLGetInfo(SQL_DBMS_VER)` raportează ceva inutil la
  Informix („09.59.0000 FC12W12”), așa că driverul rulează o dată
  `SELECT DBINFO('version','full') FROM systables WHERE tabid = 1` și memorează
  rezultatul în `conn_handle.server_version`;
- `ATTR_CLIENT_VERSION` → `SQLGetInfo(SQL_DRIVER_VER)` (CSDK-ul), cu spațiile
  inițiale eliminate; versiunea extensiei rămâne în `phpversion('pdo_informix')`;
- `ATTR_TIMEOUT` → `SQL_INFX_ATTR_SENDTIMEOUT`/`RECVTIMEOUT` **plus**
  `SQL_ATTR_LOGIN_TIMEOUT`. Atenție: CSDK 4.50 acceptă aceste atribute doar cu
  `SQL_IS_UINTEGER`; cu `SQL_IS_INTEGER` întoarce `HY105`/`-11085` (verificat cu
  un program C separat, vezi `ANALIZA.md` §5);
- `ATTR_CONNECTION_STATUS` → „Connected”/„Disconnected”.

Test: `t09` — aserțiunile din secțiunea A.

### 3.11 Atribute de tuning expuse ca `PDO_INFORMIX_*` și `check_liveness`

Constante noi (în `MINIT`): `PDO_INFORMIX_OPTOFC`, `OPTMSG`,
`FETCH_BUFFER_SIZE`, `DEFERRED_PREPARE`, `LEAVE_TRAILING_SPACES`,
`ENABLE_INSERT_CURSORS`, `DELIMIDENT`, `SENDTIMEOUT`, `RECVTIMEOUT`.
`setAttribute()`/`getAttribute()` le acceptă pe cele pe care CSDK le permite
după conectare. Test: `t09` — secțiunea A2.

`check_liveness` a fost implementat tot aici: `SQLGetInfo` răspunde din cache și
întoarce succes chiar și după ce sesiunea a fost omorâtă, așa că verificarea
rulează `SELECT 1 FROM systables WHERE tabid = 1` pe un statement temporar. PDO
apelează funcția doar când refolosește o conexiune persistentă. Verificat real în
`t09`: sesiunea persistentă este omorâtă de pe altă conexiune cu
`sysadmin:task('onmode','-z',sid)`, iar următorul `new PDO(..., ATTR_PERSISTENT)`
primește o sesiune nouă și funcționează.

### 3.12 Parametrii `INPUT_OUTPUT` erau detectați greșit

`informix_stmt_param_hook()` verifica tipul parametrului așa:

```c
if (curr->param_type & PDO_PARAM_INPUT_OUTPUT > 0) { ... }
```

`>` are prioritate mai mare decât `&`, deci expresia se citea
`param_type & (PDO_PARAM_INPUT_OUTPUT > 0)`, adică `param_type & 1` — un test al
bitului 0, nu al flagului `PDO_PARAM_INPUT_OUTPUT` (`0x80000000`). Pentru
`PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT` ieșea întâmplător corect
(`…0001 & 1`), dar pentru orice alt tip (de ex. `PARAM_STR | INPUT_OUTPUT`)
rezultatul era mereu fals. Corectat cu paranteze explicite:

```c
if ((curr->param_type & PDO_PARAM_INPUT_OUTPUT) != 0) { ... }
```

Valoarea de ieșire tot nu este copiată înapoi în variabila PHP — vezi §4.4.

### 3.13 Warning-uri de compilare în codul propriu (24 → 0)

Build-ul cu MSVC semnala 24 de warning-uri în fișierele noastre (`C4244`,
`C4267`, `C4018`, `C4146`, `C4554`, `C4028`). Printre ele erau și două semnături
care nu corespundeau cu cele din `pdo_dbh_methods` / `pdo_stmt_methods`
(`long attr` în loc de `zend_long attr`, `long offset` / `long colno` în loc de
`zend_long`), ceea ce pe Win64 însemna trunchierea argumentului la 32 de biți.
Toate au fost corectate cu semnăturile din antetul PDO și cu `cast`-uri
explicite (`SQLUSMALLINT`, `SQLINTEGER`, `SQLSMALLINT`, `SQLLEN`); build-ul iese
acum fără niciun warning din `informix_driver.c`, `informix_statement.c` și
`pdo_informix.c` (rămân doar cele din antetele PHP).

### 3.14 Mesajele de eroare conțineau fișier:linie din driver

Orice eroare ieșea ca
`… (SQLPrepare[-206] at informix_driver.c:148) ISAM: -111`: numele funcției
interne și locația din sursă ajungeau în logurile aplicației. Acum mesajul
implicit este doar textul serverului, plus codul ISAM, care este util:

```
SQLSTATE[42S02]: Base table or view not found: -206 [Informix][Informix ODBC
Driver][Informix]The specified table (x) is not in the database. (ISAM: -111)
```

Detaliile de driver s-au mutat în spatele unei opțiuni de configurare, implicit
oprită:

```php
ini_set('pdo_informix.error_detail', '1');
// … (SQLPrepare[-206] at informix_driver.c:148) ISAM: -111
```

`pdo_informix.error_detail` (`PHP_INI_ALL`, implicit `0`) apare și în
`phpinfo()`. Test: `t09` — „mesajul de eroare nu conține fișier:linie”,
„pdo_informix.error_detail=1 adaugă fișier:linie”.

### 3.15 `fseek()` pe stream-urile LOB

`php_stream_ops.Seek` era `NULL`, deci `fseek()` eșua și nu se putea sări la un
offset fără să citești tot până acolo. `SQLGetData` merge doar înainte, așa că
implementarea este „forward-only”: stream-ul reține poziția curentă, iar
`fseek()` înainte citește și aruncă diferența. `fseek()` înapoi (sau `SEEK_END`,
unde lungimea nu se știe înainte de citire) întoarce `-1` și emite un `E_WARNING`
explicit, ca să nu pară o eroare mută.

```php
$lob = $pdo->query('SELECT c_clob FROM t')->fetchColumn();
fread($lob, 16);           // poziția 16
fseek($lob, 1000);         // sari la 1000 (se citește și se aruncă)
fread($lob, 16);           // octeții de la 1000
fseek($lob, 0);            // -1: nu se poate întoarce
```

Test: `t09` — „stream LOB: fseek înainte (SEEK_SET) reușește”, „… datele de la
offsetul cerut”, „… fseek înapoi e refuzat”.

### 3.16 `fstat()` și `SEEK_END` pe stream-urile LOB

`php_stream_ops.Stat` era `NULL`, deci `fstat()` (și orice cod care cere
lungimea) nu funcționa. Pentru **smart LOB-uri** lungimea se cunoaște din primul
`SQLGetData`: indicatorul raportează exact câți octeți au rămas, adică totalul.
Driverul o memorează (`lob_stream_data.total_len`) și o raportează:

`php
 = ->query('SELECT c_clob FROM t')->fetchColumn();
fstat()['size'];        // 40000 — fără să citească nimic
fseek(, 0, SEEK_END);  // acum merge, pentru că lungimea e cunoscută
`

Pentru `TEXT`/`BYTE` (simple large objects) lungimea nu se poate afla dinainte
— indicatorul descrie doar chunk-ul curent — așa că `fstat()` întoarce `false`
până la citirea completă; după `stream_get_contents()` raportează dimensiunea
reală. `SEEK_END` întoarce `-1` în acest caz.

Test: `t09` — „stream LOB: fstat dă lungimea înainte de citire”, „stream LOB:
SEEK_END folosește lungimea cunoscută”.

### 3.17 Al patrulea argument al lui `bindParam()` făcea parametrul de ieșire

Clasificarea parametrilor era:

```c
if (param_type & PDO_PARAM_INPUT_OUTPUT)  INPUT_OUTPUT;
else if (max_value_len <= 0)              INPUT;
else                                      OUTPUT;   /* ← greșit */
```

Adică orice `bindParam($p, $v, PDO::PARAM_STR, 64)` — unde al patrulea argument
este doar un indiciu de lungime — ajungea la server ca parametru **de ieșire**, iar
serverul răspundea cu `-254` („Too many or too few parameters”) sau `-11012`
(„Wrong number of parameters”). Reparat: `INPUT` implicit, `INPUT_OUTPUT` doar
când bitul `PDO_PARAM_INPUT_OUTPUT` este setat, ca în restul driverelor PDO.

În plus, vechiul cod de „pregătire a bufferului de ieșire” scria în capul unui
`zend_string` (`Z_STRLEN_P(parameter) = transfer_length`, după un `erealloc` pe
`Z_PTR_P`), ceea ce corupea heap-ul. Acum parametrii de ieșire folosesc un buffer
al driverului (`param_node.output_buf`), iar valoarea se copiază înapoi în
variabila PHP cu `ZVAL_STR`/`ZVAL_LONG`.

### 3.18 Parametrii `INOUT`/`OUT` sunt refuzați explicit

Înainte, legarea unui parametru `PDO::PARAM_INPUT_OUTPUT` nu producea nici
valoare, nici eroare: variabila rămânea cu valoarea de intrare. Verificat acum cu
CSDK 4.50.FC12W12, pe proceduri SPL cu `OUT` și `INOUT`:

| Legare | Rezultat |
|---|---|
| `EXECUTE PROCEDURE p(?)`, `SQL_PARAM_INPUT_OUTPUT` | eroare `-9752` „Argument must be a Statement Local Variable” |
| `{CALL p(?)}`, `SQL_PARAM_INPUT_OUTPUT` | `SQLExecute()` **blochează apelul** (nu se întoarce) |
| `{CALL p(?)}`, `SQL_PARAM_OUTPUT` | idem, blochează |

Un apel care atârnă este mai rău decât o eroare, așa că driverul refuză parametrul
la `bindParam()` (evenimentul `PDO_PARAM_EVT_ALLOC`), cu un mesaj care trimite la
soluția care funcționează:

```
SQLSTATE[IM001]: Driver does not support this function: 1 Informix does not
return output parameter values; use EXECUTE FUNCTION ... RETURNING instead of
INOUT/OUT procedure parameters
```

Refuzul trebuie făcut la `bindParam()`, nu la execuție: la `EXEC_PRE` PDO ignoră
rezultatul funcției de hook și ajunge oricum la `SQLExecute()`.

Test: `t09` — „parametrii INOUT sunt refuzați explicit”, „mesajul pentru INOUT
trimite la RETURNING”.

### 3.19 `fseek()` înapoi pe stream-urile LOB

`SQLGetData` merge doar înainte, așa că `fseek()` putea doar să sară peste octeți,
nu să revină. Acum driverul ține în `lob_stream_data.history` octeții deja
transferați (citiți sau săriți) și servește citirile din memorie cât timp poziția
curentă e în urmă:

```php
$lob = $stmt->fetchColumn(0);   // CLOB
fread($lob, 20);
fseek($lob, 5);                 // 0 — se întoarce în partea deja citită
fread($lob, 10);                // exact octeții de la offsetul 5
fseek($lob, -100);              // -1 — înainte de începutul valorii
```

Memoria crește doar cu datele pe care aplicația le-a cerut oricum (bufferul se
dublează la nevoie) și se eliberează la `fclose()`. Un `fseek()` înainte rămâne
gratuit: se citesc și se aruncă octeții, fără să fie ținuți minte.

Test: `t09` — „stream LOB: fseek înapoi (SEEK_SET) reușește”, „… datele de la
început după revenire”, „… fseek înapoi relativ (SEEK_CUR)”, „… fseek înainte de
început e refuzat”.

### 3.20 `JSON`/`BSON` se întorc ca string, nu ca stream

Ambele tipuri sosesc din CSDK ca smart LOB, așa că driverul le lega ca
`PDO_PARAM_LOB` și `fetch()` întorcea un **resource**. Adică `json_decode($row['doc'])`
nu funcționa, iar un BSON cu octeți `0x00` trebuia citit manual prin stream.

Acum `stmt_get_column_descriptor()` recunoaște numele de tip `JSON`/`BSON`
(`SQL_COLUMN_TYPE_NAME`), iar `informix_stmt_get_col()` citește valoarea prin
stream și o întoarce ca `zend_string`:

| Coloană | Înainte | Acum |
|---|---|---|
| `JSON` | `resource` (stream) | `string` cu textul JSON, `json_decode()` merge direct |
| `BSON` | `resource` (stream) | `string` binar, NUL-urile păstrate (`bin2hex` = octeții originali) |

`getColumnMeta()['native_type']` rămâne `JSON`/`BSON`. Valorile mari merg și
ele: un document de 13 920 de octeți se întoarce identic, iar un BSON de
14 327 de octeți (2 012 NUL-uri) trece `bson_valid()`.

Test: `t07` — „JSON citit direct e string”, „JSON direct are conținutul corect”,
„json_decode funcționează pe valoarea JSON”, „BSON citit direct e string binar”,
„BSON direct păstrează octeții (inclusiv NUL-uri)”, „JSON mare citit direct,
identic”.

### 3.21 `next_rowset()` raportează eroarea, nu „nu mai sunt seturi”

`informix_stmt_next_rowset()` întorcea `FALSE` pentru orice rezultat care nu era
succes, inclusiv `SQL_ERROR`. PDO interpretează `FALSE` ca „nu mai există seturi
de rezultate”, deci o eroare reală dispărea. Acum `SQL_NO_DATA` rămâne `FALSE`
(cazul normal), iar orice altceva ridică `IM001` cu mesajul „the driver could not
move to the next result set”.

### 3.22 Pool de conexiuni CSDK — opt-in, cu rezultat măsurat

Pool-ul CSDK refolosește o conexiune fizică **doar între conexiuni logice care
împart același environment**. Până acum fiecare `PDO::connect()` își aloca
propriul `SQLHENV`, deci un pool cu o singură conexiune — zero reutilizare.

Driverul ține acum un environment partajat (refcount) când
`pdo_informix.connection_pooling=1`, îl dă tuturor conexiunilor și îl eliberează
la ultima închidere; setează `SQL_ATTR_CONNECTION_POOLING` (`SQL_CP_ONE_PER_HENV`)
și, dacă CLI-ul refuză atributul standard, `SQL_INFX_ATTR_CONNECTION_POOLING`
(`SQL_INFX_CP_ON`).

Măsurat cu CSDK 4.50.FC12W12 + IDS 14.10.FC12W12DE, cinci conectări succesive:
`DBINFO('sessionid')` a fost **diferit de fiecare dată** (1558…1562) și timpul de
conectare a rămas ~30 ms, adică CLI-ul nu refolosește sesiunea în această
configurație. Directiva rămâne utilă ca punct de pornire (environment-ul partajat
e corect și testează codul de refcount), dar pentru performanță se folosește
`PDO::ATTR_PERSISTENT`, care funcționează și e testat în `t09`.

Test: `t09` — „pdo_informix.connection_pooling e oprit implicit”, „… nu se poate
schimba la rulare”.

#### 3.22.1 Reîncercat prin Driver Manager-ul ODBC — tot fără reutilizare

Pe stația de dezvoltare driverul Informix este înregistrat în ODBCINST.INI ca
`IBM INFORMIX ODBC DRIVER (64-bit)` → `iclit09b.dll`, așa că s-a putut testa și
varianta cu Driver Manager-ul Microsoft (`build.bat … dm`, care leagă
`odbc32.lib`/`odbccp32.lib`).

Rezultate, fiecare verificat cu un program C separat, fără PHP, ca să nu se
amestece cauzele:

1. **Driver Manager-ul funcționează** pentru conectare: `SQLDriverConnect` cu
   `DRIVER={IBM INFORMIX ODBC DRIVER (64-bit)};server=informix_server;…` întoarce
   `SQL_SUCCESS`, iar varianta `dm` a extensiei trece **aceleași 198 de
   aserțiuni**.
2. **Atributele proprii CLI nu pot fi setate prin Driver Manager.**
   `SQLSetConnectAttr(hdbc, SQL_INFX_ATTR_LO_AUTOMATIC /* 2262 */, …)` iese cu
   `0xC0000005` (access violation), reprodus și în C pur. De aceea varianta `dm`
   compilează acele apeluri doar în build-ul direct (`#ifndef
   PDO_INFORMIX_USE_ODBC_DM`); fără ele driverul funcționează normal.
3. **Pool-ul Driver Manager-ului nu refolosește sesiunea.** Cu
   `SQL_ATTR_CONNECTION_POOLING` setat pe environment (`rc = 0`, deci atributul
   e acceptat) și patru conectări succesive identice, `DBINFO('sessionid')` a
   fost diferit de fiecare dată — atât cu `SQL_CP_ONE_PER_HENV`, cât și cu
   `SQL_CP_ONE_PER_DRIVER`. Un DSN creat cu `SQLConfigDataSource` a putut fi
   înregistrat, dar testul cu `SQLConnect` pe el a ieșit cu `0x80000003`, așa că
   varianta cu DSN a rămas neconcludentă.

Concluzia practică nu se schimbă: pentru reutilizarea sesiunii se folosește
`PDO::ATTR_PERSISTENT`; `pdo_informix.connection_pooling` rămâne o opțiune
experimentală, care nu aduce câștig cu CSDK 4.50 pe Windows.

---

## 4. Limitări rămase și soluții

### 4.1 `lastInsertId()` pentru `SERIAL8` / `BIGSERIAL` — reparat

A fost singurul defect cunoscut despre care credeam că nu poate fi reparat, pentru
că CSDK-ul nu expune valoarea generată pentru `SERIAL8`. Verificat direct în C
(`serialprobe.c`), nu doar din PHP:

| Încercare | Rezultat |
|---|---|
| `SQLGetStmtAttr(SQL_GET_SERIAL_VALUE)` pe `SERIAL8`, buffer `SQLINTEGER` | `0` |
| același apel cu buffer de 8 octeți (`SQL_IS_POINTER`, `SQL_IS_UINTEGER`) | `0` |
| `SQL_INFX_ATTR_LONGID` (2251) setat pe **conexiune** înainte de insert | `0` |
| `SQL_INFX_ATTR_LONGID` setat pe **statement** | `-11073` (opțiune nesuportată) |
| `INSERT ... RETURNING id` | `-201` (sintaxă invalidă pe 14.10) |

Niciun atribut ODBC nu raportează valoarea pe 64 de biți. Serverul însă o
raportează, prin `DBINFO`, în **primul statement de după insert** (vezi §3.4):

| Apel, imediat după insert | `SERIAL` | `SERIAL8` | `BIGSERIAL` |
|---|---|---|---|
| `DBINFO('sqlca.sqlerrd1')` | valoarea | `0` | `0` |
| `DBINFO('serial8')` | `0` | valoarea | `0` |
| `DBINFO('bigserial')` | `0` | `0` | valoarea |
| același set de apeluri la **al doilea** statement | `0` | `0` | `0` |

Driverul citește deci valoarea imediat după `INSERT` și o memorează;
`lastInsertId()` întoarce corect id-urile `SERIAL`, `SERIAL8` și `BIGSERIAL`.
Rămân valabile doar observațiile de mai jos:

- valoarea este „ultimul id generat în sesiune”, nu „ultimul id din tabela X” —
  argumentul `$name` al lui `lastInsertId()` este ignorat (ca în driverul
  CodeIgniter);
- dacă aplicația rulează ea însăși `SELECT dbinfo('serial8') ...` după insert,
  va primi `0`, pentru că driverul a consumat deja acel „prim statement”.
  Folosiți `lastInsertId()`.

### 4.2 `TEXT` / `BYTE` (simple large objects)

Cu `SQL_INFX_ATTR_ODBC_TYPES_ONLY = SQL_TRUE` (setat de driver), `TEXT`/`BYTE`
sunt raportate ca `LONGVARCHAR`/`LONGVARBINARY`. Citirea directă **funcționează**
acum (stream, vezi §3.5), iar stream-ul știe să sară înainte (vezi §3.15). Rămâne o
singură limitare de design:

- un stream ține locul unui `SQLGetData` în curs: dacă statementul e distrus
  înainte de citirea completă, doar primul chunk (8 KB) mai poate fi citit.

### 4.3 Costuri/optimizări neexpuse

Atributele de tuning sunt acum accesibile (`PDO_INFORMIX_*`, vezi §3.11), dar
CSDK-ul le împarte în două categorii (verificat cu `attrprobe.c`):

| Atribut | Se poate seta după conectare? | Observații |
|---|---|---|
| `SQL_INFX_ATTR_FETCH_BUFFER_SIZE` (2258) | ✔ | valoarea minimă acceptată: 4096 |
| `SQL_INFX_ATTR_DEFERRED_PREPARE` (2265) | ✔ | `1` mută prepare-ul la execute; `SQLNumResultCols` înainte de execute dă `-11067` |
| `SQL_INFX_ATTR_LEAVE_TRAILING_SPACES` (2252) | ✔ (acceptat) | pe acest server nu schimbă umplerea `CHAR`; se pune din DSN |
| `SQL_INFX_ATTR_ENABLE_INSERT_CURSORS` (2255) | ✔ | pentru `INSERT ... WHERE CURRENT OF` |
| `SQL_INFX_ATTR_SENDTIMEOUT` / `RECVTIMEOUT` (2281/2282) | ✔ | **doar** cu `SQL_IS_UINTEGER` |
| `SQL_INFX_ATTR_OPTOFC` (2259) | ✖ | `-11016 Connection in use`; se pune în DSN: `...;OPTOFC=1;` |
| `SQL_INFX_ATTR_OPTMSG` (2260) | ✖ | `-11097` („not implemented” pe acest CSDK) |
| `SQL_INFX_ATTR_DELIMIDENT` (2273) | ✖ | `-11085` la runtime (așteaptă valoare string / DSN) |

Neexpuse în continuare (efort mare, folosite rar): `CONNECTION_POOLING` (2293),
`PAM_*` (2266-2272), `OPEN_SMART_TRIGGER` (2284), restul familiei de smart
triggers.

### 4.4 Alte nepotriviri

| Problemă | Detaliu | Efort |
|---|---|---|
| `ATTR_TIMEOUT` și `OPTOFC` în DSN | `PDO::ATTR_TIMEOUT` se poate seta doar după conectare; `OPTOFC` doar din DSN | S |
| `next_rowset()` | întoarce mereu `false`; ar putea raporta `IM001` | S |
| `get_driver_methods` | `NULL`; nu există metode specifice driverului | S |
| parametri `OUT`/`INOUT` din proceduri | CSDK nu-i poate folosi: `-9752`, sau blocaj total în `SQLExecute()`; driverul îi refuză la `bindParam()` (vezi §3.18), iar valorile se întorc cu `EXECUTE FUNCTION … RETURNING` | imposibil prin CSDK |
| `fstat()` pe `TEXT`/`BYTE` înainte de citire | lungimea se află doar citind valoarea, așa că `fstat()` întoarce `false` până la EOF (pentru `CLOB`/`BLOB` merge din prima); `SEEK_END` se poate folosi după o parcurgere | — |

---

## 5. Ce spune SDK-ul

`infxcli.h` (CSDK 4.50.FC12W12) are doar 367 de linii și, pe Windows, se rezumă
la:

```c
#ifdef _WINDOWS_
#include "sql.h"
#include "sqlext.h"
#else
#include "infxsql.h"
#endif
```

plus ~70 de atribute specifice Informix (`SQL_INFX_ATTR_*`) și câteva funcții
(`SQLGetInfo` cu `SQL_INFX_*`). Suprafața ODBC propriu-zisă vine din Windows SDK
(`sql.h`, `sqlext.h`, `sqltypes.h`, `sqlucode.h`, `odbcinst.h`), de unde și
capcana de build descrisă în `DOCUMENTATIE.md` §4.3.

Concluzia: driverul PDO nu are nevoie de API-uri ascunse; îi lipsesc doar
mapări ale atributelor standard (`SQL_DBMS_VER`, `SQL_DRIVER_VER`,
`SQL_ATTR_LOGIN_TIMEOUT`) și ale atributelor Informix de mai sus.

Pentru verificări am scris patru programe C mici (în `C:\php-devel\`),
compilate direct cu `cl` + `iclit09b.lib`, ca să nu ghicim comportamentul CSDK:

| Program | Ce a stabilit |
|---|---|
| `attrprobe.c` | `SENDTIMEOUT`/`RECVTIMEOUT` cer `SQL_IS_UINTEGER`; `LOGIN_TIMEOUT` merge cu `SQL_IS_INTEGER`; `SQL_DBMS_VER` = „09.59.0000 FC12W12”; `DBINFO('version','full')` = versiunea reală |
| `serialprobe.c` | `SQL_GET_SERIAL_VALUE` și `DBINFO('sqlca.sqlerrd1')` întorc `0` pentru `SERIAL8`; `LONGID` nu ajută; `INSERT ... RETURNING` nu e sintaxă validă |
| `lobprobe.c` | `SQLGetData` pe `TEXT` continuă corect după o citire parțială; `SQL_NULL_DATA` este raportat corect când bufferul e real; `SQLGetData(buf=NULL, count=0)` dă `-11066` |
| `clobprobe.c` | pentru `CLOB`/`BLOB` (smart LOBs) `SQL_C_CHAR` **nu avansează** și raportează lungimea totală; `SQL_C_BINARY` avansează și raportează lungimea rămasă; pentru `TEXT`/`BYTE` `SQL_C_BINARY` întoarce `SQL_NULL_DATA`; `SQLColAttribute(SQL_COLUMN_TYPE_NAME)` dă `CLOB`/`BLOB`/`TEXT` |

---

## 6. Cum se verifică

```bat
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\t08_merge.php
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix test\t09_pdo_features.php
```

- `t08_merge.php` — 22 de aserțiuni despre MERGE;
- `t09_pdo_features.php` — 98 de aserțiuni despre atribute, tipuri, LOB-uri,
  cursoare, tranzacții, erori și conexiuni persistente.

Suita completă (9 fișiere) are acum **198 de aserțiuni**, toate PASS pe Windows
și pe Linux (tabela `teste`, `run_id` `6ae46d70` = Windows, `6ae46da7` = Linux).

Aceeași sursă a fost compilată și rulată și pe **PHP 8.1.12** și **8.3.36**
(pachete de dezvoltare NTS de la windows.php.net, fără patch-uri): 198/198, 0
eșecuri în ambele cazuri (`run_id` `6ae46d6a`, respectiv `6ae46d6d`).

Ambele rulează și pe Linux, în containerul Docker; scriptul `docker\test-on-linux.ps1`
are acum și `-Report`, care scrie rezultatul în tabela `teste`.

---

## 7. Priorități propuse

Toate punctele din lista anterioară sunt închise: `fseek()` înapoi (§3.19),
`fstat()`/`SEEK_END` (§3.16), pool-ul CSDK (§3.22), `JSON`/`BSON` ca string
(§3.20) și `next_rowset()` (§3.21). Ce a rămas, în ordinea raportului
cost/beneficiu:

1. **Pool de conexiuni care chiar refolosește** — nici CLI-ul 4.50.FC12, nici
   Driver Manager-ul ODBC al Windows-ului nu refolosesc sesiunea (§3.22.1);
   rămâne un pool la nivel de aplicație (`PDO::ATTR_PERSISTENT`, care merge) sau
   o versiune de CSDK în care pool-ul chiar funcționează.
2. **`fstat()` pentru `TEXT`/`BYTE` înainte de citire** — CSDK nu raportează
   lungimea decât la EOF; ar cere un `SELECT LENGTH(col)` suplimentar.
3. **`bindColumn()` cu `PDO::PARAM_LOB` pe JSON/BSON** — acum `fetch()` dă
   string; stream explicit rămâne disponibil doar prin `getColumnMeta`.
4. **Parametrii `JSON`/`BSON` fără cast** — ar cere ca driverul să știe tipul
   coloanei țintă la `prepare()`; acum se folosește
   `CAST(? AS LVARCHAR(n))::json` / `json_to_bson(...)`.




