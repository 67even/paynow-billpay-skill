# Paynow BillPay API — Integration Reference

Complete developer reference for integrating Paynow BillPay into third-party applications.

- **Base URL:** `https://billpay.paynow.co.zw`
- **Auth:** HTTP Basic (every request)
- **Source:** https://developers.paynow.co.zw/docs/billpay/intro/
- **Last verified against the official docs:** 21 September 2026

> **Conventions:** Content taken from the official docs is presented as fact. Anything Paynow does not specify — inferred behaviour, defensive recommendations, or ambiguities in the source — is labelled *(not specified by Paynow)* or *(recommendation)*. Confirm those points with Paynow support.


## Contents (search for the heading to jump)
§1 Overview · §2 Setup & Authentication · §3 HTTP codes & error handling · §4 Dual currency
**Part A — Vendor API:** §5 List Billers · §6 Member info · §7 Make payment · §8 Payment response · §9 Status · §10 List payments · §11 Wallets · §12 Reverse · §13 Webhooks & extras · §14 Biller integration notes · §15 Go live
**Part B — Biller API:** §16 Setup · §17 Member API · §18 Download payments · §19 Payment webhooks
**Part C — Code:** §20 Python, Node/TS, C#, cURL
**Appendices:** A endpoints · B enums · C formats · D checklist · E pitfalls · F glossary · G source map

---

## 1. Overview & Choosing Your API

BillPay is Paynow's bill-payment and service-vending platform for billers across Zimbabwe. It exposes **two separate APIs**. Pick the one matching your role:

| API | Who it's for | What you can do |
|---|---|---|
| **Vendor API** | Vendors / resellers paying bills on behalf of customers | List billers & products, Auth/Pay payments, query status & history, manage wallets, reversals, config webhooks |
| **Biller API** | Billers (service providers) who are **offline** | Create/update/delete members, bulk upload, download payment reports, receive payment webhooks |

> **Important:** The Biller API exists for *offline* billers — BillPay collects payment on the biller's behalf and then notifies them. Paynow prefers *online* biller integrations where the customer's payment is processed directly by the biller. If you are an online biller, contact Paynow support instead of using this API.

Credentials are issued by Paynow support. Vendors need an **API** user role; billers need a **Biller Admin** or **Biller User** role.

---

## 2. Setup & Authentication

### 2.1 Getting credentials

1. Contact Paynow support to have an API user created for your account.
2. For vendors, confirm which **wallets** (ZWG and/or USD) are provisioned and prefunded.
3. Register your **webhook URL** — vendors with Paynow support (biller-config webhook); billers with the BillPay **system admin** (payment webhook), as the Biller API pages state.
4. Billers additionally receive a **secret key** used to verify webhook payloads.

### 2.2 Building the Authorization header

All requests use HTTP Basic authentication. Every request must include the `Authorization` header.

1. Join username and password with a colon → `username:password`
2. Base64-encode that string → `dXNlcm5hbWU6cGFzc3dvcmQ=`
3. Prefix with the scheme → `Basic dXNlcm5hbWU6cGFzc3dvcmQ=`

For credentials `Aladdin` / `OpenSesame`:

```http
Authorization: Basic QWxhZGRpbjpPcGVuU2VzYW1l
```

### 2.3 Typical request headers

Only `Authorization` is mandatory. `Content-Type`/`Accept` below reflect JSON usage; the Biller API also accepts form-post and XML bodies (§2.4), in which case set `Content-Type` accordingly.

```http
POST /api/payment/process HTTP/1.1
Host: billpay.paynow.co.zw
Authorization: Basic <base64(user:pass)>
Content-Type: application/json
Accept: application/json
```

### 2.4 Accepted request body formats

The Biller API accepts three body encodings (the Vendor API examples use JSON throughout):

| Format | Example |
|---|---|
| Form post | `MyVariable=MyValue` |
| JSON | `{ "MyVariable": "MyValue" }` |
| XML | `<MyVariable>MyValue</MyVariable>` |

### 2.5 Recommended client configuration

| Setting | Value | Reason |
|---|---|---|
| HTTP timeout | **60 seconds** | Billers may be slow under load; a timeout is not a failure — follow up with a Status inquiry |
| Retries on timeout | **Never blind-retry a PAY** | Use the `Status` action instead, or `Retry` for retryable errors |
| Config cache | Store biller/product config in **your own database** | Do not call `ListBillers` on every page load |

---

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

# PART A — VENDOR API

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

## 14. Biller Integration Notes

Non-standard billers, and billers that need special handling.

### 14.1 Test Biller

Because many billers have no reliable test environment, BillPay provides a Test Biller that simulates a range of responses.

> **Note:** Every successful PAY response from the Test Biller includes `ReceiptHtml` and `ReceiptSmses`. **Live billers do not always do this** — always check for existence before displaying or sending.

#### Member number prefixes (test cases)

Any member number works. Prefix it to trigger a scenario:

| Prefix | Test case | Behaviour |
|---|---|---|
| `AT` | Auth timeout | AUTH responds after 120 seconds. Recommended client timeout: 60s |
| `AF` | Auth failure | Simulates an invalid member number response |
| `PT` | Payment timeout | PAY responds after 120 seconds — you must do a status inquiry |
| `PF` | Payment failure | PAY returns `Failed` with a permanent (non-retryable) error |
| `PP` | Payment pending | PAY returns `BeingPaid` (official wording — not in the §8.6 status list; treat as `BeingProcessed`); keep polling. Transitions to `Paid` after 24 hours. **Used in UAT test 3** |
| `PFF` | Payment flagged | PAY returns `Flagged`; follow-up inquiries recommended — it eventually resolves |
| `USD` | Forex required | Combinable with others (e.g. `PF-USD-1234`). Only valid where the product *sometimes* requires forex |

Examples:

| Member number | Result |
|---|---|
| `12345` | Successful AUTH response |
| `AT12345` | Response after 120 seconds (timeout simulation) |
| `AF12345` | Immediate auth failure |

If the member number contains `USD` **and** the product sometimes requires forex, `RequiresForexPayment` is returned as `TRUE`; otherwise `FALSE`.

#### Test products

| Code | Behaviour |
|---|---|
| `AI` | Variable price, AUTH returns the price, **part payment permitted** (e.g. council bill) |
| `AM` | Variable price, AUTH returns the price, **full payment required** (e.g. medical aid policy) |
| `AA` | No fixed price, AUTH does **not** return a price, customer chooses the amount (e.g. airtime, ZETDC). Must respect the product's `MinAmount`/`MaxAmount` (the official page says "MinPrice/MaxPrice"; the Product fields are `MinAmount`/`MaxAmount`) |
| `RV` | Fixed price, **returns vouchers** in the PAY response. Quantity may be specified (e.g. ZETDC tokens) |
| `FP` | Fixed price, **requires forex payment** |

#### Which test product maps to which real-world biller

| Test product | Real billers | What to test |
|---|---|---|
| `AI` | Council billers (City of Harare, Bulawayo) | Show balance, let the customer choose the amount |
| `AM` | Nyaradzo | Show balance, require full payment |
| `AA` | Airtime, ZETDC | Ask the customer how much to purchase |
| `RV` | TelOne, EVD | Display/send vouchers to the customer |
| `FP` | Nurses Council, some universities | Show products at fixed prices |

### 14.2 ZETDC Prepaid

**On success**, the response may contain:

- One or more `ReceiptSmses` entries when multiple tokens are returned
- One or more `ReceiptHtml` entries when multiple tokens are returned

> **Warning:** You **MUST** send every SMS to the customer individually, and display/print every `ReceiptHtml` entry individually — **not combined into one**.

**Pending / timeout** — under high load the biller may time out and BillPay returns a `Pending` response (official wording — not in the §8.6 status list; treat as `BeingProcessed`). Recommended web service timeout: **60 seconds**.

Status inquiry intervals:
- 1st inquiry: no less than **120 seconds** after the timeout/pending response
- Subsequent inquiries: no less than **180 seconds**

#### Test meter numbers

> **Note:** Only available on the BillPay **test** environment.

Any valid meter number works. Special cases:

| Meter number | Scenario |
|---|---|
| `37132567431` | Single debt |
| `37125980740` | Double debt |
| `37132229735` | Double token |
| *(any meter)* with amount `$177.77` | Token resend |

### 14.3 Paynow As A BillPay Biller (PaaBB)

Covered by a separate document specifically addressing **financial institution integrations**. Contact Paynow support for that specification.

### 14.4 Pink Lotto

A user can place one or more bets in a single transaction. Numbers go into the `BetInfo` metadata field.

Format rules:

- `Quantity` **must equal** the number of bet sets (sets of 6 numbers)
- `BetInfo` format: `{setOf6}${setOf6}$` — every set is terminated with `$`, **including the last**
- Each set: `{num};{num};{num};{num};{num};{num}` — semicolon separated
- Each number: **two-digit** format, `01` through `45`

```json
{
  "Products": [
    {
      "Code": "DRAW",
      "Metadata": [
        { "BetInfo": "01;02;03;04;05;06$35;19;16;06;03;11$35;19;16;06;03;11$" }
      ]
    }
  ]
}
```

Optional `RegData` for registering a new Pink Lotto user:

```json
{
  "Phone": "0733405542",
  "Password": "mypassword",
  "Name": "Sam",
  "Email": "sam@zimba.com",
  "Dob": "1990-09-10",
  "Question1": "Where is Harare?",
  "Question2": "Zimbabwe",
  "Answer1": "Where is Jo'burg",
  "Answer2": "SA"
}
```

**On success:** one or more `ReceiptSmses` / `ReceiptHtml` entries for multiple bets/tickets. `DisplayData` summarises multiple tokens into a single set of fields.

**Test member numbers:** registered mobile numbers `0733405532` through `0733405536`.

### 14.5 Paynow EVD (Vouchers)

This biller has **no member number** — use the customer's mobile number instead.

- **AUTH** confirms vouchers are in stock and **reserves** them
- **PAY** issues the previously reserved vouchers

#### Stock errors

Custom errors are returned as JSON inside the `TechnicalNarration` field.

Out of stock:

```json
{
  "Status": "OutOfStock",
  "OutOfStock": ["africom-50"],
  "InsufficientStock": null
}
```

Insufficient stock:

```json
{
  "Status": "InsufficientStock",
  "InsufficientStock": { "africom-50": 1 },
  "OutOfStock": null
}
```

#### Example AUTH request

```json
{
  "BillerCode": "EVD",
  "MemberNumber": "a@b.com",
  "Reference": "b56163df-69d3-48cc-8631-69ddff0fb3c1",
  "TotalAmount": "722",
  "Products": [
    {
      "Code": "telone-adsl-home-basic",
      "Quantity": "1",
      "Price": 722,
      "RequiresForexPayment": false
    }
  ],
  "Action": "AUTH"
}
```

#### Example PAY response

```json
{
  "Action": "Pay",
  "BillerCode": "EVD",
  "Reference": "b56163df-69d3-48cc-8631-69ddff0fb3c1",
  "MemberNumber": "a@b.com",
  "Products": [
    {
      "Quantity": 1,
      "Code": "telone-adsl-home-basic",
      "Name": "TelOne ADSL - Home Basic",
      "Price": 722,
      "RequiresForexPayment": false,
      "Vouchers": [
        {
          "SerialNumber": "50072200000066",
          "Batch": "5007220000",
          "VoucherCode": "4789000000000544",
          "ValidDays": 30,
          "ExpiryDate": "2022-07-31T00:00:00"
        }
      ]
    }
  ],
  "TotalAmount": 722,
  "Status": "Paid",
  "MemberName": "a@b.com",
  "BillerPaymentReference": "79d7a395-73c5-42ea-afc4-cc4afe9931b8",
  "BillPayReference": "EVD-220131100847476",
  "Currency": "ZWG",
  "WalletDebitReference": "D36",
  "WalletBalanceAfterDebit": -12422
}
```

### 14.6 Paynow Airtime

Use any valid mobile number (Econet, NetOne, Telecel or Africom) as the member number.

| Product | Description |
|---|---|
| `AIRTIME` | Credits a **ZWG** monetary value to the subscriber's prepaid balance |
| `AIRTIME_USD` | Credits a **USD** monetary value (only Econet supported at time of writing) |

All other products are mobile bundles; the applicable network is included in the product name.

### 14.7 Liquid Home — Pay As You Go

Liquid Home PAYG payments require a **`Service Login`** metadata field. The Service Login is **not** the account ID (e.g. `LIT-123456`) — it is assigned by Liquid Home when a service is added to a customer's account.

#### Retrieving service logins

Query the Member Information API with the customer's LIT ID:

```http
GET /api/payment/member?billerCode={billerCode}&memberNumber={litId}
```

`AccountDetails` returns the **Account Currency first**, followed by the service logins:

```json
{
  "AuthData": {
    "MemberName": "John Doe",
    "AccountDetails": {
      "Account Currency": "ZWG",
      "test@test.com": "FIB_PAYG",
      "2631234567890": "LTE_PAYG",
      "2638677187782": "VOIP_NUMBER"
    }
  }
}
```

> **Warning:** A Liquid account denominated in **USD can only purchase USD products**. A **ZWG account can purchase both USD and ZWG products**.

Product codes are prefixed with currency and service, e.g. `USD_FIB_PAYG_MEDIUM`, `USD_LTE_PAYG_30_100GB`.

#### Example payment request

```json
{
  "Products": [
    {
      "Code": "USD_FIB_PAYG_TEST",
      "Metadata": {
        "Service Login": "test@test.com"
      }
    }
  ]
}
```

**Test account numbers:** any live account number works. Example with a VOIP service: `84829` (Webdev).

---

## 15. Go Live (UAT)

Joint user acceptance testing is **required** before moving from test to live. Paynow must conform to biller requirements and ensure vendors do the same.

> **Note:** The vendor must **share their screen** while these tests are carried out.

### 15.1 UAT checklist

1. **Wallet balance** — the vendor can check and display their wallet balance in their backend.
2. **Customer info on AUTH** — at minimum `MemberName`, and `MemberAddress` if present, are displayed on screen after AUTH.
3. **Status inquiry intervals** — polling for `BeingProcessed` payments adheres to specification. Tested using the Test Biller with member numbers prefixed `PP`.
4. **Receipt HTML** — every entry in `ReceiptHtml` (if present) is shared with the customer. If displayed on screen, the customer must be able to download/print **each individual entry**, not combined into one.
5. **Receipt SMS** — every SMS in `ReceiptSmses` (if present) is sent to the customer's mobile, **each sent individually**, not combined.
6. **Config webhook** — the vendor responds to biller configuration webhooks with:
   - a `200 OK` response, **and**
   - a follow-up call to `/api/payment/ListBillers?billerCodes=ABC,DEF,GHI` — **not** the unfiltered `/api/payment/ListBillers`
7. **No polling of ListBillers** — the vendor calls it only as needed after a webhook, and stores biller configurations in the local application database.

### 15.2 Recommended practices (not part of UAT)

- **Track wallet balance** after each successful transaction (it's in the response) and notify operational staff when it falls below a predetermined amount. Avoid flooding staff with emails.
- **Cap retries** for transactions stuck in `BeingProcessed`. After a set number of retries: notify operational staff, notify the customer that you're attending to the issue, and temporarily suspend status inquiries to reduce load.
- **Have a fast refund path** for transactions that fail and cannot be retried.
- **Implement the auto-push config webhook** so your product/price offering reconfigures automatically or staff are alerted. If your pricing falls out of sync with BillPay, API requests are likely to be rejected.

---

# PART B — BILLER API

---

## 16. Biller API Setup

> **Note:** The Biller API serves **offline billers** — BillPay accepts payment on the biller's behalf and then notifies the biller. Paynow prefers online biller integrations where the customer's payment is processed directly by the biller. Online billers should contact Paynow support to discuss options.

### 16.1 Access

A user must be set up with a role of **Biller Admin** or **Biller User**. Contact the system admin to have an appropriate user created.

### 16.2 Authentication

Identical to the Vendor API — HTTP Basic on every request:

```http
Authorization: Basic QWxhZGRpbjpPcGVuU2VzYW1l
```

(Combine `username:password`, Base64-encode, prefix with `Basic `.)

### 16.3 Request formats

Bodies may be sent as form post, JSON or XML:

```
MyVariable=MyValue
```
```json
{ "MyVariable": "MyValue" }
```
```xml
<MyVariable>MyValue</MyVariable>
```

---

## 17. Member API

### 17.1 Create member

```http
POST /api/member/create
```

| Field | Type | Required |
|---|---|---|
| `MemberNumber` | String | Required |
| `FullName` | String | Required |
| `EmailAddress` | String | Optional |
| `MobileNo` | String | Optional |
| `PostalAddress` | String | Optional |
| `AccountDetails` | JSON string | Optional |

`AccountDetails` format:

```json
{"Ship Name": "Jolly Roger", "Age": "4723"}
```

Returns `200 OK` on success. All created members are logged in BillPay and viewable from the normal login.

### 17.2 Update member

```http
POST /api/member/update
```

Same fields as Create Member.

> **Warning:** All **optional fields will be overwritten with empty values** if not specified. Always send the complete record.

Returns `200 OK` on success. All updates are logged in BillPay.

### 17.3 Delete member

```http
POST /api/member/delete
```

| Field | Type | Required |
|---|---|---|
| `MemberNumber` | String | Required |

> **Warning:** The member number of a deleted member **cannot be re-used** in future. Deleted members can be undeleted later if required.

Returns `200 OK` on success.

### 17.4 Undelete member

```http
POST /api/member/undelete
```

| Field | Type | Required |
|---|---|---|
| `MemberNumber` | String | Required |

Returns `200 OK` on success.

### 17.5 List members

```http
GET /api/member/list
```

| Field | Type |
|---|---|
| `Filters` | String (syntax *not specified by Paynow*) |
| `Page` | Integer (default `1`) |
| `PerPage` | Integer (default `200`) |

Returns a paginated member list.

### 17.6 View individual member

```http
GET /api/member/single/<membernumber>
```

Returns the member's details.

### 17.7 Bulk member upload

```http
POST /api/member/uploadmembers
Content-Type: multipart/form-data
```

The file must be **CSV** with these mandatory columns **in this order**:

| Member Number | Full Name | Email | Mobile | Postal Address |
|---|---|---|---|---|
| 12345ABC | John | john@example.com | 0777123456 | |

Additional detail columns may follow the mandatory ones (e.g. `National Id`, `Place of Birth`).

**Response:**

| Field | Type | Description |
|---|---|---|
| `ResponseCode` | Integer | `0` = Unspecified, `1` = Success |
| `Narrative` | String | e.g. `"Inserted: 0, Updated: 1"` |
| `Warnings` | List\<String\> | e.g. `"Member 'a2001' has been overwritten"` |
| `Errors` | List\<String\> | Errors, if any |

> **Note:** If a member number already exists, that member's details are **updated**. If it does not exist, a **new** member record is created (upsert behaviour).

### 17.8 Bulk member delete

```http
POST /api/member/uploadmembersdelete
Content-Type: multipart/form-data
```

The CSV must contain **only one column**:

| Member Number |
|---|
| 12000 |
| 12001 |

**Response:**

| Field | Type | Description |
|---|---|---|
| `ResponseCode` | Integer | `0` = Unspecified, `1` = Success |
| `Narrative` | String | e.g. `"Deleted: 1"` |
| `Warnings` | List\<String\> | Warnings |
| `Errors` | List\<String\> | Errors, if any |

---

## 18. Download Payments (Biller)

Retrieve member payments as a CSV.

### Request

```http
GET /api/member/downloadpayments
```

| Field | Type | Required | Description |
|---|---|---|---|
| `From` | String `dd-MMM-yyyy HH:mm:ss` | **Required** | Start date |
| `To` | String `dd-MMM-yyyy HH:mm:ss` | **Required** | End date |
| `MemberNumber` | String | Optional | Filter to one member |

### Response

A CSV file:

| Payment ID | BillPay Ref | Bank Ref | Paid Date | Member Number | Member Name | Product | Price | Department |
|---|---|---|---|---|---|---|---|---|
| 172 | BP000123 | 9796 | 15-Sep-2017 14:35:21 | 12345ABC | John Doe | Lunch | 150.00 | Canteen |

> **Note:** All fields are populated **except `Department`**, which may or may not be present depending on how products are set up in your biller.

---

## 19. Biller Payment Webhooks

A biller can be notified of payments by having data posted to a URL on their site. You can choose to receive payment data **after every transaction** or **daily**.

Contact the system admin to register your webhook URL. You will then be issued a **secret key** for verifying incoming data.

### 19.1 Payload

| Field | Type | Description |
|---|---|---|
| `Payments` | Array\<Payment\> | Payment data |
| `Hash` | String | **Legacy** hash — prefer the `X-Signature` header |

### 19.2 Payment fields

| Field | Type | Description |
|---|---|---|
| `PaymentId` | Integer | Unique payment id |
| `BillPayReference` | String | Unique reference containing the biller code |
| `BankReference` | String | Payment gateway reference |
| `PaidDate` | Date + Time | Payment date |
| `MemberNumber` | String | Member identifier |
| `MemberName` | String | Member name |
| `ProductCode` | String | Product code |
| `ProductPrice` | Decimal | Price paid |
| `ProductDepartment` | String | Product department (may be absent) |

### 19.3 Validating with HMAC-SHA256 (recommended)

The posted message includes an **`X-Signature`** HTTP request header — an HMAC-based method of authenticating the source of the message.

Verify that the `X-Signature` header value matches the **HMAC-SHA256 of the raw message body**, computed with your secret key and Base64-encoded.

**C#**

```csharp
using System.Security.Cryptography;
using System.Text;

public static string ComputeHmacSHA256(string payload, string secret)
{
    var keyBytes = Encoding.UTF8.GetBytes(secret);
    var payloadBytes = Encoding.UTF8.GetBytes(payload);
    using var hmac = new HMACSHA256(keyBytes);
    var hash = hmac.ComputeHash(payloadBytes);
    return Convert.ToBase64String(hash);
}
```

**Java**

```java
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.util.Base64;

public static String computeHmacSHA256(String payload, String secret) throws Exception {
    SecretKeySpec secretKeySpec =
        new SecretKeySpec(secret.getBytes("UTF-8"), "HmacSHA256");
    Mac mac = Mac.getInstance("HmacSHA256");
    mac.init(secretKeySpec);
    byte[] hmacBytes = mac.doFinal(payload.getBytes("UTF-8"));
    return Base64.getEncoder().encodeToString(hmacBytes);
}
```

**PHP**

```php
function computeHmacSHA256($payload, $secret) {
    $hash = hash_hmac('sha256', $payload, $secret, true);
    return base64_encode($hash);
}
```

**Python**

```python
import base64, hashlib, hmac

def compute_hmac_sha256(payload: str, secret: str) -> str:
    digest = hmac.new(secret.encode("utf-8"),
                      payload.encode("utf-8"),
                      hashlib.sha256).digest()
    return base64.b64encode(digest).decode("ascii")
```

**Node.js**

```javascript
const crypto = require('crypto');

function computeHmacSha256(payload, secret) {
  return crypto.createHmac('sha256', secret).update(payload, 'utf8').digest('base64');
}
```

> **Implementation note:** Compute the HMAC over the **raw request body bytes**, before any JSON parsing or re-serialisation. Re-serialising will change whitespace and key order and break verification. Compare using a constant-time comparison function.

### 19.4 Validating with SHA256 hash (legacy)

> **Note:** There is no need to do this if you have already validated using the HMAC-SHA256 method above.

The posted message includes a `Hash` field. To validate it:

1. Concatenate the values of **all fields in each payment** in the message, in order.
2. Append the **secret key**.
3. SHA256 hash the resulting string and output as **lowercase hexadecimal**.

> **Warning:** The **order of fields when concatenating is critical**. If the wrong order is used, the hash will not validate correctly.

#### Worked example

```json
{
  "Payments": [
    {
      "PaymentId": 172,
      "BillPayReference": "FAKE-181211122304615",
      "BankReference": "9796",
      "PaidDate": "11-Dec-2018 12:24:51",
      "MemberNumber": "T00001",
      "MemberName": "John Doe",
      "ProductCode": "LN",
      "ProductPrice": 3.21,
      "ProductDepartment": "Sales"
    },
    {
      "PaymentId": 245,
      "BillPayReference": "FAKE-18121112212345",
      "BankReference": "",
      "PaidDate": "11-Dec-2018 13:14:11",
      "MemberNumber": "K00123",
      "MemberName": "Abby Fijngold",
      "ProductCode": "MP",
      "ProductPrice": 30.00,
      "ProductDepartment": "Sales"
    }
  ],
  "Hash": "660ad6a83bdd9993a2ef44e3b02098a6ce62763a145eccf1f669951bdd53ce40"
}
```

**Step 1 — concatenate all field values from each payment:**

```
172FAKE-181211122304615979611-Dec-2018 12:24:51T00001John DoeLN3.21Sales245FAKE-1812111221234511-Dec-2018 13:14:11K00123Abby FijngoldMP30.00Sales
```

**Step 2 — append the secret key:**

```
172FAKE-181211122304615979611-Dec-2018 12:24:51T00001John DoeLN3.21Sales245FAKE-1812111221234511-Dec-2018 13:14:11K00123Abby FijngoldMP30.00Sales415b654f-3544-4281-a91e-051e710bfb8d
```

**Step 3 — SHA256, lowercase hex:**

```
660ad6a83bdd9993a2ef44e3b02098a6ce62763a145eccf1f669951bdd53ce40
```

> **Formatting gotcha:** `ProductPrice` is formatted to **two decimal places** (`30.00`, not `30`). `ProductDepartment` may be absent — substitute an **empty string** in that case. `BankReference` may legitimately be an empty string.

#### Reference PHP implementation (legacy hash)

The snippet below is reproduced **as published by Paynow**, to show the field order.

> **Security warning:** Do not use the official snippet as-is in production. It builds the SQL `INSERT` by concatenating webhook values (**SQL injection**) and compares hashes with `===` (**not timing-safe**). Use the hardened version that follows it.

```php
<?php
// Takes raw data from the request
$json = file_get_contents('php://input');

// Converts it into a PHP object
$data = json_decode($json);

$payments = new Payments;
$payments->addPayments($data);

Class Payments {

  private $payments = array();

  public function addPayments($data){

    $hash = $data->Hash;
    $plainText = '';

    foreach($data->Payments as $payment) {
      $paymentId     = $payment->PaymentId;
      $billpayRef    = $payment->BillPayReference;
      $bankReference = $payment->BankReference;
      $paidDate      = $payment->PaidDate;
      $memberNumber  = $payment->MemberNumber;
      $memberName    = $payment->MemberName;
      $productCode   = $payment->ProductCode;
      $productPrice  = number_format($payment->ProductPrice, 2, '.', '');

      // department field may or may not be present
      if(isset($payment->ProductDepartment)){
        $productDepartment = $payment->ProductDepartment;
      } else {
        $productDepartment = '';
      }

      $plainText .= $paymentId.$billpayRef.$bankReference
                  .$paidDate.$memberNumber.$memberName
                  .$productCode.$productPrice.$productDepartment;

      $sqlData[] = "('".$paymentId."', '".$billpayRef."', '"
                  .$bankReference."', '".$paidDate."', '".$memberNumber
                  ."', '".$memberName."', '".$productCode."', '"
                  .$productPrice."', '".$productDepartment."')";
    }

    /* Read your Secret Key from config */
    $verified = Hash::verify($plainText, SECRETKEY, $hash);

    if ($verified) {
      $query = "INSERT INTO tblPayment
                (PaymentId, BillPayRef, BankRef, PaidDate,
                 MemberNumber, MemberName, ProductCode,
                 ProductPrice, ProductDepartment)
                VALUES ".implode(',', $sqlData);
    }
  }
}

class Hash
{
  public static function make($plainText, $secretKey) {
    $string = $plainText.$secretKey;
    $hash = hash("sha256", $string);
    return strtolower($hash);
  }

  public static function verify($values, $key, $hash)
  {
    return self::make($values, $key) === $hash;
  }
}
?>
```

**Hardened version (recommendation)** — same field order and hash, but with prepared statements and a constant-time comparison:

```php
<?php
function verifyLegacyHash(object $data, string $secretKey): bool
{
    $plainText = '';
    foreach ($data->Payments as $p) {
        $plainText .= $p->PaymentId
                    . $p->BillPayReference
                    . $p->BankReference
                    . $p->PaidDate
                    . $p->MemberNumber
                    . $p->MemberName
                    . $p->ProductCode
                    . number_format($p->ProductPrice, 2, '.', '')
                    . ($p->ProductDepartment ?? '');
    }
    $expected = strtolower(hash('sha256', $plainText . $secretKey));
    return hash_equals($expected, strtolower((string)($data->Hash ?? '')));
}

$data = json_decode(file_get_contents('php://input'));

if (!$data || !verifyLegacyHash($data, SECRETKEY)) {
    http_response_code(401);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO tblPayment
       (PaymentId, BillPayRef, BankRef, PaidDate, MemberNumber,
        MemberName, ProductCode, ProductPrice, ProductDepartment)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    // add an ON DUPLICATE KEY / ON CONFLICT clause keyed on PaymentId if you want idempotency
);

foreach ($data->Payments as $p) {
    $stmt->execute([
        $p->PaymentId, $p->BillPayReference, $p->BankReference, $p->PaidDate,
        $p->MemberNumber, $p->MemberName, $p->ProductCode,
        number_format($p->ProductPrice, 2, '.', ''), $p->ProductDepartment ?? null,
    ]);
}

http_response_code(200);
```

### 19.5 Webhook receiver checklist (billers) — *recommendations*

The official Biller API pages do not document the expected response code, a retry policy, or duplicate-delivery behaviour for payment webhooks. The following are defensive recommendations:

- Verify `X-Signature` **before** trusting or persisting any payload data.
- Respond `200 OK` quickly; do heavy processing asynchronously.
- Treat `PaymentId` as an **idempotency key**, in case the same payment is ever delivered more than once.
- Expect `ProductDepartment` to be missing on some records.
- Never expose the secret key in client-side code, logs or source control.

---

# PART C — END-TO-END INTEGRATION EXAMPLES

---

## 20. Reference implementations (Vendor API)

### 20.1 Python

```python
import base64
import time
import uuid
import requests

BASE_URL = "https://billpay.paynow.co.zw"


class BillPayError(Exception):
    """Raised for validation (400) and transport failures."""


class BillPayClient:
    def __init__(self, username, password, timeout=60):
        token = base64.b64encode(f"{username}:{password}".encode()).decode()
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        })
        self.timeout = timeout  # 60s per Paynow recommendation

    # ---------- low level ----------
    def _request(self, method, path, **kwargs):
        resp = self.session.request(
            method, f"{BASE_URL}{path}", timeout=self.timeout, **kwargs
        )
        if resp.status_code == 400:
            raise BillPayError(resp.json().get("ModelState", resp.text))
        resp.raise_for_status()
        return resp.json() if resp.content else None

    # ---------- catalogue ----------
    def list_billers(self, biller_codes=None):
        """Call unfiltered ONCE at setup; afterwards always pass biller_codes."""
        params = {"billerCodes": ",".join(biller_codes)} if biller_codes else None
        return self._request("GET", "/api/payment/ListBillers", params=params)

    def get_member(self, biller_code, member_number):
        return self._request("GET", "/api/payment/member", params={
            "billerCode": biller_code,
            "memberNumber": member_number,
        })

    # ---------- wallets ----------
    def list_wallets(self):
        return self._request("GET", "/api/wallets")

    # ---------- payments ----------
    def _process(self, payload):
        return self._request("POST", "/api/payment/process", json=payload)

    def auth(self, biller_code, member_number, reference, products, total_amount=None,
             payer_details=None):
        payload = {
            "Action": "Auth",
            "BillerCode": biller_code,
            "MemberNumber": member_number,
            "Reference": reference,
            "Products": products,
        }
        if total_amount is not None:
            payload["TotalAmount"] = total_amount
        if payer_details:
            payload["PayerDetails"] = payer_details
        return self._process(payload)

    def pay(self, biller_code, member_number, reference, products, total_amount=None,
            payer_details=None):
        # MUST mirror the AUTH request. If AUTH returned the balance owing,
        # omit TotalAmount and each product's Price.
        payload = {
            "Action": "Pay",
            "BillerCode": biller_code,
            "MemberNumber": member_number,
            "Reference": reference,
            "Products": products,
        }
        if total_amount is not None:
            payload["TotalAmount"] = total_amount
        if payer_details:
            payload["PayerDetails"] = payer_details
        return self._process(payload)

    def status(self, reference):
        return self._process({"Action": "Status", "Reference": reference})

    def reverse(self, original_reference, reversal_reference):
        return self._request("POST", "/api/payment/reverse", json={
            "OriginalReference": original_reference,
            "Reference": reversal_reference,
        })

    # ---------- extras ----------
    def target_stats(self, biller_code, currency=None):
        params = {"billercode": biller_code}
        if currency:
            params["currency"] = currency
        return self._request("GET", "/api/payment/TargetStats", params=params)

    def feed(self, biller_code, currency=None, before=None):
        params = {"billercode": biller_code}
        if currency:
            params["currency"] = currency
        if before:
            params["before"] = before
        return self._request("GET", "/api/payment/feed", params=params)

    def list_payments(self, **filters):
        return self._request("GET", "/api/payment/list", params=filters)


# --------------------------------------------------------------------------
# Full AUTH -> confirm -> PAY -> poll -> deliver flow
# --------------------------------------------------------------------------

FINAL_STATUSES = {"Paid", "Failed", "Reversed"}

# "BeingPaid" (Test Biller PP prefix) and "Pending" (ZETDC under load) appear in
# the official integration notes but not in the Statuses list. Treat them -- and
# anything unrecognised -- as pending.
PENDING_STATUSES = {"BeingProcessed", "BeingPaid", "Pending"}


def poll_until_final(client, reference, max_attempts=10):
    """First inquiry 120s after the pending/timeout; then every 180s.
    Flagged payments are polled at 600s intervals."""
    time.sleep(120)
    result = None
    for _ in range(max_attempts):
        try:
            result = client.status(reference)
        except (requests.exceptions.RequestException, BillPayError):
            time.sleep(180)          # transient failure: keep the specified cadence
            continue
        status = result.get("Status")
        if status in FINAL_STATUSES:
            return result
        time.sleep(600 if status == "Flagged" else 180)
    return result  # still non-final (or None) -> escalate to operations staff


def deliver_receipts(result):
    """Each ReceiptHtml and each SMS MUST be delivered individually."""
    data = result.get("PaymentData") or {}

    for html in (data.get("ReceiptHtml") or []):
        send_receipt_to_customer(html)          # one at a time

    for sms in (data.get("ReceiptSmses") or []):
        send_sms_to_customer(sms)               # one at a time

    for display_key, display_value in (data.get("DisplayData") or {}).items():
        show_on_summary_page(display_key, display_value)

    for product in result.get("Products", []):
        for voucher in (product.get("Vouchers") or []):
            show_voucher(voucher["VoucherCode"], voucher.get("SerialNumber"),
                         voucher.get("ExpiryDate"))


def purchase(client, biller_code, member_number, products, total_amount=None,
             auth_returns_price=False):
    """auth_returns_price: True when the product's AuthAmountMandated is true or
    false (AUTH returns the price/balance, e.g. Test products AI and AM)."""
    reference = str(uuid.uuid4())   # same reference for AUTH, PAY and STATUS

    # 1. AUTH -- always before debiting the customer
    auth = client.auth(biller_code, member_number, reference, products, total_amount)
    if auth.get("Status") != "Authorized":
        raise BillPayError(auth.get("Narration") or "Authorisation failed")

    # 2. Show the member details returned by AUTH and get confirmation
    auth_data = auth.get("AuthData") or {}
    amount_due = auth.get("TotalAmount") if auth_returns_price else total_amount
    confirmed = confirm_with_customer(
        name=auth_data.get("MemberName"),
        address=auth_data.get("MemberAddress"),
        details=auth_data.get("AccountDetails"),
        balances=auth_data.get("AccountBalances"),
        balance=auth_data.get("AccountBalance"),
        amount=amount_due,
    )
    if not confirmed:
        return None

    # 3. Build the PAY request. It must mirror AUTH, except that when AUTH
    #    returned the balance owing, TotalAmount and each product's Price are
    #    left blank (omitted here). How to submit a *part* payment for
    #    AuthAmountMandated=false is not specified by Paynow -- confirm with support.
    if auth_returns_price:
        pay_products = [{k: v for k, v in p.items() if k != "Price"} for p in products]
        pay_total = None
    else:
        pay_products, pay_total = products, total_amount

    # 4. Debit the customer only now, then PAY
    take_customer_payment(amount_due)

    try:
        result = client.pay(biller_code, member_number, reference,
                            pay_products, pay_total)
    except BillPayError as exc:
        # HTTP 400: PAY failed validation -- refund and surface the error
        refund_customer(amount_due, reason=str(exc))
        raise
    except requests.exceptions.RequestException:
        # Timeout, connection error or HTTP 5xx: outcome unknown.
        # Never re-send PAY -- find out what happened with STATUS.
        result = poll_until_final(client, reference)

    status = (result or {}).get("Status")
    if status in PENDING_STATUSES:
        result = poll_until_final(client, reference)
        status = (result or {}).get("Status")

    # 5. Outcome
    if status == "Paid":
        deliver_receipts(result)
        record_wallet_balance(result.get("Currency"),
                              result.get("WalletBalanceAfterDebit"))
    elif status == "Failed":
        refund_customer(amount_due, reason=result.get("Narration"))
    else:
        # Flagged, still pending, unknown status or no response at all
        escalate_to_operations(reference, status)

    return result


if __name__ == "__main__":
    client = BillPayClient("YOUR_USERNAME", "YOUR_PASSWORD")

    purchase(
        client,
        biller_code="COB",
        member_number="ABC123",
        products=[{
            "Code": "USD",
            "Quantity": 1,
            "Price": 100,
            "RequiresForexPayment": True,
        }],
        total_amount=100,
    )
```

### 20.2 Node.js / TypeScript

```typescript
import crypto from 'crypto';
import express from 'express';

const BASE_URL = 'https://billpay.paynow.co.zw';

type Action = 'Auth' | 'Pay' | 'Retry' | 'Status';

interface PaymentProduct {
  Code: string;
  Quantity?: number;
  Department?: string;
  Price?: number;
  RequiresForexPayment?: boolean;
  Metadata?: Record<string, string> | Array<Record<string, string>>;
}

export class BillPayClient {
  private authHeader: string;

  constructor(username: string, password: string, private timeoutMs = 60_000) {
    this.authHeader =
      'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
  }

  private async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);

    try {
      const res = await fetch(`${BASE_URL}${path}`, {
        method,
        headers: {
          Authorization: this.authHeader,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });

      if (res.status === 400) {
        const err = await res.json();
        throw new Error(`Validation failed: ${JSON.stringify(err.ModelState)}`);
      }
      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      const text = await res.text();
      return (text ? JSON.parse(text) : null) as T;   // TargetStats can be empty
    } finally {
      clearTimeout(timer);
    }
  }

  listBillers(billerCodes?: string[]) {
    const qs = billerCodes?.length
      ? `?billerCodes=${encodeURIComponent(billerCodes.join(','))}`
      : '';
    return this.request<any[]>('GET', `/api/payment/ListBillers${qs}`);
  }

  getMember(billerCode: string, memberNumber: string) {
    const qs = `?billerCode=${encodeURIComponent(billerCode)}` +
               `&memberNumber=${encodeURIComponent(memberNumber)}`;
    return this.request<any>('GET', `/api/payment/member${qs}`);
  }

  listWallets() {
    return this.request<any[]>('GET', '/api/wallets');
  }

  private process(action: Action, payload: Record<string, unknown>) {
    return this.request<any>('POST', '/api/payment/process', { Action: action, ...payload });
  }

  auth(billerCode: string, memberNumber: string, reference: string,
       products: PaymentProduct[], totalAmount?: number) {
    return this.process('Auth', {
      BillerCode: billerCode, MemberNumber: memberNumber,
      Reference: reference, Products: products, TotalAmount: totalAmount,
    });
  }

  pay(billerCode: string, memberNumber: string, reference: string,
      products: PaymentProduct[], totalAmount?: number) {
    return this.process('Pay', {
      BillerCode: billerCode, MemberNumber: memberNumber,
      Reference: reference, Products: products, TotalAmount: totalAmount,
    });
  }

  status(reference: string) {
    return this.process('Status', { Reference: reference });
  }

  reverse(originalReference: string, reference: string) {
    return this.request<any>('POST', '/api/payment/reverse', {
      OriginalReference: originalReference, Reference: reference,
    });
  }
}

// ---- webhook receivers (Express) ------------------------------------------
const app = express();

// Set only once the token has been agreed with (and configured by) the BillPay team.
const WEBHOOK_TOKEN = process.env.BILLPAY_WEBHOOK_TOKEN;

// Vendor: biller configuration change webhook
app.post(
  '/webhooks/billpay/config',
  express.json(),
  async (req, res) => {
    if (WEBHOOK_TOKEN) {
      // Header format per the official tip is ambiguous ("Bearer scheme with
      // basic authentication") -- confirm it with Paynow before enforcing.
      const expected = `Bearer ${WEBHOOK_TOKEN}`;
      const provided = req.headers.authorization ?? '';
      const ok =
        provided.length === expected.length &&
        crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(expected));
      if (!ok) return res.sendStatus(401);
    }

    const billerCodes: string[] = Array.isArray(req.body) ? req.body : [];
    res.sendStatus(200);                          // respond fast, then work

    if (billerCodes.length === 0) return;         // never fall back to an unfiltered call

    try {
      const client = new BillPayClient(process.env.BP_USER!, process.env.BP_PASS!);
      const updated = await client.listBillers(billerCodes);   // ALWAYS filtered
      await upsertBillersIntoDatabase(updated);
    } catch (err) {
      // The response has already been sent -- log and alert instead of crashing
      console.error('BillPay config resync failed', err);
      alertOperations(err);
    }
  },
);

// Biller: payment notification webhook (HMAC-SHA256 over the RAW body)
app.post(
  '/webhooks/billpay/payments',
  // Content-Type of payment webhooks is not documented, so capture any body raw
  express.raw({ type: () => true }),
  (req, res) => {
    if (!Buffer.isBuffer(req.body)) return res.sendStatus(400);
    const raw = req.body;
    const signature = String(req.headers['x-signature'] ?? '');
    const computed = crypto
      .createHmac('sha256', process.env.BILLPAY_SECRET_KEY!)
      .update(raw)
      .digest('base64');

    const a = Buffer.from(signature);
    const b = Buffer.from(computed);
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
      return res.sendStatus(401);
    }

    const { Payments } = JSON.parse(raw.toString('utf8'));
    for (const p of Payments) {
      upsertPaymentIdempotently(p.PaymentId, p);   // PaymentId = idempotency key
    }
    res.sendStatus(200);
  },
);
```

### 20.3 C# (.NET)

```csharp
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

// Partial sample: Biller, PaymentResponse and BillPayValidationException are
// left for you to define (model them on the field tables in §5 and §8).
public sealed class BillPayClient
{
    private const string BaseUrl = "https://billpay.paynow.co.zw";
    private readonly HttpClient _http;

    // Omit null fields instead of sending "Field": null
    private static readonly JsonSerializerOptions Json = new()
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
    };

    public BillPayClient(string username, string password)
    {
        _http = new HttpClient
        {
            BaseAddress = new Uri(BaseUrl),
            Timeout = TimeSpan.FromSeconds(60)
        };

        var token = Convert.ToBase64String(
            Encoding.UTF8.GetBytes($"{username}:{password}"));

        _http.DefaultRequestHeaders.Authorization =
            new AuthenticationHeaderValue("Basic", token);
    }

    // Pass codes after a webhook; call with no codes only to seed your database.
    public Task<List<Biller>?> ListBillersAsync(IEnumerable<string>? codes = null)
    {
        var list = codes?.ToList();
        var qs = list is { Count: > 0 }
            ? "?billerCodes=" + Uri.EscapeDataString(string.Join(',', list))
            : "";
        return _http.GetFromJsonAsync<List<Biller>>($"/api/payment/ListBillers{qs}");
    }

    public Task<List<Wallet>?> ListWalletsAsync() =>
        _http.GetFromJsonAsync<List<Wallet>>("/api/wallets");

    private async Task<PaymentResponse> ProcessAsync(object request)
    {
        var res = await _http.PostAsJsonAsync("/api/payment/process", request, Json);

        if (res.StatusCode == System.Net.HttpStatusCode.BadRequest)
            throw new BillPayValidationException(await res.Content.ReadAsStringAsync());

        res.EnsureSuccessStatusCode();
        return (await res.Content.ReadFromJsonAsync<PaymentResponse>())!;
    }

    public Task<PaymentResponse> AuthAsync(PaymentRequest r)
    {
        r.Action = "Auth";
        return ProcessAsync(r);
    }

    public Task<PaymentResponse> PayAsync(PaymentRequest r)
    {
        r.Action = "Pay";
        return ProcessAsync(r);
    }

    // Matches the official sample: only Reference and Action are sent
    public Task<PaymentResponse> StatusAsync(string reference) =>
        ProcessAsync(new StatusRequest(reference));
}

public sealed record StatusRequest(string Reference)
{
    public string Action => "Status";
}

public class PaymentRequest
{
    public string Action { get; set; } = "Auth";
    public string? BillerCode { get; set; }
    public string? Reference { get; set; }
    public string? MemberNumber { get; set; }
    public decimal? TotalAmount { get; set; }
    public List<PaymentProduct> Products { get; set; } = new();
    public PayerDetails? PayerDetails { get; set; }
}

public class PaymentProduct
{
    public string Code { get; set; } = "";
    public int? Quantity { get; set; }
    public string? Department { get; set; }
    public decimal? Price { get; set; }
    public bool? RequiresForexPayment { get; set; }
    // Either Dictionary<string, string> (e.g. Liquid Home) or
    // List<Dictionary<string, string>> (e.g. Pink Lotto) -- see the note in §7
    public object? Metadata { get; set; }
}

public class PayerDetails
{
    public string? BankAccountName { get; set; }
    public string? BankAccountNumber { get; set; }
    public string? BankName { get; set; }
    public string? BankBranch { get; set; }
    public string? BankReference { get; set; }
    public string? ContactNumber { get; set; }
    public string? NationalId { get; set; }
}

public class Wallet
{
    public string Currency { get; set; } = "";
    public decimal Balance { get; set; }
    public decimal LowBalance { get; set; }
    public decimal MinimumBalance { get; set; }
    public string Status { get; set; } = "";
}
```

### 20.4 cURL quick reference

```bash
# -u builds the Basic Authorization header for you (and avoids base64 line-wrapping)
AUTH="$BP_USER:$BP_PASS"

# Catalogue (filtered - preferred)
curl -u "$AUTH" \
  "https://billpay.paynow.co.zw/api/payment/ListBillers?billerCodes=COB,EVD"

# Member lookup
curl -u "$AUTH" \
  "https://billpay.paynow.co.zw/api/payment/member?billerCode=COB&memberNumber=ABC123"

# Wallets
curl -u "$AUTH" "https://billpay.paynow.co.zw/api/wallets"

# AUTH
curl -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"Action":"AUTH","BillerCode":"COB","MemberNumber":"ABC123",
       "Reference":"08be48c6-2624-4a06-95eb-be79ff5d6ef5","TotalAmount":"100",
       "Products":[{"Code":"USD","Quantity":"1","Price":100,
                    "RequiresForexPayment":true}]}' \
  "https://billpay.paynow.co.zw/api/payment/process"

# PAY (identical body, Action changed)
curl -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"Action":"PAY","BillerCode":"COB","MemberNumber":"ABC123",
       "Reference":"08be48c6-2624-4a06-95eb-be79ff5d6ef5","TotalAmount":"100",
       "Products":[{"Code":"USD","Quantity":"1","Price":100,
                    "RequiresForexPayment":true}]}' \
  "https://billpay.paynow.co.zw/api/payment/process"

# STATUS
curl -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"Action":"STATUS","Reference":"08be48c6-2624-4a06-95eb-be79ff5d6ef5"}' \
  "https://billpay.paynow.co.zw/api/payment/process"

# REVERSE
curl -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"OriginalReference":"PMT1234567890","Reference":"REV1234567890"}' \
  "https://billpay.paynow.co.zw/api/payment/reverse"

# Biller: bulk member upload (the form field name "file" is an assumption --
# Paynow only specifies multipart/form-data)
curl -X POST -u "$AUTH" -F "file=@members.csv" \
  "https://billpay.paynow.co.zw/api/member/uploadmembers"

# Biller: download payments CSV
curl -u "$AUTH" -o payments.csv \
  "https://billpay.paynow.co.zw/api/member/downloadpayments?From=01-Sep-2026%2000:00:00&To=20-Sep-2026%2023:59:59"

# Biller: create member
curl -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"MemberNumber":"12345ABC","FullName":"John Doe",
       "EmailAddress":"john@example.com","MobileNo":"0777123456",
       "AccountDetails":"{\"Ship Name\":\"Jolly Roger\",\"Age\":\"4723\"}"}' \
  "https://billpay.paynow.co.zw/api/member/create"
```

---

# APPENDICES

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

## Appendix D — Integration checklist

### Phase 1 — Setup
- [ ] Obtain API credentials from Paynow support (vendor: `API` role; biller: `Biller Admin`/`Biller User`)
- [ ] Confirm ZWG and/or USD wallets are provisioned and prefunded
- [ ] Store credentials in a secret manager — never in source control
- [ ] Set HTTP client timeout to 60 seconds
- [ ] Register your webhook URL (vendors: Paynow support; billers: system admin) and share your bearer token / receive your secret key

### Phase 2 — Catalogue
- [ ] Call `ListBillers` **once**, unfiltered, to seed your database
- [ ] Persist billers and products locally
- [ ] Honour `Enabled` on both biller and product before offering them
- [ ] Render `MemberNumberFieldLabel` / `MemberNumberFieldDesc` on your input
- [ ] Validate member numbers against `MemberNumberFieldRegex`
- [ ] Enforce `MinAmount` / `MaxAmount`
- [ ] Respect `AllowMultipleProductsPerPayment`
- [ ] Collect `MetadataFields` where `Required` is true
- [ ] Show `PrePurchaseInstructions` before payment and `PostPurchaseInstructions` after
- [ ] Handle `AllowSpecifyQuantity` with `QuantityFieldLabel`

### Phase 3 — Payment flow
- [ ] Generate a unique `Reference` per transaction and persist it before calling the API
- [ ] Call AUTH **before** debiting the customer
- [ ] Display `MemberName` (and `MemberAddress` if present) for confirmation
- [ ] Interpret `AuthAmountMandated` correctly (part vs full payment)
- [ ] Set `RequiresForexPayment` on AUTH and PAY
- [ ] Blank `TotalAmount`/`Price` in PAY when AUTH returned the balance owing
- [ ] Send PAY with a body otherwise identical to AUTH
- [ ] Never re-send PAY after a timeout, connection error or 5xx — use STATUS
- [ ] Implement 120s/180s polling for `BeingProcessed`/`BeingPaid`/`Pending`, and 600s for `Flagged`
- [ ] Cap retries and escalate stuck transactions to operations staff

### Phase 4 — Fulfilment
- [ ] Send **each** `ReceiptSmses` entry as a separate SMS
- [ ] Display/print **each** `ReceiptHtml` entry separately and make each downloadable
- [ ] Render `DisplayData` on the confirmation page/email
- [ ] Surface vouchers (`VoucherCode`, `SerialNumber`, `ExpiryDate`, `ValidDays`)
- [ ] Record `WalletBalanceAfterDebit` and alert staff on low balance
- [ ] For `VendorMustInvoicePayments` billers, run a follow-up STATUS to collect `VendorInvoiceReference`, `VendorFiscalSignature` (QR code) and `VendorFiscalMetadata`
- [ ] Refund the customer promptly on `Failed`

### Phase 5 — Webhooks
- [ ] Validate the bearer token (vendor, once agreed with BillPay) or `X-Signature` HMAC (biller)
- [ ] Return `200 OK` promptly to the config webhook — otherwise BillPay retries every 30s, up to 3 times
- [ ] On config webhook, call `ListBillers?billerCodes=...` **filtered only**
- [ ] *(Recommendation)* Treat `PaymentId` as an idempotency key on biller webhooks

### Phase 6 — Go live
- [ ] Exercise every Test Biller prefix: `AT`, `AF`, `PT`, `PF`, `PP`, `PFF`, `USD`
- [ ] Exercise every test product: `AI`, `AM`, `AA`, `RV`, `FP`
- [ ] Test ZETDC multi-token receipts and the special meter numbers
- [ ] Complete the 7 UAT items in §15.1 with screen sharing

---

## Appendix E — Common pitfalls

| Pitfall | Consequence | Fix |
|---|---|---|
| Treating HTTP 200 as success | Customers charged for failed payments | Always inspect `Status` |
| Re-sending PAY after a timeout | Risk of a duplicate payment *(inferred)* | Use the `Status` action with the original reference. A `Retry` action also exists ("retryable error or network interruption"), but Paynow does not specify when it is safe — confirm with support before using it |
| Polling faster than specified | Fails UAT; extra biller load | 120s then 180s; 600s when `Flagged` |
| Only polling on `BeingProcessed` | Test Biller `PP` returns `BeingPaid` and is used in UAT test 3 | Treat every non-final status as pending |
| Calling unfiltered `ListBillers` regularly | Fails UAT; slow app | Cache locally, refresh filtered after a webhook |
| Combining multiple receipts/SMS into one | Fails UAT; customer cannot redeem tokens | Deliver each entry individually |
| Ignoring the config webhook | Prices drift; requests rejected | Implement the webhook and resync |
| Sending `TotalAmount` when AUTH returned the balance | Likely rejection *(inferred)* | Leave `TotalAmount` and `Price` blank, as the official docs instruct |
| Omitting `RequiresForexPayment` | Likely rejection *(inferred)* — it is a required acknowledgement | Set it explicitly on AUTH and PAY |
| Partial `member/update` payload | Optional fields silently wiped | Always send the complete record |
| Re-using a deleted member number | Not permitted | Use `undelete` instead |
| Verifying HMAC over re-serialised JSON | Signature never matches | Hash the raw request body bytes |
| Assuming `ReceiptHtml` always exists | Null reference in production | Test Biller always returns it; live billers may not — check first |
| Assuming `ProductDepartment` is present | Legacy hash mismatch | Substitute an empty string |
| Expecting JSON from `TargetStats` | Parse error | Empty body returned when not configured |
| Reversal assumed available | Stuck refunds | Very few billers support it — have a manual refund path |

---

## Appendix F — Glossary

| Term | Meaning |
|---|---|
| **Vendor** | A reseller who pays bills on behalf of customers using a prefunded wallet |
| **Biller** | A service provider receiving payments (council, utility, insurer, university…) |
| **Member** | The biller's customer, identified by a **member number** |
| **Member number** | The account/meter/mobile number credited by the payment |
| **AUTH** | Stage 1 — validate, quote and reserve; returns member details |
| **PAY** | Stage 2 — debit the vendor wallet and provision the product |
| **Wallet** | Prefunded vendor balance, per currency (ZWG / USD) |
| **Forex** | A product that must be settled from the USD wallet |
| **Voucher** | A token/PIN/code returned by products such as ZETDC tokens or EVD |
| **CloudESD** | The fiscal signature service BillPay uses for auto-invoicing |
| **PaaBB** | Paynow As A BillPay Biller — financial institution integrations (separate spec) |
| **EVD** | The Paynow voucher biller ("Paynow EVD (Vouchers)") |
| **Auto-push** | The biller-configuration change webhook |
| **UAT** | Joint user acceptance testing required before going live |

---

## Appendix G — Source documentation map

Every page of the official documentation is represented in this file:

| Source page | Section here |
|---|---|
| `/docs/billpay/intro/` | §1, §2 |
| `/docs/billpay/vendor/intro/` | §2, §3, §4 |
| `/docs/billpay/vendor/list-billers/` | §5 |
| `/docs/billpay/vendor/member-info/` | §6 |
| `/docs/billpay/vendor/make-payment/` | §7 |
| `/docs/billpay/vendor/payment-response/` | §8 |
| `/docs/billpay/vendor/payment-status/` | §9 |
| `/docs/billpay/vendor/list-payments/` | §10 |
| `/docs/billpay/vendor/list-wallets/` | §11 |
| `/docs/billpay/vendor/reverse-payment/` | §12 |
| `/docs/billpay/vendor/webhooks/` | §13 |
| `/docs/billpay/vendor/biller-integration-notes/` | §14 |
| `/docs/billpay/vendor/go-live/` | §15 |
| `/docs/billpay/biller/intro/` | §16 |
| `/docs/billpay/biller/member-api/` | §17 |
| `/docs/billpay/biller/download-payments/` | §18 |
| `/docs/billpay/biller/webhooks/` | §19 |

**Support:** Paynow support issues vendor credentials, registers vendor webhook URLs and configures bearer tokens; the BillPay operations team sets low-balance thresholds; the system admin sets up biller users and payment webhooks. Go-live UAT is run jointly with Paynow.

*End of document.*
