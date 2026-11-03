# pdo_informix - sursă compatibilă cu PHP 8.5

Sursa extensiei PDO_INFORMIX, plecând de la versiunea oficială PECL **1.3.7** și
aplicând **doar** modificările funcționale din fork-ul
[`nahuelon/pdo_informix`](https://github.com/nahuelon/pdo_informix), astfel încât
extensia să compileze și să ruleze pe PHP 8.1 → 8.5 (verificat pe 8.1.12 NTS,
8.3.36 NTS și 8.5.11 NTS x64, cu aceeași sursă și fără patch-uri).

## Proveniență

| Element | Detaliu |
|---|---|
| Bază | `PDO_INFORMIX-1.3.7.tgz` de pe pecl.php.net (`package.xml` declară 1.3.7) |
| Modificări preluate | fork `nahuelon/pdo_informix`, commit-urile de compatibilitate PHP 8.1/8.4 (fișierele C identice cu fork-ul) |
| Corecție locală | `pdo_informix.c`: lista vidă de funcții (`= { }`) → `{ ZEND_FE_END }` (vezi mai jos) |
| Patch de referință | `php85-compat.patch` (cele 4 hunk-uri preluate din fork) |

Headerul `php_pdo_informix.h` păstrează `PDO_INFORMIX_VERSION "1.3.7"`.

## Modificări față de PECL 1.3.7

Toate cele patru modificări din fork sunt necesare pentru PHP ≥ 8.1:

1. **`pdo_parse_params()`** (`informix_driver.c`, `informix_statement.c`)
   semnătura a devenit `pdo_parse_params(stmt, stmt_string, …)` în PHP 8.1 —
   s-a eliminat cast-ul `(char *)` de pe al doilea argument.

2. **`stmt_cleanup`** (`informix_statement.c`)
   membru al `pdo_stmt_driver_t` eliminat în PHP 8.1 → apelul este învelit în
   `#if !PHP_8_1_OR_HIGHER`.

3. **`lob_stream_read` / `lob_stream_write`** (`informix_statement.c`)
   `php_stream_ops` folosește `ssize_t` din PHP 8.4 → semnăturile au trecut de la
   `size_t` la `ssize_t`.

4. **Parametri string goi** (`informix_statement.c`)
   din PHP 8.1 un `''` legat ca parametru trebuie să ajungă `NULL`; testul
   `Z_STRVAL_P(...) == '\0'` a fost înlocuit cu `strcmp(..., "") == 0`.

Plus corecțiile locale (nu vin din fork):

5. **`pdo_informix.c`** – `zend_function_entry pdo_informix_functions[] = { };`
   este un inițializator vid, acceptat de GCC dar care provoacă un **ICE**
   (`fatal error C1001`) în compilatorul MSVC 14.41 (VS 2022). Înlocuit cu
   `{ ZEND_FE_END }`, forma standard folosită de extensiile PHP.

6. **`lob_stream_read`** (`informix_statement.c`) – fluxul CLOB nu detecta
   sfârșitul valorii: `SQLGetData` reîntorcea aceiași octeți, iar
   `stream_get_contents()` intra în buclă infinită. Adăugat un flag `eof` în
   `struct lob_stream_data`, setat la `SQL_NO_DATA` sau la o citire parțială.

7. **`stmt_parameter_post_execute`** (`informix_statement.c`) – pentru
   `PDO_PARAM_LOB`, `transfer_length` rămâne negativ (`SQL_DATA_AT_EXEC`), iar
   codul scria `Z_STRLEN = 0` în string-ul apelantului: `bindValue($s,
   PDO::PARAM_LOB)` golea `$s` după `execute()`. Blocul se aplică acum doar
   parametrilor care nu sunt LOB.

8. **`informix_driver.c` / `dbh_connect`** – `SQL_INFX_ATTR_ENABLE_SCROLL_CURSORS`
   setat pe conexiune; fără el `PDO::CURSOR_SCROLL` eșua cu `-11086 Fetch type
   out of range`. Pe statement atributul dă `-11073`.

9. **`php_pdo_informix_int.h`** – `last_insert_id` lărgit la `zend_long` și citit
   prin `SQLINTEGER`, ca să nu trunchieze valorile mari.

10. **`create_lob_stream` / `lob_fill`** (`informix_statement.c`) – primul chunk
    al unui LOB este citit la deschiderea stream-ului, cu bufferul complet
    (8 KB), și reluat din `pending` la fiecare `read()`. Astfel `SQL_NULL_DATA`
    este detectat exact (→ `null`), iar `TEXT`/`BYTE`/`BLOB`/`CLOB` citite
    direct întorc conținutul corect. Varianta anterioară (probă cu 8 octeți)
    repornea valoarea de la început pentru `CLOB`/`BYTE` și întorcea `''`.

11. **`lob_stream_token`** (`informix_statement.c`, `php_pdo_informix_int.h`) –
    token partajat (`alive` + `refs`) între statement și stream-uri: la
    distrugerea statementului stream-urile nu mai apelează `SQLGetData` pe un
    handle eliberat (use-after-free). Chunk-ul deja citit rămâne disponibil,
    restul produce un `E_WARNING` explicit.

12. **`row_count`** (`informix_statement.c`) – valorile negative întoarse de
    `SQLRowCount` (SELECT) sunt normalizate la `0`.

13. **`informix_driver.c`** – atribute PDO:
    `ATTR_SERVER_VERSION` (din `DBINFO('version','full')`, memorat în
    `conn_handle.server_version`), `ATTR_CLIENT_VERSION` (`SQL_DRIVER_VER`,
    fără spațiile inițiale), `ATTR_TIMEOUT` (`SENDTIMEOUT`/`RECVTIMEOUT` cu
    `SQL_IS_UINTEGER` + `LOGIN_TIMEOUT`), `ATTR_CONNECTION_STATUS`,
    `ATTR_CASE` (valoare implicită `PDO_CASE_UPPER` la conectare).

14. **`pdo_informix.c` / `informix_driver.c`** – nouă constante
    `PDO_INFORMIX_*` (`OPTOFC`, `OPTMSG`, `FETCH_BUFFER_SIZE`,
    `DEFERRED_PREPARE`, `LEAVE_TRAILING_SPACES`, `ENABLE_INSERT_CURSORS`,
    `DELIMIDENT`, `SENDTIMEOUT`, `RECVTIMEOUT`), acceptate în
    `setAttribute()`/`getAttribute()` cele permise de CSDK după conectare, plus
    `check_liveness` pentru conexiunile persistente (rulează
    `SELECT 1 FROM systables`, pentru că `SQLGetInfo` răspunde din cache).

15. **`smart_lob`** (`informix_statement.c`, `php_pdo_informix_int.h`) – tipul
    real al coloanei este citit cu `SQLColAttribute(SQL_COLUMN_TYPE_NAME)`.
    Pentru `CLOB`/`BLOB` (smart LOBs) `SQLGetData` **nu avansează** cu
    `SQL_C_CHAR` — întoarce mereu primii octeți — dar avansează cu
    `SQL_C_BINARY`, unde indicatorul este lungimea rămasă. Pentru `TEXT`/`BYTE`
    (simple large objects) e invers: `SQL_C_BINARY` dă `SQL_NULL_DATA`. Driverul
    alege transferul după numele tipului, așa că LOB-urile mari se citesc
    integral.

16. **`informix_handle_fetch_error`** (`informix_driver.c`) – ramura fără cod
    ISAM folosea un `sprintf` cu 6 placeholdere și 5 argumente (mesajul conținea
    o valoare inventată pentru „ISAM: %d”). Trecut pe `snprintf`, cu formatul
    corect pentru fiecare ramură.

17. **`lastInsertId` pentru `SERIAL8`/`BIGSERIAL`** (`informix_statement.c`)
    – `SQL_GET_SERIAL_VALUE` raportează doar coloanele `SERIAL`. Imediat după
    `INSERT` (cât timp serverul încă raportează valoarea pentru sesiune) se
    citesc `DBINFO('sqlca.sqlerrd1')`, `DBINFO('serial8')` și
    `DBINFO('bigserial')`; exact una e non-zero. Un `INSERT` care nu atinge
    niciun rând nu schimbă valoarea anterioară.

18. **Curățenie de build** – 24 de warning-uri MSVC (`C4244`, `C4267`,
    `C4018`, `C4146`, `C4554`, `C4028`) eliminate: semnăturile handlerelor
    folosesc acum `zend_long` pentru `attr`/`offset`/`colno` (ca în
    `pdo_dbh_methods`/`pdo_stmt_methods`; cu `long` argumentul se trunchia pe
    Win64), iar argumentele ODBC primesc `cast`-uri explicite
    (`SQLUSMALLINT`, `SQLINTEGER`, `SQLSMALLINT`, `SQLLEN`). Tot aici:
    `param_type & PDO_PARAM_INPUT_OUTPUT > 0` → `(param_type &
    PDO_PARAM_INPUT_OUTPUT) != 0`, pentru că `>` se evalua înaintea lui `&`.

19. **Mesaje de eroare curate** (informix_driver.c, pdo_informix.c) – textul
    serverului, plus codul ISAM; funcția internă și fișierul:linia apar doar cu
    `pdo_informix.error_detail = 1` (opțiune nouă în `PHP_INI_ALL`, apare în
    `phpinfo()`).

20. **`php_stream_ops.Seek`** (informix_statement.c) – stream-ul reține
    poziția curentă și sare înainte citind/aruncând octeții; `fseek()` înapoi
    sau `SEEK_END` întorc `-1` (cu `E_WARNING`), pentru că `SQLGetData`
    nu are rewind și lungimea nu se cunoaște înainte de citire.
21. **Al patrulea argument al lui `bindParam()`** (`informix_statement.c`) – era
    tratat ca `SQL_PARAM_OUTPUT` (serverul răspundea `-254`/`-11012`); acum
    un parametru este de intrare dacă nu are bitul `PDO_PARAM_INPUT_OUTPUT`.
    Bufferul pentru parametrii de ieșire este al driverului (`output_buf`), nu
    stringul zval-ului, al cărui header nu trebuie rescris.

22. **Parametrii `INOUT`/`OUT`** (`informix_statement.c`) – sunt refuzați la
    `bindParam()` cu `IM001` și un mesaj care trimite la
    `EXECUTE FUNCTION … RETURNING`, pentru că CSDK-ul fie dă `-9752`, fie
    blochează `SQLExecute()` la infinit. Tot acolo: bucla `SQL_NEED_DATA` are
    un plafon de iterații, ca un driver care cere la nesfârșit date să dea eroare
    în loc să atârne.
23. **`php_stream_ops.Stat`** (`informix_statement.c`) – lungimea LOB-ului:
    primul `SQLGetData` pe un smart LOB raportează câți octeți au rămas, deci
    totalul; pentru `TEXT`/`BYTE` se știe abia după EOF. `fstat()` și
    `SEEK_END` folosesc această valoare. Structura `php_stream_ops` a fost
    completată în ordinea reală a câmpurilor (`cast`, `stat`, `set_option`).
24. **`fseek()` înapoi** (`informix_statement.c`) – octeții consumați sunt
    ținuți în `lob_stream_data.history` și re-serviți din memorie; memoria
    crește doar cu datele cerute de aplicație și se eliberează la `fclose()`.

25. **JSON/BSON ca string** (`informix_statement.c`) – recunoaștere după
    `SQL_COLUMN_TYPE_NAME` și întoarcerea valorii ca `zend_string` (text
    JSON, respectiv octeți BSON cu NUL-uri), în loc de stream. Atenție: PDO
    pasează `NULL` pentru parametrul de tip în unele căi de fetch, deci nu
    trebuie dereferențiat necondiționat.

26. **`next_rowset()`** (`informix_statement.c`) – `SQL_NO_DATA` rămâne
    "nu mai sunt seturi"; orice alt rezultat în afară de succes ridică
    `IM001`, ca o eroare reală să nu mai fie înghițită.

27. **Pool de conexiuni** (`informix_driver.c`, `pdo_informix.c`) –
    environment partajat cu refcount, ca pool-ul CSDK să aibă ce refolosi;
    activat din `pdo_informix.connection_pooling` (`PHP_INI_SYSTEM`).
Restul fișierelor (`config.m4`, `config.w32`, `Makefile.frag`, `package.xml`,
`tests/*.phpt`, `LICENSE`, `CREDITS`) sunt **neatinse** față de PECL 1.3.7.

## Compilare

Vezi [`../extension/build.bat`](../extension/build.bat) și
[`../extension/README.md`](../extension/README.md). Pe scurt:

```bat
cd "C:\path\to\pdo_informix\extension"
build.bat "C:\php-devel\devel\php-8.5.11-devel-vs17-x64" "C:\IBM\Informix_Client_SDK"
```

Rezultatul este `php_pdo_informix.dll` în folderul `extension`.

## Testare

Testele funcționale sunt în [`../test`](../test) și rulează împotriva serverului
Informix real:

```bat
php -n -d extension_dir="C:\path\to\pdo_informix\extension" -d extension=pdo_informix ..\test\run-tests.php
```

## Note

- `tests/*.phpt` sunt testele oficiale PECL (rulează cu `run-tests.php` din
  php-src / `php-test-pack`, nu cu runner-ul din `../test`). Sunt **material de
  referință, nu parte din validare**: pe CSDK 4.50.FC12W12 + PHP 8.5.11 dau
  18 PASS / 21 FAIL, iar eșecurile vin din așteptări vechi — mesajele de eroare
  conțin încă `at fișier:linie` (scos intenționat, vezi corecția #20),
  `rowCount()` pe `SELECT` e așteptat `-1` (normalizat intenționat la `0`, #12),
  versiunea serverului e `%d.%d.%d` (acum `4.50.FC12W12`), iar unele teste cer
  un DSN ODBC numit `informix` sau fixture-uri proprii. Fișierele nu au fost
  modificate față de PECL 1.3.7.
- Driverul folosește IBM Informix CLI (ODBC); `iclit09b.dll` trebuie să fie în
  `PATH` la runtime.
- Macro-ul `PDO_INFORMIX_USE_ODBC_DM` (build cu `build.bat … dm`) leagă
  `odbc32.lib` în loc de `iclit09b.lib` și activează două lucruri: prefixarea
  șirului de conectare cu `DRIVER={…}` (Driver Manager-ul are nevoie de un nume
  de DSN sau de driver) și **omiterea** atributelor `SQL_INFX_ATTR_*` — prin
  Driver Manager, `SQLSetConnectAttr` cu ele iese cu `0xC0000005`.














