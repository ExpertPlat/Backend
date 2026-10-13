<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentMetadataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'userId' => $this->user_id, 'purpose' => $this->purpose,
            'policyVersionId' => $this->policy_version_id, 'policyVersion' => $this->policy->version,
            'decision' => $this->decision->value, 'decidedAt' => $this->decided_at->toISOString()];
    }
}
