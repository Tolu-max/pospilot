<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $user->businessAgentProfile()?->id === $transaction->agent_profile_id;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $user->businessRole() === 'owner' && $this->view($user, $transaction);
    }
}
