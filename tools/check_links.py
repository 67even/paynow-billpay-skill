#!/usr/bin/env python3
"""Verify every internal link and in-page anchor across the repository.

Two link conventions live here and they resolve differently, which is exactly the
trap this script exists to avoid:

  * Repository Markdown (README, CHANGELOG, the skill's own files) uses relative
    links that are FILESYSTEM paths - `paynow-paybill-skills/.../troubleshooting.md` must exist
    on disk, because that is how GitHub renders them.

  * docs/ is a Jekyll site. Its links are PERMALINKS - `../hashing/` resolves to
    /hashing/, a URL that no file on disk matches. Checking those against the
    filesystem reports twelve false failures; checking them against the declared
    permalinks is the real test.

Run it exactly as CI does:

    python3 tools/check_links.py
"""

import io
import os
import re
import sys
from urllib.parse import urljoin

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
SKIP_DIRS = {".git", "node_modules", "vendor", "_site", ".jekyll-cache"}

EXTERNAL = ("http://", "https://", "mailto:", "tel:")


def slug(heading):
    """GitHub/Jekyll heading slug: lowercase, drop punctuation, spaces to hyphens.
    Runs of spaces are NOT collapsed - 'A - B' becomes 'a---b'."""
    s = re.sub(r"<[^>]+>", "", heading).strip().lower()
    s = re.sub(r"[^\w\s-]", "", s)
    return s.replace(" ", "-").strip("-")


def read(path):
    return io.open(path, encoding="utf-8").read()


def split_front_matter(text):
    if text.startswith("---\n"):
        parts = text.split("---\n", 2)
        if len(parts) == 3:
            return parts[1], parts[2]
    return "", text


def headings(body):
    return {slug(h) for h in re.findall(r"^#{1,6}\s+(.*?)\s*$", body, re.M)}


def links(body):
    """Every internal target, ignoring anything inside fenced code blocks.

    Both conventions are picked up: Markdown `](target)` and raw HTML `src=` /
    `href=` attributes, because README.md and docs/index.md both embed images and
    buttons as HTML. Targets containing Liquid (`{{ ... }}`) are skipped - Jekyll
    resolves those at build time and there is nothing to check statically.
    """
    body = re.sub(r"```.*?```", "", body, flags=re.S)
    out = re.findall(r"\]\(([^)\s]+)\)", body)
    out += re.findall(r'<(?:img|a|source)\b[^>]*?(?:src|href)="([^"]+)"', body)
    return [t for t in out if "{{" not in t and "{%" not in t]


def md_files(base, skip_docs):
    for dp, dn, fn in os.walk(base):
        dn[:] = [d for d in dn if d not in SKIP_DIRS]
        if skip_docs and os.path.abspath(dp).startswith(DOCS):
            continue
        for f in fn:
            if f.endswith(".md"):
                yield os.path.join(dp, f)


def check_repo():
    """Relative links are filesystem paths."""
    bad = []
    for path in sorted(md_files(ROOT, skip_docs=True)):
        rel = os.path.relpath(path, ROOT)
        body = split_front_matter(read(path))[1]
        heads = headings(body)
        for target in links(body):
            if target.startswith(EXTERNAL):
                continue
            if target.startswith("#"):
                if target[1:] not in heads:
                    bad.append("%s -> %s (no such heading)" % (rel, target))
                continue
            base = target.split("#")[0]
            if base and not os.path.exists(os.path.normpath(os.path.join(os.path.dirname(path), base))):
                bad.append("%s -> %s (no such file)" % (rel, target))
    return bad


def check_docs():
    """Relative links are permalinks into the generated site."""
    if not os.path.isdir(DOCS):
        return [], 0

    pages = {}
    for f in sorted(os.listdir(DOCS)):
        if not f.endswith(".md"):
            continue
        fm, body = split_front_matter(read(os.path.join(DOCS, f)))
        m = re.search(r"^permalink:\s*(\S+)\s*$", fm, re.M)
        if not m:
            return ["docs/%s has no permalink in its front matter" % f], 0
        pages[f] = (m.group(1), body)

    permalinks = {p for p, _ in pages.values()}
    bad = []
    for f, (permalink, body) in pages.items():
        heads = headings(body)
        for target in links(body):
            if target.startswith(EXTERNAL):
                continue
            if target.startswith("#"):
                if target[1:] not in heads:
                    bad.append("docs/%s -> %s (no such heading)" % (f, target))
                continue
            base = target.split("#")[0]
            resolved = urljoin(permalink, base)
            if not resolved.endswith("/"):
                resolved += "/"
            if resolved not in permalinks:
                bad.append("docs/%s -> %s (resolves to %s, which is not a permalink)"
                           % (f, target, resolved))
    return bad, len(pages)


def main():
    repo_bad = check_repo()
    docs_bad, n_docs = check_docs()

    n_repo = len(list(md_files(ROOT, skip_docs=True)))
    print("repository Markdown: %d file(s) checked as filesystem paths" % n_repo)
    print("docs/ site:          %d page(s) checked as permalinks" % n_docs)

    bad = repo_bad + docs_bad
    if not bad:
        print("\nall internal links and anchors resolve")
        return 0

    print()
    for b in bad:
        print("::error::broken link: %s" % b)
    print("\n%d broken link(s)" % len(bad))
    return 1


if __name__ == "__main__":
    sys.exit(main())
