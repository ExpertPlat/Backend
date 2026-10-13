<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\ListPrivacyRequest;
use App\Http\Resources\Admin\ConsentMetadataResource;
use App\Http\Resources\Admin\DataRequestMetadataResource;
use App\Models\DataRightsRequest;
use App\Models\User;
use App\Services\Privacy\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PrivacyOversightController extends Controller
{
    use PrivacyResponses;

    public function consents(ListPrivacyRequest $request, int $user, AuditWriter $audit): JsonResponse
    {
        Gate::authorize('users.consentMetadata.view');
        $owner = User::query()->findOrFail($user);
        $query = $owner->consents()->getQuery()->with('policy');
        $data = $request->validated();
        foreach (['purpose' => 'purpose', 'policyVersionId' => 'policy_version_id'] as $api => $column) {
            if (isset($data[$api])) {
                $query->where($column, $data[$api]);
            }
        }
        $response = $this->listing($query, $data, ConsentMetadataResource::class, ['decidedAt' => 'decided_at']);
        $audit->write($request->user(), 'admin.consent_metadata_viewed', 'user', $owner->id);

        return $response;
    }

    public function index(ListPrivacyRequest $request, AuditWriter $audit): JsonResponse
    {
        Gate::authorize('dataRequests.viewAny');
        $data = $request->validated();
        $query = DataRightsRequest::query();
        foreach (['type' => 'type', 'status' => 'status', 'userId' => 'user_id'] as $api => $column) {
            if (isset($data[$api])) {
                $query->where($column, $data[$api]);
            }
        }
        $response = $this->listing($query, $data, DataRequestMetadataResource::class, ['requestedAt' => 'requested_at', 'dueAt' => 'due_at']);
        $audit->write($request->user(), 'admin.data_request_list_viewed', 'data_request', 0);

        return $response;
    }

    public function show(Request $request, int $dataRequest, AuditWriter $audit): JsonResponse
    {
        Gate::authorize('dataRequests.view');
        $record = DataRightsRequest::query()->findOrFail($dataRequest);
        $audit->write($request->user(), 'admin.data_request_metadata_viewed', 'data_request', $record->id);

        return $this->item(new DataRequestMetadataResource($record));
    }
}
