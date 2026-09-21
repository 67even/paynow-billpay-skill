<?php

namespace App\Services\BillPay;

use RuntimeException;

/**
 * Timeout, connection failure or HTTP 5xx. For PAY the outcome is UNKNOWN:
 * never re-send PAY — wait 120 s and send a STATUS inquiry.
 */
class BillPayTransportException extends RuntimeException
{
}
