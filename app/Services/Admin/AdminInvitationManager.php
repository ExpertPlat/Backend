<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminInvitation;
use App\Notifications\Admin\AdminInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminInvitationManager
{
    public function issue(Admin $admin): void
    {
        if ($admin->invitation_accepted_at !== null) {
            throw ValidationException::withMessages([
                'admin' => ['This administrator has already accepted the invitation.'],
            ]);
        }

        $plainTextToken = Str::random(64);
        $expiresAt = now()->addHours((int) config('admin.invitation.expire_hours', 72));

        $admin->invitation()->updateOrCreate([], [
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $expiresAt,
            'accepted_at' => null,
        ]);

        $admin->notify(new AdminInvitationNotification($plainTextToken, $expiresAt));
    }

    public function accept(string $email, string $token, string $password): Admin
    {
        return DB::transaction(function () use ($email, $token, $password): Admin {
            $invitation = AdminInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'token' => ['The administrator invitation is invalid or has expired.'],
                ]);
            }

            $admin = Admin::query()->lockForUpdate()->findOrFail($invitation->admin_id);

            if (! hash_equals($admin->email, $email) || $admin->invitation_accepted_at !== null) {
                throw ValidationException::withMessages([
                    'token' => ['The administrator invitation is invalid or has expired.'],
                ]);
            }

            $acceptedAt = now();

            $admin->forceFill([
                'password' => Hash::make($password),
                'is_active' => true,
                'invitation_accepted_at' => $acceptedAt,
            ])->save();

            $invitation->forceFill(['accepted_at' => $acceptedAt])->save();
            $admin->tokens()->delete();

            return $admin->refresh();
        });
    }
}
