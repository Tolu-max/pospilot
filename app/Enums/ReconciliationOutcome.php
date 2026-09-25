<?php

namespace App\Enums;

enum ReconciliationOutcome: string
{
    case Reconciled = 'reconciled';
    case Pending = 'pending';
    case PartiallyReconciled = 'partially_reconciled';
    case Unreconciled = 'unreconciled';
    case Disputed = 'disputed';
}
