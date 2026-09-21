<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Local copy of a BillPay biller (written only by SyncBillPayBillers). */
class BillPayBiller extends Model
{
    protected $table = 'billpay_billers';
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'enabled'                 => 'boolean',
        'allow_multiple_products' => 'boolean',
        'vendor_must_invoice'     => 'boolean',
        'raw_json'                => 'array',
        'synced_at'               => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(BillPayProduct::class, 'biller_code', 'code');
    }
}
