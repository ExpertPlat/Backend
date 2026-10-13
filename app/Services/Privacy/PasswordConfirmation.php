<?php

namespace App\Services\Privacy;

use App\Exceptions\PrivacyException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordConfirmation
{
    public function confirm(User $user, #[\SensitiveParameter] string $password, string $purpose): Carbon
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => ['The password is incorrect.']]);
        }
        $until = now()->addMinutes(config('privacy.confirmation_minutes'));
        Cache::put($this->key($user, $purpose), now()->toISOString(), $until);

        return $until;
    }

    public function require(User $user, string $purpose): Carbon
    {
        $value = Cache::get($this->key($user, $purpose));
        if (! $value || Carbon::parse($value)->addMinutes(config('privacy.confirmation_minutes'))->lte(now())) {
            throw new PrivacyException('REAUTHENTICATION_REQUIRED', 'Confirm your password for this operation.', 403);
        }

        return Carbon::parse($value);
    }

    private function key(User $user, string $purpose): string
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->tokenable_type !== User::class || (int) $token->tokenable_id !== $user->id) {
            throw new PrivacyException('REAUTHENTICATION_REQUIRED', 'A personal access token is required.', 403);
        }

        return 'privacy:confirmation:user:'.$user->id.':token:'.$token->id.':'.$purpose;
    }
}
