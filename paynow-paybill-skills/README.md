# paynow-paybill-skills

The Claude skill itself lives in [`paynow-billpay/`](paynow-billpay/). That folder is
what you copy into your skills directory.

For installation, supported languages, usage and benchmarks, see the
[main README](../README.md). This file maps what's in here.

## Layout

```
paynow-billpay/              ← install this folder
├── SKILL.md                 Which API, setup, auth, endpoint map, payment-flow rules, UAT
├── config/                  billpay.env.example, Laravel config/billpay.php
├── references/              Loaded on demand, only when the task needs them
│   ├── vendor-workflows.md          Catalogue sync, purchase, polling, fulfilment, refunds, test plan
│   ├── api-reference.md             Every object, field and enum
│   ├── biller-notes.md              Test Biller, ZETDC, Pink Lotto, EVD, Airtime, Liquid Home
│   ├── biller-api.md                Offline billers: members, payments CSV, signed webhooks
│   ├── go-live-checklist.md         The seven UAT tests, integration checklist, pitfalls
│   ├── troubleshooting.md           Symptom → cause → fix
│   └── full-reference.md            The complete verified reference (source of truth)
├── templates/
│   ├── php-laravel/                 Client, payment service, purchase + webhook controllers,
│   │                                jobs, models, migration, routes, 11 feature tests
│   ├── python/                      requests client + purchase flow, Flask webhooks
│   ├── node/                        TypeScript client, purchase flow, Express webhooks
│   └── dotnet/                      C# client, models, purchase flow, minimal-API webhooks
├── scripts/
│   ├── billpay_cli.py               Smoke-test CLI: wallets, billers, member, auth, pay, status
│   └── verify_webhook.py            Verify X-Signature / legacy Hash; --self-test
└── evals/evals.json         Test prompts used to benchmark the skill
```

`VERIFICATION.md` in this folder records what was executed to verify all of it, and
what the checks do **not** prove.

## Quick check that it works

```bash
python3 paynow-billpay/scripts/verify_webhook.py --self-test   # PASS expected
python3 paynow-billpay/scripts/billpay_cli.py --help
```

The self-test reproduces the legacy-hash worked example that Paynow publishes. A webhook
receiver that passes it has the field order and price formatting right.

## How the skill is structured, and why

`SKILL.md` stays under 200 lines because it is loaded into context every time the skill
triggers. It holds three things:

- the decision between the Vendor and Biller APIs,
- the endpoint map,
- the payment-flow rules that decide whether an integration double-charges a customer
  or fails Paynow's go-live test.

Everything else is loaded on demand. A question about Test Biller prefixes should not
pull in the Biller API, and a signature mismatch should not pull in the UAT checklist.

The templates are written to be adapted rather than pasted. Their comments explain *why*
each guard is there, because a guard whose purpose isn't understood is a guard that gets
removed in the next refactor.
