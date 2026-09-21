<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Local copy of a BillPay product (written only by SyncBillPayBillers). */
class BillPayProduct extends Model
{
    protected $table = 'billpay_products';
    protected $guarded = ['id'];

    protected $casts = [
        'price'                  => 'decimal:2',
        'min_amount'             => 'decimal:2',
        'max_amount'             => 'decimal:2',
        'requires_forex'         => 'boolean',
        'auth_amount_mandated'   => 'boolean',
        'returns_vouchers'       => 'boolean',
        'allow_specify_quantity' => 'boolean',
        'enabled'                => 'boolean',
        'metadata_fields'        => 'array',
        'raw_json'               => 'array',
    ];

    public function biller(): BelongsTo
    {
        return $this->belongsTo(BillPayBiller::class, 'biller_code', 'code');
    }

    /** AuthAmountMandated true/false: AUTH returns the price, and PAY must leave Price/TotalAmount blank. */
    public function authReturnsPrice(): bool
    {
        return $this->auth_amount_mandated !== null;
    }

    /** Customer types an amount (ZESA, airtime): no fixed price and AUTH won't supply one. */
    public function customerChoosesAmount(): bool
    {
        return $this->price === null && ! $this->authReturnsPrice();
    }
}
