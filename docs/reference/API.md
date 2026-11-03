# Referință API

Toate funcțiile sunt globale, cu numele și semnăturile din extensia
`ext/informix`. Ele apelează clasele din namespace-ul `Informix\` și pot fi
folosite și direct (`new`-ul nu este necesar).

Documentul descrie **biblioteca PHP**. Varianta `ifx_pdo`
(`ifx_pdo.dll`) expune aceleași funcții și semnături, dar:

- are nevoie de `pdo_informix` la runtime;
- link-urile și rezultatele sunt `resource` (nu obiecte);
- blob/char/slob rămân `int`;
- opțiunile pot veni și din directivele `php.ini` (`ifx.*`).

Varianta directă `ifx` (`ifx.dll`) implementează toate cele 39 de funcții
documentate aici, direct peste CSDK, și **nu** are nevoie de `pdo_informix`.
Folderul ei este `ifx_informix/`; `ifx_pdo` este în `ifx_pdo_informix/`.
Link-urile și rezultatele sunt `resource`, ca în `ifx_pdo`. Cache-ul
`ifx_pconnect` este per request: refolosește conexiunea cu aceleași argumente
cât timp requestul este activ, nu între requesturi.

Convenții generale (biblioteca PHP):

- `false` la eroare, împreună cu un `E_USER_WARNING`;
- starea ultimei erori este disponibilă prin `ifx_error()` și `ifx_errormsg()`;
- link-urile sunt obiecte `Informix\Connection`;
- rezultatele sunt obiecte `Informix\Result`;
- BLOB-urile, CHAR-urile și SLOB-urile sunt identificate prin `int`.

---

## Conexiune

### `ifx_connect`

```php
ifx_connect(
    ?string $database = null,
    ?string $userid = null,
    ?string $password = null
): Informix\Connection|false
```

Deschide o conexiune. `$database` poate fi:

- `"baza@server"` — serverul se rezolvă prin `sqlhosts`;
- un DSN complet `"informix:server=...;database=...;..."`;
- `null` — se folosesc `IFX_DEFAULT_DATABASE` / `INFORMIXDATABASE`.

Dacă se apelează din nou cu exact aceleași argumente, întoarce **aceeași**
conexiune (cache), ca extensia veche. La eșec emite warning și întoarce
`false`.

```php
$conn = ifx_connect(getenv('IFX_DSN'), getenv('IFX_USER'), getenv('IFX_PASSWORD'));
```

### `ifx_pconnect`

```php
ifx_pconnect(
    ?string $database = null,
    ?string $userid = null,
    ?string $password = null
): Informix\Connection|false
```

La fel ca `ifx_connect()`, dar folosește `PDO::ATTR_PERSISTENT`. După
`ifx_close()`, următorul `ifx_pconnect()` cu aceleași argumente refolosește
sesiunea serverului. Dacă `allow_persistent` este `false`, se comportă ca
`ifx_connect()`.

### `ifx_close`

```php
ifx_close(?Informix\Connection $link = null): bool
```

Închide link-ul dat sau conexiunea implicită. Întoarce `true` la succes.
Un link deja închis este respins cu warning și `false`.

---

## Interogări

### `ifx_query`

```php
ifx_query(
    string $query,
    ?Informix\Connection $link = null,
    int|array $cursor_type = 0,
    ?array $blobidarray = null
): Informix\Result|false
```

Prepare + execute. Al treilea argument poate fi:

- `IFX_SCROLL` (sau `IFX_SCROLL | IFX_HOLD`) — cursor scroll;
- un array de blob-uri — echivalentul formei vechi
  `ifx_query($sql, $conn, $blobidarray)`.

Al patrulea argument este array-ul de blob-uri. Numărul de elemente trebuie să
fie egal cu numărul de `?` din query. Elementele pot fi:

- id-uri întregi de BLOB/CHAR/SLOB;
- obiecte `Informix\BlobHandle`, `CharHandle`, `SlobHandle`;
- valori scalare pentru placeholdere obișnuite (extensie față de API-ul vechi).

Un șir numeric este interpretat ca id de blob, ca în extensia veche.

```php
$res = ifx_query('select * from t where id = ?', $conn, [new Informix\CharHandle('42')]);
```

### `ifx_prepare`

```php
ifx_prepare(
    string $query,
    ?Informix\Connection $link = null,
    int|array $cursor_type = 0,
    ?array $blobidarray = null
): Informix\Result|false
```

Doar pregătește statement-ul; execuția se face cu `ifx_do()`.
`ifx_num_fields()` poate fi apelat imediat după `ifx_prepare()`, dar
`ifx_fieldtypes()` / `ifx_fieldproperties()` cer `ifx_do()`.

### `ifx_do`

```php
ifx_do(Informix\Result $result): bool
```

Execută un rezultat pregătit. Poate fi apelat din nou pentru a re-executa
cursorul; numărul de rânduri citite este resetat.

---

## Citirea rezultatelor

### `ifx_fetch_row`

```php
ifx_fetch_row(
    Informix\Result $result,
    int|string|null $position = null
): array|false
```

Întoarce rândul următor ca array asociativ sau `false` la sfârșit. Cheile sunt
numele coloanelor, normalizate de driver (de regulă litere mici); expresiile
fără nume primesc `[Expr_1]`, `[Expr_2]`, …

Poziționarea este respectată **doar** pentru cursoarele `IFX_SCROLL`:

| `$position` | Sens |
|---|---|
| `null` / `'NEXT'` | rândul următor |
| `'FIRST'` | primul |
| `'LAST'` | ultimul |
| `'PRIOR'` / `'PREVIOUS'` | anteriorul |
| `'CURRENT'` | rândul curent |
| `int > 0` | absolut, 1-based |
| `int < 0` | absolut de la final (`-1` = ultimul) |
| `0` | ca `NEXT` |

Fără `IFX_SCROLL`, orice poziție este ignorată. O poziție necunoscută produce
warning și `false`.

Sfârșitul datelor setează:

```
N [SQLSTATE=02 000  SQLCODE=100]
```

### `ifx_htmltbl_result`

```php
ifx_htmltbl_result(
    Informix\Result $result,
    ?string $htmltableoptions = null
): int|false
```

Tipărește toate rândurile rămase ca tabel HTML și întoarce numărul de rânduri
tipărite. Dacă nu mai există rânduri, tipărește
`<h2>No rows found</h2>` și întoarce `0`.

- `<table>` sau `<table $htmltableoptions>`;
- capete de coloană cu prima literă majusculă;
- celule `BYTE`/`BLOB`: `(BYTE)`;
- celule `TEXT`/`CLOB`: conținutul;
- `NULL`: `""` sau `"NULL"` după `nullformat`;
- escape HTML opțional prin `Informix\Config::set('htmltbl_escape', true)`.

```php
ifx_htmltbl_result($res, 'border="1" class="grid"');
```

### `ifx_free_result`

```php
ifx_free_result(Informix\Result $result): bool
```

Închide cursorul și invalidează rezultatul. Blob id-urile deja returnate rămân
valide (spre deosebire de extensia veche).

### `ifx_num_rows`

```php
ifx_num_rows(Informix\Result $result): int
```

Numărul de rânduri citite prin `ifx_fetch_row()` / `ifx_htmltbl_result()`,
inclusiv re-fetch-urile pe cursor scroll.

### `ifx_num_fields`

```php
ifx_num_fields(Informix\Result $result): int
```

Numărul de coloane. Pentru un statement non-SELECT întoarce `0`.

---

## Metadate

### `ifx_fieldtypes`

```php
ifx_fieldtypes(Informix\Result $result): array<string, string>|false
```

Întoarce `nume_coloană => tip SQL`. Ceriți mai întâi `ifx_do()`.

Exemple de valori: `SQLSERIAL`, `SQLINT`, `SQLSMINT`, `SQLINT8`,
`SQLDECIMAL`, `SQLMONEY`, `SQLFLOAT`, `SQLSMFLOAT`, `SQLDATE`, `SQLDTIME`,
`SQLCHAR`, `SQLVCHAR`, `SQLNCHAR`, `SQLNVCHAR`, `SQLLVARCHAR`, `SQLTEXT`,
`SQLBYTES`, `SQLBOOL`, `SQLUDTFIXED`.

### `ifx_fieldproperties`

```php
ifx_fieldproperties(Informix\Result $result): array<string, string>|false
```

Întoarce `nume_coloană => "TIP;len;prec;scale;nullable"`, unde `nullable` este
`Y` sau `N`.

```php
// SQLDECIMAL;14;2;2;Y
```

### `ifx_getsqlca`

```php
ifx_getsqlca(Informix\Result $result): array{
    sqlerrd0:int, sqlerrd1:int, sqlerrd2:int,
    sqlerrd3:int, sqlerrd4:int, sqlerrd5:int
}
```

Approximare a SQLCA:

- `sqlerrd1` — ultimul serial, pentru `INSERT`;
- `sqlerrd2` — rânduri afectate;
- restul — `0`.

### `ifx_affected_rows`

```php
ifx_affected_rows(Informix\Result $result): int
```

- `INSERT` / `UPDATE` / `DELETE` / `MERGE`: numărul real de rânduri;
- `SELECT`: `0` (PDO nu expune estimarea serverului);
- DDL: `0`.

---

## Erori

### `ifx_error`

```php
ifx_error(?Informix\Connection $link = null): string
```

Formatul exact al extensiei vechi:

```
E [SQLSTATE=42 S02  SQLCODE=-206]
N [SQLSTATE=02 000  SQLCODE=100]
  [SQLSTATE=00 000  SQLCODE=0]
```

Primul caracter: spațiu (succes), `W` (warning), `E` (eroare), `N` (no data).

### `ifx_errormsg`

```php
ifx_errormsg(?int $errorcode = null): string
```

Fără argument întoarce mesajul ultimei erori. Cu cod întoarce mesajul erorii
curente (dacă se potrivește) sau un mesaj din dicționarul intern; pentru coduri
necunoscute: `Informix error <cod>`.

---

## BLOB-uri

### `ifx_create_blob`

```php
ifx_create_blob(int $type, int $mode, string $param): int|false
```

- `$type`: `0` = BYTE, orice altă valoare = TEXT;
- `$mode`: `0` = memorie, orice altă valoare = fișier temporar;
- `$param`: conținutul inițial sau, în mod fișier, numele fișierului sursă.

În mod fișier, fișierul sursă este copiat; originalul nu este modificat.
Întoarce id-ul blob-ului.

### `ifx_get_blob`

```php
ifx_get_blob(int $bid): string|false
```

În mod memorie întoarce conținutul. În mod fișier întoarce **calea** fișierului
temporar.

### `ifx_update_blob`

```php
ifx_update_blob(int $bid, string $content): bool
```

Modifică doar copia locală; nu execută `UPDATE` în baza de date.

### `ifx_copy_blob`

```php
ifx_copy_blob(int $bid): int|false
```

Creează o copie independentă (are propriul fișier temporar în mod fișier).

### `ifx_free_blob`

```php
ifx_free_blob(int $bid): bool
```

Eliberează blob-ul și șterge fișierul temporar, dacă există.

### Moduri

```php
ifx_blobinfile_mode(int $mode): bool   // 0 = memorie, 1 = fișier
ifx_textasvarchar(int $mode): bool     // TEXT/CLOB ca string
ifx_byteasvarchar(int $mode): bool     // BYTE/BLOB ca string
ifx_charasvarchar(int $mode): bool     // taie spațiile din CHAR
ifx_nullformat(int $mode): bool        // NULL -> "" sau "NULL"
```

---

## CHAR-uri

### `ifx_create_char`

```php
ifx_create_char(string $param): int|false
```

Creează un container de string cu id întreg.

### `ifx_get_char` / `ifx_update_char` / `ifx_free_char`

```php
ifx_get_char(int $bid): string|false
ifx_update_char(int $bid, string $content): bool
ifx_free_char(int $bid): bool
```

Un CHAR poate fi folosit ca parametru pentru coloane VARCHAR/CHAR într-un
`ifx_query()` cu array de parametri.

---

## SLOB-uri

SLOB-urile sunt bufferi în memorie, cu poziție de citire/scriere. Nu creează
obiecte `ifx_lo_*` pe server.

### `ifxus_create_slob`

```php
ifxus_create_slob(int $mode): int|false
```

Moduri: `IFX_LO_RDONLY`, `IFX_LO_WRONLY`, `IFX_LO_RDWR`, `IFX_LO_APPEND`,
opțional combinate cu `IFX_LO_BUFFER` / `IFX_LO_NOBUFFER`. `0` este tratat ca
`IFX_LO_RDWR`.

### `ifxus_open_slob` / `ifxus_close_slob`

```php
ifxus_open_slob(int $bid, int $mode): int|false
ifxus_close_slob(int $bid): bool
```

`ifxus_open_slob()` întoarce `0` la succes (ca în extensia veche). Un SLOB
deja deschis nu poate fi deschis din nou.

### `ifxus_tell_slob` / `ifxus_seek_slob`

```php
ifxus_tell_slob(int $bid): int|false
ifxus_seek_slob(int $bid, int $mode, int $offset): int|false
```

`$mode`: `0` = de la început, `1` = relativ, `2` = de la sfârșit. Întoarce noua
poziție; o poziție negativă este refuzată.

### `ifxus_read_slob` / `ifxus_write_slob`

```php
ifxus_read_slob(int $bid, int $nbytes): string|false
ifxus_write_slob(int $bid, string $content): int|false
```

`read` întoarce până la `$nbytes` octeți (mai puțin la sfârșit). `write`
întoarce numărul de octeți scriși. În mod `APPEND`, scrierea se face mereu la
sfârșit.

### `ifxus_free_slob`

```php
ifxus_free_slob(int $bid): bool
```

---

## Maparea tipurilor

| Tip nativ (pdo_informix) | `ifx_fieldtypes()` |
|---|---|
| `SERIAL` | `SQLSERIAL` |
| `SERIAL8` | `SQLSERIAL8` |
| `INTEGER`, `INT` | `SQLINT` |
| `SMALLINT` | `SQLSMINT` |
| `INT8`, `BIGINT` | `SQLINT8` |
| `DECIMAL`, `NUMERIC`, `DEC` | `SQLDECIMAL` |
| `MONEY` | `SQLMONEY` |
| `SMALLFLOAT`, `REAL` | `SQLSMFLOAT` |
| `FLOAT`, `DOUBLE` | `SQLFLOAT` |
| `DATE` | `SQLDATE` |
| `DATETIME ...` | `SQLDTIME` |
| `INTERVAL ...` | `SQLINTERVAL` |
| `CHAR`, `CHARACTER` | `SQLCHAR` |
| `VARCHAR`, `VARCHAR2` | `SQLVCHAR` |
| `NCHAR` | `SQLNCHAR` |
| `NVARCHAR` | `SQLNVCHAR` |
| `LVARCHAR` | `SQLLVARCHAR` |
| `TEXT` | `SQLTEXT` |
| `BYTE` | `SQLBYTES` |
| `BLOB`, `CLOB`, `JSON`, `BSON` | `SQLUDTFIXED` |
| `BOOLEAN`, `BOOL` | `SQLBOOL` |
| necunoscut | numele nativ sau `SQLUNKNOWN` |

## Coduri de eroare uzuale

| SQLCODE | Mesaj | Cauză tipică |
|---|---|---|
| `0` | – | succes |
| `100` | `No rows found` | sfârșit de cursor |
| `-201` | `A syntax error has occurred.` | SQL invalid |
| `-206` | `The specified table is not in the database.` | tabelă inexistentă |
| `-239` | `Could not insert new row - duplicate value in a unique index.` | duplicat |
| `-268` | `Unique constraint violated.` | constrângere |
| `-608` | `Illegal attempt to convert Text/Byte blob type.` | literal LOB în SQL |
| `-11067` | `Function sequence error.` | fetch după commit / cursor închis |

Mesajele complete vin de la driverul ODBC și pot conține `(ISAM: -N)`.
