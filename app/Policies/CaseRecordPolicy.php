<?php

namespace App\Policies;

use App\Models\CaseRecord;
use App\Models\User;

class CaseRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, CaseRecord $case): bool
    {
        return $case->user_id === $user->id && $this->viewAny($user);
    }

    public function update(User $user, CaseRecord $case): bool
    {
        return $this->view($user, $case);
    }
}
