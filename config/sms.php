<?php

/*
 * Batch 64 (user, 2026-11-13): text messages through IPROG SMS (iprogsms.com, a Philippine SMS gateway, about PHP 1 per
 * text). Off until SMS_ENABLED=true. Each kind of text has its own switch, so a costly one (login codes) can be turned
 * off without a code change. Every text is recorded in sms_logs (sent, failed or skipped, and why).
 *
 * With the shared sender name, IPROG delivers to Globe, TM, DITO and GOMO numbers; Smart and TNT need an approved
 * custom sender name (requested on iprogsms.com). A refused text is recorded as failed with IPROG's answer.
 */

return [
    'enabled' => (bool) env('SMS_ENABLED', false),

    'api_token' => env('IPROGSMS_API_TOKEN'),
    'base_url' => rtrim((string) env('IPROGSMS_BASE_URL', 'https://www.iprogsms.com/api/v1'), '/'),
    // IPROG's route: 0 (default), 1 or 2.
    'sms_provider' => (int) env('IPROGSMS_PROVIDER', 0),
    'timeout_seconds' => (int) env('IPROGSMS_TIMEOUT_SECONDS', 15),

    // Testing with few credits: every text goes to this one number instead of the real recipient. Empty: off.
    'intercept_to' => env('SMS_INTERCEPT_TO'),

    // One text is up to 160 characters; longer messages are cut so a text never costs two credits.
    'max_length' => 160,

    'events' => [
        'login_code' => (bool) env('SMS_LOGIN_CODE', true),
        'payment_confirmed' => (bool) env('SMS_PAYMENT_CONFIRMED', true),
        'refund_completed' => (bool) env('SMS_REFUND_COMPLETED', true),
        'trip_cancelled' => (bool) env('SMS_TRIP_CANCELLED', true),
        'tell_passengers' => (bool) env('SMS_TELL_PASSENGERS', true),
        'cash_ticket' => (bool) env('SMS_CASH_TICKET', true),
        // php artisan sms test <phone>, for checking the setup by hand.
        'test' => true,
    ],
];
