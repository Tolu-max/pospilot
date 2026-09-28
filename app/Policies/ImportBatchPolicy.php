<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function view(User $user, ImportBatch $batch): bool
    {
        return $user->businessRole() === 'owner' && $user->businessAgentProfile()?->id === $batch->agent_profile_id;
    }
}
