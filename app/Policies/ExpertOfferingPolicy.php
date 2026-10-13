<?php

namespace App\Policies;

use App\Models\Expert;
use App\Models\ExpertOffering;

class ExpertOfferingPolicy
{
    public function manage(Expert $expert, ExpertOffering $offering): bool
    {
        return $expert->is_active && $offering->expert_id === $expert->id;
    }
}
