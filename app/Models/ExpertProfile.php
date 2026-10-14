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

    /** Disk new avatars are written to: `public` locally, the object-storage disk (e.g. `private`) on Laravel Cloud. */
    public static function avatarDisk(): string
    {
        return (string) config('filesystems.expert_avatar_disk', 'public');
    }

    /**
     * Avatar URL served by the API (GET /api/media/expert-avatars/{id}/{file}), so it works with any disk — a local disk
     * without `storage:link`, or a private bucket on Laravel Cloud. The file name in the URL busts caches on change.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').'/api/media/expert-avatars/'.$this->getKey().'/'.basename($this->avatar_path);
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
