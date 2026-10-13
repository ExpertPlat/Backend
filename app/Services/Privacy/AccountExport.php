<?php

namespace App\Services\Privacy;

use App\Http\Resources\User\Privacy\PreferenceResource;
use App\Models\User;

class AccountExport
{
    public function __construct(private readonly ProfileManager $profiles) {}

    public function build(User $user): array
    {
        $captured = now()->toISOString();

        return [
            'manifest' => ['formatVersion' => 'solveit.account-export.v1', 'generatedAt' => $captured,
                'scope' => 'current_user_account', 'sections' => config('data_rights.sections'),
                'snapshotSemantics' => 'best_effort_with_immutable_version_references',
                'exclusions' => ['credentials_and_tokens', 'other_account_types', 'kyc', 'security_audit_metadata', 'case_data_outside_export_v1']],
            'account' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'createdAt' => $user->created_at?->toISOString()],
            'profile' => ['current' => $this->profiles->snapshot($user), 'versions' => $user->profileVersions()->orderBy('version')->get()
                ->map(fn ($v) => ['version' => $v->version, 'snapshot' => $v->snapshot, 'createdAt' => $v->created_at->toISOString()])->all()],
            'preferences' => (new PreferenceResource($this->profiles->preferences($user)))->resolve(),
            'contexts' => $user->contexts()->with('versions')->orderBy('id')->get()->map(fn ($c) => [
                'id' => $c->id, 'domain' => $c->domain, 'country' => $c->country, 'status' => $c->status->value,
                'versions' => $c->versions->sortBy('version')->map(fn ($v) => ['version' => $v->version, 'schemaVersion' => $v->schema_version,
                    'payload' => $v->payload, 'conflictStatus' => $v->conflict_status, 'clarification' => $v->clarification,
                    'createdAt' => $v->created_at->toISOString()])->values()->all(),
            ])->all(),
            'consents' => $user->consents()->with('policy')->orderBy('id')->get()->map(fn ($c) => [
                'id' => $c->id, 'purpose' => $c->purpose, 'decision' => $c->decision->value,
                'policyVersion' => $c->policy->version, 'policyLocale' => $c->policy->locale,
                'policyHash' => $c->policy->content_hash, 'decidedAt' => $c->decided_at->toISOString(),
            ])->all(),
            'dataRequests' => $user->dataRequests()->orderBy('id')->get()->map(fn ($r) => [
                'reference' => $r->reference, 'type' => $r->type->value, 'status' => $r->status->value,
                'requestedAt' => $r->requested_at->toISOString(), 'dueAt' => $r->due_at->toISOString(),
                'reasonCode' => $r->reason_code, 'outcome' => $r->outcome,
            ])->all(),
        ];
    }
}
