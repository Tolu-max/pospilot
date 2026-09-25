<?php

namespace App\Enums;

enum TransactionAdjustmentDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
