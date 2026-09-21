<?php

// ---------------------------------------------------------------------------
// routes/api.php  (API routes carry no CSRF middleware, which webhooks need)
// ---------------------------------------------------------------------------

use App\Http\Controllers\BillPayConfigWebhookController;
use App\Http\Controllers\BillPayPaymentWebhookController;
use App\Http\Controllers\BillPayPurchaseController;
use App\Http\Controllers\BillPayWalletController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/billpay/config', BillPayConfigWebhookController::class);    // vendor
Route::post('/webhooks/billpay/payments', BillPayPaymentWebhookController::class); // biller

// Vendor purchase flow (swap auth:sanctum for your guard)
Route::middleware(['auth:sanctum', 'throttle:30,1'])->prefix('billpay')->name('billpay.')->group(function () {
    Route::get('/billers', [BillPayPurchaseController::class, 'billers'])->name('billers');
    Route::get('/billers/{code}', [BillPayPurchaseController::class, 'biller'])->name('biller');
    Route::post('/purchases', [BillPayPurchaseController::class, 'start'])->name('purchases.start');
    Route::post('/purchases/{reference}/confirm', [BillPayPurchaseController::class, 'confirm'])->name('purchases.confirm');
    Route::post('/purchases/{reference}/cancel', [BillPayPurchaseController::class, 'cancel'])->name('purchases.cancel');
    Route::get('/purchases/{reference}', [BillPayPurchaseController::class, 'show'])->name('purchases.show');
    Route::get('/purchases/{reference}/receipts/{index}', [BillPayPurchaseController::class, 'receipt'])
        ->whereNumber('index')->name('purchases.receipt');

    // Back office: wallet balances (UAT test 1) — define the gate yourself
    Route::get('/admin/wallets', BillPayWalletController::class)->middleware('can:billpay-admin')->name('admin.wallets');
});

// ---------------------------------------------------------------------------
// routes/console.php — one-off catalogue seed (the only unfiltered ListBillers call)
// ---------------------------------------------------------------------------
//
// use App\Jobs\SyncBillPayBillers;
// use Illuminate\Support\Facades\Artisan;
//
// Artisan::command('billpay:seed-catalogue', function () {
//     SyncBillPayBillers::dispatchSync(null);
//     $this->info('BillPay catalogue seeded.');
// })->purpose('Seed billers/products from BillPay (run once, then rely on the config webhook)');
//
// // Customers who never confirm: release stale AUTHs (nothing was charged)
// Schedule::call(fn () => \App\Models\BillPayTransaction::where('state', 'authorized')
//     ->where('updated_at', '<', now()->subMinutes(30))->update(['state' => 'abandoned']))->everyTenMinutes();

// ---------------------------------------------------------------------------
// app/Providers/AppServiceProvider.php — register()
// ---------------------------------------------------------------------------
//
// $this->app->singleton(\App\Services\BillPay\BillPayClient::class,
//     fn () => \App\Services\BillPay\BillPayClient::fromConfig());
// $this->app->bind(\App\Services\BillPay\BillPayHooks::class, \App\Services\MyBillPayHooks::class);

// ---------------------------------------------------------------------------
// Example controller usage (checkout)
// ---------------------------------------------------------------------------
//
// $product = BillPayProduct::where('biller_code', $billerCode)->where('code', $productCode)->firstOrFail();
// $tx = $billPay->authorizeProduct($product, $meter, amount: 20.00);   // or authorize(...) with raw product lines
// if ($tx->state !== 'authorized') { return back()->withErrors($tx->narration ?? 'Could not validate the account'); }
// // show $tx->member_name, $tx->member_address, $tx->amount_due → customer confirms →
// $tx = $billPay->confirmAndPay($tx);   // paid/fulfilled, failed/refunded, or pending (polling queued)
