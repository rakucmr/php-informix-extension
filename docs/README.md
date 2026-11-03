# docs — site de prezentare și documentație

Site static, bilingv (română / English), fără dependențe externe și fără CDN.
Prezintă cele patru module ale proiectului și are o pagină completă, separată,
pentru fiecare dintre ele.

## Structură

```text
docs/
├─ index.html                  pagina principală RO
├─ documentation.html          hub RO: alege modulul, matrice, teste, referințe
├─ pdo_informix.html           documentația RO a driverului PDO
├─ ifx_pdo.html                documentația RO a extensiei ifx_pdo
├─ ifx.html                    documentația RO a extensiei directe ifx
├─ php_informix.html           documentația RO a bibliotecii PHP
├─ en/
│  ├─ index.html               pagina principală EN
│  ├─ documentation.html       hub EN
│  ├─ pdo_informix.html        PDO driver docs
│  ├─ ifx_pdo.html             ifx_pdo docs
│  ├─ ifx.html                 direct ifx docs
│  └─ php_informix.html        PHP library docs
├─ assets/
│  ├─ style.css                tema inspirată de php.net
│  └─ app.js                   căutare, taburi, copiere, meniu mobil
├─ reference/                  documentație Markdown de referință
├─ .nojekyll                   dezactivează prelucrarea Jekyll pe GitHub Pages
├─ robots.txt                  indexare permisă
├─ build.py                    generatorul site-ului
├─ module_docs.py              conținutul RO/EN pentru paginile de modul și hub
└─ shared.py                   tabelul API partajat și iconițele SVG
```

Fiecare pagină de modul este autonomă și acoperă: prezentare, cerințe,
compilare/instalare, configurare, conectare, exemplu complet, referință API,
LOB-uri/SLOB, tranzacții și erori, testare, compatibilitate, performanță,
depanare și limitări.

## Regenerare

Din rădăcina proiectului:

```bash
python docs/build.py
python tools/check-docs.py --pages
```

Generatorul scrie direct în `docs/`; nu are nevoie de pachete Python
suplimentare (Python 3.9+). Scrie 12 fișiere: două pagini principale, două hub-uri și câte
patru pagini de modul în RO și EN. `tools/check-docs.py` verifică linkurile,
ancorele, structura HTML și linkurile Markdown.

## Previzualizare locală

```bash
python -m http.server 8766 --directory docs
```

Apoi deschideți <http://127.0.0.1:8766/>.

## Publicare pe GitHub Pages

Repository-ul include `.github/workflows/pages.yml`.
În setările repository-ului:

1. **Settings → Pages → Build and deployment → Source** = **GitHub Actions**.
2. Faceți push pe branch-ul `main` (sau rulați manual workflow-ul
   **Deploy documentation**).
3. Workflow-ul regenerează site-ul, rulează `tools/check-docs.py` și publică
   conținutul din `docs/`.

Site-ul nu are dependențe externe; `docs/.nojekyll` împiedică prelucrarea
Jekyll. Dacă folosiți un alt branch principal, actualizați `branches` în
workflow.

## Principii

- HTML semantic, CSS fără framework și JavaScript progresiv;
- layout în spiritul php.net: antet alb, accent mov, căutare, bară laterală
  pentru documentație, breadcrumbs și blocuri de cod;
- fără fonturi, imagini sau scripturi externe — site-ul funcționează offline;
- aceleași informații în RO și EN, cu linkuri între versiuni;
- nicio credențială, adresă de server sau cale personală în conținut.

## Module prezentate

| Folder | Pagina relevantă |
|---|---|
| `pdo_informix/` | [`pdo_informix.html`](pdo_informix.html) |
| `ifx_pdo_informix/` | [`ifx_pdo.html`](ifx_pdo.html) |
| `ifx_informix/` | [`ifx.html`](ifx.html) |
| `php_informix/` | [`php_informix.html`](php_informix.html) |
| `docs/` | [`documentation.html`](documentation.html) — hub-ul cu toate modulele |
