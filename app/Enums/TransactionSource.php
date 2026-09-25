<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Demo = 'demo';
    case Csv = 'csv';
    case Api = 'api';
    case Webhook = 'webhook';
    case Manual = 'manual';
}
