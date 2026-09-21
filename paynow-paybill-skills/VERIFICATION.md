# Verification record: 21 September 2026

Everything in this folder was verified by execution, not by inspection. This file records
what was run, so the next person doesn't have to take it on trust.

## 1. The reference against Paynow's documentation

`paynow-billpay/references/full-reference.md` (also at the repository root as
`PAYNOW-BILLPAY.md`) was compared field by field with all **17** pages under
<https://developers.paynow.co.zw/docs/billpay/>: the overview, 12 Vendor API pages and
4 Biller API pages. This was done twice, on 20 and 21 September 2026. The second pass
confirmed the pages had not changed.

- **Findings:** 19 were raised on the first pass. 18 were fixed and 1 was withdrawn. The
  withdrawn one said the example emails were placeholders; decoding Paynow's Cloudflare
  email obfuscation showed they are the official values.
- **Legacy-hash worked example:** recomputed, and the SHA-256 matches the published value
  `660ad6a8…ce40`.
- **Gaps in Paynow's own docs:** statements the docs don't support are labelled
  *(not specified by Paynow)*. These cover the sandbox URL, `Retry` semantics, the
  part-payment mechanism, the bearer-token header format and biller-webhook retries.

## 2. Templates and scripts

| Check | Environment | Result |
|---|---|---|
| `php -l` on every PHP template | PHP 8.4 | **all clean** |
| Laravel feature suite `BillPayTest.php` (fake HTTP): happy path, HTTP purchase flow, forex flag copied from AUTH, a double confirm getting 409, AUTH-returned price, PAY timeout → `BeingPaid` → `Flagged` → `Paid` with a single PAY, refunds, a failed charge never reaching PAY, the polling cap, both webhooks, filtered sync | Laravel 13.32, PHP 8.4 | **11 tests, 61 assertions passing** |
| Python templates compile. Purchase-flow unit test: PAY sent exactly once after a timeout, `Price`/`TotalAmount` omitted when AUTH sets the price, forex flag copied, each SMS delivered | Python 3.11 | **pass** |
| TypeScript templates: `tsc --noEmit --strict` against real `express` and `@types/node` | TypeScript 5.9, Node 22 | **0 errors** |
| C# templates build in an ASP.NET Core minimal-API project. The legacy-hash verifier reproduces Paynow's worked example; `ForPay(true)` drops `Price`/`TotalAmount`; the STATUS body is `{Reference, Action}` only | .NET 8.0.425 | **build succeeded, self-checks pass** |
| `scripts/verify_webhook.py --self-test` | Python 3.8+ | **PASS** |
| `scripts/billpay_cli.py wallets` against the live `https://billpay.paynow.co.zw` with dummy credentials | — | **HTTP 401** (the endpoint is reachable and enforces auth) |

## 3. Does the skill change the outcome?

Three realistic tasks were each run with and without the skill:

1. A Laravel vendor flow.
2. A review of a failed-UAT Node integration.
3. A school's Flask webhook plus a bulk member upload.

Each output was then graded against 27 objective assertions. See the main README for the
table. The skill run passed all 27; the run without it passed 24 (89%). The run without
the skill had three failures:

- It filled `Price`/`TotalAmount` into PAY from AUTH instead of leaving them blank, as
  Paynow instructs.
- Its review never identified `BeingPaid` (the `PP` prefix UAT uses) as the polling
  failure.
- It shipped no executed tests.

The school-biller task did not discriminate: both runs passed everything.

## What this does not prove

- **No real payment has been sent.** Everything above is offline or uses fake HTTP; the
  only live call proved authentication is enforced.
- **Test-environment runs are still needed.** Before booking UAT, run the Test Biller
  matrix in `references/vendor-workflows.md` §11 against real test credentials. The
  `PP`, `PT` and `PFF` cases and multi-token ZETDC receipts are exactly what offline
  testing can't catch.
- **Some behaviour needs confirming with Paynow support**: the exact value of the
  pending statuses (`BeingPaid`/`Pending`), the config-webhook token header, and the
  bulk-upload form field name.
