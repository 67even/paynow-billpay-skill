# Vendor integration workflows

A step-by-step guide to building a production Vendor API integration. Field and enum details are in `api-reference.md`. Biller quirks are in `biller-notes.md`.

## Contents
1. Architecture at a glance
2. Data model
3. Workflow A: seed and sync the catalogue
4. Workflow B: purchase (AUTH → confirm → PAY)
5. Workflow C: resolve unknown or pending outcomes (STATUS polling)
6. Workflow D: fulfilment (receipts, SMS, vouchers, display data)
7. Workflow E: refunds and reversals
8. Workflow F: auto-invoice (fiscal data)
9. Workflow G: wallet monitoring
10. Workflow H: reconciliation
11. Test plan with the Test Biller
12. Things Paynow does not specify

---

## 1. Architecture at a glance

```
             ┌──────────── your app ─────────────┐
customer ──► │ checkout UI ─► PaymentService ────┼──► POST /api/payment/process (AUTH, PAY)
             │                   │               │
             │                   ▼               │
             │          billpay_transactions DB  │
             │                   ▲               │
             │   queue worker: PollStatus job ───┼──► POST /api/payment/process (STATUS)
             │                                   │
BillPay ───► │ /webhooks/billpay/config ─► sync ─┼──► GET /api/payment/ListBillers?billerCodes=…
             └───────────────────────────────────┘
```

Design principles:

- **Persist before you call.** Write the transaction row, with its `Reference`, before sending AUTH. A crash mid-flow can then always be recovered with STATUS.
- **Polling never happens inside a web request.** A pending payment can take many minutes; ZETDC and the `PP` test case can take hours. Queue a job instead.
- **The transaction row is a state machine.** Only move forward and log every transition.

## 2. Data model

Suggested tables. The Laravel migration in `templates/php-laravel/create_billpay_tables.php` implements them.

**billpay_billers**

| Column | Notes |
|---|---|
| code | Primary key |
| name, description | |
| enabled | |
| member_number_label, member_number_desc, member_number_regex | |
| allow_multiple_products | |
| vendor_must_invoice | |
| icon_url, logo_url | |
| raw_json | The full Biller object |
| synced_at | |

**billpay_products**

| Column | Notes |
|---|---|
| biller_code + code | Unique together |
| name | |
| price | Nullable |
| department | |
| requires_forex | Nullable boolean |
| auth_amount_mandated | Nullable boolean |
| min_amount, max_amount | |
| returns_vouchers | |
| allow_specify_quantity | |
| metadata_fields | JSON |
| enabled | |
| raw_json | |

**billpay_transactions**

| Column | Notes |
|---|---|
| reference | Unique; your UUID |
| biller_code, member_number | |
| products | The request JSON |
| total_amount | |
| currency | |
| state | See below |
| billpay_status | Last `Status` from BillPay |
| billpay_reference, biller_payment_reference | |
| member_name, member_address | |
| auth_response, pay_response | JSON |
| narration, technical_narration | |
| wallet_balance_after | |
| status_checks, next_check_at | For polling |
| customer_charged_at, customer_refunded_at | |
| receipts_delivered_at | |
| fiscal_invoice_reference, fiscal_signature, fiscal_metadata | |
| created_at, updated_at | |

Internal `state` values:

```
created → authorized → confirming → customer_charged → paying
   confirming → charge_failed (charge declined, no PAY)
   paying → paid → fulfilled
   paying → pending (unknown outcome / BeingProcessed / BeingPaid / Pending / Flagged) → paid | failed | needs_attention
   paying → failed → refunded
   authorized → abandoned (customer did not confirm)
   auth_failed
```

## 3. Workflow A: seed and sync the catalogue

1. On first deploy (or via a manual admin action), call `GET /api/payment/ListBillers` **once** and upsert billers and products.
2. Build the UI from your database, never from live API calls:
   - Hide disabled billers and products.
   - Label the member-number input with `MemberNumberFieldLabel` and `MemberNumberFieldDesc`.
   - Validate input against `MemberNumberFieldRegex`.
   - Show an amount input when `Price` is null and AUTH won't return it (`AuthAmountMandated` null). Enforce `MinAmount` and `MaxAmount`.
   - Show a quantity input when `AllowSpecifyQuantity` is true.
   - Collect required `MetadataFields`.
   - Show `PrePurchaseInstructions`.
3. **Config webhook** (`POST` from BillPay, body `["CODE1","CODE2"]`):
   1. If you have agreed a bearer token with BillPay, validate it (constant-time). Otherwise accept the call.
   2. Respond `200 OK` immediately.
   3. Asynchronously call `GET /api/payment/ListBillers?billerCodes=CODE1,CODE2` and upsert the results.
   4. If the array is empty or invalid, do nothing. Never fall back to an unfiltered call.
4. Why this matters: out-of-sync prices get PAY requests rejected, and polling or unfiltered calls fail UAT tests 6–7.

## 4. Workflow B: purchase

1. **Create** the transaction row with a new UUID `Reference` (`state=created`).
2. *(Optional)* **Member lookup** (`GET /api/payment/member`) for early validation. `ResultCode` 0 means a wrong number, so ask the customer to fix it. `2` means the biller is offline, so try later.
3. **AUTH:** `POST /api/payment/process`:
   ```json
   {"Action":"Auth","BillerCode":"<from catalogue>","MemberNumber":"37132567431","Reference":"<uuid>",
    "TotalAmount":20,"Products":[{"Code":"<from catalogue>","Quantity":1,"Price":20,"RequiresForexPayment":true}]}
   ```
   Paynow doesn't publish real biller and product codes. Take them from your synced catalogue.
   - For `AuthAmountMandated` = `true` or `false` products, you may omit `Price` and `TotalAmount`, because AUTH returns them.
   - Set `RequiresForexPayment` whenever the product needs forex. When the catalogue says `null`, leave it off and copy AUTH's `Products[i].RequiresForexPayment` into PAY.
   - HTTP 400 means a validation error (`ModelState`). Fix the input and set `state=auth_failed`.
   - `Status` ≠ `Authorized` means AUTH failed. Show `Narration` and set `state=auth_failed`.
4. **Confirm with the customer.** Show:
   - `AuthData.MemberName`, plus `MemberAddress` if present (UAT test 2).
   - `AccountDetails` and `AccountBalances` where useful.
   - The amount: AUTH's `TotalAmount` when AUTH set the price, otherwise yours.
   - Any EVD stock errors from `TechnicalNarration`, translated into friendly messages.
5. **Charge the customer** (your own payment method) only after they confirm. First move the row atomically from `authorized` to `confirming`, so a double-click can't charge twice. A failed charge ends in `charge_failed` with no PAY; a successful one moves to `customer_charged`. Expire unconfirmed AUTHs after about 30 minutes.
6. **PAY:** the same body as AUTH with `"Action":"Pay"`, except when AUTH returned the price or balance. Then remove `TotalAmount` and every product `Price`. Include `PayerDetails` if the biller requires them.
7. **Handle the result:**
   - `Paid`: go to fulfilment (Workflow D).
   - `Failed`: refund the customer and show `Narration`.
   - `BeingProcessed`, `BeingPaid`, `Pending`, `Flagged` or anything unknown: set `state=pending` and schedule a STATUS job 120 s later.
   - Timeout, connection error or HTTP 5xx: the outcome is **unknown**, so treat it as pending. **Never** send PAY again.
   - HTTP 400 on PAY: a validation failure, so refund and investigate.

## 5. Workflow C: STATUS polling

```
wait 120 s → STATUS → final? stop
                    → Flagged? wait 600 s, repeat
                    → otherwise wait 180 s, repeat
after N checks (e.g. 10) → state=needs_attention, alert ops, message the customer, pause checks
```

- The STATUS body is just `{"Action":"Status","Reference":"<original reference>"}`.
- Treat transport errors during polling like a non-final result: keep the cadence, don't speed up.
- Implement it as a self-rescheduling queued job with a delay. Examples:
  - Laravel `dispatch()->delay()`
  - Celery `apply_async(countdown=…)`
  - BullMQ `delay`
  - Hangfire `Schedule`

  `templates/php-laravel/PollBillPayStatus.php` is a complete example.
- The Test Biller `PP` prefix returns `BeingPaid` and only becomes `Paid` after 24 hours. That is by design, for testing the cadence, so escalation must not break it.

## 6. Workflow D: fulfilment

- `PaymentData.ReceiptHtml[]`:
  - Show or email **each entry separately**, with a download or print option per entry.
  - Entries can contain images with absolute URLs.
  - Sanitise them if you render them inside your own page.
- `PaymentData.ReceiptSmses[]`: send **each SMS separately** to the customer's mobile.
- `PaymentData.DisplayData`: key/value pairs for the confirmation page or email.
- `Products[].Vouchers[]`: show `VoucherCode` prominently, plus `SerialNumber`, `ExpiryDate` and `ValidDays`.
- Show `PostPurchaseInstructions` from the catalogue.
- Check every array for null. The Test Biller always returns receipts; live billers may not.
- Set `receipts_delivered_at` so a retried job never double-sends SMSes.

## 7. Workflow E: refunds and reversals

- A `Failed` payment was not provisioned. Refund the customer through your own payment method.
- A BillPay **reversal** (`POST /api/payment/reverse`) is only for billers that support it, and very few do. Send `{"OriginalReference":"<payment ref>","Reference":"<new unique ref>"}`.

  | `ErrorCode` | Meaning |
  |---|---|
  | 0 | OK |
  | 1 | Not found |
  | 2 | Duplicate reversal reference |
  | 3 | Biller failed to reverse |
  | 4 | Biller does not support reversals |
  | 5 | Already refunded |
  | 99 | General error |

- Plan for manual refunds. A reversal is an exception, not your refund process.

## 8. Workflow F: auto-invoice

For billers with `VendorMustInvoicePayments = true`:

1. BillPay creates a CloudESD fiscal invoice asynchronously after `Paid`.
2. Schedule a STATUS call a few minutes after payment to collect `VendorInvoiceReference`, `VendorFiscalSignature` and `VendorFiscalMetadata`. Paynow gives no exact delay, so retry a few times.
3. Print `VendorFiscalSignature` as a QR code and include `VendorFiscalMetadata` on the customer invoice.

## 9. Workflow G: wallet monitoring

- `GET /api/wallets` returns one row per currency.
  - Wallets can have an overdraft: `MinimumBalance` can be negative.
  - `Status` is `Open`, `Suspended` or `Closed`. Only `Open` wallets can transact.
- Show the balances in your admin backend. This is UAT test 1.
- After each payment, record `WalletBalanceAfterDebit`. When it drops below your threshold, alert staff once, not on every transaction.
- BillPay can also email low-balance alerts. Ask the operations team to set a threshold per wallet.

## 10. Workflow H: reconciliation

- `GET /api/payment/list?From=01-Sep-2026%2000:00:00&To=30-Sep-2026%2023:59:59&Page=1&PerPage=200` covers the last 90 days.
- Listings only include a subset of fields. Use STATUS per reference for full detail.
- Nightly job:
  1. Compare BillPay listings with your transactions.
  2. Flag any local transaction in `pending` or `needs_attention` older than a day.
  3. Flag any BillPay payment you have no row for.

## 11. Test plan with the Test Biller

| Scenario | How | Expected handling |
|---|---|---|
| Happy path | Any member number, product `AI`, `AM`, `AA`, `RV` or `FP` | Receipts and SMS delivered individually |
| Auth timeout | Prefix `AT` | Your 60 s timeout fires. AUTH has failed (no money taken), so show "try again" |
| Auth failure | `AF` | `Narration` shown, no charge |
| Payment timeout | `PT` | Unknown outcome: STATUS after 120 s, then every 180 s |
| Payment failure | `PF` | Refund |
| Pending | `PP` | `BeingPaid`: 120/180 s polling. Escalates after the cap. This is what UAT uses |
| Flagged | `PFF` | 600 s polling |
| Forex | Add `USD`, e.g. `PF-USD-1234` | `RequiresForexPayment` true, USD wallet |
| Part payment | Product `AI` | Balance shown; confirm how to submit part amounts with Paynow |
| Full payment | Product `AM` | Amount from AUTH; `Price`/`TotalAmount` blank in PAY |
| Vouchers | Product `RV` | Vouchers displayed |

ZETDC test meters (test environment only):

| Meter / amount | Scenario |
|---|---|
| `37132567431` | Single debt |
| `37125980740` | Double debt |
| `37132229735` | Double token |
| Any meter with amount `177.77` | Token resend |

## 12. Things Paynow does not specify

Say so, and don't invent answers, for:

- **A separate sandbox base URL.** Test behaviour comes from credentials and the Test Biller.
- **When the `Retry` action is safe to use.** Prefer STATUS.
- **How to submit a part payment** for `AuthAmountMandated=false`.
- **The accepted `Status` filter values** for `/api/payment/list`, and the listing `Payment` schema.
- **The exact bearer-token header format** for the config webhook.
- **Numeric rate limits.** Only the polling intervals and the ListBillers rules are specified.
