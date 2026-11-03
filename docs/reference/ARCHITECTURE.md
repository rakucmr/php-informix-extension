# Arhitectură

## Privire de ansamblu

Proiectul are două intrări de producție, ambele ajung la același motor PDO;
varianta `ifx` ocolește PDO:

```
A. ifx_pdo (pdo based)                 B. bibliotecă PHP
   ifx_pdo.dll + pdo_informix             cod aplicație
   cod aplicație                             │
       │                                     │ require php_informix/src/autoload.php
       │ ifx_connect(), ifx_query(), ...     ▼
       ▼                                 php_informix/src/compat.php
   funcții C + resurse Zend                  │
       │                                     │
       │ motor PHP încorporat                │
       ▼                                     ▼
   IfxCompatApi / IfxCompatConnection    Informix\Api / Connection
       └──────────────┬──────────────────────┘
                      ▼
             PDO (driver pdo_informix)
                      │
                      ▼
        Informix Client SDK → server Informix
```

```
C. ifx (direct, fără PDO)
   cod aplicație → ifx_connect(), ifx_query()
                       │
                       ▼
   funcții C + resurse Zend → CSDK CLI
                       │
                       ▼
   SQLDriverConnect / SQLPrepare / SQLExecute / SQLGetData
                       │
                       ▼
        Informix Client SDK → server Informix
```

Varianta C (`ifx`) este o extensie C completă, cu toate cele 39 de funcții,
scrisă direct peste Informix CSDK CLI. Nu poate fi încărcată împreună cu
`ifx_pdo`, pentru că ambele exportă aceleași nume de funcții PHP.

Varianta A (`ifx_pdo`) este o extensie C reală: are `get_module`, tabelă de
funcții, resurse și directive INI. Logica este însă încorporată ca sursă PHP
(`ifx_pdo_informix/ifx_php.c`, generată din `php_informix/src/Informix/`), nu rescrisă în C.
Are nevoie de `pdo_informix`; varianta C nu are.

## De ce biblioteca PHP

Extensia veche (`ifx.ec`) are ~137 KB de cod C care:

- folosește API-ul ESQL/C (`SQLConnect`, `SQLPrepare`, `SQLFetch`, descriptor
  areas, `ifx_lo_*`);
- creează resurse Zend Engine 2, API eliminat din PHP 8;
- duplică funcționalitatea deja existentă în `pdo_informix`;
- ar trebui recompilată pentru fiecare versiune PHP și fiecare platformă.

Un wrapper C peste PDO ar adăuga un strat de marshalling între două API-uri C
(PHP și PDO), fără beneficii funcționale. Varianta PHP este mai simplă de
depanat, testat și distribuit.

Extensia `ifx_pdo` rezolvă o altă problemă: compatibilitatea binară cu
aplicațiile care așteaptă funcțiile `ifx_*` și `is_resource()`. Se încarcă prin
`extension=ifx_pdo` și refolosește exact motorul PHP, ca să nu existe două
implementări care diverg. `ifx` (direct) merge direct la CSDK și are o
implementare C proprie pentru fiecare dintre cele 39 de funcții.

## Starea globală

`Informix\Api` ține:

- `?Error $lastError` — ultima eroare;
- `string $status` — caracterul din `ifx_error()` (`' '`, `W`, `E`, `N`);
- `?Connection $defaultLink` — link-ul folosit când se apelează fără argument;
- `?Connection $lastLink` — ultima conexiune (pentru diagnostic).

Starea este per-proces și per-request în modelul PHP clasic. În medii cu
procese de lucru de lungă durată (Swoole, RoadRunner), `Api::reset()` trebuie
apelat între requesturi pentru a închide conexiunile și a goli registrul de
handle-uri.

`Api::reset()`:

1. închide toate conexiunile din cache;
2. eliberează toate handle-urile;
3. resetează `Config` (recitește variabilele de mediu);
4. golește eroarea și link-urile.

## Conexiuni

`Connection::connect()`:

1. construiește DSN-ul:
   - `baza@server` → `informix:server=server;database=baza;` + opțional
     `host`, `service`, `protocol` din `Config`;
   - DSN complet `informix:...` este preluat ca atare;
2. apply `allow_persistent`;
3. completează user/parola din `Config` dacă lipsesc;
4. calculează cheia de cache: `p:`/`n:` + DSN + `|` + user + `|` + parolă;
5. refolosește conexiunea dacă există și nu e închisă;
6. verifică `max_links` / `max_persistent`;
7. creează `PDO` cu:
   - `ERRMODE_EXCEPTION`;
   - `FETCH_ASSOC` implicit;
   - `STRINGIFY_FETCHES = true`;
   - `CASE_NATURAL`;
   - `NULL_NATURAL`;
   - `ATTR_PERSISTENT` pentru `ifx_pconnect`.

Cache-ul este static și trăiește cât procesul. `close()` scoate conexiunea din
cache și eliberează obiectul PDO; statement-urile încă deschise păstrează
handle-ul nativ până la distrugerea lor.

`Connection::closeAll()` este folosit de `Api::reset()` și de teste.

## Rezultate

`Result` este o mică mașină de stări:

```
prepare() ──► stmt pregătit, executed=false
                   │
             execute()/ifx_do()
                   ▼
             executed=true, fetched=0
                   │
             fetch()/htmlTable()
                   ▼
             fetched=N, lastError=null
                   │
             sfârșit → lastError = Error(02000, 100)
                   │
             free() → stmt=null, freed=true
```

### Prepare

`Result::prepare()` creează `PDOStatement` prin `Connection::prepare()`. Cursorul
scroll este cerut doar dacă:

- flag-ul `IFX_SCROLL` este setat;
- SQL-ul arată ca `SELECT` sau `WITH`.

Pentru `INSERT`/`UPDATE`/`DELETE` cu `IFX_SCROLL`, cursorul rămâne secvențial
(altfel driverul ar putea refuza combinația).

### Execute

`execute()`:

1. închide cursorul anterior, dacă statement-ul a mai fost executat;
2. leagă parametrii:
   - `BlobHandle` → `PDO::PARAM_LOB`;
   - `CharHandle` → `PDO::PARAM_STR`;
   - `SlobHandle` → `PDO::PARAM_LOB`;
   - `null` → `PDO::PARAM_NULL`;
   - `bool` → `'1'`/`'0'`;
   - restul → `PDO::PARAM_STR`;
3. execută;
4. reține `rowCount()`, serialul (`lastInsertId()` pentru `INSERT`);
5. dacă `rowCount()` este `0` pentru un DML, citește
   `dbinfo('sqlca.sqlerrd2')` — driverul întoarce `0` când statement-ul are
   parametri LOB, dar SQLCA păstrează numărul real;
6. resetează `fetched`, metadatele și eroarea.

### Fetch

`fetchRaw()`:

1. verifică `freed`, `executed` și `columnCount() > 0`;
2. traduce poziția în `(orientation, offset)` PDO;
3. `fetch(PDO::FETCH_NUM, orientation, offset)`;
4. la `false` setează `Error('02000', 100, 'No rows found')`;
5. incrementează `fetched`.

`fetch()` mapează rezultatul numeric pe nume de coloane, folosind metadatele
`getColumnMeta()`. `getColumnMeta()` este apelat **numai după execuție** —
driverul poate termina procesul dacă este apelat înainte.

Metadatele sunt cache-uite în `Result::$meta`.

### De ce `FETCH_NUM` + mapare

`PDO::FETCH_ASSOC` ar pierde coloanele cu același nume și ar depinde de
normalizarea driverului. `FETCH_NUM` păstrează ordinea, iar maparea pe nume
permite:

- redenumirea expresiilor fără nume în `[Expr_n]`;
- păstrarea numelor normalizate;
- detectarea tipurilor LOB pentru conversie.

## LOB-uri

### La citire

`Result::convertValue()`:

1. dacă valoarea este resursă (stream PDO), citește tot conținutul și închide
   stream-ul;
2. dacă tipul nativ este LOB (`TEXT`, `BYTE`, `BLOB`, `CLOB`):
   - cu `textasvarchar`/`byteasvarchar` activ → string;
   - altfel → `BlobHandle::fromContent()`, înregistrat în `HandleRegistry`,
     întoarce id-ul întreg;
3. dacă valoarea este `NULL`:
   - LOB fără mod string → blob id cu conținutul `""` sau `"NULL"`;
   - altfel → `Config::nullValue()`;
4. altfel → string, cu `rtrim` pentru `CHAR` dacă `charasvarchar`.

`BlobHandle` are două moduri:

- memorie: conținutul stă într-un string;
- fișier: conținutul este scris într-un fișier temporar, iar `payload()` întoarce
  calea.

În mod fișier, `ifx_get_blob()` întoarce calea, ca extensia veche. Fișierul este
șters la `free()` sau la distrugerea obiectului.

### La scriere

`Result::bindParameter()` trimite conținutul blob-ului ca `PARAM_LOB`. Informix
acceptă astfel `TEXT`, `BYTE`, `BLOB` și `CLOB` fără conversii explicite.

### CHAR și SLOB

- `CharHandle` este doar un string cu id; este trimis ca `PARAM_STR`.
- `SlobHandle` este un buffer cu poziție. Nu există un echivalent PDO pentru
  `ifx_lo_*`, așa că SLOB-ul nu ajunge pe server ca obiect separat. Este util
  pentru asamblarea conținutului înainte de a-l trimite ca parametru.

## Handle-uri

`HandleRegistry` ține `int → object`. Biblioteca PHP întoarce întregi pentru
blob/char/slob; `ifx_pdo` întoarce resurse Zend pentru link/result și întregi
pentru celelalte handle-uri, ca extensia veche.

Registrul este global și nu este garbage-collected automat; handle-urile trebuie
eliberate cu `ifx_free_blob()`, `ifx_free_char()`, `ifxus_free_slob()` sau prin
`Api::reset()`.

## ifx_pdo

`ifx_informix/ifx.c` conține:

- `PHP_MINIT_FUNCTION(ifx_pdo)` — înregistrează cele două tipuri de resurse
  (`ifx link`, `ifx result`) și directivele INI;
- `PHP_RINIT_FUNCTION(ifx_pdo)` — resetează flagul de bootstrap;
- `ifx_ensure_bootstrap()` — scrie sursa PHP încorporată într-un fișier
  temporar, o compilează cu `zend_compile_file()` și o execută o dată;
- `ifx_call_static()` — apelează `IfxCompatApi::*` / `IfxCompatConfig::*` cu
  `call_user_function()`;
- câte un wrapper C pentru fiecare funcție legacy, care copiază argumentele,
  înlocuiește resursele cu obiectele PHP ascunse în ele și împachetează
  rezultatul înapoi într-o resursă.

Resursa C are structura:

```c
typedef struct {
    zval object;   /* IfxCompatConnection sau IfxCompatResult */
    bool freed;
} ifx_handle;
```

Distructorul resursei eliberează obiectul PHP. `ifx_close()` și
`ifx_free_result()` apelează metoda PHP corespunzătoare și marchează handle-ul
`freed`, dar resursa rămâne validă până la `unset()`.

## Erori

`Error::fromThrowable()`:

1. ia `PDOException::errorInfo` (`[SQLSTATE, SQLCODE, mesaj]`) dacă există;
2. altfel încearcă să extragă SQLSTATE/SQLCODE din cod și mesaj;
3. extrage `(ISAM: -N)` din mesaj;
4. normalizează SQLSTATE la 5 caractere.

`Error::status()`:

- `E` dacă SQLCODE < 0;
- `N` dacă SQLCODE == 100;
- `W` dacă 0 < SQLCODE < 100;
- spațiu altfel.

`ifxError()` formatează exact ca extensia veche:
`E [SQLSTATE=42 S02  SQLCODE=-206]`.

## Tipuri

`SqlType` centralizează maparea dintre numele native raportate de
`getColumnMeta()` și constantele `SQL*` folosite de `ifx_fieldtypes()`.
`DATETIME ...` și `INTERVAL ...` sunt recunoscute după prefix.

## Performanță

- O conexiune = un obiect PDO; conexiunile se refolosesc.
- Metadatele se calculează o singură dată per rezultat.
- `dbinfo('sqlca.sqlerrd2')` se execută doar pentru DML cu `rowCount() == 0`
  (de regulă INSERT/UPDATE cu LOB-uri).
- LOB-urile mari sunt citite integral în memorie la fetch; dacă aveți nevoie de
  streaming, folosiți direct PDO și `PDO::PARAM_LOB`.
- Modul fișier creează câte un fișier temporar per LOB citit; nu este recomandat
  pentru volume mari.

## Testare

`php_informix/tests/run-tests.php` rulează fișierele `php_informix/tests/[0-9][0-9]_*.php` într-un singur
proces:

- `php_informix/tests/bootstrap.php` — autoload, handler de warning-uri, funcții `test()`,
  `assert_*`, `skip_test()`;
- `php_informix/tests/fixtures.php` — creează schema `ifx_t_types`, `ifx_t_lob`, `ifx_t_dml`;
- fiecare fișier începe cu `reset_state()` și își curăță datele.

Warning-urile PHP sunt capturate de handler și transformate în eșecuri, cu
excepția testelor care le așteaptă (`test(..., true)`).

Testele acoperă:

- conexiuni, cache, pconnect, limite;
- query/prepare/do, metadate, scroll, HTML;
- DML, SQLCA, tranzacții, erori;
- LOB-uri mici/mari, mod fișier, CHAR, SLOB;
- configurare și variabile de mediu;
- date Latin-1 și (condiționat) UTF-8.

`ifx_pdo` are o suită separată,
`ifx_pdo_informix/tests/native-suite.php`, care rulează aceleași scenarii prin
`ifx_pdo.dll`; varianta directă are suita echivalentă în
`ifx_informix/tests/native-suite.php`. Se lansează cu
`ifx_pdo_informix/tests/run-native.ps1`, respectiv
`ifx_informix/tests/run-native.ps1`, pe Windows, sau cu scripturile Linux din
containerele respective.
