<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cases\CaseListRequest;
use App\Http\Requests\Cases\CaseMutationRequest;
use App\Http\Requests\Cases\IntakeRequest;
use App\Http\Resources\Cases\CaseAssessmentResource;
use App\Http\Resources\Cases\CaseResource;
use App\Http\Resources\Cases\CaseTimelineResource;
use App\Models\AuditEvent;
use App\Models\CaseRecord;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\IntakeSchema;
use App\Services\Privacy\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CaseController extends Controller
{
    use PrivacyResponses;

    public function bootstrap(IntakeSchema $schema): JsonResponse
    {
        Gate::authorize('viewAny', CaseRecord::class);

        return $this->item($schema->bootstrap());
    }

    public function index(CaseListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CaseRecord::class);
        $data = $request->validated();
        $query = $request->user()->cases()->getQuery()->with(['currentIntake', 'domains']);
        $this->filters($query, $data);

        return $this->listing($query, $data, CaseResource::class, ['updatedAt' => 'updated_at', 'createdAt' => 'created_at', 'submittedAt' => 'submitted_at']);
    }

    public function store(IntakeRequest $request, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('create', CaseRecord::class);

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new CaseResource($workflow->create($request->user(), $request->validated())), 'Case draft created.', 201));
    }

    public function show(Request $request, int $case, CaseWorkflow $workflow): JsonResponse
    {
        return $this->item(new CaseResource($workflow->loaded($this->owned($request, $case))));
    }

    public function update(IntakeRequest $request, int $case, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->update($request->user(), $case, $request->validated()));
    }

    public function cancel(CaseMutationRequest $request, int $case, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->cancel($request->user(), $case, $request->validated()));
    }

    public function attach(CaseMutationRequest $request, int $case, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->attach($request->user(), $case, $request->validated()), 201);
    }

    public function detach(CaseMutationRequest $request, int $case, int $snapshot, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->detach($request->user(), $case, $snapshot, $request->validated()));
    }

    public function assess(CaseMutationRequest $request, int $case, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->assess($request->user(), $case, $request->validated()));
    }

    public function confirm(CaseMutationRequest $request, int $case, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->confirm($request->user(), $case, $request->validated()));
    }

    public function submit(CaseMutationRequest $request, int $case, CaseWorkflow $workflow, Idempotency $idempotency): JsonResponse
    {
        return $this->write($request, $case, $idempotency, fn () => $workflow->submit($request->user(), $case, $request->validated()));
    }

    public function clarifications(Request $request, int $case, CaseWorkflow $workflow): JsonResponse
    {
        $row = $this->owned($request, $case);
        $assessment = $row->assessments()->latest('id')->first();

        return $this->item(['caseVersion' => $row->version, 'assessment' => $assessment ? new CaseAssessmentResource($assessment) : null, 'isStale' => ! $assessment || ! hash_equals($assessment->input_fingerprint, $workflow->fingerprint($row, $request->user()))]);
    }

    public function timeline(CaseListRequest $request, int $case): JsonResponse
    {
        $row = $this->owned($request, $case);

        return $this->listing(AuditEvent::query()->where('subject_type', 'case')->where('subject_id', $row->id)->whereNotIn('action', ['case.admin_viewed']), $request->validated(), CaseTimelineResource::class, ['occurredAt' => 'occurred_at']);
    }

    private function owned(Request $request, int $id): CaseRecord
    {
        $row = $request->user()->cases()->findOrFail($id);
        Gate::authorize('view', $row);

        return $row;
    }

    private function write(Request $request, int $id, Idempotency $idempotency, \Closure $operation, int $status = 200): JsonResponse
    {
        Gate::authorize('update', $this->owned($request, $id));

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new CaseResource($operation()), 'Case updated.', $status));
    }

    private function filters($query, array $data): void
    {
        foreach (['status', 'jurisdiction', 'language', 'urgency'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['domain'])) {
            $query->whereHas('domains', fn ($q) => $q->where('domain', $data['domain']));
        }
    }
}
