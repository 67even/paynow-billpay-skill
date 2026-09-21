<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One BillPay vendor payment. `reference` is your unique UUID, shared by AUTH,
 * PAY and every STATUS inquiry. `state` is the internal state machine:
 *
 *   created → authorized → confirming → customer_charged → paying → paid → fulfilled
 *   paying → pending → paid | failed | needs_attention
 *   paying → failed (→ refunded)   authorized → abandoned   confirming → charge_failed   auth_failed
 */
class BillPayTransaction extends Model
{
    protected $table = 'billpay_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'auth_request'         => 'array',
        'auth_response'        => 'array',
        'pay_response'         => 'array',
        'payer_details'        => 'array',
        'auth_returns_price'   => 'boolean',
        'vendor_must_invoice'  => 'boolean',
        'total_amount'         => 'decimal:2',
        'amount_due'           => 'decimal:2',
        'wallet_balance_after' => 'decimal:2',
        'customer_charged_at'  => 'datetime',
        'customer_refunded_at' => 'datetime',
        'receipts_delivered_at'=> 'datetime',
    ];

    public function isFinal(): bool
    {
        return in_array($this->state, ['fulfilled', 'failed', 'refunded', 'reversed', 'auth_failed', 'abandoned', 'charge_failed'], true);
    }

    /** @return string[] each ReceiptHtml entry — show/print/download individually (UAT test 4) */
    public function receiptsHtml(): array
    {
        return array_values($this->pay_response['PaymentData']['ReceiptHtml'] ?? []);
    }

    /** @return string[] each ReceiptSmses entry — send individually (UAT test 5) */
    public function receiptSmses(): array
    {
        return array_values($this->pay_response['PaymentData']['ReceiptSmses'] ?? []);
    }

    /** @return array<int, array> vouchers across all products (VoucherCode, SerialNumber, ExpiryDate, ...) */
    public function vouchers(): array
    {
        return collect($this->pay_response['Products'] ?? [])
            ->flatMap(fn ($p) => $p['Vouchers'] ?? [])
            ->values()
            ->all();
    }
}
