<?php

namespace App\Http\Front\Presenters;

use App\Enums\CaseStatus;
use App\Http\Front\Front;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Services\Front\CaseAnalyzer;

/** Case resources exactly as the v7 frontend reads them (see services/mock/presenters.js presentCase). */
final class CasePresenter
{
    public static function status(CaseRecord $c): string
    {
        return $c->status === CaseStatus::Cancelled ? 'cancelled' : (string) $c->readiness_status;
    }

    public static function title(CaseRecord $c): ?string
    {
        $intake = $c->currentIntake?->payload ?? [];

        return $intake['title'] ?? null;
    }

    public static function listItem(CaseRecord $c): array
    {
        $intake = $c->currentIntake?->payload ?? [];

        return ['id' => $c->id, 'status' => self::status($c), 'version' => $c->version, 'title' => $intake['title'] ?? null,
            'caseCountry' => $intake['jurisdiction'] ?? $c->jurisdiction, 'updatedAt' => Front::iso($c->updated_at)];
    }

    public static function case(CaseRecord $c): array
    {
        $c->loadMissing(['currentIntake', 'analysis', 'serviceScopes.catalogVersion']);
        $intake = $c->currentIntake?->payload ?? [];
        $a = $c->analysis;
        $notes = $a?->scope_notes ?? [];
        $scopes = $c->serviceScopes->whereNull('detached_at')->sortBy('id')->values()->map(fn (CaseServiceScope $s) => self::scope($s, $notes[$s->catalog_version_id] ?? null))->all();

        return [
            'id' => $c->id, 'status' => self::status($c), 'readinessStatus' => self::status($c), 'version' => $c->version,
            'title' => $intake['title'] ?? null, 'language' => $intake['language'] ?? null, 'residence' => $intake['residence'] ?? null,
            'caseCountry' => $intake['jurisdiction'] ?? $c->jurisdiction, 'urgency' => $intake['urgency'] ?? null, 'story' => $intake['problemDescription'] ?? null,
            'summary' => $a?->summary, 'facts' => $a?->facts ?? [], 'scopes' => $scopes, 'aiQuestionSetId' => $a?->id,
            'answers' => (object) ($a?->answers ?? []), 'confirmedAt' => Front::iso($c->confirmed_at), 'submittedAt' => Front::iso($c->submitted_at),
            'createdAt' => Front::iso($c->created_at), 'updatedAt' => Front::iso($c->updated_at),
        ];
    }

    public static function scope(CaseServiceScope $s, ?array $note = null): array
    {
        $v = $s->catalogVersion;
        $p = $v->policy;

        return ['id' => $s->id, 'code' => $p['domain'], 'icon' => CaseAnalyzer::icon($p['domain']), 'label' => CaseAnalyzer::label($v),
            'why' => $note['why'] ?? null, 'priority' => $note['priority'] ?? 'medium', 'score' => $note['score'] ?? null, 'included' => (bool) $s->confirmed,
            'isGeneral' => CaseAnalyzer::isGeneral($v), 'catalogEntryId' => $v->entry_id, 'catalogVersion' => $v->version, 'deliveryMode' => $s->delivery_mode, 'jurisdictionCodes' => $s->jurisdiction_codes ?? [],
            'status' => $s->status, 'submittedAt' => Front::iso($s->submitted_at)];
    }

    public static function questionSet(CaseRecord $c): array
    {
        $a = $c->analysis;

        return ['id' => $a?->id, 'version' => $a?->question_version ?? 1, 'questions' => $a?->questions ?? [], 'answers' => (object) ($a?->answers ?? [])];
    }
}
