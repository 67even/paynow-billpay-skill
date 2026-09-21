---
title: "Installing the skill"
nav_order: 2
permalink: /install/
description: "Install the Paynow BillPay Skill in Claude Code or on claude.ai: where the files go, how to confirm Claude loaded it, and what to ask once it has."
date: 2026-09-21
last_modified_at: 2026-09-21
seo:
  type: WebPage
image:
  path: /assets/og/install.png
  width: 1200
  height: 630
  alt: "Installing the Paynow BillPay Skill in Claude Code or on claude.ai"
---

# Installing the skill

This site is the reference. The **skill** is what makes Claude use it. Install it once
and Claude reaches for the right answer on its own, instead of polling only on
`BeingProcessed` and failing your go-live test.

Installing takes about a minute. Everything below is optional after that.

## Contents
{: .no_toc }

- TOC
{:toc}

---

## What you are installing

One folder, `paynow-billpay`, containing:

| | |
|:---|:---|
| `SKILL.md` | Which API you need, setup, auth, the endpoint map and the payment-flow rules. Always loaded. |
| `references/` | Seven deep-dive files. Read only when the task needs them, which keeps the cost small. |
| `templates/` | Starter code for Laravel, Python, Node.js/TypeScript and C#, including a Laravel feature-test suite. |
| `scripts/` | `billpay_cli.py` and `verify_webhook.py`, two tools Claude runs for you. |
| `config/` | An `.env` template and a Laravel `config/billpay.php`. |

No dependencies, no network calls from the skill itself, no telemetry. It is Markdown,
starter code and two Python scripts that use only the standard library.

---

## Claude Code

Clone the repository and copy the skill folder into place.

```bash
git clone https://github.com/67even/paynow-billpay-skill.git
cd paynow-billpay-skill
```

**For one project.** The skill loads only in that repository:

```bash
mkdir -p /path/to/your/project/.claude/skills
cp -r paynow-paybill-skills/paynow-billpay /path/to/your/project/.claude/skills/
```

**For every project on your machine:**

```bash
mkdir -p ~/.claude/skills
cp -r paynow-paybill-skills/paynow-billpay ~/.claude/skills/
```

Either way you should end up with a `SKILL.md` at
`<skills-dir>/paynow-billpay/SKILL.md`. Start a new Claude Code session and it's picked
up automatically.

{: .warning }
> Don't install it in both places. A personal skill in `~/.claude/skills/`
> **overrides** a project one with the same name, so an old copy in your home directory
> will silently win over a freshly updated copy in the repo.

---

## Claude.ai, desktop and mobile

Claude on the web and in the desktop app takes a **ZIP**, uploaded once and available
everywhere you're signed in.

```bash
cd paynow-paybill-skills
zip -r paynow-billpay.zip paynow-billpay -x '*/evals/*' -x '*/.DS_Store' -x '*/__pycache__/*'
```

Then in Claude: **Customize → Skills → + → Create skill → Upload a skill**, and choose
that file.

{: .warning }
> Zip the **folder**, not its contents. The archive must contain
> `paynow-billpay/SKILL.md`, and the folder name has to match the `name:` in `SKILL.md`.
> A zip of loose files at the top level is rejected, and the error doesn't say why.

---

## Confirm Claude actually loaded it

This is the step people skip, and then conclude the skill doesn't work.

In Claude Code, run:

```text
/skills
```

`paynow-billpay` should be listed. If it's missing, the folder is in the wrong place or
`SKILL.md` isn't directly inside it. Check that
`ls ~/.claude/skills/paynow-billpay/SKILL.md` finds a file.

On claude.ai, the skill appears under **Customize → Skills** with a toggle. It has to be
switched on.

---

## Use it

You don't invoke the skill by name. Its `description` tells Claude when it's relevant,
and Claude decides. Ask for what you want:

```text
We're an agent with a prefunded BillPay wallet. Add ZESA token and airtime purchases to
our Laravel app, plus the endpoint Paynow calls when biller configs change.

We just failed Paynow BillPay UAT - something about polling and receipts. Here's our code.

Write a Flask endpoint that verifies BillPay payment notifications for our school.

Getting "There must be at least one product" back from /api/payment/process on a STATUS.
```

It also fires on BillPay's own vocabulary even when you never type "BillPay":

- `AuthAmountMandated`, `ReceiptSmses` and `TechnicalNarration`;
- a Paynow callback that receives `["ZETDC","EVD"]`;
- EVD `OutOfStock` errors;
- Test Biller prefixes.

It stays out of the way for Paynow's merchant checkout (EcoCash, Express Checkout),
which is a different API.

---

## Prove the code works before you trust it

```bash
cd paynow-paybill-skills/paynow-billpay
python3 scripts/verify_webhook.py --self-test     # PASS: reproduces Paynow's worked example
python3 -m py_compile templates/python/*.py       # Python templates compile
```

The Laravel templates ship with a feature suite, `templates/php-laravel/BillPayTest.php`.
It covers 11 scenarios, including the PAY-timeout → `BeingPaid` → `Flagged` → `Paid`
path, and uses fake HTTP, so it needs no credentials. See [Templates & tools](../templates/).

---

## Updating

The skill is versioned with the repository, so updating means copying it again:

```bash
cd paynow-billpay-skill && git pull
rm -rf ~/.claude/skills/paynow-billpay
cp -r paynow-paybill-skills/paynow-billpay ~/.claude/skills/
```

On claude.ai, zip and upload it again; the new version replaces the old one.

To uninstall, delete the folder, or toggle the skill off in **Customize → Skills**.

---

## You don't actually need Claude

Nothing here depends on Claude:

- The [reference pages on this site](../api-reference/) cover the complete BillPay interface.
- The templates are ordinary PHP, Python, TypeScript and C#.
- Both scripts run on their own:

```bash
# Smoke-test your credentials and the Test Biller
export BILLPAY_USERNAME=... BILLPAY_PASSWORD=...
python3 scripts/billpay_cli.py wallets
python3 scripts/billpay_cli.py auth TEST PP12345 --product AI --ref "$(uuidgen)"

# Work out why a payment webhook signature doesn't verify
python3 scripts/verify_webhook.py body.json --secret "$BILLPAY_SECRET_KEY" --signature "<X-Signature>"
```

`billpay_cli.py pay` refuses to run without the AUTH reference, so you can't create a
second payment by accident. After a timeout, run `status` and never re-run `pay`.
