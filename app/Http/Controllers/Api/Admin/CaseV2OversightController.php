<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cases\CaseV2AdminRequest;
use App\Http\Resources\Cases\CaseV2MetadataResource;
use App\Models\CaseRecord;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\Gate;

class CaseV2OversightController extends Controller
{
    use PrivacyResponses;

    public function index(CaseV2AdminRequest $r)
    {
        Gate::authorize('cases.viewAny');
        $q = CaseRecord::query()->withCount('serviceScopes');
        foreach (['status' => 'readiness_status', 'userId' => 'user_id'] as $api => $db) {
            if ($r->filled($api)) {
                $q->where($db, $r->input($api));
            }
        }

        return $this->listing($q, $r->validated(), CaseV2MetadataResource::class, ['updatedAt' => 'updated_at', 'createdAt' => 'created_at']);
    }

    public function show(CaseV2AdminRequest $r, int $case, AuditWriter $audit)
    {
        Gate::authorize('cases.view');
        $c = CaseRecord::withCount('serviceScopes')->findOrFail($case);
        $audit->write($r->user(), 'case.admin_viewed', 'case', $c->id);

        return $this->item(new CaseV2MetadataResource($c));
    }
}
