<?php

namespace App\Enums;

enum ReconciliationIssueType: string
{
    case MissingSettlement = 'missing_settlement';
    case SettlementAmountMismatch = 'settlement_amount_mismatch';
    case DuplicateSettlement = 'duplicate_settlement';
    case TransactionPending = 'transaction_pending';
    case ReversedTransaction = 'reversed_transaction';
    case ProviderFeeMismatch = 'provider_fee_mismatch';
}
