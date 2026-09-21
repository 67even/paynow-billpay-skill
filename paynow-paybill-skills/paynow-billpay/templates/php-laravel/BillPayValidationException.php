<?php

namespace App\Services\BillPay;

use RuntimeException;

/** HTTP 400 from BillPay. ModelState maps field paths to messages. Do not retry as-is. */
class BillPayValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $modelState = [])
    {
        parent::__construct($message.($modelState ? ' '.json_encode($modelState) : ''));
    }
}
