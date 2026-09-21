#!/usr/bin/env python3
"""Validate the Jekyll configuration of docs/ without a Ruby toolchain.

GitHub Pages builds docs/ on push. When that build is wrong the site does not
fail loudly - it serves something broken, or silently keeps serving the last
good version, and nobody notices for weeks. This script catches the failure
modes that have actually bitten this repository:

  * No default layout. just-the-docs ships no _config.yml of its own and Jekyll
    does not merge theme config, so without a `defaults:` block every page
    renders as bare body content with no nav, no stylesheet and no <head>.
  * A local docs/_layouts/default.html shadowing the theme's own layout.
  * color_scheme naming a scheme with no matching _sass/color_schemes file.
  * A page missing title / nav_order / permalink, which drops it out of the
    sidebar or collides its URL with another page.
  * An asset referenced by _config.yml or _includes/head_custom.html that was
    never committed - a 404 for the logo or favicon.
  * A page with no Open Graph card, or one whose card was never generated, so
    the link renders as a bare URL everywhere it is shared.

Run it exactly as CI does:

    python3 tools/check_docs_site.py
"""

import io
import os
import re
import struct
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")


def read(*parts):
    return io.open(os.path.join(*parts), encoding="utf-8").read()


def png_size(path):
    """Width and height from the IHDR chunk - avoids a Pillow dependency."""
    with open(path, "rb") as fh:
        head = fh.read(24)
    if len(head) < 24 or head[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    return struct.unpack(">II", head[16:24])


def front_matter(text):
    m = re.match(r"^---\n(.*?)\n---\n", text, re.S)
    return m.group(1) if m else None


def check():
    bad = []
    if not os.path.isdir(DOCS):
        return ["docs/ does not exist"]

    cfg_path = os.path.join(DOCS, "_config.yml")
    if not os.path.isfile(cfg_path):
        return ["docs/_config.yml is missing"]
    cfg = read(cfg_path)

    # 1. A default layout must be declared, or every page renders bare.
    if not re.search(r"^\s*-\s*scope:", cfg, re.M) or not re.search(
        r"^\s*layout:\s*\"?default\"?\s*$", cfg, re.M
    ):
        bad.append(
            "_config.yml has no `defaults:` block setting `layout: default`; "
            "just-the-docs ships no _config.yml and Jekyll does not merge theme "
            "config, so every page would render without the theme"
        )

    # 2. A local default layout would shadow the theme's.
    shadow = os.path.join(DOCS, "_layouts", "default.html")
    if os.path.isfile(shadow):
        bad.append(
            "docs/_layouts/default.html shadows the theme's own layout; delete it "
            "or the theme never renders"
        )

    # 3. A theme must be declared, exactly one way.
    has_remote = re.search(r"^\s*remote_theme:\s*\S", cfg, re.M)
    has_local = re.search(r"^\s*theme:\s*\S", cfg, re.M)
    if not has_remote and not has_local:
        bad.append("_config.yml declares neither `remote_theme:` nor `theme:`")
    if has_remote and has_local:
        bad.append("_config.yml declares both `remote_theme:` and `theme:`; pick one")

    # 4. color_scheme must have a matching _sass file.
    m = re.search(r"^\s*color_scheme:\s*[\"']?([\w-]+)[\"']?\s*$", cfg, re.M)
    if m and m.group(1) not in ("nil", "light", "dark"):
        scheme = os.path.join(DOCS, "_sass", "color_schemes", m.group(1) + ".scss")
        if not os.path.isfile(scheme):
            bad.append(
                "color_scheme: %s but docs/_sass/color_schemes/%s.scss is missing"
                % (m.group(1), m.group(1))
            )

    # 5. Every page needs title, nav_order and a unique permalink.
    seen = {}
    pages = sorted(f for f in os.listdir(DOCS) if f.endswith(".md"))
    if not pages:
        bad.append("docs/ contains no pages")
    for f in pages:
        fm = front_matter(read(DOCS, f))
        if fm is None:
            bad.append("docs/%s has no front matter" % f)
            continue
        for key in ("title", "nav_order", "permalink", "description",
                    "last_modified_at", "image"):
            if not re.search(r"^%s:" % key, fm, re.M):
                bad.append("docs/%s front matter has no `%s:`" % (f, key))

        # The OG card must exist at the declared path and be 1200x630: those
        # are the dimensions the meta tags promise, and Twitter and LinkedIn
        # drop a card whose real size disagrees.
        m = re.search(r"^image:\s*\n\s+path:\s*(\S+)\s*$", fm, re.M)
        if m:
            card = os.path.join(DOCS, m.group(1).lstrip("/"))
            if not os.path.isfile(card):
                bad.append("docs/%s declares image %s, which does not exist - "
                           "run python3 tools/build_og_images.py" % (f, m.group(1)))
            else:
                size = png_size(card)
                if size and size != (1200, 630):
                    bad.append("docs/%s card %s is %dx%d, not the 1200x630 its "
                               "meta tags declare" % (f, m.group(1), size[0], size[1]))
        m = re.search(r"^permalink:\s*(\S+)\s*$", fm, re.M)
        if m:
            if m.group(1) in seen:
                bad.append(
                    "docs/%s and docs/%s both claim permalink %s"
                    % (seen[m.group(1)], f, m.group(1))
                )
            seen[m.group(1)] = f

    # 6. Assets referenced by config and head_custom must exist.
    refs = re.findall(r"^\s*logo:\s*[\"']([^\"']+)[\"']", cfg, re.M)
    head = os.path.join(DOCS, "_includes", "head_custom.html")
    if os.path.isfile(head):
        # Matches both a bare path and the Liquid form Jekyll prefers,
        # href="{{ '/assets/images/favicon.png' | relative_url }}".
        refs += re.findall(r'href="[^"]*?[\'"](/assets/[^\'"]+)[\'"][^"]*"', read(head))
        refs += re.findall(r'href="(/assets/[^"{}]+)"', read(head))
    for ref in refs:
        rel = re.sub(r"^.*?/assets/", "assets/", ref).lstrip("/")
        if not os.path.isfile(os.path.join(DOCS, rel)):
            bad.append("referenced asset docs/%s does not exist" % rel)

    return bad


def main():
    bad = check()
    n = len([f for f in os.listdir(DOCS) if f.endswith(".md")]) if os.path.isdir(DOCS) else 0
    print("docs/ site: %d page(s) validated" % n)
    if not bad:
        print("\nJekyll configuration is sound")
        return 0
    print()
    for b in bad:
        print("::error::docs config: %s" % b)
    print("\n%d problem(s)" % len(bad))
    return 1


if __name__ == "__main__":
    sys.exit(main())
