<?php

namespace App\Enums;

enum FinancialDataStatus: string
{
    case CompleteVerified = 'complete_verified';
    case Provisional = 'provisional';
    case Calculated = 'calculated';
    case ManuallyOverridden = 'manually_overridden';
}
