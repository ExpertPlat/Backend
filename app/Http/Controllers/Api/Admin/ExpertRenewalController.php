<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Renewal\RenewalRequest;
use App\Http\Resources\Expert\Renewal\RenewalResource;
use App\Models\ExpertScopeRenewal;
use App\Services\Expert\Renewal\ScopeRenewal;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\Gate;

class ExpertRenewalController extends Controller
{
    public function index(RenewalRequest $request)
    {
        Gate::authorize('expertRenewals.viewAny');
        $query = ExpertScopeRenewal::query()->whereNotNull('submitted_at');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('scopeId')) {
            $query->where('scope_id', $request->integer('scopeId'));
        }
        $rows = $query->orderBy('id', $request->input('sort', 'newest') === 'oldest' ? 'asc' : 'desc')->paginate($request->integer('perPage', 20));

        return response()->json(['status' => true, 'data' => ['requests' => RenewalResource::collection($rows)->resolve(), 'pagination' => ['currentPage' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(), 'lastPage' => $rows->lastPage()]]]);
    }

    public function show(RenewalRequest $request, int $renewal)
    {
        Gate::authorize('expertRenewals.view');

        return $this->detail($this->visible($renewal));
    }

    public function document(RenewalRequest $request, int $renewal, int $submission, ScopeRenewal $service, AuditWriter $audit)
    {
        Gate::authorize('expertRenewals.viewEvidence');
        $row = $this->visible($renewal);
        $item = $row->submissions()->findOrFail($submission);
        $bytes = $service->bytes($item);
        $audit->write($request->user(), 'expert_renewal.document_downloaded', 'expert_scope_renewal', $row->id);

        return response($bytes, 200, ['Content-Type' => $item->mime_type, 'Content-Disposition' => 'attachment; filename="renewal-evidence.'.pathinfo($item->path, PATHINFO_EXTENSION).'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function startReview(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        Gate::authorize('expertRenewals.review');

        return $this->change($request, $renewal, $service, 'startReview');
    }

    public function requestInformation(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        Gate::authorize('expertRenewals.review');

        return $this->change($request, $renewal, $service, 'requestInformation');
    }

    public function reject(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        Gate::authorize('expertRenewals.review');

        return $this->change($request, $renewal, $service, 'reject');
    }

    public function approve(RenewalRequest $request, int $renewal, ScopeRenewal $service)
    {
        Gate::authorize('expertRenewals.review');

        return $this->change($request, $renewal, $service, 'approve');
    }

    private function visible(int $id): ExpertScopeRenewal
    {
        return ExpertScopeRenewal::query()->whereNotNull('submitted_at')->findOrFail($id);
    }

    private function change(RenewalRequest $request, int $id, ScopeRenewal $service, string $action)
    {
        return $this->detail($service->mutate($request->user(), $this->visible($id), $request->integer('version'), $action, $request->validated()));
    }

    private function detail(ExpertScopeRenewal $row)
    {
        return response()->json(['status' => true, 'data' => ['request' => (new RenewalResource($row->load(['submissions', 'scope'])))->resolve()]]);
    }
}
