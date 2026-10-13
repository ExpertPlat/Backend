<?php

namespace App\Http\Front\Presenters;

use App\Http\Front\Front;
use App\Models\CaseAccessGrant;
use App\Models\CaseBrief;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\ExpertOffering;
use App\Models\MatchingRun;
use App\Models\Proposal;

/** Matching runs, Case Briefs and invitations in the v7 shapes. */
final class MatchingPresenter
{
    private const REASONS = [
        'ACTIVE_VERIFIED_SCOPE' => ['ar' => 'معتمد من المنصة في هذا التخصص بعد التحقق من مؤهلاته.', 'en' => 'Verified by the platform for this specialty.'],
        'JURISDICTION_MATCH' => ['ar' => 'يغطي الدولة التي تخص حالتك.', 'en' => 'Covers the country of your case.'],
        'LANGUAGE_MATCH' => ['ar' => 'يتحدث لغة حالتك.', 'en' => 'Speaks the language of your case.'],
        'DELIVERY_MODE_MATCH' => ['ar' => 'يقدّم الخدمة بالطريقة التي تناسبك عن بُعد.', 'en' => 'Offers the service remotely the way you need.'],
        'AVAILABILITY_OPEN' => ['ar' => 'متاح لاستقبال طلبات جديدة الآن.', 'en' => 'Accepting new requests now.'],
    ];

    public static function run(MatchingRun $run, array $stale, ?int $scopeScore = null): array
    {
        $offerings = ExpertOffering::where('catalog_entry_id', $run->inputs['catalogEntryId'] ?? 0)->where('status', ExpertOffering::PUBLISHED)->get()->keyBy('expert_id');
        $rows = $run->candidates()->where('eligible', true)->with(['expert.profile', 'expert.availability'])->orderBy('rank')->get();
        ExpertPresenter::warm($rows->pluck('expert_id')->unique()->values()->all());
        $base = $scopeScore ?? 90;
        $candidates = $rows->map(function ($c) use ($run, $offerings, $base) {
            $o = $offerings->get($c->expert_id);
            $reasons = [];
            if ($o?->summary) {
                $reasons[] = $o->summary;
            }
            foreach (['ACTIVE_VERIFIED_SCOPE', 'JURISDICTION_MATCH', 'LANGUAGE_MATCH', 'AVAILABILITY_OPEN'] as $code) {
                if (in_array($code, $c->match_reasons ?? [], true) && count($reasons) < 3) {
                    $reasons[] = self::REASONS[$code];
                }
            }
            $expert = ExpertPresenter::public($c->expert, true);
            $expert['priceFromMinor'] = $o?->price_from_minor ?? ExpertPresenter::typicalPrice($c->expert_id);
            $expert['currency'] = $o?->currency ?? 'AED';

            return ['candidateId' => $c->id, 'expertId' => $c->expert_id, 'scopeId' => $run->scope_id, 'rank' => $c->rank, 'jurisdictionCode' => $c->jurisdiction_code,
                'matchScore' => max(50, $base - ($c->rank - 1) * 3), 'reasons' => $reasons, 'matchReasons' => $c->match_reasons, 'expert' => $expert,
                'offering' => $o ? ['id' => $o->id, 'summary' => $o->summary, 'deliveryModes' => $o->delivery_modes, 'languages' => $o->languages] : null];
        })->values()->all();

        return ['id' => $run->id, 'caseId' => $run->case_id, 'scopeId' => $run->scope_id, 'stale' => $stale !== [], 'staleReasons' => $stale,
            'createdAt' => Front::iso($run->created_at), 'summary' => ['candidates' => count($candidates), 'eligibleCount' => $run->eligible_count, 'excludedCount' => $run->excluded_count],
            'nextActions' => $candidates ? ['preview_brief', 'invite_expert'] : ['retry_later', 'contact_support'], 'candidates' => $candidates];
    }

    /** Questions + chosen answers in the brief payload, as label/answer pairs. */
    private static function answers(array $payload): array
    {
        return array_values(array_map(fn ($f) => ['id' => $f['key'], 'label' => $f['question'], 'answer' => $f['answer']], $payload['followUps'] ?? []));
    }

    public static function preview(array $built): array
    {
        $p = $built['payload'];

        return ['summary' => $p['summary'] ?? $p['problemDescription'] ?? null, 'facts' => $p['facts'] ?? [], 'answers' => self::answers($p),
            'documents' => $p['documents'] ?? [], 'contentHash' => $built['hash'], 'caseVersion' => $built['caseVersion'], 'sharedWithExpert' => ['summary', 'answers', 'scope']];
    }

    public static function brief(CaseBrief $b): array
    {
        $p = $b->payload;

        return ['id' => $b->id, 'caseId' => $b->case_id, 'scopeId' => $b->scope_id, 'status' => 'approved', 'approvedAt' => Front::iso($b->approved_at),
            'documentIds' => array_values(array_map(fn ($d) => $d['documentId'], $p['documents'] ?? [])), 'contentHash' => $b->content_hash,
            'summary' => $p['summary'] ?? $p['problemDescription'] ?? null, 'facts' => $p['facts'] ?? [], 'answers' => self::answers($p)];
    }

    private static function grants(CaseInvitation $i): array
    {
        return CaseAccessGrant::where('invitation_id', $i->id)->whereNull('revoked_at')->pluck('document_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function forUser(CaseInvitation $i): array
    {
        $i->loadMissing(['expert.profile', 'expert.availability']);

        return ['id' => $i->id, 'version' => $i->version, 'caseId' => $i->case_id, 'scopeId' => $i->scope_id, 'status' => $i->status, 'message' => $i->message ?? '',
            'sentAt' => Front::iso($i->created_at), 'respondedAt' => Front::iso($i->responded_at), 'expiresAt' => Front::iso($i->expires_at),
            'proposalDueAt' => Front::iso($i->proposal_due_at), 'expiryReason' => $i->expiry_reason, 'declineReasonCode' => $i->decline_reason_code,
            'expert' => ExpertPresenter::public($i->expert), 'documentGrants' => self::grants($i)];
    }

    /** The Expert sees a minimum summary until accepting, then the approved brief. */
    public static function forExpert(CaseInvitation $i): array
    {
        $i->loadMissing(['scope.catalogVersion', 'caseRecord.currentIntake', 'caseRecord.owner', 'brief']);
        $case = $i->caseRecord;
        $intake = $case->currentIntake->payload ?? [];
        $v = $i->scope->catalogVersion;
        $accepted = $i->status === CaseInvitation::ACCEPTED || Proposal::where('invitation_id', $i->id)->exists();
        $out = ['id' => $i->id, 'version' => $i->version, 'status' => $i->status, 'message' => $i->message ?? '', 'sentAt' => Front::iso($i->created_at),
            'respondedAt' => Front::iso($i->responded_at), 'expiresAt' => Front::iso($i->expires_at), 'proposalDueAt' => Front::iso($i->proposal_due_at), 'caseId' => $i->case_id,
            'client' => ['firstName' => self::firstName($case->owner?->name)],
            'caseSummary' => ['title' => $intake['title'] ?? null, 'specialty' => ['code' => $v->policy['domain'], 'label' => \App\Services\Front\CaseAnalyzer::label($v)],
                'caseCountry' => $intake['jurisdiction'] ?? null, 'language' => $intake['language'] ?? null, 'urgency' => $intake['urgency'] ?? null,
                'deliveryMode' => $i->scope->delivery_mode],
            'brief' => null];
        if ($accepted && $i->brief) {
            $p = $i->brief->payload;
            $out['brief'] = ['summary' => $p['summary'] ?? $p['problemDescription'] ?? null, 'facts' => $p['facts'] ?? [],
                'questions' => self::answers($p), 'desiredOutcome' => $p['desiredOutcome'] ?? null,
                'documents' => array_values(array_map(fn ($d) => ['id' => $d['documentId'], 'title' => $d['title'], 'size' => $d['size'], 'mimeType' => $d['mimeType']], $p['documents'] ?? []))];
        }

        return $out;
    }

    public static function firstName(?string $name): string
    {
        return preg_split('/\s+/u', trim((string) $name))[0] ?? '';
    }

    public static function scopeScore(CaseRecord $case, int $scopeId): ?int
    {
        $scope = $case->serviceScopes()->find($scopeId);

        return $scope ? ($case->analysis?->scope_notes[$scope->catalog_version_id]['score'] ?? null) : null;
    }
}
