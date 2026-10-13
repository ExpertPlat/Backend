<?php

namespace App\Http\Resources\Cases;

use App\Enums\CaseStatus;
use App\Models\CaseRecord;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CaseRecord */
class CaseV2Resource extends JsonResource
{
    public function toArray($r): array
    {
        if ($r->routeIs('v2.user.cases.index')) {
            return ['id' => $this->id, 'status' => $this->status === CaseStatus::Cancelled ? 'cancelled' : $this->readiness_status, 'version' => $this->version, 'title' => $this->currentIntake->payload['title'] ?? null, 'caseCountry' => $this->jurisdiction, 'updatedAt' => $this->updated_at?->toISOString()];
        }
        $input = $this->currentIntake->payload ?? [];
        unset($input['domains'],$input['primaryDomain'],$input['serviceNeeds'],$input['schemaVersion']);
        $input['caseCountry'] = $input['jurisdiction'] ?? null;
        unset($input['jurisdiction']);
        $input['answers'] = (object) ($input['answers'] ?? []);
        $scopes = $this->serviceScopes->whereNull('detached_at')->values();

        return ['id' => $this->id, 'contractVersion' => '2.0.0', 'status' => $this->status === CaseStatus::Cancelled ? 'cancelled' : $this->readiness_status, 'version' => $this->version, 'intake' => $input,
            'scopes' => $scopes->map(fn ($s) => ['id' => $s->id, 'catalogEntryId' => $s->catalogVersion->entry_id, 'catalogVersion' => $s->catalogVersion->version, 'domain' => $s->catalogVersion->policy['domain'], 'specialty' => $s->catalogVersion->policy['specialty'], 'serviceType' => $s->catalogVersion->policy['serviceType'], 'deliveryMode' => $s->delivery_mode, 'jurisdictionMode' => $s->catalogVersion->policy['jurisdictionMode'], 'jurisdictionCodes' => $s->jurisdiction_codes, 'answers' => (object) $s->answers, 'confirmed' => $s->confirmed, 'status' => $s->status, 'reasonCodes' => $s->reason_codes ?? [], 'policySnapshot' => $s->policy_snapshot, 'readinessCheckedAt' => $s->readiness_checked_at?->toISOString(), 'submittedAt' => $s->submitted_at?->toISOString()]),
            'readinessCheckedAt' => $this->readiness_checked_at?->toISOString(), 'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString()];
    }
}
