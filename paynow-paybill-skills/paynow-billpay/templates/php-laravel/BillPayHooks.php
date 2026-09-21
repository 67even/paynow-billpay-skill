<?php

namespace App\Services\BillPay;

use App\Models\BillPayTransaction;

/**
 * Your application's side of the integration. Implement this and bind it in a
 * service provider:  $this->app->bind(BillPayHooks::class, MyBillPayHooks::class);
 */
interface BillPayHooks
{
    /** Take the customer's money (card, mobile money, account...). Throw on failure. */
    public function chargeCustomer(BillPayTransaction $tx, float $amount): void;

    /** Give the customer's money back (payment Failed or PAY rejected). */
    public function refundCustomer(BillPayTransaction $tx, string $reason): void;

    /** Deliver ONE ReceiptHtml entry (show/email with its own download/print). */
    public function sendReceiptHtml(BillPayTransaction $tx, string $html, int $index): void;

    /** Send ONE ReceiptSmses entry as its own SMS. Never merge entries. */
    public function sendSms(BillPayTransaction $tx, string $text, int $index): void;

    /** Tell the customer their payment is delayed and being attended to. */
    public function notifyCustomerDelayed(BillPayTransaction $tx): void;

    /** Alert operations staff (stuck payment, low wallet balance...). */
    public function alertOperations(string $subject, array $context = []): void;
}
