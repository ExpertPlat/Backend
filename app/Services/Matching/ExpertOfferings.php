<?php

namespace App\Services\Matching;

use App\Exceptions\PrivacyException;
use App\Models\CatalogEntry;
use App\Models\CatalogVersion;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertOffering;
use App\Services\Catalog\ExpertCoverage;
use App\Services\Privacy\AuditWriter;
use Illuminate\Validation\ValidationException;

/**
 * Expert Offering = "I am currently willing to provide this service through SolveIt".
 * A Verified Scope (via an Expert Catalog Grant) is still required; an Offering never replaces it.
 */
class ExpertOfferings
{
    public function __construct(private ExpertCoverage $coverage, private AuditWriter $audit) {}

    /**
     * Services the Expert may currently offer, derived live from valid grants (never from stored flags).
     *
     * @return array<int, array{entry: CatalogEntry, deliveryModes: list<string>, languages: list<string>, jurisdictionCodes: list<string>, policy: array<string, mixed>}>
     */
    public function eligibleServices(Expert $expert): array
    {
        $grants = ExpertCatalogGrant::whereNull('revoked_at')->whereHas('scope', fn ($q) => $q->where('expert_id', $expert->id))->with('scope')->orderBy('id')->get();
        if ($grants->isEmpty()) {
            return [];
        }
        $versions = CatalogVersion::whereIn('id', $grants->pluck('catalog_version_id')->unique())->with('entry')->get()->keyBy('id');
        $services = [];
        foreach ($grants as $grant) {
            $v = $versions->get($grant->catalog_version_id);
            $s = $grant->scope;
            if (! $v || ! $s || $v->status !== 'enabled' || $v->entry->status !== 'enabled'
                || $grant->evidence_type !== $s->evidence_type || $grant->evidence_id !== $s->evidence_id
                || ! $this->coverage->scopeValid($s, $v->policy, $grant->jurisdiction_code)) {
                continue;
            }
            $row = $services[$v->entry_id] ?? ['entry' => $v->entry, 'deliveryModes' => [], 'languages' => [], 'jurisdictionCodes' => [], 'policy' => $v->policy];
            $row['deliveryModes'] = array_values(array_unique(array_merge($row['deliveryModes'], array_intersect($v->policy['deliveryModes'], $s->service_types ?? []))));
            $row['languages'] = array_values(array_unique(array_merge($row['languages'], $s->languages ?? [])));
            $row['jurisdictionCodes'] = array_values(array_unique(array_merge($row['jurisdictionCodes'], [$grant->jurisdiction_code])));
            $services[$v->entry_id] = $row;
        }
        foreach ($services as $entryId => $row) {
            sort($row['deliveryModes']);
            sort($row['languages']);
            sort($row['jurisdictionCodes']);
            $services[$entryId] = $row;
        }

        return $services;
    }

    /** @return array{eligible: bool, reasonCodes: list<string>} */
    public function eligibility(Expert $expert, ExpertOffering $offering, ?array $services = null): array
    {
        $allowed = ($services ?? $this->eligibleServices($expert))[$offering->catalog_entry_id] ?? null;
        if (! $allowed) {
            return ['eligible' => false, 'reasonCodes' => ['NO_VERIFIED_SCOPE']];
        }
        $reasons = [];
        if (array_diff($offering->delivery_modes, $allowed['deliveryModes'])) {
            $reasons[] = 'DELIVERY_MODE_NOT_SUPPORTED';
        }
        if (array_diff($offering->languages, $allowed['languages'])) {
            $reasons[] = 'LANGUAGE_MISMATCH';
        }
        if ($offering->jurisdiction_codes !== null && array_diff($offering->jurisdiction_codes, $allowed['jurisdictionCodes'])) {
            $reasons[] = 'JURISDICTION_NOT_SUPPORTED';
        }

        return ['eligible' => $reasons === [], 'reasonCodes' => $reasons];
    }

    public function create(Expert $expert, array $d): ExpertOffering
    {
        $allowed = $this->eligibleServices($expert)[(int) $d['catalogEntryId']] ?? throw new PrivacyException('NO_ELIGIBLE_SCOPE', 'You hold no currently valid verified scope for this service.', 422);
        if ($expert->offerings()->where('catalog_entry_id', $d['catalogEntryId'])->exists()) {
            throw new PrivacyException('OFFERING_EXISTS', 'You already have an offering for this service.');
        }
        $this->assertWithin($d, $allowed);
        $o = new ExpertOffering;
        $o->forceFill(['expert_id' => $expert->id, 'catalog_entry_id' => $d['catalogEntryId'], 'status' => ExpertOffering::DRAFT, 'version' => 1] + $this->fields($d))->save();
        $this->audit->write($expert, 'expert.offering_created', 'expert_offering', $o->id, null, ExpertOffering::DRAFT);

        return $o;
    }

    public function update(Expert $expert, int $id, array $d): ExpertOffering
    {
        $o = $this->lock($expert, $id, (int) $d['expectedVersion']);
        $allowed = $this->eligibleServices($expert)[$o->catalog_entry_id] ?? throw new PrivacyException('NO_ELIGIBLE_SCOPE', 'You hold no currently valid verified scope for this service.', 422);
        $this->assertWithin($d, $allowed);
        $o->forceFill($this->fields($d) + ['version' => $o->version + 1])->save();
        $this->audit->write($expert, 'expert.offering_updated', 'expert_offering', $o->id, $o->status, $o->status, metadata: ['fromVersion' => $o->version - 1, 'toVersion' => $o->version]);

        return $o;
    }

    public function publish(Expert $expert, int $id, array $d): ExpertOffering
    {
        $o = $this->lock($expert, $id, (int) $d['expectedVersion']);
        if ($o->status === ExpertOffering::PUBLISHED) {
            throw new PrivacyException('INVALID_STATE_TRANSITION', 'The offering is already published.');
        }
        if (! $this->eligibility($expert, $o)['eligible']) {
            throw new PrivacyException('OFFERING_SCOPE_INELIGIBLE', 'A currently valid verified scope covering this offering is required to publish.');
        }

        return $this->transition($expert, $o, ExpertOffering::PUBLISHED, 'expert.offering_published');
    }

    public function pause(Expert $expert, int $id, array $d): ExpertOffering
    {
        $o = $this->lock($expert, $id, (int) $d['expectedVersion']);
        if ($o->status !== ExpertOffering::PUBLISHED) {
            throw new PrivacyException('INVALID_STATE_TRANSITION', 'Only a published offering can be paused.');
        }

        return $this->transition($expert, $o, ExpertOffering::PAUSED, 'expert.offering_paused');
    }

    private function transition(Expert $expert, ExpertOffering $o, string $to, string $event): ExpertOffering
    {
        $from = $o->status;
        $o->forceFill(['status' => $to, 'version' => $o->version + 1, 'published_at' => $to === ExpertOffering::PUBLISHED ? now() : $o->published_at])->save();
        $this->audit->write($expert, $event, 'expert_offering', $o->id, $from, $to);

        return $o;
    }

    private function lock(Expert $expert, int $id, int $expected): ExpertOffering
    {
        $o = $expert->offerings()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($o->version !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'The offering has changed. Refresh before retrying.');
        }

        return $o;
    }

    private function fields(array $d): array
    {
        $modes = array_values(array_unique($d['deliveryModes']));
        $languages = array_values(array_unique($d['languages']));
        $jurisdictions = array_key_exists('jurisdictionCodes', $d) && $d['jurisdictionCodes'] !== null ? array_values(array_unique($d['jurisdictionCodes'])) : null;
        sort($modes);
        sort($languages);
        if ($jurisdictions !== null) {
            sort($jurisdictions);
        }

        return ['delivery_modes' => $modes, 'languages' => $languages, 'jurisdiction_codes' => $jurisdictions, 'summary' => isset($d['summary']) ? trim(strip_tags($d['summary'])) ?: null : null]
            + (array_key_exists('priceFromMinor', $d) ? ['price_from_minor' => $d['priceFromMinor'] === null ? null : (int) $d['priceFromMinor']] : []);
    }

    private function assertWithin(array $d, array $allowed): void
    {
        $errors = [];
        if (array_diff($d['deliveryModes'], $allowed['deliveryModes'])) {
            $errors['deliveryModes'] = ['Choose only delivery modes covered by your verified scope and the service policy.'];
        }
        if (array_diff($d['languages'], $allowed['languages'])) {
            $errors['languages'] = ['Choose only languages covered by your verified scope.'];
        }
        if (isset($d['jurisdictionCodes']) && array_diff($d['jurisdictionCodes'], $allowed['jurisdictionCodes'])) {
            $errors['jurisdictionCodes'] = ['Choose only jurisdictions you are granted for this service.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
