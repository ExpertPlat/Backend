<?php

namespace App\Http\Controllers\Api\User\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\ConsentDecisionRequest;
use App\Http\Requests\User\Privacy\ListPrivacyRequest;
use App\Http\Resources\User\Privacy\ConsentResource;
use App\Http\Resources\User\Privacy\PolicyResource;
use App\Models\UserConsentRecord;
use App\Services\Privacy\ConsentManager;
use App\Services\Privacy\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConsentController extends Controller
{
    use PrivacyResponses;

    public function policies(ConsentManager $manager): JsonResponse
    {
        Gate::authorize('viewAny', UserConsentRecord::class);

        return response()->json(['status' => true, 'message' => 'Published policy versions.', 'data' => ['items' => PolicyResource::collection($manager->currentPolicies())]]);
    }

    public function index(Request $request, ConsentManager $manager): JsonResponse
    {
        Gate::authorize('viewAny', UserConsentRecord::class);

        return response()->json(['status' => true, 'message' => 'Current consent decisions.', 'data' => ['items' => $manager->summaries($request->user())]]);
    }

    public function history(ListPrivacyRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', UserConsentRecord::class);
        $data = $request->validated();
        $query = $request->user()->consents()->getQuery()->with('policy');
        foreach (['purpose' => 'purpose', 'policyVersionId' => 'policy_version_id'] as $api => $column) {
            if (isset($data[$api])) {
                $query->where($column, $data[$api]);
            }
        }

        return $this->listing($query, $data, ConsentResource::class, ['decidedAt' => 'decided_at']);
    }

    public function store(ConsentDecisionRequest $request, ConsentManager $manager, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('create', UserConsentRecord::class);

        return $idempotency->run($request, $request->validated(), function () use ($request, $manager): JsonResponse {
            [$record, $created] = $manager->decide($request->user(), $request->validated());

            return $this->item(new ConsentResource($record), 'Consent decision recorded.', $created ? 201 : 200);
        });
    }
}
