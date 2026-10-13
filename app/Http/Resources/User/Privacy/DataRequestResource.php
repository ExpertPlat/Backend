<?php

namespace App\Http\Resources\User\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        return ['id' => $this->id, 'reference' => $this->reference, 'type' => $this->type->value,
            'scope' => $this->scope, 'status' => $this->status->value, 'version' => $this->version,
            'requestedAt' => $this->requested_at->toISOString(), 'dueAt' => $this->due_at->toISOString(),
            'startedAt' => $this->started_at?->toISOString(), 'completedAt' => $this->completed_at?->toISOString(),
            'reasonCode' => $this->reason_code, 'outcome' => $this->outcome,
            'downloadAvailable' => $this->status === DataRequestStatus::Completed
                && $this->type === DataRequestType::Export && $this->artifact_path !== null
                && ($this->artifact_expires_at?->isFuture() ?? false),
            'downloadExpiresAt' => $this->artifact_expires_at?->toISOString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'recordClass' => $i->record_class, 'status' => $i->status, 'reasonCode' => $i->reason_code,
                'retainUntil' => $i->retain_until?->toISOString(), 'reviewAt' => $i->review_at?->toISOString(),
            ]))];
    }
}
