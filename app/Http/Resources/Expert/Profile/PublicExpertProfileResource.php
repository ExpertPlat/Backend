<?php

namespace App\Http\Resources\Expert\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PublicExpertProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $effectiveScopes = $this->expert->verifiedScopes
            ->filter(fn ($scope): bool => $scope->isEffective())
            ->values();

        return [
            'slug' => $this->slug,
            'name' => $this->expert->name,
            'professionalTitle' => $this->professional_title,
            'bio' => $this->bio,
            'yearsExperience' => $this->years_experience,
            'specialties' => $this->specialties ?? [],
            'languages' => $this->public_languages ?? [],
            'country' => $this->expert->country,
            'avatarUrl' => $this->avatar_path
                ? Storage::disk($this->avatar_disk)->url($this->avatar_path)
                : null,
            'verification' => [
                'status' => 'verified',
                'scopes' => VerifiedScopeResource::collection($effectiveScopes),
            ],
            'availability' => $this->expert->availability ? [
                'timezone' => $this->expert->availability->timezone,
                'serviceModes' => $this->expert->availability->service_modes ?? [],
                'weeklySchedule' => $this->expert->availability->weekly_schedule ?? [],
                'responseTimeHours' => $this->expert->availability->response_time_hours,
                'acceptingNewRequests' => $this->expert->availability->accepting_new_requests,
            ] : null,
            'publishedAt' => $this->published_at?->toISOString(),
        ];
    }
}
