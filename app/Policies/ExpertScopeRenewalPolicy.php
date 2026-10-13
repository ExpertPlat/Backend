<?php

namespace App\Policies;

use App\Models\Expert;
use App\Models\ExpertScopeRenewal;

class ExpertScopeRenewalPolicy
{
    public function view(Expert $expert, ExpertScopeRenewal $renewal): bool
    {
        return $expert->id === $renewal->expert_id;
    }

    public function update(Expert $expert, ExpertScopeRenewal $renewal): bool
    {
        return $this->view($expert, $renewal);
    }
}
