# Biller integration notes

> Extracted from `full-reference.md` (verified against developers.paynow.co.zw, 21 Sep 2026). Special handling for the Test Biller, ZETDC, Pink Lotto, EVD, Airtime and Liquid Home.

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
