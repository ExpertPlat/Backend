<?php

namespace App\Services\Privacy;

use App\Models\Admin;
use App\Models\AuditEvent;
use App\Models\Expert;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuditWriter
{
    public function write(User|Admin|Expert|null $actor, string $action, string $subject, int $id, ?string $from = null, ?string $to = null, ?string $reason = null, array $metadata = []): AuditEvent
    {
        $type = match (true) {
            $actor instanceof User => 'user', $actor instanceof Admin => 'admin',
            $actor instanceof Expert => 'expert', default => 'system',
        };
        $token = $actor?->currentAccessToken();
        $allowed = array_intersect_key($metadata, array_flip(['changedFields', 'fromVersion', 'toVersion', 'policyVersionId', 'itemCount', 'provider', 'model', 'purpose', 'matchingRunId', 'invitationId', 'briefId', 'eligibleCount', 'candidateCount', 'proposalId', 'proposalVersion', 'agreementId', 'bookingId', 'reviewId', 'rating']));
        $event = new AuditEvent;
        $event->forceFill([
            'event_id' => (string) Str::uuid(), 'actor_type' => $type, 'actor_id' => $actor?->getKey(),
            'actor_token_id' => $token instanceof PersonalAccessToken ? $token->getKey() : null,
            'action' => $action, 'subject_type' => $subject, 'subject_id' => $id,
            'previous_state' => $from, 'new_state' => $to, 'reason_code' => $reason,
            'request_id' => request()->attributes->get('privacy_request_id') ?? (string) Str::uuid(),
            'occurred_at' => now(), 'metadata' => $allowed ?: null,
        ])->save();

        return $event;
    }
}
