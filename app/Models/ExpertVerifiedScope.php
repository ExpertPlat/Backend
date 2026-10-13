<?php

namespace App\Models;

use App\Enums\ExpertScopeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kyc_application_id',
    'verified_by_admin_id',
    'domain',
    'jurisdiction',
    'role',
    'service_types',
    'languages',
    'status',
    'valid_from',
    'valid_until',
    'evidence_type',
    'evidence_id',
    'evidence_document_id',
    'verified_country',
    'regulator',
    'registration_number',
    'verification_source',
    'status_checked',
    'checked_at',
    'next_review_at',
])]
class ExpertVerifiedScope extends Model
{
    protected function casts(): array
    {
        return [
            'service_types' => 'array',
            'languages' => 'array',
            'status' => ExpertScopeStatus::class,
            'valid_from' => 'date',
            'valid_until' => 'date',
            'checked_at' => 'datetime',
            'next_review_at' => 'date',
        ];
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    /** @return BelongsTo<ExpertKycApplication, $this> */
    public function kycApplication(): BelongsTo
    {
        return $this->belongsTo(ExpertKycApplication::class, 'kyc_application_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }

    /** @return HasMany<ExpertScopeRenewal, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(ExpertScopeRenewal::class, 'scope_id');
    }

    public function isEffective(): bool
    {
        return $this->status === ExpertScopeStatus::Active
            && ($this->valid_from->isToday() || $this->valid_from->isPast())
            && ($this->valid_until === null || $this->valid_until->isToday() || $this->valid_until->isFuture());
    }
}
