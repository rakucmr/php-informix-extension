# Ghid de migrare de la ext/informix

Ghidul presupune o aplicație PHP 5.x care folosește funcțiile `ifx_*` /
`ifxus_*` și rulează pe un server Informix. Scopul este să rulați același cod pe
PHP 7.0–8.5 cu `ifx-compat`, fără să rescrieți logica de business.

---

## 1. Inventarul apelurilor

Înainte de orice modificare:

```bash
grep -RIn --include='*.php' -E '\bifx(us)?_[a-z_]+\s*\(' /path/to/app > ifx-calls.txt
```

Verificați în special:

- `is_resource($link)` / `is_resource($res)` — funcționează cu
  `ifx_pdo`, dar
  nu cu biblioteca PHP (obiecte);
- `ifx_fieldtypes()` / `ifx_fieldproperties()` apelate înainte de `ifx_do()`;
- `IFX_HOLD` și fetch-uri după `COMMIT`;
- `ifx_getsqlca()` folosit pentru altceva decât `sqlerrd1`/`sqlerrd2`;
- `ifx_errormsg($cod)` pentru coduri rare;
- accesul la `ifx.blobinfile` și celelalte directive `ifx.*` din `php.ini`;
- `ifxus_*` folosit pentru date mari sau partajate între procese.

---

## 2. Instalarea driverului

Variantele `ifx_pdo` și biblioteca PHP au nevoie de `pdo_informix`; varianta directă `ifx` nu.

1. Compilați/instalați `pdo_informix` pentru versiunea de PHP folosită.
2. Copiați DLL-ul/SO-ul în directorul de extensii.
3. În `php.ini`:

```ini
extension = pdo_informix
```

Verificați:

```bash
php -m | grep -i informix
php -r 'var_dump(extension_loaded("pdo_informix"));'
```

Pe Windows, asigurați-vă că `%INFORMIXDIR%\bin` este în `PATH` (pentru
`iclit09b.dll`).

## 3. Instalarea `ifx-compat`

### Varianta A: `ifx_pdo` (cod legacy neschimbat)

Compilați `ifx_pdo_informix/ifx_pdo.dll` (vedeți `ifx_pdo_informix/README.md`) și adăugați în `php.ini` atât driverul PDO, cât și extensia:

```ini
extension = pdo_informix
extension = ifx_pdo
```

Această variantă are nevoie de `pdo_informix`. Link-urile și rezultatele rămân
resurse, deci `is_resource()` și codul care transmite link-ul mai departe
funcționează fără modificări. Directivele `ifx.*` din `php.ini` sunt
recunoscute.

### Varianta alternativă: `ifx` (direct, fără PDO)

Dacă nu vreți `pdo_informix`, puteți încărca `ifx.dll` cu `extension=ifx`.
Această variantă implementează toate cele 39 de funcții `ifx_*` / `ifxus_*`,
inclusiv LOB-uri, CHAR, SLOB, cursoare scroll, `ifx_prepare`/`ifx_do`,
metadate, SQLCA și HTML. Link-urile și rezultatele rămân `resource`, deci este
un înlocuitor drop-in pentru API-ul legacy complet, fără PDO.

### Varianta B: biblioteca PHP

#### Composer

```json
{
    "repositories": [
        { "type": "path", "url": "../php informix" }
    ],
    "require": {
        "informix/ifx-compat": "*"
    }
}
```

```bash
composer update informix/ifx-compat
```

#### Manual

```php
require '/path/to/ifx-compat/php_informix/src/autoload.php';
```

#### Fără modificarea aplicației

```ini
; php.ini
auto_prepend_file = "/path/to/ifx-compat/php_informix/src/autoload.php"
```

Atenție: `auto_prepend_file` se aplică tuturor requesturilor, inclusiv celor
care nu folosesc Informix.

## 4. Eliminarea extensiei vechi

Scoateți din `php.ini`:

```ini
; extension = ifx.so
; extension = php_ifx.dll
```

Dacă lăsați extensia veche încărcată, biblioteca PHP detectează funcțiile
existente și **nu** le mai definește; ar rula codul vechi, nu al nostru.
`ifx_pdo` are nume de modul diferit (`ifx_pdo`), dar exportă aceleași funcții
PHP ca extensia veche, deci nu pot fi încărcate simultan: scoateți extensia
veche și încărcați `pdo_informix` + `ifx_pdo`. Varianta directă `ifx` folosește
numele de modul `ifx` și poate înlocui binarul vechi; implementează toate cele
39 de funcții ale API-ului legacy.

## 5. Directiva `php.ini` → `ifx-compat`

Cu **`ifx_pdo`**, directivele `ifx.*` există în continuare și
pot rămâne în `php.ini` neschimbate (inclusiv `ifx.blobinfile=1`).

Cu **biblioteca PHP**, directivele `ifx.*` nu sunt înregistrate; PHP le ignoră,
deci trebuie migrate explicit:

| `php.ini` vechi | Echivalent bibliotecă PHP |
|---|---|
| `ifx.blobinfile = 1` | `IFX_BLOBINFILE=1` sau `Informix\Config::set('blobinfile', 1)` |
| `ifx.textasvarchar = 1` | `IFX_TEXTASVARCHAR=1` / `ifx_textasvarchar(1)` |
| `ifx.byteasvarchar = 1` | `IFX_BYTEASVARCHAR=1` / `ifx_byteasvarchar(1)` |
| `ifx.charasvarchar = 1` | `IFX_CHARASVARCHAR=1` / `ifx_charasvarchar(1)` |
| `ifx.nullformat = 1` | `IFX_NULLFORMAT=1` / `ifx_nullformat(1)` |
| `ifx.default_host = <server>` | `IFX_DEFAULT_HOST=<server>` |
| `ifx.default_user = <user>` | `IFX_DEFAULT_USER=<user>` |
| `ifx.default_password = ...` | `IFX_DEFAULT_PASSWORD=...` |
| `ifx.default_database = <database>` | `IFX_DEFAULT_DATABASE=<database>` |
| `ifx.allow_persistent = 0` | `IFX_ALLOW_PERSISTENT=0` |
| `ifx.max_links = N` | `Informix\Config::set('max_links', N)` |
| `ifx.max_persistent = N` | `Informix\Config::set('max_persistent', N)` |

Variabilele de mediu sunt citite la primul acces la `Config`; dacă le setați
după pornirea PHP, apelați `Informix\Config::reset()`.

> **Implicit schimbat doar la biblioteca PHP:** `blobinfile` este `0` acolo,
> față de `1` în extensia veche și în `ifx_pdo`. Dacă
> aplicația se baza pe `ifx_get_blob()` ca să obțină o **cale de fișier**,
> setați `IFX_BLOBINFILE=1` (bibliotecă) sau lăsați `ifx.blobinfile=1`
> (`ifx_pdo`).

## 6. Înlocuiri de cod

### Conexiune

```php
// înainte
$conn = ifx_connect(getenv('IFX_DSN'), getenv('IFX_USER'), getenv('IFX_PASSWORD'));
if (!$conn) { die(ifx_error()); }

// după — codul rămâne la fel
$conn = ifx_connect(getenv('IFX_DSN'), getenv('IFX_USER'), getenv('IFX_PASSWORD'));
if (!$conn) { die(ifx_error()); }
```

Dacă aveați verificări de resursă:

```php
// biblioteca PHP: obiect, nu resursă
if (!$conn instanceof \Informix\Connection) { ... }

// ifx_pdo: codul rămâne neschimbat
if (!is_resource($conn)) { ... }
```

`if ($conn)` funcționează în ambele variante: obiectul este truthy, resursa
este truthy, iar `false` rămâne `false`.

### Query și fetch

```php
// înainte
$res = ifx_query('select id, nume from clienti', $conn);
while ($row = ifx_fetch_row($res)) { ... }

// după — neschimbat
$res = ifx_query('select id, nume from clienti', $conn);
while (($row = ifx_fetch_row($res)) !== false) { ... }
```

Recomandare: folosiți `!== false` în loc de truthiness, ca un rând cu valori
goale să nu fie confundat cu sfârșitul.

### Metadate

```php
// înainte — funcționa și înainte de execuție
$res = ifx_prepare($sql, $conn);
$types = ifx_fieldtypes($res);
ifx_do($res);

// după — mutați ifx_do() înainte de metadate
$res = ifx_prepare($sql, $conn);
ifx_do($res);
$types = ifx_fieldtypes($res);
```

`ifx_num_fields()` funcționează și înainte de `ifx_do()`.

### LOB-uri

```php
// înainte
$blob = ifx_create_blob(1, 0, $text);
ifx_query('insert into documente (continut) values (?)', $conn, [$blob]);

// după — neschimbat
$blob = ifx_create_blob(1, 0, $text);
ifx_query('insert into documente (continut) values (?)', $conn, [$blob]);
```

Nu treceți `TEXT`/`BYTE` ca literal în SQL; veți primi `-608`. Folosiți
placeholdere și array-ul de blob-uri.

La citire:

```php
$row = ifx_fetch_row($res);
$content = ifx_get_blob($row['continut']); // blobinfile=0
```

Dacă aveți `blobinfile=1`, `ifx_get_blob()` întoarce calea fișierului temporar;
nu uitați `ifx_free_blob()` după citire.

### Cursoare scroll

```php
$res = ifx_query($sql, $conn, IFX_SCROLL);
$row = ifx_fetch_row($res, 'LAST');
```

`IFX_HOLD` este acceptat, dar nu păstrează cursorul după `COMMIT`. Înlocuiți:

```php
ifx_query('commit work', $conn);
$row = ifx_fetch_row($res); // -11067
```

cu re-executarea query-ului după commit.

### Tranzacții

```php
// înainte
ifx_query('begin work', $conn);
ifx_query('insert ...', $conn);
ifx_query('commit work', $conn);

// după — funcționează identic
```

Puteți folosi și API-ul OO:

```php
$conn->beginTransaction();
// ...
$conn->commit();
```

Nu amestecați `begin work` manual cu `$conn->commit()`: PDO nu știe de
tranzacția începută manual.

### SQLCA

```php
$sqlca = ifx_getsqlca($res);
$serial = $sqlca['sqlerrd1'];
$affected = $sqlca['sqlerrd2'];
```

`sqlerrd0` și `sqlerrd3`–`5` sunt `0`. Dacă aplicația folosea altceva din SQLCA
(pentru diagnostice), trebuie adaptată.

## 7. Diferențe care pot cere modificări

| Situație | Ce face `ifx-compat` | Ce trebuie să faceți |
|---|---|---|
| `is_resource($link)` | `false` | `instanceof` sau verificare de adevăr |
| metadate înainte de `ifx_do()` | warning + `false` | mutați `ifx_do()` |
| `ifx_affected_rows()` pe SELECT | `0` | folosiți `select count(*)` dacă aveți nevoie de estimare |
| `IFX_HOLD` | ignorat | re-executați query-ul după commit |
| `ifx_getsqlca()` | parțial | folosiți `sqlerrd1`/`sqlerrd2` |
| `blobinfile` implicit | `0` | setați `IFX_BLOBINFILE=1` dacă erați pe mod fișier |
| `ifx_errormsg($cod)` | dicționar mic | păstrați mesajele curente |
| `BOOLEAN` | `"1"`/`"0"` | convertiți la `"t"`/`"f"` dacă e nevoie |
| `ifx_free_result()` | nu invalidează blob id-urile | nu vă bazați pe eliberarea lor |
| `ifxus_*` | buffer local | dacă aveați nevoie de obiect pe server, rescrieți cu LOB |

## 8. Testare în paralel

1. Rulați aplicația veche și cea nouă pe aceeași bază de test.
2. Comparați rezultatele pentru un set de query-uri reprezentative:
   - valori scalare, NULL, CHAR cu padding, DATE/DATETIME, DECIMAL/MONEY;
   - LOB-uri mici și mari, binare cu `\0`;
   - cursoare scroll, poziții absolute;
   - erori: sintaxă, tabelă lipsă, duplicat.
3. Comparați `ifx_error()` pentru aceleași erori; SQLSTATE/SQLCODE trebuie să
   coincidă.
4. Verificați logurile PHP: `ifx-compat` emite `E_USER_WARNING`, ca extensia
   veche. Dacă aplicația are un error handler care transformă warning-urile în
   excepții, păstrați comportamentul.

Suitea din `php_informix/tests/` poate servi drept model pentru testele de
regresie.

## 9. Depanare

### `Unable to load dynamic library 'php_pdo_informix'`

- `%INFORMIXDIR%\bin` nu este în `PATH` (lipsește `iclit09b.dll`);
- DLL-ul nu corespunde versiunii PHP (ABI, NTS/TS, `php8.dll`);
- `extension_dir` este greșit.

### `No Informix connection has been opened`

`ifx_connect()` a eșuat sau ați închis conexiunea implicită. Verificați
`ifx_error()` imediat după conectare.

### `SQLSTATE[HY010] ... -11067`

Cursor închis de `COMMIT` (sau de un `ROLLBACK`). Re-executați query-ul;
`IFX_HOLD` nu este suportat.

### `-608 Illegal attempt to convert Text/Byte blob type`

Ați pus un literal `TEXT`/`BYTE` în `INSERT`/`UPDATE`. Folosiți un parametru
`?` și un blob id.

### `-239 duplicate value in a UNIQUE INDEX`

Comportament normal al bazei; `ifx-compat` doar îl raportează.

### LOB-ul citit este doar un nume de fișier

`blobinfile=1`. Fie citiți fișierul, fie setați `blobinfile=0` și refaceți
fetch-ul.

### Mesajul `ifx_errormsg()` nu este cel așteptat

Pentru coduri care nu sunt în dicționarul intern, funcția întoarce
`Informix error <cod>`. Folosiți `ifx_error()` pentru SQLSTATE/SQLCODE sau
mesajul complet al driverului.

## 10. Checklist final

- [ ] `pdo_informix` este încărcat și funcționează.
- [ ] Extensia veche `ifx` este scoasă din `php.ini`.
- [ ] `ifx-compat` este încărcat prin Composer sau `auto_prepend_file`.
- [ ] Directivele `ifx.*` au fost migrate în variabile de mediu / `Config`.
- [ ] `blobinfile` are valoarea dorită (implicit `0`).
- [ ] `is_resource()` a fost înlocuit.
- [ ] `ifx_do()` este apelat înainte de `ifx_fieldtypes()`.
- [ ] Nu există fetch-uri după `COMMIT` care se bazau pe `IFX_HOLD`.
- [ ] Testele de regresie trec pe baza de test.
- [ ] Logurile nu conțin warning-uri `ifx_*` neașteptate.
