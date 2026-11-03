# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project aims
to use semantic versioning once a public release is tagged.

## [Unreleased] — first public release

### Added

- Direct `ifx` extension over Informix CSDK CLI, with all 39 `ifx_*` / `ifxus_*`
  functions, LOB/CHAR/SLOB handles, scroll cursors, metadata, SQLCA and HTML
  output.
- `ifx_pdo` extension with the same API over the PDO driver, including INI
  directives and Zend resources.
- `pdo_informix` driver build for PHP 7.0–8.5 on Windows and Linux, based on
  the PDO_INFORMIX sources (Apache-2.0).
- Pure-PHP `php_informix` library with the same 39 functions and no compilation
  step.
- Bilingual Romanian/English documentation site with a separate complete page
  for every module, a documentation hub, compatibility matrix, benchmark and
  migration references.
- Windows and Linux build scripts, Docker test support, and PowerShell test
  runners.
- Real integration suites: 102 checks for direct `ifx`, 92 for `ifx_pdo`,
  9 files / 198 assertions for the PDO driver, and 93 passing + 1 skipped for
  the PHP library.
- Benchmark tools for single-connection and concurrent comparisons.

### Changed

- Licensing metadata now distinguishes the MIT-licensed original code from the
  Apache-2.0 `pdo_informix` component; see `LICENSING.md` and `NOTICE`.
- Public repository documentation now includes testing, contributing, security
  and CI/Pages guidance.

### Fixed

- PHP 7.0 build/link compatibility in the direct extension.
- PHP 7.x PE linker-version mismatch handled by the build/test runner.
- Embedded `ifx_php.c` generation is deterministic and checked in CI.
- Documentation code-block contrast issue caused by a global `code` background.

### Known limitations

- `ifx` and `ifx_pdo` export the same PHP function names and cannot be loaded
  in the same process.
- Linux extension builds are not part of the Windows compatibility matrix.
- The UTF-8 library test is skipped on a database with `DB_LOCALE=en_US.819`.
- Real integration tests require Informix CSDK/IDS and are not runnable in the
  public CI without a test server.
