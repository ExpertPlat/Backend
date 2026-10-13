<?php

namespace App\Http\Controllers\Api\Front;

use App\Exceptions\PrivacyException;
use App\Http\Controllers\Controller;
use App\Http\Front\Front;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\AdminPresenter;
use App\Models\Admin;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Models\CatalogEntry;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\DataRightsRequest;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertReview;
use App\Models\ExpertScopeRenewal;
use App\Models\Funding;
use App\Models\FundingDispute;
use App\Models\FundingRefund;
use App\Models\LedgerEvent;
use App\Models\ReconciliationIssue;
use App\Models\StripeAccount;
use App\Models\User;
use App\Services\Catalog\CatalogManager;
use App\Services\Catalog\CatalogMutex;
use App\Services\Catalog\CatalogReview;
use App\Services\Expert\Renewal\ScopeRenewal;
use App\Services\Payments\PaymentReconciler;
use App\Services\Payments\Refunds;
use App\Services\Reviews\ExpertReviews;
use App\Services\Front\CaseAnalyzer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin dashboard (v7) lists, details and decisions. Every action keeps its API permission and domain rules. */
class AdminController extends Controller
{
    use FrontResponses;

    protected bool $keepTextPairs = true;

    private function admin(Request $r): Admin
    {
        $a = $r->user();
        abort_unless($a instanceof Admin, 403);

        return $a;
    }

    private function per(Request $r, int $default = 15): int
    {
        return max(1, min(100, (int) $r->query('perPage', $default)));
    }

    private function search(Builder $q, ?string $term, array $columns): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(function ($w) use ($term, $columns) {
            foreach ($columns as $c) {
                $w->orWhere($c, 'like', '%'.$term.'%');
            }
            if (ctype_digit($term)) {
                $w->orWhere('id', (int) $term);
            }
        });
    }

    /* ───────── people ───────── */

    public function users(Request $r): JsonResponse
    {
        Gate::authorize('users.viewAny');
        $q = $this->search(User::query()->with('profile'), $r->query('search'), ['name', 'email']);
        if ($r->query('verified') === 'true') {
            $q->whereNotNull('email_verified_at');
        } elseif ($r->query('verified') === 'false') {
            $q->whereNull('email_verified_at');
        }
        if ($r->filled('country')) {
            $q->whereHas('profile', fn ($p) => $p->where('country', $r->query('country')));
        }
        $q->orderBy('created_at', $r->query('sortDirection') === 'asc' ? 'asc' : 'desc');

        return $this->paged($q->paginate($this->per($r)), fn ($u) => AdminPresenter::user($u));
    }

    public function user(Request $r, int $user): JsonResponse
    {
        Gate::authorize('users.view');

        return $this->ok(AdminPresenter::userDetail(User::with('profile')->findOrFail($user)));
    }

    public function experts(Request $r): JsonResponse
    {
        Gate::authorize('experts.viewAny');
        $q = $this->search(Expert::query()->with('profile'), $r->query('search'), ['name', 'email', 'domain']);
        foreach (['kycStatus' => 'kyc_status', 'domain' => 'domain'] as $param => $column) {
            if ($r->filled($param)) {
                $q->where($column, $r->query($param));
            }
        }
        if (in_array($r->query('isActive'), ['true', 'false'], true)) {
            $q->where('is_active', $r->query('isActive') === 'true');
        }

        return $this->paged($q->orderByDesc('created_at')->paginate($this->per($r)), fn ($e) => AdminPresenter::expert($e));
    }

    public function expert(Request $r, int $expert): JsonResponse
    {
        Gate::authorize('experts.view');

        return $this->ok(AdminPresenter::expertDetail(Expert::with(['profile', 'availability'])->findOrFail($expert)));
    }

    /* ───────── scope renewals ───────── */

    public function renewals(Request $r): JsonResponse
    {
        Gate::authorize('expertRenewals.viewAny');
        $q = ExpertScopeRenewal::query()->whereNotNull('submitted_at')->whereNotIn('status', ['draft', 'cancelled']);
        if ($r->filled('status')) {
            $q->where('status', $r->query('status'));
        }

        return $this->paged($q->orderByDesc('updated_at')->paginate($this->per($r)), fn ($x) => AdminPresenter::renewal($x));
    }

    public function renewal(Request $r, int $renewal): JsonResponse
    {
        Gate::authorize('expertRenewals.view');

        return $this->ok(AdminPresenter::renewal(ExpertScopeRenewal::whereNotNull('submitted_at')->findOrFail($renewal), true));
    }

    /**
     * v7 decision bodies: { version, reason? , validUntil?, nextReviewAt? }. Approval records that the reviewer checked
     * the submitted evidence (the dialog shows it) and carries the scope's existing regulator review forward.
     */
    public function decideRenewal(Request $r, int $renewal, ScopeRenewal $service): JsonResponse
    {
        Gate::authorize('expertRenewals.review');
        $action = ['start-review' => 'startReview', 'request-information' => 'requestInformation', 'reject' => 'reject', 'approve' => 'approve'][last(explode('.', $r->route()->getName()))];
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => [in_array($action, ['reject', 'requestInformation'], true) ? 'required' : 'sometimes', 'string', 'min:3', 'max:2000'],
            'validUntil' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'], 'nextReviewAt' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today']]);
        $row = ExpertScopeRenewal::whereNotNull('submitted_at')->with('scope')->findOrFail($renewal);
        $data = ['version' => (int) $d['version']];
        if (isset($d['reason'])) {
            $data['reason'] = mb_strlen($d['reason']) < 10 ? str_pad($d['reason'], 10, '.') : $d['reason'];
        }
        if ($action === 'approve') {
            $scope = $row->scope;
            $oldDue = collect([$scope->valid_until, $scope->next_review_at])->filter()->sort()->first();
            $next = $d['nextReviewAt'] ?? now()->addMonths(11)->toDateString();
            $until = $d['validUntil'] ?? min(($oldDue ? $oldDue->copy()->addYear() : now()->addYear())->toDateString(), $next);
            $data += ['evidenceReviewed' => true, 'validUntil' => $until, 'nextReviewAt' => $next];
            if ($scope->regulator && $scope->registration_number && $scope->verification_source && $scope->verified_country) {
                $data['professionalReview'] = ['verifiedCountry' => $scope->verified_country, 'regulator' => $scope->regulator, 'registrationNumber' => $scope->registration_number,
                    'verificationSource' => $scope->verification_source, 'statusChecked' => 'active'];
            }
        }
        $updated = $service->mutate($this->admin($r), $row, (int) $d['version'], $action, $data);

        return $this->ok(AdminPresenter::renewal($updated->fresh(), true), 'Renewal decision recorded.');
    }

    /* ───────── catalog ───────── */

    public function entries(Request $r): JsonResponse
    {
        Gate::authorize('catalog.view');
        $q = $this->search(CatalogEntry::query(), $r->query('search'), ['code']);
        if ($r->filled('status')) {
            $status = $r->query('status');
            $q->where(fn ($w) => $w->where('status', $status)->orWhereHas('versions', fn ($v) => $v->where('status', $status === 'reviewed' ? 'draft' : $status)->when($status === 'reviewed', fn ($x) => $x->whereNotNull('reviewed_at'))));
        }

        return $this->paged($q->orderBy('id')->paginate($this->per($r, 50)), fn ($e) => AdminPresenter::entry($e, false));
    }

    public function entry(Request $r, int $entry): JsonResponse
    {
        Gate::authorize('catalog.view');

        return $this->ok(AdminPresenter::entry(CatalogEntry::findOrFail($entry)));
    }

    private const DOMAIN_ALIASES = ['ip' => 'intellectual_property', 'biz' => 'business'];

    /** v7 "new service" form → a complete catalog policy (specialty/service taxonomy created as drafts when missing). */
    private function policyFrom(array $d, ?array $base = null): array
    {
        $domain = self::DOMAIN_ALIASES[$d['domain'] ?? ''] ?? ($d['domain'] ?? $base['domain'] ?? 'business');
        $domainNode = CatalogNode::where('kind', 'domain')->where('code', $domain)->first() ?? throw ValidationException::withMessages(['domain' => ['Unknown domain.']]);
        $codes = [];
        foreach ($d['policy']['jurisdictionCodes'] ?? $base['jurisdictionCodes'] ?? [] as $code) {
            if (strlen($code) === 2) {
                $country = CatalogNode::where('kind', 'country')->where('code', strtoupper($code))->value('id');
                $codes = array_merge($codes, CatalogNode::where('kind', 'jurisdiction')->where('parent_id', $country)->orderBy('id')->limit(1)->pluck('code')->all());
            } else {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        $regulated = (bool) $domainNode->regulated;
        if ($regulated && ! $codes) {
            throw ValidationException::withMessages(['jurisdictionCodes' => ['A regulated service needs at least one country.']]);
        }
        $specialty = $base['specialty'] ?? null;
        $service = $base['serviceType'] ?? null;
        if (! $specialty) {
            $code = $d['code'];
            $labels = $d['labels'] ?? ['ar' => $code, 'en' => $code];
            $spec = CatalogNode::where('kind', 'specialty')->where('code', $code)->first();
            if (! $spec) {
                $spec = new CatalogNode;
                $spec->forceFill(['kind' => 'specialty', 'code' => $code, 'parent_id' => $domainNode->id, 'labels' => $labels, 'status' => 'draft', 'version' => 1])->save();
            }
            $svc = CatalogNode::where('kind', 'service')->where('code', $code.'_service')->first();
            if (! $svc) {
                $svc = new CatalogNode;
                $svc->forceFill(['kind' => 'service', 'code' => $code.'_service', 'parent_id' => $spec->id, 'labels' => $labels, 'status' => 'draft', 'version' => 1])->save();
            }
            [$specialty, $service] = [$spec->code, $svc->code];
        }
        $base ??= [];

        return array_replace([
            'domain' => $domain, 'specialty' => $specialty, 'serviceType' => $service, 'deliveryModes' => ['video_consultation'], 'jurisdictionMode' => 'GLOBAL', 'jurisdictionCodes' => [],
            'regulated' => $regulated, 'remoteDeliveryAllowed' => true, 'requiresVerifiedScope' => true, 'requiresProfessionalLicense' => $regulated, 'requiredEvidenceType' => $regulated ? 'credential' : 'qualification',
            'minimumExperts' => 1, 'maximumExperts' => 3, 'intakeSchema' => [], 'matchingRules' => ['keywords' => [], 'prohibitedKeywords' => [], 'humanReviewRequired' => false],
            'commerciallyAvailable' => true, 'operationallyAvailable' => true, 'requiredConsents' => ['terms', 'privacy'], 'requiredDocuments' => [],
            'effectiveFrom' => now()->startOfDay()->format('Y-m-d\TH:i:s\Z'), 'effectiveUntil' => null,
        ], array_intersect_key($base, array_flip(['remoteDeliveryAllowed', 'minimumExperts', 'maximumExperts', 'intakeSchema', 'matchingRules', 'commerciallyAvailable', 'operationallyAvailable', 'requiredDocuments', 'effectiveFrom', 'effectiveUntil'])), [
            'deliveryModes' => array_values(array_unique($d['policy']['deliveryModes'] ?? $base['deliveryModes'] ?? ['video_consultation'])),
            'jurisdictionCodes' => $codes, 'jurisdictionMode' => count($codes) === 0 ? 'GLOBAL' : (count($codes) === 1 ? 'COUNTRY_SPECIFIC' : 'MULTI_COUNTRY'),
        ]);
    }

    private function catalogWrite(\Closure $op): mixed
    {
        return DB::transaction(function () use ($op) {
            CatalogMutex::lock();

            return $op();
        });
    }

    public function createEntry(Request $r, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.manageDrafts');
        $d = $r->validate(['code' => ['required', 'regex:/^[a-z][a-z0-9_]{0,99}$/'], 'domain' => ['required', 'string', 'max:100'], 'labels.ar' => ['required', 'string', 'max:120'],
            'labels.en' => ['required', 'string', 'max:120'], 'policy' => ['required', 'array'], 'policy.deliveryModes' => ['required', 'array', 'min:1'], 'policy.jurisdictionCodes' => ['sometimes', 'array']]);
        $v = $this->catalogWrite(fn () => $m->create($this->admin($r), ['code' => $d['code'], 'policy' => $this->policyFrom($d)]));

        return $this->ok(AdminPresenter::entry($v->entry->fresh()), 'The service was created as a draft.', 201);
    }

    public function createVersion(Request $r, int $entry, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.manageDrafts');
        $d = $r->validate(['expectedVersion' => ['required', 'integer'], 'policy' => ['required', 'array']]);
        $e = CatalogEntry::findOrFail($entry);
        $latest = $e->versions()->orderByDesc('version')->firstOrFail();
        if ($latest->revision !== (int) $d['expectedVersion']) {
            throw new PrivacyException('VERSION_CONFLICT', 'Refresh the catalog record.');
        }
        if ($e->versions()->where('status', 'draft')->exists()) {
            throw new PrivacyException('INVALID_CATALOG_TRANSITION', 'Finish or publish the open version before creating a new one.');
        }
        $policy = $this->policyFrom(['policy' => $d['policy'], 'domain' => $latest->policy['domain']], $latest->policy);
        $v = $this->catalogWrite(fn () => $m->version($this->admin($r), $e->id, ['expectedVersion' => $e->version, 'policy' => $policy]));

        return $this->ok(AdminPresenter::version($v), 'A new draft version was created.', 201);
    }

    private function impactShape(CatalogVersion $v, CatalogManager $m): array
    {
        $i = $m->impact($v);
        $scopes = CaseServiceScope::whereIn('catalog_version_id', CatalogVersion::where('entry_id', $v->entry_id)->select('id'))->whereNull('detached_at');

        return ['catalogVersionId' => $v->id, 'catalogEntryId' => $v->entry_id, 'openCases' => $i['activeScopes'], 'waitingCases' => (clone $scopes)->where('status', 'waiting_for_expert_supply')->count(),
            'activeOfferings' => \App\Models\ExpertOffering::where('catalog_entry_id', $v->entry_id)->where('status', 'published')->count(),
            'activeGrants' => ExpertCatalogGrant::where('catalog_version_id', $v->id)->whereNull('revoked_at')->count(), 'scopes' => $i['activeScopes'] + $i['waitingScopes'],
            'coverage' => $i['coverage'], 'impactToken' => $i['impactToken'], 'generatedAt' => Front::iso(now())];
    }

    public function impact(Request $r, int $version, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.viewImpact');

        return $this->ok($this->impactShape(CatalogVersion::findOrFail($version), $m));
    }

    public function versionAction(Request $r, int $version, CatalogManager $m): JsonResponse
    {
        $action = last(explode('.', $r->route()->getName()));
        Gate::authorize(['review' => 'catalog.review', 'publish' => 'catalog.publish', 'pause' => 'catalog.pause'][$action]);
        $d = $r->validate(['expectedVersion' => ['required', 'integer'], 'reasonCode' => [$action === 'publish' ? 'sometimes' : 'required', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'approved' => [$action === 'review' ? 'accepted' : 'sometimes'], 'status' => [$action === 'review' ? 'sometimes' : 'required', Rule::in($action === 'publish' ? ['intake_only', 'pilot', 'enabled'] : ['paused', 'retired', 'blocked'])],
            'impactToken' => [$action === 'review' ? 'sometimes' : 'required', 'string', 'size:64']]);
        $a = $this->admin($r);
        $v = $this->catalogWrite(fn () => match ($action) {
            'review' => $m->review($a, $version, $d), 'publish' => $m->publish($a, $version, $d), 'pause' => $m->pause($a, $version, $d),
        });
        if ($action === 'pause') {
            app(CatalogReview::class)->run();
        }

        return $this->ok(AdminPresenter::version($v), 'Catalog decision recorded.');
    }

    public function createNode(Request $r, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.manageDrafts');
        $d = $r->validate(['kind' => ['required', Rule::in(['country', 'jurisdiction', 'domain', 'specialty', 'service', 'delivery_mode', 'safety_rule'])], 'code' => ['required', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,99}$/'],
            'labels.ar' => ['required', 'string', 'max:120'], 'labels.en' => ['required', 'string', 'max:120'], 'parentId' => ['sometimes', 'nullable', 'integer'], 'regulated' => ['sometimes', 'boolean'],
            'rules' => ['sometimes', 'array']]);
        $d['labels'] = ['ar' => $d['labels']['ar'], 'en' => $d['labels']['en']];
        if ($d['kind'] === 'safety_rule') {
            $d['rules'] ??= ['keywords' => [], 'reasonCode' => 'HUMAN_TRIAGE_REQUIRED'];
        }
        $n = $this->catalogWrite(fn () => $m->node($this->admin($r), $d));

        return $this->ok(self::node($n), 'Added as a draft.', 201);
    }

    public function updateNode(Request $r, int $node, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.manageDrafts');
        $d = $r->validate(['expectedVersion' => ['required', 'integer'], 'labels.ar' => ['sometimes', 'string', 'max:120'], 'labels.en' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'enabled', 'paused', 'retired', 'blocked', 'intake_only', 'pilot'])], 'regulated' => ['sometimes', 'boolean']]);
        if (isset($d['labels'])) {
            $d['labels'] = ['ar' => $d['labels']['ar'] ?? '', 'en' => $d['labels']['en'] ?? ''];
        }
        if (isset($d['status']) && in_array($d['status'], ['intake_only', 'pilot'], true)) {
            $d['status'] = 'enabled';
        }
        unset($d['regulated']); // classification is fixed at creation; policies carry regulation per version
        $n = $this->catalogWrite(fn () => $m->updateNode($this->admin($r), $node, $d));

        return $this->ok(self::node($n), 'Saved.');
    }

    public static function node(CatalogNode $n): array
    {
        return ['id' => $n->id, 'kind' => $n->kind, 'code' => $n->code, 'parentId' => $n->parent_id, 'labels' => $n->labels, 'regulated' => (bool) $n->regulated, 'status' => $n->status, 'version' => $n->version,
            'rules' => $n->rules, 'createdAt' => Front::iso($n->created_at), 'updatedAt' => Front::iso($n->updated_at)];
    }

    public function grants(Request $r): JsonResponse
    {
        Gate::authorize('catalog.view');
        $q = ExpertCatalogGrant::query()->with('scope');
        if ($r->query('status') === 'active') {
            $q->whereNull('revoked_at');
        } elseif ($r->query('status') === 'revoked') {
            $q->whereNotNull('revoked_at');
        }

        return $this->paged($q->orderByDesc('id')->paginate($this->per($r)), fn ($g) => AdminPresenter::grant($g));
    }

    public function createGrant(Request $r, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.publish');
        $d = $r->validate(['scopeId' => ['required', 'integer'], 'catalogVersionId' => ['required', 'integer'], 'jurisdictionCode' => ['required', 'string', 'max:100'],
            'evidenceId' => ['required', 'integer'], 'reasonCode' => ['required', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/']]);
        $v = CatalogVersion::findOrFail($d['catalogVersionId']);
        if (strlen($d['jurisdictionCode']) === 2) {
            $country = CatalogNode::where('kind', 'country')->where('code', strtoupper($d['jurisdictionCode']))->value('id');
            $d['jurisdictionCode'] = collect($v->policy['jurisdictionCodes'])->first(fn ($j) => CatalogNode::where('kind', 'jurisdiction')->where('code', $j)->value('parent_id') === $country) ?? $d['jurisdictionCode'];
        }
        $g = $this->catalogWrite(fn () => $m->grant($this->admin($r), $d));

        return $this->ok(AdminPresenter::grant($g), 'Grant created.', 201);
    }

    public function revokeGrant(Request $r, int $grant, CatalogManager $m): JsonResponse
    {
        Gate::authorize('catalog.publish');
        $d = $r->validate(['reasonCode' => ['required', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/']]);
        $g = $this->catalogWrite(fn () => $m->revoke($this->admin($r), $grant, $d));
        app(CatalogReview::class)->run();

        return $this->ok(AdminPresenter::grant($g->fresh()), 'Grant revoked.');
    }

    public function waitingCases(Request $r): JsonResponse
    {
        Gate::authorize('catalog.view');
        $q = CaseServiceScope::query()->whereNull('detached_at')->where('status', 'waiting_for_expert_supply')->with(['catalogVersion']);

        return $this->paged($q->orderBy('id')->paginate($this->per($r)), function ($s) {
            $c = CaseRecord::find($s->case_id);

            return ['caseId' => $s->case_id, 'scopeId' => $s->id, 'title' => null, 'caseCountry' => $c?->jurisdiction, 'domain' => $s->catalogVersion->policy['domain'],
                'catalogEntryId' => $s->catalogVersion->entry_id, 'catalogEntry' => CaseAnalyzer::label($s->catalogVersion), 'user' => AdminPresenter::ref($c ? User::find($c->user_id) : null),
                'reasonCodes' => $s->reason_codes ?? [], 'waitingSince' => Front::iso($s->readiness_checked_at ?? $s->updated_at)];
        });
    }

    /* ───────── cases (metadata only) ───────── */

    public function cases(Request $r): JsonResponse
    {
        Gate::authorize('cases.viewAny');
        $q = CaseRecord::query();
        if ($r->filled('status')) {
            $q->where(fn ($w) => $w->where('readiness_status', $r->query('status'))->orWhere('status', $r->query('status')));
        }
        if ($r->filled('userId')) {
            $q->where('user_id', (int) $r->query('userId'));
        }
        if ($r->filled('caseCountry')) {
            $q->where('jurisdiction', $r->query('caseCountry'));
        }
        if ($r->filled('search') && ctype_digit((string) $r->query('search'))) {
            $q->whereKey((int) $r->query('search'));
        }

        return $this->paged($q->orderByDesc($r->query('sortBy') === 'createdAt' ? 'created_at' : 'updated_at')->paginate($this->per($r)), fn ($c) => AdminPresenter::caseRow($c));
    }

    public function caseDetail(Request $r, int $case): JsonResponse
    {
        Gate::authorize('cases.view');
        $c = CaseRecord::findOrFail($case);
        app(\App\Services\Privacy\AuditWriter::class)->write($this->admin($r), 'admin.case_metadata_viewed', 'case', $c->id);

        return $this->ok(AdminPresenter::caseDetail($c));
    }

    /* ───────── privacy ───────── */

    public function dataRequests(Request $r): JsonResponse
    {
        Gate::authorize('dataRequests.viewAny');
        $q = DataRightsRequest::query();
        foreach (['status', 'type'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->query($f));
            }
        }
        if ($r->filled('userId')) {
            $q->where('user_id', (int) $r->query('userId'));
        }

        return $this->paged($q->orderByDesc('requested_at')->paginate($this->per($r)), fn ($x) => AdminPresenter::dataRequest($x));
    }

    public function dataRequest(Request $r, int $dataRequest): JsonResponse
    {
        Gate::authorize('dataRequests.view');

        return $this->ok(AdminPresenter::dataRequest(DataRightsRequest::findOrFail($dataRequest)));
    }

    /* ───────── reviews ───────── */

    public function reviews(Request $r): JsonResponse
    {
        Gate::authorize('reviews.viewAny');
        $q = ExpertReview::query();
        $status = $r->query('status');
        if ($status) {
            match ($status) {
                'pending_moderation' => $q->where('moderation_status', ExpertReview::PENDING),
                'rejected' => $q->where('moderation_status', ExpertReview::HIDDEN)->whereNull('published_at'),
                'hidden' => $q->where('moderation_status', ExpertReview::HIDDEN)->whereNotNull('published_at'),
                default => $q->where('moderation_status', $status),
            };
        }
        if ($r->filled('expertId')) {
            $q->where('expert_id', (int) $r->query('expertId'));
        }

        return $this->paged($q->orderByDesc('created_at')->paginate($this->per($r)), fn ($x) => AdminPresenter::review($x));
    }

    public function moderate(Request $r, int $review, ExpertReviews $reviews): JsonResponse
    {
        Gate::authorize('reviews.moderate');
        $d = $r->validate(['status' => ['required', Rule::in(['published', 'hidden', 'rejected'])], 'expectedVersion' => ['required', 'integer', 'min:1'], 'note' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $to = $d['status'] === 'rejected' ? ExpertReview::HIDDEN : $d['status'];
        $row = $reviews->moderate($this->admin($r), $review, $to, (int) $d['expectedVersion'], $d['note'] ?? null);

        return $this->ok(AdminPresenter::review($row), 'Review updated.');
    }

    /* ───────── finance ───────── */

    public function fundings(Request $r): JsonResponse
    {
        Gate::authorize('finance.view');
        $q = Funding::query();
        if ($r->filled('fundingId')) {
            $q->whereKey((int) $r->query('fundingId'));
        }
        if ($r->filled('status')) {
            $s = $r->query('status');
            match ($s) {
                'requires_payment' => $q->where('status', Funding::AWAITING_PAYMENT),
                'refunded', 'partially_refunded' => $q->where('refund_status', $s),
                default => $q->where('status', $s),
            };
        }

        return $this->paged($q->orderByDesc('id')->paginate($this->per($r)), fn ($f) => AdminPresenter::funding($f));
    }

    public function funding(Request $r, int $funding): JsonResponse
    {
        Gate::authorize('finance.view');

        return $this->ok(AdminPresenter::fundingDetail(Funding::findOrFail($funding)));
    }

    /** v7 reasons outside the API's admin list are recorded as ADMIN_DECISION with the original wording kept in the reason text. */
    public function createRefund(Request $r, int $funding, Refunds $refunds): JsonResponse
    {
        Gate::authorize('refunds.approve');
        $d = $r->validate(['reasonCode' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'attemptId' => ['sometimes', 'nullable', 'integer'],
            'amountMinor' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        if (! in_array($d['reasonCode'], Refunds::ADMIN_REASONS, true)) {
            $d['reason'] = '['.$d['reasonCode'].'] '.$d['reason'];
            $d['reasonCode'] = 'ADMIN_DECISION';
        }
        if (in_array($d['reasonCode'], ['LATE_PAYMENT', 'DUPLICATE_CHARGE'], true) && empty($d['attemptId'])) {
            throw ValidationException::withMessages(['attemptId' => ['Choose the payment attempt.']]);
        }
        $refund = $refunds->createByAdmin($this->admin($r), $funding, $d);

        return $this->ok(AdminPresenter::refund($refund->fresh()), 'Refund approved.', 201);
    }

    public function reconcile(Request $r, int $funding, PaymentReconciler $reconciler): JsonResponse
    {
        Gate::authorize('finance.reconcile');
        $f = Funding::findOrFail($funding);
        $reconciler->reconcileFunding($f->id, true);

        return $this->ok(AdminPresenter::fundingDetail($f->fresh()), 'Reconciliation completed against the payment provider.');
    }

    public function ledger(Request $r): JsonResponse
    {
        Gate::authorize('finance.view');
        $q = LedgerEvent::query()->whereIn('event_type', ['payment_confirmed', 'late_payment_received', 'duplicate_payment_detected', 'refund_completed']);
        if ($r->filled('fundingId')) {
            $q->where('funding_id', (int) $r->query('fundingId'));
        }
        $rows = AdminPresenter::ledger($q->orderByDesc('id')->limit(500)->get());
        if ($r->filled('type')) {
            $rows = array_values(array_filter($rows, fn ($x) => $x['type'] === $r->query('type')));
        }
        $per = $this->per($r, 20);
        $page = max(1, (int) $r->query('page', 1));

        return response()->json(['status' => true, 'message' => '', 'data' => array_slice($rows, ($page - 1) * $per, $per),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / $per)), 'per_page' => $per, 'total' => count($rows)]]);
    }

    public function refunds(Request $r): JsonResponse
    {
        Gate::authorize('refunds.view');
        $q = FundingRefund::query();
        if ($r->filled('status')) {
            $q->where('status', $r->query('status'));
        }
        if ($r->filled('fundingId')) {
            $q->where('funding_id', (int) $r->query('fundingId'));
        }

        return $this->paged($q->orderByDesc('id')->paginate($this->per($r)), fn ($x) => AdminPresenter::refund($x));
    }

    public function decideRefund(Request $r, int $refund, Refunds $refunds): JsonResponse
    {
        Gate::authorize('refunds.approve');
        $d = $r->validate(['decision' => ['required', Rule::in(['approve_full', 'approve_partial', 'reject'])], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'expectedVersion' => ['required', 'integer', 'min:1'], 'amountMinor' => ['required_if:decision,approve_partial', 'nullable', 'integer', 'min:1']]);

        return $this->ok(AdminPresenter::refund($refunds->decide($this->admin($r), $refund, $d)->fresh()), 'Refund decision recorded.');
    }

    public function reconciliation(Request $r): JsonResponse
    {
        Gate::authorize('finance.view');
        $q = ReconciliationIssue::query();
        if ($r->filled('status')) {
            $q->where('status', $r->query('status'));
        }
        if ($r->filled('kind')) {
            $q->where('kind', strtoupper($r->query('kind')));
        }
        if ($r->filled('fundingId')) {
            $q->where('funding_id', (int) $r->query('fundingId'));
        }

        return $this->paged($q->orderByDesc('detected_at')->paginate($this->per($r)), fn ($x) => AdminPresenter::issue($x));
    }

    public function disputes(Request $r): JsonResponse
    {
        Gate::authorize('disputes.view');
        $q = FundingDispute::query();
        if ($r->filled('status')) {
            $q->where('status', $r->query('status'));
        }

        return $this->paged($q->orderByDesc('id')->paginate($this->per($r)), fn ($x) => AdminPresenter::dispute($x));
    }

    public function stripeAccounts(Request $r): JsonResponse
    {
        Gate::authorize('stripe_accounts.view');

        return $this->paged(StripeAccount::query()->orderByDesc('id')->paginate($this->per($r)), fn ($x) => AdminPresenter::stripe($x));
    }
}
