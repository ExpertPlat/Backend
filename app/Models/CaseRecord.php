<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\CaseSuitability;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CaseRecord extends Model
{
    use HasFactory;

    protected $table = 'cases';

    protected $guarded = ['*'];

    /** @return HasMany<CaseServiceScope, $this> */
    public function serviceScopes(): HasMany
    {
        return $this->hasMany(CaseServiceScope::class, 'case_id');
    }

    protected function casts(): array
    {
        return ['readiness_checked_at' => 'datetime', 'catalog_contract_version' => 'integer', 'status' => CaseStatus::class, 'suitability' => CaseSuitability::class, 'version' => 'integer', 'confirmed_at' => 'datetime', 'submitted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<CaseIntakeVersion, $this> */
    public function currentIntake(): BelongsTo
    {
        return $this->belongsTo(CaseIntakeVersion::class, 'current_intake_version_id');
    }

    /** @return HasMany<CaseDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(CaseDomain::class, 'case_id');
    }

    /** @return HasMany<CaseContextSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(CaseContextSnapshot::class, 'case_id');
    }

    /** @return HasMany<CaseDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class, 'case_id');
    }

    /** @return HasMany<CaseIntakeAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(CaseIntakeAssessment::class, 'case_id');
    }

    /** @return HasOne<CaseIntakeAssessment, $this> */
    public function latestAssessment(): HasOne
    {
        return $this->hasOne(CaseIntakeAssessment::class, 'case_id')->latestOfMany();
    }

    /** @return HasMany<CaseIntakeVersion, $this> */
    public function analysis(): HasOne
    {
        return $this->hasOne(CaseAnalysis::class, 'case_id');
    }

    public function intakeVersions(): HasMany
    {
        return $this->hasMany(CaseIntakeVersion::class, 'case_id');
    }
}
