<?php

namespace App\Enums;

enum TransactionAdjustmentSource: string
{
    case Provider = 'provider';
    case Manual = 'manual';
    case Calculated = 'calculated';
}
