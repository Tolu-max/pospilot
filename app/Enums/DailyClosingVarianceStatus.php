<?php

namespace App\Enums;

enum DailyClosingVarianceStatus: string
{
    case Balanced = 'balanced';
    case SmallVariance = 'small_variance';
    case NeedsReview = 'needs_review';
    case Unresolved = 'unresolved';
}
