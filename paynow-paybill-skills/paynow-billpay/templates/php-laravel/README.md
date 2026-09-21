# Laravel template — where each file goes

| Template file | Copy to |
|---|---|
| `../../config/laravel/billpay.php` | `config/billpay.php` |
| `BillPayClient.php`, `BillPayPaymentService.php`, `BillPayHooks.php`, `BillPayValidationException.php`, `BillPayTransportException.php` | `app/Services/BillPay/` |
| `BillPayTransaction.php`, `BillPayBiller.php`, `BillPayProduct.php` | `app/Models/` |
| `BillPayPurchaseController.php`, `BillPayWalletController.php` | `app/Http/Controllers/` |
| `StartBillPayPurchaseRequest.php` | `app/Http/Requests/` |
| `PollBillPayStatus.php`, `FetchBillPayFiscalData.php`, `SyncBillPayBillers.php` | `app/Jobs/` |
| `BillPayConfigWebhookController.php`, `BillPayPaymentWebhookController.php` | `app/Http/Controllers/` |
| `create_billpay_tables.php` | `database/migrations/<timestamp>_create_billpay_tables.php` |
| `routes.php` | merge into `routes/api.php`, `routes/console.php`, `AppServiceProvider` |
| `BillPayTest.php` | `tests/Feature/`. Run it with `php artisan test --filter=BillPayTest`. It uses fake HTTP, so no credentials are needed. 11 tests. They cover the happy path, the HTTP purchase flow (validation, member lookup, forex flag copied from AUTH, a double confirm getting 409, per-receipt pages), AUTH-returned price, PAY timeout → polling (BeingPaid, then Flagged, then Paid), refunds, a failed charge never reaching PAY, the polling cap, both webhooks and filtered sync |

Then:

1. Add the variables from `config/billpay.env.example` to `.env`.
2. Implement `BillPayHooks` (charge, refund, receipts, SMS, alerts) and bind it.
3. Run a real queue worker (`php artisan queue:work`). Polling jobs are delayed and can't run on the `sync` driver.
4. `php artisan migrate && php artisan billpay:seed-catalogue`
5. Give Paynow your webhook URLs: `https://your-app/api/webhooks/billpay/config` (vendor) and `/api/webhooks/billpay/payments` (biller).

Vendor-only apps can drop the biller webhook controller and the `billpay_received_payments` table. Biller-only apps can drop the rest.

Requires Laravel 10+ and PHP 8.1+.
