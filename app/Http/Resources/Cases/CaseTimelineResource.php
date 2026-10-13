<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->event_id, 'action' => $this->action, 'previousState' => $this->previous_state, 'newState' => $this->new_state, 'reasonCode' => $this->reason_code, 'occurredAt' => $this->occurred_at?->toISOString()];
    }
}
