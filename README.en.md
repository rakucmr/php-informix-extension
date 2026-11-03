# ifx-compat

Independent, unofficial compatibility project for the legacy Informix `ifx_*`
API on modern PHP. It does not redistribute IBM Informix Client SDK or IDS; a
valid IBM license is required for production use.

[Romanian README](README.md) · [Full documentation RO](docs/index.html) ·
[Full documentation EN](docs/en/index.html)

## Modules

| Folder | PHP module | What it is | Technology |
|---|---|---|---|
| [`ifx_informix/`](ifx_informix/) | `ifx` | Complete `ifx_*` / `ifxus_*` API directly over CSDK CLI; no `pdo_informix` | C + Informix CSDK |
| [`ifx_pdo_informix/`](ifx_pdo_informix/) | `ifx_pdo` | The same procedural API implemented over PDO | C + embedded PHP + PDO (CSDK through the driver) |
| [`pdo_informix/`](pdo_informix/) | `pdo_informix` | PDO driver for Informix, with PHP 7.0–8.5 compatibility | C + CSDK |
| [`php_informix/`](php_informix/) | — | Pure-PHP library with the same semantics | PHP + `pdo_informix` |
| [`docs/`](docs/index.html) | — | Bilingual static documentation and project site | HTML/CSS/JS |

`ifx`, `ifx_pdo` and the PHP library expose the same **39** global functions.
`ifx` talks directly to the Informix CLI; `ifx_pdo` and the library use the PDO
driver. `ifx` and `ifx_pdo` export the same function names and cannot be loaded
in the same PHP process.

## Loading

```ini
; direct extension, no pdo_informix
extension=ifx
```

```ini
; PDO-backed extension
extension=pdo_informix
extension=ifx_pdo
```

The PDO-backed variants require `pdo_informix`; direct `ifx` does not. All
variants ultimately require Informix Client SDK at runtime.

## Quick start

Set `IFX_DSN` (`<database>@<server>`), `IFX_USER` and `IFX_PASSWORD` in the
environment; never hard-code credentials.

```php
<?php

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
```

For the C extension, load the DLL/SO and use the same code without `require`.

## Compatibility

- PHP 7.0–8.5, x64; NTS/ZTS matching the PHP build.
- Windows and Linux builds; tested matrix in
  [`docs/reference/COMPATIBILITY.md`](docs/reference/COMPATIBILITY.md).
- Informix IDS 14.10 and CSDK 4.50, or compatible versions.
- Direct `ifx`: 102/102 checks on every tested PHP version.
- `ifx_pdo`: 92/92; PDO driver: 9 files / 198 assertions; PHP library:
  93 passed + 1 skipped (UTF-8 on `en_US.819`).

## Verify locally

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
python docs/build.py
python tools/check-docs.py --pages
```

Real integration tests need Informix CSDK/IDS and a test database. See
[`TESTING.md`](TESTING.md) for exact commands, environment variables and
cleanup notes.

## Documentation

- [Documentation RO](docs/index.html)
- [Documentation EN](docs/en/index.html)
- [Module guides RO](docs/documentation.html)
- [Module guides EN](docs/en/documentation.html)

## License

The original ifx-compat code is deliberately **MIT**, chosen for easy adoption
and legacy compatibility. `pdo_informix` is Apache-2.0 (IBM / PDO_INFORMIX
code) and remains under that license. See
[`LICENSING.md`](LICENSING.md), [`LICENSE`](LICENSE),
[`LICENSES/Apache-2.0.txt`](LICENSES/Apache-2.0.txt) and [`NOTICE`](NOTICE).

MIT and Apache-2.0 **allow commercial use and resale** with notice retention.
If you need to prohibit commercial resale of the original code, a different
license (for example AGPL-3.0 or PolyForm Noncommercial plus a commercial
license) is required; the Apache-2.0 driver cannot be restricted by this
project.

## Contributing and security

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before a pull request. Report
vulnerabilities privately as described in [`SECURITY.md`](SECURITY.md). Never
post credentials, real DSNs or production data. Release notes are in
[`CHANGELOG.md`](CHANGELOG.md).
