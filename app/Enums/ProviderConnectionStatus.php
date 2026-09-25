<?php

namespace App\Enums;

enum ProviderConnectionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Error = 'error';
}
