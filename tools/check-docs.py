#!/usr/bin/env python3
"""Validate the static documentation site and Markdown links.

Dependency-free. Intended for local use and CI:

    python tools/check-docs.py
"""

from __future__ import annotations

import argparse
import re
import sys
import urllib.parse
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
MODULES = ("pdo_informix", "ifx_pdo", "ifx", "php_informix")
REQUIRED_PAGES = [
    "index.html",
    "documentation.html",
    "pdo_informix.html",
    "ifx_pdo.html",
    "ifx.html",
    "php_informix.html",
    "en/index.html",
    "en/documentation.html",
    "en/pdo_informix.html",
    "en/ifx_pdo.html",
    "en/ifx.html",
    "en/php_informix.html",
]
MODULE_SECTIONS = {
    "overview",
    "requirements",
    "install",
    "config",
    "connect",
    "example",
    "api",
    "lobs",
    "transactions",
    "testing",
    "compatibility",
    "performance",
    "troubleshooting",
    "limits",
    "next",
}
EXTERNAL = ("http://", "https://", "mailto:", "tel:", "javascript:", "data:")


class PageParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.ids: list[str] = []
        self.links: list[str] = []
        self.assets: list[str] = []
        self.main_count = 0
        self.lang = ""
        self.title = ""
        self.description = ""
        self._in_title = False

    def handle_starttag(self, tag: str, attrs) -> None:
        data = dict(attrs)
        if tag == "main":
            self.main_count += 1
        if tag == "html":
            self.lang = (data.get("lang") or "").strip()
        if tag == "title":
            self._in_title = True
        if tag == "meta" and (data.get("name") or "").lower() == "description":
            self.description = (data.get("content") or "").strip()
        if data.get("id"):
            self.ids.append(data["id"])
        if tag == "a" and data.get("href"):
            self.links.append(data["href"])
        if tag in ("link", "script", "img") and data.get("src"):
            self.assets.append(data["src"])
        if tag == "link" and data.get("href"):
            self.assets.append(data["href"])

    def handle_endtag(self, tag: str) -> None:
        if tag == "title":
            self._in_title = False

    def handle_data(self, data: str) -> None:
        if self._in_title:
            self.title += data


def local_target(page: Path, href: str) -> tuple[Path, str]:
    parsed = urllib.parse.urlsplit(href)
    path = urllib.parse.unquote(parsed.path)
    target = (page.parent / path).resolve() if path else page.resolve()
    return target, parsed.fragment


def read_html(path: Path) -> PageParser:
    parser = PageParser()
    parser.feed(path.read_text(encoding="utf-8"))
    return parser


def check_html(errors: list[str], warnings: list[str], pages_only: bool = False) -> None:
    for rel in REQUIRED_PAGES:
        path = DOCS / rel
        if not path.is_file():
            errors.append(f"{rel}: missing page")
            continue
        parser = read_html(path)
        if parser.main_count != 1:
            errors.append(f"{rel}: expected one <main>, found {parser.main_count}")
        if parser.lang not in ("ro", "en"):
            errors.append(f"{rel}: missing/invalid html lang ({parser.lang!r})")
        if not parser.title.strip():
            errors.append(f"{rel}: empty <title>")
        if not parser.description:
            errors.append(f"{rel}: empty meta description")
        duplicates = sorted({item for item in parser.ids if parser.ids.count(item) > 1})
        if duplicates:
            errors.append(f"{rel}: duplicate ids: {', '.join(duplicates)}")

        for href in parser.links + parser.assets:
            if href.startswith(EXTERNAL):
                continue
            if not href or href == "#":
                continue
            target, fragment = local_target(path, href)
            if not target.exists():
                errors.append(f"{rel}: missing target {href}")
                continue
            if pages_only:
                try:
                    target.relative_to(DOCS)
                except ValueError:
                    errors.append(f"{rel}: target is outside published docs/: {href}")
            if fragment and target.suffix.lower() in (".html", ".htm"):
                target_parser = read_html(target)
                if fragment not in target_parser.ids:
                    errors.append(f"{rel}: missing anchor {href}")

        if rel.endswith("documentation.html") or rel == "documentation.html":
            text = path.read_text(encoding="utf-8")
            for section in ("modules", "choose", "compatibility", "testing", "reference", "contributing", "license"):
                if f'id="{section}"' not in text:
                    warnings.append(f"{rel}: hub section #{section} missing")

        stem = Path(rel).stem
        if stem in MODULES:
            text = path.read_text(encoding="utf-8")
            missing = sorted(MODULE_SECTIONS - set(re.findall(r'<section id="([^"]+)"', text)))
            if missing:
                warnings.append(f"{rel}: module sections missing: {', '.join(missing)}")
            if text.count("<h2>") < 15:
                warnings.append(f"{rel}: fewer than 15 <h2> headings")


def check_markdown(errors: list[str], warnings: list[str]) -> None:
    for md in sorted(ROOT.rglob("*.md")):
        if any(part in {"vendor", "node_modules", ".git", "__pycache__"} for part in md.parts):
            continue
        text = md.read_text(encoding="utf-8", errors="replace")
        for match in re.finditer(r"\[[^\]]*\]\(([^)\s]+)(?:\s+\"[^\"]*\")?\)", text):
            href = match.group(1)
            if href.startswith(EXTERNAL) or href.startswith("#"):
                continue
            parsed = urllib.parse.urlsplit(href)
            target = (md.parent / urllib.parse.unquote(parsed.path)).resolve() if parsed.path else md
            if not target.exists():
                errors.append(f"{md.relative_to(ROOT)}: missing Markdown target {href}")
                continue
            if parsed.fragment and target.suffix.lower() == ".md":
                body = target.read_text(encoding="utf-8", errors="replace").lower()
                fragment = urllib.parse.unquote(parsed.fragment).lower()
                if fragment not in body:
                    warnings.append(f"{md.relative_to(ROOT)}: Markdown anchor not found: {href}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--quiet", action="store_true", help="only print errors")
    parser.add_argument(
        "--pages",
        action="store_true",
        help="require every local HTML link to resolve inside docs/",
    )
    args = parser.parse_args()

    errors: list[str] = []
    warnings: list[str] = []
    check_html(errors, warnings, pages_only=args.pages)
    check_markdown(errors, warnings)

    if not args.quiet:
        print(f"pages checked: {len(REQUIRED_PAGES)}")
        print(f"errors: {len(errors)}; warnings: {len(warnings)}")
    for item in errors:
        print(f"ERROR: {item}")
    for item in warnings:
        print(f"WARN:  {item}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
