# BillPay API reference — objects, fields, enums

> Extracted from `full-reference.md` (verified against developers.paynow.co.zw, 21 Sep 2026). Use this for exact field names, types and enum values.

## Contents
- Errors and HTTP codes (§3)
- Dual currency (§4)
- List Billers, Biller/Product objects (§5)
- Member lookup (§6)
- Payment request (§7) and response objects (§8)
- Status (§9), list payments (§10), wallets (§11), reversal (§12)
- Webhooks, auto-invoice, target stats, feed (§13)
- Appendix A endpoint index, Appendix B enums, Appendix C formats

## 3. HTTP Response Codes & Error Handling

### 3.1 `200 OK` — does *not* mean success

A `200` means the request was processed, **not** that the payment succeeded. You must inspect the `Status` field of the response body.

Two narration fields accompany responses:

| Field | Audience | Usage |
|---|---|---|
| `Narration` | **Customer-facing** | Pass back to the customer — often contains useful actionable information |
| `TechnicalNarration` | **Internal only** | For troubleshooting/logging. Do not show to customers |

### 3.2 `400 Bad Request` — validation failure

The body contains a `ModelState` object mapping field paths to validation messages:

```json
{
  "Message": "The request is invalid.",
  "ModelState": {
    "request.MemberNumber": [
      "The member number is required"
    ],
    "request.Products": [
      "Product validation failed. There must be at least one product"
    ]
  }
}
```

### 3.3 Error-handling decision table

| Condition | Action |
|---|---|
| HTTP 400 | Fix the request; do not retry as-is. Surface field errors to the user |
| HTTP 200 + `Status: Authorized` | Proceed to PAY |
| HTTP 200 + `Status: Paid` | Success — provision/receipt the customer |
| HTTP 200 + `Status: Failed` | Show `Narration`; refund the customer if already debited |
| HTTP 200 + `Status: BeingProcessed` | Poll with `Status` action (see §3.4) |
| HTTP 200 + `Status: BeingPaid` or `Pending` | Treat exactly like `BeingProcessed` — see note below |
| HTTP 200 + `Status: Flagged` | Poll at 600s intervals; it will resolve to success or failure |
| Network timeout, connection error, or HTTP 5xx on PAY | Outcome unknown — do **not** re-send PAY. Wait 120s, then send a `Status` inquiry |
| Any other/unknown `Status` value | Treat as non-final: keep polling, then escalate to operations staff |

> **Note — undocumented pending statuses:** The official Statuses list (§8.6) has only `Authorized`, `BeingProcessed`, `Paid`, `Reversed`, `Failed` and `Flagged`. However, the official Biller Integration Notes say the Test Biller `PP` prefix returns **`BeingPaid`** (§14.1) and that ZETDC returns a **`Pending`** response under load (§14.2). These are probably aliases or typos for `BeingProcessed`, but because UAT test 3 uses the `PP` prefix, treat **every non-final status as pending** and confirm the exact value with Paynow support before UAT.

### 3.4 Polling intervals (rate limits)

Paynow does not publish numeric request-per-second rate limits. It does specify these timing rules; the last column shows which are checked at go-live:

| Scenario | First inquiry | Subsequent inquiries | Source / UAT |
|---|---|---|---|
| `BeingProcessed` (and `BeingPaid`/`Pending`) after PAY | ≥ **120 seconds** after the timeout/pending response | ≥ **180 seconds** apart | Specified; **tested in UAT** (test 3) |
| `Flagged` | — | ≥ **600 seconds** apart | Specified (Statuses table); guidance, not a UAT test |
| `ListBillers` (full, unfiltered) | Only on initial load | Only when a webhook signals a change — then filter by `billerCodes` | Specified; **tested in UAT** (tests 6–7) |

For reference, BillPay's own retry behaviour for the **vendor biller-config webhook** (not a client limit): if you do not return `200 OK`, it resends every **30 seconds, up to 3 attempts**. No retry policy is documented for biller payment webhooks.

> **Warning:** Frequent unfiltered `ListBillers` calls return a large payload and will degrade your application's performance. This is explicitly checked during go-live testing.

---

## 4. Dual Currency & Wallets

BillPay supports **ZWG** and **USD**. Products denominated in any other currency are converted to USD at an interbank rate sourced from visa.com.

Rules:

- Maintain a **prefunded ZWG wallet** for products that do not require forex.
- Maintain a **prefunded USD wallet** for products that require forex.
- Products requiring forex carry `RequiresForexPayment = true`.
- You must **explicitly set `RequiresForexPayment`** on both the AUTH and PAY requests as an acknowledgement.

A product's `RequiresForex` value in the biller config tells you what to expect:

| `RequiresForex` | Meaning |
|---|---|
| `true` | Forex is required |
| `false` | No forex required |
| `null` | Sometimes required — the AUTH response determines it |

---
## 5. List Billers

Retrieve billers and their products. Use this to build and maintain your local biller/product catalogue.

### Request

```http
GET /api/payment/ListBillers
```

Filtered (preferred after a webhook notification):

```http
GET /api/payment/ListBillers?billerCodes=ABC,DEF,GHI
```

> **Warning:** The unfiltered payload is large. Do not poll it. Store configurations locally and refresh only the codes named in a webhook.

### `Biller` object

| Field | Type | Description |
|---|---|---|
| `Code` | String | Unique biller identifier |
| `Name` | String | Biller name |
| `Description` | String | Biller description |
| `IconUrl` | String | Icon image URL on BillPay |
| `LogoUrl` | String | Logo image URL on BillPay |
| `ReferencePrefix` | String | Transaction reference prefix used in BillPay |
| `Enabled` | Boolean | If `false`, payments are not allowed |
| `MemberNumberFieldDesc` | String | Help text for the member number input |
| `MemberNumberFieldLabel` | String | Input label (e.g. "Mobile Number", "Account Number") |
| `MemberNumberFieldRegex` | String | Regex the member number must match (if any) |
| `AllowMultipleProductsPerPayment` | Boolean | Whether several products can go in one transaction |
| `MetaTitle` | String | Suitable for an HTML meta title |
| `MetaDescription` | String | Suitable for an HTML meta description |
| `Products` | List\<Product\> | Products belonging to the biller |
| `VendorMustInvoicePayments` | Boolean | Whether you are legally required to issue a VAT invoice per payment (BillPay can automate via CloudESD) |

### `Product` object

| Field | Type | Description |
|---|---|---|
| `Code` | String | Unique product identifier |
| `Name` | String | Product name |
| `Description` | String | Product description |
| `Price` | Decimal (nullable) | `null` when AUTH returns the price or the product is free-priced |
| `Department` | String (nullable) | Department the product belongs to |
| `RequiresForex` | Boolean (nullable) | `true` / `false` / `null` (AUTH decides) |
| `ReturnsVouchers` | Boolean | Whether the product returns vouchers |
| `IconUrl` | String | Product icon URL |
| `LogoUrl` | String | Product logo URL |
| `PrePurchaseInstructions` | String | Show to the member before payment (optional) |
| `PostPurchaseInstructions` | String | Show to the member after payment (optional) |
| `AmountFieldLabel` | String | Label for the amount input |
| `AmountFieldDesc` | String | Help text for the amount field |
| `MinAmount` | Decimal (nullable) | Minimum allowed (`null` = none) |
| `MaxAmount` | Decimal (nullable) | Maximum allowed (`null` = none) |
| `NewProduct` | Boolean | Whether the product is newly added |
| `InvoiceTitle` | String | Title to use on the invoice |
| `Enabled` | Boolean | Whether the product is active |
| `ReminderDays` | Integer (nullable) | Remind the customer to repurchase after X days |
| `AuthAmountMandated` | Boolean (nullable) | See table below |
| `AllowSpecifyQuantity` | Boolean | Whether quantity may be specified |
| `QuantityFieldLabel` | String | Label for the quantity field |
| `QuantityFieldDesc` | String | Help text for the quantity field |
| `MetadataFields` | Array\<ProductMeta\> | Additional metadata fields |

### `AuthAmountMandated`

| Value | Meaning |
|---|---|
| `null` | You specify the price in AUTH; it must stay the same in PAY |
| `false` | AUTH returns the price. **Part payment permitted** |
| `true` | AUTH returns the price. **Full payment required** |

### `ProductMeta`

Some products need extra input (e.g. Liquid Home Pay As You Go).

| Field | Type | Description |
|---|---|---|
| `Name` | String | Metadata field name |
| `Required` | Boolean | Whether it is mandatory |
| `Description` | String | Field description |

---

## 6. Get Member Information

Look up a member's details before payment.

### Request

```http
GET  /api/payment/member?billerCode={code}&memberNumber={number}
POST /api/payment/member
```

| Field | Type | Required |
|---|---|---|
| `BillerCode` | String | Required |
| `MemberNumber` | String | Required |

### Response

| Field | Type | Description |
|---|---|---|
| `AuthData` | AuthResponseData | See §8.3 |
| `ResultCode` | Integer | See below |
| `Narration` | String | Customer-friendly error description |
| `TechnicalNarration` | String | Technical error description |

| `ResultCode` | Name | Meaning |
|---|---|---|
| `0` | FailedPermanent | Member does not exist / number incorrect |
| `1` | Success | Member found |
| `2` | Offline | Biller offline — safe to retry later |

---

## 7. Make Payment (AUTH → PAY)

Payment is a **two-stage** flow against a single endpoint.

```
┌─────────┐   AUTH    ┌──────────┐   PAY    ┌──────┐
│ Validate│ ────────► │Authorized│ ───────► │ Paid │
│ + quote │           │          │          │      │
└─────────┘           └──────────┘          └──────┘
```

- **AUTH** — validates your request, checks wallet funding, confirms biller availability, and returns the member's account details for customer confirmation.
- **PAY** — debits your wallet and provisions the product/service.

> **Warning:** AUTH and PAY requests should be **identical**. Always run AUTH *before* debiting your customer's payment method, so you never have to refund them because of an invalid request or wrong member number.

> **Exception:** If AUTH returned the customer's balance owing, leave `TotalAmount` and each product's `Price` **blank** in the PAY request.

### Endpoint

```http
POST /api/payment/process
```

### Payment request fields

| Field | Type | Description | Required |
|---|---|---|---|
| `Action` | String | `Auth` \| `Pay` \| `Retry` \| `Status` | Required |
| `BillerCode` | String | Biller identifier | Required |
| `Reference` | String | **Your** unique reference per transaction — identical for AUTH and PAY | Required |
| `MemberNumber` | String | Account/service to be credited | Required |
| `Products` | Array\<PaymentProduct\> | Products being paid for | Required |
| `TotalAmount` | Decimal | Sum of product totals. May be blank if AUTH returns the balance | Required |
| `PayerDetails` | PayerDetails | Required at PAY by some billers | Optional |

### `PaymentProduct`

| Field | Type | Description | Required |
|---|---|---|---|
| `Code` | String | Product identifier | Required |
| `Quantity` | Integer | Defaults to `1` | Optional |
| `Department` | String | Mandatory if the product has a department | Sometimes |
| `Price` | Decimal | Blank if AUTH returns the amount; required at PAY | Sometimes |
| `RequiresForexPayment` | Boolean | Acknowledgement of the forex requirement | Sometimes |
| `Metadata` | Array\<Key/Value\> | Product-specific metadata | Sometimes |

> **Note — `Metadata` shape is inconsistent in the official docs:** the field table says "Array of Key/Value Pairs", the Pink Lotto example sends an **array of objects** (`[{ "BetInfo": "…" }]`, §14.4), and the Liquid Home example sends a **plain object** (`{ "Service Login": "…" }`, §14.7). Follow each biller's example and confirm the accepted shape with Paynow support; your client model must be able to send both.

### `PayerDetails`

Only needed by some billers, and only at PAY (may be blank at AUTH).

| Field | Type | Description |
|---|---|---|
| `BankAccountName` | String | Source bank account name |
| `BankAccountNumber` | String | Source bank account number |
| `BankName` | String | Source bank name |
| `BankBranch` | String | Source bank branch |
| `BankReference` | String | Source bank reference, for reconciliation |
| `ContactNumber` | String | Used by some billers to contact the payer |
| `NationalId` | String | Used by some billers to validate payer identity |

### `Action` values

| Action | Description |
|---|---|
| `Auth` | Authorize a payment before it can be initiated |
| `Pay` | Initiate an authorized payment |
| `Retry` | Retry a payment after a retryable error or network interruption |
| `Status` | Query the status of a payment |

### Sample AUTH request

```json
{
  "BillerCode": "COB",
  "MemberNumber": "ABC123",
  "Reference": "08be48c6-2624-4a06-95eb-be79ff5d6ef5",
  "TotalAmount": "100",
  "Products": [
    {
      "Code": "USD",
      "Quantity": "1",
      "Price": 100,
      "RequiresForexPayment": true
    }
  ],
  "Action": "AUTH"
}
```

### Sample PAY request

```json
{
  "BillerCode": "COB",
  "MemberNumber": "ABC123",
  "Reference": "08be48c6-2624-4a06-95eb-be79ff5d6ef5",
  "TotalAmount": "100",
  "Products": [
    {
      "Code": "USD",
      "Quantity": "1",
      "Price": 100,
      "RequiresForexPayment": true
    }
  ],
  "Action": "PAY"
}
```

---

## 8. Payment Response

The response echoes every field from the request and adds the following.

### 8.1 Top-level response fields

| Field | Type | Description |
|---|---|---|
| `Status` | String | See §8.6 |
| `MemberName` | String | Member name |
| `Products` | List\<ProductResponse\> | Products in the response |
| `Narration` | String | Populated on a `Failed` status |
| `TechnicalNarration` | String | Technical error description |
| `BillPayReference` | String | Unique reference generated by BillPay |
| `AuthData` | AuthResponseData | Auth response data |
| `PaymentData` | PaymentResponseData | Post-completion payment data |
| `BillerPaymentReference` | String | Biller's own payment reference |
| `VendorServiceFeeCurrency` | String | 3-char ISO currency code for the service fee |
| `VendorServiceFee` | Decimal (nullable) | Service fee charged to you |
| `Currency` | String | Currency of the wallet debited |
| `WalletDebitReference` | String | Your wallet debit reference |
| `WalletBalanceAfterDebit` | Decimal (nullable) | Wallet balance after the debit |
| `WalletDebitReversed` | DateTime (nullable) | Reversal timestamp, or `null` |
| `WalletBalanceAfterReversal` | Decimal (nullable) | Wallet balance after reversal |
| `VendorInvoiceReference` | String | Invoice reference (auto-invoice enabled) |
| `VendorFiscalSignature` | String | Fiscal signature (auto-invoice enabled) |
| `VendorFiscalMetadata` | String | Fiscal metadata (auto-invoice enabled) |
| `VendorReversalReference` | String | Reversal reference if reversed/refunded |

### 8.2 `ProductResponse`

| Field | Type | Description |
|---|---|---|
| `Name` | String | Product name |
| `Code` | String | Product code |
| `Quantity` | Integer | Quantity |
| `Department` | String | Department if specified |
| `Price` | Decimal | Price (observe this if AUTH returns the price) |
| `AccountBalance` | Decimal (nullable) | Product account balance if available |
| `RequiresForexPayment` | Boolean | Whether forex is required |
| `Vouchers` | Array\<ProductVoucherData\> | Vouchers, if any |
| `Metadata` | Array\<Key/Value\> | Metadata submitted |
| `VendorCommission` | Decimal (nullable) | Your commission, in the payment currency |

### 8.3 `AuthResponseData`

| Field | Type | Description |
|---|---|---|
| `MemberName` | String | Member name |
| `MemberAddress` | String | Member address |
| `AccountDetails` | Dictionary\<string,string\> | Key/value account details |
| `AccountBalances` | Dictionary\<string,string\> | Key/value **non-monetary** balances |
| `AccountBalance` | Decimal (nullable) | Monetary account balance |

```json
"AccountDetails": {
  "National Id": "AB-CDEF123",
  "Place of Birth": "Harare"
}
```

```json
"AccountBalances": {
  "Data": "1.75GB",
  "Days": "12"
}
```

### 8.4 `PaymentResponseData`

| Field | Type | Description |
|---|---|---|
| `ReceiptHtml` | String[] | Formatted receipt HTML (may embed fully-qualified image URLs). **May contain multiple entries** (e.g. ZETDC prepaid) |
| `DisplayData` | Dictionary\<string,string\> | Fields to show the customer after payment (email, summary page) |
| `ReceiptSmses` | String[] | SMS texts to send the customer. **May contain multiple entries** |

> **Critical:** If `ReceiptHtml` or `ReceiptSmses` are populated, you **must** deliver **each entry individually** — never merged into one receipt or one SMS. This is verified during go-live UAT.

### 8.5 `ProductVoucherData`

| Field | Type | Description |
|---|---|---|
| `SerialNumber` | String | Voucher serial number |
| `Pin` | String | Voucher PIN |
| `ValidDays` | Integer (nullable) | Days the voucher stays valid |
| `Batch` | String | Voucher batch |
| `VoucherCode` | String | Token/PIN/code the user enters to activate the service |
| `ExpiryDate` | DateTime (nullable) | Voucher expiry |

### 8.6 Statuses

| Status | Meaning | Your action |
|---|---|---|
| `Authorized` | Authorized successfully; ready to pay | Send PAY |
| `BeingProcessed` | Still processing | Poll `Status` at 180s intervals (first at 120s) |
| `Paid` | Payment succeeded | Deliver receipts/vouchers |
| `Reversed` | Payment reversed/refunded | Reconcile |
| `Failed` | Payment failed | Show `Narration`; refund the customer |
| `Flagged` | Unexpected biller result, flagged for BillPay support | Poll `Status` at 600s intervals |

> **Note:** `BeingPaid` (Test Biller `PP`) and `Pending` (ZETDC) also appear in the official integration notes but not in this list — handle them as `BeingProcessed`. See the note under §3.3.

### Sample AUTH response

```json
{
  "Action": "Auth",
  "BillerCode": "COB",
  "Reference": "08be48c6-2624-4a06-95eb-be79ff5d6ef5",
  "MemberNumber": "ABC123",
  "Products": [
    {
      "Quantity": 1,
      "Code": "USD",
      "Name": "USD Bill Payment",
      "Price": 100,
      "RequiresForexPayment": true
    }
  ],
  "TotalAmount": 100,
  "Status": "Authorized",
  "MemberName": "Bulawayo Guy",
  "BillPayReference": "COB-211129114124239",
  "AuthData": {
    "MemberName": "Bulawayo Guy",
    "AccountDetails": {
      "Favourite Burger": "Macdonalds"
    }
  }
}
```

### Sample PAY response

```json
{
  "Action": "Pay",
  "BillerCode": "COB",
  "Reference": "08be48c6-2624-4a06-95eb-be79ff5d6ef5",
  "MemberNumber": "ABC123",
  "Products": [
    {
      "Quantity": 1,
      "Code": "USD",
      "Name": "USD Bill Payment",
      "Price": 100,
      "RequiresForexPayment": true
    }
  ],
  "TotalAmount": 100,
  "Status": "Paid",
  "MemberName": "Bulawayo Guy",
  "BillerPaymentReference": "0740000022",
  "BillPayReference": "COB-211129114124239",
  "AuthData": {
    "MemberName": "Bulawayo Guy",
    "AccountDetails": {
      "Favourite Burger": "Macdonalds"
    }
  },
  "Currency": "USD"
}
```

---

## 9. Get Payment Status

Use when a PAY response was lost (timeout, disconnect) or to re-check an earlier payment. Send the **original** reference.

```http
POST /api/payment/process
```

```json
{
  "Reference": "08be48c6-2624-4a06-95eb-be79ff5d6ef5",
  "Action": "STATUS"
}
```

### Sample status response

```json
{
  "Action": "Status",
  "BillerCode": "COB",
  "Reference": "08be48c6-2624-4a06-95eb-be79ff5d6ef5",
  "MemberNumber": "ABC123",
  "Products": [
    {
      "Quantity": 1,
      "Code": "USD",
      "Name": "USD Bill Payment",
      "Price": 100,
      "RequiresForexPayment": true
    }
  ],
  "TotalAmount": 100,
  "Status": "Paid",
  "MemberName": "Bulawayo Guy",
  "BillerPaymentReference": "0740000022",
  "BillPayReference": "COB-211129114124239",
  "AuthData": {
    "MemberName": "Bulawayo Guy",
    "AccountDetails": {
      "Favourite Burger": "Macdonalds"
    }
  },
  "Currency": "USD"
}
```

> **Warning:** While the status is `BeingProcessed`, keep checking until a final status is reached:
> - **First inquiry:** 120 seconds after the timeout or pending response
> - **Subsequent inquiries:** 180 seconds after the previous inquiry

---

## 10. List Payments

Retrieve your payments from the **last 90 days**.

### Request

```http
GET /api/payment/list
```

| Field | Type | Description | Required |
|---|---|---|---|
| `From` | DateTime `dd-MMM-yyyy HH:mm:ss` | Start date | Optional |
| `To` | DateTime `dd-MMM-yyyy HH:mm:ss` | End date | Optional |
| `BillerCode` | String | Filter by biller | Optional |
| `Status` | String (default `Unspecified`) | Payment status filter — accepted values are *not specified by Paynow* (`Unspecified` is not in the §8.6 list) | Optional |
| `VendorReference` | String | Your unique reference | Optional |
| `Page` | Integer (default `1`) | Page number | Optional |
| `PerPage` | Integer (default `200`) | Results per page, 1–200 | Optional |

Example:

```http
GET /api/payment/list?From=01-Sep-2026%2000:00:00&To=20-Sep-2026%2023:59:59&BillerCode=COB&Page=1&PerPage=200
```

### Response

| Field | Type | Description |
|---|---|---|
| `Page` | Integer | Current page number |
| `TotalPages` | Integer | Total pages available |
| `PerPage` | Integer | Results per page |
| `TotalListings` | Integer | Total matching results |
| `Listings` | List\<Payment\> | Matching payments (the `Payment` listing schema is *not specified by Paynow*) |

> **Note:** Listings contain only a **subset** of the payment data. For the full payment record (receipts, vouchers, fiscal data), issue a `Status` inquiry for that reference.

---

## 11. List Wallets

### Request

```http
GET /api/wallets
```

### Response

```json
[
  {
    "Currency": "ZWG",
    "Balance": 18874.19,
    "LowBalance": 2000.0,
    "MinimumBalance": 0.0,
    "Status": "Open"
  },
  {
    "Currency": "USD",
    "Balance": -431.0,
    "LowBalance": 0.0,
    "MinimumBalance": -10000.0,
    "Status": "Open"
  }
]
```

> **Note:** In the example above the vendor has a **$10,000 overdraft facility** on the USD wallet, which is why `MinimumBalance` is `-10000`.

### Wallet status

| Code | Status | Description |
|---|---|---|
| `1` | `Open` | Normal — the vendor can transact |
| `2` | `Suspended` | Temporarily suspended by BillPay administration |
| `3` | `Closed` | Emptied and permanently closed |

> **Tip:** BillPay can email a "Low Wallet Balance" notification to your nominated *Accounts Department Email*. Ask the BillPay operations team to set the **Low Balance Threshold** per wallet.

---

## 12. Reverse / Refund Payment

> **Warning:** Only a **very limited set of billers** accept reversals or refunds. Most do not. Design your refund process assuming reversal will usually be unavailable.

### Request

```http
POST /api/payment/reverse
```

| Field | Type | Description |
|---|---|---|
| `OriginalReference` | String | Your original payment reference (used in AUTH and PAY) |
| `Reference` | String | Your **unique reversal** reference |

```json
{
  "OriginalReference": "PMT1234567890",
  "Reference": "REV1234567890"
}
```

### Response

| Field | Type | Description |
|---|---|---|
| `OriginalReference` | String | Original payment reference |
| `Reference` | String | Your reversal reference |
| `ErrorCode` | Integer | `0` = success; non-zero = failure |
| `Narration` | String | Populated when `ErrorCode` is non-zero |
| `TechnicalNarration` | String | Technical error description |
| `BillpayReference` | String | BillPay's reversal reference |
| `BillerReference` | String | Biller's reversal reference |

```json
{
  "OriginalReference": "PMT1234567890",
  "Reference": "REV1234567890",
  "ErrorCode": 0,
  "Narration": "",
  "TechnicalNarration": "",
  "BillpayReference": "R-COH-1234567890",
  "BillerReference": "1000-123456789"
}
```

### Reversal error codes

| Code | Description |
|---|---|
| `0` | Reversal successful (no error) |
| `1` | Original payment not found |
| `2` | Duplicate vendor reversal reference |
| `3` | Biller failed to reverse the payment |
| `4` | Biller does not support reversals |
| `5` | Original payment is already refunded |
| `99` | General (non-specific) error |

---

## 13. Webhooks & Additional Vendor Features

### 13.1 Biller configuration webhook (auto-push)

Register a callback URL with Paynow support. BillPay notifies you whenever biller configurations change — fees updated, biller enabled/disabled, products changed, and so on.

BillPay sends a JSON `POST` containing an **array of biller codes**:

```http
POST /your/webhook/endpoint
Content-Type: application/json
Authorization: Bearer <your-token>   ← only if you have agreed a token with the BillPay team
```

```json
["CODE1", "CODE2"]
```

On receipt you should:

1. Respond immediately with `200 OK`.
2. Call `GET /api/payment/ListBillers?billerCodes=CODE1,CODE2` — **filtered**, never unfiltered.
3. Update your local biller/product database.

> **Tip (official wording):** "To protect your webhook against spoofing attacks, authenticate incoming requests using the 'Bearer' scheme with basic authentication. Share your bearer token with the BillPay team for configuration." The wording is ambiguous (Bearer vs Basic), and the header is only sent once BillPay has configured your token. Confirm the exact header format with Paynow before enforcing it — rejecting unauthenticated calls too early will fail UAT test 6.

> **Warning:** If your endpoint does not return `200 OK`, BillPay resends the request every **30 seconds, up to 3 attempts**.

> **Why it matters:** If your pricing drifts out of sync with BillPay, your API requests are likely to be **rejected**.

### 13.2 Auto invoice for biller payments

Some billers legally require the **vendor** to issue an invoice for every member payment (flagged by `VendorMustInvoicePayments` on the biller). BillPay can generate these automatically through the **CloudESD** fiscal signature service.

The process is **asynchronous**. After a successful payment for such a biller, BillPay assigns a `VendorInvoiceReference`, generates the invoice, and exports it to CloudESD to obtain a fiscal signature.

| Field | Purpose |
|---|---|
| `VendorInvoiceReference` | The invoice reference assigned by BillPay |
| `VendorFiscalSignature` | Data to render as a **QR code** on the invoice |
| `VendorFiscalMetadata` | Verification code, device ID, receipt counters and device serial number — **required** on the customer's invoice |

> **Note:** Because it runs asynchronously, these three fields will not be in the PAY response. Issue a **Status inquiry** for the payment to retrieve them.

### 13.3 Target statistics

Billers such as charities may have a monetary or unit target.

```http
GET /api/payment/TargetStats?billercode=<BillerCode>
GET /api/payment/TargetStats?billercode=<BillerCode>&currency=<CurrencyCode>
```

| Field | Type | Description |
|---|---|---|
| `TargetAmount` | Decimal | Total amount needed to hit the target |
| `TargetUnits` | Integer | Quantity needed to hit the target |
| `TargetAchievedSinceDate` | DateTime (nullable) | Date the statistics run from |
| `TargetAchievedAmount` | Decimal | Amount paid so far |
| `TargetAchievedUnits` | Integer | Units purchased so far |
| `PayerCount` | Integer | Number of payers |

> **Note:** If the biller is not configured for target statistics, BillPay returns `200 OK` with an **empty body**. Handle that case.

### 13.4 Payment feed

Show a list of people who recently paid a biller — useful for charities and fundraisers. Requires `AllowFeed` to be enabled on the biller.

```http
GET /api/payment/feed?billercode=<BillerCode>
GET /api/payment/feed?billercode=<BillerCode>&currency=<CurrencyCode>
```

Returns the **last 20 payments**:

| Field | Type | Description |
|---|---|---|
| `Date` | DateTime | Payment date |
| `MemberNumber` | String | Member identifier |
| `Amount` | Decimal | Amount paid |
| `MetaData` | List | Key/value pairs of product metadata |

**Pagination** — to fetch the next 20 (older) records, pass `before` set to the Unix time (**milliseconds** since epoch) of the oldest record in the previous page:

```http
GET /api/payment/feed?billercode=<BillerCode>&before=<unixLongDate>
```

---
## Appendix A — Complete endpoint index

**Base URL:** `https://billpay.paynow.co.zw`

### Vendor API

| # | Method | Path | Purpose | Section |
|---|---|---|---|---|
| 1 | `GET` | `/api/payment/ListBillers` | All billers + products | §5 |
| 2 | `GET` | `/api/payment/ListBillers?billerCodes=A,B,C` | Filtered billers (use after webhook) | §5 |
| 3 | `GET` | `/api/payment/member` | Member lookup | §6 |
| 4 | `POST` | `/api/payment/member` | Member lookup (POST form) | §6 |
| 5 | `POST` | `/api/payment/process` | `Auth` \| `Pay` \| `Retry` \| `Status` | §7–§9 |
| 6 | `POST` | `/api/payment/reverse` | Reverse / refund | §12 |
| 7 | `GET` | `/api/payment/list` | Payment history (90 days) | §10 |
| 8 | `GET` | `/api/wallets` | Wallet balances | §11 |
| 9 | `GET` | `/api/payment/TargetStats?billercode=` | Target statistics | §13.3 |
| 10 | `GET` | `/api/payment/feed?billercode=` | Recent payers feed (last 20) | §13.4 |

### Biller API

| # | Method | Path | Purpose | Section |
|---|---|---|---|---|
| 11 | `POST` | `/api/member/create` | Create member | §17.1 |
| 12 | `POST` | `/api/member/update` | Update member (full overwrite) | §17.2 |
| 13 | `POST` | `/api/member/delete` | Delete member | §17.3 |
| 14 | `POST` | `/api/member/undelete` | Restore deleted member | §17.4 |
| 15 | `GET` | `/api/member/list` | Paginated member list | §17.5 |
| 16 | `GET` | `/api/member/single/<membernumber>` | Single member | §17.6 |
| 17 | `POST` | `/api/member/uploadmembers` | Bulk upsert (CSV, multipart) | §17.7 |
| 18 | `POST` | `/api/member/uploadmembersdelete` | Bulk delete (CSV, multipart) | §17.8 |
| 19 | `GET` | `/api/member/downloadpayments` | Payments CSV | §18 |

### Inbound webhooks (BillPay → you)

| Direction | Payload | Auth mechanism | Section |
|---|---|---|---|
| Vendor — biller config changed | `["CODE1","CODE2"]` | `Bearer` token you supply | §13.1 |
| Biller — payment notification | `{ "Payments": [...], "Hash": "..." }` | `X-Signature` HMAC-SHA256 (legacy: `Hash`) | §19 |

---

## Appendix B — Enumerations quick reference

### Payment `Status`

| Value | Final? | Next action |
|---|---|---|
| `Authorized` | No | Send PAY |
| `BeingProcessed` | No | Poll: first at 120s, then every 180s |
| `BeingPaid` / `Pending` | No | Not in the official Statuses list, but used in the integration notes (Test Biller `PP`, ZETDC). Handle as `BeingProcessed` |
| `Paid` | **Yes** | Deliver receipts, vouchers, SMS |
| `Reversed` | **Yes** | Reconcile |
| `Failed` | **Yes** | Show `Narration`, refund customer |
| `Flagged` | No | Poll every 600s; BillPay support is involved |

### `Action`

| Value | Use |
|---|---|
| `Auth` | Authorise before initiating |
| `Pay` | Initiate an authorised payment |
| `Retry` | Retry after a retryable error / network interruption |
| `Status` | Query an existing payment by reference |

### Member lookup `ResultCode`

| Value | Name | Retryable? |
|---|---|---|
| `0` | FailedPermanent | No — member does not exist / wrong number |
| `1` | Success | — |
| `2` | Offline | Yes — biller offline |

### Reversal `ErrorCode`

| Value | Meaning |
|---|---|
| `0` | Success |
| `1` | Original payment not found |
| `2` | Duplicate vendor reversal reference |
| `3` | Biller failed to reverse |
| `4` | Biller does not support reversals |
| `5` | Already refunded |
| `99` | General error |

### Wallet `Status`

| Code | Value | Can transact? |
|---|---|---|
| `1` | `Open` | Yes |
| `2` | `Suspended` | No — temporary |
| `3` | `Closed` | No — permanent |

### Bulk upload `ResponseCode`

| Value | Meaning |
|---|---|
| `0` | Unspecified |
| `1` | Success |

### `AuthAmountMandated`

| Value | Price source | Part payment |
|---|---|---|
| `null` | You specify it in AUTH, unchanged in PAY | N/A |
| `false` | AUTH response | Permitted |
| `true` | AUTH response | Not permitted — full payment |

### `RequiresForex`

| Value | Meaning | Wallet |
|---|---|---|
| `true` | Always forex | USD |
| `false` | Never forex | ZWG |
| `null` | AUTH decides | Determined at AUTH |

---

## Appendix C — Field formats & conventions

| Concept | Format | Notes |
|---|---|---|
| Date/time (query params) | `dd-MMM-yyyy HH:mm:ss` | e.g. `15-Sep-2017 14:35:21`. URL-encode the space |
| Date/time (JSON responses) | ISO 8601 | e.g. `2022-07-31T00:00:00` |
| Feed pagination `before` | Unix time in **milliseconds** | From the oldest record of the previous page |
| `Reference` (vendor) | Your own unique string | A UUID works well. **Identical** across AUTH, PAY and STATUS |
| Reversal `Reference` | Your own unique string | A new unique reference (reusing one returns error code `2`) |
| `BillPayReference` | Generated by BillPay | Unique reference containing the biller code, e.g. `COB-211129114124239` (exact format *not specified by Paynow*) |
| Currency codes | 3-char ISO | `ZWG`, `USD` |
| `ProductPrice` in legacy hash | 2 decimal places | `30.00`, not `30` |
| Pagination | `Page` (default 1), `PerPage` (default 200, max 200) | |
| Payment history window | **90 days** | `/api/payment/list` |
| Feed page size | **20 records** | Fixed |

---
