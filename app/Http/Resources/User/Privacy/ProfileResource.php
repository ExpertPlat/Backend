<?php

namespace App\Http\Resources\User\Privacy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        return ['name' => $this->name, 'email' => $this->email,
            'phone' => $this->profile?->phone, 'phoneVerified' => false,
            'country' => $this->profile?->country, 'language' => $this->profile?->language,
            'timezone' => $this->profile?->timezone, 'version' => $this->profile?->version ?? 0,
            'updatedAt' => $this->profile?->updated_at?->toISOString()];
    }
}
