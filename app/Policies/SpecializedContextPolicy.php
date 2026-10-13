<?php

namespace App\Policies;

use App\Models\SpecializedContext;
use App\Models\User;

class SpecializedContextPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, SpecializedContext $record): bool
    {
        return (int) $record->user_id === (int) $user->getKey();
    }

    public function update(User $user, SpecializedContext $record): bool
    {
        return $this->view($user, $record);
    }
}
