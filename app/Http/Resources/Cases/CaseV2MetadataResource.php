<?php

namespace App\Http\Resources\Cases;

use App\Models\CaseRecord;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CaseRecord */
class CaseV2MetadataResource extends JsonResource
{
    public function toArray($r): array
    {
        return ['id' => $this->id, 'userId' => $this->user_id, 'status' => $this->readiness_status, 'version' => $this->version, 'scopeCount' => $this->service_scopes_count, 'caseCountry' => $this->jurisdiction, 'language' => $this->language, 'readinessCheckedAt' => $this->readiness_checked_at?->toISOString(), 'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString()];
    }
}
