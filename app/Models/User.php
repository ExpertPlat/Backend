<?php

namespace App\Models;

use App\Notifications\User\ResetPasswordNotification;
use App\Notifications\User\VerifyEmailNotification;
use Database\Factories\UserFactory;
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

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ACCESS_ABILITY = 'user:access';

    public const VERIFICATION_ABILITY = 'user:verify-email';

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
     * Send the regular-user verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the regular-user password reset notification.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<CaseRecord, $this> */
    public function cases(): HasMany
    {
        return $this->hasMany(CaseRecord::class, 'user_id');
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasMany<UserProfileVersion, $this> */
    public function profileVersions(): HasMany
    {
        return $this->hasMany(UserProfileVersion::class);
    }

    /** @return HasOne<UserPreference, $this> */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /** @return HasMany<SpecializedContext, $this> */
    public function contexts(): HasMany
    {
        return $this->hasMany(SpecializedContext::class);
    }

    /** @return HasMany<UserConsentRecord, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(UserConsentRecord::class);
    }

    /** @return HasMany<DataRightsRequest, $this> */
    public function dataRequests(): HasMany
    {
        return $this->hasMany(DataRightsRequest::class);
    }
}
