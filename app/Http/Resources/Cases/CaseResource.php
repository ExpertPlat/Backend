<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->currentIntake?->payload ?? [];
        $data['answers'] = (object) ($data['answers'] ?? []);
        $result = ['id' => $this->id, 'status' => $this->readiness_status === 'requires_review' ? 'needs_information' : $this->status->value, 'readinessStatus' => $this->readiness_status, 'catalogContractVersion' => $this->catalog_contract_version, 'version' => $this->version, 'schemaVersion' => 1,
            'title' => $data['title'] ?? null, 'primaryDomain' => $this->primary_domain, 'domains' => $this->domains->pluck('domain')->all(),
            'language' => $this->language, 'jurisdiction' => $this->jurisdiction, 'urgency' => $this->urgency, 'suitability' => $this->suitability->value,
            'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(), 'submittedAt' => $this->submitted_at?->toISOString(), 'cancelledAt' => $this->cancelled_at?->toISOString()];
        if (! $request->routeIs('user.cases.index')) {
            $result += ['intakeVersion' => $this->currentIntake?->version, 'intake' => $data, 'confirmedAssessmentId' => $this->confirmed_assessment_id, 'confirmedAt' => $this->confirmed_at?->toISOString(),
                'contextSnapshots' => CaseContextSnapshotResource::collection($this->whenLoaded('snapshots')),
                'assessment' => $this->latestAssessment ? new CaseAssessmentResource($this->latestAssessment) : null];
        }

        return $result;
    }
}
