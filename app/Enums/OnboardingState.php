<?php

namespace App\Enums;

enum OnboardingState: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
