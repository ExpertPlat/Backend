<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cases\CaseListRequest;
use App\Http\Resources\Cases\CaseMetadataResource;
use App\Models\CaseRecord;
use App\Services\Privacy\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CaseOversightController extends Controller
{
    use PrivacyResponses;

    public function index(CaseListRequest $request): JsonResponse
    {
        Gate::authorize('cases.viewAny');
        $data = $request->validated();
        $query = CaseRecord::query()->with('domains');
        foreach (['status', 'jurisdiction', 'language', 'urgency'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['userId'])) {
            $query->where('user_id', $data['userId']);
        }
        if (isset($data['domain'])) {
            $query->whereHas('domains', fn ($q) => $q->where('domain', $data['domain']));
        }

        return $this->listing($query, $data, CaseMetadataResource::class, ['updatedAt' => 'updated_at', 'createdAt' => 'created_at', 'submittedAt' => 'submitted_at']);
    }

    public function show(Request $request, int $case, AuditWriter $audit): JsonResponse
    {
        Gate::authorize('cases.view');
        $row = CaseRecord::query()->with('domains')->findOrFail($case);
        $audit->write($request->user(), 'case.admin_viewed', 'case', $row->id);

        return $this->item(new CaseMetadataResource($row));
    }
}
