<?php

namespace App\Http\Resources\User\Privacy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        return ['id' => $this->id, 'purpose' => $this->purpose, 'policyVersionId' => $this->policy_version_id,
            'policyVersion' => $this->policy->version, 'policyLocale' => $this->policy->locale,
            'policyHash' => $this->policy->content_hash, 'decision' => $this->decision->value,
            'previousRecordId' => $this->previous_record_id, 'decidedAt' => $this->decided_at->toISOString()];
    }
}
