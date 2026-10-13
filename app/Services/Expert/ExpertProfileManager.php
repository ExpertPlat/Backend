<?php

namespace App\Services\Expert;

use App\Enums\ExpertKycStatus;
use App\Models\Expert;
use App\Models\ExpertAvailabilitySetting;
use App\Models\ExpertProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpertProfileManager
{
    public function profile(Expert $expert): ExpertProfile
    {
        return $expert->profile()->firstOrCreate([], [
            'slug' => $this->slug($expert),
            'public_languages' => array_values(array_filter([$this->normalizeLanguage($expert->language)])),
        ]);
    }

    public function availability(Expert $expert): ExpertAvailabilitySetting
    {
        return $expert->availability()->firstOrCreate([], [
            'timezone' => 'UTC',
            'service_modes' => [],
            'weekly_schedule' => $this->defaultWeeklySchedule(),
            'blackout_dates' => [],
            'max_active_requests' => 3,
            'response_time_hours' => 48,
            'accepting_new_requests' => false,
        ]);
    }

    public function updateProfile(Expert $expert, array $data): ExpertProfile
    {
        $profile = $this->profile($expert);
        $profile->fill([
            'professional_title' => $data['professionalTitle'],
            'bio' => $data['bio'],
            'years_experience' => $data['yearsExperience'] ?? null,
            'specialties' => $data['specialties'] ?? [],
            'public_languages' => $data['publicLanguages'],
        ])->save();

        return $profile->refresh();
    }

    public function updateAvailability(Expert $expert, array $data): ExpertAvailabilitySetting
    {
        $availability = $this->availability($expert);
        $availability->fill([
            'timezone' => $data['timezone'],
            'service_modes' => $data['serviceModes'],
            'weekly_schedule' => $data['weeklySchedule'],
            'blackout_dates' => $data['blackoutDates'] ?? [],
            'max_active_requests' => $data['maxActiveRequests'],
            'response_time_hours' => $data['responseTimeHours'],
            'accepting_new_requests' => $data['acceptingNewRequests'],
        ])->save();

        return $availability->refresh();
    }

    public function uploadAvatar(Expert $expert, UploadedFile $file): ExpertProfile
    {
        $profile = $this->profile($expert);
        $disk = 'public';
        $extension = mb_strtolower($file->extension());
        $path = 'expert-avatars/'.$expert->getKey().'/'.Str::uuid().'.'.$extension;

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($profile, $file, $disk, $path): ExpertProfile {
                $locked = ExpertProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
                $oldDisk = $locked->avatar_disk;
                $oldPath = $locked->avatar_path;

                $locked->forceFill([
                    'avatar_disk' => $disk,
                    'avatar_path' => $path,
                    'avatar_original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'avatar_mime_type' => (string) $file->getMimeType(),
                    'avatar_size' => (int) $file->getSize(),
                ])->save();

                if ($oldDisk && $oldPath) {
                    DB::afterCommit(fn () => Storage::disk($oldDisk)->delete($oldPath));
                }

                return $locked->refresh();
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function deleteAvatar(Expert $expert): ExpertProfile
    {
        $profile = $this->profile($expert);

        return DB::transaction(function () use ($profile): ExpertProfile {
            $locked = ExpertProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $disk = $locked->avatar_disk;
            $path = $locked->avatar_path;
            $locked->forceFill([
                'avatar_disk' => null,
                'avatar_path' => null,
                'avatar_original_name' => null,
                'avatar_mime_type' => null,
                'avatar_size' => null,
            ])->save();

            if ($disk && $path) {
                DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
            }

            return $locked->refresh();
        });
    }

    public function publish(Expert $expert): ExpertProfile
    {
        return DB::transaction(function () use ($expert): ExpertProfile {
            $lockedExpert = Expert::query()->lockForUpdate()->findOrFail($expert->getKey());
            $profile = $this->profile($lockedExpert);
            $availability = $this->availability($lockedExpert);
            $missing = $this->missingRequirements($lockedExpert, $profile, $availability);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'publication' => ['Complete the publication requirements before publishing.'],
                    'missingRequirements' => $missing,
                ]);
            }

            $profile->forceFill([
                'is_published' => true,
                'published_at' => $profile->published_at ?? now(),
            ])->save();

            return $profile->refresh();
        });
    }

    public function unpublish(Expert $expert): ExpertProfile
    {
        $profile = $this->profile($expert);
        $profile->forceFill(['is_published' => false])->save();

        return $profile->refresh();
    }

    /**
     * @return list<string>
     */
    public function missingRequirements(
        Expert $expert,
        ?ExpertProfile $profile = null,
        ?ExpertAvailabilitySetting $availability = null,
    ): array {
        $profile ??= $this->profile($expert);
        $availability ??= $this->availability($expert);
        $missing = [];

        if ($expert->kyc_status !== ExpertKycStatus::Approved) {
            $missing[] = 'kyc_approval';
        }

        if (blank($profile->professional_title)) {
            $missing[] = 'professional_title';
        }

        if (mb_strlen((string) $profile->bio) < 80) {
            $missing[] = 'bio';
        }

        if (($profile->public_languages ?? []) === []) {
            $missing[] = 'public_languages';
        }

        $hasEffectiveScope = $expert->verifiedScopes()
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', today())
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today());
            })
            ->exists();

        if (! $hasEffectiveScope) {
            $missing[] = 'verified_scope';
        }

        if (($availability->service_modes ?? []) === []) {
            $missing[] = 'service_modes';
        }

        $hasWorkingWindow = collect($availability->weekly_schedule ?? [])
            ->contains(fn (array $day): bool => ($day['enabled'] ?? false) && ($day['windows'] ?? []) !== []);

        if (! $hasWorkingWindow) {
            $missing[] = 'weekly_schedule';
        }

        return $missing;
    }

    /**
     * @return list<array{day: string, enabled: bool, windows: array<int, mixed>}>
     */
    public function defaultWeeklySchedule(): array
    {
        return array_map(static fn (string $day): array => [
            'day' => $day,
            'enabled' => false,
            'windows' => [],
        ], ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
    }

    private function slug(Expert $expert): string
    {
        $base = Str::slug($expert->name) ?: 'expert';

        return mb_substr($base, 0, 100).'-'.$expert->getKey();
    }

    private function normalizeLanguage(?string $language): ?string
    {
        return in_array($language, ['ar', 'en'], true) ? $language : null;
    }
}
