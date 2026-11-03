# Licensing

`ifx-compat` is a multi-component repository. The components do **not** all use
the same license. This file explains which license applies to which directory
and what those licenses allow. It is a summary, not legal advice; read the full
license texts before redistributing.

## Project decision

The original ifx-compat code is intentionally kept under **MIT** to maximize
adoption, compatibility with legacy projects and contributions. This is a
deliberate trade-off: MIT also allows commercial use and resale, provided the
copyright and permission notices are preserved. The Apache-2.0 `pdo_informix`
component remains under Apache-2.0 and is not affected by this decision.

## Component map

| Component | Files | License | Copyright / origin |
|---|---|---|---|
| Original `ifx-compat` code | `ifx_informix/`, `ifx_pdo_informix/` (C adapters and generated embedded source), `php_informix/`, repository scripts and documentation | MIT | The ifx-compat contributors |
| `pdo_informix` | `pdo_informix/src/` and the driver tests derived from it | Apache License 2.0 | IBM Corporation and the PDO_INFORMIX contributors |
| Original `ext/informix` | Not redistributed in this repository | PHP License 3.01 | The PHP Group and the original `ext/informix` authors |
| IBM Informix Client SDK / IDS | Not redistributed in this repository | IBM commercial license | IBM Corporation |

The root [`LICENSE`](LICENSE) file contains the MIT text that applies to the
original ifx-compat code. The Apache-2.0 text is in
[`LICENSES/Apache-2.0.txt`](LICENSES/Apache-2.0.txt) and
[`pdo_informix/src/LICENSE`](pdo_informix/src/LICENSE). Attribution notices are
in [`NOTICE`](NOTICE) and [`CREDITS.md`](CREDITS.md).

## What MIT allows

The MIT license grants permission, free of charge, to:

- use, copy, modify, merge, publish and distribute the software;
- sublicense it;
- **sell copies of the software**;
- distribute modified versions, including proprietary/closed-source versions.

The main condition is that the copyright notice and the MIT permission notice
must be included in all copies or substantial portions of the software. The
software is provided without warranty.

In plain language: **MIT does not prevent a company from selling the original
code, a modified version, or a product built around it.** A company can also
offer paid support or hosting. MIT does not grant trademark rights, so a
redistributor may not imply that the project or its authors endorse the
redistributor.

## What Apache-2.0 allows

Apache-2.0 is also a permissive license. It allows commercial use, modification,
distribution and sale, and it adds an express patent grant. A redistributor
must:

- provide a copy of the Apache-2.0 license;
- retain copyright, patent, trademark and attribution notices;
- mark modified files prominently;
- include any `NOTICE` content supplied with the original work, if applicable.

Apache-2.0 does not grant trademark rights either. The `pdo_informix` code is
IBM's code; the ifx-compat project cannot relicense it under MIT or under a
non-commercial license. A company can therefore redistribute and sell the
Apache-2.0 driver while complying with Apache-2.0.

## What this means for commercial redistribution

With the current licenses, **anyone may sell the MIT-licensed original code and
the Apache-2.0 driver**, provided they preserve the applicable notices and
comply with the license conditions. This is intentional in permissive
open-source licensing, but it is the opposite of a "no commercial resale"
policy.

If the goal is to prohibit commercial use or resale, MIT and Apache-2.0 are the
wrong licenses for the code you own. The realistic options are:

1. **A copyleft license such as AGPL-3.0-or-later** for the original code.
   Commercial use and paid services are still allowed, but a redistributor or
   network-service operator must provide the corresponding source under the
   AGPL and cannot turn the code into a proprietary fork. The Apache-2.0
   `pdo_informix` component remains Apache-2.0.
2. **A non-commercial license such as PolyForm Noncommercial 1.0.0**, with a
   separate paid commercial license from the copyright holder. This prohibits
   commercial use without permission, but it is **not OSI open source** and can
   reduce adoption and contribution.
3. **A source-available license with delayed open source** (for example
   BUSL-1.1) or another custom license. These are not OSI open source either and
   need careful legal review.
4. **A proprietary/all-rights-reserved release** of the code you own. This
   gives the strongest control, but it is not open source.

No license can prevent someone from independently implementing a similar API,
and no open-source license can prevent someone from charging for their own
services. Enforcement also requires identifying the copyright holder and, in
practice, legal resources.

## Contributions

Unless a contributor explicitly states otherwise, contributions to the original
ifx-compat code are accepted under the MIT license. Contributions to
`pdo_informix` must remain compatible with Apache-2.0. Do not copy code from a
project whose license is incompatible with these terms.

If the project later changes the license of the original code, the change can
only apply to code whose copyright is held by the relicensing party; the
Apache-2.0 component and third-party contributions keep their original terms.
