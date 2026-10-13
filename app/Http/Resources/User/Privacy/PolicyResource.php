<?php

namespace App\Http\Resources\User\Privacy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        return ['id' => $this->id, 'policyKey' => $this->policy_key, 'purpose' => $this->purpose,
            'version' => $this->version, 'locale' => $this->locale, 'content' => $this->content,
            'contentHash' => $this->content_hash, 'effectiveAt' => $this->effective_at->toISOString(),
            'requiresReconsent' => $this->requires_reconsent];
    }
}
