# paynow-billpay (skill folder)

This folder is the Claude skill. Install it as-is (see the repository README).

```
paynow-billpay/
├── SKILL.md                 Entry point: which API, setup, auth, endpoint map, payment-flow rules, UAT
├── config/                  billpay.env.example and the Laravel config/billpay.php
├── references/              Loaded on demand
│   ├── vendor-workflows.md      Catalogue sync, purchase, polling, fulfilment, refunds, reconciliation, test plan
│   ├── api-reference.md         Every object, field and enum
│   ├── biller-notes.md          Test Biller, ZETDC, Pink Lotto, EVD, Airtime, Liquid Home
│   ├── biller-api.md            Offline billers: members, payments CSV, signed webhooks
│   ├── go-live-checklist.md     UAT tests, integration checklist, pitfalls
│   ├── troubleshooting.md       Symptom → cause → fix
│   └── full-reference.md        The complete verified reference (source of truth)
├── templates/               Integration templates, adapted rather than pasted
│   ├── php-laravel/             Client, service, controllers, jobs, migration, routes, 11 feature tests
│   ├── python/                  Client + purchase flow, Flask webhooks
│   ├── node/                    TypeScript client, purchase flow, Express webhooks
│   └── dotnet/                  C# client, models, purchase flow, minimal-API webhooks
├── scripts/
│   ├── billpay_cli.py           Smoke-test CLI (standard library only)
│   └── verify_webhook.py        Check X-Signature / legacy Hash; --self-test runs Paynow's worked example
└── evals/evals.json         Test prompts used to benchmark the skill
```
