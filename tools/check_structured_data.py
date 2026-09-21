#!/usr/bin/env python3
"""Parse every JSON-LD block out of a BUILT site and validate it.

Structured data fails silently: a trailing comma or an unescaped quote makes
Google discard the whole block, and nothing on the page looks wrong. Liquid
makes that easy to do, because the JSON is assembled by string templating. So
this parses what was actually rendered rather than trusting the template.

It checks JSON validity, then the required and recommended properties for the
types Google still surfaces, per its Search gallery:

  BreadcrumbList  itemListElement, each with @type/position/name, positions 1..n
  Article family  headline (<=110 chars), image, datePublished, dateModified,
                  author with a name, publisher resolvable
  Organization    name, url, logo
  every block     absolute URLs, no unresolved Liquid, resolvable @id references

Point it at a Jekyll output directory:

    python3 tools/check_structured_data.py _site
"""

import io
import json
import os
import re
import sys

ARTICLE_TYPES = {"Article", "TechArticle", "BlogPosting", "NewsArticle"}


def blocks(html):
    return re.findall(
        r'<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>', html, re.S)


def walk(node, fn, path="$"):
    fn(node, path)
    if isinstance(node, dict):
        for k, v in node.items():
            walk(v, fn, "%s.%s" % (path, k))
    elif isinstance(node, list):
        for i, v in enumerate(node):
            walk(v, fn, "%s[%d]" % (path, i))


def check_site(root):
    problems = []
    ids = set()
    refs = []
    counts = {}
    pages = 0

    html_files = []
    for dp, dn, fn in os.walk(root):
        for f in fn:
            if f.endswith(".html"):
                html_files.append(os.path.join(dp, f))

    for path in sorted(html_files):
        rel = os.path.relpath(path, root)
        html = io.open(path, encoding="utf-8").read()
        raw = blocks(html)
        if not raw:
            problems.append("%s has no JSON-LD at all" % rel)
            continue
        pages += 1

        for i, text in enumerate(raw):
            where = "%s block %d" % (rel, i + 1)
            if "{{" in text or "{%" in text:
                problems.append("%s contains unrendered Liquid" % where)
                continue
            try:
                data = json.loads(text)
            except ValueError as e:
                problems.append("%s is not valid JSON: %s" % (where, e))
                continue

            t = data.get("@type", "?")
            counts[t] = counts.get(t, 0) + 1

            # collect @id declarations and references for cross-checking
            def collect(node, p):
                if isinstance(node, dict):
                    if "@id" in node and len(node) > 1:
                        ids.add(node["@id"])
                    elif isinstance(node.get("@id"), str) and len(node) == 1:
                        refs.append((where, node["@id"]))
            walk(data, collect)

            # absolute URLs only - a relative one resolves against Google's host
            def urls(node, p):
                if isinstance(node, dict):
                    for key in ("url", "item", "codeRepository"):
                        v = node.get(key)
                        if isinstance(v, str) and not v.startswith(("http://", "https://")):
                            problems.append("%s %s.%s is not absolute: %r"
                                            % (where, p, key, v))
                    v = node.get("image")
                    if isinstance(v, str) and not v.startswith("http"):
                        problems.append("%s %s.image is not absolute: %r" % (where, p, v))
            walk(data, urls)

            if t == "BreadcrumbList":
                items = data.get("itemListElement")
                if not isinstance(items, list) or not items:
                    problems.append("%s BreadcrumbList has no itemListElement" % where)
                else:
                    for n, it in enumerate(items, 1):
                        if it.get("@type") != "ListItem":
                            problems.append("%s breadcrumb %d is not a ListItem" % (where, n))
                        if it.get("position") != n:
                            problems.append("%s breadcrumb %d has position %r, expected %d"
                                            % (where, n, it.get("position"), n))
                        if not it.get("name"):
                            problems.append("%s breadcrumb %d has no name" % (where, n))
                    # only the last item may omit `item`
                    for n, it in enumerate(items[:-1], 1):
                        if not it.get("item"):
                            problems.append("%s breadcrumb %d is not the last but has no item"
                                            % (where, n))

            if t in ARTICLE_TYPES:
                for key in ("headline", "image", "datePublished", "dateModified",
                            "author", "publisher", "mainEntityOfPage"):
                    if not data.get(key):
                        problems.append("%s %s has no %s" % (where, t, key))
                h = data.get("headline", "")
                if len(h) > 110:
                    problems.append("%s headline is %d chars; Google caps it at 110"
                                    % (where, len(h)))
                a = data.get("author")
                if isinstance(a, dict) and not a.get("name"):
                    problems.append("%s author has no name" % where)
                for key in ("datePublished", "dateModified"):
                    v = data.get(key, "")
                    if v and not re.match(r"^\d{4}-\d{2}-\d{2}", str(v)):
                        problems.append("%s %s is not ISO 8601: %r" % (where, key, v))

            if t == "Organization":
                for key in ("name", "url", "logo"):
                    if not data.get(key):
                        problems.append("%s Organization has no %s" % (where, key))

    for where, ref in refs:
        if ref not in ids:
            problems.append("%s references @id %s, which nothing defines" % (where, ref))

    return problems, pages, counts


def main():
    root = sys.argv[1] if len(sys.argv) > 1 else "_site"
    if not os.path.isdir(root):
        print("::error::no such directory: %s" % root)
        return 1
    problems, pages, counts = check_site(root)
    print("%d page(s) with structured data" % pages)
    for t in sorted(counts):
        print("  %-20s %d" % (t, counts[t]))
    if not problems:
        print("\nevery JSON-LD block parses and carries its required properties")
        return 0
    print()
    for p in problems:
        print("::error::structured data: %s" % p)
    print("\n%d problem(s)" % len(problems))
    return 1


if __name__ == "__main__":
    sys.exit(main())
