<?php

namespace App\Http\Controllers\Api\User\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\ListPrivacyRequest;
use App\Http\Requests\User\Privacy\StoreDataRequest;
use App\Http\Requests\User\Privacy\TransitionRequest;
use App\Http\Resources\User\Privacy\DataRequestResource;
use App\Models\DataRightsRequest;
use App\Services\Privacy\DataRightsManager;
use App\Services\Privacy\ExportDownload;
use App\Services\Privacy\Idempotency;
use App\Services\Privacy\PasswordConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DataRightsRequestController extends Controller
{
    use PrivacyResponses;

    public function index(ListPrivacyRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DataRightsRequest::class);
        $data = $request->validated();
        $query = $request->user()->dataRequests()->getQuery();
        foreach (['type', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return $this->listing($query, $data, DataRequestResource::class, ['requestedAt' => 'requested_at', 'dueAt' => 'due_at']);
    }

    public function store(StoreDataRequest $request, PasswordConfirmation $confirmation, DataRightsManager $manager, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('create', DataRightsRequest::class);
        $confirmedAt = $confirmation->require($request->user(), $request->validated('type'));

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new DataRequestResource($manager->create($request->user(), $request->validated(), $confirmedAt)), 'Data request accepted for processing.', 202));
    }

    public function show(Request $request, int $dataRequest): JsonResponse
    {
        $record = $request->user()->dataRequests()->with('items')->findOrFail($dataRequest);
        Gate::authorize('view', $record);

        return $this->item(new DataRequestResource($record));
    }

    public function cancel(TransitionRequest $request, int $dataRequest, DataRightsManager $manager, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('update', $request->user()->dataRequests()->findOrFail($dataRequest));

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new DataRequestResource($manager->cancel($request->user(), $dataRequest, $request->validated('expectedVersion')))));
    }

    public function download(Request $request, int $dataRequest, PasswordConfirmation $confirmation, ExportDownload $downloads): Response
    {
        $record = $request->user()->dataRequests()->findOrFail($dataRequest);
        Gate::authorize('view', $record);
        $confirmation->require($request->user(), 'export');

        return $downloads->download($request->user(), $record);
    }
}
