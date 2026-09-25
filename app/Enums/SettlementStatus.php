<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
    case Unreconciled = 'unreconciled';
    case Disputed = 'disputed';
}
