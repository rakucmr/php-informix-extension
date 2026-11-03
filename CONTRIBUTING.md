# Contribuții

Mulțumim pentru interes. Proiectul are componente C, PHP și documentație
statică; o modificare bună trebuie să treacă atât testele, cât și verificările
de documentație.

## Înainte de a începe

- Citiți [`README.md`](README.md), [`TESTING.md`](TESTING.md) și
  [`LICENSING.md`](LICENSING.md).
- Pentru erori, deschideți un issue cu versiunea PHP, NTS/ZTS, OS, modulul,
  binarul, versiunea CSDK/IDS și comanda minimă de reproducere.
- Nu publicați credențiale, DSN-uri reale, dump-uri de date sau căi personale.
- Pentru vulnerabilități, urmați [`SECURITY.md`](SECURITY.md), nu deschideți un
  issue public.

## Structura proiectului

| Folder | Ce modifică |
|---|---|
| `ifx_informix/` | extensia directă `ifx` peste CSDK CLI |
| `ifx_pdo_informix/` | adaptoarele C `ifx_pdo` + sursa PHP încorporată |
| `pdo_informix/src/` | driverul PDO (Apache-2.0, cod IBM) |
| `php_informix/` | biblioteca PHP pură și testele ei |
| `docs/` | generatorul și site-ul static RO/EN |
| `docs/reference/` | referința Markdown |

Nu amestecați schimbările de licență cu cele funcționale. `pdo_informix` are
licență Apache-2.0 și nu poate fi relicențiat de acest proiect.

## Mediu de dezvoltare

Sunt necesare PHP 7.0–8.5, pachetul de dezvoltare PHP pentru Windows,
Visual Studio 2019/2022 pentru C și Informix Client SDK. Pentru testele reale
este nevoie și de un server IDS; vedeți [`TESTING.md`](TESTING.md).

Comenzi utile:

```powershell
# Windows: build ifx direct
.\ifx_informix\build-ifx.bat "C:\php-devel\...\php-8.5.x-devel-vs17-x64"

# Windows: build ifx_pdo
.\ifx_pdo_informix\build-ifx.bat "C:\php-devel\...\php-8.5.x-devel-vs17-x64"

# Windows: build driver PDO
cd pdo_informix\extension
.\build.bat

# Linux
cd ifx_informix && IFX_CSDK=/opt/ibm/informix ./build-ifx-linux.sh
cd ifx_pdo_informix && sh build-ifx-linux.sh
```

Nu comiteți binarare `.dll`, `.so`, `.lib`, `.pdb`, `.obj`, arhive CSDK sau
directoare `linux-build/`. Sunt deja excluse de `.gitignore`.

## Teste

Rulați cel puțin suita afectată și, dacă este posibil, toate cele patru:

```text
ifx direct:       102/102
ifx_pdo:           92/92
pdo_informix:       9/9, 198 assertions
php_informix:      93 passed, 1 skipped (UTF-8 on en_US.819)
```

Pentru modificări care afectează API-ul, rulați și testele de compatibilitate
din [`docs/reference/COMPATIBILITY.md`](docs/reference/COMPATIBILITY.md).
Testele care necesită Informix nu pot rula în CI-ul public fără un server;
descrieți în PR ce ați rulat local.

## Fișiere generate

Nu editați manual:

- `ifx_pdo_informix/ifx_php.c` — generați-l cu
  `php ifx_pdo_informix/generate-embedded.php` după orice modificare în
  `php_informix/src/`;
- `docs/*.html` și `docs/en/*.html` — generați-le cu `python docs/build.py`;
- `docs/__pycache__/` — artefact Python, nu se comite.

După regenerare:

```bash
php ifx_pdo_informix/generate-embedded.php
python docs/build.py
python tools/check-docs.py
```

Dacă modificați `ifx_php.c`, reconstruiți și `ifx_pdo.dll`/`ifx_pdo.so` înainte
de a rula testele `ifx_pdo`.

## Stil de cod

- PHP: `declare(strict_types=1)` unde există deja; compatibil PHP 7.0;
  fără funcții PHP 8-only în codul bibliotecii; păstrați semnăturile `ifx_*`.
- C: păstrați stilul fișierului, gestionați `emalloc`/`efree`, verificați
  rezultatele CLI și nu introduceți buffere fixe pentru LOB-uri.
- Nu schimbați valorile măsurate din benchmark sau rezultatele de
  compatibilitate fără o rulare reală și fără să actualizați sursa citată.
- Documentația: RO și EN trebuie să rămână sincronizate; nu introduceți
  credențiale, nume reale de server sau căi personale.

## Pull request

1. Creați un branch focusat pe o singură problemă.
2. Includeți testul care demonstrează corecția, dacă este posibil.
3. Rulați lint-ul, testele afectate și `python tools/check-docs.py`.
4. Descrieți mediul și rezultatele; marcați clar ce nu ați putut testa.
5. Nu includeți binarare, date de test sau modificări nelegate.

Prin trimiterea unei contribuții acceptați ca ea să fie distribuită sub
licența componentei modificate (MIT pentru codul original, Apache-2.0 pentru
`pdo_informix`). Dacă nu dețineți drepturile pentru codul trimis, nu îl trimiteți.
