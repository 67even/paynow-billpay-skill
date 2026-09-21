#!/usr/bin/env python3
"""Check the meta tags and sitemap of a BUILT site.

These tags are what decides how a page looks in a search result and in every
link preview - the title, the snippet underneath it, the card image. They are
assembled by jekyll-seo-tag from front matter, so a page that quietly loses its
`description` or `image` key still builds, still renders, and simply stops
showing a snippet or a card. Nothing about the page looks wrong.

Checked on every rendered page:

    exactly one <h1>              the strongest on-page topic signal after
                                  <title>; just-the-docs emits {{ content }} and
                                  no heading of its own, so a page that does not
                                  write one simply has none
    <title>                       non-empty
    meta description              present and at least 50 characters
    canonical                     absolute https URL
    og:title / og:url / og:image  present, image absolute
    twitter:card                  summary_large_image (needs og:image)
    robots                        carries max-image-preview:large

and on the sitemap: every URL has a <lastmod>, and no page is missing from it.

The entry page is checked for the Search Console verification tag when one is
configured. Google re-checks that tag periodically and un-verifies the property
if it goes missing, which is silent - Search Console simply stops reporting.

Point it at a Jekyll output directory:

    python3 tools/check_meta_tags.py _site
"""

import io
import os
import re
import sys
import xml.etree.ElementTree as ET

SITEMAP_NS = {"s": "http://www.sitemaps.org/schemas/sitemap/0.9"}

REQUIRED = [
    ("a <title>", r"<title>\s*\S.*?</title>"),
    ("a meta description of at least 50 characters",
     r'<meta name="description" content="[^"]{50,}"'),
    ("an absolute canonical URL", r'<link rel="canonical" href="https://[^"]+"'),
    ("og:title", r'<meta property="og:title" content="[^"]+"'),
    ("an absolute og:image", r'<meta property="og:image" content="https://[^"]+\.png"'),
    ("og:url", r'<meta property="og:url" content="https://[^"]+"'),
    ("og:image:alt", r'<meta property="og:image:alt" content="[^"]+"'),
    ("twitter:card=summary_large_image",
     r'<meta name="twitter:card" content="summary_large_image"'),
    ("robots with max-image-preview:large",
     r'<meta name="robots" content="[^"]*max-image-preview:large'),
]


def head_of(path):
    return io.open(path, encoding="utf-8").read().split("</head>")[0]


def html_files(root):
    for dp, dn, fn in os.walk(root):
        for f in sorted(fn):
            if f.endswith(".html"):
                yield os.path.join(dp, f)


def check_pages(root):
    bad, pages, seen = [], 0, set()
    for path in sorted(html_files(root)):
        rel = os.path.relpath(path, root).replace(os.sep, "/")
        body = io.open(path, encoding="utf-8").read()
        head = body.split("</head>")[0]
        pages += 1

        n_h1 = len(re.findall(r"<h1\b", body.split("</head>")[-1]))
        if n_h1 != 1:
            bad.append("%s has %d <h1> headings, expected exactly 1" % (rel, n_h1))
        seen.add("/" + rel.replace("index.html", ""))
        for name, pattern in REQUIRED:
            if not re.search(pattern, head, re.S):
                bad.append("%s has no %s" % (rel, name))
        # A description Google will truncate is a wasted snippet.
        m = re.search(r'<meta name="description" content="([^"]*)"', head)
        if m and len(m.group(1)) > 160:
            bad.append("%s description is %d chars; Google shows about 160"
                       % (rel, len(m.group(1))))
    return bad, pages, seen


def check_verification(root, config_path):
    """The Search Console token must be on the page the property URL resolves to."""
    if not os.path.isfile(config_path):
        return []
    cfg = io.open(config_path, encoding="utf-8").read()
    m = re.search(r'^google_site_verification_token:\s*"([^"]+)"\s*$', cfg, re.M)
    if not m:
        return []
    home = os.path.join(root, "index.html")
    if not os.path.isfile(home):
        return ["no index.html to carry the Search Console verification tag"]
    want = '<meta name="google-site-verification" content="%s">' % m.group(1)
    if want not in head_of(home):
        return ["the entry page has lost its Search Console verification tag; "
                "Google re-checks it and un-verifies the property when it goes"]
    return []


def check_sitemap(root, page_paths):
    path = os.path.join(root, "sitemap.xml")
    if not os.path.isfile(path):
        return ["no sitemap.xml was generated"], 0
    urls = ET.parse(path).getroot().findall("s:url", SITEMAP_NS)
    bad = []
    listed = set()
    for u in urls:
        loc = u.find("s:loc", SITEMAP_NS)
        if loc is None:
            bad.append("a sitemap entry has no <loc>")
            continue
        listed.add(re.sub(r"^https?://[^/]+", "", loc.text))
        if u.find("s:lastmod", SITEMAP_NS) is None:
            bad.append("%s has no <lastmod>" % loc.text)
    if len(urls) < len(page_paths):
        bad.append("the sitemap lists %d URL(s) but the site has %d page(s)"
                   % (len(urls), len(page_paths)))
    return bad, len(urls)


def main():
    root = sys.argv[1] if len(sys.argv) > 1 else "_site"
    if not os.path.isdir(root):
        print("::error::no such directory: %s" % root)
        return 1

    page_bad, pages, seen = check_pages(root)
    site_bad, n_urls = check_sitemap(root, seen)
    verify_bad = check_verification(
        root, os.path.join(os.path.dirname(os.path.dirname(
            os.path.abspath(__file__))), "docs", "_config.yml"))

    print("%d page(s) checked, %d URL(s) in the sitemap" % (pages, n_urls))
    bad = page_bad + site_bad + verify_bad
    if not bad:
        print("\nevery page carries the tags that decide how it appears in search")
        return 0
    print()
    for b in bad:
        print("::error::meta: %s" % b)
    print("\n%d problem(s)" % len(bad))
    return 1


if __name__ == "__main__":
    sys.exit(main())
