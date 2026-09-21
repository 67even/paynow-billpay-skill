<?php

namespace App\Http\Controllers;

use App\Jobs\SyncBillPayBillers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Vendor: BillPay POSTs a JSON array of biller codes whose configuration changed,
 * e.g. ["ZETDC","EVD"]. Respond 200 quickly (otherwise BillPay retries every 30 s,
 * up to 3 times), then refresh ONLY those billers (UAT test 6).
 */
class BillPayConfigWebhookController
{
    public function __invoke(Request $request): Response
    {
        // Only enforce once the BillPay team has configured your token. The official
        // wording ("Bearer scheme with basic authentication") is ambiguous — confirm
        // the exact header with Paynow before relying on it.
        $token = config('billpay.webhook.token');
        if ($token) {
            $provided = (string) $request->header('Authorization', '');
            if (! hash_equals('Bearer '.$token, $provided)) {
                return response('', 401);
            }
        }

        $payload = json_decode($request->getContent(), true);
        $codes = array_values(array_unique(array_filter(
            is_array($payload) ? $payload : [],
            fn ($c) => is_string($c) && $c !== '',
        )));

        // Never fall back to an unfiltered ListBillers call
        if ($codes !== []) {
            SyncBillPayBillers::dispatch($codes);
        }

        return response('', 200);
    }
}
