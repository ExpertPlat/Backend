<?php

namespace App\Http\Resources\Expert\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProfessionalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'professionalTitle' => $this->professional_title,
            'bio' => $this->bio,
            'yearsExperience' => $this->years_experience,
            'specialties' => $this->specialties ?? [],
            'publicLanguages' => $this->public_languages ?? [],
            'avatarUrl' => $this->avatar_path
                ? Storage::disk($this->avatar_disk)->url($this->avatar_path)
                : null,
            'isPublished' => (bool) $this->is_published,
            'publishedAt' => $this->published_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
