<?php

namespace App\Policies;

use App\Models\DataRightsRequest;
use App\Models\User;

class DataRightsRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, DataRightsRequest $record): bool
    {
        return (int) $record->user_id === (int) $user->getKey();
    }

    public function update(User $user, DataRightsRequest $record): bool
    {
        return $this->view($user, $record);
    }
}
