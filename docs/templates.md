---
title: "Templates & tools"
nav_order: 9
permalink: /templates/
description: "Paynow BillPay starter code for PHP/Laravel, Python, Node.js/TypeScript and C#/.NET, plus a smoke-test CLI and a webhook signature checker."
date: 2026-09-21
last_modified_at: 2026-09-21
seo:
  type: WebPage
image:
  path: /assets/og/templates.png
  width: 1200
  height: 630
  alt: "Templates & tools - Paynow BillPay Skill"
---

# Templates & tools

Every template already follows the payment-flow rules:

- AUTH before charging the customer;
- PAY sent once;
- STATUS polling at 120/180/600 s, with `BeingPaid` and `Pending` treated as pending;
- each receipt and SMS delivered separately;
- the forex flag copied from AUTH;
- a filtered `ListBillers` call after the config webhook;
- `X-Signature` verified over the raw body.

Adapt them rather than pasting them blind. The comments explain why each guard is there.

## Contents
{: .no_toc }

- TOC
{:toc}

---

## Supported languages

| Stack | Minimum | What ships |
|:--|:--|:--|
| **PHP / Laravel** | PHP **8.1+**, Laravel **10+** (tested on 13) | HTTP client, payment service, purchase + wallet + both webhook controllers, form request, three models, three queued jobs, migration, routes, **11 feature tests** |
| **Python** | Python **3.9+** | `requests` client covering the Vendor and Biller APIs, purchase flow with injectable hooks, Flask webhook receivers |
| **Node.js / TypeScript** | Node **18+** | Dependency-free `fetch` client, purchase flow, Express webhook receivers (strict `tsc` clean) |
| **C# / .NET** | **.NET 8** | Typed `HttpClient`, full models, purchase flow, ASP.NET Core minimal-API webhooks |

Any other language can use the [API reference](../api-reference/) and the
[vendor workflows](../vendor-workflows/).

---

## PHP / Laravel

Located in `templates/php-laravel/`. Its `README.md` says where each file goes.

```bash
php artisan migrate
php artisan billpay:seed-catalogue      # the only unfiltered ListBillers call
php artisan queue:work                   # polling jobs are delayed; they can't run on "sync"
php artisan test --filter=BillPayTest    # 11 tests, fake HTTP, no credentials needed
```

Implement `BillPayHooks` (charge, refund, receipt, SMS, alerts) and bind it in a service
provider. Nothing is sold until you do: a failed charge never reaches PAY.

---

## Python

Located in `templates/python/`:

- `billpay_client.py` has `BillPayClient`, `purchase()` and `poll_until_final()`.
- `webhooks_flask.py` has both webhook receivers.

In production, run polling in a worker (Celery, RQ), not in the request.

## Node.js / TypeScript

Located in `templates/node/`:

- `billpayClient.ts` is the client.
- `purchase.ts` is the purchase flow.
- `webhooks.ts` is the Express receivers, with raw-body HMAC and an Express 4-safe
  async error path.

## C# / .NET

Located in `templates/dotnet/`:

- `BillPayClient.cs` is a typed `HttpClient` that omits null fields.
- `BillPayModels.cs` has the models.
- `PurchaseFlow.cs` is the purchase flow.
- `Webhooks.cs` has the minimal-API receivers, whose legacy-hash check reproduces
  Paynow's worked example.

---

## Tools

Both need only Python 3.8+ and the standard library.

### `billpay_cli.py`: smoke-test from the command line

```bash
python3 scripts/billpay_cli.py wallets
python3 scripts/billpay_cli.py billers --codes ZETDC,EVD
python3 scripts/billpay_cli.py member <BILLER> <MEMBER>
python3 scripts/billpay_cli.py auth TEST PP12345 --product AI --ref <uuid>
python3 scripts/billpay_cli.py status <uuid>
```

It prints the HTTP status and the next step for the returned `Status`, for example
`pending: poll every 180 s (first at 120 s)`.

### `verify_webhook.py`: prove a webhook signature

```bash
python3 scripts/verify_webhook.py --self-test
python3 scripts/verify_webhook.py body.json --secret KEY --signature "<X-Signature>"
python3 scripts/verify_webhook.py body.json --secret KEY          # legacy Hash field
```

On a mismatch it prints the concatenated plaintext, so a wrong field order or price
format is visible immediately.

---

This page is maintained by hand. The starter code lives in
[`paynow-paybill-skills/paynow-billpay/templates/`](https://github.com/67even/paynow-billpay-skill/tree/main/paynow-paybill-skills/paynow-billpay/templates).
Spotted something wrong? [Open an issue](https://github.com/67even/paynow-billpay-skill/issues).
