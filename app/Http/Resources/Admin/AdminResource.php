<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'status' => $this->invitation_accepted_at === null
                ? 'pending_invitation'
                : ($this->is_active ? 'active' : 'inactive'),
            'invitation_accepted_at' => $this->invitation_accepted_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->values(),
            ),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->getAllPermissions()->pluck('name')->sort()->values(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
