<?php

namespace App\Http\Controllers;

use App\Services\BillPay\BillPayClient;
use App\Services\BillPay\BillPayTransportException;
use App\Services\BillPay\BillPayValidationException;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/billpay/admin/wallets — back-office view of the prefunded BillPay wallets
 * (UAT test 1). Only "Open" wallets can transact; MinimumBalance may be negative (overdraft).
 */
class BillPayWalletController
{
    public function __invoke(BillPayClient $client): JsonResponse
    {
        try {
            $wallets = $client->wallets();
        } catch (BillPayTransportException|BillPayValidationException $e) {
            report($e);

            return response()->json(['message' => 'BillPay is unreachable right now.'], 503);
        }

        return response()->json(array_map(fn (array $w) => [
            'currency'        => $w['Currency'] ?? null,
            'balance'         => $w['Balance'] ?? null,
            'low_balance'     => $w['LowBalance'] ?? null,
            'minimum_balance' => $w['MinimumBalance'] ?? null,
            'status'          => $w['Status'] ?? null,
            'can_transact'    => ($w['Status'] ?? null) === 'Open',
        ], $wallets));
    }
}
