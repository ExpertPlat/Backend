<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseContextSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sourceContextVersionId' => $this->source_context_version_id, 'selectedFactKeys' => $this->selected_fact_keys, 'snapshot' => $this->payload, 'policyVersionId' => $this->policy_version_id, 'authorizedAt' => $this->authorized_at?->toISOString(), 'detachedAt' => $this->detached_at?->toISOString()];
    }
}
