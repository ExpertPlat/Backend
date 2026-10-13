<?php

namespace App\Services\Privacy;

use App\Enums\ContextStatus;
use App\Exceptions\PrivacyException;
use App\Models\SpecializedContext;
use App\Models\SpecializedContextVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContextManager
{
    public function __construct(private readonly AuditWriter $audit, private readonly ProfileManager $profiles) {}

    public function create(User $user, array $data): SpecializedContext
    {
        return DB::transaction(function () use ($user, $data): SpecializedContext {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $context = new SpecializedContext;
            $context->forceFill(['user_id' => $user->id, 'domain' => $data['domain'], 'country' => $data['country'],
                'status' => ContextStatus::Active, 'current_version' => 1, 'allow_case_reuse' => $data['allowCaseReuse'] ?? false])->save();
            $this->saveVersion($context, ['title' => $data['title'], 'facts' => $data['facts']], 'none', null);
            $this->audit->write($user, 'user.context_created', 'context', $context->id, null, 'active');

            return $context->load('latestVersion');
        });
    }

    public function change(User $user, int $id, array $data, ?ContextStatus $target = null): SpecializedContext
    {
        return DB::transaction(function () use ($user, $id, $data, $target): SpecializedContext {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $context = $user->contexts()->whereKey($id)->where('status', '!=', 'deleted')->lockForUpdate()->firstOrFail();
            $this->profiles->version($context->current_version, $data['expectedVersion']);
            $previous = $context->latestVersion;
            $oldState = $context->status;
            $payload = $previous->payload;
            $conflict = $previous->conflict_status;
            $clarification = $data['clarification'] ?? null;
            if ($target) {
                $allowed = match ($target) {
                    ContextStatus::Archived => $oldState === ContextStatus::Active,
                    ContextStatus::Active => $oldState === ContextStatus::Archived,
                    ContextStatus::Deleted => in_array($oldState, [ContextStatus::Active, ContextStatus::Archived], true),
                };
                if (! $allowed) {
                    throw new PrivacyException('INVALID_STATE_TRANSITION', 'This context transition is not allowed.');
                }
                $context->status = $target;
                $context->archived_at = $target === ContextStatus::Archived ? now() : null;
                $context->deleted_at = $target === ContextStatus::Deleted ? now() : null;
            } else {
                if ($oldState !== ContextStatus::Active) {
                    throw new PrivacyException('INVALID_STATE_TRANSITION', 'Restore an archived context before editing it.');
                }
                if (isset($data['facts'])) {
                    $old = collect($payload['facts'])->keyBy('key');
                    foreach ($data['facts'] as $fact) {
                        $prior = $old->get($fact['key']);
                        if ($prior && $prior['effectiveDate'] === $fact['effectiveDate'] && $prior['value'] !== $fact['value']) {
                            $conflict = 'unresolved';
                        }
                    }
                    $payload['facts'] = $data['facts'];
                }
                $payload['title'] = $data['title'] ?? $payload['title'];
                if ($clarification !== null) {
                    $conflict = 'clarified';
                }
                $context->allow_case_reuse = $data['allowCaseReuse'] ?? $context->allow_case_reuse;
            }
            if (! $target && ! $context->isDirty() && $payload === $previous->payload && $clarification === null) {
                return $context->load('latestVersion');
            }
            $context->current_version++;
            $context->save();
            $this->saveVersion($context, $payload, $conflict, $clarification);
            $this->audit->write($user, $target ? 'user.context_'.$target->value : 'user.context_updated', 'context', $id,
                $oldState->value, $context->status->value, metadata: ['fromVersion' => $previous->version, 'toVersion' => $context->current_version]);

            return $context->unsetRelation('latestVersion')->load('latestVersion');
        });
    }

    private function saveVersion(SpecializedContext $context, array $payload, string $conflict, ?string $clarification): void
    {
        $payload = array_merge($payload, ['domain' => $context->domain, 'country' => $context->country,
            'status' => $context->status->value, 'allowCaseReuse' => $context->allow_case_reuse]);
        (new SpecializedContextVersion)->forceFill([
            'context_id' => $context->id, 'version' => $context->current_version, 'schema_version' => 1,
            'payload' => $payload, 'conflict_status' => $conflict, 'clarification' => $clarification,
            'supersedes_version' => $context->current_version > 1 ? $context->current_version - 1 : null, 'created_at' => now(),
        ])->save();
    }
}
