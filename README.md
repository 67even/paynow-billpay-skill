<p align="center">
  <a href="https://developers.paynow.co.zw/docs/billpay/intro/">
    <img src="assets/button_pay-now_large.svg"
         alt="Paynow BillPay developer documentation" width="200" height="81">
  </a>
</p>

<h1 align="center">Paynow BillPay Skill</h1>

<p align="center">
  <strong>A Claude skill for integrating Paynow BillPay: vend ZESA tokens, airtime and bill payments without double-charging anyone, and pass go-live UAT the first time.</strong>
</p>

<p align="center">
  <a href="https://67even.github.io/paynow-billpay-skill/"><img alt="Documentation" src="https://img.shields.io/badge/docs-67even.github.io-FF3131?logo=readthedocs&logoColor=white"></a>
  <a href="#installation"><img alt="Claude Skill" src="https://img.shields.io/badge/Claude-Skill-D97757"></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/License-MIT-blue.svg"></a>
  <img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white">
  <img alt="Python 3.9+" src="https://img.shields.io/badge/Python-3.9%2B-3776AB?logo=python&logoColor=white">
  <img alt="Node 18+" src="https://img.shields.io/badge/Node.js-18%2B-339933?logo=node.js&logoColor=white">
  <img alt=".NET 8" src="https://img.shields.io/badge/.NET-8-512BD4?logo=dotnet&logoColor=white">
  <a href="https://github.com/67even/paynow-billpay-skill/actions/workflows/tests.yml"><img alt="tests" src="https://github.com/67even/paynow-billpay-skill/actions/workflows/tests.yml/badge.svg?branch=main"></a>
</p>

<p align="center">
  <a href="https://67even.github.io/paynow-billpay-skill/"><strong>Read the documentation →</strong></a>
</p>

---

## Why this exists

[Paynow BillPay](https://developers.paynow.co.zw/docs/billpay/intro/) is Zimbabwe's
bill-payment and service-vending platform. It serves two kinds of user:

- **Vendors** (agents, fintechs, banks, shops) sell ZESA tokens, airtime, vouchers and bill
  payments from a prefunded wallet.
- **Offline billers** (schools, councils, clubs) receive payments collected on their behalf.

Every vendor integration has to pass a joint go-live test (UAT) with Paynow before it can
take real money.

The documentation lists the payment statuses as `Authorized`, `BeingProcessed`, `Paid`,
`Reversed`, `Failed` and `Flagged`. So most integrations poll like this:

```js
while (result.Status === 'BeingProcessed') {
  await sleep(180_000);
  result = await status(reference);
}
```

UAT test 3 checks your polling with the Test Biller's `PP` prefix. According to the same
documentation's integration notes, that prefix returns **`BeingPaid`**, which isn't in the
status list. The loop exits at once, treats a pending payment as final, and fails the
test. ZETDC under load returns a third undocumented status, **`Pending`**.

That is one of a dozen things an integration needs to get right that the documentation
doesn't spell out. Here are three more:

- A timeout on PAY doesn't mean the payment failed, and re-sending it charges the customer
  twice.
- Merging ZETDC's multiple token SMSes into one fails UAT.
- The official PHP webhook snippet builds SQL by string concatenation.

This skill makes Claude get all of these right while it writes, reviews or debugs your
integration. Every claim was checked against all **17 pages** of Paynow's BillPay
documentation on 21 September 2026.

---

## Table of contents

- [What you get](#what-you-get)
- [Supported languages and frameworks](#supported-languages-and-frameworks)
- [Installation](#installation)
- [Usage](#usage)
- [The two tools](#the-two-tools)
- [What the skill is opinionated about](#what-the-skill-is-opinionated-about)
- [Repository structure](#repository-structure)
- [Verification](#verification)
- [Does it actually help?](#does-it-actually-help)
- [Known gaps in Paynow's BillPay documentation](#known-gaps-in-paynows-billpay-documentation)
- [Contributing](#contributing)
- [Author](#author)
- [License](#license)

---

## What you get

| | |
|---|---|
| **A skill Claude loads on demand** | `SKILL.md` covers which API you need, setup, auth, the endpoint map and the payment-flow rules. Seven reference files load only when the task needs them. |
| **Copy-ready starter code in four languages** | **Laravel:** client, payment service, controllers, jobs, models, migration, routes and 11 feature tests. **Python:** client plus Flask webhooks. **TypeScript:** client plus Express webhooks. **C#:** client plus minimal-API webhooks. Written to be adapted, not pasted blind. |
| **Two executable tools** | Smoke-test your credentials and the Test Biller from the command line, and prove a payment-webhook signature against Paynow's published worked example. |
| **A corrected API reference** | [`PAYNOW-BILLPAY.md`](PAYNOW-BILLPAY.md) covers the full Vendor and Biller APIs, verified page by page, with everything Paynow leaves unspecified labelled as such. |
| **A documentation site** | [67even.github.io/paynow-billpay-skill](https://67even.github.io/paynow-billpay-skill/), generated from the skill's own reference files so the two can't drift. |

---

## Supported languages and frameworks

### First class: full starter code

| Stack | Minimum | What ships | Location |
|---|---|---|---|
| **PHP / Laravel** | PHP **8.1+**<br>Laravel **10+** (tested on 13) | HTTP client, payment service, purchase + wallet + both webhook controllers, form request, 3 models, 3 queued jobs (status polling, catalogue sync, fiscal data), migration, routes, config, **11 feature tests** | [`templates/php-laravel/`](paynow-paybill-skills/paynow-billpay/templates/php-laravel/) |
| **Python** | Python **3.9+** | `requests` client for the Vendor and Biller APIs, purchase flow with injectable hooks, Flask webhook receivers | [`templates/python/`](paynow-paybill-skills/paynow-billpay/templates/python/) |
| **Node.js / TypeScript** | Node **18+**<br>Express 4 or 5 | Dependency-free `fetch` client, purchase flow, Express webhook receivers (strict `tsc` clean) | [`templates/node/`](paynow-paybill-skills/paynow-billpay/templates/node/) |
| **C# / .NET** | **.NET 8** | Typed `HttpClient`, full request/response models, purchase flow, ASP.NET Core minimal-API webhooks | [`templates/dotnet/`](paynow-paybill-skills/paynow-billpay/templates/dotnet/) |

> **Why PHP 8.1+ and Laravel 10+:** the templates use `readonly` constructor properties,
> named arguments and `FormRequest::after()`.
>
> **Why Node 18+:** the client uses built-in `fetch` and `AbortSignal.timeout`, so there
> are no runtime dependencies.

### Any other language: full protocol reference

[`api-reference.md`](paynow-paybill-skills/paynow-billpay/references/api-reference.md) and
[`vendor-workflows.md`](paynow-paybill-skills/paynow-billpay/references/vendor-workflows.md)
document every endpoint, field, enum and flow. The skill still helps in **Go, Java, Ruby,
Kotlin, Dart** or anything else that speaks HTTP: BillPay is plain JSON over HTTP Basic
auth.

### Tooling

The two scripts in `scripts/` need **Python 3.8+** and nothing else: standard library
only, no `pip install`. They are development tools, not runtime dependencies.

---

## Installation

### Option 1: Claude Code or Cowork (recommended)

Clone the repo and copy the skill into your skills directory:

```bash
git clone https://github.com/67even/paynow-billpay-skill.git
cd paynow-billpay-skill

# Claude Code (project-scoped)
mkdir -p .claude/skills
cp -r paynow-paybill-skills/paynow-billpay .claude/skills/

# or user-scoped, available in every project
mkdir -p ~/.claude/skills
cp -r paynow-paybill-skills/paynow-billpay ~/.claude/skills/
```

Claude picks the skill up automatically the next time it starts.

### Option 2: claude.ai, desktop and mobile

Upload a ZIP once and the skill is available everywhere you're signed in. Run this from
inside `paynow-paybill-skills/`:

```bash
zip -r paynow-billpay.zip paynow-billpay \
  -x '*/evals/*' -x '*/.DS_Store' -x '*/__pycache__/*'
```

Then in Claude: **Customize → Skills → + → Create skill → Upload a skill**.

Zip the *folder*, not its contents. The archive has to contain `paynow-billpay/SKILL.md`,
and the folder name must match the `name:` in `SKILL.md`.

### Option 3: reference only, no skill

You don't need Claude to get value from this repo:

- Read [`PAYNOW-BILLPAY.md`](PAYNOW-BILLPAY.md) or the
  [documentation site](https://67even.github.io/paynow-billpay-skill/).
- Lift the starter code from `paynow-paybill-skills/paynow-billpay/templates/`.
- Run the two scripts against your own credentials and webhooks.

### Configure your credentials

Every template reads the same variables. Start from
[`config/billpay.env.example`](paynow-paybill-skills/paynow-billpay/config/billpay.env.example):

```bash
BILLPAY_BASE_URL=https://billpay.paynow.co.zw
BILLPAY_USERNAME=          # vendor: "API" role · biller: "Biller Admin"/"Biller User"
BILLPAY_PASSWORD=
BILLPAY_TIMEOUT=60
BILLPAY_WEBHOOK_TOKEN=     # leave empty until BillPay confirms they configured it
BILLPAY_SECRET_KEY=        # biller payment-webhook HMAC key
```

Credentials come from Paynow support (vendors) or the BillPay system admin (billers).
Paynow publishes no separate sandbox URL. Test behaviour comes from test credentials plus
the **Test Biller**.

### Verify the install

Run `/skills` in Claude Code. `paynow-billpay` should be listed. Then check the code:

```bash
cd paynow-paybill-skills/paynow-billpay
python3 scripts/verify_webhook.py --self-test       # PASS expected
python3 scripts/billpay_cli.py wallets              # with credentials: your wallet balances
```

---

## Usage

Once installed, the skill triggers on its own. You don't invoke it by name.

**It fires on prompts like these:**

```text
We're an agent with a prefunded BillPay wallet. Add ZESA token and airtime purchases to
our Laravel app, plus the endpoint Paynow calls when biller configs change.

We just failed Paynow BillPay UAT - something about polling and receipts. Here's our code.

I run IT for a school registered as an offline biller. Write a Flask endpoint that verifies
BillPay payment notifications, and a script to bulk-upload our students.

Getting "There must be at least one product" back from /api/payment/process on a STATUS.

node/express: paynow keeps POSTing ["ZETDC","EVD"] to our callback - what is that?
```

It also fires on BillPay's own vocabulary:

- `AuthAmountMandated`, `ReceiptSmses` and `TechnicalNarration`;
- Test Biller prefixes;
- EVD `OutOfStock` errors;
- `X-Signature` on payment notifications.

It stays out of the way for Paynow's merchant checkout (EcoCash, Express Checkout), which
is a different API.

**Reviewing an existing integration?** Point Claude at the code and ask. It checks the code
item by item against the seven UAT tests and the integration checklist.

### What a typical Laravel integration looks like

```php
// 1. AUTH: nothing is charged yet
$tx = $billPay->authorizeProduct($product, $meterNumber, amount: 20.00);

if ($tx->state !== 'authorized') {
    return back()->withErrors($tx->narration);          // customer-safe Narration
}

// 2. Show $tx->member_name / member_address / amount_due, and the customer confirms

// 3. Charge, then PAY exactly once
$tx = $billPay->confirmAndPay($tx);
// → fulfilled (each token SMS sent separately), refunded,
//   or pending (a queued STATUS job runs at 120 s, then every 180 s / 600 s)
```

---

## The two tools

### `billpay_cli.py`: smoke-test from the command line

```bash
export BILLPAY_USERNAME=... BILLPAY_PASSWORD=...

python3 scripts/billpay_cli.py wallets
python3 scripts/billpay_cli.py billers --codes ZETDC,EVD          # filtered; --all only to seed
python3 scripts/billpay_cli.py member <BILLER> <MEMBER>
python3 scripts/billpay_cli.py auth TEST PP12345 --product AI --ref <uuid>
python3 scripts/billpay_cli.py status <uuid>
```

It prints the next step for whatever `Status` comes back, such as
`pending: poll every 180 s (first at 120 s)`. `pay` refuses to run without the AUTH
reference, so you can't create a second payment by accident.

### `verify_webhook.py`: prove a payment-webhook signature

```bash
python3 scripts/verify_webhook.py --self-test                                  # Paynow's worked example
python3 scripts/verify_webhook.py body.json --secret KEY --signature "<X-Signature>"
python3 scripts/verify_webhook.py body.json --secret KEY                       # legacy Hash field
```

On a mismatch it prints the concatenated plaintext, so a wrong field order or price format
is visible immediately. The exit code is `1` on a mismatch, so it drops straight into CI.

---

## What the skill is opinionated about

These are the mistakes that cost money or fail UAT.

1. **AUTH before taking the customer's money.** AUTH validates the meter or account, checks
   your wallet, and returns the member's name for the customer to confirm (UAT test 2).
2. **Never re-send PAY when the outcome is unknown.** After a timeout, connection error or
   5xx, send a STATUS inquiry with the same reference: first after 120 s, then every
   180 s, and every 600 s while `Flagged`.
3. **Every non-final status is pending.** Only `Paid`, `Failed` and `Reversed` end
   polling. This includes `BeingPaid` and `Pending`.
4. **Deliver every `ReceiptHtml` entry and every SMS separately** (UAT tests 4–5).
5. **Seed the catalogue once; never poll `ListBillers`.** Refresh only the codes named in
   the config webhook, and reply `200` first (UAT tests 6–7).
6. **When AUTH returns the price, leave `Price` and `TotalAmount` blank in PAY.** This
   applies to products with `AuthAmountMandated` set, such as council bills and medical
   aid.
7. **Verify biller webhooks over the raw body,** in constant time, before parsing anything.

---

## Repository structure

```
.
├── README.md                    ← you are here
├── CHANGELOG.md
├── LICENSE
├── NOTICE                       Paynow non-affiliation disclaimer
├── PAYNOW-BILLPAY.md            Full corrected BillPay API reference (~95 KB)
├── .github/workflows/tests.yml  CI: PHP, Laravel, Python, Node, .NET, links, rendered SEO
├── assets/                      Logos and the Paynow button used by the README
├── docs/                        The published site - 67even.github.io/paynow-billpay-skill
├── tools/                       Generators and checkers for docs/ (see below)
└── paynow-paybill-skills/
    ├── README.md                Skill-folder guide
    ├── VERIFICATION.md          What was executed to verify this, and what it proves
    └── paynow-billpay/          ← the skill; this folder is what you install
        ├── SKILL.md             Which API, setup, auth, endpoint map, payment-flow rules, UAT
        ├── config/              billpay.env.example, Laravel config/billpay.php
        ├── references/
        │   ├── vendor-workflows.md      Catalogue, purchase, polling, fulfilment, test plan
        │   ├── api-reference.md         Every object, field and enum
        │   ├── biller-notes.md          Test Biller, ZETDC, Pink Lotto, EVD, Liquid Home
        │   ├── biller-api.md            Members, payments CSV, signed webhooks
        │   ├── go-live-checklist.md     The seven UAT tests and the pitfalls
        │   ├── troubleshooting.md       Symptom → cause → fix
        │   └── full-reference.md        Complete verified reference
        ├── templates/           php-laravel · python · node · dotnet
        ├── scripts/             billpay_cli.py · verify_webhook.py
        └── evals/evals.json     Test prompts used to benchmark the skill
```

`docs/` is **generated** from `paynow-paybill-skills/paynow-billpay/references/` by
`tools/build_docs.py`. Edit the reference files, never the generated `docs/*.md`, and
re-run the generator. CI fails if the two drift apart. The other tools check what gets
published:

| Tool | Checks |
|---|---|
| `build_docs.py --check` | `docs/` is in sync with the reference sources |
| `build_og_images.py` | Regenerates the Open Graph cards (needs Playwright) |
| `check_links.py` | Every internal link and anchor resolves |
| `check_docs_site.py` | The Jekyll config is sound and every page has its card |
| `check_meta_tags.py` | Title, description, canonical, OG tags, one `<h1>`, sitemap |
| `check_structured_data.py` | Every JSON-LD block parses and is complete |

---

## Verification

Everything here was verified by **execution**, not inspection.

| Check | Result |
|---|---|
| Reference vs all 17 official BillPay pages, two passes | **19 findings: 18 fixed, 1 withdrawn** |
| Paynow's legacy-hash worked example, recomputed | **matches** `660ad6a8…ce40` |
| `php -l` on every PHP template (PHP 8.4) | **all clean** |
| Laravel feature suite on Laravel 13 / PHP 8.4 (fake HTTP) | **11 tests, 61 assertions passing** |
| Python purchase-flow test: one PAY after a timeout, blank price when AUTH sets it, forex flag copied | **pass** |
| TypeScript `tsc --noEmit --strict` against real `express` types | **0 errors** |
| .NET 8 build; C# legacy-hash verifier reproduces the worked example | **build succeeded, pass** |
| `billpay_cli.py` against the live endpoint with dummy credentials | **HTTP 401** (auth enforced) |

Full record: [`paynow-paybill-skills/VERIFICATION.md`](paynow-paybill-skills/VERIFICATION.md).

> **What this doesn't prove.** No real payment has been sent from this code. Before
> booking UAT, run the Test Biller matrix in
> [`vendor-workflows.md`](paynow-paybill-skills/paynow-billpay/references/vendor-workflows.md)
> §11 against your test credentials. The `PP`, `PT` and `PFF` cases and multi-token ZETDC
> receipts are exactly what offline testing can't catch.

---

## Does it actually help?

Three realistic tasks were run twice, once with the skill and once without:

1. A Laravel vendor flow.
2. A review of a Node integration that failed UAT.
3. A school's Flask webhook plus a bulk member upload.

The outputs were graded against 27 objective assertions.

| | With skill | Without |
|---|---:|---:|
| Assertions passed | **27 / 27** | 24 / 27 (89%) |
| Mean wall-clock | **197 s** | 250 s |
| Mean tokens | 132,645 | **119,692** |

The runs without the skill had read Paynow's documentation online, so they got the basics
right. They missed the details that decide real outcomes:

- They filled `Price`/`TotalAmount` into PAY from AUTH, where Paynow says to leave them
  blank.
- The UAT review never identified `BeingPaid` (the `PP` prefix) as the polling failure.
- They shipped no tests that had actually been run.

The skill costs about **13k more tokens**, the price of reading reference files, and saves
about **52 seconds** on average, because the tested templates replace code written from scratch.

---

## Known gaps in Paynow's BillPay documentation

Places where the [BillPay documentation](https://developers.paynow.co.zw/docs/billpay/intro/)
contradicts itself or omits something an integration needs. Verified on
21 September 2026.

| The docs say | What actually matters | Severity |
|---|---|---|
| Statuses: Authorized, BeingProcessed, Paid, Reversed, Failed, Flagged | The Test Biller `PP` prefix (used in UAT) returns `BeingPaid`, and ZETDC returns `Pending` | **Critical** |
| The official PHP webhook snippet | Builds SQL by string concatenation (injection) and compares hashes with `===` | **High** |
| Test product `AA`: "respect MinPrice/MaxPrice" | The product fields are `MinAmount`/`MaxAmount` | Medium |
| `Metadata` is an "array of key/value pairs" | Pink Lotto sends an array of objects; Liquid Home sends a plain object | Medium |
| Config webhook: "Bearer scheme with basic authentication" | Ambiguous header format. Enforce it too early and UAT test 6 fails | Medium |
| A `Retry` action exists | When it's safe to use isn't documented. Use STATUS | Medium |
| — | No sandbox URL, no biller-webhook retry policy, no part-payment mechanism | Low |

Full detail in [`PAYNOW-BILLPAY.md`](PAYNOW-BILLPAY.md).

---

## Contributing

Issues and pull requests are welcome, especially from anyone running BillPay in production
or who has recently been through UAT.

**Particularly valuable:**

- **The pending status's actual value.** What does `PP` really return on the Test Biller:
  `BeingPaid` or `BeingProcessed`?
- **The config-webhook token header.** The exact format BillPay sends once a token is
  configured.
- **The bulk-upload form field name** for `/api/member/uploadmembers`.
- **The part-payment mechanism** for products with `AuthAmountMandated: false`.
- **Anything that behaves differently from what's written here.** Please include the raw
  request and response bodies with credentials, secret keys and customer data redacted.

**How to contribute:**

1. Fork the repository and create a branch: `git checkout -b fix/short-description`.
2. Make the change.
   - Documentation lives in `paynow-paybill-skills/paynow-billpay/references/`. Edit
     those files, never the generated `docs/*.md`, and regenerate with
     `python3 tools/build_docs.py`.
   - Keep `SKILL.md` under 500 lines, and explain *why* a rule exists, not just what it is.
   - Label anything Paynow doesn't document as *(not specified by Paynow)*.
3. Run the checks below, then open a pull request that describes what changed and how you
   verified it.

**Before opening a PR:**

```bash
cd paynow-paybill-skills/paynow-billpay
python3 scripts/verify_webhook.py --self-test                    # PASS
for f in templates/php-laravel/*.php; do php -l "$f"; done       # no syntax errors
python3 -m py_compile templates/python/*.py scripts/*.py
cd ../.. && python3 tools/build_docs.py --check && python3 tools/check_links.py
```

Never commit real credentials, secret keys or customer data. `.gitignore` covers `.env`
and `*.key`, but the check that matters is the one you do before `git add`.

---

## Author

Written and maintained by **[John Mugabe](https://github.com/johnmugabe)**
([@johnmugabe](https://github.com/johnmugabe)) under [67even](https://github.com/67even).

A companion to the
[Paynow Integration Skill](https://github.com/67even/paynow-integration-skill), which
covers Paynow's merchant checkout (web and Express Checkout).

---

## License

[MIT](LICENSE) © 2026 John Mugabe.

Independent and community-maintained. Not affiliated with, endorsed by, or supported by
Paynow Zimbabwe. Paynow and BillPay are trademarks of their respective owner.

---

<p align="center">
  <sub>Built for the Zimbabwean developers who failed UAT over a status called <code>BeingPaid</code>.</sub>
</p>
