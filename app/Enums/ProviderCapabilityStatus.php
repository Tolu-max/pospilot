<?php

namespace App\Enums;

enum ProviderCapabilityStatus: string
{
    case Supported = 'supported';
    case Unavailable = 'unavailable';
    case Planned = 'planned';
    case Unknown = 'unknown';
}
