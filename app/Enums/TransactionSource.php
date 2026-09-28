<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Demo = 'demo';
    case Csv = 'csv';
    case Statement = 'statement';
    case Api = 'api';
    case Webhook = 'webhook';
    case Manual = 'manual';

    public function provenance(): string
    {
        return match ($this) {
            self::Api => 'provider_api',
            self::Webhook => 'provider_webhook',
            self::Statement, self::Csv => 'provider_statement',
            self::Manual => 'manual',
            self::Demo => 'demo',
        };
    }
}
