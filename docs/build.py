#!/usr/bin/env python3
"""Generate the bilingual ifx-compat documentation site.

Static, dependency-free output inspired by the php.net documentation layout.
Run from anywhere:

    python docs/build.py

Outputs:
    docs/index.html                  Romanian home
    docs/documentation.html          Romanian documentation
    docs/en/index.html               English home
    docs/en/documentation.html       English documentation
"""

import html
import os

import module_docs

ROOT = os.path.dirname(os.path.abspath(__file__))
LANG = "ro"


def t(ro, en):
    return ro if LANG == "ro" else en


def e(value):
    return html.escape(str(value), quote=True)


def code(text, filename=None, language="php"):
    text = text.strip("\n")
    head = ""
    if filename:
        head = (
            '<div class="code-head">'
            f'<span class="lang">{e(language)}</span>'
            f'<span class="file">{e(filename)}</span>'
            f'<button class="copy" type="button" data-copy data-copied="{e(t("Copiat", "Copied"))}">'
            f'{e(t("Copiază", "Copy"))}</button>'
            "</div>"
        )
    return (
        f'<div class="code">{head}'
        f"<pre><code>{html.escape(text)}</code></pre>"
        "</div>"
    )


def code_tabs(items):
    """items: list of (id, label, filename, text)."""
    buttons = []
    panels = []
    for i, (ident, label, filename, text) in enumerate(items):
        active = " active" if i == 0 else ""
        buttons.append(
            f'<button type="button" data-tab="{e(ident)}" class="{active.strip()}" '
            f'aria-selected="{"true" if i == 0 else "false"}">{e(label)}</button>'
        )
        panels.append(
            f'<div class="code-panel{active}" data-panel="{e(ident)}">'
            + code(text, filename)
            + "</div>"
        )
    return (
        '<div class="code-tabs-box" data-code-tabs>'
        '<div class="code-tabs">' + "".join(buttons) + "</div>"
        + "".join(panels)
        + "</div>"
    )


ICON = {
    "db": '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
    "bolt": '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg>',
    "code": '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 9-4 3 4 3m8-6 4 3-4 3M14 4l-4 16"/></svg>',
    "book": '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2.5Z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H21"/></svg>',
    "search": '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>',
    "menu": '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
    "arrow": '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    "check": '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20 6-11 11-5-5"/></svg>',
}


def nav(home_href, docs_href, page):
    items = [
        (t("Documentație", "Documentation"), docs_href, "docs"),
        (t("Module", "Modules"), home_href + "#modules", "modules"),
        (t("Start rapid", "Quick start"), home_href + "#quickstart", "quickstart"),
        (t("Testare", "Testing"), home_href + "#testing", "testing"),
        (t("Contribuție", "Contributing"), docs_href + "#contributing", "contributing"),
    ]
    out = []
    for label, href, key in items:
        current = ' aria-current="page"' if key == page else ""
        out.append(f'<a href="{e(href)}"{current}>{e(label)}</a>')
    return "".join(out)


def layout(title, description, body, *, lang, root, page, home_href, docs_href, other_lang_href):
    global LANG
    LANG = lang
    html_lang = "ro" if lang == "ro" else "en"
    ro_link = "#" if lang == "ro" else other_lang_href
    en_link = other_lang_href if lang == "ro" else "#"
    ro_current = ' aria-current="true"' if lang == "ro" else ""
    en_current = ' aria-current="true"' if lang == "en" else ""
    search_placeholder = t("Caută în documentație…", "Search the documentation…")
    return f"""<!doctype html>
<html lang="{html_lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(title)}</title>
<meta name="description" content="{e(description)}">
<meta name="theme-color" content="#ffffff">
<link rel="stylesheet" href="{root}assets/style.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236f74b5'/%3E%3Ctext x='32' y='43' font-family='Arial,sans-serif' font-size='25' font-weight='700' fill='white' text-anchor='middle'%3Eifx%3C/text%3E%3C/svg%3E">
</head>
<body class="page-{page}">
<a class="skip-link" href="#main">{e(t("Sari la conținut", "Skip to content"))}</a>

<div class="utility">
  <div class="container utility-inner">
    <span>{e(t("PHP 7.0–8.5 · IBM Informix", "PHP 7.0–8.5 · IBM Informix"))}</span>
    <div class="utility-links">
      <a href="{e(home_href)}">{e(t("Prezentare", "Overview"))}</a>
      <a href="{e(docs_href)}">{e(t("Documentație", "Documentation"))}</a>
      <span class="lang-switch" aria-label="{e(t("Limbă", "Language"))}">
        <a href="{e(ro_link)}"{ro_current}>RO</a>
        <span>/</span>
        <a href="{e(en_link)}"{en_current}>EN</a>
      </span>
    </div>
  </div>
</div>

<header class="masthead">
  <div class="container masthead-inner">
    <a class="logo" href="{e(home_href)}" aria-label="ifx-compat">
      <span class="logo-mark">ifx</span>
      <span class="logo-text">informix<small>for PHP</small></span>
    </a>
    <button class="menu-btn" type="button" data-menu-toggle aria-expanded="false" aria-label="{e(t('Meniu', 'Menu'))}">{ICON['menu']}</button>
    <nav class="main-nav" data-nav aria-label="{e(t('Navigare principală', 'Main navigation'))}">
      {nav(home_href, docs_href, page)}
    </nav>
    <form class="search" role="search" data-search-form action="{e(docs_href)}">
      <label class="sr-only" for="site-search">{search_placeholder}</label>
      <input id="site-search" type="search" name="q" placeholder="{search_placeholder}" autocomplete="off">
      <button type="submit" aria-label="{e(t('Caută', 'Search'))}">{ICON['search']}</button>
    </form>
  </div>
</header>

<main id="main">
{body}
</main>

<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-brand">
      <a class="logo" href="{e(home_href)}"><span class="logo-mark">ifx</span><span class="logo-text">informix<small>for PHP</small></span></a>
      <p>{e(t("API-ul procedural ifx_* peste driverul PDO modern, pentru PHP 7.0–8.5.", "The procedural ifx_* API on top of the modern PDO driver, for PHP 7.0–8.5."))}</p>
    </div>
    <div>
      <h3>{e(t("Module", "Modules"))}</h3>
      <a href="pdo_informix.html">{e(t("pdo_informix", "pdo_informix"))}</a>
      <a href="ifx_pdo.html">{e(t("ifx_pdo", "ifx_pdo"))}</a>
      <a href="ifx.html">{e(t("ifx direct", "direct ifx"))}</a>
      <a href="php_informix.html">{e(t("Biblioteca PHP", "PHP library"))}</a>
    </div>
    <div>
      <h3>{e(t("Documentație", "Documentation"))}</h3>
      <a href="{e(docs_href)}#modules">{e(t("Ghiduri pe module", "Per-module guides"))}</a>
      <a href="{e(docs_href)}#compatibility">{e(t("Compatibilitate", "Compatibility"))}</a>
      <a href="{e(docs_href)}#testing">{e(t("Testare", "Testing"))}</a>
      <a href="{e(docs_href)}#reference">{e(t("Referințe", "References"))}</a>
    </div>
    <div>
      <h3>{e(t("Proiect", "Project"))}</h3>
      <a href="{e(docs_href)}#contributing">{e(t("Contribuție", "Contributing"))}</a>
      <a href="{e(docs_href)}#license">{e(t("Licență", "License"))}</a>
    </div>
  </div>
  <div class="container footer-note">
    <p>{e(t("Construit pe baza extensiilor originale ext/informix (PHP 5.1) și pdo_informix, cu DeepSeek (AI), sub îndrumarea autorului proiectului. Cod original MIT; pdo_informix Apache-2.0. Contribuțiile și raportările de erori sunt binevenite.", "Built on the original ext/informix (PHP 5.1) and pdo_informix extensions, with DeepSeek (AI), under the project author's guidance. Original code MIT; pdo_informix Apache-2.0. Contributions and bug reports are welcome."))}</p>
    <p class="footer-copy">© 2026 ifx-compat · {e(t("Documentație bilingvă", "Bilingual documentation"))}</p>
  </div>
</footer>
<script src="{root}assets/app.js"></script>
</body>
</html>
"""


# --------------------------------------------------------------------------
# Home page
# --------------------------------------------------------------------------

COMPAT_ROWS = [
    ("7.0.33", "ZTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("7.1.33", "ZTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("7.2.34", "ZTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("7.3.33", "ZTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("7.4.32", "ZTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("8.0.30", "ZTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("8.1.12", "ZTS", "—", "✓", "92/92", "102/102", "93 + 1"),
    ("8.2.35", "—", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("8.3.36", "ZTS", "—", "✓", "92/92", "102/102", "93 + 1"),
    ("8.4.27", "—", "NTS", "✓", "92/92", "102/102", "93 + 1"),
    ("8.5.11/8.5.12", "NTS", "NTS", "✓", "92/92", "102/102", "93 + 1"),
]

MODULE_RESULT_INDEX = {
    "pdo_informix": 3,
    "ifx_pdo": 4,
    "ifx": 5,
    "php_informix": 6,
}


def compatibility_rows():
    out = []
    for php, win, lin, pdo, native, direct, library in COMPAT_ROWS:
        out.append(
            "<tr>"
            f"<td>PHP {e(php)}</td>"
            f"<td class=\"{'yes' if win != '—' else 'no'}\">{e(win)}</td>"
            f"<td class=\"{'yes' if lin != '—' else 'no'}\">{e(lin)}</td>"
            f"<td class=\"{'yes' if pdo != '—' else 'no'}\">{e(pdo)}</td>"
            f"<td class=\"score\">{e(native)}</td>"
            f"<td class=\"score\">{e(direct)}</td>"
            f"<td class=\"score\">{e(library)}</td>"
            "</tr>"
        )
    return "".join(out)


def module_compat_rows(slug):
    index = MODULE_RESULT_INDEX[slug]
    return [(row[0], row[index]) for row in COMPAT_ROWS]

MODULES = [
    {
        "id": "pdo",
        "name": "pdo_informix",
        "title_ro": "Driverul PDO",
        "title_en": "PDO driver",
        "text_ro": "Fundația: driverul PDO pentru Informix, cu patch-urile de compatibilitate PHP 7.0–8.5. Varianta ifx_pdo și biblioteca PHP trec prin el; varianta directă ifx nu.",
        "text_en": "The foundation: the Informix PDO driver with compatibility patches for PHP 7.0–8.5. ifx_pdo and the PHP library go through it; direct ifx does not.",
        "tag": "C · PDO",
        "icon": "db",
        "href": "pdo_informix.html",
    },
    {
        "id": "direct",
        "name": "ifx",
        "title_ro": "ifx (direct CSDK)",
        "title_en": "ifx (direct CSDK)",
        "text_ro": "Extensie C cu toate cele 39 de funcții, direct peste CSDK CLI: LOB-uri, CHAR, SLOB, scroll, metadate și SQLCA, fără pdo_informix. Se încarcă cu extension=ifx.",
        "text_en": "C extension with all 39 functions, directly over the CSDK CLI: LOBs, CHAR, SLOB, scroll, metadata and SQLCA, with no pdo_informix. Loads with extension=ifx.",
        "tag": "C · CSDK",
        "tag_ro": "C · CSDK",
        "tag_en": "C · CSDK",
        "icon": "bolt",
        "href": "ifx.html",
    },
    {
        "id": "native",
        "name": "ifx_pdo",
        "title_ro": "ifx_pdo (pdo based)",
        "title_en": "ifx_pdo (pdo based)",
        "text_ro": "Extensie C peste PDO: funcții ifx_*, resurse Zend, directive INI. Necesită php_pdo_informix.dll; se încarcă cu extension=ifx_pdo.",
        "text_en": "C extension over PDO: ifx_* functions, Zend resources, INI directives. It needs php_pdo_informix.dll and loads with extension=ifx_pdo.",
        "tag": "C · resurse",
        "tag_ro": "C · resurse",
        "tag_en": "C · resources",
        "icon": "bolt",
        "href": "ifx_pdo.html",
    },
    {
        "id": "library",
        "name": "php_informix",
        "title_ro": "Biblioteca PHP",
        "title_en": "PHP library",
        "text_ro": "Aceeași semantică, fără compilare. Un require și funcțiile globale sunt disponibile; link-urile și rezultatele sunt obiecte PHP, iar handle-urile LOB/CHAR/SLOB rămân id-uri întregi.",
        "text_en": "The same semantics without compilation. One require and the global functions are available; links and results are PHP objects, while LOB/CHAR/SLOB handles stay integer ids.",
        "tag": "PHP · obiecte",
        "tag_ro": "PHP · obiecte",
        "tag_en": "PHP · objects",
        "icon": "code",
        "href": "php_informix.html",
    },
    {
        "id": "docs",
        "name": "docs",
        "title_ro": "Documentația",
        "title_en": "Documentation",
        "text_ro": "Site bilingv, ghiduri complete pe module, referință API, matrice de compatibilitate și exemple complete. Totul offline.",
        "text_en": "Bilingual site, complete per-module guides, API reference, compatibility matrix and complete examples. Fully offline.",
        "tag": "RO + EN",
        "icon": "book",
        "href": "documentation.html",
    },
]


def module_cards(lang):
    cards = []
    for m in MODULES:
        title = m["title_ro"] if lang == "ro" else m["title_en"]
        text = m["text_ro"] if lang == "ro" else m["text_en"]
        tag = m.get(f"tag_{lang}", m["tag"])
        href = m["href"]
        cards.append(
            f'<a class="module-card" href="{e(href)}">'
            f'<span class="module-icon">{ICON[m["icon"]]}</span>'
            f'<span class="module-kicker">{e(m["name"])}</span>'
            f'<h3>{e(title)}</h3>'
            f'<p>{e(text)}</p>'
            f'<span class="module-foot"><span class="pill">{e(tag)}</span>'
            f'<span class="module-link">{e(t("Deschide", "Open"))} {ICON["arrow"]}</span></span>'
            "</a>"
        )
    return "".join(cards)


def home_body(lang):
    global LANG
    LANG = lang
    if lang == "ro":
        title = "Informix pentru PHP modern, fără compromisuri."
        lead = (
            "API-ul procedural <code>ifx_*</code> din 2005, disponibil pe PHP 7.0–8.5. "
            "Alege driverul PDO, ifx_pdo, biblioteca PHP pură sau varianta directă "
            "<code>ifx</code> — aceeași semantică, aceleași rezultate, pe Windows și Linux."
        )
        hero_cta = "Citește documentația"
        hero_second = "Vezi cele 4 module"
        quick_title = "Primul query"
        quick_lead = "Patru moduri de a ajunge la aceeași bază: driverul PDO, ifx direct peste CSDK, ifx_pdo peste PDO și biblioteca PHP. Setați DSN-ul și credențialele în mediu."
        modules_title = "Patru module, un singur API"
        modules_lead = "Fiecare folder este independent, dar toate împărtășesc aceeași semantică și aceleași teste de integrare."
        comparison_title = "Ce alegi?"
        compat_title = "Compatibilitate verificată"
        compat_lead = "Matricea rezultată din teste reale, nu din declarații. Toate cele patru module apar în tabel; fiecare build are driverul PDO compilat pentru versiunea respectivă."
        testing_title = "Testat, nu presupus"
        testing_lead = "Suitelor li se alătură exemple rulate și build-uri pe Windows și Linux."
        docs_cta_title = "Documentația completă"
        docs_cta_lead = "De la cerințe și compilare până la LOB-uri, cursoare, referință API și depanare."
        docs_cta_btn = "Deschide documentația"
    else:
        title = "Informix for modern PHP, without compromises."
        lead = (
            "The procedural <code>ifx_*</code> API from 2005, available on PHP 7.0–8.5. "
            "Choose the PDO driver, ifx_pdo, the pure PHP library or the direct "
            "<code>ifx</code> variant — same semantics, same results, on Windows and Linux."
        )
        hero_cta = "Read the documentation"
        hero_second = "Browse the 4 modules"
        quick_title = "First query"
        quick_lead = "Four ways to reach the same database: the PDO driver, direct ifx over CSDK, ifx_pdo over PDO and the PHP library. Keep the DSN and credentials in the environment."
        modules_title = "Four modules, one API"
        modules_lead = "Each folder stands on its own, yet all share the same semantics and integration tests."
        comparison_title = "Which one?"
        compat_title = "Verified compatibility"
        compat_lead = "A matrix built from real tests, not claims. All four modules appear in the table; every build has a PDO driver compiled for that PHP version."
        testing_title = "Tested, not assumed"
        testing_lead = "Test suites, runnable examples and builds on Windows and Linux."
        docs_cta_title = "The complete documentation"
        docs_cta_lead = "From requirements and builds to LOBs, cursors, API reference and troubleshooting."
        docs_cta_btn = "Open the documentation"

    quick = code_tabs([
        ("lib", t("Bibliotecă PHP", "PHP library"), "quickstart.php", """<?php
require __DIR__ . '/php_informix/src/autoload.php';

$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query('select tabid, tabname from systables', $conn, IFX_SCROLL);

while (($row = ifx_fetch_row($res)) !== false) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}

ifx_free_result($res);
ifx_close($conn);"""),
        ("ext", t("ifx_pdo (pdo based)", "ifx_pdo (pdo based)"), "ifx_pdo.php", """<?php
/*
 * Windows php.ini:
 *   extension=pdo_informix
 *   extension=ifx_pdo
 *
 * Linux php.ini:
 *   extension=pdo_informix.so
 *   extension=ifx_pdo.so
 *
 * CLI Windows:
 *   php -d extension=pdo_informix -d extension=ifx_pdo ifx_pdo.php
 *
 * CLI Linux:
 *   php -d extension=pdo_informix.so -d extension=ifx_pdo.so ifx_pdo.php
 */

$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query('select tabid, tabname from systables', $conn, IFX_SCROLL);

while (($row = ifx_fetch_row($res)) !== false) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}

ifx_free_result($res);
ifx_close($conn);"""),
        ("direct", t("ifx (direct)", "ifx (direct)"), "ifx.php", """<?php
/*
 * Windows php.ini:
 *   extension=ifx
 *
 * Linux php.ini:
 *   extension=ifx.so
 *
 * CLI Windows:
 *   php -d extension=ifx ifx.php
 *
 * CLI Linux:
 *   php -d extension=ifx.so ifx.php
 */

$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query('select tabid, tabname from systables', $conn, IFX_SCROLL);

while (($row = ifx_fetch_row($res)) !== false) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}

ifx_free_result($res);
ifx_close($conn);"""),
        ("pdo", "PDO", "pdo.php", """<?php
$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'),
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS')
);

$stmt = $pdo->query('select tabid, tabname from systables');
foreach ($stmt as $row) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}"""),
    ])

    if lang == "ro":
        compare_rows = [
            ("Necesită pdo_informix", "el însuși", "da", "da, prin PDO", "nu"),
            ("Client Informix CSDK", "da", "da", "da, prin pdo_informix", "da"),
            ("Încărcare", "extension=pdo_informix", "extension=pdo_informix + ifx_pdo", "require autoload.php", "extension=ifx"),
            ("Handle", "obiect PDO", "resource Zend", "obiect PHP", "resource Zend"),
            ("Compilare", "C + CSDK, per PHP", "C + CSDK, per PHP", "nu", "C + CSDK, per PHP"),
            ("API", "PDO", "ifx_* / ifxus_* complet (39)", "ifx_* / ifxus_* complet (39)", "ifx_* / ifxus_* complet (39)"),
            ("Potrivit pentru", "aplicații noi, ecosistem PDO", "aplicații noi și legacy", "deployment simplu, prototipuri", "aplicații noi și legacy; producție fără PDO, overhead minim"),
        ]
    else:
        compare_rows = [
            ("Needs pdo_informix", "itself", "yes", "yes, through PDO", "no"),
            ("Informix Client SDK", "yes", "yes", "yes, through pdo_informix", "yes"),
            ("Loading", "extension=pdo_informix", "extension=pdo_informix + ifx_pdo", "require autoload.php", "extension=ifx"),
            ("Handle", "PDO object", "Zend resource", "PHP object", "Zend resource"),
            ("Build", "C + CSDK, per PHP", "C + CSDK, per PHP", "none", "C + CSDK, per PHP"),
            ("API", "PDO", "full ifx_* / ifxus_* (39)", "full ifx_* / ifxus_* (39)", "full ifx_* / ifxus_* (39)"),
            ("Best for", "new applications, PDO ecosystem", "new and legacy applications", "simple deployment, prototypes", "new and legacy applications; production without PDO, lowest overhead"),
        ]
    compare = "".join(
        f"<tr><th>{e(a)}</th><td>{e(b)}</td><td>{e(c)}</td><td>{e(d)}</td><td>{e(f)}</td></tr>"
        for a, b, c, d, f in compare_rows
    )

    return f"""
<section class="hero">
  <div class="container hero-grid">
    <div class="hero-copy">
      <p class="eyebrow">{e(t("PHP 7.0–8.5 · Windows & Linux", "PHP 7.0–8.5 · Windows & Linux"))}</p>
      <h1>{title}</h1>
      <p class="hero-lead">{lead}</p>
      <div class="hero-actions">
        <a class="btn primary" href="documentation.html">{e(hero_cta)} {ICON['arrow']}</a>
        <a class="btn ghost" href="#modules">{e(hero_second)}</a>
      </div>
      <ul class="hero-points">
        <li>{ICON['check']} {e(t("PHP 7.0–8.5, NTS și TS", "PHP 7.0–8.5, NTS and TS"))}</li>
        <li>{ICON['check']} {e(t("Windows și Linux", "Windows and Linux"))}</li>
        <li>{ICON['check']} {e(t("fără credențiale în cod", "no credentials in code"))}</li>
      </ul>
    </div>
    <div class="hero-code">
      <div class="window-bar"><span></span><span></span><span></span><em>ifx_connect.php</em></div>
      {code("""<?php
$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query(
    'select tabname from systables',
    $conn,
    IFX_SCROLL
);

while ($row = ifx_fetch_row($res)) {
    echo $row['tabname'], PHP_EOL;
}""", None)}
    </div>
  </div>
</section>

<section class="section" id="modules">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">{e(t("Module", "Modules"))}</p>
      <h2>{e(modules_title)}</h2>
      <p>{e(modules_lead)}</p>
    </div>
    <div class="module-grid">{module_cards(lang)}</div>
  </div>
</section>

<section class="section alt" id="compare">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">{e(t("Alegere", "Choose"))}</p>
      <h2>{e(comparison_title)}</h2>
    </div>
    <div class="table-wrap compare-table">
      <table>
        <thead><tr><th></th><th>pdo_informix</th><th>ifx_pdo</th><th>php_informix</th><th>ifx<br><small>direct</small></th></tr></thead>
        <tbody>{compare}</tbody>
      </table>
    </div>
  </div>
</section>

<section class="section" id="quickstart">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">{e(t("Start rapid", "Quick start"))}</p>
      <h2>{e(quick_title)}</h2>
      <p>{e(quick_lead)}</p>
    </div>
    {quick}
    <p class="small-note">{e(t("Exemplele ifx_pdo și ifx arată în comentarii cum se încarcă extensia, atât prin php.ini, cât și cu -d extension. Comenzile complete de compilare sunt în documentație.", "The ifx_pdo and ifx examples show in their comments how to load the extension, both through php.ini and with -d extension. Complete build commands are in the documentation."))}</p>
  </div>
</section>

<section class="section alt" id="compatibility">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">{e(t("Matrice", "Matrix"))}</p>
      <h2>{e(compat_title)}</h2>
      <p>{e(compat_lead)}</p>
    </div>
    <div class="table-wrap">
      <table class="matrix">
        <thead><tr><th>PHP</th><th>Windows</th><th>Linux</th><th>pdo_informix</th><th>ifx_pdo</th><th>ifx</th><th>php_informix</th></tr></thead>
        <tbody>{compatibility_rows()}</tbody>
      </table>
    </div>
    <p class="small-note">{e(t("Coloanele Windows/Linux descriu build-urile ifx_pdo/bibliotecă; pdo_informix este inclus în fiecare build. ifx direct: 102/102 pe PHP 7.0–8.5, rulat pe Windows x64 (7.x–8.4 ZTS, 8.5 NTS).", "The Windows/Linux columns describe the ifx_pdo/library builds; pdo_informix is included in every build. Direct ifx: 102/102 on PHP 7.0–8.5, run on Windows x64 (7.x–8.4 ZTS, 8.5 NTS)."))}</p>
  </div>
</section>

<section class="section" id="testing">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">{e(t("Calitate", "Quality"))}</p>
      <h2>{e(testing_title)}</h2>
      <p>{e(testing_lead)}</p>
    </div>
    <div class="stats">
      <div class="stat"><strong>93 + 1</strong><span>{e(t("teste bibliotecă PHP", "PHP library tests"))}</span></div>
      <div class="stat"><strong>92</strong><span>{e(t("verificări extensie C", "C extension checks"))}</span></div>
      <div class="stat"><strong>198</strong><span>{e(t("aserțiuni driver PDO", "PDO driver assertions"))}</span></div>
      <div class="stat"><strong>11</strong><span>{e(t("versiuni PHP", "PHP versions"))}</span></div>
    </div>
    <div class="docs-cta">
      <div>
        <h2>{e(docs_cta_title)}</h2>
        <p>{e(docs_cta_lead)}</p>
      </div>
      <a class="btn primary" href="documentation.html">{e(docs_cta_btn)} {ICON['arrow']}</a>
    </div>
  </div>
</section>
"""


# --------------------------------------------------------------------------
# Documentation page
# --------------------------------------------------------------------------

def docs_body(lang):
    return module_docs.hub_body(lang, compatibility_rows())


# --------------------------------------------------------------------------
# Output
# --------------------------------------------------------------------------

def write(path, content):
    full = os.path.join(ROOT, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(content)
    print("wrote", os.path.relpath(full, ROOT))


def main():
    ro_home = layout(
        "ifx-compat — Informix pentru PHP 7.0–8.5",
        "Patru module pentru IBM Informix în PHP: driver PDO, extensie C ifx, bibliotecă PHP și documentație bilingvă.",
        home_body("ro"),
        lang="ro",
        root="",
        page="home",
        home_href="index.html",
        docs_href="documentation.html",
        other_lang_href="en/index.html",
    )
    en_home = layout(
        "ifx-compat — Informix for PHP 7.0–8.5",
        "Four modules for IBM Informix in PHP: PDO driver, ifx C extension, PHP library and bilingual documentation.",
        home_body("en"),
        lang="en",
        root="../",
        page="home",
        home_href="index.html",
        docs_href="documentation.html",
        other_lang_href="../index.html",
    )
    ro_docs = layout(
        "Documentație — ifx-compat",
        "Documentația ifx-compat pe module: pdo_informix, ifx_pdo, ifx direct și biblioteca PHP, fiecare cu ghid complet.",
        docs_body("ro"),
        lang="ro",
        root="",
        page="docs",
        home_href="index.html",
        docs_href="documentation.html",
        other_lang_href="en/documentation.html",
    )
    en_docs = layout(
        "Documentation — ifx-compat",
        "ifx-compat documentation by module: pdo_informix, ifx_pdo, direct ifx and the PHP library, each with a complete guide.",
        docs_body("en"),
        lang="en",
        root="../",
        page="docs",
        home_href="index.html",
        docs_href="documentation.html",
        other_lang_href="../documentation.html",
    )

    write("index.html", ro_home)
    write("en/index.html", en_home)
    write("documentation.html", ro_docs)
    write("en/documentation.html", en_docs)

    for slug in module_docs.MODULE_ORDER:
        ro_title, ro_desc = module_docs.module_page_meta("ro", slug)
        en_title, en_desc = module_docs.module_page_meta("en", slug)
        ro_page = layout(
            ro_title,
            ro_desc,
            module_docs.module_body("ro", slug, module_compat_rows(slug)),
            lang="ro",
            root="",
            page="docs",
            home_href="index.html",
            docs_href="documentation.html",
            other_lang_href=f"en/{slug}.html",
        )
        en_page = layout(
            en_title,
            en_desc,
            module_docs.module_body("en", slug, module_compat_rows(slug)),
            lang="en",
            root="../",
            page="docs",
            home_href="index.html",
            docs_href="documentation.html",
            other_lang_href=f"../{slug}.html",
        )
        write(f"{slug}.html", ro_page)
        write(f"en/{slug}.html", en_page)


if __name__ == "__main__":
    main()
