<?php

namespace App\Enums;

enum ProviderConnectionType: string
{
    case Api = 'api';
    case Webhook = 'webhook';
    case Csv = 'csv';
    case Manual = 'manual';
}
