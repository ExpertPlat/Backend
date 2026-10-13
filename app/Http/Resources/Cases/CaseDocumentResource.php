<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'caseId' => $this->case_id, 'caseVersion' => $this->when($this->case_version !== null, $this->case_version), 'deletedAt' => $this->deleted_at?->toISOString(), 'currentVersion' => new CaseDocumentVersionResource($this->whenLoaded('currentVersion'))];
    }
}
