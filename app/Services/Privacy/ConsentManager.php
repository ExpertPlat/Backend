<?php

namespace App\Services\Privacy;

use App\Enums\ConsentDecision;
use App\Exceptions\PrivacyException;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Models\UserConsentRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsentManager
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function currentPolicies(): Collection
    {
        return PolicyVersion::query()->where('is_published', true)->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')->orderByDesc('id')->get()
            ->unique(fn ($p) => $p->purpose.'|'.$p->locale)->values();
    }

    public function currentPolicy(string $purpose, string $locale): ?PolicyVersion
    {
        return PolicyVersion::query()->where('purpose', $purpose)->where('locale', $locale)
            ->where('is_published', true)->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')->orderByDesc('id')->first();
    }

    public function summaries(User $user): array
    {
        $latest = $user->consents()->whereIn('id', $user->consents()->selectRaw('MAX(id)')->groupBy('purpose'))
            ->with('policy')->get()->keyBy('purpose');
        $published = PolicyVersion::query()->where('is_published', true)->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')->orderByDesc('id')->get();
        $policies = $published->unique(fn ($p) => $p->purpose.'|'.$p->locale)->keyBy(fn ($p) => $p->purpose.'|'.$p->locale);

        return collect(config('privacy.purposes'))->map(function ($purpose) use ($latest, $policies, $published): array {
            $record = $latest->get($purpose);
            $current = $record ? $policies->get($purpose.'|'.$record->policy->locale) : null;
            $changed = $record && $current && $current->id !== $record->policy_version_id;
            // Any intervening material version requires renewal, even if the latest revision is editorial.
            $material = $changed && $published->contains(fn (PolicyVersion $policy): bool => $policy->purpose === $purpose && $policy->locale === $record->policy->locale && $policy->requires_reconsent
                && ($policy->effective_at->gt($record->policy->effective_at)
                    || ($policy->effective_at->eq($record->policy->effective_at) && $policy->id > $record->policy_version_id)));
            $granted = $record?->decision === ConsentDecision::Granted;

            return ['purpose' => $purpose, 'decision' => $record?->decision->value ?? 'not_recorded',
                'recordId' => $record?->id, 'policyVersionId' => $record?->policy_version_id,
                'decidedAt' => $record?->decided_at?->toISOString(),
                'requiresReconsent' => $granted && (bool) $material,
                'effective' => $granted && $current !== null && ! $material,
                'withdrawable' => in_array($purpose, config('privacy.withdrawable_purposes'), true)];
        })->all();
    }

    public function decide(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $policy = PolicyVersion::query()->find($data['policyVersionId']);
            if (! $policy || ! $policy->is_published || $policy->purpose !== $data['purpose'] || $policy->effective_at->isFuture()) {
                throw ValidationException::withMessages(['policyVersionId' => ['Choose a published policy for this purpose.']]);
            }
            $latest = $user->consents()->where('purpose', $data['purpose'])->orderByDesc('id')->first();
            if ($data['decision'] === 'withdrawn') {
                if (! in_array($data['purpose'], config('privacy.withdrawable_purposes'), true)) {
                    throw ValidationException::withMessages(['decision' => ['This purpose is not an optional withdrawable consent.']]);
                }
                if (! $latest || $latest->id !== (int) $data['previousRecordId'] || $latest->policy_version_id !== $policy->id || $latest->decision !== ConsentDecision::Granted) {
                    throw new PrivacyException('INVALID_STATE_TRANSITION', 'Withdrawal must reference your latest granted decision.');
                }
            } elseif ($this->currentPolicy($data['purpose'], $policy->locale)?->id !== $policy->id) {
                throw new PrivacyException('POLICY_VERSION_CHANGED', 'Load the current policy before recording a decision.');
            }
            if ($latest && $latest->policy_version_id === $policy->id && $latest->decision->value === $data['decision']) {
                return [$latest->load('policy'), false];
            }
            $record = new UserConsentRecord;
            $record->forceFill(['user_id' => $user->id, 'purpose' => $data['purpose'], 'policy_version_id' => $policy->id,
                'decision' => $data['decision'], 'previous_record_id' => $latest?->id, 'decided_at' => now(), 'source' => 'api',
                'request_id' => request()->attributes->get('privacy_request_id') ?? (string) Str::uuid()])->save();
            $this->audit->write($user, 'user.consent_recorded', 'consent', $record->id, $latest?->decision->value, $record->decision->value,
                metadata: ['policyVersionId' => $policy->id]);

            return [$record->load('policy'), true];
        });
    }
}
