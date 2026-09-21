# Troubleshooting BillPay integrations

Symptom → cause → fix. Most BillPay problems are one of the entries below. Field details are in `api-reference.md`, flows in `vendor-workflows.md`, and biller quirks in `biller-notes.md`.

## Contents
- Failed UAT
- Authentication and HTTP errors
- Payments and statuses
- Receipts, vouchers and tokens
- Catalogue and config webhook
- Biller payment webhooks
- Biller API (members and CSV)

---

## Failed UAT

UAT is a joint screen-share with Paynow, covering seven tests. See `go-live-checklist.md`.

| Symptom | Likely cause | Fix |
|---|---|---|
| Failed the polling test (test 3) | Only `BeingProcessed` is treated as pending. The Test Biller `PP` prefix returns `BeingPaid`, so the loop exits or errors | Treat every status except `Paid`, `Failed` and `Reversed` as pending. First STATUS 120 s after the pending response, then every 180 s (600 s while `Flagged`) |
| Failed the polling test, even though `BeingPaid` is handled | Polling faster than 120/180 s, or polling inside the web request until it times out | Use a queued, delayed job. Never lower the intervals |
| Failed the receipts tests (tests 4–5) | `ReceiptSmses` joined into one SMS, or `ReceiptHtml` entries merged into one page | Send each SMS on its own. Give each receipt its own page, with its own print or download option |
| Failed the member details test (test 2) | Member name not shown after AUTH | Show `AuthData.MemberName`, plus `MemberAddress` if present, before the customer confirms |
| Failed the wallet test (test 1) | No wallet screen in the back office | Call `GET /api/wallets` and show every currency's balance and status |
| Failed the webhook or ListBillers tests (tests 6–7) | The config webhook calls the unfiltered `ListBillers`, answers slowly, or the app calls `ListBillers` on page loads | Reply `200` immediately, then call `ListBillers?billerCodes=…` for only the named codes. Serve the catalogue from your own database |

## Authentication and HTTP errors

| Symptom | Likely cause | Fix |
|---|---|---|
| `401 Unauthorized` on every call | Wrong credentials, or the user doesn't have the **API** role (vendor) or the **Biller Admin/User** role (biller) | Check the credentials with `scripts/billpay_cli.py wallets`. Ask Paynow support to confirm the user's role |
| `401` only from some tools | The header is built incorrectly, e.g. by a `base64` that wraps long lines | Use the HTTP client's Basic-auth option (`curl -u`, `requests` `auth=`, `withBasicAuth`) |
| `400` with a `ModelState` body | Validation failed: a required field is missing, there's no product, or a value is badly formatted | Read the `ModelState` messages, fix the request, and don't retry it unchanged |
| `400 "There must be at least one product"` on a STATUS request | The client serialises an empty `Products` array (or nulls) into the STATUS body | Send only `{"Action":"Status","Reference":"…"}` |
| HTTP 200 but the payment didn't happen | 200 only means the request was processed | Always read `Status` |
| Timeouts on AUTH | The biller is slow, or it's the Test Biller `AT` prefix (a 120 s delay) | Use a 60 s timeout. A timed-out AUTH has charged nothing, so tell the customer to try again |

## Payments and statuses

| Symptom | Likely cause | Fix |
|---|---|---|
| Customer charged twice, or two payments for one purchase | PAY re-sent after a timeout, sometimes with a new `Reference` | Send PAY once. On a timeout, connection error or 5xx, the outcome is unknown: send a STATUS with the **same** reference |
| STATUS says the payment isn't found | STATUS used a different reference from PAY, or the reference was never stored | Generate one UUID per transaction, store it before AUTH, and use it for AUTH, PAY and STATUS |
| Customer charged but AUTH failed | Customer charged before AUTH | Order the flow: AUTH → confirm → charge → PAY |
| Double-click charges twice | No guard on the confirm step | Atomically move the transaction from `authorized` to `confirming` before charging |
| PAY rejected, or wrong amount, for council or medical-aid bills | `AuthAmountMandated` ignored: `Price`/`TotalAmount` sent in PAY when AUTH returned the balance | Charge the amount AUTH returned and leave `TotalAmount` and every `Price` blank in PAY |
| Rejections on USD products | `RequiresForexPayment` not set on AUTH and PAY | Set it for forex products. When the catalogue says `null`, copy AUTH's answer into PAY |
| Status stays `Flagged` | BillPay support is looking at an unexpected biller result | Poll every 600 s. Don't refund until the payment reaches a final status |
| `PP` payment still pending after hours | By design: the Test Biller `PP` turns `Paid` after 24 hours | Cap your polling and escalate. That's exactly the behaviour UAT checks |
| Customers see confusing technical errors | `TechnicalNarration` shown to them | Show `Narration`. Log `TechnicalNarration` |
| Reversal returns `ErrorCode 4` | The biller doesn't support reversals (most don't) | Refund through your own payment method |
| Reversal returns `ErrorCode 2` | The reversal reused a reference | Use a new unique reversal `Reference` |

## Receipts, vouchers and tokens

| Symptom | Likely cause | Fix |
|---|---|---|
| Crash after a successful live payment | Code assumes `PaymentData.ReceiptHtml` or `ReceiptSmses` exist. The Test Biller always returns them; live billers may not | Check for null before looping |
| ZETDC customer only got one of several tokens | Only the first receipt or SMS was delivered | Loop over every entry |
| Voucher codes missing | Looked in `PaymentData` instead of `Products[].Vouchers[]` | Read `VoucherCode`, `SerialNumber` and `ExpiryDate` from each product's `Vouchers` |
| EVD AUTH fails with JSON in `TechnicalNarration` | Stock errors: `OutOfStock` (a list of codes) or `InsufficientStock` (a map of code to available quantity) | Parse the JSON and show a friendly "out of stock" message |
| Fiscal invoice fields empty | Auto-invoicing is asynchronous | Send a STATUS a few minutes later to collect `VendorInvoiceReference`, `VendorFiscalSignature` and `VendorFiscalMetadata` |

## Catalogue and config webhook

| Symptom | Likely cause | Fix |
|---|---|---|
| App slow, `ListBillers` huge | Unfiltered `ListBillers` called often | Seed once, then refresh by `billerCodes` after each webhook |
| Prices out of date, PAY rejected | Config webhook not implemented, or it failed silently | Implement it. Log and alert on resync failures |
| Webhook always gets `401` | A bearer-token check was enforced before BillPay configured the token (the official wording is ambiguous) | Only enforce the token once the BillPay team confirms it's configured, and confirm the header format with them |
| Resync fetches the whole catalogue | The webhook body was empty or unparsed, and the code fell back to an unfiltered call | Ignore empty or invalid bodies. Never fall back to an unfiltered call |
| Unknown biller or product codes | Paynow doesn't publish codes | Take them from your synced catalogue tables |
| Member number rejected locally but valid at BillPay | `MemberNumberFieldRegex` uses .NET regex syntax your language can't compile | If the pattern doesn't compile, skip the local check and let AUTH validate |

## Biller payment webhooks

| Symptom | Likely cause | Fix |
|---|---|---|
| `X-Signature` never matches | The HMAC was computed over re-serialised JSON instead of the raw body bytes, or encoded as hex instead of Base64 | `base64(HMAC-SHA256(raw body, secret key))`, computed before any parsing |
| Legacy `Hash` doesn't match | Wrong field order, `ProductPrice` not formatted to 2 dp, or a missing `ProductDepartment` not replaced with `""` | Order: PaymentId, BillPayReference, BankReference, PaidDate, MemberNumber, MemberName, ProductCode, ProductPrice (2 dp), ProductDepartment, then append the secret key and hash with SHA-256 (lowercase hex). Check with `scripts/verify_webhook.py --self-test` |
| Same payment recorded twice | No idempotency | Upsert keyed on `PaymentId` |
| Missing department crashes the parser | `ProductDepartment` is optional | Treat it as optional |

## Biller API (members and CSV)

| Symptom | Likely cause | Fix |
|---|---|---|
| Member fields blank after an update | `member/update` blanks every optional field you omit | Always send the full record |
| Can't recreate a deleted member | Deleted member numbers can't be reused | Use `member/undelete` |
| Bulk upload data in the wrong columns | Columns out of order | Use Member Number, Full Name, Email, Mobile, Postal Address, in that order, then any extra columns |
| Bulk upload rejected | The multipart field name isn't documented; `file` is an assumption | Confirm the field name with Paynow |
| `downloadpayments` returns an error | `From`/`To` missing or badly formatted | Both are required, as `dd-MMM-yyyy HH:mm:ss` (URL-encode the space) |
