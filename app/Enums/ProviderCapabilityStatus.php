<?php

namespace App\Enums;

enum ProviderCapabilityStatus: string
{
    case Supported = 'supported';
    case Unavailable = 'unavailable';
    case Planned = 'planned';
    case Unknown = 'unknown';
    case Documented = 'documented';
    case RequiresProviderAccess = 'requires_provider_access';
    case SandboxOnly = 'sandbox_only';
    case Unsupported = 'unsupported';
    case Unverified = 'unverified';
    case ComingLater = 'coming_later';
}
