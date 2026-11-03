"""Separate, complete documentation pages for each ifx-compat module.

Each page is generated for Romanian and English and contains the same
structure: overview, requirements, build/install, configuration, connecting,
a complete example, API, LOBs, transactions/errors, testing, compatibility,
performance, troubleshooting and limitations.
"""

import html

from shared import INFO, WARN, api_table

MODULE_ORDER = ["pdo_informix", "ifx_pdo", "ifx", "php_informix"]

MODULE_META = {
    "pdo_informix": {
        "slug": "pdo_informix",
        "kind": "pdo",
        "name": "pdo_informix",
        "tag": "C · PDO",
        "icon": "db",
        "binary": "php_pdo_informix.dll / pdo_informix.so",
        "api": "PDO",
        "tests": "9/9 · 198",
        "title_ro": "pdo_informix — driverul PDO pentru Informix",
        "title_en": "pdo_informix — the Informix PDO driver",
        "lead_ro": "Driverul PDO care stă la baza lui <code>ifx_pdo</code> și a bibliotecii PHP. Oferă API-ul PDO standard, stream-uri pentru LOB-uri și acces direct la Informix prin CSDK.",
        "lead_en": "The PDO driver underneath <code>ifx_pdo</code> and the PHP library. It provides the standard PDO API, LOB streams and direct Informix access through CSDK.",
    },
    "ifx_pdo": {
        "slug": "ifx_pdo",
        "kind": "ifx_pdo",
        "name": "ifx_pdo",
        "tag": "C · PDO",
        "icon": "bolt",
        "binary": "ifx_pdo.dll / ifx_pdo.so",
        "api": "39 funcții",
        "tests": "92/92",
        "title_ro": "ifx_pdo — API-ul ifx peste PDO",
        "title_en": "ifx_pdo — the ifx API over PDO",
        "lead_ro": "Extensie C care expune toate cele 39 de funcții <code>ifx_*</code> / <code>ifxus_*</code> peste <code>pdo_informix</code>. Are resurse Zend, directive INI și un motor PHP încorporat.",
        "lead_en": "A C extension that exposes all 39 <code>ifx_*</code> / <code>ifxus_*</code> functions over <code>pdo_informix</code>. It has Zend resources, INI directives and an embedded PHP engine.",
    },
    "ifx": {
        "slug": "ifx",
        "kind": "ifx",
        "name": "ifx",
        "tag": "C · CSDK",
        "icon": "bolt",
        "binary": "ifx.dll / ifx.so",
        "api": "39 funcții",
        "tests": "102/102",
        "title_ro": "ifx — extensia directă peste CSDK",
        "title_en": "ifx — the direct CSDK extension",
        "lead_ro": "Extensie C independentă, direct peste Informix Client SDK CLI. Implementează toate cele 39 de funcții, fără PDO și fără <code>pdo_informix</code>.",
        "lead_en": "An independent C extension directly over the Informix Client SDK CLI. It implements all 39 functions without PDO and without <code>pdo_informix</code>.",
    },
    "php_informix": {
        "slug": "php_informix",
        "kind": "library",
        "name": "php_informix",
        "tag": "PHP · PDO",
        "icon": "code",
        "binary": "php_informix/src/autoload.php",
        "api": "39 funcții",
        "tests": "93 + 1",
        "title_ro": "php_informix — biblioteca PHP pură",
        "title_en": "php_informix — the pure PHP library",
        "lead_ro": "Aceleași 39 de funcții, fără compilare: un singur <code>require</code> peste <code>pdo_informix</code>. Link-urile și rezultatele sunt obiecte PHP.",
        "lead_en": "The same 39 functions without compilation: one <code>require</code> over <code>pdo_informix</code>. Links and results are PHP objects.",
    },
}


def t(lang, ro, en):
    return ro if lang == "ro" else en


def e(value):
    return html.escape(str(value), quote=True)


def code(lang, text, filename, language="php"):
    copy = "Copiază" if lang == "ro" else "Copy"
    copied = "Copiat" if lang == "ro" else "Copied"
    return (
        '<div class="code"><div class="code-head">'
        f'<span class="lang">{e(language)}</span><span class="file">{e(filename)}</span>'
        f'<button class="copy" type="button" data-copy data-copied="{e(copied)}">{e(copy)}</button>'
        f'</div><pre><code>{html.escape(text.strip(chr(10)))}</code></pre></div>'
    )


def table(headers, rows, cls=""):
    head = "".join(f"<th>{h}</th>" for h in headers)
    body = "".join(
        "<tr>" + "".join(f"<td>{cell}</td>" for cell in row) + "</tr>"
        for row in rows
    )
    klass = f' class="{e(cls)}"' if cls else ""
    return (
        f'<div class="table-wrap"><table{klass}>'
        f"<thead><tr>{head}</tr></thead><tbody>{body}</tbody></table></div>"
    )


def note(kind, body):
    icon = INFO if kind == "info" else WARN
    klass = "notice info" if kind == "info" else "notice"
    return f'<div class="{klass}">{icon}<div>{body}</div></div>'


def ul(items):
    return "<ul>" + "".join(f"<li>{item}</li>" for item in items) + "</ul>"


def section(ident, title, body):
    return f'<section id="{e(ident)}"><h2>{title}</h2>{body}</section>'


def module_link(lang, slug, label=None, active=False):
    meta = MODULE_META[slug]
    text = label or meta["name"]
    cls = ' class="active"' if active else ""
    return f'<a href="{e(slug)}.html"{cls}>{e(text)}</a>'


def side(lang, active, items):
    modules = "".join(module_link(lang, slug, active=slug == active) for slug in MODULE_ORDER)
    links = "".join(f'<a href="#{e(i)}">{e(label)}</a>' for i, label in items)
    placeholder = "Filtrează secțiunile…" if lang == "ro" else "Filter sections…"
    label = "Cuprins" if lang == "ro" else "Contents"
    return (
        '<aside class="docs-side">'
        f'<h3>{e("Module" if lang == "ro" else "Modules")}</h3>'
        f'<nav class="module-switch">{modules}</nav>'
        f'<h3>{e(label)}</h3><nav>{links}</nav>'
        f'<input class="doc-filter" data-doc-filter type="search" placeholder="{e(placeholder)}" aria-label="{e(placeholder)}">'
        "</aside>"
    )


def hero(lang, meta):
    breadcrumb = (
        '<div class="breadcrumb">'
        f'<a href="index.html">ifx-compat</a><span>/</span>'
        f'<a href="documentation.html">{e(t(lang, "documentație", "documentation"))}</a>'
        f'<span>/</span><span>{e(meta["name"])}</span></div>'
    )
    return f"""
<div class="docs-hero">
  <div class="container">
    {breadcrumb}
    <p class="eyebrow">{e(meta["tag"])} · PHP 7.0–8.5</p>
    <h1>{meta["title_ro"] if lang == "ro" else meta["title_en"]}</h1>
    <p>{meta["lead_ro"] if lang == "ro" else meta["lead_en"]}</p>
  </div>
</div>"""


def overview(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        body = (
            f"<p>{t(lang, 'Driverul PDO este stratul care vorbește direct cu Informix Client SDK prin ODBC. Nu expune funcțiile <code>ifx_*</code>: aplicația folosește API-ul PDO standard, iar <code>ifx_pdo</code> și biblioteca PHP construiesc compatibilitatea procedurală peste el.', 'The PDO driver is the layer that talks directly to Informix Client SDK through ODBC. It does not expose the <code>ifx_*</code> functions: the application uses the standard PDO API, while <code>ifx_pdo</code> and the PHP library build the procedural compatibility on top.')}</p>"
            + ul([
                t(lang, "Îl folosești direct dacă vrei PDO, prepared statements și stream-uri LOB.", "Use it directly when you want PDO, prepared statements and LOB streams."),
                t(lang, "Este obligatoriu pentru <code>ifx_pdo</code> și pentru biblioteca PHP.", "It is required by <code>ifx_pdo</code> and by the PHP library."),
                t(lang, "Nu are nevoie de extensia <code>ifx</code> sau <code>ifx_pdo</code>.", "It does not need the <code>ifx</code> or <code>ifx_pdo</code> extension."),
            ])
            + note("info", t(lang, "<strong>Un singur driver, mai multe API-uri.</strong> Dacă ai cod vechi cu <code>ifx_connect()</code>, alege <code>ifx_pdo</code>, <code>ifx</code> sau biblioteca — nu driverul PDO direct.", "<strong>One driver, several APIs.</strong> If you have legacy <code>ifx_connect()</code> code, choose <code>ifx_pdo</code>, <code>ifx</code> or the library — not the raw PDO driver."))
        )
    elif kind == "ifx_pdo":
        body = (
            f"<p>{t(lang, 'Aceasta este varianta C care păstrează contractul extensiei vechi: link-uri și rezultate <code>resource</code>, handle-uri întregi pentru LOB/CHAR/SLOB, directive <code>ifx.*</code> și toate cele 39 de funcții. Motorul care execută semantica este sursa PHP a bibliotecii, încorporată în DLL și compilată la primul apel.', 'This is the C variant that preserves the legacy extension contract: <code>resource</code> links and results, integer LOB/CHAR/SLOB handles, <code>ifx.*</code> directives and all 39 functions. The engine that executes the semantics is the library PHP source, embedded in the DLL and compiled on first use.')}</p>"
            + ul([
                t(lang, "Potrivit pentru cod vechi care se așteaptă la <code>is_resource()</code> și la directive INI.", "Suitable for legacy code that expects <code>is_resource()</code> and INI directives."),
                t(lang, "Necesită <code>pdo_informix</code>; cele două DLL-uri se încarcă în ordinea corectă.", "Requires <code>pdo_informix</code>; the two DLLs must be loaded in the right order."),
                t(lang, "Nu se poate încărca în același proces cu <code>ifx</code> direct.", "It cannot be loaded in the same process as direct <code>ifx</code>."),
            ])
            + note("info", t(lang, "<strong>Motor încorporat, nu wrapper subțire.</strong> Extensia refolosește exact codul bibliotecii, ca să nu existe două implementări care pot diverge.", "<strong>Embedded engine, not a thin wrapper.</strong> The extension reuses the library code exactly, so there are no two implementations that can drift apart."))
        )
    elif kind == "ifx":
        body = (
            f"<p>{t(lang, 'Varianta directă implementează toate cele 39 de funcții în C, peste API-ul CLI al CSDK. Nu folosește PDO, nu încorporează motorul PHP și nu depinde de <code>pdo_informix</code>. Este cea mai independentă cale, cu cel mai mic overhead între PHP și CSDK.', 'The direct variant implements all 39 functions in C over the CSDK CLI API. It does not use PDO, does not embed the PHP engine and does not depend on <code>pdo_informix</code>. It is the most independent path, with the lowest overhead between PHP and CSDK.')}</p>"
            + ul([
                t(lang, "Potrivit pentru producție fără PDO și pentru aplicații legacy.", "Suitable for production without PDO and for legacy applications."),
                t(lang, "Se încarcă singur, cu <code>extension=ifx</code>.", "Loads on its own, with <code>extension=ifx</code>."),
                t(lang, "Nu se poate încărca împreună cu <code>ifx_pdo</code>.", "It cannot be loaded together with <code>ifx_pdo</code>."),
            ])
            + note("info", t(lang, "<strong>Testat pe Windows x64.</strong> Suita directă are 102/102 pe PHP 7.0–8.5. Pentru Linux există scriptul <code>build-ifx-linux.sh</code>, dar rezultatele din matricea de compatibilitate sunt cele Windows.", "<strong>Tested on Windows x64.</strong> The direct suite is 102/102 on PHP 7.0–8.5. Linux has <code>build-ifx-linux.sh</code>, but the compatibility matrix results are the Windows ones."))
        )
    else:
        body = (
            f"<p>{t(lang, 'Biblioteca reimplementează API-ul procedural în PHP pur, peste driverul PDO. Nu compilați nimic: un <code>require</code> definește cele 39 de funcții globale. Link-urile sunt obiecte <code>Informix\\Connection</code>, rezultatele sunt <code>Informix\\Result</code>, iar handle-urile LOB/CHAR/SLOB rămân identificatori întregi, ca în extensie.', 'The library reimplements the procedural API in pure PHP over the PDO driver. Nothing is compiled: one <code>require</code> defines the 39 global functions. Links are <code>Informix\\Connection</code> objects, results are <code>Informix\\Result</code>, and LOB/CHAR/SLOB handles stay integer identifiers, as in the extension.')}</p>"
            + ul([
                t(lang, "Potrivit pentru deployment simplu, shared hosting și prototipuri.", "Suitable for simple deployment, shared hosting and prototypes."),
                t(lang, "Necesită <code>pdo_informix</code> și <code>ext-pdo</code>.", "Requires <code>pdo_informix</code> and <code>ext-pdo</code>."),
                t(lang, "Nu intră în conflict cu extensia C: funcțiile sunt definite doar dacă nu există deja.", "It does not conflict with the C extension: functions are defined only if they do not already exist."),
            ])
            + note("info", t(lang, "<strong>Obiecte la suprafață, handle-uri în API.</strong> <code>ifx_connect()</code> întoarce un obiect, dar <code>ifx_create_blob()</code> întoarce un id întreg folosit de <code>ifx_get_blob()</code>.", "<strong>Objects at the surface, handles in the API.</strong> <code>ifx_connect()</code> returns an object, but <code>ifx_create_blob()</code> returns an integer id used by <code>ifx_get_blob()</code>."))
        )
    return body


def facts(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        deps = t(lang, "<code>ext-pdo</code>; fără <code>ifx_pdo</code>", "<code>ext-pdo</code>; no <code>ifx_pdo</code>")
        api = "PDO"
    elif kind == "ifx_pdo":
        deps = t(lang, "<code>pdo_informix</code> + <code>ext-pdo</code>", "<code>pdo_informix</code> + <code>ext-pdo</code>")
        api = t(lang, "39 funcții <code>ifx_*</code> / <code>ifxus_*</code>", "39 <code>ifx_*</code> / <code>ifxus_*</code> functions")
    elif kind == "ifx":
        deps = t(lang, "niciunul; nu folosește PDO", "none; does not use PDO")
        api = t(lang, "39 funcții <code>ifx_*</code> / <code>ifxus_*</code>", "39 <code>ifx_*</code> / <code>ifxus_*</code> functions")
    else:
        deps = t(lang, "<code>pdo_informix</code> + <code>ext-pdo</code>", "<code>pdo_informix</code> + <code>ext-pdo</code>")
        api = t(lang, "39 funcții <code>ifx_*</code> / <code>ifxus_*</code>", "39 <code>ifx_*</code> / <code>ifxus_*</code> functions")
    return table(
        [t(lang, "Element", "Item"), t(lang, "Valoare", "Value")],
        [
            [t(lang, "Binar / fișier", "Binary / file"), f"<code>{e(meta['binary'])}</code>"],
            [t(lang, "API", "API"), api],
            [t(lang, "Dependențe", "Dependencies"), deps],
            [t(lang, "CSDK", "CSDK"), t(lang, "4.50 sau compatibil", "4.50 or compatible")],
            [t(lang, "Suită proprie", "Own suite"), f"<strong>{e(meta['tests'])}</strong>"],
        ],
    )


def requirements(lang, meta):
    kind = meta["kind"]
    rows = [
        [t(lang, "PHP", "PHP"), t(lang, "7.0–8.5 x64; NTS sau ZTS, același build ca extensia/driverul.", "7.0–8.5 x64; NTS or ZTS, same build as the extension/driver.")],
        [t(lang, "Informix Client SDK", "Informix Client SDK"), t(lang, "4.50 sau compatibil, cu bibliotecile CLI/ODBC și uneltele de build.", "4.50 or compatible, with the CLI/ODBC libraries and build tools.")],
        [t(lang, "Server Informix", "Informix server"), t(lang, "IDS 14.10.FC12 sau compatibil; orice bază de test.", "IDS 14.10.FC12 or compatible; any test database.")],
    ]
    if kind == "pdo":
        rows += [
            [t(lang, "ext-pdo", "ext-pdo"), t(lang, "Obligatoriu; este API-ul folosit de driver.", "Required; it is the API used by the driver.")],
            [t(lang, "pdo_informix", "pdo_informix"), t(lang, "Acest modul este driverul; nu are nevoie de alt driver.", "This module is the driver; it does not need another driver.")],
            [t(lang, "Build tools", "Build tools"), t(lang, "Visual Studio 2019/2022 cu C++ pe Windows; <code>phpize</code>, GCC, make pe Linux.", "Visual Studio 2019/2022 with C++ on Windows; <code>phpize</code>, GCC, make on Linux.")],
        ]
    elif kind == "ifx_pdo":
        rows += [
            [t(lang, "ext-pdo", "ext-pdo"), t(lang, "Obligatoriu pentru motorul încorporat.", "Required by the embedded engine.")],
            [t(lang, "pdo_informix", "pdo_informix"), t(lang, "Obligatoriu la runtime; se încarcă înaintea lui <code>ifx_pdo</code>.", "Required at runtime; load it before <code>ifx_pdo</code>.")],
            [t(lang, "Build tools", "Build tools"), t(lang, "Pachet PHP de dezvoltare + Visual Studio pe Windows; phpize/GCC pe Linux. Nu linkează direct CSDK.", "PHP development pack + Visual Studio on Windows; phpize/GCC on Linux. It does not link CSDK directly.")],
        ]
    elif kind == "ifx":
        rows += [
            [t(lang, "ext-pdo", "ext-pdo"), t(lang, "Nu este necesar.", "Not required.")],
            [t(lang, "pdo_informix", "pdo_informix"), t(lang, "Nu este necesar; varianta este independentă.", "Not required; this variant is independent.")],
            [t(lang, "Build tools", "Build tools"), t(lang, "Pachet PHP de dezvoltare + Visual Studio pe Windows; phpize/GCC și CSDK pe Linux.", "PHP development pack + Visual Studio on Windows; phpize/GCC and CSDK on Linux.")],
        ]
    else:
        rows += [
            [t(lang, "ext-pdo", "ext-pdo"), t(lang, "Obligatoriu; biblioteca folosește PDO.", "Required; the library uses PDO.")],
            [t(lang, "pdo_informix", "pdo_informix"), t(lang, "Obligatoriu la runtime.", "Required at runtime.")],
            [t(lang, "Build tools", "Build tools"), t(lang, "Niciunul; nu se compilează nimic.", "None; nothing is compiled.")],
        ]
    return table([t(lang, "Componentă", "Component"), t(lang, "Cerință", "Requirement")], rows) + note(
        "warning",
        t(lang, "<strong>PHP 7.0–7.3 sunt EOL.</strong> Sunt acceptate pentru aplicații legacy, dar nu mai primesc update-uri de securitate.", "<strong>PHP 7.0–7.3 are EOL.</strong> They are accepted for legacy applications but no longer receive security updates."),
    )


def installation(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        body = (
            f"<p>{t(lang, 'Compilați driverul cu header-ele PHP-ului țintă. Pe Windows, <code>build.bat</code> produce <code>php_pdo_informix.dll</code>; pe Linux, <code>phpize</code> produce <code>pdo_informix.so</code>.', 'Build the driver with the target PHP headers. On Windows, <code>build.bat</code> produces <code>php_pdo_informix.dll</code>; on Linux, <code>phpize</code> produces <code>pdo_informix.so</code>.')}</p>"
            + code(lang, '''cd pdo_informix/extension
build.bat "C:\\php-devel\\devel\\php-8.5.11-devel-vs17-x64" "C:\\IBM\\Informix_Client_SDK"''', "build.bat", "bat")
            + f"<p>{t(lang, 'Pentru PHP &lt; 8.1 aplicați patch-ul JSON/BSON din <code>docs/reference/pdo-informix-php7.patch</code>; pentru PHP 7.0–7.4 aplicați și patch-ul de linker.', 'For PHP &lt; 8.1 apply the JSON/BSON patch from <code>docs/reference/pdo-informix-php7.patch</code>; for PHP 7.0–7.4 also apply the linker patch.')}</p>"
            + code(lang, '''cd pdo_informix/src
phpize
./configure --with-pdo-informix=/opt/IBM/informix
make -j2''', "build.sh", "bash")
            + f"<p>{t(lang, 'Instalare:', 'Install:')}</p>"
            + code(lang, '''; php.ini
extension=pdo_informix''', "php.ini", "ini")
            + code(lang, '''php -m | findstr /i pdo_informix
php -r "var_dump(PDO::getAvailableDrivers());"''', "verify", "bat")
        )
    elif kind == "ifx_pdo":
        body = (
            f"<p>{t(lang, 'Extensia se compilează separat de driver. Are nevoie de pachetul PHP de dezvoltare, nu de CSDK la linkare; driverul aduce CSDK la runtime.', 'The extension is built separately from the driver. It needs the PHP development pack, not CSDK at link time; the driver brings CSDK at runtime.')}</p>"
            + code(lang, '''cd ifx_pdo_informix
build-ifx.bat "C:\\php-devel\\devel\\php-8.5.11-devel-vs17-x64" ifx.dll "C:\\IBM\\Informix_Client_SDK"''', "build-ifx.bat", "bat")
            + code(lang, '''cd ifx_pdo_informix
sh build-ifx-linux.sh''', "build-ifx-linux.sh", "bash")
            + f"<p>{t(lang, 'După modificări în <code>php_informix/src/</code>, regenerați sursa încorporată:', 'After changes in <code>php_informix/src/</code>, regenerate the embedded source:')}</p>"
            + code(lang, '''php ifx_pdo_informix/generate-embedded.php
cd ifx_pdo_informix && build-ifx.bat "C:\\php-devel\\devel\\php-8.5.11-devel-vs17-x64"''', "regenerate", "bat")
            + f"<p>{t(lang, 'Instalare, cu driverul primul:', 'Install, driver first:')}</p>"
            + code(lang, '''; php.ini
extension=pdo_informix
extension=ifx_pdo''', "php.ini", "ini")
            + code(lang, '''php -m | findstr /i ifx
php --ri ifx_pdo''', "verify", "bat")
        )
    elif kind == "ifx":
        body = (
            f"<p>{t(lang, 'Varianta directă se compilează cu header-ele PHP-ului țintă și cu CSDK. Nu are nevoie de <code>pdo_informix</code>.', 'The direct variant is built with the target PHP headers and CSDK. It does not need <code>pdo_informix</code>.')}</p>"
            + code(lang, '''cd ifx_informix
build-ifx.bat "C:\\php-devel\\devel\\php-8.5.11-devel-vs17-x64"''', "build-ifx.bat", "bat")
            + code(lang, '''cd ifx_informix
IFX_CSDK=/opt/IBM/informix ./build-ifx-linux.sh''', "build-ifx-linux.sh", "bash")
            + f"<p>{t(lang, 'Pentru PHP 7.0–7.4, DLL-ul trebuie marcat cu versiunea de linker a PHP-ului: 14.0 pentru 7.0/7.1, 14.16 pentru 7.2–7.4. <code>tests/run-native.ps1 -Build</code> o face automat.', 'For PHP 7.0–7.4, the DLL must be marked with the PHP linker version: 14.0 for 7.0/7.1, 14.16 for 7.2–7.4. <code>tests/run-native.ps1 -Build</code> does it automatically.')}</p>"
            + code(lang, '''; php.ini
extension=ifx''', "php.ini", "ini")
            + code(lang, '''php -m | findstr /i ifx
php -r "var_dump(extension_loaded('ifx'), function_exists('ifx_connect'));"''', "verify", "bat")
        )
    else:
        body = (
            f"<p>{t(lang, 'Nu se compilează nimic. Copiați directorul <code>php_informix/</code> în proiect sau instalați-l cu Composer, apoi încărcați autoload-ul.', 'Nothing is compiled. Copy the <code>php_informix/</code> directory into the project or install it with Composer, then load the autoloader.')}</p>"
            + code(lang, '''require __DIR__ . '/php_informix/src/autoload.php';''', "require.php")
            + code(lang, '''composer require informix/ifx-compat''', "composer", "bash")
            + f"<p>{t(lang, 'Driverul <code>pdo_informix</code> rămâne obligatoriu. Verificați:', 'The <code>pdo_informix</code> driver is still required. Verify:')}</p>"
            + code(lang, '''php -r "var_dump(PDO::getAvailableDrivers());"''', "verify", "bash")
        )
    return body


def configuration(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        rows = [
            ["<code>PDO::ATTR_ERRMODE</code>", "<code>PDO::ERRMODE_EXCEPTION</code>", t(lang, "Aruncă excepții la erori.", "Throws exceptions on errors.")],
            ["<code>PDO::ATTR_DEFAULT_FETCH_MODE</code>", "<code>PDO::FETCH_ASSOC</code>", t(lang, "Rânduri asociative implicit.", "Associative rows by default.")],
            ["<code>PDO::ATTR_PERSISTENT</code>", "true / false", t(lang, "Conexiuni persistente, dacă driverul le suportă.", "Persistent connections, if the driver supports them.")],
            ["<code>PDO::ATTR_TIMEOUT</code>", t(lang, "secunde", "seconds"), t(lang, "Timeout de conectare.", "Connection timeout.")],
        ]
        body = (
            f"<p>{t(lang, 'Driverul nu are directive <code>ifx.*</code>. Configurarea se face prin DSN, variabile de mediu și atribute PDO.', 'The driver has no <code>ifx.*</code> directives. Configure it through the DSN, environment variables and PDO attributes.')}</p>"
            + table([t(lang, "Atribut", "Attribute"), t(lang, "Valoare", "Value"), t(lang, "Efect", "Effect")], rows)
            + code(lang, '''$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'),
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);''', "pdo-options.php")
            + note("info", t(lang, "<strong>DSN scurt vs DSN complet.</strong> Un DSN care conține <code>=</code> este trimis prin <code>SQLDriverConnect</code>; forma scurtă <code>informix:testdb</code> are nevoie de o intrare în <code>odbc.ini</code>.", "<strong>Short DSN vs full DSN.</strong> A DSN containing <code>=</code> is sent through <code>SQLDriverConnect</code>; the short form <code>informix:testdb</code> needs an <code>odbc.ini</code> entry."))
        )
    elif kind == "ifx_pdo":
        rows = [
            ["<code>ifx.blobinfile</code>", "1", t(lang, "<code>ifx_get_blob()</code> întoarce calea fișierului.", "<code>ifx_get_blob()</code> returns the file path.")],
            ["<code>ifx.textasvarchar</code>", "0", t(lang, "TEXT/CLOB ca string.", "TEXT/CLOB as string.")],
            ["<code>ifx.byteasvarchar</code>", "0", t(lang, "BYTE/BLOB ca string.", "BYTE/BLOB as string.")],
            ["<code>ifx.charasvarchar</code>", "0", t(lang, "Taie padding-ul CHAR.", "Trims CHAR padding.")],
            ["<code>ifx.nullformat</code>", "0", t(lang, "NULL ca <code>\"\"</code> sau <code>\"NULL\"</code>.", "NULL as <code>\"\"</code> or <code>\"NULL\"</code>.")],
            ["<code>ifx.default_host</code>", t(lang, "gol", "empty"), t(lang, "Server implicit.", "Default server.")],
            ["<code>ifx.default_user</code> / <code>password</code> / <code>database</code>", t(lang, "gol", "empty"), t(lang, "Valori implicite pentru conectare.", "Default connection values.")],
            ["<code>ifx.allow_persistent</code>", "1", t(lang, "Permite <code>ifx_pconnect()</code>.", "Allows <code>ifx_pconnect()</code>.")],
            ["<code>ifx.connect_timeout</code>", "-1", t(lang, "Timeout PDO când este &gt; 0.", "PDO timeout when &gt; 0.")],
            ["<code>ifx.max_links</code> / <code>max_persistent</code>", "-1", t(lang, "Limită de conexiuni; -1 = nelimitat.", "Connection limit; -1 = unlimited.")],
            ["<code>ifx.htmltbl_escape</code>", "0", t(lang, "Escapează HTML în <code>ifx_htmltbl_result()</code>.", "Escapes HTML in <code>ifx_htmltbl_result()</code>.")],
        ]
        body = (
            f"<p>{t(lang, 'Directivele sunt cele ale extensiei vechi. Sunt aplicate la începutul requestului, înainte de primul apel <code>ifx_*</code>; funcțiile <code>ifx_blobinfile_mode()</code>, <code>ifx_textasvarchar()</code> etc. le pot schimba apoi.', 'These are the legacy directives. They are applied at request start, before the first <code>ifx_*</code> call; <code>ifx_blobinfile_mode()</code>, <code>ifx_textasvarchar()</code> etc. can change them afterwards.')}</p>"
            + table([t(lang, "Directivă", "Directive"), t(lang, "Implicit", "Default"), t(lang, "Efect", "Effect")], rows)
            + code(lang, '''; php.ini
ifx.blobinfile = 1
ifx.nullformat = 0
ifx.allow_persistent = 1''', "php.ini", "ini")
        )
    elif kind == "ifx":
        body = (
            f"<p>{t(lang, 'Varianta directă nu înregistrează directive <code>ifx.*</code>. Modurile se setează prin funcții, iar conectarea implicită prin variabilele de mediu ale procesului.', 'The direct variant does not register <code>ifx.*</code> directives. Modes are set through functions, and the default connection through process environment variables.')}</p>"
            + table(
                [t(lang, "Setare", "Setting"), t(lang, "Cum", "How")],
                [
                    [t(lang, "Bază / server implicit", "Default database / server"), "<code>IFX_DEFAULT_DATABASE</code>, <code>INFORMIXDATABASE</code>"],
                    [t(lang, "Utilizator / parolă", "User / password"), "<code>IFX_DEFAULT_USER</code>, <code>IFX_DEFAULT_PASSWORD</code>"],
                    [t(lang, "Mod LOB", "LOB mode"), "<code>ifx_blobinfile_mode()</code>, <code>ifx_textasvarchar()</code>, <code>ifx_byteasvarchar()</code>"],
                    [t(lang, "CHAR / NULL", "CHAR / NULL"), "<code>ifx_charasvarchar()</code>, <code>ifx_nullformat()</code>"],
                ],
            )
            + note("info", t(lang, "<strong>Fără INI, cu intenție.</strong> Opțiunile se aplică explicit în cod, ca să nu depindă de ordinea de încărcare a modulelor.", "<strong>No INI, on purpose.</strong> Options are applied explicitly in code, so they do not depend on module load order."))
        )
    else:
        body = (
            f"<p>{t(lang, 'Biblioteca citește aceleași variabile de mediu ca extensia, dar le poți seta și prin <code>Informix\\Config</code>. Nu are directive INI.', 'The library reads the same environment variables as the extension, but you can also set them through <code>Informix\\Config</code>. It has no INI directives.')}</p>"
            + code(lang, '''Informix\\Config::set('nullformat', 1);
Informix\\Config::set('charasvarchar', 1);
Informix\\Config::set('connect_timeout', 5);''', "config.php")
            + f"<p>{t(lang, 'Variabile recunoscute: <code>IFX_BLOBINFILE</code>, <code>IFX_TEXTASVARCHAR</code>, <code>IFX_BYTEASVARCHAR</code>, <code>IFX_CHARASVARCHAR</code>, <code>IFX_NULLFORMAT</code>, <code>IFX_HTMLTBL_ESCAPE</code>, <code>IFX_ALLOW_PERSISTENT</code>, <code>IFX_CONNECT_TIMEOUT</code>, <code>IFX_DEFAULT_DATABASE</code>, <code>IFX_DEFAULT_USER</code>, <code>IFX_DEFAULT_PASSWORD</code>, <code>IFX_DEFAULT_HOST</code>, <code>IFX_HOST</code>, <code>IFX_SERVICE</code>, <code>IFX_PROTOCOL</code>, <code>IFX_USE_ODBC</code>, <code>DB_LOCALE</code>, <code>CLIENT_LOCALE</code>.', 'Recognised variables: <code>IFX_BLOBINFILE</code>, <code>IFX_TEXTASVARCHAR</code>, <code>IFX_BYTEASVARCHAR</code>, <code>IFX_CHARASVARCHAR</code>, <code>IFX_NULLFORMAT</code>, <code>IFX_HTMLTBL_ESCAPE</code>, <code>IFX_ALLOW_PERSISTENT</code>, <code>IFX_CONNECT_TIMEOUT</code>, <code>IFX_DEFAULT_DATABASE</code>, <code>IFX_DEFAULT_USER</code>, <code>IFX_DEFAULT_PASSWORD</code>, <code>IFX_DEFAULT_HOST</code>, <code>IFX_HOST</code>, <code>IFX_SERVICE</code>, <code>IFX_PROTOCOL</code>, <code>IFX_USE_ODBC</code>, <code>DB_LOCALE</code>, <code>CLIENT_LOCALE</code>.')}</p>"
        )
    return body


def connect(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        body = (
            f"<p>{t(lang, 'DSN-ul complet este cea mai portabilă formă. Folosiți variabile de mediu, nu credențiale în cod.', 'The full DSN is the most portable form. Use environment variables, not credentials in code.')}</p>"
            + code(lang, '''$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'), // informix:server=...;database=...;host=...;service=...;protocol=olsoctcp;
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS')
);''', "connect.php")
            + table(
                [t(lang, "Formă DSN", "DSN form"), t(lang, "Exemplu", "Example")],
                [
                    [t(lang, "Complet", "Full"), "<code>informix:server=ids;database=db;host=127.0.0.1;service=9088;protocol=olsoctcp;</code>"],
                    [t(lang, "Scurt", "Short"), "<code>informix:db@ids</code> " + t(lang, "(necesită <code>odbc.ini</code>)", "(needs <code>odbc.ini</code>)")],
                ],
            )
        )
    elif kind == "ifx_pdo":
        body = (
            f"<p>{t(lang, 'Sintaxa este cea a extensiei vechi. Driverul PDO trebuie încărcat înaintea extensiei.', 'The syntax is the legacy one. The PDO driver must be loaded before the extension.')}</p>"
            + code(lang, '''$conn = ifx_connect(
    getenv('IFX_DSN'),       // database@server
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);''', "connect.php")
            + code(lang, '''; php.ini
extension=pdo_informix
extension=ifx_pdo''', "php.ini", "ini")
        )
    elif kind == "ifx":
        body = (
            f"<p>{t(lang, 'Varianta directă acceptă aceleași trei argumente. Dacă lipsesc, citește variabilele de mediu; nu are nevoie de PDO.', 'The direct variant accepts the same three arguments. If omitted, it reads environment variables; it does not need PDO.')}</p>"
            + code(lang, '''$conn = ifx_connect(
    getenv('IFX_DSN'),       // database@server
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);''', "connect.php")
            + code(lang, '''; php.ini
extension=ifx''', "php.ini", "ini")
        )
    else:
        body = (
            f"<p>{t(lang, 'Biblioteca întoarce un obiect <code>Informix\\Connection</code>; restul API-ului rămâne procedural.', 'The library returns an <code>Informix\\Connection</code> object; the rest of the API stays procedural.')}</p>"
            + code(lang, '''require __DIR__ . '/php_informix/src/autoload.php';

$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

var_dump($conn instanceof Informix\\Connection); // true''', "connect.php")
        )
    return body


def example(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        text = '''<?php

$pdo = new PDO(
    getenv('PDO_INFORMIX_DSN'),
    getenv('PDO_INFORMIX_USER'),
    getenv('PDO_INFORMIX_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->query(
    'select tabid, tabname from systables where tabid <= 5 order by tabid'
);

foreach ($stmt as $row) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}

$pdo->beginTransaction();
$pdo->exec("insert into ifx_example (name) values ('pdo')");
$pdo->commit();'''
        filename = "pdo_informix.php"
    else:
        if kind == "library":
            preamble = "require __DIR__ . '/php_informix/src/autoload.php';\n\n"
        elif kind == "ifx":
            preamble = "/*\n * Windows php.ini:  extension=ifx\n * Linux php.ini:    extension=ifx.so\n */\n\n"
        else:
            preamble = "/*\n * Windows php.ini:  extension=pdo_informix\n *                   extension=ifx_pdo\n * Linux php.ini:    extension=pdo_informix.so\n *                   extension=ifx_pdo.so\n */\n\n"
        text = "<?php\n\n" + preamble + '''$conn = ifx_connect(
    getenv('IFX_DSN'),
    getenv('IFX_USER'),
    getenv('IFX_PASSWORD')
);

$res = ifx_query(
    'select tabid, tabname from systables where tabid <= 5 order by tabid',
    $conn,
    IFX_SCROLL
);

echo 'Coloane: ', ifx_num_fields($res), PHP_EOL;

while (($row = ifx_fetch_row($res)) !== false) {
    echo $row['tabid'], ' ', $row['tabname'], PHP_EOL;
}

ifx_free_result($res);
ifx_close($conn);'''
        filename = "ifx_example.php"
    return code(lang, text, filename)


def api(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        rows = [
            ["<code>new PDO($dsn, $user, $password, $options)</code>", t(lang, "Deschide conexiunea; opțiunile sunt atribute PDO.", "Opens the connection; options are PDO attributes.")],
            ["<code>PDO::query($sql)</code>", t(lang, "Execută un statement și întoarce <code>PDOStatement</code>.", "Executes a statement and returns a <code>PDOStatement</code>.")],
            ["<code>PDO::exec($sql)</code>", t(lang, "Execută un statement fără rezultat; întoarce rândurile afectate.", "Executes a statement without a result; returns affected rows.")],
            ["<code>PDO::prepare($sql, $options)</code>", t(lang, "Preparează un statement; execuția cu <code>execute()</code>.", "Prepares a statement; execute with <code>execute()</code>.")],
            ["<code>PDOStatement::execute($params)</code>", t(lang, "Execută statement-ul, cu parametri opționali.", "Executes the statement, with optional parameters.")],
            ["<code>PDOStatement::fetch($mode)</code>", t(lang, "Citește un rând în modul cerut.", "Fetches one row in the requested mode.")],
            ["<code>PDOStatement::fetchAll($mode)</code>", t(lang, "Citește toate rândurile.", "Fetches all rows.")],
            ["<code>PDOStatement::rowCount()</code> / <code>columnCount()</code>", t(lang, "Numărul de rânduri afectate / coloane.", "Affected rows / column count.")],
            ["<code>PDOStatement::closeCursor()</code>", t(lang, "Închide cursorul și eliberează resursele.", "Closes the cursor and releases resources.")],
            ["<code>PDOStatement::bindValue()</code> / <code>bindParam()</code>", t(lang, "Leagă valori sau referințe, inclusiv <code>PDO::PARAM_LOB</code>.", "Binds values or references, including <code>PDO::PARAM_LOB</code>.")],
            ["<code>PDOStatement::getColumnMeta($n)</code>", t(lang, "Metadate pentru coloană, când driverul le oferă.", "Column metadata, when the driver provides it.")],
            ["<code>PDO::beginTransaction()</code> / <code>commit()</code> / <code>rollBack()</code>", t(lang, "Tranzacții PDO.", "PDO transactions.")],
            ["<code>PDO::lastInsertId()</code>, <code>quote()</code>, <code>errorInfo()</code>", t(lang, "Funcții standard PDO.", "Standard PDO helpers.")],
        ]
        body = (
            f"<p>{t(lang, 'Driverul expune API-ul PDO, nu funcțiile <code>ifx_*</code>. Dacă ai nevoie de API-ul procedural, folosește <code>ifx_pdo</code>, <code>ifx</code> sau biblioteca.', 'The driver exposes the PDO API, not the <code>ifx_*</code> functions. If you need the procedural API, use <code>ifx_pdo</code>, <code>ifx</code> or the library.')}</p>"
            + table([t(lang, "API", "API"), t(lang, "Descriere", "Description")], rows)
        )
    else:
        body = (
            f"<p>{t(lang, 'Toate cele 39 de funcții sunt globale și au semnăturile extensiei originale. Link-urile și rezultatele sunt resurse în <code>ifx_pdo</code>/<code>ifx</code>, respectiv obiecte în bibliotecă; handle-urile LOB/CHAR/SLOB sunt identificatori întregi.', 'All 39 functions are global and keep the original signatures. Links and results are resources in <code>ifx_pdo</code>/<code>ifx</code>, and objects in the library; LOB/CHAR/SLOB handles are integer identifiers.')}</p>"
            + api_table(lang)
        )
        if kind == "ifx":
            body = body.replace(
                "Deschide o conexiune; cache după DSN + user + parolă.",
                "Deschide o conexiune nouă către baza indicată.",
            ).replace(
                "Conexiune persistentă prin PDO::ATTR_PERSISTENT.",
                "Refolosește conexiunea cu aceleași argumente în request (fără PDO).",
            ).replace(
                "Opens a connection; cached by DSN + user + password.",
                "Opens a new connection to the given database.",
            ).replace(
                "Persistent connection via PDO::ATTR_PERSISTENT.",
                "Reuses the connection with the same arguments within the request (no PDO).",
            )
        if kind == "ifx":
            body += note("info", t(lang, "<strong>Constante:</strong> <code>IFX_SCROLL</code>, <code>IFX_HOLD</code> și <code>IFX_LO_*</code> sunt definite de extensie. Link-urile și rezultatele sunt resurse; handle-urile LOB/CHAR/SLOB sunt identificatori întregi.", "<strong>Constants:</strong> <code>IFX_SCROLL</code>, <code>IFX_HOLD</code> and <code>IFX_LO_*</code> are defined by the extension. Links and results are resources; LOB/CHAR/SLOB handles are integer identifiers."))
        elif kind == "ifx_pdo":
            body += note("info", t(lang, "<strong>Constante:</strong> <code>IFX_SCROLL</code>, <code>IFX_HOLD</code> și <code>IFX_LO_*</code> sunt definite de extensie. Link-urile și rezultatele sunt resurse Zend; handle-urile LOB/CHAR/SLOB sunt identificatori întregi.", "<strong>Constants:</strong> <code>IFX_SCROLL</code>, <code>IFX_HOLD</code> and <code>IFX_LO_*</code> are defined by the extension. Links and results are Zend resources; LOB/CHAR/SLOB handles are integer identifiers."))
        else:
            body += note("info", t(lang, "<strong>Constante:</strong> <code>IFX_SCROLL</code>, <code>IFX_HOLD</code> și <code>IFX_LO_*</code> sunt definite de bibliotecă. <code>ifx_connect()</code> întoarce <code>Informix\\Connection</code>, <code>ifx_query()</code> întoarce <code>Informix\\Result</code>, iar handle-urile LOB/CHAR/SLOB rămân id-uri întregi.", "<strong>Constants:</strong> <code>IFX_SCROLL</code>, <code>IFX_HOLD</code> and <code>IFX_LO_*</code> are defined by the library. <code>ifx_connect()</code> returns <code>Informix\\Connection</code>, <code>ifx_query()</code> returns <code>Informix\\Result</code>, and LOB/CHAR/SLOB handles stay integer ids."))
    return body


def lobs(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        body = (
            f"<p>{t(lang, 'TEXT/BYTE/CLOB/BLOB se citesc ca stream-uri PDO; JSON/BSON se întorc ca string. Pentru inserare folosiți <code>PDO::PARAM_LOB</code> cu un stream.', 'TEXT/BYTE/CLOB/BLOB are read as PDO streams; JSON/BSON are returned as strings. For inserts use <code>PDO::PARAM_LOB</code> with a stream.')}</p>"
            + code(lang, '''$fp = fopen('php://temp', 'r+');
fwrite($fp, "binary\\0data");
rewind($fp);

$stmt = $pdo->prepare('insert into ifx_lob (payload) values (?)');
$stmt->bindValue(1, $fp, PDO::PARAM_LOB);
$stmt->execute();

$row = $pdo->query('select payload from ifx_lob')->fetch(PDO::FETCH_ASSOC);
echo stream_get_contents($row['payload']);''', "lob.php")
        )
    else:
        if kind == "ifx_pdo":
            lead = t(lang, "În <code>ifx_pdo</code>, <code>ifx.blobinfile=1</code> este implicit: rândul conține <strong>blob id</strong>, iar <code>ifx_get_blob()</code> întoarce calea fișierului temporar. Cu <code>ifx_blobinfile_mode(0)</code>, conținutul rămâne în memorie.", "In <code>ifx_pdo</code>, <code>ifx.blobinfile=1</code> is the default: the row contains a <strong>blob id</strong>, and <code>ifx_get_blob()</code> returns the temporary file path. With <code>ifx_blobinfile_mode(0)</code>, the content stays in memory.")
        elif kind == "ifx":
            lead = t(lang, "Implicit, <code>blobinfile=0</code>: rândul conține <strong>blob id</strong>, iar <code>ifx_get_blob()</code> întoarce conținutul din memorie. <code>ifx_blobinfile_mode(1)</code> trece pe fișier temporar.", "By default, <code>blobinfile=0</code>: the row contains a <strong>blob id</strong>, and <code>ifx_get_blob()</code> returns the in-memory content. <code>ifx_blobinfile_mode(1)</code> switches to a temporary file.")
        else:
            lead = t(lang, "Implicit, <code>blobinfile=0</code> în <code>Informix\\Config</code>: rândul conține <strong>blob id</strong>, iar <code>ifx_get_blob()</code> întoarce conținutul. Setarea se face cu <code>Informix\\Config::set('blobinfile', 1)</code> sau cu funcția legacy.", "By default, <code>blobinfile=0</code> in <code>Informix\\Config</code>: the row contains a <strong>blob id</strong>, and <code>ifx_get_blob()</code> returns the content. Set it with <code>Informix\\Config::set('blobinfile', 1)</code> or the legacy function.")
        body = (
            f"<p>{lead}</p>"
            + code(lang, '''$text = ifx_create_blob(1, 0, 'Hello from ifx-compat!');
$bytes = ifx_create_blob(0, 0, "binary\\0data");

$res = ifx_query(
    'insert into ifx_lob (txt, byt) values (?, ?)',
    $conn,
    [$text, $bytes]
);
echo ifx_affected_rows($res);''', "lob-insert.php")
            + table(
                [t(lang, "Mod", "Mode"), t(lang, "Setare", "Setting"), t(lang, "Rezultat", "Result")],
                [
                    [t(lang, "Blob id", "Blob id"), t(lang, "implicit", "default"), t(lang, "Întreg; <code>ifx_get_blob()</code> întoarce conținutul.", "Integer; <code>ifx_get_blob()</code> returns the content.")],
                    [t(lang, "String", "String"), "<code>ifx_textasvarchar(1)</code> / <code>ifx_byteasvarchar(1)</code>", t(lang, "Conținutul direct în rând.", "Content directly in the row.")],
                    [t(lang, "Fișier", "File"), "<code>ifx_blobinfile_mode(1)</code>", t(lang, "<code>ifx_get_blob()</code> întoarce calea unui fișier temporar.", "<code>ifx_get_blob()</code> returns a temporary file path.")],
                ],
            )
            + code(lang, '''ifx_blobinfile_mode(1);
$row = ifx_fetch_row(ifx_query('select txt from ifx_lob', $conn));
echo file_get_contents(ifx_get_blob($row['txt']));

$ch = ifx_create_char('value');
ifx_update_char($ch, 'updated');
echo ifx_get_char($ch);
ifx_free_char($ch);''', "lob-read.php")
            + f"<p>{t(lang, 'SLOB-urile sunt bufferi client-side, nu obiecte <code>ifx_lo_*</code> pe server. Pentru fișiere mari, modul fișier evită dublarea memoriei.', 'SLOBs are client-side buffers, not server-side <code>ifx_lo_*</code> objects. For large files, file mode avoids duplicating memory.')}</p>"
        )
    return body


def transactions(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        body = code(lang, '''try {
    $pdo->beginTransaction();
    $pdo->exec("insert into ifx_t (name) values ('one')");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}''', "transaction.php")
    else:
        body = code(lang, '''$res = @ifx_query('select * from missing_table', $conn);
if ($res === false) {
    echo ifx_error(), PHP_EOL;      // E, W or N
    echo ifx_errormsg(), PHP_EOL;   // driver message
}

ifx_query('begin work', $conn);
// ...
ifx_query('commit work', $conn);''', "errors.php")
        body += f"<p>{t(lang, 'Cursoarele nu supraviețuiesc unui <code>COMMIT</code>; după commit recitiți rezultatul. <code>ifx_getsqlca()</code> oferă <code>sqlerrd1</code> și <code>sqlerrd2</code> pentru ultimul DML.', 'Cursors do not survive a <code>COMMIT</code>; re-read the result after commit. <code>ifx_getsqlca()</code> provides <code>sqlerrd1</code> and <code>sqlerrd2</code> for the last DML.')}</p>"
    return body


def testing(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        body = (
            f"<p>{t(lang, 'Suita driverului are 9 teste și 198 de aserțiuni. Rulează real împotriva serverului, cu DSN-ul și credențialele din mediu. Extensia trebuie încărcată explicit dacă nu este în <code>php.ini</code>.', 'The driver suite has 9 tests and 198 assertions. It runs against the real server, with the DSN and credentials from the environment. The extension must be loaded explicitly if it is not in <code>php.ini</code>.')}</p>"
            + code(lang, '''set PDO_INFORMIX_DSN=informix:server=<server>;database=<database>;host=<host>;service=<service>;protocol=olsoctcp;
set PDO_INFORMIX_USER=<user>
set PDO_INFORMIX_PASS=<password>
php -d extension_dir=C:\\php\\ext -d extension=pdo_informix pdo_informix/test/run-tests.php''', "run-tests.bat", "bat")
            + code(lang, '''export PDO_INFORMIX_DSN='informix:server=<server>;database=<database>;host=<host>;service=<service>;protocol=olsoctcp;'
export PDO_INFORMIX_USER='<user>'
export PDO_INFORMIX_PASS='<password>'
php -d extension=pdo_informix pdo_informix/test/run-tests.php''', "run-tests.sh", "bash")
        )
    elif kind == "ifx_pdo":
        body = (
            f"<p>{t(lang, 'Suita nativă are 92 de verificări. Are nevoie de ambele extensii încărcate, driverul primul, și de DSN-ul PDO separat.', 'The native suite has 92 checks. It needs both extensions loaded, driver first, and a separate PDO DSN.')}</p>"
            + code(lang, '''$env:INFORMIXDIR = 'C:\\IBM\\informix'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = 'C:\\IBM\\informix\\etc\\sqlhosts'
$env:IFX_TEST_DSN = '<database>@<server>'
$env:IFX_TEST_PDO_DSN = 'informix:server=<server>;database=<database>;host=<host>;service=<service>;protocol=olsoctcp;'
$env:IFX_TEST_USER = '<user>'
$env:IFX_TEST_PASSWORD = '<password>'
$env:IFX_PDO_EXTENSION_DIR = 'C:\\php\\ext'

.\\ifx_pdo_informix\\tests\\run-native.ps1 `
  -Php C:\\php\\php.exe `
  -PdoDll php_pdo_informix `
  -Dll C:\\path\\to\\ifx_pdo.dll''', "run-native.ps1", "powershell")
        )
    elif kind == "ifx":
        body = (
            f"<p>{t(lang, 'Suita directă are 102 verificări și nu depinde de <code>pdo_informix</code>. A fost rulată pe PHP 7.0–8.5 x64, cu 102/102 pe fiecare versiune.', 'The direct suite has 102 checks and does not depend on <code>pdo_informix</code>. It was run on PHP 7.0–8.5 x64, with 102/102 on every version.')}</p>"
            + code(lang, '''$env:INFORMIXDIR = 'C:\\IBM\\informix'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = 'C:\\IBM\\informix\\etc\\sqlhosts'
$env:IFX_TEST_DSN = '<database>@<server>'
$env:IFX_TEST_USER = '<user>'
$env:IFX_TEST_PASSWORD = '<password>'

.\\ifx_informix\\tests\\run-native.ps1 `
  -Php C:\\php\\php.exe `
  -Dll C:\\path\\to\\ifx.dll''', "run-native.ps1", "powershell")
        )
    else:
        body = (
            f"<p>{t(lang, 'Suita bibliotecii are 93 de teste trecute și 1 sărit (UTF-8 pe o bază <code>en_US.819</code>). Rulează prin PDO, fără extensie C; driverul trebuie încărcat.', 'The library suite has 93 passing tests and 1 skipped (UTF-8 on an <code>en_US.819</code> database). It runs over PDO, without a C extension; the driver must be loaded.')}</p>"
            + code(lang, '''$env:INFORMIXDIR = 'C:\\IBM\\informix'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = 'C:\\IBM\\informix\\etc\\sqlhosts'
$env:IFX_TEST_DSN = '<database>@<server>'
$env:IFX_TEST_USER = '<user>'
$env:IFX_TEST_PASSWORD = '<password>'

php -d extension=pdo_informix php_informix/tests/run-tests.php''', "run-tests.ps1", "powershell")
        )
    cleanup = t(lang, "<strong>Teste reale.</strong> Suitele creează tabele temporare, rulează DML, LOB-uri și cursoare, apoi curăță datele. Nu folosesc mock-uri.", "<strong>Real tests.</strong> The suites create temporary tables, run DML, LOBs and cursors, then clean up. They do not use mocks.")
    if kind == "pdo":
        cleanup = t(lang, "<strong>Teste reale.</strong> Rulează DML, LOB-uri și cursoare. Testele <code>t07_lob_trigger</code> și <code>t08_merge</code> păstrează intenționat tabele de diagnostic; curățați baza de test după rulare.", "<strong>Real tests.</strong> They run DML, LOBs and cursors. <code>t07_lob_trigger</code> and <code>t08_merge</code> intentionally keep diagnostic tables; clean the test database afterwards.")
    return body + note("info", cleanup)


def perf(lang, meta):
    key = meta["kind"]
    data = BENCH[key]
    base = BENCH["pdo"]
    labels = [
        ("conn", t(lang, "Conexiune + închidere", "Connect + close")),
        ("select", t(lang, "SELECT punctual", "Point SELECT")),
        ("count", t(lang, "COUNT(*)", "COUNT(*)")),
        ("scan", t(lang, "Scanare catalog", "Catalog scan")),
        ("insert", t(lang, "INSERT", "INSERT")),
        ("update", t(lang, "UPDATE", "UPDATE")),
    ]
    fmt = lambda n: f"{n:.4f}".replace(".", "," if lang == "ro" else ".")
    rows = []
    for k, label in labels:
        val = data[k]
        if key == "pdo":
            rows.append([label, fmt(val)])
        else:
            rows.append([label, fmt(val), fmt(base[k]), f"×{val / base[k]:.2f}".replace(".", "," if lang == "ro" else ".")])
    if key == "pdo":
        body = table(
            [t(lang, "Operație", "Operation"), t(lang, "pdo_informix (ms/op)", "pdo_informix (ms/op)")],
            rows,
        )
        body += table(
            [t(lang, "Conexiuni simultane", "Concurrent connections"), t(lang, "pdo_informix (ops/s)", "pdo_informix (ops/s)")],
            [["1", str(data["ops1"])], ["25", str(data["ops25"])]],
        )
    else:
        body = table(
            [t(lang, "Operație", "Operation"), t(lang, "Modul (ms/op)", "Module (ms/op)"), t(lang, "PDO direct (ms/op)", "Direct PDO (ms/op)"), t(lang, "Raport", "Ratio")],
            rows,
        )
        body += table(
            [t(lang, "Conexiuni simultane", "Concurrent connections"), t(lang, "Modul (ops/s)", "Module (ops/s)"), t(lang, "PDO direct (ops/s)", "Direct PDO (ops/s)")],
            [["1", str(data["ops1"]), str(base["ops1"])], ["25", str(data["ops25"]), str(base["ops25"])]],
        )
    body += f"<p>{t(lang, 'Măsurat pe PHP 8.5.11 NTS x64, Informix IDS 14.10.FC12, local, fără rețea între client și server. Valorile sunt orientative; recompilați și remăsurați în mediul vostru.', 'Measured on PHP 8.5.11 NTS x64, Informix IDS 14.10.FC12, locally, with no network hop between client and server. Values are indicative; rebuild and re-measure in your environment.')}</p>"
    if key == "ifx":
        body += f"<p>{t(lang, 'Varianta directă este cea mai apropiată de PDO la interogări simple, dar mai lentă la scanare și UPDATE din cauza citirii fiecărei coloane cu <code>SQLGetData</code>.', 'The direct variant is closest to PDO on simple queries, but slower on scans and UPDATE because every column is read with <code>SQLGetData</code>.')}</p>"
    elif key in ("ifx_pdo", "library"):
        body += f"<p>{t(lang, 'Varianta rulează peste PDO, deci plătește marshalling-ul suplimentar al stratului PHP; diferențele față de PDO sunt vizibile mai ales la UPDATE și scanare.', 'This variant runs over PDO, so it pays the extra PHP-layer marshalling; the differences from PDO are most visible on UPDATE and scans.')}</p>"
    else:
        body += f"<p>{t(lang, 'pdo_informix este referința de viteză pentru celelalte variante; folosiți-l când API-ul PDO este suficient.', 'pdo_informix is the speed reference for the other variants; use it when the PDO API is enough.')}</p>"
    return body

def compatibility(lang, meta, compat_rows):
    rows = [[f"PHP {php}", f"<strong>{result}</strong>"] for php, result in compat_rows]
    body = table([t(lang, "PHP", "PHP"), t(lang, "Rezultat", "Result")], rows)
    if meta["kind"] == "pdo":
        body += f"<p>{t(lang, '<code>✓</code> înseamnă că driverul a fost compilat și folosit de suitele <code>ifx_pdo</code>/bibliotecă pe versiunea respectivă. Suita proprie are 9/9 teste și 198 de aserțiuni pe 8.5.11.', '<code>✓</code> means the driver was built and used by the <code>ifx_pdo</code>/library suites on that version. Its own suite has 9/9 tests and 198 assertions on 8.5.11.')}</p>"
    elif meta["kind"] == "ifx":
        body += f"<p>{t(lang, 'Toate rezultatele directe au fost obținute pe Windows x64 (7.x–8.4 ZTS, 8.5 NTS). Scriptul Linux este disponibil, dar nu este inclus în această matrice.', 'All direct results were obtained on Windows x64 (7.x–8.4 ZTS, 8.5 NTS). The Linux script is available but is not part of this matrix.')}</p>"
    else:
        body += f"<p>{t(lang, 'Rezultatele sunt din suitele reale, pe fiecare build PHP; nu sunt estimări.', 'The results come from the real suites on each PHP build; they are not estimates.')}</p>"
    ref = "../reference" if lang == "en" else "reference"
    body += f'<p class="small-note"><a href="{ref}/COMPATIBILITY.md">{t(lang, "Matricea completă și notele de testare", "The full matrix and testing notes")}</a></p>'
    return body


BENCH = {
    "pdo": {"conn": 27.42, "select": 0.7173, "count": 0.6645, "scan": 1.5123, "insert": 1.1075, "update": 0.3222, "ops1": 1079, "ops25": 3005},
    "ifx": {"conn": 29.23, "select": 0.7256, "count": 0.6586, "scan": 2.5207, "insert": 1.1742, "update": 0.5125, "ops1": 976, "ops25": 2691},
    "ifx_pdo": {"conn": 27.31, "select": 0.7697, "count": 0.7187, "scan": 2.0774, "insert": 1.4796, "update": 0.6664, "ops1": 859, "ops25": 2621},
    "library": {"conn": 29.89, "select": 0.7635, "count": 0.7120, "scan": 2.0179, "insert": 1.4219, "update": 0.6583, "ops1": 874, "ops25": 2717},
}


def troubleshooting(lang, meta):
    kind = meta["kind"]
    common = [
        [t(lang, "Modulul nu se încarcă", "The module does not load"), t(lang, "Verificați NTS/ZTS, x64 și versiunea PHP; un DLL construit pentru altă versiune nu se încarcă.", "Check NTS/ZTS, x64 and the PHP version; a DLL built for another version will not load.")],
        [t(lang, "Lipsește CSDK la runtime", "CSDK missing at runtime"), t(lang, "Adăugați <code>%INFORMIXDIR%\\bin</code> (Windows) sau <code>$INFORMIXDIR/lib</code> (Linux) în <code>PATH</code>/<code>LD_LIBRARY_PATH</code>.", "Add <code>%INFORMIXDIR%\\bin</code> (Windows) or <code>$INFORMIXDIR/lib</code> (Linux) to <code>PATH</code>/<code>LD_LIBRARY_PATH</code>.")],
        [t(lang, "Serverul nu este găsit", "Server not found"), t(lang, "Verificați <code>INFORMIXSERVER</code>, <code>INFORMIXSQLHOSTS</code> și intrarea din <code>sqlhosts</code>.", "Check <code>INFORMIXSERVER</code>, <code>INFORMIXSQLHOSTS</code> and the <code>sqlhosts</code> entry.")],
    ]
    if kind == "pdo":
        rows = common + [
            [t(lang, "Linker 14.29 vs 14.16", "Linker 14.29 vs 14.16"), t(lang, "PHP 7.0–7.4: aplicați <code>ifx_pdo_informix/tools/patch-pe-linker.ps1</code> pe DLL.", "PHP 7.0–7.4: apply <code>ifx_pdo_informix/tools/patch-pe-linker.ps1</code> to the DLL.")],
            [t(lang, "<code>could not find driver</code>", "<code>could not find driver</code>"), t(lang, "<code>pdo_informix</code> nu este încărcat sau nu este în <code>extension_dir</code>.", "<code>pdo_informix</code> is not loaded or is not in <code>extension_dir</code>.")],
            [t(lang, "<code>IM002</code> la ODBC", "<code>IM002</code> on ODBC"), t(lang, "Numele driverului ODBC trebuie să fie exact, de exemplu <code>IBM INFORMIX ODBC DRIVER (64-bit)</code>.", "The ODBC driver name must be exact, for example <code>IBM INFORMIX ODBC DRIVER (64-bit)</code>.")],
        ]
    elif kind == "ifx_pdo":
        rows = common + [
            [t(lang, "Linker 14.29 vs 14.16", "Linker 14.29 vs 14.16"), t(lang, "PHP 7.x: patch pe <code>ifx_pdo</code> și pe driver.", "PHP 7.x: patch both <code>ifx_pdo</code> and the driver.")],
            [t(lang, "Ordinea de încărcare", "Load order"), t(lang, "<code>extension=pdo_informix</code> trebuie să apară înaintea <code>extension=ifx_pdo</code>.", "<code>extension=pdo_informix</code> must come before <code>extension=ifx_pdo</code>.")],
            [t(lang, "Cod încorporat vechi", "Stale embedded code"), t(lang, "Rulați <code>php ifx_pdo_informix/generate-embedded.php</code> și reconstruiți.", "Run <code>php ifx_pdo_informix/generate-embedded.php</code> and rebuild.")],
        ]
    elif kind == "ifx":
        rows = common + [
            [t(lang, "Linker 14.29 vs 14.16", "Linker 14.29 vs 14.16"), t(lang, "PHP 7.0–7.4: patch cu 14.0 (7.0/7.1) sau 14.16 (7.2–7.4); runner-ul o face automat.", "PHP 7.0–7.4: patch with 14.0 (7.0/7.1) or 14.16 (7.2–7.4); the runner does it automatically.")],
            [t(lang, "Nu se încarcă împreună cu <code>ifx_pdo</code>", "Does not load with <code>ifx_pdo</code>"), t(lang, "Ambele exportă aceleași funcții; încărcați doar una.", "Both export the same functions; load only one.")],
            [t(lang, "<code>ifx_pconnect()</code> fără argumente", "<code>ifx_pconnect()</code> without arguments"), t(lang, "Setați <code>IFX_DEFAULT_DATABASE</code> în environment-ul procesului, nu doar cu <code>putenv()</code> pe PHP 7.0.", "Set <code>IFX_DEFAULT_DATABASE</code> in the process environment, not only with <code>putenv()</code> on PHP 7.0.")],
        ]
    else:
        rows = common + [
            [t(lang, "<code>could not find driver</code>", "<code>could not find driver</code>"), t(lang, "Instalați <code>pdo_informix</code> sau setați <code>IFX_USE_ODBC=1</code> pentru fallback-ul experimental.", "Install <code>pdo_informix</code> or set <code>IFX_USE_ODBC=1</code> for the experimental fallback.")],
            [t(lang, "Autoload lipsă", "Missing autoload"), t(lang, "Includeți <code>php_informix/src/autoload.php</code> înainte de primul apel.", "Include <code>php_informix/src/autoload.php</code> before the first call.")],
            [t(lang, "Workeri de lungă durată", "Long-running workers"), t(lang, "Apelați <code>Informix\\Api::reset()</code> între requesturi.", "Call <code>Informix\\Api::reset()</code> between requests.")],
        ]
    return table([t(lang, "Simptom", "Symptom"), t(lang, "Cauză / soluție", "Cause / fix")], rows)


def limits(lang, meta):
    kind = meta["kind"]
    if kind == "pdo":
        items = [
            t(lang, "Nu oferă funcțiile <code>ifx_*</code>; pentru acestea folosiți unul dintre celelalte module.", "It does not provide the <code>ifx_*</code> functions; use one of the other modules for those."),
            t(lang, "Fallback-ul ODBC este experimental și nu oferă metadate complete.", "The ODBC fallback is experimental and does not provide complete metadata."),
            t(lang, "Coloanele JSON/BSON se întorc ca string, nu ca stream.", "JSON/BSON columns are returned as strings, not streams."),
        ]
    elif kind == "ifx_pdo":
        items = [
            t(lang, "Depinde de <code>pdo_informix</code>; nu este o extensie autonomă.", "It depends on <code>pdo_informix</code>; it is not standalone."),
            t(lang, "Prima utilizare dintr-un request compilează sursa încorporată; există un cost de pornire.", "The first use in a request compiles the embedded source; there is a startup cost."),
            t(lang, "Nu se încarcă împreună cu <code>ifx</code> direct.", "It cannot be loaded together with direct <code>ifx</code>."),
        ]
    elif kind == "ifx":
        items = [
            t(lang, "Nu folosește PDO; nu poate partaja conexiuni/atribute PDO.", "It does not use PDO; it cannot share PDO connections/attributes."),
            t(lang, "Nu se încarcă împreună cu <code>ifx_pdo</code>.", "It cannot be loaded together with <code>ifx_pdo</code>."),
            t(lang, "Testele din matrice sunt pe Windows x64; build-ul Linux este separat.", "The matrix tests are on Windows x64; the Linux build is separate."),
        ]
    else:
        items = [
            t(lang, "Rulează peste PDO, deci are overhead față de extensia C directă.", "It runs over PDO, so it has overhead compared with the direct C extension."),
            t(lang, "Necesită <code>pdo_informix</code> și <code>ext-pdo</code>.", "It requires <code>pdo_informix</code> and <code>ext-pdo</code>."),
            t(lang, "În workers de lungă durată trebuie apelat <code>Api::reset()</code>.", "In long-running workers, <code>Api::reset()</code> must be called."),
        ]
    return ul(items)


def next_steps(lang, meta):
    links = []
    for slug in MODULE_ORDER:
        if slug == meta["slug"]:
            continue
        m = MODULE_META[slug]
        title = m["title_ro"] if lang == "ro" else m["title_en"]
        links.append(f'<a class="module-card" href="{e(slug)}.html"><span class="module-kicker">{e(m["name"])}</span><h3>{e(title)}</h3><p>{e(m["tag"])}</p><span class="module-link">{e(t(lang, "Deschide", "Open"))}</span></a>')
    ref = "../reference" if lang == "en" else "reference"
    refs = (
        f'<p>{t(lang, "Referințe:", "References:")} '
        f'<a href="{ref}/API.md">API.md</a> · '
        f'<a href="{ref}/BUILD-WINDOWS.md">BUILD-WINDOWS.md</a> · '
        f'<a href="{ref}/COMPATIBILITY.md">COMPATIBILITY.md</a> · '
        f'<a href="{ref}/BENCHMARK.md">BENCHMARK.md</a> · '
        f'<a href="{ref}/MIGRATION.md">MIGRATION.md</a></p>'
    )
    return f'<div class="module-grid">{ "".join(links) }</div>{refs}'


def module_body(lang, slug, compat_rows):
    meta = MODULE_META[slug]
    sections = [
        ("overview", t(lang, "Prezentare", "Overview"), overview(lang, meta)),
        ("facts", t(lang, "Pe scurt", "At a glance"), facts(lang, meta)),
        ("requirements", t(lang, "Cerințe", "Requirements"), requirements(lang, meta)),
        ("install", t(lang, "Compilare și instalare", "Build and install"), installation(lang, meta)),
        ("config", t(lang, "Configurare", "Configuration"), configuration(lang, meta)),
        ("connect", t(lang, "Conectare", "Connecting"), connect(lang, meta)),
        ("example", t(lang, "Exemplu complet", "Complete example"), example(lang, meta)),
        ("api", t(lang, "Referință API", "API reference"), api(lang, meta)),
        ("lobs", t(lang, "LOB-uri și SLOB", "LOBs and SLOBs"), lobs(lang, meta)),
        ("transactions", t(lang, "Tranzacții și erori", "Transactions and errors"), transactions(lang, meta)),
        ("testing", t(lang, "Testare", "Testing"), testing(lang, meta)),
        ("compatibility", t(lang, "Compatibilitate", "Compatibility"), compatibility(lang, meta, compat_rows)),
        ("performance", t(lang, "Performanță", "Performance"), perf(lang, meta)),
        ("troubleshooting", t(lang, "Depanare", "Troubleshooting"), troubleshooting(lang, meta)),
        ("limits", t(lang, "Limitări", "Limitations"), limits(lang, meta)),
        ("next", t(lang, "Alte module", "Other modules"), next_steps(lang, meta)),
    ]
    body = "".join(section(ident, title, content) for ident, title, content in sections)
    nav_items = [(ident, title) for ident, title, _ in sections]
    return (
        hero(lang, meta)
        + '<div class="container docs-layout">'
        + side(lang, slug, nav_items)
        + f'<div class="docs-main">{body}</div>'
        + "</div>"
    )


def module_page_meta(lang, slug):
    meta = MODULE_META[slug]
    if lang == "ro":
        return meta["title_ro"] + " — ifx-compat", f"Documentație completă pentru {meta['name']}: cerințe, compilare, configurare, API, LOB-uri, teste și compatibilitate."
    return meta["title_en"] + " — ifx-compat", f"Complete documentation for {meta['name']}: requirements, build, configuration, API, LOBs, tests and compatibility."


def hub_body(lang, compat_html):
    cards = []
    for slug in MODULE_ORDER:
        m = MODULE_META[slug]
        cards.append(
            f'<a class="module-card" href="{e(slug)}.html">'
            f'<span class="module-kicker">{e(m["name"])}</span>'
            f'<h3>{m["title_ro"] if lang == "ro" else m["title_en"]}</h3>'
            f'<p>{m["lead_ro"] if lang == "ro" else m["lead_en"]}</p>'
            f'<span class="module-foot"><span class="pill">{e(m["tag"])}</span>'
            f'<span class="module-link">{e(t(lang, "Deschide", "Open"))}</span></span>'
            "</a>"
        )
    compare = table(
        [t(lang, "Criteriu", "Criterion"), "pdo_informix", "ifx_pdo", "ifx", "php_informix"],
        [
            [t(lang, "API", "API"), "PDO", "39 funcții", "39 funcții", "39 funcții"],
            [t(lang, "pdo_informix", "pdo_informix"), t(lang, "el însuși", "itself"), "da", "nu", "da"],
            [t(lang, "CSDK", "CSDK"), "da", t(lang, "prin driver", "through driver"), "da", t(lang, "prin driver", "through driver")],
            [t(lang, "Compilare", "Build"), "C", "C", "C", "nu"],
            [t(lang, "Handle link", "Link handle"), "PDO", "resource", "resource", "object"],
        ],
    )
    ref = "../reference" if lang == "en" else "reference"
    return f"""
<div class="docs-hero">
  <div class="container">
    <div class="breadcrumb"><a href="index.html">ifx-compat</a><span>/</span><span>{e(t(lang, "documentație", "documentation"))}</span></div>
    <h1>{e(t(lang, "Documentația modulelor", "Module documentation"))}</h1>
    <p>{t(lang, "Fiecare modul are propria documentație completă: cerințe, compilare, configurare, API, LOB-uri, teste, compatibilitate și depanare. Alege modulul cu care lucrezi.", "Each module has its own complete documentation: requirements, build, configuration, API, LOBs, tests, compatibility and troubleshooting. Choose the module you work with.")}</p>
  </div>
</div>
<div class="container docs-layout">
  <aside class="docs-side">
    <h3>{e(t(lang, "Module", "Modules"))}</h3>
    <nav class="module-switch">{"".join(module_link(lang, slug) for slug in MODULE_ORDER)}</nav>
    <h3>{e(t(lang, "Cuprins", "Contents"))}</h3>
    <nav>
      <a href="#modules">{e(t(lang, "Module", "Modules"))}</a>
      <a href="#choose">{e(t(lang, "Cum alegi", "How to choose"))}</a>
      <a href="#compatibility">{e(t(lang, "Compatibilitate", "Compatibility"))}</a>
      <a href="#testing">{e(t(lang, "Testare", "Testing"))}</a>
      <a href="#reference">{e(t(lang, "Referințe", "References"))}</a>
      <a href="#contributing">{e(t(lang, "Contribuție", "Contributing"))}</a>
      <a href="#license">{e(t(lang, "Licență", "License"))}</a>
    </nav>
  </aside>
  <div class="docs-main">
    <section id="modules"><h2>{e(t(lang, "Alege modulul", "Choose a module"))}</h2>
      <p>{t(lang, "Patru implementări, aceeași bază Informix. Fiecare pagină este autonomă și poate fi citită fără celelalte.", "Four implementations, the same Informix database. Each page is self-contained and can be read without the others.")}</p>
      <div class="module-grid">{"".join(cards)}</div>
    </section>
    <section id="choose"><h2>{e(t(lang, "Cum alegi", "How to choose"))}</h2>{compare}
      <p>{t(lang, "Pentru cod nou cu PDO, începe cu <code>pdo_informix</code>. Pentru cod vechi cu <code>ifx_*</code>, alege <code>ifx_pdo</code> (PDO) sau <code>ifx</code> (direct). Bibliotecă este pentru deployment fără compilare.", "For new PDO code, start with <code>pdo_informix</code>. For legacy <code>ifx_*</code> code, choose <code>ifx_pdo</code> (PDO) or <code>ifx</code> (direct). The library is for deployment without compilation.")}</p>
    </section>
    <section id="compatibility"><h2>{e(t(lang, "Compatibilitate", "Compatibility"))}</h2>
      <p>{t(lang, "Matricea este rezultatul testelor reale, nu al declarațiilor.", "The matrix is the result of real tests, not claims.")}</p>
      <div class="table-wrap"><table class="matrix"><thead><tr><th>PHP</th><th>Windows</th><th>Linux</th><th>pdo_informix</th><th>ifx_pdo</th><th>ifx</th><th>php_informix</th></tr></thead><tbody>{compat_html}</tbody></table></div>
      <p class="small-note">{t(lang, "Coloanele Windows/Linux descriu build-urile ifx_pdo/bibliotecă; ifx direct este testat pe Windows x64.", "The Windows/Linux columns describe the ifx_pdo/library builds; direct ifx is tested on Windows x64.")}</p>
    </section>
    <section id="testing"><h2>{e(t(lang, "Testare", "Testing"))}</h2>
      <div class="stats">
        <div class="stat"><strong>102</strong><span>{e(t(lang, "verificări ifx direct", "direct ifx checks"))}</span></div>
        <div class="stat"><strong>92</strong><span>{e(t(lang, "verificări ifx_pdo", "ifx_pdo checks"))}</span></div>
        <div class="stat"><strong>198</strong><span>{e(t(lang, "aserțiuni driver PDO", "PDO driver assertions"))}</span></div>
        <div class="stat"><strong>93 + 1</strong><span>{e(t(lang, "teste bibliotecă", "library tests"))}</span></div>
      </div>
      <p>{t(lang, "Comenzile exacte și variabilele de mediu sunt în pagina fiecărui modul, secțiunea Testare.", "Exact commands and environment variables are on each module page, in the Testing section.")}</p>
    </section>
    <section id="reference"><h2>{e(t(lang, "Referințe", "References"))}</h2>
      <ul>
        <li><a href="{ref}/API.md">API.md</a> — {e(t(lang, "semnăturile celor 39 de funcții", "signatures of the 39 functions"))}</li>
        <li><a href="{ref}/BUILD-WINDOWS.md">BUILD-WINDOWS.md</a> — {e(t(lang, "build Windows, pas cu pas", "Windows build, step by step"))}</li>
        <li><a href="{ref}/COMPATIBILITY.md">COMPATIBILITY.md</a> — {e(t(lang, "matricea și notele de testare", "matrix and testing notes"))}</li>
        <li><a href="{ref}/BENCHMARK.md">BENCHMARK.md</a> — {e(t(lang, "metodologia și rezultatele măsurate", "methodology and measured results"))}</li>
        <li><a href="{ref}/MIGRATION.md">MIGRATION.md</a> — {e(t(lang, "trecerea de la extensia veche", "migrating from the old extension"))}</li>
        <li><a href="#testing">{e(t(lang, "Testare", "Testing"))}</a> — {e(t(lang, "medii, comenzi și curățare", "environments, commands and cleanup"))}</li>
        <li><a href="#license">{e(t(lang, "Licență", "License"))}</a> — {e(t(lang, "licențe pe componente și utilizare comercială", "component licenses and commercial use"))}</li>
      </ul>
    </section>
    <section id="contributing"><h2>{e(t(lang, "Contribuție", "Contributing"))}</h2>
      <p>{t(lang, "Raportați versiunea PHP, NTS/ZTS, sistemul, binarul folosit și comanda minimă care reproduce problema. Pentru modificări în bibliotecă, rulați generarea sursei încorporate și suitele afectate.", "Report the PHP version, NTS/ZTS, OS, binary and the minimal command that reproduces the issue. For library changes, regenerate the embedded source and run the affected suites.")}</p>
      <p>{t(lang, "Instrucțiuni complete pentru pull request-uri și raportare sunt în <code>CONTRIBUTING.md</code> și <code>SECURITY.md</code> din rădăcina repository-ului.", "Full pull-request and reporting instructions are in <code>CONTRIBUTING.md</code> and <code>SECURITY.md</code> in the repository root.")}</p>
    </section>
    <section id="license"><h2>{e(t(lang, "Licență", "License"))}</h2>
      <p>{t(lang, "Codul original ifx-compat este MIT, alegere deliberată pentru adopție ușoară; pdo_informix păstrează licența Apache-2.0 a codului IBM. MIT și Apache-2.0 permit utilizare comercială și revânzare cu păstrarea notificărilor.", "Original ifx-compat code is deliberately MIT, chosen for easy adoption; pdo_informix keeps the Apache-2.0 license of the IBM code. MIT and Apache-2.0 permit commercial use and resale when notices are preserved.")}</p>
      <div class="table-wrap"><table>
        <thead><tr><th>{e(t(lang, "Componentă", "Component"))}</th><th>{e(t(lang, "Licență", "License"))}</th></tr></thead>
        <tbody>
          <tr><td><code>ifx_informix</code>, <code>ifx_pdo_informix</code>, <code>php_informix</code>, docs, scripts</td><td>MIT</td></tr>
          <tr><td><code>pdo_informix/src</code></td><td>Apache-2.0 (IBM/PDO_INFORMIX)</td></tr>
          <tr><td>{e(t(lang, "ext/informix original", "original ext/informix"))}</td><td>PHP License 3.01 ({e(t(lang, "sursa nu este redistribuită", "source not redistributed"))})</td></tr>
          <tr><td>{e(t(lang, "Informix CSDK/IDS", "Informix CSDK/IDS"))}</td><td>{e(t(lang, "licență comercială IBM, nu este inclusă", "IBM commercial license, not included"))}</td></tr>
        </tbody>
      </table></div>
      <p>{t(lang, "MIT și Apache-2.0 permit utilizarea comercială și revânzarea; dacă doriți să interziceți revânzarea codului original, este nevoie de o altă licență (de exemplu AGPL-3.0 sau PolyForm Noncommercial + licență comercială).", "MIT and Apache-2.0 allow commercial use and resale; prohibiting resale of the original code requires a different license (for example AGPL-3.0 or PolyForm Noncommercial plus a commercial license).")}</p>
    </section>
  </div>
</div>"""
