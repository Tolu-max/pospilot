<?php

namespace App\Enums;

enum DailyClosingStatus: string
{
    case Draft = 'draft';
    case Balanced = 'balanced';
    case SmallVariance = 'small_variance';
    case NeedsReview = 'needs_review';
    case Unresolved = 'unresolved';
    case Finalized = 'finalized';
}
