#!/usr/bin/env python3
"""Generate the Open Graph card for every page in docs/.

A link with no og:image renders as a bare URL in Slack, Twitter, LinkedIn and
iMessage. These cards are what make a shared link look like something worth
opening, and Google uses og:image as one candidate for the SERP thumbnail.

The cards are RENDERED, not drawn: each one is an HTML page screenshotted at
1200x630 with headless Chromium, so it uses the real 67even logo and the same
palette as the site. That keeps them in step with the theme instead of being a
separate set of colours maintained by hand.

Chromium and Playwright are needed to regenerate, which is why the OUTPUT is
committed and CI only checks that every page has a card (tools/check_docs_site.py).
Run this after adding a page or changing a title:

    python3 tools/build_og_images.py          # write the cards
    python3 tools/build_og_images.py --check  # list pages whose card is missing
"""

import base64
import io
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
OUT = os.path.join(DOCS, "assets", "og")

BRAND_RED = "#FF3131"
BRAND_BLACK = "#0C0E0B"
SURFACE = "#151614"
MUTED = "#9A9A99"

SITE_URL = "67even.github.io/paynow-billpay-skill"


def read(path):
    return io.open(path, encoding="utf-8").read()


def front_matter(text):
    m = re.match(r"^---\n(.*?)\n---\n", text, re.S)
    return m.group(1) if m else ""


def scalar(fm, key):
    """Pull a YAML scalar, handling plain, quoted and folded (>-) forms."""
    m = re.search(r"^%s:\s*>-?\s*\n((?:[ \t]+.*\n?)+)" % key, fm, re.M)
    if m:
        return " ".join(l.strip() for l in m.group(1).splitlines()).strip()
    m = re.search(r'^%s:\s*"([^"]*)"\s*$' % key, fm, re.M)
    if m:
        return m.group(1)
    m = re.search(r"^%s:\s*'([^']*)'\s*$" % key, fm, re.M)
    if m:
        return m.group(1)
    m = re.search(r"^%s:\s*(\S.*?)\s*$" % key, fm, re.M)
    return m.group(1) if m else ""


def pages():
    out = []
    for f in sorted(os.listdir(DOCS)):
        if not f.endswith(".md"):
            continue
        fm = front_matter(read(os.path.join(DOCS, f)))
        title = scalar(fm, "title")
        desc = scalar(fm, "description")
        permalink = scalar(fm, "permalink")
        slug = "home" if permalink.strip("/") == "" else permalink.strip("/")
        out.append({"file": f, "title": title, "desc": desc, "slug": slug})
    return out


def data_uri(path, mime):
    return "data:%s;base64,%s" % (
        mime, base64.b64encode(open(path, "rb").read()).decode("ascii"))


CARD = """<!doctype html>
<meta charset="utf-8">
<style>
  @page {{ margin: 0 }}
  * {{ box-sizing: border-box; margin: 0; padding: 0 }}
  body {{
    width: 1200px; height: 630px; overflow: hidden;
    background: {black};
    font-family: Poppins, "Liberation Sans", sans-serif;
    color: #fff;
    position: relative;
  }}
  /* a soft brand-red bloom behind the top-left, so the card is not a flat slab */
  .bloom {{
    position: absolute; top: -280px; left: -180px;
    width: 760px; height: 760px; border-radius: 50%;
    background: radial-gradient(circle, rgba(255,49,49,0.20) 0%, rgba(255,49,49,0) 68%);
  }}
  .rule {{ position: absolute; top: 0; left: 0; right: 0; height: 8px; background: {red} }}
  .pad {{ position: absolute; inset: 0; padding: 72px 76px; display: flex; flex-direction: column }}
  .mark {{ height: 76px; width: auto; align-self: flex-start }}
  .spacer {{ flex: 1 }}
  h1 {{
    font-size: {title_size}px; font-weight: 700; line-height: 1.08;
    letter-spacing: -0.02em; margin-bottom: 22px;
  }}
  p {{ font-size: 27px; line-height: 1.45; color: {muted}; max-width: 980px; font-weight: 300 }}
  .foot {{
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 40px; padding-top: 26px; border-top: 1px solid #2a2c29;
  }}
  .url {{ font-size: 23px; color: {red}; font-weight: 500; letter-spacing: 0.01em }}
  .kicker {{ font-size: 21px; color: #6f706e; font-weight: 400 }}
</style>
<div class="bloom"></div>
<div class="rule"></div>
<div class="pad">
  <img class="mark" src="{logo}" alt="">
  <div class="spacer"></div>
  <h1>{title}</h1>
  <p>{desc}</p>
  <div class="foot">
    <span class="url">{url}</span>
    <span class="kicker">PHP · Laravel · Python · Node.js · C#</span>
  </div>
</div>
"""


def esc(s):
    return (s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def build(check_only=False):
    logo = os.path.join(DOCS, "assets", "images", "67even-logo.png")
    if not os.path.isfile(logo):
        print("::error::missing %s" % logo)
        return 1

    want = pages()
    if check_only:
        missing = [p for p in want
                   if not os.path.isfile(os.path.join(OUT, p["slug"] + ".png"))]
        for p in missing:
            print("::error::docs/%s has no OG card at docs/assets/og/%s.png "
                  "- run python3 tools/build_og_images.py" % (p["file"], p["slug"]))
        print("\n%d page(s), %d missing card(s)" % (len(want), len(missing)))
        return 1 if missing else 0

    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("::error::playwright is not installed; "
              "pip install playwright --break-system-packages")
        return 1

    os.makedirs(OUT, exist_ok=True)
    logo_uri = data_uri(logo, "image/png")

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path="/opt/pw-browsers/chromium")
        page = browser.new_page(viewport={"width": 1200, "height": 630},
                                device_scale_factor=1)
        for p in want:
            title = p["title"] if p["title"] != "Home" else "Paynow BillPay Skill"
            # Long titles need a smaller size or they wrap into the description.
            size = 76 if len(title) <= 26 else 64 if len(title) <= 40 else 54
            html = CARD.format(black=BRAND_BLACK, red=BRAND_RED, muted=MUTED,
                               logo=logo_uri, title=esc(title), desc=esc(p["desc"]),
                               url=SITE_URL, title_size=size)
            with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False,
                                             encoding="utf-8") as fh:
                fh.write(html)
                tmp = fh.name
            page.goto("file://" + tmp)
            page.wait_for_timeout(120)
            dest = os.path.join(OUT, p["slug"] + ".png")
            page.screenshot(path=dest)
            os.unlink(tmp)
            print("  %-22s %-14s %6d bytes" % (p["file"], p["slug"] + ".png",
                                               os.path.getsize(dest)))
        browser.close()

    print("\n%d card(s) written to docs/assets/og/" % len(want))
    return 0


if __name__ == "__main__":
    sys.exit(build(check_only="--check" in sys.argv))
