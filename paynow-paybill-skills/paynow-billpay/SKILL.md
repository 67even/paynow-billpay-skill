---
name: paynow-billpay
description: "Paynow BillPay (billpay.paynow.co.zw) integration expert for Zimbabwe's bill-payment and vending API: vendors/agents paying bills or selling ZESA/ZETDC tokens, airtime, EVD vouchers, council, school or medical-aid payments from a prefunded wallet, and offline billers receiving payments. BillPay's rules are specialised and easy to get wrong, so consult this skill for any BillPay code, review, error, webhook or UAT question, even a quick one. Recognise it by: BillPay; AUTH/PAY/Status on /api/payment/process; ListBillers or billerCodes; member numbers; AuthAmountMandated, RequiresForexPayment, ReceiptSmses, ReceiptHtml, TechnicalNarration, BillPayReference; statuses BeingProcessed/BeingPaid/Flagged; Test Biller prefixes; a callback receiving an array of biller codes; X-Signature or Hash on payment notifications; /api/member endpoints or downloadpayments; EVD OutOfStock errors; wallets. Not for Paynow web/Express checkout where customers pay a merchant via EcoCash or cards."
---

# Paynow BillPay integration

BillPay lets a **vendor** (a reseller with a prefunded wallet) pay bills and buy services on behalf of customers, and lets an **offline biller** receive those payments. Most integrations are vendor integrations. Payment flows here move real money and are checked in a joint go-live test (UAT), so correctness matters more than speed. Most of the rules below exist because breaking them either double-charges someone or fails UAT.

`references/full-reference.md` is the source of truth (verified against developers.paynow.co.zw, 21 Sep 2026). Everything else in this skill is derived from it.

## 1. Work out which API the user needs

| The user is… | API | Start with |
|---|---|---|
| An app, fintech, bank, shop or agent selling tokens, airtime or bill payments to its customers | **Vendor API** | §3–§6 below, `references/vendor-workflows.md` |
| A service provider (school, club, council, SACCO) that wants BillPay to collect payments for it and tell it who paid | **Biller API** | §7 below, `references/biller-api.md` |
| An *online* biller that wants payments processed in its own system | Neither. Tell them Paynow prefers online biller integrations and they should contact Paynow support. | — |

If the user's stack is clear from context (e.g. a Laravel repo), use the matching template without asking. Ask only when neither the stack nor the role can be inferred.

## 2. Setup and authentication

1. **Credentials come from Paynow.** Vendors need a user with the **API** role, requested from Paynow support. Billers need a **Biller Admin** or **Biller User**, requested from the BillPay system admin.
2. **Vendors need prefunded wallets:** ZWG for products that don't require forex, USD for those that do.
3. **Webhook URLs are registered manually.** Vendors register the biller-config webhook with Paynow support (optionally agreeing a bearer token). Billers register the payment webhook with the system admin and receive a **secret key**.
4. **Auth is HTTP Basic on every request:** `Authorization: Basic base64(username:password)`. It is the only mandatory header. Send JSON with `Content-Type: application/json`. The Biller API also accepts form-post and XML bodies.
5. **Base URL:** `https://billpay.paynow.co.zw`. Paynow does not publish a separate sandbox URL. Test behaviour comes from test credentials plus the **Test Biller** (`references/biller-notes.md`). Keep the base URL in config anyway.
6. Keep credentials in environment variables or a secret store. Start from `config/billpay.env.example` (and `config/laravel/billpay.php` for Laravel).

Run `python scripts/billpay_cli.py wallets` once credentials exist. It is the quickest way to prove auth works.

## 3. Vendor endpoint map

Base `https://billpay.paynow.co.zw`, Basic auth on all of them.

| Purpose | Method + path | Notes |
|---|---|---|
| Biller catalogue | `GET /api/payment/ListBillers` | Large payload. Call unfiltered only to seed your database |
| Refresh specific billers | `GET /api/payment/ListBillers?billerCodes=A,B` | Use this after a config webhook |
| Member lookup | `GET /api/payment/member?billerCode=&memberNumber=` (or `POST`) | `ResultCode` 0 = not found, 1 = found, 2 = biller offline |
| AUTH / PAY / STATUS / RETRY | `POST /api/payment/process` | `Action` = `Auth`, `Pay`, `Status`, `Retry` |
| Payment history (90 days) | `GET /api/payment/list` | `From`/`To` as `dd-MMM-yyyy HH:mm:ss`, `Page`, `PerPage` ≤ 200 |
| Wallets | `GET /api/wallets` | Balance, LowBalance, MinimumBalance, Status |
| Reverse / refund | `POST /api/payment/reverse` | `OriginalReference` + new `Reference`. Very few billers support it |
| Target stats | `GET /api/payment/TargetStats?billercode=&currency=` | Empty body if not configured |
| Recent payers feed | `GET /api/payment/feed?billercode=&before=` | 20 per page; `before` = Unix time in ms |

Field-level detail (Biller, Product, request and response objects, enums) is in `references/api-reference.md`.

## 4. The payment flow

```
catalogue (cached) → member lookup (optional) → AUTH → show member + amount → customer confirms
→ debit customer → PAY → [Paid | Failed | pending → STATUS polling] → deliver receipts
```

Each rule below comes with the reason for it.

- **AUTH before taking the customer's money.** AUTH validates the request, checks your wallet and the biller, and returns the member's name and account details. Charging first means refunding every typo'd meter number.
- **Show `MemberName` (and `MemberAddress` if present) after AUTH.** This is UAT test 2, and it is how customers catch wrong accounts.
- **PAY must mirror AUTH:** same `Reference`, `BillerCode`, `MemberNumber` and products. There is one exception. When AUTH returns the price or balance (product `AuthAmountMandated` is `true` or `false`), charge the amount AUTH returned and leave `TotalAmount` and every product `Price` **blank** in PAY. `AuthAmountMandated: null` means you set the price in AUTH and repeat it in PAY. `true` means full payment only. `false` allows part payment, but Paynow doesn't document how to send a smaller amount, so confirm with support before offering it.
- **Set `RequiresForexPayment` on AUTH and PAY** for forex products. It acknowledges that the USD wallet will be used. If the product's `RequiresForex` is `null`, leave the flag off in AUTH and copy AUTH's answer (`Products[i].RequiresForexPayment`) into PAY. The templates already do this.
- **HTTP 200 is not success.** Read `Status`. Show `Narration` to customers. Log `TechnicalNarration` and keep it internal.
- **Never re-send PAY when the outcome is unknown.** After a timeout, connection error or 5xx, the payment may have gone through. Wait **120 s**, send `{"Action":"Status","Reference":…}`, then poll every **180 s**. Use **600 s** while the status is `Flagged`. These intervals are specified by Paynow, and the 120/180 cadence is tested in UAT.
- **Treat every non-final status as pending.** Final statuses are `Paid`, `Failed` and `Reversed`. The official status list also includes `Authorized`, `BeingProcessed` and `Flagged`, but the integration notes use **`BeingPaid`** (Test Biller `PP` prefix, which is exactly what UAT test 3 uses) and **`Pending`** (ZETDC under load). Code that only checks `BeingProcessed` fails UAT, so use a pending set such as `{BeingProcessed, BeingPaid, Pending}` and escalate anything unrecognised.
- **Use a 60 s HTTP timeout.** Billers can be slow, and the Test Biller `AT`/`PT` prefixes deliberately take 120 s.
- **Cap polling, then escalate.** After N attempts, alert operations staff, tell the customer you're on it, and pause inquiries.
- **Deliver each `ReceiptHtml` entry and each `ReceiptSmses` entry separately.** Never merge them. ZETDC can return several tokens and customers need each one. This is UAT tests 4–5. Check the arrays exist first: the Test Biller always returns them, live billers often don't.
- **Vouchers** are in `Products[].Vouchers[]`: `VoucherCode`, `SerialNumber`, `ExpiryDate`, `ValidDays`, `Pin`, `Batch`.
- **Record `WalletBalanceAfterDebit`** from each payment and alert staff below a threshold. Checking and displaying the wallet balance is UAT test 1.
- **Auto-invoiced billers** (`VendorMustInvoicePayments`): the fiscal fields arrive asynchronously. Send a STATUS later to collect `VendorInvoiceReference`, `VendorFiscalSignature` (render as a QR code) and `VendorFiscalMetadata`.
- **Generate a unique `Reference` per transaction** (a UUID) and store it before calling AUTH. It is your key for STATUS and for reconciliation.
- **Guard the confirm step against double-clicks.** Atomically move the transaction from `authorized` to `confirming` before charging, so a retried request can't charge or PAY twice. If the customer never confirms, expire the AUTH after about 30 minutes. Nothing was charged, so this is safe.

The step-by-step version with state machine, database fields and edge cases is in `references/vendor-workflows.md`.

## 5. Keeping the catalogue in sync (vendor)

- Seed billers and products into **your own database** with one unfiltered `ListBillers`. Never call it per page load. Polling it fails UAT test 7 and is slow.
- Honour the catalogue metadata:
  - `Enabled` on both billers and products.
  - `MemberNumberFieldLabel`, `MemberNumberFieldDesc` and `MemberNumberFieldRegex`.
  - `MinAmount` and `MaxAmount`.
  - `AllowMultipleProductsPerPayment`.
  - `AllowSpecifyQuantity`.
  - Required `MetadataFields`.
  - `Pre-` and `PostPurchaseInstructions`.
- **Config webhook:** BillPay POSTs a JSON array of changed biller codes, e.g. `["ZETDC","EVD"]`. Return `200 OK` quickly, then call `ListBillers?billerCodes=…` **filtered** and upsert the results. That is UAT test 6. Without a 200, BillPay retries every 30 s, up to 3 times. Guard against an empty array, because that would turn into an unfiltered call.
- **Bearer token:** only enforce it once the BillPay team has configured your token. The official wording ("Bearer scheme with basic authentication") is ambiguous, so confirm the header format first. Rejecting their calls too early fails UAT test 6.

**Biller and product codes come from the catalogue.** Paynow doesn't publish them, and apart from the Test Biller and the examples in `references/biller-notes.md` (EVD, `AIRTIME`, `AIRTIME_USD`, Liquid `USD_FIB_PAYG_*`), don't hard-code guesses such as `ZETDC` or `ZESA`. Seed the catalogue, then pick codes from `billpay_billers` and `billpay_products`, or map friendly names to codes in config. When you write code for a user, say which codes they need to confirm.

## 6. Biller-specific quirks

Read `references/biller-notes.md` before integrating any of these:

- **Test Biller:** prefixes `AT`, `AF`, `PT`, `PF`, `PP`, `PFF`, `USD` and test products `AI`, `AM`, `AA`, `RV`, `FP`.
- **ZETDC:** multiple tokens per payment, `Pending` under load, test meters.
- **Pink Lotto:** `BetInfo` encoding `01;02;03;04;05;06$…$`, and `Quantity` must equal the number of bet sets.
- **EVD:** AUTH reserves vouchers; stock errors arrive as JSON inside `TechnicalNarration`.
- **Airtime:** `AIRTIME` and `AIRTIME_USD`.
- **Liquid Home PAYG:** a `Service Login` metadata value is required. Get it from the member lookup's `AccountDetails`.

**`Metadata` shape varies.** The field table says "array of key/value pairs". Pink Lotto sends `[{"BetInfo":"…"}]`; Liquid sends `{"Service Login":"…"}`. Let client models accept both and follow each biller's example.

## 7. Biller API (offline billers)

| Purpose | Method + path |
|---|---|
| Create / update member | `POST /api/member/create`, `POST /api/member/update` |
| Delete / undelete | `POST /api/member/delete`, `POST /api/member/undelete` |
| List / view | `GET /api/member/list?Page=&PerPage=&Filters=`, `GET /api/member/single/{memberNumber}` |
| Bulk upsert / delete (CSV, multipart) | `POST /api/member/uploadmembers`, `POST /api/member/uploadmembersdelete` |
| Payments CSV | `GET /api/member/downloadpayments?From=&To=&MemberNumber=` |

Key rules:

- **`update` blanks any optional field you omit,** so always send the full record.
- **Deleted member numbers can never be reused.** Undelete instead.
- **Bulk CSV columns are fixed and ordered:** Member Number, Full Name, Email, Mobile, Postal Address, then any extra detail columns.
- **Verify payment webhooks with the `X-Signature` header.** It is Base64(HMAC-SHA256(raw body, secret key)). Compute it over the **raw request bytes** before parsing JSON, and compare in constant time.
- **The legacy `Hash` field** is SHA-256, lowercase hex, of the concatenated payment field values plus the secret key, in this exact order: PaymentId, BillPayReference, BankReference, PaidDate, MemberNumber, MemberName, ProductCode, ProductPrice (2 dp), ProductDepartment (or an empty string).
- Details and a worked example are in `references/biller-api.md`. `scripts/verify_webhook.py` checks either signature from a saved payload.

## 8. Templates and code

Copy a template into the user's project and adapt it. Don't write a client from scratch. The templates already encode every rule above.

| Stack | Files |
|---|---|
| PHP / Laravel | `templates/php-laravel/` (see its `README.md` for where each file goes). Services: `BillPayClient`, `BillPayPaymentService` (`lookupMember`, `authorizeProduct`, `confirmAndPay`, `checkStatus`), `BillPayHooks` (your charge, refund and SMS hooks) and the exceptions. Models: `BillPayTransaction`, `BillPayBiller`, `BillPayProduct`. HTTP: `BillPayPurchaseController` (catalogue, start, confirm, cancel, status, per-receipt pages), `StartBillPayPurchaseRequest` (validates against the catalogue), `BillPayWalletController`, both webhook controllers. Jobs: `PollBillPayStatus`, `SyncBillPayBillers`, `FetchBillPayFiscalData`. Plus the `create_billpay_tables.php` migration, `routes.php`, `BillPayTest.php` (11 feature tests) and `config/laravel/billpay.php` |
| Python | `templates/python/`: `billpay_client.py` (client + purchase flow), `webhooks_flask.py` |
| Node / TypeScript | `templates/node/`: `billpayClient.ts`, `purchase.ts`, `webhooks.ts` (Express) |
| C# / .NET | `templates/dotnet/`: `BillPayClient.cs`, `BillPayModels.cs`, `PurchaseFlow.cs`, `Webhooks.cs` (minimal API) |

For Laravel, the templates are a complete, tested vertical slice. Copy them, run `BillPayTest.php`, then change only what the app needs. That is faster and safer than rewriting them.

When adapting a template:

- Replace the placeholder hooks (`chargeCustomer`, `refundCustomer`, `sendSms`, …) with the app's own services.
- Keep the pending-status set, the timeout handling and the individual receipt delivery intact.
- Keep polling out of the web request. Use a queue or job (Laravel jobs, Celery/RQ, BullMQ, Hangfire…), because polling can take many minutes.

## 9. Scripts

- `scripts/billpay_cli.py`: a standard-library CLI for smoke tests. Commands: `wallets`, `billers [--codes]`, `member`, `auth`, `pay`, `status`, `payments`. It reads `BILLPAY_USERNAME`, `BILLPAY_PASSWORD` and optionally `BILLPAY_BASE_URL`. Use it to check credentials or reproduce an issue outside the app.
- `scripts/verify_webhook.py`: verifies an `X-Signature` or legacy `Hash` for a saved webhook body. Useful when a receiver keeps rejecting real calls.

## 10. Go-live (UAT)

UAT is a joint screen-share with Paynow. There are seven tests:

1. Wallet balance is visible.
2. Member details are shown after AUTH.
3. Polling follows 120 s/180 s (tested with the `PP` prefix).
4. Each `ReceiptHtml` entry is shared individually.
5. Each SMS is sent individually.
6. The config webhook gets a 200 and a filtered `ListBillers` call.
7. No `ListBillers` polling.

Before booking UAT, walk through `references/go-live-checklist.md` with the user.

## 11. When answering questions or reviewing code

- **Start debugging from the symptom.** Look it up in `references/troubleshooting.md`, which maps symptoms to causes: failed UAT tests, 401/400 errors, double charges, missing receipts, and signature mismatches.
- Cite the specific rule and its reason, e.g. "this re-sends PAY after a timeout, which risks a double payment; use STATUS after 120 s".
- Keep official facts separate from recommendations. Paynow does not document:
  - a sandbox URL,
  - a biller-webhook retry policy,
  - `Retry`-action semantics,
  - the part-payment mechanism,
  - list-filter values.

  Say so rather than inventing them, and point the user to Paynow support.
- When reviewing an existing integration, check the code against `references/go-live-checklist.md` item by item.
