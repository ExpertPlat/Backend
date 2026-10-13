<?php

namespace App\Policies;

use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Expert;
use App\Models\Proposal;

/** Experts act only on their own proposals, agreements and bookings. Admins get no implicit access in Sprint 4. */
class CommercePolicy
{
    public function act(Expert $expert, Proposal|Agreement|Booking $subject): bool
    {
        return $expert->is_active && $subject->expert_id === $expert->id;
    }
}
