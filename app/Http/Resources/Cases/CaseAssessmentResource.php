<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'intakeVersionId' => $this->intake_version_id, 'rulesVersion' => $this->rules_version, 'suitability' => $this->suitability, 'createdAt' => $this->created_at?->toISOString()] + $this->result;
    }
}
