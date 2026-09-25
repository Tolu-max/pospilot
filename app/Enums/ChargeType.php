<?php

namespace App\Enums;

enum ChargeType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
