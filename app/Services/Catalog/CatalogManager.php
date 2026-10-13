<?php

namespace App\Services\Catalog;

use App\Enums\ExpertKycStatus;
use App\Exceptions\PrivacyException;
use App\Models\Admin;
use App\Models\CaseServiceScope;
use App\Models\CatalogEntry;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertVerifiedScope;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CatalogManager
{
    public function __construct(private CatalogPolicy $policies, private AuditWriter $audit, private ExpertCoverage $coverage) {}

    public function node(Admin $actor, array $d): CatalogNode
    {
        $parent = isset($d['parentId']) ? CatalogNode::findOrFail($d['parentId']) : null;
        $expected = match ($d['kind']) {
            'jurisdiction' => 'country','specialty' => 'domain','service' => 'specialty',default => null
        };
        if (($expected && $parent?->kind !== $expected) || (! $expected && $parent)) {
            throw ValidationException::withMessages(['parentId' => 'Invalid catalog hierarchy.']);
        }
        if ($d['kind'] === 'country' && ! preg_match('/^[A-Z]{2}$/D', $d['code'])) {
            throw ValidationException::withMessages(['code' => 'Use a two-letter country code.']);
        }
        if (CatalogNode::where('kind', $d['kind'])->where('code', $d['code'])->exists()) {
            throw new PrivacyException('CATALOG_CODE_EXISTS', 'Catalog code already exists.');
        }
        $n = new CatalogNode;
        $n->forceFill(['kind' => $d['kind'], 'code' => $d['code'], 'parent_id' => $parent?->id, 'labels' => $d['labels'], 'regulated' => $d['kind'] === 'domain' ? ($d['regulated'] ?? null) : null, 'rules' => $d['kind'] === 'safety_rule' ? $d['rules'] : null, 'status' => 'draft', 'version' => 1])->save();
        $this->event($actor, 'node_created', $n->id);

        return $n->refresh();
    }

    public function updateNode(Admin $a, int $id, array $d): CatalogNode
    {
        $n = CatalogNode::findOrFail($id);
        $this->expected($n->version, $d['expectedVersion']);
        if (in_array($n->status, ['blocked', 'retired'], true) && isset($d['status']) && $d['status'] !== $n->status) {
            throw new PrivacyException('CATALOG_NODE_TERMINAL', 'Blocked or retired taxonomy cannot be reactivated.');
        }
        if (isset($d['status']) && $d['status'] !== $n->status) {
            Gate::authorize($d['status'] === 'enabled' ? 'catalog.publish' : 'catalog.pause');
            if ($n->status === 'enabled' && $d['status'] !== 'enabled' && in_array($n->kind, ['domain', 'specialty', 'service', 'jurisdiction', 'country', 'delivery_mode'], true) && CatalogVersion::whereNotNull('published_at')->exists()) {
                throw new PrivacyException('ENTRY_POLICY_CHANGE_REQUIRED', 'Use versioned service publication or pause; shared taxonomy status is frozen after first publication.');
            }
        }
        // Codes and parent relationships are immutable; policy changes belong to entry versions.
        $n->forceFill(['labels' => $d['labels'] ?? $n->labels, 'status' => $d['status'] ?? $n->status, 'version' => $n->version + 1])->save();
        $this->event($a, 'node_updated', $n->id);

        return $n->refresh();
    }

    public function create(Admin $a, array $d): CatalogVersion
    {
        if (CatalogEntry::where('code', $d['code'])->exists()) {
            throw new PrivacyException('CATALOG_CODE_EXISTS', 'Catalog entry exists.');
        }
        $p = $this->policies->validate($d['policy']);
        $e = new CatalogEntry;
        $e->forceFill(['code' => $d['code'], 'version' => 1])->save();

        return $this->newVersion($a, $e, $p);
    }

    public function version(Admin $a, int $id, array $d): CatalogVersion
    {
        $e = CatalogEntry::findOrFail($id);
        $this->expected($e->version, $d['expectedVersion']);
        $p = $this->policies->validate($d['policy']);
        $e->increment('version');

        return $this->newVersion($a, $e, $p);
    }

    private function newVersion(Admin $a, CatalogEntry $e, array $p): CatalogVersion
    {
        $v = new CatalogVersion;
        $v->forceFill(['entry_id' => $e->id, 'version' => ($e->versions()->max('version') ?? 0) + 1, 'created_by' => $a->id, 'policy' => $p, 'policy_hash' => hash('sha256', json_encode($p, JSON_THROW_ON_ERROR))])->save();
        $this->event($a, 'draft_created', $v->id);

        return $v->refresh();
    }

    public function review(Admin $a, int $id, array $d): CatalogVersion
    {
        $v = CatalogVersion::findOrFail($id);
        $this->expected($v->revision, $d['expectedVersion']);
        if ($v->status !== 'draft' || $v->reviewed_at) {
            throw new PrivacyException('INVALID_CATALOG_TRANSITION', 'Version is already reviewed or published.');
        }
        if ($v->policy['regulated'] && $v->created_by === $a->id) {
            throw new PrivacyException('SEPARATION_OF_DUTIES_REQUIRED', 'A separate reviewer is required.', 403);
        }
        $this->policies->validate($v->policy);
        $v->forceFill(['reviewed_by' => $a->id, 'reviewed_at' => now(), 'revision' => $v->revision + 1])->save();
        $this->event($a, 'reviewed', $id, $d['reasonCode']);

        return $v->refresh();
    }

    public function impact(CatalogVersion $v): array
    {
        $scopes = CaseServiceScope::whereIn('catalog_version_id', CatalogVersion::where('entry_id', $v->entry_id)->select('id'));
        $coverage = [];
        foreach ($v->policy['jurisdictionCodes'] ?: ['GLOBAL'] as $j) {
            $coverage[] = ['jurisdictionCode' => $j, 'eligibleExperts' => $this->coverage->count($v, $j)];
        }
        $data = ['catalogEntryId' => $v->entry_id, 'catalogVersion' => $v->version, 'revision' => $v->revision, 'activeScopes' => (clone $scopes)->whereNotNull('submitted_at')->whereNull('detached_at')->count(), 'waitingScopes' => (clone $scopes)->whereNull('submitted_at')->whereNull('detached_at')->count(), 'coverage' => $coverage];
        $data['impactToken'] = hash_hmac('sha256', json_encode($data).'|'.DB::table('catalog_locks')->where('id', 1)->value('revision').'|'.$v->policy_hash, config('app.key'));

        return $data;
    }

    public function publish(Admin $a, int $id, array $d): CatalogVersion
    {
        $v = CatalogVersion::findOrFail($id);
        $this->expected($v->revision, $d['expectedVersion']);
        $this->checkImpact($v, $d['impactToken']);
        if (! $v->reviewed_at || ! in_array($v->status, ['draft', 'pilot', 'intake_only', 'paused'], true)) {
            throw new PrivacyException('INVALID_CATALOG_TRANSITION', 'Review a publishable version first.');
        }
        if ($v->policy['regulated'] && in_array($a->id, [$v->created_by, $v->reviewed_by], true)) {
            throw new PrivacyException('SEPARATION_OF_DUTIES_REQUIRED', 'An independent publisher is required.', 403);
        }
        $this->policies->validate($v->policy);
        $p = $v->policy;
        if ($d['status'] === 'enabled') {
            if (! $p['commerciallyAvailable'] || ! $p['operationallyAvailable'] || ! $p['remoteDeliveryAllowed']) {
                throw new PrivacyException('POLICY_CONFIGURATION_REQUIRED', 'Operational and remote-delivery policy must be approved.');
            }
            foreach (['domain' => $p['domain'], 'specialty' => $p['specialty'], 'service' => $p['serviceType']] as $kind => $code) {
                if ($this->policies->node($kind, $code)->status !== 'enabled') {
                    throw new PrivacyException('POLICY_CONFIGURATION_REQUIRED', 'Enable the catalog taxonomy first.');
                }
            }
            foreach ($p['jurisdictionCodes'] as $j) {
                $node = $this->policies->node('jurisdiction', $j);
                if ($node->status !== 'enabled' || CatalogNode::find($node->parent_id)?->status !== 'enabled') {
                    throw new PrivacyException('POLICY_CONFIGURATION_REQUIRED', 'Jurisdiction or country is not enabled.');
                }
            }
        }
        $v->forceFill(['status' => $d['status'], 'published_by' => $a->id, 'published_at' => now(), 'revision' => $v->revision + 1])->save();
        $v->entry->forceFill(['current_version_id' => $v->id, 'status' => $d['status'], 'version' => $v->entry->version + 1])->save();
        $this->event($a, 'published', $id);

        return $v->refresh();
    }

    public function pause(Admin $a, int $id, array $d): CatalogVersion
    {
        $v = CatalogVersion::findOrFail($id);
        $this->expected($v->revision, $d['expectedVersion']);
        $this->checkImpact($v, $d['impactToken']);
        if ($v->status === 'draft' || in_array($v->status, ['blocked', 'retired'])) {
            throw new PrivacyException('INVALID_CATALOG_TRANSITION', 'This version cannot be paused.');
        }
        $v->forceFill(['status' => $d['status'], 'revision' => $v->revision + 1])->save();
        if ($v->entry->current_version_id === $v->id || $d['status'] === 'blocked') {
            $v->entry->forceFill(['status' => $d['status'], 'version' => $v->entry->version + 1])->save();
        }
        $this->event($a, $d['status'], $id, $d['reasonCode']);

        return $v->refresh();
    }

    public function grant(Admin $a, array $d): ExpertCatalogGrant
    {
        $v = CatalogVersion::findOrFail($d['catalogVersionId']);
        $s = ExpertVerifiedScope::findOrFail($d['scopeId']);
        Expert::whereKey($s->expert_id)->lockForUpdate()->firstOrFail();
        $s->refresh();
        if (! $v->reviewed_at || ! in_array($d['jurisdictionCode'], $v->policy['jurisdictionCodes'] ?: ['GLOBAL'], true) || ! $this->coverage->scopeValid($s, $v->policy, $d['jurisdictionCode']) || $d['evidenceId'] !== $s->evidence_id) {
            throw new PrivacyException('SCOPE_REVIEW_REQUIRED', 'Evidence and exact jurisdiction must match the reviewed policy.');
        }
        if ($s->expert->kyc_status !== ExpertKycStatus::Approved) {
            throw new PrivacyException('SCOPE_REVIEW_REQUIRED', 'Expert KYC is not approved.');
        }
        if (ExpertCatalogGrant::where('scope_id', $s->id)->where('catalog_version_id', $v->id)->where('jurisdiction_code', $d['jurisdictionCode'])->exists()) {
            throw new PrivacyException('GRANT_ALREADY_EXISTS', 'A grant already exists; revoked grants are immutable.');
        }
        $g = new ExpertCatalogGrant;
        $g->forceFill(['scope_id' => $s->id, 'catalog_version_id' => $v->id, 'jurisdiction_code' => $d['jurisdictionCode'], 'reviewed_by' => $a->id, 'evidence_type' => $s->evidence_type, 'evidence_id' => $s->evidence_id])->save();
        $this->event($a, 'expert_granted', $g->id, $d['reasonCode']);

        return $g;
    }

    public function revoke(Admin $a, int $id, array $d): ExpertCatalogGrant
    {
        $g = ExpertCatalogGrant::findOrFail($id);
        if ($g->revoked_at) {
            throw new PrivacyException('GRANT_ALREADY_REVOKED', 'Grant already revoked.');
        }$g->forceFill(['revoked_at' => now()])->save();
        $this->event($a, 'expert_revoked', $id, $d['reasonCode']);

        return $g;
    }

    private function checkImpact(CatalogVersion $v, string $token): void
    {
        if (! hash_equals($this->impact($v)['impactToken'], $token)) {
            throw new PrivacyException('IMPACT_CHANGED', 'Refresh the impact preview before deciding.');
        }
    }

    private function expected(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'Refresh the catalog record.');
        }
    }

    private function event(Admin $a, string $action, int $id, ?string $reason = null): void
    {
        CatalogMutex::changed();
        $this->audit->write($a, 'catalog.'.$action, 'catalog', $id, reason: $reason);
    }
}
