<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserConsentRecord;

class UserConsentRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserConsentRecord $record): bool
    {
        return (int) $record->user_id === (int) $user->getKey();
    }

    public function update(User $user, UserConsentRecord $record): bool
    {
        return $this->view($user, $record);
    }
}
