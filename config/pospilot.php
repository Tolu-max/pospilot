<?php

return [
    'provider_capabilities' => [
        'opay' => ['csv_transaction_import' => 'supported', 'csv_settlement_import' => 'supported', 'api_transaction_sync' => 'planned', 'webhook_transactions' => 'planned', 'settlement_sync' => 'planned', 'balance_sync' => 'planned'],
        'moniepoint' => ['csv_transaction_import' => 'supported', 'csv_settlement_import' => 'supported', 'api_transaction_sync' => 'planned', 'webhook_transactions' => 'planned', 'settlement_sync' => 'planned', 'balance_sync' => 'planned'],
        'palmpay' => ['csv_transaction_import' => 'supported', 'csv_settlement_import' => 'supported', 'api_transaction_sync' => 'planned', 'webhook_transactions' => 'planned', 'settlement_sync' => 'planned', 'balance_sync' => 'planned'],
        'other' => ['csv_transaction_import' => 'supported', 'csv_settlement_import' => 'supported', 'api_transaction_sync' => 'unavailable', 'webhook_transactions' => 'unavailable', 'settlement_sync' => 'unavailable', 'balance_sync' => 'unavailable'],
    ],
    'daily_closing' => [
        'small_variance_threshold' => '100.00',
        'review_variance_threshold' => '1000.00',
        'transaction_effects' => [
            'withdrawal' => ['cash' => '-amount', 'electronic' => 'net'],
            'deposit' => ['cash' => 'amount', 'electronic' => '-net'],
            'transfer' => ['cash' => 'amount', 'electronic' => '-net'],
            'payment' => ['cash' => 'amount', 'electronic' => '-net'],
        ],
    ],
];
