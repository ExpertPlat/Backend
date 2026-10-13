<?php

namespace App\Models;

use App\Enums\ExpertKycApplicationStatus;
use Database\Factories\ExpertKycApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpertKycApplication extends Model
{
    /** @use HasFactory<ExpertKycApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'attempt_number',
        'source_application_id',
        'full_name',
        'email_snapshot',
        'country',
        'language',
        'domain',
        'jurisdiction',
        'payout_readiness',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpertKycApplicationStatus::class,
            'requested_changes' => 'array',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function sourceApplication(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_application_id');
    }

    public function retryApplications(): HasMany
    {
        return $this->hasMany(self::class, 'source_application_id');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(ExpertKycExperience::class, 'application_id')->orderBy('sort_order');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(ExpertKycQualification::class, 'application_id')->orderBy('sort_order');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ExpertKycCredential::class, 'application_id')->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExpertKycDocument::class, 'application_id')->orderBy('sort_order');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ExpertKycStatusHistory::class, 'application_id')->latest('created_at');
    }

    public function verifiedScopes(): HasMany
    {
        return $this->hasMany(ExpertVerifiedScope::class, 'kyc_application_id');
    }
}
