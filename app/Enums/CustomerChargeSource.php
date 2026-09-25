<?php

namespace App\Enums;

enum CustomerChargeSource: string
{
    case Imported = 'imported';
    case Calculated = 'calculated';
    case Manual = 'manual';
}
