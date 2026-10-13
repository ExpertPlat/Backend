<?php

namespace App\Http\Controllers\Api\Front;

use App\Exceptions\PrivacyException;
use App\Http\Controllers\Controller;
use App\Http\Front\Front;
use App\Http\Front\FrontResponses;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\CaseDocument;
use App\Models\CaseInvitation;
use App\Models\CatalogNode;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Cases\CaseDocumentStorage;
use App\Services\Front\CaseAnalyzer;
use App\Services\Privacy\AuditWriter;
use App\Services\Cases\CaseWorkflow;
use App\Enums\CaseDocumentScanStatus;
use App\Models\CaseDocumentVersion;
use App\Jobs\Cases\ScanCaseDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Public catalog lists, in-app notifications (User and Expert) and case documents, in the v7 shapes. */
class SharedController extends Controller
{
    use FrontResponses;

    /* ───────── catalog ───────── */

    public function countries(): JsonResponse
    {
        return $this->ok(CatalogNode::where('kind', 'country')->where('status', 'enabled')->orderBy('code')->get()->map(fn ($n) => ['code' => $n->code, 'name' => $n->labels])->all());
    }

    public function specialties(CaseAnalyzer $analyzer): JsonResponse
    {
        return $this->ok($analyzer->catalog()->map(fn ($v) => ['code' => $v->policy['domain'], 'catalogEntryId' => $v->entry_id, 'icon' => CaseAnalyzer::icon($v->policy['domain']), 'label' => CaseAnalyzer::label($v)])
            ->unique('code')->values()->all());
    }

    public function deliveryModes(): JsonResponse
    {
        return $this->ok(CatalogNode::where('kind', 'delivery_mode')->where('status', 'enabled')->orderBy('id')->get()->map(fn ($n) => ['code' => $n->code, 'label' => $n->labels, 'remote' => true])->all());
    }

    /* ───────── notifications ───────── */

    private const TITLES = [
        'invitation.created' => ['ar' => 'وصلتك دعوة جديدة لحالة', 'en' => 'You received a new case invitation'],
        'invitation.accepted' => ['ar' => 'قبل الخبير دعوتك', 'en' => 'The expert accepted your invitation'],
        'invitation.declined' => ['ar' => 'اعتذر الخبير عن دعوتك', 'en' => 'The expert declined your invitation'],
        'invitation.expiring' => ['ar' => 'دعوة على وشك الانتهاء', 'en' => 'An invitation is about to expire'],
        'invitation.expired' => ['ar' => 'انتهت مهلة دعوة', 'en' => 'An invitation expired'],
        'invitation.revoked' => ['ar' => 'أُلغيت دعوة', 'en' => 'An invitation was revoked'],
        'proposal.submitted' => ['ar' => 'وصلك عرض جديد من خبير', 'en' => 'A new proposal arrived'],
        'proposal.revised' => ['ar' => 'أرسل الخبير عرضًا معدّلًا', 'en' => 'The expert sent a revised proposal'],
        'proposal.change_requested' => ['ar' => 'طلب العميل تعديل عرضك', 'en' => 'The client asked for changes to your proposal'],
        'proposal.accepted' => ['ar' => 'قبل العميل عرضك وبانتظار التمويل', 'en' => 'The client accepted your proposal; awaiting funding'],
        'proposal.rejected' => ['ar' => 'اعتذر العميل عن عرضك', 'en' => 'The client declined your proposal'],
        'proposal.withdrawn' => ['ar' => 'سحب الخبير عرضه', 'en' => 'The expert withdrew the proposal'],
        'proposal.expired' => ['ar' => 'انتهت صلاحية عرض', 'en' => 'A proposal expired'],
        'agreement.cancelled' => ['ar' => 'أُلغي الاتفاق', 'en' => 'The agreement was cancelled'],
        'funding.confirmed' => ['ar' => 'تم تمويل الاتفاق، يمكن حجز الجلسة الآن', 'en' => 'The agreement is funded; the session can be booked'],
        'funding.failed' => ['ar' => 'تعذّر إتمام الدفع', 'en' => 'The payment did not go through'],
        'funding.expired' => ['ar' => 'انتهت مهلة الدفع', 'en' => 'The payment window closed'],
        'refund.completed' => ['ar' => 'تم الاسترداد', 'en' => 'The refund was completed'],
        'booking.confirmed' => ['ar' => 'جلسة جديدة مؤكدة', 'en' => 'A session was confirmed'],
        'booking.cancelled' => ['ar' => 'أُلغيت جلسة', 'en' => 'A session was cancelled'],
        'booking.rescheduled' => ['ar' => 'تغيّر موعد جلسة', 'en' => 'A session was moved'],
    ];

    public static function notification($n): array
    {
        $d = $n->data ?? [];
        $type = (string) ($d['type'] ?? 'notice');
        [$kind, $id, $caseId] = match (true) {
            isset($d['bookingId']) => ['booking', (int) $d['bookingId'], Booking::whereKey($d['bookingId'])->value('case_id')],
            isset($d['agreementId']) && ! str_starts_with($type, 'proposal.') => ['agreement', (int) $d['agreementId'], $d['caseId'] ?? Agreement::whereKey($d['agreementId'])->value('case_id')],
            isset($d['proposalId']) => [$type === 'proposal.accepted' && isset($d['agreementId']) ? 'agreement' : 'proposal', (int) ($type === 'proposal.accepted' && isset($d['agreementId']) ? $d['agreementId'] : $d['proposalId']), $d['caseId'] ?? Proposal::whereKey($d['proposalId'])->value('case_id')],
            isset($d['invitationId']) => ['invitation', (int) $d['invitationId'], CaseInvitation::whereKey($d['invitationId'])->value('case_id')],
            isset($d['fundingId']) => ['agreement', (int) ($d['agreementId'] ?? 0), $d['caseId'] ?? null],
            default => ['notice', null, $d['caseId'] ?? null],
        };
        $fallback = ['ar' => 'تحديث جديد على حسابك', 'en' => 'A new update on your account'];

        return ['id' => $n->id, 'type' => $type, 'title' => self::TITLES[$type] ?? $fallback, 'unread' => $n->read_at === null, 'createdAt' => Front::iso($n->created_at),
            'target' => ['kind' => $kind, 'id' => $id, 'caseId' => $caseId ? (int) $caseId : null]];
    }

    public function notifications(Request $r): JsonResponse
    {
        return $this->ok($r->user()->notifications()->latest()->limit(100)->get()->map(fn ($n) => self::notification($n))->all());
    }

    public function readNotification(Request $r, string $notification): JsonResponse
    {
        $n = $r->user()->notifications()->whereKey($notification)->firstOrFail();
        $n->markAsRead();

        return $this->ok(self::notification($n->fresh()));
    }

    /* ───────── case documents (/api/user/cases/{case}/documents) ───────── */

    public static function document(CaseDocument $d): array
    {
        $v = $d->currentVersion;

        return ['id' => $d->id, 'caseId' => $d->case_id, 'currentVersion' => $v ? ['id' => $v->id, 'version' => $v->version, 'title' => $v->title, 'category' => $v->category,
            'mimeType' => $v->mime_type, 'size' => $v->size, 'scanStatus' => $v->scan_status->value, 'createdAt' => Front::iso($v->created_at)] : null];
    }

    private function owned(Request $r, int $case)
    {
        $u = $r->user();
        abort_unless($u instanceof User, 403);

        return $u->cases()->findOrFail($case);
    }

    public function documents(Request $r, int $case): JsonResponse
    {
        return $this->ok($this->owned($r, $case)->documents()->whereNull('deleted_at')->with('currentVersion')->orderBy('id')->get()->map(fn ($d) => self::document($d))->all());
    }

    /**
     * v7 upload: { expectedVersion, title, category, file }. Uploading does not bump the Case version (the page keeps
     * working with the version it has); before submission it only resets the readiness confirmation.
     */
    public function upload(Request $r, int $case, CaseDocumentStorage $storage, AuditWriter $audit): JsonResponse
    {
        $d = $r->validate(['title' => ['sometimes', 'nullable', 'string', 'max:160'], 'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.config('case_intake.max_file_kib')]]);
        $file = $r->file('file');
        $category = in_array($d['category'] ?? null, ['supporting', 'reference', 'work_sample'], true) ? $d['category'] : 'supporting';
        $doc = DB::transaction(function () use ($r, $case, $d, $file, $storage, $category, $audit) {
            $u = $r->user();
            User::query()->whereKey($u->id)->lockForUpdate()->firstOrFail();
            $c = $u->cases()->whereKey($case)->lockForUpdate()->firstOrFail();
            if ($c->status === \App\Enums\CaseStatus::Cancelled) {
                throw new PrivacyException('INVALID_CASE_TRANSITION', 'This case is cancelled.');
            }
            if ($c->documents()->whereNull('deleted_at')->count() >= config('case_intake.max_documents')) {
                throw ValidationException::withMessages(['file' => ['The active document limit has been reached.']]);
            }
            $record = new CaseDocument;
            $record->forceFill(['case_id' => $c->id])->save();
            $stored = $storage->store($file);
            $version = new CaseDocumentVersion;
            $version->forceFill($stored + ['document_id' => $record->id, 'version' => 1, 'title' => trim((string) ($d['title'] ?? '')) ?: mb_substr($file->getClientOriginalName(), 0, 160),
                'category' => $category, 'scan_status' => CaseDocumentScanStatus::Pending])->save();
            $record->forceFill(['current_version_id' => $version->id])->save();
            if ($c->submitted_at === null) {
                $c->forceFill(['readiness_confirmation' => null, 'confirmed_at' => null])->save();
            }
            $audit->write($u, 'case.document_uploaded', 'case', $c->id);
            ScanCaseDocument::dispatch($version->id)->afterCommit();

            return $record;
        });

        return $this->ok(self::document($doc->fresh('currentVersion')), 'Uploaded. It is being scanned.', 201);
    }

    public function deleteDocument(Request $r, int $case, int $document, AuditWriter $audit): JsonResponse
    {
        DB::transaction(function () use ($r, $case, $document, $audit) {
            $c = $this->owned($r, $case);
            $record = $c->documents()->whereNull('deleted_at')->lockForUpdate()->findOrFail($document);
            foreach ($c->serviceScopes()->whereNotNull('submitted_at')->get() as $scope) {
                if (collect($scope->policy_snapshot['documentVersions'] ?? [])->contains('documentId', $record->id)) {
                    throw new PrivacyException('SUBMITTED_DOCUMENT_IMMUTABLE', 'A submitted service references this document.');
                }
            }
            if (\App\Models\CaseAccessGrant::where('document_id', $record->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw new PrivacyException('DOCUMENT_SHARED', Front::t(['ar' => 'هذا المستند مشارك مع خبير. ألغِ المشاركة أولًا.', 'en' => 'This document is shared with an expert. Revoke the share first.']));
            }
            $record->forceFill(['deleted_at' => now()])->save();
            if ($c->submitted_at === null) {
                $c->forceFill(['readiness_confirmation' => null, 'confirmed_at' => null])->save();
            }
            $audit->write($r->user(), 'case.document_removed', 'case', $c->id);
        });

        return $this->ok(null, 'Document removed.');
    }
}
