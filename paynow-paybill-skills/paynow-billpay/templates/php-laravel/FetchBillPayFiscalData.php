<?php

namespace App\Jobs;

use App\Models\BillPayTransaction;
use App\Services\BillPay\BillPayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-invoice billers (VendorMustInvoicePayments): BillPay obtains the CloudESD
 * fiscal signature asynchronously, so collect it with a later STATUS inquiry.
 * Paynow gives no exact delay; retry a few times.
 */
class FetchBillPayFiscalData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public int $transactionId)
    {
    }

    public function backoff(): array
    {
        return [300, 600, 900, 1800, 3600];
    }

    public function handle(BillPayClient $client): void
    {
        $tx = BillPayTransaction::find($this->transactionId);
        if (! $tx || $tx->fiscal_signature) {
            return;
        }

        $result = $client->status($tx->reference);

        if (empty($result['VendorFiscalSignature'])) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 3600);

            return;
        }

        $tx->update([
            'fiscal_invoice_reference' => $result['VendorInvoiceReference'] ?? null,
            'fiscal_signature'         => $result['VendorFiscalSignature'],   // render as QR code
            'fiscal_metadata'          => $result['VendorFiscalMetadata'] ?? null, // print on invoice
        ]);
    }
}
