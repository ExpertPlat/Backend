<?php

namespace App\Http\Resources\Expert\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'timezone' => $this->timezone,
            'serviceModes' => $this->service_modes ?? [],
            'weeklySchedule' => $this->weekly_schedule ?? [],
            'blackoutDates' => $this->blackout_dates ?? [],
            'maxActiveRequests' => $this->max_active_requests,
            'responseTimeHours' => $this->response_time_hours,
            'acceptingNewRequests' => $this->accepting_new_requests,
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
