# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] — 2026-09-21

First public release.

### Added

- **The `paynow-billpay` skill** in `paynow-paybill-skills/paynow-billpay/`:
  - `SKILL.md` covers which API you need, setup and HTTP Basic auth, the Vendor and Biller
    endpoint maps, the payment-flow rules and the seven UAT tests.
  - **Seven reference files:** vendor workflows, API reference, biller notes, Biller API,
    go-live checklist, troubleshooting, and the full verified reference.
  - **Templates in four languages:**
    - **Laravel:** client, payment service, purchase, wallet and both webhook
      controllers, form request, models, three queued jobs, migration, routes and an
      11-test feature suite.
    - **Python:** client with purchase flow, plus Flask webhooks.
    - **TypeScript:** client and purchase flow, plus Express webhooks.
    - **C#:** client, models and purchase flow, plus minimal-API webhooks.
  - `scripts/billpay_cli.py`, a standard-library smoke-test CLI. `pay` refuses to run
    without the AUTH reference.
  - `scripts/verify_webhook.py` checks `X-Signature` and the legacy `Hash`, and
    `--self-test` reproduces Paynow's worked example.
- `PAYNOW-BILLPAY.md` is the complete Vendor and Biller API reference. It was verified
  against all 17 official BillPay pages, twice, on 20 and 21 September 2026: 19 findings,
  18 fixed and 1 withdrawn.
- **Documentation site** at <https://67even.github.io/paynow-billpay-skill/>:
  - built with [just-the-docs](https://github.com/just-the-docs/just-the-docs) on the
    67even dark theme, matching the
    [Paynow Integration Skill](https://67even.github.io/paynow-integration-skill/) site;
  - full-text search, Open Graph cards and structured data;
  - generated from the skill's reference files by `tools/build_docs.py`.
- **CI** (`.github/workflows/tests.yml`):
  - PHP lint across 8.1–8.4, and the Laravel feature suite in a fresh Laravel app;
  - Python templates and scripts on 3.9 and 3.12;
  - strict TypeScript on Node 18, 20 and 22;
  - .NET 8 build;
  - docs sync, links, site config, rendered SEO and structured data;
  - a secrets scan.

### Notes

The BillPay documentation contradicts itself in places. The skill encodes the safe
reading and labels the rest *(not specified by Paynow)*:

- `BeingPaid` and `Pending` statuses that aren't in the official list;
- `MinPrice` vs `MinAmount`;
- the shape of `Metadata`;
- the format of the webhook bearer header.

See "Known gaps" in the README.

[Unreleased]: https://github.com/67even/paynow-billpay-skill/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/67even/paynow-billpay-skill/releases/tag/v1.0.0
