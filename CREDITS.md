# Credite și origini

## pdo_informix

Driverul PDO provine din extensia PECL **PDO_INFORMIX** și din fork-ul
[`nahuelon/pdo_informix`](https://github.com/nahuelon/pdo_informix), adaptat
pentru PHP 7.0–8.5, Windows și Linux. Sursa C, testele și scripturile de build
din folderul `pdo_informix/` păstrează licența și creditul proiectului original.

- PECL: <https://pecl.php.net/package/PDO_INFORMIX>
- fork: <https://github.com/nahuelon/pdo_informix>

## ifx_pdo, ifx și php_informix

Reimplementarea API-ului procedural `ifx_*` / `ifxus_*` din extensia
`ext/informix` a PHP 5.1, în trei variante: direct peste CSDK, peste driverul
**pdo_informix** modern și ca bibliotecă PHP pură.

Codul PHP este original; semantica a fost derivată din sursa
[`ext/informix/ifx.ec`](https://github.com/php/php-src/tree/PHP-5.1/ext/informix)
(PHP License 3.01) și din manualul PHP.

Varianta `ifx_pdo` (`ifx_pdo.dll`) încorporează același cod PHP (redenumit
`IfxCompat*`) și adaugă numai adaptoarele C pentru funcții, resurse și
directive INI; are nevoie de `pdo_informix`.

Varianta `ifx` (direct, binar `ifx.dll`) din `ifx_informix/` este cod C
original scris direct peste Informix Client SDK CLI; nu copiază `ifx.ec`, nu
are nevoie de `pdo_informix` și implementează toate cele 39 de funcții ale
API-ului procedural, inclusiv LOB-uri, CHAR, SLOB, cursoare scroll, metadate,
SQLCA și `ifx_htmltbl_result`.

Autori originali ai extensiei `ext/informix`, cărora li se datorează
comportamentul reprodus aici:

- Danny Heijl <Danny.Heijl@cevi.be> — versiunea inițială (ODS 7)
- Christian Cartus <chc@idgruppe.de> — BLOBs și IUS 9
- Jouni Ahto <jouni.ahto@exdec.fi> — configurare
- Corne' Cornelius <cornec@reach.co.za> — input descriptors
- și echipa PHP (mysql code by Zeev Suraski)

Documentația originală a API-ului:
[PHP Manual — Informix](https://www.php.net/manual/en/book.ifx.php)
(arhivată).

## docs

Site-ul bilingv, generatoarele și textele de prezentare au fost realizate cu
**DeepSeek (AI)**, sub îndrumarea și validarea autorului proiectului.

## Licențe

- codul original ifx-compat: MIT — vezi [`LICENSE`](LICENSE);
- `pdo_informix`: Apache License 2.0, cod IBM/PDO_INFORMIX — vezi
  [`LICENSES/Apache-2.0.txt`](LICENSES/Apache-2.0.txt) și
  [`pdo_informix/src/LICENSE`](pdo_informix/src/LICENSE);
- `ext/informix` original: PHP License 3.01; sursa nu este redistribuită aici,
  este reprodus doar comportamentul observabil al API-ului;
- Informix CSDK/IDS: licență comercială IBM, nu este inclusă în repository.

Matricea completă, obligațiile de atribuire și explicația utilizării comerciale:
[`LICENSING.md`](LICENSING.md). Atribuirile suplimentare: [`NOTICE`](NOTICE).
