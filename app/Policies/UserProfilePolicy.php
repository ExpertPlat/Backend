<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserProfile;

class UserProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserProfile $record): bool
    {
        return (int) $record->user_id === (int) $user->getKey();
    }

    public function update(User $user, UserProfile $record): bool
    {
        return $this->view($user, $record);
    }
}
