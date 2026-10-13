<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseMetadataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'userId' => $this->user_id, 'status' => $this->readiness_status === 'requires_review' ? 'needs_information' : $this->status->value, 'readinessStatus' => $this->readiness_status, 'catalogContractVersion' => $this->catalog_contract_version, 'version' => $this->version, 'primaryDomain' => $this->primary_domain, 'domains' => $this->domains->pluck('domain')->all(), 'jurisdiction' => $this->jurisdiction, 'language' => $this->language, 'urgency' => $this->urgency, 'suitability' => $this->suitability->value, 'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(), 'submittedAt' => $this->submitted_at?->toISOString(), 'cancelledAt' => $this->cancelled_at?->toISOString()];
    }
}
