<?php

namespace App\Jobs;

use App\Services\BillPay\BillPayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Upserts billers and products into your own database.
 * - After a config webhook: dispatch with the biller codes it named (filtered call).
 * - To seed: `php artisan billpay:seed-catalogue` (the ONLY place a null/unfiltered call is made).
 */
class SyncBillPayBillers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param string[]|null $billerCodes */
    public function __construct(public ?array $billerCodes)
    {
    }

    public function handle(BillPayClient $client): void
    {
        foreach ($client->listBillers($this->billerCodes) as $b) {
            DB::table('billpay_billers')->updateOrInsert(['code' => $b['Code']], [
                'name'                    => $b['Name'] ?? $b['Code'],
                'description'             => $b['Description'] ?? null,
                'enabled'                 => (bool) ($b['Enabled'] ?? false),
                'member_number_label'     => $b['MemberNumberFieldLabel'] ?? null,
                'member_number_desc'      => $b['MemberNumberFieldDesc'] ?? null,
                'member_number_regex'     => $b['MemberNumberFieldRegex'] ?? null,
                'allow_multiple_products' => (bool) ($b['AllowMultipleProductsPerPayment'] ?? false),
                'vendor_must_invoice'     => (bool) ($b['VendorMustInvoicePayments'] ?? false),
                'icon_url'                => $b['IconUrl'] ?? null,
                'logo_url'                => $b['LogoUrl'] ?? null,
                'raw_json'                => json_encode($b),
                'synced_at'               => now(),
                'updated_at'              => now(),
            ]);

            $seen = [];
            foreach ($b['Products'] ?? [] as $p) {
                $seen[] = $p['Code'];
                DB::table('billpay_products')->updateOrInsert(
                    ['biller_code' => $b['Code'], 'code' => $p['Code']],
                    [
                        'name'                   => $p['Name'] ?? $p['Code'],
                        'price'                  => $p['Price'] ?? null,
                        'description'            => $p['Description'] ?? null,
                        'department'             => $p['Department'] ?? null,
                        'amount_field_label'     => $p['AmountFieldLabel'] ?? null,
                        'quantity_field_label'   => $p['QuantityFieldLabel'] ?? null,
                        'pre_purchase_instructions'  => $p['PrePurchaseInstructions'] ?? null,
                        'post_purchase_instructions' => $p['PostPurchaseInstructions'] ?? null,
                        'requires_forex'         => $p['RequiresForex'] ?? null,      // true / false / null (AUTH decides)
                        'auth_amount_mandated'   => $p['AuthAmountMandated'] ?? null, // null = you set price; true/false = AUTH returns it
                        'min_amount'             => $p['MinAmount'] ?? null,
                        'max_amount'             => $p['MaxAmount'] ?? null,
                        'returns_vouchers'       => (bool) ($p['ReturnsVouchers'] ?? false),
                        'allow_specify_quantity' => (bool) ($p['AllowSpecifyQuantity'] ?? false),
                        'metadata_fields'        => json_encode($p['MetadataFields'] ?? []),
                        'enabled'                => (bool) ($p['Enabled'] ?? false),
                        'raw_json'               => json_encode($p),
                        'updated_at'             => now(),
                    ],
                );
            }

            // Products that disappeared from the biller are disabled, not deleted
            DB::table('billpay_products')
                ->where('biller_code', $b['Code'])
                ->whereNotIn('code', $seen ?: [''])
                ->update(['enabled' => false, 'updated_at' => now()]);
        }
    }
}
