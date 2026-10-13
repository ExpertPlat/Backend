<?php

namespace App\Services\Privacy;

use App\Exceptions\PrivacyException;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserProfile;
use App\Models\UserProfileVersion;
use Illuminate\Support\Facades\DB;

class ProfileManager
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function snapshot(User $user, ?UserProfile $profile = null): array
    {
        $profile ??= $user->profile;

        return ['name' => $user->name, 'phone' => $profile?->phone, 'country' => $profile?->country,
            'language' => $profile?->language, 'timezone' => $profile?->timezone];
    }

    public function update(User $user, array $data): UserProfile
    {
        return DB::transaction(function () use ($user, $data): UserProfile {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $profile = $owner->profile()->lockForUpdate()->first() ?? (new UserProfile)->forceFill(['user_id' => $owner->id, 'version' => 0]);
            $this->version($profile->version, $data['expectedVersion']);
            $before = $this->snapshot($owner, $profile);
            $after = array_replace($before, array_intersect_key($data, $before));
            $changed = array_keys(array_filter($after, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $profile;
            }
            if (! $profile->exists) {
                $this->saveVersion($owner->id, 0, $before, []);
            }
            if (array_key_exists('name', $data)) {
                $owner->name = $data['name'];
                $owner->save();
            }
            $profile->forceFill(array_diff_key($after, ['name' => true]));
            $old = $profile->version;
            $profile->version++;
            $profile->save();
            $this->saveVersion($owner->id, $profile->version, $after, $changed);
            $this->audit->write($user, 'user.profile_updated', 'user_profile', $profile->id, (string) $old, (string) $profile->version, metadata: ['changedFields' => $changed]);

            return $profile;
        });
    }

    private function saveVersion(int $userId, int $version, array $snapshot, array $changed): void
    {
        (new UserProfileVersion)->forceFill(['user_id' => $userId, 'version' => $version, 'snapshot' => $snapshot,
            'changed_fields' => $changed, 'created_at' => now()])->save();
    }

    public function preferences(User $user): UserPreference
    {
        return $user->preferences()->first() ?? (new UserPreference)->forceFill([
            'user_id' => $user->id, 'contact_channels' => ['email'], 'ai_assistance_enabled' => false,
            'recording_preference' => false, 'context_visibility' => 'private', 'version' => 0,
        ]);
    }

    public function updatePreferences(User $user, array $data): UserPreference
    {
        return DB::transaction(function () use ($user, $data): UserPreference {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $record = $this->preferences($user);
            $this->version($record->version, $data['expectedVersion']);
            $map = ['contactChannels' => 'contact_channels', 'aiAssistanceEnabled' => 'ai_assistance_enabled',
                'recordingPreference' => 'recording_preference', 'contextVisibility' => 'context_visibility'];
            $changed = [];
            foreach ($map as $api => $column) {
                if (array_key_exists($api, $data) && in_array($api, ['aiAssistanceEnabled', 'recordingPreference'], true)) {
                    $data[$api] = (bool) $data[$api];
                }
                if (array_key_exists($api, $data) && $record->{$column} !== $data[$api]) {
                    $record->{$column} = $data[$api];
                    $changed[] = $api;
                }
            }
            if ($changed !== []) {
                $old = $record->version;
                $record->version++;
                $record->save();
                $this->audit->write($user, 'user.preferences_updated', 'user_preferences', $record->id, (string) $old, (string) $record->version, metadata: ['changedFields' => $changed]);
            }

            return $record;
        });
    }

    public function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'The record changed. Reload it before saving.');
        }
    }
}
