<?php

namespace App\Http\Controllers\Api\Expert\Renewal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Renewal\RenewalRequest;
use App\Http\Resources\Expert\Profile\VerifiedScopeResource;
use App\Http\Resources\Expert\Renewal\RenewalResource;
use App\Models\ExpertScopeRenewal;
use App\Services\Expert\Renewal\ScopeRenewal;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\Gate;

class RenewalController extends Controller
{
    public function scopes(RenewalRequest $request, ScopeRenewal $service)
    {
        $rows = $request->user()->verifiedScopes()->orderByDesc('id')->paginate($request->integer('perPage', 20));
        $open = ExpertScopeRenewal::query()->whereIn('open_scope_id', $rows->pluck('id'))->pluck('id', 'open_scope_id');

        return response()->json(['status' => true, 'data' => ['scopes' => $rows->getCollection()->map(fn ($scope) => (new VerifiedScopeResource($scope))->resolve() + ['nextReviewAt' => $scope->next_review_at?->toDateString(), 'renewal' => $service->eligibility($scope, $request->user(), $open[$scope->id] ?? null)]), 'pagination' => $this->pagination($rows)]]);
    }

    public function store(RenewalRequest $request, int $scope, ScopeRenewal $service)
    {
        return $this->detail($service->create($request->user(), $scope), 201);
    }

    public function index(RenewalRequest $request)
    {
        $query = ExpertScopeRenewal::query()->where('expert_id', $request->user()->id);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('scopeId')) {
            $query->where('scope_id', $request->integer('scopeId'));
        }
        $rows = $query->orderBy('id', $request->input('sort', 'newest') === 'oldest' ? 'asc' : 'desc')->paginate($request->integer('perPage', 20));

        return response()->json(['status' => true, 'data' => ['requests' => RenewalResource::collection($rows)->resolve(), 'pagination' => $this->pagination($rows)]]);
    }

    public function show(RenewalRequest $request, int $renewal)
    {
        return $this->detail($this->owned($request, $renewal));
    }

    public function evidence(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        return $this->change($request, $renewal, $service, 'evidence', 201);
    }

    public function submit(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        return $this->change($request, $renewal, $service, 'submit');
    }

    public function cancel(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        return $this->change($request, $renewal, $service, 'cancel');
    }

    public function document(RenewalRequest $request, int $renewal, int $submission, ScopeRenewal $service, AuditWriter $audit)
    {
        $row = $this->owned($request, $renewal);
        $item = $row->submissions()->findOrFail($submission);
        $bytes = $service->bytes($item);
        $audit->write($request->user(), 'expert_renewal.document_downloaded', 'expert_scope_renewal', $row->id);

        return response($bytes, 200, ['Content-Type' => $item->mime_type, 'Content-Disposition' => 'attachment; filename="renewal-evidence.'.pathinfo($item->path, PATHINFO_EXTENSION).'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function owned(RenewalRequest $request, int $id): ExpertScopeRenewal
    {
        $row = ExpertScopeRenewal::query()->where('expert_id', $request->user()->id)->findOrFail($id);
        Gate::authorize('view', $row);

        return $row;
    }

    private function change(RenewalRequest $request, int $id, ScopeRenewal $service, string $action, int $status = 200)
    {
        return $this->detail($service->mutate($request->user(), $this->owned($request, $id), $request->integer('version'), $action, $request->validated(), $request->file('file')), $status);
    }

    private function detail(ExpertScopeRenewal $row, int $status = 200)
    {
        return response()->json(['status' => true, 'data' => ['request' => (new RenewalResource($row->load(['submissions', 'scope'])))->resolve()]], $status);
    }

    private function pagination($rows): array
    {
        return ['currentPage' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(), 'lastPage' => $rows->lastPage()];
    }
}
