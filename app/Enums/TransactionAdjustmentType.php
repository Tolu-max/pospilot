<?php

namespace App\Enums;

enum TransactionAdjustmentType: string
{
    case ProviderFee = 'provider_fee';
    case VatTax = 'vat_tax';
    case Levy = 'levy';
    case SettlementFee = 'settlement_fee';
    case Commission = 'commission';
    case CustomerCharge = 'customer_charge';
    case Adjustment = 'adjustment';
    case Other = 'other';
}
