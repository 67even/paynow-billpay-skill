---
title: Home
nav_order: 1
permalink: /
description: >-
  Integrate Paynow BillPay correctly in PHP/Laravel, Python, Node.js and C#.
  ZESA tokens, airtime, EVD vouchers and bill payments - and pass Paynow's
  go-live UAT the first time.
last_modified_at: 2026-09-21
image:
  path: /assets/og/home.png
  width: 1200
  height: 630
  alt: "Paynow BillPay Skill - integrate Paynow BillPay in PHP, Laravel, Python, Node.js and C#"
---

<div class="hero" markdown="0">
  <img class="hero-mark" src="{{ '/assets/images/67even-logo.png' | relative_url }}" alt="67even">
  <h1 class="hero-title">Paynow BillPay Skill</h1>
  <p class="hero-tagline">Vend ZESA tokens, airtime and bill payments through Paynow BillPay without double-charging anyone, and without failing go-live UAT.</p>
  <p class="hero-badges">
    <a href="https://github.com/67even/paynow-billpay-skill/stargazers"><img src="https://img.shields.io/github/stars/67even/paynow-billpay-skill?style=flat-square&logo=github&label=Stars&color=FF3131&labelColor=0C0E0B" alt="GitHub stars"></a>
    <a href="https://github.com/67even/paynow-billpay-skill/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/67even/paynow-billpay-skill/tests.yml?branch=main&style=flat-square&logo=githubactions&logoColor=white&label=tests&color=FF3131&labelColor=0C0E0B" alt="Tests"></a>
    <a href="https://github.com/67even/paynow-billpay-skill/blob/main/LICENSE"><img src="https://img.shields.io/github/license/67even/paynow-billpay-skill?style=flat-square&label=License&color=FF3131&labelColor=0C0E0B" alt="MIT licence"></a>
    <a href="https://github.com/67even/paynow-billpay-skill"><img src="https://img.shields.io/github/last-commit/67even/paynow-billpay-skill?style=flat-square&label=Updated&color=FF3131&labelColor=0C0E0B" alt="Last commit"></a>
  </p>
</div>

<div class="hero-actions" markdown="1">
[View on GitHub](https://github.com/67even/paynow-billpay-skill){: .btn .btn-primary }
[Install the skill](install/){: .btn }
</div>

---

## The status that fails UAT

Paynow's BillPay documentation lists the payment statuses as `Authorized`,
`BeingProcessed`, `Paid`, `Reversed`, `Failed` and `Flagged`. So most integrations poll
like this:

```js
while (result.Status === 'BeingProcessed') {
  await sleep(180_000);
  result = await status(reference);
}
```

UAT test 3 checks your polling with the Test Biller's `PP` prefix. According to the same
documentation's integration notes, that prefix returns **`BeingPaid`**, which isn't in
the status list. The loop above exits at once, treats a pending payment as final, and
fails the test. ZETDC under load returns a third undocumented value, **`Pending`**.

The fix is one line: treat **every status except `Paid`, `Failed` and `Reversed`** as
pending. Nothing in the official docs tells you to.

{: .warning }
> A timeout on PAY does not mean the payment failed. BillPay may already have debited
> your wallet and delivered the token. Re-sending PAY after a timeout is how customers
> get charged twice. Use a STATUS inquiry instead.

Every claim on this site was checked against all 17 pages of Paynow's BillPay
documentation on 21 September 2026.

---

## Where to start

| | |
|:--|:--|
| [**Installing the skill**](install/) | Put the skill in Claude Code or on claude.ai, and confirm it loaded. |
| [**Troubleshooting**](troubleshooting/) | Something is broken right now. Symptom-to-cause table. |
| [**Vendor integration**](vendor-workflows/) | Catalogue sync, AUTH → PAY, status polling, receipts, refunds. |
| [**Go-live & UAT**](go-live/) | The seven tests Paynow runs, and what fails them. |
| [**Biller notes**](biller-notes/) | Test Biller prefixes, ZETDC, Pink Lotto, EVD, Liquid Home. |
| [**Biller API**](biller-api/) | Offline billers: members, bulk upload, signed payment webhooks. |
| [**API reference**](api-reference/) | Every object, field and enum, for any language. |
| [**Templates & tools**](templates/) | Laravel, Python, Node.js and C# starter code, plus two scripts. |

---

## The rules that stop money going missing

1. **AUTH before you take the customer's money.** AUTH validates the meter or account,
   checks your wallet and returns the member's name for the customer to confirm.
2. **Never re-send PAY when the outcome is unknown.** After a timeout, connection error or
   5xx, send a STATUS inquiry with the same reference: first after 120 s, then every
   180 s, and every 600 s while `Flagged`.
3. **Treat every non-final status as pending.** That includes `BeingPaid` and `Pending`,
   which the status list omits.
4. **Deliver every receipt and SMS separately.** ZETDC can return several tokens in one
   payment, and merging them fails UAT.
5. **Never poll `ListBillers`.** Seed your catalogue once, then refresh only the biller
   codes named in Paynow's config webhook.

---

## Install the skill

This reference also ships as a skill for Claude, so the rules apply themselves while you
write the integration.

```bash
git clone https://github.com/67even/paynow-billpay-skill.git
cp -r paynow-billpay-skill/paynow-paybill-skills/paynow-billpay ~/.claude/skills/
```

It includes two tools that need only Python and no dependencies. One checks a webhook
signature against Paynow's published worked example. The other smoke-tests your
credentials and the Test Biller from the command line.

```bash
cd paynow-billpay-skill/paynow-paybill-skills/paynow-billpay
python3 scripts/verify_webhook.py --self-test        # PASS expected
python3 scripts/billpay_cli.py wallets               # needs BILLPAY_USERNAME/PASSWORD
```

---

## Known gaps in Paynow's BillPay documentation

Places where the [BillPay documentation](https://developers.paynow.co.zw/docs/billpay/intro/)
contradicts itself or leaves out something an integration needs. Verified on
21 September 2026.

| The docs say | What actually matters | Severity |
|:--|:--|:--|
| Statuses: Authorized, BeingProcessed, Paid, Reversed, Failed, Flagged | The Test Biller `PP` prefix (used in UAT) returns `BeingPaid`, and ZETDC returns `Pending` | **Critical** |
| The official PHP webhook snippet | Builds SQL by string concatenation (injection) and compares hashes with `===` | **High** |
| Test product `AA`: "respect MinPrice/MaxPrice" | The product fields are `MinAmount`/`MaxAmount` | Medium |
| `Metadata` is an "array of key/value pairs" | Pink Lotto sends an array of objects; Liquid Home sends a plain object | Medium |
| Config webhook: "Bearer scheme with basic authentication" | Ambiguous header format. Enforce it too early and UAT test 6 fails | Medium |
| A `Retry` action exists | When it's safe to use is not documented. Use STATUS | Medium |
| — | No sandbox URL is published. Test behaviour comes from test credentials and the Test Biller | Low |

If you find these no longer match the docs, please
[open an issue](https://github.com/67even/paynow-billpay-skill/issues).
