# Security policy

## Reporting a vulnerability

Please do **not** open a public issue for a suspected vulnerability.

Use GitHub's **Security → Advisories → Report a vulnerability** flow for this
repository. If advisories are not available, contact the maintainer through the
repository profile and ask for a private channel. Include:

- affected component (`ifx`, `ifx_pdo`, `pdo_informix`, PHP library or docs);
- affected version/commit and PHP version (NTS/ZTS, x64/x86);
- operating system and Informix CSDK/IDS version;
- a minimal reproduction, with credentials and real DSNs redacted;
- impact and any known workaround.

Do not include passwords, keys, production data or unredacted connection
strings. A sanitized DSN such as
`informix:server=<server>;database=<database>;host=<host>;` is enough.

## Supported versions

Security fixes are applied to the current `main` branch and, when practical, to
the latest published release. The project supports PHP 7.0–8.5 at the source
level, but older PHP branches may no longer receive upstream security fixes.
Users are responsible for running a supported PHP and Informix CSDK/IDS
version.

## Scope

In scope:

- memory-safety issues in the C extensions;
- SQL injection or unsafe escaping introduced by this project;
- credential leakage or unsafe defaults in the PHP library/extensions;
- broken authentication or authorization assumptions in examples/tests;
- vulnerabilities in the documentation site that can affect visitors.

Out of scope:

- vulnerabilities in IBM Informix Client SDK, IDS or PHP itself;
- issues caused by running tests against production data;
- missing features or compatibility limitations (use a normal issue);
- reports that require credentials or data you are not authorized to share.

## Secure usage notes

- Keep DSNs, users and passwords in environment variables or a secret manager;
  never commit them to the repository.
- Do not use the test suites against production databases. They create and drop
  objects and some PDO tests intentionally leave diagnostic tables behind.
- The examples use `getenv()` and placeholders; adapt them to your own secret
  management rather than hard-coding values.
- The project is provided without warranty under the licenses in
  [`LICENSING.md`](LICENSING.md). A security review of your deployment remains
  your responsibility.
