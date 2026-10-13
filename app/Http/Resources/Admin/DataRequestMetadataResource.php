<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataRequestMetadataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'userId' => $this->user_id,
            'type' => $this->type->value, 'scope' => $this->scope, 'status' => $this->status->value,
            'requestedAt' => $this->requested_at->toISOString(), 'dueAt' => $this->due_at->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(), 'reasonCode' => $this->reason_code];
    }
}
