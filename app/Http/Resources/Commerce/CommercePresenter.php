<?php

namespace App\Http\Resources\Commerce;

use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Expert;
use App\Models\Funding;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Serializers for the Sprint 4 contract. Money is always integer minor units + ISO currency + exponent. Times are UTC
 * ("...Z") plus a presentation block in the viewing actor's timezone. Nothing here exposes payment state beyond
 * `awaiting_payment`, storage details, or the other party's private data.
 */
final class CommercePresenter
{
    public static function money(int $amountMinor, string $currency): array
    {
        return ['amountMinor' => $amountMinor, 'currency' => $currency, 'minorUnitExponent' => Money::exponent($currency)];
    }

    public static function utc(?CarbonInterface $t): ?string
    {
        return $t?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function timezoneOf(User|Expert $actor): string
    {
        $tz = $actor instanceof Expert ? $actor->availability?->timezone : $actor->profile?->timezone;

        return $tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    public static function version(ProposalVersion $v): array
    {
        $v->loadMissing('milestones');
        $c = $v->content;

        return ['versionNo' => $v->version_no, 'contentHash' => $v->content_hash, 'createdAt' => self::utc($v->created_at), 'validUntil' => self::utc($v->valid_until),
            'scopeSummary' => $c['scopeSummary'], 'inputsRequired' => $c['inputsRequired'], 'deliverables' => $c['deliverables'], 'exclusions' => $c['exclusions'],
            'clarificationAllowance' => $c['clarificationAllowance'], 'reviewPeriodHours' => $c['reviewPeriodHours'], 'consultationMinutes' => $c['consultationMinutes'],
            'currency' => $v->currency, 'totalAmountMinor' => $v->total_amount_minor, 'minorUnitExponent' => Money::exponent($v->currency),
            'milestones' => $v->milestones->map(fn ($m) => ['sequence' => $m->sequence, 'title' => $m->title, 'description' => $m->description, 'amountMinor' => $m->amount_minor, 'dueAt' => self::utc($m->due_at)])->values()->all()];
    }

    public static function proposal(Proposal $p, bool $detail = false, bool $forUser = true): array
    {
        $p->loadMissing('versions.milestones', 'expert.profile');
        $current = $p->versions->firstWhere('version_no', $p->current_version_no);
        $item = ['id' => $p->id, 'status' => $p->status, 'version' => $p->version, 'currentVersionNo' => $p->current_version_no, 'invitationId' => $p->invitation_id,
            'validUntil' => self::utc($p->valid_until), 'closedAt' => self::utc($p->closed_at), 'createdAt' => self::utc($p->created_at),
            'currentVersion' => $current ? self::version($current) : null];
        if ($forUser) {
            $item += ['caseId' => $p->case_id, 'scopeId' => $p->scope_id, 'expert' => ['slug' => $p->expert->profile?->slug, 'name' => $p->expert->name, 'professionalTitle' => $p->expert->profile?->professional_title]];
        }
        if ($detail) {
            $item['versions'] = $p->versions->map(fn ($v) => self::version($v))->values()->all();
            $item['changeRequests'] = $p->changeRequests()->get()->map(fn ($r) => ['id' => $r->id, 'versionNo' => $r->version_no, 'categories' => $r->categories, 'message' => $r->message, 'status' => $r->status,
                'resolvedByVersion' => $r->resolved_by_version, 'createdAt' => self::utc($r->created_at)])->values()->all();
            $item['events'] = $p->events()->get()->map(fn ($e) => ['type' => $e->type, 'versionNo' => $e->version_no, 'actorType' => $e->actor_type, 'reasonCode' => $e->reason_code, 'createdAt' => self::utc($e->created_at)])->values()->all();
            $item['agreementId'] = $p->agreement?->id;
        }

        return $item;
    }

    public static function agreement(Agreement $a, bool $detail = false, bool $forUser = true): array
    {
        $item = ['id' => $a->id, 'status' => $a->status, 'version' => $a->version, 'proposalId' => $a->proposal_id, 'proposalVersion' => $a->proposal_version_no, 'invitationId' => $a->invitation_id,
            'currency' => $a->currency, 'totalAmountMinor' => $a->total_amount_minor, 'minorUnitExponent' => Money::exponent($a->currency), 'consultationMinutes' => $a->consultation_minutes,
            'snapshotHash' => $a->snapshot_hash, 'acceptedAt' => self::utc($a->accepted_at), 'cancelledAt' => self::utc($a->cancelled_at), 'cancelReasonCode' => $a->cancel_reason_code,
            // Label of the commercial state. `funded` is set only by provider-confirmed payment (Sprint 5); never by a client.
            'funding' => ['state' => in_array($a->status, ['awaiting_payment', 'funded', 'funding_expired'], true) ? $a->status : 'none',
                'fundedAt' => $a->status === 'funded' ? self::utc(Funding::where('agreement_id', $a->id)->where('status', 'funded')->value('funded_at')) : null]];
        if ($forUser) {
            $item += ['caseId' => $a->case_id, 'scopeId' => $a->scope_id];
        }
        if ($detail) {
            $item['milestones'] = $a->milestones()->get()->map(fn ($m) => ['id' => $m->id, 'sequence' => $m->sequence, 'title' => $m->title, 'description' => $m->description, 'amountMinor' => $m->amount_minor,
                'currency' => $m->currency, 'dueAt' => self::utc($m->due_at)])->values()->all();
            $snapshot = $a->snapshot;
            if (! $forUser) {
                unset($snapshot['actors']['userId'], $snapshot['caseId']);
            }
            $item['snapshot'] = $snapshot;
        }

        return $item;
    }

    public static function booking(Booking $b, User|Expert $viewer): array
    {
        $tz = self::timezoneOf($viewer);

        return ['id' => $b->id, 'agreementId' => $b->agreement_id, 'status' => $b->status, 'version' => $b->version, 'startAt' => self::utc($b->start_at), 'endAt' => self::utc($b->end_at),
            'durationMinutes' => $b->duration_minutes, 'holdExpiresAt' => self::utc($b->hold_expires_at), 'confirmedAt' => self::utc($b->confirmed_at), 'cancelledAt' => self::utc($b->cancelled_at),
            'cancelReasonCode' => $b->cancel_reason_code, 'rescheduleCount' => $b->reschedule_count,
            'presentation' => ['timezone' => $tz, 'startLocal' => $b->start_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'endLocal' => $b->end_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i:sP')],
            // A confirmed booking is a calendar reservation only; it never means the work is funded or started.
            'calendarOnly' => true];
    }
}
