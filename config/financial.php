<?php

return [
    // Display names for payment channels as they are stored on payments.payment_channel. A channel that is not listed
    // is shown by a readable form of its stored value, so a new gateway method appears without a code change.
    'channel_labels' => [
        'onsite' => 'Cash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'paymaya' => 'Maya',
        'card' => 'Card',
    ],

    'default_per_page' => (int) env('FINANCIAL_DEFAULT_PER_PAGE', 25),
    'max_per_page' => (int) env('FINANCIAL_MAX_PER_PAGE', 100),

    // The longest date range one request may cover (the figures are computed per request).
    'max_range_days' => (int) env('FINANCIAL_MAX_RANGE_DAYS', 366),
];
