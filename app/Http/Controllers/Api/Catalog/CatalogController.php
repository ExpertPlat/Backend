<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CatalogRequest;
use App\Http\Resources\Catalog\CatalogNodeResource;
use App\Http\Resources\Catalog\CatalogVersionResource;
use App\Models\CaseServiceScope;
use App\Models\CatalogEntry;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\ExpertCatalogGrant;
use App\Services\Catalog\CatalogIdempotency;
use App\Services\Catalog\CatalogManager;
use App\Services\Catalog\CatalogReview;

class CatalogController extends Controller
{
    use PrivacyResponses;

    public function nodes(CatalogRequest $r)
    {
        $kind = $r->route('kind') ?? $r->input('kind');
        $q = CatalogNode::query();
        if ($kind) {
            $q->where('kind', $kind);
        }
        if (! $r->routeIs('admin.*')) {
            $q->where('status', 'enabled');
        }
        foreach (['parentId' => 'parent_id', 'code' => 'code', 'status' => 'status'] as $api => $db) {
            if ($r->filled($api)) {
                $q->where($db, $r->input($api));
            }
        }

        return $this->listing($q, $r->validated(), CatalogNodeResource::class, ['id' => 'id', 'updatedAt' => 'updated_at']);
    }

    public function entries(CatalogRequest $r)
    {
        $q = CatalogVersion::query();
        if (! $r->routeIs('admin.*')) {
            $q->whereIn('status', ['intake_only', 'pilot', 'enabled', 'paused'])->whereHas('entry', fn ($q) => $q->whereColumn('catalog_entries.current_version_id', 'catalog_versions.id'));
        }
        foreach (['status', 'domain'] as $f) {
            if ($r->filled($f)) {
                $q->where($f === 'domain' ? 'domain_code' : 'status', $r->input($f));
            }
        }

        return $this->listing($q, $r->validated(), CatalogVersionResource::class, ['id' => 'id', 'updatedAt' => 'updated_at']);
    }

    public function schema(CatalogRequest $r, int $entry)
    {
        $e = CatalogEntry::findOrFail($entry);
        $v = CatalogVersion::whereKey($e->current_version_id)->whereIn('status', ['intake_only', 'pilot', 'enabled', 'paused'])->firstOrFail();

        return $this->item(new CatalogVersionResource($v));
    }

    public function detail(CatalogRequest $r, int $entry)
    {
        $e = CatalogEntry::findOrFail($entry);

        return $this->item(['id' => $e->id, 'code' => $e->code, 'version' => $e->version, 'status' => $e->status, 'currentVersionId' => $e->current_version_id, 'versions' => CatalogVersionResource::collection($e->versions()->orderByDesc('version')->get())]);
    }

    public function impact(CatalogRequest $r, int $version, CatalogManager $m)
    {
        return $this->item($m->impact(CatalogVersion::findOrFail($version)));
    }

    public function waiting(CatalogRequest $r)
    {
        $q = CaseServiceScope::whereNull('submitted_at')->whereNull('detached_at');
        $p = $q->orderBy('id')->paginate($r->integer('perPage', 20));

        return response()->json(['status' => true, 'message' => 'Waiting scope metadata.', 'data' => ['items' => $p->getCollection()->map(fn ($s) => ['caseId' => $s->case_id, 'scopeId' => $s->id, 'catalogVersionId' => $s->catalog_version_id, 'status' => $s->status, 'reasonCodes' => $s->reason_codes ?? []]), 'pagination' => ['currentPage' => $p->currentPage(), 'perPage' => $p->perPage(), 'lastPage' => $p->lastPage(), 'total' => $p->total()]]]);
    }

    public function grants(CatalogRequest $r)
    {
        $p = ExpertCatalogGrant::orderByDesc('id')->paginate($r->integer('perPage', 20));

        return response()->json(['status' => true, 'message' => 'Grant metadata.', 'data' => ['items' => $p->getCollection()->map(fn ($g) => $this->grantData($g)), 'pagination' => ['currentPage' => $p->currentPage(), 'perPage' => $p->perPage(), 'lastPage' => $p->lastPage(), 'total' => $p->total()]]]);
    }

    public function write(CatalogRequest $r, CatalogManager $m, CatalogIdempotency $i)
    {
        $action = last(explode('.', $r->route()->getName()));

        return $i->run($r, function () use ($r, $m, $action) {
            $d = $r->validated();
            $a = $r->admin();
            $result = match ($action) {
                'node' => $m->node($a, $d),'updateNode' => $m->updateNode($a, (int) $r->route('node'), $d),'store' => $m->create($a, $d),'version' => $m->version($a, (int) $r->route('entry'), $d),'review' => $m->review($a, (int) $r->route('version'), $d),'publish' => $m->publish($a, (int) $r->route('version'), $d),'pause' => $m->pause($a, (int) $r->route('version'), $d),'grant' => $m->grant($a, $d),'revoke' => $m->revoke($a, (int) $r->route('grant'), $d),default => throw new \LogicException('Unknown catalog action')
            };
            if (in_array($action, ['pause', 'revoke'])) {
                app(CatalogReview::class)->run();
            }
            $resource = match (true) {
                $result instanceof CatalogNode => new CatalogNodeResource($result),$result instanceof CatalogVersion => new CatalogVersionResource($result),default => $this->grantData($result)
            };

            return $this->item($resource, 'Catalog decision recorded.', in_array($action, ['node', 'store', 'version', 'grant']) ? 201 : 200);
        });
    }

    private function grantData(ExpertCatalogGrant $g): array
    {
        return ['id' => $g->id, 'scopeId' => $g->scope_id, 'catalogVersionId' => $g->catalog_version_id, 'jurisdictionCode' => $g->jurisdiction_code, 'reviewedBy' => $g->reviewed_by, 'revokedAt' => $g->revoked_at?->toISOString()];
    }
}
