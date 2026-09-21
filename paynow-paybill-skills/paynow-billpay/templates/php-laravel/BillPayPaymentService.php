<?php

namespace App\Services\BillPay;

use App\Jobs\FetchBillPayFiscalData;
use App\Jobs\PollBillPayStatus;
use App\Models\BillPayProduct;
use App\Models\BillPayTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Vendor payment flow: AUTH → customer confirms → charge customer → PAY →
 * (pending → STATUS polling) → fulfil. Every rule here is explained in SKILL.md §4.
 */
class BillPayPaymentService
{
    /** Anything not in this list — BeingProcessed, BeingPaid, Pending, Flagged, unknown — is pending. */
    private array $finalStatuses;

    public function __construct(
        private readonly BillPayClient $client,
        private readonly BillPayHooks $hooks,
    ) {
        $this->finalStatuses = config('billpay.final_statuses', ['Paid', 'Failed', 'Reversed']);
    }

    /**
     * Optional early check of a meter / account number (GET /api/payment/member).
     * Advisory only — AUTH validates the number again.
     *
     * @return array{result: 'found'|'not_found'|'offline'|'unavailable', member_name: ?string, narration: ?string}
     */
    public function lookupMember(string $billerCode, string $memberNumber): array
    {
        try {
            $r = $this->client->member($billerCode, $memberNumber);
        } catch (BillPayValidationException|BillPayTransportException $e) {
            return ['result' => 'unavailable', 'member_name' => null, 'narration' => null];
        }

        return [
            'result' => match ((int) ($r['ResultCode'] ?? -1)) {
                1 => 'found',
                0 => 'not_found',   // wrong number: ask the customer to fix it
                2 => 'offline',     // biller offline: try later
                default => 'unavailable',
            },
            'member_name' => $r['AuthData']['MemberName'] ?? null,
            'narration'   => $r['Narration'] ?? null,
        ];
    }

    /**
     * Convenience AUTH for one catalogue product: builds the product line from the
     * local catalogue (price, forex flag, AuthAmountMandated, invoicing) so callers
     * only pass what the customer typed.
     *
     * @param float|null $amount customer-chosen amount (airtime, ZETDC); ignored when the
     *                           product has a fixed price or AUTH returns the price
     */
    public function authorizeProduct(
        BillPayProduct $product,
        string $memberNumber,
        ?float $amount = null,
        int $quantity = 1,
        array $metadata = [],
        array $extra = [],
    ): BillPayTransaction {
        $authReturnsPrice = $product->authReturnsPrice();
        $unitPrice = $authReturnsPrice ? null : ($product->price !== null ? (float) $product->price : $amount);

        $line = ['Code' => $product->code, 'Quantity' => $quantity];
        if ($unitPrice !== null) {
            $line['Price'] = $unitPrice;
        }
        if ($product->department) {
            $line['Department'] = $product->department;
        }
        if ($product->requires_forex !== null) {
            $line['RequiresForexPayment'] = $product->requires_forex; // null → AUTH decides (copied into PAY)
        }
        if ($metadata !== []) {
            $line['Metadata'] = $metadata;   // shape varies per biller — see SKILL.md §6
        }

        return $this->authorize(
            billerCode: $product->biller_code,
            memberNumber: $memberNumber,
            products: [$line],
            totalAmount: $unitPrice !== null ? round($unitPrice * $quantity, 2) : null,
            authReturnsPrice: $authReturnsPrice,
            vendorMustInvoice: (bool) $product->biller?->vendor_must_invoice,
            extra: $extra + ['product_code' => $product->code],
        );
    }

    /**
     * Step 1 — AUTH. Call before taking any money from the customer.
     *
     * @param array $products        [['Code' => 'ZESA', 'Quantity' => 1, 'Price' => 20, 'RequiresForexPayment' => true, 'Metadata' => ...], ...]
     * @param bool  $authReturnsPrice true when the catalogue product's AuthAmountMandated is true or false
     *                                (AUTH returns the price/balance; Price may be omitted here)
     */
    public function authorize(
        string $billerCode,
        string $memberNumber,
        array $products,
        ?float $totalAmount = null,
        bool $authReturnsPrice = false,
        ?array $payerDetails = null,
        bool $vendorMustInvoice = false,
        array $extra = [],   // your own columns, e.g. ['user_id' => ..., 'customer_phone' => ...]
    ): BillPayTransaction {
        $request = array_filter([
            'Action'       => 'Auth',
            'BillerCode'   => $billerCode,
            'MemberNumber' => $memberNumber,
            'Reference'    => (string) Str::uuid(),
            'TotalAmount'  => $totalAmount,
            'Products'     => $products,
        ], fn ($v) => $v !== null);

        // Persist BEFORE calling BillPay so the reference is never lost
        $tx = BillPayTransaction::create($extra + [
            'reference'           => $request['Reference'],
            'biller_code'         => $billerCode,
            'member_number'       => $memberNumber,
            'auth_request'        => $request,
            'total_amount'        => $totalAmount,
            'auth_returns_price'  => $authReturnsPrice,
            'payer_details'       => $payerDetails,
            'vendor_must_invoice' => $vendorMustInvoice,
            'state'               => 'created',
        ]);

        try {
            $result = $this->client->process($request);
        } catch (BillPayValidationException|BillPayTransportException $e) {
            // Nothing has been charged yet, so a failed/timed-out AUTH is safe to report as failed
            $tx->update(['state' => 'auth_failed', 'technical_narration' => $e->getMessage()]);

            return $tx;
        }

        $authorized = ($result['Status'] ?? null) === 'Authorized';

        // Forex: where the catalogue said RequiresForex = null, AUTH decides. Copy AUTH's
        // answer into the stored request so PAY acknowledges it too.
        foreach ($request['Products'] as $i => $line) {
            $fromAuth = $result['Products'][$i]['RequiresForexPayment'] ?? null;
            if (! array_key_exists('RequiresForexPayment', $line) && $fromAuth !== null) {
                $request['Products'][$i]['RequiresForexPayment'] = (bool) $fromAuth;
            }
        }

        $tx->update([
            'auth_request'        => $request,
            'state'               => $authorized ? 'authorized' : 'auth_failed',
            'billpay_status'      => $result['Status'] ?? null,
            'billpay_reference'   => $result['BillPayReference'] ?? null,
            'member_name'         => $result['AuthData']['MemberName'] ?? $result['MemberName'] ?? null,
            'member_address'      => $result['AuthData']['MemberAddress'] ?? null,
            'amount_due'          => $authReturnsPrice ? ($result['TotalAmount'] ?? null) : $totalAmount,
            'narration'           => $result['Narration'] ?? null,
            'technical_narration' => $result['TechnicalNarration'] ?? null,
            'auth_response'       => $result,
        ]);

        // Show member_name / member_address / amount_due to the customer next (UAT test 2).
        // EVD stock errors arrive as JSON inside technical_narration.
        return $tx;
    }

    /** Customer declined the confirmation screen. */
    public function abandon(BillPayTransaction $tx): void
    {
        if ($tx->state === 'authorized') {
            $tx->update(['state' => 'abandoned']);
        }
    }

    /**
     * Step 2 — customer confirmed: charge them, then PAY.
     */
    public function confirmAndPay(BillPayTransaction $tx): BillPayTransaction
    {
        // Atomic claim: a double-click or retried request can never charge or PAY twice
        $claimed = BillPayTransaction::whereKey($tx->id)
            ->where('state', 'authorized')
            ->update(['state' => 'confirming', 'updated_at' => now()]);
        if ($claimed !== 1) {
            throw new RuntimeException("Transaction {$tx->reference} is not awaiting confirmation");
        }
        $tx->refresh();

        try {
            $this->hooks->chargeCustomer($tx, (float) $tx->amount_due);
        } catch (Throwable $e) {
            $tx->update(['state' => 'charge_failed', 'technical_narration' => $e->getMessage()]);
            throw $e;                                         // no charge → no PAY
        }
        $tx->update(['state' => 'customer_charged', 'customer_charged_at' => now()]);

        // PAY mirrors AUTH, except: when AUTH returned the price/balance,
        // TotalAmount and every product Price are left blank.
        $payload = $tx->auth_request;
        $payload['Action'] = 'Pay';
        if ($tx->auth_returns_price) {
            unset($payload['TotalAmount']);
            foreach ($payload['Products'] as &$product) {
                unset($product['Price']);
            }
            unset($product);
        }
        if ($tx->payer_details) {
            $payload['PayerDetails'] = $tx->payer_details;
        }

        $tx->update(['state' => 'paying']);

        try {
            $result = $this->client->process($payload);
        } catch (BillPayValidationException $e) {
            // HTTP 400: PAY rejected by validation, nothing provisioned
            $tx->update(['state' => 'failed', 'technical_narration' => $e->getMessage()]);
            $this->refund($tx, 'Payment request rejected');

            return $tx;
        } catch (BillPayTransportException $e) {
            // Timeout / connection error / 5xx: outcome UNKNOWN. Never re-send PAY.
            Log::warning('BillPay PAY outcome unknown', ['reference' => $tx->reference, 'error' => $e->getMessage()]);
            $this->startPolling($tx);

            return $tx;
        }

        $this->record($tx, $result);

        if ($this->isFinal($result['Status'] ?? null)) {
            $this->applyFinal($tx, $result);
        } else {
            $this->startPolling($tx);
        }

        return $tx->refresh();
    }

    /**
     * One STATUS inquiry. Returns the delay (seconds) before the next check,
     * or null when polling should stop. Called by PollBillPayStatus.
     */
    public function checkStatus(BillPayTransaction $tx): ?int
    {
        if ($tx->state !== 'pending') {
            return null;
        }

        $status = null;
        try {
            $result = $this->client->status($tx->reference);
            $this->record($tx, $result);
            $status = $result['Status'] ?? null;
            if ($this->isFinal($status)) {
                $this->applyFinal($tx, $result);

                return null;
            }
        } catch (BillPayValidationException|BillPayTransportException $e) {
            // Transient: keep the specified cadence, don't speed up
            Log::info('BillPay STATUS failed', ['reference' => $tx->reference, 'error' => $e->getMessage()]);
        }

        $tx->increment('status_checks');

        if ($tx->status_checks >= config('billpay.polling.max_attempts', 10)) {
            $tx->update(['state' => 'needs_attention']);
            $this->hooks->alertOperations('BillPay payment unresolved', [
                'reference' => $tx->reference, 'status' => $status, 'checks' => $tx->status_checks,
            ]);
            $this->hooks->notifyCustomerDelayed($tx);

            return null;
        }

        return $status === 'Flagged'
            ? (int) config('billpay.polling.flagged_interval', 600)
            : (int) config('billpay.polling.interval', 180);
    }

    // ------------------------------------------------------------------

    private function isFinal(?string $status): bool
    {
        return $status !== null && in_array($status, $this->finalStatuses, true);
    }

    private function startPolling(BillPayTransaction $tx): void
    {
        $tx->update(['state' => 'pending', 'status_checks' => 0]);
        PollBillPayStatus::dispatch($tx->id)
            ->delay(now()->addSeconds((int) config('billpay.polling.first_delay', 120)));
    }

    private function record(BillPayTransaction $tx, array $result): void
    {
        $tx->update(array_filter([
            'billpay_status'           => $result['Status'] ?? null,
            'billpay_reference'        => $result['BillPayReference'] ?? null,
            'biller_payment_reference' => $result['BillerPaymentReference'] ?? null,
            'currency'                 => $result['Currency'] ?? null,
            'wallet_balance_after'     => $result['WalletBalanceAfterDebit'] ?? null,
            'narration'                => $result['Narration'] ?? null,
            'technical_narration'      => $result['TechnicalNarration'] ?? null,
            'fiscal_invoice_reference' => $result['VendorInvoiceReference'] ?? null,
            'fiscal_signature'         => $result['VendorFiscalSignature'] ?? null,
            'fiscal_metadata'          => $result['VendorFiscalMetadata'] ?? null,
            'pay_response'             => $result,
        ], fn ($v) => $v !== null));
    }

    private function applyFinal(BillPayTransaction $tx, array $result): void
    {
        switch ($result['Status']) {
            case 'Paid':
                $tx->update(['state' => 'paid']);
                $this->fulfil($tx->refresh(), $result);
                $this->checkWalletBalance($tx);
                if ($tx->vendor_must_invoice && ! $tx->fiscal_signature) {
                    // Fiscal data is produced asynchronously — collect it with a later STATUS
                    FetchBillPayFiscalData::dispatch($tx->id)->delay(now()->addMinutes(5));
                }
                break;

            case 'Failed':
                $tx->update(['state' => 'failed']);
                $this->refund($tx, $result['Narration'] ?? 'Payment failed');
                break;

            case 'Reversed':
                $tx->update(['state' => 'reversed']);
                $this->hooks->alertOperations('BillPay payment reversed', ['reference' => $tx->reference]);
                break;
        }
    }

    /** Deliver every receipt and SMS individually — UAT tests 4 and 5. Idempotent. */
    private function fulfil(BillPayTransaction $tx, array $result): void
    {
        if ($tx->receipts_delivered_at) {
            return;
        }

        $data = $result['PaymentData'] ?? [];

        foreach (array_values($data['ReceiptHtml'] ?? []) as $i => $html) {
            $this->hooks->sendReceiptHtml($tx, $html, $i);
        }
        foreach (array_values($data['ReceiptSmses'] ?? []) as $i => $sms) {
            $this->hooks->sendSms($tx, $sms, $i);
        }
        // DisplayData and Products[].Vouchers[] stay in pay_response for your
        // confirmation page / email (show VoucherCode, SerialNumber, ExpiryDate).

        $tx->update(['state' => 'fulfilled', 'receipts_delivered_at' => now()]);
    }

    private function refund(BillPayTransaction $tx, string $reason): void
    {
        if ($tx->customer_charged_at && ! $tx->customer_refunded_at) {
            $this->hooks->refundCustomer($tx, $reason);
            $tx->update(['state' => 'refunded', 'customer_refunded_at' => now()]);
        }
    }

    private function checkWalletBalance(BillPayTransaction $tx): void
    {
        $threshold = config("billpay.low_balance.{$tx->currency}");
        if ($threshold === null || $threshold === '' || $tx->wallet_balance_after === null) {
            return;
        }
        // Alert at most once per currency per day — don't flood staff
        if ((float) $tx->wallet_balance_after < (float) $threshold
            && Cache::add("billpay:low-balance:{$tx->currency}", true, now()->addDay())) {
            $this->hooks->alertOperations('BillPay wallet balance low', [
                'currency' => $tx->currency, 'balance' => $tx->wallet_balance_after, 'threshold' => $threshold,
            ]);
        }
    }
}
