<?php

namespace App\Models;

use App\Enums\ExpertKycStatus;
use App\Notifications\Expert\ResetPasswordNotification;
use App\Notifications\Expert\VerifyEmailNotification;
use Database\Factories\ExpertFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'country', 'language', 'domain', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class Expert extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<ExpertFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ACCESS_ABILITY = 'expert:access';

    /**
     * Normalize email addresses for all Eloquent writes.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * Send the expert-specific verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the expert-specific password reset notification.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** @return HasMany<ExpertKycApplication, $this> */
    public function kycApplications(): HasMany
    {
        return $this->hasMany(ExpertKycApplication::class);
    }

    /** @return HasOne<ExpertKycApplication, $this> */
    public function latestKycApplication(): HasOne
    {
        return $this->hasOne(ExpertKycApplication::class)->ofMany('attempt_number', 'max');
    }

    /** @return HasOne<ExpertProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(ExpertProfile::class);
    }

    /** @return HasOne<ExpertAvailabilitySetting, $this> */
    public function availability(): HasOne
    {
        return $this->hasOne(ExpertAvailabilitySetting::class);
    }

    /** @return HasMany<ExpertScopeRenewal, $this> */
    public function scopeRenewals(): HasMany
    {
        return $this->hasMany(ExpertScopeRenewal::class);
    }

    /** @return HasMany<ExpertOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(ExpertOffering::class);
    }

    /** @return HasMany<CaseInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(CaseInvitation::class);
    }

    /** @return HasMany<ExpertVerifiedScope, $this> */
    public function verifiedScopes(): HasMany
    {
        return $this->hasMany(ExpertVerifiedScope::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'kyc_status' => ExpertKycStatus::class,
            'password' => 'hashed',
        ];
    }
}
