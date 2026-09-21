<?php

namespace App\Jobs;

use App\Models\BillPayTransaction;
use App\Services\BillPay\BillPayPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Self-rescheduling STATUS poller. First run is dispatched 120 s after the
 * pending/unknown PAY; afterwards every 180 s (600 s while Flagged), up to
 * billpay.polling.max_attempts, then escalates. Never lower these intervals —
 * the 120/180 cadence is tested in UAT (Test Biller prefix PP).
 */
class PollBillPayStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // rescheduling is handled explicitly below

    public function __construct(public int $transactionId)
    {
    }

    public function handle(BillPayPaymentService $service): void
    {
        $tx = BillPayTransaction::find($this->transactionId);
        if (! $tx) {
            return;
        }

        $nextDelay = $service->checkStatus($tx);

        if ($nextDelay !== null) {
            self::dispatch($this->transactionId)->delay(now()->addSeconds($nextDelay));
        }
    }
}
