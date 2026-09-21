<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartBillPayPurchaseRequest;
use App\Models\BillPayBiller;
use App\Models\BillPayProduct;
use App\Models\BillPayTransaction;
use App\Services\BillPay\BillPayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Customer-facing purchase API (JSON). All catalogue data comes from your own DB.
 *
 *   GET  /billpay/billers                              enabled billers
 *   GET  /billpay/billers/{code}                       one biller: input rules + products
 *   POST /billpay/purchases                            validate → (member lookup) → AUTH → confirmation data
 *   POST /billpay/purchases/{reference}/confirm        charge customer → PAY
 *   POST /billpay/purchases/{reference}/cancel         customer declined
 *   GET  /billpay/purchases/{reference}                state + SMS texts / vouchers / receipt links
 *   GET  /billpay/purchases/{reference}/receipts/{i}   one ReceiptHtml entry, printable on its own
 */
class BillPayPurchaseController
{
    public function __construct(private readonly BillPayPaymentService $billPay)
    {
    }

    public function billers(): JsonResponse
    {
        return response()->json(
            BillPayBiller::where('enabled', true)->orderBy('name')
                ->get(['code', 'name', 'description', 'logo_url', 'icon_url'])
        );
    }

    public function biller(string $code): JsonResponse
    {
        $biller = BillPayBiller::where('code', $code)->where('enabled', true)->firstOrFail();
        $products = BillPayProduct::where('biller_code', $code)->where('enabled', true)->orderBy('name')->get();

        return response()->json([
            'code'                => $biller->code,
            'name'                => $biller->name,
            'logo_url'            => $biller->logo_url,
            'member_number_label' => $biller->member_number_label,
            'member_number_desc'  => $biller->member_number_desc,
            'member_number_regex' => $biller->member_number_regex,
            'allow_multiple_products' => $biller->allow_multiple_products,
            'products' => $products->map(fn (BillPayProduct $p) => [
                'code'                      => $p->code,
                'name'                      => $p->name,
                'description'               => $p->description,
                'price'                     => $p->price,
                'customer_chooses_amount'   => $p->customerChoosesAmount(),
                'amount_from_biller'        => $p->authReturnsPrice(),   // shown after AUTH
                'amount_label'              => $p->amount_field_label,
                'min_amount'                => $p->min_amount,
                'max_amount'                => $p->max_amount,
                'currency'                  => $p->requires_forex === null ? null : ($p->requires_forex ? 'USD' : 'ZWG'),
                'allow_quantity'            => $p->allow_specify_quantity,
                'quantity_label'            => $p->quantity_field_label,
                'metadata_fields'           => $p->metadata_fields ?? [],
                'pre_purchase_instructions' => $p->pre_purchase_instructions,
            ])->values(),
        ]);
    }

    /** Step 1: validate the number and AUTH. Nothing is charged here. */
    public function start(StartBillPayPurchaseRequest $request): JsonResponse
    {
        $product = $request->product();
        $memberNumber = $request->input('member_number');

        // Optional early lookup — gives a friendlier error than AUTH for a mistyped number
        $lookup = $this->billPay->lookupMember($product->biller_code, $memberNumber);
        if ($lookup['result'] === 'not_found') {
            return response()->json([
                'message' => $lookup['narration'] ?: 'We could not find that number. Please check it and try again.',
                'errors'  => ['member_number' => ['Not found']],
            ], 422);
        }
        if ($lookup['result'] === 'offline') {
            return response()->json(['message' => 'This service is temporarily offline. Please try again shortly.'], 503);
        }

        $tx = $this->billPay->authorizeProduct(
            product: $product,
            memberNumber: $memberNumber,
            amount: $request->filled('amount') ? (float) $request->input('amount') : null,
            quantity: (int) ($request->input('quantity') ?? 1),
            metadata: (array) $request->input('metadata', []),
            extra: [
                'user_id'        => $request->user()?->getAuthIdentifier(),
                'customer_phone' => $request->input('customer_phone'),
            ],
        );

        if ($tx->state !== 'authorized') {
            return response()->json([
                'reference' => $tx->reference,
                'message'   => $tx->narration ?: 'We could not validate this purchase. Please check the details and try again.',
            ], 422);
        }

        // Confirmation screen: the customer must see who they are paying (UAT test 2)
        return response()->json([
            'reference'       => $tx->reference,
            'member_number'   => $tx->member_number,
            'member_name'     => $tx->member_name,
            'member_address'  => $tx->member_address,
            'account_details' => $tx->auth_response['AuthData']['AccountDetails'] ?? null,
            'account_balances'=> $tx->auth_response['AuthData']['AccountBalances'] ?? null,
            'product'         => $product->name,
            'amount'          => $tx->amount_due,
            'pre_purchase_instructions' => $product->pre_purchase_instructions,
        ], 201);
    }

    /** Step 2: customer confirmed → charge → PAY. */
    public function confirm(Request $request, string $reference): JsonResponse
    {
        $tx = $this->find($request, $reference);

        try {
            $tx = $this->billPay->confirmAndPay($tx);
        } catch (Throwable $e) {
            $state = $tx->refresh()->state;
            if ($state === 'charge_failed') {
                report($e);

                return response()->json(['message' => 'Your payment could not be taken. You have not been charged.'], 402);
            }
            if ($e instanceof RuntimeException) {
                return response()->json(['message' => 'This purchase has already been confirmed or is no longer available.'], 409);
            }
            throw $e;
        }

        return response()->json($this->present($tx), $tx->state === 'pending' ? 202 : 200);
    }

    public function cancel(Request $request, string $reference): Response
    {
        $this->billPay->abandon($this->find($request, $reference));

        return response()->noContent();
    }

    /** Poll from the app while state is "pending"; tokens appear once "fulfilled". */
    public function show(Request $request, string $reference): JsonResponse
    {
        return response()->json($this->present($this->find($request, $reference)));
    }

    /** One ReceiptHtml entry on its own page so each can be printed/downloaded (UAT test 4). */
    public function receipt(Request $request, string $reference, int $index): Response
    {
        $tx = $this->find($request, $reference);
        $html = $tx->receiptsHtml()[$index] ?? null;
        abort_if($html === null, 404);

        $headers = [
            'Content-Type'            => 'text/html; charset=UTF-8',
            // Third-party HTML: no scripts; images (absolute URLs) and inline styles only
            'Content-Security-Policy' => "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'",
            'X-Content-Type-Options'  => 'nosniff',
        ];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="receipt-'.$tx->reference.'-'.($index + 1).'.html"';
        }

        return response($html, 200, $headers);
    }

    // ------------------------------------------------------------------

    private function find(Request $request, string $reference): BillPayTransaction
    {
        return BillPayTransaction::where('reference', $reference)
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->firstOrFail();
    }

    private function present(BillPayTransaction $tx): array
    {
        $delivered = $tx->state === 'fulfilled';

        $message = match (true) {
            $delivered                       => 'Payment successful.',
            $tx->state === 'pending'         => 'Your payment is being processed. We will notify you as soon as it completes.',
            $tx->state === 'needs_attention' => 'Your payment is taking longer than usual. Our team is looking into it.',
            in_array($tx->state, ['failed', 'refunded'], true)
                                             => $tx->narration ?: 'The payment failed. Your money has been refunded.',
            default                          => $tx->narration,   // Narration is customer-safe; never show TechnicalNarration
        };

        return [
            'reference'         => $tx->reference,
            'state'             => $tx->state,
            'message'           => $message,
            'member_number'     => $tx->member_number,
            'member_name'       => $tx->member_name,
            'amount'            => $tx->amount_due,
            'currency'          => $tx->currency,
            'billpay_reference' => $tx->billpay_reference,
            'sms'               => $delivered ? $tx->receiptSmses() : [],   // each token separately
            'vouchers'          => $delivered ? $tx->vouchers() : [],
            'display_data'      => $delivered ? ($tx->pay_response['PaymentData']['DisplayData'] ?? null) : null,
            'receipts'          => $delivered ? array_map(fn (int $i) => [
                'view_url'     => route('billpay.purchases.receipt', [$tx->reference, $i]),
                'download_url' => route('billpay.purchases.receipt', [$tx->reference, $i, 'download' => 1]),
            ], array_keys($tx->receiptsHtml())) : [],
            'fiscal'            => $tx->fiscal_signature ? [
                'invoice_reference' => $tx->fiscal_invoice_reference,
                'signature'         => $tx->fiscal_signature,   // render as a QR code
                'metadata'          => $tx->fiscal_metadata,
            ] : null,
        ];
    }
}
