<?php

// config/billpay.php — Paynow BillPay settings (values come from .env)

return [
    'base_url' => env('BILLPAY_BASE_URL', 'https://billpay.paynow.co.zw'),
    'username' => env('BILLPAY_USERNAME'),
    'password' => env('BILLPAY_PASSWORD'),
    'timeout'  => (int) env('BILLPAY_TIMEOUT', 60),

    'webhook' => [
        // Vendor config webhook: only enforced when set (see SKILL.md §5)
        'token'      => env('BILLPAY_WEBHOOK_TOKEN'),
        // Biller payment webhook HMAC key
        'secret_key' => env('BILLPAY_SECRET_KEY'),
    ],

    'polling' => [
        'first_delay'      => (int) env('BILLPAY_POLL_FIRST_DELAY', 120),
        'interval'         => (int) env('BILLPAY_POLL_INTERVAL', 180),
        'flagged_interval' => (int) env('BILLPAY_POLL_FLAGGED_INTERVAL', 600),
        'max_attempts'     => (int) env('BILLPAY_POLL_MAX_ATTEMPTS', 10),
    ],

    'low_balance' => [
        'ZWG' => env('BILLPAY_LOW_BALANCE_ZWG'),
        'USD' => env('BILLPAY_LOW_BALANCE_USD'),
    ],

    // Final statuses end polling. Everything else (BeingProcessed, BeingPaid,
    // Pending, Flagged, unknown) is treated as pending — see SKILL.md §4.
    'final_statuses' => ['Paid', 'Failed', 'Reversed'],
];
