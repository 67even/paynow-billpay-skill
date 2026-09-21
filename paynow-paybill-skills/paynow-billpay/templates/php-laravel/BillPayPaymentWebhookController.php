<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Biller: BillPay posts {"Payments":[...], "Hash":"..."} with an X-Signature header.
 * X-Signature = base64(HMAC-SHA256(raw body, secret key)) — computed over the RAW
 * bytes, before JSON parsing. The legacy Hash is only a fallback.
 * Paynow documents no retry policy for this webhook; storing by PaymentId makes
 * the handler idempotent in case of redelivery (recommendation).
 */
class BillPayPaymentWebhookController
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        $secret = (string) config('billpay.webhook.secret_key');
        $data = json_decode($raw);

        if ($secret === '' || ! $this->verified($raw, $data, $secret, $request->header('X-Signature'))) {
            return response('', 401);
        }

        foreach ($data->Payments ?? [] as $p) {
            DB::table('billpay_received_payments')->updateOrInsert(
                ['payment_id' => $p->PaymentId],
                [
                    'billpay_reference'  => $p->BillPayReference,
                    'bank_reference'     => $p->BankReference ?? '',
                    'paid_date'          => $p->PaidDate,          // "dd-MMM-yyyy HH:mm:ss"
                    'member_number'      => $p->MemberNumber,
                    'member_name'        => $p->MemberName,
                    'product_code'       => $p->ProductCode,
                    'product_price'      => $p->ProductPrice,
                    'product_department' => $p->ProductDepartment ?? null,
                    'updated_at'         => now(),
                ],
            );
        }

        return response('', 200);
    }

    private function verified(string $raw, ?object $data, string $secret, ?string $signature): bool
    {
        if ($signature) {
            $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

            return hash_equals($expected, $signature);
        }

        // Legacy: sha256(concatenated fields in this exact order + secret), lowercase hex
        if (! $data || empty($data->Hash) || ! isset($data->Payments)) {
            return false;
        }
        $plain = '';
        foreach ($data->Payments as $p) {
            $plain .= $p->PaymentId.$p->BillPayReference.$p->BankReference.$p->PaidDate
                .$p->MemberNumber.$p->MemberName.$p->ProductCode
                .number_format($p->ProductPrice, 2, '.', '')
                .($p->ProductDepartment ?? '');
        }

        return hash_equals(hash('sha256', $plain.$secret), strtolower($data->Hash));
    }
}
