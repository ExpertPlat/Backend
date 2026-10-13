<?php

namespace App\Models;

use App\Enums\ExpertKycStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'slug',
    'professional_title',
    'bio',
    'years_experience',
    'specialties',
    'public_languages',
])]
#[Hidden(['avatar_disk', 'avatar_path'])]
class ExpertProfile extends Model
{
    protected function casts(): array
    {
        return [
            'years_experience' => 'integer',
            'specialties' => 'array',
            'public_languages' => 'array',
            'avatar_size' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** Public visibility rule shared by the profile and its reviews. Requires `expert` and `expert.verifiedScopes` to be loadable. */
    public function isPubliclyVisible(): bool
    {
        $this->loadMissing('expert.verifiedScopes');

        return $this->is_published
            && $this->expert->is_active
            && $this->expert->kyc_status === ExpertKycStatus::Approved
            && $this->expert->verifiedScopes->contains(fn ($scope): bool => $scope->isEffective());
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }
}
