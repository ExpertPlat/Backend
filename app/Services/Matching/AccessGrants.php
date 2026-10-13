<?php

namespace App\Services\Matching;

use App\Enums\CaseDocumentScanStatus;
use App\Exceptions\PrivacyException;
use App\Models\CaseAccessGrant;
use App\Models\CaseDocumentVersion;
use App\Models\CaseInvitation;
use App\Models\Expert;
use App\Models\User;
use App\Services\Cases\CaseDocumentStorage;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Document-version-specific, expiring access for an invited Expert. Never case-wide, never storage paths. */
class AccessGrants
{
    public function __construct(private AuditWriter $audit, private CaseDocumentStorage $storage) {}

    /** @param list<int> $versionIds @return Collection<int, CaseAccessGrant> */
    public function grant(User $user, int $caseId, int $invitationId, array $versionIds): Collection
    {
        return DB::transaction(function () use ($user, $caseId, $invitationId, $versionIds) {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $inv = CaseInvitation::where('case_id', $case->id)->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($inv->status !== CaseInvitation::ACCEPTED || ! $inv->proposal_due_at?->isFuture()) {
                throw new PrivacyException('INVITATION_NOT_ACCEPTED', 'Document access can only be granted to an Expert who accepted an open invitation.');
            }
            $brief = $inv->brief;
            $granted = collect();
            foreach (array_values(array_unique($versionIds)) as $versionId) {
                if (! in_array($versionId, $brief->document_version_ids, true)) {
                    throw new PrivacyException('DOCUMENT_NOT_IN_BRIEF', 'Only documents included in the approved Case Brief can be granted.', 422);
                }
                $version = CaseDocumentVersion::findOrFail($versionId);
                $document = $case->documents()->whereNull('deleted_at')->find($version->document_id);
                if (! $document || $version->scan_status !== CaseDocumentScanStatus::Clean) {
                    throw new PrivacyException('DOCUMENT_NOT_READY', 'Only documents that passed the security scan can be shared.');
                }
                $existing = CaseAccessGrant::where(['invitation_id' => $inv->id, 'document_version_id' => $version->id, 'status' => CaseAccessGrant::ACTIVE])->first();
                if ($existing) {
                    $granted->push($existing);

                    continue;
                }
                $grant = new CaseAccessGrant;
                $grant->forceFill(['invitation_id' => $inv->id, 'case_id' => $case->id, 'expert_id' => $inv->expert_id, 'document_id' => $document->id, 'document_version_id' => $version->id,
                    'brief_id' => $brief->id, 'granted_by_user_id' => $user->id, 'status' => CaseAccessGrant::ACTIVE, 'active_key' => $inv->id.':'.$version->id,
                    'granted_at' => now(), 'expires_at' => $inv->proposal_due_at])->save();
                $this->audit->write($user, 'case.document_access_granted', 'case_access_grant', $grant->id, null, 'active', metadata: ['invitationId' => $inv->id]);
                $granted->push($grant);
            }

            return $granted;
        });
    }

    public function revoke(User $user, int $caseId, int $invitationId, int $grantId): CaseAccessGrant
    {
        return DB::transaction(function () use ($user, $caseId, $invitationId, $grantId) {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $grant = CaseAccessGrant::where(['case_id' => $case->id, 'invitation_id' => $invitationId])->whereKey($grantId)->lockForUpdate()->firstOrFail();
            if ($grant->status !== CaseAccessGrant::ACTIVE) {
                throw new PrivacyException('INVALID_STATE_TRANSITION', 'This access grant is no longer active.');
            }
            $this->close($grant, CaseAccessGrant::REVOKED, 'USER_REVOKED', $user);

            return $grant;
        });
    }

    /** Revoke every active grant of an invitation (decline/expire/revoke/case cancellation). */
    public function revokeForInvitation(CaseInvitation $inv, string $reason, ?User $actor = null): void
    {
        foreach (CaseAccessGrant::where('invitation_id', $inv->id)->where('status', CaseAccessGrant::ACTIVE)->lockForUpdate()->get() as $grant) {
            $this->close($grant, CaseAccessGrant::REVOKED, $reason, $actor);
        }
    }

    /** Mark grants whose time elapsed as expired (scheduler). */
    public function expireElapsed(): int
    {
        $count = 0;
        foreach (CaseAccessGrant::where('status', CaseAccessGrant::ACTIVE)->where('expires_at', '<=', now())->lockForUpdate()->get() as $grant) {
            $this->close($grant, CaseAccessGrant::EXPIRED, 'GRANT_EXPIRED');
            $count++;
        }

        return $count;
    }

    private function close(CaseAccessGrant $grant, string $status, string $reason, ?User $actor = null): void
    {
        $grant->forceFill(['status' => $status, 'active_key' => null, 'revoked_at' => now(), 'revoke_reason' => $reason])->save();
        $this->audit->write($actor, $status === CaseAccessGrant::EXPIRED ? 'case.document_access_expired' : 'case.document_access_revoked', 'case_access_grant', $grant->id, 'active', $status, $reason, ['invitationId' => $grant->invitation_id]);
    }

    /**
     * Authorize + audit an Expert file download. Object access is checked before the feature flag so that other
     * Experts never learn anything. Returns raw bytes and the version for the response headers.
     *
     * @return array{bytes: string, version: CaseDocumentVersion}
     */
    public function download(Expert $expert, int $invitationId, int $grantId): array
    {
        $inv = $expert->invitations()->whereKey($invitationId)->firstOrFail();
        $grant = CaseAccessGrant::where(['invitation_id' => $inv->id, 'expert_id' => $expert->id])->whereKey($grantId)->firstOrFail();
        if ($inv->status !== CaseInvitation::ACCEPTED || ! $inv->proposal_due_at?->isFuture()) {
            throw new PrivacyException('INVITATION_NOT_ACCEPTED', 'This invitation is not open.');
        }
        if (! $grant->isUsable() || ! in_array($grant->document_version_id, $inv->brief->document_version_ids, true)) {
            throw new PrivacyException('ACCESS_GRANT_REQUIRED', 'An active document access grant is required.', 403);
        }
        $version = CaseDocumentVersion::findOrFail($grant->document_version_id);
        if ($version->scan_status !== CaseDocumentScanStatus::Clean || ! $inv->caseRecord->documents()->whereNull('deleted_at')->whereKey($grant->document_id)->exists()) {
            throw new PrivacyException('DOCUMENT_NOT_READY', 'This document is not available.');
        }
        if (! config('matching.document_downloads_enabled')) {
            throw new PrivacyException('DOCUMENT_DELIVERY_NOT_ENABLED', 'Document file delivery is not enabled in this environment.', 503);
        }
        $bytes = $this->storage->bytes($version);
        $this->audit->write($expert, 'case.document_downloaded_by_expert', 'case_access_grant', $grant->id, reason: 'AUTHORIZED_GRANT', metadata: ['invitationId' => $inv->id]);

        return ['bytes' => $bytes, 'version' => $version];
    }
}
