<?php

namespace App\Policies;

use App\Models\CaseInvitation;
use App\Models\Expert;

/** Only the invited Expert may answer or read an invitation. Admins get no implicit access. */
class CaseInvitationPolicy
{
    public function respond(Expert $expert, CaseInvitation $invitation): bool
    {
        return $expert->is_active && $invitation->expert_id === $expert->id;
    }
}
