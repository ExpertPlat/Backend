<?php

namespace App\Http\Resources\Expert\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerifiedScopeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'jurisdiction' => $this->jurisdiction,
            'jurisdictionCountry' => $this->verified_country,
            'role' => $this->role,
            'serviceTypes' => $this->service_types ?? [],
            'languages' => $this->languages ?? [],
            'status' => $this->status->value,
            'isEffective' => $this->isEffective(),
            'validFrom' => $this->valid_from?->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
            'nextReviewAt' => $this->next_review_at?->toDateString(),
            'verifiedAt' => $this->created_at?->toISOString(),
        ];
    }
}
